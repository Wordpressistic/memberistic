<?php
/**
 * Formatting helpers.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Format money.
 *
 * @param mixed  $amount   Amount.
 * @param string $currency Currency.
 */
function memberistic_format_price( $amount, $currency = 'USD' ) {
	return sprintf( '%s %s', number_format_i18n( (float) $amount, 2 ), esc_html( $currency ) );
}

/**
 * Format date.
 *
 * @param mixed $date Date.
 */
function memberistic_format_date( $date ) {
	if ( empty( $date ) ) {
		return '&mdash;';
	}

	return esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $date ) ) );
}

/**
 * Return a status badge.
 *
 * @param string $status Status.
 */
function memberistic_status_badge( $status ) {
	$class_map = array(
		'active'    => 'success',
		'inactive'  => 'muted',
		'pending'   => 'warning',
		'past_due'  => 'danger',
		'expired'   => 'muted',
		'cancelled' => 'muted',
		'paused'    => 'info',
		'comped'    => 'success',
		'trial'     => 'info',
		'suspended' => 'danger',
		'needs_review' => 'warning',
		'missing'   => 'warning',
		'signed'    => 'success',
		'rejected'  => 'danger',
	);

	$class = $class_map[ $status ] ?? 'muted';
	$label = ucwords( str_replace( '_', ' ', $status ) );

	return sprintf(
		'<span class="memberistic-badge memberistic-badge-%1$s">%2$s</span>',
		esc_attr( $class ),
		esc_html( $label )
	);
}
