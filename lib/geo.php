<?php
/**
 * İsteğin ülkesi: IP saklanmadan, istek anında ülke koduna çevrilir.
 *
 * Kaynak sırası:
 *   1. Cloudflare CF-IPCountry (yalnız istek gerçekten Cloudflare'den geliyorsa; başlık sahte olabilir)
 *   2. Yerel ülke veritabanı data/geo/country.mmdb (DB-IP IP to Country Lite, CC BY 4.0;
 *      MaxMind GeoLite2-Country .mmdb de okunur). IP hiçbir harici servise gönderilmez.
 *
 * Veritabanı panelden indirilir/güncellenir (ayda bir kendiliğinden) ya da elle yüklenir.
 */

namespace FLXUpdate;

final class Geo {

	/** Veritabanı eskiyince (gün) kendiliğinden yenilenir. */
	const MAX_AGE_DAYS = 35;

	/** Cloudflare IP aralıkları (https://www.cloudflare.com/ips/). */
	const CF_RANGES = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
		'108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
		'162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
		'2a06:98c0::/29', '2c0f:f248::/32',
	);

	/** @var string */
	private $dir;

	/** @var array|null İstek başına bir kez bulunur. */
	private static $current = null;

	public function __construct( string $data_dir ) {
		$this->dir = rtrim( $data_dir, '/' ) . '/geo';
	}

	public function db_file(): string {
		return $this->dir . '/country.mmdb';
	}

	private function meta_file(): string {
		return $this->dir . '/geo.json';
	}

	/** Veritabanı bilgisi: {ok, size, at, built, source, type, error, tried}. */
	public function status(): array {
		$m  = is_file( $this->meta_file() ) ? json_decode( (string) file_get_contents( $this->meta_file() ), true ) : array();
		$m  = is_array( $m ) ? $m : array();
		$ok = is_file( $this->db_file() );
		$out = array(
			'ok'     => $ok,
			'size'   => $ok ? (int) filesize( $this->db_file() ) : 0,
			'at'     => (int) ( $m['at'] ?? ( $ok ? filemtime( $this->db_file() ) : 0 ) ),
			'built'  => (int) ( $m['built'] ?? 0 ),
			'source' => (string) ( $m['source'] ?? '' ),
			'type'   => (string) ( $m['type'] ?? '' ),
			'error'  => (string) ( $m['error'] ?? '' ),
			'tried'  => (int) ( $m['tried'] ?? 0 ),
		);
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* İsteğin ülkesi                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Bu isteğin ülkesi. IP yalnız bellekte kullanılır, hiçbir yere yazılmaz.
	 *
	 * @return array{cc:string,src:string} src: cf | db | ''
	 */
	public function request_country(): array {
		if ( null !== self::$current ) {
			return self::$current;
		}
		$remote = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$ip     = $remote;
		if ( '' !== $remote && self::is_cloudflare( $remote ) ) {
			$cf = strtoupper( (string) ( $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '' ) );
			if ( preg_match( '/^[A-Z]{2}$/', $cf ) && 'XX' !== $cf && 'T1' !== $cf ) {
				return self::$current = array( 'cc' => $cf, 'src' => 'cf' );
			}
			$real = (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '' );
			if ( filter_var( $real, FILTER_VALIDATE_IP ) ) {
				$ip = $real;
			}
		}
		$cc = '' !== $ip ? $this->lookup( $ip ) : '';
		return self::$current = array( 'cc' => $cc, 'src' => '' !== $cc ? 'db' : '' );
	}

	/** Testler ve panel için önbelleği boşaltır. */
	public static function reset(): void {
		self::$current = null;
	}

	public static function is_cloudflare( string $ip ): bool {
		foreach ( self::CF_RANGES as $cidr ) {
			if ( self::in_cidr( $ip, $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	public static function in_cidr( string $ip, string $cidr ): bool {
		list( $net, $bits ) = explode( '/', $cidr );
		$a = @inet_pton( $ip );
		$b = @inet_pton( $net );
		if ( false === $a || false === $b || strlen( $a ) !== strlen( $b ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		if ( substr( $a, 0, $bytes ) !== substr( $b, 0, $bytes ) ) {
			return false;
		}
		$rem = $bits % 8;
		if ( ! $rem ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rem ) ) & 0xFF;
		return ( ord( $a[ $bytes ] ) & $mask ) === ( ord( $b[ $bytes ] ) & $mask );
	}

	/** IP → ülke kodu ('' bulunamazsa ya da veritabanı yoksa). */
	public function lookup( string $ip ): string {
		if ( ! is_file( $this->db_file() ) || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		try {
			$rec = ( new Mmdb( $this->db_file() ) )->get( $ip );
		} catch ( \Throwable $e ) {
			return '';
		}
		if ( ! is_array( $rec ) ) {
			return '';
		}
		$cc = (string) ( $rec['country_code'] ?? ( $rec['country']['iso_code'] ?? ( $rec['registered_country']['iso_code'] ?? '' ) ) );
		$cc = strtoupper( $cc );
		return preg_match( '/^[A-Z]{2}$/', $cc ) && 'ZZ' !== $cc ? $cc : '';
	}

	/* ------------------------------------------------------------------ */
	/* Veritabanını indirme / yükleme                                      */
	/* ------------------------------------------------------------------ */

	/** Kaynaklar sırayla denenir. Hepsi DB-IP Lite (CC BY 4.0). */
	public static function sources(): array {
		$now  = time();
		$this_m = gmdate( 'Y-m', $now );
		$prev_m = gmdate( 'Y-m', strtotime( gmdate( 'Y-m-01', $now ) . ' -1 month' ) );
		return array(
			array( 'db-ip.com', 'https://download.db-ip.com/free/dbip-country-lite-' . $this_m . '.mmdb.gz', 'gz' ),
			array( 'db-ip.com', 'https://download.db-ip.com/free/dbip-country-lite-' . $prev_m . '.mmdb.gz', 'gz' ),
			array( 'jsDelivr (ip-location-db)', 'https://cdn.jsdelivr.net/npm/@ip-location-db/dbip-country-mmdb/dbip-country.mmdb', 'mmdb' ),
			array( 'npm (ip-location-db)', 'npm:@ip-location-db/dbip-country-mmdb', 'npm' ),
		);
	}

	/** Eskiyse (ya da hiç yoksa) günde en çok bir kez yenilemeyi dener. */
	public function needs_update(): bool {
		$s = $this->status();
		if ( time() - $s['tried'] < DAY_SECONDS ) {
			return false;
		}
		return ! $s['ok'] || time() - $s['at'] > self::MAX_AGE_DAYS * DAY_SECONDS;
	}

	/**
	 * Veritabanını indirir. Başarılıysa kaynağın adını, değilse hata fırlatır.
	 *
	 * @param callable|null $http fn(string $url): string (testler için)
	 */
	public function update( ?callable $http = null ): string {
		$http   = $http ?: array( __CLASS__, 'http_get' );
		$errors = array();
		$this->save_meta( array( 'tried' => time() ) );
		foreach ( self::sources() as $src ) {
			list( $name, $url, $kind ) = $src;
			try {
				if ( 'npm' === $kind ) {
					$data = self::from_npm( substr( $url, 4 ), $http );
				} else {
					$data = (string) $http( $url );
					if ( 'gz' === $kind ) {
						$data = (string) @gzdecode( $data );
					}
				}
				$this->install( $data, $name );
				return $name;
			} catch ( \Throwable $e ) {
				$errors[] = $name . ': ' . $e->getMessage();
			}
		}
		$msg = implode( ' · ', $errors );
		$this->save_meta( array( 'error' => mb_substr( $msg, 0, 500 ) ) );
		throw new \RuntimeException( $msg );
	}

	/** Elle yüklenen .mmdb (ya da .mmdb.gz). */
	public function upload( string $tmp_file, string $name ): void {
		$data = (string) file_get_contents( $tmp_file );
		if ( "\x1f\x8b" === substr( $data, 0, 2 ) ) {
			$data = (string) @gzdecode( $data );
		}
		$this->install( $data, 'upload: ' . mb_substr( basename( $name ), 0, 80 ) );
	}

	public function delete(): void {
		@unlink( $this->db_file() );
		@unlink( $this->meta_file() );
	}

	/** Doğrular ve yerine koyar. */
	private function install( string $data, string $source ): void {
		if ( strlen( $data ) < 1024 ) {
			throw new \RuntimeException( 'empty or invalid file' );
		}
		if ( ! is_dir( $this->dir ) && ! @mkdir( $this->dir, 0750, true ) && ! is_dir( $this->dir ) ) {
			throw new \RuntimeException( 'cannot create ' . $this->dir );
		}
		$tmp = $this->dir . '/country.mmdb.' . getmypid() . '.tmp';
		if ( false === file_put_contents( $tmp, $data ) ) {
			throw new \RuntimeException( 'cannot write ' . $tmp );
		}
		try {
			$db   = new Mmdb( $tmp );
			$meta = $db->metadata();
			// 8.8.8.8 (US) ile çalıştığını doğrula.
			$rec = $db->get( '8.8.8.8' );
			$cc  = is_array( $rec ) ? (string) ( $rec['country_code'] ?? ( $rec['country']['iso_code'] ?? '' ) ) : '';
			if ( 'US' !== strtoupper( $cc ) ) {
				throw new \RuntimeException( 'not a country database' );
			}
		} catch ( \Throwable $e ) {
			@unlink( $tmp );
			throw new \RuntimeException( 'invalid mmdb: ' . $e->getMessage() );
		}
		rename( $tmp, $this->db_file() );
		$this->save_meta(
			array(
				'at'     => time(),
				'built'  => (int) ( $meta['build_epoch'] ?? 0 ),
				'type'   => mb_substr( (string) ( $meta['database_type'] ?? '' ), 0, 60 ),
				'source' => $source,
				'error'  => null,
			)
		);
	}

	private function save_meta( array $changes ): void {
		if ( ! is_dir( $this->dir ) ) {
			@mkdir( $this->dir, 0750, true );
		}
		$m = is_file( $this->meta_file() ) ? json_decode( (string) file_get_contents( $this->meta_file() ), true ) : array();
		$m = is_array( $m ) ? $m : array();
		foreach ( $changes as $k => $v ) {
			if ( null === $v ) {
				unset( $m[ $k ] );
			} else {
				$m[ $k ] = $v;
			}
		}
		@file_put_contents( $this->meta_file(), json_encode( $m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/** npm paketinin son sürümünün tarball'ından dbip-country.mmdb'yi çıkarır. */
	private static function from_npm( string $pkg, callable $http ): string {
		$meta = json_decode( (string) $http( 'https://registry.npmjs.org/' . str_replace( '/', '%2F', $pkg ) . '/latest' ), true );
		$tgz  = is_array( $meta ) ? (string) ( $meta['dist']['tarball'] ?? '' ) : '';
		if ( ! preg_match( '#^https://registry\.npmjs\.org/#', $tgz ) ) {
			throw new \RuntimeException( 'no tarball' );
		}
		$tar = (string) @gzdecode( (string) $http( $tgz ) );
		$out = self::tar_file( $tar, 'package/dbip-country.mmdb' );
		if ( '' === $out ) {
			throw new \RuntimeException( 'mmdb not in package' );
		}
		return $out;
	}

	/** Düz tar içinden tek dosya. */
	public static function tar_file( string $tar, string $want ): string {
		$pos = 0;
		$len = strlen( $tar );
		while ( $pos + 512 <= $len ) {
			$h    = substr( $tar, $pos, 512 );
			$name = rtrim( substr( $h, 0, 100 ), "\0" );
			if ( '' === $name ) {
				break;
			}
			$prefix = rtrim( substr( $h, 345, 155 ), "\0" );
			if ( '' !== $prefix && 'ustar' === substr( $h, 257, 5 ) ) {
				$name = $prefix . '/' . $name;
			}
			$size = (int) octdec( trim( substr( $h, 124, 12 ), "\0 " ) );
			if ( $name === $want ) {
				return substr( $tar, $pos + 512, $size );
			}
			$pos += 512 + (int) ceil( $size / 512 ) * 512;
		}
		return '';
	}

	public static function http_get( string $url ): string {
		if ( ! function_exists( 'curl_init' ) ) {
			$ctx = stream_context_create( array( 'http' => array( 'timeout' => 40, 'header' => "User-Agent: flx-update-server\r\n" ) ) );
			$d   = @file_get_contents( $url, false, $ctx );
			if ( false === $d ) {
				throw new \RuntimeException( 'download failed' );
			}
			return $d;
		}
		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT        => 45,
				CURLOPT_USERAGENT      => 'flx-update-server/' . VERSION,
			)
		);
		$body = curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$err  = curl_error( $ch );
		curl_close( $ch );
		if ( false === $body || $code >= 400 ) {
			throw new \RuntimeException( $err ? $err : 'HTTP ' . $code );
		}
		return (string) $body;
	}
}

/**
 * En küçük MaxMind DB (.mmdb) okuyucusu: yalnız arama ve veri çözme. Bağımlılık yok.
 * Biçim: https://maxmind.github.io/MaxMind-DB/
 */
final class Mmdb {

	const MARKER = "\xAB\xCD\xEFMaxMind.com";

	/** @var resource */
	private $fh;

	/** @var array */
	private $meta;

	/** @var int */
	private $nodes;

	/** @var int */
	private $rsize;

	/** @var int */
	private $tree_size;

	/** @var int */
	private $data_start;

	/** @var int|null */
	private $v4_start = null;

	public function __construct( string $file ) {
		$fh = @fopen( $file, 'rb' );
		if ( ! $fh ) {
			throw new \RuntimeException( 'cannot open' );
		}
		$this->fh = $fh;
		$size     = (int) filesize( $file );
		$tail_len = min( $size, 128 * 1024 );
		fseek( $fh, $size - $tail_len );
		$tail = (string) fread( $fh, $tail_len );
		$p    = strrpos( $tail, self::MARKER );
		if ( false === $p ) {
			throw new \RuntimeException( 'no metadata' );
		}
		$meta_off   = $size - $tail_len + $p + strlen( self::MARKER );
		$this->data_start = 0; // Metadata işaretçileri kendi bölümüne göre.
		list( $meta )     = $this->decode( $meta_off, $meta_off );
		if ( ! is_array( $meta ) || empty( $meta['node_count'] ) || empty( $meta['record_size'] ) ) {
			throw new \RuntimeException( 'bad metadata' );
		}
		$this->meta       = $meta;
		$this->nodes      = (int) $meta['node_count'];
		$this->rsize      = (int) $meta['record_size'];
		if ( ! in_array( $this->rsize, array( 24, 28, 32 ), true ) ) {
			throw new \RuntimeException( 'record size' );
		}
		$this->tree_size  = $this->nodes * $this->rsize / 4;
		$this->data_start = $this->tree_size + 16;
	}

	public function __destruct() {
		if ( is_resource( $this->fh ) ) {
			fclose( $this->fh );
		}
	}

	public function metadata(): array {
		return $this->meta;
	}

	/** @return mixed|null */
	public function get( string $ip ) {
		$packed = @inet_pton( $ip );
		if ( false === $packed ) {
			return null;
		}
		$bits = strlen( $packed ) * 8;
		$node = 0;
		if ( 128 === $bits && 4 === (int) ( $this->meta['ip_version'] ?? 4 ) ) {
			return null;
		}
		if ( 32 === $bits && 6 === (int) ( $this->meta['ip_version'] ?? 4 ) ) {
			$node = $this->v4_start();
		}
		for ( $i = 0; $i < $bits && $node < $this->nodes; $i++ ) {
			$bit  = ( ord( $packed[ $i >> 3 ] ) >> ( 7 - ( $i & 7 ) ) ) & 1;
			$node = $this->record( $node, $bit );
		}
		if ( $node === $this->nodes ) {
			return null;
		}
		if ( $node < $this->nodes ) {
			return null;
		}
		$off = $node - $this->nodes - 16 + $this->tree_size + 16;
		list( $val ) = $this->decode( $off, $this->data_start );
		return $val;
	}

	private function v4_start(): int {
		if ( null === $this->v4_start ) {
			$node = 0;
			for ( $i = 0; $i < 96 && $node < $this->nodes; $i++ ) {
				$node = $this->record( $node, 0 );
			}
			$this->v4_start = $node;
		}
		return $this->v4_start;
	}

	private function read( int $off, int $len ): string {
		if ( $len <= 0 ) {
			return '';
		}
		fseek( $this->fh, $off );
		$s = (string) fread( $this->fh, $len );
		if ( strlen( $s ) !== $len ) {
			throw new \RuntimeException( 'unexpected end of file' );
		}
		return $s;
	}

	private function record( int $node, int $bit ): int {
		$base = $node * $this->rsize / 4;
		switch ( $this->rsize ) {
			case 24:
				$b = $this->read( (int) $base + $bit * 3, 3 );
				return unpack( 'N', "\0" . $b )[1];
			case 28:
				$b = $this->read( (int) $base, 7 );
				if ( 0 === $bit ) {
					return ( ( ord( $b[3] ) & 0xF0 ) << 20 ) | unpack( 'N', "\0" . substr( $b, 0, 3 ) )[1];
				}
				return ( ( ord( $b[3] ) & 0x0F ) << 24 ) | unpack( 'N', "\0" . substr( $b, 4, 3 ) )[1];
			default:
				return unpack( 'N', $this->read( (int) $base + $bit * 4, 4 ) )[1];
		}
	}

	/**
	 * @param int $off   Dosyadaki konum.
	 * @param int $base  İşaretçilerin göreli olduğu bölüm başı.
	 * @return array{0:mixed,1:int} değer, sonraki konum
	 */
	private function decode( int $off, int $base ): array {
		$ctrl = ord( $this->read( $off, 1 ) );
		++$off;
		$type = $ctrl >> 5;
		if ( 1 === $type ) { // pointer
			$ss  = ( $ctrl >> 3 ) & 0x3;
			$vvv = $ctrl & 0x7;
			$b   = $this->read( $off, $ss + 1 );
			$off += $ss + 1;
			switch ( $ss ) {
				case 0:
					$p = ( $vvv << 8 ) | ord( $b[0] );
					break;
				case 1:
					$p = ( ( $vvv << 16 ) | unpack( 'n', $b )[1] ) + 2048;
					break;
				case 2:
					$p = ( ( $vvv << 24 ) | unpack( 'N', "\0" . $b )[1] ) + 526336;
					break;
				default:
					$p = unpack( 'N', $b )[1];
			}
			list( $val ) = $this->decode( $base + $p, $base );
			return array( $val, $off );
		}
		if ( 0 === $type ) { // extended
			$type = 7 + ord( $this->read( $off, 1 ) );
			++$off;
		}
		$size = $ctrl & 0x1F;
		if ( $size >= 29 ) {
			$n    = $size - 28;
			$b    = $this->read( $off, $n );
			$off += $n;
			if ( 29 === $size ) {
				$size = 29 + ord( $b[0] );
			} elseif ( 30 === $size ) {
				$size = 285 + unpack( 'n', $b )[1];
			} else {
				$size = 65821 + unpack( 'N', "\0" . $b )[1];
			}
		}
		switch ( $type ) {
			case 2: // utf8
			case 4: // bytes
				return array( $this->read( $off, $size ), $off + $size );
			case 3: // double
				return array( unpack( 'E', $this->read( $off, 8 ) )[1], $off + 8 );
			case 15: // float
				return array( unpack( 'G', $this->read( $off, 4 ) )[1], $off + 4 );
			case 5: // uint16
			case 6: // uint32
			case 8: // int32
			case 9: // uint64
			case 10: // uint128
				$v = 0;
				foreach ( str_split( $this->read( $off, $size ) ?: '', 1 ) as $ch ) {
					$v = $v * 256 + ord( $ch );
				}
				if ( 8 === $type && $size > 0 && $v >= 2147483648 ) {
					$v -= 4294967296;
				}
				return array( $v, $off + $size );
			case 7: // map
				$m = array();
				for ( $i = 0; $i < $size; $i++ ) {
					list( $k, $off ) = $this->decode( $off, $base );
					list( $v, $off ) = $this->decode( $off, $base );
					$m[ (string) $k ] = $v;
				}
				return array( $m, $off );
			case 11: // array
				$a = array();
				for ( $i = 0; $i < $size; $i++ ) {
					list( $a[], $off ) = $this->decode( $off, $base );
				}
				return array( $a, $off );
			case 14: // boolean
				return array( 0 !== $size, $off );
			default:
				throw new \RuntimeException( 'unsupported type ' . $type );
		}
	}
}
