<?php
/**
 * Staged Source Rewrite Artifact and independent Quality authority.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Source_Rewrite_Quality_Authority {
	private const SOURCE_REWRITE_MAX_RUNS_PER_ROLE = 6;
	private const SOURCE_REWRITE_MAX_SUBMISSION_GENERATIONS = 3;

	/** @var array<string,mixed> Exact request-local authority set only by source-rewrite-publish. */
	private static $source_rewrite_publish_authority = array();

	/** @return array<string,array<string,mixed>> */
	private static function source_rewrite_ability_catalogue(): array {
		$definitions = array(
			'source-rewrite-discover' => array( 'Discover Source Rewrite Job', 'Creates or returns the exact current Source Rewrite Job for one canonical page or post.', 'source_rewrite_discover_schema', 'source_rewrite_discover', true, true ),
			'source-rewrite-claim' => array( 'Claim Source Rewrite Job', 'Claims one bounded source-writer Run or the installation-wide single active Quality Run.', 'source_rewrite_claim_schema', 'source_rewrite_claim', false, false ),
			'source-rewrite-abandon' => array( 'Abandon Source Rewrite Run', 'Releases the caller-owned Run without fabricating an artifact or Quality Decision.', 'source_rewrite_abandon_schema', 'source_rewrite_abandon', false, false ),
			'source-rewrite-fetch-packet' => array( 'Fetch Source Rewrite Packet', 'Returns the complete source/artifact plus role-specific Ogilvy and literary-craft priming for the active Run.', 'source_rewrite_claim_access_schema', 'source_rewrite_fetch_packet', true, true ),
			'source-rewrite-submit-artifact' => array( 'Submit Source Rewrite Artifact', 'Stores one complete immutable source rewrite and its whole-page preservation brief.', 'source_rewrite_artifact_schema', 'source_rewrite_submit_artifact', false, false ),
			'source-rewrite-submit-quality-decision' => array( 'Submit Source Rewrite Quality Decision', 'Stores an independent semantic and literary Quality Decision against the exact staged source artifact.', 'source_rewrite_quality_schema', 'source_rewrite_submit_quality_decision', false, false ),
			'source-rewrite-reopen-quality' => array( 'Reopen Published Source Rewrite Quality', 'Reopens only the exact currently applied and live-verified artifact for one replacement independent Quality Decision.', 'source_rewrite_reopen_quality_schema', 'source_rewrite_reopen_quality', false, false ),
			'source-rewrite-publish' => array( 'Publish Approved Source Rewrite', 'Applies only the exact independently approved artifact and leaves live verification as a separate required step.', 'source_rewrite_publish_schema', 'source_rewrite_publish', false, false ),
			'source-rewrite-verify-live' => array( 'Verify Live Source Rewrite', 'Verifies exact reader-facing copy on origin and canonical cache surfaces, then activates hash-bound source approval.', 'source_rewrite_verify_live_schema', 'source_rewrite_verify_live', false, true ),
			'source-rewrite-status' => array( 'Inspect Source Rewrite Status', 'Returns the authoritative Job, Run, Artifact, Quality, publication, and live-verification state.', 'source_rewrite_status_schema', 'source_rewrite_status', true, true ),
		);
		$catalogue = array();
		foreach ( $definitions as $slug => $definition ) {
			$catalogue[ 'devenia-workflow/' . $slug ] = array(
				'label'            => $definition[0],
				'description'      => $definition[1],
				'input_schema'     => self::{$definition[2]}(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) use ( $definition ) { return self::run_ability_operation( $definition[3], $input ); },
				'meta'             => self::ability_meta( $definition[4], false, $definition[5] ),
			);
		}
		return $catalogue;
	}

	/** @return array<string,string> */
	private static function source_rewrite_dispatch_handlers(): array {
		return array(
			'source_rewrite_discover' => 'source_rewrite_discover',
			'source_rewrite_claim' => 'source_rewrite_claim',
			'source_rewrite_abandon' => 'source_rewrite_abandon',
			'source_rewrite_fetch_packet' => 'source_rewrite_fetch_packet',
			'source_rewrite_submit_artifact' => 'source_rewrite_submit_artifact',
			'source_rewrite_submit_quality_decision' => 'source_rewrite_submit_quality_decision',
			'source_rewrite_reopen_quality' => 'source_rewrite_reopen_quality',
			'source_rewrite_publish' => 'source_rewrite_publish',
			'source_rewrite_verify_live' => 'source_rewrite_verify_live',
			'source_rewrite_status' => 'source_rewrite_status',
		);
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_discover_schema(): array {
		return array( 'type' => 'object', 'required' => array( 'source_id' ), 'properties' => array( 'source_id' => array( 'type' => 'integer', 'minimum' => 1 ) ), 'additionalProperties' => false );
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_claim_schema(): array {
		return array(
			'type' => 'object',
			'required' => array( 'job_id', 'run_id', 'coordinator_id', 'role' ),
			'properties' => array(
				'job_id' => array( 'type' => 'string' ),
				'run_id' => array( 'type' => 'string' ),
				'coordinator_id' => array( 'type' => 'string' ),
				'role' => array( 'type' => 'string', 'enum' => array( 'source_writer', 'quality' ) ),
				'ttl_seconds' => array( 'type' => 'integer', 'minimum' => 60, 'maximum' => 7200, 'default' => 3600 ),
			),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_claim_access_schema(): array {
		return array( 'type' => 'object', 'required' => array( 'job_id', 'run_id', 'claim_token' ), 'properties' => array( 'job_id' => array( 'type' => 'string' ), 'run_id' => array( 'type' => 'string' ), 'claim_token' => array( 'type' => 'string' ) ), 'additionalProperties' => false );
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_abandon_schema(): array {
		$schema = self::source_rewrite_claim_access_schema();
		$schema['required'][] = 'reason';
		$schema['properties']['reason'] = array( 'type' => 'string', 'minLength' => 12, 'maxLength' => 500 );
		return $schema;
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_artifact_schema(): array {
		return array(
			'type' => 'object',
			'required' => array( 'job_id', 'run_id', 'claim_token', 'priming_revision', 'proposed_title', 'proposed_excerpt', 'proposed_content', 'preservation_brief' ),
			'properties' => array(
				'job_id' => array( 'type' => 'string' ), 'run_id' => array( 'type' => 'string' ), 'claim_token' => array( 'type' => 'string' ), 'priming_revision' => array( 'type' => 'string' ),
				'proposed_title' => array( 'type' => 'string' ), 'proposed_excerpt' => array( 'type' => 'string' ), 'proposed_content' => array( 'type' => 'string' ),
				'preservation_brief' => array(
					'type' => 'object',
					'required' => self::source_rewrite_preservation_brief_fields(),
					'properties' => array(
						'buyer' => array( 'type' => 'string' ), 'problem' => array( 'type' => 'string' ), 'desired_result' => array( 'type' => 'string' ), 'promise' => array( 'type' => 'string' ),
						'proof' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), 'offer' => array( 'type' => 'string' ), 'capabilities' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'boundaries' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), 'next_action' => array( 'type' => 'string' ), 'page_purpose' => array( 'type' => 'string' ),
						'emotional_intent' => array( 'type' => 'string' ), 'intentional_changes' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					),
					'additionalProperties' => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_quality_schema(): array {
		$kinds = self::source_rewrite_quality_evidence_fields();
		$properties = array(
			'job_id' => array( 'type' => 'string' ), 'run_id' => array( 'type' => 'string' ), 'claim_token' => array( 'type' => 'string' ), 'priming_revision' => array( 'type' => 'string' ),
			'artifact_revision' => array( 'type' => 'string' ), 'decision' => array( 'type' => 'string', 'enum' => array( 'pass', 'revise' ) ),
			'reviewed_sections' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), 'findings' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'reviewer_attestations' => array(
				'type' => 'array',
				'minItems' => count( $kinds ),
				'items' => array(
					'type' => 'object',
					'required' => array( 'kind', 'passed', 'observation' ),
					'properties' => array(
						'kind' => array( 'type' => 'string', 'enum' => $kinds ),
						'passed' => array( 'type' => 'boolean' ),
						'observation' => array( 'type' => 'string', 'minLength' => 120 ),
					),
					'additionalProperties' => false,
				),
			),
			'browser_receipts' => array(
				'type' => 'array',
				'minItems' => 4,
				'maxItems' => 4,
				'items' => array(
					'type' => 'object',
					'required' => array( 'artifact_revision', 'copy_revision', 'preview_url', 'viewport_scheme', 'viewport', 'color_scheme', 'response_digest', 'document_language', 'document_direction', 'layout_digest', 'screenshot_digest', 'checked_at' ),
					'properties' => array(
						'artifact_revision' => array( 'type' => 'string' ), 'copy_revision' => array( 'type' => 'string' ), 'preview_url' => array( 'type' => 'string', 'format' => 'uri' ),
						'viewport_scheme' => array( 'type' => 'string', 'enum' => array( 'desktop', 'mobile' ) ),
						'viewport' => array(
							'type' => 'object', 'required' => array( 'width', 'height', 'device_scale_factor' ),
							'properties' => array( 'width' => array( 'type' => 'integer' ), 'height' => array( 'type' => 'integer' ), 'device_scale_factor' => array( 'type' => 'integer' ) ),
							'additionalProperties' => false,
						),
						'color_scheme' => array( 'type' => 'string', 'enum' => array( 'light', 'dark' ) ), 'response_digest' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
						'document_language' => array( 'type' => 'string' ), 'document_direction' => array( 'type' => 'string', 'enum' => array( 'ltr', 'rtl' ) ), 'layout_digest' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
						'screenshot_digest' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ), 'checked_at' => array( 'type' => 'string', 'format' => 'date-time' ),
					),
					'additionalProperties' => false,
				),
			),
		);
		return array( 'type' => 'object', 'required' => array( 'job_id', 'run_id', 'claim_token', 'priming_revision', 'artifact_revision', 'decision', 'reviewer_attestations', 'reviewed_sections', 'findings', 'browser_receipts' ), 'properties' => $properties, 'additionalProperties' => false );
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_publish_schema(): array { return array( 'type' => 'object', 'required' => array( 'job_id' ), 'properties' => array( 'job_id' => array( 'type' => 'string' ) ), 'additionalProperties' => false ); }
	/** @return array<string,mixed> */
	private static function source_rewrite_reopen_quality_schema(): array {
		return array(
			'type' => 'object',
			'required' => array( 'job_id', 'artifact_revision', 'quality_revision', 'applied_source_hash', 'applied_publication_surface_revision', 'reason' ),
			'properties' => array(
				'job_id' => array( 'type' => 'string' ),
				'artifact_revision' => array( 'type' => 'string' ),
				'quality_revision' => array( 'type' => 'string' ),
				'applied_source_hash' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
				'applied_publication_surface_revision' => array( 'type' => 'string' ),
				'reason' => array( 'type' => 'string', 'minLength' => 24, 'maxLength' => 500 ),
			),
			'additionalProperties' => false,
		);
	}
	/** @return array<string,mixed> */
	private static function source_rewrite_verify_live_schema(): array { return array( 'type' => 'object', 'required' => array( 'job_id' ), 'properties' => array( 'job_id' => array( 'type' => 'string' ), 'timeout' => array( 'type' => 'integer', 'minimum' => 2, 'maximum' => 30, 'default' => 5 ) ), 'additionalProperties' => false ); }
	/** @return array<string,mixed> */
	private static function source_rewrite_status_schema(): array { return array( 'type' => 'object', 'properties' => array( 'job_id' => array( 'type' => 'string' ), 'source_id' => array( 'type' => 'integer', 'minimum' => 1 ) ), 'additionalProperties' => false ); }

	/** @return array<string,mixed> */
	private static function source_rewrite_discover( array $input ): array {
		$source_id = absint( $input['source_id'] ?? 0 );
		$source    = $source_id ? get_post( $source_id ) : null;
		if ( ! $source instanceof WP_Post || ! self::is_translatable_post_type( (string) $source->post_type ) || self::is_translation_post( $source_id ) ) {
			return self::source_rewrite_error( 'source_not_found', 'Canonical source content not found.' );
		}
		$lease = self::source_rewrite_acquire_source_transition_lease( $source_id, 'discover', '' );
		if ( empty( $lease['success'] ) ) {
			return $lease;
		}
		try {
			return self::source_rewrite_discover_locked( $source );
		} finally {
			self::source_rewrite_release_source_transition_lease( $lease );
		}
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_discover_locked( WP_Post $source ): array {
		$source_id = (int) $source->ID;
		$baseline_revision = self::source_publication_surface_revision( $source );
		$latest_job_id     = self::source_rewrite_clean_id( (string) get_option( self::source_rewrite_latest_key( $source_id ), '' ) );
		$latest            = '' !== $latest_job_id ? get_option( self::source_rewrite_job_key( $latest_job_id ) ) : null;
		if ( is_array( $latest ) && ! empty( $latest['quality_recheck'] ) && ! in_array( (string) ( $latest['status'] ?? '' ), array( 'published', 'cancelled', 'exhausted' ), true ) && ! empty( self::source_rewrite_current_baseline( $latest )['current'] ) ) {
			return array( 'success' => true, 'created' => false, 'job' => self::source_rewrite_public_job( $latest ) );
		}
		$existing          = is_array( $latest )
			&& $source_id === absint( $latest['source_id'] ?? 0 )
			&& $baseline_revision === (string) ( $latest['baseline_publication_surface_revision'] ?? '' )
			&& self::source_rewrite_policy_revision() === (string) ( $latest['policy_revision'] ?? '' )
			? $latest
			: null;
		if ( ! is_array( $existing ) ) {
			$base_job_id = self::source_rewrite_job_id( $source_id, $baseline_revision );
			$base_job = get_option( self::source_rewrite_job_key( $base_job_id ) );
			$existing = is_array( $base_job ) ? $base_job : null;
		}
		if ( is_array( $existing ) && 'exhausted' !== (string) ( $existing['status'] ?? '' ) ) {
			update_option( self::source_rewrite_latest_key( $source_id ), (string) $existing['job_id'], false );
			return array( 'success' => true, 'created' => false, 'job' => self::source_rewrite_public_job( $existing ) );
		}

		$retry_cycle       = is_array( $existing ) ? max( 1, absint( $existing['retry_cycle'] ?? 1 ) ) + 1 : 1;
		$supersedes_job_id = is_array( $existing ) ? (string) ( $existing['job_id'] ?? '' ) : '';
		$job_id            = self::source_rewrite_job_id( $source_id, $baseline_revision, $retry_cycle );
		$key               = self::source_rewrite_job_key( $job_id );
		$winner            = get_option( $key );
		if ( is_array( $winner ) ) {
			update_option( self::source_rewrite_latest_key( $source_id ), $job_id, false );
			return array( 'success' => true, 'created' => false, 'job' => self::source_rewrite_public_job( $winner ) );
		}

		$now = gmdate( 'c' );
		$job = array(
			'schema_version'                      => 1,
			'policy_revision'                     => self::source_rewrite_policy_revision(),
			'job_id'                              => $job_id,
			'source_id'                           => $source_id,
			'post_type'                           => (string) $source->post_type,
			'priming_context'                     => self::source_rewrite_priming_context( $source ),
			'baseline_source_hash'                => self::source_hash( $source ),
			'baseline_publication_surface_revision'=> $baseline_revision,
			'retry_cycle'                         => $retry_cycle,
			'supersedes_job_id'                    => $supersedes_job_id,
			'submission_generation'               => 1,
			'review_cycle'                         => 0,
			'status'                              => 'queued',
			'artifact_revision'                   => '',
			'quality_revision'                    => '',
			'active_run_id'                       => '',
			'run_ids'                             => array(),
			'created_at'                          => $now,
			'updated_at'                          => $now,
		);
		if ( ! self::atomic_create_option( $key, $job ) ) {
			$winner = get_option( $key );
			if ( ! is_array( $winner ) ) {
				return self::source_rewrite_error( 'job_create_failed', 'Source Rewrite Job creation lost authority without a readable winner.' );
			}
			update_option( self::source_rewrite_latest_key( $source_id ), $job_id, false );
			return array( 'success' => true, 'created' => false, 'job' => self::source_rewrite_public_job( $winner ) );
		}
		update_option( self::source_rewrite_latest_key( $source_id ), $job_id, false );

		return array( 'success' => true, 'created' => true, 'job' => self::source_rewrite_public_job( $job ) );
	}

	/**
	 * Invalidate only the active Quality authority for the exact artifact that is
	 * already applied and live-verified. Page bytes and immutable history remain.
	 *
	 * @return array<string,mixed>
	 */
	private static function source_rewrite_reopen_quality( array $input ): array {
		$job_id = self::source_rewrite_clean_id( (string) ( $input['job_id'] ?? '' ) );
		$artifact_revision = self::source_rewrite_clean_id( (string) ( $input['artifact_revision'] ?? '' ) );
		$quality_revision = self::source_rewrite_clean_id( (string) ( $input['quality_revision'] ?? '' ) );
		$applied_source_hash = strtolower( trim( (string) ( $input['applied_source_hash'] ?? '' ) ) );
		$applied_surface_revision = trim( (string) ( $input['applied_publication_surface_revision'] ?? '' ) );
		$reason = sanitize_textarea_field( (string) ( $input['reason'] ?? '' ) );
		if ( '' === $job_id || '' === $artifact_revision || '' === $quality_revision || 1 !== preg_match( '/^[a-f0-9]{64}$/', $applied_source_hash ) || '' === $applied_surface_revision || strlen( $reason ) < 24 || strlen( $reason ) > 500 ) {
			return self::source_rewrite_error( 'requality_input_invalid', 'Exact published Job, Artifact, Quality, applied hashes, and a substantive reason are required.' );
		}

		$job_key = self::source_rewrite_job_key( $job_id );
		$job = get_option( $job_key );
		if ( ! is_array( $job ) ) {
			return self::source_rewrite_error( 'job_not_found', 'Source Rewrite Job not found.' );
		}
		$lease = self::source_rewrite_acquire_source_transition_lease( absint( $job['source_id'] ?? 0 ), 'reopen_quality', $job_id );
		if ( empty( $lease['success'] ) ) {
			return $lease;
		}
		try {
			return self::source_rewrite_reopen_quality_locked( $job, $artifact_revision, $quality_revision, $applied_source_hash, $applied_surface_revision, $reason );
		} finally {
			self::source_rewrite_release_source_transition_lease( $lease );
		}
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_reopen_quality_locked( array $job, string $artifact_revision, string $quality_revision, string $applied_source_hash, string $applied_surface_revision, string $reason ): array {
		$job_id = (string) $job['job_id'];
		$job_key = self::source_rewrite_job_key( $job_id );
		$latest_key = self::source_rewrite_latest_key( absint( $job['source_id'] ?? 0 ) );
		if ( ! hash_equals( $job_id, self::source_rewrite_clean_id( (string) get_option( $latest_key, '' ) ) ) ) {
			return self::source_rewrite_error( 'requality_job_not_latest', 'Only the latest authoritative Source Rewrite Job may be reopened for Quality.' );
		}
		$marker = is_array( $job['quality_recheck'] ?? null ) ? $job['quality_recheck'] : array();
		$bindings_match = hash_equals( $artifact_revision, (string) ( $job['artifact_revision'] ?? '' ) )
			&& hash_equals( $applied_source_hash, (string) ( $job['applied_source_hash'] ?? '' ) )
			&& hash_equals( $applied_surface_revision, (string) ( $job['applied_publication_surface_revision'] ?? '' ) );
		$active_recheck_matches = $bindings_match && hash_equals( $quality_revision, (string) ( $marker['prior_quality_revision'] ?? '' ) );
		if ( $active_recheck_matches && ( '' !== (string) ( $job['active_run_id'] ?? '' ) || is_array( get_option( self::source_rewrite_claim_key( $job_id ) ) ) ) ) {
			return self::source_rewrite_error( 'requality_claim_active', 'The reopened artifact already has an active Quality claim.' );
		}
		if ( 'requality_reopening' === (string) ( $job['status'] ?? '' ) && $active_recheck_matches ) {
			$source = get_post( absint( $job['source_id'] ?? 0 ) );
			if ( ! $source instanceof WP_Post || ! self::source_rewrite_clear_source_approval( (int) $source->ID ) ) {
				return self::source_rewrite_error( 'requality_approval_clear_failed', 'The non-claimable reopen transition could not remove stale source approval metadata.', array( 'severity' => 'critical', 'job' => self::source_rewrite_public_job( $job ) ) );
			}
			$pending = $job;
			$pending['status'] = 'quality_pending';
			$pending['updated_at'] = gmdate( 'c' );
			if ( ! self::atomic_replace_option_value( $job_key, $job, $pending ) ) {
				return self::source_rewrite_error( 'requality_activation_conflict', 'The exact non-claimable reopen transition changed before Quality activation.', array( 'severity' => 'critical' ) );
			}
			return array( 'success' => true, 'reopened' => true, 'job' => self::source_rewrite_public_job( $pending ) );
		}
		if ( 'quality_pending' === (string) ( $job['status'] ?? '' ) && $active_recheck_matches ) {
			$source = get_post( absint( $job['source_id'] ?? 0 ) );
			if ( ! $source instanceof WP_Post || ! self::source_rewrite_clear_source_approval( (int) $source->ID ) ) {
				return self::source_rewrite_error( 'requality_approval_clear_failed', 'The Job is pending requality, but stale source approval metadata could not be removed.', array( 'severity' => 'critical', 'job' => self::source_rewrite_public_job( $job ) ) );
			}
			return array( 'success' => true, 'reopened' => false, 'job' => self::source_rewrite_public_job( $job ) );
		}
		if ( 'published' !== (string) ( $job['status'] ?? '' ) || true !== ( $job['live_verification_passed'] ?? null ) ) {
			return self::source_rewrite_error( 'job_not_requality_ready', 'Only a live-verified published Source Rewrite Job can be reopened for Quality.', array( 'job' => self::source_rewrite_public_job( $job ) ) );
		}
		if ( '' !== (string) ( $job['active_run_id'] ?? '' ) || is_array( get_option( self::source_rewrite_claim_key( $job_id ) ) ) ) {
			return self::source_rewrite_error( 'requality_claim_active', 'The published Source Rewrite Job has an active claim.' );
		}
		if ( ! $bindings_match || ! hash_equals( $quality_revision, (string) ( $job['quality_revision'] ?? '' ) ) ) {
			return self::source_rewrite_error( 'requality_binding_mismatch', 'The requested revisions do not equal the exact current published authority.' );
		}
		$authority = self::source_rewrite_authority_chain( $job, true );
		if ( empty( $authority['success'] ) ) {
			return $authority;
		}
		$source = get_post( absint( $job['source_id'] ?? 0 ) );
		$artifact = $authority['artifact'];
		$applied = self::source_rewrite_applied_artifact_matches( $job, $artifact, $source );
		if ( empty( $applied['success'] ) ) {
			return self::source_rewrite_error( 'published_artifact_drifted', 'The live source no longer equals the exact published artifact; Quality cannot be reopened.', array( 'applied' => $applied ) );
		}

		$reopened_at = gmdate( 'c' );
		$history = is_array( $job['quality_recheck_history'] ?? null ) ? $job['quality_recheck_history'] : array();
		$history[] = array(
			'artifact_revision' => $artifact_revision,
			'prior_quality_revision' => $quality_revision,
			'applied_source_hash' => $applied_source_hash,
			'applied_publication_surface_revision' => $applied_surface_revision,
			'reason' => $reason,
			'prior_submission_generation' => (int) ( $job['submission_generation'] ?? 1 ),
			'reopened_at' => $reopened_at,
		);
		$next = $job;
		$next['status'] = 'requality_reopening';
		$next['quality_revision'] = '';
		$next['active_run_id'] = '';
		$next['live_verification_passed'] = null;
		$next['quality_recheck_history'] = $history;
		$next['review_cycle'] = (int) ( $job['review_cycle'] ?? 0 ) + 1;
		$next['quality_recheck'] = array(
			'artifact_revision' => $artifact_revision,
			'prior_quality_revision' => $quality_revision,
			'anchor_source_hash' => $applied_source_hash,
			'anchor_publication_surface_revision' => $applied_surface_revision,
			'anchor_copy_revision' => (string) ( $job['applied_copy_revision'] ?? '' ),
			'correction_generation' => 0,
			'reason' => $reason,
			'reopened_at' => $reopened_at,
		);
		$next['updated_at'] = $reopened_at;
		if ( ! self::atomic_replace_option_value( $job_key, $job, $next ) ) {
			return self::source_rewrite_error( 'requality_transition_conflict', 'The published Job changed before Quality could be reopened.' );
		}
		if ( ! hash_equals( $job_id, self::source_rewrite_clean_id( (string) get_option( $latest_key, '' ) ) ) ) {
			if ( ! self::atomic_replace_option_value( $job_key, $next, $job ) ) {
				return self::source_rewrite_error( 'requality_latest_race_rollback_failed', 'The latest Job changed during reopen and the pending transition could not be rolled back.', array( 'severity' => 'critical' ) );
			}
			return self::source_rewrite_error( 'requality_job_not_latest', 'The latest authoritative Job changed during reopen; no approval was invalidated.' );
		}
		if ( ! self::source_rewrite_clear_source_approval( (int) $source->ID ) ) {
			return self::source_rewrite_error( 'requality_approval_clear_failed', 'Quality was invalidated, but stale source approval metadata could not be removed.', array( 'severity' => 'critical', 'job' => self::source_rewrite_public_job( $next ) ) );
		}
		$pending = $next;
		$pending['status'] = 'quality_pending';
		$pending['updated_at'] = gmdate( 'c' );
		if ( ! self::atomic_replace_option_value( $job_key, $next, $pending ) ) {
			$current = get_option( $job_key );
			if ( ! is_array( $current ) || 'quality_pending' !== (string) ( $current['status'] ?? '' ) || ! hash_equals( $quality_revision, (string) ( $current['quality_recheck']['prior_quality_revision'] ?? '' ) ) ) {
				return self::source_rewrite_error( 'requality_activation_conflict', 'Source approval was cleared, but the non-claimable reopen transition could not activate Quality.', array( 'severity' => 'critical' ) );
			}
			$pending = $current;
		}
		return array( 'success' => true, 'reopened' => true, 'job' => self::source_rewrite_public_job( $pending ) );
	}

	/** Remove and verify every derived source-approval field. */
	private static function source_rewrite_clear_source_approval( int $source_id ): bool {
		$cleared = true;
		foreach ( array( self::META_SOURCE_CONTENT_INTEGRITY_REVIEW_HASH, self::META_SOURCE_CONTENT_INTEGRITY_REVIEWED_AT, self::META_SOURCE_CONTENT_INTEGRITY_REVIEWER, self::META_SOURCE_CONTENT_INTEGRITY_REVIEW_NOTE, self::META_SOURCE_CONTENT_INTEGRITY_REVIEW_EVIDENCE ) as $meta_key ) {
			delete_post_meta( $source_id, $meta_key );
			$cleared = $cleared && '' === get_post_meta( $source_id, $meta_key, true );
		}
		return $cleared;
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_claim( array $input ): array {
		$job_id         = self::source_rewrite_clean_id( (string) ( $input['job_id'] ?? '' ) );
		$run_id         = self::source_rewrite_clean_id( (string) ( $input['run_id'] ?? '' ) );
		$coordinator_id = self::source_rewrite_clean_id( (string) ( $input['coordinator_id'] ?? '' ) );
		$role           = sanitize_key( (string) ( $input['role'] ?? '' ) );
		if ( '' === $job_id || '' === $run_id || '' === $coordinator_id || ! in_array( $role, array( 'source_writer', 'quality' ), true ) ) {
			return self::source_rewrite_error( 'claim_input_invalid', 'job_id, run_id, coordinator_id, and role source_writer|quality are required.' );
		}

		$key = self::source_rewrite_job_key( $job_id );
		$job = get_option( $key );
		if ( ! is_array( $job ) ) {
			return self::source_rewrite_error( 'job_not_found', 'Source Rewrite Job not found.' );
		}
		$baseline = self::source_rewrite_current_baseline( $job );
		if ( empty( $baseline['current'] ) ) {
			return self::source_rewrite_error( 'job_superseded', 'The canonical source changed; discover a Source Rewrite Job for the current surface.', array( 'baseline' => $baseline ) );
		}
		$recovered = self::source_rewrite_recover_expired_claim( $job );
		if ( empty( $recovered['success'] ) ) {
			return $recovered;
		}
		$job = $recovered['job'];
		if ( (int) ( $job['submission_generation'] ?? 0 ) > self::SOURCE_REWRITE_MAX_SUBMISSION_GENERATIONS ) {
			return self::source_rewrite_error( 'submission_generation_exhausted', 'The finite Source Rewrite submission-generation budget is exhausted.', array( 'job' => self::source_rewrite_public_job( $job ) ) );
		}
		$review_cycle = (int) ( $job['review_cycle'] ?? 0 );
		$role_runs = array_filter(
			(array) ( $job['run_ids'] ?? array() ),
			static function ( $row ) use ( $role, $review_cycle ): bool {
				return is_array( $row ) && $role === (string) ( $row['role'] ?? '' ) && $review_cycle === (int) ( $row['review_cycle'] ?? 0 );
			}
		);
		if ( count( $role_runs ) >= self::SOURCE_REWRITE_MAX_RUNS_PER_ROLE ) {
			return self::source_rewrite_error( 'run_limit_exhausted', 'The finite Source Rewrite Run budget for this role is exhausted.', array( 'role' => $role, 'job' => self::source_rewrite_public_job( $job ) ) );
		}
		$allowed_statuses = 'source_writer' === $role ? array( 'queued', 'changes_requested' ) : array( 'quality_pending' );
		if ( ! in_array( (string) ( $job['status'] ?? '' ), $allowed_statuses, true ) ) {
			return self::source_rewrite_error( 'job_not_claimable', 'The Source Rewrite Job is not claimable for this role.', array( 'job' => self::source_rewrite_public_job( $job ) ) );
		}
		if ( false !== get_option( self::source_rewrite_run_key( $run_id ) ) ) {
			return self::source_rewrite_error( 'run_id_conflict', 'run_id already exists.' );
		}
		$claim_key = self::source_rewrite_claim_key( $job_id );
		if ( is_array( get_option( $claim_key ) ) ) {
			return self::source_rewrite_error( 'job_claim_conflict', 'The Source Rewrite Job is already claimed.' );
		}

		$token = wp_generate_password( 48, false, false );
		$now   = time();
		$ttl   = max( 60, min( 7200, absint( $input['ttl_seconds'] ?? 3600 ) ) );
		$claim = array(
			'job_id'                => $job_id,
			'run_id'                => $run_id,
			'coordinator_id'        => $coordinator_id,
			'role'                  => $role,
			'artifact_revision'     => 'quality' === $role ? (string) ( $job['artifact_revision'] ?? '' ) : '',
			'previous_status'       => (string) $job['status'],
			'submission_generation' => (int) $job['submission_generation'],
			'review_cycle'          => $review_cycle,
			'token_hash'            => hash( 'sha256', $token ),
			'claimed_at'            => gmdate( 'c', $now ),
			'expires_at'            => gmdate( 'c', $now + $ttl ),
		);
		$principal = self::source_rewrite_run_principal( $job, $claim );
		if ( 'quality' === $role ) {
			$artifact = get_option( self::source_rewrite_artifact_key( (string) ( $job['artifact_revision'] ?? '' ) ) );
			$writer   = is_array( $artifact ) && is_array( $artifact['writer_principal'] ?? null ) ? $artifact['writer_principal'] : array();
			if ( empty( $writer['principal_id'] ) ) {
				return self::source_rewrite_error( 'artifact_writer_authority_missing', 'The staged source artifact has no authenticated writer principal.' );
			}
			if ( $run_id === (string) ( $writer['run_id'] ?? '' ) || hash_equals( (string) $writer['principal_id'], (string) $principal['principal_id'] ) ) {
				return self::source_rewrite_error( 'writer_reviewer_principal_conflict', 'Quality must use a fresh bounded Run Principal distinct from the source writer.' );
			}
		}

		if ( ! self::atomic_create_option( $claim_key, $claim ) ) {
			return self::source_rewrite_error( 'job_claim_race_lost', 'Another Run claimed the Source Rewrite Job.' );
		}
		if ( 'quality' === $role ) {
			$single_flight = self::quality_single_flight_acquire( 'source_rewrite', $job, $claim );
			if ( empty( $single_flight['success'] ) ) {
				self::atomic_delete_option_value( $claim_key, $claim );
				return $single_flight;
			}
		}
		$run = array(
			'run_id'                => $run_id,
			'job_id'                => $job_id,
			'role'                  => $role,
			'coordinator_id'        => $coordinator_id,
			'principal'             => $principal,
			'status'                => 'running',
			'submission_generation' => (int) $job['submission_generation'],
			'review_cycle'          => $review_cycle,
			'started_at'             => gmdate( 'c', $now ),
		);
		if ( ! self::atomic_create_option( self::source_rewrite_run_key( $run_id ), $run ) ) {
			self::source_rewrite_release_claim( $claim );
			return self::source_rewrite_error( 'run_create_failed', 'The bounded Source Rewrite Run could not be created.' );
		}

		$next          = $job;
		$next['status'] = 'source_writer' === $role ? 'writer_claimed' : 'quality_claimed';
		$next['active_run_id'] = $run_id;
		$next['run_ids'][] = array( 'run_id' => $run_id, 'role' => $role, 'submission_generation' => (int) $job['submission_generation'], 'review_cycle' => $review_cycle );
		$next['updated_at'] = gmdate( 'c' );
		if ( ! self::atomic_replace_option_value( $key, $job, $next ) ) {
			self::atomic_delete_option_value( self::source_rewrite_run_key( $run_id ), $run );
			self::source_rewrite_release_claim( $claim );
			return self::source_rewrite_error( 'job_claim_transition_conflict', 'The Source Rewrite Job changed before claim activation.' );
		}

		return array(
			'success'     => true,
			'claim_token' => $token,
			'claim'       => self::source_rewrite_public_claim( $claim ),
			'run'         => $run,
			'job'         => self::source_rewrite_public_job( $next ),
		);
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_abandon( array $input ): array {
		$access = self::source_rewrite_claim_access( $input );
		if ( empty( $access['success'] ) ) {
			return $access;
		}
		$reason = sanitize_textarea_field( (string) ( $input['reason'] ?? '' ) );
		if ( strlen( trim( $reason ) ) < 12 ) {
			return self::source_rewrite_error( 'job_abandon_reason_required', 'A concrete reason is required to abandon a Source Rewrite Run.' );
		}
		$job = $access['job'];
		$run = $access['run'];
		$claim = $access['claim'];
		$completed = array_merge( $run, array( 'status' => 'completed', 'outcome' => 'abandoned', 'abandon_reason' => $reason, 'completed_at' => gmdate( 'c' ) ) );
		if ( ! self::atomic_replace_option_value( self::source_rewrite_run_key( (string) $run['run_id'] ), $run, $completed ) ) {
			return self::source_rewrite_error( 'run_abandon_conflict', 'The Run changed before abandonment could become terminal.', array( 'retryable' => true ) );
		}
		$next = $job;
		$next['status'] = (string) ( $claim['previous_status'] ?? ( 'quality' === (string) $run['role'] ? 'quality_pending' : 'queued' ) );
		$next['active_run_id'] = '';
		$next['updated_at'] = gmdate( 'c' );
		if ( ! self::atomic_replace_option_value( self::source_rewrite_job_key( (string) $job['job_id'] ), $job, $next ) ) {
			return self::source_rewrite_error( 'job_abandon_transition_conflict', 'The Run is terminal, but the Job changed before abandonment could release it.', array( 'retryable' => true ) );
		}
		if ( ! self::source_rewrite_release_claim( $claim ) ) {
			return self::source_rewrite_error( 'claim_abandon_release_conflict', 'The abandoned claim could not be released exactly.', array( 'retryable' => true ) );
		}
		return array( 'success' => true, 'message' => 'Source Rewrite Run abandoned and claim released.', 'run' => $completed, 'job' => self::source_rewrite_public_job( $next ) );
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_fetch_packet( array $input ): array {
		$access = self::source_rewrite_claim_access( $input );
		if ( empty( $access['success'] ) ) {
			return $access;
		}
		$job    = $access['job'];
		$run    = $access['run'];
		$source = get_post( (int) $job['source_id'] );
		if ( ! $source instanceof WP_Post ) {
			return self::source_rewrite_error( 'source_not_found', 'Canonical source content not found while building the Run packet.' );
		}
		$current = self::source_rewrite_source_values( $source );
		$packet  = array(
			'job'                  => self::source_rewrite_public_job( $job ),
			'run'                  => $run,
			'baseline_source_hash' => (string) $job['baseline_source_hash'],
			'baseline_publication_surface_revision' => (string) $job['baseline_publication_surface_revision'],
			'quality_standard'     => self::source_rewrite_quality_standard(),
			'role_priming'         => self::source_rewrite_role_priming( (string) $run['role'], $job, $source ),
			'correction_context'   => is_array( $job['last_publish_failure'] ?? null ) ? $job['last_publish_failure'] : null,
		);
		if ( 'source_writer' === (string) $run['role'] ) {
			$packet['source'] = $current;
			$packet['current_copy_surface'] = self::source_rewrite_copy_surface( $current['title'], $current['excerpt'], $current['content'] );
			$packet['required_preservation_brief'] = self::source_rewrite_preservation_brief_fields();
		} else {
			$artifact = get_option( self::source_rewrite_artifact_key( (string) $job['artifact_revision'] ) );
			if ( ! is_array( $artifact ) ) {
				return self::source_rewrite_error( 'artifact_not_found', 'The exact staged Source Rewrite Artifact is unavailable.' );
			}
			$packet['artifact_revision'] = (string) $artifact['artifact_revision'];
			$packet['current']            = $current;
			$packet['proposed']           = $artifact['proposed'];
			$packet['current_copy_surface']  = $artifact['current_copy_surface'];
			$packet['proposed_copy_surface'] = $artifact['proposed_copy_surface'];
			$packet['preservation_brief']    = $artifact['preservation_brief'];
			$packet['writer_principal']      = $artifact['writer_principal'];
			$packet['required_quality_evidence'] = self::source_rewrite_quality_evidence_fields();
			$packet['proposed_design_validation'] = self::source_editorial_design_validation(
				$source,
				(string) ( $artifact['proposed']['content'] ?? '' )
			);
			$packet['rendered_preview'] = self::source_rewrite_preview_descriptor( $job, $run, $access['claim'], $artifact, $source );
		}

		return array( 'success' => true, 'packet' => $packet );
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_submit_artifact( array $input ): array {
		$access = self::source_rewrite_claim_access( $input, 'source_writer' );
		if ( empty( $access['success'] ) ) {
			return $access;
		}
		$job      = $access['job'];
		$run      = $access['run'];
		$claim    = $access['claim'];
		$source   = get_post( (int) $job['source_id'] );
		$priming  = self::source_rewrite_validate_priming_acknowledgement( $input, 'source_writer', $job, $source );
		if ( empty( $priming['success'] ) ) {
			return $priming;
		}
		$baseline = self::source_rewrite_current_baseline( $job );
		if ( ! $source instanceof WP_Post || empty( $baseline['current'] ) ) {
			return self::source_rewrite_error( 'artifact_baseline_stale', 'The canonical source changed before artifact submission.', array( 'baseline' => $baseline ) );
		}

		$proposed = array(
			'title'   => sanitize_text_field( (string) ( $input['proposed_title'] ?? '' ) ),
			'excerpt' => sanitize_textarea_field( (string) ( $input['proposed_excerpt'] ?? '' ) ),
			'content' => self::normalize_gutenberg_content_for_storage( (string) ( $input['proposed_content'] ?? '' ) ),
		);
		if ( '' === $proposed['title'] || '' === trim( $proposed['content'] ) ) {
			return self::source_rewrite_error( 'artifact_content_incomplete', 'A complete proposed title and Gutenberg content are required.' );
		}
		$brief = self::source_rewrite_validate_preservation_brief( $input['preservation_brief'] ?? null );
		if ( empty( $brief['success'] ) ) {
			return $brief;
		}

		$current_values   = self::source_rewrite_source_values( $source );
		$current_surface  = self::source_rewrite_copy_surface( $current_values['title'], $current_values['excerpt'], $current_values['content'] );
		$proposed_surface = self::source_rewrite_copy_surface( $proposed['title'], $proposed['excerpt'], $proposed['content'] );
		$current_artifact_values = array(
			'title'   => sanitize_text_field( $current_values['title'] ),
			'excerpt' => sanitize_textarea_field( $current_values['excerpt'] ),
			'content' => $current_values['content'],
		);
		if ( $current_artifact_values === $proposed ) {
			return self::source_rewrite_error( 'artifact_unchanged', 'A Source Rewrite Artifact requires a changed reader-facing source surface.' );
		}

		$record = array(
			'schema_version'       => 1,
			'policy_revision'      => self::source_rewrite_policy_revision(),
			'job_id'               => (string) $job['job_id'],
			'source_id'            => (int) $job['source_id'],
			'submission_generation'=> (int) $job['submission_generation'],
			'review_cycle'         => (int) ( $job['review_cycle'] ?? 0 ),
			'baseline_source_hash' => (string) $job['baseline_source_hash'],
			'baseline_publication_surface_revision' => (string) $job['baseline_publication_surface_revision'],
			'current_copy_surface' => $current_surface,
			'proposed_copy_surface'=> $proposed_surface,
			'proposed'             => $proposed,
			'proposed_content_hash'=> hash( 'sha256', $proposed['content'] ),
			'preservation_brief'   => $brief['brief'],
			'priming_revision'     => (string) $priming['priming_revision'],
			'writer_principal'     => $run['principal'],
			'submitted_at'         => gmdate( 'c' ),
		);
		$artifact_revision = 'sra_' . substr( hash( 'sha256', wp_json_encode( $record ) ?: '' ), 0, 48 );
		$record['artifact_revision'] = $artifact_revision;
		$artifact_key = self::source_rewrite_artifact_key( $artifact_revision );
		if ( ! self::atomic_create_option( $artifact_key, $record ) ) {
			$stored = get_option( $artifact_key );
			if ( ! is_array( $stored ) || $stored !== $record ) {
				return self::source_rewrite_error( 'artifact_revision_conflict', 'The immutable Source Rewrite Artifact revision already belongs to different bytes.' );
			}
		}

		$next = $job;
		$next['status']            = 'quality_pending';
		$next['artifact_revision'] = $artifact_revision;
		$next['quality_revision']  = '';
		$next['active_run_id']     = '';
		$next['updated_at']        = gmdate( 'c' );
		if ( ! self::atomic_replace_option_value( self::source_rewrite_job_key( (string) $job['job_id'] ), $job, $next ) ) {
			return self::source_rewrite_error( 'artifact_job_transition_conflict', 'The Source Rewrite Job changed before artifact activation.' );
		}
		$completion = self::source_rewrite_complete_run_and_release( $run, $claim, 'artifact_submitted', array( 'artifact_revision' => $artifact_revision ) );
		if ( empty( $completion['success'] ) ) {
			return array_merge( $completion, array( 'job' => self::source_rewrite_public_job( $next ), 'artifact_revision' => $artifact_revision ) );
		}

		return array(
			'success'           => true,
			'artifact_revision' => $artifact_revision,
			'copy_revision'     => (string) $proposed_surface['revision'],
			'job'               => self::source_rewrite_public_job( $next ),
		);
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_submit_quality_decision( array $input ): array {
		$access = self::source_rewrite_claim_access( $input, 'quality' );
		if ( empty( $access['success'] ) ) {
			return $access;
		}
		$job      = $access['job'];
		$run      = $access['run'];
		$claim    = $access['claim'];
		$source   = get_post( (int) $job['source_id'] );
		$priming  = self::source_rewrite_validate_priming_acknowledgement( $input, 'quality', $job, $source );
		if ( empty( $priming['success'] ) ) {
			return $priming;
		}
		$artifact_revision = self::source_rewrite_clean_id( (string) ( $input['artifact_revision'] ?? '' ) );
		if ( $artifact_revision !== (string) ( $job['artifact_revision'] ?? '' ) ) {
			return self::source_rewrite_error( 'quality_artifact_binding_mismatch', 'Quality must decide the exact active Source Rewrite Artifact revision.' );
		}
		$artifact = get_option( self::source_rewrite_artifact_key( $artifact_revision ) );
		if ( ! is_array( $artifact ) ) {
			return self::source_rewrite_error( 'artifact_not_found', 'The exact Source Rewrite Artifact is unavailable.' );
		}
		$writer = is_array( $artifact['writer_principal'] ?? null ) ? $artifact['writer_principal'] : array();
		$reviewer = is_array( $run['principal'] ?? null ) ? $run['principal'] : array();
		if ( empty( $writer['principal_id'] ) || empty( $reviewer['principal_id'] ) || hash_equals( (string) $writer['principal_id'], (string) $reviewer['principal_id'] ) || (string) $writer['run_id'] === (string) $reviewer['run_id'] ) {
			return self::source_rewrite_error( 'writer_reviewer_principal_conflict', 'Quality authority requires a fresh reviewer Run Principal distinct from the source writer.' );
		}

		$decision = sanitize_key( (string) ( $input['decision'] ?? '' ) );
		if ( ! in_array( $decision, array( 'pass', 'revise' ), true ) ) {
			return self::source_rewrite_error( 'quality_decision_invalid', 'Quality decision must be pass or revise.' );
		}
		$evidence = self::source_rewrite_validate_quality_evidence( $input, $decision );
		if ( empty( $evidence['success'] ) ) {
			return $evidence;
		}
		$browser_evidence = self::source_rewrite_validate_browser_receipts( $input['browser_receipts'] ?? array(), $job, $run, $claim, $artifact, $source );
		if ( empty( $browser_evidence['success'] ) ) {
			return $browser_evidence;
		}
		$baseline = self::source_rewrite_current_baseline( $job );
		if ( empty( $baseline['current'] ) ) {
			return self::source_rewrite_error( 'quality_baseline_stale', 'The canonical source changed before the Quality Decision.', array( 'baseline' => $baseline ) );
		}
		$proposed_design_validation = self::source_editorial_design_validation(
			$source,
			(string) ( $artifact['proposed']['content'] ?? '' )
		);
		if (
			'pass' === $decision
			&& ( empty( $proposed_design_validation['available'] ) || empty( $proposed_design_validation['passed'] ) )
		) {
			return self::source_rewrite_error(
				'quality_proposed_design_gate_failed',
				'A passing Source Rewrite Quality Decision requires the exact proposed page to pass the owning Source Content Design Gate.',
				array( 'proposed_design_validation' => $proposed_design_validation )
			);
		}

		$quality = array(
			'schema_version'       => 1,
			'policy_revision'      => self::source_rewrite_policy_revision(),
			'job_id'               => (string) $job['job_id'],
			'source_id'            => (int) $job['source_id'],
			'artifact_revision'    => $artifact_revision,
			'submission_generation'=> (int) $job['submission_generation'],
			'review_cycle'         => (int) ( $job['review_cycle'] ?? 0 ),
			'baseline_source_hash' => (string) $job['baseline_source_hash'],
			'baseline_publication_surface_revision' => (string) $job['baseline_publication_surface_revision'],
			'decision'             => $decision,
			'reviewer_principal'   => $reviewer,
			'writer_principal_id'  => (string) $writer['principal_id'],
			'priming_revision'     => (string) $priming['priming_revision'],
			'evidence'             => $evidence['evidence'],
			'browser_receipts'     => $browser_evidence['receipts'],
			'preview_identity'     => (string) $browser_evidence['preview_identity'],
			'preview_url'          => (string) $browser_evidence['preview_url'],
			'preview_host_id'      => (int) $browser_evidence['preview_host_id'],
			'preview_host_scope'   => (string) $browser_evidence['preview_host_scope'],
			'proposed_design_validation' => $proposed_design_validation,
			'decided_at'           => gmdate( 'c' ),
		);
		$quality_revision = 'srq_' . substr( hash( 'sha256', wp_json_encode( $quality ) ?: '' ), 0, 48 );
		$quality['quality_revision'] = $quality_revision;
		if ( ! self::atomic_create_option( self::source_rewrite_quality_key( $quality_revision ), $quality ) ) {
			$stored = get_option( self::source_rewrite_quality_key( $quality_revision ) );
			if ( ! is_array( $stored ) || $stored !== $quality ) {
				return self::source_rewrite_error( 'quality_revision_conflict', 'The immutable Source Rewrite Quality revision already belongs to different bytes.' );
			}
		}

		$next = $job;
		$recheck = is_array( $job['quality_recheck'] ?? null ) ? $job['quality_recheck'] : array();
		$can_revise = ! empty( $recheck )
			? (int) ( $recheck['correction_generation'] ?? 0 ) < self::SOURCE_REWRITE_MAX_SUBMISSION_GENERATIONS
			: (int) $job['submission_generation'] < self::SOURCE_REWRITE_MAX_SUBMISSION_GENERATIONS;
		$next['status']           = 'pass' === $decision ? 'ready_to_publish' : ( $can_revise ? 'changes_requested' : 'exhausted' );
		$next['quality_revision'] = $quality_revision;
		$next['active_run_id']    = '';
		if ( 'revise' === $decision && $can_revise ) {
			if ( ! empty( $recheck ) ) {
				$next['quality_recheck']['correction_generation'] = (int) ( $recheck['correction_generation'] ?? 0 ) + 1;
				$next['submission_generation'] = (int) $next['quality_recheck']['correction_generation'];
			} else {
				$next['submission_generation'] = (int) $job['submission_generation'] + 1;
			}
		}
		$next['updated_at'] = gmdate( 'c' );
		if ( ! self::atomic_replace_option_value( self::source_rewrite_job_key( (string) $job['job_id'] ), $job, $next ) ) {
			return self::source_rewrite_error( 'quality_job_transition_conflict', 'The Source Rewrite Job changed before Quality activation.' );
		}
		$completion = self::source_rewrite_complete_run_and_release( $run, $claim, 'quality_decided', array( 'quality_revision' => $quality_revision, 'decision' => $decision ) );
		if ( empty( $completion['success'] ) ) {
			return array_merge( $completion, array( 'job' => self::source_rewrite_public_job( $next ), 'quality_revision' => $quality_revision, 'decision' => $decision ) );
		}

		return array(
			'success'          => true,
			'decision'         => $decision,
			'quality_revision' => $quality_revision,
			'job'              => self::source_rewrite_public_job( $next ),
		);
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_publish( array $input ): array {
		$job_id = self::source_rewrite_clean_id( (string) ( $input['job_id'] ?? '' ) );
		$job_key = self::source_rewrite_job_key( $job_id );
		$job = get_option( $job_key );
		if ( ! is_array( $job ) ) {
			return self::source_rewrite_error( 'job_not_found', 'Source Rewrite Job not found.' );
		}
		if ( ! in_array( (string) ( $job['status'] ?? '' ), array( 'ready_to_publish', 'published' ), true ) ) {
			return self::source_rewrite_error( 'job_not_ready_to_publish', 'Source Rewrite publication requires the exact passing Quality Decision.', array( 'job' => self::source_rewrite_public_job( $job ) ) );
		}

		$lease = self::source_rewrite_acquire_publish_lease( $job );
		if ( empty( $lease['success'] ) ) {
			return $lease;
		}
		try {
			$job = get_option( $job_key );
			if ( ! is_array( $job ) ) {
				return self::source_rewrite_error( 'job_not_found_after_lease', 'Source Rewrite Job disappeared after publication lease acquisition.' );
			}
			$source = get_post( absint( $job['source_id'] ?? 0 ) );
			if ( ! $source instanceof WP_Post ) {
				return self::source_rewrite_error( 'source_not_found', 'Canonical source content is unavailable for publication.' );
			}
			$authority = self::source_rewrite_authority_chain( $job );
			if ( empty( $authority['success'] ) ) {
				if ( in_array( (string) ( $authority['code'] ?? '' ), array( 'source_rewrite_priming_stale', 'source_rewrite_policy_stale' ), true ) ) {
					return self::source_rewrite_transition_after_stale_authority( $job, $authority );
				}
				return $authority;
			}
			$artifact = $authority['artifact'];
			$proposed = $artifact['proposed'];
			$proposed_surface = $artifact['proposed_copy_surface'];
			$current_values = self::source_rewrite_source_values( $source );
			$current_surface = self::source_rewrite_copy_surface( $current_values['title'], $current_values['excerpt'], $current_values['content'] );
			$artifact_bytes_applied = hash_equals( (string) $proposed_surface['revision'], (string) $current_surface['revision'] )
				&& hash_equals( (string) $artifact['proposed_content_hash'], hash( 'sha256', (string) $current_values['content'] ) );
			$already_applied = $artifact_bytes_applied && 'publish' === (string) $source->post_status;
			if ( ! $artifact_bytes_applied && empty( self::source_rewrite_current_baseline( $job )['current'] ) ) {
				return self::source_rewrite_error( 'publication_baseline_stale', 'The canonical source changed after Quality approval and does not equal the approved artifact.' );
			}

			if ( ! $already_applied ) {
				self::$source_rewrite_publish_authority = array(
					'job_id'               => (string) $job['job_id'],
					'source_id'            => (int) $job['source_id'],
					'artifact_revision'    => (string) $artifact['artifact_revision'],
					'quality_revision'     => (string) $job['quality_revision'],
					'proposed_copy_revision'=> (string) $proposed_surface['revision'],
					'proposed_content_hash'=> (string) $artifact['proposed_content_hash'],
				);
				try {
					if ( ! function_exists( 'mcp_expose_validate_content_write_policy' ) ) {
						return self::source_rewrite_error( 'content_write_policy_interface_unavailable', 'The owning MCP content-write policy Interface is unavailable; Source Rewrite publication fails closed.' );
					}
					$preflight_input = array(
						'title'                  => (string) $proposed['title'],
						'excerpt'                => (string) $proposed['excerpt'],
						'content_write_mode'     => 'full_rebuild',
						'content_write_operation' => 'update',
					);
					$preflight = mcp_expose_validate_content_write_policy(
						$source,
						(string) $source->post_type,
						'publish',
						(string) $proposed['content'],
						$preflight_input,
						'devenia-workflow/source-rewrite-publish'
					);
					if ( is_wp_error( $preflight ) || true !== $preflight ) {
						return self::source_rewrite_transition_after_publish_preflight_failure( $job, $preflight );
					}
					$updated = wp_update_post(
						array(
							'ID'           => (int) $source->ID,
							'post_title'   => (string) $proposed['title'],
							'post_excerpt' => (string) $proposed['excerpt'],
							'post_content' => (string) $proposed['content'],
							'post_status'  => 'publish',
						),
						true
					);
					if ( is_wp_error( $updated ) || (int) $updated !== (int) $source->ID ) {
						return self::source_rewrite_error( 'publication_write_failed', 'WordPress did not commit the exact approved Source Rewrite Artifact.', array( 'write_result' => $updated ) );
					}
				} finally {
					self::$source_rewrite_publish_authority = array();
				}
				$source = get_post( (int) $source->ID );
			}

			$applied_values = $source instanceof WP_Post ? self::source_rewrite_source_values( $source ) : array();
			$applied_surface = $source instanceof WP_Post
				? self::source_rewrite_copy_surface( $applied_values['title'], $applied_values['excerpt'], $applied_values['content'] )
				: array( 'revision' => '' );
			if (
				! $source instanceof WP_Post
				|| 'publish' !== (string) $source->post_status
				|| ! hash_equals( self::source_rewrite_proposed_source_hash( $proposed ), self::source_hash( $source ) )
				|| ! hash_equals( (string) $proposed_surface['revision'], (string) ( $applied_surface['revision'] ?? '' ) )
				|| ! hash_equals( (string) $artifact['proposed_content_hash'], hash( 'sha256', (string) ( $applied_values['content'] ?? '' ) ) )
			) {
				return self::source_rewrite_error( 'publication_write_verification_failed', 'Stored source bytes do not equal the exact Quality-approved artifact.', array( 'published' => null ) );
			}

			$purge_urls = self::source_rewrite_purge_urls( $source );
			$invalidation = apply_filters(
				'devenia_workflow_frontend_cache_invalidation_result',
				null,
				$purge_urls,
				array( 'event' => 'source_rewrite_publish', 'source_id' => (int) $source->ID, 'job_id' => (string) $job['job_id'] )
			);
			$next = $job;
			$next['status']                     = 'published';
			$next['applied_source_hash']        = self::source_hash( $source );
			$next['applied_publication_surface_revision'] = self::source_publication_surface_revision( $source );
			$next['applied_copy_revision']      = (string) $applied_surface['revision'];
			$next['published_at']               = (string) ( $job['published_at'] ?? gmdate( 'c' ) );
			$next['live_verification_passed']   = null;
			$next['purge_urls']                 = $purge_urls;
			$next['cache_invalidation']         = is_array( $invalidation ) ? $invalidation : array( 'success' => false, 'code' => 'cache_invalidation_adapter_missing' );
			$next['updated_at']                 = gmdate( 'c' );
			unset( $next['quality_recheck'] );
			if ( $next !== $job && ! self::atomic_replace_option_value( $job_key, $job, $next ) ) {
				$current_job = get_option( $job_key );
				if ( ! is_array( $current_job ) || 'published' !== (string) ( $current_job['status'] ?? '' ) || ! hash_equals( (string) $next['applied_copy_revision'], (string) ( $current_job['applied_copy_revision'] ?? '' ) ) ) {
					return self::source_rewrite_error( 'publication_job_transition_conflict', 'Source bytes were applied, but the Job publication receipt could not be reconciled.', array( 'severity' => 'critical', 'published' => true ) );
				}
				$next = $current_job;
			}

			return array(
				'success'                 => ! empty( $next['cache_invalidation']['success'] ),
				'published'               => true,
				'needs_live_verification' => true,
				'code'                    => ! empty( $next['cache_invalidation']['success'] ) ? 'source_rewrite_published' : 'source_rewrite_cache_invalidation_failed',
				'message'                 => 'Source Rewrite published. Run source-rewrite-verify-live to complete hash-bound source approval.',
				'job'                     => self::source_rewrite_public_job( $next ),
				'purge_urls'              => $purge_urls,
				'cache_invalidation'      => $next['cache_invalidation'],
			);
		} finally {
			self::source_rewrite_release_publish_lease( $lease );
		}
	}

	/**
	 * Return an approved artifact to the bounded writer lifecycle when the owning
	 * public-write Adapter finds a defect that Quality could not see in its packet.
	 *
	 * @param mixed $preflight Public-write preflight result.
	 * @return array<string,mixed>
	 */
	private static function source_rewrite_transition_after_publish_preflight_failure( array $job, $preflight ): array {
		$failure = array(
			'code'       => 'publication_preflight_rejected',
			'error_code' => is_wp_error( $preflight ) ? (string) $preflight->get_error_code() : 'invalid_preflight_result',
			'error_data' => is_wp_error( $preflight ) ? $preflight->get_error_data() : null,
		);
		return self::source_rewrite_transition_to_correction( $job, $failure, $preflight );
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_transition_after_stale_authority( array $job, array $authority ): array {
		$failure = array(
			'code'       => 'publication_authority_stale',
			'error_code' => sanitize_key( (string) ( $authority['code'] ?? 'source_rewrite_authority_stale' ) ),
			'error_data' => $authority,
		);
		return self::source_rewrite_transition_to_correction( $job, $failure, $authority );
	}

	/**
	 * One finite state transition for post-Quality failures discovered before mutation.
	 *
	 * @param mixed $public_failure Error returned to the coordinator.
	 * @return array<string,mixed>
	 */
	private static function source_rewrite_transition_to_correction( array $job, array $failure, $public_failure ): array {
		$generation = (int) ( $job['submission_generation'] ?? 0 );
		$can_revise = $generation < self::SOURCE_REWRITE_MAX_SUBMISSION_GENERATIONS;
		$next = $job;
		$next['status'] = $can_revise ? 'changes_requested' : 'exhausted';
		$next['active_run_id'] = '';
		if ( $can_revise ) {
			$next['submission_generation'] = $generation + 1;
		}
		$failure['failed_at'] = gmdate( 'c' );
		$next['last_publish_failure'] = $failure;
		$next['updated_at'] = gmdate( 'c' );

		if ( ! self::atomic_replace_option_value( self::source_rewrite_job_key( (string) $job['job_id'] ), $job, $next ) ) {
			return self::source_rewrite_error(
				'publication_correction_transition_conflict',
				'The approved artifact failed a current authority gate, but the Job changed before it could return to the bounded writer lifecycle.',
				array( 'retryable' => true, 'failure' => $public_failure )
			);
		}

		$code = (string) ( $failure['code'] ?? 'publication_authority_rejected' );
		return self::source_rewrite_error(
			$code,
			$can_revise
				? 'The exact approved artifact failed a current authority gate and returned to changes_requested for one fresh bounded correction.'
				: 'The exact approved artifact failed a current authority gate and exhausted the bounded correction budget.',
			array( 'failure' => $public_failure, 'job' => self::source_rewrite_public_job( $next ) )
		);
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_verify_live( array $input ): array {
		$job_id = self::source_rewrite_clean_id( (string) ( $input['job_id'] ?? '' ) );
		$job_key = self::source_rewrite_job_key( $job_id );
		$job = get_option( $job_key );
		if ( ! is_array( $job ) || 'published' !== (string) ( $job['status'] ?? '' ) ) {
			return self::source_rewrite_error( 'job_not_published', 'Live verification requires a published Source Rewrite Job.' );
		}
		$authority = self::source_rewrite_authority_chain( $job, true );
		if ( empty( $authority['success'] ) ) {
			return $authority;
		}
		$source = get_post( absint( $job['source_id'] ?? 0 ) );
		if ( ! $source instanceof WP_Post || 'publish' !== (string) $source->post_status ) {
			return self::source_rewrite_error( 'source_not_published', 'The approved canonical source is not published.' );
		}
		$artifact = $authority['artifact'];
		$current = self::source_rewrite_source_values( $source );
		$current_source_hash = self::source_hash( $source );
		$current_surface = self::source_rewrite_copy_surface( $current['title'], $current['excerpt'], $current['content'] );
		$proposed = (array) ( $artifact['proposed'] ?? array() );
		$proposed_source_hash = self::source_rewrite_proposed_source_hash( $proposed );
		$verification_surface = self::source_rewrite_copy_surface(
			(string) ( $proposed['title'] ?? '' ),
			(string) ( $proposed['excerpt'] ?? '' ),
			(string) ( $proposed['content'] ?? '' )
		);
		if (
			! hash_equals( (string) ( $job['applied_source_hash'] ?? '' ), $current_source_hash )
			|| ! hash_equals( $proposed_source_hash, $current_source_hash )
			|| ! hash_equals( (string) $artifact['proposed_copy_surface']['revision'], (string) $current_surface['revision'] )
			|| ! hash_equals( (string) $artifact['proposed_copy_surface']['revision'], (string) $verification_surface['revision'] )
			|| ! hash_equals( (string) $artifact['proposed_content_hash'], hash( 'sha256', (string) $current['content'] ) )
		) {
			return self::source_rewrite_error( 'published_source_drifted', 'The stored source no longer equals the exact Quality-approved artifact.' );
		}

		$timeout = max( 2, min( 30, absint( $input['timeout'] ?? 5 ) ) );
		$url = esc_url_raw( (string) get_permalink( $source ) );
		$integrity = self::frontend_public_surface_integrity_for_url( $url, self::source_language_code(), $timeout, 'source_rewrite' );
		$copy_receipts = array();
		$copy_issues = array();
		foreach ( array( 'origin', 'canonical' ) as $cache_surface ) {
			$response = self::fetch_frontend_cache_surface( $url, $timeout, $cache_surface );
			$body = (string) ( $response['body'] ?? '' );
			$decoded_body = html_entity_decode( $body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$reader_actions = self::reader_surface_action_values( $decoded_body );
			$template_fields = self::reader_surface_template_field_values( $decoded_body );
			$body_text = self::source_rewrite_reader_text( wp_strip_all_tags( $decoded_body ) );
			$missing = array();
			foreach ( (array) ( $verification_surface['fragments'] ?? array() ) as $fragment ) {
				$field = (string) ( $fragment['field'] ?? '' );
				$block = (string) ( $fragment['block'] ?? '' );
				$is_action = str_starts_with( $field, 'action:' );
				$is_template_field = in_array( $field, array( 'title', 'excerpt' ), true );
				$attribute = $is_action && str_starts_with( $block, 'document:' ) ? substr( $block, strlen( 'document:' ) ) : '';
				$text = $is_action
					? self::reader_surface_action_identity( $attribute, (string) ( $fragment['text'] ?? '' ) )
					: self::source_rewrite_reader_text( (string) ( $fragment['text'] ?? '' ) );
				if ( empty( $fragment['atomic'] ) || 'content:document' === $field || 'document' === $block ) {
					continue;
				}
				$template_values = $is_template_field ? (array) ( $template_fields[ $field ] ?? array() ) : array();
				// A content-owned template has no semantic field host, so stored
				// bytes remain authoritative without inventing a body requirement.
				// When a theme or dynamic block does expose a field host, every
				// rendered value must equal the exact approved reader text.
				if ( $is_template_field && empty( $template_values ) ) {
					continue;
				}
				if ( ! $is_template_field && '' === $text ) {
					continue;
				}
				$found = $is_template_field
					? empty( array_diff( $template_values, array( $text ) ) )
					: ( $is_action
					? in_array( $text, (array) ( $reader_actions[ $attribute ] ?? array() ), true )
					: false !== strpos( $body_text, $text ) );
				if ( ! $found ) {
					$missing[] = $field;
				}
			}
			$passed = ! empty( $response['success'] ) && 200 === (int) ( $response['status_code'] ?? 0 ) && empty( $missing );
			$copy_receipts[ $cache_surface ] = array(
				'passed'       => $passed,
				'status_code'  => (int) ( $response['status_code'] ?? 0 ),
				'final_url'    => (string) ( $response['final_url'] ?? $url ),
				'body_digest'  => hash( 'sha256', (string) ( $response['body'] ?? '' ) ),
				'missing_fields'=> $missing,
			);
			if ( ! $passed ) {
				$copy_issues[] = array( 'cache_surface' => $cache_surface, 'missing_fields' => $missing, 'status_code' => (int) ( $response['status_code'] ?? 0 ) );
			}
		}
		$content_integrity = self::source_content_integrity_validation( $source );
		$publication_experience = self::publication_experience_readiness_for_post( $source, self::source_language_code(), 'source_rewrite_verify_live' );
		$passed = ! empty( $integrity['success'] )
			&& ! empty( $integrity['passed'] )
			&& empty( $copy_issues )
			&& empty( $content_integrity['issue_count'] )
			&& ! empty( $publication_experience['passed'] );
		if ( ! $passed ) {
			$failed = $job;
			$failed['live_verification_passed'] = false;
			$failed['live_verification'] = array( 'integrity' => $integrity, 'copy_receipts' => $copy_receipts, 'content_integrity' => $content_integrity, 'publication_experience' => $publication_experience );
			$failed['updated_at'] = gmdate( 'c' );
			self::atomic_replace_option_value( $job_key, $job, $failed );
			return self::source_rewrite_error( 'source_rewrite_live_verification_failed', 'The published source failed exact origin/canonical or publication-quality verification.', array( 'passed' => false, 'job' => self::source_rewrite_public_job( $failed ), 'verification' => $failed['live_verification'] ) );
		}

		$quality = $authority['quality'];
		$evidence = array(
			'source_rewrite_quality_passed' => true,
			'content_integrity_already_clean' => false,
			'job_id'          => (string) $job['job_id'],
			'artifact_revision'=> (string) $job['artifact_revision'],
			'quality_revision'=> (string) $job['quality_revision'],
			'source_hash'     => self::source_hash( $source ),
			'source_publication_surface_revision' => self::source_publication_surface_revision( $source ),
			'audit_notes'     => 'Exact staged Source Rewrite Artifact, independent Quality Decision, stored WordPress bytes, and origin/canonical rendered copy all match.',
			'reviewer_statement' => 'The independent Quality Run approved this exact artifact revision; live verification found every required reader-facing fragment on both cache surfaces.',
			'quality_evidence'=> $quality['evidence'],
			'live_verification'=> array( 'integrity' => $integrity, 'copy_receipts' => $copy_receipts ),
			'reviewed_at'     => gmdate( 'c' ),
			'reviewer'        => (string) ( $quality['reviewer_principal']['principal_id'] ?? 'source-rewrite-quality' ),
		);
		update_post_meta( (int) $source->ID, self::META_SOURCE_CONTENT_INTEGRITY_REVIEW_HASH, hash( 'sha256', wp_json_encode( array( $evidence['source_hash'], $evidence['source_publication_surface_revision'], $evidence['artifact_revision'], $evidence['quality_revision'] ) ) ?: '' ) );
		update_post_meta( (int) $source->ID, self::META_SOURCE_CONTENT_INTEGRITY_REVIEWED_AT, $evidence['reviewed_at'] );
		update_post_meta( (int) $source->ID, self::META_SOURCE_CONTENT_INTEGRITY_REVIEWER, $evidence['reviewer'] );
		update_post_meta( (int) $source->ID, self::META_SOURCE_CONTENT_INTEGRITY_REVIEW_NOTE, 'Approved through the exact Source Rewrite Quality lifecycle.' );
		self::update_json_post_meta( (int) $source->ID, self::META_SOURCE_CONTENT_INTEGRITY_REVIEW_EVIDENCE, $evidence );

		$next = $job;
		$next['live_verification_passed'] = true;
		$next['live_verification'] = array( 'integrity' => $integrity, 'copy_receipts' => $copy_receipts, 'content_integrity' => $content_integrity, 'publication_experience' => $publication_experience );
		$next['verified_at'] = gmdate( 'c' );
		$next['updated_at'] = gmdate( 'c' );
		if ( ! self::atomic_replace_option_value( $job_key, $job, $next ) ) {
			return self::source_rewrite_error( 'live_verification_job_transition_conflict', 'Live evidence passed, but the Source Rewrite Job receipt changed before activation.', array( 'retryable' => true ) );
		}
		return array( 'success' => true, 'passed' => true, 'job' => self::source_rewrite_public_job( $next ), 'verification' => $next['live_verification'], 'source_approval' => $evidence );
	}

	/**
	 * Canonical reader text after WordPress applies its normal typography.
	 *
	 * Stored source and artifact hashes remain byte-exact. This projection is
	 * used only for reader-facing copy receipts.
	 */
	private static function source_rewrite_reader_text( string $text ): string {
		return self::normalize_review_text( wptexturize( $text ) );
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_status( array $input ): array {
		$job_id = self::source_rewrite_clean_id( (string) ( $input['job_id'] ?? '' ) );
		if ( '' === $job_id && ! empty( $input['source_id'] ) ) {
			$source_id = absint( $input['source_id'] );
			$job_id = self::source_rewrite_clean_id( (string) get_option( self::source_rewrite_latest_key( $source_id ) ) );
			$source = get_post( $source_id );
			if ( '' === $job_id && $source instanceof WP_Post ) {
				$job_id = self::source_rewrite_job_id( (int) $source->ID, self::source_publication_surface_revision( $source ) );
			}
		}
		$job = get_option( self::source_rewrite_job_key( $job_id ) );
		if ( ! is_array( $job ) ) {
			return self::source_rewrite_error( 'job_not_found', 'Source Rewrite Job not found.' );
		}
		$runs = array();
		foreach ( (array) ( $job['run_ids'] ?? array() ) as $row ) {
			$run = get_option( self::source_rewrite_run_key( (string) ( $row['run_id'] ?? '' ) ) );
			if ( is_array( $run ) ) {
				$runs[] = $run;
			}
		}
		$artifact = ! empty( $job['artifact_revision'] ) ? get_option( self::source_rewrite_artifact_key( (string) $job['artifact_revision'] ) ) : null;
		$quality  = ! empty( $job['quality_revision'] ) ? get_option( self::source_rewrite_quality_key( (string) $job['quality_revision'] ) ) : null;
		return array( 'success' => true, 'job' => self::source_rewrite_public_job( $job ), 'runs' => $runs, 'artifact' => is_array( $artifact ) ? $artifact : null, 'quality_decision' => is_array( $quality ) ? $quality : null );
	}

	/**
	 * Final storage Adapter guard. Unauthorized published source-copy changes are
	 * replaced with the current stored bytes, including draft-to-publish attempts.
	 *
	 * @param array<string,mixed> $data Sanitized post data.
	 * @param array<string,mixed> $postarr Slashed post input.
	 * @param array<string,mixed> $unsanitized_postarr Raw post input.
	 * @return array<string,mixed>
	 */
	public static function guard_unapproved_source_rewrite_before_save( array $data, array $postarr, array $unsanitized_postarr = array(), bool $update = false ): array {
		unset( $unsanitized_postarr, $update );
		$post_id = absint( $postarr['ID'] ?? $data['ID'] ?? 0 );
		$current = $post_id ? get_post( $post_id ) : null;
		$post_type = $current instanceof WP_Post
			? (string) $current->post_type
			: sanitize_key( (string) ( $data['post_type'] ?? $postarr['post_type'] ?? '' ) );
		$target_status = sanitize_key( (string) ( $data['post_status'] ?? ( $current instanceof WP_Post ? $current->post_status : 'draft' ) ) );
		if ( ! $current instanceof WP_Post ) {
			if ( self::is_translatable_post_type( $post_type ) && 'publish' === $target_status ) {
				$data['post_status'] = 'draft';
			}
			return $data;
		}
		if ( ! self::is_translatable_post_type( $post_type ) || self::is_translation_post( $post_id ) ) {
			return $data;
		}
		if ( 'publish' !== $target_status && 'publish' !== (string) $current->post_status ) {
			return $data;
		}
		$proposed = array(
			'title'   => sanitize_text_field( (string) ( $data['post_title'] ?? $current->post_title ) ),
			'excerpt' => sanitize_textarea_field( (string) ( $data['post_excerpt'] ?? $current->post_excerpt ) ),
			'content' => self::normalize_gutenberg_content_for_storage( (string) ( $data['post_content'] ?? $current->post_content ) ),
		);
		$current_values = self::source_rewrite_source_values( $current );
		$current_surface = self::source_rewrite_copy_surface( $current_values['title'], $current_values['excerpt'], $current_values['content'] );
		$proposed_surface = self::source_rewrite_copy_surface( $proposed['title'], $proposed['excerpt'], $proposed['content'] );
		$is_first_publication = 'publish' === $target_status && 'publish' !== (string) $current->post_status;
		if (
			( ! $is_first_publication && hash_equals( (string) $current_surface['revision'], (string) $proposed_surface['revision'] ) )
			|| self::source_rewrite_request_authorizes( $current, $proposed, $proposed_surface )
		) {
			return $data;
		}
		$data['post_title']   = (string) $current->post_title;
		$data['post_excerpt'] = (string) $current->post_excerpt;
		$data['post_content'] = (string) $current->post_content;
		if ( 'publish' !== (string) $current->post_status ) {
			$data['post_status'] = (string) $current->post_status;
		}
		return $data;
	}

	/**
	 * Adapt Source Rewrite Quality Authority to the neutral public write seam.
	 *
	 * @param mixed               $result  Earlier preflight result.
	 * @param array<string,mixed> $context Neutral public write context.
	 * @return true|WP_Error
	 */
	public static function validate_source_rewrite_quality_preflight( $result, array $context ) {
		if ( true !== $result ) {
			return $result;
		}
		$post      = $context['post'] ?? null;
		$post_type = sanitize_key( (string) ( $context['post_type'] ?? '' ) );
		$content   = (string) ( $context['content'] ?? '' );
		$input     = is_array( $context['input'] ?? null ) ? $context['input'] : array();
		if ( ! $post instanceof WP_Post || ! in_array( $post_type, array( 'page', 'post' ), true ) || self::is_translation_post( (int) $post->ID ) ) {
			return true;
		}

		$current  = self::source_rewrite_source_values( $post );
		$proposed = array(
			'title'   => array_key_exists( 'title', $input ) ? sanitize_text_field( (string) $input['title'] ) : $current['title'],
			'excerpt' => array_key_exists( 'excerpt', $input ) ? sanitize_textarea_field( (string) $input['excerpt'] ) : $current['excerpt'],
			'content' => self::normalize_gutenberg_content_for_storage( $content ),
		);
		$current_surface  = self::source_rewrite_copy_surface( $current['title'], $current['excerpt'], $current['content'] );
		$proposed_surface = self::source_rewrite_copy_surface( $proposed['title'], $proposed['excerpt'], $proposed['content'] );
		if ( hash_equals( (string) $current_surface['revision'], (string) $proposed_surface['revision'] ) ) {
			return true;
		}

		if ( self::source_rewrite_request_authorizes( $post, $proposed, $proposed_surface ) ) {
			return true;
		}

		return new WP_Error(
			'devenia_source_rewrite_quality_required',
			'A source copy mutation requires an exact staged artifact and an independent Quality decision before publication.',
			array(
				'source_id'               => (int) $post->ID,
				'current_copy_revision'   => (string) $current_surface['revision'],
				'proposed_copy_revision'  => (string) $proposed_surface['revision'],
				'current_fragment_count'  => count( $current_surface['fragments'] ),
				'proposed_fragment_count' => count( $proposed_surface['fragments'] ),
				'suggested_ability'       => 'devenia-workflow/source-rewrite-discover',
			)
		);
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_claim_access( array $input, string $required_role = '' ): array {
		$job_id = self::source_rewrite_clean_id( (string) ( $input['job_id'] ?? '' ) );
		$run_id = self::source_rewrite_clean_id( (string) ( $input['run_id'] ?? '' ) );
		$token  = (string) ( $input['claim_token'] ?? '' );
		$job    = get_option( self::source_rewrite_job_key( $job_id ) );
		$claim  = get_option( self::source_rewrite_claim_key( $job_id ) );
		$run    = get_option( self::source_rewrite_run_key( $run_id ) );
		if ( ! is_array( $job ) || ! is_array( $claim ) || ! is_array( $run ) ) {
			return self::source_rewrite_error( 'claim_access_missing', 'The active Source Rewrite claim or Run is unavailable.' );
		}
		if (
			$run_id !== (string) ( $claim['run_id'] ?? '' )
			|| $run_id !== (string) ( $job['active_run_id'] ?? '' )
			|| '' === $token
			|| ! hash_equals( (string) ( $claim['token_hash'] ?? '' ), hash( 'sha256', $token ) )
			|| strtotime( (string) ( $claim['expires_at'] ?? '' ) ) <= time()
		) {
			return self::source_rewrite_error( 'claim_access_denied', 'The Source Rewrite claim token, Run, or lease is invalid.' );
		}
		$generation = (int) ( $job['submission_generation'] ?? 0 );
		$review_cycle = (int) ( $job['review_cycle'] ?? 0 );
		if (
			(string) ( $job['job_id'] ?? '' ) !== (string) ( $claim['job_id'] ?? '' )
			|| (string) ( $job['job_id'] ?? '' ) !== (string) ( $run['job_id'] ?? '' )
			|| (string) ( $claim['role'] ?? '' ) !== (string) ( $run['role'] ?? '' )
			|| (string) ( $claim['coordinator_id'] ?? '' ) !== (string) ( $run['coordinator_id'] ?? '' )
			|| 'running' !== (string) ( $run['status'] ?? '' )
			|| $generation !== (int) ( $claim['submission_generation'] ?? -1 )
			|| $generation !== (int) ( $run['submission_generation'] ?? -1 )
			|| $review_cycle !== (int) ( $claim['review_cycle'] ?? 0 )
			|| $review_cycle !== (int) ( $run['review_cycle'] ?? 0 )
		) {
			return self::source_rewrite_error( 'claim_record_binding_mismatch', 'The Source Rewrite Job, Claim, and Run records do not share one active role, review cycle, and submission generation.' );
		}
		if ( '' !== $required_role && $required_role !== (string) ( $run['role'] ?? '' ) ) {
			return self::source_rewrite_error( 'claim_role_mismatch', 'The active Source Rewrite Run has the wrong role for this operation.' );
		}
		if ( 'quality' === (string) ( $run['role'] ?? '' ) ) {
			$single_flight = self::quality_single_flight_validate( 'source_rewrite', $job, $claim );
			if ( empty( $single_flight['success'] ) ) {
				return $single_flight;
			}
		}

		return array( 'success' => true, 'job' => $job, 'claim' => $claim, 'run' => $run );
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_recover_expired_claim( array $job ): array {
		$claim_key = self::source_rewrite_claim_key( (string) $job['job_id'] );
		$claim = get_option( $claim_key );
		if ( ! is_array( $claim ) || strtotime( (string) ( $claim['expires_at'] ?? '' ) ) > time() ) {
			return array( 'success' => true, 'job' => $job, 'recovered' => false );
		}
		$run_id = (string) ( $claim['run_id'] ?? '' );
		$run_key = self::source_rewrite_run_key( $run_id );
		$run = get_option( $run_key );
		if ( is_array( $run ) && 'running' === (string) ( $run['status'] ?? '' ) ) {
			$expired = array_merge( $run, array( 'status' => 'completed', 'outcome' => 'claim_expired', 'completed_at' => gmdate( 'c' ) ) );
			if ( ! self::atomic_replace_option_value( $run_key, $run, $expired ) ) {
				return self::source_rewrite_error( 'expired_run_transition_conflict', 'The expired Source Rewrite Run changed during recovery.', array( 'retryable' => true ) );
			}
		}
		$next = $job;
		if ( $run_id === (string) ( $job['active_run_id'] ?? '' ) ) {
			$next['status'] = (string) ( $claim['previous_status'] ?? ( 'quality' === (string) ( $claim['role'] ?? '' ) ? 'quality_pending' : 'queued' ) );
			$next['active_run_id'] = '';
			$next['updated_at'] = gmdate( 'c' );
			if ( ! self::atomic_replace_option_value( self::source_rewrite_job_key( (string) $job['job_id'] ), $job, $next ) ) {
				return self::source_rewrite_error( 'expired_job_recovery_conflict', 'The Job changed while its expired claim was being recovered.', array( 'retryable' => true ) );
			}
		}
		if ( ! self::source_rewrite_release_claim( $claim ) ) {
			$current_claim = get_option( $claim_key );
			if ( false !== $current_claim ) {
				return self::source_rewrite_error( 'expired_claim_release_conflict', 'The expired claim could not be released exactly.', array( 'retryable' => true ) );
			}
		}
		return array( 'success' => true, 'job' => $next, 'recovered' => true );
	}

	/** @param array<string,mixed> $extra @return array<string,mixed> */
	private static function source_rewrite_complete_run_and_release( array $run, array $claim, string $outcome, array $extra = array() ): array {
		$completed = array_merge( $run, $extra, array( 'status' => 'completed', 'outcome' => $outcome, 'completed_at' => gmdate( 'c' ) ) );
		$run_key = self::source_rewrite_run_key( (string) $run['run_id'] );
		if ( ! self::atomic_replace_option_value( $run_key, $run, $completed ) ) {
			$current_run = get_option( $run_key );
			if ( ! is_array( $current_run ) || 'completed' !== (string) ( $current_run['status'] ?? '' ) || $outcome !== (string) ( $current_run['outcome'] ?? '' ) ) {
				return self::source_rewrite_error( 'run_completion_conflict', 'The immutable result was stored, but the Run could not become terminal.', array( 'severity' => 'critical', 'retryable' => true ) );
			}
			$completed = $current_run;
		}
		$claim_key = self::source_rewrite_claim_key( (string) $claim['job_id'] );
		if ( ! self::source_rewrite_release_claim( $claim ) ) {
			$current_claim = get_option( $claim_key );
			if ( false !== $current_claim ) {
				return self::source_rewrite_error( 'claim_release_conflict', 'The terminal Run could not release its exact claim.', array( 'severity' => 'critical', 'retryable' => true ) );
			}
		}
		return array( 'success' => true, 'run' => $completed );
	}

	/** Release one exact Source Rewrite claim and its Quality single-flight lease. */
	private static function source_rewrite_release_claim( array $claim ): bool {
		$claim_key = self::source_rewrite_claim_key( (string) ( $claim['job_id'] ?? '' ) );
		if ( 'quality' === sanitize_key( (string) ( $claim['role'] ?? '' ) ) ) {
			$released = self::quality_single_flight_release( 'source_rewrite', $claim );
			if ( empty( $released['success'] ) && 'quality_lease_owner_mismatch' !== (string) ( $released['code'] ?? '' ) ) {
				return false;
			}
		}
		return self::atomic_delete_option_value( $claim_key, $claim );
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_current_baseline( array $job ): array {
		$source = get_post( absint( $job['source_id'] ?? 0 ) );
		if ( ! $source instanceof WP_Post ) {
			return array( 'current' => false, 'reason' => 'source_missing' );
		}
		$current_hash    = self::source_hash( $source );
		$current_surface = self::source_publication_surface_revision( $source );
		$current = hash_equals( (string) ( $job['baseline_source_hash'] ?? '' ), $current_hash )
			&& hash_equals( (string) ( $job['baseline_publication_surface_revision'] ?? '' ), $current_surface );
		$authority = 'original_baseline';
		$recheck = is_array( $job['quality_recheck'] ?? null ) ? $job['quality_recheck'] : array();
		if ( ! $current && ! empty( $recheck ) ) {
			$artifact = get_option( self::source_rewrite_artifact_key( (string) ( $recheck['artifact_revision'] ?? '' ) ) );
			$applied = is_array( $artifact ) ? self::source_rewrite_applied_artifact_matches( $job, $artifact, $source, $recheck ) : array( 'success' => false );
			$current = ! empty( $applied['success'] );
			$authority = $current ? 'published_artifact_requality' : 'published_artifact_requality_drifted';
		}
		return array( 'current' => $current, 'authority' => $authority, 'source_hash' => $current_hash, 'publication_surface_revision' => $current_surface );
	}

	/**
	 * Verify that WordPress still stores the exact bytes selected by the applied
	 * Artifact receipt. An optional recheck marker supplies immutable anchors.
	 *
	 * @param mixed $source Canonical source post.
	 * @param array<string,mixed> $anchors Optional active requality anchors.
	 * @return array<string,mixed>
	 */
	private static function source_rewrite_applied_artifact_matches( array $job, array $artifact, $source, array $anchors = array() ): array {
		if ( ! $source instanceof WP_Post || 'publish' !== (string) $source->post_status ) {
			return array( 'success' => false, 'reason' => 'source_not_published' );
		}
		$values = self::source_rewrite_source_values( $source );
		$surface = self::source_rewrite_copy_surface( $values['title'], $values['excerpt'], $values['content'] );
		$proposed = is_array( $artifact['proposed'] ?? null ) ? $artifact['proposed'] : array();
		$expected_source_hash = (string) ( $anchors['anchor_source_hash'] ?? $job['applied_source_hash'] ?? '' );
		$expected_publication_surface = (string) ( $anchors['anchor_publication_surface_revision'] ?? $job['applied_publication_surface_revision'] ?? '' );
		$expected_copy_revision = (string) ( $anchors['anchor_copy_revision'] ?? $job['applied_copy_revision'] ?? '' );
		$expected_artifact_revision = (string) ( $anchors['artifact_revision'] ?? $job['artifact_revision'] ?? '' );
		$matches = '' !== $expected_source_hash
			&& '' !== $expected_publication_surface
			&& '' !== $expected_copy_revision
			&& hash_equals( $expected_artifact_revision, (string) ( $artifact['artifact_revision'] ?? '' ) )
			&& hash_equals( $expected_source_hash, self::source_hash( $source ) )
			&& hash_equals( $expected_publication_surface, self::source_publication_surface_revision( $source ) )
			&& hash_equals( $expected_copy_revision, (string) $surface['revision'] )
			&& hash_equals( self::source_rewrite_proposed_source_hash( $proposed ), self::source_hash( $source ) )
			&& hash_equals( (string) ( $artifact['proposed_copy_surface']['revision'] ?? '' ), (string) $surface['revision'] )
			&& hash_equals( (string) ( $artifact['proposed_content_hash'] ?? '' ), hash( 'sha256', (string) $values['content'] ) );
		return array(
			'success' => $matches,
			'source_hash' => self::source_hash( $source ),
			'publication_surface_revision' => self::source_publication_surface_revision( $source ),
			'copy_revision' => (string) $surface['revision'],
		);
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_pending_for_source( WP_Post $source ): array {
		$job_id = self::source_rewrite_clean_id( (string) get_option( self::source_rewrite_latest_key( (int) $source->ID ), '' ) );
		if ( '' === $job_id ) {
			$job_id = self::source_rewrite_job_id( (int) $source->ID, self::source_publication_surface_revision( $source ) );
		}
		$job = get_option( self::source_rewrite_job_key( $job_id ) );
		$pending = is_array( $job )
			&& (int) $source->ID === absint( $job['source_id'] ?? 0 )
			&& ! in_array( (string) ( $job['status'] ?? '' ), array( 'published', 'cancelled' ), true );
		return array(
			'pending' => $pending,
			'job_id'  => $pending ? $job_id : '',
			'status'  => is_array( $job ) ? sanitize_key( (string) ( $job['status'] ?? '' ) ) : '',
		);
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_authority_chain( array $job, bool $allow_published = false ): array {
		$allowed = $allow_published ? array( 'ready_to_publish', 'published' ) : array( 'ready_to_publish' );
		if ( ! in_array( (string) ( $job['status'] ?? '' ), $allowed, true ) || empty( $job['artifact_revision'] ) || empty( $job['quality_revision'] ) ) {
			return self::source_rewrite_error( 'source_rewrite_authority_missing', 'The Job has no passing exact Artifact and Quality authority chain.' );
		}
		$artifact = get_option( self::source_rewrite_artifact_key( (string) $job['artifact_revision'] ) );
		$quality  = get_option( self::source_rewrite_quality_key( (string) $job['quality_revision'] ) );
		if ( ! is_array( $artifact ) || ! is_array( $quality ) || 'pass' !== (string) ( $quality['decision'] ?? '' ) ) {
			return self::source_rewrite_error( 'source_rewrite_authority_record_missing', 'The exact Artifact or passing Quality record is unavailable.' );
		}
		$quality_hash_input = $quality;
		unset( $quality_hash_input['quality_revision'] );
		$expected_quality_revision = 'srq_' . substr( hash( 'sha256', wp_json_encode( $quality_hash_input ) ?: '' ), 0, 48 );
		if (
			! hash_equals( $expected_quality_revision, (string) ( $quality['quality_revision'] ?? '' ) )
			|| ! hash_equals( $expected_quality_revision, (string) ( $job['quality_revision'] ?? '' ) )
		) {
			return self::source_rewrite_error( 'source_rewrite_quality_revision_mismatch', 'The stored Source Rewrite Quality bytes no longer match their content-addressed revision.' );
		}
		$source = get_post( absint( $job['source_id'] ?? 0 ) );
		if ( ! $source instanceof WP_Post ) {
			return self::source_rewrite_error( 'source_not_found', 'Canonical source content is unavailable while validating Source Rewrite authority.' );
		}
		$current_writer_priming = (string) ( self::source_rewrite_role_priming( 'source_writer', $job, $source )['priming_revision'] ?? '' );
		$current_quality_priming = (string) ( self::source_rewrite_role_priming( 'quality', $job, $source )['priming_revision'] ?? '' );
		if (
			'' === $current_writer_priming
			|| '' === $current_quality_priming
			|| ! hash_equals( $current_writer_priming, (string) ( $artifact['priming_revision'] ?? '' ) )
			|| ! hash_equals( $current_quality_priming, (string) ( $quality['priming_revision'] ?? '' ) )
		) {
			return self::source_rewrite_error( 'source_rewrite_priming_stale', 'The source-scoped writing or Quality policy changed after approval. A fresh artifact and Quality Decision are required.' );
		}
		$quality_evidence_validation = self::source_rewrite_validate_quality_evidence(
			is_array( $quality['evidence'] ?? null ) ? $quality['evidence'] : array(),
			(string) ( $quality['decision'] ?? '' )
		);
		if ( empty( $quality_evidence_validation['success'] ) ) {
			return self::source_rewrite_error(
				'source_rewrite_quality_evidence_invalid',
				'The stored Source Rewrite Quality evidence no longer satisfies the current exact attestation contract.',
				array( 'validation' => $quality_evidence_validation )
			);
		}
		$stored_browser_validation = self::source_rewrite_validate_stored_browser_receipts( $quality, $artifact );
		if ( empty( $stored_browser_validation['success'] ) ) {
			return $stored_browser_validation;
		}
		if (
			(string) ( $artifact['artifact_revision'] ?? '' ) !== (string) ( $quality['artifact_revision'] ?? '' )
			|| (string) ( $artifact['artifact_revision'] ?? '' ) !== (string) $job['artifact_revision']
			|| (string) ( $quality['quality_revision'] ?? '' ) !== (string) $job['quality_revision']
			|| (string) ( $artifact['policy_revision'] ?? '' ) !== self::source_rewrite_policy_revision()
			|| (string) ( $quality['policy_revision'] ?? '' ) !== self::source_rewrite_policy_revision()
			|| (string) ( $artifact['job_id'] ?? '' ) !== (string) ( $job['job_id'] ?? '' )
			|| (string) ( $quality['job_id'] ?? '' ) !== (string) ( $job['job_id'] ?? '' )
			|| (int) ( $artifact['source_id'] ?? 0 ) !== (int) ( $job['source_id'] ?? 0 )
			|| (int) ( $quality['source_id'] ?? 0 ) !== (int) ( $job['source_id'] ?? 0 )
			|| (string) ( $artifact['baseline_source_hash'] ?? '' ) !== (string) ( $job['baseline_source_hash'] ?? '' )
			|| (string) ( $quality['baseline_source_hash'] ?? '' ) !== (string) ( $job['baseline_source_hash'] ?? '' )
			|| (string) ( $artifact['baseline_publication_surface_revision'] ?? '' ) !== (string) ( $job['baseline_publication_surface_revision'] ?? '' )
			|| (string) ( $quality['baseline_publication_surface_revision'] ?? '' ) !== (string) ( $job['baseline_publication_surface_revision'] ?? '' )
			|| (int) ( $artifact['submission_generation'] ?? 0 ) !== (int) ( $quality['submission_generation'] ?? -1 )
			|| (int) ( $artifact['submission_generation'] ?? 0 ) !== (int) ( $job['submission_generation'] ?? -1 )
			|| (int) ( $quality['review_cycle'] ?? 0 ) !== (int) ( $job['review_cycle'] ?? 0 )
		) {
			return self::source_rewrite_error( 'source_rewrite_authority_binding_mismatch', 'Artifact, Quality, policy, or generation bindings do not match.' );
		}
		$writer   = is_array( $artifact['writer_principal'] ?? null ) ? $artifact['writer_principal'] : array();
		$reviewer = is_array( $quality['reviewer_principal'] ?? null ) ? $quality['reviewer_principal'] : array();
		if ( empty( $writer['principal_id'] ) || empty( $reviewer['principal_id'] ) || hash_equals( (string) $writer['principal_id'], (string) $reviewer['principal_id'] ) || (string) ( $writer['run_id'] ?? '' ) === (string) ( $reviewer['run_id'] ?? '' ) ) {
			return self::source_rewrite_error( 'writer_reviewer_principal_conflict', 'Writer and Quality authority must come from distinct fresh Run Principals.' );
		}
		return array( 'success' => true, 'artifact' => $artifact, 'quality' => $quality, 'writer_principal' => $writer, 'reviewer_principal' => $reviewer );
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_acquire_publish_lease( array $job ): array {
		$key = self::source_rewrite_publish_lease_key( (string) $job['job_id'] );
		$current = get_option( $key );
		if ( is_array( $current ) && strtotime( (string) ( $current['expires_at'] ?? '' ) ) <= time() ) {
			self::atomic_delete_option_value( $key, $current );
			$current = get_option( $key );
		}
		if ( is_array( $current ) ) {
			return self::source_rewrite_error( 'source_rewrite_publish_lease_conflict', 'Another coordinator owns this Source Rewrite publication.', array( 'retryable' => true, 'expires_at' => (string) ( $current['expires_at'] ?? '' ) ) );
		}
		$lease = array(
			'job_id'     => (string) $job['job_id'],
			'token_hash' => hash( 'sha256', wp_generate_password( 48, false, false ) ),
			'acquired_at'=> gmdate( 'c' ),
			'expires_at' => gmdate( 'c', time() + 120 ),
		);
		if ( ! self::atomic_create_option( $key, $lease ) ) {
			return self::source_rewrite_error( 'source_rewrite_publish_lease_race_lost', 'Another coordinator acquired Source Rewrite publication first.', array( 'retryable' => true ) );
		}
		return array( 'success' => true, 'key' => $key, 'lease' => $lease );
	}

	private static function source_rewrite_release_publish_lease( array $lease ): void {
		if ( ! empty( $lease['key'] ) && is_array( $lease['lease'] ?? null ) ) {
			self::atomic_delete_option_value( (string) $lease['key'], $lease['lease'] );
		}
	}

	/** Serialize latest-Job ownership transitions for one canonical source. */
	private static function source_rewrite_acquire_source_transition_lease( int $source_id, string $operation, string $job_id ): array {
		$key = self::source_rewrite_source_transition_lease_key( $source_id );
		$now = time();
		$lease = array(
			'source_id' => $source_id,
			'operation' => sanitize_key( $operation ),
			'job_id' => self::source_rewrite_clean_id( $job_id ),
			'lease_id' => hash( 'sha256', wp_generate_password( 48, false, false ) . '|' . $source_id . '|' . $operation . '|' . $now ),
			'acquired_at' => gmdate( 'c', $now ),
			'expires_at' => gmdate( 'c', $now + 120 ),
		);
		if ( self::atomic_create_option( $key, $lease ) ) {
			return array( 'success' => true, 'key' => $key, 'lease' => $lease );
		}
		$existing = get_option( $key );
		if ( is_array( $existing ) && strtotime( (string) ( $existing['expires_at'] ?? '' ) ) <= $now && self::atomic_replace_option_value( $key, $existing, $lease ) ) {
			return array( 'success' => true, 'key' => $key, 'lease' => $lease, 'recovered' => true );
		}
		return self::source_rewrite_error( 'source_rewrite_transition_active', 'Another exact source ownership transition is active.', array( 'source_id' => $source_id, 'retryable' => true ) );
	}

	/** Release only the exact source-transition owner. */
	private static function source_rewrite_release_source_transition_lease( array $lease ): void {
		if ( ! empty( $lease['key'] ) && is_array( $lease['lease'] ?? null ) ) {
			self::atomic_delete_option_value( (string) $lease['key'], $lease['lease'] );
		}
	}

	/** @return array<int,string> */
	private static function source_rewrite_purge_urls( WP_Post $source ): array {
		$urls = array( (string) get_permalink( $source ), home_url( '/' ) );
		if ( (int) $source->post_parent > 0 ) {
			$urls[] = (string) get_permalink( (int) $source->post_parent );
		}
		$urls = apply_filters( 'devenia_workflow_source_rewrite_purge_urls', $urls, $source );
		return array_values( array_unique( array_filter( array_map( 'esc_url_raw', is_array( $urls ) ? $urls : array() ) ) ) );
	}

	private static function source_rewrite_request_authorizes( WP_Post $source, array $proposed, array $proposed_surface ): bool {
		$authority = self::$source_rewrite_publish_authority;
		if (
			(int) ( $authority['source_id'] ?? 0 ) !== (int) $source->ID
			|| empty( $authority['job_id'] )
			|| empty( $authority['artifact_revision'] )
			|| empty( $authority['quality_revision'] )
			|| ! hash_equals( (string) ( $authority['proposed_copy_revision'] ?? '' ), (string) ( $proposed_surface['revision'] ?? '' ) )
			|| ! hash_equals( (string) ( $authority['proposed_content_hash'] ?? '' ), hash( 'sha256', (string) ( $proposed['content'] ?? '' ) ) )
		) {
			return false;
		}
		$job = get_option( self::source_rewrite_job_key( (string) $authority['job_id'] ) );
		if ( ! is_array( $job ) || (string) ( $job['artifact_revision'] ?? '' ) !== (string) $authority['artifact_revision'] || (string) ( $job['quality_revision'] ?? '' ) !== (string) $authority['quality_revision'] ) {
			return false;
		}
		$chain = self::source_rewrite_authority_chain( $job );
		if ( empty( $chain['success'] ) ) {
			return false;
		}
		$artifact_proposed = (array) ( $chain['artifact']['proposed'] ?? array() );
		return (string) ( $artifact_proposed['title'] ?? '' ) === (string) ( $proposed['title'] ?? '' )
			&& (string) ( $artifact_proposed['excerpt'] ?? '' ) === (string) ( $proposed['excerpt'] ?? '' );
	}

	/** @return array{revision:string,fragments:array<int,array<string,mixed>>} */
	private static function source_rewrite_copy_surface( string $title, string $excerpt, string $content ): array {
		$fragments = array(
			array( 'field' => 'title', 'text' => self::normalize_review_text( $title ), 'atomic' => true ),
			array( 'field' => 'excerpt', 'text' => self::normalize_review_text( $excerpt ), 'atomic' => true ),
			array( 'field' => 'content:document', 'block' => 'document', 'heading' => false, 'unique_id' => '', 'text' => self::normalize_review_text( wp_strip_all_tags( $content ) ), 'atomic' => false ),
		);
		foreach ( self::text_fragments_for_copy_quality( $content ) as $index => $fragment ) {
			$fragments[] = array(
				'field'     => 'content:' . (int) $index,
				'block'     => sanitize_text_field( (string) ( $fragment['block'] ?? '' ) ),
				'heading'   => ! empty( $fragment['heading'] ),
				'unique_id' => sanitize_text_field( (string) ( $fragment['unique_id'] ?? '' ) ),
				'text'      => self::normalize_review_text( (string) ( $fragment['text'] ?? '' ) ),
				'atomic'    => ! array_key_exists( 'atomic', $fragment ) || ! empty( $fragment['atomic'] ),
			);
		}
		foreach ( self::source_rewrite_customer_action_fragments( $content ) as $index => $fragment ) {
			$fragments[] = array(
				'field'     => 'action:' . (int) $index,
				'block'     => 'document:' . (string) $fragment['attribute'],
				'heading'   => false,
				'unique_id' => '',
				'text'      => (string) $fragment['value'],
				'atomic'    => true,
			);
		}
		$revision_fragments = array_map(
			static function ( array $fragment ): array {
				unset( $fragment['atomic'] );
				return $fragment;
			},
			$fragments
		);
		return array( 'revision' => hash( 'sha256', wp_json_encode( $revision_fragments ) ?: '' ), 'fragments' => $fragments );
	}

	/** @return array<int,array{attribute:string,value:string}> */
	private static function source_rewrite_customer_action_fragments( string $content ): array {
		$fragments = array();
		foreach ( array( 'href', 'aria-label', 'alt' ) as $attribute ) {
			$pattern = '/\b' . preg_quote( $attribute, '/' ) . '\s*=\s*(["\'])(.*?)\1/isu';
			if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $matches as $match ) {
				$value = self::normalize_review_text( html_entity_decode( (string) ( $match[2] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( '' !== $value ) {
					$fragments[] = array( 'attribute' => $attribute, 'value' => $value );
				}
			}
		}
		return $fragments;
	}

	/** @return array{title:string,excerpt:string,content:string} */
	private static function source_rewrite_source_values( WP_Post $source ): array {
		return array(
			'title'   => (string) $source->post_title,
			'excerpt' => (string) $source->post_excerpt,
			'content' => self::normalize_gutenberg_content_for_storage( (string) $source->post_content ),
		);
	}

	/** @param array<string,mixed> $proposed */
	private static function source_rewrite_proposed_source_hash( array $proposed ): string {
		return self::source_hash_from_values(
			(string) ( $proposed['title'] ?? '' ),
			(string) ( $proposed['excerpt'] ?? '' ),
			self::normalize_gutenberg_content_for_storage( (string) ( $proposed['content'] ?? '' ) )
		);
	}

	/**
	 * Render the immutable staged artifact through the canonical theme for one
	 * active Quality claim. The capability contains no claim token and expires
	 * at the same server-owned lease boundary.
	 *
	 * @return array<string,mixed>
	 */
	private static function source_rewrite_preview_descriptor( array $job, array $run, array $claim, array $artifact, WP_Post $source ): array {
		$expires = strtotime( (string) ( $claim['expires_at'] ?? '' ) );
		$host_scope = 'canonical_source_theme_shell';
		$host_identity = $host_scope . ':' . (int) $source->ID;
		$token = self::staged_preview_capability_token( 'source', (string) $job['job_id'], (string) $run['run_id'], (string) $artifact['artifact_revision'], $expires, (string) ( $claim['token_hash'] ?? '' ), $host_identity );
		$base_url = add_query_arg( 'post' === (string) $source->post_type ? 'p' : 'page_id', (string) $source->ID, home_url( '/' ) );
		$url = add_query_arg( 'devenia_source_rewrite_preview', $token, $base_url );
		return array(
			'url' => esc_url_raw( $url ),
			'preview_identity' => hash( 'sha256', $token ),
			'artifact_revision' => (string) $artifact['artifact_revision'],
			'copy_revision' => (string) ( $artifact['proposed_copy_surface']['revision'] ?? '' ),
			'preview_host_id' => (int) $source->ID,
			'preview_host_scope' => $host_scope,
			'expires_at' => (string) ( $claim['expires_at'] ?? '' ),
			'cache_policy' => 'private_no_store',
			'indexing_policy' => 'noindex_nofollow_noarchive',
			'viewports' => array(
				'desktop' => array( 'width' => 1140, 'height' => 800, 'device_scale_factor' => 1 ),
				'mobile' => array( 'width' => 390, 'height' => 844, 'device_scale_factor' => 1 ),
			),
		);
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_preview_authority( string $token ): array {
		$parts = self::staged_preview_capability_parts( $token, 'source' );
		if ( empty( $parts ) ) {
			return self::source_rewrite_error( 'source_rewrite_preview_invalid', 'The staged preview capability is malformed.' );
		}
		$job_id = (string) $parts['job_id'];
		$run_id = (string) $parts['run_id'];
		$artifact_revision = (string) $parts['artifact_revision'];
		$expires = (int) $parts['expires'];
		$job = get_option( self::source_rewrite_job_key( $job_id ) );
		$run = get_option( self::source_rewrite_run_key( $run_id ) );
		$claim = get_option( self::source_rewrite_claim_key( $job_id ) );
		$artifact = get_option( self::source_rewrite_artifact_key( $artifact_revision ) );
		$host_identity = 'canonical_source_theme_shell:' . absint( $job['source_id'] ?? 0 );
		$expected_token = is_array( $claim ) ? self::staged_preview_capability_token( 'source', $job_id, $run_id, $artifact_revision, $expires, (string) ( $claim['token_hash'] ?? '' ), $host_identity ) : '';
		if (
			! is_array( $job ) || ! is_array( $run ) || ! is_array( $claim ) || ! is_array( $artifact )
			|| 'quality_claimed' !== (string) ( $job['status'] ?? '' )
			|| 'quality' !== (string) ( $run['role'] ?? '' ) || 'running' !== (string) ( $run['status'] ?? '' )
			|| $run_id !== (string) ( $job['active_run_id'] ?? '' ) || $run_id !== (string) ( $claim['run_id'] ?? '' )
			|| $artifact_revision !== (string) ( $job['artifact_revision'] ?? '' ) || $artifact_revision !== (string) ( $artifact['artifact_revision'] ?? '' )
			|| $host_identity !== (string) ( $parts['host_identity'] ?? '' )
			|| $expires <= time() || $expires !== strtotime( (string) ( $claim['expires_at'] ?? '' ) )
			|| '' === $expected_token || ! hash_equals( $expected_token, (string) $parts['token'] )
		) {
			return self::source_rewrite_error( 'source_rewrite_preview_expired_or_denied', 'The staged preview capability is expired or no longer owns the active Quality claim.' );
		}
		return array( 'success' => true, 'job' => $job, 'run' => $run, 'claim' => $claim, 'artifact' => $artifact, 'preview_identity' => hash( 'sha256', (string) $parts['token'] ), 'preview_host_id' => absint( $job['source_id'] ?? 0 ), 'preview_host_scope' => 'canonical_source_theme_shell' );
	}

	private static function source_rewrite_preview_request_matches( array $authority, $query = null, ?array $resolved_posts = null ): bool {
		$expected_id = absint( $authority['job']['source_id'] ?? 0 );
		return self::staged_preview_request_matches_id( $expected_id, $query, $resolved_posts );
	}

	/** @param array<int,mixed> $posts @return array<int,mixed> */
	public static function filter_source_rewrite_preview_posts( array $posts, $query ): array {
		if ( ! self::staged_preview_query_owns_namespace( $query, 'devenia_source_rewrite_preview' ) ) {
			return $posts;
		}
		$token = self::staged_preview_query_token( $query, 'devenia_source_rewrite_preview' );
		if ( '' === $token ) {
			return $posts;
		}
		$authority = self::source_rewrite_preview_authority( $token );
		if ( empty( $authority['success'] ) || ! self::source_rewrite_preview_request_matches( $authority, $query, $posts ) ) {
			return $posts;
		}
		$source_id = absint( $authority['job']['source_id'] ?? 0 );
		$proposed = (array) ( $authority['artifact']['proposed'] ?? array() );
		$matched = false;
		foreach ( $posts as $index => $post ) {
			if ( $post instanceof WP_Post && $source_id === (int) $post->ID ) {
				$preview = clone $post;
				$preview->post_title = (string) ( $proposed['title'] ?? '' );
				$preview->post_excerpt = (string) ( $proposed['excerpt'] ?? '' );
				$preview->post_content = (string) ( $proposed['content'] ?? '' );
				$posts[ $index ] = $preview;
				$matched = true;
			}
		}
		if ( ! $matched ) {
			$source = get_post( $source_id );
			if ( $source instanceof WP_Post ) {
				$preview = clone $source;
				$preview->post_title = (string) ( $proposed['title'] ?? '' );
				$preview->post_excerpt = (string) ( $proposed['excerpt'] ?? '' );
				$preview->post_content = (string) ( $proposed['content'] ?? '' );
				$preview->post_status = 'publish';
				$posts[] = $preview;
				if ( is_object( $query ) ) {
					$query->is_404 = false;
					$query->is_singular = true;
					$query->is_page = 'page' === (string) $source->post_type;
					$query->is_single = 'post' === (string) $source->post_type;
					$query->post_count = 1;
					$query->found_posts = 1;
				}
			}
		}
		self::staged_preview_prevent_page_cache();
		return $posts;
	}

	public static function apply_source_rewrite_preview_response_policy(): void {
		$token = (string) get_query_var( 'devenia_source_rewrite_preview' );
		if ( '' === $token ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
		header( 'Referrer-Policy: no-referrer', true );
		$authority = self::source_rewrite_preview_authority( $token );
		self::staged_preview_apply_response_policy( ! empty( $authority['success'] ) && self::source_rewrite_preview_request_matches( $authority ) );
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_validate_browser_receipts( $raw, array $job, array $run, array $claim, array $artifact, $source ): array {
		if ( ! $source instanceof WP_Post ) {
			return self::source_rewrite_error( 'source_rewrite_preview_source_missing', 'The canonical source is unavailable for rendered Quality evidence.' );
		}
		$preview = self::source_rewrite_preview_descriptor( $job, $run, $claim, $artifact, $source );
		$required = array( 'desktop:light', 'desktop:dark', 'mobile:light', 'mobile:dark' );
		$seen = array();
		$receipts = array();
		$invalid = array();
		foreach ( (array) $raw as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$viewport = sanitize_key( (string) ( $row['viewport_scheme'] ?? '' ) );
			$scheme = sanitize_key( (string) ( $row['color_scheme'] ?? '' ) );
			$key = $viewport . ':' . $scheme;
			$dimensions = (array) ( $row['viewport'] ?? array() );
			$expected_dimensions = (array) ( $preview['viewports'][ $viewport ] ?? array() );
			$layout_digest = strtolower( sanitize_text_field( (string) ( $row['layout_digest'] ?? '' ) ) );
			$screenshot_digest = strtolower( sanitize_text_field( (string) ( $row['screenshot_digest'] ?? '' ) ) );
			$response_digest = strtolower( sanitize_text_field( (string) ( $row['response_digest'] ?? '' ) ) );
			$document_language = strtolower( sanitize_text_field( (string) ( $row['document_language'] ?? '' ) ) );
			$document_direction = sanitize_key( (string) ( $row['document_direction'] ?? '' ) );
			$expected_language = strtolower( self::html_lang_for_language( self::source_language_code() ) );
			$expected_direction = self::is_rtl_language( self::source_language_code() ) ? 'rtl' : 'ltr';
			$reasons = array();
			if ( ! in_array( $key, $required, true ) || isset( $seen[ $key ] ) ) { $reasons[] = 'viewport_or_scheme'; }
			if ( (string) $artifact['artifact_revision'] !== (string) ( $row['artifact_revision'] ?? '' ) ) { $reasons[] = 'artifact_revision'; }
			if ( (string) $preview['copy_revision'] !== (string) ( $row['copy_revision'] ?? '' ) ) { $reasons[] = 'copy_revision'; }
			if ( (string) $preview['url'] !== esc_url_raw( (string) ( $row['preview_url'] ?? '' ) ) ) { $reasons[] = 'preview_url'; }
			if ( $expected_dimensions !== array( 'width' => absint( $dimensions['width'] ?? 0 ), 'height' => absint( $dimensions['height'] ?? 0 ), 'device_scale_factor' => absint( $dimensions['device_scale_factor'] ?? 0 ) ) ) { $reasons[] = 'viewport_dimensions'; }
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $layout_digest ) ) { $reasons[] = 'layout_digest'; }
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $screenshot_digest ) ) { $reasons[] = 'screenshot_digest'; }
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $response_digest ) ) { $reasons[] = 'response_digest'; }
			if ( '' === $expected_language || $expected_language !== $document_language ) { $reasons[] = 'document_language'; }
			if ( $expected_direction !== $document_direction ) { $reasons[] = 'document_direction'; }
			$checked_at = strtotime( (string) ( $row['checked_at'] ?? '' ) );
			$claimed_at = strtotime( (string) ( $claim['claimed_at'] ?? '' ) );
			if ( $checked_at <= 0 || $checked_at < $claimed_at || $checked_at > min( time() + 60, strtotime( (string) $preview['expires_at'] ) ) ) { $reasons[] = 'checked_at'; }
			if ( $reasons ) { $invalid[ $key ] = $reasons; continue; }
			$seen[ $key ] = true;
			$receipts[] = array(
				'artifact_revision' => (string) $artifact['artifact_revision'], 'copy_revision' => (string) $preview['copy_revision'],
				'preview_identity' => (string) $preview['preview_identity'], 'preview_url' => (string) $preview['url'],
				'preview_host_id' => (int) $preview['preview_host_id'], 'preview_host_scope' => (string) $preview['preview_host_scope'],
				'viewport_scheme' => $viewport, 'viewport' => $expected_dimensions,
				'color_scheme' => $scheme, 'response_digest' => $response_digest, 'document_language' => $document_language, 'document_direction' => $document_direction,
				'layout_digest' => $layout_digest, 'screenshot_digest' => $screenshot_digest, 'checked_at' => sanitize_text_field( (string) $row['checked_at'] ),
				'adapter' => 'fresh_quality_browser', 'policy_revision' => self::source_rewrite_policy_revision(), 'trust' => 'reviewer_attested_exact_staged_preview',
			);
		}
		$missing = array_values( array_diff( $required, array_keys( $seen ) ) );
		return $missing || $invalid
			? self::source_rewrite_error( 'source_rewrite_browser_receipts_incomplete', 'Rendered Source Rewrite Quality requires four exact staged-preview receipts.', array( 'missing' => $missing, 'invalid' => $invalid ) )
			: array(
				'success' => true,
				'receipts' => $receipts,
				'preview_identity' => (string) $preview['preview_identity'],
				'preview_url' => (string) $preview['url'],
				'preview_host_id' => (int) $preview['preview_host_id'],
				'preview_host_scope' => (string) $preview['preview_host_scope'],
			);
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_validate_stored_browser_receipts( array $quality, array $artifact ): array {
		$required = array( 'desktop:light', 'desktop:dark', 'mobile:light', 'mobile:dark' );
		$seen = array();
		$preview_identity = (string) ( $quality['preview_identity'] ?? '' );
		$preview_url = esc_url_raw( (string) ( $quality['preview_url'] ?? '' ) );
		$preview_host_id = absint( $quality['preview_host_id'] ?? 0 );
		$preview_host_scope = sanitize_key( (string) ( $quality['preview_host_scope'] ?? '' ) );
		$decided_at = strtotime( (string) ( $quality['decided_at'] ?? '' ) );
		foreach ( (array) ( $quality['browser_receipts'] ?? array() ) as $receipt ) {
			if ( ! is_array( $receipt ) ) { return self::source_rewrite_error( 'source_rewrite_browser_evidence_invalid', 'Stored rendered Quality evidence contains a malformed receipt.' ); }
			$viewport = sanitize_key( (string) ( $receipt['viewport_scheme'] ?? '' ) );
			$key = $viewport . ':' . sanitize_key( (string) ( $receipt['color_scheme'] ?? '' ) );
			$expected_dimensions = 'desktop' === $viewport
				? array( 'width' => 1140, 'height' => 800, 'device_scale_factor' => 1 )
				: array( 'width' => 390, 'height' => 844, 'device_scale_factor' => 1 );
			$dimensions = (array) ( $receipt['viewport'] ?? array() );
			$checked_at = strtotime( (string) ( $receipt['checked_at'] ?? '' ) );
			if (
				! in_array( $key, $required, true ) || isset( $seen[ $key ] )
				|| (string) ( $artifact['artifact_revision'] ?? '' ) !== (string) ( $receipt['artifact_revision'] ?? '' )
				|| (string) ( $artifact['proposed_copy_surface']['revision'] ?? '' ) !== (string) ( $receipt['copy_revision'] ?? '' )
				|| '' === $preview_identity || ! hash_equals( $preview_identity, (string) ( $receipt['preview_identity'] ?? '' ) )
				|| '' === $preview_url || $preview_url !== esc_url_raw( (string) ( $receipt['preview_url'] ?? '' ) )
				|| $preview_host_id < 1 || $preview_host_id !== absint( $receipt['preview_host_id'] ?? 0 )
				|| '' === $preview_host_scope || $preview_host_scope !== sanitize_key( (string) ( $receipt['preview_host_scope'] ?? '' ) )
				|| $expected_dimensions !== array( 'width' => absint( $dimensions['width'] ?? 0 ), 'height' => absint( $dimensions['height'] ?? 0 ), 'device_scale_factor' => absint( $dimensions['device_scale_factor'] ?? 0 ) )
				|| ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $receipt['layout_digest'] ?? '' ) )
				|| ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $receipt['screenshot_digest'] ?? '' ) )
				|| ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $receipt['response_digest'] ?? '' ) )
				|| ( self::is_rtl_language( self::source_language_code() ) ? 'rtl' : 'ltr' ) !== (string) ( $receipt['document_direction'] ?? '' )
				|| strtolower( self::html_lang_for_language( self::source_language_code() ) ) !== strtolower( (string) ( $receipt['document_language'] ?? '' ) )
				|| 'fresh_quality_browser' !== (string) ( $receipt['adapter'] ?? '' )
				|| self::source_rewrite_policy_revision() !== (string) ( $receipt['policy_revision'] ?? '' )
				|| 'reviewer_attested_exact_staged_preview' !== (string) ( $receipt['trust'] ?? '' )
				|| $checked_at <= 0 || $decided_at <= 0 || $checked_at > $decided_at + 60
			) {
				return self::source_rewrite_error( 'source_rewrite_browser_evidence_invalid', 'Stored rendered Quality evidence is incomplete or no longer bound to the exact staged artifact.' );
			}
			$seen[ $key ] = true;
		}
		return array_diff( $required, array_keys( $seen ) )
			? self::source_rewrite_error( 'source_rewrite_browser_evidence_invalid', 'Stored rendered Quality evidence is missing a required viewport or color scheme.' )
			: array( 'success' => true );
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_validate_preservation_brief( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return self::source_rewrite_error( 'preservation_brief_missing', 'The complete source rewrite preservation brief is required.' );
		}
		$text_fields = array( 'buyer', 'problem', 'desired_result', 'promise', 'offer', 'next_action', 'page_purpose', 'emotional_intent' );
		$list_fields = array( 'proof', 'capabilities', 'boundaries', 'intentional_changes' );
		$brief       = array();
		$errors      = array();
		foreach ( $text_fields as $field ) {
			$value = self::normalize_review_text( (string) ( $raw[ $field ] ?? '' ) );
			if ( strlen( $value ) < 55 || self::source_rewrite_generic_evidence( $value ) ) {
				$errors[] = $field;
			}
			$brief[ $field ] = sanitize_textarea_field( $value );
		}
		foreach ( $list_fields as $field ) {
			$values = self::source_rewrite_string_list( $raw[ $field ] ?? array() );
			if ( count( $values ) < 2 ) {
				$errors[] = $field;
			}
			$brief[ $field ] = $values;
		}
		if ( $errors ) {
			return self::source_rewrite_error( 'preservation_brief_incomplete', 'The writer preservation brief must explain the complete buyer, purpose, emotional movement, commercial argument, capabilities, proof, boundaries, and intentional changes.', array( 'fields' => $errors ) );
		}
		return array( 'success' => true, 'brief' => $brief );
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_validate_quality_evidence( array $input, string $decision ): array {
		$required_kinds = self::source_rewrite_quality_evidence_fields();
		$attestations   = array();
		$errors      = array();
		foreach ( (array) ( $input['reviewer_attestations'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				$errors[] = 'reviewer_attestations';
				continue;
			}
			$kind = sanitize_key( (string) ( $row['kind'] ?? '' ) );
			$observation = self::normalize_review_text( (string) ( $row['observation'] ?? '' ) );
			if (
				! in_array( $kind, $required_kinds, true )
				|| isset( $attestations[ $kind ] )
				|| strlen( $observation ) < 120
				|| self::source_rewrite_generic_evidence( $observation )
			) {
				$errors[] = '' !== $kind ? $kind : 'reviewer_attestations';
				continue;
			}
			$attestations[ $kind ] = array(
				'kind'        => $kind,
				'passed'      => ! empty( $row['passed'] ),
				'observation' => sanitize_textarea_field( $observation ),
			);
		}
		if ( array_diff( $required_kinds, array_keys( $attestations ) ) || array_diff( array_keys( $attestations ), $required_kinds ) ) {
			$errors[] = 'reviewer_attestations';
		}
		if ( 'pass' === $decision ) {
			foreach ( $attestations as $attestation ) {
				if ( empty( $attestation['passed'] ) ) {
					return self::source_rewrite_error( 'quality_reviewer_attestation_failed', 'A passing Source Rewrite Quality Decision requires every mandatory semantic and information-architecture attestation to pass.', array( 'kind' => (string) $attestation['kind'] ) );
				}
			}
		}
		$reviewed_sections = self::source_rewrite_string_list( $input['reviewed_sections'] ?? array() );
		$findings = self::source_rewrite_string_list( $input['findings'] ?? array() );
		if ( count( $reviewed_sections ) < 4 ) {
			$errors[] = 'reviewed_sections';
		}
		if ( count( $findings ) < 2 ) {
			$errors[] = 'findings';
		}
		if ( $errors ) {
			return self::source_rewrite_error( 'quality_evidence_incomplete', 'Source Rewrite Quality requires concrete whole-page semantic, emotional, literary, factual, product-depth, boundary, and action evidence.', array( 'fields' => array_values( array_unique( $errors ) ) ) );
		}
		return array(
			'success' => true,
			'evidence' => array(
				'reviewer_attestations' => $attestations,
				'reviewed_sections' => $reviewed_sections,
				'findings' => $findings,
			),
		);
	}

	/** @return array<int,string> */
	private static function source_rewrite_quality_evidence_fields(): array {
		return array(
			'whole_page_purpose_assessment',
			'emotional_connection_assessment',
			'literary_craft_assessment',
			'buyer_problem_result_assessment',
			'promise_proof_assessment',
			'capability_complexity_assessment',
			'boundaries_assessment',
			'next_action_assessment',
			'natural_non_slop_assessment',
			'rendered_information_architecture_assessment',
		);
	}

	/** @return array<int,string> */
	private static function source_rewrite_preservation_brief_fields(): array {
		return array( 'buyer', 'problem', 'desired_result', 'promise', 'proof', 'offer', 'capabilities', 'boundaries', 'next_action', 'page_purpose', 'emotional_intent', 'intentional_changes' );
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_quality_standard(): array {
		return array(
			'authority' => 'independent_semantic_quality',
			'principles' => array(
				'Judge what the page says, why it exists, and what the words make the reader understand, feel, and want to do.',
				'Preserve factual product depth, proof, boundaries, use cases, and technical complexity without flattening them into generic marketing.',
				'Apply literary craft through concrete language, human voice, rhythm, tension and release, memorable specificity, honest emotional connection, and freedom from cliché.',
				'Apply the Ogilvy whole-page argument: buyer, problem, desired result, promise, proof, offer, and value-led next action.',
				'Judge the rendered information architecture at desktop and mobile widths: design must clarify sequence, relationships, hierarchy, and action; emphasize selectively without nagging; and avoid both text walls and decorative card soup.',
			),
		);
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_role_priming( string $role, array $job = array(), $source = null ): array {
		$context = isset( $job['priming_context'] ) && is_array( $job['priming_context'] )
			? $job['priming_context']
			: self::source_rewrite_priming_context( $source );
		return self::copy_quality_role_priming( $role, $context );
	}

	/**
	 * Pin mutable source facts at discovery so publish and separate live
	 * verification interpret the approved policy against the same snapshot.
	 *
	 * @return array<string,mixed>
	 */
	private static function source_rewrite_priming_context( $source ): array {
		return array(
			'workflow'     => 'source_rewrite',
			'source_id'    => $source instanceof WP_Post ? (int) $source->ID : 0,
			'post_type'    => $source instanceof WP_Post ? (string) $source->post_type : '',
			'source_title' => $source instanceof WP_Post ? (string) $source->post_title : '',
			'language'     => self::source_language_code(),
		);
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_validate_priming_acknowledgement( array $input, string $role, array $job = array(), $source = null ): array {
		$expected = (string) ( self::source_rewrite_role_priming( $role, $job, $source )['priming_revision'] ?? '' );
		$received = self::source_rewrite_clean_id( (string) ( $input['priming_revision'] ?? '' ) );
		if ( '' === $received || ! hash_equals( $expected, $received ) ) {
			return self::source_rewrite_error(
				'source_rewrite_priming_not_acknowledged',
				'The Run must fetch, read, and acknowledge the exact role priming revision before acting.',
				array( 'role' => $role, 'expected_priming_revision' => $expected )
			);
		}
		return array( 'success' => true, 'priming_revision' => $expected );
	}

	/** @return array<int,string> */
	private static function source_rewrite_string_list( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = array( $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$values = array();
		foreach ( $raw as $value ) {
			$value = sanitize_textarea_field( self::normalize_review_text( (string) $value ) );
			if ( strlen( $value ) >= 12 && ! self::source_rewrite_generic_evidence( $value ) ) {
				$values[] = $value;
			}
		}
		return array_values( array_unique( $values ) );
	}

	private static function source_rewrite_generic_evidence( string $value ): bool {
		$value = strtolower( trim( preg_replace( '/\s+/', ' ', $value ) ?: '' ) );
		return in_array( $value, array( '', 'ok', 'looks good', 'all good', 'checked', 'reviewed', 'approved', 'no issues', 'not applicable' ), true );
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_run_principal( array $job, array $claim ): array {
		$material = implode(
			'|',
			array(
				(string) $job['job_id'],
				(string) $job['submission_generation'],
				(string) ( $job['review_cycle'] ?? 0 ),
				self::source_rewrite_policy_revision(),
				(string) $claim['run_id'],
				(string) $claim['role'],
				(string) get_current_user_id(),
				(string) $claim['token_hash'],
			)
		);
		return array(
			'principal_id'      => 'srp_' . substr( hash( 'sha256', $material ), 0, 32 ),
			'job_id'            => (string) $job['job_id'],
			'run_id'            => (string) $claim['run_id'],
			'role'              => (string) $claim['role'],
			'wordpress_user_id' => get_current_user_id(),
			'authority'         => 'server_issued_source_rewrite_claim',
			'coordinator_label' => (string) $claim['coordinator_id'],
			'claim_digest'      => (string) $claim['token_hash'],
			'issued_at'         => (string) $claim['claimed_at'],
			'expires_at'        => (string) $claim['expires_at'],
			'review_cycle'      => (int) ( $job['review_cycle'] ?? 0 ),
		);
	}

	private static function source_rewrite_job_id( int $source_id, string $baseline_revision, int $retry_cycle = 1 ): string {
		$material = $source_id . '|' . $baseline_revision . '|' . self::source_rewrite_policy_revision();
		if ( $retry_cycle > 1 ) {
			$material .= '|retry-cycle:' . $retry_cycle;
		}
		return 'srj_' . substr( hash( 'sha256', $material ), 0, 40 );
	}
	private static function source_rewrite_policy_revision(): string { return 'source-rewrite-quality-v4-rendered-preview'; }
	private static function source_rewrite_job_key( string $job_id ): string { return 'devenia_workflow_source_rewrite_job_' . self::source_rewrite_clean_id( $job_id ); }
	private static function source_rewrite_claim_key( string $job_id ): string { return 'devenia_workflow_source_rewrite_claim_' . self::source_rewrite_clean_id( $job_id ); }
	private static function source_rewrite_run_key( string $run_id ): string { return 'devenia_workflow_source_rewrite_run_' . self::source_rewrite_clean_id( $run_id ); }
	private static function source_rewrite_artifact_key( string $revision ): string { return 'devenia_workflow_source_rewrite_artifact_' . self::source_rewrite_clean_id( $revision ); }
	private static function source_rewrite_quality_key( string $revision ): string { return 'devenia_workflow_source_rewrite_quality_' . self::source_rewrite_clean_id( $revision ); }
	private static function source_rewrite_publish_lease_key( string $job_id ): string { return 'devenia_workflow_source_rewrite_publish_lease_' . self::source_rewrite_clean_id( $job_id ); }
	private static function source_rewrite_source_transition_lease_key( int $source_id ): string { return 'devenia_workflow_source_rewrite_source_transition_lease_' . absint( $source_id ); }
	private static function source_rewrite_latest_key( int $source_id ): string { return 'devenia_workflow_source_rewrite_latest_' . absint( $source_id ); }
	private static function source_rewrite_clean_id( string $value ): string { return substr( sanitize_key( $value ), 0, 96 ); }

	/** @return array<string,mixed> */
	private static function source_rewrite_public_job( array $job ): array {
		return array(
			'job_id'                              => (string) ( $job['job_id'] ?? '' ),
			'source_id'                           => absint( $job['source_id'] ?? 0 ),
			'post_type'                           => sanitize_key( (string) ( $job['post_type'] ?? '' ) ),
			'baseline_source_hash'                => (string) ( $job['baseline_source_hash'] ?? '' ),
			'baseline_publication_surface_revision'=> (string) ( $job['baseline_publication_surface_revision'] ?? '' ),
			'retry_cycle'                         => max( 1, absint( $job['retry_cycle'] ?? 1 ) ),
			'supersedes_job_id'                    => (string) ( $job['supersedes_job_id'] ?? '' ),
			'submission_generation'               => absint( $job['submission_generation'] ?? 1 ),
			'review_cycle'                         => absint( $job['review_cycle'] ?? 0 ),
			'status'                              => sanitize_key( (string) ( $job['status'] ?? '' ) ),
			'artifact_revision'                   => (string) ( $job['artifact_revision'] ?? '' ),
			'quality_revision'                    => (string) ( $job['quality_revision'] ?? '' ),
			'last_publish_failure'                => is_array( $job['last_publish_failure'] ?? null ) ? $job['last_publish_failure'] : null,
			'applied_source_hash'                 => (string) ( $job['applied_source_hash'] ?? '' ),
			'applied_publication_surface_revision'=> (string) ( $job['applied_publication_surface_revision'] ?? '' ),
			'applied_copy_revision'               => (string) ( $job['applied_copy_revision'] ?? '' ),
			'published_at'                        => (string) ( $job['published_at'] ?? '' ),
			'live_verification_passed'            => array_key_exists( 'live_verification_passed', $job ) ? $job['live_verification_passed'] : null,
			'quality_recheck'                     => is_array( $job['quality_recheck'] ?? null ) ? $job['quality_recheck'] : null,
			'quality_recheck_history'             => is_array( $job['quality_recheck_history'] ?? null ) ? $job['quality_recheck_history'] : array(),
			'verified_at'                         => (string) ( $job['verified_at'] ?? '' ),
			'active_run_id'                       => (string) ( $job['active_run_id'] ?? '' ),
			'run_ids'                             => is_array( $job['run_ids'] ?? null ) ? $job['run_ids'] : array(),
			'created_at'                          => (string) ( $job['created_at'] ?? '' ),
			'updated_at'                          => (string) ( $job['updated_at'] ?? '' ),
		);
	}

	/** @return array<string,mixed> */
	private static function source_rewrite_public_claim( array $claim ): array {
		$public = $claim;
		unset( $public['token_hash'] );
		return $public;
	}

	/** @param array<string,mixed> $data @return array<string,mixed> */
	private static function source_rewrite_error( string $code, string $message, array $data = array() ): array {
		return array_merge( array( 'success' => false, 'code' => $code, 'message' => $message ), $data );
	}
}
