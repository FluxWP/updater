<?php
/**
 * Kullanım istatistikleri: hangi siteler (anahtarlı/anahtarsız) güncelleme denetliyor,
 * hangi paketleri indiriyor, hangi ülkeden. IP saklanmaz; ülke, sitenin saat diliminden
 * (WordPress timezone_string) ya da Cloudflare'in CF-IPCountry başlığından bulunur.
 *
 * data/stats.json:
 *   days    => { "Y-m-d": { c: kontrol, a: anahtarsız kontrol, d: indirme, b: bayt, s: {site_id: 1}, p: {slug: indirme} } }
 *   clients => { site_id: { url, name, key, off, ih, cc, wp, php, upd, use, pk: {slug: sürüm}, first, last, n } }
 *   use: site kullanım verisi göndermeye izin verdi mi (vermediyse url, cc, wp, php boş).
 *   ih: Flux Updater 1.7.0+ kurulum kimliğinin sha256 özeti (panelden anahtar vermek için).
 */

namespace FLXUpdate;

final class Stats {

	const KEEP_DAYS    = 120;
	const CLIENT_DAYS  = 180;
	const MAX_CLIENTS  = 5000;

	/** @var string */
	private $file;

	public function __construct( string $data_dir ) {
		$this->file = rtrim( $data_dir, '/' ) . '/stats.json';
	}

	public function file(): string {
		return $this->file;
	}

	/**
	 * Site kimliği: anahtarlı site anahtar özetinden, anahtarsız site adresinden; adres yoksa
	 * (kullanım verisi izni verilmemiş) rastgele kurulum kimliğinin özetinden. IP kimlik olarak kullanılmaz.
	 */
	public static function client_id( array $site, string $url, string $install_hash = '' ): string {
		if ( ! empty( $site['hash'] ) ) {
			return 'k' . substr( (string) $site['hash'], 0, 15 );
		}
		$u = strtolower( preg_replace( '#^https?://(www\.)?#i', '', rtrim( $url, '/' ) ) );
		if ( '' !== $u ) {
			return 'u' . substr( hash( 'sha256', $u ), 0, 15 );
		}
		return 'i' . substr( hash( 'sha256', '' !== $install_hash ? $install_hash : 'unknown' ), 0, 15 );
	}

	/**
	 * Site kullanım verisi göndermeye izin verdi mi? FluxWP Panel 1.0.7+ "usage" alanını yollar;
	 * alan yoksa eski istemcidir (adres ve sürümleri her zaman gönderir).
	 */
	public static function usage_allowed( array $body ): bool {
		return ! array_key_exists( 'usage', $body ) || ! empty( $body['usage'] );
	}

