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
