<?php
/**
 * Kullanım istatistikleri: hangi siteler (anahtarlı/anahtarsız) güncelleme denetliyor,
 * hangi paketleri indiriyor, hangi ülkeden. IP saklanmaz: istek anında ülke koduna çevrilir
 * (Geo: Cloudflare CF-IPCountry ya da yerel ülke veritabanı) ve yalnız ülke kodu tutulur.
 * Ülke bulunamazsa ve site kullanım verisine izin verdiyse WordPress saat diliminden bulunur.
 *
 * data/stats.json:
 *   jseq    => günlüğe katlanmış son sıra numarası (yoksa 0; eski dosyalar da okunur)
 *   days    => { "Y-m-d": { c: kontrol, a: anahtarsız kontrol, d: indirme, b: bayt, s: {site_id: 1}, p: {slug: indirme},
 *                g: { CC: { c: kontrol, d: indirme, b: bayt, x: engellenen, p: {slug: indirme} } } } }
 *   clients => { site_id: { url, name, key, off, ih, pfx, cc, wp, php, upd, use, pk: {slug: sürüm}, first, last, n } }
 * data/stats.journal: her olay bir satır ({"seq","ts","op",...}). İstek stats.json'u baştan yazmaz.
 * Katlama, sıra numarası jseq'ten büyük satırları uygular; yeniden adlandırma ile kesme arasında
 * kalan günlük ikinci kez sayılmaz. data/stats.seq son verilen sıra numarasıdır.
 *   use: site kullanım verisi göndermeye izin verdi mi (vermediyse wp, php boş). url ve cc zorunlu kayıttır,
 *   izinden bağımsız tutulur (zorunlu çerez gibi): url yalnız şema + host (+ alt dizin), sorgu yok.
 *   Ülkesi bilinmeyen istek g altında "ZZ" ile sayılır.
 *   ih: kurulum kimliğinin sha256 özeti (panelden anahtar vermek için). Kayıt bu özete bağlıdır, adrese değil.
 *   pfx: kurulum kimliğinin ilk 8 karakteri (panelde satırları ayırt etmek için). Kimliğin kendisi saklanmaz.
 */

namespace FLXUpdate;

final class Stats {

	const KEEP_DAYS    = 120;
	const CLIENT_DAYS  = 180;
	const MAX_CLIENTS  = 5000;
	/** Anahtarsız kayıt tavanı. Aşanlar en eski son-görülmeden düşer; anahtarlı kayıtlar bu tavandan etkilenmez. */
	const MAX_KEYLESS  = 1000;
	const INSTALL_PREFIX = 8;
	/** Günlük bu boyutu ya da yaşı geçince stats.json'a katlanır. Okuyanlar (panel, API) her zaman katlar. */
	const JOURNAL_BYTES = 65536;
	const JOURNAL_AGE   = 60;

	/** @var string */
	private $file;

	/** @var string */
	private $journal;

	/** @var string */
	private $seq_file;

	public function __construct( string $data_dir ) {
		$dir             = rtrim( $data_dir, '/' );
		$this->file      = $dir . '/stats.json';
		$this->journal   = $dir . '/stats.journal';
		$this->seq_file  = $dir . '/stats.seq';
	}

	public function file(): string {
		return $this->file;
	}

	/**
	 * Site kimliği: anahtarlı site anahtar özetinden, anahtarsız site kurulum kimliğinin özetinden.
	 * Beyan edilen adres kimlik değildir; kurulum kimliği yoksa (eski istemci) adres kullanılır.
	 * IP kimlik olarak kullanılmaz.
	 */
	public static function client_id( array $site, string $url, string $install_hash = '' ): string {
		if ( ! empty( $site['hash'] ) ) {
			return 'k' . substr( (string) $site['hash'], 0, 15 );
		}
		$bound = self::install_client_id( $install_hash );
		if ( '' !== $bound ) {
			return $bound;
		}
		$u = strtolower( preg_replace( '#^https?://(www\.)?#i', '', rtrim( $url, '/' ) ) );
		if ( '' !== $u ) {
			return 'u' . substr( hash( 'sha256', $u ), 0, 15 );
		}
		return 'i' . substr( hash( 'sha256', 'unknown' ), 0, 15 );
	}

