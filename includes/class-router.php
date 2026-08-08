<?php
/**
 * Route name holder for future internal routing.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Router {
	public const ADMIN_DASHBOARD = 'memberistic-dashboard';
	public const ADMIN_MEMBERS   = 'memberistic-members';
	public const ADMIN_PLANS     = 'memberistic-plans';
	public const ADMIN_SETTINGS  = 'memberistic-settings';
}