	/** /v1/check isteğini işler. */
	public function record_check( array $site, array $body ): void {
		$usage = self::usage_allowed( $body );
		if ( ! $usage ) {
			// İzin yok: adres, WordPress/PHP sürümü, saat dilimi, dil ve ülke saklanmaz.
			unset( $body['site'], $body['wp'], $body['php'], $body['tz'], $body['locale'] );
		}
		$url = substr( (string) ( $body['site'] ?? '' ), 0, 200 );
		if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
			$url = '';
		}
		$anon = ! empty( $site['anon'] );
		$ih   = Server::install_hash( (string) ( $body['install'] ?? '' ) );
		$id   = self::client_id( $site, $url, $ih );
		$cc   = $usage ? self::country( $body ) : '';
		$pkgs = array();
		foreach ( (array) ( $body['packages'] ?? array() ) as $slug => $ver ) {
			if ( is_string( $slug ) && preg_match( '/^[a-z0-9-]{1,64}$/', $slug ) && is_scalar( $ver ) ) {
				$pkgs[ $slug ] = substr( (string) $ver, 0, 32 );
			}
			if ( count( $pkgs ) >= 100 ) {
				break;
			}
		}
		$this->update(
			function ( array $all ) use ( $id, $anon, $url, $cc, $pkgs, $site, $body, $ih, $usage ) {
				$day = gmdate( 'Y-m-d' );
				$d   = $all['days'][ $day ] ?? array();
				$d['c'] = (int) ( $d['c'] ?? 0 ) + 1;
				if ( $anon ) {
					$d['a'] = (int) ( $d['a'] ?? 0 ) + 1;
				}
				$d['s'][ $id ]       = 1;
				$all['days'][ $day ] = $d;

				$c        = $all['clients'][ $id ] ?? array( 'first' => time(), 'n' => 0 );
				$c['url'] = $usage && '' === $url ? (string) ( $c['url'] ?? '' ) : $url;
				$c['use'] = $usage;
				$c['name'] = (string) ( $site['name'] ?? '' );
				$c['key']  = '' !== (string) ( $site['hash'] ?? '' );
				$c['off']  = ! empty( $site['disabled'] );
				if ( '' !== $ih ) {
					$c['ih'] = $ih;
					// Anahtar alan site ya da izni geri alan site: aynı kurulumun eski anahtarsız kaydı
					// (adresli olabilir) bu kayda katılır, eski kayıt silinir.
					if ( $c['key'] || ! $usage ) {
						foreach ( (array) ( $all['clients'] ?? array() ) as $oid => $o ) {
							if ( $oid !== $id && empty( $o['key'] ) && ( $o['ih'] ?? '' ) === $ih ) {
								$c['first'] = min( (int) ( $c['first'] ?? time() ), (int) ( $o['first'] ?? time() ) );
								$c['n']     = (int) ( $c['n'] ?? 0 ) + (int) ( $o['n'] ?? 0 );
								unset( $all['clients'][ $oid ] );
								foreach ( $all['days'] ?? array() as $day => $d ) {
									if ( isset( $d['s'][ $oid ] ) ) {
										unset( $all['days'][ $day ]['s'][ $oid ] );
										$all['days'][ $day ]['s'][ $id ] = 1;
									}
								}
							}
						}
					}
				}
				if ( '' !== $cc ) {
					$c['cc'] = $cc;
				} elseif ( ! $usage ) {
					unset( $c['cc'] );
				}
				$c['wp']   = substr( (string) ( $body['wp'] ?? '' ), 0, 20 );
				$c['php']  = substr( (string) ( $body['php'] ?? '' ), 0, 20 );
				$c['upd']  = substr( (string) ( $body['updater'] ?? '' ), 0, 20 );
				$c['pk']   = $pkgs;
				$c['last'] = time();
				$c['n']    = (int) ( $c['n'] ?? 0 ) + 1;
				$all['clients'][ $id ] = $c;
				return $all;
			}
		);
	}

	/** Tek kayıt (panelden anahtar verirken). */
	public function client( string $id ): ?array {
		$c = $this->read()['clients'][ $id ] ?? null;
		return is_array( $c ) ? $c : null;
	}

	/** İndirilen zip. */
	public function record_download( array $site, string $slug, int $bytes ): void {
		$this->update(
			function ( array $all ) use ( $slug, $bytes ) {
				$day = gmdate( 'Y-m-d' );
				$d   = $all['days'][ $day ] ?? array();
				$d['d'] = (int) ( $d['d'] ?? 0 ) + 1;
				$d['b'] = (int) ( $d['b'] ?? 0 ) + $bytes;
				$d['p'][ $slug ] = (int) ( $d['p'][ $slug ] ?? 0 ) + 1;
				$all['days'][ $day ] = $d;
				return $all;
			}
		);
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
		$tot    = array( 'checks' => 0, 'anon' => 0, 'downloads' => 0, 'bytes' => 0 );
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
				$sites            += (array) ( $d['s'] ?? array() );
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
			foreach ( array_keys( (array) ( $c['pk'] ?? array() ) ) as $slug ) {
				$installs[ $slug ] = ( $installs[ $slug ] ?? 0 ) + 1;
			}
		}
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
			'installs'  => $installs,
			'downloads' => $dl,
			'clients'   => $active,
			'all_clients' => count( $clients ),
		);
	}

	/* ------------------------------------------------------------------ */

	/** Ülke kodu: CF-IPCountry > saat dilimi > yerel ayarın bölgesi. */
	public static function country( array $body ): string {
		$cf = strtoupper( (string) ( $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '' ) );
		if ( preg_match( '/^[A-Z]{2}$/', $cf ) && 'XX' !== $cf && 'T1' !== $cf ) {
			return $cf;
		}
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

	private function read(): array {
		if ( ! is_file( $this->file ) ) {
			return array();
		}
		$d = json_decode( (string) file_get_contents( $this->file ), true );
		return is_array( $d ) ? $d : array();
	}

	/** Kilitli oku-değiştir-yaz. İstatistik yazılamazsa istek yine de yanıtlanır. */
	private function update( callable $fn ): void {
		$dir = dirname( $this->file );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0750, true ) ) {
			return;
		}
		$lock = @fopen( $this->file . '.lock', 'c' );
		if ( ! $lock ) {
			return;
		}
		try {
			flock( $lock, LOCK_EX );
			$all = $fn( $this->read() );
			$this->prune( $all );
			$tmp = $this->file . '.' . getmypid() . '.tmp';
			if ( false !== file_put_contents( $tmp, json_encode( $all, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ) {
				rename( $tmp, $this->file );
			}
		} catch ( \Throwable $e ) {
			error_log( '[flx-update] stats: ' . $e->getMessage() );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
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
			if ( (int) ( $c['last'] ?? 0 ) < $old ) {
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