	/** Kurulum özetinden kayıt kimliği (`i` + özetin ilk 15 karakteri). Özet geçersizse ''. */
	public static function install_client_id( string $install_hash ): string {
		return preg_match( '/^[a-f0-9]{64}$/', $install_hash ) ? 'i' . substr( $install_hash, 0, 15 ) : '';
	}

	/** Bu kayıt, sakladığı kurulum özetinin kaydı mı? Karşılaştırma sabit zamanlıdır. */
	public static function bound_install( string $client_id, array $client ): bool {
		$want = self::install_client_id( (string) ( $client['ih'] ?? '' ) );
		return '' !== $want && hash_equals( $want, $client_id );
	}

	/**
	 * Aynı adresi birden fazla kurulum kimliği bildirdiyse o adresler.
	 *
	 * @param array<string,mixed> $clients
	 * @return array<string,true>
	 */
	public static function shared_urls( array $clients ): array {
		$by = array();
		foreach ( $clients as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$url = (string) ( $c['url'] ?? '' );
			$ih  = (string) ( $c['ih'] ?? '' );
			if ( '' === $url || ! preg_match( '/^[a-f0-9]{64}$/', $ih ) ) {
				continue;
			}
			$by[ $url ][ $ih ] = true;
		}
		$out = array();
		foreach ( $by as $url => $ihs ) {
			if ( count( $ihs ) > 1 ) {
				$out[ $url ] = true;
			}
		}
		return $out;
	}

	/**
	 * Site adresini zorunlu kayıt için sadeleştirir: şema + host (+ port, alt dizin). Kullanıcı bilgisi,
	 * sorgu ve parça atılır. Gövdede adres yoksa WordPress'in User-Agent'ından ("WordPress/6.8; https://ornek.com") alınır.
	 */
	public static function site_url( string $site, string $ua = '' ): string {
		if ( '' === trim( $site ) && preg_match( '#;\s*(https?://\S+)#i', $ua, $m ) ) {
			$site = $m[1];
		}
		$p = parse_url( trim( $site ) );
		if ( ! is_array( $p ) || empty( $p['host'] ) || ! preg_match( '#^https?$#i', (string) ( $p['scheme'] ?? '' ) ) ) {
			return '';
		}
		$host = strtolower( (string) $p['host'] );
		if ( ! preg_match( '/^[a-z0-9.\-\[\]:]{1,190}$/', $host ) ) {
			return '';
		}
		$url = strtolower( (string) $p['scheme'] ) . '://' . $host . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' );
		$path = rtrim( (string) ( $p['path'] ?? '' ), '/' );
		if ( '' !== $path && preg_match( '#^(/[A-Za-z0-9._~%\-]+){1,4}$#', $path ) ) {
			$url .= $path;
		}
		return substr( $url, 0, 200 );
	}

	/**
	 * Site kullanım verisi göndermeye izin verdi mi? FluxWP Panel 1.0.8+ "usage" alanını yollar;
	 * alan yoksa eski istemcidir (adres ve sürümleri her zaman gönderir).
	 */
	public static function usage_allowed( array $body ): bool {
		return ! array_key_exists( 'usage', $body ) || ! empty( $body['usage'] );
	}

