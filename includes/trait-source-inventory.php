<?php
/**
 * Authoritative public source inventory and translation obligation projection.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Devenia_AI_Translations_Source_Inventory {
	private static function source_inventory_table(): string { global $wpdb; return $wpdb->prefix . 'devenia_translation_sources'; }
	private static function translation_obligations_table(): string { global $wpdb; return $wpdb->prefix . 'devenia_translation_obligations'; }

	private static function source_inventory_ability_catalogue(): array {
		$cursor_schema = array(
			'type' => 'object',
			'properties' => array(
				'cursor' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100 ),
			),
			'additionalProperties' => false,
		);
		return array(
			'ai-translations/rebuild-source-inventory' => array(
				'label' => 'Rebuild Authoritative Source Inventory',
				'description' => 'Builds and atomically activates a complete generation of publicly visible source pages/posts and every target-language obligation.',
				'input_schema' => array( 'type' => 'object', 'required' => array( 'confirm_rebuild' ), 'properties' => array( 'confirm_rebuild' => array( 'type' => 'boolean', 'enum' => array( true ) ) ), 'additionalProperties' => false ),
				'output_schema' => self::generic_output_schema(),
				'execute_callback' => function ( $input = array() ) { return self::run_ability_operation( 'rebuild_source_inventory', $input ); },
				'meta' => self::ability_meta( false, false, true ),
			),
			'ai-translations/source-inventory' => array(
				'label' => 'Read Authoritative Source Inventory',
				'description' => 'Reads the active inventory generation with a stable source-ID cursor, including structured exclusions.',
				'input_schema' => $cursor_schema,
				'output_schema' => self::generic_output_schema(),
				'execute_callback' => function ( $input = array() ) { return self::run_ability_operation( 'source_inventory', $input ); },
				'meta' => self::ability_meta( true, false, true ),
			),
			'ai-translations/translation-obligation-queue' => array(
				'label' => 'Read Complete Translation Obligation Queue',
				'description' => 'Reads unresolved obligations from the active whole-site projection with a stable obligation cursor.',
				'input_schema' => $cursor_schema,
				'output_schema' => self::generic_output_schema(),
				'execute_callback' => function ( $input = array() ) { return self::run_ability_operation( 'translation_obligation_queue', $input ); },
				'meta' => self::ability_meta( true, false, true ),
			),
			'ai-translations/translation-job-v2-next' => array(
				'label' => 'Discover Next Complete-Inventory Translation Job',
				'description' => 'Selects the first unresolved whole-site obligation and delegates job creation to the existing v2 discover lifecycle.',
				'input_schema' => array( 'type' => 'object', 'properties' => array( 'observability_label' => array( 'type' => 'string' ) ), 'additionalProperties' => false ),
				'output_schema' => self::generic_output_schema(),
				'execute_callback' => function ( $input = array() ) { return self::run_ability_operation( 'translation_job_v2_next', $input ); },
				'meta' => self::ability_meta( false, false, true ),
			),
			'ai-translations/translation-exhaustion-proof' => array(
				'label' => 'Prove Whole-Site Translation Exhaustion',
				'description' => 'Proves a clean complete generation, exact source-language arithmetic, and zero unresolved obligations.',
				'input_schema' => array( 'type' => 'object', 'properties' => array( 'refresh' => array( 'type' => 'boolean', 'default' => true ) ), 'additionalProperties' => false ),
				'output_schema' => self::generic_output_schema(),
				'execute_callback' => function ( $input = array() ) { return self::run_ability_operation( 'translation_exhaustion_proof', $input ); },
				'meta' => self::ability_meta( true, false, true ),
			),
		);
	}

	private static function install_source_inventory_schema(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$sources = self::source_inventory_table();
		$obligations = self::translation_obligations_table();
		dbDelta( "CREATE TABLE {$sources} (
			generation varchar(64) NOT NULL, source_id bigint(20) unsigned NOT NULL,
			post_type varchar(20) NOT NULL, post_status varchar(20) NOT NULL,
			applicable tinyint(1) unsigned NOT NULL DEFAULT '0', exclusion_reason varchar(64) NOT NULL DEFAULT '',
			source_revision varchar(64) NOT NULL DEFAULT '', modified_gmt datetime NOT NULL,
			PRIMARY KEY  (generation, source_id),
			KEY applicable_cursor (generation, applicable, source_id)
		) {$charset};" );
		dbDelta( "CREATE TABLE {$obligations} (
			obligation_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, generation varchar(64) NOT NULL,
			source_id bigint(20) unsigned NOT NULL, target_language varchar(20) NOT NULL,
			state varchar(40) NOT NULL, job_id varchar(100) NOT NULL DEFAULT '', translation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_revision varchar(64) NOT NULL, updated_gmt datetime NOT NULL,
			PRIMARY KEY  (obligation_id),
			UNIQUE KEY generation_source_language (generation, source_id, target_language),
			KEY unresolved_cursor (generation, state, obligation_id)
		) {$charset};" );
		update_option( self::OPTION_SOURCE_INVENTORY_SCHEMA, self::SOURCE_INVENTORY_SCHEMA_VERSION, false );
	}

	public static function mark_source_inventory_dirty( int $post_id = 0 ): void {
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post && ( ! self::is_translatable_post_type( (string) $post->post_type ) || self::is_translation_post( $post_id ) ) ) { return; }
		}
		update_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '1', false );
	}

	private static function rebuild_source_inventory( array $input = array() ): array {
		global $wpdb;
		self::install_source_inventory_schema();
		$generation = gmdate( 'YmdHis' ) . '-' . substr( wp_generate_uuid4(), 0, 8 );
		$post_types = self::translatable_post_types();
		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$included = 0; $excluded = 0; $reasons = array(); $source_rows = array();
		$last_id = 0;
		do {
			$sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND ID > %d ORDER BY ID ASC LIMIT 500";
			$args = array_merge( $post_types, array( $last_id ) );
			$ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $ids as $id ) {
			$last_id = $id;
			$post = get_post( $id );
			if ( ! $post ) { continue; }
			$reason = '';
			if ( self::is_translation_post( $id ) ) { $reason = 'translation'; }
			elseif ( 'publish' !== $post->post_status ) { $reason = 'status_' . sanitize_key( $post->post_status ); }
			elseif ( '' !== (string) $post->post_password ) { $reason = 'password_protected'; }
			elseif ( ! is_post_publicly_viewable( $post ) ) { $reason = 'not_publicly_viewable'; }
			$applicable = '' === $reason;
			$revision = $applicable ? self::source_hash( $post ) : '';
			$wpdb->replace( self::source_inventory_table(), array(
				'generation' => $generation, 'source_id' => $id, 'post_type' => $post->post_type,
				'post_status' => $post->post_status, 'applicable' => $applicable ? 1 : 0,
				'exclusion_reason' => $reason, 'source_revision' => $revision,
				'modified_gmt' => '0000-00-00 00:00:00' === $post->post_modified_gmt ? gmdate( 'Y-m-d H:i:s' ) : $post->post_modified_gmt,
			) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $applicable ) { ++$included; $source_rows[] = array( $id, $revision ); }
			else { ++$excluded; $reasons[ $reason ] = 1 + ( $reasons[ $reason ] ?? 0 ); }
		}
		} while ( 500 === count( $ids ) );
		$languages = array_keys( self::target_languages() );
		$state_counts = array();
		foreach ( $source_rows as $source_row ) {
			foreach ( $languages as $language ) {
				$projection = self::project_translation_obligation( $source_row[0], $language, $source_row[1] );
				$state_counts[ $projection['state'] ] = 1 + ( $state_counts[ $projection['state'] ] ?? 0 );
				$wpdb->insert( self::translation_obligations_table(), array_merge( $projection, array( 'generation' => $generation, 'updated_gmt' => gmdate( 'Y-m-d H:i:s' ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			}
		}
		$manifest = array( 'generation' => $generation, 'completed_at' => gmdate( 'c' ), 'included_sources' => $included, 'excluded_sources' => $excluded, 'excluded_by_reason' => $reasons, 'target_languages' => count( $languages ), 'projected_obligations' => $included * count( $languages ), 'source_signature' => hash( 'sha256', wp_json_encode( $source_rows ) ), 'state_counts' => $state_counts );
		update_option( self::OPTION_SOURCE_INVENTORY_ACTIVE, $manifest, false );
		update_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '0', false );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE generation <> %s', self::source_inventory_table(), $generation ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE generation <> %s', self::translation_obligations_table(), $generation ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return array( 'success' => true, 'inventory' => $manifest );
	}

	private static function project_translation_obligation( int $source_id, string $language, string $revision ): array {
		$translation_id = self::translation_index_id_for_source_language( $source_id, $language, self::translation_workflow_post_statuses( false ) );
		$job_id = self::translation_job_v2_id( $source_id, $language, $revision );
		$job = self::translation_job_v2_get_job( $job_id );
		$state = 'missing';
		if ( $job ) {
			$state = sanitize_key( (string) ( $job['status'] ?? 'queued' ) );
			if ( 'published' === $state && ! empty( $job['live_verification']['passed'] ) && ! empty( $job['artifact_revision'] ) && ! empty( $job['quality_revision'] ) ) { $state = 'published_verified'; }
		}
		if ( ! $job && $translation_id > 0 ) {
			$translation = get_post( $translation_id );
			$stored_hash = (string) get_post_meta( $translation_id, self::META_SOURCE_HASH, true );
			$state = $translation && 'publish' === $translation->post_status && hash_equals( $revision, $stored_hash ) ? 'legacy_review_required' : 'stale';
		}
		return array( 'source_id' => $source_id, 'target_language' => $language, 'state' => $state, 'job_id' => $job_id, 'translation_id' => $translation_id, 'source_revision' => $revision );
	}

	private static function source_inventory( array $input ): array {
		global $wpdb; $manifest = get_option( self::OPTION_SOURCE_INVENTORY_ACTIVE, array() );
		if ( ! is_array( $manifest ) || empty( $manifest['generation'] ) ) { return array( 'success' => false, 'code' => 'inventory_not_built' ); }
		$cursor = absint( $input['cursor'] ?? 0 ); $limit = min( 500, max( 1, absint( $input['limit'] ?? 100 ) ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE generation = %s AND source_id > %d ORDER BY source_id ASC LIMIT %d', self::source_inventory_table(), $manifest['generation'], $cursor, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$next = $rows ? absint( end( $rows )['source_id'] ) : 0;
		return array( 'success' => true, 'inventory' => $manifest, 'dirty' => '1' === get_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '1' ), 'items' => $rows, 'next_cursor' => count( $rows ) === $limit ? $next : null );
	}

	private static function refresh_active_obligations(): array {
		global $wpdb; $manifest = get_option( self::OPTION_SOURCE_INVENTORY_ACTIVE, array() );
		if ( ! is_array( $manifest ) || empty( $manifest['generation'] ) ) { return array(); }
		if ( '1' === get_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '1' ) && hash_equals( (string) ( $manifest['source_signature'] ?? '' ), self::current_source_inventory_signature() ) ) {
			update_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '0', false );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT source_id, target_language, source_revision FROM %i WHERE generation = %s ORDER BY obligation_id ASC', self::translation_obligations_table(), $manifest['generation'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $rows as $row ) {
			$projection = self::project_translation_obligation( absint( $row['source_id'] ), sanitize_key( $row['target_language'] ), (string) $row['source_revision'] );
			$wpdb->update( self::translation_obligations_table(), array( 'state' => $projection['state'], 'job_id' => $projection['job_id'], 'translation_id' => $projection['translation_id'], 'updated_gmt' => gmdate( 'Y-m-d H:i:s' ) ), array( 'generation' => $manifest['generation'], 'source_id' => $projection['source_id'], 'target_language' => $projection['target_language'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		return $manifest;
	}

	private static function current_source_inventory_signature(): string {
		global $wpdb;
		$post_types = self::translatable_post_types();
		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_status = 'publish' AND post_password = '' ORDER BY ID ASC";
		$ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( $sql, $post_types ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post && ! self::is_translation_post( $id ) && is_post_publicly_viewable( $post ) ) { $rows[] = array( $id, self::source_hash( $post ) ); }
		}
		return hash( 'sha256', wp_json_encode( $rows ) );
	}

	private static function translation_obligation_queue( array $input ): array {
		global $wpdb; $manifest = self::refresh_active_obligations();
		if ( ! $manifest ) { return array( 'success' => false, 'code' => 'inventory_not_built' ); }
		$cursor = absint( $input['cursor'] ?? 0 ); $limit = min( 500, max( 1, absint( $input['limit'] ?? 100 ) ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE generation = %s AND obligation_id > %d AND state <> 'published_verified' ORDER BY obligation_id ASC LIMIT %d", self::translation_obligations_table(), $manifest['generation'], $cursor, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$next = $rows ? absint( end( $rows )['obligation_id'] ) : 0;
		return array( 'success' => true, 'generation' => $manifest['generation'], 'items' => $rows, 'item_count' => count( $rows ), 'next_cursor' => count( $rows ) === $limit ? $next : null );
	}

	private static function translation_job_v2_next( array $input ): array {
		$queue = self::translation_obligation_queue( array( 'cursor' => 0, 'limit' => 1 ) );
		if ( empty( $queue['success'] ) || empty( $queue['items'] ) ) { return array( 'success' => ! empty( $queue['success'] ), 'exhausted' => ! empty( $queue['success'] ), 'queue' => $queue ); }
		$item = $queue['items'][0];
		$result = self::translation_job_v2_discover( array( 'source_id' => absint( $item['source_id'] ), 'language' => sanitize_key( $item['target_language'] ), 'observability_label' => sanitize_text_field( (string) ( $input['observability_label'] ?? '' ) ) ) );
		return array( 'success' => ! empty( $result['success'] ), 'obligation' => $item, 'discover' => $result );
	}

	private static function translation_exhaustion_proof( array $input = array() ): array {
		global $wpdb; $manifest = self::refresh_active_obligations();
		if ( ! $manifest ) { return array( 'success' => false, 'complete' => false, 'code' => 'inventory_not_built' ); }
		$generation = (string) $manifest['generation'];
		$total = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE generation = %s', self::translation_obligations_table(), $generation ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$unresolved = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE generation = %s AND state <> 'published_verified'", self::translation_obligations_table(), $generation ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$expected = absint( $manifest['included_sources'] ?? 0 ) * absint( $manifest['target_languages'] ?? 0 );
		$dirty = '1' === get_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '1' );
		$complete = ! $dirty && $expected === $total && 0 === $unresolved;
		return array( 'success' => true, 'complete' => $complete, 'generation' => $generation, 'generation_completed_at' => $manifest['completed_at'] ?? '', 'dirty' => $dirty, 'included_sources' => absint( $manifest['included_sources'] ?? 0 ), 'target_languages' => absint( $manifest['target_languages'] ?? 0 ), 'expected_obligations' => $expected, 'projected_obligations' => $total, 'unresolved_obligations' => $unresolved, 'all_published_verified' => 0 === $unresolved );
	}
}
