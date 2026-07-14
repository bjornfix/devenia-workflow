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
	 * Stage, validate, activate, invalidate, and verify one Public Header
	 * Projection. This is the operator-facing deep Interface; callers never
	 * coordinate raw menu writes or cache ordering themselves.
	 *
	 * @param array<string,mixed> $input Projection arguments.
	 * @return array<string,mixed>
	 */
	private static function sync_public_header_projection( array $input ): array {
		$pending = self::pending_public_header_manifest();
		if ( empty( $pending ) ) {
			return array( 'success' => false, 'code' => 'public_header_pending_manifest_missing', 'message' => 'Register a pending Public Header Projection manifest before activation.' );
		}
		$languages = self::configured_public_header_languages();
		if ( empty( $languages ) ) {
			return array( 'success' => false, 'code' => 'public_header_source_language_invalid', 'message' => 'Exactly one configured source language is required before Public Header Projection can run.' );
		}
		$staged    = array();
		foreach ( $languages as $language ) {
			$projection = self::sync_language_menu(
				array(
					'language'             => $language,
					'include_untranslated' => false,
					'include_custom_links' => true,
					'stage_only'           => true,
					'manifest'             => $pending,
				)
			);
			if ( empty( $projection['success'] ) || empty( $projection['validation']['passed'] ) || '' === (string) ( $projection['menu_surface_revision'] ?? '' ) || ! empty( $projection['skipped'] ) || count( (array) ( $projection['added'] ?? array() ) ) !== count( (array) $pending['items'] ) ) {
				self::delete_staged_public_header_projections( $staged );
				return array( 'success' => false, 'code' => 'public_header_projection_staging_failed', 'message' => 'Every configured source and target projection must stage, validate, and produce a recovery receipt before activation.', 'failed_language' => $language, 'projection' => $projection );
			}
			$staged[ $language ] = $projection;
		}

		$activation = self::activate_public_header_projection_set( $pending, $staged );
		if ( empty( $activation['success'] ) ) {
			self::delete_staged_public_header_projections( $staged );
			return array( 'success' => false, 'code' => 'public_header_projection_activation_failed', 'message' => 'The complete Public Header Projection set was not activated.', 'activation' => $activation );
		}

		$purge_urls = self::public_header_projection_urls( $languages );
		$context = array( 'event' => 'public_header_projection', 'manifest_revision' => (string) $pending['revision'], 'languages' => $languages );
		$invalidation = apply_filters( 'devenia_workflow_frontend_cache_invalidation_result', null, $purge_urls, $context );
		if ( ! is_array( $invalidation ) || true !== ( $invalidation['success'] ?? null ) ) {
			return self::public_header_failure_after_activation( 'public_header_cache_invalidation_failed', 'The activated Public Header Projection set could not be invalidated.', $activation, $staged, $purge_urls, $invalidation, array(), $input );
		}

		$verification = self::verify_public_header_projection_set( $languages, absint( $input['timeout'] ?? 15 ) );
		if ( empty( $verification['passed'] ) ) {
			return self::public_header_failure_after_activation( 'public_header_projection_verification_failed', 'The activated Public Header Projection set failed origin or canonical verification.', $activation, $staged, $purge_urls, $invalidation, $verification, $input );
		}

		$retirement = self::retire_previous_public_header_projection_set( $staged );
		if ( empty( $retirement['success'] ) ) {
			return self::public_header_failure_after_activation( 'public_header_projection_retirement_failed', 'The prior Public Header Projection set could not be retired safely.', $activation, $staged, $purge_urls, $invalidation, $verification, $input, $retirement );
		}

		return array( 'success' => true, 'manifest_revision' => (string) $pending['revision'], 'languages' => $languages, 'projections' => $staged, 'activation' => $activation, 'purge_urls' => $purge_urls, 'cache_invalidation' => $invalidation, 'verification' => $verification, 'retirement' => $retirement );
	}

	/**
	 * Ensure normal Translation Job publication enters the same pending-manifest
	 * Interface as the operator Ability. An intentional pending revision wins;
	 * otherwise the active manifest is copied to pending for a complete refresh.
	 */
	private static function stage_public_header_manifest_for_publication(): array {
		$pending = self::pending_public_header_manifest();
		if ( ! empty( $pending ) ) {
			return array( 'success' => true, 'manifest' => $pending, 'existing_pending' => true );
		}
		$active = self::public_header_manifest();
		if ( empty( $active ) ) {
			return array( 'success' => false, 'code' => 'public_header_active_manifest_missing', 'message' => 'Translation publication requires an enrolled active Public Header Projection manifest.' );
		}
		$missing = '__devenia_workflow_option_missing__';
		$before  = get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $missing );
		$pending = $active;
		$pending['updated_at'] = gmdate( 'c' );
		$written = $missing === $before
			? self::atomic_create_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $pending )
			: self::atomic_replace_option_value( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $before, $pending );
		$stored = get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $missing );
		if ( ! $written || self::translation_job_canonicalize( $stored ) !== self::translation_job_canonicalize( $pending ) ) {
			return array( 'success' => false, 'code' => 'public_header_pending_manifest_refresh_failed', 'message' => 'The active Public Header Projection manifest could not be staged for complete publication refresh.' );
		}
		return array( 'success' => true, 'manifest' => $pending, 'existing_pending' => false );
	}

	/** Run the one deep all-language header Interface during content publication. */
	private static function refresh_public_header_projection_for_publication( int $timeout ): array {
		$staging = self::stage_public_header_manifest_for_publication();
		if ( empty( $staging['success'] ) ) {
			return $staging;
		}
		$result = self::sync_public_header_projection( array( 'timeout' => max( 3, min( 30, $timeout ) ) ) );
		$result['manifest_staging'] = $staging;
		return $result;
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
	private static function verify_public_header_projection_set( array $languages, int $timeout ): array {
		$items = array();
		$passed = true;
		foreach ( $languages as $language ) {
			foreach ( array( 'homepage' => self::localized_home_url_for_language( (string) $language ), 'blog_archive' => self::public_blog_archive_url_for_language( (string) $language ) ) as $surface => $url ) {
				$item = self::frontend_public_surface_integrity_for_url( (string) $url, (string) $language, $timeout, $surface );
				$items[ (string) $language ][ $surface ] = $item;
				$passed = $passed && ! empty( $item['passed'] ) && isset( $item['cache_responses']['origin'], $item['cache_responses']['canonical'] );
			}
		}
		return array( 'success' => $passed, 'passed' => $passed, 'items' => $items );
	}

	/**
	 * Activate one complete staged set and its pending manifest in one database transaction.
	 *
	 * @param array<string,mixed> $pending Pending manifest.
	 * @param array<string,array<string,mixed>> $staged Staged projections.
	 */
	private static function activate_public_header_projection_set( array $pending, array $staged ): array {
		$missing = '__devenia_workflow_option_missing__';
		$before = array(
			'manifest'   => get_option( self::OPTION_PUBLIC_HEADER_MANIFEST, $missing ),
			'identities' => get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, $missing ),
			'pending'    => get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $missing ),
			'enrollment' => get_option( self::OPTION_PUBLIC_HEADER_ENROLLMENT, $missing ),
		);
		if ( self::translation_job_canonicalize( $before['pending'] ) !== self::translation_job_canonicalize( $pending ) ) {
			return array( 'success' => false, 'code' => 'pending_manifest_changed_before_activation' );
		}
		$identities = is_array( $before['identities'] ) ? $before['identities'] : array();
		foreach ( $staged as $language => $projection ) {
			$identities[ $language ] = array( 'menu_id' => absint( $projection['target_menu']['id'] ?? 0 ), 'configured_name' => (string) ( $projection['target_menu']['name'] ?? '' ), 'manifest_revision' => (string) $pending['revision'], 'activated_at' => gmdate( 'c' ) );
		}
		$after = array(
			'manifest'   => $pending,
			'identities' => $identities,
			'pending'    => array( 'status' => 'activated', 'revision' => (string) $pending['revision'], 'activated_at' => gmdate( 'c' ) ),
			'enrollment' => '1',
		);
		do_action( 'devenia_workflow_public_header_before_locked_state_transition', $pending, $before );
		$result = self::replace_public_header_state_transaction( $before, $after, $staged );
		return array_merge( $result, array( 'before' => $before, 'after' => $after ) );
	}

	/** Remove option-cache values which may reflect writes later rolled back by MySQL. */
	private static function clear_public_header_state_option_cache(): void {
		foreach ( array( self::OPTION_PUBLIC_HEADER_MANIFEST, self::OPTION_LOCALIZED_MENU_IDENTITIES, self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, self::OPTION_PUBLIC_HEADER_ENROLLMENT ) as $key ) {
			wp_cache_delete( $key, 'options' );
		}
	}

	/** Roll back the owned transaction and discard every possibly uncommitted option-cache value. */
	private static function rollback_public_header_state_transaction(): void {
		self::translation_job_rollback_recovery_transaction();
		self::clear_public_header_state_option_cache();
	}

	/** Atomically replace active manifest, all identities, and pending state. */
	private static function replace_public_header_state_transaction( array $expected, array $replacement, array $staged = array() ): array {
		if ( ! self::translation_job_begin_recovery_transaction() ) { return array( 'success' => false, 'code' => 'public_header_transaction_unavailable' ); }
		try {
			global $wpdb;
			foreach ( $staged as $language => $projection ) {
				$menu_id = absint( $projection['target_menu']['id'] ?? 0 );
				$receipt = (string) ( $projection['menu_surface_revision'] ?? '' );
				$manifest_revision = (string) ( $projection['manifest_revision'] ?? '' );
				$locked = $menu_id > 0 ? self::lock_localized_menu_projection_surface( $menu_id ) : array();
				$current = $menu_id > 0 ? self::localized_menu_projection_revision( $menu_id ) : '';
				if ( empty( $locked['success'] ) || '' === $receipt || '' === $current || ! hash_equals( $receipt, $current ) || '1' !== (string) get_term_meta( $menu_id, self::TERM_META_MENU_MANAGED, true ) || sanitize_key( (string) $language ) !== sanitize_key( (string) get_term_meta( $menu_id, self::TERM_META_MENU_LANGUAGE, true ) ) || '' === $manifest_revision || ! hash_equals( $manifest_revision, (string) get_term_meta( $menu_id, self::TERM_META_PUBLIC_HEADER_MANIFEST_REVISION, true ) ) ) {
					self::rollback_public_header_state_transaction();
					return array( 'success' => false, 'code' => 'public_header_staged_receipt_changed', 'language' => (string) $language );
				}
			}
			$keys = array( self::OPTION_PUBLIC_HEADER_MANIFEST, self::OPTION_LOCALIZED_MENU_IDENTITIES, self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, self::OPTION_PUBLIC_HEADER_ENROLLMENT );
			$locked_options = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- SELECT FOR UPDATE is required to lock the four fixed option rows inside the owned transaction; the option cache is invalidated before every read below.
				$wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name IN (%s, %s, %s, %s) FOR UPDATE", $keys[0], $keys[1], $keys[2], $keys[3] )
			);
			if ( false === $locked_options ) {
				self::rollback_public_header_state_transaction();
				return array( 'success' => false, 'code' => 'public_header_state_lock_failed' );
			}
			$map = array( 'manifest' => self::OPTION_PUBLIC_HEADER_MANIFEST, 'identities' => self::OPTION_LOCALIZED_MENU_IDENTITIES, 'pending' => self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, 'enrollment' => self::OPTION_PUBLIC_HEADER_ENROLLMENT );
			foreach ( $map as $slot => $key ) {
				wp_cache_delete( $key, 'options' );
				$current = get_option( $key, '__devenia_workflow_option_missing__' );
				$expected_value = $expected[ $slot ] ?? '__devenia_workflow_option_missing__';
				$replacement_value = $replacement[ $slot ] ?? '__devenia_workflow_option_missing__';
				if ( self::translation_job_canonicalize( $current ) !== self::translation_job_canonicalize( $expected_value ) ) { self::rollback_public_header_state_transaction(); return array( 'success' => false, 'code' => 'public_header_state_changed', 'slot' => $slot ); }
				if ( self::translation_job_canonicalize( $current ) === self::translation_job_canonicalize( $replacement_value ) ) {
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
			$commit = self::translation_job_commit_recovery_transaction();
			self::clear_public_header_state_option_cache();
			if ( empty( $commit['success'] ) ) { return array( 'success' => false, 'code' => 'public_header_state_commit_failed', 'commit' => $commit ); }
			return array( 'success' => true, 'commit' => $commit );
		} catch ( Throwable $error ) {
			self::rollback_public_header_state_transaction();
			return array( 'success' => false, 'code' => 'public_header_state_exception' );
		}
	}

	/** Handle every post-activation failure through verified cache-safe rollback. */
	private static function public_header_failure_after_activation( string $code, string $message, array $activation, array $staged, array $purge_urls, $invalidation, array $verification, array $input, array $retirement = array() ): array {
		$rollback_receipts = self::public_header_rollback_projection_receipts( $activation, $staged );
		$rollback = empty( $rollback_receipts['success'] )
			? array( 'success' => false, 'code' => (string) ( $rollback_receipts['code'] ?? 'public_header_rollback_receipt_invalid' ), 'receipt_validation' => $rollback_receipts )
			: self::replace_public_header_state_transaction( (array) $activation['after'], (array) $activation['before'], (array) ( $rollback_receipts['projections'] ?? array() ) );
		$rollback_invalidation = null;
		$rollback_verification = array();
		if ( ! empty( $rollback['success'] ) ) {
			$rollback_invalidation = apply_filters( 'devenia_workflow_frontend_cache_invalidation_result', null, $purge_urls, array( 'event' => 'public_header_projection_rollback', 'failed_code' => $code ) );
			if ( is_array( $rollback_invalidation ) && true === ( $rollback_invalidation['success'] ?? null ) ) {
				$rollback_verification = self::verify_public_header_projection_set( self::configured_public_header_languages(), absint( $input['timeout'] ?? 15 ) );
			}
		}
		$rollback_complete = ! empty( $rollback['success'] ) && is_array( $rollback_invalidation ) && true === ( $rollback_invalidation['success'] ?? null ) && ! empty( $rollback_verification['passed'] );
		if ( $rollback_complete ) { self::delete_staged_public_header_projections( $staged ); }
		return array( 'success' => false, 'code' => $rollback_complete ? $code : 'public_header_projection_severe_rollback_failure', 'severity' => $rollback_complete ? 'error' : 'critical', 'failed_code' => $code, 'message' => $rollback_complete ? $message : 'Public Header Projection activation failed and the prior cached reader surface could not be proven restored.', 'cache_invalidation' => $invalidation, 'verification' => $verification, 'retirement' => $retirement, 'rollback' => $rollback, 'rollback_cache_invalidation' => $rollback_invalidation, 'rollback_verification' => $rollback_verification );
	}

	/**
	 * Rebind the exact prior complete set as locked transaction receipts.
	 * A post-activation rollback must never point readers at an old term which
	 * changed after staging captured its recovery revision.
	 */
	private static function public_header_rollback_projection_receipts( array $activation, array $staged ): array {
		$before_manifest = self::normalize_public_header_manifest( $activation['before']['manifest'] ?? array() );
		if ( empty( $before_manifest ) ) {
			return array( 'success' => true, 'projections' => array(), 'pre_enrollment' => true );
		}

		$projections = array();
		foreach ( self::configured_public_header_languages() as $language ) {
			$projection = isset( $staged[ $language ] ) && is_array( $staged[ $language ] ) ? $staged[ $language ] : array();
			$menu_id    = absint( $projection['previous_menu_id'] ?? 0 );
			$receipt    = (string) ( $projection['previous_menu_surface_revision'] ?? '' );
			if ( $menu_id < 1 || '' === $receipt ) {
				return array( 'success' => false, 'code' => 'public_header_rollback_receipt_missing', 'language' => $language );
			}
			$projections[ $language ] = array(
				'target_menu'          => array( 'id' => $menu_id ),
				'menu_surface_revision'=> $receipt,
				'manifest_revision'    => (string) $before_manifest['revision'],
			);
		}

		return array( 'success' => true, 'projections' => $projections );
	}

	/** Attach a verified all-language header rollback to a later content failure. */
	private static function publication_failure_with_public_header_rollback( array $failure, $header, int $timeout ): array {
		if ( ! is_array( $header ) || empty( $header['success'] ) ) {
			return $failure;
		}
		$rollback = self::public_header_failure_after_activation(
			'localized_presentation_followup_failed',
			'A later localized presentation check failed after header activation.',
			(array) ( $header['activation'] ?? array() ),
			(array) ( $header['projections'] ?? array() ),
			(array) ( $header['purge_urls'] ?? array() ),
			$header['cache_invalidation'] ?? null,
			(array) ( $header['verification'] ?? array() ),
			array( 'timeout' => max( 3, min( 30, $timeout ) ) )
		);
		$failure['public_header_rollback'] = $rollback;
		if ( 'public_header_projection_severe_rollback_failure' === (string) ( $rollback['code'] ?? '' ) ) {
			$failure['code'] = 'publication_rollback_failed';
			$failure['message'] = 'Localized presentation failed and the prior cached Public Header Projection could not be proven restored.';
		}
		return $failure;
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

	/** Delete only unactivated staged menus whose pre-activation receipt still matches. */
	private static function delete_staged_public_header_projections( array $staged ): void {
		foreach ( $staged as $projection ) {
			$menu_id = absint( $projection['target_menu']['id'] ?? 0 );
			$receipt = (string) ( $projection['menu_surface_revision'] ?? '' );
			$current = $menu_id ? self::localized_menu_projection_revision( $menu_id ) : '';
			if ( $menu_id > 0 && '' !== $receipt && '' !== $current && hash_equals( $receipt, $current ) ) { wp_delete_nav_menu( $menu_id ); }
		}
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
	 * Publish content, project its menu, invalidate caches, and verify both public views.
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
			return array_merge( array( 'success' => false, 'code' => 'publication_transaction_unavailable', 'message' => 'The localized presentation transaction could not be started.', 'published' => false, 'mutation_started' => $recover_staged_mutation, 'mutation_cas_revision' => $prior_mutation_cas_revision ), self::translation_job_recovery_transaction_error_fields() );
		}
		try {
		$locked = self::translation_job_lock_recovery_surface( $translation_id, $term_scope, $identity_scope );
		if ( empty( $locked['success'] ) ) {
			return self::translation_job_failure_after_recovery_rollback( array_merge( $locked, array( 'published' => false, 'mutation_started' => $recover_staged_mutation, 'mutation_cas_revision' => $prior_mutation_cas_revision ) ) );
		}
		$expected_before = (string) ( $input['expected_mutation_cas_revision'] ?? '' );
		$current_before = self::translation_job_rollback_cas_revision( $translation_id, $term_scope, $identity_scope );
		if ( '' === $expected_before || '' === $current_before || ! hash_equals( $expected_before, $current_before ) ) {
			$rollback = self::translation_job_rollback_recovery_transaction();
			self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
			return array_merge( array( 'success' => false, 'code' => 'publication_surface_changed_before_locked_transition', 'message' => 'The translation surface changed before the publication transaction acquired ownership.', 'published' => false, 'mutation_started' => ! empty( $input['recover_staged_mutation'] ), 'mutation_cas_revision' => $expected_before ), self::translation_job_rollback_response_fields( $rollback ) );
		}
		$transition = self::apply_translation_publish_transition( $translation_id, $language, $source_id, $term_scope );
		if ( empty( $transition['success'] ) ) {
			$rollback = self::translation_job_rollback_recovery_transaction();
			self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
			$transition['mutation_started'] = $recover_staged_mutation;
			$transition['mutation_cas_revision'] = $prior_mutation_cas_revision;
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
			return array_merge( array( 'success' => false, 'code' => 'publication_mutation_receipt_failed', 'message' => 'The publication transaction could not produce its exact recovery receipt.', 'published' => false, 'transition' => $transition, 'mutation_started' => $recover_staged_mutation, 'mutation_cas_revision' => $prior_mutation_cas_revision ), self::translation_job_rollback_response_fields( $rollback ) );
		}
		$commit = self::translation_job_commit_recovery_transaction();
		if ( empty( $commit['success'] ) ) {
			self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
			return array_merge( array( 'success' => false, 'code' => 'publication_transaction_commit_failed', 'message' => 'The localized presentation transaction could not be committed safely.', 'published' => false, 'transition' => $transition, 'mutation_started' => $recover_staged_mutation, 'mutation_cas_revision' => $prior_mutation_cas_revision, 'transaction_commit' => $commit ), self::translation_job_recovery_transaction_error_fields() );
		}
		self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
		} catch ( Throwable $error ) {
			$rollback = self::translation_job_rollback_recovery_transaction();
			self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
			return array_merge( array( 'success' => false, 'code' => 'publication_transaction_exception', 'message' => 'The localized presentation transaction stopped unexpectedly.', 'published' => false, 'mutation_started' => $recover_staged_mutation, 'mutation_cas_revision' => $prior_mutation_cas_revision ), self::translation_job_rollback_response_fields( $rollback ), self::translation_job_recovery_transaction_error_fields() );
			}

		$menu = null;
		if ( $post instanceof WP_Post && 'page' === $post->post_type && ! empty( $input['sync_menu'] ) ) {
			$menu = self::refresh_public_header_projection_for_publication( absint( $input['live_verification_timeout'] ?? 15 ) );
			if ( empty( $menu['success'] ) ) {
				return array(
					'success'                 => false,
					'code'                    => 'public_header_projection_publication_failed',
					'message'                 => 'Content was published, but the complete source-and-target Public Header Projection failed and retained or restored the prior reader surface.',
					'published'               => true,
					'transition'              => $transition,
					'menu'                    => $menu,
					'mutation_started'        => true,
					'mutation_cas_revision'   => $mutation_cas_revision,
				);
			}
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
			return self::publication_failure_with_public_header_rollback( array(
				'success'            => false,
				'code'               => 'frontend_cache_adapter_missing',
				'message'            => 'Content was published, but no Frontend Cache Adapter acknowledged invalidation.',
				'published'          => true,
				'transition'         => $transition,
				'menu'               => $menu,
				'purge_urls'         => $purge_urls,
				'cache_invalidation' => null,
				'mutation_cas_revision' => $mutation_cas_revision,
			), $menu, absint( $input['live_verification_timeout'] ?? 15 ) );
		}
		if ( true !== ( $invalidation['success'] ?? null ) ) {
			return self::publication_failure_with_public_header_rollback( array(
				'success'            => false,
				'code'               => sanitize_key( (string) ( $invalidation['code'] ?? 'frontend_cache_invalidation_failed' ) ),
				'message'            => 'Content was published, but frontend cache invalidation failed.',
				'published'          => true,
				'transition'         => $transition,
				'menu'               => $menu,
				'purge_urls'         => $purge_urls,
				'cache_invalidation' => $invalidation,
				'mutation_cas_revision' => $mutation_cas_revision,
			), $menu, absint( $input['live_verification_timeout'] ?? 15 ) );
		}

		// Publication is a fail-closed Module invariant. Callers cannot opt out of
		// verifying both the origin-bypassing and exact canonical cache surfaces.
		$live = self::verify_live_translation(
			array(
				'translation_id' => $translation_id,
				'timeout'        => absint( $input['live_verification_timeout'] ?? 15 ),
				'expected_media' => $expected_media,
			)
		);
		if ( empty( $live['success'] ) || empty( $live['passed'] ) ) {
			return self::publication_failure_with_public_header_rollback( array(
				'success'            => false,
				'code'               => 'localized_presentation_verification_failed',
				'message'            => 'Content was published and caches were invalidated, but the public presentation failed verification.',
				'published'          => true,
				'transition'         => $transition,
				'menu'               => $menu,
				'purge_urls'         => $purge_urls,
				'cache_invalidation' => $invalidation,
				'live_verification'  => $live,
				'mutation_cas_revision' => $mutation_cas_revision,
			), $menu, absint( $input['live_verification_timeout'] ?? 15 ) );
		}

		return array(
			'success'            => true,
			'published'          => true,
			'transition'         => $transition,
			'menu'               => $menu,
			'purge_urls'         => $purge_urls,
			'cache_invalidation' => $invalidation,
			'live_verification'  => $live,
			'mutation_cas_revision' => $mutation_cas_revision,
			'transaction_commit' => $commit,
		);
	}

	/** Restore the previous active menu identity and remove an uncommitted projection. */
	private static function rollback_localized_menu_projection( string $language, array $menu ): array {
		$target_id = absint( $menu['target_menu']['id'] ?? 0 );
		if ( ! $target_id ) { return array( 'success' => true, 'action' => 'not_required' ); }
		if ( ! self::translation_job_begin_recovery_transaction() ) { return array_merge( array( 'success' => false, 'action' => 'menu_rollback_conflict', 'error' => 'menu_recovery_transaction_unavailable' ), self::translation_job_recovery_transaction_error_fields() ); }
		try {
		$locked = self::lock_localized_menu_projection_surface( $target_id );
		if ( empty( $locked['success'] ) ) { return self::translation_job_failure_after_recovery_rollback( $locked ); }
		$previous_id = absint( $menu['previous_menu_id'] ?? 0 );
		if ( $previous_id ) {
			$previous_locked = self::lock_localized_menu_projection_surface( $previous_id );
			if ( empty( $previous_locked['success'] ) ) { return self::translation_job_failure_after_recovery_rollback( $previous_locked ); }
		}
		clean_term_cache( array( $target_id ) );
		$result = self::rollback_localized_menu_projection_uncommitted( $language, $menu );
		if ( empty( $result['success'] ) ) {
			$rollback = self::translation_job_rollback_recovery_transaction();
			clean_term_cache( array( $target_id ) );
			return array_merge( $result, self::translation_job_rollback_response_fields( $rollback ) );
		}
		$commit = self::translation_job_commit_recovery_transaction();
		if ( empty( $commit['success'] ) ) {
			clean_term_cache( array( $target_id ) );
			return array_merge( array( 'success' => false, 'action' => 'menu_rollback_conflict', 'error' => 'menu_recovery_transaction_commit_failed', 'transaction_commit' => $commit ), self::translation_job_recovery_transaction_error_fields() );
		}
		clean_term_cache( array( $target_id ) );
		$result['transaction_committed'] = true;
		$result['transaction_commit'] = $commit;
		return $result;
		} catch ( Throwable $error ) {
			$rollback = self::translation_job_rollback_recovery_transaction();
			clean_term_cache( array( $target_id ) );
			return array_merge( array( 'success' => false, 'action' => 'menu_rollback_conflict', 'error' => 'menu_recovery_transaction_exception', 'message' => 'The menu recovery transaction stopped unexpectedly.' ), self::translation_job_rollback_response_fields( $rollback ), self::translation_job_recovery_transaction_error_fields() );
		}
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
				return array( 'success' => false, 'action' => 'menu_rollback_conflict', 'error' => 'menu_recovery_surface_lock_failed' );
			}
		}
		clean_term_cache( array( $menu_id ) );
		return array( 'success' => true );
	}

	/** Restore the exact prior identity and delete the target inside the menu transaction. */
	private static function localized_menu_projection_rollback_preflight( string $language, array $menu ): array {
		$target_id = absint( $menu['target_menu']['id'] ?? 0 );
		$previous_id = absint( $menu['previous_menu_id'] ?? 0 );
		if ( ! $target_id ) { return array( 'success' => true, 'action' => 'not_required' ); }
		if ( '1' !== (string) get_term_meta( $target_id, self::TERM_META_MENU_MANAGED, true ) || sanitize_key( (string) get_term_meta( $target_id, self::TERM_META_MENU_LANGUAGE, true ) ) !== sanitize_key( $language ) ) {
			return array( 'success' => false, 'action' => 'menu_rollback_conflict', 'error' => 'target_menu_ownership_mismatch' );
		}
		$expected_manifest_revision = (string) ( $menu['manifest_revision'] ?? '' );
		$current_manifest_revision = (string) get_term_meta( $target_id, self::TERM_META_PUBLIC_HEADER_MANIFEST_REVISION, true );
		if ( '' === $expected_manifest_revision || ! hash_equals( $expected_manifest_revision, $current_manifest_revision ) ) {
			return array( 'success' => false, 'action' => 'menu_rollback_conflict', 'error' => 'target_menu_manifest_revision_mismatch' );
		}
		$expected_menu_revision = (string) ( $menu['menu_surface_revision'] ?? '' );
		$current_menu_revision = self::localized_menu_projection_revision( $target_id );
		if ( '' === $expected_menu_revision || '' === $current_menu_revision || ! hash_equals( $expected_menu_revision, $current_menu_revision ) ) {
			return array( 'success' => false, 'action' => 'menu_rollback_conflict', 'error' => 'target_menu_changed_after_projection' );
		}
		$activation = (array) ( $menu['menu_identity_activation'] ?? array() );
		$before_exists = ! empty( $activation['before_exists'] );
		$before = $activation['before'] ?? array();
		$after = $activation['after'] ?? array();
		$current = get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, '__devenia_workflow_option_missing__' );
		if ( self::translation_job_canonicalize( $current ) !== self::translation_job_canonicalize( $after ) || $target_id !== absint( is_array( $current ) ? ( $current[ $language ]['menu_id'] ?? 0 ) : 0 ) ) {
			return array( 'success' => false, 'action' => 'menu_rollback_conflict', 'error' => 'active_menu_changed_after_projection' );
		}
		if ( $previous_id ) {
			$previous = wp_get_nav_menu_object( $previous_id );
			if ( ! $previous ) { return array( 'success' => false, 'action' => 'menu_rollback_conflict', 'error' => 'previous_menu_missing' ); }
			$expected_previous_revision = (string) ( $menu['previous_menu_surface_revision'] ?? '' );
			$current_previous_revision = self::localized_menu_projection_revision( $previous_id );
			if ( '' === $expected_previous_revision || '' === $current_previous_revision || ! hash_equals( $expected_previous_revision, $current_previous_revision ) ) {
				return array( 'success' => false, 'action' => 'menu_rollback_conflict', 'error' => 'previous_menu_changed_after_projection' );
			}
		}
		return array( 'success' => true, 'target_id' => $target_id, 'previous_id' => $previous_id, 'before_exists' => $before_exists, 'before' => $before, 'after' => $after );
	}

	/** Restore the exact prior identity and delete the target inside the menu transaction. */
	private static function rollback_localized_menu_projection_uncommitted( string $language, array $menu ): array {
		$preflight = self::localized_menu_projection_rollback_preflight( $language, $menu );
		if ( empty( $preflight['success'] ) ) { return $preflight; }
		$target_id = absint( $preflight['target_id'] ?? 0 );
		$previous_id = absint( $preflight['previous_id'] ?? 0 );
		if ( ! $target_id ) { return array( 'success' => true, 'action' => 'not_required' ); }
		$before_exists = ! empty( $preflight['before_exists'] );
		$before = $preflight['before'] ?? array();
		$after = $preflight['after'] ?? array();
		$restored = $before_exists
			? self::atomic_replace_option_value( self::OPTION_LOCALIZED_MENU_IDENTITIES, $after, $before )
			: self::atomic_delete_option_value( self::OPTION_LOCALIZED_MENU_IDENTITIES, $after );
		if ( ! $restored ) { return array( 'success' => false, 'action' => 'menu_rollback_conflict', 'error' => 'previous_menu_identity_restore_cas_failed' ); }
		$stored = get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, '__devenia_workflow_option_missing__' );
		$expected_stored = $before_exists ? $before : '__devenia_workflow_option_missing__';
		if ( self::translation_job_canonicalize( $stored ) !== self::translation_job_canonicalize( $expected_stored ) ) { return array( 'success' => false, 'action' => 'menu_rollback_failed', 'error' => 'previous_menu_identity_restore_failed' ); }
		$deleted = wp_delete_nav_menu( $target_id );
		if ( is_wp_error( $deleted ) || false === $deleted ) { return array( 'success' => false, 'action' => 'menu_rollback_failed', 'error' => 'target_menu_delete_failed' ); }
		return array( 'success' => true, 'action' => 'restore_previous_menu', 'previous_menu_id' => $previous_id, 'deleted_target_menu_id' => $target_id );
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

		$normalized = self::normalize_public_header_manifest_items( $input['items'] ?? array() );
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
			'schema_version'  => 1,
			'source_language' => self::source_language_code(),
			'revision'        => $revision,
			'items'           => $items,
			'updated_at'      => gmdate( 'c' ),
		);
		$missing = '__devenia_workflow_option_missing__';
		$before  = get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $missing );
		$existing_pending = self::normalize_public_header_manifest( $before );
		if ( '' !== (string) ( $existing_pending['revision'] ?? '' ) && hash_equals( (string) $existing_pending['revision'], $revision ) ) {
			return array( 'success' => true, 'source_language' => self::source_language_code(), 'revision' => $revision, 'item_count' => count( $items ), 'pending' => true, 'unchanged' => true, 'message' => 'The pending Public Header Projection manifest is already current.' );
		}
		$written = $missing === $before
			? self::atomic_create_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $manifest )
			: self::atomic_replace_option_value( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $before, $manifest );
		$stored = get_option( self::OPTION_PENDING_PUBLIC_HEADER_MANIFEST, $missing );
		if ( ! $written || self::translation_job_canonicalize( $stored ) !== self::translation_job_canonicalize( $manifest ) ) {
			return array( 'success' => false, 'code' => 'public_header_pending_manifest_write_failed', 'message' => 'The pending Public Header Projection manifest could not be stored.' );
		}

		return array(
			'success'         => true,
			'source_language' => self::source_language_code(),
			'revision'        => $revision,
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
		if ( ! is_array( $manifest ) || 1 !== absint( $manifest['schema_version'] ?? 0 ) || self::source_language_code() !== sanitize_key( (string) ( $manifest['source_language'] ?? '' ) ) ) {
			return array();
		}
		$normalized = self::normalize_public_header_manifest_items( $manifest['items'] ?? array() );
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
	private static function normalize_public_header_manifest_items( $raw_items ): array {
		if ( ! is_array( $raw_items ) || empty( $raw_items ) ) {
			return array( 'success' => false, 'code' => 'public_header_manifest_empty', 'message' => 'The Public Header Projection manifest must contain at least one item.' );
		}

		$items = array();
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
	private static function public_header_projection_plan( string $language, bool $include_untranslated = false, bool $include_custom = true, array $manifest = array() ): array {
		$language = sanitize_key( $language );
		$languages = self::languages();
		if ( '' === $language || ! isset( $languages[ $language ] ) ) {
			return array( 'success' => false, 'code' => 'public_header_language_unknown', 'message' => 'Public Header Projection language is not configured.' );
		}
		$manifest = $manifest ?: self::public_header_manifest();
		if ( empty( $manifest ) ) {
			return array( 'success' => false, 'code' => 'public_header_manifest_missing', 'message' => 'No valid Public Header Projection manifest is registered.' );
		}
		$is_source = self::source_language_code() === $language;
		$rows      = array();
		$skipped   = array();
		foreach ( (array) $manifest['items'] as $item ) {
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
				$object_id = $is_source ? absint( $source_item->object_id ) : self::find_translation_id( absint( $source_item->object_id ), $language, array( 'publish' ) );
				if ( ! $object_id && $include_untranslated ) {
					$object_id = absint( $source_item->object_id );
				}
				if ( ! $object_id ) {
					$skipped[] = array( 'source_item_id' => (int) $source_item->ID, 'source_page_id' => (int) $source_item->object_id, 'title' => (string) $source_item->title, 'reason' => 'missing_published_translation' );
					continue;
				}
				$fallback = $is_source ? (string) $source_item->title : (string) get_the_title( $object_id );
				$args = array(
					'menu-item-title'     => self::localized_menu_item_title( $source_item, $language, $fallback ),
					'menu-item-object'    => 'page',
					'menu-item-object-id' => $object_id,
					'menu-item-type'      => 'post_type',
					'menu-item-status'    => 'publish',
					'menu-item-parent-id' => 0,
				);
			} elseif ( $include_custom ) {
				$url = (string) $source_item->url;
				if ( ! $is_source ) {
					$localized_url = self::localized_internal_link_target( $url, self::localized_internal_link_map( $language ) );
					$url = $localized_url ?: $url;
				}
				$args = array(
					'menu-item-title'     => self::localized_menu_item_title( $source_item, $language, (string) $source_item->title ),
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

		return array( 'success' => true, 'language' => $language, 'manifest_revision' => (string) $manifest['revision'], 'rows' => $rows, 'skipped' => array() );
	}

	/** Return the public blog archive URL from WordPress route data. */
	private static function public_blog_archive_url_for_language( string $language ): string {
		$language = sanitize_key( $language );
		$path     = self::localized_blog_base_path( $language );
		return '' !== $path ? trailingslashit( home_url( '/' . trim( $path, '/' ) . '/' ) ) : '';
	}

	/**
	 * Resolve the authoritative menu term ID, migrating deterministic name-based state once.
	 */
	private static function localized_menu_id( string $language, bool $migrate = true ): int {
		$language  = sanitize_key( $language );
		$identities = get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, array() );
		$identities = is_array( $identities ) ? $identities : array();
		$stored_id  = absint( $identities[ $language ]['menu_id'] ?? 0 );
		$languages  = self::languages();
		$config     = isset( $languages[ $language ] ) && is_array( $languages[ $language ] ) ? $languages[ $language ] : array();
		$active_manifest = self::public_header_manifest();
		$active_revision = (string) ( $active_manifest['revision'] ?? '' );
		if ( $stored_id > 0 && '' !== $active_revision && wp_get_nav_menu_object( $stored_id ) && '1' === (string) get_term_meta( $stored_id, self::TERM_META_MENU_MANAGED, true ) && '' === (string) get_term_meta( $stored_id, self::TERM_META_PUBLIC_HEADER_RETIRED, true ) && $language === sanitize_key( (string) get_term_meta( $stored_id, self::TERM_META_MENU_LANGUAGE, true ) ) && hash_equals( $active_revision, (string) get_term_meta( $stored_id, self::TERM_META_PUBLIC_HEADER_MANIFEST_REVISION, true ) ) ) {
			return $stored_id;
		}
		if ( ! $migrate ) {
			return 0;
		}

		$name = sanitize_text_field( (string) ( $config['menu_name'] ?? '' ) );
		if ( '' === $name ) {
			return 0;
		}
		$matches = array_values(
			array_filter(
				wp_get_nav_menus(),
				static function ( $menu ) use ( $name, $language, $active_revision ): bool {
					return is_object( $menu ) && '' !== $active_revision && $name === (string) $menu->name && '1' === (string) get_term_meta( (int) $menu->term_id, self::TERM_META_MENU_MANAGED, true ) && '' === (string) get_term_meta( (int) $menu->term_id, self::TERM_META_PUBLIC_HEADER_RETIRED, true ) && $language === sanitize_key( (string) get_term_meta( (int) $menu->term_id, self::TERM_META_MENU_LANGUAGE, true ) ) && hash_equals( $active_revision, (string) get_term_meta( (int) $menu->term_id, self::TERM_META_PUBLIC_HEADER_MANIFEST_REVISION, true ) );
				}
			)
		);
		usort(
			$matches,
			static function ( $left, $right ): int {
				return (int) $left->term_id <=> (int) $right->term_id;
			}
		);
		if ( empty( $matches ) ) {
			return 0;
		}

		$selected_id = (int) $matches[0]->term_id;
		$identities[ $language ] = array(
			'menu_id'         => $selected_id,
			'configured_name' => $name,
			'migrated_at'     => gmdate( 'c' ),
			'duplicate_ids'   => array_values(
				array_map(
					static function ( $menu ): int {
						return (int) $menu->term_id;
					},
					$matches
				)
			),
		);
		update_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, $identities, false );

		return $selected_id;
	}

	/**
	 * Persist one validated menu projection as the active identity.
	 */
	private static function activate_localized_menu_id( string $language, int $menu_id, string $configured_name, int $previous_id ): array {
		$active_manifest = self::public_header_manifest();
		$active_revision = (string) ( $active_manifest['revision'] ?? '' );
		if ( '' === $active_revision || '1' !== (string) get_term_meta( $menu_id, self::TERM_META_MENU_MANAGED, true ) || sanitize_key( $language ) !== sanitize_key( (string) get_term_meta( $menu_id, self::TERM_META_MENU_LANGUAGE, true ) ) || ! hash_equals( $active_revision, (string) get_term_meta( $menu_id, self::TERM_META_PUBLIC_HEADER_MANIFEST_REVISION, true ) ) ) {
			return array( 'success' => false, 'code' => 'menu_projection_manifest_revision_mismatch' );
		}
		$missing = '__devenia_workflow_option_missing__';
		$before_raw = get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, $missing );
		$before_exists = $missing !== $before_raw;
		$identities = is_array( $before_raw ) ? $before_raw : array();
		$identities[ $language ] = array(
			'menu_id'          => $menu_id,
			'configured_name'  => $configured_name,
			'previous_menu_id' => $previous_id,
			'activated_at'     => gmdate( 'c' ),
		);
		$written = $before_exists
			? self::atomic_replace_option_value( self::OPTION_LOCALIZED_MENU_IDENTITIES, $before_raw, $identities )
			: self::atomic_create_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, $identities, false );
		$stored = get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, $missing );
		$activated = $written && self::translation_job_canonicalize( $stored ) === self::translation_job_canonicalize( $identities );
		if ( ! $activated && $written ) {
			if ( $before_exists ) {
				self::atomic_replace_option_value( self::OPTION_LOCALIZED_MENU_IDENTITIES, $identities, $before_raw );
			} else {
				self::atomic_delete_option_value( self::OPTION_LOCALIZED_MENU_IDENTITIES, $identities );
			}
		}
		return array( 'success' => $activated, 'before_exists' => $before_exists, 'before' => $before_raw, 'after' => $identities );
	}

	/**
	 * Retire only a projection explicitly created and marked by Workflow.
	 */
	private static function retire_managed_localized_menu( int $menu_id, int $active_id, string $expected_menu_revision = '' ): bool {
		if ( $menu_id < 1 || $menu_id === $active_id ) { return false; }
		$language = sanitize_key( (string) get_term_meta( $active_id, self::TERM_META_MENU_LANGUAGE, true ) );
		if ( '' === $language || ! self::translation_job_begin_recovery_transaction() ) { return false; }
		try {
			global $wpdb;
			$menu_lock = self::lock_localized_menu_projection_surface( $menu_id );
			if ( empty( $menu_lock['success'] ) ) { self::translation_job_rollback_recovery_transaction(); return false; }
			$queries = array(
				$wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE", self::OPTION_LOCALIZED_MENU_IDENTITIES ),
				$wpdb->prepare( "SELECT term_id FROM {$wpdb->terms} WHERE term_id IN (%d, %d) FOR UPDATE", $menu_id, $active_id ),
				$wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id IN (%d, %d) FOR UPDATE", $menu_id, $active_id ),
				$wpdb->prepare( "SELECT meta_id FROM {$wpdb->termmeta} WHERE term_id IN (%d, %d) FOR UPDATE", $menu_id, $active_id ),
				$wpdb->prepare( "SELECT tr.object_id, tr.term_taxonomy_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.term_id IN (%d, %d) FOR UPDATE", $menu_id, $active_id ),
			);
			foreach ( $queries as $query ) {
				if ( false === $wpdb->query( $query ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared row locks prevent deleting a menu which became active concurrently.
					self::translation_job_rollback_recovery_transaction();
					return false;
				}
			}
			clean_term_cache( array( $menu_id, $active_id ) );
			$identities = get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, array() );
			$current_menu_revision = self::localized_menu_projection_revision( $menu_id );
			if ( ! is_array( $identities ) || $active_id !== absint( $identities[ $language ]['menu_id'] ?? 0 ) || '1' !== (string) get_term_meta( $menu_id, self::TERM_META_MENU_MANAGED, true ) || '' === $expected_menu_revision || '' === $current_menu_revision || ! hash_equals( $expected_menu_revision, $current_menu_revision ) ) {
				self::translation_job_rollback_recovery_transaction();
				return false;
			}
			$result = wp_delete_nav_menu( $menu_id );
			if ( is_wp_error( $result ) || false === $result ) {
				self::translation_job_rollback_recovery_transaction();
				clean_term_cache( array( $menu_id, $active_id ) );
				return false;
			}
			$commit = self::translation_job_commit_recovery_transaction();
			if ( empty( $commit['success'] ) ) {
				clean_term_cache( array( $menu_id, $active_id ) );
				return false;
			}
			clean_term_cache( array( $menu_id, $active_id ) );
			return true;
		} catch ( Throwable $error ) {
			self::translation_job_rollback_recovery_transaction();
			clean_term_cache( array( $menu_id, $active_id ) );
			return false;
		}
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
	private static function frontend_public_surface_integrity_for_url( string $url, string $language, int $timeout = 15, string $surface = 'public', array $expected_media = array() ): array {
		$url       = esc_url_raw( $url );
		$language  = sanitize_key( $language );
		$issues    = array();
		$warnings  = array();
		$responses = array();

		if ( '' === $url ) {
			$issues[] = self::qa_item( 'frontend_url_missing', 'Frontend URL is missing.', array( 'language' => $language, 'surface' => $surface ) );
		} else {
			foreach ( array( 'origin', 'canonical' ) as $cache_surface ) {
				$response = self::fetch_frontend_cache_surface( $url, $timeout, $cache_surface );
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
				$issues = array_merge( $issues, self::localized_primary_navigation_html_issues( $body, $language, $url, $cache_surface ) );
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
	 * Fetch one explicit cache surface and expose cache response evidence.
	 *
	 * @return array<string,mixed>
	 */
	private static function fetch_frontend_cache_surface( string $url, int $timeout, string $surface ): array {
		$canonical = 'canonical' === $surface;
		$fetch_url = $canonical ? $url : add_query_arg( 'devenia_frontend_integrity', wp_generate_uuid4(), $url );
		$args      = array(
			'timeout'     => max( 3, min( 30, $timeout ) ),
			'redirection' => 3,
		);
		if ( ! $canonical ) {
			$args['headers'] = array( 'Cache-Control' => 'no-cache, no-store, max-age=0' );
		}
		$response = wp_remote_get( $fetch_url, $args );
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
	 * Verify that rendered primary navigation is the authoritative localized menu.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function localized_primary_navigation_html_issues( string $html, string $language, string $url, string $cache_surface ): array {
		$expected = self::expected_localized_primary_navigation( $language );
		if ( empty( $expected ) ) {
			return array( self::qa_item( 'frontend_primary_menu_identity_missing', 'No authoritative localized primary-menu identity is configured.', array( 'language' => $language, 'url' => $url, 'cache_surface' => $cache_surface ) ) );
		}

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
						"//nav[@id='site-navigation']//a[@href and not(ancestor::*[contains(concat(' ', normalize-space(@class), ' '), ' devenia-language-menu-dropdown ')])]",
						"//nav[contains(concat(' ', normalize-space(@class), ' '), ' main-navigation ')]//a[@href and not(ancestor::*[contains(concat(' ', normalize-space(@class), ' '), ' devenia-language-menu-dropdown ')])]",
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
}
