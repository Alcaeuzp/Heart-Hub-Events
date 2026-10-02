<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- HeartHub is the established plugin vendor prefix; retain existing class names for integrations.
namespace HeartHub\EventRegistrations;

defined( 'ABSPATH' ) || exit;

final class Public_Page_Theme {
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function security_headers( int $status = 200 ): void {
		status_header( $status );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Referrer-Policy: no-referrer', true );
		header( 'X-Content-Type-Options: nosniff', true );
		header( 'X-Frame-Options: SAMEORIGIN', true );
		header( "Content-Security-Policy: frame-ancestors 'self'", true );
	}

	public function style_tag(): string {
		$accent = $this->colour( 'accent_color', '#35657f' );
		$rules = array(
			'--hh-header'      => $this->colour( 'header_background_color', '#35657f' ),
			'--hh-header-text' => $this->colour( 'header_text_color', '#ffffff' ),
			'--hh-canvas'      => $this->colour( 'page_background_color', '#f4f8fb' ),
			'--hh-surface'     => $this->colour( 'surface_color', '#ffffff' ),
			'--hh-ink'         => $this->colour( 'heading_color', '#16384a' ),
			'--hh-body'        => $this->colour( 'text_color', '#2c4a5a' ),
			'--hh-muted'       => $this->colour( 'muted_color', '#55707f' ),
			'--hh-blue'        => $accent,
			'--hh-blue-dark'   => $this->shade( $accent, -18 ),
			'--hh-blue-soft'   => $this->tint( $accent, 88 ),
			'--hh-blue-light'  => $this->colour( 'header_background_color', '#35657f' ),
			'--hh-button-text' => $this->colour( 'button_text_color', '#ffffff' ),
			'--hh-border'      => $this->colour( 'border_color', '#dbe6ee' ),
		);
		$variables = '';
		foreach ( $rules as $name => $value ) {
			$variables .= $name . ':' . $value . ';';
		}
		$width = min( 300, max( 40, absint( $this->settings->page( 'logo_width', 150 ) ) ) );
		return '<style id="hherm-public-page-theme">:root{' . esc_html( $variables ) . '}.hherm-site-header{color:var(--hh-header-text)!important}.hherm-site-logo{display:block;width:min(' . esc_attr( $width ) . 'px,42vw);max-height:72px;object-fit:contain}.hherm-site-identity{display:flex;align-items:center;gap:14px}.hherm-site-brand-wrap{min-width:0}.hherm-public-button,.hherm-manage-button{color:var(--hh-button-text)!important}</style>';
	}

	public function header( string $label, string $class_name, string $extra_html = '' ): string {
		$header_text = $this->header_text();
		$logo_url = esc_url( (string) $this->settings->page( 'header_logo_url', '' ) );
		$logo_alt = sanitize_text_field( (string) $this->settings->page( 'header_logo_alt', '' ) );
		if ( '' === $logo_alt && '' === $header_text ) {
			$logo_alt = sanitize_text_field( (string) $this->settings->email( 'brand_name', 'Heart Hub South West' ) );
		}
		$logo = $logo_url ? '<img class="hherm-site-logo" src="' . $logo_url . '" alt="' . esc_attr( $logo_alt ) . '">' : '';
		$brand = $header_text ? '<div class="hherm-site-brand">' . esc_html( $header_text ) . '</div>' : '';
		return '<header class="' . esc_attr( $class_name ) . ' hherm-site-header"><div class="hherm-site-identity">' . $logo . '<div class="hherm-site-brand-wrap">' . $brand . '<div class="hherm-site-label">' . esc_html( $label ) . '</div></div></div>' . $extra_html . '</header>';
	}

	public function footer( string $class_name ): string {
		$custom = sanitize_text_field( (string) $this->settings->page( 'footer_text', '' ) );
		if ( $custom ) {
			$text = $custom;
		} else {
			$text = $this->header_text();
		}
		$contact = sanitize_email( $this->settings->get( 'contact_email', '' ) );
		$html = esc_html( $text );
		if ( ! $custom && $contact ) {
			$html .= ( $html ? ' · ' : '' ) . '<a href="mailto:' . esc_attr( $contact ) . '">' . esc_html( $contact ) . '</a>';
		}
		return '<footer class="' . esc_attr( $class_name ) . ' hherm-site-footer">' . $html . '</footer>';
	}

	public function copy( string $key, array $replacements = array() ): string {
		$value = sanitize_textarea_field( (string) $this->settings->page( $key, '' ) );
		$safe = array();
		foreach ( $replacements as $placeholder => $replacement ) {
			$safe[ (string) $placeholder ] = sanitize_text_field( (string) $replacement );
		}
		return strtr( $value, $safe );
	}

	private function header_text(): string {
		$text = sanitize_text_field( (string) $this->settings->page( 'header_text', '' ) );
		return $text ?: sanitize_text_field( (string) $this->settings->email( 'brand_name', 'Heart Hub South West' ) );
	}

	private function colour( string $key, string $fallback ): string {
		$value = sanitize_hex_color( (string) $this->settings->page( $key, '' ) );
		return $value ?: $fallback;
	}

	private function shade( string $hex, int $percent ): string {
		$hex = ltrim( $hex, '#' );
		if ( 6 !== strlen( $hex ) ) {
			return '#244c63';
		}
		$factor = ( 100 + $percent ) / 100;
		$parts = array();
		foreach ( array( 0, 2, 4 ) as $offset ) {
			$parts[] = str_pad( dechex( min( 255, max( 0, (int) round( hexdec( substr( $hex, $offset, 2 ) ) * $factor ) ) ) ), 2, '0', STR_PAD_LEFT );
		}
		return '#' . implode( '', $parts );
	}

	private function tint( string $hex, int $percent ): string {
		$hex = ltrim( $hex, '#' );
		if ( 6 !== strlen( $hex ) ) {
			return '#eaf2f8';
		}
		$ratio = min( 100, max( 0, $percent ) ) / 100;
		$parts = array();
		foreach ( array( 0, 2, 4 ) as $offset ) {
			$channel = hexdec( substr( $hex, $offset, 2 ) );
			$parts[] = str_pad( dechex( (int) round( $channel + ( 255 - $channel ) * $ratio ) ), 2, '0', STR_PAD_LEFT );
		}
		return '#' . implode( '', $parts );
	}
}
