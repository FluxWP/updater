<?php
/**
 * Duyurular: panelden girilir (settings.json › announcements), GET /v1/announcements ile anahtarsız yayınlanır.
 * FluxWP Panel'li siteler (flx-admin ana sayfası) kapatılabilir kart olarak gösterir. Metin düz metindir;
 * site tarafı da kaçışlayarak basar.
 */

namespace FLXUpdate;

final class Announcements {

	const TONES = array( 'info', 'success', 'warn', 'critical' );
	const MAX   = 50;

	/** Panel formundan tek duyuru. Başlık (en ya da tr) yoksa null. */
	public static function clean( array $in, array $old = array(), int $now = 0 ): ?array {
		$now   = $now ?: time();
		$txt   = static function ( $v, int $max ): string {
			$v = trim( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $v ) );
			return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $max ) : substr( $v, 0, $max );
		};
		$title = array( 'en' => $txt( $in['title_en'] ?? '', 120 ), 'tr' => $txt( $in['title_tr'] ?? '', 120 ) );
		if ( '' === $title['en'] && '' === $title['tr'] ) {
			return null;
		}
		$url = trim( (string) ( $in['url'] ?? '' ) );
		if ( ! preg_match( '#^https://[^\s"<>]{3,500}$#i', $url ) ) {
			$url = '';
		}
		$list = static function ( $v, string $re ): array {
			$out = array();
			foreach ( preg_split( '/[\s,]+/', strtolower( (string) $v ) ) as $x ) {
				if ( preg_match( $re, $x ) ) {
					$out[ $x ] = $x;
				}
			}
			return array_values( $out );
		};
		$time = static function ( $v ): int {
			$v = trim( (string) $v );
			if ( '' === $v ) {
				return 0;
			}
			$t = strtotime( $v . ' UTC' );
			return $t ? (int) $t : 0;
		};
		$tone = (string) ( $in['tone'] ?? 'info' );
		return array(
			'id'      => (string) ( $old['id'] ?? ( 'a' . bin2hex( random_bytes( 4 ) ) ) ),
			'on'      => ! empty( $in['on'] ),
			'tone'    => in_array( $tone, self::TONES, true ) ? $tone : 'info',
			'title'   => $title,
			'body'    => array( 'en' => $txt( $in['body_en'] ?? '', 600 ), 'tr' => $txt( $in['body_tr'] ?? '', 600 ) ),
			'cta'     => array( 'en' => $txt( $in['cta_en'] ?? '', 40 ), 'tr' => $txt( $in['cta_tr'] ?? '', 40 ) ),
			'url'     => $url,
			'starts'  => $time( $in['starts'] ?? '' ),
			'ends'    => $time( $in['ends'] ?? '' ),
			'plugins' => $list( $in['plugins'] ?? '', '/^[a-z0-9][a-z0-9-]{1,63}$/' ),
			'langs'   => $list( $in['langs'] ?? '', '/^[a-z]{2,3}(_[a-z]{2})?$/' ),
			'created' => (int) ( $old['created'] ?? $now ),
			'updated' => $now,
		);
	}

	/** Şu an yayında olanlar (açık, tarih aralığında), siteye gidecek biçimde. */
	public static function public_list( array $all, int $now = 0 ): array {
		$now = $now ?: time();
		$out = array();
		foreach ( $all as $a ) {
			if ( ! is_array( $a ) || empty( $a['on'] ) || empty( $a['id'] ) ) {
				continue;
			}
			if ( ( (int) ( $a['starts'] ?? 0 ) && $now < (int) $a['starts'] ) || ( (int) ( $a['ends'] ?? 0 ) && $now >= (int) $a['ends'] ) ) {
				continue;
			}
			$out[] = array(
				'id'      => (string) $a['id'],
				'tone'    => (string) ( $a['tone'] ?? 'info' ),
				'title'   => (array) ( $a['title'] ?? array() ),
				'body'    => (array) ( $a['body'] ?? array() ),
				'cta'     => (array) ( $a['cta'] ?? array() ),
				'url'     => (string) ( $a['url'] ?? '' ),
				'starts'  => (int) ( $a['starts'] ?? 0 ),
				'ends'    => (int) ( $a['ends'] ?? 0 ),
				'plugins' => array_values( (array) ( $a['plugins'] ?? array() ) ),
				'langs'   => array_values( (array) ( $a['langs'] ?? array() ) ),
			);
		}
		usort( $out, static function ( $x, $y ) { return $y['starts'] <=> $x['starts']; } );
		return $out;
	}
}
