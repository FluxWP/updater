<?php
/**
 * Panel dili: İngilizce (varsayılan) ve Türkçe. Kaynak metinler Türkçe yazılır,
 * İngilizceleri lib/lang/en.php'dedir. Seçim çerezde (flxu_lang) durur; giriş ekranından
 * ya da üst menüden değiştirilir.
 */

namespace FLXUpdate;

final class I18n {

	const COOKIE    = 'flxu_lang';
	const LANGS     = array( 'en' => 'English', 'tr' => 'Türkçe' );
	const FALLBACK  = 'en';

	/** @var string */
	private static $lang = '';

	/** @var array|null */
	private static $map = null;

	public static function lang(): string {
		if ( '' === self::$lang ) {
			$c          = (string) ( $_COOKIE[ self::COOKIE ] ?? '' );
			self::$lang = isset( self::LANGS[ $c ] ) ? $c : self::default_lang();
		}
		return self::$lang;
	}

	/** Varsayılan dil: FLX_UPDATE_LANG (en|tr), yoksa İngilizce. */
	public static function default_lang(): string {
		$env = strtolower( (string) getenv( 'FLX_UPDATE_LANG' ) );
		return isset( self::LANGS[ $env ] ) ? $env : self::FALLBACK;
	}

	public static function set( string $lang ): void {
		if ( ! isset( self::LANGS[ $lang ] ) ) {
			return;
		}
		self::$lang = $lang;
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$lang,
				array(
					'expires'  => time() + 365 * 86400,
					'path'     => '/',
					'secure'   => ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'],
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
	}

	public static function t( string $tr ): string {
		if ( 'tr' === self::lang() ) {
			return $tr;
		}
		if ( null === self::$map ) {
			$file      = __DIR__ . '/lang/' . self::lang() . '.php';
			self::$map = is_file( $file ) ? (array) require $file : array();
		}
		return isset( self::$map[ $tr ] ) && '' !== self::$map[ $tr ] ? self::$map[ $tr ] : $tr;
	}

	/** Sayı biçimi: en 1,234.5 · tr 1.234,5 */
	public static function num( $x, int $dec = 0 ): string {
		return 'tr' === self::lang() ? number_format( (float) $x, $dec, ',', '.' ) : number_format( (float) $x, $dec, '.', ',' );
	}
}

/** Çeviri: Türkçe kaynak metin → seçili dil. */
function __( string $tr ): string {
	return I18n::t( $tr );
}

/** Çeviri + sprintf. */
function _f( string $tr, ...$args ): string {
	return vsprintf( I18n::t( $tr ), $args );
}
