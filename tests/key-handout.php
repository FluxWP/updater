<?php
// Keyless records are keyed by install id. A panel handout is single-use and expires.
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
use FLXUpdate\Stats;
use function FLXUpdate\load_config;

function same( $actual, $expected, string $message ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $message . ' (got ' . var_export( $actual, true ) . ')' );
	}
}

function call( string $dir, string $method, string $route, string $key, array $body = array() ): array {
	$_SERVER['HTTP_X_FLUXWP_MANAGER_KEY'] = $key;
	$_GET                                = array( 'lang' => 'en' );
	$_POST                               = $body;
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

function deliver( string $dir, string $install ): string {
	$m = new ReflectionMethod( Server::class, 'assigned_key' );
	$m->setAccessible( true );
	return $m->invoke( new Server( load_config( $dir ) ), $install );
}

function pending_ih( string $dir, string $ih ): bool {
	foreach ( (array) ( Settings::load( $dir )['sites'] ?? array() ) as $conf ) {
		$p = is_array( $conf['pending'] ?? null ) ? $conf['pending'] : null;
		$got = (string) ( $p['ih'] ?? '' );
		if ( 64 === strlen( $got ) && hash_equals( $got, $ih ) ) {
			return true;
		}
	}
	return false;
}

$dir = sys_get_temp_dir() . '/flx-key-handout-' . bin2hex( random_bytes( 8 ) );
mkdir( $dir, 0700, true );
putenv( 'FLX_UPDATE_DATA_DIR=' . $dir );
$manager = 'flxm_' . bin2hex( random_bytes( 24 ) );
$url     = 'https://customer.example/blog';
$a       = bin2hex( random_bytes( 20 ) );
$b       = bin2hex( random_bytes( 20 ) );
$ih_a    = Server::install_hash( $a );
$ih_b    = Server::install_hash( $b );
$id_a    = Stats::install_client_id( $ih_a );
$id_b    = Stats::install_client_id( $ih_b );
$anon    = array( 'anon' => true, 'hash' => '' );

try {
	same( strlen( $a ), 40, 'Fixture install id matches the client (20 bytes hex)' );
	same( Server::install_hash( '' ), '', 'Empty install id is rejected' );
	same( Server::install_hash( strtoupper( $a ) ), '', 'Uppercase install id is rejected' );
	same( Server::install_hash( substr( $a, 0, 39 ) ), '', 'Short install id is rejected' );
	same( Server::install_hash( $a . 'aa' ), '', 'Long install id is rejected' );
	same( Server::install_hash( str_repeat( 'g', 40 ) ), '', 'Non-hex install id is rejected' );
	same( $id_a === $id_b, false, 'Two install ids produce two record ids' );

	Settings::update(
		$dir,
		array(
			'admin_user'          => 'admin',
			'admin_password_hash' => password_hash( 'long-password-1', PASSWORD_DEFAULT ),
			'secret'              => bin2hex( random_bytes( 32 ) ),
			'manager_keys'        => array( hash( 'sha256', $manager ) => array( 'name' => 'Test', 'created' => time(), 'last' => 0, 'hint' => substr( $manager, -4 ) ) ),
		)
	);

	$stats = new Stats( $dir );
	$stats->record_check( $anon, array( 'site' => $url, 'install' => $a, 'usage' => true ) );
	$stats->client( $id_a );
	$legacy_ih = hash( 'sha256', 'legacy-url-row' );
	$legacy_id = Stats::client_id( array( 'hash' => '' ), $url, '' );
	$raw       = json_decode( (string) file_get_contents( $stats->file() ), true );
	$raw['clients'][ $legacy_id ] = array(
		'url'   => 'https://customer.example/blog',
		'ih'    => $legacy_ih,
		'first' => time() - 50,
		'last'  => time() - 50,
		'n'     => 2,
	);
	$raw['clients']['old-shape'] = array( 'url' => 'https://old.example', 'first' => time(), 'last' => time(), 'n' => 1 );
	file_put_contents( $stats->file(), json_encode( $raw ) );

	$stats->record_check( $anon, array( 'site' => $url, 'install' => $b, 'usage' => true ) );
	$stats->record_check( $anon, array( 'site' => $url, 'install' => strtoupper( $a ), 'usage' => true ) );
	$row_a = $stats->client( $id_a );
	$row_b = $stats->client( $id_b );
	$legacy = $stats->client( $legacy_id );
	same( is_array( $row_a ) && hash_equals( $ih_a, (string) $row_a['ih'] ), true, 'First install id is kept after a second install reports the same URL' );
	same( is_array( $row_b ) && hash_equals( $ih_b, (string) $row_b['ih'] ), true, 'Second install id is stored as its own record' );
	same( (string) ( $row_a['url'] ?? '' ), 'https://customer.example/blog', 'Declared URL is normalized onto the install record' );
	same( (string) ( $row_a['pfx'] ?? '' ), substr( $a, 0, 8 ), 'Only a short prefix of the install id is stored' );
	same( is_array( $legacy ) && hash_equals( $legacy_ih, (string) $legacy['ih'] ), true, 'A URL-keyed row keeps its install id when another install reports that URL' );
	same( isset( Stats::shared_urls( array( $id_a => $row_a, $id_b => $row_b ) )['https://customer.example/blog'] ), true, 'Two install ids for one URL are flagged' );
	$stats->summary( 7 );

	$stats->record_check( $anon, array( 'site' => $url, 'install' => $a, 'usage' => true ) );
	same( hash_equals( $ih_a, (string) $stats->client( $id_a )['ih'] ), true, 'Repeating the first install id does not replace it' );
	same( hash_equals( $ih_b, (string) $stats->client( $id_b )['ih'] ), true, 'Repeating the first install id does not touch the second record' );

	list( $code, $denied ) = call( $dir, 'POST', 'action', $manager, array( 'action' => 'issue_key', 'client' => $legacy_id ) );
	same( array( $code, $denied['ok'] ?? null, $denied['code'] ?? '' ), array( 422, false, 'noclient' ), 'A URL-keyed row cannot be given a key' );

	list( $code, $issued ) = call( $dir, 'POST', 'action', $manager, array( 'action' => 'issue_key', 'client' => $id_a ) );
	$key = (string) ( $issued['data']['new_key'] ?? '' );
	same( $issued['ok'] ?? false, true, 'A key can be issued to the targeted install record' );
	same( 1 === preg_match( '/^flxu_[a-f0-9]{48}$/', $key ), true, 'Issued key has the site-key shape' );
	same( deliver( $dir, $b ), '', 'The other install id does not receive the key' );
	same( pending_ih( $dir, $ih_a ), true, 'A rejected claim leaves the handout in place' );
	same( deliver( $dir, $a ), $key, 'The targeted install id receives the key' );
	same( deliver( $dir, $a ), '', 'The handout is single-use' );
	same( pending_ih( $dir, $ih_a ), false, 'The handout is cleared after delivery' );

	list( $code, $issued_b ) = call( $dir, 'POST', 'action', $manager, array( 'action' => 'issue_key', 'client' => $id_b ) );
	same( $issued_b['ok'] ?? false, true, 'A second record can be issued its own key' );
	$sites = Settings::load( $dir )['sites'];
	foreach ( $sites as $h => $conf ) {
		if ( is_array( $conf['pending'] ?? null ) && hash_equals( (string) $conf['pending']['ih'], $ih_b ) ) {
			$sites[ $h ]['pending']['exp'] = time() - 30;
		}
	}
	Settings::update( $dir, array( 'sites' => $sites ) );
	same( deliver( $dir, $b ), '', 'An expired handout is not delivered' );
	same( pending_ih( $dir, $ih_b ), true, 'An expired handout is left for the panel to show' );

	$all      = json_decode( (string) file_get_contents( $stats->file() ), true );
	$keyed_id = 'k' . str_repeat( 'ab', 8 );
	$all['clients'][ $keyed_id ] = array( 'key' => true, 'url' => 'https://keyed.example', 'first' => time() - 400000, 'last' => time() - 400000, 'n' => 4 );
	$stale_ih = hash( 'sha256', 'stale-keyless' );
	$stale_id = Stats::install_client_id( $stale_ih );
	$all['clients'][ $stale_id ] = array( 'key' => false, 'ih' => $stale_ih, 'url' => 'https://stale.example', 'first' => time() - 300000, 'last' => time() - 300000, 'n' => 1 );
	for ( $i = 0; $i < Stats::MAX_KEYLESS; $i++ ) {
		$flood_ih = hash( 'sha256', 'flood-' . $i );
		$all['clients'][ Stats::install_client_id( $flood_ih ) ] = array(
			'key'   => false,
			'ih'    => $flood_ih,
			'url'   => 'https://flood.example/' . $i,
			'first' => time() - 1000,
			'last'  => time() - 1000 + $i,
			'n'     => 1,
		);
	}
	file_put_contents( $stats->file(), json_encode( $all ) );
	$fresh    = bin2hex( random_bytes( 20 ) );
	$fresh_id = Stats::install_client_id( Server::install_hash( $fresh ) );
	$stats->record_check( $anon, array( 'site' => 'https://fresh.example', 'install' => $fresh ) );
	$stats->client( $fresh_id );
	$left    = json_decode( (string) file_get_contents( $stats->file() ), true );
	$keyless = 0;
	foreach ( (array) ( $left['clients'] ?? array() ) as $c ) {
		if ( is_array( $c ) && empty( $c['key'] ) ) {
			++$keyless;
		}
	}
	same( $keyless <= Stats::MAX_KEYLESS, true, 'Keyless rows stay within the cap' );
	same( isset( $left['clients'][ $keyed_id ] ), true, 'Keyed rows are not dropped to make room for keyless rows' );
	same( isset( $left['clients'][ $stale_id ] ), false, 'The stalest keyless row is dropped' );
	same( isset( $left['clients'][ $fresh_id ] ), true, 'The row just reported is kept' );
	$stats->summary( 30 );

	echo "Key handout checks passed.\n";
} finally {
	$files = array_merge( glob( $dir . '/*' ) ?: array(), glob( $dir . '/.*' ) ?: array() );
	foreach ( $files as $file ) {
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}
	@rmdir( $dir );
}
