<?php
/**
 * Cost-bounded Translation Job Module.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Translation_Job {
	/**
	 * Current write seam. Concurrency is owned by the Translation Job claim,
	 * so downstream translation helpers do not maintain a second claim system.
	 */
	private static function translation_job_write_gate( int $source_id, string $language, string $claim_token = '', array $input = array() ): array {
		unset( $source_id, $language, $claim_token, $input );
		return array();
	}
	const TRANSLATION_JOB_MAX_RUNS_PER_ROLE = 3;
	const TRANSLATION_JOB_MAX_SUBMISSION_GENERATIONS = 3;
	const TRANSLATION_JOB_SURFACE_REFRESH_PUBLISH_FAILURE_CODES = array(
		'staged_surface_drifted',
		'staged_surface_drifted_before_locked_write',
		'staged_translation_identity_changed_before_locked_write',
	);

	private static $translation_job_internal_identity = array();

	private static function translation_job_ability_catalogue(): array {
		$definitions = array(
			'translation-job-discover' => array( 'Discover Translation Job', 'Creates or returns the current finite Translation Job for one source revision and target language.', 'translation_job_discover_schema', 'translation_job_discover', true, true ),
			'translation-job-claim' => array( 'Claim Translation Job', 'Atomically claims one Translation Job for a bounded translator or quality Run.', 'translation_job_claim_schema', 'translation_job_claim', false, false ),
			'translation-job-abandon' => array( 'Abandon Translation Job Run', 'Releases the caller-owned bounded claim without submitting a fabricated artifact or Quality Decision.', 'translation_job_abandon_schema', 'translation_job_abandon', false, false ),
			'translation-job-fetch-packet' => array( 'Fetch Translation Job Packet', 'Returns the bounded source or quality packet for the current Run.', 'translation_job_claim_access_schema', 'translation_job_fetch_packet', true, true ),
			'translation-job-submit-artifact' => array( 'Submit Translation Artifact', 'Validates and atomically stores one complete localized artifact within the translator Token Budget.', 'translation_job_artifact_schema', 'translation_job_submit_artifact', false, false ),
			'translation-job-submit-quality-decision' => array( 'Submit Translation Quality Decision', 'Stores a bounded Quality Decision against the exact submitted artifact revision.', 'translation_job_quality_schema', 'translation_job_submit_quality_decision', false, false ),
			'translation-job-publish' => array( 'Publish Approved Translation Job', 'Publishes an artifact only when deterministic QA and its exact Quality Decision pass.', 'translation_job_publish_schema', 'translation_job_publish', false, false ),
			'translation-job-status' => array( 'Inspect Translation Job Status', 'Returns authoritative Job, Run, Quality Decision, and measured cost status.', 'translation_job_status_schema', 'translation_job_status', true, true ),
		);
		$catalogue = array();
		foreach ( $definitions as $slug => $definition ) {
			$catalogue[ 'devenia-workflow/' . $slug ] = array(
				'label'            => $definition[0],
				'description'      => $definition[1],
				'input_schema'     => self::{$definition[2]}(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) use ( $definition ) {
					return self::run_ability_operation( $definition[3], $input );
				},
				'meta'             => self::ability_meta( $definition[4], false, $definition[5] ),
			);
		}
		return $catalogue;
	}

	private static function translation_job_dispatch_handlers(): array {
		return array(
			'translation_job_discover'                => 'translation_job_discover',
			'translation_job_claim'                   => 'translation_job_claim',
			'translation_job_abandon'                 => 'translation_job_abandon',
			'translation_job_fetch_packet'            => 'translation_job_fetch_packet',
			'translation_job_submit_artifact'         => 'translation_job_submit_artifact',
			'translation_job_submit_quality_decision' => 'translation_job_submit_quality_decision',
			'translation_job_publish'                 => 'translation_job_publish',
			'translation_job_status'                  => 'translation_job_status',
		);
	}

	private static function translation_job_discover_schema(): array {
		return array(
			'type' => 'object',
			'required' => array( 'source_id', 'language' ),
			'properties' => array(
				'source_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'language' => array( 'type' => 'string' ),
				'observability_label' => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);
	}

	private static function translation_job_claim_schema(): array {
		return array(
			'type' => 'object',
			'required' => array( 'job_id', 'run_id', 'coordinator_id', 'role' ),
			'properties' => array(
				'job_id' => array( 'type' => 'string' ),
				'run_id' => array( 'type' => 'string' ),
				'coordinator_id' => array( 'type' => 'string' ),
				'role' => array( 'type' => 'string', 'enum' => array( 'translator', 'quality' ) ),
				'observability_label' => array( 'type' => 'string' ),
				'ttl_seconds' => array( 'type' => 'integer', 'minimum' => 60, 'maximum' => 7200, 'default' => 3600 ),
			),
			'additionalProperties' => false,
		);
	}

	private static function translation_job_claim_access_schema(): array {
		return array(
			'type' => 'object',
			'required' => array( 'job_id', 'run_id', 'claim_token' ),
			'properties' => array(
				'job_id' => array( 'type' => 'string' ),
				'run_id' => array( 'type' => 'string' ),
				'claim_token' => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);
	}

	private static function translation_job_abandon_schema(): array {
		return array(
			'type' => 'object',
			'required' => array( 'job_id', 'run_id', 'claim_token', 'reason' ),
			'properties' => array(
				'job_id' => array( 'type' => 'string' ),
				'run_id' => array( 'type' => 'string' ),
				'claim_token' => array( 'type' => 'string' ),
				'reason' => array( 'type' => 'string', 'minLength' => 12, 'maxLength' => 500 ),
			),
			'additionalProperties' => false,
		);
	}

	private static function translation_job_usage_schema(): array {
		return array(
			'type' => 'object',
			'required' => array( 'input_tokens', 'cached_input_tokens', 'output_tokens', 'attempts', 'duration_ms', 'estimated_cost_microusd' ),
			'properties' => array(
				'input_tokens' => array( 'type' => 'integer', 'minimum' => 0 ),
				'cached_input_tokens' => array( 'type' => 'integer', 'minimum' => 0 ),
				'output_tokens' => array( 'type' => 'integer', 'minimum' => 0 ),
				'attempts' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 2 ),
				'duration_ms' => array( 'type' => 'integer', 'minimum' => 0 ),
				'estimated_cost_microusd' => array( 'type' => 'integer', 'minimum' => 0 ),
			),
			'additionalProperties' => false,
		);
	}

	private static function translation_job_artifact_schema(): array {
		return array(
			'type' => 'object',
			'required' => array( 'job_id', 'run_id', 'claim_token', 'artifact', 'usage' ),
			'properties' => array(
				'job_id' => array( 'type' => 'string' ),
				'run_id' => array( 'type' => 'string' ),
				'claim_token' => array( 'type' => 'string' ),
				'artifact' => array(
					'type' => 'object',
					'required' => array( 'title', 'localized_fragments' ),
					'properties' => array(
						'title' => array( 'type' => 'string' ),
						'excerpt' => array( 'type' => 'string' ),
						'localized_slug' => array( 'type' => 'string' ),
						'localized_path' => array( 'type' => 'string' ),
						'localized_parent_path' => array( 'type' => 'string' ),
						'localized_parent_id' => array( 'type' => 'integer' ),
						'source_slug_reason' => array( 'type' => 'string' ),
						'allow_source_slug_in_url' => array( 'type' => 'boolean' ),
						'seo' => array( 'type' => 'object' ),
						'taxonomies' => array( 'type' => 'object' ),
						'localized_fragments' => array(
							'type' => 'array',
							'items' => array(
								'type' => 'object',
								'required' => array( 'key' ),
								'properties' => array(
									'key' => array( 'type' => 'string' ),
									'text' => array( 'type' => 'string' ),
									'html' => array( 'type' => 'string' ),
								),
								'additionalProperties' => false,
							),
						),
					),
					'additionalProperties' => false,
				),
				'usage' => self::translation_job_usage_schema(),
			),
			'additionalProperties' => false,
		);
	}

	private static function translation_job_quality_schema(): array {
		return array(
			'type' => 'object',
			'required' => array( 'job_id', 'run_id', 'claim_token', 'artifact_revision', 'surface_revision', 'decision', 'evidence_receipt_ids', 'reviewer_attestations', 'reviewer_observations', 'browser_receipts', 'usage' ),
			'properties' => array(
				'job_id' => array( 'type' => 'string' ),
				'run_id' => array( 'type' => 'string' ),
				'claim_token' => array( 'type' => 'string' ),
				'artifact_revision' => array( 'type' => 'string' ),
				'surface_revision' => array( 'type' => 'string' ),
				'decision' => array( 'type' => 'string', 'enum' => array( 'pass', 'revise', 'reject' ) ),
				'evidence_receipt_ids' => array( 'type' => 'array', 'minItems' => 6, 'items' => array( 'type' => 'string' ) ),
				'reviewer_attestations' => array(
					'type' => 'array',
					'minItems' => 2,
					'items' => array(
						'type' => 'object',
						'required' => array( 'kind', 'passed', 'observation' ),
						'properties' => array(
							'kind' => array( 'type' => 'string', 'enum' => array( 'natural_language', 'factual_accuracy' ) ),
							'passed' => array( 'type' => 'boolean' ),
							'observation' => array( 'type' => 'string', 'minLength' => 40 ),
							'fragment_keys' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						),
						'additionalProperties' => false,
					),
				),
				'reviewer_observations' => array( 'type' => 'string', 'minLength' => 40 ),
				'browser_receipts' => array(
					'type' => 'array',
					'minItems' => 4,
					'items' => array(
						'type' => 'object',
						'required' => array( 'artifact_revision', 'surface_revision', 'viewport_scheme', 'viewport', 'color_scheme', 'url', 'response_digest', 'document_language', 'document_direction', 'layout_digest', 'screenshot_digest', 'checked_at' ),
						'properties' => array(
							'artifact_revision' => array( 'type' => 'string' ),
							'surface_revision' => array( 'type' => 'string' ),
							'viewport_scheme' => array( 'type' => 'string', 'enum' => array( 'desktop', 'mobile' ) ),
							'viewport' => array( 'type' => 'object' ),
							'color_scheme' => array( 'type' => 'string', 'enum' => array( 'light', 'dark' ) ),
							'url' => array( 'type' => 'string' ),
							'response_digest' => array( 'type' => 'string' ),
							'document_language' => array( 'type' => 'string' ),
							'document_direction' => array( 'type' => 'string' ),
							'layout_digest' => array( 'type' => 'string' ),
							'screenshot_digest' => array( 'type' => 'string' ),
							'checked_at' => array( 'type' => 'string' ),
							'adapter' => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
				),
				'browser_adapter_receipt_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'corrections' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'usage' => self::translation_job_usage_schema(),
			),
			'additionalProperties' => false,
		);
	}

	private static function translation_job_publish_schema(): array {
		return array(
			'type' => 'object',
			'required' => array( 'job_id' ),
			'properties' => array(
				'job_id' => array( 'type' => 'string' ),
				'coordinator_id' => array( 'type' => 'string', 'description' => 'Deprecated compatibility label. It grants no publication authority.' ),
				'sync_menu' => array( 'type' => 'boolean', 'default' => true ),
				'verify_live' => array( 'type' => 'boolean', 'default' => true, 'description' => 'Deprecated compatibility input. Publication always verifies origin and canonical cache surfaces.' ),
				'live_verification_timeout' => array( 'type' => 'integer', 'minimum' => 3, 'maximum' => 30, 'default' => 15 ),
			),
			'additionalProperties' => false,
		);
	}

	private static function translation_job_status_schema(): array {
		return array(
			'type' => 'object',
			'properties' => array(
				'job_id' => array( 'type' => 'string' ),
				'source_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'language' => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);
	}

	private static function translation_job_discover( array $input ): array {
		$source_id = absint( $input['source_id'] ?? 0 );
		$language = sanitize_key( (string) ( $input['language'] ?? '' ) );
		$source = get_post( $source_id );
		if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) || self::is_translation_post( $source_id ) ) {
			return self::error( 'Source content not found.' );
		}
		if ( ! self::is_translation_language( $language ) ) {
			return self::error( 'Unknown or source language.' );
		}
		$source_approval = self::translation_job_source_approval( $source );
		if ( empty( $source_approval['passed'] ) ) {
			return array(
				'success' => false,
				'code' => 'source_quality_approval_required',
				'message' => 'Improve and explicitly approve the current source revision before creating Translation Jobs.',
				'source_approval' => $source_approval,
			);
		}
		$source_revision = self::source_hash( $source );
		$job_id = self::translation_job_id( $source_id, $language, $source_revision );
		$job = self::translation_job_get_job( $job_id );
		if ( ! $job ) {
			$now = gmdate( 'c' );
			$job = array(
				'schema_version' => 3,
				'job_id' => $job_id,
				'source_id' => $source_id,
				'source_revision' => $source_revision,
				'target_language' => $language,
				'observability_label' => sanitize_text_field( (string) ( $input['observability_label'] ?? '' ) ),
				'status' => 'queued',
				'created_at' => $now,
				'updated_at' => $now,
				'run_ids' => array(),
				'submission_generation' => 1,
				'surface_refresh_history' => array(),
				'source_approval' => array(
					'reviewed_at' => (string) ( $source_approval['reviewed_at'] ?? '' ),
					'reviewer' => (string) ( $source_approval['reviewer'] ?? '' ),
					'source_hash' => (string) ( $source_approval['source_hash'] ?? '' ),
				),
			);
			if ( ! self::atomic_create_option( self::translation_job_job_key( $job_id ), $job ) ) {
				$job = self::translation_job_get_job( $job_id );
			}
		}
		return array( 'success' => true, 'created' => $job['created_at'] === $job['updated_at'] && empty( $job['run_ids'] ), 'job' => self::translation_job_public_job( $job ) );
	}

	private static function translation_job_claim( array $input ): array {
		$job_id = self::translation_job_clean_id( (string) ( $input['job_id'] ?? '' ) );
		$role = sanitize_key( (string) ( $input['role'] ?? '' ) );
		if ( ! in_array( $role, array( 'translator', 'quality' ), true ) ) {
			return self::error( 'Run role must be translator or quality.' );
		}
		$run_id = self::translation_job_clean_id( (string) ( $input['run_id'] ?? '' ) );
		$coordinator_id = self::translation_job_clean_id( (string) ( $input['coordinator_id'] ?? '' ) );
		if ( '' === $job_id || '' === $run_id || '' === $coordinator_id ) {
			return self::error( 'job_id, run_id, and coordinator_id are required.' );
		}
		$initial_job = self::translation_job_get_job( $job_id );
		if ( ! $initial_job ) {
			return self::error( 'Translation Job not found.' );
		}
		$lifecycle_lease = self::translation_job_acquire_lifecycle_lease( $initial_job, 'claim' );
		if ( empty( $lifecycle_lease['success'] ) ) {
			return $lifecycle_lease;
		}
		try {
			$job = self::translation_job_get_job( $job_id );
			if ( ! $job ) {
				return self::error( 'Translation Job not found after lifecycle lease acquisition.' );
			}
			if (
				absint( $job['source_id'] ?? 0 ) !== absint( $initial_job['source_id'] ?? 0 )
				|| sanitize_key( (string) ( $job['target_language'] ?? '' ) ) !== sanitize_key( (string) ( $initial_job['target_language'] ?? '' ) )
			) {
				return array( 'success' => false, 'code' => 'translation_job_lifecycle_binding_changed', 'message' => 'The Translation Job source/language binding changed before lifecycle mutation.' );
			}
			return self::translation_job_claim_under_lifecycle_lease( $input, $job, $role, $run_id, $coordinator_id );
		} finally {
			self::translation_job_release_lifecycle_lease( $lifecycle_lease );
		}
	}

	/** Mutate expiry, refresh, claim, Run, and Job state only while the lifecycle lease is owned. */
	private static function translation_job_claim_under_lifecycle_lease( array $input, array $job, string $role, string $run_id, string $coordinator_id ): array {
		$lock_key = self::translation_job_claim_key( (string) $job['job_id'] );
		$existing_lock = get_option( $lock_key );
		if ( is_array( $existing_lock ) && strtotime( (string) ( $existing_lock['expires_at'] ?? '' ) ) <= time() ) {
			self::translation_job_expire_run( $existing_lock );
			delete_option( $lock_key );
			$expired_role = sanitize_key( (string) ( $existing_lock['role'] ?? '' ) );
			$resume_status = sanitize_key( (string) ( $existing_lock['previous_status'] ?? '' ) );
			if ( ! in_array( $resume_status, array( 'queued', 'changes_requested', 'quality_pending', 'published' ), true ) ) {
				$resume_status = 'quality' === $expired_role ? 'quality_pending' : ( empty( $job['artifact_revision'] ) ? 'queued' : 'changes_requested' );
			}
			$resumed = self::translation_job_transition( $job, array( 'status' => $resume_status, 'active_run_id' => '' ) );
			if ( empty( $resumed['success'] ) ) {
				return $resumed;
			}
			$job = $resumed['job'];
			$existing_lock = null;
		}
		if ( ! is_array( $existing_lock ) && in_array( (string) ( $job['status'] ?? '' ), array( 'quality_pending', 'ready_to_publish' ), true ) ) {
			$surface_refresh = self::translation_job_refresh_drifted_surface( $job, 'claim_baseline_mismatch' );
			if ( empty( $surface_refresh['success'] ) ) {
				return $surface_refresh;
			}
			if ( ! empty( $surface_refresh['refreshed'] ) ) {
				$job = $surface_refresh['job'];
				if ( 'quality' === $role ) {
					return array_merge( $surface_refresh, array( 'success' => false, 'code' => 'surface_refresh_required', 'message' => 'The public baseline drifted. The Job was reopened for a fresh translator generation before Quality could claim it.' ) );
				}
			}
		}
		if ( 'translator' === $role && 'quality_pending' === (string) $job['status'] && ! is_array( $existing_lock ) ) {
			$artifact = self::translation_job_unpack_artifact_record(
				get_option( self::translation_job_artifact_key( (string) ( $job['artifact_revision'] ?? '' ) ) )
			);
			$coverage = self::translation_job_fragment_coverage(
				$job,
				(array) ( $artifact['artifact']['localized_fragments'] ?? array() )
			);
			if ( empty( $coverage['success'] ) ) {
				$refreshed = self::translation_job_transition(
					$job,
					array(
						'status' => 'changes_requested',
						'quality_revision' => '',
						'active_run_id' => '',
						'contract_refresh' => array(
							'refreshed_at' => gmdate( 'c' ),
							'reason' => 'artifact_fragment_contract_changed',
							'coverage' => $coverage,
						),
					)
				);
				if ( empty( $refreshed['success'] ) ) {
					return $refreshed;
				}
				$job = $refreshed['job'];
			}
		}
		$expected_statuses = 'translator' === $role ? array( 'queued', 'changes_requested', 'published' ) : array( 'quality_pending' );
		if ( ! in_array( (string) $job['status'], $expected_statuses, true ) ) {
			return array( 'success' => false, 'code' => 'job_not_claimable', 'message' => 'Translation Job is not claimable for this Run role.', 'job' => self::translation_job_public_job( $job ) );
		}
		if ( self::translation_job_source_is_stale( $job ) ) {
			self::translation_job_transition( $job, array( 'status' => 'superseded' ) );
			return array( 'success' => false, 'code' => 'job_superseded', 'message' => 'The source changed. Discover a Job for the current revision.' );
		}
		$source_approval = self::translation_job_source_approval( get_post( (int) $job['source_id'] ) );
		if ( empty( $source_approval['passed'] ) ) {
			return array( 'success' => false, 'code' => 'source_quality_approval_required', 'message' => 'Current source revision is not approved for translation.', 'source_approval' => $source_approval );
		}
		$existing_runs = isset( $job['run_ids'] ) && is_array( $job['run_ids'] ) ? $job['run_ids'] : array();
		$submission_generation = self::translation_job_submission_generation( $job );
		if ( self::translation_job_role_attempt_count( $existing_runs, $role, $submission_generation ) >= self::TRANSLATION_JOB_MAX_RUNS_PER_ROLE ) {
			return array( 'success' => false, 'code' => 'run_attempt_limit', 'message' => 'This Job generation used every allowed bounded Run for this role.', 'submission_generation' => $submission_generation );
		}
		if ( is_array( $existing_lock ) ) {
			return array( 'success' => false, 'code' => 'job_claim_conflict', 'message' => 'Translation Job is already claimed.', 'claim' => self::translation_job_public_claim( $existing_lock ) );
		}
		$token = wp_generate_password( 48, false, false );
		$ttl = max( 60, min( 7200, absint( $input['ttl_seconds'] ?? 3600 ) ) );
		$now = time();
		$lock = array(
			'job_id' => (string) $job['job_id'],
			'run_id' => $run_id,
			'coordinator_id' => $coordinator_id,
			'role' => $role,
			'previous_status' => (string) $job['status'],
			'submission_generation' => $submission_generation,
			'token_hash' => hash( 'sha256', $token ),
			'claimed_at' => gmdate( 'c', $now ),
			'expires_at' => gmdate( 'c', $now + $ttl ),
		);
		$principal = self::translation_job_authenticated_principal(
			$job,
			array( 'run_id' => $run_id, 'role' => $role, 'coordinator_id' => $coordinator_id ),
			$lock
		);
		if ( 'quality' === $role ) {
			$artifact_record = self::translation_job_unpack_artifact_record(
				get_option( self::translation_job_artifact_key( (string) ( $job['artifact_revision'] ?? '' ) ) )
			);
			$writer_principal = isset( $artifact_record['writer_principal'] ) && is_array( $artifact_record['writer_principal'] ) ? $artifact_record['writer_principal'] : array();
			if ( empty( $writer_principal['principal_id'] ) ) {
				$migrated = self::translation_job_transition( $job, array( 'status' => 'changes_requested', 'active_run_id' => '' ) );
				return array( 'success' => false, 'code' => 'artifact_resubmission_required', 'message' => 'This legacy artifact predates authenticated Run principals and was requeued for a fresh translator submission.', 'job' => ! empty( $migrated['job'] ) ? self::translation_job_public_job( $migrated['job'] ) : self::translation_job_public_job( $job ) );
			}
			if (
				(string) $writer_principal['principal_id'] === (string) $principal['principal_id']
				|| (string) ( $writer_principal['run_id'] ?? '' ) === $run_id
			) {
				return array( 'success' => false, 'code' => 'writer_reviewer_principal_conflict', 'message' => 'Quality must use a fresh bounded Run principal distinct from the translator Run.' );
			}
		}
		if ( ! self::atomic_create_option( $lock_key, $lock ) ) {
			return array( 'success' => false, 'code' => 'job_claim_race_lost', 'message' => 'Another Run claimed the Translation Job.' );
		}
		$budget = self::translation_job_budget( $role );
		$run = array(
			'run_id' => $run_id,
			'job_id' => (string) $job['job_id'],
			'role' => $role,
			'coordinator_id' => $coordinator_id,
			'context_mode' => 'bounded_packet',
			'observability_label' => sanitize_text_field( (string) ( $input['observability_label'] ?? '' ) ),
			'budget' => $budget,
			'principal' => $principal,
			'status' => 'running',
			'started_at' => gmdate( 'c', $now ),
			'submission_generation' => $submission_generation,
		);
		if ( ! self::atomic_create_option( self::translation_job_run_key( $run_id ), $run ) ) {
			delete_option( $lock_key );
			return array( 'success' => false, 'code' => 'run_id_conflict', 'message' => 'run_id already exists.' );
		}
		$next_runs = $existing_runs;
		$next_runs[] = array( 'run_id' => $run_id, 'role' => $role, 'submission_generation' => $submission_generation );
		$next_status = 'translator' === $role ? 'claimed' : 'quality_pending';
		$next = self::translation_job_transition( $job, array( 'status' => $next_status, 'run_ids' => $next_runs, 'active_run_id' => $run_id, 'coordinator_id' => $coordinator_id, 'submission_generation' => $submission_generation ) );
		if ( empty( $next['success'] ) ) {
			delete_option( $lock_key );
			delete_option( self::translation_job_run_key( $run_id ) );
			return $next;
		}
		return array( 'success' => true, 'claim_token' => $token, 'claim' => self::translation_job_public_claim( $lock ), 'run' => $run, 'job' => self::translation_job_public_job( $next['job'] ) );
	}

	private static function translation_job_fetch_packet( array $input ): array {
		$access = self::translation_job_claim_access( $input );
		if ( empty( $access['success'] ) ) {
			return $access;
		}
		$job = $access['job'];
		$run = $access['run'];
		if ( 'quality' === (string) ( $run['role'] ?? '' ) ) {
			$surface_refresh = self::translation_job_refresh_drifted_surface( $job, 'quality_packet_baseline_mismatch' );
			if ( empty( $surface_refresh['success'] ) || ! empty( $surface_refresh['refreshed'] ) ) {
				if ( ! empty( $surface_refresh['refreshed'] ) || 'surface_refresh_generation_limit' === (string) ( $surface_refresh['code'] ?? '' ) ) {
					self::translation_job_finish_run_without_usage( $run, 'surface_refresh_required' );
					self::translation_job_release_claim( (string) $job['job_id'] );
				}
				if ( ! empty( $surface_refresh['refreshed'] ) ) {
					$surface_refresh['success'] = false;
					$surface_refresh['code'] = 'surface_refresh_required';
					$surface_refresh['message'] = 'The public baseline drifted. The Job was reopened for a fresh translator generation before the Quality packet was issued.';
				}
				return $surface_refresh;
			}
		}
		$source = get_post( (int) $job['source_id'] );
		if ( ! $source || self::translation_job_source_is_stale( $job ) ) {
			return array( 'success' => false, 'code' => 'job_superseded', 'message' => 'The source changed before the packet was fetched.' );
		}
		$packet = 'quality' === $run['role']
			? self::translation_job_quality_packet( $job, $run, $source )
			: self::translation_job_translation_packet( $job, $run, $source );
		$estimated_tokens = (int) ceil( strlen( wp_json_encode( $packet ) ?: '' ) / 4 );
		if ( $estimated_tokens > (int) $run['budget']['input_token_limit'] ) {
			return array( 'success' => false, 'code' => 'packet_over_budget', 'message' => 'The bounded packet exceeds the Run input Token Budget.', 'estimated_input_tokens' => $estimated_tokens, 'input_token_limit' => (int) $run['budget']['input_token_limit'] );
		}
		$run['packet_estimated_input_tokens'] = $estimated_tokens;
		$run['packet_revision'] = 'tp_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $packet ) ) ?: '' ), 0, 32 );
		$run['packet_fetched_at'] = gmdate( 'c' );
		update_option( self::translation_job_run_key( (string) $run['run_id'] ), $run, false );
		$packet['estimated_input_tokens'] = $estimated_tokens;
		$packet['packet_revision'] = $run['packet_revision'];
		return array( 'success' => true, 'packet' => $packet );
	}

	private static function translation_job_abandon( array $input ): array {
		$access = self::translation_job_claim_access( $input );
		if ( empty( $access['success'] ) ) {
			return $access;
		}

		$job = $access['job'];
		$run = $access['run'];
		$claim = $access['claim'];
		$reason = sanitize_textarea_field( (string) ( $input['reason'] ?? '' ) );
		if ( strlen( trim( $reason ) ) < 12 ) {
			return array( 'success' => false, 'code' => 'job_abandon_reason_required', 'message' => 'A concrete reason is required to abandon a Translation Job Run.' );
		}

		$role = sanitize_key( (string) ( $run['role'] ?? '' ) );
		$resume_status = sanitize_key( (string) ( $claim['previous_status'] ?? '' ) );
		$allowed_statuses = 'quality' === $role
			? array( 'quality_pending' )
			: array( 'queued', 'changes_requested', 'published' );
		if ( ! in_array( $resume_status, $allowed_statuses, true ) ) {
			$resume_status = 'quality' === $role
				? 'quality_pending'
				: ( empty( $job['artifact_revision'] ) ? 'queued' : 'changes_requested' );
		}

		$transition = self::translation_job_transition(
			$job,
			array(
				'status' => $resume_status,
				'active_run_id' => '',
			)
		);
		if ( empty( $transition['success'] ) ) {
			return $transition;
		}

		$run['status'] = 'completed';
		$run['outcome'] = 'abandoned';
		$run['abandon_reason'] = $reason;
		$run['finished_at'] = gmdate( 'c' );
		update_option( self::translation_job_run_key( (string) $run['run_id'] ), $run, false );
		self::translation_job_release_claim( (string) $job['job_id'] );

		return array(
			'success' => true,
			'message' => 'Translation Job Run abandoned and claim released.',
			'run' => $run,
			'job' => self::translation_job_public_job( $transition['job'] ),
		);
	}

	private static function translation_job_submit_artifact( array $input ): array {
		$access = self::translation_job_claim_access( $input, 'translator' );
		if ( empty( $access['success'] ) ) {
			return $access;
		}
		$job = $access['job'];
		$run = $access['run'];
		$usage = self::translation_job_validate_usage( $input['usage'] ?? array(), $run['budget'], $run, $input );
		if ( empty( $usage['success'] ) ) {
			if ( 'run_budget_exceeded' === (string) ( $usage['code'] ?? '' ) ) {
				self::translation_job_finish_run( $run, 'budget_exceeded', $usage['usage'] ?? array() );
				self::translation_job_transition( $job, array( 'status' => 'budget_exceeded' ) );
				self::translation_job_release_claim( (string) $job['job_id'] );
			}
			return $usage;
		}
		if ( self::translation_job_source_is_stale( $job ) ) {
			return array( 'success' => false, 'code' => 'job_superseded', 'message' => 'The source changed before artifact submission.' );
		}
		$artifact = isset( $input['artifact'] ) && is_array( $input['artifact'] ) ? $input['artifact'] : array();
		$coverage = self::translation_job_fragment_coverage( $job, $artifact['localized_fragments'] ?? array() );
		if ( empty( $coverage['success'] ) ) {
			return $coverage;
		}
		$source = get_post( (int) $job['source_id'] );
		$link_policy = $source instanceof WP_Post
			? self::translation_job_artifact_link_policy( $source, (string) $job['target_language'], $artifact['localized_fragments'] ?? array() )
			: array( 'success' => false, 'code' => 'job_source_missing', 'message' => 'Translation Job source is unavailable.' );
		if ( empty( $link_policy['success'] ) ) {
			return $link_policy;
		}
		$contact_policy = $source instanceof WP_Post
			? self::translation_job_artifact_contact_policy( $source, $artifact['localized_fragments'] ?? array() )
			: array( 'success' => false, 'code' => 'job_source_missing', 'message' => 'Translation Job source is unavailable.' );
		if ( empty( $contact_policy['success'] ) ) {
			return $contact_policy;
		}
		$staging = self::translation_job_stage_artifact( $job, $artifact );
		if ( empty( $staging['success'] ) ) {
			return $staging;
		}
		$surface_revision = self::translation_job_surface_revision( (array) $staging['manifest'] );
		if ( ! hash_equals( (string) $staging['surface_revision'], $surface_revision ) ) {
			return array( 'success' => false, 'code' => 'staged_surface_revision_mismatch', 'message' => 'The staged surface manifest could not be reproduced.' );
		}
		$translation_id = absint( $staging['translation_id'] ?? 0 );
		$writer_principal = self::translation_job_authenticated_principal( $job, $run, $access['claim'] );
		$submission_generation = self::translation_job_submission_generation( $job );
		$baseline_surface_revision = $translation_id ? self::translation_job_current_surface_revision( $translation_id ) : '';
		// Scope the content-addressed revision to its immutable Job contract.
		// Identical localized payloads can legitimately occur after a source
		// revision changes; they must not collide with another Job's record.
		$artifact_revision = self::translation_job_revision(
			array(
				'job_id'          => (string) $job['job_id'],
				'source_revision' => (string) $job['source_revision'],
				'target_language' => (string) $job['target_language'],
				'submission_generation' => $submission_generation,
				'baseline_surface_revision' => $baseline_surface_revision,
				'writer_principal_id' => (string) ( $writer_principal['principal_id'] ?? '' ),
				'artifact'        => $artifact,
			)
		);
		$content_revision = (string) $staging['content_revision'];
		$artifact_record = array(
			'state' => 'staged',
			'artifact_revision' => $artifact_revision,
			'job_id' => (string) $job['job_id'],
			'source_revision' => (string) $job['source_revision'],
			'translation_id' => $translation_id,
			'content_revision' => $content_revision,
			'surface_revision' => $surface_revision,
			'surface_manifest' => $staging['manifest'],
			'baseline_surface_revision' => $baseline_surface_revision,
			'submission_generation' => $submission_generation,
			'writer_principal' => $writer_principal,
			'staged' => true,
			'staged_validation' => array(
				'passed' => true,
				'guardrail_issue_count' => count( $staging['guardrails']['issues'] ?? array() ),
				'taxonomy_checked' => $staging['taxonomy']['checked'] ?? array(),
			),
			'artifact' => self::translation_job_sanitize_artifact_record( $artifact ),
			'submitted_at' => gmdate( 'c' ),
		);
		$artifact_record = self::translation_job_pack_artifact_record( $artifact_record );
		if ( ! self::atomic_create_option( self::translation_job_artifact_key( $artifact_revision ), $artifact_record ) ) {
			$artifact_key = self::translation_job_artifact_key( $artifact_revision );
			$stored = get_option( $artifact_key );
			if ( ! is_array( $stored ) ) {
				// Artifact revisions are scoped to this immutable Job contract, so
				// this key cannot legitimately belong to another Job. Some hosts
				// return zero affected rows for INSERT IGNORE without storing a row;
				// use the WordPress option path, then verify the exact record.
				update_option( $artifact_key, $artifact_record, false );
				wp_cache_delete( $artifact_key, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				$stored = get_option( $artifact_key );
			}
			if (
				! is_array( $stored )
				|| (string) ( $stored['job_id'] ?? '' ) !== (string) $job['job_id']
				|| (string) ( $stored['artifact_revision'] ?? '' ) !== $artifact_revision
				|| (string) ( $stored['source_revision'] ?? '' ) !== (string) $job['source_revision']
			) {
				return array(
					'success'           => false,
					'code'              => is_array( $stored ) ? 'artifact_revision_conflict' : 'artifact_store_failed',
					'message'           => is_array( $stored ) ? 'Artifact revision already belongs to another Job.' : 'The artifact record could not be stored.',
					'artifact_revision' => $artifact_revision,
					'expected_job_id'   => (string) $job['job_id'],
					'stored_job_id'     => is_array( $stored ) ? (string) ( $stored['job_id'] ?? '' ) : '',
				);
			}
		}
		self::translation_job_finish_run( $run, 'submitted', $usage['usage'] );
		$next = self::translation_job_transition( $job, array( 'status' => 'quality_pending', 'artifact_revision' => $artifact_revision, 'translation_id' => $translation_id, 'content_revision' => $content_revision, 'surface_revision' => (string) $staging['surface_revision'], 'quality_revision' => '', 'active_run_id' => '' ) );
		self::translation_job_release_claim( (string) $job['job_id'] );
		if ( empty( $next['success'] ) ) {
			return $next;
		}
		return array( 'success' => true, 'message' => 'Complete translation artifact staged without changing the public translation.', 'job' => self::translation_job_public_job( $next['job'] ), 'artifact_revision' => $artifact_revision, 'surface_revision' => (string) $staging['surface_revision'], 'translation_id' => $translation_id, 'staged' => true, 'qa_next' => 'devenia-workflow/translation-job-claim with role=quality' );
	}

	private static function translation_job_submit_quality_decision( array $input ): array {
		$access = self::translation_job_claim_access( $input, 'quality' );
		if ( empty( $access['success'] ) ) {
			return $access;
		}
		$job = $access['job'];
		$run = $access['run'];
		$surface_refresh = self::translation_job_refresh_drifted_surface( $job, 'quality_submission_baseline_mismatch' );
		if ( empty( $surface_refresh['success'] ) || ! empty( $surface_refresh['refreshed'] ) ) {
			if ( ! empty( $surface_refresh['refreshed'] ) || 'surface_refresh_generation_limit' === (string) ( $surface_refresh['code'] ?? '' ) ) {
				self::translation_job_finish_run_without_usage( $run, 'surface_refresh_required' );
				self::translation_job_release_claim( (string) $job['job_id'] );
			}
			if ( ! empty( $surface_refresh['refreshed'] ) ) {
				$surface_refresh['success'] = false;
				$surface_refresh['code'] = 'surface_refresh_required';
				$surface_refresh['message'] = 'The public baseline drifted. The Job was reopened for a fresh translator generation before Quality could decide.';
			}
			return $surface_refresh;
		}
		$source = get_post( (int) $job['source_id'] );
		$source_approval = $source instanceof WP_Post ? self::translation_job_source_approval( $source ) : array( 'passed' => false );
		if ( empty( $source_approval['passed'] ) || self::translation_job_source_is_stale( $job ) ) {
			return array( 'success' => false, 'code' => 'source_quality_approval_required', 'message' => 'Quality cannot pass against an unapproved or changed source revision.', 'source_approval' => $source_approval );
		}
		if ( ! hash_equals( (string) ( $job['artifact_revision'] ?? '' ), sanitize_text_field( (string) ( $input['artifact_revision'] ?? '' ) ) ) ) {
			return array( 'success' => false, 'code' => 'artifact_revision_mismatch', 'message' => 'Quality Decision does not match the current artifact revision.' );
		}
		$artifact_record = self::translation_job_unpack_artifact_record( get_option( self::translation_job_artifact_key( (string) $job['artifact_revision'] ) ) );
		if ( empty( $artifact_record['staged'] ) || empty( $artifact_record['surface_revision'] ) ) {
			return array( 'success' => false, 'code' => 'staged_artifact_required', 'message' => 'Quality requires a current staged artifact that has not changed the public translation.' );
		}
		if ( ! hash_equals( (string) $artifact_record['surface_revision'], sanitize_text_field( (string) ( $input['surface_revision'] ?? '' ) ) ) ) {
			return array( 'success' => false, 'code' => 'surface_revision_mismatch', 'message' => 'Quality Decision does not match the complete staged surface revision.' );
		}
		$reviewer_principal = isset( $run['principal'] ) && is_array( $run['principal'] )
			? $run['principal']
			: self::translation_job_authenticated_principal( $job, $run, $access['claim'] );
		$writer_principal = isset( $artifact_record['writer_principal'] ) && is_array( $artifact_record['writer_principal'] ) ? $artifact_record['writer_principal'] : array();
		if (
			empty( $writer_principal['principal_id'] )
			|| (string) $writer_principal['principal_id'] === (string) ( $reviewer_principal['principal_id'] ?? '' )
			|| (string) ( $writer_principal['run_id'] ?? '' ) === (string) ( $reviewer_principal['run_id'] ?? '' )
		) {
			return array( 'success' => false, 'code' => 'writer_reviewer_principal_conflict', 'message' => 'The translator Run cannot submit Quality for its own staged artifact.' );
		}
		$usage = self::translation_job_validate_usage( $input['usage'] ?? array(), $run['budget'], $run, $input );
		if ( empty( $usage['success'] ) ) {
			if ( 'run_budget_exceeded' === (string) ( $usage['code'] ?? '' ) ) {
				self::translation_job_finish_run( $run, 'budget_exceeded', $usage['usage'] ?? array() );
				self::translation_job_transition( $job, array( 'status' => 'budget_exceeded' ) );
				self::translation_job_release_claim( (string) $job['job_id'] );
			}
			return $usage;
		}
		$decision = sanitize_key( (string) ( $input['decision'] ?? '' ) );
		if ( ! in_array( $decision, array( 'pass', 'revise', 'reject' ), true ) ) {
			return self::error( 'Quality Decision must be pass, revise, or reject.' );
		}
		$evidence = trim( sanitize_textarea_field( (string) ( $input['reviewer_observations'] ?? '' ) ) );
		if ( strlen( $evidence ) < 40 ) {
			return array( 'success' => false, 'code' => 'quality_evidence_required', 'message' => 'Quality Decision requires concrete evidence.' );
		}
		$translation_id = self::translation_job_resolve_publication_translation_id( $job, $artifact_record );
		if ( $translation_id ) { $job['translation_id'] = $translation_id; }
		if ( $translation_id && ! hash_equals( (string) ( $artifact_record['baseline_surface_revision'] ?? '' ), self::translation_job_current_surface_revision( $translation_id ) ) ) {
			return array( 'success' => false, 'code' => 'staged_surface_drifted', 'message' => 'The public translation surface changed after artifact submission.' );
		}
		$evidence_receipts = self::translation_job_quality_evidence_receipts( $job, $artifact_record, $input, $reviewer_principal );
		$qa = array(
			'success' => ! empty( $artifact_record['staged_validation']['passed'] ),
			'passed' => ! empty( $artifact_record['staged_validation']['passed'] ),
			'issue_count' => absint( $artifact_record['staged_validation']['guardrail_issue_count'] ?? 0 ),
			'warning_count' => 0,
			'adapter' => 'staged_artifact_validation',
		);
		$publication_experience = self::publication_experience_readiness_for_post( $source, self::source_language_code(), 'source_for_staged_translation' );
		if ( 'pass' === $decision && ( empty( $evidence_receipts['success'] ) || empty( $qa['passed'] ) || empty( $publication_experience['passed'] ) ) ) {
			return array( 'success' => false, 'code' => 'quality_pass_rejected', 'message' => 'A passing Quality Decision requires server-bound evidence receipts, staged deterministic QA, and source publication experience to pass.', 'evidence_receipts' => $evidence_receipts, 'qa' => $qa, 'publication_experience' => $publication_experience );
		}
		$evidence_record = ! empty( $evidence_receipts['record'] ) && is_array( $evidence_receipts['record'] ) ? $evidence_receipts['record'] : array();
		$quality = array(
			'quality_revision' => self::translation_job_revision( array( $job['artifact_revision'], $artifact_record['surface_revision'], $decision, $evidence_record['evidence_revision'] ?? '', $evidence, $input['corrections'] ?? array() ) ),
			'job_id' => (string) $job['job_id'],
			'artifact_revision' => (string) $job['artifact_revision'],
			'content_revision' => (string) $job['content_revision'],
			'surface_revision' => (string) $artifact_record['surface_revision'],
			'translation_id' => $translation_id,
			'decision' => $decision,
			'reviewer_observations' => $evidence,
			'evidence_receipt_ids' => array_values( array_map( 'sanitize_text_field', (array) ( $input['evidence_receipt_ids'] ?? array() ) ) ),
			'reviewer_attestations' => $evidence_record['reviewer_attestations'] ?? array(),
			'browser_receipts' => $evidence_record['browser_attestations'] ?? array(),
			'evidence_revision' => (string) ( $evidence_record['evidence_revision'] ?? '' ),
			'usage' => $usage['usage'],
			'reviewer_principal' => $reviewer_principal,
			'corrections' => array_values( array_map( 'sanitize_text_field', is_array( $input['corrections'] ?? null ) ? $input['corrections'] : array() ) ),
			'coordinator_id' => (string) $run['coordinator_id'],
			'run_id' => (string) $run['run_id'],
			'submission_generation' => self::translation_job_submission_generation( $job ),
			'qa' => array( 'passed' => ! empty( $qa['passed'] ), 'issue_count' => absint( $qa['issue_count'] ?? 0 ), 'warning_count' => absint( $qa['warning_count'] ?? 0 ) ),
			'publication_experience' => array( 'passed' => ! empty( $publication_experience['passed'] ), 'state' => (string) ( $publication_experience['state'] ?? '' ) ),
			'decided_at' => gmdate( 'c' ),
		);
		$quality_key = self::translation_job_quality_key( $quality['quality_revision'] );
		if ( ! self::atomic_create_option( $quality_key, $quality ) ) {
			$existing_quality = get_option( $quality_key );
			$identity_fields = array( 'quality_revision', 'job_id', 'artifact_revision', 'content_revision', 'surface_revision', 'translation_id', 'evidence_revision', 'submission_generation' );
			$existing_identity = array_intersect_key( is_array( $existing_quality ) ? $existing_quality : array(), array_flip( $identity_fields ) );
			$submitted_identity = array_intersect_key( $quality, array_flip( $identity_fields ) );
			if ( count( $existing_identity ) !== count( $identity_fields ) || self::translation_job_canonicalize( $existing_identity ) !== self::translation_job_canonicalize( $submitted_identity ) ) {
				return array( 'success' => false, 'code' => 'quality_revision_conflict', 'message' => 'Quality Decision revision already exists with different data.' );
			}
			$quality = $existing_quality;
		}
		self::translation_job_finish_run( $run, $decision, $usage['usage'] );
		$status = 'pass' === $decision ? 'ready_to_publish' : ( 'revise' === $decision ? 'changes_requested' : 'rejected' );
		$next = self::translation_job_transition( $job, array( 'status' => $status, 'quality_revision' => $quality['quality_revision'], 'active_run_id' => '' ) );
		self::translation_job_release_claim( (string) $job['job_id'] );
		if ( empty( $next['success'] ) ) {
			return $next;
		}
		return array( 'success' => true, 'job' => self::translation_job_public_job( $next['job'] ), 'quality_decision' => $quality, 'qa' => $qa );
	}

	private static function translation_job_publish( array $input ): array {
		$job_id = self::translation_job_clean_id( (string) ( $input['job_id'] ?? '' ) );
		$initial_job = self::translation_job_get_job( $job_id );
		if ( ! $initial_job ) { return self::error( 'Translation Job not found.' ); }
		$lifecycle_lease = self::translation_job_acquire_lifecycle_lease( $initial_job, 'publish' );
		if ( empty( $lifecycle_lease['success'] ) ) { return $lifecycle_lease; }
		try {
		$job = self::translation_job_get_job( $job_id );
		if ( ! $job ) { return self::error( 'Translation Job not found after lifecycle lease acquisition.' ); }
		if (
			absint( $job['source_id'] ?? 0 ) !== absint( $initial_job['source_id'] ?? 0 )
			|| sanitize_key( (string) ( $job['target_language'] ?? '' ) ) !== sanitize_key( (string) ( $initial_job['target_language'] ?? '' ) )
		) {
			return array( 'success' => false, 'code' => 'translation_job_lifecycle_binding_changed', 'message' => 'The Translation Job source/language binding changed before publication.' );
		}
		$job_status = (string) ( $job['status'] ?? '' );
		if ( ! in_array( $job_status, array( 'ready_to_publish', 'published' ), true ) ) {
			return array( 'success' => false, 'code' => 'job_not_ready_to_publish', 'message' => 'Translation Job does not have a passing current Quality Decision.', 'job' => self::translation_job_public_job( $job ) );
		}
		$quality = get_option( self::translation_job_quality_key( (string) ( $job['quality_revision'] ?? '' ) ) );
		$coordinator_id = self::translation_job_clean_id( (string) ( $input['coordinator_id'] ?? '' ) );
		$is_quality_authority_v3 = is_array( $quality ) && ! empty( $quality['evidence_revision'] ) && ! empty( $quality['reviewer_principal']['principal_id'] );
		if ( ! is_array( $quality ) || 'pass' !== (string) ( $quality['decision'] ?? '' ) || ! $is_quality_authority_v3 ) {
			return array( 'success' => false, 'code' => 'quality_decision_authority_mismatch', 'message' => 'Publication requires the current server-owned v3 Quality evidence and principal bindings. Coordinator labels grant no authority.' );
		}
		if ( self::translation_job_source_is_stale( $job ) || (string) ( $quality['artifact_revision'] ?? '' ) !== (string) ( $job['artifact_revision'] ?? '' ) ) {
			return array( 'success' => false, 'code' => 'job_superseded', 'message' => 'Source or artifact changed after the Quality Decision.' );
		}
		$source = get_post( (int) $job['source_id'] );
		$source_approval = $source instanceof WP_Post ? self::translation_job_source_approval( $source ) : array( 'passed' => false );
		if ( empty( $source_approval['passed'] ) ) {
			return array( 'success' => false, 'code' => 'source_quality_approval_required', 'message' => 'Current source revision is not approved for publication.', 'source_approval' => $source_approval );
		}
		$artifact_record = self::translation_job_unpack_artifact_record( get_option( self::translation_job_artifact_key( (string) ( $job['artifact_revision'] ?? '' ) ) ) );
		if ( ! is_array( $artifact_record ) || empty( $artifact_record['artifact_revision'] ) ) {
			return array( 'success' => false, 'code' => 'artifact_record_missing', 'message' => 'The approved artifact record is unavailable.' );
		}
		$writer_principal = isset( $artifact_record['writer_principal'] ) && is_array( $artifact_record['writer_principal'] ) ? $artifact_record['writer_principal'] : array();
		if (
			empty( $writer_principal['principal_id'] )
			|| (string) ( $writer_principal['principal_id'] ?? '' ) === (string) ( $quality['reviewer_principal']['principal_id'] ?? '' )
			|| self::translation_job_submission_generation( $job ) !== absint( $artifact_record['submission_generation'] ?? 1 )
			|| self::translation_job_submission_generation( $job ) !== absint( $quality['submission_generation'] ?? 1 )
		) {
			return array( 'success' => false, 'code' => 'quality_principal_generation_mismatch', 'message' => 'Publication requires fresh, separate writer and Quality principals bound to the current submission generation.' );
		}
		$translation_id = self::translation_job_resolve_publication_translation_id( $job, $artifact_record );
		if ( $translation_id ) { $job['translation_id'] = $translation_id; }
		$staged_apply = null;
		$surface_snapshot = null;
		if ( ! empty( $artifact_record['staged'] ) ) {
			if (
				! $is_quality_authority_v3
				|| ! hash_equals( (string) ( $artifact_record['surface_revision'] ?? '' ), (string) ( $quality['surface_revision'] ?? '' ) )
				|| empty( self::translation_job_validate_quality_evidence_record( $quality, $artifact_record )['success'] )
			) {
				return array( 'success' => false, 'code' => 'quality_authority_evidence_missing', 'message' => 'Staged publication requires the exact server-bound Quality evidence record.' );
			}
			$surface_snapshot = self::translation_job_capture_surface_snapshot( $translation_id, (array) ( $artifact_record['surface_manifest'] ?? array() ), self::translation_job_publication_identity_scope( $job ) );
			if ( empty( $surface_snapshot['snapshot_valid'] ) ) {
				return array( 'success' => false, 'code' => 'publication_snapshot_failed', 'message' => 'The complete pre-publication surface could not be captured safely.', 'snapshot' => $surface_snapshot );
			}
			$staged_apply = self::translation_job_apply_staged_artifact( $job, $artifact_record, $surface_snapshot );
			if ( empty( $staged_apply['success'] ) ) {
				$surface_snapshot['mutation_started'] = ! empty( $staged_apply['mutation_started'] );
				$surface_snapshot['rollback_expected_surface_revision'] = (string) ( $staged_apply['mutation_surface_revision'] ?? '' );
				$failure = self::translation_job_publish_failure_with_rollback( $staged_apply, $surface_snapshot, absint( $staged_apply['translation_id'] ?? $translation_id ) );
				if ( in_array( (string) ( $staged_apply['code'] ?? '' ), self::TRANSLATION_JOB_SURFACE_REFRESH_PUBLISH_FAILURE_CODES, true ) ) {
					$surface_refresh = self::translation_job_refresh_drifted_surface( $job, 'publish_baseline_mismatch', $failure );
					$failure['surface_refresh'] = $surface_refresh;
					if ( isset( $surface_refresh['job'] ) && is_array( $surface_refresh['job'] ) ) {
						$failure['job'] = self::translation_job_public_job( $surface_refresh['job'] );
					}
				}
				return $failure;
			}
			$translation_id = absint( $staged_apply['translation_id'] ?? 0 );
			$surface_snapshot['mutation_started'] = ! empty( $staged_apply['mutation_started'] );
			$surface_snapshot['rollback_expected_surface_revision'] = (string) ( $staged_apply['mutation_cas_revision'] ?? '' );
		} elseif ( self::translation_job_translation_revision( $translation_id ) !== (string) ( $quality['content_revision'] ?? '' ) ) {
			return array( 'success' => false, 'code' => 'artifact_content_changed', 'message' => 'Stored translation changed after the Quality Decision.' );
		}
		$featured_image_sync = is_array( $staged_apply ) && isset( $staged_apply['featured_image_sync'] )
			? $staged_apply['featured_image_sync']
			: self::sync_source_featured_image( $translation_id, $source );
		if ( empty( $featured_image_sync['write_verified'] ) ) {
			if ( is_array( $surface_snapshot ) ) {
				$surface_snapshot['mutation_started'] = true;
			}
			return self::translation_job_publish_failure_with_rollback( array( 'success' => false, 'code' => 'featured_image_sync_failed', 'message' => 'The approved source featured image could not be synchronized before publication.', 'featured_image_sync' => $featured_image_sync ), $surface_snapshot, $translation_id );
		}
		if ( ! is_array( $staged_apply ) && ! empty( $featured_image_sync['changed'] ) ) {
			$translation_job_identity = self::translation_job_identity(
				$job,
				array( 'coordinator_id' => $coordinator_id, 'run_id' => 'publish-media-reconcile' ),
				'publish'
			);
			self::record_translation_visible_media_provenance( $translation_id, $translation_job_identity, 'translation_job_publish_reconcile' );
			self::sync_translation_index_row( $translation_id );
		}
		$qa = self::qa_translation( array( 'translation_id' => $translation_id ) );
		if ( empty( $qa['success'] ) || empty( $qa['passed'] ) ) {
			return self::translation_job_publish_failure_with_rollback( array( 'success' => false, 'code' => 'publish_qa_failed', 'message' => 'Deterministic QA failed before publication.', 'qa' => $qa ), $surface_snapshot, $translation_id );
		}
		$language = sanitize_key( (string) $job['target_language'] );
		$translation_post = get_post( $translation_id );
		$publication_experience = $translation_post instanceof WP_Post
			? self::publication_experience_readiness_for_post( $translation_post, $language, 'pre_publish' )
			: array( 'passed' => false );
		if ( empty( $publication_experience['passed'] ) ) {
			return self::translation_job_publish_failure_with_rollback( array( 'success' => false, 'code' => 'publication_experience_failed', 'message' => 'Publication experience failed before publication.', 'publication_experience' => $publication_experience ), $surface_snapshot, $translation_id );
		}
		$renewed_lease = self::translation_job_renew_lifecycle_lease( $lifecycle_lease );
		if ( empty( $renewed_lease['success'] ) ) {
			return self::translation_job_publish_failure_with_rollback( $renewed_lease, $surface_snapshot, $translation_id );
		}
		$lifecycle_lease = $renewed_lease;
		$prepublication_cas_revision = is_array( $surface_snapshot )
			? (string) ( $surface_snapshot['rollback_expected_surface_revision'] ?? '' )
			: self::translation_job_rollback_cas_revision( $translation_id );
		if ( '' === $prepublication_cas_revision ) {
			return self::translation_job_publish_failure_with_rollback( array( 'success' => false, 'code' => 'prepublication_surface_receipt_failed', 'message' => 'The locked publication precondition could not be captured.' ), $surface_snapshot, $translation_id );
		}
		$publication = self::publish_localized_presentation(
			array(
				'translation_id'            => $translation_id,
				'language'                  => $language,
				'source_id'                 => (int) $job['source_id'],
				'job_id'                    => (string) $job['job_id'],
				'sync_menu'                 => ! array_key_exists( 'sync_menu', $input ) || ! empty( $input['sync_menu'] ),
				'include_custom_links'      => true,
				'verify_live'               => true,
				'live_verification_timeout' => absint( $input['live_verification_timeout'] ?? 15 ),
				'rollback_term_scope'        => is_array( $surface_snapshot ) ? (array) ( $surface_snapshot['term_scope'] ?? array() ) : array(),
				'rollback_identity_scope'    => is_array( $surface_snapshot ) ? (array) ( $surface_snapshot['identity_scope'] ?? array() ) : array(),
				'expected_mutation_cas_revision' => $prepublication_cas_revision,
				'recover_staged_mutation'      => is_array( $surface_snapshot ) && ! empty( $surface_snapshot['mutation_started'] ),
			)
		);
		if ( empty( $publication['success'] ) ) {
			if ( is_array( $surface_snapshot ) ) {
				$surface_snapshot['mutation_started'] = array_key_exists( 'mutation_started', $publication ) ? ! empty( $publication['mutation_started'] ) : true;
				$surface_snapshot['rollback_expected_surface_revision'] = (string) ( $publication['mutation_cas_revision'] ?? '' );
			}
			return self::translation_job_publish_failure_with_rollback( array_merge(
				$publication,
				array(
					'job'                    => self::translation_job_public_job( $job ),
					'translation'            => self::translation_payload( get_post( $translation_id ) ),
					'qa'                     => $qa,
					'publication_experience' => $publication_experience,
					'featured_image_sync'    => $featured_image_sync,
				)
			), $surface_snapshot, $translation_id );
		}
		$menu = $publication['menu'] ?? null;
		$live = $publication['live_verification'] ?? null;
		$orphaned_runs_finalized = self::translation_job_finalize_orphaned_runs( $job );
		$next = self::translation_job_transition( $job, array( 'status' => 'published', 'translation_id' => $translation_id, 'content_revision' => self::translation_job_translation_revision( $translation_id ), 'applied_surface_revision' => self::translation_job_current_surface_revision( $translation_id ), 'published_at' => gmdate( 'c' ), 'live_verification_passed' => null === $live ? null : ! empty( $live['passed'] ) ) );
		if ( ! empty( $next['success'] ) ) { delete_post_meta( $translation_id, '_devenia_workflow_publication_attempt_id' ); }
		$passed = null === $live || ( ! empty( $live['success'] ) && ! empty( $live['passed'] ) );
		return array(
			'success' => $passed && ! empty( $next['success'] ),
			'published' => true,
			'message' => $passed ? 'Translation Job published.' : 'Translation was published, but live verification failed.',
			'job' => ! empty( $next['job'] ) ? self::translation_job_public_job( $next['job'] ) : self::translation_job_public_job( $job ),
			'translation' => self::translation_payload( get_post( $translation_id ) ),
			'qa' => $qa,
			'publication_experience' => $publication_experience,
			'featured_image_sync' => $featured_image_sync,
			'menu' => $menu,
			'purge_urls' => $publication['purge_urls'] ?? array(),
			'cache_invalidation' => $publication['cache_invalidation'] ?? null,
			'live_verification' => $live,
			'orphaned_runs_finalized' => $orphaned_runs_finalized,
		);
		} finally {
			self::translation_job_release_lifecycle_lease( $lifecycle_lease );
		}
	}

	private static function translation_job_status( array $input ): array {
		$job_id = self::translation_job_clean_id( (string) ( $input['job_id'] ?? '' ) );
		if ( '' === $job_id && ! empty( $input['source_id'] ) && ! empty( $input['language'] ) ) {
			$source = get_post( absint( $input['source_id'] ) );
			if ( $source ) {
				$job_id = self::translation_job_id( (int) $source->ID, sanitize_key( (string) $input['language'] ), self::source_hash( $source ) );
			}
		}
		$job = self::translation_job_get_job( $job_id );
		if ( ! $job ) {
			return self::error( 'Translation Job not found.' );
		}
		$runs = array();
		$totals = array( 'input_tokens' => 0, 'cached_input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'duration_ms' => 0, 'estimated_cost_microusd' => 0 );
		foreach ( (array) ( $job['run_ids'] ?? array() ) as $run_row ) {
			$run = get_option( self::translation_job_run_key( (string) ( $run_row['run_id'] ?? '' ) ) );
			if ( ! is_array( $run ) ) {
				continue;
			}
			$runs[] = $run;
			foreach ( array_keys( $totals ) as $key ) {
				$totals[ $key ] += absint( $run['usage'][ $key ] ?? 0 );
			}
		}
		$quality = ! empty( $job['quality_revision'] ) ? get_option( self::translation_job_quality_key( (string) $job['quality_revision'] ) ) : null;
		return array( 'success' => true, 'job' => self::translation_job_public_job( $job ), 'runs' => $runs, 'quality_decision' => is_array( $quality ) ? $quality : null, 'cost' => $totals );
	}

	private static function translation_job_translation_packet( array $job, array $run, WP_Post $source ): array {
		$contract = self::source_design_contract( $source );
		$fragments = self::translation_job_source_fragments( $contract );
		$language = (string) $job['target_language'];
		$existing_translation_id = self::find_translation_id( (int) $source->ID, $language, self::translation_workflow_post_statuses( false ) );
		$existing_translation = $existing_translation_id ? get_post( $existing_translation_id ) : null;
		$existing_route = array();
		if ( $existing_translation instanceof WP_Post ) {
			$existing_url = (string) get_permalink( $existing_translation );
			$existing_route = array(
				'translation_id' => (int) $existing_translation->ID,
				'canonical_url'  => $existing_url,
				'canonical_path' => $existing_url ? self::normalized_url_path( $existing_url ) : '',
				'localized_slug' => (string) $existing_translation->post_name,
				'parent_id'      => (int) $existing_translation->post_parent,
				'localized_path' => trim( (string) get_post_meta( (int) $existing_translation->ID, self::META_LOCALIZED_PATH, true ), '/' ),
				'route_locked'   => 'publish' === $existing_translation->post_status,
			);
		}
		$packet = array(
			'contract_version' => 3,
			'job' => self::translation_job_public_job( $job ),
			'run' => array( 'run_id' => $run['run_id'], 'role' => $run['role'], 'budget' => $run['budget'], 'context_mode' => 'bounded_packet', 'submission_generation' => self::translation_job_submission_generation( $job ), 'principal' => $run['principal'] ?? array() ),
			'source' => array(
				'title' => get_the_title( $source ),
				'excerpt' => (string) $source->post_excerpt,
				'seo_title' => (string) get_post_meta( (int) $source->ID, 'rank_math_title', true ),
				'seo_description' => (string) get_post_meta( (int) $source->ID, 'rank_math_description', true ),
				'post_type' => (string) $source->post_type,
			),
			'fragments' => $fragments,
			'route' => array( 'language_prefix' => self::language_prefix( $language ), 'source_slug' => (string) $source->post_name, 'source_parent_id' => (int) $source->post_parent, 'existing' => $existing_route, 'policy' => $existing_route && ! empty( $existing_route['route_locked'] ) ? 'Preserve the established canonical route exactly. Ordinary translation work cannot migrate a published URL.' : 'Create one localized route for this new translation; publication establishes its Canonical Route Contract.' ),
			'taxonomy' => self::post_taxonomy_payload( $source ),
			'links' => self::translation_job_link_policy( $source, $language ),
			'language_profile' => self::translation_job_language_profile( (int) $source->ID, $language ),
			'source_approval' => self::translation_job_source_approval( $source ),
			'validation_contract' => array( 'exact_fragment_coverage' => true, 'localized_route' => true, 'deterministic_qa' => true, 'staged_public_mutation' => false, 'quality_authority' => 'server_receipts_plus_principal_bound_attestations' ),
			'submission_contract' => self::translation_job_submission_contract( 'translator' ),
		);
		$correction_context = self::translation_job_correction_context( $job );
		if ( $correction_context ) {
			$packet['correction_context'] = $correction_context;
		}
		return $packet;
	}

	private static function translation_job_quality_packet( array $job, array $run, WP_Post $source ): array {
		$artifact = self::translation_job_unpack_artifact_record( get_option( self::translation_job_artifact_key( (string) ( $job['artifact_revision'] ?? '' ) ) ) );
		$source_contract = self::source_design_contract( $source );
		$server_receipts = is_array( $artifact )
			? self::translation_job_server_quality_receipts( $job, $artifact, (array) ( $run['principal'] ?? array() ) )
			: array( 'success' => false, 'code' => 'artifact_record_missing' );
		return array(
			'contract_version' => 3,
			'job' => self::translation_job_public_job( $job ),
			'run' => array( 'run_id' => $run['run_id'], 'role' => $run['role'], 'budget' => $run['budget'], 'context_mode' => 'bounded_packet', 'submission_generation' => self::translation_job_submission_generation( $job ), 'principal' => $run['principal'] ?? array() ),
			'source' => array(
				'title' => get_the_title( $source ),
				'excerpt' => (string) $source->post_excerpt,
				'source_revision' => self::source_hash( $source ),
				'fragments' => self::translation_job_source_fragments( $source_contract ),
				'approval' => self::translation_job_source_approval( $source ),
			),
			'artifact' => is_array( $artifact ) ? $artifact : array(),
			'surface_revision' => (string) ( $artifact['surface_revision'] ?? '' ),
			'writer_principal' => is_array( $artifact['writer_principal'] ?? null ) ? $artifact['writer_principal'] : array(),
			'links' => self::translation_job_link_policy( $source, (string) $job['target_language'] ),
			'contact_actions' => array(
				'source' => self::translation_job_mailto_actions( self::translation_job_source_fragments( $source_contract ) ),
				'translation' => self::translation_job_mailto_actions( (array) ( $artifact['artifact']['localized_fragments'] ?? array() ) ),
				'policy' => 'Review decoded email, subject, and body. Subject/body must be natural target-language copy, not unchanged source-language query text.',
			),
			'language_profile' => self::translation_job_language_profile( (int) $source->ID, (string) $job['target_language'] ),
			'required_checks' => self::translation_job_quality_checks(),
			'evidence_contract' => array(
				'server_receipt_note' => 'Use only the immutable receipt IDs issued in this packet.',
				'server_receipt_error' => empty( $server_receipts['success'] ) ? $server_receipts : null,
				'server_receipt_ids' => array_values( (array) ( $server_receipts['receipt_ids'] ?? array() ) ),
				'server_receipts' => array_values( (array) ( $server_receipts['receipts'] ?? array() ) ),
				'reviewer_attestations' => array( 'natural_language', 'factual_accuracy' ),
				'browser_receipts' => array( 'desktop:light', 'desktop:dark', 'mobile:light', 'mobile:dark' ),
				'trust_model' => 'Workflow computes deterministic receipts. Natural-language and visual judgment remain explicit attestations from this fresh authenticated Quality Run.',
			),
			'submission_contract' => self::translation_job_submission_contract( 'quality' ),
		);
	}

	/**
	 * Decode mailto actions from bounded source or translated fragments.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function translation_job_mailto_actions( array $fragments ): array {
		$actions = array();
		foreach ( $fragments as $fragment ) {
			if ( ! is_array( $fragment ) ) {
				continue;
			}
			$html = (string) ( $fragment['source_html'] ?? $fragment['html'] ?? $fragment['text'] ?? '' );
			if ( '' === $html || false === stripos( $html, 'mailto:' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
				continue;
			}
			$processor = new WP_HTML_Tag_Processor( $html );
			while ( $processor->next_tag( 'A' ) ) {
				$href = html_entity_decode( (string) $processor->get_attribute( 'href' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( 0 !== stripos( $href, 'mailto:' ) ) {
					continue;
				}
				$parts = wp_parse_url( $href );
				$query = isset( $parts['query'] ) && is_string( $parts['query'] ) ? $parts['query'] : '';
				$params = array();
				wp_parse_str( $query, $params );
				$actions[] = array(
					'fragment_key' => (string) ( $fragment['key'] ?? '' ),
					'email' => rawurldecode( (string) ( $parts['path'] ?? '' ) ),
					'subject' => isset( $params['subject'] ) && is_scalar( $params['subject'] ) ? (string) $params['subject'] : '',
					'body' => isset( $params['body'] ) && is_scalar( $params['body'] ) ? (string) $params['body'] : '',
				);
			}
		}

		return $actions;
	}

	/**
	 * Reject hidden source-language contact copy before it reaches quality review.
	 *
	 * @return array<string,mixed>
	 */
	private static function translation_job_artifact_contact_policy( WP_Post $source, array $localized_fragments ): array {
		$source_actions = self::translation_job_mailto_actions( self::translation_job_source_fragments( self::source_design_contract( $source ) ) );
		$target_actions = self::translation_job_mailto_actions( $localized_fragments );
		$target_by_fragment = array();
		foreach ( $target_actions as $action ) {
			$target_by_fragment[ (string) $action['fragment_key'] ][] = $action;
		}
		$issues = array();
		foreach ( $source_actions as $source_action ) {
			$fragment_key = (string) $source_action['fragment_key'];
			$candidates = $target_by_fragment[ $fragment_key ] ?? array();
			foreach ( $candidates as $target_action ) {
				foreach ( array( 'subject', 'body' ) as $field ) {
					$source_value = trim( (string) $source_action[ $field ] );
					$target_value = trim( (string) $target_action[ $field ] );
					if ( '' !== $source_value && '' !== $target_value && 0 === strcasecmp( $source_value, $target_value ) ) {
						$issues[] = array( 'fragment_key' => $fragment_key, 'field' => $field, 'source_value' => $source_value );
					}
				}
			}
		}

		return empty( $issues )
			? array( 'success' => true, 'source_actions' => $source_actions, 'translation_actions' => $target_actions )
			: array( 'success' => false, 'code' => 'artifact_contact_action_not_localized', 'message' => 'Translate mailto subject/body query text before saving the artifact.', 'issues' => $issues );
	}

	/**
	 * Merge source-scoped QA terms into the bounded language profile.
	 *
	 * @return array<string,mixed>
	 */
	private static function translation_job_language_profile( int $source_id, string $language ): array {
		$profile = self::language_review_profile( $language );
		$source_terms = self::source_qa_carryover_preserve_terms( $source_id, $language );
		$profile['source_qa_preserve_terms'] = $source_terms;
		$profile['preserve_terms'] = array_values(
			array_unique(
				array_merge(
					is_array( $profile['preserve_terms'] ?? null ) ? $profile['preserve_terms'] : array(),
					$source_terms
				)
			)
		);

		return $profile;
	}

	/**
	 * Keep submit shape inside the packet so bounded Runs do not need discovery
	 * or conversation history to construct a valid terminal payload.
	 *
	 * @return array<string,mixed>
	 */
	private static function translation_job_submission_contract( string $role ): array {
		if ( 'quality' === $role ) {
			return array(
				'ability' => 'devenia-workflow/translation-job-submit-quality-decision',
				'input_schema' => self::translation_job_quality_schema(),
				'payload_example' => array(
					'job_id' => '<packet.job.job_id>',
					'run_id' => '<packet.run.run_id>',
					'claim_token' => '<same claim token used to fetch this packet>',
					'artifact_revision' => '<packet.artifact.artifact_revision>',
					'surface_revision' => '<packet.surface_revision>',
					'decision' => 'pass|revise|reject',
					'evidence_receipt_ids' => array( '<packet.evidence_contract.server_receipt_ids>' ),
					'reviewer_attestations' => array( array( 'kind' => 'natural_language', 'passed' => true, 'observation' => '<concrete language evidence>' ), array( 'kind' => 'factual_accuracy', 'passed' => true, 'observation' => '<concrete fact evidence>' ) ),
					'reviewer_observations' => '<concrete review summary>',
					'browser_receipts' => array( '<four policy-bound browser receipt objects>' ),
					'corrections' => array( '<required correction; omit array when empty>' ),
					'usage' => array( 'input_tokens' => 0, 'cached_input_tokens' => 0, 'output_tokens' => 0, 'attempts' => 1, 'duration_ms' => 0, 'estimated_cost_microusd' => 0 ),
				),
				'rules' => array( 'Use only properties declared by input_schema.', 'corrections is an array of strings, not an object.' ),
			);
		}

		return array(
			'ability' => 'devenia-workflow/translation-job-submit-artifact',
			'input_schema' => self::translation_job_artifact_schema(),
			'payload_example' => array(
				'job_id' => '<packet.job.job_id>',
				'run_id' => '<packet.run.run_id>',
				'claim_token' => '<same claim token used to fetch this packet>',
				'artifact' => array(
					'title' => '<localized title>',
					'excerpt' => '<localized excerpt>',
					'localized_slug' => '<for a new translation only; omit for an existing published translation>',
					'seo' => array( 'title' => '<localized SEO title>', 'description' => '<localized meta description>', 'focus_keyword' => '<localized focus keyword>' ),
					'localized_fragments' => array( array( 'key' => '<exact source fragment key>', 'html' => '<localized HTML; use text instead only for plain text>' ) ),
				),
				'usage' => array( 'input_tokens' => 0, 'cached_input_tokens' => 0, 'output_tokens' => 0, 'attempts' => 1, 'duration_ms' => 0, 'estimated_cost_microusd' => 0 ),
			),
			'rules' => array( 'Use only properties declared by input_schema.', 'For an existing published translation, omit route fields; the server preserves packet.route.existing exactly.', 'SEO fields belong inside artifact.seo; never add seo_title or seo_description to artifact.', 'Each localized fragment must contain key and exactly one of html or text; html_or_text is not a property.' ),
		);
	}

	/**
	 * Describe the only valid destination for each internal source link.
	 *
	 * A localized destination is authoritative only while its WordPress post is
	 * published. Otherwise the English source URL remains the explicit fallback;
	 * callers must never infer a localized slug that is not in the registry.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function translation_job_link_policy( WP_Post $source, string $language ): array {
		$map = self::localized_internal_link_map( $language );
		$links = array();
		foreach ( self::translation_job_anchor_hrefs( (string) $source->post_content ) as $href ) {
			$parts = wp_parse_url( $href );
			if ( ! is_array( $parts ) ) {
				continue;
			}
			$site_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
			$link_host = strtolower( (string) ( $parts['host'] ?? '' ) );
			if ( '' !== $link_host && $site_host !== $link_host ) {
				continue;
			}
			$source_url = self::translation_job_absolute_internal_url( $href );
			if ( '' === $source_url ) {
				continue;
			}
			$source_post_id = self::wordpress_content_id_from_internal_url( $source_url );
			$canonical_source_id = $source_post_id ? absint( get_post_meta( $source_post_id, self::META_SOURCE_ID, true ) ) : 0;
			if ( $canonical_source_id ) {
				$source_post_id = $canonical_source_id;
				$canonical_source_url = get_permalink( $source_post_id );
				if ( $canonical_source_url ) {
					$source_url = (string) $canonical_source_url;
				}
			}

			$mapped_target = self::localized_internal_link_target( $source_url, $map );
			$target_url = self::translation_job_absolute_internal_url( (string) ( $mapped_target ?: $source_url ) );
			$target_post_id = self::wordpress_content_id_from_internal_url( $target_url );
			$localized_available = self::normalized_comparable_url( $source_url ) !== self::normalized_comparable_url( $target_url )
				&& $target_post_id > 0
				&& 'publish' === get_post_status( $target_post_id );
			if ( ! $localized_available ) {
				$target_url = $source_url;
				$target_post_id = $source_post_id;
			}

			$key = self::normalized_comparable_url( $source_url );
			$links[ $key ] = array(
				'source_url' => $source_url,
				'source_post_id' => $source_post_id,
				'published_target_available' => $localized_available,
				'target_url' => $target_url,
				'target_post_id' => $target_post_id,
				'policy' => $localized_available ? 'use_published_localized_target' : 'retain_source_url_until_localized_target_is_published',
			);
		}

		return array_values( $links );
	}

	/**
	 * Require the submitted artifact to retain every source link at its current
	 * authoritative destination.
	 */
	private static function translation_job_artifact_link_policy( WP_Post $source, string $language, $localized_fragments ): array {
		$link_policy = self::translation_job_link_policy( $source, $language );
		$actual = array();
		foreach ( is_array( $localized_fragments ) ? $localized_fragments : array() as $fragment ) {
			$html = is_array( $fragment ) ? (string) ( $fragment['html'] ?? '' ) : '';
			foreach ( self::translation_job_anchor_hrefs( $html ) as $href ) {
				$absolute = self::translation_job_absolute_internal_url( $href );
				if ( '' !== $absolute ) {
					$actual[ self::normalized_comparable_url( $absolute ) ] = $absolute;
				}
			}
		}

		$issues = array();
		foreach ( $link_policy as $link ) {
			$expected = (string) ( $link['target_url'] ?? '' );
			if ( '' !== $expected && ! isset( $actual[ self::normalized_comparable_url( $expected ) ] ) ) {
				$issues[] = array(
					'source_url' => (string) ( $link['source_url'] ?? '' ),
					'expected_url' => $expected,
					'policy' => (string) ( $link['policy'] ?? '' ),
				);
			}
		}
		if ( $issues ) {
			return array(
				'success' => false,
				'code' => 'artifact_link_policy_invalid',
				'message' => 'Artifact must use every authoritative link target from the bounded packet.',
				'issues' => $issues,
			);
		}

		return array( 'success' => true, 'checked_link_count' => count( $link_policy ) );
	}

	/**
	 * Extract href values without changing Gutenberg serialization.
	 *
	 * @return array<int,string>
	 */
	private static function translation_job_anchor_hrefs( string $html ): array {
		if ( '' === $html || ! preg_match_all( '/<a\b[^>]*\bhref=(["\'])([^"\']+)\1/i', $html, $matches ) ) {
			return array();
		}

		return array_values( array_unique( array_map( static function ( string $href ): string {
			return html_entity_decode( trim( $href ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}, $matches[2] ) ) );
	}

	/**
	 * Normalize an internal link to an absolute URL for packet comparison.
	 */
	private static function translation_job_absolute_internal_url( string $url ): string {
		if ( '' === $url || '#' === $url[0] || preg_match( '/^(mailto|tel|sms|javascript):/i', $url ) ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$site_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$link_host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( '' !== $link_host && $site_host !== $link_host ) {
			return '';
		}
		$query_post_id = self::wordpress_content_query_id_from_parts( $parts );
		if ( $query_post_id ) {
			$permalink = get_permalink( $query_post_id );
			return $permalink ? (string) $permalink : '';
		}
		$path = self::normalized_url_path( $url );
		if ( '' === $path ) {
			return '';
		}
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

		return home_url( $path ) . $query . $fragment;
	}

	private static function translation_job_fragment_coverage( array $job, $localized_fragments ): array {
		$source = get_post( (int) $job['source_id'] );
		$contract = $source ? self::source_design_contract( $source ) : array();
		$expected = array_values( array_filter( array_map( static function ( $row ) { return (string) ( $row['key'] ?? '' ); }, (array) ( $contract['fragments'] ?? array() ) ) ) );
		$provided = array();
		$duplicates = array();
		foreach ( is_array( $localized_fragments ) ? $localized_fragments : array() as $row ) {
			$key = (string) ( is_array( $row ) ? ( $row['key'] ?? '' ) : '' );
			if ( isset( $provided[ $key ] ) ) {
				$duplicates[] = $key;
			}
			$provided[ $key ] = true;
		}
		$provided_keys = array_keys( array_filter( $provided ) );
		$missing = array_values( array_diff( $expected, $provided_keys ) );
		$extra = array_values( array_diff( $provided_keys, $expected ) );
		if ( $missing || $extra || $duplicates || count( $expected ) !== count( $provided_keys ) ) {
			return array( 'success' => false, 'code' => 'artifact_fragment_coverage_invalid', 'message' => 'Artifact must contain every source fragment exactly once.', 'expected_count' => count( $expected ), 'provided_count' => count( $provided_keys ), 'missing_keys' => $missing, 'extra_keys' => $extra, 'duplicate_keys' => array_values( array_unique( $duplicates ) ) );
		}
		return array( 'success' => true, 'fragment_count' => count( $expected ) );
	}

	private static function translation_job_claim_access( array $input, string $role = '' ): array {
		$job = self::translation_job_get_job( (string) ( $input['job_id'] ?? '' ) );
		$run_id = self::translation_job_clean_id( (string) ( $input['run_id'] ?? '' ) );
		$run = get_option( self::translation_job_run_key( $run_id ) );
		$lock = $job ? get_option( self::translation_job_claim_key( (string) $job['job_id'] ) ) : null;
		if ( ! $job || ! is_array( $run ) || ! is_array( $lock ) ) {
			return array( 'success' => false, 'code' => 'job_claim_missing', 'message' => 'A current Translation Job claim is required.' );
		}
		if ( strtotime( (string) ( $lock['expires_at'] ?? '' ) ) <= time() ) {
			return array( 'success' => false, 'code' => 'job_claim_expired', 'message' => 'Translation Job claim expired.' );
		}
		$token = (string) ( $input['claim_token'] ?? '' );
		if ( '' === $token || ! hash_equals( (string) ( $lock['token_hash'] ?? '' ), hash( 'sha256', $token ) ) || $run_id !== (string) ( $lock['run_id'] ?? '' ) ) {
			return array( 'success' => false, 'code' => 'job_claim_mismatch', 'message' => 'Translation Job claim does not match this Run.' );
		}
		if ( '' !== $role && $role !== (string) ( $run['role'] ?? '' ) ) {
			return array( 'success' => false, 'code' => 'job_run_role_mismatch', 'message' => 'Run role does not match this operation.' );
		}
		$generation = self::translation_job_submission_generation( $job );
		if ( $generation !== max( 1, absint( $run['submission_generation'] ?? 1 ) ) || $generation !== max( 1, absint( $lock['submission_generation'] ?? 1 ) ) ) {
			return array( 'success' => false, 'code' => 'job_claim_generation_mismatch', 'message' => 'The claim belongs to an older immutable submission generation.' );
		}
		$configured_budget = self::translation_job_budget( (string) ( $run['role'] ?? '' ) );
		$stored_budget = isset( $run['budget'] ) && is_array( $run['budget'] ) ? $run['budget'] : array();
		if ( (int) ( $stored_budget['input_token_limit'] ?? 0 ) < (int) $configured_budget['input_token_limit'] ) {
			$run['budget'] = $configured_budget;
			$run['budget_migrated_at'] = gmdate( 'c' );
			$run_key = self::translation_job_run_key( $run_id );
			update_option( $run_key, $run, false );
			$stored_run = get_option( $run_key );
			if ( ! is_array( $stored_run ) || (int) ( $stored_run['budget']['input_token_limit'] ?? 0 ) < (int) $configured_budget['input_token_limit'] ) {
				return array( 'success' => false, 'code' => 'run_budget_migration_failed', 'message' => 'The active Run budget could not be upgraded safely.' );
			}
			$run = $stored_run;
		}
		return array( 'success' => true, 'job' => $job, 'run' => $run, 'claim' => $lock );
	}

	private static function translation_job_validate_usage( $raw, array $budget, array $run = array(), array $submission = array() ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$packet_tokens = absint( $run['packet_estimated_input_tokens'] ?? 0 );
		if ( $packet_tokens < 1 || empty( $run['packet_revision'] ) ) {
			return array( 'success' => false, 'code' => 'run_packet_not_fetched', 'message' => 'Fetch the bounded packet before submitting a Translation Job outcome so usage and contract revisions are server-measured.' );
		}
		$measured_submission = $submission;
		unset( $measured_submission['claim_token'], $measured_submission['usage'] );
		$output_tokens = max( 1, (int) ceil( strlen( wp_json_encode( $measured_submission ) ?: '' ) / 4 ) );
		$started_at = strtotime( (string) ( $run['started_at'] ?? '' ) );
		$duration_ms = false !== $started_at ? max( 1, ( time() - $started_at ) * 1000 ) : 1;
		$usage = array(
			'input_tokens' => $packet_tokens,
			'cached_input_tokens' => 0,
			'output_tokens' => $output_tokens,
			'attempts' => 1,
			'duration_ms' => $duration_ms,
			'estimated_cost_microusd' => 0,
			'measurement_source' => 'server_payload_estimate',
			'measurement_state' => 'estimated_not_provider_measured',
			'packet_revision' => (string) $run['packet_revision'],
			'caller_reported_usage' => array(
				'input_tokens' => absint( $raw['input_tokens'] ?? 0 ),
				'cached_input_tokens' => absint( $raw['cached_input_tokens'] ?? 0 ),
				'output_tokens' => absint( $raw['output_tokens'] ?? 0 ),
				'attempts' => absint( $raw['attempts'] ?? 0 ),
				'duration_ms' => absint( $raw['duration_ms'] ?? 0 ),
				'estimated_cost_microusd' => absint( $raw['estimated_cost_microusd'] ?? 0 ),
			),
		);
		// zero_token_usage_not_measured: caller zeros are retained only as a report,
		// never as authoritative usage. This receipt binds the server payload estimate.
		$usage['usage_receipt_id'] = 'ur_' . substr( hash( 'sha256', wp_json_encode( array( $run['principal']['principal_id'] ?? '', $usage['packet_revision'], $usage['input_tokens'], $usage['output_tokens'], $usage['duration_ms'] ) ) ?: '' ), 0, 40 );
		$usage['total_tokens'] = $usage['input_tokens'] + $usage['output_tokens'];
		$violations = array();
		if ( $usage['input_tokens'] > (int) $budget['input_token_limit'] ) { $violations[] = 'input_token_limit'; }
		if ( $usage['output_tokens'] > (int) $budget['output_token_limit'] ) { $violations[] = 'output_token_limit'; }
		if ( $usage['total_tokens'] > (int) $budget['total_token_limit'] ) { $violations[] = 'total_token_limit'; }
		return empty( $violations )
			? array( 'success' => true, 'usage' => $usage )
			: array( 'success' => false, 'code' => 'run_budget_exceeded', 'message' => 'Run usage exceeds its Token Budget.', 'violations' => $violations, 'usage' => $usage, 'budget' => $budget );
	}

	private static function translation_job_finish_run( array $run, string $outcome, array $usage ): void {
		$run['status'] = 'completed';
		$run['outcome'] = sanitize_key( $outcome );
		$run['usage'] = $usage;
		$run['finished_at'] = gmdate( 'c' );
		update_option( self::translation_job_run_key( (string) $run['run_id'] ), $run, false );
	}

	/** Finish a defensive pre-submission Run without inventing measured usage. */
	private static function translation_job_finish_run_without_usage( array $run, string $outcome ): void {
		$run['status'] = 'completed';
		$run['outcome'] = sanitize_key( $outcome );
		$run['usage'] = array( 'measurement_state' => 'unavailable_before_submission' );
		$run['finished_at'] = gmdate( 'c' );
		update_option( self::translation_job_run_key( (string) $run['run_id'] ), $run, false );
	}

	private static function translation_job_transition( array $job, array $patch ): array {
		$key = self::translation_job_job_key( (string) $job['job_id'] );
		$next = array_merge( $job, $patch, array( 'updated_at' => gmdate( 'c' ) ) );
		global $wpdb;
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Job lifecycle requires compare-and-swap semantics.
			$wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s, autoload = %s WHERE option_name = %s AND option_value = %s", maybe_serialize( $next ), 'off', $key, maybe_serialize( $job ) )
		);
		wp_cache_delete( $key, 'options' );
		if ( 1 !== $updated ) {
			return array( 'success' => false, 'code' => 'job_state_conflict', 'message' => 'Translation Job changed concurrently.', 'job' => self::translation_job_get_job( (string) $job['job_id'] ) );
		}
		return array( 'success' => true, 'job' => $next );
	}

	private static function translation_job_internal_step_identity( string $step ): array {
		$identity = self::$translation_job_internal_identity;
		return is_array( $identity ) && ( $identity['step'] ?? '' ) === $step ? $identity : array();
	}

	private static function translation_job_identity( array $job, array $run, string $step ): array {
		$coordinator = (string) $run['coordinator_id'];
		return array(
			'success' => true,
			'step' => $step,
			'workflow_step' => $step,
			'step_token_label' => 'translation-job',
			'process_id' => $coordinator,
			'control_scope_id' => $coordinator,
			'execution_id' => $coordinator,
			'session_origin' => 'same_session',
			'actor' => 'translation-job:' . $coordinator,
			'actor_id' => 'translation-job',
			'authority' => 'wordpress-capability-job-claim',
			'authority_vendor' => 'wordpress',
			'authority_client' => 'translation-job',
			'job_id' => (string) $job['job_id'],
			'run_id' => (string) $run['run_id'],
		);
	}

	private static function translation_job_quality_checks(): array {
		return array( 'source_quality', 'natural_language', 'factual_accuracy', 'source_coverage', 'localized_search_intent', 'offer_and_contact', 'links_and_route', 'rendered_experience' );
	}

	private static function translation_job_source_fragments( array $contract ): array {
		$fragments = array();
		foreach ( (array) ( $contract['fragments'] ?? array() ) as $fragment ) {
			$fragments[] = array(
				'key' => (string) ( $fragment['key'] ?? '' ),
				'source_html' => (string) ( $fragment['source_html'] ?? $fragment['text'] ?? '' ),
				'heading' => ! empty( $fragment['heading'] ),
			);
		}
		return $fragments;
	}

	private static function translation_job_correction_context( array $job ): array {
		$quality_revision = (string) ( $job['quality_revision'] ?? '' );
		$artifact_revision = (string) ( $job['artifact_revision'] ?? '' );
		$refresh = array();
		if ( '' === $quality_revision || '' === $artifact_revision ) {
			$history = is_array( $job['surface_refresh_history'] ?? null ) ? $job['surface_refresh_history'] : array();
			$refresh = $history ? (array) end( $history ) : array();
			$quality_revision = (string) ( $refresh['prior_quality_revision'] ?? '' );
			$artifact_revision = (string) ( $refresh['prior_artifact_revision'] ?? '' );
		}
		if ( '' === $artifact_revision || ( empty( $refresh ) && '' === $quality_revision ) ) {
			return array();
		}
		$quality = '' !== $quality_revision ? get_option( self::translation_job_quality_key( $quality_revision ) ) : null;
		$artifact = self::translation_job_unpack_artifact_record( get_option( self::translation_job_artifact_key( $artifact_revision ) ) );
		if ( ! is_array( $artifact ) || ( empty( $refresh ) && ( ! is_array( $quality ) || 'revise' !== (string) ( $quality['decision'] ?? '' ) ) ) ) {
			return array();
		}
		return array(
			'previous_artifact_revision' => $artifact_revision,
			'previous_artifact' => $artifact,
			'quality_revision' => $quality_revision,
			'failed_checks' => is_array( $quality ) ? array_values(
				array_keys(
					array_filter(
						(array) ( $quality['checks'] ?? array() ),
						static function ( $passed ): bool { return ! $passed; }
					)
				)
			) : array(),
			'evidence' => is_array( $quality ) ? (string) ( $quality['reviewer_observations'] ?? $quality['evidence'] ?? '' ) : '',
			'corrections' => is_array( $quality ) ? array_values( (array) ( $quality['corrections'] ?? array() ) ) : array(),
			'surface_refresh' => $refresh,
		);
	}

	/** Return the server-owned generation for current and legacy Job records. */
	private static function translation_job_submission_generation( array $job ): int {
		return max( 1, absint( $job['submission_generation'] ?? 1 ) );
	}

	/**
	 * Reopen the exact current Job after server-observed public baseline drift.
	 *
	 * Old artifacts, Quality Decisions, evidence receipts, and Runs stay immutable.
	 * The publish path additionally requires proof that its owned transaction was
	 * rolled back before this compare-and-swap transition is allowed.
	 */
	private static function translation_job_refresh_drifted_surface( array $job, string $reason, array $publication_failure = array() ): array {
		$reason = sanitize_key( $reason );
		if ( ! in_array( $reason, array( 'claim_baseline_mismatch', 'quality_packet_baseline_mismatch', 'quality_submission_baseline_mismatch', 'publish_baseline_mismatch' ), true ) ) {
			return array( 'success' => false, 'code' => 'surface_refresh_reason_invalid', 'message' => 'Surface Refresh requires a fixed server-owned lifecycle reason.' );
		}
		$status = sanitize_key( (string) ( $job['status'] ?? '' ) );
		if ( ! in_array( $status, array( 'quality_pending', 'ready_to_publish' ), true ) ) {
			return array( 'success' => true, 'refreshed' => false, 'job' => $job );
		}
		if ( self::translation_job_source_is_stale( $job ) ) {
			return array( 'success' => true, 'refreshed' => false, 'job' => $job );
		}
		$artifact_revision = (string) ( $job['artifact_revision'] ?? '' );
		$artifact = self::translation_job_unpack_artifact_record( get_option( self::translation_job_artifact_key( $artifact_revision ) ) );
		if ( '' === $artifact_revision || empty( $artifact['staged'] ) ) {
			return array( 'success' => true, 'refreshed' => false, 'job' => $job );
		}
		if (
			(string) ( $artifact['job_id'] ?? '' ) !== (string) ( $job['job_id'] ?? '' )
			|| self::translation_job_submission_generation( $job ) !== max( 1, absint( $artifact['submission_generation'] ?? 1 ) )
		) {
			return array( 'success' => false, 'code' => 'surface_refresh_artifact_binding_mismatch', 'message' => 'The active artifact is not bound to the exact current Job generation.', 'job' => self::translation_job_public_job( $job ) );
		}
		$translation_id = self::translation_job_resolve_publication_translation_id( $job, $artifact );
		$current_surface_revision = $translation_id ? self::translation_job_current_surface_revision( $translation_id ) : '';
		$baseline_surface_revision = (string) ( $artifact['baseline_surface_revision'] ?? '' );
		if ( hash_equals( $baseline_surface_revision, $current_surface_revision ) ) {
			return array( 'success' => true, 'refreshed' => false, 'job' => $job, 'baseline_surface_revision' => $baseline_surface_revision, 'current_surface_revision' => $current_surface_revision );
		}

		if ( 'publish_baseline_mismatch' === $reason ) {
			$rollback = isset( $publication_failure['transaction_rollback'] ) && is_array( $publication_failure['transaction_rollback'] ) ? $publication_failure['transaction_rollback'] : array();
			if (
				! in_array( (string) ( $publication_failure['code'] ?? '' ), self::TRANSLATION_JOB_SURFACE_REFRESH_PUBLISH_FAILURE_CODES, true )
				|| ! empty( $publication_failure['mutation_started'] )
				|| empty( $rollback['success'] )
				|| empty( $rollback['rolled_back'] )
			) {
				return array( 'success' => false, 'code' => 'surface_refresh_proof_missing', 'message' => 'Publication may reopen a Job only after zero mutation and a proven successful transaction rollback.', 'job' => self::translation_job_public_job( $job ) );
			}
		}

		$generation = self::translation_job_submission_generation( $job );
		if ( $generation >= self::TRANSLATION_JOB_MAX_SUBMISSION_GENERATIONS ) {
			$terminal = self::translation_job_transition(
				$job,
				array(
					'status' => 'failed_technical',
					'active_run_id' => '',
					'surface_refresh_limit' => array(
						'failed_at' => gmdate( 'c' ),
						'generation' => $generation,
						'max_generations' => self::TRANSLATION_JOB_MAX_SUBMISSION_GENERATIONS,
						'baseline_surface_revision' => $baseline_surface_revision,
						'current_surface_revision' => $current_surface_revision,
						'reason' => $reason,
					),
				)
			);
			if ( empty( $terminal['success'] ) ) {
				return $terminal;
			}
			return array(
				'success' => false,
				'code' => 'surface_refresh_generation_limit',
				'message' => 'The finite Surface Refresh generation limit was reached. The Job failed closed for operator inspection.',
				'job' => self::translation_job_public_job( $terminal['job'] ),
				'max_generations' => self::TRANSLATION_JOB_MAX_SUBMISSION_GENERATIONS,
			);
		}

		$history = is_array( $job['surface_refresh_history'] ?? null ) ? array_values( $job['surface_refresh_history'] ) : array();
		$history[] = array(
			'prior_artifact_revision' => $artifact_revision,
			'prior_content_revision' => (string) ( $job['content_revision'] ?? '' ),
			'prior_surface_revision' => (string) ( $job['surface_revision'] ?? '' ),
			'prior_quality_revision' => (string) ( $job['quality_revision'] ?? '' ),
			'prior_baseline_surface_revision' => $baseline_surface_revision,
			'current_surface_revision' => $current_surface_revision,
			'reason' => $reason,
			'publication_failure_code' => 'publish_baseline_mismatch' === $reason ? sanitize_key( (string) ( $publication_failure['code'] ?? '' ) ) : '',
			'refreshed_at' => gmdate( 'c' ),
			'from_generation' => $generation,
			'to_generation' => $generation + 1,
		);
		$transition = self::translation_job_transition(
			$job,
			array(
				'status' => 'changes_requested',
				'submission_generation' => $generation + 1,
				'surface_refresh_history' => $history,
				'artifact_revision' => '',
				'content_revision' => '',
				'surface_revision' => '',
				'quality_revision' => '',
				'active_run_id' => '',
			)
		);
		if ( empty( $transition['success'] ) ) {
			return $transition;
		}
		return array(
			'success' => true,
			'refreshed' => true,
			'message' => 'The exact current Job was reopened for a fresh translator and Quality generation after public baseline drift.',
			'job' => self::translation_job_public_job( $transition['job'] ),
			'refresh' => end( $history ),
		);
	}

	private static function translation_job_source_approval( WP_Post $source ): array {
		$source_hash = self::source_hash( $source );
		$validation = self::source_content_integrity_validation( $source );
		$evidence = self::json_post_meta_value( (int) $source->ID, self::META_SOURCE_CONTENT_INTEGRITY_REVIEW_EVIDENCE );
		$reviewed_at = (string) get_post_meta( (int) $source->ID, self::META_SOURCE_CONTENT_INTEGRITY_REVIEWED_AT, true );
		$reviewer = (string) get_post_meta( (int) $source->ID, self::META_SOURCE_CONTENT_INTEGRITY_REVIEWER, true );
		$publication = self::publication_experience_readiness_for_post( $source, self::source_language_code(), 'pre_publish' );
		$evidence_source_hash = (string) ( $evidence['source_hash'] ?? '' );
		$passed = empty( $validation['issue_count'] )
			&& ! empty( $publication['passed'] )
			&& ! empty( $evidence['content_integrity_already_clean'] )
			&& '' !== $reviewed_at
			&& '' !== $reviewer
			&& '' !== $evidence_source_hash
			&& hash_equals( $source_hash, $evidence_source_hash )
			&& strlen( trim( (string) ( $evidence['audit_notes'] ?? '' ) ) ) >= 80
			&& strlen( trim( (string) ( $evidence['reviewer_statement'] ?? '' ) ) ) >= 80;
		return array(
			'passed' => $passed,
			'state' => $passed ? 'source_quality_approved' : 'source_quality_approval_required',
			'source_id' => (int) $source->ID,
			'source_hash' => $source_hash,
			'reviewed_at' => $reviewed_at,
			'reviewer' => $reviewer,
			'content_integrity_passed' => empty( $validation['issue_count'] ),
			'publication_experience_passed' => ! empty( $publication['passed'] ),
			'evidence_matches_source' => '' !== $evidence_source_hash && hash_equals( $source_hash, $evidence_source_hash ),
		);
	}

	private static function translation_job_budget( string $role ): array {
		return 'quality' === $role
			? array( 'input_token_limit' => 40000, 'output_token_limit' => 10000, 'total_token_limit' => 50000, 'max_attempts' => 2 )
			: array( 'input_token_limit' => 40000, 'output_token_limit' => 30000, 'total_token_limit' => 70000, 'max_attempts' => 2 );
	}

	private static function translation_job_source_is_stale( array $job ): bool {
		$source = get_post( absint( $job['source_id'] ?? 0 ) );
		return ! $source || ! hash_equals( (string) ( $job['source_revision'] ?? '' ), self::source_hash( $source ) );
	}

	private static function translation_job_translation_revision( int $translation_id ): string {
		$post = get_post( $translation_id );
		return $post ? hash( 'sha256', (string) $post->post_title . "\n" . (string) $post->post_excerpt . "\n" . (string) $post->post_content ) : '';
	}

	private static function translation_job_sanitize_artifact_record( array $artifact ): array {
		return json_decode( wp_json_encode( $artifact ) ?: '{}', true ) ?: array();
	}

	private static function translation_job_pack_artifact_record( array $record ): array {
		$artifact = isset( $record['artifact'] ) && is_array( $record['artifact'] ) ? $record['artifact'] : array();
		$encoded = base64_encode( wp_json_encode( $artifact ) ?: '{}' );
		unset( $record['artifact'] );
		$record['artifact_encoding'] = 'base64-json-v1';
		$record['artifact_payload'] = $encoded;
		return $record;
	}

	private static function translation_job_unpack_artifact_record( $record ): array {
		if ( ! is_array( $record ) ) {
			return array();
		}
		if ( 'base64-json-v1' !== (string) ( $record['artifact_encoding'] ?? '' ) ) {
			return $record;
		}
		$decoded = base64_decode( (string) ( $record['artifact_payload'] ?? '' ), true );
		$artifact = false !== $decoded ? json_decode( $decoded, true ) : null;
		if ( ! is_array( $artifact ) ) {
			return array();
		}
		$record['artifact'] = $artifact;
		return $record;
	}

	private static function translation_job_revision( $value ): string {
		$value = self::translation_job_canonicalize( $value );
		return 'a_' . substr( hash( 'sha256', wp_json_encode( $value ) ?: '' ), 0, 32 );
	}

	private static function translation_job_canonicalize( $value ) {
		if ( ! is_array( $value ) ) { return $value; }
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) { ksort( $value ); }
		foreach ( $value as $key => $item ) { $value[ $key ] = self::translation_job_canonicalize( $item ); }
		return $value;
	}

	private static function translation_job_id( int $source_id, string $language, string $source_revision ): string {
		return 'tj_' . substr( hash( 'sha256', $source_id . '|' . $language . '|' . $source_revision ), 0, 32 );
	}

	private static function translation_job_clean_id( string $value ): string {
		$value = preg_replace( '/[^a-zA-Z0-9._:-]/', '', sanitize_text_field( $value ) );
		return substr( (string) $value, 0, 100 );
	}

	private static function translation_job_role_attempt_count( array $run_rows, string $role, int $submission_generation = 1 ): int {
		$role = sanitize_key( $role );
		$submission_generation = max( 1, $submission_generation );
		$count = 0;
		foreach ( $run_rows as $run_row ) {
			if ( ! is_array( $run_row ) || $role !== sanitize_key( (string) ( $run_row['role'] ?? '' ) ) ) {
				continue;
			}
			$run_id = self::translation_job_clean_id( (string) ( $run_row['run_id'] ?? '' ) );
			$run = '' !== $run_id ? get_option( self::translation_job_run_key( $run_id ) ) : null;
			$run_generation = max( 1, absint( is_array( $run ) ? ( $run['submission_generation'] ?? 1 ) : ( $run_row['submission_generation'] ?? 1 ) ) );
			if ( $submission_generation !== $run_generation ) {
				continue;
			}
			if (
				is_array( $run )
				&& 'completed' === (string) ( $run['status'] ?? '' )
				&& in_array( (string) ( $run['outcome'] ?? '' ), array( 'expired', 'abandoned' ), true )
			) {
				continue;
			}
			++$count;
		}
		return $count;
	}

	private static function translation_job_get_job( string $job_id ): array {
		$job = get_option( self::translation_job_job_key( self::translation_job_clean_id( $job_id ) ) );
		return is_array( $job ) ? $job : array();
	}

	private static function translation_job_public_job( array $job ): array {
		$job['submission_generation'] = self::translation_job_submission_generation( $job );
		$job['surface_refresh_history'] = is_array( $job['surface_refresh_history'] ?? null ) ? array_values( $job['surface_refresh_history'] ) : array();
		unset( $job['claim_token'], $job['token_hash'] );
		return $job;
	}

	private static function translation_job_public_claim( array $claim ): array {
		unset( $claim['token_hash'] );
		return $claim;
	}

	private static function translation_job_release_claim( string $job_id ): void {
		delete_option( self::translation_job_claim_key( $job_id ) );
	}

	private static function translation_job_expire_run( array $claim ): void {
		$run_id = self::translation_job_clean_id( (string) ( $claim['run_id'] ?? '' ) );
		if ( '' === $run_id ) {
			return;
		}
		$run_key = self::translation_job_run_key( $run_id );
		$run = get_option( $run_key );
		if ( ! is_array( $run ) || 'running' !== (string) ( $run['status'] ?? '' ) ) {
			return;
		}
		$expired_at = sanitize_text_field( (string) ( $claim['expires_at'] ?? '' ) );
		$run['status'] = 'completed';
		$run['outcome'] = 'expired';
		$run['finished_at'] = $expired_at && strtotime( $expired_at ) ? gmdate( 'c', strtotime( $expired_at ) ) : gmdate( 'c' );
		update_option( $run_key, $run, false );
	}

	private static function translation_job_finalize_orphaned_runs( array $job ): int {
		$active_run_id = self::translation_job_clean_id( (string) ( $job['active_run_id'] ?? '' ) );
		$finalized = 0;
		foreach ( (array) ( $job['run_ids'] ?? array() ) as $run_row ) {
			$run_id = self::translation_job_clean_id( (string) ( $run_row['run_id'] ?? '' ) );
			if ( '' === $run_id || $run_id === $active_run_id ) {
				continue;
			}
			$run_key = self::translation_job_run_key( $run_id );
			$run = get_option( $run_key );
			if ( ! is_array( $run ) || 'running' !== (string) ( $run['status'] ?? '' ) ) {
				continue;
			}
			$run['status'] = 'completed';
			$run['outcome'] = 'expired';
			$run['finished_at'] = gmdate( 'c' );
			update_option( $run_key, $run, false );
			++$finalized;
		}
		return $finalized;
	}

	private static function translation_job_job_key( string $job_id ): string { return 'devenia_workflow_translation_job_' . self::translation_job_clean_id( $job_id ); }
	private static function translation_job_claim_key( string $job_id ): string { return 'devenia_workflow_translation_job_claim_' . self::translation_job_clean_id( $job_id ); }
	private static function translation_job_run_key( string $run_id ): string { return 'devenia_workflow_translation_run_' . self::translation_job_clean_id( $run_id ); }
	private static function translation_job_artifact_key( string $revision ): string { return 'devenia_workflow_translation_artifact_' . self::translation_job_clean_id( $revision ); }
	private static function translation_job_quality_key( string $revision ): string { return 'devenia_workflow_translation_quality_' . self::translation_job_clean_id( $revision ); }
}
