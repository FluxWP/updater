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
		'flx-commerce'     => array( 'sales', 10, true, array( 'tr' => 'WooCommerce mağazası: satış paneli, müşteriler, raporlar ve dekontlu Havale/EFT.', 'en' => 'WooCommerce store: sales dashboard, customers, reports and bank transfer with receipts.' ) ),
		'flx-donate'       => array( 'sales', 20, true, array( 'tr' => 'WooCommerce üzerinde bağış türleri ve tek sayfalık ödeme.', 'en' => 'Donation types on WooCommerce with a one-page checkout.' ) ),
		'flx-certificates' => array( 'sales', 30, true, array( 'tr' => 'Her bağışçıya adına PDF sertifika gönderir.', 'en' => 'Sends every donor a PDF certificate in their name.' ) ),
		'flx-admin'        => array( 'manage', 10, true, array( 'tr' => 'Komut paleti, mobil menü ve düzenlenebilir panel.', 'en' => 'Command palette, mobile menu and a customisable dashboard.' ) ),
		'flx-roles'        => array( 'manage', 20, false, array( 'tr' => 'Personel rolleri ve sayfa bazlı yetkiler.', 'en' => 'Staff roles and per-page permissions.' ) ),
		'flx-tasks'        => array( 'manage', 30, false, array( 'tr' => 'Görev panosu, takvim ve toplantı notları.', 'en' => 'Task board, calendar and meeting notes.' ) ),
		'flx-app'          => array( 'manage', 40, false, array( 'tr' => 'Masaüstü ve mobil FluxApp bağlantısı.', 'en' => 'Connects the desktop and mobile FluxApp.' ) ),
		'flx-crm'          => array( 'contact', 5, true, array( 'tr' => 'Bağışçı, müşteri ve gönüllüler tek kişi kartında; segment ve kampanya.', 'en' => 'Donors, customers and volunteers on one contact card, with segments and campaigns.' ) ),
		'flx-chatbot'      => array( 'contact', 15, false, array( 'tr' => 'Sitede AI sohbet penceresi ve canlı destek.', 'en' => 'AI chat window and live support on your site.' ) ),
		'flx-recaptcha'    => array( 'system', 30, false, array( 'tr' => 'Giriş, yorum, bağış ve form gönderimlerinde reCAPTCHA.', 'en' => 'reCAPTCHA on login, comments, donations and form submissions.' ) ),
		'flx-updater-manager' => array( 'system', 40, false, array( 'tr' => 'Güncelleme sunucusunu WordPress panelinden yönetin: ayarlar, anahtarlar ve istatistikler.', 'en' => 'Manage the update server from WordPress: settings, keys and statistics.' ) ),
		'flx-wiki'         => array( 'grow', 50, false, array( 'tr' => 'Sitede Markdown wiki: sayfa ağacı, arama, SEO ve LLM dostu.', 'en' => 'A Markdown wiki on your site: page tree, search, SEO and LLM friendly.' ) ),
		'flx-chat'         => array( 'contact', 10, true, array( 'tr' => 'WhatsApp gelen kutusu, hazır cevaplar ve AI asistan.', 'en' => 'WhatsApp inbox, canned replies and an AI assistant.' ) ),
		'flx-forms'        => array( 'contact', 20, true, array( 'tr' => 'Sürükle-bırak form, ödeme alanı ve otomasyon.', 'en' => 'Drag-and-drop forms with payments and automations.' ) ),
		'flx-mail'         => array( 'contact', 30, true, array( 'tr' => 'SMTP bağlantısı, posta kutusu ve gönderim kaydı.', 'en' => 'SMTP connection, mailbox and delivery log.' ) ),
		'flx-seo'          => array( 'grow', 10, true, array( 'tr' => 'Site denetimi, schema ve sayfa başına meta.', 'en' => 'Site audit, schema and per-page meta.' ) ),
		'flx-analytics'    => array( 'grow', 20, false, array( 'tr' => 'Çerez kullanmadan ziyaretçi ve dönüşüm analizi.', 'en' => 'Cookie-free visitor and conversion analytics.' ) ),
		'flx-lang'         => array( 'grow', 30, false, array( 'tr' => 'Sayfa ve menüleri İngilizce ve diğer dillere çevirir.', 'en' => 'Translates pages and menus into English and other languages.' ) ),
		'flx-social'       => array( 'grow', 40, false, array( 'tr' => 'Sosyal medya paylaşım takvimi ve onay akışı.', 'en' => 'Social media calendar with an approval flow.' ) ),
		'flx-ai'           => array( 'ai', 10, false, array( 'tr' => 'Ekip için ortak AI modelleri ve günlük bütçe.', 'en' => 'Shared AI models for the team with a daily budget.' ) ),
		'flx-studio'       => array( 'ai', 20, false, array( 'tr' => 'AI ile görsel ve kısa video üretimi.', 'en' => 'Images and short videos generated with AI.' ) ),
		'flx-speed'        => array( 'system', 10, true, array( 'tr' => 'Önbellek, WebP dönüşümü ve sistem kontrolü.', 'en' => 'Caching, WebP conversion and a system check.' ) ),
		'flx-backup'       => array( 'system', 20, true, array( 'tr' => 'Günlük yedek ve tek tıkla geri yükleme.', 'en' => 'Daily backups and one-click restore.' ) ),
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
				'desc'     => self::texts( $saved['desc'] ?? array() ),
			);
		}
		$d = self::PACKAGES[ $slug ] ?? null;
		return $d ? array( 'category' => $d[0], 'order' => $d[1], 'rec' => $d[2], 'desc' => $d[3] ) : array( 'category' => '', 'order' => 999, 'rec' => false, 'desc' => array() );
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
