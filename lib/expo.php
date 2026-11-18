<?php
/**
 * Expo Updates (protocol v1) — kendi barındırdığımız OTA sunucusu, EAS yok.
 *
 * Uygulama (expo-updates) GET /v1/expo/<marka>/manifest ister; yanıt multipart/mixed:
 * imzalı manifest ya da yönerge (noUpdateAvailable / rollBackToEmbedded). Varlıklar
 * içerik adresli: GET /v1/expo/<marka>/assets/<sha256>.<uzantı>.
 *
 * İMZA SUNUCUDA ATILMAZ. Manifest ve yönergeleri CI (GitHub Actions) özel anahtarla imzalar,
 * sunucu baytları ve imzayı aynen saklayıp sunar. Sunucu ele geçse bile uygulamaya imzasız
 * kod gönderilemez (uygulamadaki sertifika doğrular). Yayın uçları OTA anahtarı ister:
 *
 *   POST /v1/expo/<marka>/assets/missing   {"hashes":[hex…]} → {"missing":[hex…]}
 *   POST /v1/expo/<marka>/assets/<hex>     ham bayt (application/octet-stream), sha256 doğrulanır
 *   POST /v1/expo/<marka>/publish          {"channel","message","commit","updates":[{platform,runtime,manifest,signature}],"no_update":{body,signature}}
 *   POST /v1/expo/<marka>/rollback         {"channel","platform","runtime","directive","signature"} (gömülü sürüme dön)
 *
 * Panelde sertifika kayıtlıysa yayın sırasında imzalar onunla doğrulanır (yanlış anahtar erken düşer).
 *
 * data/expo/<marka>/state.json        kanal → platform → runtime → {active, rollback, history}
 * data/expo/<marka>/updates/<id>.json manifest metni + imza + varlık listesi
 * data/expo/<marka>/assets/<sha256>   varlık baytları (güncellemeler arasında paylaşılır)
 */

namespace FLXUpdate;

final class Expo {

	/** Her kanal/platform/runtime için saklanan güncelleme sayısı (geri almak için). */
	const KEEP = 8;

	/** Tek varlık üst sınırı (bayt). */
	const MAX_ASSET = 64 * 1024 * 1024;

	/** Cihazların gördüğü runtime kaydı en fazla bu sıklıkta yazılır (saniye). */
	const SEEN_EVERY = 600;

	const PLATFORMS = array( 'ios', 'android' );

	/** Seçili cihaz/kullanıcıların önce aldığı kanal. */
	const BETA = 'beta';

	/** Saklanan cihaz kaydı üst sınırı (en eski düşer) ve ömrü (gün). */
	const MAX_DEVICES = 2000;
	const DEVICE_DAYS = 180;

	const MIME = array(
		'js'     => 'application/javascript',
		'hbc'    => 'application/javascript',
		'bundle' => 'application/javascript',
		'json'   => 'application/json',
		'png'    => 'image/png',
		'jpg'    => 'image/jpeg',
		'jpeg'   => 'image/jpeg',
		'gif'    => 'image/gif',
		'webp'   => 'image/webp',
		'svg'    => 'image/svg+xml',
		'ttf'    => 'font/ttf',
		'otf'    => 'font/otf',
		'woff'   => 'font/woff',
		'woff2'  => 'font/woff2',
		'mp3'    => 'audio/mpeg',
		'wav'    => 'audio/wav',
		'mp4'    => 'video/mp4',
		'lottie' => 'application/json',
		'txt'    => 'text/plain',
	);

	/** @var Server */
	private $server;

	/** @var array */
	private $config;

	/** @var string */
	private $root;

	/** @var callable|null Testlerde istek gövdesi (php://input yerine). */
	private $input;

	public function __construct( Server $server, array $config, ?callable $input = null ) {
		$this->server = $server;
		$this->config = $config;
		$this->root   = rtrim( (string) $config['data_dir'], '/' ) . '/expo';
		$this->input  = $input;
	}

	/** Uygulama uçları mı (anahtarsız; acil durdurma ve ülke kuralı uygulanır)? */
	public static function is_client_path( string $path ): bool {
		return (bool) preg_match( '#^/v1/expo/[a-z0-9][a-z0-9-]{0,39}/(manifest|assets/[a-f0-9]{64}\.[a-z0-9]{1,8})$#D', $path );
	}

	/** Yayın uçları mı (OTA anahtarı; webhook gibi acil durdurmadan muaf)? */
	public static function is_publish_path( string $path ): bool {
		return (bool) preg_match( '#^/v1/expo/[a-z0-9][a-z0-9-]{0,39}/(publish|rollback|assets/missing|assets/[a-f0-9]{64})$#D', $path );
	}

	public function handle( string $method, string $path ): void {
		if ( ! preg_match( '#^/v1/expo/([a-z0-9][a-z0-9-]{0,39})/(.+)$#D', $path, $m ) ) {
			throw new HttpError( 'Not found', 404 );
		}
		$brand = $m[1];
		$rest  = $m[2];
		if ( 'manifest' === $rest ) {
			if ( 'GET' !== $method ) {
				throw new HttpError( 'Expected GET', 405 );
			}
			$this->route_manifest( $brand );
			return;
		}
		if ( preg_match( '#^assets/([a-f0-9]{64})\.([a-z0-9]{1,8})$#D', $rest, $a ) && in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			$this->route_asset( $brand, $a[1], $a[2], 'HEAD' === $method );
			return;
		}
		if ( 'POST' !== $method ) {
			throw new HttpError( 'Expected POST', 405 );
		}
		$this->auth_publish();
		if ( 'assets/missing' === $rest ) {
			$this->route_missing( $brand );
			return;
		}
		if ( preg_match( '#^assets/([a-f0-9]{64})$#D', $rest, $a ) ) {
			$this->route_upload( $brand, $a[1] );
			return;
		}
		if ( 'publish' === $rest ) {
			$this->route_publish( $brand );
			return;
		}
		if ( 'rollback' === $rest ) {
			$this->route_rollback( $brand );
			return;
		}
		throw new HttpError( 'Not found', 404 );
	}

