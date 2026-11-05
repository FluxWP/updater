<?php
// Regression: desktop source selection must survive reloads without changing site/package access.
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/settings.php';
use FLXUpdate\Settings;
use function FLXUpdate\load_config;
function same( $actual, $expected, string $message ): void {
	if ( $actual !== $expected ) {
		throw new RuntimeException( $message );
	}
}
$dir = sys_get_temp_dir() . '/flx-repo-test-' . bin2hex( random_bytes( 8 ) );
mkdir( $dir, 0700, true );
putenv( 'FLX_UPDATE_DATA_DIR=' . $dir );
putenv( 'FLX_UPDATE_REPO=Example/private-packages' );
putenv( 'FLX_UPDATE_PUBLIC_REPOS=FluxWP/FluxWP' );
putenv( 'FLX_UPDATE_APP_REPO=Example/mobile-app' );
putenv( 'FLX_UPDATE_FLUXAPP_REPO=Example/desktop-env' );
try {
	$legacy = load_config( $dir );
	same( $legacy['fluxapp_repo'], 'Example/desktop-env', 'Existing environment source must remain supported' );
	Settings::update( $dir, array( 'sites' => array( 'fake-site-hash' => array( 'name' => 'Regression site', 'packages' => '*' ) ), 'github_token' => 'fake-test-token', 'webhook_secret' => 'fake-test-secret' ) );
	Settings::update( $dir, array( 'fluxapp_repo' => 'FluxWP/FluxWP' ) );
	$saved = load_config( $dir );
	same( $saved['fluxapp_repo'], 'FluxWP/FluxWP', 'Panel desktop selection must override the environment' );
	same( $saved['repo'], 'Example/private-packages', 'Private package source must be preserved' );
	same( $saved['public_repos'], 'FluxWP/FluxWP', 'Public package source must be preserved' );
	same( $saved['app_repo'], 'Example/mobile-app', 'Generic app source must be independent' );
	same( $saved['sites']['fake-site-hash']['packages'], '*', 'Site access must be preserved' );
	same( $saved['github_token'], 'fake-test-token', 'Existing token must be preserved' );
	Settings::update( $dir, array( 'fluxapp_repo' => '-' ) );
	$cleared = load_config( $dir );
	same( $cleared['fluxapp_repo'], '', 'Clearing the picker must not use the sentinel as a repository' );
	same( $cleared['webhook_secret'], 'fake-test-secret', 'Clearing the picker must preserve webhook settings' );
	echo "Desktop source persistence and existing access regression checks passed.\n";
} finally {
	foreach ( glob( $dir . '/*' ) as $file ) { unlink( $file ); }
	rmdir( $dir );
}
