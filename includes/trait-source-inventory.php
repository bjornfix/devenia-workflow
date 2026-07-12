<?php
/**
 * Authoritative public source inventory and translation obligation projection.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Devenia_AI_Translations_Source_Inventory {
	private const INVENTORY_STORE_SHARD_SIZE = 200;

	private static function inventory_store_index_name( string $generation ): string {
		return 'devenia_ai_inventory_' . sanitize_key( $generation ) . '_index';
	}

	private static function inventory_store_shard_name( string $generation, string $kind, int $shard ): string {
		return 'devenia_ai_inventory_' . sanitize_key( $generation ) . '_' . sanitize_key( $kind ) . '_' . max( 0, $shard );
	}

	/** @param array<int,array<string,mixed>> $rows */
	private static function inventory_store_write_rows( string $generation, string $kind, array $rows ): int {
		$chunks = array_chunk( array_values( $rows ), self::INVENTORY_STORE_SHARD_SIZE );
		foreach ( $chunks as $shard => $chunk ) {
			update_option( self::inventory_store_shard_name( $generation, $kind, $shard ), $chunk, false );
		}
		return count( $chunks );
	}

	/** @return array<int,array<string,mixed>> */
	private static function inventory_store_read_rows( string $generation, string $kind ): array {
		$index = get_option( self::inventory_store_index_name( $generation ), array() );
		$count = is_array( $index ) ? absint( $index[ $kind . '_shards' ] ?? 0 ) : 0;
		$rows  = array();
		for ( $shard = 0; $shard < $count; ++$shard ) {
			$chunk = get_option( self::inventory_store_shard_name( $generation, $kind, $shard ), array() );
			if ( is_array( $chunk ) ) {
				$rows = array_merge( $rows, $chunk );
			}
		}
		return $rows;
	}

	/** @param array<int,array<string,mixed>> $sources @param array<int,array<string,mixed>> $obligations */
	private static function inventory_store_write_generation( string $generation, array $sources, array $obligations ): array {
		$index = array(
			'generation'          => $generation,
			'source_shards'       => self::inventory_store_write_rows( $generation, 'source', $sources ),
			'obligation_shards'   => self::inventory_store_write_rows( $generation, 'obligation', $obligations ),
			'source_count'        => count( $sources ),
			'obligation_count'    => count( $obligations ),
			'written_at'          => gmdate( 'c' ),
		);
		update_option( self::inventory_store_index_name( $generation ), $index, false );
		return $index;
	}

	/** @param array<int,array<string,mixed>> $rows */
	private static function inventory_store_replace_obligations( string $generation, array $rows ): void {
		$index = get_option( self::inventory_store_index_name( $generation ), array() );
		if ( ! is_array( $index ) ) { return; }
		$old_count = absint( $index['obligation_shards'] ?? 0 );
		$new_count = self::inventory_store_write_rows( $generation, 'obligation', $rows );
		for ( $shard = $new_count; $shard < $old_count; ++$shard ) {
			delete_option( self::inventory_store_shard_name( $generation, 'obligation', $shard ) );
		}
		$index['obligation_shards'] = $new_count;
		$index['obligation_count']  = count( $rows );
		$index['refreshed_at']      = gmdate( 'c' );
		update_option( self::inventory_store_index_name( $generation ), $index, false );
	}

	private static function inventory_store_delete_generation( string $generation ): void {
		$index = get_option( self::inventory_store_index_name( $generation ), array() );
		if ( is_array( $index ) ) {
			foreach ( array( 'source', 'obligation' ) as $kind ) {
				$count = absint( $index[ $kind . '_shards' ] ?? 0 );
				for ( $shard = 0; $shard < $count; ++$shard ) {
					delete_option( self::inventory_store_shard_name( $generation, $kind, $shard ) );
				}
			}
		}
		delete_option( self::inventory_store_index_name( $generation ) );
	}

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
		self::install_source_inventory_schema();
		$generation = gmdate( 'YmdHis' ) . '-' . substr( wp_generate_uuid4(), 0, 8 );
		$post_types = self::translatable_post_types();
		$included = 0; $excluded = 0; $reasons = array(); $source_rows = array(); $inventory_rows = array();
		$page = 1;
		do {
			$query = new WP_Query( array( 'post_type' => $post_types, 'post_status' => array_keys( get_post_stati() ), 'posts_per_page' => 500, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'no_found_rows' => true ) );
			$ids = array_map( 'absint', $query->posts );
			foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) { continue; }
			$reason = '';
			if ( self::is_translation_post( $id ) ) { $reason = 'translation'; }
			elseif ( 'publish' !== $post->post_status ) { $reason = 'status_' . sanitize_key( $post->post_status ); }
			elseif ( '' !== (string) $post->post_password ) { $reason = 'password_protected'; }
			elseif ( ! is_post_publicly_viewable( $post ) ) { $reason = 'not_publicly_viewable'; }
			$applicable = '' === $reason;
			$revision = $applicable ? self::source_hash( $post ) : '';
			$inventory_rows[] = array(
				'generation' => $generation, 'source_id' => $id, 'post_type' => $post->post_type,
				'post_status' => $post->post_status, 'applicable' => $applicable ? 1 : 0,
				'exclusion_reason' => $reason, 'source_revision' => $revision,
				'modified_gmt' => '0000-00-00 00:00:00' === $post->post_modified_gmt ? gmdate( 'Y-m-d H:i:s' ) : $post->post_modified_gmt,
			);
			if ( $applicable ) { ++$included; $source_rows[] = array( $id, $revision ); }
			else { ++$excluded; $reasons[ $reason ] = 1 + ( $reasons[ $reason ] ?? 0 ); }
			}
			++$page;
		} while ( 500 === count( $ids ) );
		$languages = array_keys( self::target_languages() );
		$state_counts = array(); $obligation_rows = array(); $obligation_id = 0;
		foreach ( $source_rows as $source_row ) {
			foreach ( $languages as $language ) {
				$projection = self::project_translation_obligation( $source_row[0], $language, $source_row[1] );
				$state_counts[ $projection['state'] ] = 1 + ( $state_counts[ $projection['state'] ] ?? 0 );
				$obligation_rows[] = array_merge( $projection, array( 'obligation_id' => ++$obligation_id, 'generation' => $generation, 'updated_gmt' => gmdate( 'Y-m-d H:i:s' ) ) );
			}
		}
		$manifest = array( 'generation' => $generation, 'completed_at' => gmdate( 'c' ), 'included_sources' => $included, 'excluded_sources' => $excluded, 'excluded_by_reason' => $reasons, 'target_languages' => count( $languages ), 'projected_obligations' => $included * count( $languages ), 'source_signature' => hash( 'sha256', wp_json_encode( $source_rows ) ), 'state_counts' => $state_counts );
		self::inventory_store_write_generation( $generation, $inventory_rows, $obligation_rows );
		$previous = get_option( self::OPTION_SOURCE_INVENTORY_ACTIVE, array() );
		update_option( self::OPTION_SOURCE_INVENTORY_ACTIVE, $manifest, false );
		update_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '0', false );
		if ( is_array( $previous ) && ! empty( $previous['generation'] ) && $generation !== (string) $previous['generation'] ) {
			self::inventory_store_delete_generation( (string) $previous['generation'] );
		}
		return array( 'success' => true, 'inventory' => $manifest );
	}

	private static function project_translation_obligation( int $source_id, string $language, string $revision ): array {
		$translation_id = self::translation_index_id_for_source_language( $source_id, $language, self::translation_workflow_post_statuses( false ) );
		$job_id = self::translation_job_v2_id( $source_id, $language, $revision );
		$job = self::translation_job_v2_get_job( $job_id );
		$state = 'missing';
		if ( $job ) {
			$state = sanitize_key( (string) ( $job['status'] ?? 'queued' ) );
			if ( 'published' === $state && ! empty( $job['live_verification_passed'] ) && ! empty( $job['artifact_revision'] ) && ! empty( $job['quality_revision'] ) ) { $state = 'published_verified'; }
		}
		if ( ! $job && $translation_id > 0 ) {
			$translation = get_post( $translation_id );
			$stored_hash = (string) get_post_meta( $translation_id, self::META_SOURCE_HASH, true );
			$state = $translation && 'publish' === $translation->post_status && hash_equals( $revision, $stored_hash ) ? 'legacy_review_required' : 'stale';
		}
		return array( 'source_id' => $source_id, 'target_language' => $language, 'state' => $state, 'job_id' => $job_id, 'translation_id' => $translation_id, 'source_revision' => $revision );
	}

	private static function source_inventory( array $input ): array {
		$manifest = get_option( self::OPTION_SOURCE_INVENTORY_ACTIVE, array() );
		if ( ! is_array( $manifest ) || empty( $manifest['generation'] ) ) { return array( 'success' => false, 'code' => 'inventory_not_built' ); }
		if ( ! is_array( get_option( self::inventory_store_index_name( (string) $manifest['generation'] ), null ) ) ) { return array( 'success' => false, 'code' => 'inventory_store_rebuild_required' ); }
		$cursor = absint( $input['cursor'] ?? 0 ); $limit = min( 500, max( 1, absint( $input['limit'] ?? 100 ) ) );
		$rows = array_values( array_filter( self::inventory_store_read_rows( (string) $manifest['generation'], 'source' ), static function ( $row ) use ( $cursor ) { return is_array( $row ) && absint( $row['source_id'] ?? 0 ) > $cursor; } ) );
		$rows = array_slice( $rows, 0, $limit );
		$next = $rows ? absint( end( $rows )['source_id'] ) : 0;
		return array( 'success' => true, 'inventory' => $manifest, 'dirty' => '1' === get_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '1' ), 'items' => $rows, 'next_cursor' => count( $rows ) === $limit ? $next : null );
	}

	private static function refresh_active_obligations(): array {
		$manifest = get_option( self::OPTION_SOURCE_INVENTORY_ACTIVE, array() );
		if ( ! is_array( $manifest ) || empty( $manifest['generation'] ) ) { return array(); }
		if ( ! is_array( get_option( self::inventory_store_index_name( (string) $manifest['generation'] ), null ) ) ) { return array(); }
		if ( '1' === get_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '1' ) && hash_equals( (string) ( $manifest['source_signature'] ?? '' ), self::current_source_inventory_signature() ) ) {
			update_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '0', false );
		}
		$rows = self::inventory_store_read_rows( (string) $manifest['generation'], 'obligation' );
		$changed = false;
		foreach ( $rows as $index => $row ) {
			$projection = self::project_translation_obligation( absint( $row['source_id'] ), sanitize_key( $row['target_language'] ), (string) $row['source_revision'] );
			foreach ( array( 'state', 'job_id', 'translation_id' ) as $field ) {
				if ( (string) ( $rows[ $index ][ $field ] ?? '' ) !== (string) ( $projection[ $field ] ?? '' ) ) { $changed = true; }
				$rows[ $index ][ $field ] = $projection[ $field ];
			}
			$rows[ $index ]['updated_gmt'] = gmdate( 'Y-m-d H:i:s' );
		}
		if ( $changed ) { self::inventory_store_replace_obligations( (string) $manifest['generation'], $rows ); }
		return $manifest;
	}

	private static function current_source_inventory_signature(): string {
		$post_types = self::translatable_post_types();
		$query = new WP_Query( array( 'post_type' => $post_types, 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'no_found_rows' => true, 'has_password' => false ) );
		$ids = array_map( 'absint', $query->posts );
		$rows = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post && ! self::is_translation_post( $id ) && is_post_publicly_viewable( $post ) ) { $rows[] = array( $id, self::source_hash( $post ) ); }
		}
		return hash( 'sha256', wp_json_encode( $rows ) );
	}

	private static function translation_obligation_queue( array $input ): array {
		$manifest = self::refresh_active_obligations();
		if ( ! $manifest ) { return array( 'success' => false, 'code' => 'inventory_not_built' ); }
		$cursor = absint( $input['cursor'] ?? 0 ); $limit = min( 500, max( 1, absint( $input['limit'] ?? 100 ) ) );
		$rows = array_values( array_filter( self::inventory_store_read_rows( (string) $manifest['generation'], 'obligation' ), static function ( $row ) use ( $cursor ) { return is_array( $row ) && absint( $row['obligation_id'] ?? 0 ) > $cursor && 'published_verified' !== (string) ( $row['state'] ?? '' ); } ) );
		$rows = array_slice( $rows, 0, $limit );
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
		$manifest = self::refresh_active_obligations();
		if ( ! $manifest ) { return array( 'success' => false, 'complete' => false, 'code' => 'inventory_not_built' ); }
		$generation = (string) $manifest['generation'];
		$obligations = self::inventory_store_read_rows( $generation, 'obligation' );
		$total = count( $obligations );
		$unresolved = count( array_filter( $obligations, static function ( $row ) { return is_array( $row ) && 'published_verified' !== (string) ( $row['state'] ?? '' ); } ) );
		$expected = absint( $manifest['included_sources'] ?? 0 ) * absint( $manifest['target_languages'] ?? 0 );
		$dirty = '1' === get_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '1' );
		$complete = ! $dirty && $expected === $total && 0 === $unresolved;
		return array( 'success' => true, 'complete' => $complete, 'generation' => $generation, 'generation_completed_at' => $manifest['completed_at'] ?? '', 'dirty' => $dirty, 'included_sources' => absint( $manifest['included_sources'] ?? 0 ), 'target_languages' => absint( $manifest['target_languages'] ?? 0 ), 'expected_obligations' => $expected, 'projected_obligations' => $total, 'unresolved_obligations' => $unresolved, 'all_published_verified' => 0 === $unresolved );
	}
}