	/* ------------------------------------------------------------------ */
	/* Uygulama: manifest ve varlıklar                                     */
	/* ------------------------------------------------------------------ */

	private function route_manifest( string $brand ): void {
		$protocol = (string) ( $_SERVER['HTTP_EXPO_PROTOCOL_VERSION'] ?? '0' );
		if ( ! in_array( $protocol, array( '0', '1' ), true ) ) {
			throw new HttpError( 'Unsupported protocol version. Expected either 0 or 1.', 400 );
		}
		$protocol = (int) $protocol;
		$platform = (string) ( $_SERVER['HTTP_EXPO_PLATFORM'] ?? ( $_GET['platform'] ?? '' ) );
		if ( ! in_array( $platform, self::PLATFORMS, true ) ) {
			throw new HttpError( 'Unsupported platform. Expected either ios or android.', 400 );
		}
		$runtime = (string) ( $_SERVER['HTTP_EXPO_RUNTIME_VERSION'] ?? ( $_GET['runtime-version'] ?? '' ) );
		if ( ! self::valid_runtime( $runtime ) ) {
			throw new HttpError( 'No runtimeVersion provided.', 400 );
		}
		$channel = (string) ( $_SERVER['HTTP_EXPO_CHANNEL_NAME'] ?? ( $_GET['channel'] ?? 'production' ) );
		if ( ! self::valid_channel( $channel ) ) {
			throw new HttpError( 'Invalid channel', 400 );
		}
		$current  = strtolower( (string) ( $_SERVER['HTTP_EXPO_CURRENT_UPDATE_ID'] ?? '' ) );
		$embedded = strtolower( (string) ( $_SERVER['HTTP_EXPO_EMBEDDED_UPDATE_ID'] ?? '' ) );

		$state  = $this->state( $brand );
		$device = self::header_device();
		$accts  = self::header_accounts();
		$this->mark_seen( $brand, $state, $channel, $platform, $runtime, $device, $accts );
		$slot = $state['channels'][ $channel ][ $platform ][ $runtime ] ?? array();
		// Beta: seçili cihaz ya da site kullanıcısı, bu runtime için beta yayını varsa onu alır.
		if ( self::BETA !== $channel && $this->is_beta( $state, $device, $accts ) ) {
			$beta = $state['channels'][ self::BETA ][ $platform ][ $runtime ] ?? array();
			if ( '' !== (string) ( $beta['active'] ?? '' ) && $this->update( $brand, (string) $beta['active'] ) ) {
				$slot = $beta;
			}
		}

		$active = (string) ( $slot['active'] ?? '' );
		$update = '' !== $active ? $this->update( $brand, $active ) : null;
		if ( $update ) {
			if ( 1 === $protocol && $current === $active ) {
				$this->no_update( $state, $protocol );
				return;
			}
			$this->multipart(
				array(
					array( 'manifest', (string) $update['manifest'], (string) ( $update['signature'] ?? '' ) ),
					array( 'extensions', json_encode( array( 'assetRequestHeaders' => new \stdClass() ) ), '' ),
				),
				$protocol
			);
			return;
		}
		$rb = $slot['rollback'] ?? null;
		if ( is_array( $rb ) && ! empty( $rb['body'] ) && 1 === $protocol ) {
			if ( '' === $embedded || $current === $embedded ) {
				$this->no_update( $state, $protocol );
				return;
			}
			$this->multipart( array( array( 'directive', (string) $rb['body'], (string) ( $rb['signature'] ?? '' ) ) ), 1 );
			return;
		}
		$this->no_update( $state, $protocol );
	}

	private function no_update( array $state, int $protocol ): void {
		if ( 0 === $protocol ) {
			// Protokol 0'da yönerge yok; istemci 404'ü "güncelleme yok" sayar.
			throw new HttpError( 'No update available', 404, 'no_update' );
		}
		$d = $state['no_update'] ?? array();
		$body = is_array( $d ) && ! empty( $d['body'] ) ? (string) $d['body'] : '{"type":"noUpdateAvailable"}';
		$sig  = is_array( $d ) && ! empty( $d['body'] ) ? (string) ( $d['signature'] ?? '' ) : '';
		$this->multipart( array( array( 'directive', $body, $sig ) ), 1 );
	}

	/** @param array<int,array{0:string,1:string,2:string}> $parts ad, gövde, expo-signature */
	private function multipart( array $parts, int $protocol ): void {
		$boundary = 'flx-' . bin2hex( random_bytes( 12 ) );
		$out      = '';
		foreach ( $parts as $p ) {
			$out .= '--' . $boundary . "\r\n";
			$out .= 'Content-Disposition: form-data; name="' . $p[0] . '"' . "\r\n";
			$out .= "Content-Type: application/json; charset=utf-8\r\n";
			if ( '' !== $p[2] ) {
				$out .= 'expo-signature: ' . $p[2] . "\r\n";
			}
			$out .= "\r\n" . $p[1] . "\r\n";
		}
		$out .= '--' . $boundary . "--\r\n";
		if ( ! headers_sent() ) {
			http_response_code( 200 );
			header( 'expo-protocol-version: ' . $protocol );
			header( 'expo-sfv-version: 0' );
			header( 'Cache-Control: private, max-age=0' );
			header( 'Content-Type: multipart/mixed; boundary=' . $boundary );
			header( 'Content-Length: ' . strlen( $out ) );
		}
		echo $out;
	}

	private function route_asset( string $brand, string $hex, string $ext, bool $head ): void {
		$file = $this->root . '/' . $brand . '/assets/' . $hex;
		if ( ! is_file( $file ) ) {
			throw new HttpError( 'Asset not found', 404 );
		}
		if ( ! headers_sent() ) {
			http_response_code( 200 );
			header( 'Content-Type: ' . ( self::MIME[ $ext ] ?? 'application/octet-stream' ) );
			header( 'Content-Length: ' . filesize( $file ) );
			header( 'Cache-Control: public, max-age=31536000, immutable' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'ETag: "' . $hex . '"' );
		}
		if ( ! $head ) {
			readfile( $file );
		}
	}

