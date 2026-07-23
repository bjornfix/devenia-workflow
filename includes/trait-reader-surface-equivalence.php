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
	 * Read values only from semantic template-owned title/excerpt hosts.
	 *
	 * Ordinary content headings are intentionally not title hosts. This lets a
	 * content-owned H1 coexist with an administrative post title while still
	 * failing closed when a theme or Post Title/Excerpt block renders stale
	 * field bytes on the canonical response.
	 *
	 * @return array{title:array<int,string>,excerpt:array<int,string>}
	 */
	private static function reader_surface_template_field_values( string $html ): array {
		$values = array( 'title' => array(), 'excerpt' => array() );
		$contracts = array(
			'title' => array(
				'classes'  => array( 'entry-title', 'wp-block-post-title' ),
				'itemprops' => array( 'headline' ),
			),
			'excerpt' => array(
				'classes'  => array( 'entry-summary', 'wp-block-post-excerpt__excerpt' ),
				'itemprops' => array(),
			),
		);
		$hidden_tags = array( 'head', 'script', 'style', 'template', 'noscript' );
		$void_tags = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );
		$stack = array();
		$finish = static function ( array $frame ) use ( &$values ): void {
			$field = (string) ( $frame['field'] ?? '' );
			if ( '' === $field || ! empty( $frame['hidden'] ) ) {
				return;
			}
			$text = self::normalize_review_text( html_entity_decode( (string) ( $frame['text'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			$values[ $field ][] = $text;
		};

		preg_match_all( "~<!--.*?-->|<![^>]*>|<(?:\"[^\"]*\"|'[^']*'|[^'\">])*>|[^<]+~su", $html, $tokens );
		foreach ( (array) ( $tokens[0] ?? array() ) as $token ) {
			$token = (string) $token;
			if ( '' === $token || '<' !== $token[0] ) {
				if ( ! empty( $stack ) && ! empty( $stack[ array_key_last( $stack ) ]['hidden'] ) ) {
					continue;
				}
				foreach ( $stack as &$frame ) {
					if ( '' !== (string) ( $frame['field'] ?? '' ) && empty( $frame['hidden'] ) ) {
						$frame['text'] .= $token;
					}
				}
				unset( $frame );
				continue;
			}
			if ( str_starts_with( $token, '<!' ) ) {
				continue;
			}
			if ( preg_match( '~^</\s*([a-z][a-z0-9:-]*)~iu', $token, $closing ) ) {
				$tag = strtolower( (string) $closing[1] );
				$match_index = null;
				for ( $index = count( $stack ) - 1; $index >= 0; --$index ) {
					if ( $tag === (string) $stack[ $index ]['tag'] ) {
						$match_index = $index;
						break;
					}
				}
				if ( null !== $match_index ) {
					while ( count( $stack ) > $match_index ) {
						$finish( array_pop( $stack ) );
					}
				}
				continue;
			}
			if ( ! preg_match( '~^<\s*([a-z][a-z0-9:-]*)\b(.*?)>$~isu', $token, $opening ) ) {
				continue;
			}
			$tag = strtolower( (string) $opening[1] );
			$attributes = (string) $opening[2];
			$class_tokens = array();
			$itemprop_tokens = array();
			if ( preg_match( '/\bclass\s*=\s*(["\'])(.*?)\1/isu', $attributes, $class_match ) ) {
				$class_tokens = preg_split( '/\s+/u', trim( (string) $class_match[2] ) ) ?: array();
			}
			if ( preg_match( '/\bitemprop\s*=\s*(["\'])(.*?)\1/isu', $attributes, $itemprop_match ) ) {
				$itemprop_tokens = preg_split( '/\s+/u', trim( (string) $itemprop_match[2] ) ) ?: array();
			}
			$field = '';
			foreach ( $contracts as $candidate => $contract ) {
				if ( array_intersect( $contract['classes'], $class_tokens ) || array_intersect( $contract['itemprops'], $itemprop_tokens ) ) {
					$field = $candidate;
					break;
				}
			}
			if ( in_array( $tag, $void_tags, true ) || in_array( $tag, $hidden_tags, true ) ) {
				$field = '';
			}
			$parent_hidden = ! empty( $stack ) && ! empty( $stack[ array_key_last( $stack ) ]['hidden'] );
			$native_hidden = 1 === preg_match( '/(?:^|\s)hidden(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?(?=\s|\/?$)/iu', trim( $attributes ) );
			$frame = array(
				'tag'    => $tag,
				'hidden' => $parent_hidden || $native_hidden || in_array( $tag, $hidden_tags, true ),
				'field'  => $field,
				'text'   => '',
			);
			if ( str_ends_with( trim( $attributes ), '/' ) || in_array( $tag, $void_tags, true ) ) {
				$finish( $frame );
			} else {
				$stack[] = $frame;
			}
		}
		while ( $stack ) {
			$finish( array_pop( $stack ) );
		}
		foreach ( array_keys( $values ) as $field ) {
			$values[ $field ] = array_values( array_unique( $values[ $field ] ) );
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
			// Cloudflare encodes the serialized HTML attribute, so query
			// separators may still be entity-encoded after the XOR reversal.
			$decoded = html_entity_decode( $decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
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
