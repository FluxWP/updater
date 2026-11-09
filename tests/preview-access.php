<?php
require __DIR__ . '/../lib/settings.php';
require __DIR__ . '/../lib/previews.php';
require __DIR__ . '/../lib/catalog-meta.php';
require __DIR__ . '/../lib/server.php';
use FLXUpdate\{Previews,Server,HttpError};
ob_start();
$dir = sys_get_temp_dir() . '/flxu-media-access-' . bin2hex( random_bytes( 6 ) ); mkdir( $dir, 0750, true );
$count = 0;
function access_check( bool $value, string $label ): void { global $count; if ( ! $value ) { throw new Exception( $label ); } $count++; echo "PASS $label\n"; }
function denied( callable $fn, int $code, string $label ): void { try { $fn(); } catch ( HttpError $e ) { access_check( $code === $e->getCode(), $label ); return; } throw new Exception( 'Expected access denial: ' . $label ); }
function media_request( Server $server, string $url ): string {
	parse_str( parse_url( $url, PHP_URL_QUERY ) ?? '', $_GET );
	preg_match( '#/v1/media/([^/]+)/([^/]+)$#', parse_url( $url, PHP_URL_PATH ), $matches );
	$method = new ReflectionMethod( $server, 'route_preview_media' ); $method->setAccessible( true );
	ob_start(); try { $method->invoke( $server, $matches[1], $matches[2] ); return ob_get_clean(); } catch ( Throwable $e ) { ob_end_clean(); throw $e; }
}
try {
	$hash = hash( 'sha256', 'local-fixture-site-key-not-a-real-secret' );
	$config = array( 'data_dir' => $dir, 'base_url' => 'https://updates.example.test', 'repo' => 'example/private', 'public_repos' => 'example/public', 'secret' => 'local-fixture-secret-not-a-real-secret', 'sites' => array( $hash => array( 'active' => true, 'packages' => array( 'flx-private' ), 'channel' => 'stable' ) ) );
	$server = new Server( $config ); $store = new Previews( $dir );
	$image = imagecreatetruecolor( 80, 60 ); ob_start(); imagejpeg( $image ); $bytes = ob_get_clean(); imagedestroy( $image ); 
	$items = array();
	foreach ( array( 'flx-public' => true, 'flx-private' => false ) as $slug => $public ) {
		$repo = $public ? 'example/public' : 'example/private';
		$store->store( $repo, str_repeat( 'a', 40 ), array( 'schema' => 1, 'packages' => array( $slug => array( 'desc' => array( 'en' => $slug ), 'gallery' => array( 'en' => array( array( 'path' => 'plugins/' . $slug . '/docs/example.jpg', 'sha' => sha1( 'blob ' . strlen( $bytes ) . "\0" . $bytes ), 'size' => strlen( $bytes ) ) ) ), 'website' => array( 'en' => 'https://example.test/' . $slug ) ) ) ), $public );
		$data = $store->read( $slug, $repo ); $id = $data['gallery']['en'][0]; $store->cache_media( $data['assets'][ $id ], $bytes );
		$items[] = array( 'slug' => $slug, 'name' => $slug, 'version' => '1.0.0', 'asset_id' => 1, 'prerelease' => false, 'public' => $public, 'repo' => $public ? 'example/public' : 'example/private' );
	}
	file_put_contents( $dir . '/releases.json', json_encode( array( 'at' => time(), 'sig' => md5( json_encode( $server->repos() ) ), 'items' => $items ) ) );
	$anonymous = array( 'anon' => true, 'hash' => '', 'channel' => 'stable', 'packages' => array() );
	$authorized = array( 'hash' => $hash, 'channel' => 'stable', 'packages' => array( 'flx-private' ) );
	access_check( array( 'flx-public' ) === array_keys( $server->catalog( 'stable', $anonymous ) ), 'Anonymous catalogs omit private package metadata' );
	access_check( 2 === count( $server->catalog( 'stable', $authorized ) ), 'Authorized site sees its public and permitted private packages' );
	$placement = \FLXUpdate\CatalogMeta::package( array(), 'flx-forms' );
	access_check( 'contact' === $placement['category'] && ! isset( $placement['desc'] ), 'Wizard placement remains configurable without bundled description fallbacks' );
	$pub = $server->preview_metadata( 'flx-public', $anonymous, array( 'repo' => 'example/public' ) )['gallery']['en'][0];
	$priv = $server->preview_metadata( 'flx-private', $authorized, array( 'repo' => 'example/private' ) )['gallery']['en'][0];
	access_check( false === strpos( $priv, 'local-fixture' ), 'Media URLs contain neither permanent site keys nor server secrets' );
	access_check( false !== strpos( $pub, 'https://updates.example.test/v1/media/' ), 'Images are served from the updater origin' );
	access_check( 0 === strpos( media_request( $server, $pub ), "\xff\xd8" ), 'Signed public raster served' );
	access_check( 0 === strpos( media_request( $server, $priv ), "\xff\xd8" ), 'Signed private raster served to authorized site' );
	denied( fn() => media_request( $server, str_replace( 'flx-public/', 'flx-private/', $pub ) ), 403, 'Public media signature cannot be reused for a private package' );
	denied( fn() => media_request( $server, $priv . 'x' ), 403, 'Modified signature rejected' );
	$blocked = $config; $blocked['sites'][ $hash ]['active'] = false;
	denied( fn() => media_request( new Server( $blocked ), $priv ), 403, 'Disabling a site revokes existing private media URLs' );
	$blocked = $config; $blocked['sites'][ $hash ]['packages'] = array();
	denied( fn() => media_request( new Server( $blocked ), $priv ), 404, 'Removing package permission revokes existing private media URLs' );
	$blocked = $config; $blocked['public_off'] = true;
	denied( fn() => media_request( new Server( $blocked ), $pub ), 403, 'Public access switch also applies to images' );
	$store->store( 'example/private', str_repeat( 'b', 40 ), array( 'schema' => 1, 'packages' => array( 'flx-private' => array() ) ), false );
	denied( fn() => media_request( $server, $priv ), 404, 'Removed gallery image is no longer served' );
	echo "$count access checks passed\n";
} finally {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ); foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); } rmdir( $dir );
	ob_end_flush();
}
