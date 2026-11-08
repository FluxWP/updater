<?php
/**
 * Sitedeki kurulum sihirbazının eklenti ekranları: kategoriler (ad, açıklama, sıra; TR ve EN) ve
 * her paketin kategorisi, sırası, önerilen olup olmadığı. Varsayılanlar burada; panelden değiştirilir
 * (data/settings.json: categories, pkg_meta). /v1/check yanıtına eklenir, FluxWP Panel bunlara göre listeler.
 */

namespace FLXUpdate;

final class CatalogMeta {

	/** Varsayılan kategoriler. name/lead: dil => metin. */
	const CATEGORIES = array(
		array( 'key' => 'sales', 'order' => 10, 'name' => array( 'tr' => 'Satış ve bağış', 'en' => 'Sales and donations' ), 'lead' => array( 'tr' => 'Mağaza, bağış ve sertifikalar.', 'en' => 'Store, donations and certificates.' ) ),
		array( 'key' => 'manage', 'order' => 20, 'name' => array( 'tr' => 'Yönetim', 'en' => 'Management' ), 'lead' => array( 'tr' => 'Ekip yetkileri, görevler ve masaüstü/mobil uygulama.', 'en' => 'Team permissions, tasks and the desktop/mobile app.' ) ),
		array( 'key' => 'contact', 'order' => 30, 'name' => array( 'tr' => 'İletişim', 'en' => 'Communication' ), 'lead' => array( 'tr' => 'WhatsApp, e-posta ve formlar tek panelde.', 'en' => 'WhatsApp, e-mail and forms in one panel.' ) ),
		array( 'key' => 'grow', 'order' => 40, 'name' => array( 'tr' => 'Büyüme', 'en' => 'Growth' ), 'lead' => array( 'tr' => 'Arama motorları, ziyaretçi analizi, diller ve sosyal medya.', 'en' => 'Search engines, visitor analytics, languages and social media.' ) ),
		array( 'key' => 'ai', 'order' => 50, 'name' => array( 'tr' => 'Yapay zekâ', 'en' => 'AI' ), 'lead' => array( 'tr' => 'Tüm paketlerin kullandığı ortak AI ve görsel stüdyosu.', 'en' => 'Shared AI for every package and an image studio.' ) ),
		array( 'key' => 'system', 'order' => 60, 'name' => array( 'tr' => 'Sistem', 'en' => 'System' ), 'lead' => array( 'tr' => 'Hız, yedek ve sistem kontrolü.', 'en' => 'Speed, backups and system checks.' ) ),
	);

	/** Varsayılan paket yerleri: slug => [kategori, sıra, önerilen, kısa açıklama (dil => metin)]. */
	const PACKAGES = array(
		'flx-commerce'     => array( 'sales', 10, true ),
		'flx-donate'       => array( 'sales', 20, true ),
		'flx-certificates' => array( 'sales', 30, true ),
		'flx-admin'        => array( 'manage', 10, true ),
		'flx-roles'        => array( 'manage', 20, false ),
		'flx-tasks'        => array( 'manage', 30, false ),
		'flx-app'          => array( 'manage', 40, false ),
		'flx-crm'          => array( 'contact', 5, true ),
		'flx-chatbot'      => array( 'contact', 15, false ),
		'flx-recaptcha'    => array( 'system', 30, false ),
		'flx-wiki'         => array( 'grow', 50, false ),
		'flx-chat'         => array( 'contact', 10, true ),
		'flx-forms'        => array( 'contact', 20, true ),
		'flx-mail'         => array( 'contact', 30, true ),
		'flx-seo'          => array( 'grow', 10, true ),
		'flx-analytics'    => array( 'grow', 20, false ),
		'flx-lang'         => array( 'grow', 30, false ),
		'flx-social'       => array( 'grow', 40, false ),
		'flx-ai'           => array( 'ai', 10, false ),
		'flx-studio'       => array( 'ai', 20, false ),
		'flx-speed'        => array( 'system', 10, true ),
		'flx-backup'       => array( 'system', 20, true ),
	);

	/** Panelde kayıtlı liste varsa o, yoksa varsayılan; sıraya göre. */
	public static function categories( array $config ): array {
		$list = isset( $config['categories'] ) && is_array( $config['categories'] ) ? $config['categories'] : self::CATEGORIES;
		$out  = array();
		foreach ( $list as $c ) {
			$key = self::key( (string) ( $c['key'] ?? '' ) );
			if ( '' === $key || isset( $out[ $key ] ) ) {
				continue;
			}
			$out[ $key ] = array(
				'key'   => $key,
				'order' => (int) ( $c['order'] ?? 0 ),
				'name'  => self::texts( $c['name'] ?? array() ),
				'lead'  => self::texts( $c['lead'] ?? array() ),
			);
		}
		$out = array_values( $out );
		usort(
			$out,
			static function ( $a, $b ) {
				return array( $a['order'], $a['key'] ) <=> array( $b['order'], $b['key'] );
			}
		);
		return $out;
	}

	/** Bir paketin yeri: panel kaydı > varsayılan > boş (sitede "Diğer"). */
	public static function package( array $config, string $slug ): array {
		$saved = $config['pkg_meta'][ $slug ] ?? null;
		if ( is_array( $saved ) ) {
			return array(
				'category' => self::key( (string) ( $saved['category'] ?? '' ) ),
				'order'    => (int) ( $saved['order'] ?? 0 ),
				'rec'      => ! empty( $saved['rec'] ),
			);
		}
		$d = self::PACKAGES[ $slug ] ?? null;
		return $d ? array( 'category' => $d[0], 'order' => $d[1], 'rec' => $d[2] ) : array( 'category' => '', 'order' => 999, 'rec' => false );
	}

	public static function key( string $k ): string {
		return substr( preg_replace( '/[^a-z0-9_-]/', '', strtolower( $k ) ), 0, 32 );
	}

	/** @param mixed $v */
	public static function texts( $v ): array {
		$out = array();
		foreach ( (array) $v as $lang => $s ) {
			$s = trim( (string) $s );
			if ( '' !== $s && isset( I18n::LANGS[ $lang ] ) ) {
				$out[ $lang ] = mb_substr( $s, 0, 200 );
			}
		}
		return $out;
	}
}
