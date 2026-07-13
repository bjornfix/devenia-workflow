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
		$check_properties = array();
		foreach ( self::translation_job_quality_checks() as $check ) {
			$check_properties[ $check ] = array( 'type' => 'boolean' );
		}
		return array(
			'type' => 'object',
			'required' => array( 'job_id', 'run_id', 'claim_token', 'artifact_revision', 'decision', 'checks', 'evidence', 'usage' ),
			'properties' => array(
				'job_id' => array( 'type' => 'string' ),
				'run_id' => array( 'type' => 'string' ),
				'claim_token' => array( 'type' => 'string' ),
				'artifact_revision' => array( 'type' => 'string' ),
				'decision' => array( 'type' => 'string', 'enum' => array( 'pass', 'revise', 'reject' ) ),
				'checks' => array( 'type' => 'object', 'required' => self::translation_job_quality_checks(), 'properties' => $check_properties, 'additionalProperties' => false ),
				'evidence' => array( 'type' => 'string' ),
				'corrections' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'usage' => self::translation_job_usage_schema(),
			),
			'additionalProperties' => false,
		);
	}

	private static function translation_job_publish_schema(): array {
		return array(
			'type' => 'object',
			'required' => array( 'job_id', 'coordinator_id' ),
			'properties' => array(
				'job_id' => array( 'type' => 'string' ),
				'coordinator_id' => array( 'type' => 'string' ),
				'sync_menu' => array( 'type' => 'boolean', 'default' => true ),
				'verify_live' => array( 'type' => 'boolean', 'default' => true ),
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
				'schema_version' => 2,
				'job_id' => $job_id,
				'source_id' => $source_id,
				'source_revision' => $source_revision,
				'target_language' => $language,
				'observability_label' => sanitize_text_field( (string) ( $input['observability_label'] ?? '' ) ),
				'status' => 'queued',
				'created_at' => $now,
				'updated_at' => $now,
				'run_ids' => array(),
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
		$job = self::translation_job_get_job( (string) ( $input['job_id'] ?? '' ) );
		if ( ! $job ) {
			return self::error( 'Translation Job not found.' );
		}
		$role = sanitize_key( (string) ( $input['role'] ?? '' ) );
		if ( ! in_array( $role, array( 'translator', 'quality' ), true ) ) {
			return self::error( 'Run role must be translator or quality.' );
		}
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
		$run_id = self::translation_job_clean_id( (string) ( $input['run_id'] ?? '' ) );
		$coordinator_id = self::translation_job_clean_id( (string) ( $input['coordinator_id'] ?? '' ) );
		if ( '' === $run_id || '' === $coordinator_id ) {
			return self::error( 'run_id and coordinator_id are required.' );
		}
		$existing_runs = isset( $job['run_ids'] ) && is_array( $job['run_ids'] ) ? $job['run_ids'] : array();
		if ( self::translation_job_role_attempt_count( $existing_runs, $role ) >= self::TRANSLATION_JOB_MAX_RUNS_PER_ROLE ) {
			return array( 'success' => false, 'code' => 'run_attempt_limit', 'message' => 'This Job used every allowed bounded Run for this role.' );
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
			'token_hash' => hash( 'sha256', $token ),
			'claimed_at' => gmdate( 'c', $now ),
			'expires_at' => gmdate( 'c', $now + $ttl ),
		);
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
			'status' => 'running',
			'started_at' => gmdate( 'c', $now ),
		);
		if ( ! self::atomic_create_option( self::translation_job_run_key( $run_id ), $run ) ) {
			delete_option( $lock_key );
			return array( 'success' => false, 'code' => 'run_id_conflict', 'message' => 'run_id already exists.' );
		}
		$next_runs = $existing_runs;
		$next_runs[] = array( 'run_id' => $run_id, 'role' => $role );
		$next_status = 'translator' === $role ? 'claimed' : 'quality_pending';
		$next = self::translation_job_transition( $job, array( 'status' => $next_status, 'run_ids' => $next_runs, 'active_run_id' => $run_id, 'coordinator_id' => $coordinator_id ) );
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
		$packet['estimated_input_tokens'] = $estimated_tokens;
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
		$usage = self::translation_job_validate_usage( $input['usage'] ?? array(), $run['budget'] );
		if ( empty( $usage['success'] ) ) {
			self::translation_job_finish_run( $run, 'budget_exceeded', $usage['usage'] ?? array() );
			self::translation_job_transition( $job, array( 'status' => 'budget_exceeded' ) );
			self::translation_job_release_claim( (string) $job['job_id'] );
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
		$translation_id = absint( $job['translation_id'] ?? 0 );
		if (
			! $translation_id
			|| (int) $job['source_id'] !== absint( get_post_meta( $translation_id, self::META_SOURCE_ID, true ) )
			|| (string) $job['target_language'] !== (string) get_post_meta( $translation_id, self::META_LANGUAGE, true )
		) {
			$translation_id = self::find_translation_id( (int) $job['source_id'], (string) $job['target_language'], self::translation_workflow_post_statuses( false ) );
		}
		$upsert = array_merge(
			$artifact,
			array(
				'source_id' => (int) $job['source_id'],
				'language' => (string) $job['target_language'],
				'translation_id' => $translation_id,
				'inherit_source_design' => true,
				'strict_source_design_fragments' => true,
				'status' => $translation_id && 'publish' === get_post_status( $translation_id ) ? 'publish' : 'draft',
				'translation_status' => 'needs_review',
				'allow_update_published' => true,
				'execution_id' => (string) $run['coordinator_id'],
				'writer_process_id' => (string) $run['coordinator_id'],
				'writer_actor' => (string) $run['coordinator_id'],
			)
		);
		self::$translation_job_internal_identity = self::translation_job_identity( $job, $run, 'draft_write' );
		try {
			$result = self::upsert_translation( $upsert );
		} finally {
			self::$translation_job_internal_identity = array();
		}
		if ( empty( $result['success'] ) ) {
			return $result;
		}
		$translation_id = absint( $result['translation']['id'] ?? 0 );
		$featured_image_sync = self::sync_source_featured_image( $translation_id, $source );
		if ( empty( $featured_image_sync['write_verified'] ) ) {
			return array(
				'success' => false,
				'code' => 'featured_image_sync_failed',
				'message' => 'The translation artifact was saved, but its featured image could not be synchronized with the approved source.',
				'featured_image_sync' => $featured_image_sync,
			);
		}
		if ( ! empty( $featured_image_sync['changed'] ) ) {
			$translation_job_identity = self::translation_job_identity( $job, $run, 'draft_write' );
			self::record_translation_visible_media_provenance( $translation_id, $translation_job_identity, 'translation_job_artifact_submit' );
			self::sync_translation_index_row( $translation_id );
		}
		// Scope the content-addressed revision to its immutable Job contract.
		// Identical localized payloads can legitimately occur after a source
		// revision changes; they must not collide with another Job's record.
		$artifact_revision = self::translation_job_revision(
			array(
				'job_id'          => (string) $job['job_id'],
				'source_revision' => (string) $job['source_revision'],
				'target_language' => (string) $job['target_language'],
				'artifact'        => $artifact,
			)
		);
		$content_revision = self::translation_job_translation_revision( $translation_id );
		$artifact_record = array(
			'artifact_revision' => $artifact_revision,
			'job_id' => (string) $job['job_id'],
			'source_revision' => (string) $job['source_revision'],
			'translation_id' => $translation_id,
			'content_revision' => $content_revision,
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
		$next = self::translation_job_transition( $job, array( 'status' => 'quality_pending', 'artifact_revision' => $artifact_revision, 'translation_id' => $translation_id, 'content_revision' => $content_revision, 'quality_revision' => '', 'active_run_id' => '' ) );
		self::translation_job_release_claim( (string) $job['job_id'] );
		if ( empty( $next['success'] ) ) {
			return $next;
		}
		return array( 'success' => true, 'message' => 'Complete translation artifact submitted.', 'job' => self::translation_job_public_job( $next['job'] ), 'artifact_revision' => $artifact_revision, 'translation' => self::translation_payload( get_post( $translation_id ) ), 'featured_image_sync' => $featured_image_sync, 'qa_next' => 'devenia-workflow/translation-job-claim with role=quality' );
	}

	private static function translation_job_submit_quality_decision( array $input ): array {
		$access = self::translation_job_claim_access( $input, 'quality' );
		if ( empty( $access['success'] ) ) {
			return $access;
		}
		$job = $access['job'];
		$run = $access['run'];
		$source = get_post( (int) $job['source_id'] );
		$source_approval = $source instanceof WP_Post ? self::translation_job_source_approval( $source ) : array( 'passed' => false );
		if ( empty( $source_approval['passed'] ) || self::translation_job_source_is_stale( $job ) ) {
			return array( 'success' => false, 'code' => 'source_quality_approval_required', 'message' => 'Quality cannot pass against an unapproved or changed source revision.', 'source_approval' => $source_approval );
		}
		if ( ! hash_equals( (string) ( $job['artifact_revision'] ?? '' ), sanitize_text_field( (string) ( $input['artifact_revision'] ?? '' ) ) ) ) {
			return array( 'success' => false, 'code' => 'artifact_revision_mismatch', 'message' => 'Quality Decision does not match the current artifact revision.' );
		}
		$usage = self::translation_job_validate_usage( $input['usage'] ?? array(), $run['budget'] );
		if ( empty( $usage['success'] ) ) {
			self::translation_job_finish_run( $run, 'budget_exceeded', $usage['usage'] ?? array() );
			self::translation_job_transition( $job, array( 'status' => 'budget_exceeded' ) );
			self::translation_job_release_claim( (string) $job['job_id'] );
			return $usage;
		}
		$decision = sanitize_key( (string) ( $input['decision'] ?? '' ) );
		if ( ! in_array( $decision, array( 'pass', 'revise', 'reject' ), true ) ) {
			return self::error( 'Quality Decision must be pass, revise, or reject.' );
		}
		$evidence = trim( sanitize_textarea_field( (string) ( $input['evidence'] ?? '' ) ) );
		if ( strlen( $evidence ) < 40 ) {
			return array( 'success' => false, 'code' => 'quality_evidence_required', 'message' => 'Quality Decision requires concrete evidence.' );
		}
		$checks = isset( $input['checks'] ) && is_array( $input['checks'] ) ? $input['checks'] : array();
		$failed_checks = array();
		foreach ( self::translation_job_quality_checks() as $check ) {
			if ( empty( $checks[ $check ] ) ) {
				$failed_checks[] = $check;
			}
		}
		$translation_id = absint( $job['translation_id'] ?? 0 );
		if ( self::translation_job_translation_revision( $translation_id ) !== (string) ( $job['content_revision'] ?? '' ) ) {
			return array( 'success' => false, 'code' => 'artifact_content_changed', 'message' => 'Stored translation changed after artifact submission.' );
		}
		$qa = self::qa_translation( array( 'translation_id' => $translation_id ) );
		$translation = get_post( $translation_id );
		$publication_experience = $translation instanceof WP_Post
			? self::publication_experience_readiness_for_post( $translation, (string) $job['target_language'], 'pre_publish' )
			: array( 'passed' => false );
		if ( 'pass' === $decision && ( $failed_checks || empty( $qa['success'] ) || empty( $qa['passed'] ) || empty( $publication_experience['passed'] ) ) ) {
			return array( 'success' => false, 'code' => 'quality_pass_rejected', 'message' => 'A passing Quality Decision requires all checks, deterministic QA, and publication experience to pass.', 'failed_checks' => $failed_checks, 'qa' => $qa, 'publication_experience' => $publication_experience );
		}
		$quality = array(
			'quality_revision' => self::translation_job_revision( array( $job['artifact_revision'], $decision, $checks, $input['evidence'] ?? '', $input['corrections'] ?? array() ) ),
			'job_id' => (string) $job['job_id'],
			'artifact_revision' => (string) $job['artifact_revision'],
			'content_revision' => (string) $job['content_revision'],
			'translation_id' => $translation_id,
			'decision' => $decision,
			'checks' => array_map( 'boolval', $checks ),
			'evidence' => $evidence,
			'corrections' => array_values( array_map( 'sanitize_text_field', is_array( $input['corrections'] ?? null ) ? $input['corrections'] : array() ) ),
			'coordinator_id' => (string) $run['coordinator_id'],
			'run_id' => (string) $run['run_id'],
			'qa' => array( 'passed' => ! empty( $qa['passed'] ), 'issue_count' => absint( $qa['issue_count'] ?? 0 ), 'warning_count' => absint( $qa['warning_count'] ?? 0 ) ),
			'publication_experience' => array( 'passed' => ! empty( $publication_experience['passed'] ), 'state' => (string) ( $publication_experience['state'] ?? '' ) ),
			'decided_at' => gmdate( 'c' ),
		);
		$quality_key = self::translation_job_quality_key( $quality['quality_revision'] );
		if ( ! self::atomic_create_option( $quality_key, $quality ) ) {
			$existing_quality = get_option( $quality_key );
			$identity_fields = array( 'quality_revision', 'job_id', 'artifact_revision', 'content_revision', 'translation_id' );
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
		$job = self::translation_job_get_job( (string) ( $input['job_id'] ?? '' ) );
		$job_status = (string) ( $job['status'] ?? '' );
		if ( ! $job || ! in_array( $job_status, array( 'ready_to_publish', 'published' ), true ) ) {
			return array( 'success' => false, 'code' => 'job_not_ready_to_publish', 'message' => 'Translation Job does not have a passing current Quality Decision.' );
		}
		$quality = get_option( self::translation_job_quality_key( (string) ( $job['quality_revision'] ?? '' ) ) );
		$coordinator_id = self::translation_job_clean_id( (string) ( $input['coordinator_id'] ?? '' ) );
		if ( ! is_array( $quality ) || 'pass' !== (string) ( $quality['decision'] ?? '' ) || $coordinator_id !== (string) ( $quality['coordinator_id'] ?? '' ) ) {
			return array( 'success' => false, 'code' => 'quality_decision_authority_mismatch', 'message' => 'The current coordinator and passing Quality Decision do not match.' );
		}
		if ( self::translation_job_source_is_stale( $job ) || (string) ( $quality['artifact_revision'] ?? '' ) !== (string) ( $job['artifact_revision'] ?? '' ) ) {
			return array( 'success' => false, 'code' => 'job_superseded', 'message' => 'Source or artifact changed after the Quality Decision.' );
		}
		$source = get_post( (int) $job['source_id'] );
		$source_approval = $source instanceof WP_Post ? self::translation_job_source_approval( $source ) : array( 'passed' => false );
		if ( empty( $source_approval['passed'] ) ) {
			return array( 'success' => false, 'code' => 'source_quality_approval_required', 'message' => 'Current source revision is not approved for publication.', 'source_approval' => $source_approval );
		}
		$translation_id = absint( $job['translation_id'] ?? 0 );
		if ( self::translation_job_translation_revision( $translation_id ) !== (string) ( $quality['content_revision'] ?? '' ) ) {
			return array( 'success' => false, 'code' => 'artifact_content_changed', 'message' => 'Stored translation changed after the Quality Decision.' );
		}
		$featured_image_sync = self::sync_source_featured_image( $translation_id, $source );
		if ( empty( $featured_image_sync['write_verified'] ) ) {
			return array( 'success' => false, 'code' => 'featured_image_sync_failed', 'message' => 'The approved source featured image could not be synchronized before publication.', 'featured_image_sync' => $featured_image_sync );
		}
		if ( ! empty( $featured_image_sync['changed'] ) ) {
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
			return array( 'success' => false, 'code' => 'publish_qa_failed', 'message' => 'Deterministic QA failed before publication.', 'qa' => $qa );
		}
		$language = sanitize_key( (string) $job['target_language'] );
		$translation_post = get_post( $translation_id );
		$publication_experience = $translation_post instanceof WP_Post
			? self::publication_experience_readiness_for_post( $translation_post, $language, 'pre_publish' )
			: array( 'passed' => false );
		if ( empty( $publication_experience['passed'] ) ) {
			return array( 'success' => false, 'code' => 'publication_experience_failed', 'message' => 'Publication experience failed before publication.', 'publication_experience' => $publication_experience );
		}
		$publication = self::publish_localized_presentation(
			array(
				'translation_id'            => $translation_id,
				'language'                  => $language,
				'source_id'                 => (int) $job['source_id'],
				'job_id'                    => (string) $job['job_id'],
				'sync_menu'                 => ! array_key_exists( 'sync_menu', $input ) || ! empty( $input['sync_menu'] ),
				'include_custom_links'      => true,
				'verify_live'               => ! array_key_exists( 'verify_live', $input ) || ! empty( $input['verify_live'] ),
				'live_verification_timeout' => absint( $input['live_verification_timeout'] ?? 15 ),
			)
		);
		if ( empty( $publication['success'] ) ) {
			return array_merge(
				$publication,
				array(
					'job'                    => self::translation_job_public_job( $job ),
					'translation'            => self::translation_payload( get_post( $translation_id ) ),
					'qa'                     => $qa,
					'publication_experience' => $publication_experience,
					'featured_image_sync'    => $featured_image_sync,
				)
			);
		}
		$menu = $publication['menu'] ?? null;
		$live = $publication['live_verification'] ?? null;
		$orphaned_runs_finalized = self::translation_job_finalize_orphaned_runs( $job );
		$next = self::translation_job_transition( $job, array( 'status' => 'published', 'published_at' => gmdate( 'c' ), 'live_verification_passed' => null === $live ? null : ! empty( $live['passed'] ) ) );
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
			'contract_version' => 2,
			'job' => self::translation_job_public_job( $job ),
			'run' => array( 'run_id' => $run['run_id'], 'role' => $run['role'], 'budget' => $run['budget'], 'context_mode' => 'bounded_packet' ),
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
			'validation_contract' => array( 'exact_fragment_coverage' => true, 'localized_route' => true, 'deterministic_qa' => true, 'quality_checks' => self::translation_job_quality_checks() ),
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
		return array(
			'contract_version' => 2,
			'job' => self::translation_job_public_job( $job ),
			'run' => array( 'run_id' => $run['run_id'], 'role' => $run['role'], 'budget' => $run['budget'], 'context_mode' => 'bounded_packet' ),
			'source' => array(
				'title' => get_the_title( $source ),
				'excerpt' => (string) $source->post_excerpt,
				'source_revision' => self::source_hash( $source ),
				'fragments' => self::translation_job_source_fragments( $source_contract ),
				'approval' => self::translation_job_source_approval( $source ),
			),
			'artifact' => is_array( $artifact ) ? $artifact : array(),
			'links' => self::translation_job_link_policy( $source, (string) $job['target_language'] ),
			'contact_actions' => array(
				'source' => self::translation_job_mailto_actions( self::translation_job_source_fragments( $source_contract ) ),
				'translation' => self::translation_job_mailto_actions( (array) ( $artifact['artifact']['localized_fragments'] ?? array() ) ),
				'policy' => 'Review decoded email, subject, and body. Subject/body must be natural target-language copy, not unchanged source-language query text.',
			),
			'language_profile' => self::translation_job_language_profile( (int) $source->ID, (string) $job['target_language'] ),
			'required_checks' => self::translation_job_quality_checks(),
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
					'decision' => 'pass|revise|reject',
					'checks' => array_fill_keys( self::translation_job_quality_checks(), true ),
					'evidence' => '<concrete review evidence>',
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

	private static function translation_job_validate_usage( $raw, array $budget ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$usage = array(
			'input_tokens' => absint( $raw['input_tokens'] ?? 0 ),
			'cached_input_tokens' => absint( $raw['cached_input_tokens'] ?? 0 ),
			'output_tokens' => absint( $raw['output_tokens'] ?? 0 ),
			'attempts' => absint( $raw['attempts'] ?? 0 ),
			'duration_ms' => absint( $raw['duration_ms'] ?? 0 ),
			'estimated_cost_microusd' => absint( $raw['estimated_cost_microusd'] ?? 0 ),
		);
		$usage['total_tokens'] = $usage['input_tokens'] + $usage['output_tokens'];
		$violations = array();
		if ( $usage['cached_input_tokens'] > $usage['input_tokens'] ) { $violations[] = 'cached_input_tokens'; }
		if ( $usage['input_tokens'] > (int) $budget['input_token_limit'] ) { $violations[] = 'input_token_limit'; }
		if ( $usage['output_tokens'] > (int) $budget['output_token_limit'] ) { $violations[] = 'output_token_limit'; }
		if ( $usage['total_tokens'] > (int) $budget['total_token_limit'] ) { $violations[] = 'total_token_limit'; }
		if ( $usage['attempts'] < 1 || $usage['attempts'] > (int) $budget['max_attempts'] ) { $violations[] = 'max_attempts'; }
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
		if ( '' === $quality_revision || '' === $artifact_revision ) {
			return array();
		}
		$quality = get_option( self::translation_job_quality_key( $quality_revision ) );
		$artifact = self::translation_job_unpack_artifact_record( get_option( self::translation_job_artifact_key( $artifact_revision ) ) );
		if ( ! is_array( $quality ) || ! is_array( $artifact ) || 'revise' !== (string) ( $quality['decision'] ?? '' ) ) {
			return array();
		}
		return array(
			'previous_artifact_revision' => $artifact_revision,
			'previous_artifact' => $artifact,
			'quality_revision' => $quality_revision,
			'failed_checks' => array_values(
				array_keys(
					array_filter(
						(array) ( $quality['checks'] ?? array() ),
						static function ( $passed ): bool { return ! $passed; }
					)
				)
			),
			'evidence' => (string) ( $quality['evidence'] ?? '' ),
			'corrections' => array_values( (array) ( $quality['corrections'] ?? array() ) ),
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

	private static function translation_job_role_attempt_count( array $run_rows, string $role ): int {
		$role = sanitize_key( $role );
		$count = 0;
		foreach ( $run_rows as $run_row ) {
			if ( ! is_array( $run_row ) || $role !== sanitize_key( (string) ( $run_row['role'] ?? '' ) ) ) {
				continue;
			}
			$run_id = self::translation_job_clean_id( (string) ( $run_row['run_id'] ?? '' ) );
			$run = '' !== $run_id ? get_option( self::translation_job_run_key( $run_id ) ) : null;
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
