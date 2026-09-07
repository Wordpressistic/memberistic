<?php
/** Standalone regression checks for the real premium access gate. */
namespace WPistic\Sdk {
	class WpisticClient {
		public $state = array();
		public $features = array();
		public function status() { return $this->state; }
		public function entitlements() { return new EntitlementChecker( $this->features ); }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_.-]/', '', strtolower( $value ) ); }
	function apply_filters( $name, $value, ...$args ) { return $value; }
	require __DIR__ . '/../includes/wpistic-sdk/EntitlementChecker.php';
	require __DIR__ . '/../includes/class-licensing.php';
	$client = new \WPistic\Sdk\WpisticClient();
	$property = new \ReflectionProperty( \WordPressistic\Memberistic\Licensing::class, 'client' );
	$property->setAccessible( true );
	$property->setValue( null, $client );
	$cases = array(
		array( array(), array(), 'reports', false ),
		array( array( 'connected' => true, 'active' => false, 'status' => 'expired' ), array( 'memberistic.pro.enabled' => true ), 'reports', false ),
		array( array( 'connected' => true, 'active' => true ), array(), 'reports', false ),
		array( array( 'connected' => true, 'active' => true ), array( 'memberistic.pro.enabled' => true ), 'reports', true ),
		array( array( 'connected' => true, 'active' => true ), array( 'memberistic.pro.enabled' => true, 'memberistic.reports' => false ), 'reports', false ),
		array( array( 'connected' => true, 'active' => true ), array( 'memberistic.reports' => true ), 'memberistic.reports', true ),
		array( array( 'connected' => true, 'active' => true ), array( 'memberistic.pro.enabled' => 'true' ), 'reports', false ),
		array( array( 'connected' => true, 'active' => true ), array( 'memberistic.pro.enabled' => true ), '', false ),
	);
	foreach ( $cases as $index => $case ) {
		list( $client->state, $client->features, $feature, $expected ) = $case;
		$actual = \WordPressistic\Memberistic\Licensing::can_use( $feature );
		if ( $actual !== $expected ) { throw new \RuntimeException( 'Licensing case failed: ' . $index ); }
	}
	echo count( $cases ) . " licensing checks passed\n";
}
