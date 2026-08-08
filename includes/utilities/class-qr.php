<?php
/**
 * Self-contained QR code generator.
 *
 * Replaces the call to api.qrserver.com that previously exfiltrated
 * member PII (name + email + plan + member id) to a third-party
 * service on every account-page render. This generator runs entirely
 * in-process, emits an SVG string the browser can render at any
 * size, and never makes a network call.
 *
 * Byte-mode QR, error-correction level M (~15% recovery). Auto-picks the
 * smallest version (1–10) that fits the payload (up to 213 bytes — ample
 * for member-verification + check-in URLs). Full QR pipeline: data
 * encoding, multi-block Reed–Solomon ECC with interleaving, function-
 * pattern placement (finders, separators, timing, alignment, dark module),
 * data zig-zag, mask 0, and format + version information. Output is a real,
 * scannable QR validated module-for-module against a reference encoder.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Utilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class QR {

	/** Error-correction codewords per block, level M, versions 1–10. */
	private static $ecc_per_block = array( 1 => 10, 2 => 16, 3 => 26, 4 => 18, 5 => 24, 6 => 16, 7 => 18, 8 => 22, 9 => 22, 10 => 26 );
	/** Number of EC blocks, level M, versions 1–10. */
	private static $num_blocks = array( 1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 2, 6 => 4, 7 => 4, 8 => 4, 9 => 5, 10 => 5 );
	/** Byte-mode data capacity (chars) at level M, versions 1–10. */
	private static $byte_capacity = array( 1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84, 6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213 );
	/** Version-info BCH strings (versions 7–10). */
	private static $version_bits = array( 7 => 0x07C94, 8 => 0x085BC, 9 => 0x09A99, 10 => 0x0A4D3 );

	/**
	 * Render a payload as an SVG QR code.
	 *
	 * @param string $payload The string to encode.
	 * @param int    $size_px Output width/height in CSS pixels.
	 * @param string $bg      Background color (CSS hex).
	 * @param string $fg      Foreground color (CSS hex).
	 * @return string SVG markup.
	 */
	public static function svg( $payload, $size_px = 260, $bg = '#ffffff', $fg = '#0f2044' ) {
		$matrix = self::encode( (string) $payload );
		$n      = count( $matrix );
		if ( $n < 1 ) {
			return '';
		}
		// Quiet zone of 4 modules is required for reliable scanning.
		$quiet   = 4;
		$modules = $n + $quiet * 2;
		$cell    = max( 1, (int) floor( $size_px / $modules ) );
		$svg_w   = $cell * $modules;
		$rects   = '';
		for ( $y = 0; $y < $n; $y++ ) {
			$x = 0;
			while ( $x < $n ) {
				if ( 1 === (int) $matrix[ $y ][ $x ] ) {
					$start = $x;
					while ( $x < $n && 1 === (int) $matrix[ $y ][ $x ] ) {
						$x++;
					}
					$w      = ( $x - $start ) * $cell;
					$rects .= sprintf(
						'<rect x="%d" y="%d" width="%d" height="%d"/>',
						( $start + $quiet ) * $cell,
						( $y + $quiet ) * $cell,
						$w,
						$cell
					);
				} else {
					$x++;
				}
			}
		}
		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%2$d" height="%2$d" shape-rendering="crispEdges" role="img" aria-label="QR code"><rect width="%1$d" height="%1$d" fill="%3$s"/><g fill="%4$s">%5$s</g></svg>',
			(int) $svg_w,
			(int) $size_px,
			esc_attr( $bg ),
			esc_attr( $fg ),
			$rects
		);
	}

	/**
	 * Encode a payload as a 2D binary matrix (1 = dark, 0 = light).
	 * Byte mode, EC level M, versions 1–10. Returns array of arrays of int.
	 * Returns a hash-derived visual placeholder when the payload is too long
	 * so the template never errors.
	 */
	public static function encode( $data ) {
		$data  = (string) $data;
		$len   = strlen( $data );
		$bytes = array_values( unpack( 'C*', $len ? $data : ' ' ) );
		if ( ! $len ) {
			$bytes = array();
		}

		// Smallest version whose byte capacity fits.
		$version = 0;
		foreach ( self::$byte_capacity as $v => $cap ) {
			if ( $len <= $cap ) {
				$version = $v;
				break;
			}
		}
		if ( ! $version ) {
			return self::fallback_matrix( $data );
		}

		$total_data = self::total_data_codewords( $version );

		// ── Build the data bit stream ─────────────────────────────────────
		$len_bits = ( $version >= 10 ) ? 16 : 8;
		$bits     = '0100' . str_pad( decbin( $len ), $len_bits, '0', STR_PAD_LEFT );
		foreach ( $bytes as $b ) {
			$bits .= str_pad( decbin( $b ), 8, '0', STR_PAD_LEFT );
		}
		$capacity_bits = $total_data * 8;
		// Terminator (≤ 4 zero bits).
		$bits = substr( $bits . '0000', 0, $capacity_bits );
		// Pad to a byte boundary.
		while ( strlen( $bits ) % 8 !== 0 ) {
			$bits .= '0';
		}
		// Pad bytes 0xEC / 0x11 until the data capacity is filled.
		$pad = array( 0xEC, 0x11 );
		$pi  = 0;
		while ( strlen( $bits ) < $capacity_bits ) {
			$bits .= str_pad( decbin( $pad[ $pi % 2 ] ), 8, '0', STR_PAD_LEFT );
			$pi++;
		}

		$data_cw = array();
		for ( $i = 0; $i < strlen( $bits ); $i += 8 ) {
			$data_cw[] = bindec( substr( $bits, $i, 8 ) );
		}

		// ── Split into blocks + Reed–Solomon, then interleave ─────────────
		$num_blocks  = self::$num_blocks[ $version ];
		$ecc_len     = self::$ecc_per_block[ $version ];
		$raw_cw      = self::raw_codewords( $version );
		$num_short   = $num_blocks - ( $raw_cw % $num_blocks );
		$short_total = intdiv( $raw_cw, $num_blocks );

		$blocks_data = array();
		$blocks_ecc  = array();
		$offset      = 0;
		for ( $b = 0; $b < $num_blocks; $b++ ) {
			$data_len = ( $short_total - $ecc_len ) + ( $b < $num_short ? 0 : 1 );
			$chunk    = array_slice( $data_cw, $offset, $data_len );
			$offset  += $data_len;
			$blocks_data[] = $chunk;
			$blocks_ecc[]  = self::rs_encode( $chunk, $ecc_len );
		}

		$max_data = 0;
		foreach ( $blocks_data as $bd ) {
			$max_data = max( $max_data, count( $bd ) );
		}

		$final = array();
		for ( $i = 0; $i < $max_data; $i++ ) {
			foreach ( $blocks_data as $bd ) {
				if ( $i < count( $bd ) ) {
					$final[] = $bd[ $i ];
				}
			}
		}
		for ( $i = 0; $i < $ecc_len; $i++ ) {
			foreach ( $blocks_ecc as $be ) {
				$final[] = $be[ $i ];
			}
		}

		$final_bits = '';
		foreach ( $final as $b ) {
			$final_bits .= str_pad( decbin( $b ), 8, '0', STR_PAD_LEFT );
		}

		return self::build_matrix( $version, $final_bits );
	}

	/* ------------------------------------------------------------------ */
	/* Capacity helpers                                                   */
	/* ------------------------------------------------------------------ */

	/** Total codewords (data + ecc) for a version. */
	private static function raw_codewords( $version ) {
		$result = ( 16 * $version + 128 ) * $version + 64;
		if ( $version >= 2 ) {
			$num_align = intdiv( $version, 7 ) + 2;
			$result   -= ( 25 * $num_align - 10 ) * $num_align - 55;
			if ( $version >= 7 ) {
				$result -= 36;
			}
		}
		return intdiv( $result, 8 );
	}

	/** Data-only codewords for a version at level M. */
	private static function total_data_codewords( $version ) {
		return self::raw_codewords( $version ) - self::$ecc_per_block[ $version ] * self::$num_blocks[ $version ];
	}

	/* ------------------------------------------------------------------ */
	/* Reed–Solomon over GF(256)                                          */
	/* ------------------------------------------------------------------ */

	private static function rs_encode( $data, $ec_count ) {
		$gen = self::rs_gen_poly( $ec_count );           // value-domain, leading coef 1.
		$msg = array_merge( $data, array_fill( 0, $ec_count, 0 ) );
		$len = count( $data );
		$gc  = count( $gen );
		for ( $i = 0; $i < $len; $i++ ) {
			$coef = $msg[ $i ];
			if ( 0 !== $coef ) {
				for ( $j = 0; $j < $gc; $j++ ) {
					$msg[ $i + $j ] ^= self::gf_mul( $gen[ $j ], $coef );
				}
			}
		}
		return array_slice( $msg, $len );
	}

	/** Generator polynomial (value domain): product of (x - α^i), i=0..n-1. */
	private static function rs_gen_poly( $n ) {
		$poly = array( 1 );
		for ( $i = 0; $i < $n; $i++ ) {
			$next = array_fill( 0, count( $poly ) + 1, 0 );
			$pc   = count( $poly );
			$ai   = self::gf_exp( $i );
			for ( $j = 0; $j < $pc; $j++ ) {
				$next[ $j ]     ^= $poly[ $j ];
				$next[ $j + 1 ] ^= self::gf_mul( $poly[ $j ], $ai );
			}
			$poly = $next;
		}
		return $poly;
	}

	private static function gf_mul( $a, $b ) {
		if ( 0 === $a || 0 === $b ) {
			return 0;
		}
		return self::gf_exp( ( self::gf_log( $a ) + self::gf_log( $b ) ) % 255 );
	}

	private static $exp = null;
	private static $log = null;

	private static function init_gf() {
		if ( null !== self::$exp ) {
			return;
		}
		self::$exp = array_fill( 0, 256, 0 );
		self::$log = array_fill( 0, 256, 0 );
		$x         = 1;
		for ( $i = 0; $i < 255; $i++ ) {
			self::$exp[ $i ] = $x;
			self::$log[ $x ] = $i;
			$x <<= 1;
			if ( $x & 0x100 ) {
				$x ^= 0x11D;
			}
		}
		self::$exp[255] = self::$exp[0];
	}

	private static function gf_exp( $i ) {
		self::init_gf();
		return self::$exp[ $i ];
	}
	private static function gf_log( $i ) {
		self::init_gf();
		return self::$log[ $i ];
	}

	/* ------------------------------------------------------------------ */
	/* Matrix construction                                                */
	/* ------------------------------------------------------------------ */

	/** Working matrix + function-module map (instance-free, passed by ref). */
	private static $m;
	private static $fn;
	private static $sz;

	private static function set_module( $x, $y, $val ) {
		self::$m[ $y ][ $x ]  = $val ? 1 : 0;
		self::$fn[ $y ][ $x ] = true;
	}

	private static function build_matrix( $version, $bits ) {
		$size      = 17 + 4 * $version;
		self::$sz  = $size;
		self::$m   = array_fill( 0, $size, array_fill( 0, $size, 0 ) );
		self::$fn  = array_fill( 0, $size, array_fill( 0, $size, false ) );

		// Timing patterns.
		for ( $i = 0; $i < $size; $i++ ) {
			self::set_module( 6, $i, 0 === $i % 2 );
			self::set_module( $i, 6, 0 === $i % 2 );
		}

		// Finder patterns (centers) + their separators.
		self::draw_finder( 3, 3 );
		self::draw_finder( $size - 4, 3 );
		self::draw_finder( 3, $size - 4 );

		// Alignment patterns (skip the three that overlap finders).
		$align = self::alignment_positions( $version );
		$an     = count( $align );
		for ( $i = 0; $i < $an; $i++ ) {
			for ( $j = 0; $j < $an; $j++ ) {
				if ( ( 0 === $i && 0 === $j ) || ( 0 === $i && $j === $an - 1 ) || ( $i === $an - 1 && 0 === $j ) ) {
					continue;
				}
				self::draw_alignment( $align[ $i ], $align[ $j ] );
			}
		}

		// Reserve + draw format and version information (marks function cells).
		self::draw_format_bits();
		if ( $version >= 7 ) {
			self::draw_version_bits( $version );
		}

		// Place data bits in the zig-zag pattern.
		$bit_len = strlen( $bits );
		$idx     = 0;
		for ( $right = $size - 1; $right >= 1; $right -= 2 ) {
			if ( 6 === $right ) {
				$right = 5;
			}
			for ( $vert = 0; $vert < $size; $vert++ ) {
				for ( $j = 0; $j < 2; $j++ ) {
					$x       = $right - $j;
					$upward  = 0 === ( ( $right + 1 ) & 2 );
					$y       = $upward ? ( $size - 1 - $vert ) : $vert;
					if ( ! self::$fn[ $y ][ $x ] ) {
						$bit                = ( $idx < $bit_len ) ? (int) $bits[ $idx ] : 0;
						self::$m[ $y ][ $x ] = $bit;
						$idx++;
					}
				}
			}
		}

		// Mask 0: invert where (x + y) is even, on data modules only.
		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x < $size; $x++ ) {
				if ( ! self::$fn[ $y ][ $x ] && 0 === ( ( $x + $y ) % 2 ) ) {
					self::$m[ $y ][ $x ] ^= 1;
				}
			}
		}

		return self::$m;
	}

	private static function draw_finder( $cx, $cy ) {
		for ( $dy = -4; $dy <= 4; $dy++ ) {
			for ( $dx = -4; $dx <= 4; $dx++ ) {
				$x = $cx + $dx;
				$y = $cy + $dy;
				if ( $x < 0 || $y < 0 || $x >= self::$sz || $y >= self::$sz ) {
					continue;
				}
				$dist = max( abs( $dx ), abs( $dy ) );
				self::set_module( $x, $y, 2 !== $dist && 4 !== $dist );
			}
		}
	}

	private static function draw_alignment( $cx, $cy ) {
		for ( $dy = -2; $dy <= 2; $dy++ ) {
			for ( $dx = -2; $dx <= 2; $dx++ ) {
				self::set_module( $cx + $dx, $cy + $dy, 1 !== max( abs( $dx ), abs( $dy ) ) );
			}
		}
	}

	private static function draw_format_bits() {
		// EC level M (00) + mask 0 (000) → BCH-encoded, masked: 0x5412.
		$bits = 0x5412;
		$size = self::$sz;
		for ( $i = 0; $i <= 5; $i++ ) {
			self::set_module( 8, $i, ( $bits >> $i ) & 1 );
		}
		self::set_module( 8, 7, ( $bits >> 6 ) & 1 );
		self::set_module( 8, 8, ( $bits >> 7 ) & 1 );
		self::set_module( 7, 8, ( $bits >> 8 ) & 1 );
		for ( $i = 9; $i < 15; $i++ ) {
			self::set_module( 14 - $i, 8, ( $bits >> $i ) & 1 );
		}
		for ( $i = 0; $i < 8; $i++ ) {
			self::set_module( $size - 1 - $i, 8, ( $bits >> $i ) & 1 );
		}
		for ( $i = 8; $i < 15; $i++ ) {
			self::set_module( 8, $size - 15 + $i, ( $bits >> $i ) & 1 );
		}
		self::set_module( 8, $size - 8, 1 ); // dark module, always set last.
	}

	private static function draw_version_bits( $version ) {
		$bits = self::$version_bits[ $version ];
		$size = self::$sz;
		for ( $i = 0; $i < 18; $i++ ) {
			$bit = ( $bits >> $i ) & 1;
			$a   = $size - 11 + ( $i % 3 );
			$b   = intdiv( $i, 3 );
			self::set_module( $a, $b, $bit );
			self::set_module( $b, $a, $bit );
		}
	}

	private static function alignment_positions( $version ) {
		$tbl = array(
			1  => array(),
			2  => array( 6, 18 ),
			3  => array( 6, 22 ),
			4  => array( 6, 26 ),
			5  => array( 6, 30 ),
			6  => array( 6, 34 ),
			7  => array( 6, 22, 38 ),
			8  => array( 6, 24, 42 ),
			9  => array( 6, 26, 46 ),
			10 => array( 6, 28, 50 ),
		);
		return isset( $tbl[ $version ] ) ? $tbl[ $version ] : array();
	}

	private static function fallback_matrix( $data ) {
		// Last-resort visual placeholder (not scannable) so the template
		// never errors when a payload exceeds the supported size.
		$hash = md5( (string) $data );
		$size = 21;
		self::$sz = $size;
		self::$m  = array_fill( 0, $size, array_fill( 0, $size, 0 ) );
		self::$fn = array_fill( 0, $size, array_fill( 0, $size, false ) );
		self::draw_finder( 3, 3 );
		self::draw_finder( $size - 4, 3 );
		self::draw_finder( 3, $size - 4 );
		$bits = str_pad( base_convert( substr( $hash, 0, 16 ), 16, 2 ), 64, '0', STR_PAD_LEFT );
		for ( $y = 8; $y < $size - 8; $y++ ) {
			for ( $x = 8; $x < $size - 8; $x++ ) {
				self::$m[ $y ][ $x ] = (int) $bits[ ( $y * $size + $x ) % 64 ];
			}
		}
		return self::$m;
	}
}
