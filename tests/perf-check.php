<?php
// Check stats fold into the same totals as a synchronous write, and a second catalog refresh does not call GitHub.
require_once __DIR__ . '/../lib/server.php';
require_once __DIR__ . '/../lib/stats.php';
require_once __DIR__ . '/../lib/previews.php';
use FLXUpdate\Server;
use FLXUpdate\Stats;

function same( $actual, $expected, string $message ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $message . ' (got ' . var_export( $actual, true ) . ')' );
	}
}

function stamp( array $summary ): array {
	$clients = array();
	foreach ( $summary['clients'] as $id => $c ) {
		$clients[ $id ] = array(
			'n'  => (int) ( $c['n'] ?? 0 ),
			'cc' => (string) ( $c['cc'] ?? '' ),
			'wp' => (string) ( $c['wp'] ?? '' ),
			'ih' => (string) ( $c['ih'] ?? '' ),
		);
	}
	ksort( $clients );
	return array( $summary['totals'], $clients );
}

function calls_of( string $file ): int {
	$state = json_decode( (string) file_get_contents( $file ), true );
	return (int) ( is_array( $state ) ? ( $state['calls'] ?? 0 ) : 0 );
}

function version_of( array $list ): string {
	foreach ( $list as $row ) {
		if ( is_array( $row ) && 'flx-demo' === ( $row['slug'] ?? '' ) ) {
			return (string) $row['version'];
		}
	}
	return '';
}

function release_row( string $version ): array {
	return array(
		'slug'       => 'flx-demo',
		'version'    => $version,
		'asset_id'   => 1,
		'prerelease' => false,
		'public'     => true,
		'repo'       => 'example/public',
		'type'       => 'plugin',
		'name'       => 'Demo',
	);
}

$dir  = sys_get_temp_dir() . '/flx-perf-' . bin2hex( random_bytes( 6 ) );
$data = $dir . '/data';
mkdir( $data, 0750, true );
$process = null;
$hold    = null;
$refresh = null;

