<?php
/**
 * Atomic localized presentation publication and menu identity.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Localized_Presentation_Publication {
	/**
	 * Resolve the effective route represented by one immutable staged manifest.
	 *
	 * New page artifacts staged before 0.1.661 contain the resolved parent and
	 * slug but an empty localized path. Those signed inputs have exactly one
	 * WordPress route while their parent identity/path still agree. Deriving that
	 * path here preserves the approved manifest and does not grant URL-migration
	 * authority. Existing translations, posts, canonical-route manifests, and
	 * future artifacts with an explicit path are returned byte-for-byte unchanged.
	 *
	 * @return array<string,mixed>
	 */
	private static function translation_job_effective_staged_route_surface( WP_Post $source, string $language, array $route ): array {
		if (
			'page' !== (string) $source->post_type
			|| '' !== trim( (string) ( $route['localized_path'] ?? '' ) )
			|| absint( $route['translation_id'] ?? 0 ) > 0
			|| array_key_exists( 'canonical_route', $route )
		) {
			return array( 'success' => true, 'route' => $route, 'compatibility_derived' => false );
		}

		$raw_slug = (string) ( $route['post_name'] ?? $route['localized_slug'] ?? '' );
		$slug = sanitize_title( $raw_slug );
		$parent_id = absint( $route['post_parent'] ?? $route['localized_parent_id'] ?? 0 );
		$parent_path = self::normalize_localized_parent_path( (string) ( $route['localized_parent_path'] ?? '' ), $language );
		if ( '' === $slug || $raw_slug !== $slug || self::has_wordpress_duplicate_slug_suffix( $slug ) ) {
			return array( 'success' => false, 'code' => 'legacy_staged_page_route_unresolvable', 'message' => 'The legacy staged page route does not contain one valid signed slug.', 'mutation_started' => false );
		}

		$parent_authority = self::authoritative_source_parent_for_translation( $source, $language, $parent_id, $parent_path );
		if ( empty( $parent_authority['success'] ) ) {
			return array_merge( $parent_authority, array( 'mutation_started' => false ) );
		}
		$authoritative_parent_id = absint( $parent_authority['parent_id'] ?? $parent_id );
		if ( $authoritative_parent_id !== $parent_id ) {
			return array( 'success' => false, 'code' => 'legacy_staged_page_parent_changed', 'message' => 'The signed legacy staged parent no longer matches the authoritative translated source parent.', 'mutation_started' => false, 'signed_parent_id' => $parent_id, 'authoritative_parent_id' => $authoritative_parent_id );
		}
		if ( $parent_id ) {
			$parent = get_post( $parent_id );
			$parent_language = sanitize_key( (string) get_post_meta( $parent_id, self::META_LANGUAGE, true ) );
			$observed_parent_path = self::localized_parent_path_for_post( $parent_id, $language );
			if (
				! $parent instanceof WP_Post
				|| 'page' !== (string) $parent->post_type
				|| sanitize_key( $language ) !== $parent_language
				|| ( '' !== $parent_path && $parent_path !== $observed_parent_path )
			) {
				return array( 'success' => false, 'code' => 'legacy_staged_page_parent_route_changed', 'message' => 'The signed legacy staged parent route no longer matches WordPress.', 'mutation_started' => false, 'signed_parent_id' => $parent_id, 'signed_parent_path' => $parent_path, 'observed_parent_path' => $observed_parent_path );
			}
			if ( '' === $parent_path ) {
				$route['localized_parent_path'] = $observed_parent_path;
			}
		} elseif ( '' !== $parent_path ) {
			return array( 'success' => false, 'code' => 'legacy_staged_page_parent_missing', 'message' => 'The legacy staged page route names a parent path without a signed parent identity.', 'mutation_started' => false, 'signed_parent_path' => $parent_path );
		}

		$derived_path = self::expected_localized_path_for_new_page( $parent_id, $slug, $language );
		if ( '' === $derived_path ) {
			return array( 'success' => false, 'code' => 'legacy_staged_page_path_unresolvable', 'message' => 'The legacy staged page route could not be derived from its signed parent and slug.', 'mutation_started' => false );
		}
		$route['localized_path'] = $derived_path;

		return array( 'success' => true, 'route' => $route, 'compatibility_derived' => true, 'derived_path' => $derived_path );
	}

	/**
	 * Issue one opaque activation capability for the exact raw stored pending
	 * manifest. Callers cannot substitute an item revision because the
	 * receipt also binds every ephemeral authority and relation receipt.
	 */
	private static function public_header_activation_receipt( array $manifest ): string {
		$payload = maybe_serialize( $manifest );
		if ( '' === $payload ) { return ''; }
		return 'phact_' . substr( hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) ), 0, 48 );
	}

	/** Validate one caller-owned receipt against the current exact pending state. */
	private static function validate_public_header_activation_receipt( string $activation_receipt ): array {
		if ( '' === $activation_receipt ) {
			return array( 'success' => false, 'code' => 'public_header_activation_receipt_missing', 'message' => 'Activation requires the receipt returned by the exact pending Public Header staging operation.' );
		}
		if ( 1 !== preg_match( '/^phact_[a-f0-9]{48}$/', $activation_receipt ) ) {
			return array( 'success' => false, 'code' => 'public_header_activation_receipt_invalid', 'message' => 'The Public Header activation receipt is malformed.' );
		}
		$missing = '__devenia_workflow_option_missing__';
		$raw_pending = get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $missing );
		if ( $missing === $raw_pending ) {
			return array( 'success' => false, 'code' => 'public_header_pending_manifest_missing', 'message' => 'The receipt has no current pending Public Header manifest to activate.' );
		}
		$pending = self::normalize_public_header_manifest( $raw_pending );
		if ( empty( $pending ) ) {
			return array( 'success' => false, 'code' => 'public_header_pending_manifest_invalid', 'message' => 'The current raw Public Header pending value is not a complete canonical manifest.' );
		}
		$expected = is_array( $raw_pending ) ? self::public_header_activation_receipt( $raw_pending ) : '';
		if ( '' === $expected || ! hash_equals( $expected, $activation_receipt ) ) {
			return array( 'success' => false, 'code' => 'public_header_activation_receipt_mismatch', 'message' => 'The receipt does not own the current exact pending Public Header manifest.' );
		}
		if ( $raw_pending !== $pending ) {
			return array( 'success' => false, 'code' => 'public_header_pending_manifest_not_canonical', 'message' => 'The receipt-bound raw Public Header pending value is not stored in canonical form.' );
		}
		return array( 'success' => true, 'code' => 'public_header_activation_receipt_valid', 'manifest' => $pending, 'raw_manifest' => $raw_pending );
	}

	/** Return the durable idle value which owns the transition lock row. */
	private static function public_header_idle_transition(): array {
		return array( 'schema_version' => 1, 'phase' => 'idle' );
	}

	/** Whether one durable transition still owns unfinished reader authority. */
	private static function public_header_transition_is_nonterminal( array $transition ): bool {
		return ! in_array( (string) ( $transition['phase'] ?? '' ), array( 'idle', 'forward_verified', 'rolled_back_verified', 'critical_conflict' ), true );
	}

	/** Resolve a durable transition by the original crash-safe activation receipt. */
	private static function public_header_transition_for_activation_receipt( string $activation_receipt ): array {
		self::ensure_public_header_transition_option();
		wp_cache_delete( self::OPTION_PUBLIC_HEADER_TRANSITION, 'options' );
		$transition = get_option( self::OPTION_PUBLIC_HEADER_TRANSITION, self::public_header_idle_transition() );
		if ( ! is_array( $transition ) || 'idle' === (string) ( $transition['phase'] ?? '' ) ) { return array( 'success' => false, 'matching' => false ); }
		$authority = isset( $transition['authority'] ) && is_array( $transition['authority'] ) ? $transition['authority'] : array();
		$expected_revision = 'phtr_' . substr( hash( 'sha256', maybe_serialize( $authority ) ), 0, 48 );
		$matching = '' !== $activation_receipt
			&& hash_equals( (string) ( $authority['activation_receipt'] ?? '' ), $activation_receipt )
			&& hash_equals( (string) ( $transition['authority_revision'] ?? '' ), $expected_revision );
		return array( 'success' => $matching, 'matching' => $matching, 'transition' => $transition );
	}

	/** A new explicit staging call may release only a terminal transition record. */
	private static function reset_terminal_public_header_transition(): array {
		self::ensure_public_header_transition_option();
		wp_cache_delete( self::OPTION_PUBLIC_HEADER_TRANSITION, 'options' );
		$current = get_option( self::OPTION_PUBLIC_HEADER_TRANSITION, self::public_header_idle_transition() );
		if ( ! is_array( $current ) || self::public_header_transition_is_nonterminal( $current ) ) { return array( 'success' => false, 'code' => 'public_header_transition_in_progress' ); }
		if ( 'idle' === (string) ( $current['phase'] ?? '' ) ) { return array( 'success' => true, 'unchanged' => true ); }
		return self::replace_public_header_transition( $current, self::public_header_idle_transition() );
	}

	/** Build the exact crash-safe transition authority before reader activation. */
	private static function build_public_header_transition( array $pending, array $before, array $activated, array $staged, string $activation_receipt, array $pre_enrollment_recovery = array() ): array {
		$languages = self::configured_public_header_languages();
		$forward_navigation = array();
		$rollback_navigation = array();
		$candidates = array();
		$rollback_projections = array();
		$before_manifest = self::normalize_public_header_manifest( $before['manifest'] ?? array() );
		$first_enrollment = empty( $before_manifest );
		foreach ( $languages as $language ) {
			$projection = isset( $staged[ $language ] ) && is_array( $staged[ $language ] ) ? $staged[ $language ] : array();
			$menu_id = absint( $projection['target_menu']['id'] ?? 0 );
			$menu_receipt = (string) ( $projection['menu_surface_revision'] ?? '' );
			$navigation = $menu_id > 0 ? self::public_header_navigation_snapshot_from_menu( $menu_id ) : array();
			if ( $menu_id < 1 || '' === $menu_receipt || empty( $navigation ) ) {
				return array( 'success' => false, 'code' => 'public_header_transition_candidate_invalid', 'language' => $language );
			}
			$candidates[ $language ] = array( 'menu_id' => $menu_id, 'menu_surface_revision' => $menu_receipt, 'manifest_revision' => (string) ( $pending['revision'] ?? '' ) );
			$forward_navigation[ $language ] = $navigation;
			if ( ! $first_enrollment ) {
				$old_menu_id = absint( $projection['previous_menu_id'] ?? 0 );
				$old_receipt = (string) ( $projection['previous_menu_surface_revision'] ?? '' );
				$old_navigation = $old_menu_id > 0 ? self::public_header_navigation_snapshot_from_menu( $old_menu_id ) : array();
				if ( $old_menu_id < 1 || '' === $old_receipt || empty( $old_navigation ) ) {
					return array( 'success' => false, 'code' => 'public_header_transition_rollback_invalid', 'language' => $language );
				}
				$rollback_projections[ $language ] = array( 'target_menu' => array( 'id' => $old_menu_id ), 'menu_surface_revision' => $old_receipt, 'manifest_revision' => (string) ( $before_manifest['revision'] ?? '' ) );
				$rollback_navigation[ $language ] = $old_navigation;
			}
		}
		$rollback_target = $before;
		$rollback_kind = 'managed';
		if ( $first_enrollment ) {
			$pre_state = isset( $pre_enrollment_recovery['pre_state'] ) && is_array( $pre_enrollment_recovery['pre_state'] ) ? $pre_enrollment_recovery['pre_state'] : array();
			$rollback_navigation = isset( $pre_enrollment_recovery['expected_navigation'] ) && is_array( $pre_enrollment_recovery['expected_navigation'] ) ? $pre_enrollment_recovery['expected_navigation'] : array();
			if ( array( 'manifest', 'identities', 'pending', 'enrollment' ) !== array_keys( $pre_state ) || count( $rollback_navigation ) !== count( $languages ) ) {
				return array( 'success' => false, 'code' => 'public_header_transition_pre_enrollment_recovery_invalid' );
			}
			$rollback_target = $pre_state;
			$rollback_kind = 'pre_enrollment';
		}
		$language_snapshot = self::languages( true );
		$authority = array(
			'activation_receipt'      => $activation_receipt,
			'manifest_revision'       => (string) ( $pending['revision'] ?? '' ),
			'languages'               => $languages,
			'language_registry_revision' => hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $language_snapshot ) ) ?: '' ),
			'before_state'            => $before,
			'activated_state'         => $activated,
			'rollback_target_state'   => $rollback_target,
			'rollback_kind'           => $rollback_kind,
			'candidates'              => $candidates,
			'rollback_projections'    => $rollback_projections,
			'expected_navigation'     => array( 'forward' => $forward_navigation, 'rollback' => $rollback_navigation ),
			'purge_urls'              => self::public_header_projection_urls( $languages ),
		);
		$authority_revision = 'phtr_' . substr( hash( 'sha256', maybe_serialize( $authority ) ), 0, 48 );
		return array(
			'success'            => true,
			'schema_version'     => 1,
			'phase'              => 'forward_invalidation_pending',
			'authority_revision' => $authority_revision,
			'authority'          => $authority,
			'evidence'           => array( 'forward' => array(), 'rollback' => array() ),
			'lease'              => array(),
			'outcome'            => array(),
			'updated_at'         => gmdate( 'c' ),
		);
	}

	/** CAS one exact durable transition revision outside a reader-state mutation. */
	private static function replace_public_header_transition( array $expected, array $replacement ): array {
		$written = self::atomic_replace_option_value( self::OPTION_PUBLIC_HEADER_TRANSITION, $expected, $replacement );
		wp_cache_delete( self::OPTION_PUBLIC_HEADER_TRANSITION, 'options' );
		$current = get_option( self::OPTION_PUBLIC_HEADER_TRANSITION, '__devenia_workflow_option_missing__' );
		$exact = self::translation_job_canonicalize( $current ) === self::translation_job_canonicalize( $replacement );
		return array( 'success' => $written && $exact, 'code' => $written && $exact ? 'public_header_transition_replaced' : 'public_header_transition_changed', 'current' => $current );
	}

	/**
	 * Apply one exact receipt-owned complete Public Header set and return its
	 * durable verification-pending transition. The root coordinator resumes the
	 * same Interface through one bounded verification call per language; callers
	 * never coordinate raw menu writes or cache ordering themselves.
	 *
	 * @param array<string,mixed> $input Projection arguments.
	 * @return array<string,mixed>
	 */
	private static function activate_public_header_projection( array $input ): array {
		$missing = '__devenia_workflow_option_missing__';
		$activation_receipt = sanitize_text_field( (string) ( $input['activation_receipt'] ?? '' ) );
		$existing = self::public_header_transition_for_activation_receipt( $activation_receipt );
		if ( ! empty( $existing['matching'] ) ) {
			$transition = (array) $existing['transition'];
			$phase = (string) ( $transition['phase'] ?? '' );
			$forward_verified = 'forward_verified' === $phase;
			$rolled_back = 'rolled_back_verified' === $phase;
			$activation_applied = ! in_array( $phase, array( 'rolled_back_verified', 'rollback_invalidation_pending', 'rollback_verifying', 'rollback_cleanup_pending', 'critical_conflict' ), true );
			return array( 'success' => $activation_applied, 'code' => $phase, 'activation_applied' => $activation_applied, 'finalized' => $forward_verified || $rolled_back, 'verification_pending' => ! in_array( $phase, array( 'forward_verified', 'rolled_back_verified', 'critical_conflict' ), true ), 'needs_live_verification' => self::public_header_transition_is_nonterminal( $transition ), 'transition' => $transition, 'idempotent' => true );
		}
		$receipt_validation = self::validate_public_header_activation_receipt( $activation_receipt );
		if ( empty( $receipt_validation['success'] ) ) { return $receipt_validation; }
		$pending = (array) $receipt_validation['manifest'];
		$first_enrollment = ! self::public_header_projection_is_enrolled() && empty( self::public_header_manifest() );
		if ( ! $first_enrollment ) { return self::activate_public_header_projection_core( $input ); }

		$staged_state = array(
			'manifest'   => get_option( self::OPTION_PUBLIC_HEADER_MANIFEST, $missing ),
			'identities' => get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, $missing ),
			'pending'    => get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $missing ),
			'enrollment' => get_option( self::OPTION_PUBLIC_HEADER_ENROLLMENT, $missing ),
		);
		$source_language = self::source_language_code();
		$source_receipt = isset( $pending['authority_receipts'][ $source_language ] ) && is_array( $pending['authority_receipts'][ $source_language ] ) ? $pending['authority_receipts'][ $source_language ] : array();
		$source_candidate = isset( $source_receipt['candidates'][0] ) && is_array( $source_receipt['candidates'][0] ) ? $source_receipt['candidates'][0] : array();
		$recovery = isset( $pending['pre_enrollment_recovery'] ) && is_array( $pending['pre_enrollment_recovery'] ) ? $pending['pre_enrollment_recovery'] : array();
		$pre_state = isset( $recovery['pre_state'] ) && is_array( $recovery['pre_state'] ) ? $recovery['pre_state'] : array();
		$expected_state_keys = array( 'manifest', 'identities', 'pending', 'enrollment' );
		$expected_staged_state = $pre_state;
		$expected_staged_state['pending'] = $pending;
		$recovery_revision = 'pher_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( array( absint( $recovery['source_menu_id'] ?? 0 ), (string) ( $recovery['source_receipt'] ?? '' ), (array) ( $recovery['authority'] ?? array() ), (array) ( $recovery['expected_navigation'] ?? array() ), $pre_state ) ) ) ?: '' ), 0, 40 );
		if (
			empty( $recovery['success'] )
			|| $expected_state_keys !== array_keys( $pre_state )
			|| $missing !== ( $pre_state['pending'] ?? null )
			|| self::translation_job_canonicalize( $staged_state ) !== self::translation_job_canonicalize( $expected_staged_state )
			|| '' === (string) ( $recovery['revision'] ?? '' )
			|| ! hash_equals( (string) $recovery['revision'], $recovery_revision )
			|| absint( $source_candidate['menu_id'] ?? 0 ) !== absint( $recovery['source_menu_id'] ?? 0 )
			|| ! hash_equals( (string) ( $source_candidate['surface_revision'] ?? '' ), (string) ( $recovery['source_receipt'] ?? '' ) )
			|| count( (array) ( $recovery['expected_navigation'] ?? array() ) ) !== count( self::configured_public_header_languages() )
		) {
			return array( 'success' => false, 'code' => 'public_header_pre_enrollment_recovery_receipt_invalid' );
		}
		$input['pre_enrollment_recovery'] = $recovery;
		$input['cleanup_state_authority'] = self::public_header_staged_cleanup_state_authority( $staged_state, $missing === ( $pre_state['identities'] ?? null ) );
		$result = self::activate_public_header_projection_core( $input );
		if ( ! empty( $result['success'] ) || ! empty( $result['activation_applied'] ) ) { return $result; }

		do_action( 'devenia_workflow_public_header_enrollment_before_intake_restore', $result, array( 'expected_state' => $staged_state ) );
		$current = array(
			'manifest'   => get_option( self::OPTION_PUBLIC_HEADER_MANIFEST, $missing ),
			'identities' => get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, $missing ),
			'pending'    => get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $missing ),
			'enrollment' => get_option( self::OPTION_PUBLIC_HEADER_ENROLLMENT, $missing ),
		);
		$current_is_pre_state = self::translation_job_canonicalize( $current ) === self::translation_job_canonicalize( $pre_state );
		$current_is_staged = self::translation_job_canonicalize( $current ) === self::translation_job_canonicalize( $staged_state );
		$activation_severe = 'critical' === (string) ( $result['severity'] ?? '' ) || 'public_header_projection_severe_rollback_failure' === (string) ( $result['code'] ?? '' );
		$restored = $current_is_pre_state
			? array( 'success' => true, 'code' => 'public_header_enrollment_pre_state_already_safe' )
			: ( $current_is_staged && ! $activation_severe
				? self::replace_public_header_state_transaction( $staged_state, $pre_state, array() )
				: array( 'success' => false, 'code' => $activation_severe ? 'public_header_enrollment_severe_rollback_not_bypassed' : 'public_header_enrollment_intake_restore_conflict' ) );
		$after_restore = array(
			'manifest'   => get_option( self::OPTION_PUBLIC_HEADER_MANIFEST, $missing ),
			'identities' => get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, $missing ),
			'pending'    => get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $missing ),
			'enrollment' => get_option( self::OPTION_PUBLIC_HEADER_ENROLLMENT, $missing ),
		);
		$exact = ! empty( $restored['success'] ) && self::translation_job_canonicalize( $pre_state ) === self::translation_job_canonicalize( $after_restore );
		$core_cleanup = isset( $result['cleanup'] ) && is_array( $result['cleanup'] ) ? $result['cleanup'] : array();
		$projections = (array) ( $result['projections'] ?? array() );
		$cleanup_safe = $exact || ( $current_is_staged && self::public_header_state_excludes_staged_menu_ids( $after_restore, $projections, true ) );
		$cleanup_authority = self::public_header_staged_cleanup_state_authority( $after_restore, $exact || $current_is_staged );
		$cleanup = ! empty( $core_cleanup['success'] )
			? $core_cleanup
			: ( $cleanup_safe && ! empty( $projections ) ? self::delete_staged_public_header_projections( $projections, $cleanup_authority ) : array( 'success' => $cleanup_safe && empty( $projections ), 'code' => $cleanup_safe ? 'no_staged_projections_remain' : 'cleanup_blocked_by_foreign_staged_menu_reference', 'results' => array() ) );
		// The core returned activation_applied=false, so no reader-visible state
		// changed. Exact state restoration plus candidate cleanup is terminal;
		// cache invalidation and live verification belong only to a durable
		// applied transition resumed through the explicit verification Interface.
		$invalidation = array( 'success' => true, 'skipped' => true, 'code' => 'public_header_reader_state_unchanged' );
		$verification = array( 'success' => true, 'passed' => true, 'skipped' => true, 'code' => 'public_header_reader_state_unchanged' );
		$recovered = $exact && ! empty( $cleanup['success'] );
		$result['first_enrollment_restore'] = array( 'success' => $recovered, 'transaction' => $restored, 'current_is_pre_state' => $current_is_pre_state, 'current_is_staged' => $current_is_staged, 'activation_severe' => $activation_severe, 'expected' => $pre_state, 'expected_staging' => $staged_state, 'actual' => $after_restore, 'cleanup' => $cleanup, 'cache_invalidation' => $invalidation, 'verification' => $verification );
		if ( ! $recovered ) { $result['failed_code'] = (string) ( $result['code'] ?? '' ); $result['code'] = 'public_header_enrollment_restore_failed'; $result['severity'] = 'critical'; }
		return $result;
	}

	/** Execute the receipt-bound complete-set activation after the outer Interface has classified first-enrollment recovery. */
	private static function activate_public_header_projection_core( array $input ): array {
		$cleanup_state_authority = isset( $input['cleanup_state_authority'] ) && is_array( $input['cleanup_state_authority'] ) ? $input['cleanup_state_authority'] : array();
		$activation_receipt = sanitize_text_field( (string) ( $input['activation_receipt'] ?? '' ) );
		$receipt_validation = self::validate_public_header_activation_receipt( $activation_receipt );
		if ( empty( $receipt_validation['success'] ) ) { return $receipt_validation; }
		$pending = (array) $receipt_validation['manifest'];
		$relation_validation = self::validate_public_header_relation_receipts( $pending );
		if ( empty( $relation_validation['success'] ) ) { return array_merge( $relation_validation, array( 'success' => false, 'message' => 'The pending Public Header relation authority is absent, malformed, or stale.' ) ); }
		$authority_validation = self::validate_public_header_authority_receipts( $pending );
		if ( empty( $authority_validation['success'] ) ) { return array_merge( $authority_validation, array( 'success' => false, 'message' => 'The pending Public Header authority snapshot changed before projection staging.' ) ); }
		do_action( 'devenia_workflow_public_header_before_activation_receipt_revalidation', $activation_receipt, $pending );
		$receipt_revalidation = self::validate_public_header_activation_receipt( $activation_receipt );
		if ( empty( $receipt_revalidation['success'] ) ) { return array_merge( $receipt_revalidation, array( 'success' => false, 'message' => 'The pending Public Header manifest changed before projection staging.' ) ); }
		if ( $pending !== (array) $receipt_revalidation['manifest'] ) {
			return array( 'success' => false, 'code' => 'public_header_activation_receipt_manifest_changed', 'message' => 'The pending Public Header manifest changed before projection staging.' );
		}
		$languages = self::configured_public_header_languages();
		if ( empty( $languages ) ) {
			return array( 'success' => false, 'code' => 'public_header_source_language_invalid', 'message' => 'Exactly one configured source language is required before Public Header Projection can run.' );
		}
		$staged    = array();
		foreach ( $languages as $language ) {
			$projection = self::stage_language_menu_projection(
				array(
					'language'             => $language,
					'include_untranslated' => false,
					'include_custom_links' => true,
					'manifest'             => $pending,
				)
			);
			if ( empty( $projection['success'] ) || empty( $projection['validation']['passed'] ) || '' === (string) ( $projection['menu_surface_revision'] ?? '' ) || ! empty( $projection['skipped'] ) || count( (array) ( $projection['added'] ?? array() ) ) !== count( (array) $pending['items'] ) ) {
				$cleanup = self::delete_staged_public_header_projections( $staged, $cleanup_state_authority );
				return empty( $cleanup['success'] )
					? array( 'success' => false, 'code' => 'public_header_projection_staging_cleanup_failed', 'severity' => 'critical', 'failed_language' => $language, 'projection' => $projection, 'projections' => $staged, 'cleanup' => $cleanup )
					: array( 'success' => false, 'code' => 'public_header_projection_staging_failed', 'message' => 'Every configured source and target projection must stage, validate, and produce a recovery receipt before activation.', 'failed_language' => $language, 'projection' => $projection, 'cleanup' => $cleanup );
			}
			$staged[ $language ] = $projection;
		}
		do_action( 'devenia_workflow_public_header_relation_before_final_revalidation', $pending, $staged );
		$final_relation_validation = self::validate_public_header_relation_receipts( $pending );
		if ( empty( $final_relation_validation['success'] ) ) {
			$cleanup = self::delete_staged_public_header_projections( $staged, $cleanup_state_authority );
			return empty( $cleanup['success'] )
				? array( 'success' => false, 'code' => 'public_header_relation_changed_cleanup_failed', 'severity' => 'critical', 'relation_validation' => $final_relation_validation, 'projections' => $staged, 'cleanup' => $cleanup )
				: array( 'success' => false, 'code' => 'public_header_relation_changed_before_activation', 'relation_validation' => $final_relation_validation, 'projections' => $staged, 'cleanup' => $cleanup );
		}
		if ( ! empty( $authority_validation['present'] ) ) {
			do_action( 'devenia_workflow_public_header_authority_before_final_revalidation', $pending, $staged );
			$final_authority_validation = self::validate_public_header_authority_receipts( $pending );
			if ( empty( $final_authority_validation['success'] ) ) {
				$cleanup = self::delete_staged_public_header_projections( $staged, $cleanup_state_authority );
				return empty( $cleanup['success'] )
					? array( 'success' => false, 'code' => 'public_header_authority_changed_cleanup_failed', 'severity' => 'critical', 'authority_validation' => $final_authority_validation, 'projections' => $staged, 'cleanup' => $cleanup )
					: array( 'success' => false, 'code' => 'public_header_authority_changed_before_activation', 'authority_validation' => $final_authority_validation, 'projections' => $staged, 'cleanup' => $cleanup );
			}
		}

		$activation = self::activate_public_header_projection_set( $pending, $staged, $activation_receipt );
		if ( empty( $activation['success'] ) ) {
			$cleanup_authority = self::public_header_activation_cleanup_authority( $activation, $staged );
			$cleanup = ! empty( $cleanup_authority['allowed'] ) ? self::delete_staged_public_header_projections( $staged, (array) ( $cleanup_authority['state_authority'] ?? array() ) ) : array( 'success' => false, 'code' => 'staged_projection_cleanup_not_authorized', 'results' => array() );
			if ( empty( $cleanup_authority['allowed'] ) ) { return array( 'success' => false, 'code' => 'public_header_projection_activation_state_unresolved', 'severity' => 'critical', 'activation' => $activation, 'projections' => $staged, 'cleanup_authority' => $cleanup_authority, 'cleanup' => $cleanup ); }
			return empty( $cleanup['success'] )
				? array( 'success' => false, 'code' => 'public_header_projection_activation_cleanup_failed', 'severity' => 'critical', 'activation' => $activation, 'projections' => $staged, 'cleanup' => $cleanup )
				: array( 'success' => false, 'code' => 'public_header_projection_activation_failed', 'message' => 'The complete Public Header Projection set was not activated.', 'activation' => $activation, 'cleanup' => $cleanup );
		}

		$purge_urls = self::public_header_projection_urls( $languages );
		$context = array( 'event' => 'public_header_projection', 'manifest_revision' => (string) $pending['revision'], 'languages' => $languages );
		$invalidation = apply_filters( 'devenia_workflow_frontend_cache_invalidation_result', null, $purge_urls, $context );
		$transition = isset( $activation['transition'] ) && is_array( $activation['transition'] ) ? $activation['transition'] : array();
		if ( ! is_array( $invalidation ) || true !== ( $invalidation['success'] ?? null ) ) {
			return array( 'success' => false, 'code' => 'public_header_cache_invalidation_failed', 'activation_applied' => true, 'finalized' => false, 'verification_pending' => true, 'needs_live_verification' => true, 'manifest_revision' => (string) $pending['revision'], 'languages' => $languages, 'projections' => $staged, 'activation' => $activation, 'purge_urls' => $purge_urls, 'cache_invalidation' => $invalidation, 'transition' => $transition );
		}
		$verifying = $transition;
		$verifying['phase'] = 'forward_verifying';
		$verifying['outcome']['forward_cache_invalidation'] = $invalidation;
		$verifying['updated_at'] = gmdate( 'c' );
		$transition_write = self::replace_public_header_transition( $transition, $verifying );
		if ( empty( $transition_write['success'] ) ) {
			return array( 'success' => false, 'code' => 'public_header_transition_phase_write_failed', 'severity' => 'critical', 'activation_applied' => true, 'finalized' => false, 'verification_pending' => true, 'needs_live_verification' => true, 'activation' => $activation, 'cache_invalidation' => $invalidation, 'transition_write' => $transition_write );
		}
		return array( 'success' => true, 'code' => 'public_header_activation_applied_verification_pending', 'activation_applied' => true, 'finalized' => false, 'verification_pending' => true, 'needs_live_verification' => true, 'manifest_revision' => (string) $pending['revision'], 'languages' => $languages, 'projections' => $staged, 'activation' => $activation, 'purge_urls' => $purge_urls, 'cache_invalidation' => $invalidation, 'transition' => $verifying, 'message' => 'Public Header activation was applied. Call verify-public-header-projection once per configured language to complete the invariant.' );
	}

	/** @return string[] */
	private static function configured_public_header_languages(): array {
		$source = self::source_language_code();
		if ( '' === $source ) {
			return array();
		}
		return array_values( array_unique( array_merge( array( $source ), array_keys( self::target_languages() ) ) ) );
	}

	/** @param string[] $languages @return string[] */
	private static function public_header_projection_urls( array $languages ): array {
		$urls = array();
		foreach ( $languages as $language ) {
			$urls[] = self::localized_home_url_for_language( (string) $language );
			$urls[] = self::public_blog_archive_url_for_language( (string) $language );
		}
		return array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
	}

	/** @param string[] $languages @return array<string,mixed> */
	private static function verify_public_header_projection_set( array $languages, int $timeout, array $expected_navigation = array() ): array {
		$items = array();
		$passed = true;
		$conclusive = true;
		$response_set = self::public_header_frontend_cache_response_set( $languages, $timeout, 1 );
		foreach ( $languages as $language ) {
			foreach ( array( 'homepage' => self::localized_home_url_for_language( (string) $language ), 'blog_archive' => self::public_blog_archive_url_for_language( (string) $language ) ) as $surface => $url ) {
				$item = self::public_header_navigation_integrity_for_responses( (string) $url, (string) $language, $surface, (array) ( $expected_navigation[ (string) $language ] ?? array() ), (array) ( $response_set[ (string) $language ][ $surface ] ?? array() ) );
				$items[ (string) $language ][ $surface ] = $item;
				$passed = $passed && ! empty( $item['passed'] ) && isset( $item['cache_responses']['origin'], $item['cache_responses']['canonical'] );
				$conclusive = $conclusive && ! empty( $item['conclusive'] );
			}
		}
		return array( 'success' => $passed, 'passed' => $passed, 'conclusive' => $conclusive, 'items' => $items );
	}

	/** Verify one Public Header coordinate without importing unrelated page-quality rules. */
	private static function public_header_navigation_integrity_for_responses( string $url, string $language, string $surface, array $expected, array $provided_responses ): array {
		$responses = array();
		$transport_complete = '' !== $url && ! empty( $expected );
		$passed = $transport_complete;
		foreach ( array( 'origin', 'canonical' ) as $cache_surface ) {
			$response = (array) ( $provided_responses[ $cache_surface ] ?? array() );
			$body = (string) ( $response['body'] ?? '' );
			$transport_ok = ! empty( $response['success'] ) && 200 === (int) ( $response['status_code'] ?? 0 ) && '' !== trim( $body );
			$actual = $transport_ok ? self::primary_navigation_from_html( $body, $language ) : array();
			$matches = $transport_ok && $actual === array_values( $expected );
			$transport_complete = $transport_complete && $transport_ok;
			$passed = $passed && $matches;
			$responses[ $cache_surface ] = array_merge( array_diff_key( $response, array( 'body' => true ) ), array( 'transport_complete' => $transport_ok, 'navigation_matches' => $matches, 'actual_navigation' => $actual ) );
		}
		return array( 'success' => $passed, 'passed' => $passed, 'conclusive' => $transport_complete, 'surface' => sanitize_key( $surface ), 'language' => sanitize_key( $language ), 'url' => esc_url_raw( $url ), 'expected_navigation' => array_values( $expected ), 'cache_responses' => $responses, 'checked_at' => gmdate( 'c' ) );
	}

	/** Verify only the receipt-bound raw menu which existed before enrollment. */
	private static function verify_pre_enrollment_public_header_navigation( array $languages, int $timeout, array $expected_navigation ): array {
		$items = array();
		$passed = true;
		$conclusive = true;
		$response_set = self::public_header_frontend_cache_response_set( $languages, $timeout, 1 );
		foreach ( $languages as $language ) {
			$expected = array_values( (array) ( $expected_navigation[ (string) $language ] ?? array() ) );
			foreach ( array( 'homepage' => self::localized_home_url_for_language( (string) $language ), 'blog_archive' => self::public_blog_archive_url_for_language( (string) $language ) ) as $surface => $url ) {
				$item = self::public_header_navigation_integrity_for_responses( (string) $url, (string) $language, $surface, $expected, (array) ( $response_set[ (string) $language ][ $surface ] ?? array() ) );
				$items[ (string) $language ][ $surface ] = $item;
				$passed = $passed && ! empty( $item['passed'] );
				$conclusive = $conclusive && ! empty( $item['conclusive'] );
			}
		}
		return array( 'success' => $passed, 'passed' => $passed, 'conclusive' => $conclusive, 'pre_enrollment' => true, 'items' => $items );
	}

	/** Read the exact four-option Public Header reader state. */
	private static function public_header_state_snapshot(): array {
		$missing = '__devenia_workflow_option_missing__';
		self::clear_public_header_state_option_cache();
		return array(
			'manifest'   => get_option( self::OPTION_PUBLIC_HEADER_MANIFEST, $missing ),
			'identities' => get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, $missing ),
			'pending'    => get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $missing ),
			'enrollment' => get_option( self::OPTION_PUBLIC_HEADER_ENROLLMENT, $missing ),
		);
	}

	/** Validate the durable transition, original activation receipt, registry, and reader state. */
	private static function validate_public_header_transition( string $activation_receipt, string $language ): array {
		self::ensure_public_header_transition_option();
		wp_cache_delete( self::OPTION_PUBLIC_HEADER_TRANSITION, 'options' );
		$transition = get_option( self::OPTION_PUBLIC_HEADER_TRANSITION, self::public_header_idle_transition() );
		if ( ! is_array( $transition ) || 1 !== absint( $transition['schema_version'] ?? 0 ) || 'idle' === (string) ( $transition['phase'] ?? '' ) ) {
			return array( 'success' => false, 'code' => 'public_header_transition_missing' );
		}
		$authority = isset( $transition['authority'] ) && is_array( $transition['authority'] ) ? $transition['authority'] : array();
		$stored_receipt = (string) ( $authority['activation_receipt'] ?? '' );
		$stored_revision = (string) ( $transition['authority_revision'] ?? '' );
		$expected_revision = 'phtr_' . substr( hash( 'sha256', maybe_serialize( $authority ) ), 0, 48 );
		if ( '' === $stored_receipt || ! hash_equals( $stored_receipt, $activation_receipt ) || '' === $stored_revision || ! hash_equals( $stored_revision, $expected_revision ) ) {
			return array( 'success' => false, 'code' => 'public_header_transition_receipt_invalid' );
		}
		$languages = array_values( array_map( 'sanitize_key', (array) ( $authority['languages'] ?? array() ) ) );
		if ( '' === $language || ! in_array( $language, $languages, true ) ) {
			return array( 'success' => false, 'code' => 'public_header_transition_language_invalid', 'languages' => $languages );
		}
		$current_registry_revision = hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( self::languages( true ) ) ) ?: '' );
		if ( '' === (string) ( $authority['language_registry_revision'] ?? '' ) || ! hash_equals( (string) $authority['language_registry_revision'], $current_registry_revision ) ) {
			return array( 'success' => false, 'code' => 'public_header_transition_language_registry_changed' );
		}
		$phase = (string) ( $transition['phase'] ?? '' );
		if ( in_array( $phase, array( 'forward_verified', 'rolled_back_verified', 'critical_conflict' ), true ) ) {
			return array( 'success' => true, 'terminal' => true, 'transition' => $transition, 'phase' => $phase, 'language' => $language );
		}
		if ( ! in_array( $phase, array( 'forward_invalidation_pending', 'forward_verifying', 'rollback_invalidation_pending', 'rollback_verifying', 'rollback_cleanup_pending' ), true ) ) {
			return array( 'success' => false, 'code' => 'public_header_transition_phase_invalid', 'phase' => $phase );
		}
		$current_state = self::public_header_state_snapshot();
		$rollback_phase = 0 === strpos( $phase, 'rollback_' );
		$expected_state = $rollback_phase
			? ( isset( $authority['rollback_target_state'] ) && is_array( $authority['rollback_target_state'] ) ? $authority['rollback_target_state'] : array() )
			: ( isset( $authority['activated_state'] ) && is_array( $authority['activated_state'] ) ? $authority['activated_state'] : array() );
		if ( self::translation_job_canonicalize( $current_state ) !== self::translation_job_canonicalize( $expected_state ) ) {
			return array( 'success' => false, 'code' => 'public_header_transition_reader_state_changed', 'severity' => 'critical' );
		}
		$candidate = isset( $authority['candidates'][ $language ] ) && is_array( $authority['candidates'][ $language ] ) ? $authority['candidates'][ $language ] : array();
		$menu_id = absint( $candidate['menu_id'] ?? 0 );
		$menu_receipt = (string) ( $candidate['menu_surface_revision'] ?? '' );
		$current_receipt = $menu_id > 0 ? self::localized_menu_projection_revision( $menu_id ) : '';
		$cleanup_may_have_committed = 'rollback_cleanup_pending' === $phase && $menu_id > 0 && ! wp_get_nav_menu_object( $menu_id );
		if ( ! $cleanup_may_have_committed && ( $menu_id < 1 || '' === $menu_receipt || '' === $current_receipt || ! hash_equals( $menu_receipt, $current_receipt ) ) ) {
			return array( 'success' => false, 'code' => 'public_header_transition_candidate_changed', 'severity' => 'critical', 'language' => $language );
		}
		return array( 'success' => true, 'terminal' => false, 'transition' => $transition, 'phase' => $phase, 'language' => $language, 'current_state' => $current_state );
	}

	/** Acquire the one in-flight verification lease before any same-site HTTP. */
	private static function acquire_public_header_transition_lease( array $transition ): array {
		$lease = isset( $transition['lease'] ) && is_array( $transition['lease'] ) ? $transition['lease'] : array();
		if ( '' !== (string) ( $lease['lease_token'] ?? '' ) && absint( $lease['lease_expires_at'] ?? 0 ) > time() ) {
			return array( 'success' => false, 'code' => 'public_header_transition_busy', 'lease_expires_at' => absint( $lease['lease_expires_at'] ) );
		}
		$leased = $transition;
		$leased['lease'] = array( 'lease_token' => wp_generate_uuid4(), 'lease_expires_at' => time() + 120 );
		$leased['updated_at'] = gmdate( 'c' );
		$write = self::replace_public_header_transition( $transition, $leased );
		return empty( $write['success'] ) ? array_merge( $write, array( 'success' => false ) ) : array( 'success' => true, 'transition' => $leased, 'lease_token' => (string) $leased['lease']['lease_token'], 'lease_expires_at' => (int) $leased['lease']['lease_expires_at'] );
	}

	/** Whether every captured language has one complete forward/rollback evidence row. */
	private static function public_header_transition_evidence_complete( array $transition, string $direction ): bool {
		$evidence = isset( $transition['evidence'][ $direction ] ) && is_array( $transition['evidence'][ $direction ] ) ? $transition['evidence'][ $direction ] : array();
		foreach ( (array) ( $transition['authority']['languages'] ?? array() ) as $language ) {
			if ( empty( $evidence[ (string) $language ]['passed'] ) ) { return false; }
		}
		return true;
	}

	/** Store body-free evidence with one independent digest. */
	private static function compact_public_header_transition_evidence( array $verification ): array {
		$items = array();
		foreach ( (array) ( $verification['items'] ?? array() ) as $language => $surfaces ) {
			foreach ( (array) $surfaces as $surface => $item ) {
				$items[ (string) $language ][ (string) $surface ] = is_array( $item ) ? $item : array();
			}
		}
		return array( 'passed' => ! empty( $verification['passed'] ), 'items' => $items, 'evidence_digest' => hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $items ) ) ?: '' ), 'observed_at' => gmdate( 'c' ) );
	}

	/** Reconstruct only receipt-bound candidate and rollback menu authority. */
	private static function public_header_transition_staged_projections( array $transition ): array {
		$staged = array();
		$candidates = (array) ( $transition['authority']['candidates'] ?? array() );
		$rollback = (array) ( $transition['authority']['rollback_projections'] ?? array() );
		foreach ( $candidates as $language => $candidate ) {
			if ( ! is_array( $candidate ) ) { continue; }
			$prior = isset( $rollback[ $language ] ) && is_array( $rollback[ $language ] ) ? $rollback[ $language ] : array();
			$staged[ (string) $language ] = array(
				'target_menu'                   => array( 'id' => absint( $candidate['menu_id'] ?? 0 ) ),
				'menu_surface_revision'         => (string) ( $candidate['menu_surface_revision'] ?? '' ),
				'manifest_revision'             => (string) ( $candidate['manifest_revision'] ?? '' ),
				'previous_menu_id'               => absint( $prior['target_menu']['id'] ?? 0 ),
				'previous_menu_surface_revision' => (string) ( $prior['menu_surface_revision'] ?? '' ),
			);
		}
		return $staged;
	}

	/** Validate the complete candidate menu and reader authority before a terminal transition. */
	private static function validate_public_header_transition_candidate_set( array $transition, string $direction, bool $require_candidates = true ): array {
		$authority = (array) ( $transition['authority'] ?? array() );
		$expected_state = 'rollback' === $direction ? (array) ( $authority['rollback_target_state'] ?? array() ) : (array) ( $authority['activated_state'] ?? array() );
		$current_state = self::public_header_state_snapshot();
		if ( self::translation_job_canonicalize( $current_state ) !== self::translation_job_canonicalize( $expected_state ) ) { return array( 'success' => false, 'code' => 'public_header_transition_reader_state_changed' ); }
		$current_registry_revision = hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( self::languages( true ) ) ) ?: '' );
		if ( ! hash_equals( (string) ( $authority['language_registry_revision'] ?? '' ), $current_registry_revision ) ) { return array( 'success' => false, 'code' => 'public_header_transition_language_registry_changed' ); }
		foreach ( $require_candidates ? (array) ( $authority['candidates'] ?? array() ) : array() as $language => $candidate ) {
			$menu_id = absint( is_array( $candidate ) ? ( $candidate['menu_id'] ?? 0 ) : 0 );
			$expected_receipt = (string) ( is_array( $candidate ) ? ( $candidate['menu_surface_revision'] ?? '' ) : '' );
			$current_receipt = $menu_id > 0 ? self::localized_menu_projection_revision( $menu_id ) : '';
			if ( $menu_id < 1 || '' === $expected_receipt || '' === $current_receipt || ! hash_equals( $expected_receipt, $current_receipt ) ) { return array( 'success' => false, 'code' => 'public_header_transition_candidate_changed', 'language' => (string) $language ); }
		}
		return array( 'success' => true, 'current_state' => $current_state );
	}

	/** Classify candidate cleanup as wholly present, wholly absent, or conflicted. */
	private static function public_header_transition_candidate_presence( array $transition ): array {
		$present = array();
		$missing = array();
		foreach ( (array) ( $transition['authority']['candidates'] ?? array() ) as $language => $candidate ) {
			$menu_id = absint( is_array( $candidate ) ? ( $candidate['menu_id'] ?? 0 ) : 0 );
			if ( $menu_id > 0 && wp_get_nav_menu_object( $menu_id ) ) { $present[ (string) $language ] = $menu_id; } else { $missing[ (string) $language ] = $menu_id; }
		}
		return array( 'all_present' => ! empty( $present ) && empty( $missing ), 'all_missing' => ! empty( $missing ) && empty( $present ), 'present' => $present, 'missing' => $missing );
	}

	/** Atomically restore the exact prior reader state and enter bounded rollback verification. */
	private static function start_public_header_transition_rollback( array $transition, string $failed_code, array $failure_evidence = array() ): array {
		$authority = (array) ( $transition['authority'] ?? array() );
		$current_state = self::public_header_state_snapshot();
		$activated_state = (array) ( $authority['activated_state'] ?? array() );
		$rollback_target = (array) ( $authority['rollback_target_state'] ?? array() );
		if ( self::translation_job_canonicalize( $current_state ) !== self::translation_job_canonicalize( $activated_state ) ) {
			return array( 'success' => false, 'code' => 'public_header_transition_reader_state_changed', 'severity' => 'critical' );
		}
		$rollback_transition = $transition;
		$rollback_transition['phase'] = 'rollback_invalidation_pending';
		$rollback_transition['lease'] = array();
		$rollback_transition['outcome']['failed_code'] = $failed_code;
		$rollback_transition['outcome']['forward_failure_evidence'] = $failure_evidence;
		$rollback_transition['updated_at'] = gmdate( 'c' );
		$rollback = self::replace_public_header_state_transaction( $current_state, $rollback_target, (array) ( $authority['rollback_projections'] ?? array() ), '', $transition, $rollback_transition );
		if ( empty( $rollback['success'] ) ) { return array( 'success' => false, 'code' => 'public_header_transition_rollback_failed', 'severity' => 'critical', 'rollback' => $rollback ); }
		return array( 'success' => false, 'passed' => false, 'code' => 'public_header_rollback_verification_pending', 'failed_code' => $failed_code, 'rolled_back_state_applied' => true, 'finalized' => false, 'verification_pending' => true, 'needs_live_verification' => true, 'transition' => $rollback_transition, 'rollback' => $rollback );
	}

	/** Finalize a complete forward evidence matrix without deleting rollback material. */
	private static function finalize_public_header_transition_forward( array $transition ): array {
		$lease = self::acquire_public_header_transition_lease( $transition );
		if ( empty( $lease['success'] ) ) { return $lease; }
		$owned = (array) $lease['transition'];
		$validation = self::validate_public_header_transition_candidate_set( $owned, 'forward' );
		if ( empty( $validation['success'] ) ) { return self::start_public_header_transition_rollback( $owned, (string) ( $validation['code'] ?? 'public_header_forward_finalization_invalid' ), $validation ); }
		$staged = self::public_header_transition_staged_projections( $owned );
		$retirement = self::retire_previous_public_header_projection_set( $staged );
		if ( empty( $retirement['success'] ) ) { return self::start_public_header_transition_rollback( $owned, 'public_header_projection_retirement_failed', $retirement ); }
		$terminal = $owned;
		$terminal['phase'] = 'forward_verified';
		$terminal['lease'] = array();
		$terminal['outcome']['forward_verified_at'] = gmdate( 'c' );
		$terminal['outcome']['retirement'] = $retirement;
		$terminal['updated_at'] = gmdate( 'c' );
		$current_state = (array) $validation['current_state'];
		$finalized = self::replace_public_header_state_transaction( $current_state, $current_state, array(), '', $owned, $terminal );
		if ( empty( $finalized['success'] ) ) { return array( 'success' => false, 'code' => 'public_header_forward_finalization_failed', 'severity' => 'critical', 'finalization' => $finalized ); }
		return array( 'success' => true, 'passed' => true, 'code' => 'public_header_projection_verified', 'finalized' => true, 'verification_pending' => false, 'phase' => 'forward_verified', 'transition' => $terminal, 'retirement' => $retirement );
	}

	/** Complete rollback cleanup only after every captured reader coordinate passes. */
	private static function finalize_public_header_transition_rollback( array $transition ): array {
		$lease = self::acquire_public_header_transition_lease( $transition );
		if ( empty( $lease['success'] ) ) { return $lease; }
		$owned = (array) $lease['transition'];
		$presence = self::public_header_transition_candidate_presence( $owned );
		$cleanup_already_applied = 'rollback_cleanup_pending' === (string) ( $owned['phase'] ?? '' ) && ! empty( $presence['all_missing'] );
		if ( ! $cleanup_already_applied && empty( $presence['all_present'] ) ) { return array( 'success' => false, 'code' => 'public_header_rollback_candidate_set_partial', 'severity' => 'critical', 'presence' => $presence ); }
		$validation = self::validate_public_header_transition_candidate_set( $owned, 'rollback', ! $cleanup_already_applied );
		if ( empty( $validation['success'] ) ) { return array( 'success' => false, 'code' => (string) ( $validation['code'] ?? 'public_header_rollback_finalization_invalid' ), 'severity' => 'critical' ); }
		$cleanup_transition = $owned;
		$cleanup_transition['phase'] = 'rollback_cleanup_pending';
		$cleanup_transition['updated_at'] = gmdate( 'c' );
		if ( 'rollback_cleanup_pending' !== (string) ( $owned['phase'] ?? '' ) ) {
			$phase_write = self::replace_public_header_transition( $owned, $cleanup_transition );
			if ( empty( $phase_write['success'] ) ) { return array_merge( $phase_write, array( 'success' => false, 'severity' => 'critical' ) ); }
		}
		$staged = self::public_header_transition_staged_projections( $cleanup_transition );
		$state = (array) $validation['current_state'];
		$cleanup_authority = self::public_header_staged_cleanup_state_authority( $state, '__devenia_workflow_option_missing__' === ( $state['identities'] ?? null ) );
		$cleanup = $cleanup_already_applied ? array( 'success' => true, 'code' => 'staged_projection_cleanup_already_complete', 'results' => array() ) : self::delete_staged_public_header_projections( $staged, $cleanup_authority );
		if ( empty( $cleanup['success'] ) ) { return array( 'success' => false, 'code' => 'public_header_rollback_cleanup_failed', 'severity' => 'critical', 'cleanup' => $cleanup ); }
		$terminal = $cleanup_transition;
		$terminal['phase'] = 'rolled_back_verified';
		$terminal['lease'] = array();
		$terminal['outcome']['rollback_verified_at'] = gmdate( 'c' );
		$terminal['outcome']['cleanup'] = $cleanup;
		$terminal['updated_at'] = gmdate( 'c' );
		$finalized = self::replace_public_header_state_transaction( $state, $state, array(), '', $cleanup_transition, $terminal );
		if ( empty( $finalized['success'] ) ) { return array( 'success' => false, 'code' => 'public_header_rollback_finalization_failed', 'severity' => 'critical', 'cleanup' => $cleanup, 'finalization' => $finalized ); }
		return array( 'success' => false, 'passed' => false, 'code' => (string) ( $terminal['outcome']['failed_code'] ?? 'public_header_projection_verification_failed' ), 'rolled_back' => true, 'finalized' => true, 'verification_pending' => false, 'phase' => 'rolled_back_verified', 'transition' => $terminal, 'cleanup' => $cleanup );
	}

	/** Resume one bounded Public Header verification step. */
	private static function verify_public_header_projection( array $input ): array {
		$activation_receipt = sanitize_text_field( (string) ( $input['activation_receipt'] ?? '' ) );
		$language = sanitize_key( (string) ( $input['language'] ?? '' ) );
		$timeout = max( 3, min( 30, absint( $input['timeout'] ?? 15 ) ) );
		$validation = self::validate_public_header_transition( $activation_receipt, $language );
		if ( empty( $validation['success'] ) ) { return $validation; }
		$transition = (array) $validation['transition'];
		if ( ! empty( $validation['terminal'] ) ) {
			$passed = 'forward_verified' === (string) ( $validation['phase'] ?? '' );
			return array( 'success' => $passed, 'passed' => $passed, 'finalized' => true, 'phase' => (string) $validation['phase'], 'transition' => $transition, 'idempotent' => true );
		}
		$rollback_direction = 0 === strpos( (string) $transition['phase'], 'rollback_' );
		$direction = $rollback_direction ? 'rollback' : 'forward';
		$invalidation_phase = $direction . '_invalidation_pending';
		if ( $invalidation_phase === (string) $transition['phase'] ) {
			$event = $rollback_direction ? 'public_header_projection_rollback' : 'public_header_projection';
			$invalidation = apply_filters( 'devenia_workflow_frontend_cache_invalidation_result', null, (array) $transition['authority']['purge_urls'], array( 'event' => $event, 'manifest_revision' => (string) $transition['authority']['manifest_revision'], 'languages' => (array) $transition['authority']['languages'], 'failed_code' => (string) ( $transition['outcome']['failed_code'] ?? '' ) ) );
			if ( ! is_array( $invalidation ) || true !== ( $invalidation['success'] ?? null ) ) { return array( 'success' => false, 'passed' => false, 'code' => $rollback_direction ? 'public_header_rollback_cache_invalidation_failed' : 'public_header_cache_invalidation_failed', 'needs_retry' => true, 'transition' => $transition, 'cache_invalidation' => $invalidation ); }
			$next = $transition;
			$next['phase'] = $direction . '_verifying';
			$next['outcome'][ $direction . '_cache_invalidation' ] = $invalidation;
			$next['updated_at'] = gmdate( 'c' );
			$write = self::replace_public_header_transition( $transition, $next );
			if ( empty( $write['success'] ) ) { return array_merge( $write, array( 'success' => false, 'severity' => 'critical' ) ); }
			$transition = $next;
		}
		if ( ! empty( $transition['evidence'][ $direction ][ $language ]['passed'] ) ) {
			if ( self::public_header_transition_evidence_complete( $transition, $direction ) ) { return $rollback_direction ? self::finalize_public_header_transition_rollback( $transition ) : self::finalize_public_header_transition_forward( $transition ); }
			return array( 'success' => ! $rollback_direction, 'passed' => ! $rollback_direction, 'finalized' => false, 'verification_pending' => true, 'phase' => $direction . '_verifying', 'language' => $language, 'transition' => $transition, 'idempotent' => true );
		}
		$lease = self::acquire_public_header_transition_lease( $transition );
		if ( empty( $lease['success'] ) ) { return $lease; }
		$leased = (array) $lease['transition'];
		$expected = array( $language => (array) ( $leased['authority']['expected_navigation'][ $direction ][ $language ] ?? array() ) );
		if ( $rollback_direction && 'pre_enrollment' === (string) ( $leased['authority']['rollback_kind'] ?? '' ) ) {
			$verification = self::verify_pre_enrollment_public_header_navigation( array( $language ), $timeout, $expected );
		} else {
			$response_set = self::public_header_frontend_cache_response_set( array( $language ), $timeout, 1 );
			$items = array();
			$passed = true;
			$conclusive = true;
			foreach ( array( 'homepage' => self::localized_home_url_for_language( $language ), 'blog_archive' => self::public_blog_archive_url_for_language( $language ) ) as $surface => $url ) {
				$item = self::public_header_navigation_integrity_for_responses( (string) $url, $language, $surface, (array) $expected[ $language ], (array) ( $response_set[ $language ][ $surface ] ?? array() ) );
				$items[ $language ][ $surface ] = $item;
				$passed = $passed && ! empty( $item['passed'] );
				$conclusive = $conclusive && ! empty( $item['conclusive'] );
			}
			$verification = array( 'success' => $passed, 'passed' => $passed, 'conclusive' => $conclusive, 'items' => $items );
		}
		$passed = ! empty( $verification['passed'] );
		wp_cache_delete( self::OPTION_PUBLIC_HEADER_TRANSITION, 'options' );
		$current = get_option( self::OPTION_PUBLIC_HEADER_TRANSITION, array() );
		if ( ! is_array( $current ) || ! hash_equals( (string) $lease['lease_token'], (string) ( $current['lease']['lease_token'] ?? '' ) ) || absint( $current['lease']['lease_expires_at'] ?? 0 ) < time() || (string) ( $current['authority_revision'] ?? '' ) !== (string) ( $leased['authority_revision'] ?? '' ) ) {
			return array( 'success' => false, 'code' => 'public_header_transition_lease_lost', 'severity' => 'critical' );
		}
		if ( ! $passed && empty( $verification['conclusive'] ) ) {
			$retry = $current;
			$retry['lease'] = array();
			$retry['outcome'][ $direction . '_last_inconclusive' ] = self::compact_public_header_transition_evidence( $verification );
			$retry['updated_at'] = gmdate( 'c' );
			$retry_write = self::replace_public_header_transition( $current, $retry );
			if ( empty( $retry_write['success'] ) ) { return array_merge( $retry_write, array( 'success' => false, 'severity' => 'critical' ) ); }
			return array( 'success' => false, 'passed' => false, 'code' => 'public_header_verification_transport_inconclusive', 'needs_retry' => true, 'language' => $language, 'verification' => $verification, 'transition' => $retry );
		}
		if ( ! $passed && ! $rollback_direction ) {
			return self::start_public_header_transition_rollback( $current, 'public_header_projection_verification_failed', array( 'language' => $language, 'verification' => self::compact_public_header_transition_evidence( $verification ) ) );
		}
		$next = $current;
		$next['lease'] = array();
		$next['evidence'][ $direction ][ $language ] = self::compact_public_header_transition_evidence( $verification );
		$next['updated_at'] = gmdate( 'c' );
		if ( ! $passed ) { $next['outcome']['rollback_failed_language'] = $language; }
		$write = self::replace_public_header_transition( $current, $next );
		if ( empty( $write['success'] ) ) { return array_merge( $write, array( 'success' => false, 'severity' => 'critical' ) ); }
		if ( ! $passed ) { return array( 'success' => false, 'passed' => false, 'code' => 'public_header_rollback_verification_failed', 'needs_retry' => true, 'language' => $language, 'verification' => $verification, 'transition' => $next ); }
		if ( self::public_header_transition_evidence_complete( $next, $direction ) ) { return $rollback_direction ? self::finalize_public_header_transition_rollback( $next ) : self::finalize_public_header_transition_forward( $next ); }
		return array( 'success' => ! $rollback_direction, 'passed' => ! $rollback_direction, 'finalized' => false, 'verification_pending' => true, 'phase' => $direction . '_verifying', 'language' => $language, 'verification' => $verification, 'transition' => $next );
	}

	/**
	 * Activate one complete staged set and its pending manifest in one database transaction.
	 *
	 * @param array<string,mixed> $pending Pending manifest.
	 * @param array<string,array<string,mixed>> $staged Staged projections.
	 */
	private static function activate_public_header_projection_set( array $pending, array $staged, string $activation_receipt = '' ): array {
		$missing = '__devenia_workflow_option_missing__';
		$before = array(
			'manifest'   => get_option( self::OPTION_PUBLIC_HEADER_MANIFEST, $missing ),
			'identities' => get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, $missing ),
			'pending'    => get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $missing ),
			'enrollment' => get_option( self::OPTION_PUBLIC_HEADER_ENROLLMENT, $missing ),
		);
		if ( $before['pending'] !== $pending ) {
			return array( 'success' => false, 'code' => 'pending_manifest_changed_before_activation' );
		}
		$relation_validation = self::validate_public_header_relation_receipts( $pending );
		if ( empty( $relation_validation['success'] ) ) { return array_merge( $relation_validation, array( 'success' => false ) ); }
		$authority_validation = self::validate_public_header_authority_receipts( $pending );
		if ( empty( $authority_validation['success'] ) ) { return array_merge( $authority_validation, array( 'success' => false ) ); }
		$identities = is_array( $before['identities'] ) ? $before['identities'] : array();
		foreach ( $staged as $language => $projection ) {
			$identities[ $language ] = array( 'menu_id' => absint( $projection['target_menu']['id'] ?? 0 ), 'configured_name' => (string) ( $projection['target_menu']['name'] ?? '' ), 'manifest_revision' => (string) $pending['revision'], 'activated_at' => gmdate( 'c' ) );
		}
		$active_manifest = $pending;
		unset( $active_manifest['authority_receipts'] );
		unset( $active_manifest['relation_receipts'] );
		unset( $active_manifest['pre_enrollment_recovery'] );
		$after = array(
			'manifest'   => $active_manifest,
			'identities' => $identities,
			'pending'    => $missing,
			'enrollment' => '1',
		);
		self::ensure_public_header_transition_option();
		$expected_transition = get_option( self::OPTION_PUBLIC_HEADER_TRANSITION, self::public_header_idle_transition() );
		if ( ! is_array( $expected_transition ) || self::public_header_transition_is_nonterminal( $expected_transition ) ) {
			return array( 'success' => false, 'code' => 'public_header_transition_in_progress', 'transition' => $expected_transition );
		}
		$transition = self::build_public_header_transition( $pending, $before, $after, $staged, $activation_receipt, (array) ( $pending['pre_enrollment_recovery'] ?? array() ) );
		if ( empty( $transition['success'] ) ) { return $transition; }
		unset( $transition['success'] );
		do_action( 'devenia_workflow_public_header_before_locked_state_transition', $pending, $before );
		$result = self::replace_public_header_state_transaction( $before, $after, $staged, $activation_receipt, $expected_transition, $transition );
		return array_merge( $result, array( 'before' => $before, 'after' => $after, 'transition' => $transition ) );
	}

	/** Remove option-cache values which may reflect writes later rolled back by MySQL. */
	private static function clear_public_header_state_option_cache(): void {
		foreach ( array( self::OPTION_PUBLIC_HEADER_MANIFEST, self::OPTION_LOCALIZED_MENU_IDENTITIES, self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, self::OPTION_PUBLIC_HEADER_ENROLLMENT, self::OPTION_PUBLIC_HEADER_TRANSITION ) as $key ) {
			wp_cache_delete( $key, 'options' );
		}
	}

	/** Read the owned option rows directly when cache cannot prove COMMIT state. */
	private static function public_header_database_state(): array {
		global $wpdb;
		$missing = '__devenia_workflow_option_missing__';
		$keys = array( self::OPTION_PUBLIC_HEADER_MANIFEST, self::OPTION_LOCALIZED_MENU_IDENTITIES, self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, self::OPTION_PUBLIC_HEADER_ENROLLMENT, self::OPTION_PUBLIC_HEADER_TRANSITION );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Post-COMMIT reconciliation must observe raw durable rows instead of possibly stale persistent option cache.
			$wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN (%s, %s, %s, %s, %s)", $keys[0], $keys[1], $keys[2], $keys[3], $keys[4] ),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) { return array( 'success' => false, 'code' => 'public_header_database_state_read_failed' ); }
		$values = array_fill_keys( $keys, $missing );
		foreach ( $rows as $row ) {
			$key = (string) ( $row['option_name'] ?? '' );
			if ( array_key_exists( $key, $values ) ) { $values[ $key ] = maybe_unserialize( (string) ( $row['option_value'] ?? '' ) ); }
		}
		return array(
			'success' => true,
			'state' => array(
				'manifest'   => $values[ self::OPTION_PUBLIC_HEADER_MANIFEST ],
				'identities' => $values[ self::OPTION_LOCALIZED_MENU_IDENTITIES ],
				'pending'    => $values[ self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST ],
				'enrollment' => $values[ self::OPTION_PUBLIC_HEADER_ENROLLMENT ],
			),
			'transition' => $values[ self::OPTION_PUBLIC_HEADER_TRANSITION ],
		);
	}

	/** Roll back the owned transaction and discard every possibly uncommitted option-cache value. */
	private static function rollback_public_header_state_transaction(): void {
		self::translation_job_rollback_recovery_transaction();
		self::clear_public_header_state_option_cache();
	}

	/** Atomically replace active manifest, all identities, and pending state. */
	private static function replace_public_header_state_transaction( array $expected, array $replacement, array $staged = array(), string $activation_receipt = '', ?array $expected_transition = null, ?array $replacement_transition = null ): array {
		if ( ! self::translation_job_begin_recovery_transaction() ) { return array( 'success' => false, 'code' => 'public_header_transaction_unavailable' ); }
		try {
			global $wpdb;
			$pending_authority = isset( $expected['pending'] ) && is_array( $expected['pending'] ) ? $expected['pending'] : array();
			$forward_projection = ! empty( $staged ) && ! empty( $pending_authority['items'] );
			$authority_lock = ! empty( $staged ) ? self::lock_public_header_relation_authority_surface( $pending_authority, $expected, $replacement, $staged ) : array( 'success' => true, 'present' => false );
			if ( empty( $authority_lock['success'] ) ) { self::rollback_public_header_state_transaction(); return array( 'success' => false, 'code' => 'public_header_relation_authority_lock_failed', 'authority_lock' => $authority_lock ); }
			if ( ! empty( $authority_lock['present'] ) ) { do_action( 'devenia_workflow_public_header_authority_after_locked_surface', $pending_authority, $authority_lock ); }
			$relation_validation = $forward_projection ? self::validate_public_header_relation_receipts( $pending_authority ) : array( 'success' => true, 'present' => false );
			if ( empty( $relation_validation['success'] ) ) { self::rollback_public_header_state_transaction(); return array( 'success' => false, 'code' => 'public_header_relation_changed_at_locked_boundary', 'relation_validation' => $relation_validation ); }
			$authority_validation = empty( $staged ) || empty( $pending_authority ) ? array( 'success' => true, 'present' => false ) : self::validate_public_header_authority_receipts( $pending_authority );
			if ( empty( $authority_validation['success'] ) ) { self::rollback_public_header_state_transaction(); return array( 'success' => false, 'code' => 'public_header_authority_changed_at_locked_boundary', 'authority_validation' => $authority_validation ); }
			foreach ( $staged as $language => $projection ) {
				$menu_id = absint( $projection['target_menu']['id'] ?? 0 );
				$receipt = (string) ( $projection['menu_surface_revision'] ?? '' );
				$manifest_revision = (string) ( $projection['manifest_revision'] ?? '' );
				$current = $menu_id > 0 ? self::localized_menu_projection_revision( $menu_id ) : '';
				if ( $menu_id < 1 || '' === $receipt || '' === $current || ! hash_equals( $receipt, $current ) || '1' !== (string) get_term_meta( $menu_id, self::TERM_META_MENU_MANAGED, true ) || sanitize_key( (string) $language ) !== sanitize_key( (string) get_term_meta( $menu_id, self::TERM_META_MENU_LANGUAGE, true ) ) || '' === $manifest_revision || ! hash_equals( $manifest_revision, (string) get_term_meta( $menu_id, self::TERM_META_PUBLIC_HEADER_MANIFEST_REVISION, true ) ) ) {
					self::rollback_public_header_state_transaction();
					return array( 'success' => false, 'code' => 'public_header_staged_receipt_changed', 'language' => (string) $language );
				}
			}
			$keys = array( self::OPTION_PUBLIC_HEADER_MANIFEST, self::OPTION_LOCALIZED_MENU_IDENTITIES, self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, self::OPTION_PUBLIC_HEADER_ENROLLMENT, self::OPTION_PUBLIC_HEADER_TRANSITION );
			$missing = '__devenia_workflow_option_missing__';
			$locked_options = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Every expected-present reader row and the durable transition row share one owned transaction; absent rows remain protected by the unique option-name insert.
				$wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name IN (%s, %s, %s, %s, %s) FOR UPDATE", $keys[0], $keys[1], $keys[2], $keys[3], $keys[4] )
			);
			if ( false === $locked_options ) {
				self::rollback_public_header_state_transaction();
				return array( 'success' => false, 'code' => 'public_header_state_lock_failed' );
			}
			wp_cache_delete( self::OPTION_PUBLIC_HEADER_TRANSITION, 'options' );
			$current_transition = get_option( self::OPTION_PUBLIC_HEADER_TRANSITION, '__devenia_workflow_option_missing__' );
			if ( null !== $expected_transition && self::translation_job_canonicalize( $current_transition ) !== self::translation_job_canonicalize( $expected_transition ) ) {
				self::rollback_public_header_state_transaction();
				return array( 'success' => false, 'code' => 'public_header_transition_changed' );
			}
			$map = array( 'manifest' => self::OPTION_PUBLIC_HEADER_MANIFEST, 'identities' => self::OPTION_LOCALIZED_MENU_IDENTITIES, 'pending' => self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, 'enrollment' => self::OPTION_PUBLIC_HEADER_ENROLLMENT );
			foreach ( $map as $slot => $key ) {
				wp_cache_delete( $key, 'options' );
				$current = get_option( $key, '__devenia_workflow_option_missing__' );
				$expected_value = $expected[ $slot ] ?? '__devenia_workflow_option_missing__';
				$replacement_value = $replacement[ $slot ] ?? '__devenia_workflow_option_missing__';
				if ( 'pending' === $slot && '' !== $activation_receipt ) {
					$current_receipt = is_array( $current ) ? self::public_header_activation_receipt( $current ) : '';
					if ( '' === $current_receipt || ! hash_equals( $activation_receipt, $current_receipt ) ) { self::rollback_public_header_state_transaction(); return array( 'success' => false, 'code' => 'public_header_activation_receipt_mismatch', 'slot' => $slot ); }
				}
				$expected_exact = 'pending' === $slot ? $current === $expected_value : self::translation_job_canonicalize( $current ) === self::translation_job_canonicalize( $expected_value );
				$replacement_exact = 'pending' === $slot ? $current === $replacement_value : self::translation_job_canonicalize( $current ) === self::translation_job_canonicalize( $replacement_value );
				if ( ! $expected_exact ) { self::rollback_public_header_state_transaction(); return array( 'success' => false, 'code' => 'public_header_state_changed', 'slot' => $slot ); }
				if ( $replacement_exact ) {
					$written = true;
				} elseif ( '__devenia_workflow_option_missing__' === $expected_value ) {
					$written = self::atomic_create_option( $key, $replacement_value );
				} elseif ( '__devenia_workflow_option_missing__' === $replacement_value ) {
					$written = self::atomic_delete_option_value( $key, $expected_value );
				} else {
					$written = self::atomic_replace_option_value( $key, $expected_value, $replacement_value );
				}
				if ( ! $written ) { self::rollback_public_header_state_transaction(); return array( 'success' => false, 'code' => 'public_header_state_write_failed', 'slot' => $slot ); }
			}
			if ( null !== $replacement_transition ) {
				$written = self::atomic_replace_option_value( self::OPTION_PUBLIC_HEADER_TRANSITION, $current_transition, $replacement_transition );
				if ( ! $written ) { self::rollback_public_header_state_transaction(); return array( 'success' => false, 'code' => 'public_header_transition_write_failed' ); }
			}
			$commit = apply_filters( 'devenia_workflow_public_header_state_commit_adapter_result', null, $expected, $replacement, $staged );
			$commit = null === $commit ? self::translation_job_commit_recovery_transaction() : self::translation_job_recovery_commit_adapter_receipt( $commit );
			self::clear_public_header_state_option_cache();
			return self::reconcile_public_header_state_commit_outcome( $expected, $replacement, $commit, $expected_transition, $replacement_transition );
		} catch ( Throwable $error ) {
			self::rollback_public_header_state_transaction();
			return array( 'success' => false, 'code' => 'public_header_state_exception' );
		}
	}

	/** Reconcile a structured activation commit receipt against exact state evidence. */
	private static function reconcile_public_header_state_commit_outcome( array $expected, array $replacement, array $commit, ?array $expected_transition = null, ?array $replacement_transition = null ): array {
		$receipt_validation = self::translation_job_require_recovery_commit_receipt( $commit );
		self::clear_public_header_state_option_cache();
		$database = self::public_header_database_state();
		if ( empty( $database['success'] ) ) { return array( 'success' => false, 'code' => 'public_header_state_commit_reconciliation_read_failed', 'severity' => 'critical', 'commit' => $commit, 'receipt_validation' => $receipt_validation ); }
		$current = (array) $database['state'];
		$current_transition = $database['transition'];
		$committed = $receipt_validation['committed'];
		$expected_transition_exact = null === $expected_transition || self::translation_job_canonicalize( $current_transition ) === self::translation_job_canonicalize( $expected_transition );
		$replacement_transition_exact = null === $replacement_transition || self::translation_job_canonicalize( $current_transition ) === self::translation_job_canonicalize( $replacement_transition );
		$expected_exact = self::translation_job_canonicalize( $current ) === self::translation_job_canonicalize( $expected ) && $expected_transition_exact;
		$replacement_exact = self::translation_job_canonicalize( $current ) === self::translation_job_canonicalize( $replacement ) && $replacement_transition_exact;
		$state_class = $expected_exact ? 'expected' : ( $replacement_exact ? 'replacement' : 'foreign' );
		$base = array( 'success' => false, 'severity' => 'critical', 'commit' => $commit, 'committed' => $committed, 'receipt_validation' => $receipt_validation, 'current_state' => $current, 'current_transition' => $current_transition, 'expected_state_exact' => $expected_exact, 'replacement_state_exact' => $replacement_exact, 'expected_transition_exact' => $expected_transition_exact, 'replacement_transition_exact' => $replacement_transition_exact, 'state_class' => $state_class );
		if ( empty( $receipt_validation['valid'] ) ) { return array_merge( $base, array( 'code' => 'public_header_state_commit_receipt_invalid', 'state_outcome' => 'invalid_receipt' ) ); }
		if ( false === $committed && $expected_exact ) { return array_merge( $base, array( 'severity' => 'error', 'code' => 'public_header_state_commit_rolled_back', 'state_outcome' => 'unapplied' ) ); }
		if ( true === $committed && $replacement_exact && true === ( $commit['success'] ?? null ) ) { return array_merge( $base, array( 'success' => true, 'severity' => 'success', 'code' => 'public_header_state_commit_applied', 'state_outcome' => 'applied' ) ); }
		if ( true === $committed && $replacement_exact ) { return array_merge( $base, array( 'code' => 'public_header_state_commit_applied_adapter_error', 'state_outcome' => 'applied' ) ); }
		if ( null === $committed && $expected_exact ) { return array_merge( $base, array( 'code' => 'public_header_state_commit_outcome_unknown_unapplied', 'state_outcome' => 'unapplied' ) ); }
		if ( null === $committed && $replacement_exact ) { return array_merge( $base, array( 'code' => 'public_header_state_commit_outcome_unknown_applied', 'state_outcome' => 'applied' ) ); }
		if ( false === $committed && $replacement_exact ) { return array_merge( $base, array( 'code' => 'public_header_state_commit_receipt_conflict', 'state_outcome' => 'applied' ) ); }
		return array_merge( $base, array( 'code' => 'public_header_state_commit_reconciliation_conflict', 'state_outcome' => 'foreign' ) );
	}

	/** Authorize staged deletion only after exact current identities prove no reference. */
	private static function public_header_activation_cleanup_authority( array $activation, array $staged ): array {
		$missing = '__devenia_workflow_option_missing__'; self::clear_public_header_state_option_cache();
		$current = array( 'manifest' => get_option( self::OPTION_PUBLIC_HEADER_MANIFEST, $missing ), 'identities' => get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, $missing ), 'pending' => get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $missing ), 'enrollment' => get_option( self::OPTION_PUBLIC_HEADER_ENROLLMENT, $missing ) );
		$receipt_current_exact = empty( $activation['current_state'] ) || ( is_array( $activation['current_state'] ) && self::translation_job_canonicalize( $current ) === self::translation_job_canonicalize( $activation['current_state'] ) );
		$before_exact = isset( $activation['before'] ) && is_array( $activation['before'] ) && self::translation_job_canonicalize( $current ) === self::translation_job_canonicalize( $activation['before'] );
		$outcome_unapplied = 'unapplied' === (string) ( $activation['state_outcome'] ?? '' ) || ( '' === (string) ( $activation['state_outcome'] ?? '' ) && $before_exact );
		$references_excluded = $receipt_current_exact && $outcome_unapplied && self::public_header_state_excludes_staged_menu_ids( $current, $staged, $before_exact );
		$state_authority = self::public_header_staged_cleanup_state_authority( $current, $before_exact && '__devenia_workflow_option_missing__' === ( $current['identities'] ?? null ) );
		return array( 'allowed' => $references_excluded, 'state_outcome' => (string) ( $activation['state_outcome'] ?? ( $before_exact ? 'unapplied' : 'unknown' ) ), 'receipt_current_exact' => $receipt_current_exact, 'before_exact' => $before_exact, 'references_excluded' => $references_excluded, 'current_state' => $current, 'state_authority' => $state_authority );
	}

	/**
	 * Capture the exact stored navigation protected by a menu surface receipt.
	 * This versioned rollback authority lets schema-1 state verify without
	 * inventing schema-2 labels during recovery.
	 *
	 * @return array<int,array{title:string,url:string}>
	 */
	private static function public_header_navigation_snapshot_from_menu( int $menu_id ): array {
		$items = wp_get_nav_menu_items( $menu_id, array( 'orderby' => 'menu_order' ) ) ?: array();
		$expected = array();
		foreach ( self::localized_menu_items_in_render_order( $items ) as $item ) {
			$title = trim( html_entity_decode( wp_strip_all_tags( (string) ( $item->title ?? '' ) ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' ) );
			$url = self::normalize_primary_navigation_url( (string) ( $item->url ?? '' ) );
			if ( '' === $title || '' === $url ) { return array(); }
			$expected[] = array( 'title' => $title, 'url' => $url );
		}
		return $expected;
	}

	/**
	 * Retire the old complete set logically while preserving rollback material.
	 *
	 * The active manifest revision is the reader authority, so no term mutation
	 * is needed here. Keeping the old terms byte-stable avoids a second commit
	 * boundary which could make the already-activated set impossible to roll
	 * back safely.
	 */
	private static function retire_previous_public_header_projection_set( array $staged ): array {
		$allowed = apply_filters( 'devenia_workflow_public_header_projection_retirement_result', true, $staged );
		if ( true !== $allowed ) { return array( 'success' => false, 'code' => 'public_header_retirement_rejected' ); }
		$retired = array();
		foreach ( $staged as $language => $projection ) {
			$old_id = absint( $projection['previous_menu_id'] ?? 0 );
			$new_id = absint( $projection['target_menu']['id'] ?? 0 );
			if ( $old_id < 1 || $old_id === $new_id ) { continue; }
			$current_revision = self::localized_menu_projection_revision( $old_id );
			$expected_revision = (string) ( $projection['previous_menu_surface_revision'] ?? '' );
			if ( '1' !== (string) get_term_meta( $old_id, self::TERM_META_MENU_MANAGED, true ) || '' === $expected_revision || '' === $current_revision || ! hash_equals( $expected_revision, $current_revision ) ) {
				return array( 'success' => false, 'code' => 'public_header_retirement_preflight_failed', 'language' => $language );
			}
			$retired[] = $old_id;
		}
		return array( 'success' => true, 'retired_menu_ids' => $retired, 'preserved_for_rollback' => true );
	}

	/** Bind an exact Public Header state to a later receipt-checked cleanup. */
	private static function public_header_staged_cleanup_state_authority( array $state, bool $allow_missing_identities = false ): array {
		return array(
			'expected_state'             => $state,
			'expected_state_revision'    => hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $state ) ) ?: '' ),
			'allow_missing_identities'   => $allow_missing_identities,
		);
	}

	/** Roll back one owned staged-menu cleanup without overstating deletion. */
	private static function public_header_staged_cleanup_failure( array $failure, array $menu_ids ): array {
		$rollback = self::translation_job_rollback_recovery_transaction();
		self::clear_public_header_state_option_cache();
		if ( ! empty( $menu_ids ) ) { clean_term_cache( $menu_ids ); }
		$failure['success'] = false;
		$failure['results'] = (array) ( $failure['results'] ?? array() );
		$failure['transaction_rollback'] = $rollback;
		if ( empty( $rollback['success'] ) ) { $failure['severity'] = 'critical'; }
		return $failure;
	}

	/**
	 * Delete only receipt-bound unreferenced staged menus inside one owned
	 * transaction. The identity proof, every menu surface lock, revalidation,
	 * and every deletion share the same serializable boundary.
	 */
	private static function delete_staged_public_header_projections( array $staged, array $state_authority = array() ): array {
		if ( empty( $staged ) ) { return array( 'success' => true, 'code' => 'staged_projection_cleanup_complete', 'results' => array() ); }
		$rows = array(); $menu_ids = array(); $results = array();
		foreach ( $staged as $language => $projection ) {
			$menu_id = absint( $projection['target_menu']['id'] ?? 0 );
			$receipt = (string) ( $projection['menu_surface_revision'] ?? '' );
			if ( $menu_id < 1 || '' === $receipt || isset( $menu_ids[ $menu_id ] ) ) {
				$results[ (string) $language ] = array( 'success' => false, 'code' => 'staged_menu_receipt_mismatch', 'menu_id' => $menu_id, 'expected_receipt' => $receipt, 'actual_receipt' => '' );
				return array( 'success' => false, 'code' => 'staged_projection_cleanup_incomplete', 'results' => $results );
			}
			$menu_ids[ $menu_id ] = true;
			$rows[] = array( 'language' => (string) $language, 'menu_id' => $menu_id, 'receipt' => $receipt );
		}
		$menu_id_list = array_keys( $menu_ids );
		if ( ! self::translation_job_begin_recovery_transaction() ) {
			return array( 'success' => false, 'code' => 'staged_projection_cleanup_transaction_unavailable', 'severity' => 'critical', 'results' => array() );
		}
		try {
			global $wpdb;
			foreach ( $rows as $row ) {
				$locked = self::lock_localized_menu_projection_surface( (int) $row['menu_id'] );
				$current = self::localized_menu_projection_revision( (int) $row['menu_id'] );
				if ( empty( $locked['success'] ) || '' === $current || ! hash_equals( (string) $row['receipt'], $current ) ) {
					$results[ $row['language'] ] = array( 'success' => false, 'code' => 'staged_menu_receipt_mismatch', 'menu_id' => $row['menu_id'], 'expected_receipt' => $row['receipt'], 'actual_receipt' => $current );
					return self::public_header_staged_cleanup_failure( array( 'code' => 'staged_projection_cleanup_incomplete', 'results' => $results ), $menu_id_list );
				}
			}
			$keys = array( self::OPTION_PUBLIC_HEADER_MANIFEST, self::OPTION_LOCALIZED_MENU_IDENTITIES, self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, self::OPTION_PUBLIC_HEADER_ENROLLMENT );
			$locked_options = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The identity proof and receipt-bound menu deletions must share one locked option/menu transaction.
				$wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name IN (%s, %s, %s, %s) FOR UPDATE", $keys[0], $keys[1], $keys[2], $keys[3] )
			);
			if ( false === $locked_options ) {
				return self::public_header_staged_cleanup_failure( array( 'code' => 'staged_projection_cleanup_state_lock_failed' ), $menu_id_list );
			}
			$missing = '__devenia_workflow_option_missing__';
			$read_state = static function () use ( $missing ): array {
				return array(
					'manifest'   => get_option( self::OPTION_PUBLIC_HEADER_MANIFEST, $missing ),
					'identities' => get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, $missing ),
					'pending'    => get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $missing ),
					'enrollment' => get_option( self::OPTION_PUBLIC_HEADER_ENROLLMENT, $missing ),
				);
			};
			self::clear_public_header_state_option_cache();
			$locked_state = $read_state();
			$authority_expected = isset( $state_authority['expected_state'] ) && is_array( $state_authority['expected_state'] ) ? $state_authority['expected_state'] : array();
			$authority_revision = (string) ( $state_authority['expected_state_revision'] ?? '' );
			$authority_actual_revision = ! empty( $authority_expected ) ? hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $authority_expected ) ) ?: '' ) : '';
			$authority_valid = ! empty( $authority_expected ) && '' !== $authority_revision && hash_equals( $authority_revision, $authority_actual_revision );
			if ( ( ! empty( $state_authority ) && ! $authority_valid ) || ( $authority_valid && self::translation_job_canonicalize( $locked_state ) !== self::translation_job_canonicalize( $authority_expected ) ) ) {
				return self::public_header_staged_cleanup_failure( array( 'code' => 'staged_projection_cleanup_state_receipt_mismatch', 'severity' => 'critical', 'locked_state' => $locked_state ), $menu_id_list );
			}
			$allow_missing_identities = $authority_valid && ! empty( $state_authority['allow_missing_identities'] );
			if ( ! self::public_header_state_excludes_staged_menu_ids( $locked_state, $staged, $allow_missing_identities ) ) {
				return self::public_header_staged_cleanup_failure( array( 'code' => 'cleanup_blocked_by_foreign_staged_menu_reference', 'severity' => 'critical', 'locked_state' => $locked_state ), $menu_id_list );
			}

			do_action( 'devenia_workflow_public_header_staged_cleanup_before_locked_revalidation', $staged, $locked_state );
			self::clear_public_header_state_option_cache();
			clean_term_cache( $menu_id_list );
			$revalidated_state = $read_state();
			if ( self::translation_job_canonicalize( $revalidated_state ) !== self::translation_job_canonicalize( $locked_state ) || ! self::public_header_state_excludes_staged_menu_ids( $revalidated_state, $staged, $allow_missing_identities ) ) {
				return self::public_header_staged_cleanup_failure( array( 'code' => 'staged_projection_cleanup_identity_changed', 'severity' => 'critical', 'locked_state' => $locked_state, 'revalidated_state' => $revalidated_state ), $menu_id_list );
			}
			foreach ( $rows as $row ) {
				$current = self::localized_menu_projection_revision( (int) $row['menu_id'] );
				if ( '' === $current || ! hash_equals( (string) $row['receipt'], $current ) ) {
					$results[ $row['language'] ] = array( 'success' => false, 'code' => 'staged_menu_receipt_mismatch', 'menu_id' => $row['menu_id'], 'expected_receipt' => $row['receipt'], 'actual_receipt' => $current );
					return self::public_header_staged_cleanup_failure( array( 'code' => 'staged_projection_cleanup_incomplete', 'results' => $results ), $menu_id_list );
				}
			}
			foreach ( $rows as $row ) {
				$deleted = wp_delete_nav_menu( (int) $row['menu_id'] );
				$deleted_ok = ! is_wp_error( $deleted ) && false !== $deleted && ! wp_get_nav_menu_object( (int) $row['menu_id'] );
				$results[ $row['language'] ] = array( 'success' => $deleted_ok, 'code' => $deleted_ok ? 'staged_menu_deleted' : 'staged_menu_delete_failed', 'menu_id' => $row['menu_id'], 'receipt' => $row['receipt'] );
				if ( ! $deleted_ok ) { return self::public_header_staged_cleanup_failure( array( 'code' => 'staged_projection_cleanup_incomplete', 'results' => $results ), $menu_id_list ); }
			}
			self::clear_public_header_state_option_cache();
			$state_before_commit = $read_state();
			if ( self::translation_job_canonicalize( $state_before_commit ) !== self::translation_job_canonicalize( $locked_state ) ) {
				return self::public_header_staged_cleanup_failure( array( 'code' => 'staged_projection_cleanup_identity_changed', 'severity' => 'critical', 'locked_state' => $locked_state, 'revalidated_state' => $state_before_commit ), $menu_id_list );
			}
			$commit = self::translation_job_commit_recovery_transaction();
			$receipt_validation = self::translation_job_require_recovery_commit_receipt( $commit );
			self::clear_public_header_state_option_cache(); clean_term_cache( $menu_id_list );
			$all_deleted = empty( array_filter( $menu_id_list, 'wp_get_nav_menu_object' ) );
			if ( ! empty( $receipt_validation['valid'] ) && true === ( $commit['success'] ?? null ) && true === ( $commit['committed'] ?? null ) && $all_deleted ) {
				return array( 'success' => true, 'code' => 'staged_projection_cleanup_complete', 'results' => $results, 'transaction_commit' => $commit, 'receipt_validation' => $receipt_validation, 'locked_state_revision' => hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $locked_state ) ) ?: '' ) );
			}
			return array( 'success' => false, 'code' => empty( $receipt_validation['valid'] ) ? 'staged_projection_cleanup_commit_receipt_invalid' : ( $all_deleted ? 'staged_projection_cleanup_commit_outcome_unresolved' : 'staged_projection_cleanup_commit_failed' ), 'severity' => 'critical', 'results' => $results, 'transaction_commit' => $commit, 'receipt_validation' => $receipt_validation, 'state_outcome' => empty( $receipt_validation['valid'] ) ? 'invalid_receipt' : ( $all_deleted ? 'applied' : 'unapplied' ) );
		} catch ( Throwable $error ) {
			return self::public_header_staged_cleanup_failure( array( 'code' => 'staged_projection_cleanup_exception', 'severity' => 'critical' ), $menu_id_list );
		}
	}

	/** Prove that the currently authoritative identities reference no staged menu. */
	private static function public_header_state_excludes_staged_menu_ids( array $state, array $staged, bool $exact_owned_state = false ): bool {
		$staged_ids = array(); foreach ( $staged as $projection ) { $menu_id = absint( $projection['target_menu']['id'] ?? 0 ); if ( $menu_id > 0 ) { $staged_ids[ $menu_id ] = true; } }
		if ( empty( $staged_ids ) ) { return true; }
		$identities = $state['identities'] ?? null;
		if ( '__devenia_workflow_option_missing__' === $identities ) { return $exact_owned_state; }
		if ( ! is_array( $identities ) ) { return false; }
		foreach ( $identities as $identity ) {
			if ( ! is_array( $identity ) ) { continue; }
			$menu_id = absint( $identity['menu_id'] ?? 0 );
			if ( $menu_id > 0 && isset( $staged_ids[ $menu_id ] ) ) { return false; }
		}
		return true;
	}

	/**
	 * Verify the published translation on both origin and canonical cache surfaces.
	 *
	 * @param array<string,mixed> $input Verification arguments.
	 * @return array<string,mixed>
	 */
	private static function verify_live_translation( array $input ): array {
		$translation_id = absint( $input['translation_id'] ?? 0 );
		$post           = get_post( $translation_id );
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) || ! self::is_translation_post( $translation_id ) ) {
			return array( 'success' => false, 'code' => 'translation_not_found', 'message' => 'Translation content not found.' );
		}
		$translation = self::translation_payload( $post );
		$language    = sanitize_key( (string) ( $translation['language'] ?? '' ) );
		$url         = esc_url_raw( (string) ( $translation['url'] ?? '' ) );
		if ( ! self::is_translation_language( $language ) ) {
			return array(
				'success'       => true,
				'passed'        => false,
				'issues'        => array( self::qa_item( 'missing_or_unknown_language', 'Translation language is missing or not configured.' ) ),
				'warnings'      => array(),
				'issue_count'   => 1,
				'warning_count' => 0,
				'translation'   => $translation,
			);
		}
		if ( 'publish' !== $post->post_status ) {
			return array( 'success' => true, 'passed' => false, 'issues' => array( self::qa_item( 'translation_not_published', 'Live verification requires a published translation.', array( 'status' => $post->post_status ) ) ), 'translation' => $translation );
		}

		$expected_media = isset( $input['expected_media'] ) && is_array( $input['expected_media'] )
			? $input['expected_media']
			: array();
		$result = self::frontend_public_surface_integrity_for_url( $url, $language, absint( $input['timeout'] ?? 15 ), 'translation', $expected_media );
		$result['success']     = true;
		$result['translation'] = $translation;

		return $result;
	}

	/**
	 * Publish localized content, invalidate its caches, and verify public views.
	 *
	 * Public Header Projection is a separate explicit coordinator operation.
	 *
	 * @param array<string,mixed> $input Publication arguments.
	 * @return array<string,mixed>
	 */
	private static function publish_localized_presentation( array $input ): array {
		$translation_id = absint( $input['translation_id'] ?? 0 );
		$language       = sanitize_key( (string) ( $input['language'] ?? '' ) );
		$source_id      = absint( $input['source_id'] ?? 0 );
		$job_id         = sanitize_text_field( (string) ( $input['job_id'] ?? '' ) );
		$expected_media = isset( $input['expected_media'] ) && is_array( $input['expected_media'] ) ? $input['expected_media'] : array();
		$term_scope     = (array) ( $input['rollback_term_scope'] ?? array() );
		$identity_scope = (array) ( $input['rollback_identity_scope'] ?? array() );
		$recover_staged_mutation = ! empty( $input['recover_staged_mutation'] );
		$prior_mutation_cas_revision = (string) ( $input['expected_mutation_cas_revision'] ?? '' );
		if ( ! self::translation_job_begin_recovery_transaction() ) {
			return array_merge( array( 'success' => false, 'code' => 'publication_transaction_unavailable', 'message' => 'The localized presentation transaction could not be started.', 'published' => false, 'mutation_started' => $recover_staged_mutation, 'mutation_cas_revision' => $prior_mutation_cas_revision, 'rollback_authorized' => false ), self::translation_job_recovery_transaction_error_fields() );
		}
		$current_before = '';
		$mutation_cas_revision = '';
		$transition = array();
		$commit = null;
		try {
		$locked = self::translation_job_lock_recovery_surface( $translation_id, $term_scope, $identity_scope );
		if ( empty( $locked['success'] ) ) {
			return self::translation_job_failure_after_recovery_rollback( array_merge( $locked, array( 'published' => false, 'mutation_started' => $recover_staged_mutation, 'mutation_cas_revision' => $prior_mutation_cas_revision, 'rollback_authorized' => false ) ) );
		}
		$expected_before = (string) ( $input['expected_mutation_cas_revision'] ?? '' );
		$current_before = self::translation_job_rollback_cas_revision( $translation_id, $term_scope, $identity_scope );
		if ( '' === $expected_before || '' === $current_before || ! hash_equals( $expected_before, $current_before ) ) {
			$rollback = self::translation_job_rollback_recovery_transaction();
			self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
			return array_merge( array( 'success' => false, 'code' => 'publication_surface_changed_before_locked_transition', 'message' => 'The translation surface changed before the publication transaction acquired ownership.', 'published' => false, 'mutation_started' => ! empty( $input['recover_staged_mutation'] ), 'mutation_cas_revision' => $expected_before, 'rollback_authorized' => false ), self::translation_job_rollback_response_fields( $rollback ) );
		}
		$transition = self::apply_translation_publish_transition( $translation_id, $language, $source_id, $term_scope );
		if ( empty( $transition['success'] ) ) {
			$rollback = self::translation_job_rollback_recovery_transaction();
			self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
			$transition['mutation_started'] = $recover_staged_mutation;
			$transition['mutation_cas_revision'] = $current_before;
			$transition['rollback_authorized'] = ! empty( $rollback['success'] ) && ! empty( $rollback['rolled_back'] );
			if ( $transition['rollback_authorized'] ) { $transition['rollback_expected_surface_revision'] = $current_before; }
			return array_merge( $transition, self::translation_job_rollback_response_fields( $rollback ) );
		}
		$post = get_post( $translation_id );
		// The receipt is captured while editor/meta/taxonomy rows are still
		// locked. Concurrent writes can only proceed after commit and will then
		// differ from this receipt before any rollback begins.
		$mutation_cas_revision = self::translation_job_rollback_cas_revision( $translation_id, $term_scope, $identity_scope );
		if ( '' === $mutation_cas_revision ) {
			$rollback = self::translation_job_rollback_recovery_transaction();
			self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
			$rollback_authorized = ! empty( $rollback['success'] ) && ! empty( $rollback['rolled_back'] );
			return array_merge( array( 'success' => false, 'code' => 'publication_mutation_receipt_failed', 'message' => 'The publication transaction could not produce its exact recovery receipt.', 'published' => false, 'transition' => $transition, 'mutation_started' => $recover_staged_mutation, 'mutation_cas_revision' => $current_before, 'rollback_authorized' => $rollback_authorized, 'rollback_expected_surface_revision' => $rollback_authorized ? $current_before : '' ), self::translation_job_rollback_response_fields( $rollback ) );
		}
			$commit = apply_filters( 'devenia_workflow_localized_presentation_commit_adapter_result', null, $translation_id, $current_before, $mutation_cas_revision );
			$commit = null === $commit ? self::translation_job_commit_recovery_transaction() : self::translation_job_recovery_commit_adapter_receipt( $commit );
			$receipt_validation = self::translation_job_require_recovery_commit_receipt( $commit );
			self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
			$observed_mutation_revision = self::translation_job_rollback_cas_revision( $translation_id, $term_scope, $identity_scope );
			$committed = $receipt_validation['committed'];
			$before_exact = '' !== $observed_mutation_revision && hash_equals( $current_before, $observed_mutation_revision );
			$replacement_exact = '' !== $observed_mutation_revision && hash_equals( $mutation_cas_revision, $observed_mutation_revision );
			$commit_reconciliation = array( 'committed' => $committed, 'receipt_validation' => $receipt_validation, 'before_exact' => $before_exact, 'replacement_exact' => $replacement_exact, 'before_revision' => $current_before, 'replacement_revision' => $mutation_cas_revision, 'observed_revision' => $observed_mutation_revision );
			if ( empty( $receipt_validation['valid'] ) ) {
				return array_merge( array( 'success' => false, 'code' => 'publication_transaction_commit_receipt_invalid', 'severity' => 'critical', 'message' => 'The localized presentation commit Adapter returned an invalid receipt.', 'published' => null, 'transition' => $transition, 'mutation_started' => ! $before_exact, 'mutation_cas_revision' => '', 'observed_mutation_cas_revision' => $observed_mutation_revision, 'rollback_authorized' => false, 'transaction_commit' => $commit, 'commit_reconciliation' => array_merge( $commit_reconciliation, array( 'state_outcome' => 'invalid_receipt' ) ) ), self::translation_job_recovery_transaction_error_fields() );
			}
			if ( $before_exact && ( false === $committed || null === $committed ) ) {
					return array_merge( array( 'success' => false, 'code' => false === $committed ? 'publication_transaction_commit_rolled_back' : 'publication_transaction_commit_outcome_unknown_unapplied', 'severity' => null === $committed ? 'critical' : 'error', 'message' => 'The localized presentation mutation is proven unapplied.', 'published' => false, 'transition' => $transition, 'mutation_started' => $recover_staged_mutation, 'mutation_cas_revision' => $current_before, 'rollback_authorized' => true, 'rollback_expected_surface_revision' => $current_before, 'transaction_commit' => $commit, 'commit_reconciliation' => $commit_reconciliation ), self::translation_job_recovery_transaction_error_fields() );
			}
			if ( $replacement_exact && ( true === $committed || null === $committed ) ) {
				$mutation_cas_revision = $observed_mutation_revision;
				$commit_reconciliation['state_outcome'] = 'applied';
			} else {
				return array_merge( array( 'success' => false, 'code' => 'publication_transaction_commit_reconciliation_conflict', 'severity' => 'critical', 'message' => 'The localized presentation commit outcome conflicts with the exact observed public mutation surface.', 'published' => null, 'transition' => $transition, 'mutation_started' => true, 'mutation_cas_revision' => '', 'observed_mutation_cas_revision' => $observed_mutation_revision, 'rollback_authorized' => false, 'transaction_commit' => $commit, 'commit_reconciliation' => array_merge( $commit_reconciliation, array( 'state_outcome' => 'foreign' ) ) ), self::translation_job_recovery_transaction_error_fields() );
			}
			$post = get_post( $translation_id );
		} catch ( Throwable $error ) {
			$rollback = self::translation_job_rollback_recovery_transaction();
			self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
			$observed_after_exception = '' !== $mutation_cas_revision ? self::translation_job_rollback_cas_revision( $translation_id, $term_scope, $identity_scope ) : '';
			$exception_before_exact = '' !== $current_before && '' !== $observed_after_exception && hash_equals( $current_before, $observed_after_exception );
			$exception_replacement_exact = '' !== $mutation_cas_revision && '' !== $observed_after_exception && hash_equals( $mutation_cas_revision, $observed_after_exception );
			if ( $exception_before_exact ) {
				return array_merge( array( 'success' => false, 'code' => 'publication_transaction_exception_unapplied', 'severity' => 'error', 'message' => 'The localized presentation transaction stopped unexpectedly and its mutation is proven unapplied.', 'published' => false, 'transition' => $transition, 'mutation_started' => $recover_staged_mutation, 'mutation_cas_revision' => $current_before, 'rollback_authorized' => true, 'rollback_expected_surface_revision' => $current_before, 'transaction_commit' => $commit, 'commit_reconciliation' => array( 'state_outcome' => 'unapplied', 'observed_revision' => $observed_after_exception ) ), self::translation_job_rollback_response_fields( $rollback ), self::translation_job_recovery_transaction_error_fields() );
			}
			if ( $exception_replacement_exact ) {
				return array_merge( array( 'success' => false, 'code' => 'publication_transaction_exception_applied', 'severity' => 'critical', 'message' => 'The localized presentation mutation is applied, but the transaction Adapter stopped before publication could safely continue.', 'published' => true, 'transition' => $transition, 'mutation_started' => true, 'mutation_cas_revision' => $observed_after_exception, 'rollback_authorized' => true, 'rollback_expected_surface_revision' => $observed_after_exception, 'transaction_commit' => $commit, 'commit_reconciliation' => array( 'state_outcome' => 'applied', 'observed_revision' => $observed_after_exception ) ), self::translation_job_rollback_response_fields( $rollback ), self::translation_job_recovery_transaction_error_fields() );
			}
			if ( '' !== $observed_after_exception ) {
				return array_merge( array( 'success' => false, 'code' => 'publication_transaction_exception_reconciliation_conflict', 'severity' => 'critical', 'message' => 'The localized presentation transaction stopped with a foreign observed public mutation surface.', 'published' => null, 'transition' => $transition, 'mutation_started' => true, 'mutation_cas_revision' => '', 'observed_mutation_cas_revision' => $observed_after_exception, 'rollback_authorized' => false, 'transaction_commit' => $commit, 'commit_reconciliation' => array( 'state_outcome' => 'foreign', 'observed_revision' => $observed_after_exception ) ), self::translation_job_rollback_response_fields( $rollback ), self::translation_job_recovery_transaction_error_fields() );
			}
			return array_merge( array( 'success' => false, 'code' => 'publication_transaction_exception', 'severity' => 'critical', 'message' => 'The localized presentation transaction stopped unexpectedly before an exact mutation outcome could be observed.', 'published' => null, 'mutation_started' => $recover_staged_mutation, 'mutation_cas_revision' => '', 'observed_mutation_cas_revision' => $prior_mutation_cas_revision, 'rollback_authorized' => false ), self::translation_job_rollback_response_fields( $rollback ), self::translation_job_recovery_transaction_error_fields() );
			}

		$purge_urls = self::localized_presentation_purge_urls( $language, (array) ( $transition['purge_urls'] ?? array() ) );
		$context    = array(
			'event'          => 'localized_presentation_publication',
			'language'       => $language,
			'translation_id' => $translation_id,
			'job_id'         => $job_id,
		);
		$invalidation = apply_filters( 'devenia_workflow_frontend_cache_invalidation_result', null, $purge_urls, $context );
		if ( ! is_array( $invalidation ) ) {
			return array(
				'success'            => false,
				'code'               => 'frontend_cache_adapter_missing',
				'message'            => 'Content was published, but no Frontend Cache Adapter acknowledged invalidation.',
				'published'          => true,
				'transition'         => $transition,
				'purge_urls'         => $purge_urls,
				'cache_invalidation' => null,
				'mutation_cas_revision' => $mutation_cas_revision,
				'rollback_authorized' => true,
				'rollback_expected_surface_revision' => $mutation_cas_revision,
				'transaction_commit' => $commit,
				'commit_reconciliation' => $commit_reconciliation,
			);
		}
		if ( true !== ( $invalidation['success'] ?? null ) ) {
			return array(
				'success'            => false,
				'code'               => sanitize_key( (string) ( $invalidation['code'] ?? 'frontend_cache_invalidation_failed' ) ),
				'message'            => 'Content was published, but frontend cache invalidation failed.',
				'published'          => true,
				'transition'         => $transition,
				'purge_urls'         => $purge_urls,
				'cache_invalidation' => $invalidation,
				'mutation_cas_revision' => $mutation_cas_revision,
				'rollback_authorized' => true,
				'rollback_expected_surface_revision' => $mutation_cas_revision,
				'transaction_commit' => $commit,
				'commit_reconciliation' => $commit_reconciliation,
			);
		}

		// Live verification is a separate, callable step — not bundled
		// with publish. Callers such as Translation Job publication verify
		// the live surface through translation-job-verify-live after publish
		// returns. This keeps the publication response fast and the Module
		// invariant intact: verification is mandatory, but it is not
		// synchronous with content mutation.

		return array(
			'success'            => true,
			'published'          => true,
			'transition'         => $transition,
			'purge_urls'         => $purge_urls,
			'cache_invalidation' => $invalidation,
			'needs_live_verification' => true,
			'mutation_cas_revision' => $mutation_cas_revision,
			'rollback_authorized' => true,
			'rollback_expected_surface_revision' => $mutation_cas_revision,
			'transaction_commit' => $commit,
			'commit_reconciliation' => $commit_reconciliation,
		);
	}

	/** Lock the option, menu term, every item row/meta row, and all item relationships. */
	private static function lock_localized_menu_projection_surface( int $menu_id ): array {
		global $wpdb;
		$queries = array(
			$wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE", self::OPTION_LOCALIZED_MENU_IDENTITIES ),
			$wpdb->prepare( "SELECT term_id FROM {$wpdb->terms} WHERE term_id = %d FOR UPDATE", $menu_id ),
			$wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d FOR UPDATE", $menu_id ),
			$wpdb->prepare( "SELECT meta_id FROM {$wpdb->termmeta} WHERE term_id = %d FOR UPDATE", $menu_id ),
			$wpdb->prepare( "SELECT tr.object_id, tr.term_taxonomy_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.term_id = %d FOR UPDATE", $menu_id ),
			$wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.term_id = %d FOR UPDATE", $menu_id ),
			$wpdb->prepare( "SELECT pm.meta_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = pm.post_id INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.term_id = %d FOR UPDATE", $menu_id ),
			$wpdb->prepare( "SELECT owned.object_id, owned.term_taxonomy_id FROM {$wpdb->term_relationships} owned WHERE owned.object_id IN (SELECT menu_items.object_id FROM {$wpdb->term_relationships} menu_items INNER JOIN {$wpdb->term_taxonomy} menu_tt ON menu_tt.term_taxonomy_id = menu_items.term_taxonomy_id WHERE menu_tt.term_id = %d) FOR UPDATE", $menu_id ),
		);
		foreach ( $queries as $query ) {
			if ( false === $wpdb->query( $query ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared row/range locks make complete menu deletion atomic.
				return array( 'success' => false, 'action' => 'public_header_lock_conflict', 'error' => 'public_header_menu_surface_lock_failed' );
			}
		}
		clean_term_cache( array( $menu_id ) );
		return array( 'success' => true );
	}

	/** Fingerprint the complete managed nav-menu term and all owned item posts/meta. */
	private static function localized_menu_projection_revision( int $menu_id ): string {
		if ( $menu_id < 1 ) { return ''; }
		clean_term_cache( array( $menu_id ) );
		$term_state = self::translation_job_taxonomy_term_state( $menu_id, 'nav_menu' );
		if ( is_wp_error( $term_state ) ) { return ''; }
		$items = array();
		foreach ( (array) ( $term_state['objects'] ?? array() ) as $item_id ) {
			$item_id = absint( $item_id );
			clean_post_cache( $item_id );
			$post = get_post( $item_id );
			if ( ! $post instanceof WP_Post || 'nav_menu_item' !== (string) $post->post_type ) { return ''; }
			$taxonomy_assignments = array();
			foreach ( get_object_taxonomies( (string) $post->post_type ) as $taxonomy ) {
				$ids = wp_get_object_terms( $item_id, $taxonomy, array( 'fields' => 'ids' ) );
				if ( is_wp_error( $ids ) ) { return ''; }
				$ids = array_values( array_map( 'absint', (array) $ids ) );
				sort( $ids );
				$taxonomy_assignments[ $taxonomy ] = $ids;
			}
			$items[ $item_id ] = array(
				'post' => array(
					'ID' => (int) $post->ID, 'post_author' => (int) $post->post_author, 'post_date' => (string) $post->post_date,
					'post_date_gmt' => (string) $post->post_date_gmt, 'post_content' => (string) $post->post_content,
					'post_title' => (string) $post->post_title, 'post_excerpt' => (string) $post->post_excerpt,
					'post_status' => (string) $post->post_status, 'comment_status' => (string) $post->comment_status,
					'ping_status' => (string) $post->ping_status, 'post_password' => (string) $post->post_password,
					'to_ping' => (string) $post->to_ping, 'pinged' => (string) $post->pinged,
					'post_name' => (string) $post->post_name, 'post_modified' => (string) $post->post_modified,
					'post_modified_gmt' => (string) $post->post_modified_gmt, 'post_content_filtered' => (string) $post->post_content_filtered,
					'post_parent' => (int) $post->post_parent, 'guid' => (string) $post->guid,
					'menu_order' => (int) $post->menu_order, 'post_type' => (string) $post->post_type,
					'post_mime_type' => (string) $post->post_mime_type, 'comment_count' => (int) $post->comment_count,
				),
				'meta' => get_post_meta( $item_id ),
				'taxonomies' => $taxonomy_assignments,
			);
		}
		ksort( $items );
		return 'mcas_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( array( 'term' => $term_state, 'items' => $items ) ) ) ?: '' ), 0, 40 );
	}

	/**
	 * Include the language root because every primary-menu projection affects it.
	 *
	 * @param string[] $transition_urls URLs produced by the content transition.
	 * @return string[]
	 */
	private static function localized_presentation_purge_urls( string $language, array $transition_urls ): array {
		$urls = array_merge(
			$transition_urls,
			array(
				self::localized_home_url_for_language( $language ),
				self::public_blog_archive_url_for_language( $language ),
			)
		);
		$urls = apply_filters( 'devenia_workflow_localized_presentation_purge_urls', $urls, $language );
		if ( ! is_array( $urls ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
	}

	/**
	 * Stage the first managed Public Header Projection
	 * from explicitly verified existing WordPress menu identities.
	 *
	 * @param array<string,mixed> $input Enrollment arguments.
	 * @return array<string,mixed>
	 */
	private static function enroll_public_header_from_existing_menus( array $input ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return self::error( 'Public header enrollment requires manage_options.' );
		}
		$missing_option = '__devenia_workflow_option_missing__';
		$before = array(
			'manifest'   => get_option( self::OPTION_PUBLIC_HEADER_MANIFEST, $missing_option ),
			'identities' => get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, $missing_option ),
			'pending'    => get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $missing_option ),
			'enrollment' => get_option( self::OPTION_PUBLIC_HEADER_ENROLLMENT, $missing_option ),
		);
		if ( self::public_header_projection_is_enrolled() || ! empty( self::public_header_manifest() ) ) {
			return array( 'success' => false, 'code' => 'public_header_already_enrolled', 'message' => 'First enrollment is available only before the managed-header activation boundary.' );
		}
		if ( $missing_option !== $before['pending'] ) {
			return array( 'success' => false, 'code' => 'public_header_unenrolled_pending_state_present', 'message' => 'Un-enrolled intake requires an empty pending slot; existing pending state must be classified first.' );
		}

		$languages = self::configured_public_header_languages();
		$source_language = self::source_language_code();
		$source_menu_id = absint( $input['source_menu_id'] ?? 0 );
		$locations = get_nav_menu_locations();
		$primary_menu_id = absint( is_array( $locations ) ? ( $locations['primary'] ?? 0 ) : 0 );
		if ( $source_menu_id < 1 || $source_menu_id !== $primary_menu_id ) {
			return array( 'success' => false, 'code' => 'public_header_source_menu_not_primary', 'message' => 'First enrollment requires the explicitly verified menu currently assigned to the public primary theme location.', 'source_menu_id' => $source_menu_id, 'primary_menu_id' => $primary_menu_id );
		}
		$source_manifest = self::public_header_source_manifest_from_menu( $source_menu_id );
		if ( empty( $source_manifest['success'] ) ) { return $source_manifest; }

		$authority_ids = array();
		foreach ( (array) ( $input['authority_menus'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$language = sanitize_key( (string) ( $row['language'] ?? '' ) );
			$menu_id = absint( $row['menu_id'] ?? 0 );
			if ( ! in_array( $language, $languages, true ) || $menu_id < 1 ) {
				return array( 'success' => false, 'code' => 'public_header_enrollment_authority_invalid', 'message' => 'Every authority menu must bind one configured language to one existing menu identity.' );
			}
			$authority_ids[ $language ][] = $menu_id;
		}
		$authority_ids[ $source_language ][] = $source_menu_id;
		foreach ( $authority_ids as $language => $ids ) { $authority_ids[ $language ] = array_values( array_unique( array_map( 'absint', $ids ) ) ); }
		$retained_menus = wp_get_nav_menus();

		$manifest_items = (array) $source_manifest['items'];
		$authority = array();
		$missing = array();
		foreach ( $languages as $language ) {
			$explicit_ids = (array) ( $authority_ids[ $language ] ?? array() );
			$ids = $explicit_ids;
			if ( $source_language !== $language && empty( $explicit_ids ) ) {
				foreach ( $retained_menus as $retained_menu ) {
					$retained_id = is_object( $retained_menu ) ? absint( $retained_menu->term_id ?? 0 ) : 0;
					if ( $retained_id > 0 && '1' !== (string) get_term_meta( $retained_id, self::TERM_META_MENU_MANAGED, true ) ) { $ids[] = $retained_id; }
				}
				$ids = array_values( array_unique( $ids ) );
			}
			if ( empty( $ids ) ) { $missing[] = array( 'language' => $language, 'reason' => 'authority_provenance_missing' ); continue; }
			$snapshots = array();
			foreach ( $ids as $menu_id ) {
				$menu = wp_get_nav_menu_object( $menu_id );
				$is_explicit = in_array( $menu_id, $explicit_ids, true );
				if ( $source_language !== $language && '1' === (string) get_term_meta( $menu_id, self::TERM_META_MENU_MANAGED, true ) ) {
					if ( $is_explicit ) { $missing[] = array( 'language' => $language, 'menu_id' => $menu_id, 'reason' => 'authority_menu_managed_not_retained' ); }
					continue;
				}
				$snapshot = $menu ? self::public_header_editorial_label_snapshot( $language, $menu_id, $manifest_items ) : array();
				if ( empty( $snapshot['success'] ) && $is_explicit ) {
					$missing[] = array( 'language' => $language, 'menu_id' => $menu_id, 'reason' => (string) ( $snapshot['code'] ?? 'authority_menu_missing' ), 'details' => (array) ( $snapshot['missing'] ?? array() ) );
					continue;
				}
				if ( empty( $snapshot['success'] ) ) { continue; }
				$snapshots[] = array( 'menu_id' => $menu_id, 'menu_name' => (string) $menu->name, 'provenance' => $is_explicit ? 'explicit_authority' : 'retained_relation_match', 'surface_revision' => (string) $snapshot['surface_revision'], 'labels' => (array) $snapshot['labels'], 'relations' => (array) $snapshot['relations'], 'relation_revision' => (string) $snapshot['relation_revision'] );
			}
			if ( empty( $snapshots ) || ( $source_language !== $language && count( $snapshots ) < 2 ) ) {
				$missing[] = array( 'language' => $language, 'reason' => empty( $snapshots ) ? 'authority_provenance_missing' : 'insufficient_independent_authority_candidates', 'candidate_menu_ids' => array_values( array_map( static function ( array $candidate ): int { return (int) $candidate['menu_id']; }, $snapshots ) ) );
				continue;
			}
			$canonical = self::translation_job_canonicalize( array( 'labels' => (array) $snapshots[0]['labels'], 'relations' => (array) $snapshots[0]['relations'], 'relation_revision' => (string) $snapshots[0]['relation_revision'] ) );
			foreach ( $snapshots as $snapshot ) {
				if ( self::translation_job_canonicalize( array( 'labels' => (array) $snapshot['labels'], 'relations' => (array) $snapshot['relations'], 'relation_revision' => (string) $snapshot['relation_revision'] ) ) !== $canonical ) {
					$missing[] = array( 'language' => $language, 'reason' => 'authority_candidate_conflict', 'candidate_menu_ids' => array_values( array_map( static function ( array $candidate ): int { return (int) $candidate['menu_id']; }, $snapshots ) ) );
					continue 2;
				}
			}
			$authority[ $language ] = $snapshots;
			foreach ( (array) $snapshots[0]['labels'] as $source_item_id => $label ) {
				foreach ( $manifest_items as &$manifest_item ) {
					if ( absint( $manifest_item['source_item_id'] ?? 0 ) === absint( $source_item_id ) ) { $manifest_item['labels'][ $language ] = (string) $label; break; }
				}
				unset( $manifest_item );
			}
		}
		if ( ! empty( $missing ) || count( $authority ) !== count( $languages ) ) {
			return array( 'success' => false, 'code' => 'public_header_enrollment_authority_incomplete', 'message' => 'First enrollment requires complete unambiguous source-item label authority for every configured language.', 'missing' => $missing, 'authority' => $authority );
		}
		foreach ( $manifest_items as &$manifest_item ) { $manifest_item['title'] = sanitize_text_field( (string) ( $manifest_item['labels'][ $source_language ] ?? '' ) ); }
		unset( $manifest_item );
		$normalized = self::normalize_public_header_manifest_items( $manifest_items, true );
		if ( empty( $normalized['success'] ) ) { return array_merge( $normalized, array( 'authority' => $authority ) ); }
		if ( self::translation_job_canonicalize( $before ) !== self::translation_job_canonicalize( array( 'manifest' => get_option( self::OPTION_PUBLIC_HEADER_MANIFEST, $missing_option ), 'identities' => get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, $missing_option ), 'pending' => get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $missing_option ), 'enrollment' => get_option( self::OPTION_PUBLIC_HEADER_ENROLLMENT, $missing_option ) ) ) ) {
			return array( 'success' => false, 'code' => 'public_header_enrollment_state_changed', 'message' => 'Public Header state changed while enrollment authority was being verified.' );
		}
		$current_source_revision = self::localized_menu_projection_revision( $source_menu_id );
		if ( '' === $current_source_revision || ! hash_equals( (string) ( $source_manifest['surface_revision'] ?? '' ), $current_source_revision ) ) {
			return array( 'success' => false, 'code' => 'public_header_source_menu_changed', 'message' => 'The verified source menu changed before enrollment could stage.' );
		}
		foreach ( $authority as $language => $snapshots ) {
			foreach ( $snapshots as $snapshot ) {
				$current_revision = self::localized_menu_projection_revision( absint( $snapshot['menu_id'] ?? 0 ) );
				if ( '' === $current_revision || ! hash_equals( (string) ( $snapshot['surface_revision'] ?? '' ), $current_revision ) ) {
					return array( 'success' => false, 'code' => 'public_header_enrollment_authority_changed', 'message' => 'An accepted authority menu changed before enrollment could stage.', 'language' => $language, 'menu_id' => absint( $snapshot['menu_id'] ?? 0 ) );
				}
			}
		}
		$recovery = self::pre_enrollment_public_header_recovery_snapshot( $languages, absint( $input['timeout'] ?? 15 ), $source_menu_id, (string) $current_source_revision, $authority, $before );
		if ( empty( $recovery['success'] ) ) { return $recovery; }
		$draft_revision = self::public_header_manifest_revision_for_items( (array) $normalized['items'] );
		$authority_receipts = self::public_header_authority_receipts( $authority, $draft_revision );
		if ( count( $authority_receipts ) !== count( $languages ) ) { return array( 'success' => false, 'code' => 'public_header_enrollment_authority_receipt_invalid', 'authority' => $authority ); }
		$draft = array( 'schema_version' => 2, 'source_language' => $source_language, 'revision' => $draft_revision, 'items' => (array) $normalized['items'], 'authority_receipts' => $authority_receipts, 'pre_enrollment_recovery' => $recovery );
		$relation_receipts = self::public_header_relation_receipts_for_manifest( $draft );
		if ( empty( $relation_receipts['success'] ) ) { return array_merge( $relation_receipts, array( 'authority' => $authority ) ); }
		$draft['relation_receipts'] = (array) $relation_receipts['receipts'];
		$relation_validation = self::validate_public_header_relation_receipts( $draft );
		if ( empty( $relation_validation['success'] ) ) { return array_merge( $relation_validation, array( 'authority' => $authority ) ); }
		$receipt_validation = self::validate_public_header_authority_receipts( $draft );
		if ( empty( $receipt_validation['success'] ) ) { return array_merge( $receipt_validation, array( 'authority' => $authority ) ); }
		$result = array( 'success' => true, 'staged' => false, 'activated' => false, 'draft' => $draft, 'authority' => $authority );
		if ( empty( $input['stage'] ) ) { return $result; }
		$stage = self::stage_first_public_header_enrollment_transaction( $before, $draft, $source_menu_id, $authority );
		return array_merge( $result, array( 'success' => ! empty( $stage['success'] ) && ! empty( $stage['pending'] ), 'staged' => ! empty( $stage['success'] ) && ! empty( $stage['pending'] ), 'stage_result' => $stage, 'activation_receipt' => (string) ( $stage['activation_receipt'] ?? '' ), 'requires_explicit_activation' => true ) );
	}

	/** Atomically bind primary assignment and all authority receipts to pending enrollment. */
	private static function stage_first_public_header_enrollment_transaction( array $before, array $draft, int $source_menu_id, array $authority ): array {
		if ( ! self::translation_job_begin_recovery_transaction() ) { return array( 'success' => false, 'code' => 'public_header_enrollment_transaction_unavailable' ); }
		try {
			global $wpdb;
			$receipt_rows = array();
			foreach ( $authority as $language => $snapshots ) { foreach ( $snapshots as $snapshot ) { $receipt_rows[] = array( 'language' => $language, 'menu_id' => absint( $snapshot['menu_id'] ?? 0 ), 'receipt' => (string) ( $snapshot['surface_revision'] ?? '' ) ); } }
			$relation_lock = self::lock_public_header_relation_authority_surface( $draft, $before, $before, array() );
			if ( empty( $relation_lock['success'] ) ) { self::rollback_first_public_header_enrollment_transaction(); return array( 'success' => false, 'code' => 'public_header_enrollment_relation_authority_lock_failed', 'relation_lock' => $relation_lock ); }
			$theme_option = 'theme_mods_' . (string) get_option( 'stylesheet' );
			$locked_options = $wpdb->query( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name IN (%s, %s, %s, %s, %s) FOR UPDATE", self::OPTION_PUBLIC_HEADER_MANIFEST, self::OPTION_LOCALIZED_MENU_IDENTITIES, self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, self::OPTION_PUBLIC_HEADER_ENROLLMENT, $theme_option ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Enrollment needs one locked primary assignment plus four option rows.
			if ( false === $locked_options ) { self::rollback_first_public_header_enrollment_transaction( $theme_option ); return array( 'success' => false, 'code' => 'public_header_enrollment_state_lock_failed' ); }
			do_action( 'devenia_workflow_public_header_enrollment_before_locked_stage_revalidation', $draft, $authority );
			wp_cache_delete( $theme_option, 'options' ); self::clear_public_header_state_option_cache();
			$relation_validation = self::validate_public_header_relation_receipts( $draft );
			if ( empty( $relation_validation['success'] ) ) { self::rollback_first_public_header_enrollment_transaction( $theme_option ); return array( 'success' => false, 'code' => 'public_header_enrollment_relation_changed_at_locked_boundary', 'relation_validation' => $relation_validation ); }
			foreach ( $receipt_rows as $row ) {
				$current = self::localized_menu_projection_revision( (int) $row['menu_id'] );
				if ( '' === $current || ! hash_equals( (string) $row['receipt'], $current ) ) { self::rollback_first_public_header_enrollment_transaction( $theme_option ); return array( 'success' => false, 'code' => 'public_header_enrollment_authority_changed_at_locked_boundary', 'language' => $row['language'], 'menu_id' => $row['menu_id'] ); }
			}
			$authority_validation = self::validate_public_header_authority_receipts( $draft );
			if ( empty( $authority_validation['success'] ) ) { self::rollback_first_public_header_enrollment_transaction( $theme_option ); return array( 'success' => false, 'code' => 'public_header_enrollment_authority_changed_at_locked_boundary', 'authority_validation' => $authority_validation ); }
			$locations = get_nav_menu_locations();
			$current_state = array( 'manifest' => get_option( self::OPTION_PUBLIC_HEADER_MANIFEST, '__devenia_workflow_option_missing__' ), 'identities' => get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, '__devenia_workflow_option_missing__' ), 'pending' => get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, '__devenia_workflow_option_missing__' ), 'enrollment' => get_option( self::OPTION_PUBLIC_HEADER_ENROLLMENT, '__devenia_workflow_option_missing__' ) );
			if ( $source_menu_id !== absint( is_array( $locations ) ? ( $locations['primary'] ?? 0 ) : 0 ) || self::translation_job_canonicalize( $current_state ) !== self::translation_job_canonicalize( $before ) ) { self::rollback_first_public_header_enrollment_transaction( $theme_option ); return array( 'success' => false, 'code' => 'public_header_enrollment_locked_state_changed' ); }
			$pending = $draft; $pending['updated_at'] = gmdate( 'c' );
			if ( ! self::atomic_create_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $pending ) ) { self::rollback_first_public_header_enrollment_transaction( $theme_option ); return array( 'success' => false, 'code' => 'public_header_enrollment_pending_write_failed' ); }
			$commit = apply_filters( 'devenia_workflow_public_header_enrollment_commit_adapter_result', null, $before, $pending );
			$commit = null === $commit ? self::translation_job_commit_recovery_transaction() : self::translation_job_recovery_commit_adapter_receipt( $commit );
			self::clear_first_public_header_enrollment_option_cache( $theme_option );
			$expected_state = $before; $expected_state['pending'] = $pending;
			$reconciled = self::reconcile_first_public_header_enrollment_commit_outcome( $before, $pending, $commit );
			$stored_pending = ! empty( $reconciled['success'] ) ? get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, array() ) : array();
			$activation_receipt = is_array( $stored_pending ) && $stored_pending === $pending ? self::public_header_activation_receipt( $stored_pending ) : '';
			return array_merge( $reconciled, array( 'pending' => ! empty( $reconciled['success'] ), 'revision' => (string) $draft['revision'], 'activation_receipt' => $activation_receipt, 'authority_receipts' => $receipt_rows, 'expected_state' => $expected_state, 'expected_state_revision' => hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $expected_state ) ) ?: '' ) ) );
		} catch ( Throwable $error ) { self::rollback_first_public_header_enrollment_transaction(); return array( 'success' => false, 'code' => 'public_header_enrollment_transaction_exception' ); }
	}

	/** Clear option caches changed inside a transaction before its terminal outcome. */
	private static function clear_first_public_header_enrollment_option_cache( string $theme_option = '' ): void {
		self::clear_public_header_state_option_cache();
		if ( '' !== $theme_option ) { wp_cache_delete( $theme_option, 'options' ); }
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/** Roll back first-enrollment staging and discard every transactional option cache value. */
	private static function rollback_first_public_header_enrollment_transaction( string $theme_option = '' ): void {
		self::rollback_public_header_state_transaction();
		self::clear_first_public_header_enrollment_option_cache( $theme_option );
	}

	/** Reconcile the three-valued transaction Adapter outcome against exact option evidence. */
	private static function reconcile_first_public_header_enrollment_commit_outcome( array $before, array $pending, array $commit ): array {
		$receipt_validation = self::translation_job_require_recovery_commit_receipt( $commit );
		$read = static function (): array {
			$database = self::public_header_database_state();
			return ! empty( $database['success'] ) ? (array) $database['state'] : array();
		};
		$current = $read();
		if ( empty( $current ) ) { return array( 'success' => false, 'code' => 'public_header_enrollment_commit_reconciliation_read_failed', 'severity' => 'critical', 'commit' => $commit, 'receipt_validation' => $receipt_validation ); }
		$committed = $receipt_validation['committed'];
		$expected_after = $before; $expected_after['pending'] = $pending;
		$pre_state_proven = self::translation_job_canonicalize( $current ) === self::translation_job_canonicalize( $before );
		$initial_pre_state_proven = $pre_state_proven;
		$applied_state_proven = self::translation_job_canonicalize( $current ) === self::translation_job_canonicalize( $expected_after );
		$state_class = $pre_state_proven ? 'expected' : ( $applied_state_proven ? 'replacement' : 'foreign' );
		$restore = array();
		$base = array( 'success' => false, 'commit' => $commit, 'committed' => $committed, 'receipt_validation' => $receipt_validation, 'state_class' => $state_class, 'reconciliation' => array( 'initial_pre_state_proven' => $pre_state_proven, 'pre_state_proven' => $pre_state_proven, 'applied_state_observed' => $applied_state_proven, 'foreign_state_observed' => ! $pre_state_proven && ! $applied_state_proven, 'restore' => $restore, 'expected' => $before, 'expected_after' => $expected_after, 'actual' => $current ) );
		if ( empty( $receipt_validation['valid'] ) ) { return array_merge( $base, array( 'code' => 'public_header_enrollment_commit_receipt_invalid', 'severity' => 'critical', 'state_outcome' => 'invalid_receipt' ) ); }
		$commit_succeeded = true === ( $commit['success'] ?? null ) && true === $committed;
		if ( ! $commit_succeeded && ! $pre_state_proven && $applied_state_proven ) {
			$restore = self::replace_public_header_state_transaction( $current, $before, array() );
			self::clear_public_header_state_option_cache();
			$current = $read();
			$pre_state_proven = self::translation_job_canonicalize( $current ) === self::translation_job_canonicalize( $before );
		}
		$foreign_state_observed = ! $initial_pre_state_proven && ! $applied_state_proven;
		$base = array( 'success' => false, 'commit' => $commit, 'committed' => $committed, 'receipt_validation' => $receipt_validation, 'state_class' => $state_class, 'reconciliation' => array( 'initial_pre_state_proven' => $initial_pre_state_proven, 'pre_state_proven' => $pre_state_proven, 'applied_state_observed' => $applied_state_proven, 'foreign_state_observed' => $foreign_state_observed, 'restore' => $restore, 'expected' => $before, 'expected_after' => $expected_after, 'actual' => $current ) );
		if ( $commit_succeeded && $applied_state_proven ) { return array_merge( $base, array( 'success' => true, 'code' => 'public_header_enrollment_commit_applied', 'state_outcome' => 'applied' ) ); }
		if ( false === $committed && $initial_pre_state_proven ) { return array_merge( $base, array( 'code' => 'public_header_enrollment_commit_rolled_back' ) ); }
		if ( false === $committed ) { return array_merge( $base, array( 'code' => $foreign_state_observed ? 'public_header_enrollment_commit_reconciliation_conflict' : 'public_header_enrollment_commit_rollback_evidence_mismatch', 'severity' => 'critical' ) ); }
		if ( true === $committed && $pre_state_proven ) { return array_merge( $base, array( 'code' => 'public_header_enrollment_commit_applied_then_restored' ) ); }
		if ( true === $committed && $foreign_state_observed ) { return array_merge( $base, array( 'code' => 'public_header_enrollment_commit_reconciliation_conflict', 'severity' => 'critical' ) ); }
		if ( null === $committed && $pre_state_proven ) { return array_merge( $base, array( 'code' => 'public_header_enrollment_commit_outcome_unknown_reconciled', 'severity' => 'critical' ) ); }
		if ( null === $committed && $foreign_state_observed ) { return array_merge( $base, array( 'code' => 'public_header_enrollment_commit_outcome_unknown_conflict', 'severity' => 'critical' ) ); }
		return array_merge( $base, array( 'code' => 'public_header_enrollment_commit_reconciliation_failed', 'severity' => 'critical' ) );
	}

	/** Capture the exact pre-enrollment public reader menu on origin and canonical surfaces. */
	private static function pre_enrollment_public_header_recovery_snapshot( array $languages, int $timeout, int $source_menu_id, string $source_receipt, array $authority, array $pre_state ): array {
		$expected = array(); $evidence = array();
		$response_set = self::public_header_frontend_cache_response_set( $languages, $timeout );
		foreach ( $languages as $language ) {
			$observed = array();
			foreach ( array( 'homepage' => self::localized_home_url_for_language( $language ), 'blog_archive' => self::public_blog_archive_url_for_language( $language ) ) as $surface => $url ) {
				foreach ( array( 'origin', 'canonical' ) as $cache_surface ) {
					$response = (array) ( $response_set[ $language ][ $surface ][ $cache_surface ] ?? array() );
					$navigation = ! empty( $response['success'] ) && 200 === (int) ( $response['status_code'] ?? 0 ) ? self::primary_navigation_from_html( (string) ( $response['body'] ?? '' ) ) : array();
					if ( empty( $navigation ) ) {
						return array(
							'success' => false,
							'code' => 'public_header_pre_enrollment_oracle_missing',
							'language' => $language,
							'surface' => $surface,
							'cache_surface' => $cache_surface,
							'response_evidence' => array_merge( array_diff_key( $response, array( 'body' => true ) ), array( 'body_length' => strlen( (string) ( $response['body'] ?? '' ) ) ) ),
						);
					}
					$observed[] = $navigation; $evidence[ $language ][ $surface ][ $cache_surface ] = array_diff_key( $response, array( 'body' => true ) );
				}
			}
			$canonical = self::translation_job_canonicalize( $observed[0] );
			if ( ! empty( array_filter( $observed, static function ( array $navigation ) use ( $canonical ): bool { return self::translation_job_canonicalize( $navigation ) !== $canonical; } ) ) ) { return array( 'success' => false, 'code' => 'public_header_pre_enrollment_oracle_conflict', 'language' => $language ); }
			$expected[ $language ] = $observed[0];
		}
		return array( 'success' => true, 'source_menu_id' => $source_menu_id, 'source_receipt' => $source_receipt, 'authority' => $authority, 'expected_navigation' => $expected, 'pre_state' => $pre_state, 'evidence' => $evidence, 'revision' => 'pher_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( array( $source_menu_id, $source_receipt, $authority, $expected, $pre_state ) ) ) ?: '' ), 0, 40 ) );
	}

	/** Build stable source item identities and hierarchy from one verified source menu. */
	private static function public_header_source_manifest_from_menu( int $menu_id ): array {
		$menu = $menu_id > 0 ? wp_get_nav_menu_object( $menu_id ) : false;
		if ( ! $menu ) { return array( 'success' => false, 'code' => 'public_header_source_menu_missing', 'message' => 'A verified existing source menu identity is required.' ); }
		$items = wp_get_nav_menu_items( $menu_id, array( 'orderby' => 'menu_order' ) ) ?: array();
		if ( empty( $items ) ) { return array( 'success' => false, 'code' => 'public_header_source_menu_empty', 'message' => 'The verified source menu is empty.' ); }
		$known_ids = array(); foreach ( $items as $item ) { if ( is_object( $item ) && ! empty( $item->ID ) ) { $known_ids[ (int) $item->ID ] = true; } }
		$manifest = array();
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || empty( $item->ID ) ) { return array( 'success' => false, 'code' => 'public_header_source_menu_item_invalid' ); }
			$type = (string) ( $item->type ?? '' );
			$title = sanitize_text_field( (string) ( $item->title ?? '' ) );
			$parent = absint( $item->menu_item_parent ?? 0 );
			if ( '' === $title || ( $parent > 0 && ! isset( $known_ids[ $parent ] ) ) ) { return array( 'success' => false, 'code' => 'public_header_source_menu_item_invalid' ); }
			$row = array( 'source_item_id' => (int) $item->ID, 'type' => '', 'title' => $title, 'parent_source_item_id' => $parent, 'position' => absint( $item->menu_order ?? 0 ) );
			if ( 'post_type' === $type && 'page' === (string) ( $item->object ?? '' ) ) {
				$object_id = absint( $item->object_id ?? 0 ); $post = $object_id ? get_post( $object_id ) : null;
				if ( ! $post instanceof WP_Post || 'publish' !== (string) $post->post_status || self::is_translation_post( $object_id ) ) { return array( 'success' => false, 'code' => 'public_header_source_menu_page_invalid', 'source_item_id' => (int) $item->ID ); }
				$row['type'] = 'page'; $row['object_id'] = $object_id;
			} elseif ( 'custom' === $type ) {
				$url = esc_url_raw( (string) ( $item->url ?? '' ) ); if ( '' === $url ) { return array( 'success' => false, 'code' => 'public_header_source_menu_url_invalid', 'source_item_id' => (int) $item->ID ); }
				$row['type'] = 'custom'; $row['url'] = $url;
			} else { return array( 'success' => false, 'code' => 'public_header_source_menu_type_unsupported', 'source_item_id' => (int) $item->ID ); }
			$manifest[] = $row;
		}
		$normalized = self::normalize_public_header_manifest_items( $manifest, false );
		return empty( $normalized['success'] ) ? $normalized : array( 'success' => true, 'items' => (array) $normalized['items'], 'menu_id' => $menu_id, 'surface_revision' => self::localized_menu_projection_revision( $menu_id ) );
	}

	/**
	 * Snapshot established pre-managed menu labels into a complete schema-2
	 * manifest draft. Raw WordPress menus are accepted only as one-time
	 * migration input; the returned/staged manifest becomes the authority.
	 *
	 * @param array<string,mixed> $input Migration arguments.
	 * @return array<string,mixed>
	 */
	private static function migrate_public_header_label_authority( array $input ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return self::error( 'Public header label-authority migration requires manage_options.' );
		}
		$active = self::public_header_manifest();
		if ( empty( $active ) || 1 !== absint( $active['schema_version'] ?? 0 ) ) {
			return array( 'success' => false, 'code' => 'public_header_legacy_manifest_missing', 'message' => 'Label-authority migration requires one valid active schema-1 manifest.' );
		}

		$languages = self::languages();
		$configured_languages = self::configured_public_header_languages();
		$known = array();
		foreach ( (array) ( $input['authority_menus'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$language = sanitize_key( (string) ( $row['language'] ?? '' ) );
			$menu_id = absint( $row['menu_id'] ?? 0 );
			if ( '' === $language || ! in_array( $language, $configured_languages, true ) || $menu_id < 1 ) {
				return array( 'success' => false, 'code' => 'public_header_label_authority_menu_invalid', 'message' => 'Known authority-menu identities must be configured language/menu pairs.' );
			}
			$known[ $language ][] = $menu_id;
		}

		$identities = get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, array() );
		$identities = is_array( $identities ) ? $identities : array();
		$labels_by_item = array();
		$authority = array();
		$missing = array();
		$generated_drift = array();
		foreach ( $configured_languages as $language ) {
			$config = isset( $languages[ $language ] ) && is_array( $languages[ $language ] ) ? $languages[ $language ] : array();
			$explicit_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $known[ $language ] ?? array() ) ) ) ) );
			$candidate_ids = $explicit_ids;
			$name = sanitize_text_field( (string) ( $config['menu_name'] ?? '' ) );
			$configured_menu = '' !== $name ? wp_get_nav_menu_object( $name ) : false;
			$configured_menu_id = $configured_menu ? absint( $configured_menu->term_id ?? 0 ) : 0;
			if ( empty( $explicit_ids ) ) {
				if ( $configured_menu_id > 0 ) { $candidate_ids[] = $configured_menu_id; }
				foreach ( wp_get_nav_menus() as $retained_menu ) {
					if ( is_object( $retained_menu ) && ! empty( $retained_menu->term_id ) ) { $candidate_ids[] = absint( $retained_menu->term_id ); }
				}
			}
			$candidate_ids = array_values( array_unique( array_filter( $candidate_ids ) ) );
			$candidate_snapshots = array();
			foreach ( $candidate_ids as $menu_id ) {
				$menu = wp_get_nav_menu_object( $menu_id );
				$is_explicit = in_array( $menu_id, (array) ( $known[ $language ] ?? array() ), true );
				$managed_language = sanitize_key( (string) get_term_meta( $menu_id, self::TERM_META_MENU_LANGUAGE, true ) );
				$is_managed = '1' === (string) get_term_meta( $menu_id, self::TERM_META_MENU_MANAGED, true );
				if ( ! $menu || ( '' !== $managed_language && $managed_language !== $language ) || $is_managed ) {
					if ( $is_explicit ) {
						$missing[] = array( 'language' => $language, 'menu_id' => $menu_id, 'reason' => ! $menu ? 'authority_menu_missing' : ( $is_managed ? 'authority_menu_managed_not_retained' : 'authority_menu_language_mismatch' ) );
					}
					continue;
				}
				$snapshot = self::public_header_editorial_label_snapshot( $language, $menu_id, (array) $active['items'] );
				if ( ! empty( $snapshot['success'] ) ) {
					$resolved_from = $is_explicit ? 'known_identity' : ( $menu_id === $configured_menu_id ? 'configured_name' : 'retained_relation_match' );
					$candidate_snapshots[] = array( 'menu_id' => $menu_id, 'menu_name' => (string) $menu->name, 'resolved_from' => $resolved_from, 'surface_revision' => (string) $snapshot['surface_revision'], 'labels' => (array) $snapshot['labels'], 'relations' => (array) $snapshot['relations'], 'relation_revision' => (string) $snapshot['relation_revision'] );
				} elseif ( $is_explicit ) {
					$missing[] = array( 'language' => $language, 'menu_id' => $menu_id, 'reason' => (string) ( $snapshot['code'] ?? 'authority_menu_invalid' ), 'details' => (array) ( $snapshot['missing'] ?? array() ) );
				}
			}
			if ( count( $candidate_snapshots ) < 2 ) {
				$missing[] = array( 'language' => $language, 'reason' => empty( $candidate_snapshots ) ? 'authority_menu_missing_or_incomplete' : 'insufficient_independent_authority_candidates', 'candidate_menu_ids' => array_values( array_map( static function ( array $candidate ): int { return (int) $candidate['menu_id']; }, $candidate_snapshots ) ) );
				continue;
			}
			$canonical_labels = self::translation_job_canonicalize( array( 'labels' => (array) $candidate_snapshots[0]['labels'], 'relations' => (array) $candidate_snapshots[0]['relations'], 'relation_revision' => (string) $candidate_snapshots[0]['relation_revision'] ) );
			$conflicting = array_values( array_filter( $candidate_snapshots, static function ( array $candidate ) use ( $canonical_labels ): bool { return self::translation_job_canonicalize( array( 'labels' => (array) $candidate['labels'], 'relations' => (array) $candidate['relations'], 'relation_revision' => (string) $candidate['relation_revision'] ) ) !== $canonical_labels; } ) );
			if ( ! empty( $conflicting ) ) {
				$missing[] = array( 'language' => $language, 'reason' => 'authority_candidate_conflict', 'candidate_menu_ids' => array_values( array_map( static function ( array $candidate ): int { return (int) $candidate['menu_id']; }, $candidate_snapshots ) ) );
				continue;
			}
			$snapshot = $candidate_snapshots[0];
			$authority[ $language ] = array( 'confidence' => 'identical_retained_candidates', 'candidates' => $candidate_snapshots );
			foreach ( (array) $snapshot['labels'] as $source_item_id => $label ) {
				$labels_by_item[ (int) $source_item_id ][ $language ] = (string) $label;
			}
			$active_menu_id = absint( $identities[ $language ]['menu_id'] ?? 0 );
			$active_labels = $active_menu_id > 0 ? self::existing_menu_label_map( $active_menu_id ) : array();
			foreach ( (array) $snapshot['labels'] as $source_item_id => $label ) {
				$current_label = sanitize_text_field( (string) ( $active_labels['by_source_item'][ (int) $source_item_id ] ?? '' ) );
				if ( '' !== $current_label && $current_label !== (string) $label ) {
					$generated_drift[] = array( 'language' => $language, 'source_item_id' => (int) $source_item_id, 'current_managed_label' => $current_label, 'authority_label' => (string) $label );
				}
			}
		}
		if ( ! empty( $missing ) ) {
			return array( 'success' => false, 'code' => 'public_header_label_authority_incomplete', 'message' => 'Established editorial labels could not be proven for every stable source-item identity and configured language.', 'missing' => $missing, 'authority' => $authority, 'generated_label_drift' => $generated_drift );
		}

		$items = array();
		$source_language = self::source_language_code();
		foreach ( (array) $active['items'] as $item ) {
			$source_item_id = absint( $item['source_item_id'] ?? 0 );
			$item['labels'] = (array) ( $labels_by_item[ $source_item_id ] ?? array() );
			$item['title'] = sanitize_text_field( (string) ( $item['labels'][ $source_language ] ?? '' ) );
			$items[] = $item;
		}
		$normalized = self::normalize_public_header_manifest_items( $items, true );
		if ( empty( $normalized['success'] ) ) {
			return array_merge( $normalized, array( 'authority' => $authority, 'generated_label_drift' => $generated_drift ) );
		}
		$draft_revision = self::public_header_manifest_revision_for_items( (array) $normalized['items'] );
		$authority_receipts = self::public_header_authority_receipts( $authority, $draft_revision );
		if ( count( $authority_receipts ) !== count( $configured_languages ) ) { return array( 'success' => false, 'code' => 'public_header_label_authority_receipt_invalid', 'authority' => $authority, 'generated_label_drift' => $generated_drift ); }
		$draft = array( 'schema_version' => 2, 'source_language' => $source_language, 'revision' => $draft_revision, 'items' => (array) $normalized['items'], 'authority_receipts' => $authority_receipts );
		$relation_receipts = self::public_header_relation_receipts_for_manifest( $draft );
		if ( empty( $relation_receipts['success'] ) ) { return array_merge( $relation_receipts, array( 'authority' => $authority, 'generated_label_drift' => $generated_drift ) ); }
		$draft['relation_receipts'] = (array) $relation_receipts['receipts'];
		do_action( 'devenia_workflow_public_header_migration_before_final_authority_revalidation', $draft, $active, $authority );
		if ( self::translation_job_canonicalize( self::public_header_manifest() ) !== self::translation_job_canonicalize( $active ) ) { return array( 'success' => false, 'code' => 'public_header_label_authority_active_manifest_changed', 'authority' => $authority, 'generated_label_drift' => $generated_drift ); }
		$relation_validation = self::validate_public_header_relation_receipts( $draft );
		if ( empty( $relation_validation['success'] ) ) { return array_merge( $relation_validation, array( 'authority' => $authority, 'generated_label_drift' => $generated_drift ) ); }
		$receipt_validation = self::validate_public_header_authority_receipts( $draft );
		if ( empty( $receipt_validation['success'] ) ) { return array_merge( $receipt_validation, array( 'authority' => $authority, 'generated_label_drift' => $generated_drift ) ); }
		$result = array( 'success' => true, 'staged' => false, 'draft' => $draft, 'authority' => $authority, 'generated_label_drift' => $generated_drift );
		if ( ! empty( $input['stage'] ) ) {
			$staged = self::update_public_header_manifest( array( 'items' => $draft['items'], 'authority_receipts' => $authority_receipts, 'expected_active_manifest' => $active ) );
			$result['staged'] = ! empty( $staged['success'] ) && ! empty( $staged['pending'] );
			$result['stage_result'] = $staged;
			$result['success'] = ! empty( $staged['success'] );
		}
		return $result;
	}

	/** Convert accepted menu snapshots into the temporary authority receipt carried by pending state. */
	private static function public_header_authority_receipts( array $authority, string $manifest_revision ): array {
		$receipts = array();
		foreach ( $authority as $language => $authority_row ) {
			$snapshots = isset( $authority_row['candidates'] ) && is_array( $authority_row['candidates'] ) ? $authority_row['candidates'] : $authority_row;
			if ( ! is_array( $snapshots ) || empty( $snapshots ) ) { return array(); }
			$first = $snapshots[0];
			$relations = isset( $first['relations'] ) && is_array( $first['relations'] ) ? $first['relations'] : array();
			$relation_revision = (string) ( $first['relation_revision'] ?? '' );
			$candidates = array();
			foreach ( $snapshots as $snapshot ) {
				if ( self::translation_job_canonicalize( (array) ( $snapshot['relations'] ?? array() ) ) !== self::translation_job_canonicalize( $relations ) || ! hash_equals( $relation_revision, (string) ( $snapshot['relation_revision'] ?? '' ) ) ) { return array(); }
				$candidates[] = array( 'menu_id' => absint( $snapshot['menu_id'] ?? 0 ), 'surface_revision' => (string) ( $snapshot['surface_revision'] ?? '' ) );
			}
			$language = sanitize_key( (string) $language );
			$receipt = array( 'language' => $language, 'manifest_revision' => $manifest_revision, 'relations' => $relations, 'relation_revision' => $relation_revision, 'candidates' => $candidates );
			$receipt['receipt_revision'] = 'phar_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $receipt ) ) ?: '' ), 0, 40 );
			$receipts[ $language ] = $receipt;
		}
		ksort( $receipts );
		return $receipts;
	}

	/** Revalidate label menus and exact relations carried by one pending authority snapshot. */
	private static function validate_public_header_authority_receipts( array $manifest ): array {
		if ( ! array_key_exists( 'authority_receipts', $manifest ) ) { return array( 'success' => true, 'present' => false ); }
		if ( ! is_array( $manifest['authority_receipts'] ) || empty( $manifest['authority_receipts'] ) ) { return array( 'success' => false, 'code' => 'public_header_authority_receipts_invalid' ); }
		$receipts = $manifest['authority_receipts'];
		$languages = self::configured_public_header_languages();
		$receipt_languages = array_map( 'sanitize_key', array_keys( $receipts ) );
		sort( $receipt_languages ); sort( $languages );
		if ( $receipt_languages !== $languages ) { return array( 'success' => false, 'code' => 'public_header_authority_receipt_language_set_invalid' ); }
		foreach ( $languages as $language ) {
			$receipt = isset( $receipts[ $language ] ) && is_array( $receipts[ $language ] ) ? $receipts[ $language ] : array();
			$receipt_revision = (string) ( $receipt['receipt_revision'] ?? '' );
			$receipt_payload = $receipt; unset( $receipt_payload['receipt_revision'] );
			$expected_receipt_revision = 'phar_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $receipt_payload ) ) ?: '' ), 0, 40 );
			if ( $language !== sanitize_key( (string) ( $receipt['language'] ?? '' ) ) || '' === (string) ( $manifest['revision'] ?? '' ) || ! hash_equals( (string) $manifest['revision'], (string) ( $receipt['manifest_revision'] ?? '' ) ) || '' === $receipt_revision || ! hash_equals( $expected_receipt_revision, $receipt_revision ) ) { return array( 'success' => false, 'code' => 'public_header_authority_receipt_revision_invalid', 'language' => $language ); }
			$fresh = self::public_header_ephemeral_relation_snapshot( $language, (array) $manifest['items'] );
			if ( empty( $fresh['success'] ) || self::translation_job_canonicalize( (array) ( $fresh['relations'] ?? array() ) ) !== self::translation_job_canonicalize( (array) ( $receipt['relations'] ?? array() ) ) || ! hash_equals( (string) ( $fresh['revision'] ?? '' ), (string) ( $receipt['relation_revision'] ?? '' ) ) ) {
				return array( 'success' => false, 'code' => 'public_header_authority_relation_changed', 'language' => $language, 'fresh' => $fresh );
			}
			$candidates = isset( $receipt['candidates'] ) && is_array( $receipt['candidates'] ) ? $receipt['candidates'] : array();
			$candidate_ids = array_values( array_unique( array_filter( array_map( static function ( $candidate ): int { return absint( is_array( $candidate ) ? ( $candidate['menu_id'] ?? 0 ) : 0 ); }, $candidates ) ) ) );
			$minimum_candidates = self::source_language_code() === $language ? 1 : 2;
			if ( count( $candidate_ids ) !== count( $candidates ) || count( $candidate_ids ) < $minimum_candidates ) { return array( 'success' => false, 'code' => 'public_header_authority_candidate_receipt_missing', 'language' => $language ); }
			$expected_labels = array();
			foreach ( (array) $manifest['items'] as $item ) { $expected_labels[ absint( $item['source_item_id'] ?? 0 ) ] = sanitize_text_field( (string) ( $item['labels'][ $language ] ?? '' ) ); }
			foreach ( $candidates as $candidate ) {
				$menu_id = absint( is_array( $candidate ) ? ( $candidate['menu_id'] ?? 0 ) : 0 );
				$surface_revision = (string) ( is_array( $candidate ) ? ( $candidate['surface_revision'] ?? '' ) : '' );
				$current_revision = self::localized_menu_projection_revision( $menu_id );
				if ( $menu_id < 1 || '' === $surface_revision || '' === $current_revision || ! hash_equals( $surface_revision, $current_revision ) ) { return array( 'success' => false, 'code' => 'public_header_authority_menu_changed', 'language' => $language, 'menu_id' => $menu_id ); }
				$snapshot = self::public_header_editorial_label_snapshot( $language, $menu_id, (array) $manifest['items'], $fresh );
				if ( empty( $snapshot['success'] ) || self::translation_job_canonicalize( (array) ( $snapshot['labels'] ?? array() ) ) !== self::translation_job_canonicalize( $expected_labels ) || self::translation_job_canonicalize( (array) ( $snapshot['relations'] ?? array() ) ) !== self::translation_job_canonicalize( (array) $receipt['relations'] ) ) {
					return array( 'success' => false, 'code' => 'public_header_authority_snapshot_changed', 'language' => $language, 'menu_id' => $menu_id );
				}
			}
			$fresh_after = self::public_header_ephemeral_relation_snapshot( $language, (array) $manifest['items'] );
			if ( empty( $fresh_after['success'] ) || ! hash_equals( (string) $fresh['revision'], (string) ( $fresh_after['revision'] ?? '' ) ) || self::translation_job_canonicalize( (array) $fresh['relations'] ) !== self::translation_job_canonicalize( (array) ( $fresh_after['relations'] ?? array() ) ) ) { return array( 'success' => false, 'code' => 'public_header_authority_relation_changed', 'language' => $language, 'fresh' => $fresh_after ); }
		}
		return array( 'success' => true, 'present' => true );
	}

	/**
	 * Map one established WordPress menu to stable manifest item identities.
	 * No content title participates in this Adapter.
	 *
	 * @param array<int,array<string,mixed>> $manifest_items Legacy manifest rows.
	 * @return array<string,mixed>
	 */
	private static function public_header_editorial_label_snapshot( string $language, int $menu_id, array $manifest_items, array $bound_relation_snapshot = array() ): array {
		$surface_before = self::localized_menu_projection_revision( $menu_id );
		$items = wp_get_nav_menu_items( $menu_id, array( 'orderby' => 'menu_order' ) ) ?: array();
		$matched = array();
		$used = array();
		$missing = array();
		$is_source = self::source_language_code() === sanitize_key( $language );
		$relation_snapshot = ! empty( $bound_relation_snapshot ) ? $bound_relation_snapshot : self::public_header_ephemeral_relation_snapshot( $language, $manifest_items );
		if ( empty( $relation_snapshot['success'] ) ) { return $relation_snapshot; }
		$manifest_source_item_ids = array();
		foreach ( $manifest_items as $manifest_item ) { $manifest_source_item_id = absint( is_array( $manifest_item ) ? ( $manifest_item['source_item_id'] ?? 0 ) : 0 ); if ( $manifest_source_item_id > 0 ) { $manifest_source_item_ids[ $manifest_source_item_id ] = true; } }
		$identity_states = array();
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || empty( $item->ID ) ) { continue; }
			$item_id = absint( $item->ID );
			$identity_state = self::public_header_menu_item_source_identity( $item_id );
			$identity_states[ $item_id ] = $identity_state;
			if ( empty( $identity_state['valid'] ) ) {
				$missing[] = array( 'menu_item_id' => $item_id, 'reason' => 'stable_identity_meta_corrupt', 'violations' => (array) ( $identity_state['violations'] ?? array() ) );
			} elseif ( ! empty( $identity_state['present'] ) && ! isset( $manifest_source_item_ids[ absint( $identity_state['source_item_id'] ?? 0 ) ] ) ) {
				$missing[] = array( 'menu_item_id' => $item_id, 'reason' => 'foreign_stable_identity' );
			}
		}
		if ( ! empty( $missing ) ) { return array( 'success' => false, 'code' => 'public_header_label_authority_items_incomplete', 'missing' => $missing ); }
		foreach ( $manifest_items as $manifest_item ) {
			$source_item_id = absint( $manifest_item['source_item_id'] ?? 0 );
			$type = sanitize_key( (string) ( $manifest_item['type'] ?? '' ) );
			$relation = isset( $relation_snapshot['relations'][ $source_item_id ] ) && is_array( $relation_snapshot['relations'][ $source_item_id ] ) ? $relation_snapshot['relations'][ $source_item_id ] : array();
			$expected_object_id = 'page' === $type ? absint( $relation['object_id'] ?? 0 ) : 0;
			$expected_url = 'custom' === $type ? (string) ( $relation['url'] ?? '' ) : '';
			$identity_candidates = array();
			$fallback_candidates = array();
			$identity_observed = false;
			$identity_relation_mismatch = false;
			foreach ( $items as $item ) {
				if ( ! is_object( $item ) || isset( $used[ (int) $item->ID ] ) ) { continue; }
				$identity_state = (array) ( $identity_states[ (int) $item->ID ] ?? array( 'present' => false, 'valid' => true, 'source_item_id' => 0 ) );
				$identity_present = ! empty( $identity_state['present'] );
				$stable_id = absint( $identity_state['source_item_id'] ?? 0 );
				$matches_source_item = $is_source && ! $identity_present && absint( $item->ID ?? 0 ) === $source_item_id;
				$matches_stable = $stable_id > 0 && $stable_id === $source_item_id;
				$matches_page = 'page' === $type && $expected_object_id > 0 && 'post_type' === (string) ( $item->type ?? '' ) && 'page' === (string) ( $item->object ?? '' ) && $expected_object_id === absint( $item->object_id ?? 0 );
				$matches_custom = 'custom' === $type && 'custom' === (string) ( $item->type ?? '' ) && '' !== $expected_url && $expected_url === self::normalize_primary_navigation_url( (string) ( $item->url ?? '' ) );
				$matches_relation = $matches_page || $matches_custom;
				if ( $matches_source_item || $matches_stable ) {
					$identity_observed = true;
					if ( $matches_relation ) { $identity_candidates[] = $item; }
					else { $identity_relation_mismatch = true; }
				} elseif ( ! $identity_present && $matches_relation ) {
					$fallback_candidates[] = $item;
				}
			}
			if ( $identity_relation_mismatch ) {
				$missing[] = array( 'source_item_id' => $source_item_id, 'reason' => 'stable_identity_relation_mismatch' );
				continue;
			}
			$candidates = $identity_observed ? $identity_candidates : $fallback_candidates;
			if ( 1 !== count( $candidates ) ) {
				$missing[] = array( 'source_item_id' => $source_item_id, 'reason' => empty( $candidates ) ? 'stable_item_not_found' : 'stable_item_ambiguous', 'candidate_count' => count( $candidates ) );
				continue;
			}
			$item = $candidates[0];
			$label = sanitize_text_field( (string) ( $item->title ?? '' ) );
			if ( '' === $label ) {
				$missing[] = array( 'source_item_id' => $source_item_id, 'reason' => 'editorial_label_empty' );
				continue;
			}
			$used[ (int) $item->ID ] = true;
			$matched[ $source_item_id ] = array( 'item' => $item, 'label' => $label );
		}
		foreach ( $manifest_items as $manifest_item ) {
			$source_item_id = absint( $manifest_item['source_item_id'] ?? 0 );
			$expected_parent = absint( $manifest_item['parent_source_item_id'] ?? 0 );
			if ( ! isset( $matched[ $source_item_id ] ) ) { continue; }
			$actual_parent_item_id = absint( $matched[ $source_item_id ]['item']->menu_item_parent ?? 0 );
			$actual_parent_source_id = 0;
			foreach ( $matched as $candidate_source_id => $candidate ) {
				if ( absint( $candidate['item']->ID ?? 0 ) === $actual_parent_item_id ) { $actual_parent_source_id = (int) $candidate_source_id; break; }
			}
			if ( $actual_parent_source_id !== $expected_parent ) {
				$missing[] = array( 'source_item_id' => $source_item_id, 'reason' => 'parent_identity_mismatch', 'expected_parent_source_item_id' => $expected_parent, 'actual_parent_source_item_id' => $actual_parent_source_id );
			}
		}
		if ( ! empty( $missing ) ) { return array( 'success' => false, 'code' => 'public_header_label_authority_items_incomplete', 'missing' => $missing ); }
		$labels = array();
		foreach ( $matched as $source_item_id => $row ) { $labels[ (int) $source_item_id ] = (string) $row['label']; }
		$relation_after = ! empty( $bound_relation_snapshot ) ? $bound_relation_snapshot : self::public_header_ephemeral_relation_snapshot( $language, $manifest_items );
		$surface_after = self::localized_menu_projection_revision( $menu_id );
		if ( '' === $surface_before || '' === $surface_after || ! hash_equals( $surface_before, $surface_after ) || empty( $relation_after['success'] ) || ! hash_equals( (string) $relation_snapshot['revision'], (string) ( $relation_after['revision'] ?? '' ) ) || self::translation_job_canonicalize( (array) $relation_snapshot['relations'] ) !== self::translation_job_canonicalize( (array) ( $relation_after['relations'] ?? array() ) ) ) {
			return array( 'success' => false, 'code' => 'public_header_authority_snapshot_unstable' );
		}
		return array( 'success' => true, 'labels' => $labels, 'relations' => (array) $relation_snapshot['relations'], 'relation_revision' => (string) $relation_snapshot['revision'], 'surface_revision' => $surface_after );
	}

	/**
	 * Decode one persisted menu-item source identity without treating corrupt
	 * or duplicate metadata as absence that may fall back to a derived relation.
	 *
	 * @return array{present:bool,valid:bool,source_item_id:int,violations:string[]}
	 */
	private static function public_header_menu_item_source_identity( int $item_id ): array {
		$present = $item_id > 0 && metadata_exists( 'post', $item_id, self::MENU_ITEM_META_SOURCE_ITEM_ID );
		if ( ! $present ) { return array( 'present' => false, 'valid' => true, 'source_item_id' => 0, 'violations' => array() ); }

		$rows = get_post_meta( $item_id, self::MENU_ITEM_META_SOURCE_ITEM_ID, false );
		$violations = array();
		$source_item_id = 0;
		if ( ! is_array( $rows ) || 1 !== count( $rows ) ) {
			$violations[] = 'stable_identity_row_count_invalid';
		} else {
			$value = $rows[0];
			if ( is_int( $value ) && $value > 0 ) {
				$source_item_id = $value;
			} elseif ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) && (string) absint( $value ) === $value ) {
				$source_item_id = absint( $value );
			} else {
				$violations[] = 'stable_identity_value_invalid';
			}
		}
		return array( 'present' => true, 'valid' => empty( $violations ), 'source_item_id' => $source_item_id, 'violations' => $violations );
	}

	/**
	 * Register the complete source navigation manifest used by every Public
	 * Header Projection. The active WordPress menu is deliberately not read:
	 * it is a rendered Adapter, never the expectation oracle.
	 *
	 * @param array<string,mixed> $input Manifest input.
	 * @return array<string,mixed>
	 */
	private static function update_public_header_manifest( array $input ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return self::error( 'Public header manifest updates require manage_options.' );
		}
		self::ensure_public_header_transition_option();
		$transition = get_option( self::OPTION_PUBLIC_HEADER_TRANSITION, self::public_header_idle_transition() );
		if ( is_array( $transition ) && self::public_header_transition_is_nonterminal( $transition ) ) {
			return array( 'success' => false, 'code' => 'public_header_transition_in_progress', 'phase' => (string) ( $transition['phase'] ?? '' ), 'message' => 'The current Public Header transition must reach a verified terminal outcome before another manifest can be staged.' );
		}

		$normalized = self::normalize_public_header_manifest_items( $input['items'] ?? array(), true );
		if ( empty( $normalized['success'] ) ) {
			return $normalized;
		}

		$items    = (array) $normalized['items'];
		$revision = self::public_header_manifest_revision_for_items( $items );
		$current  = self::public_header_manifest();
		if ( '' !== (string) ( $current['revision'] ?? '' ) && hash_equals( (string) $current['revision'], $revision ) ) {
			$missing = '__devenia_workflow_option_missing__';
			$pending_before = get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $missing );
			$cancelled = $missing === $pending_before || self::atomic_delete_option_value( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $pending_before );
			if ( ! $cancelled || $missing !== get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $missing ) ) {
				return array( 'success' => false, 'code' => 'public_header_pending_manifest_cancel_failed', 'message' => 'The stale pending Public Header Projection manifest could not be cancelled safely.' );
			}
			return array( 'success' => true, 'source_language' => self::source_language_code(), 'revision' => $revision, 'item_count' => count( $items ), 'unchanged' => true, 'pending' => false, 'cancelled_pending' => $missing !== $pending_before, 'message' => 'The active Public Header Projection manifest is current and any different pending revision was cancelled.' );
		}
		$manifest = array(
			'schema_version'  => 2,
			'source_language' => self::source_language_code(),
			'revision'        => $revision,
			'items'           => $items,
			'updated_at'      => gmdate( 'c' ),
		);
		if ( array_key_exists( 'authority_receipts', $input ) ) {
			if ( ! is_array( $input['authority_receipts'] ) || empty( $input['authority_receipts'] ) ) { return array( 'success' => false, 'code' => 'public_header_authority_receipts_invalid' ); }
			$manifest['authority_receipts'] = $input['authority_receipts'];
			$authority_validation = self::validate_public_header_authority_receipts( $manifest );
			if ( empty( $authority_validation['success'] ) ) { return $authority_validation; }
		}
		$relation_receipts = self::public_header_relation_receipts_for_manifest( $manifest );
		if ( empty( $relation_receipts['success'] ) ) { return $relation_receipts; }
		$manifest['relation_receipts'] = (array) $relation_receipts['receipts'];
		$relation_validation = self::validate_public_header_relation_receipts( $manifest );
		if ( empty( $relation_validation['success'] ) ) { return $relation_validation; }
		$missing = '__devenia_workflow_option_missing__';
		$before  = get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $missing );
		$existing_pending = self::normalize_public_header_manifest( $before );
		$existing_pending_is_canonical = is_array( $before ) && $before === $existing_pending;
		$existing_receipts = (array) ( $existing_pending['authority_receipts'] ?? array() );
		$requested_receipts = (array) ( $manifest['authority_receipts'] ?? array() );
		$existing_relation_receipts = (array) ( $existing_pending['relation_receipts'] ?? array() );
		if ( $existing_pending_is_canonical && '' !== (string) ( $existing_pending['revision'] ?? '' ) && hash_equals( (string) $existing_pending['revision'], $revision ) && self::translation_job_canonicalize( $existing_receipts ) === self::translation_job_canonicalize( $requested_receipts ) && self::translation_job_canonicalize( $existing_relation_receipts ) === self::translation_job_canonicalize( (array) $manifest['relation_receipts'] ) ) {
			$transition_reset = self::reset_terminal_public_header_transition();
			if ( empty( $transition_reset['success'] ) ) { return $transition_reset; }
			return array( 'success' => true, 'source_language' => self::source_language_code(), 'revision' => $revision, 'activation_receipt' => self::public_header_activation_receipt( $before ), 'item_count' => count( $items ), 'pending' => true, 'unchanged' => true, 'message' => 'The pending Public Header Projection manifest is already current.' );
		}
		do_action( 'devenia_workflow_public_header_before_pending_authority_write', $manifest, $before );
		if ( isset( $input['expected_active_manifest'] ) && is_array( $input['expected_active_manifest'] ) && self::translation_job_canonicalize( self::public_header_manifest() ) !== self::translation_job_canonicalize( $input['expected_active_manifest'] ) ) { return array( 'success' => false, 'code' => 'public_header_label_authority_active_manifest_changed' ); }
		if ( array_key_exists( 'authority_receipts', $manifest ) ) {
			$authority_validation = self::validate_public_header_authority_receipts( $manifest );
			if ( empty( $authority_validation['success'] ) ) { return $authority_validation; }
		}
		$relation_validation = self::validate_public_header_relation_receipts( $manifest );
		if ( empty( $relation_validation['success'] ) ) { return $relation_validation; }
		$written = $missing === $before
			? self::atomic_create_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $manifest )
			: self::atomic_replace_option_value( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $before, $manifest );
		$stored = get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $missing );
		if ( ! $written || $stored !== $manifest ) {
			return array( 'success' => false, 'code' => 'public_header_pending_manifest_write_failed', 'message' => 'The pending Public Header Projection manifest could not be stored.' );
		}

		return array(
			'success'         => true,
			'source_language' => self::source_language_code(),
			'revision'        => $revision,
			'activation_receipt' => self::public_header_activation_receipt( (array) $stored ),
			'item_count'      => count( $items ),
			'pending'         => true,
			'message'         => 'Pending Public Header Projection manifest registered. The active manifest and projections are unchanged until complete-set activation passes.',
		);
	}

	/**
	 * Return only a complete, current-source manifest.
	 *
	 * @return array<string,mixed>
	 */
	private static function public_header_manifest(): array {
		return self::normalize_public_header_manifest( get_option( self::OPTION_PUBLIC_HEADER_MANIFEST, array() ) );
	}

	/** Whether this installation has crossed the managed-header activation boundary. */
	private static function public_header_projection_is_enrolled(): bool {
		$missing = '__devenia_workflow_option_missing__';
		return $missing !== get_option( self::OPTION_PUBLIC_HEADER_ENROLLMENT, $missing ) || $missing !== get_option( self::OPTION_PUBLIC_HEADER_MANIFEST, $missing );
	}

	/** Return only a complete pending manifest. */
	private static function pending_public_header_manifest(): array {
		return self::normalize_public_header_manifest( get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, array() ) );
	}

	/** Normalize one stored manifest without consulting rendered menu state. */
	private static function normalize_public_header_manifest( $manifest ): array {
		if ( ! is_array( $manifest ) ) {
			return array();
		}
		$schema_version = absint( $manifest['schema_version'] ?? 0 );
		if ( ! in_array( $schema_version, array( 1, 2 ), true ) || self::source_language_code() !== sanitize_key( (string) ( $manifest['source_language'] ?? '' ) ) ) {
			return array();
		}
		$normalized = self::normalize_public_header_manifest_items( $manifest['items'] ?? array(), 2 === $schema_version );
		if ( empty( $normalized['success'] ) ) {
			return array();
		}
		$items    = (array) $normalized['items'];
		$revision = self::public_header_manifest_revision_for_items( $items );
		if ( '' === (string) ( $manifest['revision'] ?? '' ) || ! hash_equals( (string) $manifest['revision'], $revision ) ) {
			return array();
		}
		$manifest['items'] = $items;
		return $manifest;
	}

	/**
	 * Validate and canonicalize a complete ordered source navigation manifest.
	 *
	 * @param mixed $raw_items Manifest items.
	 * @return array<string,mixed>
	 */
	private static function normalize_public_header_manifest_items( $raw_items, bool $require_label_authority = false ): array {
		if ( ! is_array( $raw_items ) || empty( $raw_items ) ) {
			return array( 'success' => false, 'code' => 'public_header_manifest_empty', 'message' => 'The Public Header Projection manifest must contain at least one item.' );
		}

		$items = array();
		$configured_languages = array_keys( self::languages() );
		$source_language = self::source_language_code();
		foreach ( $raw_items as $raw ) {
			if ( ! is_array( $raw ) ) {
				return array( 'success' => false, 'code' => 'public_header_manifest_item_invalid', 'message' => 'Every Public Header Projection manifest item must be an object.' );
			}
			$id       = absint( $raw['source_item_id'] ?? 0 );
			$type     = sanitize_key( (string) ( $raw['type'] ?? '' ) );
			$title    = sanitize_text_field( (string) ( $raw['title'] ?? '' ) );
			$parent   = absint( $raw['parent_source_item_id'] ?? 0 );
			$position = absint( $raw['position'] ?? 0 );
			if ( $id < 1 || isset( $items[ $id ] ) || ! in_array( $type, array( 'page', 'custom' ), true ) || '' === $title || $id === $parent ) {
				return array( 'success' => false, 'code' => 'public_header_manifest_item_invalid', 'message' => 'Public header manifest items require a unique positive identity, supported type, title, and valid parent identity.' );
			}
			$item = array(
				'source_item_id'        => $id,
				'type'                  => $type,
				'title'                 => $title,
				'parent_source_item_id' => $parent,
				'position'              => $position,
			);
			$labels = array();
			if ( isset( $raw['labels'] ) && is_array( $raw['labels'] ) ) {
				foreach ( $raw['labels'] as $label_language => $label ) {
					$label_language = sanitize_key( (string) $label_language );
					$label = is_scalar( $label ) ? sanitize_text_field( (string) $label ) : '';
					if ( '' === $label_language || ! in_array( $label_language, $configured_languages, true ) || '' === $label ) {
						return array( 'success' => false, 'code' => 'public_header_label_authority_invalid', 'message' => 'Every editorial menu label must bind one configured language to one non-empty label.', 'source_item_id' => $id );
					}
					$labels[ $label_language ] = $label;
				}
			}
			if ( $require_label_authority ) {
				$missing_languages = array_values( array_diff( $configured_languages, array_keys( $labels ) ) );
				if ( ! empty( $missing_languages ) || ! isset( $labels[ $source_language ] ) || $title !== (string) $labels[ $source_language ] ) {
					return array( 'success' => false, 'code' => 'public_header_label_authority_missing', 'message' => 'Every manifest item requires an exact editorial label for the source and every configured target language; page titles are not label authority.', 'source_item_id' => $id, 'missing_languages' => $missing_languages );
				}
				ksort( $labels );
				$item['labels'] = $labels;
			} elseif ( ! empty( $labels ) ) {
				ksort( $labels );
				$item['labels'] = $labels;
			}
			if ( 'page' === $type ) {
				$object_id = absint( $raw['object_id'] ?? 0 );
				$post      = $object_id ? get_post( $object_id ) : null;
				if ( ! $post instanceof WP_Post || 'page' !== (string) $post->post_type || 'publish' !== (string) $post->post_status || self::is_translation_post( $object_id ) ) {
					return array( 'success' => false, 'code' => 'public_header_manifest_page_invalid', 'message' => 'Every page item must reference one published source page.' );
				}
				$item['object_id'] = $object_id;
			} else {
				$url = esc_url_raw( (string) ( $raw['url'] ?? '' ) );
				if ( '' === $url ) {
					return array( 'success' => false, 'code' => 'public_header_manifest_url_invalid', 'message' => 'Every custom item must contain a valid URL.' );
				}
				$item['url'] = $url;
			}
			$items[ $id ] = $item;
		}

		foreach ( $items as $id => $item ) {
			$parent = absint( $item['parent_source_item_id'] ?? 0 );
			if ( $parent > 0 && ! isset( $items[ $parent ] ) ) {
				return array( 'success' => false, 'code' => 'public_header_manifest_parent_missing', 'message' => 'Every Public Header Projection parent must exist in the same manifest.' );
			}
			$seen = array( $id => true );
			while ( $parent > 0 ) {
				if ( isset( $seen[ $parent ] ) ) {
					return array( 'success' => false, 'code' => 'public_header_manifest_parent_cycle', 'message' => 'Public Header Projection parent relationships must be acyclic.' );
				}
				$seen[ $parent ] = true;
				$parent = absint( $items[ $parent ]['parent_source_item_id'] ?? 0 );
			}
		}

		$items = array_values( $items );
		usort(
			$items,
			static function ( array $left, array $right ): int {
				$order = absint( $left['position'] ?? 0 ) <=> absint( $right['position'] ?? 0 );
				return 0 !== $order ? $order : absint( $left['source_item_id'] ?? 0 ) <=> absint( $right['source_item_id'] ?? 0 );
			}
		);

		return array( 'success' => true, 'items' => $items );
	}

	/** @param array<int,array<string,mixed>> $items */
	private static function public_header_manifest_revision_for_items( array $items ): string {
		return 'phm_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $items ) ) ?: '' ), 0, 40 );
	}

	/**
	 * Build the complete language projection plan from the immutable manifest.
	 * This is the shared Interface used by staging and public verification.
	 *
	 * @return array<string,mixed>
	 */
	private static function public_header_projection_plan( string $language, bool $include_untranslated = false, bool $include_custom = true, array $manifest = array(), bool $require_relation_receipts = false ): array {
		$language = sanitize_key( $language );
		$languages = self::languages();
		if ( '' === $language || ! isset( $languages[ $language ] ) ) {
			return array( 'success' => false, 'code' => 'public_header_language_unknown', 'message' => 'Public Header Projection language is not configured.' );
		}
		$manifest = $manifest ?: self::public_header_manifest();
		if ( empty( $manifest ) ) {
			return array( 'success' => false, 'code' => 'public_header_manifest_missing', 'message' => 'No valid Public Header Projection manifest is registered.' );
		}
		$relation_authority_present = array_key_exists( 'relation_receipts', $manifest );
		if ( $require_relation_receipts ) {
			$receipt_validation = self::validate_public_header_relation_receipts( $manifest );
			if ( empty( $receipt_validation['success'] ) ) { return $receipt_validation; }
		}
		if ( $relation_authority_present ) {
			$relation_authority = isset( $manifest['relation_receipts'][ $language ]['relations'] ) && is_array( $manifest['relation_receipts'][ $language ]['relations'] ) ? $manifest['relation_receipts'][ $language ]['relations'] : array();
		} else {
			$fresh_relations = self::public_header_ephemeral_relation_snapshot( $language, (array) $manifest['items'] );
			if ( empty( $fresh_relations['success'] ) ) { return $fresh_relations; }
			$relation_authority = (array) $fresh_relations['relations'];
		}
		$rows      = array();
		$skipped   = array();
		foreach ( (array) $manifest['items'] as $item ) {
			$label = sanitize_text_field( (string) ( $item['labels'][ $language ] ?? '' ) );
			if ( '' === $label ) {
				$skipped[] = array( 'source_item_id' => absint( $item['source_item_id'] ?? 0 ), 'reason' => 'missing_editorial_label_authority', 'language' => $language );
				continue;
			}
			$source_item = (object) array(
				'ID'               => absint( $item['source_item_id'] ?? 0 ),
				'title'            => (string) ( $item['title'] ?? '' ),
				'type'             => 'page' === (string) ( $item['type'] ?? '' ) ? 'post_type' : 'custom',
				'object'           => 'page' === (string) ( $item['type'] ?? '' ) ? 'page' : 'custom',
				'object_id'        => absint( $item['object_id'] ?? 0 ),
				'url'              => (string) ( $item['url'] ?? '' ),
				'menu_item_parent' => absint( $item['parent_source_item_id'] ?? 0 ),
				'menu_order'       => absint( $item['position'] ?? 0 ),
			);
			$args = null;
			if ( 'post_type' === $source_item->type ) {
				$bound_relation = isset( $relation_authority[ (int) $source_item->ID ] ) && is_array( $relation_authority[ (int) $source_item->ID ] ) ? $relation_authority[ (int) $source_item->ID ] : array();
				if ( 'page' !== (string) ( $bound_relation['type'] ?? '' ) || absint( $bound_relation['object_id'] ?? 0 ) < 1 ) {
					$skipped[] = array( 'source_item_id' => (int) $source_item->ID, 'reason' => 'page_relation_authority_missing' );
					continue;
				}
				$object_id = absint( $bound_relation['object_id'] );
				if ( ! $object_id ) {
					$skipped[] = array( 'source_item_id' => (int) $source_item->ID, 'source_page_id' => (int) $source_item->object_id, 'title' => (string) $source_item->title, 'reason' => 'missing_published_translation' );
					continue;
				}
				$args = array(
					'menu-item-title'     => $label,
					'menu-item-object'    => 'page',
					'menu-item-object-id' => $object_id,
					'menu-item-type'      => 'post_type',
					'menu-item-status'    => 'publish',
					'menu-item-parent-id' => 0,
				);
			} elseif ( $include_custom ) {
				$bound_relation = isset( $relation_authority[ (int) $source_item->ID ] ) && is_array( $relation_authority[ (int) $source_item->ID ] ) ? $relation_authority[ (int) $source_item->ID ] : array();
				if ( 'custom' !== (string) ( $bound_relation['type'] ?? '' ) || '' === (string) ( $bound_relation['url'] ?? '' ) ) {
					$skipped[] = array( 'source_item_id' => (int) $source_item->ID, 'reason' => 'custom_relation_authority_missing' );
					continue;
				}
				$url = 'internal' === sanitize_key( (string) ( $bound_relation['scope'] ?? '' ) ) ? (string) ( $bound_relation['target_url'] ?? '' ) : (string) $bound_relation['url'];
				if ( '' === $url ) { $skipped[] = array( 'source_item_id' => (int) $source_item->ID, 'reason' => 'custom_relation_target_url_missing' ); continue; }
				$args = array(
					'menu-item-title'     => $label,
					'menu-item-url'       => $url,
					'menu-item-type'      => 'custom',
					'menu-item-status'    => 'publish',
					'menu-item-parent-id' => 0,
				);
			} else {
				$skipped[] = array( 'source_item_id' => (int) $source_item->ID, 'title' => (string) $source_item->title, 'type' => (string) $source_item->type, 'reason' => 'unsupported_or_disabled' );
				continue;
			}
			$args['menu-item-position'] = absint( $source_item->menu_order );
			$rows[] = array(
				'source_item'          => $source_item,
				'source_item_id'       => (int) $source_item->ID,
				'parent_source_item_id'=> absint( $source_item->menu_item_parent ),
				'args'                 => $args,
				'title'                => (string) $args['menu-item-title'],
				'url'                  => 'custom' === (string) $args['menu-item-type'] ? (string) $args['menu-item-url'] : (string) get_permalink( absint( $args['menu-item-object-id'] ) ),
			);
		}

		if ( ! empty( $skipped ) ) {
			return array( 'success' => false, 'code' => 'public_header_projection_incomplete', 'message' => 'Every manifest item must resolve for the configured language before projection activation.', 'language' => $language, 'manifest_revision' => (string) $manifest['revision'], 'rows' => $rows, 'skipped' => $skipped );
		}

		return array( 'success' => true, 'language' => $language, 'manifest_revision' => (string) $manifest['revision'], 'rows' => $rows, 'skipped' => array(), 'relation_authority_consumed' => $relation_authority_present, 'relation_authority_revision' => $relation_authority_present ? (string) ( $manifest['relation_receipts'][ $language ]['relation_revision'] ?? '' ) : (string) ( $fresh_relations['revision'] ?? '' ) );
	}

	/** Return the public blog archive URL from WordPress route data. */
	private static function public_blog_archive_url_for_language( string $language ): string {
		$language = sanitize_key( $language );
		$path     = self::localized_blog_base_path( $language );
		return '' !== $path ? trailingslashit( home_url( '/' . trim( $path, '/' ) . '/' ) ) : '';
	}

	/** Read the authoritative managed menu identity without mutating its source of truth. */
	private static function localized_menu_id( string $language ): int {
		$language   = sanitize_key( $language );
		$identities = get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, array() );
		$identities = is_array( $identities ) ? $identities : array();
		$stored_id   = absint( $identities[ $language ]['menu_id'] ?? 0 );
		$active_manifest = self::public_header_manifest();
		$active_revision = (string) ( $active_manifest['revision'] ?? '' );
		if ( $stored_id > 0 && '' !== $active_revision && wp_get_nav_menu_object( $stored_id ) && '1' === (string) get_term_meta( $stored_id, self::TERM_META_MENU_MANAGED, true ) && '' === (string) get_term_meta( $stored_id, self::TERM_META_PUBLIC_HEADER_RETIRED, true ) && $language === sanitize_key( (string) get_term_meta( $stored_id, self::TERM_META_MENU_LANGUAGE, true ) ) && hash_equals( $active_revision, (string) get_term_meta( $stored_id, self::TERM_META_PUBLIC_HEADER_MANIFEST_REVISION, true ) ) ) {
			return $stored_id;
		}
		return 0;
	}

	/**
	 * Compare a staged menu against the complete expected projection.
	 *
	 * @param array<int,array<string,mixed>> $expected Expected rows keyed by source item ID.
	 * @param array<int,int>                 $id_map   Source item to new item ID.
	 * @return array<string,mixed>
	 */
	private static function validate_localized_menu_projection( int $menu_id, array $expected, array $id_map ): array {
		$items  = wp_get_nav_menu_items( $menu_id, array( 'orderby' => 'menu_order' ) ) ?: array();
		$issues = array();
		if ( count( $items ) !== count( $expected ) ) {
			$issues[] = array( 'code' => 'menu_projection_count_mismatch', 'expected' => count( $expected ), 'actual' => count( $items ) );
		}
		$by_id = array();
		foreach ( $items as $item ) {
			$by_id[ (int) $item->ID ] = $item;
		}
		foreach ( $expected as $source_item_id => $row ) {
			$item_id = absint( $id_map[ $source_item_id ] ?? 0 );
			$item    = $by_id[ $item_id ] ?? null;
			if ( ! is_object( $item ) ) {
				$issues[] = array( 'code' => 'menu_projection_item_missing', 'source_item_id' => $source_item_id );
				continue;
			}
			$expected_parent = absint( $row['parent_source_item_id'] ?? 0 );
			$expected_parent = $expected_parent > 0 ? absint( $id_map[ $expected_parent ] ?? 0 ) : 0;
			$expected_url    = untrailingslashit( (string) ( $row['url'] ?? '' ) );
			$actual_url      = untrailingslashit( (string) ( $item->url ?? '' ) );
			if ( sanitize_text_field( (string) $item->title ) !== sanitize_text_field( (string) $row['title'] ) || $expected_url !== $actual_url || $expected_parent !== absint( $item->menu_item_parent ?? 0 ) ) {
				$issues[] = array(
					'code'                   => 'menu_projection_item_mismatch',
					'source_item_id'         => $source_item_id,
					'expected_title'         => (string) $row['title'],
					'actual_title'           => (string) $item->title,
					'expected_url'           => $expected_url,
					'actual_url'             => $actual_url,
					'expected_parent_item_id'=> $expected_parent,
					'actual_parent_item_id'  => absint( $item->menu_item_parent ?? 0 ),
				);
			}
		}

		return array( 'passed' => empty( $issues ), 'issues' => $issues, 'expected_count' => count( $expected ), 'actual_count' => count( $items ) );
	}

	/**
	 * Expected primary navigation labels and URLs for integrity checks.
	 *
	 * @return array<int,array{title:string,url:string}>
	 */
	private static function expected_localized_primary_navigation( string $language ): array {
		$plan = self::public_header_projection_plan( $language, false, true );
		if ( empty( $plan['success'] ) || empty( $plan['rows'] ) || self::localized_menu_id( $language ) < 1 ) {
			return array();
		}
		$rows_by_id = array();
		$items      = array();
		foreach ( (array) $plan['rows'] as $row ) {
			$item_id = absint( $row['source_item_id'] ?? 0 );
			$item = (object) array(
				'ID'               => $item_id,
				'menu_item_parent' => absint( $row['parent_source_item_id'] ?? 0 ),
				'menu_order'       => absint( $row['args']['menu-item-position'] ?? 0 ),
			);
			$items[] = $item;
			$rows_by_id[ $item_id ] = $row;
		}
		$expected = array();
		foreach ( self::localized_menu_items_in_render_order( $items ) as $item ) {
			$row = $rows_by_id[ (int) $item->ID ];
			$expected[] = array(
				'title' => trim( html_entity_decode( wp_strip_all_tags( (string) $row['title'] ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' ) ),
				'url'   => self::normalize_primary_navigation_url( (string) $row['url'] ),
			);
		}

		return $expected;
	}

	/**
	 * Resolve the same runtime-editable label used by the frontend menu Adapter.
	 */
	private static function effective_localized_menu_item_title( object $item, string $language ): string {
		$effective_item = $item;
		if ( isset( $item->type, $item->object ) && 'post_type' === (string) $item->type && 'page' === (string) $item->object && ! empty( $item->object_id ) ) {
			$effective_item = clone $item;
			$effective_item->object_id = (string) self::source_id_for_context( (int) $item->object_id );
		}
		$title = self::localized_menu_item_title( $effective_item, $language, isset( $item->title ) ? (string) $item->title : '' );

		return trim( html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' ) );
	}

	/**
	 * Flatten menu items in the same depth-first order used by WordPress walkers.
	 *
	 * The menu-item `menu_order` column orders siblings; it is not the rendered
	 * flat order when a later row is a child of an earlier root item.
	 *
	 * @param array<int,object> $items WordPress navigation menu items.
	 * @return array<int,object>
	 */
	private static function localized_menu_items_in_render_order( array $items ): array {
		$by_parent = array();
		$known_ids = array();
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || empty( $item->ID ) ) {
				continue;
			}
			$known_ids[ (int) $item->ID ] = true;
		}
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || empty( $item->ID ) ) {
				continue;
			}
			$parent_id = absint( $item->menu_item_parent ?? 0 );
			if ( $parent_id > 0 && ! isset( $known_ids[ $parent_id ] ) ) {
				$parent_id = 0;
			}
			$by_parent[ $parent_id ][] = $item;
		}
		foreach ( $by_parent as &$siblings ) {
			usort(
				$siblings,
				static function ( object $left, object $right ): int {
					$order = absint( $left->menu_order ?? 0 ) <=> absint( $right->menu_order ?? 0 );
					return 0 !== $order ? $order : (int) $left->ID <=> (int) $right->ID;
				}
			);
		}
		unset( $siblings );

		$ordered = array();
		$visited = array();
		$append = static function ( int $parent_id ) use ( &$append, &$by_parent, &$ordered, &$visited ): void {
			foreach ( $by_parent[ $parent_id ] ?? array() as $item ) {
				$item_id = (int) $item->ID;
				if ( isset( $visited[ $item_id ] ) ) {
					continue;
				}
				$visited[ $item_id ] = true;
				$ordered[] = $item;
				$append( $item_id );
			}
		};
		$append( 0 );
		foreach ( $items as $item ) {
			if ( is_object( $item ) && ! empty( $item->ID ) && ! isset( $visited[ (int) $item->ID ] ) ) {
				$ordered[] = $item;
			}
		}

		return $ordered;
	}

	/**
	 * Normalize relative and absolute menu links to one canonical comparison form.
	 */
	private static function normalize_primary_navigation_url( string $url ): string {
		$url = html_entity_decode( trim( $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return untrailingslashit( esc_url_raw( $url ) );
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$port   = isset( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '';
		$path   = '/' . ltrim( (string) ( $parts['path'] ?? '' ), '/' );
		$query  = isset( $parts['query'] ) && '' !== (string) $parts['query'] ? '?' . (string) $parts['query'] : '';
		$normalized = ( $scheme && $host ? $scheme . '://' . $host . $port : '' ) . untrailingslashit( $path ) . $query;

		return esc_url_raw( $normalized );
	}

	/**
	 * Validate both an origin-bypassing response and the exact canonical cacheable URL.
	 *
	 * @return array<string,mixed>
	 */
	private static function frontend_public_surface_integrity_for_url( string $url, string $language, int $timeout = 15, string $surface = 'public', array $expected_media = array(), array $expected_navigation = array(), ?array $provided_responses = null ): array {
		$url       = esc_url_raw( $url );
		$language  = sanitize_key( $language );
		$issues    = array();
		$warnings  = array();
		$responses = array();

		if ( '' === $url ) {
			$issues[] = self::qa_item( 'frontend_url_missing', 'Frontend URL is missing.', array( 'language' => $language, 'surface' => $surface ) );
		} else {
			foreach ( array( 'origin', 'canonical' ) as $cache_surface ) {
				$response = null !== $provided_responses
					? (array) ( $provided_responses[ $cache_surface ] ?? array() )
					: self::fetch_frontend_cache_surface( $url, $timeout, $cache_surface );
				$body     = (string) ( $response['body'] ?? '' );
				$final_url = (string) ( $response['final_url'] ?? $url );
				$responses[ $cache_surface ] = array_diff_key( $response, array( 'body' => true ) );
				if ( empty( $response['success'] ) ) {
					$issues[] = self::qa_item( 'frontend_integrity_http_error', 'Frontend integrity could not fetch a required cache surface.', array( 'language' => $language, 'url' => $url, 'cache_surface' => $cache_surface, 'error' => (string) ( $response['error'] ?? '' ) ) );
					continue;
				}
				if ( 200 !== (int) ( $response['status_code'] ?? 0 ) ) {
					$issues[] = self::qa_item( 'frontend_integrity_http_status_not_ok', 'Frontend integrity did not receive HTTP 200 from a required cache surface.', array( 'language' => $language, 'url' => $url, 'cache_surface' => $cache_surface, 'status_code' => (int) ( $response['status_code'] ?? 0 ) ) );
				}
				if ( '' === trim( $body ) ) {
					$issues[] = self::qa_item( 'frontend_integrity_empty_body', 'Frontend integrity received an empty response from a required cache surface.', array( 'language' => $language, 'url' => $url, 'cache_surface' => $cache_surface ) );
					continue;
				}
				$issues = array_merge( $issues, self::frontend_public_surface_html_issues( $body, $language, $final_url, $surface . '_' . $cache_surface ) );
				$issues = array_merge( $issues, self::localized_primary_navigation_html_issues( $body, $language, $url, $cache_surface, $expected_navigation ) );
				if ( $expected_media ) {
					$issues = array_merge( $issues, self::frontend_featured_image_html_issues( $body, $expected_media, $url, $cache_surface ) );
				}
				$expected_hreflang = self::hreflang_for_language( $language );
				if ( $expected_hreflang && ! preg_match( '/<link\b[^>]*rel=["\']alternate["\'][^>]*hreflang=["\']' . preg_quote( $expected_hreflang, '/' ) . '["\']/i', $body ) ) {
					$warnings[] = self::qa_item(
						'frontend_hreflang_missing',
						'Live page does not expose the expected hreflang alternate for this language.',
						array( 'language' => $language, 'hreflang' => $expected_hreflang, 'cache_surface' => $cache_surface )
					);
				}
			}
		}

		$canonical = $responses['canonical'] ?? array();
		return array(
			'success'         => empty( $issues ),
			'surface'         => sanitize_key( $surface ),
			'language'        => $language,
			'url'             => $url,
			'final_url'       => (string) ( $canonical['final_url'] ?? $url ),
			'passed'          => empty( $issues ),
			'issues'          => $issues,
			'warnings'        => $warnings,
			'issue_count'     => count( $issues ),
			'warning_count'   => count( $warnings ),
			'status_code'     => (int) ( $canonical['status_code'] ?? 0 ),
			'cache_responses' => $responses,
			'checked_at'      => gmdate( 'c' ),
		);
	}

	/**
	 * Verify the exact approved featured-image identity in rendered hero and SEO output.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function frontend_featured_image_html_issues( string $html, array $expected_media, string $url, string $cache_surface, $dom_parser_available = null ): array {
		$expected_id  = absint( $expected_media['attachment_id'] ?? 0 );
		$expected_url = esc_url_raw( (string) ( $expected_media['url'] ?? '' ) );
		$hero_urls    = array();
		$hero_srcset_urls = array();
		$hero_srcset_candidates = array();
		$srcset_well_formed = true;
		$open_graph   = array();
		$parse_success = false;
		$hero_element_count = 0;
		$open_graph_element_count = 0;
		$parser_available = null === $dom_parser_available ? class_exists( 'DOMDocument' ) : true === $dom_parser_available;
		if ( $parser_available && class_exists( 'DOMDocument' ) ) {
			$dom = new DOMDocument();
			$previous = libxml_use_internal_errors( true );
			$loaded = false;
			try {
				$loaded = $dom->loadHTML( $html );
			} catch ( Throwable $error ) {
				$loaded = false;
			} finally {
				libxml_clear_errors();
				libxml_use_internal_errors( $previous );
			}
			if ( $loaded ) {
				$xpath = new DOMXPath( $dom );
				$hero_nodes = $xpath->query( "//img[contains(concat(' ', normalize-space(@class), ' '), ' wp-post-image ')]" );
				$open_graph_nodes = $xpath->query( "//meta[translate(@property,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='og:image']" );
				if ( false !== $hero_nodes && false !== $open_graph_nodes ) {
					$parse_success = true;
					$hero_element_count = $hero_nodes->length;
					$open_graph_element_count = $open_graph_nodes->length;
				}
				foreach ( false !== $hero_nodes ? $hero_nodes : array() as $node ) {
					$hero_urls[] = esc_url_raw( (string) $node->getAttribute( 'src' ) );
					if ( $node->hasAttribute( 'srcset' ) ) {
						foreach ( explode( ',', (string) $node->getAttribute( 'srcset' ) ) as $candidate ) {
							$candidate = trim( $candidate );
							$parts = '' === $candidate ? array() : ( preg_split( '/\s+/', $candidate ) ?: array() );
							$url = esc_url_raw( (string) ( $parts[0] ?? '' ) );
							if ( '' !== $url ) { $hero_srcset_urls[] = $url; }
							$hero_srcset_candidates[] = array( 'url' => $url, 'descriptor' => (string) ( $parts[1] ?? '' ), 'token_count' => count( $parts ) );
						}
					}
				}
				foreach ( false !== $open_graph_nodes ? $open_graph_nodes : array() as $node ) {
					$open_graph[] = esc_url_raw( (string) $node->getAttribute( 'content' ) );
				}
			}
		}
		$normalize = static function ( string $candidate ): string {
			$parts = wp_parse_url( html_entity_decode( $candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( ! is_array( $parts ) ) { return ''; }
			return strtolower( (string) ( $parts['scheme'] ?? '' ) . '://' . (string) ( $parts['host'] ?? '' ) ) . (string) ( $parts['path'] ?? '' );
		};
		$expected_normalized = $normalize( $expected_url );
		$normalized_hero = array_values( array_unique( array_filter( array_map( $normalize, $hero_urls ) ) ) );
		$normalized_og = array_values( array_unique( array_filter( array_map( $normalize, $open_graph ) ) ) );
		$expected_srcset = array();
		if ( $expected_id > 0 ) {
			foreach ( preg_split( '/\s*,\s*/', (string) wp_get_attachment_image_srcset( $expected_id, 'full' ) ) ?: array() as $candidate ) {
				$parts = preg_split( '/\s+/', trim( $candidate ) );
				if ( ! empty( $parts[0] ) ) { $expected_srcset[ $normalize( (string) $parts[0] ) ] = (string) ( $parts[1] ?? '' ); }
			}
		}
		$actual_srcset = array();
		foreach ( $hero_srcset_candidates as $candidate ) {
			$normalized_candidate = $normalize( (string) $candidate['url'] );
			$descriptor = (string) $candidate['descriptor'];
			if ( 2 !== absint( $candidate['token_count'] ?? 0 ) || '' === $normalized_candidate || ! preg_match( '/^(?:[1-9][0-9]*w|(?:[1-9][0-9]*|0?\.[0-9]+)x)$/', $descriptor ) || isset( $actual_srcset[ $normalized_candidate ] ) ) { $srcset_well_formed = false; continue; }
			$actual_srcset[ $normalized_candidate ] = $descriptor;
		}
		ksort( $expected_srcset );
		ksort( $actual_srcset );
		$open_graph_policy = $expected_id > 0 ? 'exact_featured_image' : 'none_without_featured_image';
		$srcset_policy = $expected_srcset ? 'exact_attachment_candidate_descriptor_set' : 'none_available_for_attachment';
		$hero_matches = $expected_id <= 0
			? $parse_success && 0 === $hero_element_count && empty( $actual_srcset ) && $srcset_well_formed
			: $parse_success && 1 === $hero_element_count && array( $expected_normalized ) === $normalized_hero && $srcset_well_formed && $expected_srcset === $actual_srcset;
		$og_matches = $expected_id <= 0 ? $parse_success && 0 === $open_graph_element_count : $parse_success && 1 === $open_graph_element_count && array( $expected_normalized ) === $normalized_og;
		if ( $hero_matches && $og_matches ) { return array(); }
		return array(
			self::qa_item(
				'frontend_featured_image_identity_mismatch',
				'Rendered featured-image and Open Graph output do not match the approved Artifact Surface Revision.',
				array( 'url' => $url, 'cache_surface' => $cache_surface, 'expected_attachment_id' => $expected_id, 'expected_url' => $expected_url, 'open_graph_policy' => $open_graph_policy, 'srcset_policy' => $srcset_policy, 'parse_success' => $parse_success, 'hero_element_count' => $hero_element_count, 'open_graph_element_count' => $open_graph_element_count, 'hero_urls' => array_values( array_unique( $hero_urls ) ), 'hero_srcset_urls' => array_values( array_unique( $hero_srcset_urls ) ), 'expected_srcset' => $expected_srcset, 'actual_srcset' => $actual_srcset, 'srcset_well_formed' => $srcset_well_formed, 'open_graph_urls' => array_values( array_unique( $open_graph ) ) )
			)
		);
	}

	/** Verify only restored featured-media output on origin and canonical cache surfaces. */
	private static function verify_frontend_featured_image_for_url( string $url, array $expected_media, int $timeout = 15 ): array {
		$url = esc_url_raw( $url );
		$issues = array();
		$responses = array();
		if ( '' === $url ) { return array( 'success' => false, 'issues' => array( self::qa_item( 'frontend_url_missing', 'Rollback media verification requires a public URL.' ) ), 'responses' => array() ); }
		foreach ( array( 'origin', 'canonical' ) as $cache_surface ) {
			$response = self::fetch_frontend_cache_surface( $url, $timeout, $cache_surface );
			$responses[ $cache_surface ] = array_diff_key( $response, array( 'body' => true ) );
			if ( empty( $response['success'] ) || 200 !== (int) ( $response['status_code'] ?? 0 ) ) {
				$issues[] = self::qa_item( 'frontend_integrity_http_error', 'Rollback media verification could not fetch a required cache surface.', array( 'url' => $url, 'cache_surface' => $cache_surface ) );
				continue;
			}
			$issues = array_merge( $issues, self::frontend_featured_image_html_issues( (string) ( $response['body'] ?? '' ), $expected_media, $url, $cache_surface ) );
		}
		return array( 'success' => empty( $issues ), 'issues' => $issues, 'responses' => $responses );
	}

	/**
	 * Fetch the complete all-language Public Header oracle through one bounded plan.
	 *
	 * @param string[] $languages Configured language codes.
	 * @return array<string,array<string,array<string,array<string,mixed>>>>
	 */
	private static function public_header_frontend_cache_response_set( array $languages, int $timeout, ?int $concurrency_limit = null ): array {
		$requests = array();
		$keys = array();
		$index = 0;
		foreach ( $languages as $language ) {
			$language = sanitize_key( (string) $language );
			foreach ( array( 'homepage' => self::localized_home_url_for_language( $language ), 'blog_archive' => self::public_blog_archive_url_for_language( $language ) ) as $surface => $url ) {
				foreach ( array( 'origin', 'canonical' ) as $cache_surface ) {
					$key = 'public_header_' . $index++;
					$requests[ $key ] = array( 'url' => (string) $url, 'surface' => $cache_surface );
					$keys[ $language ][ $surface ][ $cache_surface ] = $key;
				}
			}
		}
		$fetched = self::fetch_frontend_cache_surfaces( $requests, $timeout, $concurrency_limit );
		$responses = array();
		foreach ( $keys as $language => $surfaces ) {
			foreach ( $surfaces as $surface => $cache_surfaces ) {
				foreach ( $cache_surfaces as $cache_surface => $key ) {
					$responses[ $language ][ $surface ][ $cache_surface ] = (array) ( $fetched[ $key ] ?? array() );
				}
			}
		}
		return $responses;
	}

	/** Allocate one timeout while reserving a viable minimum for every later dispatch. */
	private static function public_header_dispatch_timeout( int $requested_timeout, int $remaining_dispatches, int $wall_remaining, int $minimum_timeout ): int {
		if ( $remaining_dispatches < 1 || $wall_remaining < 1 || $minimum_timeout < 1 ) { return 0; }
		$reserved_for_later = ( $remaining_dispatches - 1 ) * $minimum_timeout;
		return min( $requested_timeout, max( 0, $wall_remaining - $reserved_for_later ) );
	}

	/**
	 * Fetch explicit cache surfaces through a bounded WordPress Requests plan.
	 *
	 * The returned shape is identical to fetch_frontend_cache_surface(), so every
	 * existing fail-closed parser and verifier consumes the same evidence.
	 *
	 * @param array<string,array{url:string,surface:string}> $requests Requests keyed by caller identity.
	 * @return array<string,array<string,mixed>>
	 */
	private static function fetch_frontend_cache_surfaces( array $requests, int $timeout, ?int $concurrency_limit = null ): array {
		$normalized = array();
		foreach ( $requests as $key => $request ) {
			$url = self::same_site_frontend_evidence_url( (string) ( $request['url'] ?? '' ) );
			$surface = 'canonical' === (string) ( $request['surface'] ?? '' ) ? 'canonical' : 'origin';
			if ( '' !== $url ) { $normalized[ (string) $key ] = array( 'url' => $url, 'surface' => $surface ); }
		}
		if ( empty( $normalized ) ) { return array(); }

		$requests_class = '\\WpOrg\\Requests\\Requests';
		$response_class = '\\WpOrg\\Requests\\Response';
		$curl_class = '\\WpOrg\\Requests\\Transport\\Curl';
		$capability_class = '\\WpOrg\\Requests\\Capability';
		$failure = static function ( array $request, string $fetch_url, string $code, string $message ): array {
			return array(
				'success' => false,
				'code' => $code,
				'surface' => $request['surface'],
				'url' => $fetch_url,
				'error' => $message,
				'status_code' => 0,
				'body' => '',
				'cf_cache_status' => '',
				'age' => '',
			);
		};
		$fail_all = static function ( array $source, array $native, string $code, string $message ) use ( $failure ): array {
			$failed = array();
			foreach ( $source as $key => $request ) { $failed[ $key ] = $failure( $request, (string) ( $native[ $key ]['url'] ?? $request['url'] ), $code, $message ); }
			return $failed;
		};
		$native = array();
		foreach ( $normalized as $key => $request ) {
			$canonical = 'canonical' === $request['surface'];
			$fetch_url = $canonical ? $request['url'] : add_query_arg( 'devenia_frontend_integrity', wp_generate_uuid4(), $request['url'] );
			$native[ $key ] = array(
				'url' => $fetch_url,
				'type' => 'GET',
				'headers' => $canonical ? array() : array( 'Cache-Control' => 'no-cache, no-store, max-age=0' ),
			);
		}
		$requested_concurrency = null === $concurrency_limit
			? absint( apply_filters( 'devenia_workflow_public_header_self_fetch_concurrency_limit', self::PUBLIC_HEADER_REQUEST_CONCURRENCY_LIMIT, count( $native ) ) )
			: absint( $concurrency_limit );
		$requested_concurrency = max( 1, min( self::PUBLIC_HEADER_REQUEST_CONCURRENCY_LIMIT, $requested_concurrency ) );
		$dispatches = array_chunk( $native, $requested_concurrency, true );
		$minimum_timeout = 3;
		if ( count( $dispatches ) * $minimum_timeout >= self::PUBLIC_HEADER_BATCH_BUDGET_SECONDS ) {
			return $fail_all( $normalized, $native, 'public_header_batch_budget_exceeded', 'The complete frontend cache request plan exceeds its bounded runtime budget.' );
		}
		$requested_timeout = max( $minimum_timeout, min( 30, $timeout ) );
		$deadline = microtime( true ) + self::PUBLIC_HEADER_BATCH_BUDGET_SECONDS;
		$options = array(
			'timeout' => $requested_timeout,
			'connect_timeout' => $requested_timeout,
			'redirects' => 0,
			'max_bytes' => self::FRONTEND_EVIDENCE_MAX_BYTES,
			'useragent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
			'verify' => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			'transport' => $curl_class,
		);
		try {
			$batch = apply_filters( 'devenia_workflow_frontend_cache_batch_adapter_result', null, $native, $options );
			if ( null === $batch ) {
				if ( ! class_exists( $requests_class ) || ! class_exists( $response_class ) || ! class_exists( 'WP_HTTP_Requests_Response' ) || ! class_exists( $curl_class ) || ( ! class_exists( $capability_class ) && ! interface_exists( $capability_class ) ) || ! $curl_class::test( array( $capability_class::SSL => true ) ) ) {
					return $fail_all( $normalized, $native, 'public_header_batch_transport_unavailable', 'Concurrent WordPress cURL transport is unavailable.' );
				}
				$batch = array();
				$dispatch_count = count( $dispatches );
				foreach ( $dispatches as $dispatch_index => $dispatch ) {
					$remaining_dispatches = $dispatch_count - $dispatch_index;
					$wall_remaining = (int) floor( $deadline - microtime( true ) );
					$dispatch_timeout = self::public_header_dispatch_timeout( $requested_timeout, $remaining_dispatches, $wall_remaining, $minimum_timeout );
					if ( $dispatch_timeout < $minimum_timeout ) {
						return $fail_all( $normalized, $native, 'public_header_batch_budget_exhausted', 'The complete frontend cache request plan exhausted its bounded runtime budget.' );
					}
					$dispatch_options = array_merge( $options, array( 'timeout' => $dispatch_timeout, 'connect_timeout' => $dispatch_timeout ) );
					$partial = $requests_class::request_multiple( $dispatch, $dispatch_options );
					if ( ! is_array( $partial ) || ! empty( array_diff_key( $partial, $dispatch ) ) || ! empty( array_diff_key( $dispatch, $partial ) ) || ! empty( array_intersect_key( $batch, $partial ) ) ) {
						return $fail_all( $normalized, $native, 'public_header_batch_result_key_mismatch', 'Concurrent frontend cache results did not match the exact requested key set.' );
					}
					$batch += $partial;
				}
			}
		} catch ( Throwable $error ) {
			return $fail_all( $normalized, $native, 'public_header_batch_request_failed', $error->getMessage() );
		}
		if ( ! is_array( $batch ) || count( $batch ) !== count( $native ) || ! empty( array_diff_key( $batch, $native ) ) || ! empty( array_diff_key( $native, $batch ) ) ) {
			return $fail_all( $normalized, $native, 'public_header_batch_result_key_mismatch', 'Concurrent frontend cache results did not match the exact requested key set.' );
		}
		$responses = array();
		foreach ( array_keys( $native ) as $key ) {
			$request = $normalized[ $key ];
			$fetch_url = (string) $native[ $key ]['url'];
			$response = $batch[ $key ];
			if ( is_object( $response ) && is_a( $response, $response_class ) ) {
				$response = ( new WP_HTTP_Requests_Response( $response ) )->to_array();
			}
			if ( is_wp_error( $response ) ) {
				$responses[ $key ] = $failure( $request, $fetch_url, 'public_header_batch_member_failed', $response->get_error_message() );
				continue;
			}
			if ( $response instanceof Throwable || ! is_array( $response ) ) {
				$responses[ $key ] = $failure( $request, $fetch_url, 'public_header_batch_member_failed', $response instanceof Throwable ? $response->getMessage() : 'Concurrent frontend cache request did not return a response.' );
				continue;
			}
			$responses[ $key ] = array(
				'success' => true,
				'surface' => $request['surface'],
				'url' => $fetch_url,
				'final_url' => self::wp_remote_final_url( $response, $fetch_url ),
				'status_code' => (int) wp_remote_retrieve_response_code( $response ),
				'body' => (string) wp_remote_retrieve_body( $response ),
				'cf_cache_status' => (string) wp_remote_retrieve_header( $response, 'cf-cache-status' ),
				'age' => (string) wp_remote_retrieve_header( $response, 'age' ),
			);
		}
		$ordered = array();
		foreach ( array_keys( $normalized ) as $key ) {
			$ordered[ $key ] = $responses[ $key ];
		}
		return $ordered;
	}

	/**
	 * Fetch one explicit cache surface and expose cache response evidence.
	 *
	 * @return array<string,mixed>
	 */
	private static function fetch_frontend_cache_surface( string $url, int $timeout, string $surface ): array {
		$url = self::same_site_frontend_evidence_url( $url );
		if ( '' === $url ) {
			return array(
				'success' => false,
				'code' => 'frontend_evidence_url_rejected',
				'surface' => $surface,
				'url' => '',
				'error' => 'Frontend evidence requests must target the configured WordPress site.',
				'status_code' => 0,
				'body' => '',
				'cf_cache_status' => '',
				'age' => '',
			);
		}
		$canonical = 'canonical' === $surface;
		$fetch_url = $canonical ? $url : add_query_arg( 'devenia_frontend_integrity', wp_generate_uuid4(), $url );
		$args      = array(
			'timeout'     => max( 3, min( 30, $timeout ) ),
			'redirection' => 3,
			'limit_response_size' => self::FRONTEND_EVIDENCE_MAX_BYTES,
		);
		if ( ! $canonical ) {
			$args['headers'] = array( 'Cache-Control' => 'no-cache, no-store, max-age=0' );
		}
		$response = wp_safe_remote_get( $fetch_url, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'success'         => false,
				'surface'         => $surface,
				'url'             => $fetch_url,
				'error'           => $response->get_error_message(),
				'status_code'     => 0,
				'body'            => '',
				'cf_cache_status' => '',
				'age'             => '',
			);
		}

		return array(
			'success'         => true,
			'surface'         => $surface,
			'url'             => $fetch_url,
			'final_url'       => self::wp_remote_final_url( $response, $fetch_url ),
			'status_code'     => (int) wp_remote_retrieve_response_code( $response ),
			'body'            => (string) wp_remote_retrieve_body( $response ),
			'cf_cache_status' => (string) wp_remote_retrieve_header( $response, 'cf-cache-status' ),
			'age'             => (string) wp_remote_retrieve_header( $response, 'age' ),
		);
	}

	/**
	 * Validate an HTTP evidence URL against the configured WordPress origin.
	 *
	 * This is the owning seam for every self-fetch path. It prevents internal
	 * callers and filters from turning frontend verification into an SSRF path.
	 */
	private static function same_site_frontend_evidence_url( string $url ): string {
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return '';
		}

		$site_url = home_url( '/' );
		$target_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$site_host = strtolower( (string) wp_parse_url( $site_url, PHP_URL_HOST ) );
		$target_scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$site_scheme = strtolower( (string) wp_parse_url( $site_url, PHP_URL_SCHEME ) );
		$target_port = absint( wp_parse_url( $url, PHP_URL_PORT ) ?: ( 'https' === $target_scheme ? 443 : 80 ) );
		$site_port = absint( wp_parse_url( $site_url, PHP_URL_PORT ) ?: ( 'https' === $site_scheme ? 443 : 80 ) );
		if (
			'' === $target_host
			|| '' === $site_host
			|| ! hash_equals( $site_host, $target_host )
			|| ! hash_equals( $site_scheme, $target_scheme )
			|| $site_port !== $target_port
		) {
			return '';
		}

		return $url;
	}

	/**
	 * Verify that rendered primary navigation is the authoritative localized menu.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function localized_primary_navigation_html_issues( string $html, string $language, string $url, string $cache_surface, array $expected_navigation = array() ): array {
		$expected = ! empty( $expected_navigation ) ? array_values( $expected_navigation ) : self::expected_localized_primary_navigation( $language );
		if ( empty( $expected ) ) {
			return array( self::qa_item( 'frontend_primary_menu_identity_missing', 'No authoritative localized primary-menu identity is configured.', array( 'language' => $language, 'url' => $url, 'cache_surface' => $cache_surface ) ) );
		}

		$actual = self::primary_navigation_from_html( $html, $language );

		if ( $actual === $expected ) {
			return array();
		}

		return array(
			self::qa_item(
				'frontend_primary_menu_projection_mismatch',
				'Rendered primary navigation does not match the authoritative localized menu identity, labels, and URLs.',
				array(
					'language'       => $language,
					'url'            => $url,
					'cache_surface'  => $cache_surface,
					'expected_menu_id' => self::localized_menu_id( $language ),
					'expected'       => $expected,
					'actual'         => $actual,
				)
			),
		);
	}

	/** Extract the exact rendered primary-navigation anchor sequence. */
	private static function primary_navigation_from_html( string $html, string $language = '' ): array {
		$actual = array();
		if ( class_exists( 'DOMDocument' ) ) {
			$dom = new DOMDocument();
			$previous = libxml_use_internal_errors( true );
			$loaded = $dom->loadHTML( $html );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			if ( $loaded ) {
				$xpath = new DOMXPath( $dom );
				$expressions = apply_filters(
					'devenia_workflow_primary_navigation_xpaths',
					array(
						"(//nav[@id='site-navigation']//ul[contains(concat(' ', normalize-space(@class), ' '), ' menu ')])[1]//a[@href and not(ancestor::*[contains(concat(' ', normalize-space(@class), ' '), ' devenia-language-menu-dropdown ')]) and not(contains(concat(' ', normalize-space(@class), ' '), ' devenia-language-trigger ')) and not(contains(concat(' ', normalize-space(@class), ' '), ' devenia-language-menu-item '))]",
						"(//nav[contains(concat(' ', normalize-space(@class), ' '), ' main-navigation ')]//ul[contains(concat(' ', normalize-space(@class), ' '), ' menu ')])[1]//a[@href and not(ancestor::*[contains(concat(' ', normalize-space(@class), ' '), ' devenia-language-menu-dropdown ')]) and not(contains(concat(' ', normalize-space(@class), ' '), ' devenia-language-trigger ')) and not(contains(concat(' ', normalize-space(@class), ' '), ' devenia-language-menu-item '))]",
					),
					$language
				);
				if ( is_array( $expressions ) ) {
					foreach ( $expressions as $expression ) {
						$nodes = $xpath->query( (string) $expression );
						if ( ! $nodes || 0 === $nodes->length ) {
							continue;
						}
						foreach ( $nodes as $node ) {
							$actual[] = array(
								'title' => trim( preg_replace( '/\s+/u', ' ', (string) $node->textContent ) ?? '' ),
								'url'   => self::normalize_primary_navigation_url( (string) $node->getAttribute( 'href' ) ),
							);
						}
						break;
					}
				}
			}
		}

		return $actual;
	}
}
