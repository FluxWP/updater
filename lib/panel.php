<?php
/**
 * /admin: kurulum, giriş, GitHub ayarı, siteler (anahtar üret/test/sil), paketler.
 *
 * config.php düzenlemeden her şey buradan yapılır. Ayarlar data/settings.json'a yazılır.
 */

namespace FLXUpdate;

final class Panel {

	const COOKIE = 'flxu_admin';
	const TTL    = 43200; // 12 saat

	/** Siteye her zaman açık güncelleyici paketleri: Flux Setup (1.0.0'dan beri güncelleyiciyi içerir) ve eski Flux Updater. */
	const UPDATER_SLUGS = array( 'flx-setup', 'flx-updater' );

	/** @var Server */
	private $server;

	/** @var array */
	private $config;

	/** @var string */
	private $data_dir;

	/** @var string */
	private $notice = '';

	/** @var string */
	private $notice_type = 'ok';

	public function __construct( Server $server, array $config ) {
		$this->server   = $server;
		$this->config   = $config;
		$this->data_dir = rtrim( (string) $config['data_dir'], '/' );
	}

	/* ------------------------------------------------------------------ */
	/* Giriş noktası                                                       */
	/* ------------------------------------------------------------------ */

	public function handle( string $method ): void {
		header( 'Cache-Control: no-store' );
		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );

		if ( 'GET' === $method && isset( $_GET['lang'] ) ) {
			I18n::set( (string) $_GET['lang'] );
			$this->redirect();
			return;
		}
		if ( 'POST' === $method && isset( $_POST['lang'] ) ) {
			I18n::set( (string) $_POST['lang'] );
		}

		$action = 'POST' === $method ? (string) ( $_POST['action'] ?? '' ) : '';

		if ( ! $this->has_admin() ) {
			if ( 'setup' === $action ) {
				$this->do_setup();
				return;
			}
			$this->page( __( 'Kurulum' ), array( $this, 'view_setup' ) );
			return;
		}

		$user = $this->current_user();
		if ( '' === $user ) {
			if ( 'login' === $action ) {
				$this->do_login();
				return;
			}
			$this->page( __( 'Giriş' ), array( $this, 'view_login' ) );
			return;
		}

