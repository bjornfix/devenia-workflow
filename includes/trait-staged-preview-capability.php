<?php
/**
 * Shared staged-preview capability primitives.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Staged_Preview_Capability {
	/** Read a preview namespace from the original parsed request when available. */
	private static function staged_preview_request_token( string $query_var ): string {
		if ( isset( $_GET[ $query_var ] ) && is_scalar( $_GET[ $query_var ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only capability input; cryptographic authority validation follows before any projection.
			return (string) wp_unslash( $_GET[ $query_var ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only signed capability input must remain byte-exact; cryptographic authority validation follows before any projection.
		}
		$request_query = is_object( $GLOBALS['wp'] ?? null ) && is_array( $GLOBALS['wp']->query_vars ?? null ) ? $GLOBALS['wp']->query_vars : array();
		return array_key_exists( $query_var, $request_query )
			? (string) $request_query[ $query_var ]
			: (string) get_query_var( $query_var );
	}

	/**
	 * Supply exact staged content to the reusable GenerateBlocks request Adapter.
	 *
	 * Workflow retains capability authority; GP-MCP owns native CSS generation.
	 * A pre-existing value belongs to another caller and is never overwritten.
	 *
	 * @param mixed $content Existing request-local authority or the null sentinel.
	 * @return mixed
	 */
	public static function filter_staged_preview_generateblocks_request_content( $content ) {
		if ( null !== $content ) {
			return $content;
		}

		$source_token      = self::staged_preview_request_token( 'devenia_source_rewrite_preview' );
		$translation_token = self::staged_preview_request_token( 'devenia_translation_artifact_preview' );
		if ( ( '' !== $source_token ) === ( '' !== $translation_token ) ) {
			return null;
		}

		if ( '' !== $source_token ) {
			$authority = self::source_rewrite_preview_authority( $source_token );
			if ( ! empty( $authority['success'] ) && self::source_rewrite_preview_request_matches( $authority ) ) {
				return (string) ( $authority['artifact']['proposed']['content'] ?? '' );
			}
			return null;
		}

		$authority = self::translation_job_preview_authority( $translation_token );
		if ( ! empty( $authority['success'] ) && self::translation_job_preview_request_matches( $authority ) ) {
			return (string) ( $authority['artifact']['surface_manifest']['content']['gutenberg'] ?? '' );
		}

		return null;
	}

	/** Read a preview capability from the exact query invoking the projection filter. */
	private static function staged_preview_query_token( $query, string $query_var ): string {
		return is_object( $query ) && is_callable( array( $query, 'get' ) ) ? (string) $query->get( $query_var ) : '';
	}

	/** Require one and only one preview namespace on the exact projection query. */
	private static function staged_preview_query_owns_namespace( $query, string $expected_query_var ): bool {
		$source_token = self::staged_preview_query_token( $query, 'devenia_source_rewrite_preview' );
		$translation_token = self::staged_preview_query_token( $query, 'devenia_translation_artifact_preview' );
		return 'devenia_source_rewrite_preview' === $expected_query_var
			? '' !== $source_token && '' === $translation_token
			: 'devenia_translation_artifact_preview' === $expected_query_var && '' !== $translation_token && '' === $source_token;
	}

	/** Match the exact query-ID route before WordPress canonicalizes it. */
	private static function staged_preview_request_matches_id( int $expected_id, $query = null, ?array $resolved_posts = null ): bool {
		if ( is_object( $query ) && is_callable( array( $query, 'get' ) ) ) {
			$page_id = absint( $query->get( 'page_id' ) );
			$post_id = absint( $query->get( 'p' ) );
			if ( 0 === $page_id && 0 === $post_id ) {
				$request_query = is_object( $GLOBALS['wp'] ?? null ) && is_array( $GLOBALS['wp']->query_vars ?? null ) ? $GLOBALS['wp']->query_vars : array();
				$page_id = absint( $request_query['page_id'] ?? 0 );
				$post_id = absint( $request_query['p'] ?? 0 );
			}
		} else {
			$request_query = is_object( $GLOBALS['wp'] ?? null ) && is_array( $GLOBALS['wp']->query_vars ?? null ) ? $GLOBALS['wp']->query_vars : array();
			$page_id = absint( $request_query['page_id'] ?? 0 );
			$post_id = absint( $request_query['p'] ?? 0 );
		}
		$request_ids = array_values( array_unique( array_filter( array( $page_id, $post_id ) ) ) );
		if ( $expected_id < 1 || 1 !== count( $request_ids ) || $expected_id !== $request_ids[0] ) {
			return false;
		}
		if ( null === $resolved_posts ) {
			return true;
		}
		$resolved_ids = array_values(
			array_unique(
				array_map(
					static function ( WP_Post $post ): int { return (int) $post->ID; },
					array_filter( $resolved_posts, static function ( $post ): bool { return $post instanceof WP_Post; } )
				)
			)
		);
		return empty( $resolved_ids ) || ( 1 === count( $resolved_ids ) && $expected_id === $resolved_ids[0] );
	}

	/** Apply the fail-closed response boundary for one resolved staged-preview request. */
	private static function staged_preview_apply_response_policy( bool $authorized ): void {
		self::staged_preview_prevent_page_cache();
		remove_action( 'template_redirect', 'redirect_canonical', 10 );
		if ( ! $authorized ) {
			status_header( 404 );
			global $wp_query;
			if ( is_object( $wp_query ) && is_callable( array( $wp_query, 'set_404' ) ) ) {
				$wp_query->set_404();
			}
			return;
		}
	}

	/** Keep an authorized staged preview out of ecosystem page caches. */
	private static function staged_preview_prevent_page_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Established page-cache interoperability constant; this plugin only sets it for an authorized staged preview request.
		}
	}

	private static function staged_preview_clean_id( string $value ): string {
		return substr( sanitize_key( $value ), 0, 96 );
	}

	private static function staged_preview_capability_token( string $kind, string $job_id, string $run_id, string $artifact_revision, int $expires, string $claim_token_hash, string $host_identity ): string {
		$host_identity = substr( sanitize_text_field( str_replace( '~', '', $host_identity ) ), 0, 128 );
		$material = implode(
			'~',
			array(
				sanitize_key( $kind ),
				self::staged_preview_clean_id( $job_id ),
				self::staged_preview_clean_id( $run_id ),
				self::staged_preview_clean_id( $artifact_revision ),
				(string) $expires,
				$host_identity,
			)
		);
		return $material . '~' . hash_hmac( 'sha256', $material . '|' . $claim_token_hash, wp_salt( 'auth' ) );
	}

	/** @return array<string,mixed> */
	private static function staged_preview_capability_parts( string $token, string $expected_kind ): array {
		$decoded = rawurldecode( $token );
		$parts = explode( '~', $decoded );
		if ( 7 !== count( $parts ) || sanitize_key( $expected_kind ) !== sanitize_key( (string) $parts[0] ) ) {
			return array();
		}
		return array(
			'kind' => sanitize_key( (string) $parts[0] ),
			'job_id' => self::staged_preview_clean_id( (string) $parts[1] ),
			'run_id' => self::staged_preview_clean_id( (string) $parts[2] ),
			'artifact_revision' => self::staged_preview_clean_id( (string) $parts[3] ),
			'expires' => absint( $parts[4] ),
			'host_identity' => substr( sanitize_text_field( (string) $parts[5] ), 0, 128 ),
			'mac' => strtolower( sanitize_text_field( (string) $parts[6] ) ),
			'token' => $decoded,
		);
	}
}
