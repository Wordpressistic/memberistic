<?php
/**
 * Database schema.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema {
	/**
	 * Create or update plugin tables.
	 *
	 * A note on time zones, because this schema now mixes two conventions and
	 * the mixture is deliberate rather than an oversight.
	 *
	 * The original columns — `start_date`, `renewal_date`, `end_date`,
	 * `paid_at`, every `created_at` — are written with `current_time( 'mysql' )`
	 * and are therefore in the site's local time. Changing that would rewrite
	 * the meaning of every existing row, so it stays.
	 *
	 * The payment-integrity columns added in 2.1.0 are UTC, without exception:
	 * `last_provider_event_created_at`, `last_provider_synced_at`,
	 * `current_period_end`, `grace_period_ends_at`, and every datetime in
	 * `memberistic_payment_events`. They are compared against payment-provider
	 * timestamps, which are UTC epochs, and against each other to decide
	 * whether one event happened before another. Storing those in site-local
	 * time means an admin changing the site's time zone — or a DST boundary —
	 * silently reorders events, and event ordering is what stops a stale
	 * cancellation from taking away a member's access. The comparison has to
	 * be done in the provider's frame, so the storage is too.
	 *
	 * `Payment_Clock` in includes/payments/ is the only place that produces
	 * these values; use it rather than reaching for `current_time()`.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$prefix          = $wpdb->prefix;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "
CREATE TABLE {$prefix}memberistic_plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(191) NOT NULL,
  slug VARCHAR(191) NOT NULL,
  description TEXT NULL,
  monthly_price DECIMAL(10,2) DEFAULT 0.00,
  annual_price DECIMAL(10,2) DEFAULT 0.00,
  included_people INT DEFAULT 1,
  benefits LONGTEXT NULL,
  settings LONGTEXT NULL,
  is_featured TINYINT(1) DEFAULT 0,
  sort_order INT DEFAULT 0,
  status VARCHAR(50) DEFAULT 'active',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) {$charset_collate};
CREATE TABLE {$prefix}memberistic_memberships (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  membership_uuid VARCHAR(64) NOT NULL,
  primary_user_id BIGINT UNSIGNED NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  billing_cycle VARCHAR(20) NOT NULL,
  status VARCHAR(50) DEFAULT 'pending',
  start_date DATETIME NULL,
  renewal_date DATETIME NULL,
  end_date DATETIME NULL,
  cancelled_at DATETIME NULL,
  payment_source VARCHAR(50) NULL,
  billing_amount DECIMAL(10,2) NULL,
  stripe_customer_id VARCHAR(191) NULL,
  stripe_subscription_id VARCHAR(191) NULL,
  stripe_checkout_session_id VARCHAR(191) NULL,
  stripe_checkout_expires_at DATETIME NULL,
  billing_status VARCHAR(32) NULL,
  payment_provider VARCHAR(32) NULL,
  provider_account_id VARCHAR(64) NULL,
  provider_customer_id VARCHAR(191) NULL,
  provider_subscription_id VARCHAR(191) NULL,
  last_provider_event_id VARCHAR(191) NULL,
  last_provider_event_created_at DATETIME NULL,
  last_provider_synced_at DATETIME NULL,
  current_period_end DATETIME NULL,
  grace_period_ends_at DATETIME NULL,
  woo_customer_id BIGINT UNSIGNED NULL,
  woo_subscription_id BIGINT UNSIGNED NULL,
  pos_customer_id VARCHAR(191) NULL,
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY membership_uuid (membership_uuid),
  KEY primary_user_id (primary_user_id),
  KEY plan_id (plan_id),
  KEY stripe_checkout_session_id (stripe_checkout_session_id),
  KEY provider_subscription_id (provider_subscription_id),
  KEY billing_status (billing_status),
  KEY grace_period_ends_at (grace_period_ends_at),
  KEY status (status)
) {$charset_collate};
CREATE TABLE {$prefix}memberistic_people (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  membership_id BIGINT UNSIGNED NOT NULL,
  wp_user_id BIGINT UNSIGNED NULL,
  role VARCHAR(50) DEFAULT 'linked',
  full_name VARCHAR(191) NOT NULL,
  email VARCHAR(191) NULL,
  phone VARCHAR(50) NULL,
  date_of_birth DATE NULL,
  relationship VARCHAR(100) NULL,
  waiver_status VARCHAR(50) DEFAULT 'missing',
  waiver_signed_at DATETIME NULL,
  waiver_expires_at DATETIME NULL,
  waiver_renewal_reminded_at DATETIME NULL,
  status VARCHAR(50) DEFAULT 'active',
  notes TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY  (id),
  KEY membership_id (membership_id),
  KEY wp_user_id (wp_user_id),
  KEY email (email),
  KEY phone (phone),
  KEY waiver_status (waiver_status)
) {$charset_collate};
CREATE TABLE {$prefix}memberistic_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  membership_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency VARCHAR(10) DEFAULT 'USD',
  payment_method VARCHAR(50) NULL,
  payment_gateway VARCHAR(50) NULL,
  gateway_transaction_id VARCHAR(191) NULL,
  woo_order_id BIGINT UNSIGNED NULL,
  pos_order_id VARCHAR(191) NULL,
  status VARCHAR(50) DEFAULT 'pending',
  paid_at DATETIME NULL,
  raw_response LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY  (id),
  KEY membership_id (membership_id),
  KEY woo_order_id (woo_order_id),
  KEY status (status)
) {$charset_collate};
CREATE TABLE {$prefix}memberistic_activity (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  membership_id BIGINT UNSIGNED NULL,
  person_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  activity_type VARCHAR(100) NOT NULL,
  title VARCHAR(191) NULL,
  description TEXT NULL,
  related_object_type VARCHAR(100) NULL,
  related_object_id VARCHAR(191) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY membership_id (membership_id),
  KEY person_id (person_id),
  KEY activity_type (activity_type),
  KEY created_at (created_at)
) {$charset_collate};
CREATE TABLE {$prefix}memberistic_checkins (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  membership_id BIGINT UNSIGNED NOT NULL,
  person_id BIGINT UNSIGNED NOT NULL,
  booking_id BIGINT UNSIGNED NULL,
  pos_order_id VARCHAR(191) NULL,
  checkin_type VARCHAR(50) DEFAULT 'walk_in',
  status VARCHAR(50) DEFAULT 'checked_in',
  checked_in_by BIGINT UNSIGNED NULL,
  checked_in_at DATETIME NOT NULL,
  checked_out_at DATETIME NULL,
  notes TEXT NULL,
  PRIMARY KEY  (id),
  KEY membership_id (membership_id),
  KEY person_id (person_id),
  KEY booking_id (booking_id),
  KEY checked_in_at (checked_in_at)
) {$charset_collate};
CREATE TABLE {$prefix}memberistic_notes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  membership_id BIGINT UNSIGNED NOT NULL,
  person_id BIGINT UNSIGNED NULL,
  note TEXT NOT NULL,
  visibility VARCHAR(50) DEFAULT 'staff_only',
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY membership_id (membership_id),
  KEY person_id (person_id)
) {$charset_collate};
CREATE TABLE {$prefix}memberistic_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  level VARCHAR(50) DEFAULT 'info',
  source VARCHAR(100) NULL,
  message TEXT NOT NULL,
  context LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY level (level),
  KEY source (source),
  KEY created_at (created_at)
) {$charset_collate};
CREATE TABLE {$prefix}memberistic_rate_limits (
  rate_key_hash CHAR(64) NOT NULL,
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (rate_key_hash),
  KEY expires_at (expires_at)
) {$charset_collate};
CREATE TABLE {$prefix}memberistic_email_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  membership_id BIGINT UNSIGNED NULL,
  person_id BIGINT UNSIGNED NULL,
  template VARCHAR(100) NOT NULL,
  recipient VARCHAR(191) NOT NULL,
  subject VARCHAR(255) NULL,
  status VARCHAR(50) DEFAULT 'sent',
  error_message TEXT NULL,
  sent_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY membership_id (membership_id),
  KEY template (template),
  KEY recipient (recipient),
  KEY sent_at (sent_at)
) {$charset_collate};
CREATE TABLE {$prefix}memberistic_integrations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider VARCHAR(100) NOT NULL,
  status VARCHAR(50) DEFAULT 'disconnected',
  settings LONGTEXT NULL,
  last_synced_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY provider (provider)
) {$charset_collate};
CREATE TABLE {$prefix}memberistic_waiver_signatures (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  person_id BIGINT UNSIGNED NULL,
  membership_id BIGINT UNSIGNED NULL,
  signer_name VARCHAR(191) NULL,
  signer_email VARCHAR(191) NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'self_serve',
  signed_at DATETIME NOT NULL,
  expires_at DATETIME NULL,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  waiver_text LONGTEXT NULL,
  text_hash CHAR(64) NULL,
  attachment_id BIGINT UNSIGNED NULL,
  dob DATE NULL,
  phone VARCHAR(60) NULL,
  emergency_name VARCHAR(191) NULL,
  emergency_phone VARCHAR(60) NULL,
  minors_json LONGTEXT NULL,
  waiver_version_id BIGINT UNSIGNED NULL,
  station VARCHAR(100) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY membership_id (membership_id),
  KEY signed_at (signed_at)
) {$charset_collate};
CREATE TABLE {$prefix}memberistic_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  person_id BIGINT UNSIGNED NULL,
  membership_id BIGINT UNSIGNED NULL,
  signature_id BIGINT UNSIGNED NULL,
  file_path VARCHAR(255) NULL,
  file_name VARCHAR(191) NULL,
  mime VARCHAR(100) NULL,
  file_size BIGINT UNSIGNED NULL,
  label VARCHAR(191) NULL,
  doc_type VARCHAR(40) NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY membership_id (membership_id)
) {$charset_collate};
CREATE TABLE {$prefix}memberistic_waiver_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(191) NULL,
  body LONGTEXT NOT NULL,
  text_hash CHAR(64) NOT NULL,
  requires_reconsent TINYINT(1) NOT NULL DEFAULT 0,
  effective_from DATETIME NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY effective_from (effective_from)
) {$charset_collate};
CREATE TABLE {$prefix}memberistic_waivers_archive (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  first_name VARCHAR(120) NULL,
  last_name VARCHAR(120) NULL,
  email VARCHAR(191) NULL,
  phone VARCHAR(60) NULL,
  dob DATE NULL,
  signed_at DATETIME NULL,
  source VARCHAR(40) NULL,
  participant_type VARCHAR(40) NULL,
  external_url TEXT NULL,
  attachment_id BIGINT UNSIGNED NULL,
  local_path VARCHAR(255) NULL,
  minor_name VARCHAR(191) NULL,
  minor_age VARCHAR(20) NULL,
  emergency_name VARCHAR(191) NULL,
  emergency_phone VARCHAR(60) NULL,
  matched_user_id BIGINT UNSIGNED NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 1,
  dedupe_key VARCHAR(191) NULL,
  raw_json LONGTEXT NULL,
  import_batch VARCHAR(40) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY email (email),
  KEY last_name (last_name),
  KEY dob (dob),
  KEY matched_user_id (matched_user_id),
  KEY dedupe_key (dedupe_key)
) {$charset_collate};
CREATE TABLE {$prefix}memberistic_payment_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider VARCHAR(32) NOT NULL,
  provider_account_id VARCHAR(64) NOT NULL DEFAULT '',
  event_id VARCHAR(191) NOT NULL,
  event_type VARCHAR(100) NOT NULL DEFAULT '',
  provider_created_at DATETIME NULL,
  received_at DATETIME NOT NULL,
  processed_at DATETIME NULL,
  membership_id BIGINT UNSIGNED NULL,
  provider_customer_id VARCHAR(191) NULL,
  provider_subscription_id VARCHAR(191) NULL,
  payload_hash CHAR(64) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'received',
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  failure_code VARCHAR(64) NULL,
  failure_message TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY provider_event (provider,provider_account_id,event_id),
  KEY membership_id (membership_id),
  KEY provider_subscription_id (provider_subscription_id),
  KEY status (status),
  KEY received_at (received_at)
) {$charset_collate};
CREATE TABLE {$prefix}memberistic_payment_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id VARCHAR(191) NULL,
  event_type VARCHAR(100) NULL,
  provider VARCHAR(32) NOT NULL,
  provider_account_id VARCHAR(64) NOT NULL DEFAULT '',
  membership_id BIGINT UNSIGNED NULL,
  provider_subscription_id VARCHAR(191) NULL,
  previous_billing_status VARCHAR(32) NULL,
  new_billing_status VARCHAR(32) NULL,
  integrity_result VARCHAR(32) NOT NULL DEFAULT '',
  transition_result VARCHAR(32) NULL,
  reason_code VARCHAR(64) NOT NULL DEFAULT '',
  context LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY membership_id (membership_id),
  KEY event_id (event_id),
  KEY reason_code (reason_code),
  KEY created_at (created_at)
) {$charset_collate};
";

		dbDelta( $sql );
	}
}
