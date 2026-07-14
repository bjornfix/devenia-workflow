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

		$result = self::frontend_public_surface_integrity_for_url( $url, $language, absint( $input['timeout'] ?? 15 ), 'translation' );
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
		$term_scope     = (array) ( $input['rollback_term_scope'] ?? array() );
		$identity_scope = (array) ( $input['rollback_identity_scope'] ?? array() );
		$recover_staged_mutation = ! empty( $input['recover_staged_mutation'] );
		$prior_mutation_cas_revision = (string) ( $input['expected_mutation_cas_revision'] ?? '' );
		if ( ! self::translation_job_begin_recovery_transaction() ) {
			return array( 'success' => false, 'code' => 'publication_transaction_unavailable', 'message' => 'The localized presentation transaction could not be started.', 'published' => false, 'mutation_started' => $recover_staged_mutation, 'mutation_cas_revision' => $prior_mutation_cas_revision );
		}
		try {
		$locked = self::translation_job_lock_recovery_surface( $translation_id, $term_scope, $identity_scope );
		if ( empty( $locked['success'] ) ) {
			self::translation_job_rollback_recovery_transaction();
			return array_merge( $locked, array( 'published' => false, 'mutation_started' => $recover_staged_mutation, 'mutation_cas_revision' => $prior_mutation_cas_revision ) );
		}
		$expected_before = (string) ( $input['expected_mutation_cas_revision'] ?? '' );
		$current_before = self::translation_job_rollback_cas_revision( $translation_id, $term_scope, $identity_scope );
		if ( '' === $expected_before || '' === $current_before || ! hash_equals( $expected_before, $current_before ) ) {
			self::translation_job_rollback_recovery_transaction();
			self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
			return array( 'success' => false, 'code' => 'publication_surface_changed_before_locked_transition', 'message' => 'The translation surface changed before the publication transaction acquired ownership.', 'published' => false, 'mutation_started' => ! empty( $input['recover_staged_mutation'] ), 'mutation_cas_revision' => $expected_before );
		}
		$transition = self::apply_translation_publish_transition( $translation_id, $language, $source_id, $term_scope );
		if ( empty( $transition['success'] ) ) {
			self::translation_job_rollback_recovery_transaction();
			self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
			$transition['mutation_started'] = $recover_staged_mutation;
			$transition['mutation_cas_revision'] = $prior_mutation_cas_revision;
			$transition['transaction_rolled_back'] = true;
			return $transition;
		}
		$menu = null;
		$post = get_post( $translation_id );
		if ( $post instanceof WP_Post && 'page' === $post->post_type && ! empty( $input['sync_menu'] ) ) {
			$menu = self::sync_language_menu(
				array(
					'language'             => $language,
					'include_untranslated' => false,
					'include_custom_links' => ! array_key_exists( 'include_custom_links', $input ) || ! empty( $input['include_custom_links'] ),
					'retire_previous'      => false,
				)
			);
			if ( empty( $menu['success'] ) ) {
				self::translation_job_rollback_recovery_transaction();
				self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
				return array(
					'success'     => false,
					'code'        => 'localized_menu_projection_failed',
					'message'     => 'The localized menu projection failed and the publication transaction was rolled back.',
					'published'   => false,
					'transition'  => $transition,
					'menu'        => $menu,
					'mutation_started' => $recover_staged_mutation,
					'mutation_cas_revision' => $prior_mutation_cas_revision,
					'transaction_rolled_back' => true,
				);
			}
			$menu_surface_revision = self::localized_menu_projection_revision( absint( $menu['target_menu']['id'] ?? 0 ) );
			if ( '' === $menu_surface_revision ) {
				self::translation_job_rollback_recovery_transaction();
				self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
				return array( 'success' => false, 'code' => 'menu_projection_receipt_failed', 'message' => 'The localized menu projection could not produce an exact recovery receipt.', 'published' => false, 'mutation_started' => $recover_staged_mutation, 'mutation_cas_revision' => $prior_mutation_cas_revision, 'transaction_rolled_back' => true );
			}
			$menu['menu_surface_revision'] = $menu_surface_revision;
		}
		// The receipt is captured while editor/meta/taxonomy rows are still
		// locked. Concurrent writes can only proceed after commit and will then
		// differ from this receipt before any rollback begins.
		$mutation_cas_revision = self::translation_job_rollback_cas_revision( $translation_id, $term_scope, $identity_scope );
		if ( '' === $mutation_cas_revision ) {
			self::translation_job_rollback_recovery_transaction();
			self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
			return array( 'success' => false, 'code' => 'publication_mutation_receipt_failed', 'message' => 'The publication transaction could not produce its exact recovery receipt.', 'published' => false, 'transition' => $transition, 'mutation_started' => $recover_staged_mutation, 'mutation_cas_revision' => $prior_mutation_cas_revision, 'transaction_rolled_back' => true );
		}
		if ( ! self::translation_job_commit_recovery_transaction() ) {
			self::translation_job_rollback_recovery_transaction();
			self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
			return array( 'success' => false, 'code' => 'publication_transaction_commit_failed', 'message' => 'The localized presentation transaction could not be committed safely.', 'published' => false, 'transition' => $transition, 'mutation_started' => $recover_staged_mutation, 'mutation_cas_revision' => $prior_mutation_cas_revision );
		}
		self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
		} catch ( Throwable $error ) {
			self::translation_job_rollback_recovery_transaction();
			self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
			return array( 'success' => false, 'code' => 'publication_transaction_exception', 'message' => $error->getMessage(), 'published' => false, 'mutation_started' => $recover_staged_mutation, 'mutation_cas_revision' => $prior_mutation_cas_revision );
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
				'menu'               => $menu,
				'purge_urls'         => $purge_urls,
				'cache_invalidation' => null,
				'mutation_cas_revision' => $mutation_cas_revision,
				'menu_recovery_plan' => $menu,
			);
		}
		if ( true !== ( $invalidation['success'] ?? null ) ) {
			return array(
				'success'            => false,
				'code'               => sanitize_key( (string) ( $invalidation['code'] ?? 'frontend_cache_invalidation_failed' ) ),
				'message'            => 'Content was published, but frontend cache invalidation failed.',
				'published'          => true,
				'transition'         => $transition,
				'menu'               => $menu,
				'purge_urls'         => $purge_urls,
				'cache_invalidation' => $invalidation,
				'mutation_cas_revision' => $mutation_cas_revision,
				'menu_recovery_plan' => $menu,
			);
		}

		// Publication is a fail-closed Module invariant. Callers cannot opt out of
		// verifying both the origin-bypassing and exact canonical cache surfaces.
		$live = self::verify_live_translation(
			array(
				'translation_id' => $translation_id,
				'timeout'        => absint( $input['live_verification_timeout'] ?? 15 ),
			)
		);
		if ( empty( $live['success'] ) || empty( $live['passed'] ) ) {
			return array(
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
				'menu_recovery_plan' => $menu,
			);
		}

		if ( is_array( $menu ) ) {
			$previous_menu_id = absint( $menu['previous_menu_id'] ?? 0 );
			$target_menu_id = absint( $menu['target_menu']['id'] ?? 0 );
			$menu['retired_previous'] = self::retire_managed_localized_menu( $previous_menu_id, $target_menu_id, (string) ( $menu['previous_menu_surface_revision'] ?? '' ) );
			$final_identities = get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, array() );
			$final_active_id = is_array( $final_identities ) ? absint( $final_identities[ $language ]['menu_id'] ?? 0 ) : 0;
			if ( $final_active_id !== $target_menu_id ) {
				return array( 'success' => false, 'code' => 'localized_menu_identity_changed_after_verification', 'message' => 'The active localized menu identity changed after public verification; the competing identity was preserved.', 'published' => true, 'transition' => $transition, 'menu' => $menu, 'purge_urls' => $purge_urls, 'cache_invalidation' => $invalidation, 'live_verification' => $live, 'mutation_started' => true, 'mutation_cas_revision' => $mutation_cas_revision, 'menu_recovery_plan' => $menu );
			}
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
		);
	}

	/** Restore the previous active menu identity and remove an uncommitted projection. */
	private static function rollback_localized_menu_projection( string $language, array $menu ): array {
		$target_id = absint( $menu['target_menu']['id'] ?? 0 );
		if ( ! $target_id ) { return array( 'success' => true, 'action' => 'not_required' ); }
		if ( ! self::translation_job_begin_recovery_transaction() ) { return array( 'success' => false, 'action' => 'menu_rollback_conflict', 'error' => 'menu_recovery_transaction_unavailable' ); }
		try {
		$locked = self::lock_localized_menu_projection_surface( $target_id );
		if ( empty( $locked['success'] ) ) { self::translation_job_rollback_recovery_transaction(); return $locked; }
		$previous_id = absint( $menu['previous_menu_id'] ?? 0 );
		if ( $previous_id ) {
			$previous_locked = self::lock_localized_menu_projection_surface( $previous_id );
			if ( empty( $previous_locked['success'] ) ) { self::translation_job_rollback_recovery_transaction(); return $previous_locked; }
		}
		clean_term_cache( array( $target_id ) );
		$result = self::rollback_localized_menu_projection_uncommitted( $language, $menu );
		if ( empty( $result['success'] ) ) {
			self::translation_job_rollback_recovery_transaction();
			clean_term_cache( array( $target_id ) );
			$result['transaction_rolled_back'] = true;
			return $result;
		}
		if ( ! self::translation_job_commit_recovery_transaction() ) {
			self::translation_job_rollback_recovery_transaction();
			clean_term_cache( array( $target_id ) );
			return array( 'success' => false, 'action' => 'menu_rollback_conflict', 'error' => 'menu_recovery_transaction_commit_failed' );
		}
		clean_term_cache( array( $target_id ) );
		$result['transaction_committed'] = true;
		return $result;
		} catch ( Throwable $error ) {
			self::translation_job_rollback_recovery_transaction();
			clean_term_cache( array( $target_id ) );
			return array( 'success' => false, 'action' => 'menu_rollback_conflict', 'error' => 'menu_recovery_transaction_exception', 'message' => $error->getMessage() );
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
		$urls = array_merge( $transition_urls, array( self::localized_home_url_for_language( $language ) ) );
		$urls = apply_filters( 'devenia_workflow_localized_presentation_purge_urls', $urls, $language );
		if ( ! is_array( $urls ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
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
		$is_source  = ! empty( $config['source'] );
		$locations  = get_nav_menu_locations();
		$primary_id = absint( $locations['primary'] ?? 0 );
		if ( $stored_id > 0 && wp_get_nav_menu_object( $stored_id ) && ( ! $is_source || $primary_id < 1 || $stored_id === $primary_id ) ) {
			return $stored_id;
		}
		if ( ! $migrate ) {
			return 0;
		}

		$name = sanitize_text_field( (string) ( $config['menu_name'] ?? '' ) );
		if ( $is_source && $primary_id > 0 && wp_get_nav_menu_object( $primary_id ) ) {
			$identities[ $language ] = array(
				'menu_id'         => $primary_id,
				'configured_name' => $name,
				'resolved_from'   => 'primary_theme_location',
				'migrated_at'     => gmdate( 'c' ),
			);
			update_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, $identities, false );

			return $primary_id;
		}
		if ( '' === $name ) {
			return 0;
		}
		$matches = array_values(
			array_filter(
				wp_get_nav_menus(),
				static function ( $menu ) use ( $name ): bool {
					return is_object( $menu ) && $name === (string) $menu->name;
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
		foreach ( $matches as $match ) {
			if ( $primary_id > 0 && $primary_id === (int) $match->term_id ) {
				$selected_id = $primary_id;
				break;
			}
		}

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
			if ( is_wp_error( $result ) || false === $result || ! self::translation_job_commit_recovery_transaction() ) {
				self::translation_job_rollback_recovery_transaction();
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
		$menu_id = self::localized_menu_id( $language );
		if ( $menu_id < 1 ) {
			return array();
		}
		$expected = array();
		$items = wp_get_nav_menu_items( $menu_id, array( 'orderby' => 'menu_order' ) ) ?: array();
		foreach ( self::localized_menu_items_in_render_order( $items ) as $item ) {
			$expected[] = array(
				'title' => self::effective_localized_menu_item_title( $item, $language ),
				'url'   => self::normalize_primary_navigation_url( (string) $item->url ),
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
	private static function frontend_public_surface_integrity_for_url( string $url, string $language, int $timeout = 15, string $surface = 'public' ): array {
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
					array( "//nav[@id='site-navigation']//a[@href]", "//nav[contains(concat(' ', normalize-space(@class), ' '), ' main-navigation ')]//a[@href]" ),
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

		$expected_offset = -1;
		$expected_count  = count( $expected );
		for ( $offset = 0; $offset <= count( $actual ) - $expected_count; $offset++ ) {
			if ( array_slice( $actual, $offset, $expected_count ) === $expected ) {
				$expected_offset = $offset;
				break;
			}
		}
		if ( $expected_offset >= 0 ) {
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
