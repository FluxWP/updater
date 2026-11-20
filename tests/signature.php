<?php
// Package signatures: release asset <slug>.zip.sig is picked up and the served file format is checked.
require_once __DIR__ . '/../lib/server.php';
use FLXUpdate\Server;
function same( $actual, $expected, string $message ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $message . ': ' . var_export( $actual, true ) );
	}
}
$ok = json_encode( array( 'v' => 1, 'kid' => 'be8d9cfa9f2229b8', 'sig' => base64_encode( str_repeat( 'a', 64 ) ) ) );
same( Server::valid_signature_file( $ok . "\n" ), true, 'Valid signature file' );
same( Server::valid_signature_file( json_encode( array( 'v' => 1, 'kid' => 'be8d9cfa9f2229b8', 'sig' => base64_encode( 'short' ) ) ) ), false, 'Short signature' );
same( Server::valid_signature_file( json_encode( array( 'v' => 2, 'kid' => 'be8d9cfa9f2229b8', 'sig' => base64_encode( str_repeat( 'a', 64 ) ) ) ) ), false, 'Unknown version' );
same( Server::valid_signature_file( '<html>' ), false, 'HTML error page' );

$parse = new ReflectionMethod( Server::class, 'parse_release' );
$parse->setAccessible( true );
$server = ( new ReflectionClass( Server::class ) )->newInstanceWithoutConstructor();
$rel = array(
	'tag_name' => 'flx-ai-v0.33.4',
	'body'     => '',
	'assets'   => array(
		array( 'id' => 11, 'name' => 'flx-ai.zip', 'size' => 100 ),
		array( 'id' => 12, 'name' => 'flx-ai.zip.sig', 'size' => 120 ),
	),
);
$r = $parse->invoke( $server, $rel );
same( array( $r['asset_id'], $r['sig_asset_id'] ), array( 11, 12 ), 'Zip and signature assets' );
$rel['assets'] = array( $rel['assets'][1], $rel['assets'][0] );
same( $parse->invoke( $server, $rel )['sig_asset_id'], 12, 'Asset order does not matter' );
$rel['assets'] = array( array( 'id' => 11, 'name' => 'flx-ai.zip', 'size' => 100 ), array( 'id' => 13, 'name' => 'other.zip.sig', 'size' => 1 ) );
same( $parse->invoke( $server, $rel )['sig_asset_id'], 0, 'Another package\'s signature is ignored' );
echo "Signature checks passed.\n";