	/** Uygulamanın gönderdiği cihaz kodu (flux-device): rastgele UUID, cihazda saklanır. */
	private static function header_device(): string {
		$d = strtolower( trim( (string) ( $_SERVER['HTTP_FLUX_DEVICE'] ?? '' ) ) );
		return preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $d ) ? $d : '';
	}

	/** Uygulamadaki hesaplar (flux-accounts): "site#kullanıcıNo" virgülle, en çok 10. Ad/e-posta yok. */
	private static function header_accounts(): array {
		$out = array();
		foreach ( explode( ',', (string) ( $_SERVER['HTTP_FLUX_ACCOUNTS'] ?? '' ) ) as $a ) {
			$a = self::norm_account( $a );
			if ( '' !== $a ) {
				$out[ $a ] = true;
			}
			if ( count( $out ) >= 10 ) {
				break;
			}
		}
		return array_keys( $out );
	}

	/** "https://Site.com/blog#12" ya da "site.com#12" → "site.com/blog#12"; geçersizse ''. */
	public static function norm_account( string $a ): string {
		$a = strtolower( trim( $a ) );
		$a = (string) preg_replace( '#^https?://#', '', $a );
		if ( ! preg_match( '#^([a-z0-9.-]+(?::\d{1,5})?)((?:/[a-z0-9._~-]+)*)/?\#([1-9][0-9]{0,9})$#D', $a, $m ) ) {
			return '';
		}
		return $m[1] . $m[2] . '#' . $m[3];
	}

	private function is_beta( array $state, string $device, array $accounts ): bool {
		$b = (array) ( $state['beta'] ?? array() );
		if ( '' !== $device && in_array( $device, (array) ( $b['devices'] ?? array() ), true ) ) {
			return true;
		}
		return (bool) array_intersect( $accounts, (array) ( $b['accounts'] ?? array() ) );
	}

	/**
	 * Cihazların sorduğu runtime'lar (yayın kimsenin kullanmadığı runtime'a gidiyorsa uyarır) ve
	 * cihaz kaydı (panelde beta seçimi için). En fazla SEEN_EVERY saniyede bir yazılır.
	 */
	private function mark_seen( string $brand, array $state, string $channel, string $platform, string $runtime, string $device = '', array $accounts = array() ): void {
		$now   = time();
		$last  = (int) ( $state['seen'][ $channel ][ $platform ][ $runtime ] ?? 0 );
		$dev   = '' !== $device ? (array) ( $this->devices( $brand )[ $device ] ?? array() ) : array();
		$name  = mb_substr( trim( (string) preg_replace( '/[^\p{L}\p{N} ._,()+-]/u', '', (string) ( $_SERVER['HTTP_FLUX_DEVICE_NAME'] ?? '' ) ) ), 0, 60 );
		$dirty_dev = '' !== $device && ( ! $dev || $now - (int) ( $dev['last'] ?? 0 ) >= self::SEEN_EVERY || $accounts !== (array) ( $dev['accounts'] ?? array() ) || $runtime !== ( $dev['runtime'] ?? '' ) );
		if ( $now - $last >= self::SEEN_EVERY && is_dir( $this->root . '/' . $brand ) ) {
			// Hiç yayın yapılmamış markada diske yazma (rastgele marka adıyla dosya üretilmesin).
			$this->modify(
				$brand,
				static function ( array $s ) use ( $channel, $platform, $runtime, $now ) {
					$seen = (array) ( $s['seen'][ $channel ][ $platform ] ?? array() );
					if ( ! isset( $seen[ $runtime ] ) && count( $seen ) >= 50 ) {
						asort( $seen );
						$seen = array_slice( $seen, -49, null, true );
					}
					$seen[ $runtime ]                   = $now;
					$s['seen'][ $channel ][ $platform ] = $seen;
					return $s;
				}
			);
		}
		if ( $dirty_dev && is_dir( $this->root . '/' . $brand ) ) {
			$this->modify_devices(
				$brand,
				static function ( array $all ) use ( $device, $platform, $runtime, $channel, $name, $accounts, $now ) {
					$d = (array) ( $all[ $device ] ?? array( 'first' => $now ) );
					$d['platform'] = $platform;
					$d['runtime']  = $runtime;
					$d['channel']  = $channel;
					$d['accounts'] = $accounts;
					$d['last']     = $now;
					if ( '' !== $name ) {
						$d['name'] = $name;
					}
					$all[ $device ] = $d;
					return $all;
				}
			);
		}
	}

	/** Cihaz kayıtları: kod → {platform, name, runtime, channel, accounts, first, last}. */
	public function devices( string $brand ): array {
		$file = $this->root . '/' . $brand . '/devices.json';
		if ( ! is_file( $file ) ) {
			return array();
		}
		$d = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $d ) ? $d : array();
	}

	private function modify_devices( string $brand, callable $fn ): void {
		$file = $this->root . '/' . $brand . '/devices.json';
		$lock = fopen( $file . '.lock', 'c' );
		if ( ! $lock ) {
			return;
		}
		flock( $lock, LOCK_EX );
		try {
			$all = $fn( $this->devices( $brand ) );
			$cut = time() - self::DEVICE_DAYS * 86400;
			$all = array_filter( $all, static function ( $d ) use ( $cut ) { return (int) ( $d['last'] ?? 0 ) >= $cut; } );
			if ( count( $all ) > self::MAX_DEVICES ) {
				uasort( $all, static function ( $a, $b ) { return (int) $a['last'] <=> (int) $b['last']; } );
				$all = array_slice( $all, -self::MAX_DEVICES, null, true );
			}
			$this->write( $file, $all );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Beta                                                                */
	/* ------------------------------------------------------------------ */

	public function beta( string $brand ): array {
		$b = (array) ( $this->state( $brand )['beta'] ?? array() );
		return array( 'devices' => array_values( (array) ( $b['devices'] ?? array() ) ), 'accounts' => array_values( (array) ( $b['accounts'] ?? array() ) ) );
	}

	/** Bir cihazı beta listesine ekler/çıkarır. */
	public function set_beta_device( string $brand, string $device, bool $on ): bool {
		$device = strtolower( trim( $device ) );
		if ( ! self::valid_brand( $brand ) || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $device ) || ! is_dir( $this->root . '/' . $brand ) ) {
			return false;
		}
		$this->modify(
			$brand,
			static function ( array $s ) use ( $device, $on ) {
				$list = array_values( array_diff( (array) ( $s['beta']['devices'] ?? array() ), array( $device ) ) );
				if ( $on ) {
					$list[] = $device;
				}
				$s['beta']['devices'] = array_slice( $list, -500 );
				return $s;
			}
		);
		return true;
	}

	/**
	 * Beta site kullanıcılarını yazar ("site.com#12", satır/virgül). Geçersiz satırlar döner.
	 *
	 * @return array{ok:bool,invalid:array<int,string>,count:int}
	 */
	public function set_beta_accounts( string $brand, string $text ): array {
		if ( ! self::valid_brand( $brand ) || ! is_dir( $this->root . '/' . $brand ) ) {
			return array( 'ok' => false, 'invalid' => array(), 'count' => 0 );
		}
		$ok  = array();
		$bad = array();
		foreach ( preg_split( '/[\s,]+/', $text, -1, PREG_SPLIT_NO_EMPTY ) as $line ) {
			$n = self::norm_account( (string) $line );
			if ( '' === $n ) {
				$bad[] = mb_substr( (string) $line, 0, 80 );
			} else {
				$ok[ $n ] = true;
			}
		}
		$list = array_slice( array_keys( $ok ), 0, 500 );
		$this->modify(
			$brand,
			static function ( array $s ) use ( $list ) {
				$s['beta']['accounts'] = $list;
				return $s;
			}
		);
		return array( 'ok' => true, 'invalid' => $bad, 'count' => count( $list ) );
	}

	/**
	 * Beta'daki güncellemeyi kararlı kanala terfi ettirir: aynı imzalı manifest, yeniden build yok.
	 */
	public function promote( string $brand, string $platform, string $runtime, string $to = 'production' ): bool {
		if ( ! self::valid_brand( $brand ) || ! in_array( $platform, self::PLATFORMS, true ) || ! self::valid_runtime( $runtime ) || ! self::valid_channel( $to ) || self::BETA === $to ) {
			return false;
		}
		$ok      = false;
		$dropped = array();
		$this->modify(
			$brand,
			function ( array $s ) use ( $platform, $runtime, $to, &$ok, &$dropped, $brand ) {
				$id = (string) ( $s['channels'][ self::BETA ][ $platform ][ $runtime ]['active'] ?? '' );
				if ( '' === $id || ! $this->update( $brand, $id ) ) {
					return null;
				}
				$slot = (array) ( $s['channels'][ $to ][ $platform ][ $runtime ] ?? array() );
				$hist = array_values( array_diff( (array) ( $slot['history'] ?? array() ), array( $id ) ) );
				array_unshift( $hist, $id );
				$dropped          = array_slice( $hist, self::KEEP );
				$slot['history']  = array_slice( $hist, 0, self::KEEP );
				$slot['active']   = $id;
				$slot['rollback'] = null;
				$slot['changed']  = time();
				$s['channels'][ $to ][ $platform ][ $runtime ] = $slot;
				$ok = true;
				return $s;
			}
		);
		if ( $dropped ) {
			$this->drop_updates( $brand, $dropped );
		}
		if ( $ok ) {
			$this->log( 'ota', $brand . ' promote ' . $platform . ' ' . $runtime . ' beta→' . $to );
		}
		return $ok;
	}

	public static function valid_brand( string $b ): bool {
		return (bool) preg_match( '/^[a-z0-9][a-z0-9-]{0,39}$/D', $b );
	}

	/* ------------------------------------------------------------------ */
	/* Yayın (CI)                                                          */
	/* ------------------------------------------------------------------ */

	/** OTA anahtarının sha256'sı: panel (ota_key_hash) ya da FLX_UPDATE_OTA_KEY. */
	public function key_hashes(): array {
		$out = array();
		$h   = (string) ( Settings::load( (string) $this->config['data_dir'] )['ota_key_hash'] ?? '' );
		if ( preg_match( '/^[a-f0-9]{64}$/', $h ) ) {
			$out[] = $h;
		}
		$env = (string) ( $this->config['ota_key'] ?? '' );
		if ( strlen( $env ) >= 32 ) {
			$out[] = hash( 'sha256', $env );
		}
		return $out;
	}

	private function auth_publish(): void {
		$key  = (string) ( $_SERVER['HTTP_X_FLUXWP_OTA_KEY'] ?? '' );
		$auth = (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? ( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ) );
		if ( '' === $key && preg_match( '/^Bearer\s+(\S+)$/i', $auth, $m ) ) {
			$key = $m[1];
		}
		$hashes = $this->key_hashes();
		if ( ! $hashes ) {
			throw new HttpError( 'OTA publishing is not set up on this server', 403, 'ota_disabled' );
		}
		$retry = $this->server->auth_throttle( 'ota' );
		if ( $retry > 0 ) {
			throw new HttpError( 'Too many attempts', 429, 'throttled', $retry );
		}
		$given = hash( 'sha256', trim( $key ) );
		foreach ( $hashes as $h ) {
			if ( '' !== trim( $key ) && hash_equals( $h, $given ) ) {
				return;
			}
		}
		$this->server->auth_throttle( 'ota', true );
		throw new HttpError( 'Invalid OTA key', 401, 'invalid_key' );
	}

	private function body(): string {
		if ( $this->input ) {
			return (string) call_user_func( $this->input );
		}
		return (string) file_get_contents( 'php://input' );
	}

	private function body_json(): array {
		$raw = $this->body();
		if ( strlen( $raw ) > 8 * 1024 * 1024 ) {
			throw new HttpError( 'Body too large', 413 );
		}
		$d = json_decode( $raw, true );
		if ( ! is_array( $d ) ) {
			throw new HttpError( 'Expected a JSON body', 400, 'bad_json' );
		}
		return $d;
	}

	private function route_missing( string $brand ): void {
		$d       = $this->body_json();
		$missing = array();
		foreach ( (array) ( $d['hashes'] ?? array() ) as $h ) {
			$h = (string) $h;
			if ( preg_match( '/^[a-f0-9]{64}$/D', $h ) && ! is_file( $this->root . '/' . $brand . '/assets/' . $h ) ) {
				$missing[ $h ] = true;
			}
		}
		$this->json( array( 'ok' => true, 'missing' => array_keys( $missing ) ) );
	}

	private function route_upload( string $brand, string $hex ): void {
		$raw = $this->body();
		if ( '' === $raw || strlen( $raw ) > self::MAX_ASSET ) {
			throw new HttpError( 'Empty or too large asset', 413 );
		}
		if ( ! hash_equals( $hex, hash( 'sha256', $raw ) ) ) {
			throw new HttpError( 'Asset hash mismatch', 400, 'hash_mismatch' );
		}
		$dir = $this->root . '/' . $brand . '/assets';
		$this->mkdir( $dir );
		$file = $dir . '/' . $hex;
		if ( ! is_file( $file ) ) {
			$tmp = $file . '.' . getmypid() . '.tmp';
			if ( false === file_put_contents( $tmp, $raw ) ) {
				throw new HttpError( 'Could not store asset', 500 );
			}
			rename( $tmp, $file );
		}
		$this->json( array( 'ok' => true, 'hash' => $hex, 'size' => strlen( $raw ) ) );
	}

	private function route_publish( string $brand ): void {
		$d       = $this->body_json();
		$channel = (string) ( $d['channel'] ?? '' );
		if ( ! self::valid_channel( $channel ) ) {
			throw new HttpError( 'Invalid channel', 400, 'bad_channel' );
		}
		$list = (array) ( $d['updates'] ?? array() );
		if ( ! $list || count( $list ) > 2 ) {
			throw new HttpError( 'Expected one update per platform', 400, 'bad_updates' );
		}
		$no_update = (array) ( $d['no_update'] ?? array() );
		if ( ! empty( $no_update['body'] ) ) {
			$nb = json_decode( (string) $no_update['body'], true );
			if ( ! is_array( $nb ) || 'noUpdateAvailable' !== ( $nb['type'] ?? '' ) ) {
				throw new HttpError( 'Invalid noUpdateAvailable directive', 400, 'bad_directive' );
			}
			$this->verify( (string) $no_update['body'], (string) ( $no_update['signature'] ?? '' ) );
		}
		$message = mb_substr( trim( (string) ( $d['message'] ?? '' ) ), 0, 200 );
		$commit  = preg_match( '/^[a-f0-9]{7,40}$/', (string) ( $d['commit'] ?? '' ) ) ? (string) $d['commit'] : '';
		$checked = array();
		$seen_platform = array();
		foreach ( $list as $u ) {
			$u        = (array) $u;
			$platform = (string) ( $u['platform'] ?? '' );
			$runtime  = (string) ( $u['runtime'] ?? '' );
			if ( ! in_array( $platform, self::PLATFORMS, true ) || isset( $seen_platform[ $platform ] ) ) {
				throw new HttpError( 'Invalid platform', 400, 'bad_platform' );
			}
			$seen_platform[ $platform ] = true;
			if ( ! self::valid_runtime( $runtime ) ) {
				throw new HttpError( 'Invalid runtime version', 400, 'bad_runtime' );
			}
			$text = (string) ( $u['manifest'] ?? '' );
			$sig  = (string) ( $u['signature'] ?? '' );
			$assets = $this->check_manifest( $brand, $text, $runtime );
			$this->verify( $text, $sig );
			$man       = json_decode( $text, true );
			$checked[] = array(
				'id'         => strtolower( (string) $man['id'] ),
				'platform'   => $platform,
				'runtime'    => $runtime,
				'channel'    => $channel,
				'created_at' => (string) $man['createdAt'],
				'published'  => time(),
				'message'    => $message,
				'commit'     => $commit,
				'manifest'   => $text,
				'signature'  => $sig,
				'assets'     => $assets['hashes'],
				'bytes'      => $assets['bytes'],
			);
		}
		$dir = $this->root . '/' . $brand . '/updates';
		$this->mkdir( $dir );
		foreach ( $checked as $c ) {
			$this->write( $dir . '/' . $c['id'] . '.json', $c );
		}
		$seen   = array();
		$dropped = array();
		$this->modify(
			$brand,
			function ( array $s ) use ( $checked, $channel, $no_update, &$seen, &$dropped ) {
				foreach ( $checked as $c ) {
					$slot = (array) ( $s['channels'][ $channel ][ $c['platform'] ][ $c['runtime'] ] ?? array() );
					$hist = array_values( array_diff( (array) ( $slot['history'] ?? array() ), array( $c['id'] ) ) );
					array_unshift( $hist, $c['id'] );
					$dropped = array_merge( $dropped, array_slice( $hist, self::KEEP ) );
					$slot['history']  = array_slice( $hist, 0, self::KEEP );
					$slot['active']   = $c['id'];
					$slot['rollback'] = null;
					$slot['changed']  = time();
					$s['channels'][ $channel ][ $c['platform'] ][ $c['runtime'] ] = $slot;
					$seen[ $c['platform'] ] = isset( $s['seen'][ $channel ][ $c['platform'] ][ $c['runtime'] ] );
				}
				if ( ! empty( $no_update['body'] ) ) {
					$s['no_update'] = array( 'body' => (string) $no_update['body'], 'signature' => (string) ( $no_update['signature'] ?? '' ) );
				}
				return $s;
			}
		);
		$this->drop_updates( $brand, $dropped );
		$this->log( 'ota', $brand . ' ' . $channel . ' ' . implode( ',', array_map( static function ( $c ) { return $c['platform'] . ':' . $c['id']; }, $checked ) ) );
		$out = array();
		foreach ( $checked as $c ) {
			$out[] = array(
				'platform' => $c['platform'],
				'runtime'  => $c['runtime'],
				'id'       => $c['id'],
				'bytes'    => $c['bytes'],
				'seen'     => ! empty( $seen[ $c['platform'] ] ),
			);
		}
		$this->json( array( 'ok' => true, 'brand' => $brand, 'channel' => $channel, 'updates' => $out ) );
	}

	/**
	 * Manifest metnini denetler: kimlik, runtime ve her varlığın bu sunucudaki
	 * içerik adresli URL'si + sha256'sı eşleşmeli, varlık yüklenmiş olmalı.
	 *
	 * @return array{hashes:array<int,string>,bytes:int}
	 */
	private function check_manifest( string $brand, string $text, string $runtime ): array {
		$m = json_decode( $text, true );
		if ( ! is_array( $m ) || ! isset( $m['launchAsset'] ) || ! is_array( $m['launchAsset'] ) ) {
			throw new HttpError( 'Invalid manifest', 400, 'bad_manifest' );
		}
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', (string) ( $m['id'] ?? '' ) ) ) {
			throw new HttpError( 'Manifest id must be a UUID', 400, 'bad_manifest' );
		}
		if ( $runtime !== ( $m['runtimeVersion'] ?? null ) ) {
			throw new HttpError( 'Manifest runtimeVersion does not match', 400, 'bad_manifest' );
		}
		if ( false === strtotime( (string) ( $m['createdAt'] ?? '' ) ) ) {
			throw new HttpError( 'Manifest createdAt is missing', 400, 'bad_manifest' );
		}
		$base   = $this->server->base_url() . '/v1/expo/' . $brand . '/assets/';
		$assets = array_merge( array( $m['launchAsset'] ), (array) ( $m['assets'] ?? array() ) );
		$hashes = array();
		$bytes  = 0;
		foreach ( $assets as $a ) {
			if ( ! is_array( $a ) ) {
				throw new HttpError( 'Invalid asset', 400, 'bad_manifest' );
			}
			$url = (string) ( $a['url'] ?? '' );
			if ( 0 !== strpos( $url, $base ) || ! preg_match( '#^([a-f0-9]{64})\.[a-z0-9]{1,8}$#D', substr( $url, strlen( $base ) ), $u ) ) {
				throw new HttpError( 'Asset URL must point to this server: ' . $base, 400, 'bad_asset_url' );
			}
			$hex = $u[1];
			if ( self::b64url( (string) hex2bin( $hex ) ) !== (string) ( $a['hash'] ?? '' ) ) {
				throw new HttpError( 'Asset hash does not match its URL', 400, 'bad_asset_hash' );
			}
			$file = $this->root . '/' . $brand . '/assets/' . $hex;
			if ( ! is_file( $file ) ) {
				throw new HttpError( 'Asset not uploaded: ' . $hex, 400, 'missing_asset' );
			}
			if ( ! isset( $hashes[ $hex ] ) ) {
				$hashes[ $hex ] = true;
				$bytes         += (int) filesize( $file );
			}
		}
		return array( 'hashes' => array_keys( $hashes ), 'bytes' => $bytes );
	}

	private function route_rollback( string $brand ): void {
		$d        = $this->body_json();
		$channel  = (string) ( $d['channel'] ?? '' );
		$platform = (string) ( $d['platform'] ?? '' );
		$runtime  = (string) ( $d['runtime'] ?? '' );
		if ( ! self::valid_channel( $channel ) || ! in_array( $platform, self::PLATFORMS, true ) || ! self::valid_runtime( $runtime ) ) {
			throw new HttpError( 'Invalid channel, platform or runtime', 400, 'bad_target' );
		}
		$body = (string) ( $d['directive'] ?? '' );
		$dir  = json_decode( $body, true );
		if ( ! is_array( $dir ) || 'rollBackToEmbedded' !== ( $dir['type'] ?? '' ) || false === strtotime( (string) ( $dir['parameters']['commitTime'] ?? '' ) ) ) {
			throw new HttpError( 'Invalid rollBackToEmbedded directive', 400, 'bad_directive' );
		}
		$sig = (string) ( $d['signature'] ?? '' );
		$this->verify( $body, $sig );
		$this->modify(
			$brand,
			static function ( array $s ) use ( $channel, $platform, $runtime, $body, $sig ) {
				$slot             = (array) ( $s['channels'][ $channel ][ $platform ][ $runtime ] ?? array() );
				$slot['active']   = '';
				$slot['rollback'] = array( 'body' => $body, 'signature' => $sig, 'at' => time() );
				$slot['changed']  = time();
				$s['channels'][ $channel ][ $platform ][ $runtime ] = $slot;
				return $s;
			}
		);
		$this->log( 'ota', $brand . ' ' . $channel . ' ' . $platform . ' rollback ' . $runtime );
		$this->json( array( 'ok' => true ) );
	}

	/** Paneldeki sertifika kayıtlıysa imzayı doğrular; değilse yalnız biçime bakar. */
	private function verify( string $body, string $signature ): void {
		$cert = (string) ( Settings::load( (string) $this->config['data_dir'] )['ota_cert'] ?? '' );
		if ( '' === $cert ) {
			$cert = (string) ( $this->config['ota_cert'] ?? '' );
		}
		if ( '' === $cert ) {
			return;
		}
		if ( ! preg_match( '/(?:^|,)\s*sig="([A-Za-z0-9+\/=]+)"/', $signature, $m ) ) {
			throw new HttpError( 'Missing expo-signature (sig="…")', 400, 'unsigned' );
		}
		if ( ! function_exists( 'openssl_verify' ) ) {
			return;
		}
		$ok = openssl_verify( $body, (string) base64_decode( $m[1], true ), $cert, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $ok ) {
			throw new HttpError( 'Signature does not match the OTA certificate on this server', 400, 'bad_signature' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Panel                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Yönetici API'si (GET /v1/admin/ota): yayın anahtarı ve sertifika durumu + markalar.
	 * Anahtarın kendisi ya da sha256'sı dönmez; sertifika açık bilgidir.
	 */
	public function admin_info(): array {
		$set     = Settings::load( (string) $this->config['data_dir'] );
		$panel_c = (string) ( $set['ota_cert'] ?? '' );
		$pem     = '' !== $panel_c ? $panel_c : (string) ( $this->config['ota_cert'] ?? '' );
		$info    = '' !== $pem && function_exists( 'openssl_x509_parse' ) ? @openssl_x509_parse( $pem ) : false;
		$has_env = strlen( (string) ( $this->config['ota_key'] ?? '' ) ) >= 32;
		return array(
			'manifest_url' => $this->server->base_url() . '/v1/expo/',
			'key'          => array(
				'set'     => (bool) $this->key_hashes(),
				'hint'    => (string) ( $set['ota_key_hint'] ?? '' ),
				'created' => (int) ( $set['ota_key_created'] ?? 0 ),
				'env'     => $has_env && empty( $set['ota_key_hash'] ),
			),
			'cert'         => array(
				'set'      => '' !== $pem,
				'pem'      => $pem,
				'cn'       => is_array( $info ) ? (string) ( $info['subject']['CN'] ?? '' ) : '',
				'valid_to' => is_array( $info ) ? (int) ( $info['validTo_time_t'] ?? 0 ) : 0,
				'env'      => '' === $panel_c && '' !== $pem,
			),
			'brands'       => $this->overview(),
		);
	}

	/** Panel/yönetici API'si için özet: marka → kanal → platform → runtime. */
	public function overview(): array {
		$out = array();
		if ( ! is_dir( $this->root ) ) {
			return $out;
		}
		foreach ( (array) scandir( $this->root ) as $brand ) {
			if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,39}$/D', (string) $brand ) ) {
				continue;
			}
			$state = $this->state( $brand );
			$rows  = array();
			foreach ( (array) ( $state['channels'] ?? array() ) as $channel => $plats ) {
				foreach ( (array) $plats as $platform => $runtimes ) {
					foreach ( (array) $runtimes as $runtime => $slot ) {
						$hist = array();
						foreach ( (array) ( $slot['history'] ?? array() ) as $id ) {
							$u = $this->update( $brand, (string) $id );
							if ( $u ) {
								$hist[] = array(
									'id'        => $u['id'],
									'published' => (int) ( $u['published'] ?? 0 ),
									'message'   => (string) ( $u['message'] ?? '' ),
									'commit'    => (string) ( $u['commit'] ?? '' ),
									'bytes'     => (int) ( $u['bytes'] ?? 0 ),
								);
							}
						}
						$rows[] = array(
							'channel'  => (string) $channel,
							'platform' => (string) $platform,
							'runtime'  => (string) $runtime,
							'active'   => (string) ( $slot['active'] ?? '' ),
							'rollback' => ! empty( $slot['rollback'] ),
							'changed'  => (int) ( $slot['changed'] ?? 0 ),
							'seen'     => (int) ( $state['seen'][ $channel ][ $platform ][ $runtime ] ?? 0 ),
							'history'  => $hist,
						);
					}
				}
			}
			$unpublished = array();
			foreach ( (array) ( $state['seen'] ?? array() ) as $channel => $plats ) {
				foreach ( (array) $plats as $platform => $runtimes ) {
					foreach ( (array) $runtimes as $runtime => $ts ) {
						if ( ! isset( $state['channels'][ $channel ][ $platform ][ $runtime ] ) ) {
							$unpublished[] = array( 'channel' => (string) $channel, 'platform' => (string) $platform, 'runtime' => (string) $runtime, 'seen' => (int) $ts );
						}
					}
				}
			}
			$devices = $this->devices( $brand );
			uasort( $devices, static function ( $a, $b ) { return (int) ( $b['last'] ?? 0 ) <=> (int) ( $a['last'] ?? 0 ); } );
			$beta = $this->beta( $brand );
			$list = array();
			foreach ( $devices as $id => $d ) {
				$list[] = array(
					'id'       => (string) $id,
					'name'     => (string) ( $d['name'] ?? '' ),
					'platform' => (string) ( $d['platform'] ?? '' ),
					'runtime'  => (string) ( $d['runtime'] ?? '' ),
					'accounts' => array_values( (array) ( $d['accounts'] ?? array() ) ),
					'first'    => (int) ( $d['first'] ?? 0 ),
					'last'     => (int) ( $d['last'] ?? 0 ),
					'beta'     => in_array( (string) $id, $beta['devices'], true ) || (bool) array_intersect( (array) ( $d['accounts'] ?? array() ), $beta['accounts'] ),
				);
			}
			$out[ $brand ] = array( 'rows' => $rows, 'seen_only' => $unpublished, 'disk' => $this->disk( $brand ), 'devices' => $list, 'beta' => $beta );
		}
		return $out;
	}

	/**
	 * Paneldeki "Etkinleştir / Durdur": geçmişteki imzalı bir güncellemeyi yeniden sunar
	 * ya da ('' ile) bu runtime'a güncelleme sunmayı durdurur. Yeni imza gerekmez.
	 */
	public function activate( string $brand, string $channel, string $platform, string $runtime, string $id ): bool {
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,39}$/D', $brand ) || ! self::valid_channel( $channel ) || ! in_array( $platform, self::PLATFORMS, true ) || ! self::valid_runtime( $runtime ) ) {
			return false;
		}
		$id = strtolower( $id );
		$ok = false;
		$this->modify(
			$brand,
			static function ( array $s ) use ( $channel, $platform, $runtime, $id, &$ok ) {
				if ( ! isset( $s['channels'][ $channel ][ $platform ][ $runtime ] ) ) {
					return null;
				}
				$slot = (array) $s['channels'][ $channel ][ $platform ][ $runtime ];
				if ( '' !== $id && ! in_array( $id, (array) ( $slot['history'] ?? array() ), true ) ) {
					return null;
				}
				$slot['active']   = $id;
				$slot['rollback'] = null;
				$slot['changed']  = time();
				$s['channels'][ $channel ][ $platform ][ $runtime ] = $slot;
				$ok = true;
				return $s;
			}
		);
		return $ok;
	}

	/* ------------------------------------------------------------------ */
	/* Depolama                                                            */
	/* ------------------------------------------------------------------ */

	private function state( string $brand ): array {
		$file = $this->root . '/' . $brand . '/state.json';
		if ( ! is_file( $file ) ) {
			return array();
		}
		$d = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $d ) ? $d : array();
	}

	/** state.json'u kilit altında değiştirir; geri çağrı null dönerse yazmaz. */
	private function modify( string $brand, callable $fn ): void {
		$dir = $this->root . '/' . $brand;
		$this->mkdir( $dir );
		$file = $dir . '/state.json';
		$lock = fopen( $file . '.lock', 'c' );
		flock( $lock, LOCK_EX );
		try {
			$next = $fn( $this->state( $brand ) );
			if ( is_array( $next ) ) {
				$this->write( $file, $next );
			}
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	private function update( string $brand, string $id ): ?array {
		if ( ! preg_match( '/^[0-9a-f-]{36}$/D', $id ) ) {
			return null;
		}
		$file = $this->root . '/' . $brand . '/updates/' . $id . '.json';
		if ( ! is_file( $file ) ) {
			return null;
		}
		$d = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $d ) && ! empty( $d['manifest'] ) ? $d : null;
	}

	/** Geçmişten düşen güncellemeleri ve artık hiçbir güncellemenin kullanmadığı varlıkları siler. */
	private function drop_updates( string $brand, array $ids ): void {
		$state = $this->state( $brand );
		$keep  = array();
		foreach ( (array) ( $state['channels'] ?? array() ) as $plats ) {
			foreach ( (array) $plats as $runtimes ) {
				foreach ( (array) $runtimes as $slot ) {
					foreach ( (array) ( $slot['history'] ?? array() ) as $id ) {
						$keep[ (string) $id ] = true;
					}
				}
			}
		}
		$dir = $this->root . '/' . $brand . '/updates';
		foreach ( array_unique( $ids ) as $id ) {
			if ( ! isset( $keep[ $id ] ) && preg_match( '/^[0-9a-f-]{36}$/D', (string) $id ) ) {
				@unlink( $dir . '/' . $id . '.json' );
			}
		}
		if ( ! $ids ) {
			return;
		}
		$used = array();
		foreach ( (array) glob( $dir . '/*.json' ) as $f ) {
			$u = json_decode( (string) file_get_contents( $f ), true );
			foreach ( (array) ( $u['assets'] ?? array() ) as $h ) {
				$used[ (string) $h ] = true;
			}
		}
		$adir = $this->root . '/' . $brand . '/assets';
		foreach ( (array) glob( $adir . '/*' ) as $f ) {
			$h = basename( (string) $f );
			// Bir saatten yeni varlıklar yayın ortasında olabilir: dokunma.
			if ( preg_match( '/^[a-f0-9]{64}$/D', $h ) && ! isset( $used[ $h ] ) && filemtime( (string) $f ) < time() - 3600 ) {
				@unlink( (string) $f );
			}
		}
	}

	/** Marka başına disk kullanımı. Panel her açılışta klasörü gezmesin diye 10 dakika önbellekte (disk.json). */
	private function disk( string $brand ): int {
		$cache = $this->root . '/' . $brand . '/disk.json';
		$c     = is_file( $cache ) ? json_decode( (string) file_get_contents( $cache ), true ) : null;
		if ( is_array( $c ) && isset( $c['at'], $c['bytes'] ) && time() - (int) $c['at'] < 600 ) {
			return (int) $c['bytes'];
		}
		$n = 0;
		foreach ( array( 'assets', 'updates' ) as $sub ) {
			foreach ( (array) glob( $this->root . '/' . $brand . '/' . $sub . '/*', GLOB_NOSORT ) as $f ) {
				$n += (int) @filesize( (string) $f );
			}
		}
		$tmp = $cache . '.' . getmypid() . '.tmp';
		if ( false !== @file_put_contents( $tmp, json_encode( array( 'at' => time(), 'bytes' => $n ) ) ) ) {
			@rename( $tmp, $cache );
		}
		return $n;
	}

	private function mkdir( string $dir ): void {
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0750, true ) && ! is_dir( $dir ) ) {
			throw new HttpError( 'data/ is not writable', 500 );
		}
	}

	private function write( string $file, array $data ): void {
		$tmp = $file . '.' . getmypid() . '.tmp';
		file_put_contents( $tmp, json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		rename( $tmp, $file );
	}

	private function json( array $data, int $code = 200 ): void {
		if ( ! headers_sent() ) {
			http_response_code( $code );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Cache-Control: no-store' );
		}
		echo json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private function log( string $type, string $msg ): void {
		$dir = rtrim( (string) $this->config['data_dir'], '/' );
		if ( is_dir( $dir ) ) {
			file_put_contents( $dir . '/access.log', gmdate( 'c' ) . "\t" . $type . "\t" . str_replace( array( "\n", "\r", "\t" ), ' ', $msg ) . "\n", FILE_APPEND | LOCK_EX );
		}
	}

	public static function b64url( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	public static function valid_runtime( string $r ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9._+-]{0,127}$/D', $r );
	}

	public static function valid_channel( string $c ): bool {
		return (bool) preg_match( '/^[a-z0-9][a-z0-9_-]{0,39}$/D', $c );
	}
}