	/**
	 * /v1/check isteğini işler.
	 *
	 * @param string $geo İsteğin IP'sinden bulunan ülke ('' bilinmiyor). IP buraya hiç gelmez.
	 */
	public function record_check( array $site, array $body, string $geo = '' ): void {
		$usage = self::usage_allowed( $body );
		if ( ! $usage ) {
			// İzin yok: WordPress/PHP sürümü, saat dilimi ve dil saklanmaz. Adres zorunlu kayıttır.
			unset( $body['wp'], $body['php'], $body['tz'], $body['locale'] );
		}
		$url     = self::site_url( (string) ( $body['site'] ?? '' ) );
		$anon    = ! empty( $site['anon'] );
		$install = (string) ( $body['install'] ?? '' );
		$ih      = Server::install_hash( $install );
		$prefix  = '' !== $ih ? substr( $install, 0, self::INSTALL_PREFIX ) : '';
		$id      = self::client_id( $site, $url, $ih );
		$cc   = self::valid_cc( $geo ) ? $geo : ( $usage ? self::country( $body ) : '' );
		$gk   = '' !== $cc ? $cc : 'ZZ';
		$pkgs = array();
		foreach ( (array) ( $body['packages'] ?? array() ) as $slug => $ver ) {
			if ( is_string( $slug ) && preg_match( '/^[a-z0-9-]{1,64}$/', $slug ) && is_scalar( $ver ) ) {
				$pkgs[ $slug ] = substr( (string) $ver, 0, 32 );
			}
			if ( count( $pkgs ) >= 100 ) {
				break;
			}
		}
		$this->note(
			array(
				'op'     => 'c',
				'day'    => gmdate( 'Y-m-d' ),
				'id'     => $id,
				'anon'   => $anon,
				'url'    => $url,
				'cc'     => $cc,
				'gk'     => $gk,
				'pkgs'   => $pkgs,
				'name'   => (string) ( $site['name'] ?? '' ),
				'hash'   => (string) ( $site['hash'] ?? '' ),
				'off'    => ! empty( $site['disabled'] ),
				'wp'     => substr( (string) ( $body['wp'] ?? '' ), 0, 20 ),
				'php'    => substr( (string) ( $body['php'] ?? '' ), 0, 20 ),
				'upd'    => substr( (string) ( $body['updater'] ?? '' ), 0, 20 ),
				'ih'     => $ih,
				'prefix' => $prefix,
				'usage'  => $usage,
			)
		);
	}

	/** Tek kayıt (panelden anahtar verirken). */
	public function client( string $id ): ?array {
		$c = $this->read()['clients'][ $id ] ?? null;
		return is_array( $c ) ? $c : null;
	}

	/** İndirilen zip. $geo: isteğin ülkesi ('' bilinmiyor). */
	public function record_download( array $site, string $slug, int $bytes, string $geo = '' ): void {
		$this->note(
			array(
				'op'    => 'd',
				'day'   => gmdate( 'Y-m-d' ),
				'slug'  => $slug,
				'bytes' => $bytes,
				'gk'    => self::valid_cc( $geo ) ? $geo : 'ZZ',
			)
		);
	}

	/** Ülke kuralı ya da dağıtım anahtarı yüzünden geri çevrilen istek. */
	public function record_blocked( string $geo ): void {
		$this->note(
			array(
				'op'  => 'x',
				'day' => gmdate( 'Y-m-d' ),
				'gk'  => self::valid_cc( $geo ) ? $geo : 'ZZ',
			)
		);
	}

	public static function valid_cc( string $cc ): bool {
		return (bool) preg_match( '/^[A-Z]{2}$/', $cc ) && 'ZZ' !== $cc && 'XX' !== $cc;
	}

