<?php
/**
 * Runtime contract for the shared Quality Single-Flight Gate.
 *
 * Run: php tools/check-quality-single-flight-runtime.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['devenia_quality_single_flight_options'] = array();
$GLOBALS['devenia_quality_single_flight_now']     = 1_753_353_600;
$GLOBALS['devenia_quality_single_flight_fail_next_release'] = false;

function sanitize_key( $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ?? '' );
}

function sanitize_text_field( $value ): string {
	return trim( (string) $value );
}

function absint( $value ): int {
	return abs( (int) $value );
}

function get_option( $key, $default = false ) {
	return array_key_exists( (string) $key, $GLOBALS['devenia_quality_single_flight_options'] )
		? $GLOBALS['devenia_quality_single_flight_options'][ (string) $key ]
		: $default;
}

require_once dirname( __DIR__ ) . '/includes/trait-quality-single-flight.php';

final class Devenia_Quality_Single_Flight_Runtime_Harness {
	use Devenia_Workflow_Quality_Single_Flight;

	private static function quality_single_flight_now(): int {
		return (int) $GLOBALS['devenia_quality_single_flight_now'];
	}

	private static function atomic_create_option( string $key, $value ): bool {
		if ( array_key_exists( $key, $GLOBALS['devenia_quality_single_flight_options'] ) ) {
			return false;
		}
		$GLOBALS['devenia_quality_single_flight_options'][ $key ] = $value;
		return true;
	}

	private static function atomic_delete_option_value( string $key, $expected ): bool {
		if ( self::quality_single_flight_key() === $key && $GLOBALS['devenia_quality_single_flight_fail_next_release'] ) {
			$GLOBALS['devenia_quality_single_flight_fail_next_release'] = false;
			return false;
		}
		if ( ! array_key_exists( $key, $GLOBALS['devenia_quality_single_flight_options'] ) || $expected !== $GLOBALS['devenia_quality_single_flight_options'][ $key ] ) {
			return false;
		}
		unset( $GLOBALS['devenia_quality_single_flight_options'][ $key ] );
		return true;
	}

	public static function acquire( string $workflow, array $job, array $claim ): array {
		return self::quality_single_flight_acquire( $workflow, $job, $claim );
	}

	public static function validate( string $workflow, array $job, array $claim ): array {
		return self::quality_single_flight_validate( $workflow, $job, $claim );
	}

	public static function release( string $workflow, array $claim ): array {
		return self::quality_single_flight_release( $workflow, $claim );
	}
}

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function quality_claim( string $job_id, string $run_id, string $artifact_revision, string $token_hash, int $expires_at ): array {
	return array(
		'job_id'                => $job_id,
		'run_id'                => $run_id,
		'role'                  => 'quality',
		'artifact_revision'     => $artifact_revision,
		'submission_generation' => 1,
		'token_hash'            => $token_hash,
		'claimed_at'            => gmdate( 'c', $GLOBALS['devenia_quality_single_flight_now'] ),
		'expires_at'            => gmdate( 'c', $expires_at ),
	);
}

$source_job = array(
	'job_id'                => 'srj_source_one',
	'artifact_revision'      => 'sra_source_one',
	'submission_generation' => 1,
);
$source_claim = quality_claim( 'srj_source_one', 'run_source_q', 'sra_source_one', str_repeat( 'a', 64 ), $GLOBALS['devenia_quality_single_flight_now'] + 600 );

$first = Devenia_Quality_Single_Flight_Runtime_Harness::acquire( 'source_rewrite', $source_job, $source_claim );
assert_true( ! empty( $first['success'] ), 'The first Quality claim did not acquire the global lease.' );
assert_true( 'source_rewrite' === (string) ( $first['lease']['workflow'] ?? '' ), 'The lease did not bind the source workflow.' );
assert_true( ! isset( $first['lease']['token_hash'] ), 'The public lease exposed the claim secret hash.' );

$translation_job = array(
	'job_id'                => 'tj_translation_one',
	'artifact_revision'      => 'ta_translation_one',
	'submission_generation' => 1,
);
$translation_claim = quality_claim( 'tj_translation_one', 'run_translation_q', 'ta_translation_one', str_repeat( 'b', 64 ), $GLOBALS['devenia_quality_single_flight_now'] + 600 );

$blocked = Devenia_Quality_Single_Flight_Runtime_Harness::acquire( 'translation', $translation_job, $translation_claim );
assert_true( empty( $blocked['success'] ), 'A second workflow acquired Quality while the first lease was active.' );
assert_true( 'quality_run_active' === (string) ( $blocked['code'] ?? '' ), 'The second workflow did not receive the stable busy code.' );
assert_true( 'srj_source_one' === (string) ( $blocked['active']['job_id'] ?? '' ), 'The busy response did not identify the active job.' );
assert_true( ! isset( $blocked['active']['token_hash'] ), 'The busy response exposed the active claim secret hash.' );

$wrong_owner = $source_claim;
$wrong_owner['run_id'] = 'run_forged';
$wrong_release = Devenia_Quality_Single_Flight_Runtime_Harness::release( 'source_rewrite', $wrong_owner );
assert_true( empty( $wrong_release['success'] ), 'A foreign Run released the active Quality lease.' );
assert_true( 'quality_lease_owner_mismatch' === (string) ( $wrong_release['code'] ?? '' ), 'Foreign release did not fail with the ownership code.' );

$wrong_artifact = $source_claim;
$wrong_artifact['artifact_revision'] = 'sra_forged';
$wrong_artifact_release = Devenia_Quality_Single_Flight_Runtime_Harness::release( 'source_rewrite', $wrong_artifact );
assert_true( empty( $wrong_artifact_release['success'] ), 'A claim for another artifact released the active Quality lease.' );

$wrong_lifetime = $source_claim;
$wrong_lifetime['expires_at'] = gmdate( 'c', $GLOBALS['devenia_quality_single_flight_now'] + 601 );
$wrong_lifetime_release = Devenia_Quality_Single_Flight_Runtime_Harness::release( 'source_rewrite', $wrong_lifetime );
assert_true( empty( $wrong_lifetime_release['success'] ), 'A claim with another lifetime released the active Quality lease.' );

$valid = Devenia_Quality_Single_Flight_Runtime_Harness::validate( 'source_rewrite', $source_job, $source_claim );
assert_true( ! empty( $valid['success'] ), 'The exact active Quality owner did not validate.' );

$GLOBALS['devenia_quality_single_flight_fail_next_release'] = true;
$failed_release = Devenia_Quality_Single_Flight_Runtime_Harness::release( 'source_rewrite', $source_claim );
assert_true( empty( $failed_release['success'] ), 'A failed lease CAS was reported as released.' );
assert_true( 'quality_lease_release_conflict' === (string) ( $failed_release['code'] ?? '' ), 'A failed lease CAS did not preserve a retryable terminal outcome.' );
$still_owned = Devenia_Quality_Single_Flight_Runtime_Harness::validate( 'source_rewrite', $source_job, $source_claim );
assert_true( ! empty( $still_owned['success'] ), 'A failed terminal release destroyed the exact lease needed for retry.' );

$released = Devenia_Quality_Single_Flight_Runtime_Harness::release( 'source_rewrite', $source_claim );
assert_true( ! empty( $released['success'] ), 'The exact terminal owner did not release the lease.' );

$second = Devenia_Quality_Single_Flight_Runtime_Harness::acquire( 'translation', $translation_job, $translation_claim );
assert_true( ! empty( $second['success'] ), 'The next workflow was not offered after terminal release.' );

$GLOBALS['devenia_quality_single_flight_now'] += 601;
$expired_successor_claim = quality_claim( 'srj_source_two', 'run_source_q2', 'sra_source_two', str_repeat( 'c', 64 ), $GLOBALS['devenia_quality_single_flight_now'] + 600 );
$expired_successor_job = array(
	'job_id'                => 'srj_source_two',
	'artifact_revision'      => 'sra_source_two',
	'submission_generation' => 1,
);
$recovered = Devenia_Quality_Single_Flight_Runtime_Harness::acquire( 'source_rewrite', $expired_successor_job, $expired_successor_claim );
assert_true( ! empty( $recovered['success'] ), 'An expired Quality lease did not recover for the next exact artifact.' );
assert_true( ! empty( $recovered['recovered_expired_lease'] ), 'Expiry recovery was not reported.' );

$stale_release = Devenia_Quality_Single_Flight_Runtime_Harness::release( 'translation', $translation_claim );
assert_true( empty( $stale_release['success'] ), 'An expired predecessor deleted its successor lease.' );
assert_true( 'quality_lease_owner_mismatch' === (string) ( $stale_release['code'] ?? '' ), 'Stale release did not preserve the successor with an ownership failure.' );

echo "Quality Single-Flight runtime checks passed.\n";
