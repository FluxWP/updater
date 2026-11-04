<?php
/**
 * Flux Update Server giriş noktası. Tüm istekler buraya yönlenir (.htaccess).
 * Ayarlar ortam değişkenlerinden ya da config.php'den gelir (lib/config.php).
 */

require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/i18n.php';
require __DIR__ . '/lib/server.php';
require __DIR__ . '/lib/stats.php';
require __DIR__ . '/lib/geo.php';
require __DIR__ . '/lib/panel.php';

$flx_config = FLXUpdate\load_config( __DIR__ );
$flx_path   = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );

// Alt klasöre kurulduysa (ör. /update/), önekini at.
$flx_script = (string) ( $_SERVER['SCRIPT_NAME'] ?? '' );
$flx_base   = '/index.php' === substr( $flx_script, -10 ) ? rtrim( substr( $flx_script, 0, -10 ), '/' ) : '';
if ( '' !== $flx_base && 0 === strpos( $flx_path, $flx_base . '/' ) ) {
	$flx_path = substr( $flx_path, strlen( $flx_base ) );
}

( new FLXUpdate\Server( $flx_config ) )->handle( $_SERVER['REQUEST_METHOD'] ?? 'GET', $flx_path );
