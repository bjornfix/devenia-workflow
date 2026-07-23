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
	/** Read a preview capability from the exact query invoking the projection filter. */
	private static function staged_preview_query_token( $query, string $query_var ): string {
		return is_object( $query ) && is_callable( array( $query, 'get' ) ) ? (string) $query->get( $query_var ) : '';
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
		if ( $expected_id < 1 || 1 !== count( array_filter( array( $page_id, $post_id ) ) ) || $expected_id !== max( $page_id, $post_id ) ) {
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
		if ( ! $authorized ) {
			status_header( 404 );
			global $wp_query;
			if ( is_object( $wp_query ) && is_callable( array( $wp_query, 'set_404' ) ) ) {
				$wp_query->set_404();
			}
			return;
		}

		self::staged_preview_prevent_page_cache();
		remove_action( 'template_redirect', 'redirect_canonical', 10 );
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