try {
	$stats = new Stats( $data );
	$anon  = array( 'anon' => true, 'hash' => '', 'name' => 'Shop' );
	$url   = 'https://shop.example/a';
	$a     = bin2hex( random_bytes( 20 ) );
	$b     = bin2hex( random_bytes( 20 ) );
	$id_a  = Stats::install_client_id( Server::install_hash( $a ) );
	$id_b  = Stats::install_client_id( Server::install_hash( $b ) );
	$check = static function ( string $install, string $geo, bool $usage ) use ( $stats, $anon, $url ): void {
		$body = array( 'site' => $url, 'install' => $install, 'usage' => $usage );
		if ( $usage ) {
			$body['wp']       = '6.8';
			$body['php']      = '8.3';
			$body['packages'] = array( 'flx-demo' => '1.0.0' );
		}
		$stats->record_check( $anon, $body, $geo );
	};
	for ( $i = 0; $i < 3; $i++ ) {
		$check( $a, 'TR', true );
	}
	for ( $i = 0; $i < 2; $i++ ) {
		$check( $b, 'DE', false );
	}

	$journal = $data . '/stats.journal';
	$blob    = (string) file_get_contents( $journal );
	$lines   = array_values( array_filter( explode( "\n", $blob ), static function ( $line ) {
		return '' !== trim( $line );
	} ) );
	same( count( $lines ), 5, 'Five checks append five journal lines' );
	same( is_file( $stats->file() ), false, 'Those checks do not rewrite stats.json' );
	$line = json_decode( $lines[0], true );
	same( isset( $line['seq'], $line['ts'], $line['op'] ) && 'c' === $line['op'], true, 'A journal line is a small check record' );
	same( isset( $line['ip'] ), false, 'A journal line has no IP' );
	same( false !== strpos( $blob, $a ), false, 'The journal does not store the install id' );

	$once  = $stats->summary( 7 );
	$twice = $stats->summary( 7 );
	same( $once['totals']['checks'], 5, 'Folded checks match the number recorded' );
	same( $once['totals']['anon'], 5, 'Folded anonymous checks match' );
	same( (int) ( $once['clients'][ $id_a ]['n'] ?? 0 ), 3, 'The first install keeps its own check count' );
	same( (int) ( $once['clients'][ $id_b ]['n'] ?? 0 ), 2, 'The second install is a separate row' );
	same( (string) ( $once['clients'][ $id_a ]['cc'] ?? '' ), 'TR', 'Country is the code recorded at check time' );
	same( (string) ( $once['clients'][ $id_b ]['cc'] ?? '' ), 'DE', 'The second install keeps its own country' );
	same( (string) ( $once['clients'][ $id_a ]['wp'] ?? '' ), '6.8', 'Opted-in WordPress version is folded' );
	same( (string) ( $once['clients'][ $id_b ]['wp'] ?? '' ), '', 'Opt-out does not store the WordPress version' );
	same( (string) ( $once['clients'][ $id_a ]['pk']['flx-demo'] ?? '' ), '1.0.0', 'Installed package version is folded' );
	same( (int) ( $once['geo']['TR']['checks'] ?? 0 ), 3, 'Per-country checks match' );
	same( (int) ( $once['geo']['DE']['checks'] ?? 0 ), 2, 'The second country is counted on its own' );
	same( stamp( $once ), stamp( $twice ), 'Folding twice does not double count' );
	clearstatcache( true, $journal );
	same( (int) filesize( $journal ), 0, 'A successful fold truncates the journal' );

	file_put_contents( $journal, $blob );
	$third = $stats->summary( 7 );
	same( stamp( $once ), stamp( $third ), 'A journal left after a crash is not counted twice' );

	$check( $a, 'TR', true );
	$more = $stats->summary( 7 );
	same( $more['totals']['checks'], 6, 'A check after crash recovery is counted once' );
	same( (int) ( $more['clients'][ $id_a ]['n'] ?? 0 ), 4, 'The recovered row accepts a new check' );

	$stats->record_download( array( 'hash' => '' ), 'flx-demo', 40, 'TR' );
	$stats->record_blocked( 'TR' );
	$done = $stats->summary( 7 );
	same( $done['totals']['downloads'], 1, 'A download still folds into the totals' );
	same( $done['totals']['bytes'], 40, 'Download bytes still fold into the totals' );
	same( $done['totals']['blocked'], 1, 'A blocked check still folds into the totals' );
	same( $done['totals']['checks'], 6, 'Folding a download does not recount checks' );

	$legacy = sys_get_temp_dir() . '/flx-perf-old-' . bin2hex( random_bytes( 4 ) );
	mkdir( $legacy, 0750, true );
	$old_id = 'u1234567890abcd';
	$day    = gmdate( 'Y-m-d' );
	file_put_contents(
		$legacy . '/stats.json',
		json_encode(
			array(
				'days'    => array( $day => array( 'c' => 3, 's' => array( $old_id => 1 ) ) ),
				'clients' => array( $old_id => array( 'url' => 'https://old.example', 'n' => 3, 'first' => time(), 'last' => time(), 'key' => true ) ),
			)
		)
	);
	$old = new Stats( $legacy );
	same( $old->summary( 7 )['totals']['checks'], 3, 'An existing stats.json without jseq is readable' );
	$old->record_check( $anon, array( 'site' => 'https://fresh.example', 'install' => $a, 'usage' => true ), 'TR' );
	$kept = json_decode( (string) file_get_contents( $old->file() ), true );
	same( $old->summary( 7 )['totals']['checks'], 4, 'A new check is added on top of an existing file' );
	$kept = json_decode( (string) file_get_contents( $old->file() ), true );
	same( isset( $kept['clients'][ $old_id ] ), true, 'The previous client is kept' );

	$socket = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $message );
	if ( ! $socket ) {
		throw new RuntimeException( 'fixture socket: ' . $message );
	}
	$address = stream_socket_get_name( $socket, false );
	fclose( $socket );
	$state = $dir . '/state.json';
	file_put_contents( $state, json_encode( array( 'calls' => 0, 'mode' => 'slow' ) ) );
	$router = <<<'ROUTER'
