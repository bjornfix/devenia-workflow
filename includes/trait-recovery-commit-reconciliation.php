<?php
/**
 * Strict recovery COMMIT receipt boundary shared by every publication seam.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Recovery_Commit_Reconciliation {
	/**
	 * Convert a present non-array Adapter value into a safe malformed receipt.
	 *
	 * The null sentinel is handled by each owning call site before this method;
	 * it alone means that no Adapter supplied a receipt.
	 *
	 * @param mixed $adapter_result Adapter result other than the null sentinel.
	 * @return array<string,mixed>
	 */
	private static function translation_job_recovery_commit_adapter_receipt( $adapter_result ): array {
		if ( is_array( $adapter_result ) ) {
			return $adapter_result;
		}

		return array(
			'success'              => false,
			'code'                 => 'recovery_commit_adapter_receipt_not_array',
			'adapter_receipt_type' => sanitize_key( gettype( $adapter_result ) ),
		);
	}

	/**
	 * Decode one Adapter receipt without collapsing an absent field to null.
	 *
	 * A successful COMMIT is the only receipt that may report success=true.
	 * Unknown, rolled-back, and Adapter-error outcomes remain valid receipts,
	 * but must report success=false and an explicit three-valued committed key.
	 *
	 * @param array<string,mixed> $commit Adapter receipt.
	 * @return array<string,mixed>
	 */
	private static function translation_job_decode_recovery_commit_receipt( array $commit ): array {
		$violations = array();
		$has_success = array_key_exists( 'success', $commit );
		$has_committed = array_key_exists( 'committed', $commit );
		$has_code = array_key_exists( 'code', $commit );
		$success = $has_success && is_bool( $commit['success'] ) ? $commit['success'] : null;
		$committed = $has_committed && ( is_bool( $commit['committed'] ) || null === $commit['committed'] ) ? $commit['committed'] : null;
		$code = $has_code && is_string( $commit['code'] ) ? trim( $commit['code'] ) : '';

		if ( ! $has_success ) {
			$violations[] = 'missing_success';
		} elseif ( ! is_bool( $commit['success'] ) ) {
			$violations[] = 'invalid_success_type';
		}
		if ( ! $has_committed ) {
			$violations[] = 'missing_committed';
		} elseif ( ! is_bool( $commit['committed'] ) && null !== $commit['committed'] ) {
			$violations[] = 'invalid_committed_type';
		}
		if ( ! $has_code ) {
			$violations[] = 'missing_code';
		} elseif ( ! is_string( $commit['code'] ) || '' === $code ) {
			$violations[] = 'invalid_code';
		}
		if ( true === $success && true !== $committed ) {
			$violations[] = 'success_without_committed';
		}
		$transaction_still_owned = ! empty( self::$translation_job_recovery_transaction['owned'] );
		if ( $transaction_still_owned ) {
			$violations[] = 'transaction_not_terminal';
		}

		return array(
			'valid'                              => empty( $violations ),
			'code'                               => empty( $violations ) ? 'recovery_commit_receipt_valid' : 'recovery_commit_receipt_invalid',
			'success'                            => $success,
			'committed'                          => $committed,
			'receipt_code'                       => $code,
			'transaction_still_owned_at_boundary' => $transaction_still_owned,
			'violations'                         => $violations,
		);
	}

	/**
	 * Fail closed and close any still-owned transaction after a bad receipt.
	 *
	 * If an Adapter already ended the transaction, the rollback Interface
	 * returns transaction_not_owned and cannot mutate another boundary.
	 *
	 * @param array<string,mixed> $commit Adapter receipt.
	 * @return array<string,mixed>
	 */
	private static function translation_job_require_recovery_commit_receipt( array $commit ): array {
		$decoded = self::translation_job_decode_recovery_commit_receipt( $commit );
		if ( ! empty( $decoded['valid'] ) ) {
			return $decoded;
		}

		$decoded['terminalization'] = self::translation_job_rollback_recovery_transaction();
		return $decoded;
	}
}
