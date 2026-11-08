<?php
/** GitHub-owned package metadata mirrored into protected updater storage. */
namespace FLXUpdate;

final class Previews {
	private $dir;
	public function __construct( string $dir ) { $this->dir = rtrim( $dir, '/' ); }
	public static function locale( string $locale ): bool { return (bool) preg_match( '/^[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,2}$/D', $locale ); }
	private function file( string $repo ): string { return $this->dir . '/preview-catalogs/' . hash( 'sha256', strtolower( $repo ) ) . '.json'; }
	private function write( string $file, array $value ): void {
		$dir = dirname( $file ); if ( ! is_dir( $dir ) ) { mkdir( $dir, 0750, true ); }
		$tmp = tempnam( $dir, 'save-' );
		if ( false === $tmp || false === file_put_contents( $tmp, json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) ) ) { throw new \RuntimeException( 'Could not cache package previews' ); }
		chmod( $tmp, 0640 ); if ( ! rename( $tmp, $file ) ) { unlink( $tmp ); throw new \RuntimeException( 'Could not replace package preview cache' ); }
	}
	public function cached( string $repo ): array {
		$file = $this->file( $repo ); return is_file( $file ) ? (array) json_decode( (string) file_get_contents( $file ), true ) : array();
	}
	public function read( string $slug, string $repo ): array { return (array) ( $this->cached( $repo )['packages'][ $slug ] ?? array() ); }
	public function checked( string $repo, string $error = '' ): void {
		$data = $this->cached( $repo ); $data['checked_at'] = time(); $data['error'] = $error;
		$this->write( $this->file( $repo ), $data );
	}
	public function invalidate(): void {
		foreach ( glob( $this->dir . '/preview-catalogs/*.json' ) ?: array() as $file ) {
			$data = (array) json_decode( (string) file_get_contents( $file ), true ); $data['checked_at'] = 0; $this->write( $file, $data );
		}
	}
	private function texts( $value, bool $website = false ): array {
		if ( ! is_array( $value ) || count( $value ) > 50 ) { throw new \InvalidArgumentException( 'Invalid localized preview metadata' ); }
		$out = array();
		foreach ( $value as $locale => $text ) {
			if ( ! self::locale( (string) $locale ) || ! is_string( $text ) || strlen( $text ) > ( $website ? 2048 : 4000 ) ) { throw new \InvalidArgumentException( 'Invalid localized preview text' ); }
			$text = trim( $text );
			if ( $website && '' !== $text ) {
				$parts = parse_url( $text );
				if ( ! filter_var( $text, FILTER_VALIDATE_URL ) || 'https' !== ( $parts['scheme'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) { throw new \InvalidArgumentException( 'Preview website must use HTTPS without credentials' ); }
			}
			$out[ $locale ] = $website ? $text : strip_tags( $text );
		}
		return $out;
	}
	private function reference( string $repo, string $revision, string $slug, $ref, bool $icon, bool $public ): array {
		if ( ! is_array( $ref ) || ! preg_match( '/^[a-f0-9]{40}$/D', (string) ( $ref['sha'] ?? '' ) ) || ! is_int( $ref['size'] ?? null ) || $ref['size'] < 1 || $ref['size'] > 8 * 1024 * 1024 ) { throw new \InvalidArgumentException( 'Invalid GitHub preview asset' ); }
		$path = (string) ( $ref['path'] ?? '' );
		if ( ! preg_match( '#^(?:plugins|themes)/' . preg_quote( $slug, '#' ) . '/[A-Za-z0-9_./-]+\.(jpg|png|webp|svg)$#D', $path, $match ) || in_array( '..', explode( '/', $path ), true ) || in_array( '.', explode( '/', $path ), true ) || ( ! $icon && 'svg' === $match[1] ) ) { throw new \InvalidArgumentException( 'Preview assets must belong to their package' ); }
		$id = hash( 'sha256', strtolower( $repo ) . '|' . $ref['sha'] . '|' . ( $icon ? 'icon' : 'gallery' ) ) . '.' . $match[1];
		return array( 'id' => $id, 'repo' => $repo, 'revision' => $revision, 'sha' => $ref['sha'], 'path' => $path, 'size' => $ref['size'], 'icon' => $icon, 'public' => $public );
	}
	/** Validate a complete repository manifest before atomically replacing its last good cache. */
	public function store( string $repo, string $revision, array $manifest, bool $public ): void {
		if ( ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $repo ) || ! preg_match( '/^[a-f0-9]{40}$/D', $revision ) || 1 !== ( $manifest['schema'] ?? null ) || ! is_array( $manifest['packages'] ?? null ) || count( $manifest['packages'] ) > 200 ) { throw new \InvalidArgumentException( 'Invalid GitHub preview manifest' ); }
		$packages = array();
		foreach ( $manifest['packages'] as $slug => $package ) {
			if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/D', (string) $slug ) || ! is_array( $package ) ) { throw new \InvalidArgumentException( 'Invalid preview package' ); }
			$out = array( 'desc' => $this->texts( $package['desc'] ?? array() ), 'website' => $this->texts( $package['website'] ?? array(), true ), 'gallery' => array(), 'icons' => array(), 'assets' => array() );
			if ( ! is_array( $package['gallery'] ?? array() ) || count( $package['gallery'] ?? array() ) > 50 || ! is_array( $package['icons'] ?? array() ) ) { throw new \InvalidArgumentException( 'Invalid preview gallery' ); }
			foreach ( $package['gallery'] ?? array() as $locale => $references ) {
				if ( ! self::locale( (string) $locale ) || ! is_array( $references ) || count( $references ) > 12 ) { throw new \InvalidArgumentException( 'Invalid preview gallery locale' ); }
				foreach ( $references as $ref ) {
					$asset = $this->reference( $repo, $revision, $slug, $ref, false, $public ); $out['assets'][ $asset['id'] ] = $asset; $out['gallery'][ $locale ][] = $asset['id'];
				}
				$out['gallery'][ $locale ] = array_values( array_unique( $out['gallery'][ $locale ] ?? array() ) );
			}
			foreach ( $package['icons'] ?? array() as $kind => $ref ) {
				if ( ! in_array( $kind, array( 'svg', 'pastel' ), true ) ) { throw new \InvalidArgumentException( 'Invalid preview icon kind' ); }
				$asset = $this->reference( $repo, $revision, $slug, $ref, true, $public ); $out['assets'][ $asset['id'] ] = $asset; $out['icons'][ $kind ] = $asset['id'];
			}
			$packages[ $slug ] = $out;
		}
		$this->write( $this->file( $repo ), array( 'repo' => $repo, 'revision' => $revision, 'synced_at' => time(), 'checked_at' => time(), 'error' => '', 'packages' => $packages ) );
	}
	public function path( string $id ): string {
		if ( ! preg_match( '/^[a-f0-9]{64}\.(?:jpg|png|webp|svg)$/D', $id ) ) { return ''; }
		$file = $this->dir . '/preview-media/' . $id;
		return is_file( $file ) && ! is_link( $file ) ? $file : '';
	}
	private function svg( string $bytes ): string {
		if ( strlen( $bytes ) > 512 * 1024 || preg_match( '/<!DOCTYPE|<!ENTITY/i', $bytes ) ) { throw new \InvalidArgumentException( 'Invalid SVG icon' ); }
		$doc = new \DOMDocument(); $previous = libxml_use_internal_errors( true );
		try { $ok = $doc->loadXML( $bytes, LIBXML_NONET ); } finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
		$root = $doc->documentElement;
		if ( ! $ok || ! $root || 'svg' !== $root->localName || 'http://www.w3.org/2000/svg' !== $root->namespaceURI ) { throw new \InvalidArgumentException( 'Invalid SVG icon' ); }
		$tags = array( 'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'defs', 'linearGradient', 'radialGradient', 'stop', 'title', 'desc', 'clipPath', 'mask', 'use', 'symbol' );
		$attributes = array( 'xmlns', 'id', 'viewBox', 'width', 'height', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points', 'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-opacity', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-miterlimit', 'opacity', 'transform', 'gradientUnits', 'gradientTransform', 'offset', 'stop-color', 'stop-opacity', 'clip-path', 'clip-rule', 'mask', 'maskUnits', 'preserveAspectRatio', 'href' );
		foreach ( iterator_to_array( $doc->getElementsByTagName( '*' ) ) as $element ) {
			if ( ! in_array( $element->localName, $tags, true ) || 'http://www.w3.org/2000/svg' !== $element->namespaceURI ) { if ( $element->parentNode ) { $element->parentNode->removeChild( $element ); } continue; }
			foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
				$value = $attribute->value;
				$unsafe_url = false !== stripos( $value, 'url(' ) && ! preg_match( '/^url\(#[A-Za-z_][A-Za-z0-9_.:-]*\)$/D', trim( $value ) );
				if ( ! in_array( $attribute->localName, $attributes, true ) || $unsafe_url || ( 'href' === $attribute->localName && ! preg_match( '/^#[A-Za-z_][A-Za-z0-9_.:-]*$/D', $value ) ) || ( 'xmlns' !== $attribute->localName && preg_match( '#(?:https?:|data:|javascript:)#i', $value ) ) ) { $element->removeAttributeNode( $attribute ); }
			}
		}
		$xpath = new \DOMXPath( $doc );
		foreach ( iterator_to_array( $xpath->query( '//comment() | //processing-instruction()' ) ) as $node ) { if ( $node->parentNode ) { $node->parentNode->removeChild( $node ); } }
		return (string) $doc->saveXML( $root );
	}
	/** Raw GitHub bytes are verified against the immutable blob before image decoding. */
	public function cache_media( array $asset, string $bytes ): string {
		if ( strlen( $bytes ) !== $asset['size'] || ! hash_equals( $asset['sha'], sha1( 'blob ' . strlen( $bytes ) . "\0" . $bytes ) ) ) { throw new \InvalidArgumentException( 'GitHub image integrity check failed' ); }
		if ( ! preg_match( '/^[a-f0-9]{64}\.(?:jpg|png|webp|svg)$/D', (string) ( $asset['id'] ?? '' ) ) || strlen( $bytes ) > 8 * 1024 * 1024 ) { throw new \InvalidArgumentException( 'Invalid preview media identifier' ); }
		$ext = pathinfo( $asset['id'], PATHINFO_EXTENSION );
		if ( 'svg' === $ext ) {
			if ( empty( $asset['icon'] ) ) { throw new \InvalidArgumentException( 'SVG is allowed for icons only' ); }
			$clean = $this->svg( $bytes );
		} else {
			$info = @getimagesizefromstring( $bytes ); $expected = array( 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' )[ $ext ] ?? '';
			if ( ! $info || $info['mime'] !== $expected || $info[0] < 16 || $info[1] < 16 || $info[0] * $info[1] > 16000000 ) { throw new \InvalidArgumentException( 'Invalid preview raster image' ); }
			$image = @imagecreatefromstring( $bytes ); if ( ! $image ) { throw new \InvalidArgumentException( 'Could not decode preview image' ); }
			ob_start(); if ( 'jpg' === $ext ) { imagejpeg( $image, null, 90 ); } elseif ( 'png' === $ext ) { imagepng( $image ); } else { imagewebp( $image, null, 90 ); }
			$clean = ob_get_clean(); imagedestroy( $image ); if ( ! $clean ) { throw new \RuntimeException( 'Could not encode preview image' ); }
		}
		$dir = $this->dir . '/preview-media'; if ( ! is_dir( $dir ) ) { mkdir( $dir, 0750, true ); }
		$file = $dir . '/' . $asset['id']; $temp = tempnam( $dir, 'media-' );
		if ( false === $temp || false === file_put_contents( $temp, $clean ) ) { throw new \RuntimeException( 'Could not cache preview image' ); }
		chmod( $temp, 0640 ); if ( ! rename( $temp, $file ) ) { unlink( $temp ); throw new \RuntimeException( 'Could not replace preview image cache' ); }
		return $file;
	}
}
