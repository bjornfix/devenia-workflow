<?php
/**
 * Reader-facing route and customer-action equivalence.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Reader_Surface_Equivalence {
	/**
	 * Extract complete, attribute-scoped reader action identities from HTML.
	 *
	 * @return array<string,array<int,string>>
	 */
	private static function reader_surface_action_values( string $html ): array {
		$values = array( 'href' => array(), 'aria-label' => array(), 'alt' => array() );
		$processor = new WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			foreach ( array_keys( $values ) as $attribute ) {
				$value = $processor->get_attribute( $attribute );
				if ( ! is_string( $value ) ) {
					continue;
				}
				$identity = self::reader_surface_action_identity( $attribute, $value );
				if ( '' !== $identity ) {
					$values[ $attribute ][ $identity ] = $identity;
				}
			}
		}
		foreach ( array_keys( $values ) as $attribute ) {
			$values[ $attribute ] = array_values( $values[ $attribute ] );
		}

		return $values;
	}

	/**
	 * Return one exact comparison identity for an action attribute.
	 */
	private static function reader_surface_action_identity( string $attribute, string $value ): string {
		$value = trim( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $value ) {
			return '';
		}
		if ( 'href' !== $attribute ) {
			return self::normalize_review_text( $value );
		}

		if ( preg_match( '~^(?:https?://[^/"\']+)?/cdn-cgi/l/email-protection#([a-f0-9]+)$~iu', $value, $matches ) ) {
			$decoded = self::reader_surface_decode_cloudflare_email_protection( (string) $matches[1] );
			if ( 1 !== preg_match( '/^(?:mailto:)?[^@\s?]+@[^@\s?]+(?:\?.*)?$/u', $decoded ) ) {
				return '';
			}
			$value = str_starts_with( $decoded, 'mailto:' ) ? $decoded : 'mailto:' . $decoded;
		}

		if ( preg_match( '/^(?:mailto|tel|sms|javascript):/iu', $value ) || '#' === $value[0] ) {
			return $value;
		}

		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$path = isset( $parts['path'] ) && is_string( $parts['path'] ) ? $parts['path'] : '';
		if ( ! empty( $parts['host'] ) && ( '' === $path || '/' === $path ) ) {
			$query = isset( $parts['query'] ) ? '?' . (string) $parts['query'] : '';
			$fragment = isset( $parts['fragment'] ) ? '#' . (string) $parts['fragment'] : '';
			$scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
			$host = strtolower( (string) $parts['host'] );
			$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
			return $scheme . '://' . $host . $port . '/' . $query . $fragment;
		}

		return $value;
	}

	/**
	 * Decode the exact reversible Cloudflare Email Protection byte payload.
	 */
	private static function reader_surface_decode_cloudflare_email_protection( string $encoded ): string {
		if ( 4 > strlen( $encoded ) || 0 !== strlen( $encoded ) % 2 || ! ctype_xdigit( $encoded ) ) {
			return '';
		}

		$key = hexdec( substr( $encoded, 0, 2 ) );
		$decoded = '';
		for ( $offset = 2, $length = strlen( $encoded ); $offset < $length; $offset += 2 ) {
			$decoded .= chr( hexdec( substr( $encoded, $offset, 2 ) ) ^ $key );
		}

		return 1 === preg_match( '//u', $decoded ) && ! preg_match( '/[\x00-\x1F\x7F]/', $decoded ) ? $decoded : '';
	}

	/**
	 * Decode Cloudflare text for visible frontend-integrity inspection.
	 */
	private static function decode_cloudflare_email_protection_text( string $encoded ): string {
		$decoded = self::reader_surface_decode_cloudflare_email_protection( $encoded );
		return '' === $decoded ? '' : trim( rawurldecode( html_entity_decode( $decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * Normalize URLs for localized-route equality while preserving query and fragment.
	 */
	private static function normalized_comparable_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		$path = self::normalized_url_path( $url );
		if ( '' === $path ) {
			return untrailingslashit( $url );
		}

		$parts = wp_parse_url( $url );
		$query = is_array( $parts ) && isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$fragment = is_array( $parts ) && isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

		return trailingslashit( $path ) . $query . $fragment;
	}

	/**
	 * Normalize URL/path to a root-relative path with a leading slash.
	 */
	public static function normalized_url_path( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			$parts = wp_parse_url( $url );
			return is_array( $parts ) && ! empty( $parts['host'] ) ? '/' : '';
		}

		$path = trim( $path, '/' );
		return '' === $path ? '/' : '/' . $path . '/';
	}
}
