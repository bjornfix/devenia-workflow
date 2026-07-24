<?php
/**
 * Shared single-flight authority for every automated Quality Run.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Quality_Single_Flight {
	/** One installation-wide exclusion record shared by every Quality workflow. */
	private static function quality_single_flight_key(): string {
		return 'devenia_workflow_quality_single_flight';
	}

	/** Clock seam for deterministic lease-expiry tests. */
	private static function quality_single_flight_now(): int {
		return time();
	}

	/** @return array<string,mixed> */
	private static function quality_single_flight_acquire( string $workflow, array $job, array $claim ): array {
		$candidate = self::quality_single_flight_record( $workflow, $job, $claim );
		if ( empty( $candidate['success'] ) ) {
			return $candidate;
		}

		$key       = self::quality_single_flight_key();
		$record    = $candidate['record'];
		$existing  = get_option( $key );
		$recovered_expired = false;
		if ( is_array( $existing ) ) {
			$recovered_expired = self::quality_single_flight_is_expired( $existing );
		}
		if ( is_array( $existing ) && $recovered_expired ) {
			if ( self::atomic_delete_option_value( $key, $existing ) ) {
				$existing = false;
			} else {
				$existing = get_option( $key );
				$recovered_expired = false;
			}
		}

		if ( is_array( $existing ) ) {
			return array(
				'success' => false,
				'code'    => 'quality_run_active',
				'message' => 'Another Quality Run owns the installation-wide single-flight lease.',
				'active'  => self::quality_single_flight_public_lease( $existing ),
			);
		}

		if ( ! self::atomic_create_option( $key, $record ) ) {
			$current = get_option( $key );
			return array(
				'success' => false,
				'code'    => 'quality_run_active',
				'message' => 'Another Quality Run won the installation-wide single-flight lease race.',
				'active'  => is_array( $current ) ? self::quality_single_flight_public_lease( $current ) : array(),
			);
		}

		return array(
			'success'                 => true,
			'lease'                   => self::quality_single_flight_public_lease( $record ),
			'recovered_expired_lease' => $recovered_expired,
		);
	}

	/** @return array<string,mixed> */
	private static function quality_single_flight_validate( string $workflow, array $job, array $claim ): array {
		$expected = self::quality_single_flight_record( $workflow, $job, $claim );
		if ( empty( $expected['success'] ) ) {
			return $expected;
		}
		$current = get_option( self::quality_single_flight_key() );
		if ( ! is_array( $current ) ) {
			return array( 'success' => false, 'code' => 'quality_lease_missing', 'message' => 'The active Quality claim has no installation-wide single-flight lease.' );
		}
		if ( self::quality_single_flight_is_expired( $current ) ) {
			return array( 'success' => false, 'code' => 'quality_lease_expired', 'message' => 'The installation-wide Quality lease expired before this operation.' );
		}
		if ( ! self::quality_single_flight_same_owner( $current, $expected['record'] ) ) {
			return array(
				'success' => false,
				'code'    => 'quality_lease_owner_mismatch',
				'message' => 'The active Quality claim does not own the installation-wide single-flight lease.',
				'active'  => self::quality_single_flight_public_lease( $current ),
			);
		}
		return array( 'success' => true, 'lease' => self::quality_single_flight_public_lease( $current ) );
	}

	/** @return array<string,mixed> */
	private static function quality_single_flight_release( string $workflow, array $claim ): array {
		$current = get_option( self::quality_single_flight_key() );
		if ( ! is_array( $current ) ) {
			return array( 'success' => true, 'released' => false );
		}
		$identity = self::quality_single_flight_claim_identity( $workflow, $claim, false );
		if ( empty( $identity['success'] ) ) {
			return $identity;
		}
		if ( ! self::quality_single_flight_same_owner( $current, $identity['record'] ) ) {
			return array(
				'success' => false,
				'code'    => 'quality_lease_owner_mismatch',
				'message' => 'A Run that does not own the installation-wide Quality lease cannot release it.',
				'active'  => self::quality_single_flight_public_lease( $current ),
			);
		}
		if ( ! self::atomic_delete_option_value( self::quality_single_flight_key(), $current ) ) {
			return array( 'success' => false, 'retryable' => true, 'code' => 'quality_lease_release_conflict', 'message' => 'The Quality lease changed before exact terminal release.' );
		}
		return array( 'success' => true, 'released' => true );
	}

	/** @return array<string,mixed> */
	private static function quality_single_flight_record( string $workflow, array $job, array $claim ): array {
		$identity = self::quality_single_flight_claim_identity( $workflow, $claim );
		if ( empty( $identity['success'] ) ) {
			return $identity;
		}
		$artifact_revision = sanitize_text_field( (string) ( $job['artifact_revision'] ?? '' ) );
		$job_generation     = absint( $job['submission_generation'] ?? $identity['record']['submission_generation'] );
		if (
			'' === $artifact_revision
			|| $artifact_revision !== (string) ( $identity['record']['artifact_revision'] ?? '' )
			|| $job_generation < 1
			|| $job_generation !== (int) $identity['record']['submission_generation']
		) {
			return array( 'success' => false, 'code' => 'quality_lease_input_invalid', 'message' => 'Quality single-flight acquisition requires one exact artifact revision and matching submission generation.' );
		}
		$record                      = $identity['record'];
		$record['schema_version']     = 1;
		$record['acquired_at']        = gmdate( 'c', self::quality_single_flight_now() );
		return array( 'success' => true, 'record' => $record );
	}

	/** @return array<string,mixed> */
	private static function quality_single_flight_claim_identity( string $workflow, array $claim, bool $require_live = true ): array {
		$workflow = sanitize_key( $workflow );
		$job_id   = sanitize_text_field( (string) ( $claim['job_id'] ?? '' ) );
		$run_id   = sanitize_text_field( (string) ( $claim['run_id'] ?? '' ) );
		$role     = sanitize_key( (string) ( $claim['role'] ?? '' ) );
		$artifact = sanitize_text_field( (string) ( $claim['artifact_revision'] ?? '' ) );
		$token    = sanitize_text_field( (string) ( $claim['token_hash'] ?? '' ) );
		$expires  = sanitize_text_field( (string) ( $claim['expires_at'] ?? '' ) );
		$expiry   = strtotime( $expires );
		$generation = absint( $claim['submission_generation'] ?? 0 );
		if (
			! in_array( $workflow, array( 'source_rewrite', 'translation' ), true )
			|| 'quality' !== $role
			|| '' === $job_id
			|| '' === $run_id
			|| '' === $artifact
			|| ! preg_match( '/^[a-f0-9]{64}$/', $token )
			|| $generation < 1
			|| false === $expiry
			|| ( $require_live && $expiry <= self::quality_single_flight_now() )
		) {
			return array( 'success' => false, 'code' => 'quality_lease_input_invalid', 'message' => 'Quality single-flight ownership requires one live, exact Quality claim.' );
		}
		return array(
			'success' => true,
			'record'  => array(
				'workflow'             => $workflow,
				'job_id'                => $job_id,
				'run_id'                => $run_id,
				'role'                  => 'quality',
				'artifact_revision'     => $artifact,
				'submission_generation' => $generation,
				'token_hash'            => $token,
				'claimed_at'            => sanitize_text_field( (string) ( $claim['claimed_at'] ?? '' ) ),
				'expires_at'            => gmdate( 'c', $expiry ),
			),
		);
	}

	private static function quality_single_flight_is_expired( array $lease ): bool {
		$expires = strtotime( (string) ( $lease['expires_at'] ?? '' ) );
		return false === $expires || $expires <= self::quality_single_flight_now();
	}

	private static function quality_single_flight_same_owner( array $left, array $right ): bool {
		$fields = array( 'workflow', 'job_id', 'run_id', 'role', 'artifact_revision', 'submission_generation', 'token_hash', 'claimed_at', 'expires_at' );
		foreach ( $fields as $field ) {
			if ( (string) ( $left[ $field ] ?? '' ) !== (string) ( $right[ $field ] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return array<string,mixed> */
	private static function quality_single_flight_public_lease( array $lease ): array {
		unset( $lease['token_hash'] );
		return $lease;
	}
}
