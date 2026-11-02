<?php
/**
 * Flux Update Server: private GitHub release'lerini WordPress sitelerine sunar.
 *
 * GitHub token yalnız bu sunucuda durur. Siteler kendi site anahtarlarıyla
 * sürüm bilgisini sorar ve zip'i buradan indirir.
 */

namespace FLXUpdate;

const VERSION = '1.6.0';
const DAY_SECONDS = 86400;

class HttpError extends \Exception {}

final class Server {

	/** @var array */
	private $config;

	/** @var string */
	private $data_dir;

	public function __construct( array $config ) {
		$this->config = $config + array(
			'repo'                => '',
			'app_repo'            => '',
			'fluxapp_repo'        => '',
			'github_token'        => '',
			'webhook_secret'      => '',
			'cache_ttl'           => 300,
			'zip_keep'            => 5,
			'zip_temp_hours'      => 24,
			'admin_user'          => '',
			'admin_password_hash' => '',
			'admin_password'      => '',
			'sites'               => array(),
			'data_dir'            => __DIR__ . '/../data',
			'github_api'          => 'https://api.github.com',
		);
		$this->data_dir = rtrim( $this->config['data_dir'], '/' );
	}

	/* ------------------------------------------------------------------ */
	/* Yönlendirme                                                         */
	/* ------------------------------------------------------------------ */

	public function handle( string $method, string $path ): void {
		$path = '/' . trim( $path, '/' );
		try {
			if ( '/' === $path || '/v1/health' === $path ) {
				$this->json(
					array(
						'ok'         => true,
						'service'    => 'flx-update-server',
						'version'    => VERSION,
						'configured' => '' !== (string) $this->config['github_token'] && ! empty( $this->config['sites'] ),
					)
				);
				return;
			}
			if ( '/v1/check' === $path ) {
				$this->route_check( $method );
				return;
			}
			if ( preg_match( '#^/v1/download/([a-z0-9][a-z0-9-]*)/([0-9][0-9A-Za-z.+-]*)\.zip$#', $path, $m ) ) {
				$this->route_download( $m[1], $m[2] );
				return;
			}
			if ( '/v1/app/latest' === $path ) {
				$this->route_app_latest();
				return;
			}
			if ( preg_match( '#^/v1/app/download/(v[0-9][0-9A-Za-z.+-]*)/([A-Za-z0-9._-]+)$#', $path, $m ) ) {
				$this->route_app_download( $m[1], $m[2] );
				return;
			}
			if ( preg_match( '#^/v1/fluxapp/([a-z0-9][a-z0-9-]*)/latest$#', $path, $m ) ) {
				$this->route_fluxapp_latest( $m[1] );
				return;
			}
			if ( preg_match( '#^/v1/fluxapp/([a-z0-9][a-z0-9-]*)/tauri/([a-z0-9_-]+)/([a-z0-9_-]+)/([0-9][0-9A-Za-z.+-]*)$#', $path, $m ) ) {
				$this->route_fluxapp_tauri( $m[1], $m[2], $m[3], $m[4] );
				return;
			}
			if ( preg_match( '#^/v1/fluxapp/download/(fluxapp-v[0-9][0-9A-Za-z.+-]*)/([A-Za-z0-9._-]+)$#', $path, $m ) ) {
				$this->route_fluxapp_download( $m[1], $m[2] );
				return;
			}
			if ( '/v1/webhook' === $path && 'POST' === $method ) {
				$this->route_webhook();
				return;
			}
			if ( '/admin' === $path || '/status' === $path ) {
				if ( '/status' === $path ) {
					header( 'Location: ' . $this->base_url() . '/admin', true, 302 );
					return;
				}
				( new Panel( $this, $this->config ) )->handle( $method );
				return;
			}
			throw new HttpError( 'Not found', 404 );
		} catch ( HttpError $e ) {
			$this->json( array( 'ok' => false, 'error' => $e->getMessage() ), $e->getCode() ?: 500 );
		} catch ( \Throwable $e ) {
			error_log( '[flx-update] ' . $e->getMessage() );
			$this->json( array( 'ok' => false, 'error' => 'Server error' ), 500 );
		}
	}

	/* ------------------------------------------------------------------ */
	/* /v1/check                                                           */
	/* ------------------------------------------------------------------ */

	private function route_check( string $method ): void {
		$site = $this->auth_site();
		$this->maybe_prune();
		$body = array();
		if ( 'POST' === $method ) {
			$raw  = (string) file_get_contents( 'php://input', false, null, 0, 262144 );
			$body = json_decode( $raw, true );
			if ( ! is_array( $body ) ) {
				$body = array();
			}
		}

		$catalog  = $this->catalog( $site['channel'] );
		$versions = $this->versions( $site['channel'] );
		$out      = array();
		foreach ( $catalog as $slug => $rel ) {
			if ( ! $this->site_allows( $site, $slug ) ) {
				continue;
			}
			$out[ $slug ] = array(
				'slug'         => $slug,
				'type'         => $rel['type'],
				'name'         => $rel['name'],
				'version'      => $rel['version'],
				'package'      => $this->base_url() . '/v1/download/' . $slug . '/' . rawurlencode( $rel['version'] ) . '.zip',
				'requires'     => $rel['requires'],
				'tested'       => $rel['tested'],
				'requires_php' => $rel['requires_php'],
				'requires_plugins' => $rel['requires_plugins'] ?? array(),
				'changelog'    => $rel['changelog_html'],
				'released'     => $rel['published_at'],
				'size'         => $rel['size'],
				'versions'     => $versions[ $slug ] ?? array(),
			);
		}

		$this->remember_site( $site, $body );
		$this->json( array( 'ok' => true, 'site' => $site['name'], 'packages' => $out ) );
	}

