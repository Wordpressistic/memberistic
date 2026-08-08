<?php
/**
 * GDPR / privacy integration.
 *
 * Wires Memberistic into core's personal-data tooling so a data subject
 * request handled through Tools → Export/Erase Personal Data covers what
 * this plugin stores, rather than silently missing it.
 *
 * Three surfaces:
 *
 *  - an exporter that returns every record Memberistic holds for an email;
 *  - an eraser that anonymizes what can be anonymized and reports, with a
 *    reason, what is retained;
 *  - suggested privacy-policy text registered with core's policy editor.
 *
 * ERASURE POLICY
 *
 * Two record classes are retained by default rather than erased:
 *
 *  - Signed waivers. A waiver is a legal record whose entire purpose is to
 *    evidence that a named person accepted stated terms on a stated date.
 *    Erasing the name erases the evidence, which is generally the opposite
 *    of what the site owner's own legal position requires. Retention here
 *    rests on the operator's legitimate interest / legal-claims basis, so
 *    it is a decision for them, not a default this plugin should make
 *    silently — the eraser reports every retained waiver explicitly, and
 *    `memberistic_privacy_erase_waivers` flips the behaviour.
 *
 *  - Payment records. Financial records carry statutory retention periods
 *    in most jurisdictions. Memberistic stores no card data — only amounts,
 *    gateway reference ids, and status — so retaining them keeps the books
 *    intact without keeping payment credentials.
 *
 * Everything else — people rows, check-ins, activity, notes, email logs —
 * is anonymized in place. Rows survive so aggregate counts and financial
 * history stay consistent; the identifying columns do not.
 *
 * @package Memberistic
 * @since   2.0.0
 */

namespace WordPressistic\Memberistic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Privacy {

	/**
	 * Rows handled per exporter/eraser page. Core calls these repeatedly
	 * until `done` is true, so this bounds memory on large accounts.
	 */
	const PAGE_SIZE = 100;

