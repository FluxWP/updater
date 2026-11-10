<?php
/**
 * FluxWP Update Server: GitHub release'lerini WordPress sitelerine sunar.
 *
 * Public depolardaki paketler herkese anahtarsız açıktır. Private depolardaki
 * paketler yalnız site anahtarıyla, anahtara tanımlı paketler kadar görünür.
 * GitHub token yalnız bu sunucuda durur.
 */

namespace FLXUpdate;

const VERSION = '1.15.2';
const DAY_SECONDS = 86400;

class HttpError extends \Exception {

	/** @var string Makinenin okuyacağı neden (ör. region_blocked). */
	public $reason = '';

	/** @var int 429/503 için Retry-After (saniye). */
	public $retry_after = 0;

	public function __construct( string $message, int $code = 500, string $reason = '', int $retry_after = 0 ) {
		parent::__construct( $message, $code );
		$this->reason      = $reason;
		$this->retry_after = $retry_after;
	}
}

final class Server {

	/** @var array */
	private $config;

	/** @var string */
	private $data_dir;

	public function __construct( array $config ) {
		$this->config = $config + array(
			'repo'                => '',
			'public_repos'        => '',
			'public_packages'     => '',
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
			'setup_token'         => '',
			'sites'               => array(),
			'data_dir'            => __DIR__ . '/../data',
			'github_api'          => 'https://api.github.com',
			'lockdown'            => false,
			'public_off'          => false,
			'geo_mode'            => 'off',
			'geo_countries'       => array(),
			'geo_keyed_exempt'    => true,
			'geo_auto'            => true,
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
						'configured' => ( '' !== (string) $this->config['github_token'] && ! empty( $this->config['sites'] ) ) || (bool) $this->public_repos(),
						'public'     => $this->public_enabled(),
						'paused'     => ! empty( $this->config['lockdown'] ),
					)
				);
				return;
			}
			// Yönetici API'si: acil durdurma ve ülke kuralından bağımsız, yönetici anahtarıyla.
			if ( 0 === strpos( $path, '/v1/admin/' ) ) {
				( new AdminApi( $this, $this->config ) )->handle( $method, substr( $path, 10 ) );
				return;
			}
			if ( 0 === strpos( $path, '/v1/' ) && '/v1/webhook' !== $path ) {
				$this->guard_lockdown();
				if ( 0 === strpos( $path, '/v1/app/' ) || 0 === strpos( $path, '/v1/fluxapp/' ) ) {
					// Uygulama uçları anahtarsızdır: anahtarsız dağıtım ve ülke kuralı aynen uygulanır.
					if ( ! empty( $this->config['public_off'] ) ) {
						$this->deny( 'Keyless access is disabled on this server', 401, 'public_disabled' );
					}
					$this->guard_country( array( 'anon' => true ) );
				}
			}
			if ( preg_match( '#^/v1/media/([a-z0-9][a-z0-9-]{0,63})/([a-f0-9]{64}\.(?:jpg|png|webp|svg))$#D', $path, $m ) ) {
				$this->route_preview_media( $m[1], $m[2] ); return;
			}
			if ( '/v1/check' === $path ) {
				$this->route_check( $method );
				return;
			}
			if ( preg_match( '#^/v1/download/([a-z0-9][a-z0-9-]*)/(latest|[0-9][0-9A-Za-z.+-]*)\.zip$#', $path, $m ) ) {
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
			if ( preg_match( '#^/v1/fluxapp/([a-z0-9][a-z0-9-]*)/download/(macos|windows|windows-msi)$#', $path, $m ) ) {
				$this->route_fluxapp_download_latest( $m[1], $m[2] );
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
			$out = array( 'ok' => false, 'error' => $e->getMessage() );
			if ( '' !== $e->reason ) {
				$out['code'] = $e->reason;
			}
			if ( $e->retry_after > 0 && ! headers_sent() ) {
				header( 'Retry-After: ' . (int) $e->retry_after );
			} elseif ( 503 === $e->getCode() && ! headers_sent() ) {
				header( 'Retry-After: 3600' );
			}
			$this->json( $out, $e->getCode() ?: 500 );
		} catch ( \Throwable $e ) {
			error_log( '[flx-update] ' . $e->getMessage() );
			$this->json( array( 'ok' => false, 'error' => 'Server error' ), 500 );
		}
	}



	/** Synchronize configured repositories; public UI never gets repository paths or GitHub credentials. */
	public function sync_preview_sources( bool $fresh = false ): array {
		$out = array();
		foreach ( $this->repos() as $source ) { $this->sync_preview_source( $source['repo'], $fresh ); $out[ $source['repo'] ] = ( new Previews( $this->data_dir ) )->cached( $source['repo'] ); }
		return $out;
	}

	private function sync_preview_source( string $repo, bool $fresh = false ): void {
		$source = null;
		foreach ( $this->repos() as $candidate ) { if ( strtolower( $candidate['repo'] ) === strtolower( $repo ) ) { $source = $candidate; break; } }
		if ( ! $source ) { return; }
		$store = new Previews( $this->data_dir ); $cache = $store->cached( $repo );
		if ( ! $fresh && time() - (int) ( $cache['checked_at'] ?? 0 ) < (int) $this->config['cache_ttl'] ) { return; }
		$dir = $this->data_dir . '/preview-locks'; if ( ! is_dir( $dir ) ) { mkdir( $dir, 0750, true ); }
		$lock = fopen( $dir . '/' . hash( 'sha256', strtolower( $repo ) ) . '.lock', 'c' );
		if ( ! $lock || ! flock( $lock, LOCK_EX ) ) { return; }
		try {
			$cache = $store->cached( $repo );
			if ( ! $fresh && time() - (int) ( $cache['checked_at'] ?? 0 ) < (int) $this->config['cache_ttl'] ) { return; }
			try {
				$commits = json_decode( $this->github( '/repos/' . $repo . '/commits?path=catalog%2Fpackages.json&per_page=1', 'application/vnd.github+json', null, ! $source['public'], 65536, 10 ), true, 32, JSON_THROW_ON_ERROR );
				$revision = (string) ( $commits[0]['sha'] ?? '' );
				if ( ! preg_match( '/^[a-f0-9]{40}$/D', $revision ) ) { throw new \RuntimeException( 'No GitHub package preview manifest commit found' ); }
				if ( $revision === ( $cache['revision'] ?? '' ) ) { $store->checked( $repo ); return; }
				$raw = $this->github( '/repos/' . $repo . '/contents/catalog/packages.json?ref=' . $revision, 'application/vnd.github.raw+json', null, ! $source['public'], 2 * 1024 * 1024, 15 );
				$manifest = json_decode( $raw, true, 32, JSON_THROW_ON_ERROR );
				if ( ! is_array( $manifest ) ) { throw new \RuntimeException( 'Invalid GitHub package preview manifest' ); }
				$store->store( $repo, $revision, $manifest, (bool) $source['public'] );
			} catch ( \Throwable $error ) {
				// Keep the last good manifest and immutable media when GitHub is temporarily unavailable.
				$store->checked( $repo, 'GitHub preview synchronization failed' );
				error_log( '[flx-update] previews ' . $repo . ': ' . $error->getMessage() );
			}
		} finally { flock( $lock, LOCK_UN ); fclose( $lock ); }
	}

	/** Metadata is emitted only for an authorized release from the matching repository cache. */
	public function preview_metadata( string $slug, array $site, array $release ): array {
		$data = ( new Previews( $this->data_dir ) )->read( $slug, (string) ( $release['repo'] ?? '' ) );
		$out = array( 'desc' => $data['desc'] ?? array(), 'website' => $data['website'] ?? array(), 'gallery' => array(), 'shots' => array(), 'icons' => array() );
		$scope = ! empty( $site['anon'] ) ? '' : (string) ( $site['hash'] ?? '' ); $expiry = time() + 7 * DAY_SECONDS;
		$secret = (string) ( $this->config['secret'] ?? '' );
		if ( '' === $secret ) { return $out; }
		$url = function ( string $id ) use ( $slug, $scope, $expiry, $secret ): string {
			$query = array( 'expires' => $expiry, 'site' => $scope, 'sig' => hash_hmac( 'sha256', $slug . '|' . $id . '|' . $scope . '|' . $expiry, $secret ) );
			return $this->base_url() . '/v1/media/' . $slug . '/' . $id . '?' . http_build_query( $query );
		};
		foreach ( $data['gallery'] ?? array() as $locale => $ids ) {
			foreach ( $ids as $id ) { $out['gallery'][ $locale ][] = $url( $id ); }
			if ( ! empty( $out['gallery'][ $locale ] ) ) { $out['shots'][ $locale ] = $out['gallery'][ $locale ][0]; }
		}
		foreach ( $data['icons'] ?? array() as $kind => $id ) { $out['icons'][ $kind ] = $url( $id ); }
		return $out;
	}

	private function route_preview_media( string $slug, string $id ): void {
		$expiry = (int) ( $_GET['expires'] ?? 0 ); $scope = (string) ( $_GET['site'] ?? '' ); $secret = (string) ( $this->config['secret'] ?? '' );
		$expected = hash_hmac( 'sha256', $slug . '|' . $id . '|' . $scope . '|' . $expiry, $secret );
		if ( '' === $secret || $expiry < time() || $expiry > time() + 7 * DAY_SECONDS || ! hash_equals( $expected, (string) ( $_GET['sig'] ?? '' ) ) ) { throw new HttpError( 'Media access expired or invalid', 403 ); }
		$site = array( 'anon' => true, 'channel' => 'stable', 'hash' => '', 'packages' => array(), 'repos' => array() );
		if ( '' !== $scope ) {
			$conf = $this->config['sites'][ $scope ] ?? null;
			if ( ! is_array( $conf ) || ( isset( $conf['active'] ) && ! $conf['active'] ) ) { throw new HttpError( 'Media access revoked', 403 ); }
			$site = $conf + array( 'channel' => 'stable', 'packages' => array(), 'repos' => array() ); $site['hash'] = $scope; $site['anon'] = false;
		} elseif ( ! empty( $this->config['public_off'] ) ) { throw new HttpError( 'Keyless access is disabled', 403 ); }
		$this->guard_country( $site ); $catalog = $this->catalog( $site['channel'], $site );
		if ( ! isset( $catalog[ $slug ] ) ) { throw new HttpError( 'Media not available', 404 ); }
		$release = $catalog[ $slug ]; $repo = (string) ( $release['repo'] ?? '' ); $store = new Previews( $this->data_dir );
		$metadata = $store->read( $slug, $repo ); $asset = $metadata['assets'][ $id ] ?? null;
		if ( ! is_array( $asset ) ) { throw new HttpError( 'Media not found', 404 ); }
		$file = $store->path( $id );
		if ( '' === $file ) {
			$lock_dir = $this->data_dir . '/preview-media-locks'; if ( ! is_dir( $lock_dir ) ) { mkdir( $lock_dir, 0750, true ); }
			$lock = fopen( $lock_dir . '/' . $id . '.lock', 'c' );
			if ( ! $lock || ! flock( $lock, LOCK_EX ) ) { throw new HttpError( 'Preview media temporarily unavailable', 503 ); }
			try {
				$file = $store->path( $id ); $failure = $lock_dir . '/' . $id . '.failed';
				if ( '' === $file && is_file( $failure ) && time() - filemtime( $failure ) < 300 ) { throw new HttpError( 'Preview media temporarily unavailable', 503 ); }
				if ( '' === $file ) {
					try {
						$bytes = $this->github( '/repos/' . $repo . '/git/blobs/' . $asset['sha'], 'application/vnd.github.raw+json', null, ! in_array( strtolower( $repo ), array_map( 'strtolower', $this->public_repos() ), true ), 8 * 1024 * 1024, 25 );
						$file = $store->cache_media( $asset, $bytes ); if ( is_file( $failure ) ) { unlink( $failure ); }
					} catch ( \Throwable $error ) { touch( $failure ); error_log( '[flx-update] preview media: ' . $error->getMessage() ); throw new HttpError( 'Preview media temporarily unavailable', 503 ); }
				}
			} finally { flock( $lock, LOCK_UN ); fclose( $lock ); }
		}
		$mime = array( 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'svg' => 'image/svg+xml' )[ pathinfo( $file, PATHINFO_EXTENSION ) ];
		header( 'Content-Type: ' . $mime ); header( 'X-Content-Type-Options: nosniff' ); header( 'Referrer-Policy: no-referrer' );
		if ( 'image/svg+xml' === $mime ) { header( "Content-Security-Policy: sandbox; default-src 'none'; style-src 'none'" ); }
		header( 'Cache-Control: private, max-age=300' ); header( 'Content-Length: ' . filesize( $file ) ); readfile( $file );
	}

	/* ------------------------------------------------------------------ */
	/* /v1/check                                                           */
	/* ------------------------------------------------------------------ */

	private function route_check( string $method ): void {
		$body = array();
		if ( 'POST' === $method ) {
			$raw  = (string) file_get_contents( 'php://input', false, null, 0, 262144 );
			$body = json_decode( $raw, true );
			if ( ! is_array( $body ) ) {
				$body = array();
			}
		}
		// Site adresi zorunlu kayıttır (izinden bağımsız); gövdede yoksa User-Agent'tan, sadeleştirilmiş.
		$body['site'] = Stats::site_url( (string) ( $body['site'] ?? '' ), (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
		// Panelden "Anahtar ver" denen kurulum: anahtar, tam o kurulum kimliğini sunan ilk anahtarsız isteğe bir kez teslim edilir.
		$assigned = '' === $this->request_key() ? $this->assigned_key( (string) ( $body['install'] ?? '' ) ) : '';
		try {
			$site = $this->auth_site();
		} catch ( HttpError $e ) {
			if ( '' === $assigned || 'region_blocked' === $e->reason ) {
				if ( 401 === $e->getCode() && 'public_disabled' === $e->reason ) {
					$this->stats()->record_blocked( $this->request_country() );
				}
				throw $e;
			}
			// Anahtarsız erişim kapalı olsa da teslim yapılır; site anahtarla yeniden sorar.
			$this->guard_country( array( 'anon' => true ) );
			$this->stats()->record_check( array( 'anon' => true ), $body, $this->request_country() );
			$this->json( array( 'ok' => true, 'site' => '', 'public' => true, 'packages' => array(), 'assigned_key' => $assigned ) );
			return;
		}
		$this->guard_country( $site );
		$this->maybe_prune();

		$catalog  = $this->catalog( $site['channel'], $site );
		$versions = $this->versions( $site['channel'], $site );
		$out      = array();
		$preview_repos = array();
		foreach ( $catalog as $slug => $rel ) {
			$repo = (string) ( $rel['repo'] ?? '' );
			if ( '' !== $repo && ! isset( $preview_repos[ $repo ] ) ) { $this->sync_preview_source( $repo ); $preview_repos[ $repo ] = true; }
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
				'public'       => $this->is_public( $rel ),
				'versions'     => $versions[ $slug ] ?? array(),
			) + $this->preview_metadata( $slug, $site, $rel ) + array_intersect_key( CatalogMeta::package( $this->config, $slug ), array_flip( array( 'category', 'order', 'rec' ) ) );
		}

		if ( '' !== $site['hash'] ) {
			$this->remember_site( $site, $body );
			if ( ! empty( $site['pending'] ) ) {
				$this->claimed( $site['hash'] );
			}
		}
		$this->stats()->record_check( $site, $body, $this->request_country() );
		$res = array( 'ok' => true, 'site' => $site['name'], 'public' => ! empty( $site['anon'] ), 'packages' => $out, 'categories' => CatalogMeta::categories( $this->config ) );
		if ( ! empty( $site['disabled'] ) ) {
			$res['key_disabled'] = true;
		}
		if ( '' !== $assigned ) {
			$res['assigned_key'] = $assigned;
		}
		$this->json( $res );
		$this->maybe_update_geo();
	}

	/* ------------------------------------------------------------------ */
	/* /v1/download/{slug}/{version}.zip                                   */
	/* ------------------------------------------------------------------ */

	private function route_download( string $slug, string $version ): void {
		try {
			$site = $this->auth_site();
		} catch ( HttpError $e ) {
			if ( 'public_disabled' === $e->reason ) {
				$this->stats()->record_blocked( $this->request_country() );
			}
			throw $e;
		}
		$this->guard_country( $site );
		$release = null;
		$denied  = false;
		// latest.zip: sitenin görebildiği son kararlı sürüm (web sitesindeki "İndir" butonu için).
		// Public paket anahtarsız iner; private paket yine site anahtarı ister.
		if ( 'latest' === $version ) {
			$best = null;
			foreach ( $this->releases() as $r ) {
				if ( $r['slug'] !== $slug || ! $r['asset_id'] || ( $r['prerelease'] && 'beta' !== $site['channel'] ) ) {
					continue;
				}
				if ( ! $this->can_see( $site, $r ) ) {
					$denied = true;
					continue;
				}
				if ( ! $best || version_compare( $r['version'], $best['version'], '>' ) ) {
					$best = $r;
				}
			}
			if ( ! $best ) {
				if ( $denied ) {
					throw new HttpError( empty( $site['anon'] ) ? 'Package not allowed for this site' : 'Site key required', empty( $site['anon'] ) ? 403 : 401 );
				}
				throw new HttpError( 'Package not found', 404 );
			}
			$version = $best['version'];
		}
		foreach ( $this->releases() as $r ) {
			if ( $r['slug'] !== $slug || $r['version'] !== $version || ! $r['asset_id'] ) {
				continue;
			}
			if ( $this->can_see( $site, $r ) ) {
				$release = $r;
				break;
			}
			$denied = true;
		}
		if ( ! $release && $denied ) {
			throw new HttpError( empty( $site['anon'] ) ? 'Package not allowed for this site' : 'Site key required', empty( $site['anon'] ) ? 403 : 401 );
		}
		if ( ! $release ) {
			throw new HttpError( 'Version not found', 404 );
		}
		if ( $release['prerelease'] && 'beta' !== $site['channel'] ) {
			throw new HttpError( 'Version not found', 404 );
		}

		$file = $this->zip_path( $slug, $version );
		if ( ! is_file( $file ) || filesize( $file ) !== (int) $release['size'] ) {
			$this->fetch_asset( (string) $release['repo'], (int) $release['asset_id'], $file, ! empty( $release['public'] ), (int) $release['size'] );
		}
		@touch( $file ); // Eski sürüm: son indirmeden zip_temp_hours sonra silinir.
		$this->prune_zips( $slug );

		$this->log( 'download', ( empty( $site['anon'] ) ? $site['name'] : 'public' ) . ' ' . $slug . ' ' . $version );
		$this->stats()->record_download( $site, $slug, (int) filesize( $file ), $this->request_country() );

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
		$this->prune_log();
	}

	/** access.log satırları LOG_DAYS günden eski olunca silinir (günde bir kez bakılır). */
	const LOG_DAYS = 90;

	private function prune_log(): void {
		$file  = $this->data_dir . '/access.log';
		$stamp = $this->data_dir . '/.log-pruned';
		if ( ! is_file( $file ) || ( is_file( $stamp ) && time() - (int) filemtime( $stamp ) < DAY_SECONDS ) ) {
			return;
		}
		@touch( $stamp );
		$cut  = gmdate( 'c', time() - self::LOG_DAYS * DAY_SECONDS );
		$keep = array();
		foreach ( (array) @file( $file ) as $line ) {
			if ( strncmp( (string) $line, $cut, 19 ) >= 0 ) {
				$keep[] = $line;
			}
		}
		file_put_contents( $file, implode( '', $keep ), LOCK_EX );
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

	private function fetch_asset( string $repo, int $asset_id, string $file, bool $public = false, int $expected_size = 0 ): void {
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0750, true ) && ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'Cannot create ' . $dir );
		}
		$lock = fopen( $file . '.lock', 'c' );
		flock( $lock, LOCK_EX );
		try {
			clearstatcache( true, $file );
			// Kilit alındıktan sonra tekrar bak: aynı zip'i bekleyen istek bir daha indirmesin.
			if ( is_file( $file ) ) {
				$have = (int) filesize( $file );
				if ( ( $expected_size > 0 && $have === $expected_size ) || ( $expected_size <= 0 && $have > 0 ) ) {
					return;
				}
			}
			$tmp = $file . '.part';
			$fp  = fopen( $tmp, 'wb' );
			$this->github( '/repos/' . $repo . '/releases/assets/' . $asset_id, 'application/octet-stream', $fp, ! $public );
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
		return (string) ( $this->config['fluxapp_repo'] ?: ( $this->private_repos()[0] ?? '' ) );
	}

	/**
	 * Kanal: "-" içeren sürüm (0.3.0-beta.1) yalnız beta'da. GitHub pre-release işareti kanalı belirlemez.
	 * $need: bu dosya türlerinden biri olan en yeni sürüm (dmg, tar, sig, exe, exe_sig, msi).
	 */
	private function fluxapp_best( string $brand, bool $beta, $need = array( 'dmg', 'exe', 'msi' ) ): ?array {
		$best = null;
		foreach ( $this->fluxapp_releases() as $r ) {
			if ( ! $beta && false !== strpos( $r['version'], '-' ) ) {
				continue;
			}
			if ( ! array_intersect( (array) $need, array_keys( $r['brands'][ $brand ] ?? array() ) ) ) {
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

	/** GET /v1/fluxapp/{marka}/latest?channel=stable|beta: son sürüm + kurulum dosyaları (dmg, exe, msi, güncelleme paketi). */
	private function route_fluxapp_latest( string $brand ): void {
		$best = $this->fluxapp_best( $brand, 'beta' === ( $_GET['channel'] ?? '' ) );
		header( 'Access-Control-Allow-Origin: *' );
		if ( ! $best ) {
			$this->json( array( 'ok' => true, 'version' => null ) );
			return;
		}
		$assets = array();
		foreach ( $best['brands'][ $brand ] as $kind => $a ) {
			if ( 'sig' !== $kind && 'exe_sig' !== $kind ) {
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
	 * Tauri updater biçimi. Yeni sürüm yoksa 204. macOS universal paket her iki mimariye de gider;
	 * Windows x64 setup.exe ARM64 Windows'ta da çalışır (emülasyon).
	 */
	private function route_fluxapp_tauri( string $brand, string $target, string $arch, string $current ): void {
		$beta = 'beta' === ( $_GET['channel'] ?? '' ) || false !== strpos( $current, '-' );
		$pair = array(
			'darwin'  => array( 'tar', 'sig' ),
			'windows' => array( 'exe', 'exe_sig' ),
		)[ $target ] ?? null;
		if ( ! $pair ) {
			http_response_code( 204 );
			return;
		}
		list( $pkg, $sig_kind ) = $pair;
		$best = $this->fluxapp_best( $brand, $beta, $sig_kind );
		if ( ! $best || ! version_compare( $best['version'], $current, '>' ) || empty( $best['brands'][ $brand ][ $pkg ] ) ) {
			http_response_code( 204 );
			return;
		}
		$files = $best['brands'][ $brand ];
		$sig   = $this->fluxapp_signature( (int) $files[ $sig_kind ]['id'] );
		if ( '' === $sig ) {
			http_response_code( 204 );
			return;
		}
		$this->json(
			array(
				'version'   => $best['version'],
				'notes'     => trim( preg_replace( '/\s+/', ' ', strip_tags( $best['notes_html'] ) ) ),
				'pub_date'  => $best['published_at'],
				'url'       => $this->fluxapp_url( $best['tag'], $files[ $pkg ]['name'] ),
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
	 * GET /v1/fluxapp/{marka}/download/{macos|windows|windows-msi}?channel=…: son kurulum dosyasına 302.
	 * Sitelerde ve fluxwp.com'da "İndir" düğmeleri bu kalıcı adresi kullanır.
	 */
	private function route_fluxapp_download_latest( string $brand, string $os ): void {
		$kind = array( 'macos' => 'dmg', 'windows' => 'exe', 'windows-msi' => 'msi' )[ $os ];
		$best = $this->fluxapp_best( $brand, 'beta' === ( $_GET['channel'] ?? '' ), $kind );
		if ( ! $best ) {
			throw new HttpError( 'No release for this platform yet', 404 );
		}
		header( 'Cache-Control: no-store' );
		header( 'Location: ' . $this->fluxapp_url( $best['tag'], $best['brands'][ $brand ][ $kind ]['name'] ), true, 302 );
	}

	/**
	 * FluxWP release'leri (önbellekli). Tag: fluxapp-v<sürüm>. Dosya adları:
	 * fluxapp-<marka>-<sürüm>-macos-universal.(dmg|app.tar.gz|app.tar.gz.sig),
	 * fluxapp-<marka>-<sürüm>-windows-x64(-setup.exe|-setup.exe.sig|.msi).
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
				if ( ! preg_match( '/^fluxapp-([a-z0-9][a-z0-9-]*?)-[0-9][0-9A-Za-z.+-]*-(macos-universal\.dmg|macos-universal\.app\.tar\.gz|macos-universal\.app\.tar\.gz\.sig|windows-x64-setup\.exe|windows-x64-setup\.exe\.sig|windows-x64\.msi)$/', $name, $am ) ) {
					continue;
				}
				$kind                       = array(
					'macos-universal.dmg'            => 'dmg',
					'macos-universal.app.tar.gz'     => 'tar',
					'macos-universal.app.tar.gz.sig' => 'sig',
					'windows-x64-setup.exe'          => 'exe',
					'windows-x64-setup.exe.sig'      => 'exe_sig',
					'windows-x64.msi'                => 'msi',
				)[ $am[2] ];
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
		// Refresh GitHub manifests after the release response; media blobs remain lazy.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		ignore_user_abort( true );
		try {
			$this->sync_preview_sources( true );
		} catch ( \Throwable $e ) {
			$this->log( 'media', $e->getMessage() );
		}
	}

	/** Sitelerin son bildirdiği durum (sites.json): hash => {...}. */
	public function site_states(): array {
		return $this->read_json( $this->data_dir . '/sites.json' );
	}

	public function stats(): Stats {
		return new Stats( $this->data_dir );
	}

	/**
	 * Panelin depo seçicisi için GitHub depoları: token'ın erişebildikleri ve kayıtlı
	 * depoların sahiplerindeki public depolar. 10 dakika önbellekte.
	 *
	 * @return array{repos:array,error:string}
	 */
	public function github_repos( bool $fresh = false ): array {
		$file  = $this->data_dir . '/gh-repos.json';
		$cache = $this->read_json( $file );
		if ( ! $fresh && isset( $cache['at'], $cache['repos'] ) && time() - (int) $cache['at'] < 600 ) {
			return array( 'repos' => $cache['repos'], 'error' => (string) ( $cache['error'] ?? '' ) );
		}
		$out    = array();
		$errors = array();
		$add    = static function ( array $list ) use ( &$out ) {
			foreach ( $list as $r ) {
				if ( ! is_array( $r ) || empty( $r['full_name'] ) || ! empty( $r['archived'] ) ) {
					continue;
				}
				$out[ strtolower( $r['full_name'] ) ] = array(
					'name'    => (string) $r['full_name'],
					'private' => ! empty( $r['private'] ),
					'desc'    => mb_substr( (string) ( $r['description'] ?? '' ), 0, 120 ),
				);
			}
		};
		if ( '' !== (string) $this->config['github_token'] ) {
			try {
				for ( $page = 1; $page <= 5; $page++ ) {
					$list = json_decode( $this->github( '/user/repos?per_page=100&sort=full_name&page=' . $page ), true );
					if ( ! is_array( $list ) ) {
						break;
					}
					$add( $list );
					if ( count( $list ) < 100 ) {
						break;
					}
				}
			} catch ( \Throwable $e ) {
				$errors[] = $e->getMessage();
			}
		}
		$owners = array();
		foreach ( $this->repos() as $src ) {
			$owners[ strtolower( strtok( $src['repo'], '/' ) ) ] = strtok( $src['repo'], '/' );
		}
		foreach ( $owners as $owner ) {
			try {
				$list = json_decode( $this->github( '/users/' . rawurlencode( $owner ) . '/repos?type=public&per_page=100&sort=full_name', 'application/vnd.github+json', null, false ), true );
				$add( is_array( $list ) ? $list : array() );
			} catch ( \Throwable $e ) {
				$errors[] = $e->getMessage();
			}
		}
		// Fine-grained token kendi hesabının public depolarını da listeler: yalnız kayıtlı depoların
		// sahiplerine ait depolar ve token'a açıkça verilmiş private depolar kalır.
		if ( $owners ) {
			$out = array_filter(
				$out,
				static function ( $r ) use ( $owners ) {
					return $r['private'] || isset( $owners[ strtolower( strtok( $r['name'], '/' ) ) ] );
				}
			);
		}
		ksort( $out );
		$data = array( 'at' => time(), 'repos' => array_values( $out ), 'error' => implode( '; ', $errors ) );
		$this->write_json( $file, $data );
		return array( 'repos' => $data['repos'], 'error' => $data['error'] );
	}

	/** GitHub API kotası (kota harcamaz). 5 dakika önbellekte. */
	public function github_rate(): array {
		$file  = $this->data_dir . '/gh-rate.json';
		$cache = $this->read_json( $file );
		if ( isset( $cache['at'] ) && time() - (int) $cache['at'] < 300 ) {
			return $cache;
		}
		$data = array( 'at' => time() );
		try {
			$r    = json_decode( $this->github( '/rate_limit', 'application/vnd.github+json', null, false ), true );
			$core = is_array( $r ) ? (array) ( $r['resources']['core'] ?? ( $r['rate'] ?? array() ) ) : array();
			$data += array(
				'limit'     => (int) ( $core['limit'] ?? 0 ),
				'remaining' => (int) ( $core['remaining'] ?? 0 ),
				'reset'     => (int) ( $core['reset'] ?? 0 ),
			);
		} catch ( \Throwable $e ) {
			$data['error'] = $e->getMessage();
		}
		$this->write_json( $file, $data );
		return $data;
	}

	/** Sunucu kaynakları: disk, data klasörü, önbellek, PHP. */
	public function resources(): array {
		$dir   = $this->data_dir;
		$sizes = array( 'zips' => 0, 'logs' => 0, 'cache' => 0, 'stats' => 0, 'other' => 0 );
		if ( is_dir( $dir ) ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( ! $f->isFile() ) {
					continue;
				}
				$rel = substr( $f->getPathname(), strlen( $dir ) + 1 );
				$sz  = (int) $f->getSize();
				if ( 0 === strpos( $rel, 'zips/' ) ) {
					$sizes['zips'] += $sz;
				} elseif ( '.log' === substr( $rel, -4 ) ) {
					$sizes['logs'] += $sz;
				} elseif ( 0 === strpos( $rel, 'stats' ) ) {
					$sizes['stats'] += $sz;
				} elseif ( preg_match( '/releases\.json$|^gh-/', $rel ) ) {
					$sizes['cache'] += $sz;
				} else {
					$sizes['other'] += $sz;
				}
			}
		}
		$rel = $this->read_json( $dir . '/releases.json' );
		return array(
			'data'       => $sizes,
			'data_total' => array_sum( $sizes ),
			'disk_free'  => is_dir( $dir ) ? (float) @disk_free_space( $dir ) : 0.0,
			'disk_total' => is_dir( $dir ) ? (float) @disk_total_space( $dir ) : 0.0,
			'cache_at'   => (int) ( $rel['at'] ?? 0 ),
			'cache_ttl'  => (int) $this->config['cache_ttl'],
			'php'        => PHP_VERSION,
			'memory'     => (string) ini_get( 'memory_limit' ),
			'peak'       => memory_get_peak_usage( true ),
			'max_time'   => (int) ini_get( 'max_execution_time' ),
			'curl'       => function_exists( 'curl_version' ) ? (string) ( curl_version()['version'] ?? '' ) : '',
			'load'       => function_exists( 'sys_getloadavg' ) ? @sys_getloadavg() : false,
		);
	}

	/** access.log'u boşaltır. */
	public function clear_log(): int {
		$f = $this->data_dir . '/access.log';
		$n = is_file( $f ) ? (int) filesize( $f ) : 0;
		@unlink( $f );
		return $n;
	}

	/** Release önbelleğini boşaltır; bir sonraki istek GitHub'dan okur. */
	public function flush_cache(): void {
		( new Previews( $this->data_dir ) )->invalidate();
		foreach ( glob( $this->data_dir . '/preview-media-locks/*.failed' ) ?: array() as $failure ) { unlink( $failure ); }
		@unlink( $this->data_dir . '/releases.json' );
		@unlink( $this->data_dir . '/app-releases.json' );
		@unlink( $this->data_dir . '/fluxapp-releases.json' );
		@unlink( $this->data_dir . '/gh-repos.json' );
		@unlink( $this->data_dir . '/gh-rate.json' );
	}

	/** GitHub'a token ile erişilebiliyor mu (önbelleği kullanmadan). */
	public function test_github(): string {
		try {
			foreach ( $this->repos() as $src ) {
				$repo = json_decode( $this->github( '/repos/' . $src['repo'], 'application/vnd.github+json', null, ! $src['public'] ), true );
				if ( ! is_array( $repo ) || empty( $repo['full_name'] ) ) {
					return $src['repo'] . ': beklenmeyen yanıt';
				}
			}
			return '';
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Güvenlik: acil durdurma, anahtarsız dağıtım, ülke kuralı             */
	/* ------------------------------------------------------------------ */

	/** @var Geo|null */
	private $geo = null;

	public function geo(): Geo {
		if ( ! $this->geo ) {
			$this->geo = new Geo( $this->data_dir );
		}
		return $this->geo;
	}

	/** İsteğin ülke kodu ('' bilinmiyor). IP saklanmaz. */
	public function request_country(): string {
		return $this->geo()->request_country()['cc'];
	}

	/** Anahtarsız dağıtım açık mı: public depo/paket var ve panelden kapatılmamış. */
	public function public_enabled(): bool {
		return empty( $this->config['public_off'] ) && ( (bool) $this->public_repos() || (bool) $this->public_packages() );
	}

	/** Seçili ülkeler (büyük harf ISO kodları). */
	public function geo_countries(): array {
		$out = array();
		foreach ( (array) $this->config['geo_countries'] as $cc ) {
			$cc = strtoupper( trim( (string) $cc ) );
			if ( preg_match( '/^[A-Z]{2}$/', $cc ) ) {
				$out[ $cc ] = $cc;
			}
		}
		return array_values( $out );
	}

	/**
	 * Ülke kuralı bu ülkeye izin veriyor mu? deny: listedekiler kapalı, bilinmeyen açık.
	 * allow: yalnız listedekiler açık, bilinmeyen kapalı.
	 */
	public function country_allowed( string $cc ): bool {
		$mode = (string) $this->config['geo_mode'];
		if ( 'deny' === $mode ) {
			return '' === $cc || ! in_array( $cc, $this->geo_countries(), true );
		}
		if ( 'allow' === $mode ) {
			return '' !== $cc && in_array( $cc, $this->geo_countries(), true );
		}
		return true;
	}

	private function guard_lockdown(): void {
		if ( ! empty( $this->config['lockdown'] ) ) {
			throw new HttpError( 'Update distribution is paused on this server', 503, 'paused' );
		}
	}

	/** Ülke kuralı. Anahtarlı (aktif) siteler, ayar açıksa muaftır. */
	private function guard_country( array $site ): void {
		if ( 'off' === (string) $this->config['geo_mode'] || '' === (string) $this->config['geo_mode'] ) {
			return;
		}
		if ( empty( $site['anon'] ) && ! empty( $this->config['geo_keyed_exempt'] ) ) {
			return;
		}
		$cc = $this->request_country();
		if ( $this->country_allowed( $cc ) ) {
			return;
		}
		$this->deny( 'Not available in your region', 403, 'region_blocked' );
	}

	/** Engellenen isteği sayar (yalnız ülke kodu) ve geri çevirir. */
	private function deny( string $msg, int $code, string $reason ): void {
		$this->stats()->record_blocked( $this->request_country() );
		throw new HttpError( $msg, $code, $reason );
	}

	/**
	 * Ülke veritabanı eskiyse yanıt gönderildikten sonra yeniler (istemciyi bekletmez).
	 * Yanıtı erken kapatma imkânı yoksa (fastcgi/litespeed) panelden yenilenir.
	 */
	private function maybe_update_geo(): void {
		if ( empty( $this->config['geo_auto'] ) || ! $this->geo()->needs_update() ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		} else {
			return;
		}
		@set_time_limit( 120 );
		try {
			$src = $this->geo()->update();
			$this->log( 'geo-update', $src );
		} catch ( \Throwable $e ) {
			$this->log( 'geo-update-failed', $e->getMessage() );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Site doğrulama                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Anahtarsız istek: yalnız public paketleri gören anonim site. Anahtar verildiyse
	 * geçerli olmalı (yanlış anahtar sessizce public'e düşmez, site hatayı görür).
	 */
	private function request_key(): string {
		$key  = $_SERVER['HTTP_X_FLUXWP_SITE_KEY'] ?? ( $_SERVER['HTTP_X_FLX_SITE_KEY'] ?? '' );
		$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' );
		if ( '' === $key && preg_match( '/^Bearer\s+(\S+)$/i', $auth, $m ) ) {
			$key = $m[1];
		}
		return trim( (string) $key );
	}

	/**
	 * İstemci anahtarı: IP saklanmaz. Cloudflare arkasında gerçek adres yalnız
	 * bağlantı Cloudflare IP'sinden geliyorsa okunur; özeti 10 dk'lık tuzla yazılır.
	 */
	private function throttle_client(): string {
		$remote = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$ip     = $remote;
		if ( '' !== $remote && Geo::is_cloudflare( $remote ) ) {
			$real = (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '' );
			if ( filter_var( $real, FILTER_VALIDATE_IP ) ) {
				$ip = $real;
			}
		}
		return '' !== $ip ? $ip : 'unknown';
	}

	/**
	 * Başarısız kimlik doğrulama denemelerini sınırlar (panel girişi, kurulum, yönetici anahtarı).
	 * Dosyada IP yok: kapsam + istemci, kısa ömürlü tuzla HMAC'lenir. Tuz pencereyle birlikte değişir.
	 *
	 * Ön kontrol ($record false) yalnız bu istemciyi kilitler; başka yerden gelen başarılı giriş
	 * genel sayaca takılmaz. Genel sınır yalnız başarısız denemeyi 429 yapar.
	 *
	 * @param bool $record true: başarısız denemeyi kaydeder; false: bu istemci kilitli mi bakar.
	 * @return int Sınır aşıldıysa kalan saniye (Retry-After), değilse 0.
	 */
	public function auth_throttle( string $scope, bool $record = false, int $client_max = 8, int $global_max = 40, int $window = 600 ): int {
		if ( ! is_dir( $this->data_dir ) && ! @mkdir( $this->data_dir, 0750, true ) && ! is_dir( $this->data_dir ) ) {
			return 0;
		}
		$file = $this->data_dir . '/.throttle.json';
		$lock = @fopen( $file . '.lock', 'c' );
		if ( ! $lock ) {
			return 0;
		}
		flock( $lock, LOCK_EX );
		try {
			$now          = time();
			$data         = $this->read_json( $file );
			$prev_clients = (array) ( $data['clients'] ?? array() );
			$prev_global  = (array) ( $data['global'] ?? array() );
			$salt         = (string) ( $data['salt'] ?? '' );
			$born         = (int) ( $data['salt_at'] ?? 0 );
			$fresh        = strlen( $salt ) < 32 || $born <= 0 || ( $now - $born ) >= $window;
			if ( $fresh ) {
				$salt         = bin2hex( random_bytes( 32 ) );
				$born         = $now;
				$prev_clients = array();
			}
			$cut     = $now - $window;
			$clients = array();
			foreach ( $prev_clients as $k => $e ) {
				if ( is_array( $e ) && (int) ( $e['t'] ?? 0 ) > $cut ) {
					$clients[ (string) $k ] = array( 'n' => (int) ( $e['n'] ?? 0 ), 't' => (int) $e['t'] );
				}
			}
			$global = array();
			foreach ( $prev_global as $s => $e ) {
				if ( is_array( $e ) && (int) ( $e['t'] ?? 0 ) > $cut ) {
					$global[ (string) $s ] = array( 'n' => (int) ( $e['n'] ?? 0 ), 't' => (int) $e['t'] );
				}
			}
			$cid   = substr( hash_hmac( 'sha256', $scope . '|' . $this->throttle_client(), $salt ), 0, 32 );
			$ce    = $clients[ $cid ] ?? array( 'n' => 0, 't' => $now );
			$ge    = $global[ $scope ] ?? array( 'n' => 0, 't' => $now );
			$retry = 0;
			if ( (int) $ce['n'] >= $client_max ) {
				$retry = max( 1, ( (int) $ce['t'] + $window ) - $now );
			}
			if ( $record ) {
				if ( (int) $ce['n'] < 1 ) {
					$ce['t'] = $now;
				}
				if ( (int) $ge['n'] < 1 ) {
					$ge['t'] = $now;
				}
				$ce['n'] = min( $client_max, (int) $ce['n'] + 1 );
				$ge['n'] = min( $global_max, (int) $ge['n'] + 1 );
				if ( count( $clients ) >= 2000 && ! isset( $clients[ $cid ] ) ) {
					uasort(
						$clients,
						static function ( $a, $b ) {
							return $a['t'] <=> $b['t'];
						}
					);
					$clients = array_slice( $clients, -1000, null, true );
				}
				$clients[ $cid ]  = $ce;
				$global[ $scope ] = $ge;
				if ( (int) $ce['n'] >= $client_max ) {
					$retry = max( $retry, max( 1, ( (int) $ce['t'] + $window ) - $now ) );
				}
				if ( (int) $ge['n'] >= $global_max ) {
					$retry = max( $retry, max( 1, ( (int) $ge['t'] + $window ) - $now ) );
				}
			}
			$dirty = $fresh || $record || count( $clients ) !== count( (array) ( $data['clients'] ?? array() ) ) || count( $global ) !== count( (array) ( $data['global'] ?? array() ) );
			if ( $dirty ) {
				$tmp = $file . '.' . getmypid() . '.tmp';
				if ( false !== @file_put_contents( $tmp, json_encode( array( 'salt' => $salt, 'salt_at' => $born, 'clients' => $clients, 'global' => $global ) ) ) ) {
					@chmod( $tmp, 0600 );
					@rename( $tmp, $file );
				}
			}
			return $retry;
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	private function auth_site(): array {
		$key = $this->request_key();
		if ( '' === $key ) {
			if ( ! empty( $this->config['public_off'] ) ) {
				throw new HttpError( 'Keyless access is disabled on this server', 401, 'public_disabled' );
			}
			if ( ! $this->public_repos() && ! $this->public_packages() ) {
				throw new HttpError( 'Missing site key', 401 );
			}
			return array(
				'hash'     => '',
				'name'     => '',
				'packages' => array(),
				'repos'    => array(),
				'channel'  => 'stable',
				'anon'     => true,
			);
		}
		// Site anahtarı 192 bit rastgeledir; deneme sınırı konmaz. Paylaşımlı barındırmada aynı çıkış IP'sindeki
		// tek hatalı site, geçerli anahtarlı komşularını da kilitlerdi.
		if ( strlen( $key ) < 20 ) {
			$this->log( 'auth-fail', $this->request_country() );
			throw new HttpError( 'Invalid site key', 403 );
		}
		$hash = hash( 'sha256', $key );
		foreach ( $this->config['sites'] as $h => $conf ) {
			if ( hash_equals( strtolower( (string) $h ), $hash ) ) {
				$site = array(
					'hash'     => $hash,
					'name'     => (string) ( $conf['name'] ?? 'Site' ),
					'packages' => $conf['packages'] ?? array(),
					'repos'    => (array) ( $conf['repos'] ?? array() ),
					'channel'  => ( $conf['channel'] ?? 'stable' ) === 'beta' ? 'beta' : 'stable',
					'pending'  => ! empty( $conf['pending'] ),
				);
				// Pasif anahtar: site tanınır ama yalnız public paketleri görür.
				if ( isset( $conf['active'] ) && ! $conf['active'] ) {
					if ( ! empty( $this->config['public_off'] ) ) {
						// Pasif anahtar anahtarsız sayılır; anahtarsız dağıtım kapalıyken hiçbir şey almaz.
						throw new HttpError( 'Site key disabled', 403, 'key_disabled' );
					}
					$site['anon']     = true;
					$site['disabled'] = true;
					$site['packages'] = array();
					$site['repos']    = array();
				}
				return $site;
			}
		}
		$this->log( 'auth-fail', $this->request_country() );
		throw new HttpError( 'Invalid site key', 403 );
	}

	/**
	 * Anahtarın private pakete izni: "*" tüm private depolar; yoksa depo bütünüyle
	 * (repos, sonradan eklenen paketler dahil) ya da tek tek seçilen paket (packages).
	 */
	public function site_allows( array $site, array $release ): bool {
		if ( ! empty( $site['anon'] ) ) {
			return false;
		}
		$packages = $site['packages'] ?? array();
		if ( '*' === $packages ) {
			return true;
		}
		$repo = strtolower( (string) ( $release['repo'] ?? '' ) );
		foreach ( (array) ( $site['repos'] ?? array() ) as $r ) {
			if ( '' !== $repo && strtolower( (string) $r ) === $repo ) {
				return true;
			}
		}
		return is_array( $packages ) && in_array( (string) $release['slug'], $packages, true );
	}

	/** Public depodaki paket herkese; private depodaki yalnız izinli anahtara. */
	public function can_see( array $site, array $release ): bool {
		return $this->is_public( $release ) || $this->site_allows( $site, $release );
	}

	/* ------------------------------------------------------------------ */
	/* Panelden verilen anahtarın siteye teslimi                           */
	/* ------------------------------------------------------------------ */

	const CLAIM_HOURS = 24;

	/** Yönetici API'sindeki claim_days alanı. Teslim süresi CLAIM_HOURS'tur. */
	const CLAIM_DAYS = 1;

	/**
	 * Teslim bekleyen anahtar, sunucunun gizli değeri ve kurulum kimliği özetinden türetilir;
	 * düz anahtar hiçbir yerde saklanmaz. Kurulum kimliğini yalnız site bilir (sunucu özetini tutar).
	 */
	public static function derive_key( string $secret, string $ih, string $nonce ): string {
		return 'flxu_' . substr( hash_hmac( 'sha256', 'claim|' . $ih . '|' . $nonce, $secret ), 0, 48 );
	}

	/**
	 * Kurulum kimliği opak bir belirteçtir: tam 40 küçük onaltılık karakter (istemci 20 bayt üretir).
	 * Uymayan değer yok sayılır. Stats ve bekleyen anahtar bunun sha256 özetini tutar.
	 */
	public static function install_hash( string $install ): string {
		if ( 40 !== strlen( $install ) || ! preg_match( '/^[a-f0-9]{40}$/', $install ) ) {
			return '';
		}
		return hash( 'sha256', $install );
	}

	/** Bellekte bu kurulum özetine verilmiş, süresi dolmamış teslim var mı? */
	private function pending_matches( string $ih ): bool {
		foreach ( (array) $this->config['sites'] as $conf ) {
			if ( ! is_array( $conf ) ) {
				continue;
			}
			$p = is_array( $conf['pending'] ?? null ) ? $conf['pending'] : null;
			if ( ! $p ) {
				continue;
			}
			$pih = (string) ( $p['ih'] ?? '' );
			if ( 64 === strlen( $pih ) && hash_equals( $pih, $ih ) && (int) ( $p['exp'] ?? 0 ) >= time() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Bu kurulum kimliğine verilmiş anahtar ('' yoksa). İlk eşleşen istek anahtarı alır;
	 * teslim settings.json kilidi altında silinir. Süresi dolan teslim durur, silinmez.
	 */
	private function assigned_key( string $install ): string {
		$ih     = self::install_hash( $install );
		$secret = (string) ( $this->config['secret'] ?? '' );
		if ( '' === $ih || '' === $secret || ! $this->pending_matches( $ih ) ) {
			return '';
		}
		$key  = '';
		$name = '';
		$hash = '';
		Settings::modify(
			$this->data_dir,
			function ( array $data ) use ( $ih, $secret, &$key, &$name, &$hash ) {
				$sites = is_array( $data['sites'] ?? null ) ? $data['sites'] : array();
				foreach ( $sites as $h => $conf ) {
					if ( ! is_array( $conf ) ) {
						continue;
					}
					$p = is_array( $conf['pending'] ?? null ) ? $conf['pending'] : null;
					if ( ! $p ) {
						continue;
					}
					$pih = (string) ( $p['ih'] ?? '' );
					if ( 64 !== strlen( $pih ) || ! hash_equals( $pih, $ih ) || (int) ( $p['exp'] ?? 0 ) < time() ) {
						continue;
					}
					$candidate = self::derive_key( $secret, $ih, (string) ( $p['n'] ?? '' ) );
					if ( ! hash_equals( strtolower( (string) $h ), hash( 'sha256', $candidate ) ) ) {
						continue;
					}
					unset( $sites[ $h ]['pending'] );
					$sites[ $h ]['claimed'] = gmdate( 'Y-m-d H:i' ) . ' UTC';
					$data['sites']          = $sites;
					$key                    = $candidate;
					$name                   = (string) ( $conf['name'] ?? '' );
					$hash                   = (string) $h;
					return $data;
				}
				return null;
			}
		);
		if ( '' === $key ) {
			return '';
		}
		if ( isset( $this->config['sites'][ $hash ] ) && is_array( $this->config['sites'][ $hash ] ) ) {
			unset( $this->config['sites'][ $hash ]['pending'] );
			$this->config['sites'][ $hash ]['claimed'] = gmdate( 'Y-m-d H:i' ) . ' UTC';
		}
		$this->log( 'key-delivered', $name );
		return $key;
	}

	/** Site anahtarla bağlandı: teslim tamam. Kilit altında okunur; başka bir teslimin silinmesini geri getirmez. */
	private function claimed( string $hash ): void {
		Settings::modify(
			$this->data_dir,
			static function ( array $data ) use ( $hash ) {
				$sites = is_array( $data['sites'] ?? null ) ? $data['sites'] : array();
				if ( ! isset( $sites[ $hash ]['pending'] ) ) {
					return null;
				}
				unset( $sites[ $hash ]['pending'] );
				$sites[ $hash ]['claimed'] = gmdate( 'Y-m-d H:i' ) . ' UTC';
				$data['sites']             = $sites;
				return $data;
			}
		);
	}

	/** @return string[] owner/repo listesi (virgül, boşluk ya da satır ile ayrılmış metin de olur). */
	private static function repo_list( $value ): array {
		$list = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
		$out  = array();
		foreach ( (array) $list as $r ) {
			$r = trim( (string) $r );
			if ( preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $r ) ) {
				$out[ strtolower( $r ) ] = $r;
			}
		}
		return array_values( $out );
	}

	/**
	 * Private depodaki ama herkese anahtarsız açık paketler (ör. web sitesinden indirilen flx-setup).
	 *
	 * @return string[] slug listesi
	 */
	public function public_packages(): array {
		$out = array();
		foreach ( preg_split( '/[\s,]+/', (string) ( $this->config['public_packages'] ?? '' ) ) as $s ) {
			$s = strtolower( trim( $s ) );
			if ( preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/', $s ) ) {
				$out[ $s ] = $s;
			}
		}
		return array_values( $out );
	}

	/** Paket anahtarsız iner mi: public depoda ya da public paket listesinde. */
	public function is_public( array $release ): bool {
		return ! empty( $release['public'] ) || in_array( (string) $release['slug'], $this->public_packages(), true );
	}

	public function public_repos(): array {
		return self::repo_list( $this->config['public_repos'] );
	}

	public function private_repos(): array {
		return array_values( array_diff( self::repo_list( $this->config['repo'] ), $this->public_repos() ) );
	}

	/** Okunacak depolar: önce private (paketler), sonra public. */
	public function repos(): array {
		$out = array();
		foreach ( $this->private_repos() as $r ) {
			$out[] = array( 'repo' => $r, 'public' => false );
		}
		foreach ( $this->public_repos() as $r ) {
			$out[] = array( 'repo' => $r, 'public' => true );
		}
		return $out;
	}

	private function remember_site( array $site, array $body ): void {
		$file = $this->data_dir . '/sites.json';
		$this->with_lock(
			$file,
			function () use ( $file, $site, $body ) {
				$all  = $this->read_json( $file );
				if ( ! Stats::usage_allowed( $body ) ) {
					unset( $body['wp'], $body['php'] );
				}
				$pkgs = array();
				foreach ( (array) ( $body['packages'] ?? array() ) as $slug => $ver ) {
					if ( is_string( $slug ) && preg_match( '/^[a-z0-9-]{1,64}$/', $slug ) && is_scalar( $ver ) ) {
						$pkgs[ $slug ] = substr( (string) $ver, 0, 32 );
					}
				}
				$all[ $site['hash'] ] = array(
					'name'      => $site['name'],
					'url'       => (string) ( $body['site'] ?? '' ),
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
	public function catalog( string $channel, ?array $site = null ): array {
		$out = array();
		foreach ( $this->releases() as $r ) {
			if ( ! $r['asset_id'] || ( $r['prerelease'] && 'beta' !== $channel ) || ( null !== $site && ! $this->can_see( $site, $r ) ) ) {
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
	public function versions( string $channel, ?array $site = null ): array {
		$out = array();
		foreach ( $this->releases() as $r ) {
			if ( ! $r['asset_id'] || ( $r['prerelease'] && 'beta' !== $channel ) || ( null !== $site && ! $this->can_see( $site, $r ) ) ) {
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
	 * Tüm depoların release'leri (önbellekli). Tag biçimi: <slug>-v<sürüm>.
	 * Okunamayan depo için önbellekteki eski liste kullanılır; diğerleri etkilenmez.
	 */
	public function releases(): array {
		$file  = $this->data_dir . '/releases.json';
		$cache = $this->read_json( $file );
		$sig   = md5( json_encode( $this->repos() ) );
		if ( isset( $cache['at'], $cache['items'] ) && ( $cache['sig'] ?? '' ) === $sig && time() - (int) $cache['at'] < (int) $this->config['cache_ttl'] ) {
			return self::drop_shadowed( (array) $cache['items'] );
		}

		$items  = array();
		$errors = array();
		foreach ( $this->repos() as $src ) {
			try {
				$got = array();
				for ( $page = 1; $page <= 10; $page++ ) {
					$list = json_decode( $this->github( '/repos/' . $src['repo'] . '/releases?per_page=100&page=' . $page, 'application/vnd.github+json', null, ! $src['public'] ), true );
					if ( ! is_array( $list ) || ! $list ) {
						break;
					}
					foreach ( $list as $rel ) {
						$parsed = is_array( $rel ) ? $this->parse_release( $rel ) : null;
						if ( $parsed ) {
							$got[] = $parsed + array(
								'repo'   => $src['repo'],
								'public' => $src['public'],
							);
						}
					}
					if ( count( $list ) < 100 ) {
						break;
					}
				}
				$items = array_merge( $items, $got );
			} catch ( \Throwable $e ) {
				// GitHub'a ulaşılamazsa bu deponun eski önbelleğiyle devam et.
				error_log( '[flx-update] releases ' . $src['repo'] . ': ' . $e->getMessage() );
				$errors[ $src['repo'] ] = $e->getMessage();
				foreach ( (array) ( $cache['items'] ?? array() ) as $old ) {
					if ( ( $old['repo'] ?? '' ) === $src['repo'] ) {
						$items[] = $old;
					}
				}
			}
		}
		if ( $errors && count( $errors ) === count( $this->repos() ) && ! $items ) {
			throw new HttpError( 'Release list unavailable', 502 );
		}

		$this->write_json( $file, array( 'at' => time(), 'sig' => $sig, 'items' => $items, 'errors' => $errors ) );
		return self::drop_shadowed( $items );
	}

	/**
	 * Bir paket private depoya taşındıysa public depoda kalan eski release'leri yok sayılır:
	 * paket anahtarsız inmez, yalnız private kopyası (anahtara tanımlıysa) dağıtılır.
	 * Anahtarsız açmak için slug "Herkese açık paketler" listesine yazılır.
	 *
	 * @param array[] $items releases() öğeleri
	 * @return array[]
	 */
	public static function drop_shadowed( array $items ): array {
		$private = array();
		foreach ( $items as $r ) {
			if ( is_array( $r ) && empty( $r['public'] ) && isset( $r['slug'] ) ) {
				$private[ (string) $r['slug'] ] = true;
			}
		}
		if ( ! $private ) {
			return $items;
		}
		return array_values(
			array_filter(
				$items,
				static function ( $r ) use ( $private ) {
					return ! ( is_array( $r ) && ! empty( $r['public'] ) && isset( $private[ (string) ( $r['slug'] ?? '' ) ] ) );
				}
			)
		);
	}

	/** Son okumada hata veren depolar: repo => hata. */
	public function repo_errors(): array {
		return (array) ( $this->read_json( $this->data_dir . '/releases.json' )['errors'] ?? array() );
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
	private function github( string $path, string $accept = 'application/vnd.github+json', $sink = null, bool $need_token = true, int $max_bytes = 0, int $timeout = 30 ): string {
		$token = (string) $this->config['github_token'];
		if ( '' === $token && $need_token ) {
			throw new \RuntimeException( 'github_token is empty' );
		}
		$headers = array(
			'Accept: ' . $accept,
			'X-GitHub-Api-Version: 2022-11-28',
			'User-Agent: flx-update-server/' . VERSION,
		);
		if ( '' !== $token ) {
			$headers[] = 'Authorization: Bearer ' . $token;
		}
		$ch = curl_init( rtrim( $this->config['github_api'], '/' ) . $path );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT        => $sink ? 300 : $timeout,
				CURLOPT_FAILONERROR    => false,
			)
		);
		if ( $sink ) {
			curl_setopt( $ch, CURLOPT_FILE, $sink );
		} else {
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		}
		$buffer = '';
		if ( ! $sink && $max_bytes > 0 ) {
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false );
			curl_setopt( $ch, CURLOPT_WRITEFUNCTION, static function ( $handle, string $chunk ) use ( &$buffer, $max_bytes ): int { if ( strlen( $buffer ) + strlen( $chunk ) > $max_bytes ) { return 0; } $buffer .= $chunk; return strlen( $chunk ); } );
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
		return $sink ? '' : ( $max_bytes > 0 ? $buffer : (string) $res );
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
		$host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
		$host  = preg_replace( '/[^A-Za-z0-9.:-]/', '', (string) $host );
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
