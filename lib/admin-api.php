<?php
/**
 * Yönetici API'si (/v1/admin/*): bu sunucuyu başka bir panelden (ör. bir WordPress sitesi)
 * yönetmek için. Yönetici anahtarıyla (X-FluxWP-Manager-Key ya da Authorization: Bearer) çalışır;
 * paneldeki tüm ayarlar ve istatistikler JSON olarak okunur, işlemler panelin kendi kodu ile yapılır.
 *
 *   GET  /v1/admin/info                     sunucu, sürüm, anahtar adı
 *   GET  /v1/admin/overview?r=7|30|90       Genel bakış: KPI, günlük grafik, paketler, ülkeler, bağlanan siteler
 *   GET  /v1/admin/resources                disk, data/, GitHub kotası, PHP, ülke veritabanı
 *   GET  /v1/admin/settings                 GitHub, siteler, paketler, güvenlik, dosyalar, yönetici anahtarları
 *   GET  /v1/admin/repos?fresh=1            depo seçici listesi
 *   POST /v1/admin/action   {"action":"save_switch","switch":"lockdown","on":"1"}  panel işlemi
 *   POST /v1/admin/test-key {"key":"flxu_…"}  site anahtarı testi
 *
 * Dil: ?lang=tr|en (yanıttaki metinler). Anahtarın yalnız sha256 özeti data/settings.json'da (manager_keys) durur.
 */

namespace FLXUpdate;

final class AdminApi {

	const HEADER = 'HTTP_X_FLUXWP_MANAGER_KEY';

	/** @var Server */
	private $server;

	/** @var array */
	private $config;

	/** @var string */
	private $data_dir;

	/** @var array Doğrulanan anahtar: hash, name. */
	private $key = array();

	public function __construct( Server $server, array $config ) {
		$this->server   = $server;
		$this->config   = $config;
		$this->data_dir = rtrim( (string) $config['data_dir'], '/' );
	}

	/** Kayıtlı yönetici anahtarları: sha256 => {name, created, last, hint}. */
	public static function keys( string $data_dir ): array {
		$keys = Settings::load( $data_dir )['manager_keys'] ?? array();
		return is_array( $keys ) ? array_filter( $keys, 'is_array' ) : array();
	}