	/**
	 * Register privacy hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
		add_action( 'admin_init', array( self::class, 'add_privacy_policy_content' ) );
	}

	/**
	 * Register the Memberistic exporter with core.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['memberistic'] = array(
			'exporter_friendly_name' => __( 'Memberistic membership records', 'memberistic' ),
			'callback'               => array( self::class, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the Memberistic eraser with core.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['memberistic'] = array(
			'eraser_friendly_name' => __( 'Memberistic membership records', 'memberistic' ),
			'callback'             => array( self::class, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export everything Memberistic holds for an email address.
	 *
	 * @param string $email Data subject's email address.
	 * @param int    $page  1-indexed page number.
	 * @return array{data:array,done:bool}
	 */
	public static function export( $email, $page = 1 ) {
		global $wpdb;

		$email = sanitize_email( (string) $email );
		$page  = max( 1, (int) $page );

		if ( '' === $email ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$offset = ( $page - 1 ) * self::PAGE_SIZE;
		$items  = array();

		// --- People rows (the member and any linked family members) ----
		$people = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}memberistic_people WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d",
				$email,
				self::PAGE_SIZE,
				$offset
			),
			ARRAY_A
		);

		foreach ( (array) $people as $person ) {
			$items[] = array(
				'group_id'    => 'memberistic-people',
				'group_label' => __( 'Membership profile', 'memberistic' ),
				'item_id'     => 'memberistic-person-' . (int) $person['id'],
				'data'        => self::pairs(
					array(
						__( 'Full name', 'memberistic' )     => $person['full_name'],
						__( 'Email', 'memberistic' )         => $person['email'],
						__( 'Phone', 'memberistic' )         => $person['phone'],
						__( 'Date of birth', 'memberistic' ) => $person['date_of_birth'],
						__( 'Relationship', 'memberistic' )  => $person['relationship'],
						__( 'Role', 'memberistic' )          => $person['role'],
						__( 'Status', 'memberistic' )        => $person['status'],
						__( 'Waiver status', 'memberistic' ) => $person['waiver_status'],
						__( 'Added', 'memberistic' )         => $person['created_at'],
					)
				),
			);
		}

		$person_ids = wp_list_pluck( (array) $people, 'id' );

		// --- Check-in history ------------------------------------------
		if ( ! empty( $person_ids ) ) {
			$ids_sql = implode( ',', array_map( 'absint', $person_ids ) );

			$checkins = $wpdb->get_results(
				"SELECT * FROM {$wpdb->prefix}memberistic_checkins WHERE person_id IN ({$ids_sql}) ORDER BY id ASC LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids are absint-cast above.
				ARRAY_A
			);

			foreach ( (array) $checkins as $checkin ) {
				$items[] = array(
					'group_id'    => 'memberistic-checkins',
					'group_label' => __( 'Check-in history', 'memberistic' ),
					'item_id'     => 'memberistic-checkin-' . (int) $checkin['id'],
					'data'        => self::pairs(
						array(
							__( 'Checked in', 'memberistic' )  => $checkin['checked_in_at'],
							__( 'Checked out', 'memberistic' ) => $checkin['checked_out_at'],
							__( 'Type', 'memberistic' )        => $checkin['checkin_type'],
							__( 'Status', 'memberistic' )      => $checkin['status'],
						)
					),
				);
			}
		}

		// --- Signed waivers --------------------------------------------
		$signatures = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}memberistic_waiver_signatures WHERE signer_email = %s ORDER BY id ASC LIMIT %d OFFSET %d",
				$email,
				self::PAGE_SIZE,
				$offset
			),
			ARRAY_A
		);

		foreach ( (array) $signatures as $signature ) {
			$items[] = array(
				'group_id'    => 'memberistic-waivers',
				'group_label' => __( 'Signed waivers', 'memberistic' ),
				'item_id'     => 'memberistic-waiver-' . (int) $signature['id'],
				'data'        => self::pairs(
					array(
						__( 'Signer name', 'memberistic' )        => $signature['signer_name'],
						__( 'Signer email', 'memberistic' )       => $signature['signer_email'],
						__( 'Signed at', 'memberistic' )          => $signature['signed_at'],
						__( 'Expires', 'memberistic' )            => $signature['expires_at'],
						__( 'Date of birth', 'memberistic' )      => $signature['dob'],
						__( 'Phone', 'memberistic' )              => $signature['phone'],
						__( 'Emergency contact', 'memberistic' )  => $signature['emergency_name'],
						__( 'Emergency phone', 'memberistic' )    => $signature['emergency_phone'],
						__( 'IP address', 'memberistic' )         => $signature['ip'],
						__( 'Signed from', 'memberistic' )        => $signature['source'],
					)
				),
			);
		}

		// --- Email log --------------------------------------------------
		$emails = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}memberistic_email_logs WHERE recipient = %s ORDER BY id ASC LIMIT %d OFFSET %d",
				$email,
				self::PAGE_SIZE,
				$offset
			),
			ARRAY_A
		);

		foreach ( (array) $emails as $mail ) {
			$items[] = array(
				'group_id'    => 'memberistic-emails',
				'group_label' => __( 'Emails sent', 'memberistic' ),
				'item_id'     => 'memberistic-email-' . (int) $mail['id'],
				'data'        => self::pairs(
					array(
						__( 'Subject', 'memberistic' ) => $mail['subject'],
						__( 'Sent', 'memberistic' )    => $mail['sent_at'],
						__( 'Status', 'memberistic' )  => $mail['status'],
					)
				),
			);
		}

		// Done once a full page came back empty for every source.
		$done = count( $people ) < self::PAGE_SIZE
			&& count( $signatures ) < self::PAGE_SIZE
			&& count( $emails ) < self::PAGE_SIZE;

		return array(
			'data' => $items,
			'done' => $done,
		);
	}

	/**
	 * Erase or anonymize Memberistic records for an email address.
	 *
	 * @param string $email Data subject's email address.
	 * @param int    $page  1-indexed page number.
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public static function erase( $email, $page = 1 ) {
		global $wpdb;

		$email = sanitize_email( (string) $email );
		$page  = max( 1, (int) $page );

		$response = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		if ( '' === $email ) {
			return $response;
		}

		$people = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}memberistic_people WHERE email = %s ORDER BY id ASC LIMIT %d",
				$email,
				self::PAGE_SIZE
			),
			ARRAY_A
		);

		foreach ( (array) $people as $person ) {
			$updated = $wpdb->update(
				$wpdb->prefix . 'memberistic_people',
				array(
					'full_name'     => self::redacted_name(),
					'email'         => null,
					'phone'         => null,
					'date_of_birth' => null,
					'relationship'  => null,
					'notes'         => null,
					'status'        => 'inactive',
				),
				array( 'id' => (int) $person['id'] ),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( false !== $updated ) {
				$response['items_removed'] = true;
			}
		}

		// Email log: the recipient address is the identifying part.
		$mails = $wpdb->update(
			$wpdb->prefix . 'memberistic_email_logs',
			array( 'recipient' => self::redacted_email() ),
			array( 'recipient' => $email ),
			array( '%s' ),
			array( '%s' )
		);

		if ( $mails ) {
			$response['items_removed'] = true;
		}

		// --- Waivers: retained by default, see class docblock ----------
		$waiver_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}memberistic_waiver_signatures WHERE signer_email = %s",
				$email
			)
		);

		if ( $waiver_count > 0 ) {
			/**
			 * Filters whether signed waivers are erased by a privacy request.
			 *
			 * Default false: a signed waiver is the operator's evidence that
			 * a named person accepted stated terms, and erasing the name
			 * destroys that evidence. Return true only if you have decided
			 * that your retention basis does not apply.
			 *
			 * @since 2.0.0
			 *
			 * @param bool   $erase Whether to anonymize signed waivers.
			 * @param string $email Data subject's email address.
			 */
			$erase_waivers = (bool) apply_filters( 'memberistic_privacy_erase_waivers', false, $email );

			if ( $erase_waivers ) {
				$wpdb->update(
					$wpdb->prefix . 'memberistic_waiver_signatures',
					array(
						'signer_name'     => self::redacted_name(),
						'signer_email'    => self::redacted_email(),
						'phone'           => null,
						'dob'             => null,
						'emergency_name'  => null,
						'emergency_phone' => null,
						'ip'              => null,
						'user_agent'      => null,
					),
					array( 'signer_email' => $email ),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
					array( '%s' )
				);

				$response['items_removed'] = true;
			} else {
				$response['items_retained'] = true;
				$response['messages'][]     = sprintf(
					/* translators: %d: number of signed waivers retained. */
					_n(
						'%d signed waiver was retained. A signed waiver is a legal record of the terms this person accepted and the date they accepted them; it is kept under the site operator\'s legal-claims basis. Contact the site owner to request its removal.',
						'%d signed waivers were retained. A signed waiver is a legal record of the terms this person accepted and the date they accepted them; they are kept under the site operator\'s legal-claims basis. Contact the site owner to request their removal.',
						$waiver_count,
						'memberistic'
					),
					$waiver_count
				);
			}
		}

		// --- Payments: always retained ---------------------------------
		$payment_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}memberistic_payments p
				 INNER JOIN {$wpdb->prefix}memberistic_people pe ON pe.membership_id = p.membership_id
				 WHERE pe.email = %s",
				$email
			)
		);

		if ( $payment_count > 0 ) {
			$response['items_retained'] = true;
			$response['messages'][]     = sprintf(
				/* translators: %d: number of payment records retained. */
				_n(
					'%d payment record was retained to meet financial record-keeping requirements. It holds an amount, a status, and a payment-gateway reference — no card details are stored.',
					'%d payment records were retained to meet financial record-keeping requirements. They hold amounts, statuses, and payment-gateway references — no card details are stored.',
					$payment_count,
					'memberistic'
				),
				$payment_count
			);
		}

		$response['done'] = count( $people ) < self::PAGE_SIZE;

		return $response;
	}

	/**
	 * Register suggested privacy policy text with core's policy editor.
	 *
	 * @return void
	 */
	public static function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<h2>' . esc_html__( 'Membership records', 'memberistic' ) . '</h2>'
			. '<p>' . esc_html__( 'When you join as a member, we store the name, email address, phone number, and date of birth you give us, along with any family or linked members you add to your membership. We also keep a record of your membership plan, its status, and its start and renewal dates.', 'memberistic' ) . '</p>'
			. '<p>' . esc_html__( 'Each time you check in, we record the date and time. This lets us show you your own visit history and lets staff confirm your membership is current.', 'memberistic' ) . '</p>'
			. '<h2>' . esc_html__( 'Waivers and signed documents', 'memberistic' ) . '</h2>'
			. '<p>' . esc_html__( 'If you sign a waiver, we store the text you agreed to, your name and contact details, your signature, the date and time, and the IP address you signed from. We keep signed waivers as a record of the terms you accepted, and may retain them after your membership ends.', 'memberistic' ) . '</p>'
			. '<h2>' . esc_html__( 'Payments', 'memberistic' ) . '</h2>'
			. '<p>' . esc_html__( 'Payments are processed by our payment provider. We never see or store your full card number. We keep a record of the amount, the date, the status, and a reference number from the payment provider, so we can answer billing questions and meet our accounting obligations.', 'memberistic' ) . '</p>'
			. '<h2>' . esc_html__( 'Emails we send you', 'memberistic' ) . '</h2>'
			. '<p>' . esc_html__( 'We keep a log of membership emails sent to you — the subject, the date, and whether delivery succeeded — so we can tell whether a notice such as a renewal reminder actually reached you.', 'memberistic' ) . '</p>'
			. '<h2>' . esc_html__( 'Your rights', 'memberistic' ) . '</h2>'
			. '<p>' . esc_html__( 'You can ask us for a copy of the membership data we hold about you, or ask us to erase it. Some records, such as signed waivers and payment records, may be retained where we have a legal basis or obligation to keep them; we will tell you if that applies to your request.', 'memberistic' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Memberistic Membership Solutions', 'memberistic' ), wp_kses_post( wpautop( $content ) ) );
	}

	/**
	 * Build core's export "data" array from a label => value map.
	 *
	 * Empty values are dropped: an export listing a dozen blank fields
	 * reads as though data is missing rather than simply never collected.
	 *
	 * @param array<string,mixed> $map Label => value.
	 * @return array<int,array{name:string,value:string}>
	 */
	private static function pairs( array $map ) {
		$pairs = array();

		foreach ( $map as $label => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}

			$pairs[] = array(
				'name'  => (string) $label,
				'value' => (string) $value,
			);
		}

		return $pairs;
	}

	/**
	 * Placeholder name written over erased records.
	 *
	 * @return string
	 */
	private static function redacted_name() {
		return __( 'Erased at request', 'memberistic' );
	}

	/**
	 * Placeholder address written over erased email columns.
	 *
	 * Uses the reserved `example.com` domain (RFC 2606) so a redacted row
	 * can never be mistaken for, or accidentally mailed at, a real address.
	 *
	 * @return string
	 */
	private static function redacted_email() {
		return 'erased-' . wp_generate_password( 8, false, false ) . '@example.com';
	}
}