		if ( 'GET' === $method && 'repos' === ( $_GET['ajax'] ?? '' ) ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			echo json_encode( $this->server->github_repos( ! empty( $_GET['fresh'] ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			return;
		}

		if ( '' !== $action ) {
			if ( ! hash_equals( $this->csrf(), (string) ( $_POST['csrf'] ?? '' ) ) ) {
				$this->notice( __( 'Oturum süresi doldu, tekrar deneyin.' ), 'err' );
				$this->page( __( 'Panel' ), array( $this, 'view_dashboard' ) );
				return;
			}
			$this->dispatch( $action );
			return;
		}

		$msgs = array(
			'github'  => array( __( 'GitHub ayarları kaydedildi.' ), 'ok' ),
			'deleted' => array( __( 'Site silindi. O sitenin anahtarı artık çalışmaz.' ), 'ok' ),
			'updated' => array( __( 'Site güncellendi.' ), 'ok' ),
			'on'      => array( __( 'Anahtar aktif: site izinli private paketleri yeniden alır.' ), 'ok' ),
			'off'     => array( __( 'Anahtar pasif: site yalnız public paketleri görür.' ), 'ok' ),
			'noclient' => array( __( 'Bu siteye anahtar verilemedi: kayıt yok ya da site Flux Updater 1.7.0\'dan eski.' ), 'err' ),
			'flushed' => array( __( 'Paket listesi GitHub\'dan yeniden okundu.' ), 'ok' ),
			'pw'      => array( __( 'Parola değiştirildi.' ), 'ok' ),
			'webhook' => array( __( 'Webhook secret üretildi. GitHub\'a eklemeyi unutmayın.' ), 'ok' ),
			'zips'    => array( __( 'Dosya saklama ayarı kaydedildi.' ), 'ok' ),
			'log'     => array( sprintf( __( 'Erişim günlüğü temizlendi (%s).' ), self::size( (int) ( $_GET['b'] ?? 0 ) ) ), 'ok' ),
			'pruned'  => array( sprintf( '%d eski zip silindi (%s).', (int) ( $_GET['n'] ?? 0 ), self::size( (int) ( $_GET['b'] ?? 0 ) ) ), 'ok' ),
		);
		$m = (string) ( $_GET['m'] ?? '' );
		if ( isset( $msgs[ $m ] ) ) {
			$this->notice( $msgs[ $m ][0], $msgs[ $m ][1] );
		}
		$this->page( __( 'Panel' ), array( $this, 'view_dashboard' ) );
	}

	private function dispatch( string $action ): void {
		switch ( $action ) {
			case 'logout':
				$this->set_cookie( '', time() - 3600 );
				$this->redirect();
				return;

			case 'clear_log':
				$this->redirect( 'log', array( 'b' => $this->server->clear_log() ) );
				return;

			case 'save_github':
				$changes = array(
					'repo'         => $this->clean_repos( (string) ( $_POST['repo'] ?? '' ), 'repo', true ),
					'public_repos' => $this->clean_repos( (string) ( $_POST['public_repos'] ?? '' ), 'public_repos', true ),
					'app_repo'     => $this->clean_repos( (string) ( $_POST['app_repo'] ?? '' ), 'app_repo', true, 1 ),
				);
				$token   = trim( (string) ( $_POST['github_token'] ?? '' ) );
				if ( '' !== $token ) {
					$changes['github_token'] = $token;
				}
				Settings::update( $this->data_dir, $changes );
				$this->server->flush_cache();
				$this->redirect( 'github' );
				return;

			case 'gen_webhook':
				Settings::update( $this->data_dir, array( 'webhook_secret' => bin2hex( random_bytes( 24 ) ) ) );
				$this->redirect( 'webhook' );
				return;

			case 'flush':
				$this->server->flush_cache();
				$this->redirect( 'flushed' );
				return;

			case 'save_zips':
				Settings::update(
					$this->data_dir,
					array(
						'zip_keep'       => max( 1, min( 50, (int) ( $_POST['zip_keep'] ?? 5 ) ) ),
						'zip_temp_hours' => max( 1, min( 720, (int) ( $_POST['zip_temp_hours'] ?? 24 ) ) ),
					)
				);
				$this->redirect( 'zips' );
				return;

			case 'prune_zips':
				$r = $this->server->prune_zips();
				$this->redirect( 'pruned', array( 'n' => (int) $r['deleted'], 'b' => (int) $r['bytes'] ) );
				return;

			case 'add_site':
				$this->do_add_site();
				return;

			case 'update_site':
				$this->do_update_site();
				return;

			case 'issue_key':
				$this->do_issue_key();
				return;

			case 'toggle_site':
				$hash  = (string) ( $_POST['hash'] ?? '' );
				$sites = $this->panel_sites();
				if ( isset( $sites[ $hash ] ) ) {
					$on                       = ! empty( $_POST['on'] );
					$sites[ $hash ]['active'] = $on;
					Settings::update( $this->data_dir, array( 'sites' => $sites ) );
					$this->redirect( $on ? 'on' : 'off' );
					return;
				}
				$this->redirect();
				return;

			case 'delete_site':
				$hash  = (string) ( $_POST['hash'] ?? '' );
				$sites = $this->panel_sites();
				unset( $sites[ $hash ] );
				Settings::update( $this->data_dir, array( 'sites' => $sites ) );
				$this->redirect( 'deleted' );
				return;

			case 'test_key':
				$this->page( __( 'Panel' ), array( $this, 'view_dashboard' ), array( 'test_key' => trim( (string) ( $_POST['key'] ?? '' ) ) ) );
				return;

			case 'change_password':
				$p1 = (string) ( $_POST['password'] ?? '' );
				if ( strlen( $p1 ) < 10 || $p1 !== (string) ( $_POST['password2'] ?? '' ) ) {
					$this->notice( __( 'Parola en az 10 karakter olmalı ve iki alan aynı olmalı.' ), 'err' );
					$this->page( __( 'Panel' ), array( $this, 'view_dashboard' ) );
					return;
				}
				Settings::update( $this->data_dir, array( 'admin_password_hash' => password_hash( $p1, PASSWORD_DEFAULT ) ) );
				$this->config['admin_password_hash'] = Settings::load( $this->data_dir )['admin_password_hash'];
				$this->login_as( $this->admin_user() );
				$this->redirect( 'pw' );
				return;
		}
		$this->redirect();
	}

	/** Üst menüdeki dil seçici (EN | TR). */
	private function lang_switch(): string {
		$out = '<span class="langs">';
		foreach ( I18n::LANGS as $code => $name ) {
			$cur  = I18n::lang() === $code;
			$out .= '<a href="' . self::e( $this->server->base_url() . '/admin?lang=' . $code ) . '" title="' . self::e( $name ) . '"' . ( $cur ? ' aria-current="true"' : '' ) . '>' . strtoupper( $code ) . '</a>';
		}
		return $out . '</span>';
	}

	/** Giriş ve kurulum formundaki dil alanı; seçilen dil girişte kaydedilir. */
	private function lang_field(): string {
		$out = '<label>' . self::e( __( 'Panel dili' ) ) . '</label><select name="lang" onchange="location.href=\'?lang=\'+this.value">';
		foreach ( I18n::LANGS as $code => $name ) {
			$out .= '<option value="' . $code . '"' . ( I18n::lang() === $code ? ' selected' : '' ) . '>' . self::e( $name ) . '</option>';
		}
		return $out . '</select>';
	}

	/* ------------------------------------------------------------------ */
	/* Kurulum ve giriş                                                    */
	/* ------------------------------------------------------------------ */

	private function has_admin(): bool {
		return '' !== (string) $this->config['admin_password_hash'] || strlen( (string) $this->config['admin_password'] ) >= 10;
	}

	private function admin_user(): string {
		$u = (string) ( $this->config['admin_user'] ?? '' );
		return '' !== $u ? $u : 'admin';
	}

	private function do_setup(): void {
		$user = trim( (string) ( $_POST['user'] ?? '' ) );
		$p1   = (string) ( $_POST['password'] ?? '' );
		$p2   = (string) ( $_POST['password2'] ?? '' );
		if ( ! preg_match( '/^[A-Za-z0-9._@-]{3,40}$/', $user ) || strlen( $p1 ) < 10 || $p1 !== $p2 ) {
			$this->notice( __( 'Kullanıcı adı 3–40 karakter (harf, rakam, . _ - @), parola en az 10 karakter ve iki alan aynı olmalı.' ), 'err' );
			$this->page( __( 'Kurulum' ), array( $this, 'view_setup' ) );
			return;
		}
		$data = Settings::update(
			$this->data_dir,
			array(
				'admin_user'          => $user,
				'admin_password_hash' => password_hash( $p1, PASSWORD_DEFAULT ),
				'secret'              => bin2hex( random_bytes( 32 ) ),
			)
		);
		if ( ! is_file( Settings::path( $this->data_dir ) ) ) {
			$this->notice( __( 'data/ klasörüne yazılamadı. Klasörün yazılabilir olduğundan emin olun.' ), 'err' );
			$this->page( __( 'Kurulum' ), array( $this, 'view_setup' ) );
			return;
		}
		$this->config = array_merge( $this->config, $data );
		$this->login_as( $user );
		$this->redirect();
	}

	private function do_login(): void {
		$user = (string) ( $_POST['user'] ?? '' );
		$pass = (string) ( $_POST['password'] ?? '' );
		$hash = (string) $this->config['admin_password_hash'];
		$ok   = '' !== $hash ? password_verify( $pass, $hash ) : hash_equals( (string) $this->config['admin_password'], $pass );
		if ( ! $ok || ! hash_equals( $this->admin_user(), $user ) ) {
			sleep( 1 );
			$this->notice( __( 'Kullanıcı adı veya parola hatalı.' ), 'err' );
			$this->page( __( 'Giriş' ), array( $this, 'view_login' ) );
			return;
		}
		$this->login_as( $user );
		$this->redirect();
	}

	private function secret(): string {
		$s = (string) ( $this->config['secret'] ?? '' );
		if ( '' === $s ) {
			$s = bin2hex( random_bytes( 32 ) );
			Settings::update( $this->data_dir, array( 'secret' => $s ) );
			$this->config['secret'] = $s;
		}
		return $s;
	}

	/** İmza parolaya bağlı: parola değişince eski oturumlar düşer. */
	private function sign( string $payload ): string {
		$pw = (string) $this->config['admin_password_hash'] . (string) $this->config['admin_password'];
		return hash_hmac( 'sha256', $payload . '|' . substr( hash( 'sha256', $pw ), 0, 16 ), $this->secret() );
	}

	private function login_as( string $user ): void {
		$exp = time() + self::TTL;
		$val = base64_encode( $user ) . '.' . $exp;
		$this->set_cookie( $val . '.' . $this->sign( $val ), $exp );
		$_COOKIE[ self::COOKIE ] = $val . '.' . $this->sign( $val );
	}

	private function current_user(): string {
		$c     = (string) ( $_COOKIE[ self::COOKIE ] ?? '' );
		$parts = explode( '.', $c );
		if ( 3 !== count( $parts ) ) {
			return '';
		}
		list( $u, $exp, $sig ) = $parts;
		if ( (int) $exp < time() || ! hash_equals( $this->sign( $u . '.' . $exp ), $sig ) ) {
			return '';
		}
		$user = (string) base64_decode( $u );
		return hash_equals( $this->admin_user(), $user ) ? $user : '';
	}

	private function set_cookie( string $value, int $exp ): void {
		$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) || ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) === 'https';
		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $exp,
				'path'     => '/admin',
				'secure'   => $https,
				'httponly' => true,
				'samesite' => 'Strict',
			)
		);
	}

	private function csrf(): string {
		return hash_hmac( 'sha256', 'csrf|' . (string) ( $_COOKIE[ self::COOKIE ] ?? '' ), $this->secret() );
	}

	/* ------------------------------------------------------------------ */
	/* Siteler                                                             */
	/* ------------------------------------------------------------------ */

	private function panel_sites(): array {
		$s = Settings::load( $this->data_dir );
		return is_array( $s['sites'] ?? null ) ? $s['sites'] : array();
	}

	/** Seçilen depolar (bütünüyle, sonradan eklenen paketler dahil). "Tümü" seçiliyse boş. */
	private function repos_from_post(): array {
		if ( 'all' === ( $_POST['scope'] ?? 'all' ) ) {
			return array();
		}
		$private = array_map( 'strtolower', $this->server->private_repos() );
		$out     = array();
		foreach ( (array) ( $_POST['repos'] ?? array() ) as $r ) {
			if ( is_string( $r ) && in_array( strtolower( $r ), $private, true ) ) {
				$out[] = $r;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private function packages_from_post() {
		if ( 'all' === ( $_POST['scope'] ?? 'all' ) ) {
			return '*';
		}
		$out = array();
		foreach ( (array) ( $_POST['packages'] ?? array() ) as $slug ) {
			if ( is_string( $slug ) && preg_match( '/^[a-z0-9-]{1,64}$/', $slug ) ) {
				$out[] = $slug;
			}
		}
		// Güncelleyici paketi (Flux Setup; eski adı Flux Updater) her seçime eklenir.
		foreach ( self::UPDATER_SLUGS as $u ) {
			if ( ! in_array( $u, $out, true ) ) {
				$out[] = $u;
			}
		}
		sort( $out );
		return $out;
	}

	private function do_add_site(): void {
		$name = trim( (string) ( $_POST['name'] ?? '' ) );
		if ( '' === $name ) {
			$this->notice( __( 'Site adı girin.' ), 'err' );
			$this->page( __( 'Panel' ), array( $this, 'view_dashboard' ) );
			return;
		}
		$key   = 'flxu_' . bin2hex( random_bytes( 24 ) );
		$hash  = hash( 'sha256', $key );
		$sites = $this->panel_sites();
		$sites[ $hash ] = array(
			'name'     => mb_substr( $name, 0, 80 ),
			'packages' => $this->packages_from_post(),
			'repos'    => $this->repos_from_post(),
			'channel'  => 'beta' === ( $_POST['channel'] ?? '' ) ? 'beta' : 'stable',
			'active'   => true,
			'created'  => gmdate( 'Y-m-d H:i' ) . ' UTC',
		);
		Settings::update( $this->data_dir, array( 'sites' => $sites ) );
		$this->config['sites'][ $hash ] = $sites[ $hash ] + array( 'source' => 'panel' );
		$this->page( __( 'Panel' ), array( $this, 'view_dashboard' ), array( 'new_key' => $key, 'new_name' => $sites[ $hash ]['name'] ) );
	}

	private function do_update_site(): void {
		$hash  = (string) ( $_POST['hash'] ?? '' );
		$sites = $this->panel_sites();
		if ( isset( $sites[ $hash ] ) ) {
			$name = trim( (string) ( $_POST['name'] ?? '' ) );
			if ( '' !== $name ) {
				$sites[ $hash ]['name'] = mb_substr( $name, 0, 80 );
			}
			$sites[ $hash ]['packages'] = $this->packages_from_post();
			$sites[ $hash ]['repos']    = $this->repos_from_post();
			$sites[ $hash ]['channel']  = 'beta' === ( $_POST['channel'] ?? '' ) ? 'beta' : 'stable';
			Settings::update( $this->data_dir, array( 'sites' => $sites ) );
		}
		$this->redirect( 'updated' );
	}

	/**
	 * "Bağlanan siteler"den anahtar ver: anahtar kurulum kimliğine bağlanır, site bir sonraki
	 * kontrolde kendiliğinden alır (Flux Updater 1.7.0+). Varsayılan izin boş; yönetici seçer.
	 */
	private function do_issue_key(): void {
		$id = (string) ( $_POST['client'] ?? '' );
		$c  = preg_match( '/^u[a-f0-9]{15}$/', $id ) ? $this->server->stats()->client( $id ) : null;
		$ih = (string) ( $c['ih'] ?? '' );
		if ( ! $c || ! preg_match( '/^[a-f0-9]{64}$/', $ih ) ) {
			$this->redirect( 'noclient' );
			return;
		}
		$sites = $this->panel_sites();
		foreach ( $sites as $h => $conf ) {
			// Aynı kuruluma verilmiş, henüz alınmamış eski anahtar yenisiyle değişir.
			if ( ( $conf['pending']['ih'] ?? '' ) === $ih ) {
				unset( $sites[ $h ] );
			}
		}
		$nonce = bin2hex( random_bytes( 8 ) );
		$key   = Server::derive_key( $this->secret(), $ih, $nonce );
		$hash  = hash( 'sha256', $key );
		$url   = (string) ( $c['url'] ?? '' );
		$name  = (string) parse_url( $url, PHP_URL_HOST );
		$name  = preg_replace( '/^www\./', '', $name ) . rtrim( (string) parse_url( $url, PHP_URL_PATH ), '/' );
		$sites[ $hash ] = array(
			'name'     => mb_substr( '' !== $name ? $name : 'Site', 0, 80 ),
			'packages' => array(),
			'repos'    => array(),
			'channel'  => 'stable',
			'active'   => true,
			'created'  => gmdate( 'Y-m-d H:i' ) . ' UTC',
			'pending'  => array(
				'ih'  => $ih,
				'n'   => $nonce,
				'exp' => time() + Server::CLAIM_DAYS * DAY_SECONDS,
			),
		);
		Settings::update( $this->data_dir, array( 'sites' => $sites ) );
		$this->config['sites'][ $hash ] = $sites[ $hash ] + array( 'source' => 'panel' );
		$this->page( __( 'Panel' ), array( $this, 'view_dashboard' ), array( 'new_key' => $key, 'new_name' => $sites[ $hash ]['name'], 'issued' => $hash ) );
	}

	/** Anahtarlı site satırının bağlantı kimliği. */
	private static function key_anchor( string $hash ): string {
		return 'key-' . substr( $hash, 0, 15 );
	}

	/** İzin özeti: "Tüm private paketler" ya da depolar + paketler. */
	private function scope_label( array $conf, array $catalog ): string {
		if ( '*' === ( $conf['packages'] ?? '*' ) ) {
			return self::e( __( 'Tüm private paketler' ) );
		}
		$parts = array();
		foreach ( (array) ( $conf['repos'] ?? array() ) as $r ) {
			$parts[] = '<b>' . self::e( $r ) . '</b> ' . self::e( __( '(tümü)' ) );
		}
		$in = array_map( 'strtolower', (array) ( $conf['repos'] ?? array() ) );
		foreach ( (array) $conf['packages'] as $slug ) {
			$r = $catalog[ $slug ] ?? null;
			if ( ! $r && in_array( $slug, self::UPDATER_SLUGS, true ) ) {
				continue; // Her seçime kendiliğinden eklenir; katalogda yoksa gösterme.
			}
			if ( $r && in_array( strtolower( (string) ( $r['repo'] ?? '' ) ), $in, true ) ) {
				continue;
			}
			$parts[] = self::e( $r['name'] ?? $slug );
		}
		return $parts ? implode( ', ', $parts ) : '<span class="muted">' . self::e( __( 'Yalnız public paketler' ) ) . '</span>';
	}

	/* ------------------------------------------------------------------ */
	/* Görünümler                                                          */
	/* ------------------------------------------------------------------ */

	private static function e( $s ): string {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}

	private function notice( string $msg, string $type = 'ok' ): void {
		$this->notice      = $msg;
		$this->notice_type = $type;
	}

	private function redirect( string $msg = '', array $extra = array() ): void {
		$q = $msg ? '?' . http_build_query( array( 'm' => $msg ) + $extra ) : '';
		header( 'Location: ' . $this->server->base_url() . '/admin' . $q, true, 303 );
	}

	private static function size( int $bytes ): string {
		$u = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$i = 0;
		$n = (float) $bytes;
		while ( $n >= 1024 && $i < 4 ) {
			$n /= 1024;
			++$i;
		}
		return ( $i ? I18n::num( $n, 1 ) : (string) (int) $n ) . ' ' . $u[ $i ];
	}

	private function hidden( string $action ): string {
		return '<input type="hidden" name="action" value="' . self::e( $action ) . '"><input type="hidden" name="csrf" value="' . self::e( $this->csrf() ) . '">';
	}

	private function page( string $title, callable $view, array $args = array() ): void {
		header( 'Content-Type: text/html; charset=utf-8' );
		$e = array( __CLASS__, 'e' );
		?>
<!doctype html>
<html lang="<?php echo $e( I18n::lang() ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo $e( $title ); ?> · Flux Update Server</title>
<style>
:root{--bg:#f4f7f5;--card:#fff;--line:#e2e8e4;--text:#1d2a22;--muted:#66756b;--green:#247833;--green-bg:#e6f4ea;--red:#c0392b;--red-bg:#fcecea;--amber:#9a6700;--amber-bg:#fff6dd}
*{box-sizing:border-box}body{margin:0;font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--text)}
.wrap{max-width:1080px;margin:0 auto;padding:20px 16px 60px}
header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px}
h1{font-size:20px;margin:0}h1 small{font-weight:400;color:var(--muted);font-size:13px;margin-left:6px}
h2{font-size:16px;margin:0 0 12px}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:18px;margin-bottom:16px}
.grid{display:grid;gap:16px;grid-template-columns:1fr 1fr}@media(max-width:800px){.grid{grid-template-columns:1fr}}
label{display:block;font-weight:600;margin:10px 0 4px}
input[type=text],input[type=password],input[type=number],select{width:100%;padding:8px 10px;border:1px solid #cfd8d2;border-radius:6px;font:inherit;background:#fff}
.btn{display:inline-block;padding:8px 14px;border-radius:6px;border:1px solid var(--green);background:var(--green);color:#fff;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}
.btn.sec{background:#fff;color:var(--green)}.btn.danger{background:#fff;color:var(--red);border-color:#e3b3ad}.btn.sm{padding:4px 10px;font-size:13px}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.notice{padding:10px 14px;border-radius:8px;margin-bottom:16px}.notice.ok{background:var(--green-bg);color:#14531f}.notice.err{background:var(--red-bg);color:#7d1f14}
.pill{display:inline-flex;align-items:center;gap:6px;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600}
.pill.ok{background:var(--green-bg);color:var(--green)}.pill.err{background:var(--red-bg);color:var(--red)}.pill.warn{background:var(--amber-bg);color:var(--amber)}.pill.muted{background:#eef1ef;color:var(--muted)}
.pill::before{content:"";width:8px;height:8px;border-radius:50%;background:currentColor}
table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:8px 6px;border-bottom:1px solid var(--line);vertical-align:top}th{font-size:12px;color:var(--muted);font-weight:600}
.muted{color:var(--muted)}.small{font-size:12px}code,pre{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px}
pre{background:#f6f8f7;border:1px solid var(--line);border-radius:6px;padding:10px;white-space:pre-wrap;word-break:break-all;margin:6px 0}
.key{background:#fffbe8;border-color:#f0dc9a}
form.inline{display:inline-block;margin:0}form.give{display:block;margin-top:6px}
.repo-grp{border:1px solid var(--line,#e3e7e4);border-radius:10px;padding:8px 10px;margin:8px 0}.repo-grp .repo-all{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0;font-weight:400}
.repo-grp.whole .checks{opacity:.45;pointer-events:none}tr.off td{opacity:.7}
.checks{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:4px 12px;margin-top:6px}.checks label{font-weight:400;margin:0;display:flex;gap:6px;align-items:center}
details summary{cursor:pointer;color:var(--green);font-weight:600}
.table-wrap{overflow-x:auto}
.wrap{max-width:1180px}
.langs{display:inline-flex;gap:2px;background:#e8eeea;border-radius:8px;padding:2px}.langs a{padding:3px 9px;border-radius:6px;color:var(--muted);text-decoration:none;font-size:12px;font-weight:600}.langs a[aria-current]{background:#fff;color:var(--text)}
.tabs-nav{display:flex;gap:4px;flex-wrap:wrap;margin:-4px 0 14px}.tabs-nav a{padding:5px 12px;border-radius:999px;color:var(--muted);text-decoration:none;font-size:13px;border:1px solid transparent}.tabs-nav a:hover{border-color:var(--line);background:#fff;color:var(--text)}
h2.sec{font-size:18px;margin:8px 0 12px}.sec-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;scroll-margin-top:12px}
.seg{display:inline-flex;gap:2px;background:#e8eeea;border-radius:8px;padding:2px}.seg a{padding:4px 12px;border-radius:6px;color:var(--muted);text-decoration:none;font-size:13px}.seg a[aria-selected=true]{background:#fff;color:var(--text);box-shadow:0 1px 2px rgba(0,0,0,.06)}
.kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-bottom:16px}@media(max-width:980px){.kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:520px){.kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
.kpi{--k:#1f8a5b;--kbg:#e3f4ea;position:relative;overflow:hidden;background:var(--card);border:1px solid var(--line);border-top:3px solid var(--k);border-radius:12px;padding:12px 14px}
.kpi::after{content:"";position:absolute;right:-24px;top:-24px;width:80px;height:80px;border-radius:50%;background:var(--kbg);opacity:.7;pointer-events:none}
.kpi--green{--k:#1f8a5b;--kbg:#e3f4ea}.kpi--blue{--k:#2563eb;--kbg:#e6eefc}.kpi--violet{--k:#7c3aed;--kbg:#efe8fd}.kpi--amber{--k:#d97706;--kbg:#fdf1e1}.kpi--teal{--k:#0d9488;--kbg:#e0f4f1}.kpi--rose{--k:#e11d48;--kbg:#fde7ec}
.kpi__top{display:flex;align-items:center;gap:8px;position:relative;z-index:1}.kpi__ico{display:inline-flex;width:28px;height:28px;border-radius:8px;align-items:center;justify-content:center;background:var(--kbg);color:var(--k)}.kpi__ico svg{width:16px;height:16px}
.kpi__label{color:var(--muted);font-size:13px}.kpi__row{display:flex;align-items:baseline;gap:6px;margin-top:8px;position:relative;z-index:1}.kpi__value{font-size:24px;font-weight:700;line-height:1.1}
.kpi__sub{font-size:12px;color:var(--muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.delta{font-size:12px;font-weight:600}.delta.up{color:#137a43}.delta.down{color:#b32d2e}
.legend{display:flex;gap:14px;font-size:12px;color:var(--muted)}.legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:5px;vertical-align:-1px}
.legend i.c,.chart .c{background:#b9d8c1;fill:#b9d8c1}.legend i.s,.chart .s{background:var(--green);fill:var(--green)}.legend i.dl{background:#d97706;border-radius:50%}.chart .dl{fill:#d97706;stroke:#fff;stroke-width:1.5}
.chart svg{width:100%;height:auto;display:block}.chart .grid line{stroke:var(--line);stroke-dasharray:2 3}.chart text{fill:var(--muted);font-size:11px}.chart .hit{fill:transparent}.chart .hit:hover{fill:rgba(0,0,0,.04)}
.tip-host{position:relative}.tip{position:absolute;display:none;pointer-events:none;background:var(--text);color:#fff;padding:6px 9px;border-radius:8px;font-size:12px;line-height:1.4;transform:translate(-50%,-100%);white-space:nowrap;z-index:5}
table.bars td{padding:0 6px;height:34px;vertical-align:middle}table.bars .n,th.n,td.n{text-align:right;white-space:nowrap}
.lbl{position:relative}.lbl .bar{position:absolute;left:0;top:5px;bottom:5px;background:#e3f1e7;border-radius:6px;z-index:0}.lbl .txt{position:relative;z-index:1;padding-left:8px}.lbl small{color:var(--muted);margin-left:4px}
.cc{display:inline-block;font-size:10px;font-weight:700;background:#eef1ef;color:var(--muted);border-radius:4px;padding:1px 4px;margin-right:6px;letter-spacing:.3px}
.empty{color:var(--muted);text-align:center;padding:18px 6px}
.map svg{width:100%;height:auto;display:block}.map path{fill:#e5ebe7;stroke:#fff;stroke-width:.4}.map path:hover{opacity:.75}
.map .l1,.map-legend .l1{fill:#cfe6d5;background:#cfe6d5}.map .l2,.map-legend .l2{fill:#9fcfad;background:#9fcfad}.map .l3,.map-legend .l3{fill:#5fae75;background:#5fae75}.map .l4,.map-legend .l4{fill:#247833;background:#247833}.map .l5,.map-legend .l5{fill:#14531f;background:#14531f}
.map-legend{display:flex;align-items:center;gap:3px;font-size:11px;color:var(--muted);margin:6px 0 8px}.map-legend i{width:22px;height:8px;border-radius:2px}.map-legend span{margin:0 4px}
.grid3{display:grid;gap:16px;grid-template-columns:repeat(3,minmax(0,1fr))}@media(max-width:900px){.grid3{grid-template-columns:1fr}}
.res-line{display:flex;justify-content:space-between;gap:8px;margin:10px 0 6px;font-size:13px}.res-line span{color:var(--muted)}
.meter{height:8px;background:#eef1ef;border-radius:99px;overflow:hidden}.meter i{display:block;height:100%;border-radius:99px;background:var(--green)}.meter.warn i{background:#d97706}.meter.err i{background:var(--red)}
.stack{display:flex;height:8px;background:#eef1ef;border-radius:99px;overflow:hidden;gap:1px}.stack i{display:block;height:100%}
table.mini td{padding:5px 2px;font-size:13px}.dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px}
.chips{position:relative;display:flex;flex-wrap:wrap;gap:6px;align-items:center;min-height:40px;padding:5px 6px;border:1px solid #cfd8d2;border-radius:6px;background:#fff;cursor:text}
.chips:focus-within{border-color:var(--green);box-shadow:0 0 0 2px #e6f4ea}
.chip{display:inline-flex;align-items:center;gap:4px;background:var(--green-bg);color:#14531f;border-radius:999px;padding:3px 4px 3px 10px;font-size:13px;font-weight:600}
.chip button{border:0;background:transparent;color:inherit;width:20px;height:20px;border-radius:50%;cursor:pointer;font-size:15px;line-height:1;padding:0}.chip button:hover{background:#c9e7d1}
.chip-in{flex:1;min-width:140px;border:0!important;outline:0;padding:4px!important;font:inherit;background:transparent}
.chip-dd{position:absolute;left:-1px;right:-1px;top:calc(100% + 4px);background:#fff;border:1px solid var(--line);border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,.12);max-height:260px;overflow:auto;z-index:20}
.chip-dd div{padding:7px 10px;cursor:pointer;display:flex;justify-content:space-between;gap:8px}.chip-dd div.on,.chip-dd div:hover{background:var(--green-bg)}.chip-dd small{color:var(--muted)}.chip-dd .lock{font-size:11px;color:var(--amber);font-weight:600}.chip-dd .note{cursor:default;color:var(--muted);font-size:12px}.chip-dd .note:hover{background:none}
</style>
</head>
<body><div class="wrap">
<header>
	<h1>Flux Update Server <small><?php echo $e( VERSION ); ?></small></h1>
	<div class="row">
	<?php echo $this->lang_switch(); ?>
	<?php if ( '' !== $this->current_user() ) : ?>
		<form method="post"><?php echo $this->hidden( 'logout' ); ?><button class="btn sec sm"><?php echo __( 'Çıkış' ); ?></button></form>
	<?php endif; ?>
	</div>
</header>
<?php if ( $this->notice ) : ?>
	<div class="notice <?php echo $e( $this->notice_type ); ?>"><?php echo $e( $this->notice ); ?></div>
<?php endif; ?>
<?php call_user_func( $view, $args ); ?>
</div>
<script>
var L = <?php echo json_encode( array(
	'uniq'    => __( 'Tekil site' ),
	'checks'  => __( 'Kontrol' ),
	'dl'      => __( 'İndirme' ),
	'site'    => __( 'Site' ),
	'fail'    => __( 'Liste alınamadı' ),
	'pick'    => __( 'Depo seçin' ),
	'add'     => __( 'Depo ekle…' ),
	'remove'  => __( '%s kaldır' ),
	'repos'   => __( '%d depo · ' ),
	'enter'   => __( 'Enter ile ekleyin · ' ),
	'nomatch' => __( 'Eşleşen depo yok · ' ),
	'refresh' => __( 'Listeyi yenile' ),
), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT ); ?>;
(function () {
	// Grafik ve harita ipucu.
	document.querySelectorAll('.tip-host').forEach(function (host) {
		var tip = host.querySelector('.tip');
		host.addEventListener('mousemove', function (ev) {
			var t = ev.target.getAttribute && ev.target.getAttribute('data-t');
			if (!t) { tip.style.display = 'none'; return; }
			var p = t.split('|'), r = host.getBoundingClientRect();
			tip.innerHTML = '';
			var b = document.createElement('b'); b.textContent = p[0]; tip.appendChild(b);
			var lines = p.length > 2 ? [L.uniq + ': ' + p[1], L.checks + ': ' + p[2], L.dl + ': ' + p[3]] : [L.site + ': ' + p[1]];
			lines.forEach(function (l) { tip.appendChild(document.createElement('br')); tip.appendChild(document.createTextNode(l)); });
			tip.style.left = (ev.clientX - r.left) + 'px';
			tip.style.top = (ev.clientY - r.top - 10) + 'px';
			tip.style.display = 'block';
		});
		host.addEventListener('mouseleave', function () { tip.style.display = 'none'; });
	});

	// Depo seçici.
	var repos = null;
	function load(fresh) {
		if (!repos || fresh) {
			repos = fetch(location.pathname + '?ajax=repos' + (fresh ? '&fresh=1' : ''), { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.catch(function () { return { repos: [], error: L.fail }; });
		}
		return repos;
	}
	var valid = /^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/;
	document.querySelectorAll('.chips').forEach(function (box) {
		var hidden = box.querySelector('input[type=hidden]'), input = box.querySelector('.chip-in'), dd = box.querySelector('.chip-dd');
		var mode = box.getAttribute('data-mode'), single = box.hasAttribute('data-single'), active = -1;
		function values() { return Array.prototype.map.call(box.querySelectorAll('.chip'), function (c) { return c.getAttribute('data-v'); }); }
		function sync() { hidden.value = values().join(', '); input.placeholder = single && values().length ? '' : (single ? L.pick : L.add); }
		function add(v) {
			v = v.trim().replace(/^https?:\/\/github\.com\//, '').replace(/\/+$/, '');
			if (!valid.test(v)) return false;
			if (values().some(function (x) { return x.toLowerCase() === v.toLowerCase(); })) return true;
			if (single) box.querySelectorAll('.chip').forEach(function (c) { c.remove(); });
			var c = document.createElement('span'); c.className = 'chip'; c.setAttribute('data-v', v); c.textContent = v;
			var x = document.createElement('button'); x.type = 'button'; x.textContent = '×'; x.setAttribute('aria-label', L.remove.replace('%s', v));
			c.appendChild(x); box.insertBefore(c, input); sync(); return true;
		}
		box.addEventListener('click', function (ev) {
			if (ev.target.tagName === 'BUTTON' && ev.target.parentNode.classList.contains('chip')) { ev.target.parentNode.remove(); sync(); render(); return; }
			input.focus();
		});
		function render() {
			load(false).then(function (data) {
				if (document.activeElement !== input) return;
				var q = input.value.trim().toLowerCase(), have = values().map(function (v) { return v.toLowerCase(); });
				var list = (data.repos || []).filter(function (r) {
					if (mode === 'public' && r.private) return false;
					if (have.indexOf(r.name.toLowerCase()) >= 0) return false;
					return !q || r.name.toLowerCase().indexOf(q) >= 0 || (r.desc || '').toLowerCase().indexOf(q) >= 0;
				}).slice(0, 50);
				dd.innerHTML = ''; active = -1;
				list.forEach(function (r) {
					var d = document.createElement('div'); d.setAttribute('data-v', r.name);
					var l = document.createElement('span'); l.textContent = r.name;
					if (r.desc) { var sm = document.createElement('small'); sm.textContent = ' ' + r.desc; l.appendChild(sm); }
					d.appendChild(l);
					var k = document.createElement('span'); k.className = 'lock'; k.textContent = r.private ? 'private' : ''; d.appendChild(k);
					d.addEventListener('mousedown', function (ev) { ev.preventDefault(); add(r.name); input.value = ''; render(); });
					dd.appendChild(d);
				});
				var note = document.createElement('div'); note.className = 'note';
				note.textContent = data.error ? ('GitHub: ' + data.error) : (list.length ? L.repos.replace('%d', data.repos.length) : (valid.test(input.value.trim()) ? L.enter : L.nomatch));
				var re = document.createElement('a'); re.href = '#'; re.textContent = L.refresh;
				re.addEventListener('mousedown', function (ev) { ev.preventDefault(); load(true); render(); });
				note.appendChild(re); dd.appendChild(note);
				dd.hidden = false;
			});
		}
		input.addEventListener('focus', render);
		input.addEventListener('input', render);
		input.addEventListener('blur', function () { setTimeout(function () { dd.hidden = true; }, 120); });
		input.addEventListener('keydown', function (ev) {
			var items = dd.querySelectorAll('div[data-v]');
			if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
				ev.preventDefault();
				active = Math.max(0, Math.min(items.length - 1, active + (ev.key === 'ArrowDown' ? 1 : -1)));
				items.forEach(function (it, i) { it.classList.toggle('on', i === active); });
			} else if (ev.key === 'Enter' || ev.key === ',') {
				ev.preventDefault();
				var v = active >= 0 && items[active] ? items[active].getAttribute('data-v') : input.value;
				if (add(v)) { input.value = ''; render(); }
			} else if (ev.key === 'Backspace' && !input.value) {
				var chips = box.querySelectorAll('.chip'); if (chips.length) { chips[chips.length - 1].remove(); sync(); render(); }
			} else if (ev.key === 'Escape') { dd.hidden = true; }
		});
		sync();
	});
})();
</script>
</body></html>
		<?php
	}

	public function view_setup( array $args ): void {
		?>
		<div class="card" style="max-width:460px">
			<h2><?php echo __( 'İlk kurulum' ); ?></h2>
			<p class="muted"><?php echo __( 'Bu panelin yönetici hesabını oluşturun. Kurulum bir kez yapılır; sonrasında bu sayfa giriş ekranına döner.' ); ?></p>
			<form method="post" autocomplete="off">
				<input type="hidden" name="action" value="setup">
				<label><?php echo __( 'Kullanıcı adı' ); ?></label><input type="text" name="user" value="admin" required>
				<label><?php echo __( 'Parola (en az 10 karakter)' ); ?></label><input type="password" name="password" required minlength="10">
				<label><?php echo __( 'Parola tekrar' ); ?></label><input type="password" name="password2" required minlength="10">
				<?php echo $this->lang_field(); ?>
				<p><button class="btn"><?php echo __( 'Kurulumu tamamla' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	public function view_login( array $args ): void {
		?>
		<div class="card" style="max-width:400px">
			<h2><?php echo __( 'Giriş' ); ?></h2>
			<form method="post">
				<input type="hidden" name="action" value="login">
				<label><?php echo __( 'Kullanıcı adı' ); ?></label><input type="text" name="user" required autocomplete="username">
				<label><?php echo __( 'Parola' ); ?></label><input type="password" name="password" required autocomplete="current-password">
				<?php echo $this->lang_field(); ?>
				<p><button class="btn"><?php echo __( 'Giriş yap' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	public function view_dashboard( array $args ): void {
		$e        = array( __CLASS__, 'e' );
		$token    = (string) $this->config['github_token'];
		$gh_error = '';
		$catalog  = array();
		$stable   = array();
		$has_pub  = (bool) $this->server->public_repos();
		if ( '' === $token && ! $has_pub ) {
			$gh_error = __( 'GitHub token girilmemiş.' );
		} else {
			try {
				$catalog = $this->server->catalog( 'beta' );
				$stable  = $this->server->catalog( 'stable' );
			} catch ( \Throwable $ex ) {
				$gh_error = $this->server->test_github();
				$gh_error = $gh_error ? __( 'GitHub\'a erişilemedi: ' ) . $gh_error : __( 'GitHub release listesi okunamadı.' );
			}
		}
		$states = $this->server->site_states();
		$sites  = (array) $this->config['sites'];

		// Yeni üretilen anahtar: yalnız bu yanıtta görünür.
		if ( ! empty( $args['new_key'] ) ) {
			$key = $args['new_key'];
			?>
			<div class="card" style="border-color:#e6cf7a">
				<h2><?php echo __( 'Yeni site anahtarı:' ); ?> <?php echo $e( $args['new_name'] ); ?></h2>
				<?php if ( ! empty( $args['issued'] ) ) : ?>
				<p><?php echo $e( _f( 'Site bu anahtarı bir sonraki güncelleme kontrolünde kendiliğinden alır (%d gün içinde; hemen almak için sitede Ayarlar › Flux Updater › "Bağlantıyı test et"). Elle girmeniz gerekmez.', Server::CLAIM_DAYS ) ); ?></p>
				<p><b><?php echo __( 'Şimdi erişimini seçin:' ); ?></b> <a href="#<?php echo $e( self::key_anchor( (string) $args['issued'] ) ); ?>"><?php echo __( 'Anahtarlı siteler' ); ?> › <?php echo $e( $args['new_name'] ); ?></a>. <?php echo __( 'Seçene kadar site yalnız public paketleri görür.' ); ?></p>
				<details><summary class="small"><?php echo __( 'Anahtarı elle girmek için göster' ); ?></summary>
				<?php endif; ?>
				<p><?php echo __( '<b>Bu anahtar bir daha gösterilmez.</b> Şimdi kopyalayın ve sitenin <b>WordPress › Ayarlar › Flux Updater</b> ekranında <i>Site anahtarı</i> alanına yapıştırın. Sunucu adresi:' ); ?></p>
				<pre><?php echo $e( $this->server->base_url() ); ?></pre>
				<p><?php echo __( 'Site anahtarı:' ); ?></p>
				<pre class="key" id="newkey"><?php echo $e( $key ); ?></pre>
				<div class="row">
					<button class="btn sec sm" type="button" onclick="navigator.clipboard.writeText(document.getElementById('newkey').textContent);this.textContent=<?php echo $e( json_encode( __( 'Kopyalandı' ), JSON_UNESCAPED_UNICODE ) ); ?>"><?php echo __( 'Anahtarı kopyala' ); ?></button>
					<form method="post"><?php echo $this->hidden( 'test_key' ); ?><input type="hidden" name="key" value="<?php echo $e( $key ); ?>"><button class="btn sec sm"><?php echo __( 'Bu anahtarı test et' ); ?></button></form>
				</div>
				<p class="muted small"><?php echo __( 'Sunucu artık bu anahtarın yalnız özetini (sha256) saklar. Kaybederseniz siteyi silip yeni anahtar üretin.' ); ?></p>
				<?php if ( ! empty( $args['issued'] ) ) : ?></details><?php endif; ?>
			</div>
			<?php
		}

		if ( isset( $args['test_key'] ) ) {
			$this->view_key_test( (string) $args['test_key'], $catalog, $stable, $states );
		}
		$this->view_usage( $catalog );
		?>
		<h2 class="sec" id="settings"><?php echo __( 'Ayarlar' ); ?></h2>
		<div class="grid">
			<div class="card">
				<h2>GitHub</h2>
				<p>
					<?php if ( $gh_error ) : ?>
						<span class="pill err"><?php echo __( 'Bağlı değil' ); ?></span> <span class="small"><?php echo $e( $gh_error ); ?></span>
					<?php else : ?>
						<?php
						$n_pub = count( array_filter( $catalog, static function ( $r ) {
							return ! empty( $r['public'] );
						} ) );
						?>
						<span class="pill ok"><?php echo __( 'Bağlı' ); ?></span> <span class="small"><?php echo $e( _f( '%1$d paket okundu (%2$d private, %3$d public)', count( $catalog ), count( $catalog ) - $n_pub, $n_pub ) ); ?></span>
					<?php endif; ?>
				</p>
				<?php if ( '' === $token && $has_pub ) : ?>
					<p class="small muted"><?php echo __( 'Token yok: yalnız public depolar okunuyor.' ); ?></p>
				<?php endif; ?>
				<?php foreach ( $this->server->repo_errors() as $repo => $err ) : ?>
					<p class="small"><span class="pill err"><?php echo __( 'Okunamadı' ); ?></span> <?php echo $e( $repo . ': ' . $err ); ?></p>
				<?php endforeach; ?>
				<form method="post" autocomplete="off">
					<?php echo $this->hidden( 'save_github' ); ?>
					<label><?php echo __( 'Private depolar' ); ?> <span class="muted small"><?php echo __( '(paketleri yalnız site anahtarıyla)' ); ?></span></label>
					<?php $this->repo_chips( 'repo', implode( ', ', $this->server->private_repos() ), 'private' ); ?>
					<label><?php echo __( 'Public depolar' ); ?> <span class="muted small"><?php echo __( '(paketleri herkese anahtarsız; boşsa public güncelleme kapalı)' ); ?></span></label>
					<?php $this->repo_chips( 'public_repos', implode( ', ', $this->server->public_repos() ), 'public' ); ?>
					<label><?php echo __( 'Uygulama deposu' ); ?> <span class="muted small"><?php echo __( '(masaüstü/mobil uygulama, tag v&lt;sürüm&gt;)' ); ?></span></label>
					<?php $this->repo_chips( 'app_repo', (string) ( $this->config['app_repo'] ?? '' ), 'app', true ); ?>
					<p class="small muted"><?php echo __( 'Liste GitHub\'dan gelir: token\'ın erişebildiği depolar ve bu sahiplerin public depoları. Listede olmayanı <code>owner/repo</code> yazıp Enter ile ekleyin.' ); ?></p>
					<label>Token <span class="muted small"><?php echo __( '(fine-grained, private depolar + uygulama deposu, Contents: Read-only)' ); ?></span></label>
					<input type="password" name="github_token" placeholder="<?php echo $token ? $e( _f( 'Kayıtlı: …%s (değiştirmek için yenisini yazın)', substr( $token, -4 ) ) ) : 'github_pat_…'; ?>">
					<p class="row"><button class="btn"><?php echo __( 'Kaydet ve test et' ); ?></button></p>
				</form>
				<form method="post"><?php echo $this->hidden( 'flush' ); ?><button class="btn sec sm"><?php echo __( 'Paketleri yeniden oku' ); ?></button></form>
			</div>

			<div class="card">
				<h2><?php echo __( 'Site ekle' ); ?></h2>
				<form method="post">
					<?php echo $this->hidden( 'add_site' ); ?>
					<label><?php echo __( 'Site adı' ); ?></label>
					<input type="text" name="name" placeholder="<?php echo $e( __( 'ör. Örnek Site' ) ); ?>" required>
					<?php $this->packages_field( $catalog, '*' ); ?>
					<label><?php echo __( 'Kanal' ); ?></label>
					<select name="channel"><option value="stable"><?php echo __( 'Kararlı sürümler' ); ?></option><option value="beta"><?php echo __( 'Beta dahil' ); ?></option></select>
					<p><button class="btn"><?php echo __( 'Anahtar üret' ); ?></button></p>
				</form>
				<details>
					<summary><?php echo __( 'Var olan bir anahtarı test et' ); ?></summary>
					<form method="post" class="row" style="margin-top:8px">
						<?php echo $this->hidden( 'test_key' ); ?>
						<input type="password" name="key" placeholder="flxu_…" required style="flex:1;min-width:200px" autocomplete="off">
						<button class="btn sec"><?php echo __( 'Test et' ); ?></button>
					</form>
				</details>
			</div>
		</div>

		<div class="card" id="keys">
			<h2><?php echo __( 'Anahtarlı siteler' ); ?></h2>
			<?php if ( ! $sites ) : ?>
				<p class="muted"><?php echo __( 'Henüz site yok. "Site ekle" ile ilk anahtarı üretin.' ); ?></p>
			<?php else : ?>
			<div class="table-wrap"><table>
				<tr><th><?php echo __( 'Site' ); ?></th><th><?php echo __( 'Durum' ); ?></th><th><?php echo __( 'Kurulu paketler' ); ?></th><th><?php echo __( 'İzin' ); ?></th><th></th></tr>
				<?php foreach ( $sites as $hash => $conf ) : ?>
					<?php
					$off  = isset( $conf['active'] ) && ! $conf['active'];
					$pend = is_array( $conf['pending'] ?? null ) ? $conf['pending'] : null;
					$st  = $states[ $hash ] ?? array();
					$ts  = (int) ( $st['last_seen_ts'] ?? 0 );
					$pkg = array();
					foreach ( (array) ( $st['packages'] ?? array() ) as $slug => $ver ) {
						$latest = $catalog[ $slug ]['version'] ?? null;
						$old    = $latest && version_compare( (string) $ver, $latest, '<' );
						$pkg[]  = $e( $slug ) . ' <b style="color:' . ( $old ? 'var(--amber)' : 'var(--green)' ) . '">' . $e( $ver ) . '</b>' . ( $old ? ' <span class="muted">→ ' . $e( $latest ) . '</span>' : '' );
					}
					?>
					<tr id="<?php echo $e( self::key_anchor( (string) $hash ) ); ?>"<?php echo $off ? ' class="off"' : ''; ?>>
						<td><b><?php echo $e( $conf['name'] ?? '?' ); ?></b><br><span class="small muted"><?php echo $e( $st['url'] ?? '' ); ?></span>
							<?php if ( ! empty( $st['wp'] ) ) : ?><br><span class="small muted">WP <?php echo $e( $st['wp'] ); ?> · PHP <?php echo $e( $st['php'] ?? '' ); ?></span><?php endif; ?></td>
						<td>
							<?php if ( $off ) : ?>
								<span class="pill err"><?php echo __( 'Pasif' ); ?></span><br>
							<?php endif; ?>
							<?php if ( $pend && (int) $pend['exp'] < time() ) : ?>
								<span class="pill err"><?php echo __( 'Teslim süresi doldu' ); ?></span>
							<?php elseif ( $pend ) : ?>
								<span class="pill warn" title="<?php echo $e( __( 'Site bir sonraki kontrolde anahtarı kendiliğinden alacak.' ) ); ?>"><?php echo __( 'Teslim bekliyor' ); ?></span>
							<?php elseif ( ! $ts && empty( $st['last_seen'] ) ) : ?>
								<span class="pill muted"><?php echo __( 'Hiç bağlanmadı' ); ?></span>
							<?php elseif ( $ts && time() - $ts > 2 * DAY_SECONDS ) : ?>
								<span class="pill warn"><?php echo $e( _f( '%d gündür yok', (int) floor( ( time() - $ts ) / DAY_SECONDS ) ) ); ?></span>
							<?php else : ?>
								<span class="pill ok"><?php echo __( 'Bağlı' ); ?></span>
							<?php endif; ?>
							<br><span class="small muted"><?php echo $e( $st['last_seen'] ?? '' ); ?></span>
						</td>
						<td class="small"><?php echo $pkg ? implode( '<br>', $pkg ) : '<span class="muted">—</span>'; ?></td>
						<td class="small"><?php echo $this->scope_label( $conf, $catalog ); ?><br><span class="muted"><?php echo 'beta' === ( $conf['channel'] ?? '' ) ? __( 'Beta dahil' ) : __( 'Kararlı' ); ?></span></td>
						<td>
							<?php if ( 'panel' === ( $conf['source'] ?? '' ) ) : ?>
								<form method="post" class="inline">
									<?php echo $this->hidden( 'toggle_site' ); ?>
									<input type="hidden" name="hash" value="<?php echo $e( $hash ); ?>">
									<input type="hidden" name="on" value="<?php echo $off ? '1' : ''; ?>">
									<button class="btn sm <?php echo $off ? '' : 'sec'; ?>"><?php echo $off ? __( 'Aktif yap' ) : __( 'Pasif yap' ); ?></button>
								</form>
								<details<?php echo ( $args['issued'] ?? '' ) === $hash ? ' open' : ''; ?>><summary><?php echo __( 'Erişimi düzenle' ); ?></summary>
									<form method="post" style="min-width:240px">
										<?php echo $this->hidden( 'update_site' ); ?>
										<input type="hidden" name="hash" value="<?php echo $e( $hash ); ?>">
										<label><?php echo __( 'Ad' ); ?></label><input type="text" name="name" value="<?php echo $e( $conf['name'] ?? '' ); ?>">
										<?php $this->packages_field( $catalog, $conf['packages'] ?? '*', (array) ( $conf['repos'] ?? array() ) ); ?>
										<label><?php echo __( 'Kanal' ); ?></label>
										<select name="channel"><option value="stable"><?php echo __( 'Kararlı' ); ?></option><option value="beta" <?php echo 'beta' === ( $conf['channel'] ?? '' ) ? 'selected' : ''; ?>><?php echo __( 'Beta dahil' ); ?></option></select>
										<p><button class="btn sm"><?php echo __( 'Kaydet' ); ?></button></p>
									</form>
									<form method="post" onsubmit="return confirm(<?php echo $e( json_encode( __( 'Bu site silinsin mi? Anahtarı hemen geçersiz olur.' ), JSON_UNESCAPED_UNICODE ) ); ?>)">
										<?php echo $this->hidden( 'delete_site' ); ?>
										<input type="hidden" name="hash" value="<?php echo $e( $hash ); ?>">
										<button class="btn danger sm"><?php echo __( 'Siteyi sil' ); ?></button>
									</form>
								</details>
							<?php else : ?>
								<span class="small muted"><?php echo __( 'config.php\'den' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table></div>
			<?php endif; ?>
		</div>

		<div class="card" id="packages">
			<h2><?php echo __( 'Paketler' ); ?></h2>
			<?php if ( ! $catalog ) : ?>
				<p class="muted"><?php echo __( 'Paket yok. Depoları ve (private depo için) GitHub token\'ı girin; depoda release yoksa bir pakete sürüm çıkın.' ); ?></p>
			<?php else : ?>
			<div class="table-wrap"><table>
				<tr><th><?php echo __( 'Paket' ); ?></th><th><?php echo __( 'Erişim' ); ?></th><th><?php echo __( 'Tür' ); ?></th><th><?php echo __( 'Son sürüm' ); ?></th><th><?php echo __( 'Yayın' ); ?></th></tr>
				<?php foreach ( $catalog as $slug => $r ) : ?>
					<tr>
						<td><b><?php echo $e( $r['name'] ); ?></b><br><span class="small muted"><?php echo $e( $slug ); ?></span></td>
						<td><?php echo ! empty( $r['public'] ) ? '<span class="pill ok">Public</span>' : '<span class="pill muted">' . __( 'Anahtarla' ) . '</span>'; ?><br><span class="small muted"><?php echo $e( $r['repo'] ?? '' ); ?></span></td>
						<td><?php echo 'theme' === $r['type'] ? __( 'Tema' ) : __( 'Eklenti' ); ?></td>
						<td><?php echo $e( $r['version'] ); ?><?php echo ( isset( $stable[ $slug ] ) && $stable[ $slug ]['version'] === $r['version'] ) ? '' : ' <span class="pill warn">beta</span>'; ?></td>
						<td class="small"><?php echo $e( str_replace( 'T', ' ', substr( $r['published_at'], 0, 16 ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table></div>
			<?php endif; ?>
		</div>

		<div class="grid">
			<div class="card">
				<h2><?php echo __( 'Anında yayın (isteğe bağlı)' ); ?></h2>
				<p class="small muted"><?php echo _f( 'Yeni sürüm en geç 5 dakikada görünür. Anında görünsün isterseniz bu secret\'ı paket deposunun Settings › Secrets › Actions bölümüne <code>UPDATE_WEBHOOK_SECRET</code> adıyla ekleyin; repo değişkeni <code>UPDATE_SERVER</code> = <code>%s</code>.', $e( $this->server->base_url() ) ); ?></p>
				<?php if ( ! empty( $this->config['webhook_secret'] ) ) : ?>
					<pre><?php echo $e( $this->config['webhook_secret'] ); ?></pre>
				<?php endif; ?>
				<form method="post"><?php echo $this->hidden( 'gen_webhook' ); ?><button class="btn sec sm"><?php echo empty( $this->config['webhook_secret'] ) ? __( 'Secret üret' ) : __( 'Yeni secret üret' ); ?></button></form>
			</div>
			<div class="card">
				<h2><?php echo __( 'Dosyalar' ); ?></h2>
				<?php $zs = $this->server->zip_stats(); ?>
				<p class="small"><?php echo $e( _f( '%d zip', (int) $zs['files'] ) ); ?>, <?php echo $e( self::size( $zs['bytes'] ) ); ?></p>
				<p class="small muted"><?php echo __( 'Her paketin son sürümleri sunucuda saklanır. Daha eski bir sürüm istenirse GitHub release\'ten yeniden indirilir ve son indirmeden sonra belirtilen süre dolunca silinir. Temizlik saatte bir kendiliğinden yapılır.' ); ?></p>
				<form method="post">
					<?php echo $this->hidden( 'save_zips' ); ?>
					<label><?php echo __( 'Paket başına saklanan sürüm' ); ?></label>
					<input type="number" name="zip_keep" min="1" max="50" value="<?php echo (int) ( $this->config['zip_keep'] ?? 5 ); ?>">
					<label><?php echo __( 'Eski sürüm kaç saat sonra silinsin' ); ?></label>
					<input type="number" name="zip_temp_hours" min="1" max="720" value="<?php echo (int) ( $this->config['zip_temp_hours'] ?? 24 ); ?>">
					<p class="row"><button class="btn sec sm"><?php echo __( 'Kaydet' ); ?></button></p>
				</form>
				<form method="post"><?php echo $this->hidden( 'prune_zips' ); ?><button class="btn sec sm"><?php echo __( 'Şimdi temizle' ); ?></button></form>
			</div>
			<div class="card">
				<h2><?php echo __( 'Parola değiştir' ); ?></h2>
				<form method="post" autocomplete="off">
					<?php echo $this->hidden( 'change_password' ); ?>
					<label><?php echo __( 'Yeni parola' ); ?></label><input type="password" name="password" minlength="10" required>
					<label><?php echo __( 'Tekrar' ); ?></label><input type="password" name="password2" minlength="10" required>
					<p><button class="btn sec sm"><?php echo __( 'Değiştir' ); ?></button></p>
				</form>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Genel bakış: kullanım ve kaynaklar                                  */
	/* ------------------------------------------------------------------ */

	const ICONS = array(
		'sites'  => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
		'key'    => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 9.3-9.3M17 6l3 3M14 9l2 2"/>',
		'check'  => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
		'down'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
		'data'   => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5"/><path d="M3 12c0 1.7 4 3 9 3s9-1.3 9-3"/>',
		'globe'  => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20z"/>',
	);

	private static function n( $x ): string {
		return I18n::num( $x );
	}

	private static function ago( int $ts ): string {
		if ( ! $ts ) {
			return '—';
		}
		$d = time() - $ts;
		if ( $d < 90 ) {
			return __( 'az önce' );
		}
		if ( $d < 3600 ) {
			return _f( '%d dk önce', (int) round( $d / 60 ) );
		}
		if ( $d < 2 * DAY_SECONDS ) {
			return _f( '%d sa önce', (int) round( $d / 3600 ) );
		}
		return _f( '%d gün önce', (int) floor( $d / DAY_SECONDS ) );
	}

	private static function country_name( string $cc ): string {
		if ( '?' === $cc || '' === $cc ) {
			return __( 'Bilinmiyor' );
		}
		if ( class_exists( '\Locale' ) ) {
			$n = \Locale::getDisplayRegion( '-' . $cc, I18n::lang() );
			if ( $n && $n !== $cc ) {
				return $n;
			}
		}
		return $cc;
	}

	private static function delta( int $now, int $prev ): string {
		if ( $prev <= 0 ) {
			return '';
		}
		$ch  = round( ( $now - $prev ) / $prev * 100, 1 );
		$cls = $ch > 0 ? 'up' : ( $ch < 0 ? 'down' : '' );
		return '<span class="delta ' . $cls . '" title="' . self::e( __( 'Önceki eşit döneme göre' ) ) . '">' . ( $ch > 0 ? '↑' : ( $ch < 0 ? '↓' : '' ) ) . I18n::num( abs( $ch ), 1 ) . '%</span>';
	}

	private static function kpi( string $label, string $value, string $delta, string $sub, string $tone, string $icon ): string {
		return '<div class="kpi kpi--' . $tone . '"><div class="kpi__top"><span class="kpi__ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . self::ICONS[ $icon ] . '</svg></span><span class="kpi__label">' . self::e( $label ) . '</span></div>'
			. '<div class="kpi__row"><div class="kpi__value">' . $value . '</div>' . $delta . '</div><div class="kpi__sub" title="' . self::e( $sub ) . '">' . self::e( $sub ) . '</div></div>';
	}

	private static function nice_step( float $max ): float {
		if ( $max <= 4 ) {
			return 1;
		}
		$raw = $max / 4;
		$p   = pow( 10, floor( log10( $raw ) ) );
		$f   = $raw / $p;
		return ( $f <= 1 ? 1 : ( $f <= 2 ? 2 : ( $f <= 5 ? 5 : 10 ) ) ) * $p;
	}

	/** Günlük çubuk grafik: açık = kontrol isteği, koyu = tekil site, nokta = indirme. */
	private static function chart( array $series ): string {
		$W   = 960;
		$H   = 240;
		$pl  = 40;
		$pb  = 22;
		$pt  = 8;
		$max = 0;
		foreach ( $series as $it ) {
			$max = max( $max, $it['checks'], $it['downloads'] );
		}
		$step = self::nice_step( $max );
		$top  = max( $step, ceil( $max / $step ) * $step );
		$iw   = $W - $pl;
		$ih   = $H - $pb - $pt;
		$bw   = $iw / max( 1, count( $series ) );
		$s    = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . self::e( __( 'Günlük kontrol, site ve indirme grafiği' ) ) . '"><g class="grid">';
		for ( $y = 0; $y <= $top; $y += $step ) {
			$yy = $pt + $ih - $y / $top * $ih;
			$s .= '<line x1="' . $pl . '" x2="' . $W . '" y1="' . $yy . '" y2="' . $yy . '"/><text x="' . ( $pl - 6 ) . '" y="' . ( $yy + 4 ) . '" text-anchor="end">' . self::n( $y ) . '</text>';
		}
		$s    .= '</g>';
		$every = (int) ceil( count( $series ) / max( 2, floor( $iw / 70 ) ) );
		$mon   = explode( ' ', ' ' . __( 'Oca Şub Mar Nis May Haz Tem Ağu Eyl Eki Kas Ara' ) );
		foreach ( $series as $i => $it ) {
			$x   = $pl + $i * $bw;
			$w   = max( 2, $bw * 0.72 );
			$ox  = $x + ( $bw - $w ) / 2;
			$rx  = min( 3, $w / 3 );
			$hc  = $it['checks'] ? max( 3, $it['checks'] / $top * $ih ) : 0;
			$hs  = $it['sites'] ? max( 3, $it['sites'] / $top * $ih ) : 0;
			$lab = (int) substr( $it['d'], 8, 2 ) . ' ' . $mon[ (int) substr( $it['d'], 5, 2 ) ];
			$s  .= '<rect class="c" x="' . round( $ox, 1 ) . '" y="' . round( $pt + $ih - $hc, 1 ) . '" width="' . round( $w, 1 ) . '" height="' . round( $hc, 1 ) . '" rx="' . $rx . '"/>';
			$s  .= '<rect class="s" x="' . round( $ox, 1 ) . '" y="' . round( $pt + $ih - $hs, 1 ) . '" width="' . round( $w, 1 ) . '" height="' . round( $hs, 1 ) . '" rx="' . $rx . '"/>';
			if ( $it['downloads'] ) {
				$s .= '<circle class="dl" cx="' . round( $x + $bw / 2, 1 ) . '" cy="' . round( $pt + $ih - $it['downloads'] / $top * $ih, 1 ) . '" r="3.5"/>';
			}
			$s .= '<rect class="hit" x="' . round( $x, 1 ) . '" y="' . $pt . '" width="' . round( $bw, 1 ) . '" height="' . $ih . '" data-t="' . self::e( $lab . '|' . $it['sites'] . '|' . $it['checks'] . '|' . $it['downloads'] ) . '"/>';
			if ( 0 === $i % $every ) {
				$s .= '<text class="ax" x="' . round( $x + $bw / 2, 1 ) . '" y="' . ( $H - 6 ) . '" text-anchor="middle">' . self::e( $lab ) . '</text>';
			}
		}
		return $s . '</svg>';
	}

	/** Dünya haritası (Flux Analytics ile aynı veri, Natural Earth 1:110m). */
	private function world_map( array $countries ): string {
		$file = __DIR__ . '/world.json';
		$w    = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		if ( ! is_array( $w ) || empty( $w['p'] ) ) {
			return '';
		}
		$max = max( 1, (int) max( array_values( $countries ) ?: array( 1 ) ) );
		$s   = '<svg viewBox="0 0 ' . (int) $w['w'] . ' ' . (int) $w['h'] . '" role="img" aria-label="' . self::e( __( 'Dünya haritası' ) ) . '">';
		foreach ( $w['p'] as $cc => $d ) {
			$v   = (int) ( $countries[ $cc ] ?? 0 );
			$lvl = $v ? max( 1, (int) ceil( sqrt( $v / $max ) * 5 ) ) : 0;
			$s  .= '<path d="' . self::e( $d ) . '" class="l' . $lvl . '" data-t="' . self::e( self::country_name( $cc ) . '|' . $v ) . '"/>';
		}
		return $s . '</svg>';
	}

	private function view_usage( array $catalog ): void {
		$e     = array( __CLASS__, 'e' );
		$range = (int) ( $_GET['r'] ?? 30 );
		$range = in_array( $range, array( 7, 30, 90 ), true ) ? $range : 30;
		$S     = $this->server->stats()->summary( $range );
		$T     = $S['totals'];
		$P     = $S['prev'];
		$known = array_diff_key( $S['countries'], array( '?' => 0 ) );
		$base  = $this->server->base_url() . '/admin';
		?>
		<nav class="tabs-nav">
			<a href="#usage"><?php echo __( 'Genel bakış' ); ?></a><a href="#resources"><?php echo __( 'Kaynaklar' ); ?></a><a href="#settings"><?php echo __( 'Ayarlar' ); ?></a><a href="#keys"><?php echo __( 'Anahtarlı siteler' ); ?></a><a href="#packages"><?php echo __( 'Paketler' ); ?></a>
		</nav>
		<div class="sec-head" id="usage">
			<h2 class="sec"><?php echo __( 'Genel bakış' ); ?></h2>
			<div class="seg" role="tablist">
				<?php foreach ( array( 7 => __( '7 gün' ), 30 => __( '30 gün' ), 90 => __( '90 gün' ) ) as $k => $l ) : ?>
					<a href="<?php echo $e( $base . '?r=' . $k ); ?>#usage" role="tab" aria-selected="<?php echo $k === $range ? 'true' : 'false'; ?>"><?php echo $e( $l ); ?></a>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="kpis">
			<?php
			echo self::kpi( __( 'Aktif site' ), self::n( $T['sites'] ), self::delta( $T['sites'], $P['sites'] ), _f( 'Önceki dönem: %s', self::n( $P['sites'] ) ), 'green', 'sites' );
			echo self::kpi( __( 'Anahtarlı' ), self::n( $T['keyed'] ), '', _f( '%s site anahtarsız (public)', self::n( $T['sites'] - $T['keyed'] ) ), 'violet', 'key' );
			echo self::kpi( __( 'Kontrol isteği' ), self::n( $T['checks'] ), self::delta( $T['checks'], $P['checks'] ), _f( '%s anahtarsız', self::n( $T['anon'] ) ), 'blue', 'check' );
			echo self::kpi( __( 'İndirme' ), self::n( $T['downloads'] ), self::delta( $T['downloads'], $P['downloads'] ), _f( 'Önceki dönem: %s', self::n( $P['downloads'] ) ), 'amber', 'down' );
			echo self::kpi( __( 'Aktarılan' ), $e( self::size( (int) $T['bytes'] ) ), '', __( 'Zip indirmeleri' ), 'teal', 'data' );
			echo self::kpi( __( 'Ülke' ), self::n( count( $known ) ), '', isset( $S['countries']['?'] ) ? _f( '%s sitenin ülkesi bilinmiyor', self::n( $S['countries']['?'] ) ) : __( 'Sitelerin saat diliminden' ), 'rose', 'globe' );
			?>
		</div>

		<div class="card chart-card">
			<div class="row" style="justify-content:space-between">
				<h2><?php echo __( 'Günlük kullanım' ); ?></h2>
				<div class="legend"><span><i class="c"></i><?php echo __( 'Kontrol' ); ?></span><span><i class="s"></i><?php echo __( 'Tekil site' ); ?></span><span><i class="dl"></i><?php echo __( 'İndirme' ); ?></span></div>
			</div>
			<div class="chart tip-host"><?php echo self::chart( $S['series'] ); ?><div class="tip"></div></div>
		</div>

		<div class="grid">
			<div class="card">
				<h2><?php echo __( 'Paketler' ); ?></h2>
				<?php
				$rows = array();
				foreach ( $S['installs'] as $slug => $n ) {
					$rows[ $slug ] = array( $n, (int) ( $S['downloads'][ $slug ] ?? 0 ) );
				}
				foreach ( $S['downloads'] as $slug => $n ) {
					if ( ! isset( $rows[ $slug ] ) ) {
						$rows[ $slug ] = array( 0, $n );
					}
				}
				$rows = array_slice( $rows, 0, 12, true );
				$pmax = 0;
				foreach ( $rows as $r ) {
					$pmax = max( $pmax, $r[0] );
				}
				?>
				<?php if ( ! $rows ) : ?>
					<p class="empty"><?php echo __( 'Bu aralıkta kayıt yok.' ); ?></p>
				<?php else : ?>
				<table class="bars">
					<tr><th><?php echo __( 'Paket' ); ?></th><th class="n"><?php echo __( 'Kurulu site' ); ?></th><th class="n"><?php echo __( 'İndirme' ); ?></th></tr>
					<?php foreach ( $rows as $slug => $r ) : ?>
						<tr>
							<td class="lbl"><span class="bar" style="width:<?php echo $pmax ? round( $r[0] / $pmax * 100, 1 ) : 0; ?>%"></span><span class="txt"><?php echo $e( $catalog[ $slug ]['name'] ?? $slug ); ?><?php if ( isset( $catalog[ $slug ] ) && ! empty( $catalog[ $slug ]['public'] ) ) : ?> <small>public</small><?php endif; ?></span></td>
							<td class="n"><?php echo self::n( $r[0] ); ?></td>
							<td class="n"><?php echo self::n( $r[1] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php endif; ?>
			</div>
			<div class="card">
				<h2><?php echo __( 'Ülkeler' ); ?></h2>
				<div class="map tip-host"><?php echo $this->world_map( $known ); ?><div class="tip"></div></div>
				<div class="map-legend"><span><?php echo __( 'Az' ); ?></span><i class="l1"></i><i class="l2"></i><i class="l3"></i><i class="l4"></i><i class="l5"></i><span><?php echo __( 'Çok' ); ?></span></div>
				<?php if ( $S['countries'] ) : ?>
				<?php $cmax = (int) max( $S['countries'] ); ?>
				<table class="bars">
					<?php foreach ( array_slice( $S['countries'], 0, 6, true ) as $cc => $n ) : ?>
						<tr><td class="lbl"><span class="bar" style="width:<?php echo round( $n / $cmax * 100, 1 ); ?>%"></span><span class="txt"><?php if ( '?' !== $cc ) : ?><b class="cc"><?php echo $e( $cc ); ?></b><?php endif; ?><?php echo $e( self::country_name( (string) $cc ) ); ?></span></td><td class="n"><?php echo $e( _f( '%s site', self::n( $n ) ) ); ?></td></tr>
					<?php endforeach; ?>
				</table>
				<?php endif; ?>
			</div>
		</div>

		<div class="card" id="clients">
			<div class="row" style="justify-content:space-between">
				<h2><?php echo __( 'Bağlanan siteler' ); ?></h2>
				<span class="small muted"><?php echo $e( _f( '%1$s site bu aralıkta, toplam %2$s kayıtlı', self::n( count( $S['clients'] ) ), self::n( $S['all_clients'] ) ) ); ?></span>
			</div>
			<?php if ( ! $S['clients'] ) : ?>
				<p class="empty"><?php echo __( 'Bu aralıkta güncelleme denetleyen site yok.' ); ?></p>
			<?php else : ?>
			<div class="table-wrap"><table>
				<tr><th><?php echo __( 'Site' ); ?></th><th><?php echo __( 'Erişim' ); ?></th><th><?php echo __( 'Ülke' ); ?></th><th><?php echo __( 'Ortam' ); ?></th><th class="n"><?php echo __( 'Paket' ); ?></th><th class="n"><?php echo __( 'İstek' ); ?></th><th><?php echo __( 'Son' ); ?></th></tr>
				<?php
				$pending = array();
				foreach ( (array) $this->config['sites'] as $h => $conf ) {
					if ( ! empty( $conf['pending']['ih'] ) && (int) ( $conf['pending']['exp'] ?? 0 ) >= time() ) {
						$pending[ $conf['pending']['ih'] ] = (string) $h;
					}
				}
				$i = 0;
				foreach ( $S['clients'] as $cid => $c ) :
					?>
					<?php
					$host = (string) parse_url( (string) ( $c['url'] ?? '' ), PHP_URL_HOST );
					$path = rtrim( (string) parse_url( (string) ( $c['url'] ?? '' ), PHP_URL_PATH ), '/' );
					?>
					<tr<?php echo ++$i > 15 ? ' class="more" hidden' : ''; ?>>
						<td><b><?php echo $e( '' !== $host ? $host . $path : 'Adres bildirmedi' ); ?></b><?php if ( ! empty( $c['name'] ) ) : ?><br><span class="small muted"><?php echo $e( $c['name'] ); ?></span><?php endif; ?></td>
						<td><?php echo $this->client_access( (string) $cid, $c, $pending ); ?></td>
						<td class="small"><?php echo ! empty( $c['cc'] ) ? '<b class="cc">' . $e( $c['cc'] ) . '</b>' . $e( self::country_name( (string) $c['cc'] ) ) : '<span class="muted">—</span>'; ?></td>
						<td class="small muted"><?php echo $e( trim( ( $c['wp'] ? 'WP ' . $c['wp'] : '' ) . ( $c['php'] ? ' · PHP ' . $c['php'] : '' ) ) ); ?><?php if ( ! empty( $c['upd'] ) ) : ?><br>Updater <?php echo $e( $c['upd'] ); ?><?php endif; ?></td>
						<td class="n" title="<?php echo $e( implode( ', ', array_keys( (array) ( $c['pk'] ?? array() ) ) ) ); ?>"><?php echo self::n( count( (array) ( $c['pk'] ?? array() ) ) ); ?></td>
						<td class="n"><?php echo self::n( $c['n'] ?? 0 ); ?></td>
						<td class="small"><?php echo $e( self::ago( (int) ( $c['last'] ?? 0 ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table></div>
			<?php if ( count( $S['clients'] ) > 15 ) : ?>
				<p><button type="button" class="btn sec sm" onclick="document.querySelectorAll('#clients tr.more').forEach(function(r){r.hidden=false});this.remove()"><?php echo $e( _f( 'Tümünü göster (%s)', self::n( count( $S['clients'] ) ) ) ); ?></button></p>
			<?php endif; ?>
			<?php endif; ?>
			<p class="small muted"><?php echo __( 'IP saklanmaz. Ülke, sitenin WordPress saat diliminden bulunur (Cloudflare arkasındaysa CF-IPCountry). Flux Updater 1.6.1\'den eskiler saat dilimi göndermez.' ); ?></p>
		</div>
		<?php
		$this->view_resources();
	}

	/** "Bağlanan siteler" erişim hücresi: anahtarlı/pasif ise anahtar satırına bağlantı, anahtarsızsa "Anahtar ver". */
	private function client_access( string $cid, array $c, array $pending ): string {
		$e = array( __CLASS__, 'e' );
		if ( ! empty( $c['key'] ) ) {
			$hash = '';
			foreach ( array_keys( (array) $this->config['sites'] ) as $h ) {
				if ( 'k' . substr( (string) $h, 0, 15 ) === $cid ) {
					$hash = (string) $h;
					break;
				}
			}
			$pill = ! empty( $c['off'] ) ? '<span class="pill err">' . $e( __( 'Pasif' ) ) . '</span>' : '<span class="pill ok">' . $e( __( 'Anahtarlı' ) ) . '</span>';
			return '' !== $hash ? '<a href="#' . $e( self::key_anchor( $hash ) ) . '" title="' . $e( __( 'Anahtar ayarları' ) ) . '">' . $pill . '</a>' : $pill;
		}
		$out = '<span class="pill muted">Public</span>';
		$ih  = (string) ( $c['ih'] ?? '' );
		if ( '' === $ih ) {
			return $out . '<br><span class="small muted" title="' . $e( __( 'Otomatik anahtar teslimi için sitede Flux Updater 1.7.0 ya da üstü gerekir.' ) ) . '">' . $e( __( 'Updater 1.7.0+ gerekli' ) ) . '</span>';
		}
		if ( isset( $pending[ $ih ] ) ) {
			return '<a href="#' . $e( self::key_anchor( $pending[ $ih ] ) ) . '"><span class="pill warn">' . $e( __( 'Teslim bekliyor' ) ) . '</span></a>';
		}
		return $out . '<form method="post" class="inline give">' . $this->hidden( 'issue_key' ) . '<input type="hidden" name="client" value="' . $e( $cid ) . '"><button class="btn sm">' . $e( __( 'Anahtar ver' ) ) . '</button></form>';
	}

	private static function meter( float $used, float $total, string $tone = '' ): string {
		$pct = $total > 0 ? min( 100, $used / $total * 100 ) : 0;
		$cls = $tone ? $tone : ( $pct >= 90 ? 'err' : ( $pct >= 75 ? 'warn' : 'ok' ) );
		return '<div class="meter ' . $cls . '"><i style="width:' . round( $pct, 1 ) . '%"></i></div>';
	}

	private function view_resources(): void {
		$e  = array( __CLASS__, 'e' );
		$R  = $this->server->resources();
		$zs = $this->server->zip_stats();
		$gh = $this->server->github_rate();
		$used = max( 0, $R['disk_total'] - $R['disk_free'] );
		// Paylaşımlı hosting'de PHP fiziksel makinenin diskini görür (TB'larca); hesabın kotası değildir.
		$shared = $R['disk_total'] > 2 * 1024 ** 4;
		$parts = array(
			'zips'  => array( __( 'Zip dosyaları' ), '#2563eb' ),
			'cache' => array( __( 'GitHub önbelleği' ), '#0d9488' ),
			'stats' => array( __( 'İstatistik' ), '#7c3aed' ),
			'logs'  => array( __( 'Günlük' ), '#d97706' ),
			'other' => array( __( 'Ayarlar ve diğer' ), '#94a3b8' ),
		);
		$age = $R['cache_at'] ? time() - $R['cache_at'] : 0;
		?>
		<div class="sec-head" id="resources"><h2 class="sec"><?php echo __( 'Kaynaklar' ); ?></h2></div>
		<div class="grid3">
			<div class="card">
				<h2><?php echo __( 'Depolama' ); ?></h2>
				<?php if ( $R['disk_total'] > 0 && $shared ) : ?>
					<div class="res-line"><span><?php echo __( 'Makine diski (paylaşımlı)' ); ?></span><b><?php echo $e( _f( '%s boş', self::size( (int) $R['disk_free'] ) ) ); ?></b></div>
					<p class="small muted"><?php echo __( 'Paylaşımlı hosting\'de PHP sunucudaki tüm hesapların ortak diskini görür; bu sizin kotanız değil. Kotayı hosting panelinden (Hostinger: hPanel › Hesap kaynakları) görün.' ); ?></p>
				<?php elseif ( $R['disk_total'] > 0 ) : ?>
					<div class="res-line"><span><?php echo __( 'Disk' ); ?></span><b><?php echo $e( self::size( (int) $used ) ); ?> / <?php echo $e( self::size( (int) $R['disk_total'] ) ); ?></b></div>
					<?php echo self::meter( $used, $R['disk_total'] ); ?>
					<p class="small muted"><?php echo $e( _f( '%s boş', self::size( (int) $R['disk_free'] ) ) ); ?></p>
				<?php endif; ?>
				<div class="res-line"><span><?php echo __( 'Sunucu verisi (data/)' ); ?></span><b><?php echo $e( self::size( (int) $R['data_total'] ) ); ?></b></div>
				<div class="stack">
					<?php foreach ( $parts as $k => $p ) : ?>
						<?php if ( $R['data_total'] > 0 && $R['data'][ $k ] > 0 ) : ?><i style="width:<?php echo max( 1, round( $R['data'][ $k ] / $R['data_total'] * 100, 1 ) ); ?>%;background:<?php echo $p[1]; ?>" title="<?php echo $e( $p[0] ); ?>"></i><?php endif; ?>
					<?php endforeach; ?>
				</div>
				<table class="mini">
					<?php foreach ( $parts as $k => $p ) : ?>
						<tr><td><i class="dot" style="background:<?php echo $p[1]; ?>"></i><?php echo $e( $p[0] ); ?><?php echo 'zips' === $k ? ' <span class="muted">(' . (int) $zs['files'] . ')</span>' : ''; ?></td><td class="n"><?php echo $e( self::size( (int) $R['data'][ $k ] ) ); ?></td></tr>
					<?php endforeach; ?>
				</table>
				<div class="row">
					<form method="post"><?php echo $this->hidden( 'prune_zips' ); ?><button class="btn sec sm"><?php echo __( 'Eski zipleri temizle' ); ?></button></form>
					<form method="post" onsubmit="return confirm(<?php echo $e( json_encode( __( 'Erişim günlüğü silinsin mi?' ), JSON_UNESCAPED_UNICODE ) ); ?>)"><?php echo $this->hidden( 'clear_log' ); ?><button class="btn sec sm"><?php echo __( 'Günlüğü temizle' ); ?></button></form>
				</div>
			</div>
			<div class="card">
				<h2>GitHub API</h2>
				<?php if ( ! empty( $gh['limit'] ) ) : ?>
					<div class="res-line"><span><?php echo __( 'API kotası (saatlik)' ); ?></span><b><?php echo self::n( $gh['remaining'] ); ?> / <?php echo self::n( $gh['limit'] ); ?></b></div>
					<?php echo self::meter( $gh['limit'] - $gh['remaining'], $gh['limit'] ); ?>
					<p class="small muted"><?php echo $e( _f( 'Kullanılan %1$s · yenilenme %2$s UTC', self::n( $gh['limit'] - $gh['remaining'] ), gmdate( 'H:i', (int) $gh['reset'] ) ) ); ?><?php echo '' === (string) $this->config['github_token'] ? $e( __( ' · token yok, IP kotası' ) ) : ''; ?></p>
				<?php else : ?>
					<p class="small"><span class="pill warn"><?php echo __( 'Okunamadı' ); ?></span> <?php echo $e( $gh['error'] ?? '' ); ?></p>
				<?php endif; ?>
				<div class="res-line"><span><?php echo __( 'Paket önbelleği' ); ?></span><b><?php echo $R['cache_at'] ? $e( self::ago( $R['cache_at'] ) ) : __( 'boş' ); ?></b></div>
				<?php echo self::meter( min( $age, $R['cache_ttl'] ), max( 1, $R['cache_ttl'] ), 'ok' ); ?>
				<p class="small muted"><?php echo $e( _f( 'Her %d dakikada bir GitHub\'dan yenilenir; webhook gelirse hemen.', (int) round( $R['cache_ttl'] / 60 ) ) ); ?></p>
			</div>
			<div class="card">
				<h2>PHP</h2>
				<table class="mini">
					<tr><td><?php echo __( 'Sürüm' ); ?></td><td class="n"><?php echo $e( $R['php'] ); ?></td></tr>
					<tr><td><?php echo __( 'Bellek sınırı' ); ?></td><td class="n"><?php echo $e( '-1' === $R['memory'] ? __( 'sınırsız' ) : $R['memory'] ); ?></td></tr>
					<tr><td><?php echo __( 'Bu sayfanın en yüksek belleği' ); ?></td><td class="n"><?php echo $e( self::size( (int) $R['peak'] ) ); ?></td></tr>
					<tr><td><?php echo __( 'Süre sınırı' ); ?></td><td class="n"><?php echo $R['max_time'] ? _f( '%d sn', (int) $R['max_time'] ) : __( 'sınırsız' ); ?></td></tr>
					<tr><td>cURL</td><td class="n"><?php echo $e( $R['curl'] ?: __( 'yok' ) ); ?></td></tr>
					<?php if ( is_array( $R['load'] ) ) : ?>
						<tr><td><?php echo $shared ? __( 'Makine yükü, paylaşımlı (1/5/15 dk)' ) : __( 'Sunucu yükü (1/5/15 dk)' ); ?></td><td class="n"><?php echo $e( implode( ' / ', array_map( static function ( $x ) { return I18n::num( $x, 2 ); }, $R['load'] ) ) ); ?></td></tr>
					<?php endif; ?>
					<tr><td><?php echo __( 'intl (ülke adları)' ); ?></td><td class="n"><?php echo class_exists( '\Locale' ) ? __( 'var' ) : __( 'yok' ); ?></td></tr>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Depo seçici: seçilenler etiket olarak görünür, × ile kaldırılır; açılır listede
	 * GitHub'dan gelen depolar. Değer gizli alanda virgülle gider.
	 *
	 * @param string $mode private | public | app
	 */
	private function repo_chips( string $name, string $value, string $mode, bool $single = false ): void {
		$list = array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
		?>
		<div class="chips" data-mode="<?php echo self::e( $mode ); ?>"<?php echo $single ? ' data-single="1"' : ''; ?>>
			<input type="hidden" name="<?php echo self::e( $name ); ?>" value="<?php echo self::e( implode( ', ', $list ) ); ?>">
			<?php foreach ( $list as $r ) : ?>
				<span class="chip" data-v="<?php echo self::e( $r ); ?>"><?php echo self::e( $r ); ?><button type="button" aria-label="<?php echo self::e( _f( '%s kaldır', $r ) ); ?>">×</button></span>
			<?php endforeach; ?>
			<input type="text" class="chip-in" autocomplete="off" placeholder="<?php echo $single ? __( 'Depo seçin' ) : __( 'Depo ekle…' ); ?>">
			<div class="chip-dd" hidden></div>
		</div>
		<?php
	}

	/**
	 * Private erişim: "tümü" ya da seçim. Seçimde her private depo için "depodaki tümü"
	 * (sonradan eklenen paketler dahil) ve depo altında tek tek paketler.
	 */
	private function packages_field( array $catalog, $current, array $repos = array() ): void {
		$all    = '*' === $current;
		$sel    = $all ? array() : (array) $current;
		$rsel   = array_map( 'strtolower', $repos );
		$id     = 'p' . bin2hex( random_bytes( 3 ) );
		$groups = array();
		foreach ( $this->server->private_repos() as $r ) {
			$groups[ strtolower( $r ) ] = array( 'repo' => $r, 'items' => array() );
		}
		foreach ( $catalog as $slug => $r ) {
			if ( ! empty( $r['public'] ) ) {
				continue;
			}
			$k = strtolower( (string) ( $r['repo'] ?? '' ) );
			if ( ! isset( $groups[ $k ] ) ) {
				$groups[ $k ] = array( 'repo' => (string) ( $r['repo'] ?? '' ), 'items' => array() );
			}
			$groups[ $k ]['items'][ $slug ] = $r;
		}
		?>
		<label><?php echo __( 'Private erişim' ); ?> <span class="muted small"><?php echo __( '(public paketler her siteye açık)' ); ?></span></label>
		<select name="scope" onchange="document.getElementById('<?php echo self::e( $id ); ?>').style.display=this.value==='all'?'none':'block'">
			<option value="all" <?php echo $all ? 'selected' : ''; ?>><?php echo __( 'Tüm private paketler (yeni eklenenler dahil)' ); ?></option>
			<option value="some" <?php echo $all ? '' : 'selected'; ?>><?php echo __( 'Seçilen depolar ve paketler' ); ?></option>
		</select>
		<div class="scope" id="<?php echo self::e( $id ); ?>" style="display:<?php echo $all ? 'none' : 'block'; ?>">
			<?php if ( ! $groups ) : ?>
				<p class="small muted"><?php echo __( 'Private depo yok.' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $groups as $k => $g ) : ?>
				<?php $whole = in_array( $k, $rsel, true ); ?>
				<fieldset class="repo-grp<?php echo $whole ? ' whole' : ''; ?>">
					<label class="repo-all"><input type="checkbox" name="repos[]" value="<?php echo self::e( $g['repo'] ); ?>" <?php echo $whole ? 'checked' : ''; ?> onchange="this.closest('fieldset').classList.toggle('whole',this.checked)"> <b><?php echo self::e( $g['repo'] ); ?></b> <span class="muted small"><?php echo self::e( _f( 'tümü, %d paket + sonradan eklenenler', count( $g['items'] ) ) ); ?></span></label>
					<div class="checks">
						<?php foreach ( $g['items'] as $slug => $r ) : ?>
							<label><input type="checkbox" name="packages[]" value="<?php echo self::e( $slug ); ?>" <?php echo ( in_array( $slug, $sel, true ) || in_array( $slug, self::UPDATER_SLUGS, true ) ) ? 'checked' : ''; ?> <?php echo in_array( $slug, self::UPDATER_SLUGS, true ) ? 'disabled' : ''; ?>> <?php echo self::e( $r['name'] ); ?></label>
						<?php endforeach; ?>
					</div>
				</fieldset>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function view_key_test( string $key, array $catalog, array $stable, array $states ): void {
		$e     = array( __CLASS__, 'e' );
		$hash  = hash( 'sha256', $key );
		$found = null;
		foreach ( (array) $this->config['sites'] as $h => $conf ) {
			if ( hash_equals( strtolower( (string) $h ), $hash ) ) {
				$found = $conf;
				break;
			}
		}
		echo '<div class="card"><h2>' . $e( __( 'Anahtar testi' ) ) . '</h2>';
		if ( ! $found ) {
			echo '<p><span class="pill err">' . $e( __( 'Geçersiz' ) ) . '</span> ' . $e( __( 'Bu anahtar kayıtlı bir siteye ait değil.' ) ) . '</p></div>';
			return;
		}
		$off     = isset( $found['active'] ) && ! $found['active'];
		$site    = array(
			'packages' => $found['packages'] ?? '*',
			'repos'    => (array) ( $found['repos'] ?? array() ),
			'channel'  => ( $found['channel'] ?? 'stable' ) === 'beta' ? 'beta' : 'stable',
			'anon'     => $off,
		);
		$visible = array();
		foreach ( ( 'beta' === $site['channel'] ? $catalog : $stable ) as $slug => $r ) {
			if ( $this->server->can_see( $site, $r ) ) {
				$visible[] = $r['name'] . ' ' . $r['version'];
			}
		}
		$seen = $states[ $hash ]['last_seen'] ?? '';
		echo '<p>' . ( $off ? '<span class="pill err">' . $e( __( 'Pasif' ) ) . '</span> ' : '' ) . '<span class="pill ok">' . $e( __( 'Geçerli' ) ) . '</span> <b>' . $e( $found['name'] ?? '' ) . '</b> · ' . ( 'beta' === $site['channel'] ? __( 'Beta dahil' ) : __( 'Kararlı' ) ) . '</p>';
		echo '<p class="small">' . $e( _f( 'Görebileceği paketler (%d):', count( $visible ) ) ) . ' ' . $e( implode( ', ', $visible ) ?: '—' ) . '</p>';
		echo '<p class="small">' . $e( $seen ? _f( 'Site en son %s bağlandı.', $seen ) : __( 'Site henüz bağlanmadı: WordPress › Ayarlar › Flux Updater › "Bağlantıyı test et".' ) ) . '</p>';
		echo '</div>';
	}

	/** Virgülle ayrılmış depo listesi. $allow_empty: boş liste "-" olarak saklanır (kapalı). $max: en çok kaç depo. */
	private function clean_repos( string $value, string $key, bool $allow_empty = false, int $max = 0 ): string {
		$out = array();
		foreach ( preg_split( '/[\s,]+/', $value ) as $repo ) {
			$repo = trim( preg_replace( '#^https?://github\.com/#', '', trim( $repo ) ), '/' );
			if ( preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ) {
				$out[] = $repo;
			}
		}
		if ( ! $out ) {
			return $allow_empty ? '-' : (string) ( $this->config[ $key ] ?? '' );
		}
		$out = array_values( array_unique( $out ) );
		return implode( ', ', $max ? array_slice( $out, 0, $max ) : $out );
	}
}
