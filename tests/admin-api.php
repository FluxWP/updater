<?php
// Updater Manager admin API: key check, panel actions over JSON, denied actions.
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/i18n.php';
require_once __DIR__ . '/../lib/server.php';
require_once __DIR__ . '/../lib/stats.php';
require_once __DIR__ . '/../lib/geo.php';
require_once __DIR__ . '/../lib/panel.php';
require_once __DIR__ . '/../lib/admin-api.php';
use FLXUpdate\AdminApi;
use FLXUpdate\HttpError;
use FLXUpdate\Server;
use FLXUpdate\Settings;
use function FLXUpdate\load_config;

function same( $actual, $expected, string $message ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $message . ' (got ' . var_export( $actual, true ) . ')' );
	}
}
/** Runs one API request and returns [http status, decoded body]. */
function call( string $dir, string $method, string $route, string $key, array $body = array() ): array {
	$_SERVER['HTTP_X_FLUXWP_MANAGER_KEY'] = $key;
	$_GET                                = array( 'lang' => 'en' );
	$_POST                               = $body; // php://input is empty on the CLI; the API falls back to form fields.
	$config                              = load_config( $dir );
	ob_start();
	try {
		( new AdminApi( new Server( $config ), $config ) )->handle( $method, $route );
		$code = http_response_code() ?: 200;
	} catch ( HttpError $e ) {
		ob_end_clean();
		return array( $e->getCode(), array( 'code' => $e->reason ) );
	}
	return array( $code, json_decode( (string) ob_get_clean(), true ) );
}

$dir = sys_get_temp_dir() . '/flx-admin-api-' . bin2hex( random_bytes( 8 ) );
mkdir( $dir, 0700, true );
putenv( 'FLX_UPDATE_DATA_DIR=' . $dir );
$key = 'flxm_' . bin2hex( random_bytes( 24 ) );
try {
	Settings::update(
		$dir,
		array(
			'admin_user'          => 'admin',
			'admin_password_hash' => password_hash( 'long-password-1', PASSWORD_DEFAULT ),
			'manager_keys'        => array( hash( 'sha256', $key ) => array( 'name' => 'Test', 'created' => time(), 'last' => 0, 'hint' => substr( $key, -4 ) ) ),
		)
	);

	same( call( $dir, 'GET', 'info', 'flxm_' . str_repeat( 'a', 48 ) )[0], 401, 'Unknown manager key must be rejected' );
	same( call( $dir, 'GET', 'info', '' )[0], 401, 'Missing manager key must be rejected' );
	list( $code, $info ) = call( $dir, 'GET', 'info', $key );
	same( $code, 200, 'Valid manager key must be accepted' );
	same( $info['key_name'], 'Test', 'Info must name the key' );
	same( Settings::load( $dir )['manager_keys'][ hash( 'sha256', $key ) ]['last'] > 0, true, 'Last use must be recorded' );

	list( $code, $r ) = call( $dir, 'POST', 'action', $key, array( 'action' => 'save_switch', 'switch' => 'lockdown', 'on' => '1' ) );
	same( $r['ok'], true, 'Panel action must run over the API' );
	same( Settings::load( $dir )['lockdown'], true, 'Lockdown must be saved' );
	same( call( $dir, 'GET', 'info', $key )[1]['lockdown'], true, 'The API must keep working while distribution is paused' );

	list( $code, $r ) = call( $dir, 'POST', 'action', $key, array( 'action' => 'add_site', 'name' => 'Example', 'scope' => 'all', 'channel' => 'beta' ) );
	same( 1 === preg_match( '/^flxu_[a-f0-9]{48}$/', (string) ( $r['data']['new_key'] ?? '' ) ), true, 'New site key must be returned once' );
	$site = Settings::load( $dir )['sites'][ hash( 'sha256', $r['data']['new_key'] ) ] ?? null;
	same( $site['channel'] ?? '', 'beta', 'Site must be stored with its channel' );
	same( call( $dir, 'POST', 'test-key', $key, array( 'key' => $r['data']['new_key'] ) )[1]['valid'], true, 'Test key must find the new site' );

	list( $code, $r ) = call( $dir, 'POST', 'action', $key, array( 'action' => 'add_site', 'name' => '' ) );
	same( array( $code, $r['ok'] ), array( 422, false ), 'Validation errors must come back as 422' );
	same( call( $dir, 'POST', 'action', $key, array( 'action' => 'gen_manager_key' ) )[1]['code'], 'action_denied', 'Manager keys cannot be created over the API' );
	same( call( $dir, 'POST', 'action', $key, array( 'action' => 'logout' ) )[1]['code'], 'action_denied', 'Session actions are denied' );
	same( call( $dir, 'POST', 'action', $key, array( 'action' => 'no_such' ) )[1]['code'], 'unknown_action', 'Unknown actions are reported' );

	list( $code, $s ) = call( $dir, 'GET', 'settings', $key );
	same( count( $s['sites'] ), 1, 'Settings must list the sites' );
	same( $s['manager_keys'][0]['current'], true, 'Settings must mark the calling key' );
	same( isset( $s['github']['token'] ), false, 'The GitHub token is never returned' );

	call( $dir, 'POST', 'action', $key, array( 'action' => 'revoke_manager_key', 'hash' => hash( 'sha256', $key ) ) );
	same( call( $dir, 'GET', 'info', $key )[0], 401, 'A revoked key stops working' );
	echo "Admin API checks passed.\n";
} finally {
	foreach ( glob( $dir . '/*' ) as $file ) {
		unlink( $file );
	}
	foreach ( glob( $dir . '/.*.lock' ) ?: array() as $file ) {
		unlink( $file );
	}
	@rmdir( $dir );
}
