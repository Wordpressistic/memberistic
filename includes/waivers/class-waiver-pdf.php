<?php
/**
 * Waiver PDF — dependency-free generator for signed-waiver PDFs.
 *
 * Produces a canonical PDF artifact for every e-signature: the snapshotted
 * waiver text, the signer's details (DOB, phone, emergency contact, minors
 * covered), the drawn signature image, and the audit trail (timestamp, IP,
 * SHA-256 text hash). Written as a minimal PDF 1.4 emitter on purpose — the
 * plugin has no Composer vendor tree, so bundling dompdf/mpdf isn't an
 * option; Helvetica core fonts + a DCTDecode (JPEG) image XObject need no
 * embedded font files and render everywhere.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Waivers;

use function WordPressistic\Memberistic\memberistic_get_brand_label;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Waiver_PDF {

	const PAGE_W  = 612; // US Letter, points.
	const PAGE_H  = 792;
	const MARGIN  = 54;
	const LINE_H  = 14;
	const BODY_PT = 10;

	/**
	 * Build the signed-waiver PDF.
	 *
	 * @param array  $sig            Signature row (memberistic_waiver_signatures).
	 * @param string $signature_jpeg Raw JPEG bytes of the drawn signature ('' = typed only).
	 * @return string PDF bytes.
	 */
	public static function build( array $sig, $signature_jpeg = '' ) {
		$brand = function_exists( '\WordPressistic\Memberistic\memberistic_get_brand_label' )
			? memberistic_get_brand_label()
			: get_bloginfo( 'name' );

		$meta = array(
			array( 'Signer', trim( (string) ( $sig['signer_name'] ?? '' ) ) . ( ! empty( $sig['signer_email'] ) ? ' <' . $sig['signer_email'] . '>' : '' ) ),
			array( 'Date of birth', (string) ( $sig['dob'] ?? '' ) ),
			array( 'Phone', (string) ( $sig['phone'] ?? '' ) ),
			array( 'Emergency contact', trim( (string) ( $sig['emergency_name'] ?? '' ) . ( ! empty( $sig['emergency_phone'] ) ? ' — ' . $sig['emergency_phone'] : '' ) ) ),
			array( 'Signed', (string) ( $sig['signed_at'] ?? '' ) ),
			array( 'Valid through', (string) ( $sig['expires_at'] ?? '' ) ),
			array( 'Method', (string) ( $sig['source'] ?? '' ) ),
			array( 'IP address', (string) ( $sig['ip'] ?? '' ) ),
			array( 'Signature record', '#' . (int) ( $sig['id'] ?? 0 ) ),
			array( 'Document hash (SHA-256)', (string) ( $sig['text_hash'] ?? '' ) ),
		);

		$minors = array();
		if ( ! empty( $sig['minors_json'] ) ) {
			$decoded = json_decode( (string) $sig['minors_json'], true );
			foreach ( (array) $decoded as $m ) {
				$row = trim( (string) ( $m['name'] ?? '' ) );
				if ( '' !== $row ) {
					$minors[] = $row . ( ! empty( $m['dob'] ) ? ' (DOB ' . $m['dob'] . ')' : '' );
				}
			}
		}

		// ---- Lay text out into page content streams. ------------------
		$pages = array();
		$buf   = '';
		$y     = self::PAGE_H - self::MARGIN;

		$newpage = function () use ( &$pages, &$buf, &$y ) {
			if ( '' !== $buf ) {
				$pages[] = $buf;
			}
			$buf = '';
			$y   = self::PAGE_H - self::MARGIN;
		};
		$need = function ( $pts ) use ( &$y, $newpage ) {
			if ( $y - $pts < self::MARGIN ) {
				$newpage();
			}
		};
		$line = function ( $text, $font = 'F1', $size = self::BODY_PT, $gap = self::LINE_H ) use ( &$buf, &$y, $need ) {
			$need( $gap );
			$y   -= $gap;
			$buf .= 'BT /' . $font . ' ' . $size . ' Tf ' . self::MARGIN . ' ' . $y . ' Td (' . self::esc( $text ) . ') Tj ET' . "\n";
		};

		$line( strtoupper( (string) $brand ), 'F2', 14, 20 );
		$line( 'Liability Waiver — Signed Copy', 'F2', 12, 18 );
		$y -= 6;
		foreach ( $meta as $m ) {
			if ( '' === trim( (string) $m[1] ) ) {
				continue;
			}
			$line( $m[0] . ':  ' . $m[1], 'F1', 9, 13 );
		}
		if ( $minors ) {
			$line( 'Minors covered by this waiver (signed by the adult above as parent/guardian):', 'F2', 9, 16 );
			foreach ( $minors as $m ) {
				$line( '   - ' . $m, 'F1', 9, 13 );
			}
		}
		$y -= 10;
		$line( 'Waiver text as agreed (verbatim snapshot):', 'F2', 10, 18 );
		$y -= 4;

		$text = (string) ( $sig['waiver_text'] ?? '' );
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $para ) {
			if ( '' === trim( $para ) ) {
				$y -= 6;
				continue;
			}
			foreach ( explode( "\n", wordwrap( $para, 92, "\n", true ) ) as $l ) {
				$line( $l, 'F1', self::BODY_PT, self::LINE_H );
			}
		}

		// ---- Signature block. ------------------------------------------
		$img_dims = '' !== $signature_jpeg ? self::jpeg_dims( $signature_jpeg ) : null;
		$block_h  = $img_dims ? 130 : 60;
		$need( $block_h + 20 );
		$y -= 20;
		$line( 'Electronically signed:', 'F2', 10, 16 );
		if ( $img_dims ) {
			// Fit the drawn signature into a 260 x 90pt box, keeping ratio.
			$w = 260.0;
			$h = $w * ( $img_dims[1] / max( 1, $img_dims[0] ) );
			if ( $h > 90 ) {
				$h = 90.0;
				$w = $h * ( $img_dims[0] / max( 1, $img_dims[1] ) );
			}
			$need( $h + 10 );
			$y   -= ( $h + 6 );
			$buf .= 'q ' . round( $w, 2 ) . ' 0 0 ' . round( $h, 2 ) . ' ' . self::MARGIN . ' ' . round( $y, 2 ) . ' cm /Img1 Do Q' . "\n";
		}
		$line( '/s/ ' . (string) ( $sig['signer_name'] ?? '' ) . '   —   ' . (string) ( $sig['signed_at'] ?? '' ), 'F1', 10, 18 );

		$newpage();

		return self::assemble( $pages, $signature_jpeg, $img_dims );
	}

	/* ------------------------------------------------------------------ */
	/* PDF assembly                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Assemble page content streams (+ optional JPEG XObject) into a PDF.
	 *
	 * @param string[]   $pages    One content-stream string per page.
	 * @param string     $jpeg     Raw JPEG bytes ('' = none).
	 * @param array|null $dims     [width, height] of the JPEG.
	 * @return string
	 */
	private static function assemble( array $pages, $jpeg = '', $dims = null ) {
		$objs      = array(); // 1-indexed object bodies (without "N 0 obj").
		$objs[1]   = ''; // Catalog — filled once page ids are known.
		$objs[2]   = ''; // Pages.
		$objs[3]   = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
		$objs[4]   = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";
		$img_id    = 0;
		if ( '' !== $jpeg && $dims ) {
			$img_id          = count( $objs ) + 1;
			$objs[ $img_id ] = "<< /Type /XObject /Subtype /Image /Width {$dims[0]} /Height {$dims[1]} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen( $jpeg ) . " >>\nstream\n" . $jpeg . "\nendstream";
		}

		$resources = '<< /Font << /F1 3 0 R /F2 4 0 R >>' . ( $img_id ? " /XObject << /Img1 {$img_id} 0 R >>" : '' ) . ' >>';

		$page_ids = array();
		foreach ( $pages as $content ) {
			$cid          = count( $objs ) + 1;
			$objs[ $cid ] = '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream";
			$pid          = count( $objs ) + 1;
			$objs[ $pid ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::PAGE_W . ' ' . self::PAGE_H . "] /Resources {$resources} /Contents {$cid} 0 R >>";
			$page_ids[]   = $pid;
		}

		$kids    = implode( ' ', array_map( static function ( $id ) {
			return $id . ' 0 R';
		}, $page_ids ) );
		$objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objs[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count( $page_ids ) . ' >>';

		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		ksort( $objs );
		foreach ( $objs as $num => $body ) {
			$offsets[ $num ] = strlen( $pdf );
			$pdf            .= $num . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref_pos = strlen( $pdf );
		$count    = count( $objs ) + 1;
		$pdf     .= "xref\n0 {$count}\n0000000000 65535 f \n";
		foreach ( $offsets as $off ) {
			$pdf .= sprintf( "%010d 00000 n \n", $off );
		}
		$pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref_pos}\n%%EOF\n";
		return $pdf;
	}

	/** Escape + transliterate a string for a PDF literal (Latin-1 text space). */
	private static function esc( $s ) {
		$s = (string) $s;
		if ( function_exists( 'remove_accents' ) ) {
			$s = remove_accents( $s );
		}
		if ( function_exists( 'iconv' ) ) {
			$conv = @iconv( 'UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s ); // phpcs:ignore
			if ( false !== $conv ) {
				$s = $conv;
			}
		}
		$s = preg_replace( '/[^\x20-\xFF]/', '', $s );
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), (string) $s );
	}

	/**
	 * Pixel dimensions of a JPEG (from its SOF marker).
	 *
	 * @param string $data Raw JPEG bytes.
	 * @return array|null [width, height] or null when not a parseable JPEG.
	 */
	public static function jpeg_dims( $data ) {
		$data = (string) $data;
		if ( strlen( $data ) < 12 || "\xFF\xD8\xFF" !== substr( $data, 0, 3 ) ) {
			return null;
		}
		$i   = 2;
		$len = strlen( $data );
		while ( $i + 9 < $len ) {
			if ( "\xFF" !== $data[ $i ] ) {
				$i++;
				continue;
			}
			$marker = ord( $data[ $i + 1 ] );
			if ( $marker >= 0xC0 && $marker <= 0xCF && ! in_array( $marker, array( 0xC4, 0xC8, 0xCC ), true ) ) {
				$h = ( ord( $data[ $i + 5 ] ) << 8 ) + ord( $data[ $i + 6 ] );
				$w = ( ord( $data[ $i + 7 ] ) << 8 ) + ord( $data[ $i + 8 ] );
				return ( $w > 0 && $h > 0 ) ? array( $w, $h ) : null;
			}
			$seg = ( ord( $data[ $i + 2 ] ) << 8 ) + ord( $data[ $i + 3 ] );
			if ( $seg < 2 ) {
				return null;
			}
			$i += 2 + $seg;
		}
		return null;
	}
}