	/**
	 * Panel için özet.
	 *
	 * @param int $days Son kaç gün (bugün dahil).
	 */
	public function summary( int $days ): array {
		$all   = $this->read();
		$start = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_SECONDS );
		$pstart = gmdate( 'Y-m-d', time() - ( 2 * $days - 1 ) * DAY_SECONDS );
		$series = array();
		$tot    = array( 'checks' => 0, 'anon' => 0, 'downloads' => 0, 'bytes' => 0, 'blocked' => 0 );
		$geo    = array();
		$prev   = array( 'checks' => 0, 'downloads' => 0, 'sites' => 0 );
		$sites  = array();
		$psites = array();
		$dl     = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day      = gmdate( 'Y-m-d', time() - $i * DAY_SECONDS );
			$d        = $all['days'][ $day ] ?? array();
			$series[] = array(
				'd'         => $day,
				'checks'    => (int) ( $d['c'] ?? 0 ),
				'sites'     => count( (array) ( $d['s'] ?? array() ) ),
				'downloads' => (int) ( $d['d'] ?? 0 ),
			);
		}
		foreach ( (array) ( $all['days'] ?? array() ) as $day => $d ) {
			if ( $day >= $start ) {
				$tot['checks']    += (int) ( $d['c'] ?? 0 );
				$tot['anon']      += (int) ( $d['a'] ?? 0 );
				$tot['downloads'] += (int) ( $d['d'] ?? 0 );
				$tot['bytes']     += (int) ( $d['b'] ?? 0 );
				$tot['blocked']   += (int) ( $d['x'] ?? 0 );
				$sites            += (array) ( $d['s'] ?? array() );
				foreach ( (array) ( $d['g'] ?? array() ) as $gk => $g ) {
					$gk = self::valid_cc( (string) $gk ) ? (string) $gk : '?';
					$r  = $geo[ $gk ] ?? self::geo_row();
					$r['checks']    += (int) ( $g['c'] ?? 0 );
					$r['downloads'] += (int) ( $g['d'] ?? 0 );
					$r['bytes']     += (int) ( $g['b'] ?? 0 );
					$r['blocked']   += (int) ( $g['x'] ?? 0 );
					foreach ( (array) ( $g['p'] ?? array() ) as $slug => $n ) {
						$r['pk_dl'][ $slug ] = ( $r['pk_dl'][ $slug ] ?? 0 ) + (int) $n;
					}
					$geo[ $gk ] = $r;
				}
				foreach ( (array) ( $d['p'] ?? array() ) as $slug => $n ) {
					$dl[ $slug ] = ( $dl[ $slug ] ?? 0 ) + (int) $n;
				}
			} elseif ( $day >= $pstart ) {
				$prev['checks']    += (int) ( $d['c'] ?? 0 );
				$prev['downloads'] += (int) ( $d['d'] ?? 0 );
				$psites            += (array) ( $d['s'] ?? array() );
			}
		}
		$prev['sites'] = count( $psites );

		$clients   = (array) ( $all['clients'] ?? array() );
		$active    = array_intersect_key( $clients, $sites );
		$countries = array();
		$installs  = array();
		$keyed     = 0;
		foreach ( $active as $c ) {
			$cc               = (string) ( $c['cc'] ?? '' );
			$cc               = '' !== $cc ? $cc : '?';
			$countries[ $cc ] = ( $countries[ $cc ] ?? 0 ) + 1;
			if ( ! empty( $c['key'] ) ) {
				++$keyed;
			}
			$r = $geo[ $cc ] ?? self::geo_row();
			++$r['sites'];
			foreach ( array_keys( (array) ( $c['pk'] ?? array() ) ) as $slug ) {
				$installs[ $slug ]      = ( $installs[ $slug ] ?? 0 ) + 1;
				$r['pk_inst'][ $slug ] = ( $r['pk_inst'][ $slug ] ?? 0 ) + 1;
			}
			$geo[ $cc ] = $r;
		}
		foreach ( $geo as $k => $r ) {
			arsort( $geo[ $k ]['pk_dl'] );
			arsort( $geo[ $k ]['pk_inst'] );
		}
		uasort(
			$geo,
			static function ( $a, $b ) {
				return array( $b['checks'] + $b['downloads'], $b['sites'] ) <=> array( $a['checks'] + $a['downloads'], $a['sites'] );
			}
		);
		arsort( $countries );
		arsort( $installs );
		arsort( $dl );
		uasort(
			$active,
			static function ( $a, $b ) {
				return (int) ( $b['last'] ?? 0 ) <=> (int) ( $a['last'] ?? 0 );
			}
		);

		return array(
			'days'      => $days,
			'series'    => $series,
			'totals'    => $tot + array( 'sites' => count( $sites ), 'keyed' => $keyed ),
			'prev'      => $prev,
			'countries' => $countries,
			'geo'       => $geo,
			'installs'  => $installs,
			'downloads' => $dl,
			'clients'   => $active,
			'all_clients' => count( $clients ),
		);
	}

	private static function geo_row(): array {
		return array( 'sites' => 0, 'checks' => 0, 'downloads' => 0, 'bytes' => 0, 'blocked' => 0, 'pk_dl' => array(), 'pk_inst' => array() );
	}

	/* ------------------------------------------------------------------ */

	/** Siteden gelen ülke ipucu (yalnız kullanım verisi izniyle): saat dilimi > yerel ayarın bölgesi. */
	public static function country( array $body ): string {
		$cc = self::tz_country( (string) ( $body['tz'] ?? '' ) );
		if ( '' !== $cc ) {
			return $cc;
		}
		if ( preg_match( '/^[a-z]{2,3}_([A-Z]{2})/', (string) ( $body['locale'] ?? '' ), $m ) ) {
			return $m[1];
		}
		return '';
	}

	/** "Europe/Istanbul" → "TR". UTC+3 gibi kaydırmalar ülke vermez. */
	public static function tz_country( string $tz ): string {
		static $legacy = array(
			'Europe/Kiev'          => 'UA',
			'Asia/Calcutta'        => 'IN',
			'Asia/Saigon'          => 'VN',
			'Asia/Katmandu'        => 'NP',
			'Asia/Rangoon'         => 'MM',
			'America/Buenos_Aires' => 'AR',
			'Asia/Istanbul'        => 'TR',
			'Turkey'               => 'TR',
		);
		if ( '' === $tz || ! preg_match( '#^[A-Za-z_]+(/[A-Za-z0-9_+-]+){0,2}$#', $tz ) ) {
			return '';
		}
		if ( isset( $legacy[ $tz ] ) ) {
			return $legacy[ $tz ];
		}
		try {
			$loc = ( new \DateTimeZone( $tz ) )->getLocation();
		} catch ( \Throwable $e ) {
			return '';
		}
		$cc = is_array( $loc ) ? (string) ( $loc['country_code'] ?? '' ) : '';
		return preg_match( '/^[A-Z]{2}$/', $cc ) ? $cc : '';
	}

	/** Okuyanlar (panel, yönetici API, anahtar teslimi) katlanmış veriyi görür. */
	private function read(): array {
		$this->fold();
		return $this->read_file();
	}

	private function read_file(): array {
		if ( ! is_file( $this->file ) ) {
			return array();
		}
		$d = json_decode( (string) file_get_contents( $this->file ), true );
		return is_array( $d ) ? $d : array();
	}

	/** Günlüğe bir satır ekler. stats.json'a dokunmaz; eşik aşılırsa katlar. */
	private function note( array $ev ): void {
		try {
			$this->append( $ev );
			$this->maybe_fold();
		} catch ( \Throwable $e ) {
			error_log( '[flx-update] stats: ' . $e->getMessage() );
		}
	}

	private function append( array $ev ): void {
		$dir = dirname( $this->file );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0750, true ) ) {
			return;
		}
		$fh = @fopen( $this->journal, 'c+' );
		if ( ! $fh ) {
			return;
		}
		try {
			if ( ! flock( $fh, LOCK_EX ) ) {
				return;
			}
			$seq = $this->assigned_seq( $fh ) + 1;
			$this->write_seq( $seq );
			$ev  = array( 'seq' => $seq, 'ts' => time() ) + $ev;
			$line = json_encode( $ev, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $line ) ) {
				return;
			}
			fseek( $fh, 0, SEEK_END );
			if ( false === fwrite( $fh, $line . "\n" ) ) {
				return;
			}
			fflush( $fh );
		} finally {
			flock( $fh, LOCK_UN );
			fclose( $fh );
		}
	}

	/** Son verilen sıra: stats.seq, değilse günlüğün son satırı, o da yoksa stats.json jseq. */
	private function assigned_seq( $fh ): int {
		$seq  = $this->read_seq();
		$tail = $this->tail_seq( $fh );
		if ( $tail > $seq ) {
			$seq = $tail;
		}
		if ( ! is_file( $this->seq_file ) ) {
			$stored = (int) ( $this->read_file()['jseq'] ?? 0 );
			if ( $stored > $seq ) {
				$seq = $stored;
			}
		}
		return $seq;
	}

	private function tail_seq( $fh ): int {
		$stat = fstat( $fh );
		$size = (int) ( $stat['size'] ?? 0 );
		if ( $size <= 0 ) {
			return 0;
		}
		$n = min( 4096, $size );
		fseek( $fh, -$n, SEEK_END );
		$chunk = (string) fread( $fh, $n );
		$chunk = rtrim( $chunk, "\n" );
		$pos   = strrpos( $chunk, "\n" );
		$line  = false !== $pos ? substr( $chunk, $pos + 1 ) : $chunk;
		$ev    = json_decode( $line, true );
		return is_array( $ev ) ? (int) ( $ev['seq'] ?? 0 ) : 0;
	}

	private function read_seq(): int {
		if ( ! is_file( $this->seq_file ) ) {
			return 0;
		}
		return (int) trim( (string) file_get_contents( $this->seq_file ) );
	}

	private function write_seq( int $seq ): void {
		$tmp = $this->seq_file . '.' . getmypid() . '.tmp';
		if ( false !== file_put_contents( $tmp, (string) $seq ) ) {
			rename( $tmp, $this->seq_file );
		}
	}

	private function maybe_fold(): void {
		clearstatcache( true, $this->journal );
		if ( ! is_file( $this->journal ) ) {
			return;
		}
		$size = (int) filesize( $this->journal );
		if ( $size <= 0 ) {
			return;
		}
		if ( $size >= self::JOURNAL_BYTES ) {
			$this->fold();
			return;
		}
		$head = (string) file_get_contents( $this->journal, false, null, 0, 96 );
		if ( preg_match( '/"ts":(\d+)/', $head, $m ) && time() - (int) $m[1] >= self::JOURNAL_AGE ) {
			$this->fold();
		}
	}

	/**
	 * Günlüğü stats.json'a katar (geçici dosya + rename). Günlük, başarılı yazmadan sonra kesilir.
	 * jseq'ten küçük veya eşit satırlar atlanır; kesilmemiş günlük ikinci kez sayılmaz.
	 */
	private function fold(): void {
		clearstatcache( true, $this->journal );
		if ( ! is_file( $this->journal ) || (int) filesize( $this->journal ) === 0 ) {
			return;
		}
		$dir = dirname( $this->file );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0750, true ) ) {
			return;
		}
		$lock = @fopen( $this->file . '.lock', 'c' );
		if ( ! $lock ) {
			return;
		}
		$jh = @fopen( $this->journal, 'c+' );
		if ( ! $jh ) {
			fclose( $lock );
			return;
		}
		try {
			flock( $lock, LOCK_EX );
			flock( $jh, LOCK_EX );
			rewind( $jh );
			$events = array();
			foreach ( explode( "\n", (string) stream_get_contents( $jh ) ) as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				$ev = json_decode( $line, true );
				if ( ! is_array( $ev ) || ! isset( $ev['seq'] ) ) {
					continue;
				}
				$events[] = $ev;
			}
			usort(
				$events,
				static function ( $a, $b ) {
					return ( (int) $a['seq'] ) <=> ( (int) $b['seq'] );
				}
			);
			$all     = $this->read_file();
			$jseq    = (int) ( $all['jseq'] ?? 0 );
			$applied = false;
			foreach ( $events as $ev ) {
				$seq = (int) $ev['seq'];
				if ( $seq <= $jseq ) {
					continue;
				}
				$all = $this->apply_event( $all, $ev );
				$this->prune( $all );
				$jseq    = $seq;
				$applied = true;
			}
			if ( $applied ) {
				$all['jseq'] = $jseq;
				$tmp         = $this->file . '.' . getmypid() . '.tmp';
				$json        = json_encode( $all, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( ! is_string( $json ) || false === file_put_contents( $tmp, $json ) || ! rename( $tmp, $this->file ) ) {
					@unlink( $tmp );
					return;
				}
			}
			if ( $jseq > $this->read_seq() ) {
				$this->write_seq( $jseq );
			}
			$pending = false;
			foreach ( $events as $ev ) {
				if ( (int) $ev['seq'] > $jseq ) {
					$pending = true;
					break;
				}
			}
			if ( ! $pending ) {
				ftruncate( $jh, 0 );
				fflush( $jh );
			}
		} catch ( \Throwable $e ) {
			error_log( '[flx-update] stats: ' . $e->getMessage() );
		} finally {
			flock( $jh, LOCK_UN );
			flock( $lock, LOCK_UN );
			fclose( $jh );
			fclose( $lock );
		}
	}

	private function apply_event( array $all, array $ev ): array {
		$op = (string) ( $ev['op'] ?? '' );
		if ( 'c' === $op ) {
			return $this->apply_check( $all, $ev );
		}
		if ( 'd' === $op ) {
			return $this->apply_download( $all, $ev );
		}
		if ( 'x' === $op ) {
			return $this->apply_blocked( $all, $ev );
		}
		return $all;
	}

	private function apply_check( array $all, array $ev ): array {
		$day    = (string) ( $ev['day'] ?? '' );
		$now    = (int) ( $ev['ts'] ?? time() );
		$id     = (string) ( $ev['id'] ?? '' );
		$anon   = ! empty( $ev['anon'] );
		$url    = (string) ( $ev['url'] ?? '' );
		$cc     = (string) ( $ev['cc'] ?? '' );
		$gk     = (string) ( $ev['gk'] ?? 'ZZ' );
		$pkgs   = is_array( $ev['pkgs'] ?? null ) ? $ev['pkgs'] : array();
		$ih     = (string) ( $ev['ih'] ?? '' );
		$prefix = (string) ( $ev['prefix'] ?? '' );
		$usage  = ! empty( $ev['usage'] );

		$d      = $all['days'][ $day ] ?? array();
		$d['c'] = (int) ( $d['c'] ?? 0 ) + 1;
		if ( $anon ) {
			$d['a'] = (int) ( $d['a'] ?? 0 ) + 1;
		}
		$d['g'][ $gk ]['c']  = (int) ( $d['g'][ $gk ]['c'] ?? 0 ) + 1;
		$d['s'][ $id ]       = 1;
		$all['days'][ $day ] = $d;

		$existing = $all['clients'][ $id ] ?? null;
		if ( is_array( $existing ) ) {
			$stored = (string) ( $existing['ih'] ?? '' );
			// Başka bir kurulumun kaydına düşüldüyse (özet çakışması) saklanan kimlik ezilmez.
			if ( '' !== $ih && '' !== $stored && ! hash_equals( $stored, $ih ) ) {
				return $all;
			}
			$c = $existing;
		} else {
			$c = array( 'first' => $now, 'n' => 0 );
		}
		$c['url']  = '' === $url ? (string) ( $c['url'] ?? '' ) : $url;
		$c['use']  = $usage;
		$c['name'] = (string) ( $ev['name'] ?? '' );
		$c['key']  = '' !== (string) ( $ev['hash'] ?? '' );
		$c['off']  = ! empty( $ev['off'] );
		if ( '' !== $ih && '' === (string) ( $c['ih'] ?? '' ) ) {
			$c['ih'] = $ih;
		}
		if ( '' !== $prefix && '' === (string) ( $c['pfx'] ?? '' ) && isset( $c['ih'] ) && hash_equals( (string) $c['ih'], $ih ) ) {
			$c['pfx'] = $prefix;
		}
		if ( '' !== $ih ) {
			// Aynı kurulumun eski anahtarsız kaydı (adrese bağlı kalmış olabilir) bu kayda katılır.
			foreach ( (array) ( $all['clients'] ?? array() ) as $oid => $o ) {
				if ( ! is_array( $o ) || (string) $oid === (string) $id || ! empty( $o['key'] ) ) {
					continue;
				}
				$oih = (string) ( $o['ih'] ?? '' );
				if ( 64 !== strlen( $oih ) || ! hash_equals( $oih, $ih ) ) {
					continue;
				}
				$c['first'] = min( (int) ( $c['first'] ?? $now ), (int) ( $o['first'] ?? $now ) );
				$c['n']     = (int) ( $c['n'] ?? 0 ) + (int) ( $o['n'] ?? 0 );
				if ( '' === (string) ( $c['pfx'] ?? '' ) && '' !== (string) ( $o['pfx'] ?? '' ) ) {
					$c['pfx'] = (string) $o['pfx'];
				}
				unset( $all['clients'][ $oid ] );
				foreach ( $all['days'] ?? array() as $dk => $day_row ) {
					if ( is_array( $day_row ) && isset( $day_row['s'][ $oid ] ) ) {
						unset( $all['days'][ $dk ]['s'][ $oid ] );
						$all['days'][ $dk ]['s'][ $id ] = 1;
					}
				}
			}
		}
		if ( '' !== $cc ) {
			$c['cc'] = $cc;
		}
		$c['wp']               = substr( (string) ( $ev['wp'] ?? '' ), 0, 20 );
		$c['php']              = substr( (string) ( $ev['php'] ?? '' ), 0, 20 );
		$c['upd']              = substr( (string) ( $ev['upd'] ?? '' ), 0, 20 );
		$c['pk']               = $pkgs;
		$c['last']             = $now;
		$c['n']                = (int) ( $c['n'] ?? 0 ) + 1;
		$all['clients'][ $id ] = $c;
		return $all;
	}

	private function apply_download( array $all, array $ev ): array {
		$day             = (string) ( $ev['day'] ?? '' );
		$slug            = (string) ( $ev['slug'] ?? '' );
		$bytes           = (int) ( $ev['bytes'] ?? 0 );
		$gk              = (string) ( $ev['gk'] ?? 'ZZ' );
		$d               = $all['days'][ $day ] ?? array();
		$d['d']          = (int) ( $d['d'] ?? 0 ) + 1;
		$d['b']          = (int) ( $d['b'] ?? 0 ) + $bytes;
		$d['p'][ $slug ] = (int) ( $d['p'][ $slug ] ?? 0 ) + 1;
		$g               = $d['g'][ $gk ] ?? array();
		$g['d']          = (int) ( $g['d'] ?? 0 ) + 1;
		$g['b']          = (int) ( $g['b'] ?? 0 ) + $bytes;
		$g['p'][ $slug ] = (int) ( $g['p'][ $slug ] ?? 0 ) + 1;
		$d['g'][ $gk ]   = $g;
		$all['days'][ $day ] = $d;
		return $all;
	}

	private function apply_blocked( array $all, array $ev ): array {
		$day = (string) ( $ev['day'] ?? '' );
		$gk  = (string) ( $ev['gk'] ?? 'ZZ' );
		$all['days'][ $day ]['x']             = (int) ( $all['days'][ $day ]['x'] ?? 0 ) + 1;
		$all['days'][ $day ]['g'][ $gk ]['x'] = (int) ( $all['days'][ $day ]['g'][ $gk ]['x'] ?? 0 ) + 1;
		return $all;
	}

	private function prune( array &$all ): void {
		$min = gmdate( 'Y-m-d', time() - self::KEEP_DAYS * DAY_SECONDS );
		foreach ( array_keys( (array) ( $all['days'] ?? array() ) ) as $day ) {
			if ( $day < $min ) {
				unset( $all['days'][ $day ] );
			}
		}
		$old = time() - self::CLIENT_DAYS * DAY_SECONDS;
		$cl  = (array) ( $all['clients'] ?? array() );
		foreach ( $cl as $id => $c ) {
			if ( ! is_array( $c ) || (int) ( $c['last'] ?? 0 ) < $old ) {
				unset( $cl[ $id ] );
			}
		}
		$keyless = array();
		foreach ( $cl as $id => $c ) {
			if ( empty( $c['key'] ) ) {
				$keyless[ $id ] = array(
					'last'  => (int) ( $c['last'] ?? 0 ),
					'n'     => (int) ( $c['n'] ?? 0 ),
					'first' => (int) ( $c['first'] ?? 0 ),
				);
			}
		}
		if ( count( $keyless ) > self::MAX_KEYLESS ) {
			uasort(
				$keyless,
				static function ( $a, $b ) {
					return array( $a['last'], $a['n'], $a['first'] ) <=> array( $b['last'], $b['n'], $b['first'] );
				}
			);
			foreach ( array_slice( array_keys( $keyless ), 0, count( $keyless ) - self::MAX_KEYLESS ) as $id ) {
				unset( $cl[ $id ] );
			}
		}
		if ( count( $cl ) > self::MAX_CLIENTS ) {
			uasort(
				$cl,
				static function ( $a, $b ) {
					return (int) ( $b['last'] ?? 0 ) <=> (int) ( $a['last'] ?? 0 );
				}
			);
			$cl = array_slice( $cl, 0, self::MAX_CLIENTS, true );
		}
		$all['clients'] = $cl;
	}
}
