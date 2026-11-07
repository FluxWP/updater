<?php
// Regression: a package moved from a public repository to a private one must not stay downloadable without a key.
require_once __DIR__ . '/../lib/server.php';
use FLXUpdate\Server;
function same( $actual, $expected, string $message ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $message . ': ' . var_export( $actual, true ) );
	}
}
$items = array(
	array( 'slug' => 'moved-plugin', 'version' => '0.2.0', 'repo' => 'Example/public', 'public' => true ),
	array( 'slug' => 'moved-plugin', 'version' => '0.2.1', 'repo' => 'Example/private', 'public' => false ),
	array( 'slug' => 'open-plugin', 'version' => '1.0.0', 'repo' => 'Example/public', 'public' => true ),
	array( 'slug' => 'closed-plugin', 'version' => '1.0.0', 'repo' => 'Example/private', 'public' => false ),
);
$out  = Server::drop_shadowed( $items );
$keys = array_map(
	static function ( $r ) {
		return $r['slug'] . '@' . $r['repo'];
	},
	$out
);
same( $keys, array( 'moved-plugin@Example/private', 'open-plugin@Example/public', 'closed-plugin@Example/private' ), 'Public copy of a private package must be dropped' );
same( Server::drop_shadowed( array( $items[0], $items[2] ) ), array( $items[0], $items[2] ), 'Public-only list must stay unchanged' );
echo "Private move regression checks passed.\n";