	public function handle( string $method, string $route ): void {
		header( 'Cache-Control: no-store' );
		header( 'X-Content-Type-Options: nosniff' );
		$this->auth();
		$lang = (string) ( $_GET['lang'] ?? ( $_SERVER['HTTP_X_FLUXWP_LANG'] ?? '' ) );
		I18n::use_lang( 0 === strpos( strtolower( $lang ), 'tr' ) ? 'tr' : 'en' );

		$get  = 'GET' === $method;
		$post = 'POST' === $method;
		if ( $get && 'info' === $route ) {
			$this->json( $this->info() );
		} elseif ( $get && 'overview' === $route ) {
			$this->json( $this->overview( (int) ( $_GET['r'] ?? 30 ) ) );
		} elseif ( $get && 'resources' === $route ) {
			$this->json( $this->resources() );
		} elseif ( $get && 'settings' === $route ) {
			$this->json( $this->settings() );
		} elseif ( $get && 'repos' === $route ) {
			$this->json( array( 'ok' => true ) + $this->server->github_repos( ! empty( $_GET['fresh'] ) ) );
		} elseif ( $post && 'action' === $route ) {
			$body   = $this->body();
			$action = (string) ( $body['action'] ?? '' );
			unset( $body['action'] );
			$panel = new Panel( $this->server, $this->config );
			$r     = $panel->api_action( $action, $body, (string) $this->key['name'] );
			$this->json( $r, $r['ok'] ? 200 : 422 );
		} elseif ( $post && 'test-key' === $route ) {
			$this->json( $this->test_key( (string) ( $this->body()['key'] ?? '' ) ) );
		} else {
			throw new HttpError( 'Not found', 404 );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Kimlik                                                              */
	/* ------------------------------------------------------------------ */

	private function auth(): void {
		$key = trim( (string) ( $_SERVER[ self::HEADER ] ?? '' ) );
		if ( '' === $key && preg_match( '/^Bearer\s+(\S+)$/i', (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? ( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ) ), $m ) ) {
			$key = $m[1];
		}
		$hash = preg_match( '/^flxm_[a-f0-9]{48}$/', $key ) ? hash( 'sha256', $key ) : '';
		$keys = self::keys( $this->data_dir );
		$hit  = null;
		foreach ( $keys as $h => $k ) {
			if ( '' !== $hash && hash_equals( (string) $h, $hash ) ) {
				$hit = $k + array( 'hash' => (string) $h );
			}
		}
		if ( ! $hit ) {
			usleep( 400000 ); // Deneme-yanılmayı yavaşlatır.
			throw new HttpError( '' === $key ? 'Manager key required' : 'Invalid manager key', 401, 'manager_key_invalid' );
		}
		$this->key = $hit;
		// Son kullanım: 5 dakikada bir yazılır.
		if ( time() - (int) ( $hit['last'] ?? 0 ) > 300 ) {
			$keys[ $hit['hash'] ]['last'] = time();
			Settings::update( $this->data_dir, array( 'manager_keys' => $keys ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Okuma                                                               */
	/* ------------------------------------------------------------------ */

	private function info(): array {
		return array(
			'ok'         => true,
			'service'    => 'flx-update-server',
			'version'    => VERSION,
			'base_url'   => $this->server->base_url(),
			'key_name'   => (string) ( $this->key['name'] ?? '' ),
			'lang'       => I18n::lang(),
			'admin_url'  => $this->server->base_url() . '/admin',
			'lockdown'   => ! empty( $this->config['lockdown'] ),
			'public_off' => ! empty( $this->config['public_off'] ),
			'public'     => $this->server->public_enabled(),
		);
	}

	/** Katalog (beta dahil) ve kararlı katalog; GitHub'a ulaşılamazsa hata metni. */
	private function catalogs(): array {
		$token   = (string) $this->config['github_token'];
		$has_pub = (bool) $this->server->public_repos();
		if ( '' === $token && ! $has_pub ) {
			return array( array(), array(), __( 'GitHub token girilmemiş.' ) );
		}
		try {
			return array( $this->server->catalog( 'beta' ), $this->server->catalog( 'stable' ), '' );
		} catch ( \Throwable $ex ) {
			$err = $this->server->test_github();
			return array( array(), array(), $err ? __( 'GitHub\'a erişilemedi: ' ) . $err : __( 'GitHub release listesi okunamadı.' ) );
		}
	}

	private function overview( int $range ): array {
		$range = in_array( $range, array( 7, 30, 90 ), true ) ? $range : 30;
		list( $catalog ) = $this->catalogs();
		$S       = $this->server->stats()->summary( $range );
		$names   = array();
		foreach ( $catalog as $slug => $r ) {
			$names[ $slug ] = array( 'name' => (string) $r['name'], 'public' => $this->server->is_public( $r ) );
		}

		// Bekleyen anahtar teslimleri: kurulum özeti => anahtar özeti.
		$pending = array();
		$hashes  = array();
		foreach ( (array) $this->config['sites'] as $h => $conf ) {
			$hashes[ 'k' . substr( (string) $h, 0, 15 ) ] = (string) $h;
			if ( ! empty( $conf['pending']['ih'] ) && (int) ( $conf['pending']['exp'] ?? 0 ) >= time() ) {
				$pending[ $conf['pending']['ih'] ] = (string) $h;
			}
		}
		$clients = array();
		foreach ( $S['clients'] as $cid => $c ) {
			$ih = (string) ( $c['ih'] ?? '' );
			if ( ! empty( $c['key'] ) ) {
				$access = ! empty( $c['off'] ) ? 'off' : 'keyed';
			} elseif ( '' === $ih ) {
				$access = 'needs_setup';
			} elseif ( isset( $pending[ $ih ] ) ) {
				$access = 'pending';
			} else {
				$access = 'can_issue';
			}
			$clients[] = array(
				'id'       => (string) $cid,
				'url'      => (string) ( $c['url'] ?? '' ),
				'name'     => (string) ( $c['name'] ?? '' ),
				'access'   => $access,
				'site'     => $hashes[ (string) $cid ] ?? ( $pending[ $ih ] ?? '' ),
				'cc'       => (string) ( $c['cc'] ?? '' ),
				'country'  => ! empty( $c['cc'] ) ? Panel::country_name( (string) $c['cc'] ) : '',
				'wp'       => (string) ( $c['wp'] ?? '' ),
				'php'      => (string) ( $c['php'] ?? '' ),
				'panel'    => (string) ( $c['upd'] ?? '' ),
				'packages' => (array) ( $c['pk'] ?? array() ),
				'checks'   => (int) ( $c['n'] ?? 0 ),
				'first'    => (int) ( $c['first'] ?? 0 ),
				'last'     => (int) ( $c['last'] ?? 0 ),
			);
		}

		$countries = array();
		foreach ( $S['countries'] as $cc => $n ) {
			$countries[] = array( 'cc' => '?' === $cc ? '' : (string) $cc, 'name' => Panel::country_name( (string) $cc ), 'sites' => (int) $n );
		}
		$geo = array();
		foreach ( $S['geo'] as $cc => $r ) {
			$geo[] = array( 'cc' => '?' === $cc ? '' : (string) $cc, 'name' => Panel::country_name( (string) $cc ) ) + $r;
		}
		$known = array_diff_key( $S['countries'], array( '?' => 0 ) );
		$panel = new Panel( $this->server, $this->config );

		return array(
			'ok'          => true,
			'days'        => $range,
			'totals'      => $S['totals'],
			'prev'        => $S['prev'],
			'series'      => $S['series'],
			'installs'    => $S['installs'],
			'downloads'   => $S['downloads'],
			'countries'   => $countries,
			'geo'         => $geo,
			'clients'     => $clients,
			'all_clients' => (int) $S['all_clients'],
			'packages'    => $names,
			'geo_db'      => $this->server->geo()->status()['ok'],
			// Paneldeki ile aynı SVG'ler (sınıflar panel CSS'iyle aynı).
			'chart_svg'   => Panel::chart( $S['series'] ),
			'map_svg'     => $panel->world_map( $known ),
			'now'         => time(),
		);
	}

	private function resources(): array {
		$R = $this->server->resources();
		unset( $R['peak'] );
		return array(
			'ok'     => true,
			'server' => $R,
			'zips'   => $this->server->zip_stats(),
			'github' => $this->server->github_rate(),
			'geo_db' => $this->server->geo()->status(),
			'intl'   => class_exists( '\Locale' ),
		);
	}

	private function settings(): array {
		list( $catalog, $stable, $gh_error ) = $this->catalogs();
		$states = $this->server->site_states();
		$token  = (string) $this->config['github_token'];

		$packages = array();
		foreach ( $catalog as $slug => $r ) {
			$packages[] = array(
				'slug'      => (string) $slug,
				'name'      => (string) $r['name'],
				'type'      => (string) $r['type'],
				'repo'      => (string) ( $r['repo'] ?? '' ),
				'private'   => empty( $r['public'] ),
				'public'    => $this->server->is_public( $r ),
				'version'   => (string) $r['version'],
				'stable'    => (string) ( $stable[ $slug ]['version'] ?? '' ),
				'published' => (string) $r['published_at'],
				'size'      => (int) $r['size'],
			);
		}

		$sites = array();
		foreach ( (array) $this->config['sites'] as $hash => $conf ) {
			$st   = $states[ $hash ] ?? array();
			$pend = is_array( $conf['pending'] ?? null ) ? $conf['pending'] : null;
			$sites[] = array(
				'hash'      => (string) $hash,
				'name'      => (string) ( $conf['name'] ?? '' ),
				'packages'  => $conf['packages'] ?? '*',
				'repos'     => array_values( (array) ( $conf['repos'] ?? array() ) ),
				'channel'   => 'beta' === ( $conf['channel'] ?? '' ) ? 'beta' : 'stable',
				'active'    => ! isset( $conf['active'] ) || (bool) $conf['active'],
				'editable'  => 'panel' === ( $conf['source'] ?? '' ),
				'created'   => (string) ( $conf['created'] ?? '' ),
				'pending'   => $pend ? ( (int) $pend['exp'] < time() ? 'expired' : 'waiting' ) : '',
				'url'       => (string) ( $st['url'] ?? '' ),
				'wp'        => (string) ( $st['wp'] ?? '' ),
				'php'       => (string) ( $st['php'] ?? '' ),
				'last_seen' => (int) ( $st['last_seen_ts'] ?? 0 ),
				'installed' => (array) ( $st['packages'] ?? array() ),
			);
		}

		$mkeys = array();
		foreach ( self::keys( $this->data_dir ) as $h => $k ) {
			$mkeys[] = array(
				'hash'    => (string) $h,
				'name'    => (string) ( $k['name'] ?? '' ),
				'hint'    => (string) ( $k['hint'] ?? '' ),
				'created' => (int) ( $k['created'] ?? 0 ),
				'last'    => (int) ( $k['last'] ?? 0 ),
				'current' => $h === $this->key['hash'],
			);
		}

		$out = array(
			'ok'       => true,
			'github'   => array(
				'connected'       => '' === $gh_error,
				'error'           => $gh_error,
				'repo_errors'     => $this->server->repo_errors(),
				'private_repos'   => $this->server->private_repos(),
				'public_repos'    => $this->server->public_repos(),
				'public_packages' => $this->server->public_packages(),
				'app_repo'        => (string) ( $this->config['app_repo'] ?? '' ),
				'fluxapp_repo'    => (string) ( $this->config['fluxapp_repo'] ?? '' ),
				'token_set'       => '' !== $token,
				'token_hint'      => '' !== $token ? substr( $token, -4 ) : '',
				'webhook_secret'  => (string) ( $this->config['webhook_secret'] ?? '' ),
				'base_url'        => $this->server->base_url(),
			),
			'packages' => $packages,
			'updater_slugs' => Panel::UPDATER_SLUGS,
			'sites'    => $sites,
			'claim_days' => Server::CLAIM_DAYS,
			'security' => array(
				'lockdown'         => ! empty( $this->config['lockdown'] ),
				'public_off'       => ! empty( $this->config['public_off'] ),
				'has_public'       => (bool) $this->server->public_repos() || (bool) $this->server->public_packages(),
				'geo_mode'         => (string) ( $this->config['geo_mode'] ?? 'off' ),
				'geo_countries'    => $this->server->geo_countries(),
				'geo_keyed_exempt' => ! empty( $this->config['geo_keyed_exempt'] ),
				'geo_auto'         => ! empty( $this->config['geo_auto'] ),
				'countries'        => $this->country_list(),
			),
			'files'    => array(
				'zip_keep'       => (int) ( $this->config['zip_keep'] ?? 5 ),
				'zip_temp_hours' => (int) ( $this->config['zip_temp_hours'] ?? 24 ),
			),
			'manager_keys' => $mkeys,
		);
		// Kategoriler ve paket bilgileri (sunucu 1.11.0+, lib/catalog-meta.php).
		if ( class_exists( __NAMESPACE__ . '\\CatalogMeta' ) ) {
			$out['categories'] = CatalogMeta::categories( $this->config );
			$meta              = array();
			foreach ( $catalog as $slug => $r ) {
				$meta[ $slug ] = CatalogMeta::package( $this->config, (string) $slug );
				$meta[ $slug ]['desc'] = ( new Previews( $this->data_dir ) )->read( (string) $slug, (string) ( $r['repo'] ?? '' ) )['desc'] ?? array();
			}
			$out['pkg_meta'] = $meta;
		}
		return $out;
	}

	/** Ülke seçici: kod => ad (seçili dilde). */
	private function country_list(): array {
		$out = array();
		foreach ( Panel::ISO as $cc ) {
			$out[ $cc ] = Panel::country_name( $cc );
		}
		asort( $out );
		return $out;
	}

	private function test_key( string $key ): array {
		$hash  = hash( 'sha256', trim( $key ) );
		$found = null;
		foreach ( (array) $this->config['sites'] as $h => $conf ) {
			if ( hash_equals( strtolower( (string) $h ), $hash ) ) {
				$found = $conf + array( 'hash' => (string) $h );
				break;
			}
		}
		if ( ! $found ) {
			return array( 'ok' => true, 'valid' => false, 'message' => __( 'Bu anahtar kayıtlı bir siteye ait değil.' ) );
		}
		list( $catalog, $stable ) = $this->catalogs();
		$off  = isset( $found['active'] ) && ! $found['active'];
		$site = array(
			'packages' => $found['packages'] ?? '*',
			'repos'    => (array) ( $found['repos'] ?? array() ),
			'channel'  => ( $found['channel'] ?? 'stable' ) === 'beta' ? 'beta' : 'stable',
			'anon'     => $off,
		);
		$visible = array();
		foreach ( ( 'beta' === $site['channel'] ? $catalog : $stable ) as $r ) {
			if ( $this->server->can_see( $site, $r ) ) {
				$visible[] = $r['name'] . ' ' . $r['version'];
			}
		}
		$states = $this->server->site_states();
		return array(
			'ok'        => true,
			'valid'     => true,
			'active'    => ! $off,
			'name'      => (string) ( $found['name'] ?? '' ),
			'hash'      => $found['hash'],
			'channel'   => $site['channel'],
			'visible'   => $visible,
			'last_seen' => (string) ( $states[ $found['hash'] ]['last_seen'] ?? '' ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Yardımcılar                                                         */
	/* ------------------------------------------------------------------ */

	/** JSON gövde (en çok 256 KB). Form gövdesi de kabul edilir. */
	private function body(): array {
		$raw = (string) file_get_contents( 'php://input', false, null, 0, 262144 );
		$d   = json_decode( $raw, true );
		if ( ! is_array( $d ) ) {
			$d = $_POST;
		}
		return is_array( $d ) ? $d : array();
	}

	private function json( array $data, int $code = 200 ): void {
		http_response_code( $code );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
	}
}
