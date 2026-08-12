<?php
/**
 * UTC clock for payment-integrity timestamps.
 *
 * Every datetime the payment layer stores or compares goes through here, and
 * every one of them is UTC. That is not a style preference — it is the reason
 * out-of-order event protection can work at all.
 *
 * The rest of the plugin uses `current_time( 'mysql' )`, which returns the
 * site's local time. Compare a value from that against a Stripe `created`
 * timestamp, which is a UTC epoch, and the arithmetic is wrong by the site's
 * offset: on a UTC+13 site every inbound event looks thirteen hours old, and
 * the freshness check either rejects everything or accepts a genuinely stale
 * cancellation. Worse, an admin changing the site's time zone silently
 * reorders events already stored, so a comparison that was right yesterday is
 * wrong today. Neither failure announces itself.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Payment_Clock {
	/** MySQL DATETIME format. */
	const FORMAT = 'Y-m-d H:i:s';

	/**
	 * Current time, UTC, as a MySQL DATETIME.
	 *
	 * @return string
	 */
	public static function now() {
		return gmdate( self::FORMAT, self::timestamp() );
	}

	/**
	 * Current Unix timestamp.
	 *
	 * Wrapped so tests can freeze it without touching global state: the suite
	 * filters `memberistic_payment_clock_timestamp` rather than trying to
	 * convince PHP that time() is something else.
	 *
	 * @return int
	 */
	public static function timestamp() {
		/**
		 * Filters the payment layer's idea of "now", as a Unix timestamp.
		 *
		 * Intended for tests and for reproducing a support case against a
		 * fixed clock. Production code should not hook this: shifting the
		 * clock shifts event-freshness and grace-period decisions with it.
		 *
		 * @param int $timestamp Current Unix timestamp.
		 */
		return (int) apply_filters( 'memberistic_payment_clock_timestamp', time() );
	}

	/**
	 * Convert a provider epoch to a UTC MySQL DATETIME.
	 *
	 * @param mixed $timestamp Unix timestamp.
	 * @return string|null Null when the value is not a usable timestamp.
	 */
	public static function from_timestamp( $timestamp ) {
		if ( ! is_numeric( $timestamp ) ) {
			return null;
		}

		$timestamp = (int) $timestamp;
		if ( $timestamp <= 0 ) {
			return null;
		}

		return gmdate( self::FORMAT, $timestamp );
	}

	/**
	 * Convert a stored UTC MySQL DATETIME back to a Unix timestamp.
	 *
	 * @param mixed $datetime UTC datetime string.
	 * @return int|null Null when the value is empty or unparseable.
	 */
	public static function to_timestamp( $datetime ) {
		$datetime = trim( (string) $datetime );

		if ( '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
			return null;
		}

		// The ' UTC' suffix is what makes this correct: strtotime() would
		// otherwise interpret the string in PHP's default timezone, which
		// WordPress sets to UTC but a plugin or php.ini can change.
		$timestamp = strtotime( $datetime . ' UTC' );

		return false === $timestamp ? null : (int) $timestamp;
	}

	/**
	 * A UTC MySQL DATETIME `$seconds` from now.
	 *
	 * @param int $seconds Offset in seconds; negative for the past.
	 * @return string
	 */
	public static function in( $seconds ) {
		return gmdate( self::FORMAT, self::timestamp() + (int) $seconds );
	}

	/**
	 * Whether a stored UTC datetime is in the past.
	 *
	 * A NULL or empty value is not "in the past" — it is "not set", which for
	 * a grace deadline means no grace period is running, and treating that as
	 * elapsed would expire every membership that never had one.
	 *
	 * @param mixed $datetime UTC datetime string.
	 * @return bool
	 */
	public static function has_passed( $datetime ) {
		$timestamp = self::to_timestamp( $datetime );

		return null !== $timestamp && $timestamp <= self::timestamp();
	}
}
