<?php
// Mobile OTA (Expo Updates protocol v1): publish, manifest, directives, signatures, retention.
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/i18n.php';
require_once __DIR__ . '/../lib/server.php';
require_once __DIR__ . '/../lib/stats.php';
require_once __DIR__ . '/../lib/geo.php';
require_once __DIR__ . '/../lib/panel.php';
require_once __DIR__ . '/../lib/admin-api.php';
require_once __DIR__ . '/../lib/expo.php';
use FLXUpdate\Expo;
use FLXUpdate\HttpError;
use FLXUpdate\Server;
use FLXUpdate\Settings;
use function FLXUpdate\load_config;

$count = 0;
function check( bool $ok, string $message ): void {
	global $count;
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
	$count++;
	echo "PASS $message\n";
}

$dir = sys_get_temp_dir() . '/flx-expo-' . bin2hex( random_bytes( 6 ) );
mkdir( $dir, 0700, true );
putenv( 'FLX_UPDATE_DATA_DIR=' . $dir );
putenv( 'FLX_UPDATE_BASE_URL=https://u.test' );
$KEY = 'flxota_' . str_repeat( 'ab', 24 );
Settings::update( $dir, array( 'ota_key_hash' => hash( 'sha256', $KEY ) ) );

// Key pair the way `expo-updates codesigning:generate` makes one.
$pk   = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
$csr  = openssl_csr_new( array( 'commonName' => 'FluxWP OTA' ), $pk, array( 'digest_alg' => 'sha256' ) );
$x509 = openssl_csr_sign( $csr, null, $pk, 3650, array( 'digest_alg' => 'sha256' ) );
openssl_x509_export( $x509, $cert );
$other = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
function sig( string $body, $key ): string {
	openssl_sign( $body, $raw, $key, OPENSSL_ALGO_SHA256 );
	return 'sig="' . base64_encode( $raw ) . '", keyid="main"';
}

/** POST to a publish endpoint with a body; returns [status, decoded]. */
function post( string $path, string $body, string $key ): array {
	$_SERVER['HTTP_X_FLUXWP_OTA_KEY'] = $key;
	$config = load_config( dirname( __DIR__ ) );
	$server = new Server( $config );
	ob_start();
	try {
		( new Expo( $server, $config, function () use ( $body ) { return $body; } ) )->handle( 'POST', $path );
		$code = 200;
	} catch ( HttpError $e ) {
		ob_end_clean();
		return array( $e->getCode(), array( 'code' => $e->reason, 'error' => $e->getMessage() ) );
	}
	return array( $code, json_decode( (string) ob_get_clean(), true ) );
}

/** GET through the real router with Expo headers; returns raw body. */
function get( string $path, array $headers ): string {
	foreach ( array_keys( $_SERVER ) as $k ) {
		if ( 0 === strpos( $k, 'HTTP_EXPO_' ) ) {
			unset( $_SERVER[ $k ] );
		}
	}
	foreach ( $headers as $k => $v ) {
		$_SERVER[ 'HTTP_' . strtoupper( str_replace( '-', '_', $k ) ) ] = $v;
	}
	$config = load_config( dirname( __DIR__ ) );
	ob_start();
	( new Server( $config ) )->handle( 'GET', $path );
	return (string) ob_get_clean();
}

function parts( string $raw ): array {
	$out = array();
	if ( ! preg_match( '/^--(\S+)\r\n/', $raw, $b ) ) {
		return $out;
	}
	foreach ( explode( '--' . $b[1], $raw ) as $chunk ) {
		if ( ! preg_match( '/name="([a-z]+)"\r\n(.*?)\r\n\r\n(.*)\r\n$/s', $chunk, $m ) ) {
			continue;
		}
		preg_match( '/expo-signature: (.+)/', $m[2], $s );
		$out[ $m[1] ] = array( 'body' => $m[3], 'sig' => isset( $s[1] ) ? trim( $s[1] ) : '' );
	}
	return $out;
}