	/* ------------------------------------------------------------------ */
	/* /v1/download/{slug}/{version}.zip                                   */
	/* ------------------------------------------------------------------ */

	private function route_download( string $slug, string $version ): void {
		$site = $this->auth_site();
		if ( ! $this->site_allows( $site, $slug ) ) {
			throw new HttpError( 'Package not allowed for this site', 403 );
		}

		$release = null;
		foreach ( $this->releases() as $r ) {
			if ( $r['slug'] === $slug && $r['version'] === $version ) {
				$release = $r;
				break;
			}
		}
		if ( ! $release || ! $release['asset_id'] ) {
			throw new HttpError( 'Version not found', 404 );
		}
		if ( $release['prerelease'] && 'beta' !== $site['channel'] ) {
			throw new HttpError( 'Version not found', 404 );
		}

		$file = $this->zip_path( $slug, $version );
		if ( ! is_file( $file ) || filesize( $file ) !== (int) $release['size'] ) {
			$this->fetch_asset( (int) $release['asset_id'], $file );
		}
		@touch( $file ); // Eski sürüm: son indirmeden zip_temp_hours sonra silinir.
		$this->prune_zips( $slug );

		$this->log( 'download', $site['name'] . ' ' . $slug . ' ' . $version );

		header( 'Content-Type: application/zip' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Content-Disposition: attachment; filename="' . $slug . '.zip"' );
		header( 'Cache-Control: private, no-store' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $file );
	}

	/**
	 * Zip önbelleğini sınırlar: paket başına son `zip_keep` sürüm kalır. Daha eski bir sürüm
	 * istenirse GitHub release'ten yeniden çekilir ve son indirmeden `zip_temp_hours` saat sonra silinir.
	 *
	 * @param string|null $only Yalnız bu paketin klasörü (null: hepsi).
	 * @return array{deleted:int,bytes:int}
	 */
	public function prune_zips( ?string $only = null ): array {
		$out  = array( 'deleted' => 0, 'bytes' => 0 );
		$root = $this->data_dir . '/zips';
		if ( ! is_dir( $root ) ) {
			return $out;
		}
		try {
			$releases = $this->releases();
		} catch ( \Throwable $e ) {
			return $out;
		}
		if ( ! $releases ) {
			return $out; // GitHub okunamadıysa hiçbir şeyi silme.
		}
		$by_slug = array();
		foreach ( $releases as $r ) {
			$by_slug[ $r['slug'] ][] = $r['version'];
		}
		$keep = array();
		foreach ( $by_slug as $slug => $list ) {
			usort( $list, 'version_compare' );
			foreach ( array_slice( array_reverse( $list ), 0, max( 1, (int) $this->config['zip_keep'] ) ) as $v ) {
				$keep[ $this->zip_path( $slug, $v ) ] = true;
			}
		}
		$ttl = max( 1, (int) $this->config['zip_temp_hours'] ) * 3600;
		foreach ( (array) glob( $root . '/*', GLOB_ONLYDIR ) as $dir ) {
			if ( null !== $only && basename( $dir ) !== $only ) {
				continue;
			}
			foreach ( (array) glob( $dir . '/*.zip' ) as $f ) {
				clearstatcache( true, $f );
				if ( isset( $keep[ $f ] ) || time() - (int) @filemtime( $f ) < $ttl ) {
					continue;
				}
				$size = (int) @filesize( $f );
				if ( @unlink( $f ) ) {
					@unlink( $f . '.lock' );
					++$out['deleted'];
					$out['bytes'] += $size;
				}
			}
			if ( ! glob( $dir . '/*' ) ) {
				@rmdir( $dir );
			}
		}
		if ( null === $only ) {
			@touch( $root . '/.pruned' );
		}
		if ( $out['deleted'] ) {
			$this->log( 'prune', $out['deleted'] . ' zip, ' . $out['bytes'] . ' bytes' );
		}
		return $out;
	}

	/** Saatte bir tüm klasörleri temizler (bir /v1/check isteğinde). */
	private function maybe_prune(): void {
		$stamp = $this->data_dir . '/zips/.pruned';
		if ( is_dir( $this->data_dir . '/zips' ) && ( ! is_file( $stamp ) || time() - (int) filemtime( $stamp ) > 3600 ) ) {
			$this->prune_zips();
		}
	}

	/** @return array{files:int,bytes:int} */
	public function zip_stats(): array {
		$out = array( 'files' => 0, 'bytes' => 0 );
		foreach ( (array) glob( $this->data_dir . '/zips/*/*.zip' ) as $f ) {
			++$out['files'];
			$out['bytes'] += (int) @filesize( $f );
		}
		return $out;
	}

	private function zip_path( string $slug, string $version ): string {
		return $this->data_dir . '/zips/' . $slug . '/' . $slug . '-' . preg_replace( '/[^0-9A-Za-z.+-]/', '', $version ) . '.zip';
	}

	private function fetch_asset( int $asset_id, string $file ): void {
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0750, true ) && ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'Cannot create ' . $dir );
		}
		$lock = fopen( $file . '.lock', 'c' );
		flock( $lock, LOCK_EX );
		try {
			clearstatcache( true, $file );
			$tmp = $file . '.part';
			$fp  = fopen( $tmp, 'wb' );
			$this->github( '/repos/' . $this->config['repo'] . '/releases/assets/' . $asset_id, 'application/octet-stream', $fp );
			fclose( $fp );
			$zip_magic = file_get_contents( $tmp, false, null, 0, 2 );
			if ( 'PK' !== $zip_magic ) {
				@unlink( $tmp );
				throw new \RuntimeException( 'Downloaded asset is not a zip' );
			}
			rename( $tmp, $file );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Uygulama: herkese açık, site anahtarı gerekmez             */
	/* ------------------------------------------------------------------ */

	/**
	 * GET /v1/app/latest?channel=stable|beta
	 * Son uygulama sürümü ve platform dosyaları. Dosya adresleri bu sunucudan geçer
	 * (depo private; GitHub token istemciye gitmez).
	 */
	private function route_app_latest(): void {
		$channel = 'beta' === ( $_GET['channel'] ?? '' ) ? 'beta' : 'stable';
		$best    = null;
		foreach ( $this->app_releases() as $r ) {
			if ( $r['prerelease'] && 'beta' !== $channel ) {
				continue;
			}
			if ( ! $best || version_compare( $r['version'], $best['version'], '>' ) ) {
				$best = $r;
			}
		}
		if ( ! $best ) {
			$this->json( array( 'ok' => true, 'version' => null ) );
			return;
		}
		$assets = array();
		foreach ( $best['assets'] as $platform => $a ) {
			$assets[ $platform ] = array(
				'name' => $a['name'],
				'size' => $a['size'],
				'url'  => $this->base_url() . '/v1/app/download/' . rawurlencode( $best['tag'] ) . '/' . rawurlencode( $a['name'] ),
			);
		}
		header( 'Access-Control-Allow-Origin: *' );
		$this->json(
			array(
				'ok'         => true,
				'version'    => $best['version'],
				'tag'        => $best['tag'],
				'name'       => $best['name'],
				'prerelease' => $best['prerelease'],
				'released'   => $best['published_at'],
				'notes'      => $best['notes_html'],
				'assets'     => $assets,
			)
		);
	}

	/** GET /v1/app/download/{tag}/{dosya}: GitHub'ın kısa ömürlü indirme adresine yönlendirir. */
	private function route_app_download( string $tag, string $name ): void {
		$asset_id = 0;
		foreach ( $this->app_releases() as $r ) {
			if ( $r['tag'] !== $tag ) {
				continue;
			}
			foreach ( $r['assets'] as $a ) {
				if ( $a['name'] === $name ) {
					$asset_id = (int) $a['id'];
				}
			}
		}
		if ( ! $asset_id ) {
			throw new HttpError( 'File not found', 404 );
		}
		$url = $this->github_redirect( '/repos/' . $this->config['app_repo'] . '/releases/assets/' . $asset_id );
		$this->log( 'app', $tag . ' ' . $name );
		header( 'Cache-Control: no-store' );
		header( 'Location: ' . $url, true, 302 );
	}

	/** Uygulama release'leri (önbellekli). Tag biçimi: v<sürüm>. */
	public function app_releases(): array {
		$repo = (string) $this->config['app_repo'];
		if ( '' === $repo ) {
			return array();
		}
		$file  = $this->data_dir . '/app-releases.json';
		$cache = $this->read_json( $file );
		if ( isset( $cache['at'], $cache['items'] ) && ( $cache['repo'] ?? '' ) === $repo && time() - (int) $cache['at'] < (int) $this->config['cache_ttl'] ) {
			return $cache['items'];
		}
		try {
			$list = json_decode( $this->github( '/repos/' . $repo . '/releases?per_page=30' ), true );
		} catch ( \Throwable $e ) {
			error_log( '[flx-update] app releases: ' . $e->getMessage() );
			if ( isset( $cache['items'] ) ) {
				return $cache['items'];
			}
			throw new HttpError( 'Release list unavailable', 502 );
		}
		$items = array();
		foreach ( is_array( $list ) ? $list : array() as $rel ) {
			if ( ! empty( $rel['draft'] ) || ! preg_match( '/^v([0-9][0-9A-Za-z.+-]*)$/', (string) ( $rel['tag_name'] ?? '' ), $m ) ) {
				continue;
			}
			$assets = array();
			foreach ( (array) ( $rel['assets'] ?? array() ) as $a ) {
				$platform = self::app_platform( (string) ( $a['name'] ?? '' ) );
				if ( $platform && ! isset( $assets[ $platform ] ) ) {
					$assets[ $platform ] = array( 'id' => (int) $a['id'], 'name' => (string) $a['name'], 'size' => (int) $a['size'] );
				}
			}
			$items[] = array(
				'version'      => $m[1],
				'tag'          => (string) $rel['tag_name'],
				'name'         => (string) ( $rel['name'] ?? $rel['tag_name'] ),
				'prerelease'   => ! empty( $rel['prerelease'] ),
				'published_at' => (string) ( $rel['published_at'] ?? '' ),
				'notes_html'   => self::markdown( (string) ( $rel['body'] ?? '' ) ),
				'assets'       => $assets,
			);
		}
		$this->write_json( $file, array( 'at' => time(), 'repo' => $repo, 'items' => $items ) );
		return $items;
	}

	/** Dosya adından platform: app-windows.zip, app-macos.zip, app-android.apk, app-ios*.zip. */
	public static function app_platform( string $name ): string {
		$n = strtolower( $name );
		if ( '.apk' === substr( $n, -4 ) ) {
			return 'android';
		}
		foreach ( array( 'windows', 'macos', 'ios', 'linux' ) as $p ) {
			if ( false !== strpos( $n, $p ) ) {
				return $p;
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------ */
	/* FluxWP masaüstü (FluxWP/fluxwp fluxapp-v<sürüm> release'leri)        */
	/* ------------------------------------------------------------------ */

	/** FluxWP release'lerinin deposu: boşsa paket deposu (repo). */
	private function fluxapp_repo(): string {
		return (string) ( $this->config['fluxapp_repo'] ?: $this->config['repo'] );
	}

	/** Kanal: "-" içeren sürüm (0.3.0-beta.1) yalnız beta'da. GitHub pre-release işareti kanalı belirlemez. */
	private function fluxapp_best( string $brand, bool $beta, string $need = 'dmg' ): ?array {
		$best = null;
		foreach ( $this->fluxapp_releases() as $r ) {
			if ( ! $beta && false !== strpos( $r['version'], '-' ) ) {
				continue;
			}
			if ( empty( $r['brands'][ $brand ][ $need ] ) ) {
				continue;
			}
			if ( ! $best || version_compare( $r['version'], $best['version'], '>' ) ) {
				$best = $r;
			}
		}
		return $best;
	}

	private function fluxapp_url( string $tag, string $name ): string {
		return $this->base_url() . '/v1/fluxapp/download/' . rawurlencode( $tag ) . '/' . rawurlencode( $name );
	}

	/** GET /v1/fluxapp/{marka}/latest?channel=stable|beta: son sürüm + dmg adresi. */
	private function route_fluxapp_latest( string $brand ): void {
		$best = $this->fluxapp_best( $brand, 'beta' === ( $_GET['channel'] ?? '' ) );
		header( 'Access-Control-Allow-Origin: *' );
		if ( ! $best ) {
			$this->json( array( 'ok' => true, 'version' => null ) );
			return;
		}
		$assets = array();
		foreach ( $best['brands'][ $brand ] as $kind => $a ) {
			if ( 'sig' !== $kind ) {
				$assets[ $kind ] = array( 'name' => $a['name'], 'size' => $a['size'], 'url' => $this->fluxapp_url( $best['tag'], $a['name'] ) );
			}
		}
		$this->json(
			array(
				'ok'       => true,
				'version'  => $best['version'],
				'tag'      => $best['tag'],
				'released' => $best['published_at'],
				'notes'    => $best['notes_html'],
				'assets'   => $assets,
			)
		);
	}

	/**
	 * GET /v1/fluxapp/{marka}/tauri/{target}/{arch}/{current}?channel=…
	 * Tauri updater biçimi. Yeni sürüm yoksa 204. macOS universal paket her iki mimariye de gider.
	 */
	private function route_fluxapp_tauri( string $brand, string $target, string $arch, string $current ): void {
		$beta = 'beta' === ( $_GET['channel'] ?? '' ) || false !== strpos( $current, '-' );
		if ( 'darwin' !== $target ) {
			http_response_code( 204 );
			return;
		}
		$best = $this->fluxapp_best( $brand, $beta, 'tar' );
		if ( ! $best || ! version_compare( $best['version'], $current, '>' ) || empty( $best['brands'][ $brand ]['sig'] ) ) {
			http_response_code( 204 );
			return;
		}
		$files = $best['brands'][ $brand ];
		$sig   = $this->fluxapp_signature( (int) $files['sig']['id'] );
		if ( '' === $sig ) {
			http_response_code( 204 );
			return;
		}
		$this->json(
			array(
				'version'   => $best['version'],
				'notes'     => trim( preg_replace( '/\s+/', ' ', strip_tags( $best['notes_html'] ) ) ),
				'pub_date'  => $best['published_at'],
				'url'       => $this->fluxapp_url( $best['tag'], $files['tar']['name'] ),
				'signature' => $sig,
			)
		);
	}

	/** .sig dosyasının içeriği (değişmez; asset id ile önbellekte). */
	private function fluxapp_signature( int $asset_id ): string {
		$file  = $this->data_dir . '/fluxapp-sigs.json';
		$cache = $this->read_json( $file );
		if ( isset( $cache[ (string) $asset_id ] ) ) {
			return (string) $cache[ (string) $asset_id ];
		}
		try {
			$sig = trim( $this->github( '/repos/' . $this->fluxapp_repo() . '/releases/assets/' . $asset_id, 'application/octet-stream' ) );
		} catch ( \Throwable $e ) {
			error_log( '[flx-update] fluxapp sig: ' . $e->getMessage() );
			return '';
		}
		$cache[ (string) $asset_id ] = $sig;
		$this->write_json( $file, $cache );
		return $sig;
	}

	/** GET /v1/fluxapp/download/{tag}/{dosya}: GitHub'ın kısa ömürlü indirme adresine 302. */
	private function route_fluxapp_download( string $tag, string $name ): void {
		$asset_id = 0;
		foreach ( $this->fluxapp_releases() as $r ) {
			if ( $r['tag'] !== $tag ) {
				continue;
			}
			foreach ( $r['brands'] as $files ) {
				foreach ( $files as $a ) {
					if ( $a['name'] === $name ) {
						$asset_id = (int) $a['id'];
					}
				}
			}
		}
		if ( ! $asset_id ) {
			throw new HttpError( 'File not found', 404 );
		}
		$url = $this->github_redirect( '/repos/' . $this->fluxapp_repo() . '/releases/assets/' . $asset_id );
		$this->log( 'fluxapp', $tag . ' ' . $name );
		header( 'Cache-Control: no-store' );
		header( 'Location: ' . $url, true, 302 );
	}

	/**
	 * FluxWP release'leri (önbellekli). Tag: fluxapp-v<sürüm>. Dosya adı: fluxapp-<marka>-<sürüm>-macos-universal.(dmg|app.tar.gz|app.tar.gz.sig).
	 * Paket release'leri aynı depoda olduğundan iki sayfa okunur.
	 */
	public function fluxapp_releases(): array {
		$repo  = $this->fluxapp_repo();
		$file  = $this->data_dir . '/fluxapp-releases.json';
		$cache = $this->read_json( $file );
		if ( isset( $cache['at'], $cache['items'] ) && ( $cache['repo'] ?? '' ) === $repo && time() - (int) $cache['at'] < (int) $this->config['cache_ttl'] ) {
			return $cache['items'];
		}
		$list = array();
		try {
			for ( $page = 1; $page <= 2; $page++ ) {
				$chunk = json_decode( $this->github( '/repos/' . $repo . '/releases?per_page=100&page=' . $page ), true );
				$list  = array_merge( $list, is_array( $chunk ) ? $chunk : array() );
				if ( ! is_array( $chunk ) || count( $chunk ) < 100 ) {
					break;
				}
			}
		} catch ( \Throwable $e ) {
			error_log( '[flx-update] fluxapp releases: ' . $e->getMessage() );
			if ( isset( $cache['items'] ) ) {
				return $cache['items'];
			}
			throw new HttpError( 'Release list unavailable', 502 );
		}
		$items = array();
		foreach ( $list as $rel ) {
			if ( ! empty( $rel['draft'] ) || ! preg_match( '/^fluxapp-v([0-9][0-9A-Za-z.+-]*)$/', (string) ( $rel['tag_name'] ?? '' ), $m ) ) {
				continue;
			}
			$brands = array();
			foreach ( (array) ( $rel['assets'] ?? array() ) as $a ) {
				$name = (string) ( $a['name'] ?? '' );
				if ( ! preg_match( '/^fluxapp-([a-z0-9][a-z0-9-]*?)-[0-9][0-9A-Za-z.+-]*-macos-universal\.(dmg|app\.tar\.gz|app\.tar\.gz\.sig)$/', $name, $am ) ) {
					continue;
				}
				$kind                       = array( 'dmg' => 'dmg', 'app.tar.gz' => 'tar', 'app.tar.gz.sig' => 'sig' )[ $am[2] ];
				$brands[ $am[1] ][ $kind ] = array( 'id' => (int) $a['id'], 'name' => $name, 'size' => (int) $a['size'] );
			}
			$items[] = array(
				'version'      => $m[1],
				'tag'          => (string) $rel['tag_name'],
				'published_at' => (string) ( $rel['published_at'] ?? '' ),
				'notes_html'   => self::markdown( (string) ( $rel['body'] ?? '' ) ),
				'brands'       => $brands,
			);
		}
		$this->write_json( $file, array( 'at' => time(), 'repo' => $repo, 'items' => $items ) );
		return $items;
	}

	/* ------------------------------------------------------------------ */
	/* /v1/webhook (GitHub "Releases" olayı): önbelleği temizler           */
	/* ------------------------------------------------------------------ */

	private function route_webhook(): void {
		$secret = (string) $this->config['webhook_secret'];
		if ( '' === $secret ) {
			throw new HttpError( 'Webhook disabled', 404 );
		}
		$raw  = (string) file_get_contents( 'php://input', false, null, 0, 1048576 );
		$sig  = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
		$want = 'sha256=' . hash_hmac( 'sha256', $raw, $secret );
		if ( ! hash_equals( $want, $sig ) ) {
			throw new HttpError( 'Bad signature', 401 );
		}
		@unlink( $this->data_dir . '/releases.json' );
		@unlink( $this->data_dir . '/app-releases.json' );
		@unlink( $this->data_dir . '/fluxapp-releases.json' );
		$this->log( 'webhook', (string) ( $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '' ) );
		$this->json( array( 'ok' => true, 'flushed' => true ) );
	}

	/** Sitelerin son bildirdiği durum (sites.json): hash => {...}. */
	public function site_states(): array {
		return $this->read_json( $this->data_dir . '/sites.json' );
	}

	/** Release önbelleğini boşaltır; bir sonraki istek GitHub'dan okur. */
	public function flush_cache(): void {
		@unlink( $this->data_dir . '/releases.json' );
		@unlink( $this->data_dir . '/app-releases.json' );
		@unlink( $this->data_dir . '/fluxapp-releases.json' );
	}

	/** GitHub'a token ile erişilebiliyor mu (önbelleği kullanmadan). */
	public function test_github(): string {
		try {
			$repo = json_decode( $this->github( '/repos/' . $this->config['repo'] ), true );
			return is_array( $repo ) && ! empty( $repo['full_name'] ) ? '' : 'Beklenmeyen yanıt';
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Site doğrulama                                                      */
	/* ------------------------------------------------------------------ */

	private function auth_site(): array {
		$key = $_SERVER['HTTP_X_FLX_SITE_KEY'] ?? '';
		$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' );
		if ( '' === $key && preg_match( '/^Bearer\s+(\S+)$/i', $auth, $m ) ) {
			$key = $m[1];
		}
		$key = trim( (string) $key );
		if ( strlen( $key ) < 20 ) {
			throw new HttpError( 'Missing site key', 401 );
		}
		$hash = hash( 'sha256', $key );
		foreach ( $this->config['sites'] as $h => $conf ) {
			if ( hash_equals( strtolower( (string) $h ), $hash ) ) {
				return array(
					'hash'     => $hash,
					'name'     => (string) ( $conf['name'] ?? 'Site' ),
					'packages' => $conf['packages'] ?? '*',
					'channel'  => ( $conf['channel'] ?? 'stable' ) === 'beta' ? 'beta' : 'stable',
				);
			}
		}
		usleep( 500000 );
		$this->log( 'auth-fail', $_SERVER['REMOTE_ADDR'] ?? '' );
		throw new HttpError( 'Invalid site key', 403 );
	}

	public function site_allows( array $site, string $slug ): bool {
		if ( '*' === $site['packages'] ) {
			return true;
		}
		return in_array( $slug, (array) $site['packages'], true );
	}

	private function remember_site( array $site, array $body ): void {
		$file = $this->data_dir . '/sites.json';
		$this->with_lock(
			$file,
			function () use ( $file, $site, $body ) {
				$all  = $this->read_json( $file );
				$pkgs = array();
				foreach ( (array) ( $body['packages'] ?? array() ) as $slug => $ver ) {
					if ( is_string( $slug ) && preg_match( '/^[a-z0-9-]{1,64}$/', $slug ) && is_scalar( $ver ) ) {
						$pkgs[ $slug ] = substr( (string) $ver, 0, 32 );
					}
				}
				$all[ $site['hash'] ] = array(
					'name'      => $site['name'],
					'url'       => substr( (string) ( $body['site'] ?? '' ), 0, 200 ),
					'wp'        => substr( (string) ( $body['wp'] ?? '' ), 0, 20 ),
					'php'       => substr( (string) ( $body['php'] ?? '' ), 0, 20 ),
					'packages'  => $pkgs,
					'last_seen' => gmdate( 'Y-m-d H:i' ) . ' UTC',
					'last_seen_ts' => time(),
				);
				$this->write_json( $file, $all );
			}
		);
	}

	/* ------------------------------------------------------------------ */
	/* GitHub release'leri                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Son sürümler: slug => release. $channel 'stable' ise pre-release'ler atlanır.
	 */
	public function catalog( string $channel ): array {
		$out = array();
		foreach ( $this->releases() as $r ) {
			if ( ! $r['asset_id'] || ( $r['prerelease'] && 'beta' !== $channel ) ) {
				continue;
			}
			if ( ! isset( $out[ $r['slug'] ] ) || version_compare( $r['version'], $out[ $r['slug'] ]['version'], '>' ) ) {
				$out[ $r['slug'] ] = $r;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Paket başına indirilebilir sürümler (yeniden eskiye, en çok 20): sürüm geri alma için.
	 */
	public function versions( string $channel ): array {
		$out = array();
		foreach ( $this->releases() as $r ) {
			if ( ! $r['asset_id'] || ( $r['prerelease'] && 'beta' !== $channel ) ) {
				continue;
			}
			$out[ $r['slug'] ][] = array(
				'version'  => $r['version'],
				'released' => $r['published_at'],
				'package'  => $this->base_url() . '/v1/download/' . $r['slug'] . '/' . rawurlencode( $r['version'] ) . '.zip',
			);
		}
		foreach ( $out as $slug => $list ) {
			usort(
				$list,
				static function ( $a, $b ) {
					return version_compare( $b['version'], $a['version'] );
				}
			);
			$out[ $slug ] = array_slice( $list, 0, 20 );
		}
		return $out;
	}

	/**
	 * Tüm release'ler (önbellekli). Tag biçimi: <slug>-v<sürüm>.
	 */
	public function releases(): array {
		$file  = $this->data_dir . '/releases.json';
		$cache = $this->read_json( $file );
		if ( isset( $cache['at'], $cache['items'] ) && time() - (int) $cache['at'] < (int) $this->config['cache_ttl'] ) {
			return $cache['items'];
		}

		try {
			$items = array();
			for ( $page = 1; $page <= 10; $page++ ) {
				$list = json_decode( $this->github( '/repos/' . $this->config['repo'] . '/releases?per_page=100&page=' . $page ), true );
				if ( ! is_array( $list ) || ! $list ) {
					break;
				}
				foreach ( $list as $rel ) {
					$parsed = $this->parse_release( $rel );
					if ( $parsed ) {
						$items[] = $parsed;
					}
				}
				if ( count( $list ) < 100 ) {
					break;
				}
			}
		} catch ( \Throwable $e ) {
			// GitHub'a ulaşılamazsa eski önbellekle devam et.
			error_log( '[flx-update] releases: ' . $e->getMessage() );
			if ( isset( $cache['items'] ) ) {
				return $cache['items'];
			}
			throw new HttpError( 'Release list unavailable', 502 );
		}

		$this->write_json( $file, array( 'at' => time(), 'items' => $items ) );
		return $items;
	}

	/** @param mixed $list Dizi ya da virgüllü metin. */
	private static function plugin_slugs( $list ): array {
		if ( is_string( $list ) ) {
			$list = explode( ',', $list );
		}
		$out = array();
		foreach ( (array) $list as $s ) {
			$s = strtolower( trim( (string) $s ) );
			if ( preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/', $s ) ) {
				$out[] = $s;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private function parse_release( array $rel ): ?array {
		if ( ! empty( $rel['draft'] ) ) {
			return null;
		}
		if ( ! preg_match( '/^([a-z0-9][a-z0-9-]*)-v([0-9][0-9A-Za-z.+-]*)$/', (string) ( $rel['tag_name'] ?? '' ), $m ) ) {
			return null;
		}
		$slug    = $m[1];
		$version = $m[2];
		$body    = (string) ( $rel['body'] ?? '' );
		$meta    = array();
		if ( preg_match( '/<!--\s*flx-meta\s+(\{.*?\})\s*-->/s', $body, $mm ) ) {
			$meta = json_decode( $mm[1], true ) ?: array();
			$body = trim( str_replace( $mm[0], '', $body ) );
		}

		$asset = null;
		foreach ( (array) ( $rel['assets'] ?? array() ) as $a ) {
			if ( ( $a['name'] ?? '' ) === $slug . '.zip' ) {
				$asset = $a;
				break;
			}
		}

		return array(
			'slug'           => $slug,
			'version'        => $version,
			'tag'            => (string) $rel['tag_name'],
			'type'           => ( $meta['type'] ?? 'plugin' ) === 'theme' ? 'theme' : 'plugin',
			'name'           => (string) ( $meta['name'] ?? $slug ),
			'requires'       => (string) ( $meta['requires'] ?? '' ),
			'tested'         => (string) ( $meta['tested'] ?? '' ),
			'requires_php'   => (string) ( $meta['requires_php'] ?? '' ),
			// "Requires Plugins:" başlığındaki eklenti slug'ları (ör. ["woocommerce"]).
			'requires_plugins' => self::plugin_slugs( $meta['requires_plugins'] ?? array() ),
			'prerelease'     => ! empty( $rel['prerelease'] ),
			'published_at'   => (string) ( $rel['published_at'] ?? '' ),
			'asset_id'       => $asset ? (int) $asset['id'] : 0,
			'size'           => $asset ? (int) $asset['size'] : 0,
			'changelog_html' => self::markdown( $body ),
		);
	}

	/**
	 * GitHub API isteği. $sink verilirse gövde dosyaya yazılır.
	 * Asset indirmesinde GitHub başka bir host'a yönlendirir; curl Authorization
	 * başlığını başka host'a taşımaz (doğru davranış).
	 */
	private function github( string $path, string $accept = 'application/vnd.github+json', $sink = null ): string {
		$token = (string) $this->config['github_token'];
		if ( '' === $token ) {
			throw new \RuntimeException( 'github_token is empty' );
		}
		$ch = curl_init( rtrim( $this->config['github_api'], '/' ) . $path );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_HTTPHEADER     => array(
					'Accept: ' . $accept,
					'Authorization: Bearer ' . $token,
					'X-GitHub-Api-Version: 2022-11-28',
					'User-Agent: flx-update-server/' . VERSION,
				),
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT        => $sink ? 300 : 30,
				CURLOPT_FAILONERROR    => false,
			)
		);
		if ( $sink ) {
			curl_setopt( $ch, CURLOPT_FILE, $sink );
		} else {
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		}
		$res  = curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$err  = curl_error( $ch );
		curl_close( $ch );
		if ( false === $res ) {
			throw new \RuntimeException( 'GitHub request failed: ' . $err );
		}
		if ( $code >= 400 ) {
			throw new \RuntimeException( 'GitHub HTTP ' . $code . ' for ' . $path );
		}
		return $sink ? '' : (string) $res;
	}

	/** Asset indirme adresi: GitHub'ın verdiği yönlendirmeyi izlemeden döner. */
	private function github_redirect( string $path ): string {
		$token = (string) $this->config['github_token'];
		if ( '' === $token ) {
			throw new \RuntimeException( 'github_token is empty' );
		}
		$ch = curl_init( rtrim( $this->config['github_api'], '/' ) . $path );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_HTTPHEADER     => array(
					'Accept: application/octet-stream',
					'Authorization: Bearer ' . $token,
					'X-GitHub-Api-Version: 2022-11-28',
					'User-Agent: flx-update-server/' . VERSION,
				),
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_NOBODY         => false,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT        => 30,
			)
		);
		curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$loc  = (string) curl_getinfo( $ch, CURLINFO_REDIRECT_URL );
		curl_close( $ch );
		if ( $code < 300 || $code >= 400 || 0 !== strpos( $loc, 'https://' ) ) {
			throw new HttpError( 'Download unavailable', 502 );
		}
		return $loc;
	}

	/* ------------------------------------------------------------------ */
	/* Yardımcılar                                                         */
	/* ------------------------------------------------------------------ */

	/** Release notu için küçük, güvenli markdown (başlık, liste, kalın, kod, link). */
	public static function markdown( string $md ): string {
		$lines = preg_split( '/\r?\n/', trim( $md ) );
		$html  = '';
		$list  = false;
		foreach ( $lines as $line ) {
			$t = trim( $line );
			if ( preg_match( '/^[-*]\s+(.*)$/', $t, $m ) ) {
				if ( ! $list ) {
					$html .= '<ul>';
					$list  = true;
				}
				$html .= '<li>' . self::inline( $m[1] ) . '</li>';
				continue;
			}
			if ( $list ) {
				$html .= '</ul>';
				$list  = false;
			}
			if ( '' === $t ) {
				continue;
			}
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $t, $m ) ) {
				$html .= '<h4>' . self::inline( $m[2] ) . '</h4>';
			} else {
				$html .= '<p>' . self::inline( $t ) . '</p>';
			}
		}
		if ( $list ) {
			$html .= '</ul>';
		}
		return $html;
	}

	private static function inline( string $s ): string {
		$s = htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
		$s = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $s );
		$s = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s );
		$s = preg_replace( '/\[([^\]]+)\]\((https:\/\/[^)\s"]+)\)/', '<a href="$2">$1</a>', $s );
		return $s;
	}

	public function base_url(): string {
		if ( ! empty( $this->config['base_url'] ) ) {
			return rtrim( $this->config['base_url'], '/' );
		}
		$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
		$host = preg_replace( '/[^A-Za-z0-9.:-]/', '', $host );
		$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) || ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) === 'https';
		return ( $https ? 'https' : 'http' ) . '://' . $host;
	}

	private function json( $data, int $code = 200 ): void {
		if ( ! headers_sent() ) {
			http_response_code( $code );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Cache-Control: no-store' );
			header( 'X-Content-Type-Options: nosniff' );
		}
		echo json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private function read_json( string $file ): array {
		if ( ! is_file( $file ) ) {
			return array();
		}
		$d = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $d ) ? $d : array();
	}

	private function write_json( string $file, array $data ): void {
		if ( ! is_dir( $this->data_dir ) ) {
			mkdir( $this->data_dir, 0750, true );
		}
		$tmp = $file . '.' . getmypid() . '.tmp';
		file_put_contents( $tmp, json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		rename( $tmp, $file );
	}

	private function with_lock( string $file, callable $fn ): void {
		if ( ! is_dir( $this->data_dir ) ) {
			mkdir( $this->data_dir, 0750, true );
		}
		$lock = fopen( $file . '.lock', 'c' );
		flock( $lock, LOCK_EX );
		try {
			$fn();
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	private function log( string $type, string $msg ): void {
		if ( ! is_dir( $this->data_dir ) ) {
			return;
		}
		$line = gmdate( 'c' ) . "\t" . $type . "\t" . str_replace( array( "\n", "\r", "\t" ), ' ', $msg ) . "\n";
		file_put_contents( $this->data_dir . '/access.log', $line, FILE_APPEND | LOCK_EX );
	}
}
