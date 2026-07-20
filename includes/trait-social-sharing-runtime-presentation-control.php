<?php
/**
 * Devenia Social Sharing Runtime Presentation Control.
 *
 * Workflow consumes only the sharing plugin's public manifest and filters. The
 * sharing plugin owns placement, networks, markup, and variable configuration;
 * Workflow owns target-language runtime text and Canonical Route values.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Social_Sharing_Runtime_Presentation_Control {
	/** Explicit language while asking the owner for a readiness/assertion manifest. */
	private static $social_sharing_manifest_language = '';

	/** Register the owned sharing Adapter filters. */
	private static function register_social_sharing_runtime_presentation_control(): void {
		add_filter( 'devenia_social_sharing_heading', array( __CLASS__, 'localize_social_sharing_heading' ), 20, 4 );
		add_filter( 'devenia_social_sharing_current_language', array( __CLASS__, 'filter_social_sharing_current_language' ), 20, 4 );
		add_filter( 'devenia_social_sharing_get_permalink', array( __CLASS__, 'canonicalize_social_sharing_permalink' ), 20, 4 );
		add_filter( 'devenia_social_sharing_email_subject', array( __CLASS__, 'localize_social_sharing_email_subject' ), 20, 4 );
		add_filter( 'devenia_social_sharing_email_body', array( __CLASS__, 'localize_social_sharing_email_body' ), 20, 4 );
		add_filter( 'devenia_social_sharing_accessible_label', array( __CLASS__, 'localize_social_sharing_accessible_label' ), 20, 6 );
		add_filter( 'devenia_social_sharing_network_label', array( __CLASS__, 'localize_social_sharing_network_label' ), 20, 6 );
		add_filter( 'scriptlesssocialsharing_email_subject', array( __CLASS__, 'localize_legacy_scriptless_email_subject' ), 20, 1 );
		add_filter( 'scriptlesssocialsharing_email_body', array( __CLASS__, 'localize_legacy_scriptless_email_body' ), 20, 1 );
	}

	/** Whether any part of the owned plugin's public Interface is active. */
	private static function social_sharing_owner_active(): bool {
		return function_exists( 'devenia_social_sharing_get_setting' ) || function_exists( 'devenia_social_sharing_get_surface_manifest' );
	}

	/** Whether the complete public Interface required by Workflow is available. */
	private static function social_sharing_owner_interface_ready(): bool {
		return function_exists( 'devenia_social_sharing_get_setting' ) && function_exists( 'devenia_social_sharing_get_surface_manifest' );
	}

	/** Read the configured default heading through the public settings Interface. */
	private static function social_sharing_default_heading() {
		if ( ! self::social_sharing_owner_interface_ready() ) {
			return null;
		}

		try {
			$heading = devenia_social_sharing_get_setting( 'heading' );
		} catch ( Throwable $error ) {
			return null;
		}

		return is_scalar( $heading ) ? (string) $heading : null;
	}

	/** Resolve one fully qualified semantic runtime key. */
	private static function social_sharing_runtime_text( string $language, string $runtime_key ): string {
		$runtime_key = trim( $runtime_key );
		if ( 0 !== strpos( $runtime_key, 'share_text.' ) ) {
			return '';
		}

		$key = substr( $runtime_key, strlen( 'share_text.' ) );
		if ( ! is_string( $key ) || ! preg_match( '/^social_sharing_[a-z0-9_.-]+$/', $key ) ) {
			return '';
		}

		return trim( self::runtime_text_value( sanitize_key( $language ), 'share_text', $key, '' ) );
	}

	/**
	 * Validate the public owner manifest without deriving any owner semantics.
	 *
	 * @param WP_Post|null $post Concrete post or null for conservative mode.
	 * @return array<string,mixed>
	 */
	private static function social_sharing_surface_manifest( $post, ?string $default_heading = null, string $language = '' ): array {
		$error_manifest = array(
			'state'                       => 'error',
			'error'                       => 'owner_interface_unavailable',
			'applicable'                  => false,
			'headings'                    => array(),
			'default_heading'             => '',
			'default_heading_occurrences' => 0,
			'automatic_before'            => false,
			'automatic_after'             => false,
			'embedded_count'              => 0,
			'runtime_text_keys'           => array(),
			'applicable_post_types'       => array(),
		);
		if ( ! self::social_sharing_owner_interface_ready() ) {
			return $error_manifest;
		}

		$previous_language = self::$social_sharing_manifest_language;
		self::$social_sharing_manifest_language = sanitize_key( $language );
		try {
			$manifest = devenia_social_sharing_get_surface_manifest( $post, $default_heading );
		} catch ( Throwable $error ) {
			$error_manifest['error'] = 'owner_manifest_exception';
			return $error_manifest;
		} finally {
			self::$social_sharing_manifest_language = $previous_language;
		}
		if ( ! is_array( $manifest ) ) {
			$error_manifest['error'] = 'owner_manifest_invalid';
			return $error_manifest;
		}
		$raw_post_types = $manifest['applicable_post_types'] ?? null;
		if ( ! is_array( $raw_post_types ) || array_values( $raw_post_types ) !== $raw_post_types ) {
			$error_manifest['error'] = 'owner_manifest_post_types_invalid';
			return $error_manifest;
		}
		foreach ( $raw_post_types as $raw_post_type ) {
			if ( ! is_string( $raw_post_type ) || '' === $raw_post_type || sanitize_key( $raw_post_type ) !== $raw_post_type ) {
				$error_manifest['error'] = 'owner_manifest_post_types_invalid';
				return $error_manifest;
			}
		}
		$sanitized_post_types = array_values( array_unique( $raw_post_types ) );
		if ( count( $sanitized_post_types ) !== count( $raw_post_types ) ) {
			$error_manifest['error'] = 'owner_manifest_post_types_invalid';
			return $error_manifest;
		}
		$error_manifest['applicable_post_types'] = $sanitized_post_types;
		$raw_runtime_keys = $manifest['runtime_text_keys'] ?? array();
		if ( is_array( $raw_runtime_keys ) && array_values( $raw_runtime_keys ) === $raw_runtime_keys ) {
			$runtime_keys_valid = true;
			foreach ( $raw_runtime_keys as $runtime_key ) {
				if ( ! is_string( $runtime_key ) || 0 !== strpos( $runtime_key, 'share_text.social_sharing_' ) || ! preg_match( '/^share_text\.social_sharing_[a-z0-9_.-]+$/', $runtime_key ) ) {
					$runtime_keys_valid = false;
					break;
				}
			}
			$validated_runtime_keys = $runtime_keys_valid ? array_values( array_unique( $raw_runtime_keys ) ) : array();
			if ( $runtime_keys_valid && count( $validated_runtime_keys ) === count( $raw_runtime_keys ) ) {
				$error_manifest['runtime_text_keys'] = $validated_runtime_keys;
			}
		}

		$state = sanitize_key( (string) ( $manifest['state'] ?? '' ) );
		if ( ! in_array( $state, array( 'ready', 'conservative' ), true ) ) {
			$error_manifest['error'] = sanitize_key( (string) ( $manifest['error'] ?? 'owner_manifest_error' ) ) ?: 'owner_manifest_error';
			return $error_manifest;
		}
		if ( 'ready' === $state && ! $post instanceof WP_Post ) {
			$error_manifest['error'] = 'owner_manifest_context_mismatch';
			return $error_manifest;
		}
		if ( 'conservative' === $state && null !== $post ) {
			$error_manifest['error'] = 'owner_manifest_context_mismatch';
			return $error_manifest;
		}

		$headings = $manifest['headings'] ?? null;
		$runtime_keys = $manifest['runtime_text_keys'] ?? null;
		if ( ! is_array( $headings ) || array_values( $headings ) !== $headings || ! is_array( $runtime_keys ) || array_values( $runtime_keys ) !== $runtime_keys ) {
			$error_manifest['error'] = 'owner_manifest_invalid';
			return $error_manifest;
		}
		foreach ( $headings as $heading ) {
			if ( ! is_string( $heading ) ) {
				$error_manifest['error'] = 'owner_manifest_invalid';
				return $error_manifest;
			}
		}
		$runtime_keys = (array) $error_manifest['runtime_text_keys'];
		if ( $runtime_keys !== $manifest['runtime_text_keys'] ) {
			$error_manifest['error'] = 'owner_manifest_runtime_key_invalid';
			return $error_manifest;
		}
		foreach ( $runtime_keys as $runtime_key ) {
			if ( ! is_string( $runtime_key ) || 0 !== strpos( $runtime_key, 'share_text.social_sharing_' ) || ! preg_match( '/^share_text\.social_sharing_[a-z0-9_.-]+$/', $runtime_key ) ) {
				$error_manifest['error'] = 'owner_manifest_runtime_key_invalid';
				return $error_manifest;
			}
		}

		$default_heading = $manifest['default_heading'] ?? null;
		$default_occurrences = $manifest['default_heading_occurrences'] ?? null;
		$embedded_count = $manifest['embedded_count'] ?? null;
		if ( ! is_string( $default_heading ) || ! is_int( $default_occurrences ) || $default_occurrences < 0 || ! is_int( $embedded_count ) || $embedded_count < 0 || ! is_bool( $manifest['applicable'] ?? null ) || ! is_bool( $manifest['automatic_before'] ?? null ) || ! is_bool( $manifest['automatic_after'] ?? null ) ) {
			$error_manifest['error'] = 'owner_manifest_invalid';
			return $error_manifest;
		}
		$default_occurrences = absint( $default_occurrences );
		$embedded_count      = absint( $embedded_count );
		if ( $default_occurrences > count( $headings ) ) {
			$error_manifest['error'] = 'owner_manifest_cardinality_invalid';
			return $error_manifest;
		}

		return array(
			'state'                       => $state,
			'error'                       => null,
			'applicable'                  => (bool) ( $manifest['applicable'] ?? false ),
			'headings'                    => array_map( 'strval', $headings ),
			'default_heading'             => (string) $default_heading,
			'default_heading_occurrences' => $default_occurrences,
			'automatic_before'            => (bool) ( $manifest['automatic_before'] ?? false ),
			'automatic_after'             => (bool) ( $manifest['automatic_after'] ?? false ),
			'embedded_count'              => $embedded_count,
			'runtime_text_keys'           => $runtime_keys,
			'applicable_post_types'       => $sanitized_post_types,
		);
	}

	/** Resolve one public post for rendered-surface assertions. */
	private static function social_sharing_post_id( string $url, int $post_id = 0 ): int {
		if ( $post_id && get_post( $post_id ) ) {
			return $post_id;
		}
		$parts = wp_parse_url( $url );
		return self::wordpress_content_id_from_internal_url( $url, is_array( $parts ) ? $parts : array() );
	}

	/**
	 * Runtime readiness contribution for one language/content pair.
	 *
	 * @param WP_Post|int|null $post_context Concrete post, or null for the owner's conservative manifest.
	 * @return array<string,mixed>
	 */
	private static function social_sharing_runtime_presentation_readiness( string $language, string $post_type, $post_context = null ): array {
		$language          = sanitize_key( $language );
		$post_type         = sanitize_key( $post_type );
		$active            = self::social_sharing_owner_active();
		$context_requested = $post_context instanceof WP_Post || ( is_numeric( $post_context ) && 0 < absint( $post_context ) );
		$post              = $post_context instanceof WP_Post ? $post_context : ( $context_requested ? get_post( absint( $post_context ) ) : null );
		$context_valid     = ! $context_requested || ( $post instanceof WP_Post && sanitize_key( (string) $post->post_type ) === $post_type );
		$manifest          = array();

		if ( $active && self::social_sharing_owner_interface_ready() && $context_valid ) {
			$manifest = self::social_sharing_surface_manifest( $context_requested ? $post : null, self::social_sharing_default_heading(), $language );
		}

		$unavailable = $active && ( ! self::social_sharing_owner_interface_ready() || ! $context_valid || 'error' === (string) ( $manifest['state'] ?? 'error' ) );
		$runtime_keys = ! $active ? array() : (array) ( $manifest['runtime_text_keys'] ?? array() );
		if ( ! $context_requested && ! in_array( $post_type, (array) ( $manifest['applicable_post_types'] ?? array() ), true ) ) {
			$runtime_keys = array();
		}
		$missing      = array();
		foreach ( $runtime_keys as $runtime_key ) {
			if ( '' === self::social_sharing_runtime_text( $language, (string) $runtime_key ) ) {
				$missing[] = (string) $runtime_key;
			}
		}
		if ( $unavailable ) {
			$missing[] = 'social_sharing.owner_interface';
		}

		return array(
			'required'      => ! empty( $runtime_keys ) || $unavailable,
			'configured'    => empty( $missing ),
			'runtime_keys'  => $runtime_keys,
			'missing'       => array_values( array_unique( $missing ) ),
			'owner_state'   => ! $active ? 'inactive' : ( $unavailable ? 'unavailable' : ( $runtime_keys ? 'required' : 'not_required' ) ),
			'context_mode'  => $context_requested ? 'concrete_post' : 'conservative_global',
			'post_id'       => $post instanceof WP_Post ? (int) $post->ID : 0,
			'manifest_state' => (string) ( $manifest['state'] ?? ( $active ? 'error' : 'inactive' ) ),
		);
	}

	/** Resolve a target-language runtime value or return null to fail closed. */
	private static function localized_social_sharing_runtime_value( $value, string $language, string $runtime_key ) {
		$value    = is_scalar( $value ) ? (string) $value : '';
		$language = sanitize_key( $language );
		if ( ! self::is_configured_content_language( $language ) ) {
			return null;
		}
		if ( self::source_language_code() === $language && '' !== trim( $value ) ) {
			return $value;
		}
		$localized = self::social_sharing_runtime_text( $language, $runtime_key );
		return '' !== $localized ? $localized : null;
	}

	/** Keep the owner manifest and rendering filters on Workflow's exact language context. */
	public static function filter_social_sharing_current_language( $language, $post = null, $context = '', $locale = '' ): string {
		if ( '' !== self::$social_sharing_manifest_language ) {
			return self::$social_sharing_manifest_language;
		}
		if ( $post instanceof WP_Post ) {
			$post_language = sanitize_key( (string) get_post_meta( (int) $post->ID, self::META_LANGUAGE, true ) );
			if ( self::is_configured_content_language( $post_language ) ) {
				return $post_language;
			}
		}
		$language = sanitize_key( (string) $language );
		if ( self::is_configured_content_language( $language ) ) {
			return $language;
		}
		$frontend_language = sanitize_key( self::frontend_language() );
		return self::is_configured_content_language( $frontend_language ) ? $frontend_language : self::source_language_code();
	}

	/** Public heading filter Adapter. */
	public static function localize_social_sharing_heading( $heading, $post = null, $context = '', $language = '' ) {
		return self::localized_social_sharing_runtime_value( $heading, (string) $language, 'share_text.social_sharing_heading' );
	}

	/** Public email-subject filter Adapter. */
	public static function localize_social_sharing_email_subject( $text, $post = null, $context = '', $language = '' ) {
		return self::localized_social_sharing_runtime_value( $text, (string) $language, 'share_text.social_sharing_email_subject' );
	}

	/** Public email-body filter Adapter. */
	public static function localize_social_sharing_email_body( $text, $post = null, $context = '', $language = '' ) {
		$localized = self::localized_social_sharing_runtime_value( $text, (string) $language, 'share_text.social_sharing_email_body' );
		return is_string( $localized ) && 1 === substr_count( $localized, '{url}' ) ? $localized : null;
	}

	/**
	 * Resolve the legacy Scriptless Social Sharing runtime field through the same
	 * target-language Presentation Text registry as the current sharing owner.
	 *
	 * @param mixed $value Existing owner value.
	 */
	private static function legacy_scriptless_social_sharing_runtime_value( $value, string $runtime_key, string $language = '' ): string {
		$value    = is_scalar( $value ) ? (string) $value : '';
		$language = sanitize_key( '' !== $language ? $language : self::frontend_language() );
		if ( ! self::is_configured_content_language( $language ) ) {
			return $value;
		}

		$localized = trim( self::runtime_text_value( $language, 'share_text', $runtime_key, '' ) );
		return '' !== $localized ? $localized : $value;
	}

	/** Legacy Scriptless email-subject Adapter. */
	public static function localize_legacy_scriptless_email_subject( $text ): string {
		return self::legacy_scriptless_social_sharing_runtime_value( $text, 'scriptless_email_subject_prefix' );
	}

	/**
	 * Legacy Scriptless email-body Adapter.
	 *
	 * Scriptless appends its permalink after this filter, while the shared runtime
	 * field owns one `{url}` placeholder. Remove only that placeholder at the
	 * Adapter boundary so the owner still emits exactly one canonical link.
	 */
	public static function localize_legacy_scriptless_email_body( $text ): string {
		$localized = self::legacy_scriptless_social_sharing_runtime_value( $text, 'scriptless_email_body' );
		return trim( str_replace( '{url}', '', $localized ) );
	}

	/** Public per-network accessible-label filter Adapter. */
	public static function localize_social_sharing_accessible_label( $text, $post = null, $context = '', $language = '', $network = '', $network_label = '' ) {
		$network = sanitize_key( (string) $network );
		if ( '' === $network ) {
			return null;
		}
		$localized = self::localized_social_sharing_runtime_value( $text, (string) $language, 'share_text.social_sharing_accessible_label.' . $network );
		return is_string( $localized ) && ! self::social_sharing_has_sprintf_string_placeholder( $localized ) ? $localized : null;
	}

	/**
	 * Detect executable sprintf string placeholders in an already-complete label.
	 *
	 * Escaped percent pairs are ordinary text. The bounded pattern rejects bare,
	 * positional, flagged, width, and precision string conversions without
	 * treating unrelated percent prose as a placeholder.
	 */
	private static function social_sharing_has_sprintf_string_placeholder( string $value ): bool {
		$length = strlen( $value );
		for ( $index = 0; $index < $length; ++$index ) {
			if ( '%' !== $value[ $index ] ) {
				continue;
			}
			if ( $index + 1 < $length && '%' === $value[ $index + 1 ] ) {
				++$index;
				continue;
			}

			$cursor = $index + 1;
			$digits = $cursor;
			while ( $cursor < $length && ctype_digit( $value[ $cursor ] ) ) {
				++$cursor;
			}
			$position = ltrim( substr( $value, $digits, $cursor - $digits ), '0' );
			$position_is_supported = '' !== $position
				&& ( strlen( $position ) < 10 || ( 10 === strlen( $position ) && 0 >= strcmp( $position, '2147483646' ) ) );
			if ( $cursor === $digits || $cursor >= $length || '$' !== $value[ $cursor ] || ! $position_is_supported ) {
				$cursor = $index + 1;
			} else {
				++$cursor;
			}

			while ( $cursor < $length ) {
				if ( false !== strpos( '-+0 ', $value[ $cursor ] ) ) {
					++$cursor;
					continue;
				}
				if ( "'" === $value[ $cursor ] && $cursor + 1 < $length ) {
					$cursor += 2;
					continue;
				}
				break;
			}

			while ( $cursor < $length && ctype_digit( $value[ $cursor ] ) ) {
				++$cursor;
			}
			if ( $cursor < $length && '.' === $value[ $cursor ] ) {
				++$cursor;
				while ( $cursor < $length && ctype_digit( $value[ $cursor ] ) ) {
					++$cursor;
				}
			}

			if ( $cursor < $length && 's' === $value[ $cursor ] ) {
				return true;
			}
		}

		return false;
	}

	/** Public non-brand network-label filter Adapter. */
	public static function localize_social_sharing_network_label( $text, $post = null, $context = '', $language = '', $network = '', $network_label = '' ) {
		$network = sanitize_key( (string) $network );
		if ( '' === $network ) {
			return null;
		}
		if ( is_scalar( $text ) && '' !== trim( (string) $text ) ) {
			return (string) $text;
		}
		return self::localized_social_sharing_runtime_value( $text, (string) $language, 'share_text.social_sharing_network.' . $network );
	}

	/**
	 * Required rendered-surface assertions against the exact owner manifest.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function social_sharing_runtime_presentation_assertions( string $html, string $language, string $url, string $surface, int $post_id = 0 ): array {
		if ( ! self::is_translation_language( $language ) || ! self::social_sharing_owner_active() ) {
			return array();
		}
		if ( ! self::social_sharing_owner_interface_ready() ) {
			return array( self::qa_item( 'frontend_social_sharing_configuration_error', 'The Devenia Social Sharing public Interface is incomplete.', array( 'language' => $language, 'url' => $url, 'surface' => $surface ) ) );
		}

		$post_id = self::social_sharing_post_id( $url, $post_id );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof WP_Post ) {
			$surface = sanitize_key( $surface );
			return 0 === strpos( $surface, 'translation' ) || 0 === strpos( $surface, 'singular' )
				? array( self::qa_item( 'frontend_social_sharing_configuration_error', 'The sharing surface post context could not be resolved.', array( 'language' => $language, 'url' => $url, 'surface' => $surface ) ) )
				: array();
		}

		$source_heading    = self::social_sharing_default_heading();
		$localized_heading = self::social_sharing_runtime_text( $language, 'share_text.social_sharing_heading' );
		$manifest          = self::social_sharing_surface_manifest( $post, '' !== $localized_heading ? $localized_heading : $source_heading, $language );
		if ( 'ready' !== (string) ( $manifest['state'] ?? '' ) ) {
			return array( self::qa_item( 'frontend_social_sharing_configuration_error', 'The sharing surface manifest could not be resolved.', array( 'language' => $language, 'url' => $url, 'surface' => $surface, 'owner_error' => (string) ( $manifest['error'] ?? '' ) ) ) );
		}

		$missing = array();
		foreach ( (array) $manifest['runtime_text_keys'] as $runtime_key ) {
			if ( '' === self::social_sharing_runtime_text( $language, (string) $runtime_key ) ) {
				$missing[] = (string) $runtime_key;
			}
		}
		if ( $missing ) {
			return array( self::qa_item( 'frontend_social_sharing_configuration_missing', 'The sharing surface is missing required semantic runtime text.', array( 'language' => $language, 'url' => $url, 'surface' => $surface, 'post_id' => (int) $post->ID, 'runtime_keys' => $missing ) ) );
		}

		preg_match_all( '/<h[1-6]\b[^>]*class=(["\'])[^"\']*\bdevenia-social-sharing__heading\b[^"\']*\1[^>]*>(.*?)<\/h[1-6]>/isu', $html, $matches );
		$actual   = array_map( array( __CLASS__, 'normalized_plain_text_for_review' ), (array) ( $matches[2] ?? array() ) );
		$expected = array_map( array( __CLASS__, 'normalized_plain_text_for_review' ), (array) ( $manifest['headings'] ?? array() ) );
		$details  = array( 'language' => $language, 'url' => $url, 'surface' => $surface, 'post_id' => (int) $post->ID, 'expected' => $expected, 'actual' => $actual );
		if ( count( $expected ) !== count( $actual ) ) {
			return array( self::qa_item( empty( $actual ) && $expected ? 'frontend_social_sharing_heading_missing' : 'frontend_social_sharing_heading_cardinality', 'The rendered sharing heading count differs from the owner manifest.', $details ) );
		}
		if ( $expected !== $actual ) {
			return array( self::qa_item( 'frontend_social_sharing_heading_mismatch', 'The rendered sharing headings differ from the owner manifest.', $details ) );
		}

		return array();
	}
}