function manifest( string $id, string $runtime, array $files ): string {
	$assets = array();
	foreach ( $files as $i => $f ) {
		$hex      = hash( 'sha256', $f[0] );
		$assets[] = array(
			'hash'          => Expo::b64url( hash( 'sha256', $f[0], true ) ),
			'key'           => md5( $f[0] ),
			'fileExtension' => '.' . $f[1],
			'contentType'   => 0 === $i ? 'application/javascript' : 'image/png',
			'url'           => 'https://u.test/v1/expo/flux/assets/' . $hex . '.' . ( 0 === $i ? 'js' : $f[1] ),
		);
	}
	return json_encode(
		array(
			'id'             => $id,
			'createdAt'      => '2026-11-18T10:00:00.000Z',
			'runtimeVersion' => $runtime,
			'launchAsset'    => array_shift( $assets ),
			'assets'         => $assets,
			'metadata'       => new stdClass(),
			'extra'          => array( 'expoClient' => array( 'name' => 'FluxWP' ) ),
		),
		JSON_UNESCAPED_SLASHES
	);
}

try {
	$bundle = 'var app = 1;';
	$png    = "\x89PNG fake";
	$rt     = 'a1b2c3d4e5f6';
	$id1    = '11111111-2222-4333-8444-555555555555';

	[ $c ] = post( '/v1/expo/flux/assets/missing', '{"hashes":[]}', 'wrong' );
	check( 401 === $c, 'Publish endpoints require the OTA key' );

	[ $c, $r ] = post( '/v1/expo/flux/assets/missing', json_encode( array( 'hashes' => array( hash( 'sha256', $bundle ), hash( 'sha256', $png ), '../x' ) ) ), $KEY );
	check( 200 === $c && 2 === count( $r['missing'] ), 'Missing-asset query lists only valid unknown hashes' );

	[ $c, $r ] = post( '/v1/expo/flux/assets/' . hash( 'sha256', 'other' ), $bundle, $KEY );
	check( 400 === $c && 'hash_mismatch' === $r['code'], 'Uploaded asset must match its sha256' );

	$m1 = manifest( $id1, $rt, array( array( $bundle, 'bundle' ), array( $png, 'png' ) ) );
	[ $c, $r ] = post( '/v1/expo/flux/publish', json_encode( array( 'channel' => 'production', 'updates' => array( array( 'platform' => 'ios', 'runtime' => $rt, 'manifest' => $m1, 'signature' => '' ) ) ) ), $KEY );
	check( 400 === $c && 'missing_asset' === $r['code'], 'Publish refuses manifests whose assets are not uploaded' );

	foreach ( array( $bundle, $png ) as $f ) {
		post( '/v1/expo/flux/assets/' . hash( 'sha256', $f ), $f, $KEY );
	}
	$bad = str_replace( 'https://u.test/', 'https://evil.test/', $m1 );
	[ $c, $r ] = post( '/v1/expo/flux/publish', json_encode( array( 'channel' => 'production', 'updates' => array( array( 'platform' => 'ios', 'runtime' => $rt, 'manifest' => $bad, 'signature' => '' ) ) ) ), $KEY );
	check( 400 === $c && 'bad_asset_url' === $r['code'], 'Asset URLs must point to this server' );

	Settings::update( $dir, array( 'ota_cert' => $cert ) );
	[ $c, $r ] = post( '/v1/expo/flux/publish', json_encode( array( 'channel' => 'production', 'updates' => array( array( 'platform' => 'ios', 'runtime' => $rt, 'manifest' => $m1, 'signature' => sig( $m1, $other ) ) ) ) ), $KEY );
	check( 400 === $c && 'bad_signature' === $r['code'], 'Wrong signing key is rejected when a certificate is saved' );

	$no = '{"type":"noUpdateAvailable"}';
	[ $c, $r ] = post(
		'/v1/expo/flux/publish',
		json_encode(
			array(
				'channel'   => 'production',
				'message'   => 'First OTA',
				'commit'    => 'abcdef1',
				'no_update' => array( 'body' => $no, 'signature' => sig( $no, $pk ) ),
				'updates'   => array( array( 'platform' => 'ios', 'runtime' => $rt, 'manifest' => $m1, 'signature' => sig( $m1, $pk ) ) ),
			)
		),
		$KEY
	);
	check( 200 === $c && $id1 === $r['updates'][0]['id'] && false === $r['updates'][0]['seen'], 'Signed publish accepted; reports that no device asked for this runtime yet' );

	$h   = array( 'expo-protocol-version' => '1', 'expo-platform' => 'ios', 'expo-runtime-version' => $rt, 'expo-channel-name' => 'production', 'expo-expect-signature' => 'sig, keyid="main", alg="rsa-v1_5-sha256"' );
	$p   = parts( get( '/v1/expo/flux/manifest', $h ) );
	check( isset( $p['manifest'] ) && $m1 === $p['manifest']['body'], 'Manifest bytes are served exactly as signed' );
	preg_match( '/sig="([^"]+)"/', $p['manifest']['sig'], $s );
	check( 1 === openssl_verify( $p['manifest']['body'], base64_decode( $s[1] ), $cert, OPENSSL_ALGO_SHA256 ), 'expo-signature verifies with the app certificate' );
	check( isset( $p['extensions'] ), 'Extensions part present' );

	$p = parts( get( '/v1/expo/flux/manifest', $h + array( 'expo-current-update-id' => $id1 ) ) );
	check( isset( $p['directive'] ) && $no === $p['directive']['body'] && '' !== $p['directive']['sig'], 'Running the active update gets a signed noUpdateAvailable' );

	$p = parts( get( '/v1/expo/flux/manifest', array( 'expo-runtime-version' => 'other' ) + $h ) );
	check( isset( $p['directive'] ) && ! isset( $p['manifest'] ), 'Unknown runtime gets noUpdateAvailable, never a mismatched bundle' );

	$p = parts( get( '/v1/expo/flux/manifest', array( 'expo-platform' => 'android' ) + $h ) );
	check( isset( $p['directive'] ), 'Other platform does not receive the iOS bundle' );

	$raw = get( '/v1/expo/flux/assets/' . hash( 'sha256', $bundle ) . '.js', array() );
	check( $bundle === $raw, 'Content-addressed asset served' );

	$ov = ( new Expo( new Server( load_config( dirname( __DIR__ ) ) ), load_config( dirname( __DIR__ ) ) ) )->overview();
	check( 1 === count( $ov['flux']['rows'] ) && 0 < $ov['flux']['rows'][0]['seen'] && 2 === count( $ov['flux']['seen_only'] ), 'Panel overview lists the slot, device checks and runtimes without updates' );

	// Second update, then pause and re-activate the first from history.
	$id2 = '22222222-2222-4333-8444-555555555555';
	$b2  = 'var app = 2;';
	post( '/v1/expo/flux/assets/' . hash( 'sha256', $b2 ), $b2, $KEY );
	$m2 = manifest( $id2, $rt, array( array( $b2, 'bundle' ), array( $png, 'png' ) ) );
	[ $c, $r ] = post( '/v1/expo/flux/publish', json_encode( array( 'channel' => 'production', 'updates' => array( array( 'platform' => 'ios', 'runtime' => $rt, 'manifest' => $m2, 'signature' => sig( $m2, $pk ) ) ) ) ), $KEY );
	check( 200 === $c && true === $r['updates'][0]['seen'], 'Second publish sees that devices use this runtime' );
	$p = parts( get( '/v1/expo/flux/manifest', $h ) );
	check( $m2 === $p['manifest']['body'], 'Newest update is served' );

	$cfg  = load_config( dirname( __DIR__ ) );
	$expo = new Expo( new Server( $cfg ), $cfg );
	check( $expo->activate( 'flux', 'production', 'ios', $rt, '' ), 'Pause from panel' );
	$p = parts( get( '/v1/expo/flux/manifest', $h + array( 'expo-current-update-id' => $id2 ) ) );
	check( isset( $p['directive'] ), 'Paused slot serves noUpdateAvailable' );
	check( $expo->activate( 'flux', 'production', 'ios', $rt, $id1 ), 'Re-activate an earlier signed update' );
	check( ! $expo->activate( 'flux', 'production', 'ios', $rt, '99999999-2222-4333-8444-555555555555' ), 'Cannot activate an id outside history' );
	$p = parts( get( '/v1/expo/flux/manifest', $h ) );
	check( $m1 === $p['manifest']['body'], 'Earlier update served after re-activation' );

	// Rollback to embedded.
	$rb = '{"type":"rollBackToEmbedded","parameters":{"commitTime":"2026-11-18T11:00:00.000Z"}}';
	[ $c ] = post( '/v1/expo/flux/rollback', json_encode( array( 'channel' => 'production', 'platform' => 'ios', 'runtime' => $rt, 'directive' => $rb, 'signature' => sig( $rb, $other ) ) ), $KEY );
	check( 400 === $c, 'Rollback directive must be signed with the right key' );
	[ $c ] = post( '/v1/expo/flux/rollback', json_encode( array( 'channel' => 'production', 'platform' => 'ios', 'runtime' => $rt, 'directive' => $rb, 'signature' => sig( $rb, $pk ) ) ), $KEY );
	check( 200 === $c, 'Signed rollback stored' );
	$emb = '33333333-2222-4333-8444-555555555555';
	$p   = parts( get( '/v1/expo/flux/manifest', $h + array( 'expo-current-update-id' => $id1, 'expo-embedded-update-id' => $emb ) ) );
	check( isset( $p['directive'] ) && $rb === $p['directive']['body'], 'Device on an OTA update is told to roll back to embedded' );
	$p = parts( get( '/v1/expo/flux/manifest', $h + array( 'expo-current-update-id' => $emb, 'expo-embedded-update-id' => $emb ) ) );
	check( isset( $p['directive'] ) && $no === $p['directive']['body'], 'Device already on embedded gets noUpdateAvailable' );

	// Retention: history capped, unused assets removed (after the grace hour).
	for ( $i = 0; $i < Expo::KEEP + 2; $i++ ) {
		$b  = 'var n = ' . $i . ';';
		$id = sprintf( '%08d-2222-4333-8444-555555555555', 40 + $i );
		post( '/v1/expo/flux/assets/' . hash( 'sha256', $b ), $b, $KEY );
		touch( $dir . '/expo/flux/assets/' . hash( 'sha256', $b ), time() - 7200 );
		$m = manifest( $id, $rt, array( array( $b, 'bundle' ) ) );
		post( '/v1/expo/flux/publish', json_encode( array( 'channel' => 'production', 'updates' => array( array( 'platform' => 'ios', 'runtime' => $rt, 'manifest' => $m, 'signature' => sig( $m, $pk ) ) ) ) ), $KEY );
	}
	$ov = $expo->overview();
	check( Expo::KEEP === count( $ov['flux']['rows'][0]['history'] ), 'History is capped' );
	check( ! is_file( $dir . '/expo/flux/assets/' . hash( 'sha256', 'var n = 0;' ) ), 'Assets of dropped updates are removed' );
	check( is_file( $dir . '/expo/flux/assets/' . hash( 'sha256', 'var n = ' . ( Expo::KEEP + 1 ) . ';' ) ), 'Assets of kept updates stay' );

	// Lockdown stops devices, not CI publishing.
	Settings::update( $dir, array( 'lockdown' => true ) );
	$out = get( '/v1/expo/flux/manifest', $h );
	check( false !== strpos( $out, '"paused"' ), 'Emergency stop pauses device checks' );
	[ $c ] = post( '/v1/expo/flux/assets/missing', '{"hashes":[]}', $KEY );
	check( 200 === $c, 'Publishing still works during an emergency stop' );
	check( Expo::is_publish_path( '/v1/expo/flux/publish' ) && ! Expo::is_publish_path( '/v1/expo/flux/manifest' ), 'Path classifier' );

	echo "$count checks passed\n";
} finally {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
	rmdir( $dir );
}
