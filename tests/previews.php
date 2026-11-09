<?php
require __DIR__ . '/../lib/previews.php';
use FLXUpdate\Previews;
$dir = sys_get_temp_dir() . '/flxu-preview-tests-' . bin2hex( random_bytes( 6 ) );
$store = new Previews( $dir ); $count = 0;
function check( bool $ok, string $message ): void { global $count; if ( ! $ok ) { throw new Exception( $message ); } $count++; echo "PASS $message\n"; }
function rejects( callable $fn, string $message ): void { try { $fn(); } catch ( Throwable $e ) { check( true, $message ); return; } throw new Exception( 'Expected rejection: ' . $message ); }
function ref( string $path, string $bytes ): array { return array( 'path' => $path, 'sha' => sha1( 'blob ' . strlen( $bytes ) . "\0" . $bytes ), 'size' => strlen( $bytes ) ); }
try {
	$im = imagecreatetruecolor( 64, 48 ); ob_start(); imagejpeg( $im ); $bytes = ob_get_clean() . '<?php echo "trailing-payload"; ?>'; imagedestroy( $im );
	$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" onload="alert(1)"><defs><linearGradient id="a"><stop offset="0" stop-color="#666"/></linearGradient></defs><rect width="32" height="32" fill="url(#a)"/><script>alert(1)</script><image href="https://evil.example/a"/><use href="https://evil.example/a#x"/></svg>';
	$meta = array( 'desc' => array( 'en' => '<b>English</b>', 'pt-br' => 'Português', 'ar' => 'العربية' ), 'website' => array( 'en' => 'https://example.test/plugin/' ), 'gallery' => array( 'en' => array( ref( 'plugins/flx-example/docs/first.jpg', $bytes ) ), 'pt-br' => array( ref( 'plugins/flx-example/docs/first.jpg', $bytes ) ) ), 'icons' => array( 'svg' => ref( 'plugins/flx-example/assets/icon.svg', $svg ) ) );
	$manifest = array( 'schema' => 1, 'packages' => array( 'flx-example' => $meta ) ); $revision = str_repeat( 'a', 40 );
	$store->store( 'example/public', $revision, $manifest, true ); $data = $store->read( 'flx-example', 'example/public' );
	check( 'English' === $data['desc']['en'], 'GitHub descriptions are plain text' );
	check( isset( $data['gallery']['pt-br'] ), 'Regional locale keys preserved' );
	$id = $data['gallery']['en'][0]; check( '' === $store->path( $id ), 'Manifest sync does not eagerly download media' );
	$store->cache_media( $data['assets'][ $id ], $bytes );
	check( false === strpos( file_get_contents( $store->path( $id ) ), 'trailing-payload' ), 'Raster cache removes trailing payloads' );
	$icon = $data['icons']['svg']; $store->cache_media( $data['assets'][ $icon ], $svg ); $safe = file_get_contents( $store->path( $icon ) );
	check( false === strpos( $safe, '<script' ) && false === strpos( $safe, 'onload' ) && false === strpos( $safe, 'https://evil' ), 'SVG icons remove scripts, events and remote resources' );
	check( false !== strpos( $safe, 'linearGradient' ) && false !== strpos( $safe, 'url(#a)' ), 'SVG icon gradients are preserved' );
	rejects( fn() => $store->cache_media( $data['assets'][ $id ], 'different bytes' ), 'Immutable GitHub blob integrity checked' );
	check( '' === $store->path( '../settings.json' ), 'Media path traversal rejected' );
	foreach ( array( 'javascript:alert(1)', 'http://example.test/', 'https://user:pass@example.test/' ) as $bad ) { $copy = $manifest; $copy['packages']['flx-example']['website']['en'] = $bad; rejects( fn() => $store->store( 'example/public', $revision, $copy, true ), 'Unsafe website rejected' ); }
	$copy = $manifest; $copy['packages']['flx-example']['gallery']['en'][0]['path'] = 'plugins/another-package/docs/first.jpg'; rejects( fn() => $store->store( 'example/public', $revision, $copy, true ), 'Asset paths must belong to their package' );
	$copy = $manifest; $copy['packages']['flx-example']['gallery']['en'][0]['path'] = 'plugins/flx-example/../secret.jpg'; rejects( fn() => $store->store( 'example/public', $revision, $copy, true ), 'Manifest traversal rejected' );
	$copy = $manifest; $copy['packages']['flx-example']['gallery']['en'][0] = ref( 'plugins/flx-example/icon.svg', $svg ); rejects( fn() => $store->store( 'example/public', $revision, $copy, true ), 'SVG is limited to icons, not galleries' );
	$copy = $manifest; $copy['packages']['flx-example']['icons']['svg']['size'] = 9 * 1024 * 1024; rejects( fn() => $store->store( 'example/public', $revision, $copy, true ), 'Oversized manifest media rejected' );
	$copy = $manifest; $copy['packages']['flx-example']['desc']['pt_BR'] = 'invalid'; rejects( fn() => $store->store( 'example/public', $revision, $copy, true ), 'Noncanonical locale rejected' );
	check( $revision === $store->cached( 'example/public' )['revision'], 'Invalid manifest cannot replace the last good cache' );
	$store->store( 'example/private', str_repeat( 'b', 40 ), $manifest, false );
	check( $store->read( 'flx-example', 'example/private' )['gallery']['en'][0] !== $id, 'Same-slug packages in different repositories have isolated references' );
	$store->checked( 'example/public', 'Temporary failure' ); check( $revision === $store->cached( 'example/public' )['revision'], 'GitHub errors preserve last good metadata' );
	$store->invalidate(); check( 0 === $store->cached( 'example/public' )['checked_at'] && '' !== $store->path( $id ), 'Refresh invalidates metadata checks without discarding immutable media' );
	echo "$count checks passed\n";
} finally {
	if ( is_dir( $dir ) ) { $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ); foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); } rmdir( $dir ); }
}
