<?php
/**
 * Security and sanitization helpers.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitize a text value.
 *
 * @param mixed $value Raw value.
 */
function memberistic_sanitize_text( $value ) {
	return sanitize_text_field( wp_unslash( (string) $value ) );
}

/**
 * Sanitize a textarea value.
 *
 * @param mixed $value Raw value.
 */
function memberistic_sanitize_textarea( $value ) {
	return sanitize_textarea_field( wp_unslash( (string) $value ) );
}

/**
 * Sanitize a price value.
 *
 * @param mixed $value Raw value.
 */
function memberistic_sanitize_price( $value ) {
	return number_format( max( 0, (float) $value ), 2, '.', '' );
}

/**
 * Sanitize yes/no fields.
 *
 * @param mixed $value Raw value.
 */
function memberistic_sanitize_yes_no( $value ) {
	return 'yes' === $value || '1' === (string) $value ? 'yes' : 'no';
}

/**
 * Sanitize a hex color.
 *
 * @param mixed  $value   Raw value.
 * @param string $default Default color.
 */
function memberistic_sanitize_hex_color( $value, $default = '#0F2044' ) {
	$color = sanitize_hex_color( wp_unslash( (string) $value ) );
	return $color ? $color : $default;
}

/**
 * Validate plan/membership status.
 *
 * @param mixed  $status  Raw status.
 * @param string $default Default status.
 */
function memberistic_validate_status( $status, $default = 'active' ) {
	$status = sanitize_key( wp_unslash( (string) $status ) );
	$valid  = array( 'active', 'inactive', 'pending', 'past_due', 'expired', 'cancelled', 'paused', 'comped', 'trial', 'suspended', 'needs_review' );

	return in_array( $status, $valid, true ) ? $status : $default;
}

/**
 * Validate membership billing cycle.
 *
 * @param mixed $cycle Raw cycle.
 */
function memberistic_validate_billing_cycle( $cycle ) {
	$cycle = sanitize_key( wp_unslash( (string) $cycle ) );
	return in_array( $cycle, array( 'monthly', 'annual' ), true ) ? $cycle : 'monthly';
}

/**
 * Validate email.
 *
 * @param mixed $email Raw email.
 */
function memberistic_validate_email( $email ) {
	$email = sanitize_email( wp_unslash( (string) $email ) );
	return is_email( $email ) ? $email : '';
}

/**
 * Verify an admin nonce.
 *
 * @param string $action Action.
 * @param string $field  Field name.
 */
function memberistic_verify_admin_nonce( $action, $field = '_wpnonce' ) {
	$nonce = isset( $_REQUEST[ $field ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $field ] ) ) : '';
	return (bool) wp_verify_nonce( $nonce, $action );
}

/**
 * Check capability or manage_options fallback.
 *
 * @param string $capability Capability.
 */
function memberistic_current_user_can( $capability ) {
	return current_user_can( $capability ) || current_user_can( 'manage_options' );
}

/**
 * Return wpdb formats matching field names.
 *
 * @param array<string, mixed> $data Data.
 * @return array<int, string>
 */
function memberistic_db_formats( $data ) {
	$integer_fields = array(
		'id',
		'included_people',
		'is_featured',
		'sort_order',
		'membership_id',
		'person_id',
		'user_id',
		'primary_user_id',
		'plan_id',
		'woo_customer_id',
		'woo_subscription_id',
		'woo_order_id',
		'created_by',
	);
	$decimal_fields = array( 'monthly_price', 'annual_price', 'amount' );
	$formats        = array();

	foreach ( array_keys( $data ) as $field ) {
		if ( in_array( $field, $integer_fields, true ) ) {
			$formats[] = '%d';
		} elseif ( in_array( $field, $decimal_fields, true ) ) {
			$formats[] = '%f';
		} else {
			$formats[] = '%s';
		}
	}

	return $formats;
}
