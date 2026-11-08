<?php
/** Local HTTP fixture verifies GitHub -> updater cache -> authorized media. No real GitHub requests. */
require __DIR__ . '/../lib/previews.php';
require __DIR__ . '/../lib/catalog-meta.php';
require __DIR__ . '/../lib/server.php';
use FLXUpdate\{Previews,Server,HttpError};
ob_start(); $checks = 0;
$dir = sys_get_temp_dir() . '/flxu-github-mirror-' . bin2hex( random_bytes( 6 ) ); mkdir( $dir, 0750, true );
function verify( bool $ok, string $message ): void { global $checks; if ( ! $ok ) { throw new RuntimeException( $message ); } $checks++; echo "PASS $message\n"; }
function asset_ref( string $path, string $bytes ): array { return array( 'path' => $path, 'sha' => sha1( 'blob ' . strlen( $bytes ) . "\0" . $bytes ), 'size' => strlen( $bytes ) ); }
function request_media( Server $server, string $url ): string {
	parse_str( parse_url( $url, PHP_URL_QUERY ), $_GET ); preg_match( '#/v1/media/([^/]+)/([^/]+)$#', parse_url( $url, PHP_URL_PATH ), $matches );
	$method = new ReflectionMethod( $server, 'route_preview_media' ); $method->setAccessible( true );
	ob_start(); try { $method->invoke( $server, $matches[1], $matches[2] ); return ob_get_clean(); } catch ( Throwable $error ) { ob_end_clean(); throw $error; }
}
$process = null;
try {
	$image = imagecreatetruecolor( 80, 60 ); ob_start(); imagejpeg( $image ); $bytes = ob_get_clean(); imagedestroy( $image );
	$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32"><rect width="32" height="32" fill="#7c3aed"/></svg>';
	$fixture = array( 'manifests' => array(), 'blobs' => array() );
	foreach ( array( 'example/public' => 'flx-public', 'example/private' => 'flx-private' ) as $repo => $slug ) {
		$gallery = asset_ref( 'plugins/' . $slug . '/docs/preview.jpg', $bytes ); $icon = asset_ref( 'plugins/' . $slug . '/assets/icon.svg', $svg );
		$fixture['manifests'][ $repo ] = array( 'schema' => 1, 'packages' => array( $slug => array( 'desc' => array( 'en' => 'GitHub introduction', 'tr' => 'GitHub açıklaması' ), 'website' => array( 'en' => 'https://publisher.example/product/' ), 'icons' => array( 'svg' => $icon ), 'gallery' => array( 'en' => array( $gallery ) ) ) ) );
		$fixture['blobs'][ $repo ][ $gallery['sha'] ] = base64_encode( $bytes ); $fixture['blobs'][ $repo ][ $icon['sha'] ] = base64_encode( $svg );
	}
	file_put_contents( $dir . '/fixture.json', json_encode( $fixture ) );
	file_put_contents( $dir . '/state.json', json_encode( array( 'calls' => array(), 'fail' => false, 'revision' => str_repeat( 'a', 40 ) ) ) );
	$router = <<<'ROUTER'
<?php
$state=json_decode(file_get_contents(__DIR__.'/state.json'),true);$url=parse_url($_SERVER['REQUEST_URI']);$path=$url['path'];
if($path==='/ready'){echo 'ready';return;}
$state['calls'][]=$path;file_put_contents(__DIR__.'/state.json',json_encode($state),LOCK_EX);
if($state['fail']){http_response_code(503);echo 'temporarily unavailable';return;}
if($path==='/oversize'){echo str_repeat('x',2048);return;}
$fixture=json_decode(file_get_contents(__DIR__.'/fixture.json'),true);
if(!preg_match('#^/repos/(example/(?:public|private))/(.*)$#',$path,$m)){http_response_code(404);return;}
$repo=$m[1];$route=$m[2];if($repo==='example/private'&&($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer local-fixture-token'){http_response_code(401);return;}
if($route==='commits'){header('Content-Type: application/json');echo json_encode([['sha'=>$state['revision']]]);return;}
if($route==='contents/catalog/packages.json'){
 parse_str($url['query']??'',$query);if(($query['ref']??'')!==$state['revision']){http_response_code(409);return;}
 $manifest=$fixture['manifests'][$repo];if($state['revision'][0]==='b')foreach($manifest['packages'] as &$package)$package['desc']['en']='Updated in GitHub';
 header('Content-Type: application/json');echo json_encode($manifest);return;
}
if(preg_match('#^git/blobs/([a-f0-9]{40})$#',$route,$blob)&&isset($fixture['blobs'][$repo][$blob[1]])){echo base64_decode($fixture['blobs'][$repo][$blob[1]]);return;}
http_response_code(404);
ROUTER;
	file_put_contents( $dir . '/router.php', $router );
	$socket = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $message ); $address = stream_socket_get_name( $socket, false ); fclose( $socket );
	$process = proc_open( array( PHP_BINARY, '-S', $address, $dir . '/router.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $dir . '/http.log', 'a' ), 2 => array( 'file', $dir . '/http.log', 'a' ) ), $pipes );
	fclose( $pipes[0] );
	for ( $i = 0; $i < 100; $i++ ) { if ( @file_get_contents( 'http://' . $address . '/ready' ) === 'ready' ) { break; } usleep( 10000 ); }
	$hash = hash( 'sha256', 'local-fixture-site-key' );
	$config = array( 'data_dir' => $dir . '/data', 'base_url' => 'https://updates.example.test', 'github_api' => 'http://' . $address, 'github_token' => 'local-fixture-token', 'public_repos' => 'example/public', 'repo' => 'example/private', 'secret' => 'local-fixture-signing-secret', 'sites' => array( $hash => array( 'active' => true, 'packages' => array( 'flx-private' ) ) ) );
	$server = new Server( $config ); $store = new Previews( $config['data_dir'] );
	$server->sync_preview_sources();
	$state = json_decode( file_get_contents( $dir . '/state.json' ), true ); verify( 4 === count( $state['calls'] ), 'One pinned GitHub manifest fetch per configured repository' );
	verify( str_repeat( 'a', 40 ) === $store->cached( 'example/private' )['revision'], 'Private GitHub manifest uses server-side authentication' );
	$server->sync_preview_sources(); verify( 4 === count( json_decode( file_get_contents( $dir . '/state.json' ), true )['calls'] ), 'Warm metadata checks do not call GitHub again' );
	verify( ! is_dir( $config['data_dir'] . '/preview-media' ), 'Metadata synchronization does not download images eagerly' );
	$items = array(); foreach ( array( 'example/public' => true, 'example/private' => false ) as $repo => $public ) { $items[] = array( 'slug' => $public ? 'flx-public' : 'flx-private', 'name' => 'Example', 'version' => '1.0.0', 'asset_id' => 1, 'prerelease' => false, 'public' => $public, 'repo' => $repo ); }
	file_put_contents( $config['data_dir'] . '/releases.json', json_encode( array( 'at' => time(), 'sig' => md5( json_encode( $server->repos() ) ), 'items' => $items ) ) );
	$anonymous = array( 'anon' => true, 'hash' => '' ); $authorized = array( 'hash' => $hash );
	$metadata = $server->preview_metadata( 'flx-public', $anonymous, array( 'repo' => 'example/public' ) );
	verify( isset( $metadata['icons']['svg'] ) && false === strpos( json_encode( $metadata ), '/repos/' ) && false === strpos( json_encode( $metadata ), 'local-fixture-token' ), 'Clients receive updater media URLs without GitHub paths or credentials' );
	$raster = request_media( $server, $metadata['gallery']['en'][0] ); verify( 0 === strpos( $raster, "\xff\xd8" ), 'Gallery image travels from GitHub through updater cache' );
	$before = count( json_decode( file_get_contents( $dir . '/state.json' ), true )['calls'] ); request_media( $server, $metadata['gallery']['en'][0] );
	verify( $before === count( json_decode( file_get_contents( $dir . '/state.json' ), true )['calls'] ), 'Immutable media is served from cache on repeat views' );
	verify( false !== strpos( request_media( $server, $metadata['icons']['svg'] ), '<svg' ), 'GitHub SVG icon is validated and served through updater' );
	$private = $server->preview_metadata( 'flx-private', $authorized, array( 'repo' => 'example/private' ) );
	verify( 0 === strpos( request_media( $server, $private['gallery']['en'][0] ), "\xff\xd8" ), 'Private media is fetched with server credentials for an authorized package' );
	$state = json_decode( file_get_contents( $dir . '/state.json' ), true ); $state['fail'] = true; file_put_contents( $dir . '/state.json', json_encode( $state ) ); $server->sync_preview_sources( true );
	verify( str_repeat( 'a', 40 ) === $store->cached( 'example/public' )['revision'] && ! empty( $store->cached( 'example/public' )['error'] ), 'GitHub failure preserves the last successful catalog' );
	verify( request_media( $server, $metadata['gallery']['en'][0] ) === $raster, 'Cached images remain available during a GitHub outage' );
	$state = json_decode( file_get_contents( $dir . '/state.json' ), true ); $state['fail'] = false; $state['revision'] = str_repeat( 'b', 40 ); file_put_contents( $dir . '/state.json', json_encode( $state ) ); $server->sync_preview_sources( true );
	verify( 'Updated in GitHub' === $store->read( 'flx-public', 'example/public' )['desc']['en'], 'New GitHub catalog revision updates descriptions automatically' );
	$method = new ReflectionMethod( $server, 'github' ); $method->setAccessible( true ); $limited = false;
	try { $method->invoke( $server, '/oversize', 'application/vnd.github.raw+json', null, false, 1024, 5 ); } catch ( RuntimeException $error ) { $limited = true; }
	verify( $limited, 'GitHub response byte limits abort oversized transfers' );
	echo "$checks GitHub mirror checks passed\n";
} finally {
	if ( is_resource( $process ) ) { proc_terminate( $process ); proc_close( $process ); }
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ); foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); } rmdir( $dir );
	ob_end_flush();
}