<?php
$stateFile = __DIR__ . '/state.json';
$state = json_decode( (string) file_get_contents( $stateFile ), true );
if ( ! is_array( $state ) ) { $state = array( 'calls' => 0, 'mode' => 'ok' ); }
$path = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
if ( '/ready' === $path ) { echo 'ready'; return; }
if ( false !== strpos( $path, '/releases' ) ) {
	$state['calls'] = (int) ( $state['calls'] ?? 0 ) + 1;
	file_put_contents( $stateFile, json_encode( $state ), LOCK_EX );
	if ( 'fail' === ( $state['mode'] ?? '' ) ) { http_response_code( 503 ); echo 'unavailable'; return; }
	if ( 'slow' === ( $state['mode'] ?? '' ) ) { sleep( 3 ); }
	header( 'Content-Type: application/json' );
	echo json_encode( array( array(
		'tag_name' => 'flx-demo-v2.0.0', 'draft' => false, 'prerelease' => false,
		'published_at' => '2026-01-01T00:00:00Z', 'body' => '',
		'assets' => array( array( 'name' => 'flx-demo.zip', 'id' => 9, 'size' => 100 ) ),
	) ) );
	return;
}
http_response_code( 404 );
ROUTER;
	file_put_contents( $dir . '/router.php', $router );
	$process = proc_open(
		array( PHP_BINARY, '-S', $address, $dir . '/router.php' ),
		array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $dir . '/http.log', 'a' ), 2 => array( 'file', $dir . '/http.log', 'a' ) ),
		$pipes
	);
	fclose( $pipes[0] );
	for ( $i = 0; $i < 100 && 'ready' !== @file_get_contents( 'http://' . $address . '/ready' ); $i++ ) {
		usleep( 10000 );
	}
	$config = array(
		'data_dir'     => $data,
		'base_url'     => 'https://updates.example.test',
		'github_api'   => 'http://' . $address,
		'github_token' => '',
		'public_repos' => 'example/public',
		'repo'         => '',
		'cache_ttl'    => 300,
	);
	$server = new Server( $config );
	$sig    = md5( json_encode( $server->repos() ) );
	file_put_contents(
		$data . '/releases.json',
		json_encode( array( 'at' => time() - 1000, 'sig' => $sig, 'items' => array( release_row( '1.0.0' ) ), 'errors' => array() ) )
	);
	$child = $dir . '/child.php';
	file_put_contents( $child, "<?php\nrequire " . var_export( dirname( __DIR__ ) . '/lib/server.php', true ) . ";\n\$cfg = json_decode( (string) file_get_contents( \$argv[1] ), true );\n( new FLXUpdate\\Server( \$cfg ) )->releases();\n" );
	file_put_contents( $dir . '/config.json', json_encode( $config ) );
	$refresh = proc_open(
		array( PHP_BINARY, $child, $dir . '/config.json' ),
		array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $dir . '/child.out', 'a' ), 2 => array( 'file', $dir . '/child.err', 'a' ) ),
		$child_pipes
	);
	fclose( $child_pipes[0] );
	$ready = false;
	for ( $i = 0; $i < 200; $i++ ) {
		if ( calls_of( $state ) >= 1 ) {
			$ready = true;
			break;
		}
		usleep( 50000 );
	}
	if ( ! $ready ) {
		throw new RuntimeException( 'refresh child did not call GitHub: ' . file_get_contents( $dir . '/child.err' ) . file_get_contents( $dir . '/http.log' ) );
	}
	$started = hrtime( true );
	$stale   = $server->releases();
	$elapsed = ( hrtime( true ) - $started ) / 1e9;
	same( $elapsed < 1, true, 'A second refresher does not wait for GitHub' );
	same( version_of( $stale ), '1.0.0', 'A second refresher serves the stale catalog' );
	same( calls_of( $state ), 1, 'A second refresher does not call GitHub' );
	proc_close( $refresh );
	$refresh = null;
	same( calls_of( $state ), 1, 'Only the lock holder called GitHub' );
	$saved = json_decode( (string) file_get_contents( $data . '/releases.json' ), true );
	same( version_of( (array) ( $saved['items'] ?? array() ) ), '2.0.0', 'The lock holder stored the fresh catalog' );

	$saved['at'] = time() - 1000;
	unset( $saved['fail_until'] );
	file_put_contents( $data . '/releases.json', json_encode( $saved ) );
	file_put_contents( $state, json_encode( array( 'calls' => 0, 'mode' => 'fail' ) ), LOCK_EX );
	$backed = $server->releases();
	same( version_of( $backed ), '2.0.0', 'A failed refresh keeps the stale catalog' );
	same( calls_of( $state ), 1, 'A failed refresh calls GitHub once' );
	$failed = json_decode( (string) file_get_contents( $data . '/releases.json' ), true );
	same( (int) ( $failed['fail_until'] ?? 0 ) > time(), true, 'A failed refresh records a backoff' );
	same( (int) ( $failed['at'] ?? 0 ), (int) $saved['at'], 'A failed refresh does not mark the catalog fresh' );
	$server->releases();
	same( calls_of( $state ), 1, 'The backoff skips GitHub' );

	file_put_contents( $state, json_encode( array( 'calls' => 0, 'mode' => 'ok' ) ), LOCK_EX );
	$server->flush_cache();
	$forced = $server->releases();
	same( calls_of( $state ), 1, 'Reload packages forces a GitHub refresh' );
	same( version_of( $forced ), '2.0.0', 'The forced refresh stores the catalog' );

	file_put_contents( $state, json_encode( array( 'calls' => 0, 'mode' => 'ok' ) ), LOCK_EX );
	$prepared = json_encode( array( 'at' => time(), 'sig' => $sig, 'items' => array( release_row( '9.9.9' ) ), 'errors' => array() ) );
	file_put_contents( $dir . '/prepared.json', $prepared );
	@unlink( $data . '/releases.json' );
	file_put_contents(
		$dir . '/hold.php',
		<<<'PHP'
<?php
$lock = fopen( $argv[1], 'c' );
if ( ! $lock || ! flock( $lock, LOCK_EX ) ) { fwrite( STDERR, "lock failed\n" ); exit( 1 ); }
file_put_contents( $argv[2], '1' );
sleep( 1 );
$tmp = $argv[3] . '.part';
file_put_contents( $tmp, (string) file_get_contents( $argv[4] ) );
rename( $tmp, $argv[3] );
flock( $lock, LOCK_UN );
fclose( $lock );
PHP
	);
	$hold = proc_open(
		array( PHP_BINARY, $dir . '/hold.php', $data . '/releases.json.lock', $dir . '/held', $data . '/releases.json', $dir . '/prepared.json' ),
		array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $dir . '/hold.out', 'a' ), 2 => array( 'file', $dir . '/hold.err', 'a' ) ),
		$hold_pipes
	);
	fclose( $hold_pipes[0] );
	for ( $i = 0; $i < 100 && ! is_file( $dir . '/held' ); $i++ ) {
		usleep( 20000 );
	}
	if ( ! is_file( $dir . '/held' ) ) {
		throw new RuntimeException( 'holder did not take the lock: ' . @file_get_contents( $dir . '/hold.err' ) );
	}
	$waited = $server->releases();
	same( calls_of( $state ), 0, 'A request with no catalog waits instead of calling GitHub' );
	same( version_of( $waited ), '9.9.9', 'The waiter uses the catalog published by the refresher' );
	proc_close( $hold );
	$hold = null;

	echo "Perf checks passed.\n";
} finally {
	if ( is_resource( $refresh ?? null ) ) {
		proc_terminate( $refresh );
		proc_close( $refresh );
	}
	if ( is_resource( $hold ) ) {
		proc_terminate( $hold );
		proc_close( $hold );
	}
	if ( is_resource( $process ) ) {
		proc_terminate( $process );
		proc_close( $process );
	}
	$roots = array( $dir );
	if ( isset( $legacy ) && is_dir( $legacy ) ) {
		$roots[] = $legacy;
	}
	foreach ( $roots as $root ) {
		if ( ! is_dir( $root ) ) {
			continue;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $root );
	}
}
