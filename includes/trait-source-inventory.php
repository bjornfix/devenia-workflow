<?php
/**
 * Authoritative public source inventory and translation obligation projection.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Devenia_Workflow_Source_Inventory {
	private const INVENTORY_STORE_SHARD_SIZE = 200;
	private const INVENTORY_DEPENDENCY_TRAVERSAL_LIMIT = 200;
	private const OBLIGATION_PROJECTION_LEASE_KEY = 'devenia_workflow_obligation_projection_lease';
	private const OBLIGATION_PROJECTION_LEASE_SECONDS = 120;
	private static int $inventory_authorized_mutation_depth = 0;

	/** Normalize the public Source Inventory phase scope. */
	private static function inventory_source_type_scope( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, array( 'page', 'post' ), true ) ? $value : 'all';
	}

	private static function inventory_source_type_scope_schema(): array {
		return array( 'type' => 'string', 'enum' => array( 'all', 'page', 'post' ), 'default' => 'all' );
	}

	private static function inventory_obligation_snapshot_kind( string $source_type ): string {
		return 'all' === $source_type ? 'obligation' : 'obligation_' . $source_type;
	}

	private static function inventory_store_index_name( string $generation ): string {
		return 'devenia_workflow_inventory_' . sanitize_key( $generation ) . '_index';
	}

	private static function inventory_store_shard_name( string $generation, string $kind, int $shard ): string {
		return 'devenia_workflow_inventory_' . sanitize_key( $generation ) . '_' . sanitize_key( $kind ) . '_' . max( 0, $shard );
	}

	/** @param array<int,array<string,mixed>> $rows @return array<int,string> */
	private static function inventory_store_shard_digests( array $rows ): array {
		return array_map(
			static function ( array $chunk ): string { return hash( 'sha256', wp_json_encode( array_values( $chunk ) ) ?: '' ); },
			array_chunk( array_values( $rows ), self::INVENTORY_STORE_SHARD_SIZE )
		);
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
		$result = self::inventory_store_read_rows_strict( $generation, $kind );
		return ! empty( $result['success'] ) ? $result['rows'] : array();
	}

	/** Read every declared shard or fail closed; a partial Store is never empty. */
	private static function inventory_store_read_rows_strict( string $generation, string $kind ): array {
		$index = get_option( self::inventory_store_index_name( $generation ), array() );
		if ( ! is_array( $index ) || $generation !== (string) ( $index['generation'] ?? '' ) ) {
			return array( 'success' => false, 'code' => 'inventory_store_rebuild_required' );
		}
		$count = absint( $index[ $kind . '_shards' ] ?? 0 );
		$expected = absint( $index[ $kind . '_count' ] ?? 0 );
		$digests = is_array( $index[ $kind . '_shard_digests' ] ?? null ) ? $index[ $kind . '_shard_digests' ] : array();
		if ( $count !== (int) ceil( $expected / self::INVENTORY_STORE_SHARD_SIZE ) ) {
			return array( 'success' => false, 'code' => 'inventory_store_rebuild_required' );
		}
		if ( count( $digests ) !== $count ) { return array( 'success' => false, 'code' => 'inventory_store_rebuild_required' ); }
		$rows  = array();
		$unresolved_counts = array();
		$unresolved_source_type_counts = array( 'page' => array(), 'post' => array() );
		for ( $shard = 0; $shard < $count; ++$shard ) {
			$chunk = get_option( self::inventory_store_shard_name( $generation, $kind, $shard ), null );
			$remaining = $expected - count( $rows );
			$expected_chunk = min( self::INVENTORY_STORE_SHARD_SIZE, $remaining );
			if ( ! is_array( $chunk ) || count( $chunk ) !== $expected_chunk ) {
				return array( 'success' => false, 'code' => 'inventory_store_rebuild_required', 'kind' => $kind, 'shard' => $shard );
			}
			$digest = hash( 'sha256', wp_json_encode( array_values( $chunk ) ) ?: '' );
			if ( ! isset( $digests[ $shard ] ) || ! hash_equals( (string) $digests[ $shard ], $digest ) ) {
				return array( 'success' => false, 'code' => 'inventory_store_rebuild_required', 'kind' => $kind, 'shard' => $shard );
			}
			$rows = array_merge( $rows, $chunk );
			if ( 'obligation' === $kind ) {
				$unresolved_counts[ $shard ] = count( array_filter( $chunk, static function ( $row ) { return is_array( $row ) && 'published_verified' !== (string) ( $row['state'] ?? '' ); } ) );
				foreach ( array( 'page', 'post' ) as $source_type ) {
					$unresolved_source_type_counts[ $source_type ][ $shard ] = count( array_filter( $chunk, static function ( $row ) use ( $source_type ) { return is_array( $row ) && 'published_verified' !== (string) ( $row['state'] ?? '' ) && $source_type === sanitize_key( (string) ( $row['source_post_type'] ?? '' ) ); } ) );
				}
			}
		}
		if ( count( $rows ) !== $expected ) {
			return array( 'success' => false, 'code' => 'inventory_store_rebuild_required' );
		}
		if ( 'obligation' === $kind && array_values( $unresolved_counts ) !== array_values( array_map( 'absint', (array) ( $index['unresolved_shard_counts'] ?? array() ) ) ) ) {
			return array( 'success' => false, 'code' => 'inventory_store_rebuild_required', 'kind' => 'obligation_index' );
		}
		if ( 'obligation' === $kind ) {
			foreach ( array( 'page', 'post' ) as $source_type ) {
				if ( array_values( $unresolved_source_type_counts[ $source_type ] ) !== array_values( array_map( 'absint', (array) ( $index['unresolved_source_type_shard_counts'][ $source_type ] ?? array() ) ) ) ) {
					return array( 'success' => false, 'code' => 'inventory_store_rebuild_required', 'kind' => 'obligation_source_type_index' );
				}
			}
		}
		return array( 'success' => true, 'rows' => $rows, 'index' => $index );
	}

	/** @param array<int,array<string,mixed>> $sources @param array<int,array<string,mixed>> $obligations */
	private static function inventory_store_write_generation( string $generation, array $sources, array $obligations ): array {
		$source_lookup = array();
		foreach ( array_values( $sources ) as $offset => $row ) {
			$source_id = absint( $row['source_id'] ?? 0 );
			$source_lookup[ (string) $source_id ] = array( 'shard' => intdiv( $offset, self::INVENTORY_STORE_SHARD_SIZE ), 'row' => $offset % self::INVENTORY_STORE_SHARD_SIZE );
		}
		$obligation_lookup = array();
		$unresolved_shard_counts = array_fill( 0, (int) ceil( count( $obligations ) / self::INVENTORY_STORE_SHARD_SIZE ), 0 );
		$unresolved_source_type_shard_counts = array(
			'page' => array_fill( 0, count( $unresolved_shard_counts ), 0 ),
			'post' => array_fill( 0, count( $unresolved_shard_counts ), 0 ),
		);
		foreach ( array_values( $obligations ) as $offset => $row ) {
			$key = absint( $row['source_id'] ?? 0 ) . ':' . sanitize_key( (string) ( $row['target_language'] ?? '' ) );
			$shard = intdiv( $offset, self::INVENTORY_STORE_SHARD_SIZE );
			$obligation_lookup[ $key ] = array( 'shard' => $shard, 'row' => $offset % self::INVENTORY_STORE_SHARD_SIZE );
			if ( 'published_verified' !== (string) ( $row['state'] ?? '' ) ) {
				++$unresolved_shard_counts[ $shard ];
				$source_type = sanitize_key( (string) ( $row['source_post_type'] ?? '' ) );
				if ( isset( $unresolved_source_type_shard_counts[ $source_type ] ) ) { ++$unresolved_source_type_shard_counts[ $source_type ][ $shard ]; }
			}
		}
		$index = array(
			'generation'          => $generation,
			'source_shards'       => self::inventory_store_write_rows( $generation, 'source', $sources ),
			'obligation_shards'   => self::inventory_store_write_rows( $generation, 'obligation', $obligations ),
			'source_count'        => count( $sources ),
			'obligation_count'    => count( $obligations ),
			'source_shard_digests' => self::inventory_store_shard_digests( $sources ),
			'obligation_shard_digests' => self::inventory_store_shard_digests( $obligations ),
			'obligation_lookup'   => $obligation_lookup,
			'unresolved_shard_counts' => $unresolved_shard_counts,
			'unresolved_source_type_shard_counts' => $unresolved_source_type_shard_counts,
			'source_lookup' => $source_lookup,
			'written_at'          => gmdate( 'c' ),
		);
		update_option( self::inventory_store_index_name( $generation ), $index, false );
		return $index;
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

	/** Read a bounded contiguous row range through validated shard receipts. */
	private static function inventory_store_read_row_range( array $manifest, string $kind, int $offset, int $limit ): array {
		$generation = (string) ( $manifest['generation'] ?? '' );
		$index = get_option( self::inventory_store_index_name( $generation ), null );
		if ( ! is_array( $index ) || ! hash_equals( (string) ( $manifest['inventory_index_digest'] ?? '' ), hash( 'sha256', wp_json_encode( $index ) ?: '' ) ) ) {
			return array( 'success' => false, 'code' => 'inventory_store_rebuild_required' );
		}
		$kind = 'source' === $kind ? 'source' : 'obligation';
		$count = absint( $index[ $kind . '_count' ] ?? 0 );
		$offset = max( 0, $offset );
		$end = min( $count, $offset + max( 1, $limit ) );
		$rows = array();
		$digests = (array) ( $index[ $kind . '_shard_digests' ] ?? array() );
		for ( $position = $offset; $position < $end; ) {
			$shard = intdiv( $position, self::INVENTORY_STORE_SHARD_SIZE );
			$chunk = get_option( self::inventory_store_shard_name( $generation, $kind, $shard ), null );
			$digest = is_array( $chunk ) ? hash( 'sha256', wp_json_encode( array_values( $chunk ) ) ?: '' ) : '';
			if ( ! is_array( $chunk ) || ! isset( $digests[ $shard ] ) || ! hash_equals( (string) $digests[ $shard ], $digest ) ) {
				return array( 'success' => false, 'code' => 'inventory_store_rebuild_required', 'kind' => $kind, 'shard' => $shard );
			}
			$within = $position % self::INVENTORY_STORE_SHARD_SIZE;
			$take = min( count( $chunk ) - $within, $end - $position );
			if ( $take < 1 ) { return array( 'success' => false, 'code' => 'inventory_store_rebuild_required', 'kind' => $kind, 'shard' => $shard ); }
			$rows = array_merge( $rows, array_slice( $chunk, $within, $take ) );
			$position += $take;
		}
		return array( 'success' => true, 'rows' => $rows, 'index' => $index, 'end_offset' => $end, 'terminal' => $end >= $count );
	}

	/** Seek unresolved obligations through per-row-shard counts; never materialize the whole queue. */
	private static function inventory_store_seek_unresolved( array $manifest, int $cursor, int $limit, string $source_type = 'all' ): array {
		$generation = (string) ( $manifest['generation'] ?? '' );
		$index = get_option( self::inventory_store_index_name( $generation ), null );
		if ( ! is_array( $index ) || ! hash_equals( (string) ( $manifest['inventory_index_digest'] ?? '' ), hash( 'sha256', wp_json_encode( $index ) ?: '' ) ) ) {
			return array( 'success' => false, 'code' => 'inventory_store_rebuild_required' );
		}
		$count = absint( $index['obligation_count'] ?? 0 );
		$source_type = self::inventory_source_type_scope( $source_type );
		$shard_counts = 'all' === $source_type
			? array_values( array_map( 'absint', (array) ( $index['unresolved_shard_counts'] ?? array() ) ) )
			: array_values( array_map( 'absint', (array) ( $index['unresolved_source_type_shard_counts'][ $source_type ] ?? array() ) ) );
		$expected_shards = absint( $index['obligation_shards'] ?? 0 );
		if ( count( $shard_counts ) !== $expected_shards ) { return array( 'success' => false, 'code' => 'inventory_store_rebuild_required' ); }
		$ids = array();
		$start_shard = min( $expected_shards, intdiv( max( 0, $cursor ), self::INVENTORY_STORE_SHARD_SIZE ) );
		for ( $shard = $start_shard; $shard < $expected_shards && count( $ids ) <= $limit; ++$shard ) {
			if ( 0 === $shard_counts[ $shard ] ) { continue; }
			$offset = $shard * self::INVENTORY_STORE_SHARD_SIZE;
			$read = self::inventory_store_read_row_range( $manifest, 'obligation', $offset, self::INVENTORY_STORE_SHARD_SIZE );
			if ( empty( $read['success'] ) ) { return $read; }
			$observed = 0;
			foreach ( $read['rows'] as $row ) {
				$id = absint( $row['obligation_id'] ?? 0 );
				if ( 'published_verified' !== (string) ( $row['state'] ?? '' ) && ( 'all' === $source_type || $source_type === sanitize_key( (string) ( $row['source_post_type'] ?? '' ) ) ) ) {
					++$observed;
					if ( $id > $cursor && count( $ids ) <= $limit ) { $ids[] = $id; }
				}
			}
			if ( $observed !== $shard_counts[ $shard ] ) { return array( 'success' => false, 'code' => 'inventory_store_rebuild_required', 'kind' => 'obligation', 'shard' => $shard ); }
		}
		return array( 'success' => true, 'ids' => array_slice( $ids, 0, $limit ), 'has_more' => count( $ids ) > $limit, 'total' => $count, 'index' => $index );
	}

	/** Return the active, structurally complete Inventory Generation manifest. */
	private static function active_inventory_manifest(): array {
		$manifest = get_option( self::OPTION_SOURCE_INVENTORY_ACTIVE, array() );
		if ( ! is_array( $manifest ) || empty( $manifest['generation'] ) ) {
			return array();
		}
		$generation = (string) $manifest['generation'];
		$index = get_option( self::inventory_store_index_name( $generation ), null );
		if (
			! is_array( $index )
			|| $generation !== (string) ( $index['generation'] ?? '' )
			|| absint( $manifest['projected_obligations'] ?? 0 ) !== absint( $index['obligation_count'] ?? 0 )
			|| count( (array) ( $index['obligation_lookup'] ?? array() ) ) !== absint( $index['obligation_count'] ?? 0 )
			|| count( (array) ( $index['source_lookup'] ?? array() ) ) !== absint( $index['source_count'] ?? 0 )
			|| count( (array) ( $index['unresolved_shard_counts'] ?? array() ) ) !== absint( $index['obligation_shards'] ?? 0 )
			|| count( (array) ( $index['unresolved_source_type_shard_counts']['page'] ?? array() ) ) !== absint( $index['obligation_shards'] ?? 0 )
			|| count( (array) ( $index['unresolved_source_type_shard_counts']['post'] ?? array() ) ) !== absint( $index['obligation_shards'] ?? 0 )
			|| absint( $manifest['included_sources'] ?? 0 ) !== absint( $manifest['included_sources_by_post_type']['page'] ?? 0 ) + absint( $manifest['included_sources_by_post_type']['post'] ?? 0 )
			|| ! hash_equals( (string) ( $manifest['inventory_index_digest'] ?? '' ), hash( 'sha256', wp_json_encode( $index ) ?: '' ) )
		) {
			return array();
		}
		return $manifest;
	}

	/** Read only requested generation-bound rows and validate every touched shard. */
	private static function inventory_store_read_bound_rows( array $manifest, string $kind, array $ids ): array {
		$generation = (string) ( $manifest['generation'] ?? '' );
		$index = get_option( self::inventory_store_index_name( $generation ), null );
		if ( ! is_array( $index ) || ! hash_equals( (string) ( $manifest['inventory_index_digest'] ?? '' ), hash( 'sha256', wp_json_encode( $index ) ?: '' ) ) ) {
			return array( 'success' => false, 'code' => 'inventory_store_rebuild_required' );
		}
		$kind = 'source' === $kind ? 'source' : 'obligation';
		$digests = (array) ( $index[ $kind . '_shard_digests' ] ?? array() );
		$count = absint( $index[ $kind . '_count' ] ?? 0 );
		$shards = array();
		$rows = array();
		foreach ( array_values( array_unique( array_map( 'absint', $ids ) ) ) as $id ) {
			if ( $id < 1 ) { continue; }
			if ( 'source' === $kind ) {
				$binding = $index['source_lookup'][ (string) $id ] ?? null;
				if ( ! is_array( $binding ) ) { return array( 'success' => false, 'code' => 'inventory_store_rebuild_required' ); }
				$shard = isset( $binding['shard'] ) ? (int) $binding['shard'] : -1;
				$row_index = isset( $binding['row'] ) ? (int) $binding['row'] : -1;
			} else {
				if ( $id > $count ) { return array( 'success' => false, 'code' => 'inventory_store_rebuild_required' ); }
				$shard = intdiv( $id - 1, self::INVENTORY_STORE_SHARD_SIZE );
				$row_index = ( $id - 1 ) % self::INVENTORY_STORE_SHARD_SIZE;
			}
			if ( $shard < 0 || $row_index < 0 ) { return array( 'success' => false, 'code' => 'inventory_store_rebuild_required' ); }
			if ( ! isset( $shards[ $shard ] ) ) {
				$chunk = get_option( self::inventory_store_shard_name( $generation, $kind, $shard ), null );
				$digest = is_array( $chunk ) ? hash( 'sha256', wp_json_encode( array_values( $chunk ) ) ?: '' ) : '';
				if ( ! is_array( $chunk ) || ! isset( $digests[ $shard ] ) || ! hash_equals( (string) $digests[ $shard ], $digest ) ) {
					return array( 'success' => false, 'code' => 'inventory_store_rebuild_required', 'kind' => $kind, 'shard' => $shard );
				}
				$shards[ $shard ] = $chunk;
			}
			$row = $shards[ $shard ][ $row_index ] ?? null;
			$id_field = 'source' === $kind ? 'source_id' : 'obligation_id';
			if ( ! is_array( $row ) || $id !== absint( $row[ $id_field ] ?? 0 ) ) { return array( 'success' => false, 'code' => 'inventory_store_rebuild_required' ); }
			$rows[] = $row;
		}
		return array( 'success' => true, 'rows' => $rows, 'index' => $index );
	}

	/** Serialize Generation activation and every lifecycle-owned obligation projection. */
	private static function inventory_store_acquire_projection_lease( string $operation ): array {
		$key = self::OBLIGATION_PROJECTION_LEASE_KEY;
		$lease = array(
			'token'      => 'opl_' . substr( hash( 'sha256', wp_generate_uuid4() . '|' . microtime( true ) ), 0, 32 ),
			'operation'  => sanitize_key( $operation ),
			'expires_at' => time() + self::OBLIGATION_PROJECTION_LEASE_SECONDS,
		);
		if ( self::atomic_create_option( $key, $lease ) ) {
			return array( 'success' => true, 'key' => $key, 'lease' => $lease );
		}
		$existing = get_option( $key );
		if ( is_array( $existing ) && absint( $existing['expires_at'] ?? 0 ) < time() && self::atomic_replace_option_value( $key, $existing, $lease ) ) {
			return array( 'success' => true, 'key' => $key, 'lease' => $lease );
		}
		return array( 'success' => false, 'retryable' => true, 'code' => 'obligation_projection_lease_conflict', 'message' => 'Another Inventory Generation or Translation Job writer owns the obligation projection.' );
	}

	private static function inventory_store_release_projection_lease( array $lease_result ): void {
		$key = sanitize_key( (string) ( $lease_result['key'] ?? '' ) );
		$owned = (array) ( $lease_result['lease'] ?? array() );
		if ( '' !== $key ) {
			self::atomic_delete_option_value( $key, $owned );
		}
	}

	private static function inventory_store_projection_epoch(): int {
		return absint( get_option( self::OPTION_OBLIGATION_PROJECTION_EPOCH, 0 ) );
	}

	private static function source_inventory_epoch(): int {
		return absint( get_option( self::OPTION_SOURCE_INVENTORY_EPOCH, 0 ) );
	}

	private static function source_inventory_input_signature(): string {
		return hash(
			'sha256',
			wp_json_encode(
				array(
					'schema' => self::SOURCE_INVENTORY_SCHEMA_VERSION,
					'workflow_mode' => self::workflow_mode(),
					'target_languages' => self::translation_job_canonicalize( self::target_languages() ),
				)
			) ?: ''
		);
	}

	/** Advance source authority monotonically; it is never cleared by a reader. */
	private static function source_inventory_advance_epoch(): int {
		$key = self::OPTION_SOURCE_INVENTORY_EPOCH;
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			$current = get_option( $key, null );
			$next = absint( $current ) + 1;
			$updated = null === $current
				? self::atomic_create_option( $key, $next )
				: self::atomic_replace_option_value( $key, $current, $next );
			if ( $updated ) { return $next; }
		}
		// Fail closed even when the monotonic CAS cannot complete: the diagnostic
		// flag remains set and every queue reader rejects the Generation.
		update_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '1', false );
		return self::source_inventory_epoch();
	}

	/**
	 * Begin one lifecycle projection mutation while leaving readers fail-closed.
	 *
	 * The active manifest advances only after its exact obligation shard has been
	 * persisted. A request interrupted between these writes therefore leaves an
	 * epoch mismatch instead of exposing a silently stale queue.
	 */
	private static function inventory_store_begin_projection_mutation( string $operation ): array {
		$lease = self::inventory_store_acquire_projection_lease( $operation );
		if ( empty( $lease['success'] ) ) {
			return $lease;
		}
		$manifest = self::active_inventory_manifest();
		$current_epoch = self::inventory_store_projection_epoch();
		if ( $manifest && absint( $manifest['obligation_projection_epoch'] ?? 0 ) !== $current_epoch ) {
			self::inventory_store_release_projection_lease( $lease );
			return array( 'success' => false, 'retryable' => false, 'code' => 'inventory_projection_rebuild_required', 'message' => 'The active obligation projection did not complete its prior lifecycle mutation.' );
		}
		$next_epoch = $current_epoch + 1;
		update_option( self::OPTION_OBLIGATION_PROJECTION_EPOCH, $next_epoch, false );
		if ( self::inventory_store_projection_epoch() !== $next_epoch ) {
			self::inventory_store_release_projection_lease( $lease );
			return array( 'success' => false, 'retryable' => false, 'code' => 'obligation_projection_epoch_write_failed', 'message' => 'The obligation projection epoch could not be advanced.' );
		}
		return array( 'success' => true, 'lease' => $lease, 'epoch' => $next_epoch );
	}

	/**
	 * Commit one Job write and its derived obligation through one deep Interface.
	 *
	 * The callback owns only the Job CAS/create operation and returns `stored_job`.
	 * Lease ordering, Epoch fencing, projection, counts and recovery stay local.
	 */
	private static function inventory_store_commit_job_projection( string $operation, callable $mutation ): array {
		$projection_mutation = self::inventory_store_begin_projection_mutation( $operation );
		if ( empty( $projection_mutation['success'] ) ) { return $projection_mutation; }
		try {
			$result = $mutation();
			$stored_job = is_array( $result ) && is_array( $result['stored_job'] ?? null ) ? $result['stored_job'] : array();
			if ( ! $stored_job ) {
				return array( 'success' => false, 'code' => 'job_write_projection_rebuild_required', 'message' => 'The Job write did not provide a recoverable obligation authority.' );
			}
			$projection = self::inventory_store_sync_job_obligation( $stored_job, absint( $projection_mutation['epoch'] ) );
			if ( empty( $projection['success'] ) ) {
				return array( 'success' => false, 'code' => 'job_stored_projection_rebuild_required', 'message' => 'The Translation Job was stored but its obligation projection did not complete.', 'job' => $stored_job, 'projection' => $projection );
			}
			$result['projection'] = $projection;
			return $result;
		} finally {
			self::inventory_store_release_projection_lease( (array) $projection_mutation['lease'] );
		}
	}

	/** Return one reader snapshot only when lifecycle authority and projection agree. */
	private static function active_inventory_projection_snapshot(): array {
		$manifest = self::active_inventory_manifest();
		if ( ! $manifest ) {
			$raw = get_option( self::OPTION_SOURCE_INVENTORY_ACTIVE, array() );
			return array( 'success' => false, 'code' => is_array( $raw ) && ! empty( $raw['generation'] ) ? 'inventory_store_rebuild_required' : 'inventory_not_built' );
		}
		$epoch = self::inventory_store_projection_epoch();
		if ( absint( $manifest['obligation_projection_epoch'] ?? 0 ) !== $epoch ) {
			return array( 'success' => false, 'code' => 'inventory_projection_rebuild_required' );
		}
		$source_epoch = self::source_inventory_epoch();
		if ( absint( $manifest['source_inventory_epoch'] ?? 0 ) !== $source_epoch ) {
			return array( 'success' => false, 'code' => 'inventory_rebuild_required' );
		}
		if ( ! hash_equals( (string) ( $manifest['inventory_input_signature'] ?? '' ), self::source_inventory_input_signature() ) ) {
			return array( 'success' => false, 'code' => 'inventory_rebuild_required' );
		}
		if ( '1' === get_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '1' ) ) { return array( 'success' => false, 'code' => 'inventory_rebuild_required' ); }
		return array( 'success' => true, 'manifest' => $manifest, 'epoch' => $epoch, 'source_epoch' => $source_epoch );
	}

	/** Prove a reader did not cross a Generation activation or projection mutation. */
	private static function inventory_store_projection_snapshot_is_current( array $manifest, int $epoch ): bool {
		$current = self::active_inventory_manifest();
		return $current
			&& (string) ( $current['generation'] ?? '' ) === (string) ( $manifest['generation'] ?? '' )
			&& absint( $current['obligation_projection_epoch'] ?? 0 ) === $epoch
			&& self::inventory_store_projection_epoch() === $epoch
			&& absint( $current['source_inventory_epoch'] ?? 0 ) === self::source_inventory_epoch()
			&& '1' !== get_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '1' );
	}

	/** Bind pagination to one immutable reader view without exposing store details. */
	private static function inventory_store_snapshot_token( array $manifest, string $kind ): string {
		$payload = array(
			'generation' => (string) ( $manifest['generation'] ?? '' ),
			'schema' => self::SOURCE_INVENTORY_SCHEMA_VERSION,
			'projection_epoch' => absint( $manifest['obligation_projection_epoch'] ?? 0 ),
			'source_epoch' => absint( $manifest['source_inventory_epoch'] ?? 0 ),
			'kind' => sanitize_key( $kind ),
		);
		$encoded = rtrim( strtr( base64_encode( wp_json_encode( $payload ) ?: '' ), '+/', '-_' ), '=' );
		return $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'nonce' ) );
	}

	private static function inventory_store_validate_snapshot_token( string $token, array $manifest, string $kind ): array {
		$parts = explode( '.', trim( $token ), 2 );
		if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'nonce' ) ), $parts[1] ) ) {
			return array( 'success' => false, 'code' => 'inventory_snapshot_invalid' );
		}
		$padding = strlen( $parts[0] ) % 4;
		$decoded = base64_decode( strtr( $parts[0] . ( $padding ? str_repeat( '=', 4 - $padding ) : '' ), '-_', '+/' ), true );
		$payload = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
		$expected = array(
			'generation' => (string) ( $manifest['generation'] ?? '' ),
			'schema' => self::SOURCE_INVENTORY_SCHEMA_VERSION,
			'projection_epoch' => absint( $manifest['obligation_projection_epoch'] ?? 0 ),
			'source_epoch' => absint( $manifest['source_inventory_epoch'] ?? 0 ),
			'kind' => sanitize_key( $kind ),
		);
		return is_array( $payload ) && $payload === $expected
			? array( 'success' => true )
			: array( 'success' => false, 'retryable' => true, 'code' => 'inventory_snapshot_stale' );
	}

	/** Synchronize the exact persisted obligation owned by a Translation Job. */
	private static function inventory_store_sync_job_obligation( array $job, int $projection_epoch ): array {
		$manifest = self::active_inventory_manifest();
		$source_id = absint( $job['source_id'] ?? 0 );
		$language = sanitize_key( (string) ( $job['target_language'] ?? '' ) );
		if ( ! $manifest ) {
			return array( 'success' => true, 'tracked' => false );
		}
		if ( $source_id < 1 || '' === $language ) {
			return array( 'success' => false, 'code' => 'obligation_projection_binding_invalid' );
		}

		$generation = (string) $manifest['generation'];
		$index = get_option( self::inventory_store_index_name( $generation ), array() );
		$lookup_key = $source_id . ':' . $language;
		$binding = is_array( $index ) && is_array( $index['obligation_lookup'] ?? null ) ? ( $index['obligation_lookup'][ $lookup_key ] ?? null ) : null;
		if ( ! is_array( $binding ) ) {
			return array( 'success' => false, 'code' => 'obligation_projection_missing' );
		}
		$shard = isset( $binding['shard'] ) ? (int) $binding['shard'] : -1;
		$row_index = isset( $binding['row'] ) ? (int) $binding['row'] : -1;
		if ( $shard < 0 || $row_index < 0 || $row_index >= self::INVENTORY_STORE_SHARD_SIZE ) {
			return array( 'success' => false, 'code' => 'obligation_projection_binding_stale' );
		}
		$name = self::inventory_store_shard_name( $generation, 'obligation', $shard );
		$rows = get_option( $name, null );
		$row = is_array( $rows ) && isset( $rows[ $row_index ] ) && is_array( $rows[ $row_index ] ) ? $rows[ $row_index ] : null;
		if ( ! is_array( $row ) || $source_id !== absint( $row['source_id'] ?? 0 ) || $language !== sanitize_key( (string) ( $row['target_language'] ?? '' ) ) ) {
			return array( 'success' => false, 'code' => 'obligation_projection_binding_stale' );
		}

		$projection = self::project_translation_obligation( $source_id, $language, (string) ( $row['source_revision'] ?? '' ), (string) ( $row['publication_surface_contract_revision'] ?? '' ), $job );
		$old_state = sanitize_key( (string) ( $rows[ $row_index ]['state'] ?? '' ) );
		foreach ( array( 'state', 'job_id', 'translation_id', 'publication_surface_contract_revision', 'current_publication_surface_contract_revision' ) as $field ) {
			if ( array_key_exists( $field, $projection ) ) {
				$rows[ $row_index ][ $field ] = $projection[ $field ];
			} else {
				unset( $rows[ $row_index ][ $field ] );
			}
		}
		$rows[ $row_index ]['updated_gmt'] = gmdate( 'Y-m-d H:i:s' );
		update_option( $name, array_values( $rows ), false );
		$stored_rows = get_option( $name, array() );
		if ( ! is_array( $stored_rows ) || (string) ( $stored_rows[ $row_index ]['state'] ?? '' ) !== (string) ( $projection['state'] ?? '' ) ) {
			return array( 'success' => false, 'code' => 'obligation_projection_shard_write_failed' );
		}
		$index['obligation_shard_digests'][ $shard ] = hash( 'sha256', wp_json_encode( array_values( $stored_rows ) ) ?: '' );
		$old_unresolved = 'published_verified' !== $old_state;
		$new_unresolved = 'published_verified' !== (string) ( $projection['state'] ?? '' );
		if ( $old_unresolved !== $new_unresolved ) {
			$index['unresolved_shard_counts'][ $shard ] = max( 0, absint( $index['unresolved_shard_counts'][ $shard ] ?? 0 ) + ( $new_unresolved ? 1 : -1 ) );
			$source_type = sanitize_key( (string) ( $rows[ $row_index ]['source_post_type'] ?? '' ) );
			if ( in_array( $source_type, array( 'page', 'post' ), true ) ) {
				$index['unresolved_source_type_shard_counts'][ $source_type ][ $shard ] = max( 0, absint( $index['unresolved_source_type_shard_counts'][ $source_type ][ $shard ] ?? 0 ) + ( $new_unresolved ? 1 : -1 ) );
			}
		}
		update_option( self::inventory_store_index_name( $generation ), $index, false );
		$stored_index = get_option( self::inventory_store_index_name( $generation ), array() );
		if ( ! is_array( $stored_index ) || (string) ( $stored_index['obligation_shard_digests'][ $shard ] ?? '' ) !== (string) $index['obligation_shard_digests'][ $shard ] ) {
			return array( 'success' => false, 'code' => 'obligation_projection_index_write_failed' );
		}

		$new_state = sanitize_key( (string) ( $projection['state'] ?? '' ) );
		$counts = is_array( $manifest['state_counts'] ?? null ) ? $manifest['state_counts'] : array();
		if ( $old_state !== $new_state ) {
			if ( '' !== $old_state ) { $counts[ $old_state ] = max( 0, absint( $counts[ $old_state ] ?? 0 ) - 1 ); }
			if ( '' !== $new_state ) { $counts[ $new_state ] = 1 + absint( $counts[ $new_state ] ?? 0 ); }
		}
		$manifest['state_counts'] = $counts;
		$manifest['inventory_index_digest'] = hash( 'sha256', wp_json_encode( $index ) ?: '' );
		$manifest['obligation_projection_epoch'] = $projection_epoch;
		$manifest['obligation_projection_updated_at'] = gmdate( 'c' );
		update_option( self::OPTION_SOURCE_INVENTORY_ACTIVE, $manifest, false );
		$current = get_option( self::OPTION_SOURCE_INVENTORY_ACTIVE, array() );
		if ( ! is_array( $current ) || absint( $current['obligation_projection_epoch'] ?? 0 ) !== $projection_epoch ) {
			return array( 'success' => false, 'code' => 'obligation_projection_manifest_write_failed' );
		}
		return array( 'success' => true, 'tracked' => true, 'state' => $new_state, 'epoch' => $projection_epoch );
	}

	private static function source_inventory_ability_catalogue(): array {
		$cursor_schema = array(
			'type' => 'object',
			'properties' => array(
				'cursor' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100 ),
				'snapshot' => array( 'type' => 'string', 'maxLength' => 1024 ),
			),
			'additionalProperties' => false,
		);
		$obligation_cursor_schema = $cursor_schema;
		$obligation_cursor_schema['properties']['source_type'] = self::inventory_source_type_scope_schema();
		return array(
			'devenia-workflow/rebuild-source-inventory' => array(
				'label' => 'Rebuild Authoritative Source Inventory',
				'description' => 'Builds and atomically activates a complete generation of publicly visible source pages/posts and every target-language obligation.',
				'input_schema' => array( 'type' => 'object', 'required' => array( 'confirm_rebuild' ), 'properties' => array( 'confirm_rebuild' => array( 'type' => 'boolean', 'enum' => array( true ) ), 'resume_token' => array( 'type' => 'string', 'maxLength' => 128 ) ), 'additionalProperties' => false ),
				'output_schema' => self::generic_output_schema(),
				'execute_callback' => function ( $input = array() ) { return self::run_ability_operation( 'rebuild_source_inventory', $input ); },
				'meta' => self::ability_meta( false, false, true ),
			),
			'devenia-workflow/source-inventory' => array(
				'label' => 'Read Authoritative Source Inventory',
				'description' => 'Reads the active inventory generation with a stable source-ID cursor, including structured exclusions.',
				'input_schema' => $cursor_schema,
				'output_schema' => self::generic_output_schema(),
				'execute_callback' => function ( $input = array() ) { return self::run_ability_operation( 'source_inventory', $input ); },
				'meta' => self::ability_meta( true, false, true ),
			),
			'devenia-workflow/translation-obligation-queue' => array(
				'label' => 'Read Complete Translation Obligation Queue',
				'description' => 'Reads unresolved obligations for an explicit source type from the active whole-site projection with a scope-bound stable cursor.',
				'input_schema' => $obligation_cursor_schema,
				'output_schema' => self::generic_output_schema(),
				'execute_callback' => function ( $input = array() ) { return self::run_ability_operation( 'translation_obligation_queue', $input ); },
				'meta' => self::ability_meta( true, false, true ),
			),
			'devenia-workflow/translation-job-next' => array(
				'label' => 'Discover Next Complete-Inventory Translation Job',
				'description' => 'Selects the next unresolved obligation within an explicit source type, prioritizing same-type unresolved internal-link dependencies before their referring source, and delegates job creation to the current Translation Job discover lifecycle.',
				'input_schema' => array( 'type' => 'object', 'properties' => array( 'source_type' => self::inventory_source_type_scope_schema(), 'observability_label' => array( 'type' => 'string' ) ), 'additionalProperties' => false ),
				'output_schema' => self::generic_output_schema(),
				'execute_callback' => function ( $input = array() ) { return self::run_ability_operation( 'translation_job_next', $input ); },
				'meta' => self::ability_meta( false, false, true ),
			),
			'devenia-workflow/translation-exhaustion-proof' => array(
				'label' => 'Prove Whole-Site Translation Exhaustion',
				'description' => 'Proves a clean complete generation, exact source-language arithmetic, and zero unresolved obligations for an explicit source type.',
				'input_schema' => array( 'type' => 'object', 'properties' => array( 'source_type' => self::inventory_source_type_scope_schema(), 'refresh' => array( 'type' => 'boolean', 'default' => true ) ), 'additionalProperties' => false ),
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
			if ( $post && ! self::is_translatable_post_type( (string) $post->post_type ) ) { return; }
			if ( $post && self::is_translation_post( $post_id ) && ( ! empty( self::$translation_job_internal_identity ) || self::$inventory_authorized_mutation_depth > 0 ) ) { return; }
		}
		self::source_inventory_advance_epoch();
		update_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '1', false );
	}

	/** Invalidate source/translation authority when an owned taxonomy relation changes. */
	public static function mark_source_inventory_dirty_on_object_terms( int $object_id, $terms, array $term_taxonomy_ids, string $taxonomy, bool $append, array $old_term_taxonomy_ids ): void {
		unset( $terms, $term_taxonomy_ids, $taxonomy, $append, $old_term_taxonomy_ids );
		self::mark_source_inventory_dirty( $object_id );
	}

	/** A term edit can revise the publication surface of many sources at once. */
	public static function mark_source_inventory_dirty_on_term_change( int $term_id, int $term_taxonomy_id, string $taxonomy ): void {
		unset( $term_id, $term_taxonomy_id );
		$object = get_taxonomy( $taxonomy );
		if ( $object && array_intersect( (array) $object->object_type, self::translatable_post_types() ) && empty( self::$translation_job_internal_identity ) && 0 === self::$inventory_authorized_mutation_depth ) { self::mark_source_inventory_dirty(); }
	}

	/** A term deletion has the same many-source authority effect as a rename. */
	public static function mark_source_inventory_dirty_on_term_delete( int $term_id, int $term_taxonomy_id, string $taxonomy, $deleted_term, array $object_ids ): void {
		unset( $term_id, $term_taxonomy_id, $deleted_term, $object_ids );
		self::mark_source_inventory_dirty_on_term_change( 0, 0, $taxonomy );
	}

	private static function rebuild_source_inventory( array $input = array() ): array {
		self::install_source_inventory_schema();
		$token = sanitize_text_field( (string) ( $input['resume_token'] ?? '' ) );
		$state = get_option( self::OPTION_SOURCE_INVENTORY_REBUILD, array() );
		if ( '' === $token ) {
			if ( is_array( $state ) && ! empty( $state['token'] ) && absint( $state['expires_at'] ?? 0 ) >= time() ) {
				return array( 'success' => true, 'completed' => false, 'resume_token' => (string) $state['token'], 'progress' => self::inventory_rebuild_progress( $state ) );
			}
			if ( is_array( $state ) && ! empty( $state['generation'] ) ) { self::inventory_store_delete_generation( (string) $state['generation'] ); }
			$state = self::inventory_rebuild_initialize();
			if ( empty( $state['success'] ) ) { return $state; }
			unset( $state['success'] );
			if ( ! update_option( self::OPTION_SOURCE_INVENTORY_REBUILD, $state, false ) ) {
				$stored = get_option( self::OPTION_SOURCE_INVENTORY_REBUILD, array() );
				if ( ! is_array( $stored ) || $stored !== $state ) { return array( 'success' => false, 'code' => 'inventory_rebuild_state_write_failed' ); }
			}
			$token = (string) $state['token'];
		} elseif ( ! is_array( $state ) || ! hash_equals( (string) ( $state['token'] ?? '' ), $token ) ) {
			return array( 'success' => false, 'code' => 'inventory_rebuild_resume_invalid' );
		}
		return self::inventory_rebuild_continue( $state );
	}

	/** Capture immutable rebuild inputs once; projection proceeds in bounded calls. */
	private static function inventory_rebuild_initialize(): array {
		$generation = gmdate( 'YmdHis' ) . '-' . substr( wp_generate_uuid4(), 0, 8 );
		return array( 'success' => true, 'token' => 'sir_' . substr( hash( 'sha256', wp_generate_uuid4() . '|' . microtime( true ) ), 0, 32 ), 'generation' => $generation, 'projection_epoch' => self::inventory_store_projection_epoch(), 'source_epoch' => self::source_inventory_epoch(), 'input_signature' => self::source_inventory_input_signature(), 'source_signature' => '', 'phase' => 'scan', 'scan_page' => 1, 'included' => 0, 'included_by_source_type' => array( 'page' => 0, 'post' => 0 ), 'excluded' => 0, 'reasons' => array(), 'source_rows' => array(), 'inventory_rows' => array(), 'languages' => array_keys( self::target_languages() ), 'source_offset' => 0, 'obligation_rows' => array(), 'state_counts' => array(), 'expires_at' => time() + HOUR_IN_SECONDS );
	}

	private static function inventory_rebuild_progress( array $state ): array {
		$total = count( (array) ( $state['source_rows'] ?? array() ) );
		$done = min( $total, absint( $state['source_offset'] ?? 0 ) );
		return array( 'phase' => (string) ( $state['phase'] ?? 'project' ), 'source_candidates_scanned' => count( (array) ( $state['inventory_rows'] ?? array() ) ), 'sources_projected' => $done, 'sources_total' => $total, 'obligations_projected' => count( (array) ( $state['obligation_rows'] ?? array() ) ) );
	}

	/** Project at most five sources per request, then atomically activate the completed Generation. */
	private static function inventory_rebuild_continue( array $state ): array {
		if ( self::inventory_store_projection_epoch() !== absint( $state['projection_epoch'] ?? 0 ) || self::source_inventory_epoch() !== absint( $state['source_epoch'] ?? 0 ) || ! hash_equals( (string) ( $state['input_signature'] ?? '' ), self::source_inventory_input_signature() ) ) {
			self::atomic_delete_option_value( self::OPTION_SOURCE_INVENTORY_REBUILD, $state );
			return array( 'success' => false, 'retryable' => true, 'code' => 'inventory_changed_during_rebuild' );
		}
		$before = $state;
		if ( 'scan' === (string) ( $state['phase'] ?? '' ) ) {
			$page = max( 1, absint( $state['scan_page'] ?? 1 ) );
			$query = new WP_Query( array( 'post_type' => self::translatable_post_types(), 'post_status' => array_keys( get_post_stati() ), 'posts_per_page' => 50, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'no_found_rows' => true ) );
			$ids = array_map( 'absint', $query->posts );
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( ! $post ) { continue; }
				$reason = self::is_translation_post( $id ) ? 'translation' : ( 'publish' !== $post->post_status ? 'status_' . sanitize_key( $post->post_status ) : ( '' !== (string) $post->post_password ? 'password_protected' : ( ! is_post_publicly_viewable( $post ) ? 'not_publicly_viewable' : '' ) ) );
				$applicable = '' === $reason;
				$revision = $applicable ? self::source_publication_surface_revision( $post ) : '';
				$contract_revision = $applicable ? self::translation_job_publication_surface_contract_revision( $post ) : '';
				$state['inventory_rows'][] = array( 'generation' => (string) $state['generation'], 'source_id' => $id, 'post_type' => $post->post_type, 'post_status' => $post->post_status, 'applicable' => $applicable ? 1 : 0, 'exclusion_reason' => $reason, 'source_revision' => $revision, 'publication_surface_contract_revision' => $contract_revision, 'modified_gmt' => '0000-00-00 00:00:00' === $post->post_modified_gmt ? gmdate( 'Y-m-d H:i:s' ) : $post->post_modified_gmt );
				if ( $applicable ) { ++$state['included']; $state['included_by_source_type'][ (string) $post->post_type ] = 1 + absint( $state['included_by_source_type'][ (string) $post->post_type ] ?? 0 ); $state['source_rows'][] = array( $id, $revision, $contract_revision, (string) $post->post_type ); }
				else { ++$state['excluded']; $state['reasons'][ $reason ] = 1 + absint( $state['reasons'][ $reason ] ?? 0 ); }
			}
			$state['expires_at'] = time() + HOUR_IN_SECONDS;
			if ( 50 === count( $ids ) ) {
				$state['scan_page'] = $page + 1;
				if ( ! self::atomic_replace_option_value( self::OPTION_SOURCE_INVENTORY_REBUILD, $before, $state ) ) { return array( 'success' => false, 'retryable' => true, 'code' => 'inventory_rebuild_resume_conflict' ); }
				return array( 'success' => true, 'completed' => false, 'resume_token' => (string) $state['token'], 'progress' => self::inventory_rebuild_progress( $state ) );
			}
			$state['phase'] = 'project';
			$state['source_signature'] = hash( 'sha256', wp_json_encode( (array) $state['source_rows'] ) ?: '' );
			$before = get_option( self::OPTION_SOURCE_INVENTORY_REBUILD, array() );
			if ( ! is_array( $before ) || ! hash_equals( (string) ( $before['token'] ?? '' ), (string) $state['token'] ) ) { return array( 'success' => false, 'retryable' => true, 'code' => 'inventory_rebuild_resume_conflict' ); }
		}
		$source_rows = (array) $state['source_rows'];
		$offset = absint( $state['source_offset'] ?? 0 );
		foreach ( array_slice( $source_rows, $offset, 5 ) as $source_row ) {
			foreach ( (array) $state['languages'] as $language ) {
				$source = get_post( absint( $source_row[0] ?? 0 ) );
				$contract = $source instanceof WP_Post ? self::translation_job_publication_surface_contract_revision( $source, (string) $language ) : '';
				$projection = self::project_translation_obligation( absint( $source_row[0] ?? 0 ), (string) $language, (string) ( $source_row[1] ?? '' ), $contract );
				$state['state_counts'][ $projection['state'] ] = 1 + absint( $state['state_counts'][ $projection['state'] ] ?? 0 );
				$state['obligation_rows'][] = array_merge( $projection, array( 'source_post_type' => sanitize_key( (string) ( $source_row[3] ?? '' ) ), 'obligation_id' => count( $state['obligation_rows'] ) + 1, 'generation' => (string) $state['generation'], 'updated_gmt' => gmdate( 'Y-m-d H:i:s' ) ) );
			}
			++$state['source_offset'];
		}
		$state['expires_at'] = time() + HOUR_IN_SECONDS;
		if ( absint( $state['source_offset'] ) < count( $source_rows ) ) {
			if ( ! self::atomic_replace_option_value( self::OPTION_SOURCE_INVENTORY_REBUILD, $before, $state ) ) { return array( 'success' => false, 'retryable' => true, 'code' => 'inventory_rebuild_resume_conflict' ); }
			return array( 'success' => true, 'completed' => false, 'resume_token' => (string) $state['token'], 'progress' => self::inventory_rebuild_progress( $state ) );
		}
		$manifest = array( 'generation' => (string) $state['generation'], 'completed_at' => gmdate( 'c' ), 'included_sources' => absint( $state['included'] ), 'included_sources_by_post_type' => array_map( 'absint', (array) $state['included_by_source_type'] ), 'excluded_sources' => absint( $state['excluded'] ), 'excluded_by_reason' => (array) $state['reasons'], 'target_languages' => count( (array) $state['languages'] ), 'target_language_keys' => (array) $state['languages'], 'projected_obligations' => absint( $state['included'] ) * count( (array) $state['languages'] ), 'source_signature' => (string) $state['source_signature'], 'inventory_input_signature' => (string) $state['input_signature'], 'state_counts' => (array) $state['state_counts'], 'obligation_projection_epoch' => absint( $state['projection_epoch'] ), 'source_inventory_epoch' => absint( $state['source_epoch'] ) );
		$generation_index = self::inventory_store_write_generation( (string) $state['generation'], (array) $state['inventory_rows'], (array) $state['obligation_rows'] );
		$manifest['inventory_index_digest'] = hash( 'sha256', wp_json_encode( $generation_index ) ?: '' );
		$activation_lease = self::inventory_store_acquire_projection_lease( 'activate_generation' );
		if ( empty( $activation_lease['success'] ) ) { return $activation_lease; }
		try {
			if ( self::inventory_store_projection_epoch() !== absint( $state['projection_epoch'] ) ) {
				self::inventory_store_delete_generation( (string) $state['generation'] );
				return array( 'success' => false, 'retryable' => true, 'code' => 'inventory_changed_during_rebuild', 'message' => 'A Translation Job changed while the Inventory Generation was being built. Retry the rebuild.' );
			}
			if ( self::source_inventory_epoch() !== absint( $state['source_epoch'] ) || ! hash_equals( (string) $manifest['source_signature'], self::current_source_inventory_signature() ) || ! hash_equals( (string) $manifest['inventory_input_signature'], self::source_inventory_input_signature() ) ) {
				update_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '1', false );
				self::inventory_store_delete_generation( (string) $state['generation'] );
				return array( 'success' => false, 'retryable' => true, 'code' => 'source_changed_during_rebuild', 'message' => 'Source content changed while the Inventory Generation was being built. Retry the rebuild.' );
			}
			update_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '0', false );
			if ( self::source_inventory_epoch() !== absint( $state['source_epoch'] ) ) {
				update_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '1', false );
				self::inventory_store_delete_generation( (string) $state['generation'] );
				return array( 'success' => false, 'retryable' => true, 'code' => 'source_changed_during_rebuild', 'message' => 'Source content changed while the Inventory Generation was being activated. Retry the rebuild.' );
			}
			$previous = get_option( self::OPTION_SOURCE_INVENTORY_ACTIVE, array() );
			update_option( self::OPTION_SOURCE_INVENTORY_ACTIVE, $manifest, false );
			self::atomic_delete_option_value( self::OPTION_SOURCE_INVENTORY_REBUILD, $before );
		} finally {
			self::inventory_store_release_projection_lease( $activation_lease );
		}
		if ( is_array( $previous ) && ! empty( $previous['generation'] ) && (string) $state['generation'] !== (string) $previous['generation'] ) {
			self::inventory_store_delete_generation( (string) $previous['generation'] );
		}
		return array( 'success' => true, 'completed' => true, 'inventory' => $manifest );
	}

	private static function project_translation_obligation( int $source_id, string $language, string $revision, string $contract_revision = '', array $job_override = array() ): array {
		$source = get_post( $source_id );
		$translation_id = self::translation_index_id_for_source_language( $source_id, $language, self::translation_workflow_post_statuses( false ) );
		$job_id = self::translation_job_id( $source_id, $language, $revision );
		$job = $job_override
			&& $job_id === (string) ( $job_override['job_id'] ?? '' )
			&& $source_id === absint( $job_override['source_id'] ?? 0 )
			&& $language === sanitize_key( (string) ( $job_override['target_language'] ?? '' ) )
			? $job_override
			: self::translation_job_get_job( $job_id );
		$state = 'missing';
		$current_revision = $source instanceof WP_Post ? self::source_publication_surface_revision( $source ) : '';
		$current_contract_revision = $source instanceof WP_Post ? self::translation_job_publication_surface_contract_revision( $source, $language ) : '';
		if ( '' === $current_revision || ! hash_equals( $revision, $current_revision ) ) {
			return array( 'source_id' => $source_id, 'target_language' => $language, 'state' => 'source_surface_stale', 'job_id' => $job_id, 'translation_id' => $translation_id, 'source_revision' => $revision, 'publication_surface_contract_revision' => $contract_revision );
		}
		if ( '' === $contract_revision || '' === $current_contract_revision || ! hash_equals( $contract_revision, $current_contract_revision ) ) {
			return array( 'source_id' => $source_id, 'target_language' => $language, 'state' => 'publication_contract_stale', 'job_id' => $job_id, 'translation_id' => $translation_id, 'source_revision' => $revision, 'publication_surface_contract_revision' => $contract_revision, 'current_publication_surface_contract_revision' => $current_contract_revision );
		}
		if ( $job ) {
			$job_contract = self::translation_job_publication_surface_contract_state( $job );
			if (
				empty( $job_contract['success'] )
				|| ! empty( $job_contract['contract_stale'] )
				|| ! hash_equals( $contract_revision, (string) ( $job['publication_surface_contract_revision'] ?? '' ) )
			) {
				return array( 'source_id' => $source_id, 'target_language' => $language, 'state' => 'publication_contract_stale', 'job_id' => $job_id, 'translation_id' => $translation_id, 'source_revision' => $revision, 'publication_surface_contract_revision' => $contract_revision, 'current_publication_surface_contract_revision' => $current_contract_revision, 'job_publication_surface_contract_revision' => (string) ( $job['publication_surface_contract_revision'] ?? '' ), 'contract_evidence' => $job_contract );
			}
			$state = sanitize_key( (string) ( $job['status'] ?? 'queued' ) );
			if ( 'published' === $state ) {
				$authority = self::translation_job_validate_published_authority( $job, $translation_id, true );
				if ( empty( $authority['success'] ) ) {
					$state = 'publication_authority_stale';
				} elseif ( ! empty( $job['live_verification_passed'] ) ) {
					$source_media = self::publication_featured_image_revision_identity( $source );
					$translation_media = $translation_id > 0 ? self::publication_featured_image_revision_identity( $translation_id ) : array();
					$artifact = isset( $authority['artifact_record'] ) && is_array( $authority['artifact_record'] ) ? $authority['artifact_record'] : array();
					$approved_media = (array) ( $artifact['surface_manifest']['media']['featured_image'] ?? array() );
					$media_matches = self::translation_job_canonicalize( $source_media ) === self::translation_job_canonicalize( $translation_media )
						&& self::translation_job_canonicalize( $source_media ) === self::translation_job_canonicalize( $approved_media );
					$state = $media_matches ? 'published_verified' : 'visible_media_stale';
				}
			}
		}
		if ( ! $job && $translation_id > 0 ) {
			$translation = get_post( $translation_id );
			$stored_hash = (string) get_post_meta( $translation_id, self::META_SOURCE_HASH, true );
			$state = $translation && 'publish' === $translation->post_status && hash_equals( $revision, $stored_hash ) ? 'review_required' : 'stale';
		}
		return array( 'source_id' => $source_id, 'target_language' => $language, 'state' => $state, 'job_id' => $job_id, 'translation_id' => $translation_id, 'source_revision' => $revision, 'publication_surface_contract_revision' => $contract_revision );
	}

	private static function source_inventory( array $input ): array {
		$manifest = self::active_inventory_manifest();
		if ( ! $manifest ) { $raw = get_option( self::OPTION_SOURCE_INVENTORY_ACTIVE, array() ); return array( 'success' => false, 'code' => is_array( $raw ) && ! empty( $raw['generation'] ) ? 'inventory_store_rebuild_required' : 'inventory_not_built' ); }
		$cursor = absint( $input['cursor'] ?? 0 ); $limit = min( 500, max( 1, absint( $input['limit'] ?? 100 ) ) );
		$snapshot_token = sanitize_text_field( (string) ( $input['snapshot'] ?? '' ) );
		if ( $cursor > 0 && '' === $snapshot_token ) { return array( 'success' => false, 'code' => 'inventory_snapshot_required' ); }
		if ( '' !== $snapshot_token ) {
			$snapshot_validation = self::inventory_store_validate_snapshot_token( $snapshot_token, $manifest, 'source' );
			if ( empty( $snapshot_validation['success'] ) ) { return $snapshot_validation; }
		}
		$index = get_option( self::inventory_store_index_name( (string) $manifest['generation'] ), array() );
		$offset = 0;
		if ( $cursor > 0 ) {
			$binding = is_array( $index['source_lookup'][ (string) $cursor ] ?? null ) ? $index['source_lookup'][ (string) $cursor ] : array();
			if ( ! $binding ) { return array( 'success' => false, 'code' => 'inventory_cursor_invalid' ); }
			$offset = ( absint( $binding['shard'] ?? 0 ) * self::INVENTORY_STORE_SHARD_SIZE ) + absint( $binding['row'] ?? 0 ) + 1;
		}
		$store = self::inventory_store_read_row_range( $manifest, 'source', $offset, $limit );
		if ( empty( $store['success'] ) ) { return $store; }
		$rows = $store['rows'];
		if ( ! empty( $store['terminal'] ) ) {
			$complete_store = self::inventory_store_read_rows_strict( (string) $manifest['generation'], 'source' );
			if ( empty( $complete_store['success'] ) ) { return $complete_store; }
		}
		$next = $rows ? absint( end( $rows )['source_id'] ) : 0;
		$dirty = absint( $manifest['source_inventory_epoch'] ?? 0 ) !== self::source_inventory_epoch()
			|| ! hash_equals( (string) ( $manifest['inventory_input_signature'] ?? '' ), self::source_inventory_input_signature() )
			|| '1' === get_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '1' );
		return array( 'success' => true, 'inventory' => $manifest, 'dirty' => $dirty, 'items' => $rows, 'snapshot' => self::inventory_store_snapshot_token( $manifest, 'source' ), 'next_cursor' => empty( $store['terminal'] ) ? $next : null );
	}

	/** Reconcile persisted dirty state with the complete live source signature. */
	private static function source_inventory_refresh_dirty_state( array $manifest ): bool {
		$dirty = absint( $manifest['source_inventory_epoch'] ?? 0 ) !== self::source_inventory_epoch()
			|| ! hash_equals( (string) ( $manifest['source_signature'] ?? '' ), self::current_source_inventory_signature() )
			|| ! hash_equals( (string) ( $manifest['inventory_input_signature'] ?? '' ), self::source_inventory_input_signature() );
		if ( $dirty && absint( $manifest['source_inventory_epoch'] ?? 0 ) === self::source_inventory_epoch() ) {
			self::source_inventory_advance_epoch();
		}
		if ( $dirty ) { update_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '1', false ); }
		return $dirty;
	}

	private static function current_source_inventory_signature(): string {
		$post_types = self::translatable_post_types();
		$query = new WP_Query( array( 'post_type' => $post_types, 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'no_found_rows' => true, 'has_password' => false ) );
		$ids = array_map( 'absint', $query->posts );
		$rows = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post && ! self::is_translation_post( $id ) && is_post_publicly_viewable( $post ) ) { $rows[] = array( $id, self::source_publication_surface_revision( $post ), self::translation_job_publication_surface_contract_revision( $post ), (string) $post->post_type ); }
		}
		return hash( 'sha256', wp_json_encode( $rows ) );
	}

	private static function translation_obligation_queue( array $input ): array {
		$snapshot = self::active_inventory_projection_snapshot();
		if ( empty( $snapshot['success'] ) ) { return $snapshot; }
		$manifest = $snapshot['manifest'];
		$epoch = absint( $snapshot['epoch'] );
		$cursor = absint( $input['cursor'] ?? 0 ); $limit = min( 500, max( 1, absint( $input['limit'] ?? 100 ) ) );
		$source_type = self::inventory_source_type_scope( $input['source_type'] ?? 'all' );
		$snapshot_kind = self::inventory_obligation_snapshot_kind( $source_type );
		$snapshot_token = sanitize_text_field( (string) ( $input['snapshot'] ?? '' ) );
		if ( $cursor > 0 && '' === $snapshot_token ) { return array( 'success' => false, 'code' => 'inventory_snapshot_required' ); }
		if ( '' !== $snapshot_token ) {
			$snapshot_validation = self::inventory_store_validate_snapshot_token( $snapshot_token, $manifest, $snapshot_kind );
			if ( empty( $snapshot_validation['success'] ) ) { return $snapshot_validation; }
		}
		$seek = self::inventory_store_seek_unresolved( $manifest, $cursor, $limit, $source_type );
		if ( empty( $seek['success'] ) ) { return $seek; }
		$page_ids = $seek['ids'];
		$store = self::inventory_store_read_bound_rows( $manifest, 'obligation', $page_ids );
		if ( empty( $store['success'] ) ) { return $store; }
		$rows = $store['rows'];
		if ( empty( $seek['has_more'] ) ) {
			$complete_store = self::inventory_store_read_rows_strict( (string) $manifest['generation'], 'obligation' );
			if ( empty( $complete_store['success'] ) ) { return $complete_store; }
		}
		if ( ! self::inventory_store_projection_snapshot_is_current( $manifest, $epoch ) ) { return array( 'success' => false, 'retryable' => true, 'code' => 'inventory_projection_changed' ); }
		$next = $rows ? absint( end( $rows )['obligation_id'] ) : 0;
		return array( 'success' => true, 'generation' => $manifest['generation'], 'source_type' => $source_type, 'items' => $rows, 'item_count' => count( $rows ), 'snapshot' => self::inventory_store_snapshot_token( $manifest, $snapshot_kind ), 'next_cursor' => ! empty( $seek['has_more'] ) ? $next : null );
	}

	private static function translation_job_next( array $input ): array {
		$snapshot = self::active_inventory_projection_snapshot();
		if ( empty( $snapshot['success'] ) ) { return array( 'success' => false, 'exhausted' => false, 'queue' => $snapshot ); }
		$manifest = $snapshot['manifest'];
		$epoch = absint( $snapshot['epoch'] );
		$index = get_option( self::inventory_store_index_name( (string) $manifest['generation'] ), array() );
		$source_type = self::inventory_source_type_scope( $input['source_type'] ?? 'all' );
		$seek = self::inventory_store_seek_unresolved( $manifest, 0, 1, $source_type );
		if ( empty( $seek['success'] ) ) { return array( 'success' => false, 'exhausted' => false, 'queue' => $seek ); }
		$unresolved_ids = $seek['ids'];
		if ( ! self::inventory_store_projection_snapshot_is_current( $manifest, $epoch ) ) { return array( 'success' => false, 'exhausted' => false, 'queue' => array( 'success' => false, 'retryable' => true, 'code' => 'inventory_projection_changed' ) ); }
		if ( empty( $unresolved_ids ) ) {
			$complete_store = self::inventory_store_read_rows_strict( (string) $manifest['generation'], 'obligation' );
			if ( empty( $complete_store['success'] ) ) { return array( 'success' => false, 'exhausted' => false, 'queue' => $complete_store ); }
			return array( 'success' => true, 'exhausted' => true, 'queue' => array( 'success' => true, 'generation' => $manifest['generation'], 'items' => array(), 'item_count' => 0 ) );
		}
		$selection = self::translation_job_dependency_ordered_selection( $manifest, $index, $unresolved_ids, $source_type );
		if ( empty( $selection['success'] ) ) { return array( 'success' => false, 'exhausted' => false, 'queue' => $selection ); }
		if ( ! self::inventory_store_projection_snapshot_is_current( $manifest, $epoch ) ) { return array( 'success' => false, 'exhausted' => false, 'queue' => array( 'success' => false, 'retryable' => true, 'code' => 'inventory_projection_changed' ) ); }
		$item = $selection['item'];
		$result = self::translation_job_discover( array( 'source_id' => absint( $item['source_id'] ), 'language' => sanitize_key( $item['target_language'] ), 'observability_label' => sanitize_text_field( (string) ( $input['observability_label'] ?? '' ) ) ) );
		return array( 'success' => ! empty( $result['success'] ), 'obligation' => $item, 'dependency_ordering' => $selection['dependency_ordering'], 'discover' => $result );
	}

	/**
	 * Select the deepest unresolved internal-link dependency of the first queued obligation.
	 *
	 * Stable queue order remains the fallback. Visiting-state cycle detection prevents
	 * mutually linked sources from recursing forever.
	 *
	 * @param array<int,array<string,mixed>> $items Unresolved obligations in stable store order.
	 * @return array{item:array<string,mixed>,dependency_ordering:array<string,mixed>}
	 */
	private static function translation_job_dependency_ordered_selection( array $manifest, array $index, array $unresolved_ids, string $source_type = 'all' ): array {
		$root_read = self::inventory_store_read_bound_rows( $manifest, 'obligation', array( $unresolved_ids[0] ) );
		if ( empty( $root_read['success'] ) || empty( $root_read['rows'][0] ) ) { return array( 'success' => false, 'code' => 'inventory_store_rebuild_required' ); }
		$root = $root_read['rows'][0];
		if ( 'all' !== $source_type && $source_type !== sanitize_key( (string) ( $root['source_post_type'] ?? '' ) ) ) { return array( 'success' => false, 'code' => 'inventory_store_rebuild_required' ); }
		$states = array();
		$chain = array();
		$cycles = array();
		$traversed = 0;
		$budget_exhausted = false;
		$store_failure = false;
		$resolve = function ( array $item, array $path ) use ( &$resolve, &$states, &$chain, &$cycles, &$traversed, &$budget_exhausted, &$store_failure, $manifest, $index, $source_type ): array {
			$source_id = absint( $item['source_id'] ?? 0 );
			$language = sanitize_key( (string) ( $item['target_language'] ?? '' ) );
			$key = $source_id . ':' . $language;
			if ( 'visiting' === ( $states[ $key ] ?? '' ) ) { $cycles[] = array_values( array_merge( $path, array( $source_id ) ) ); return $item; }
			if ( 'done' === ( $states[ $key ] ?? '' ) ) { return $item; }
			if ( ++$traversed > self::INVENTORY_DEPENDENCY_TRAVERSAL_LIMIT ) { $budget_exhausted = true; return $item; }
			$states[ $key ] = 'visiting';
			$source = get_post( $source_id );
			if ( $source instanceof WP_Post ) {
				foreach ( self::translation_job_link_policy( $source, $language ) as $link ) {
					$dependency_id = absint( $link['source_post_id'] ?? 0 );
					$dependency_key = $dependency_id . ':' . $language;
					$binding = $index['obligation_lookup'][ $dependency_key ] ?? null;
					if ( $dependency_id <= 0 || $dependency_id === $source_id || ! empty( $link['published_target_available'] ) || ! is_array( $binding ) ) { continue; }
					$dependency_obligation_id = ( (int) ( $binding['shard'] ?? -1 ) * self::INVENTORY_STORE_SHARD_SIZE ) + (int) ( $binding['row'] ?? -1 ) + 1;
					if ( $dependency_obligation_id < 1 ) { continue; }
					$dependency_read = self::inventory_store_read_bound_rows( $manifest, 'obligation', array( $dependency_obligation_id ) );
					if ( empty( $dependency_read['success'] ) || empty( $dependency_read['rows'][0] ) ) { $store_failure = true; continue; }
					$dependency_source_type = sanitize_key( (string) ( $dependency_read['rows'][0]['source_post_type'] ?? '' ) );
					if ( ! in_array( $dependency_source_type, array( 'page', 'post' ), true ) ) { $store_failure = true; continue; }
					if ( 'all' !== $source_type && $source_type !== $dependency_source_type ) { continue; }
					if ( 'published_verified' === (string) ( $dependency_read['rows'][0]['state'] ?? '' ) ) { continue; }
					$selected = $resolve( $dependency_read['rows'][0], array_merge( $path, array( $source_id ) ) );
					if ( absint( $selected['source_id'] ?? 0 ) !== $source_id ) {
						$chain[] = array( 'referrer_source_id' => $source_id, 'dependency_source_id' => absint( $selected['source_id'] ?? 0 ), 'target_language' => $language );
						$states[ $key ] = 'done';
						return $selected;
					}
				}
			}
			$states[ $key ] = 'done';
			return $item;
		};
		$selected = $resolve( $root, array() );
		if ( $store_failure ) { return array( 'success' => false, 'code' => 'inventory_store_rebuild_required' ); }
		return array(
			'success' => true,
			'item' => $selected,
			'dependency_ordering' => array(
				'applied' => absint( $selected['source_id'] ?? 0 ) !== absint( $root['source_id'] ?? 0 ),
				'root_source_id' => absint( $root['source_id'] ?? 0 ),
				'selected_source_id' => absint( $selected['source_id'] ?? 0 ),
				'chain' => array_reverse( $chain ),
				'cycles_skipped' => $cycles,
				'traversed' => min( $traversed, self::INVENTORY_DEPENDENCY_TRAVERSAL_LIMIT ),
				'budget_exhausted' => $budget_exhausted,
				'source_type' => $source_type,
			),
		);
	}

	private static function translation_exhaustion_proof( array $input = array() ): array {
		$source_type = self::inventory_source_type_scope( $input['source_type'] ?? 'all' );
		$manifest = self::active_inventory_manifest();
		if ( ! $manifest ) { $raw = get_option( self::OPTION_SOURCE_INVENTORY_ACTIVE, array() ); return array( 'success' => false, 'complete' => false, 'code' => is_array( $raw ) && ! empty( $raw['generation'] ) ? 'inventory_store_rebuild_required' : 'inventory_not_built' ); }
		if ( ! array_key_exists( 'refresh', $input ) || ! empty( $input['refresh'] ) ) { self::source_inventory_refresh_dirty_state( $manifest ); }
		$snapshot = self::active_inventory_projection_snapshot();
		if ( empty( $snapshot['success'] ) ) { return array_merge( array( 'complete' => false ), $snapshot ); }
		$manifest = $snapshot['manifest'];
		$epoch = absint( $snapshot['epoch'] );
		$generation = (string) $manifest['generation'];
		$store = self::inventory_store_read_rows_strict( $generation, 'obligation' );
		if ( empty( $store['success'] ) ) { return array_merge( array( 'complete' => false ), $store ); }
		$source_store = self::inventory_store_read_rows_strict( $generation, 'source' );
		if ( empty( $source_store['success'] ) ) { return array_merge( array( 'complete' => false ), $source_store ); }
		$obligations = 'all' === $source_type ? $store['rows'] : array_values( array_filter( $store['rows'], static function ( $row ) use ( $source_type ) { return is_array( $row ) && $source_type === sanitize_key( (string) ( $row['source_post_type'] ?? '' ) ); } ) );
		$sources = 'all' === $source_type ? $source_store['rows'] : array_values( array_filter( $source_store['rows'], static function ( $row ) use ( $source_type ) { return is_array( $row ) && $source_type === sanitize_key( (string) ( $row['post_type'] ?? '' ) ); } ) );
		$total = count( $obligations );
		$unresolved = count( array_filter( $obligations, static function ( $row ) { return is_array( $row ) && 'published_verified' !== (string) ( $row['state'] ?? '' ); } ) );
		$state_counts = array();
		foreach ( $obligations as $obligation ) {
			$state = sanitize_key( (string) ( $obligation['state'] ?? '' ) );
			if ( '' !== $state ) { $state_counts[ $state ] = 1 + absint( $state_counts[ $state ] ?? 0 ); }
		}
		$included_sources = 0;
		$excluded_sources = 0;
		$excluded_by_reason = array();
		foreach ( $sources as $source ) {
			if ( 1 === absint( $source['applicable'] ?? 0 ) ) { ++$included_sources; continue; }
			++$excluded_sources;
			$reason = sanitize_key( (string) ( $source['exclusion_reason'] ?? '' ) );
			if ( '' !== $reason ) { $excluded_by_reason[ $reason ] = 1 + absint( $excluded_by_reason[ $reason ] ?? 0 ); }
		}
		if ( ! self::inventory_store_projection_snapshot_is_current( $manifest, $epoch ) ) { return array( 'success' => false, 'complete' => false, 'retryable' => true, 'code' => 'inventory_projection_changed' ); }
		$manifest_included_sources = 'all' === $source_type ? absint( $manifest['included_sources'] ?? 0 ) : absint( $manifest['included_sources_by_post_type'][ $source_type ] ?? 0 );
		if ( $included_sources !== $manifest_included_sources ) { return array( 'success' => false, 'complete' => false, 'code' => 'inventory_store_rebuild_required', 'kind' => 'source_scope_arithmetic' ); }
		$expected = $included_sources * absint( $manifest['target_languages'] ?? 0 );
		$dirty = absint( $manifest['source_inventory_epoch'] ?? 0 ) !== self::source_inventory_epoch() || '1' === get_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '1' );
		$complete = ! $dirty && $expected === $total && 0 === $unresolved;
		return array( 'success' => true, 'complete' => $complete, 'source_type' => $source_type, 'generation' => $generation, 'generation_completed_at' => $manifest['completed_at'] ?? '', 'source_inventory_schema' => self::SOURCE_INVENTORY_SCHEMA_VERSION, 'source_signature' => (string) ( $manifest['source_signature'] ?? '' ), 'inventory_input_signature' => (string) ( $manifest['inventory_input_signature'] ?? '' ), 'source_inventory_epoch' => absint( $manifest['source_inventory_epoch'] ?? 0 ), 'obligation_projection_epoch' => absint( $manifest['obligation_projection_epoch'] ?? 0 ), 'dirty' => $dirty, 'included_sources' => $included_sources, 'excluded_sources' => $excluded_sources, 'excluded_by_reason' => $excluded_by_reason, 'target_languages' => absint( $manifest['target_languages'] ?? 0 ), 'expected_obligations' => $expected, 'projected_obligations' => $total, 'state_counts' => $state_counts, 'unresolved_obligations' => $unresolved, 'all_published_verified' => 0 === $unresolved );
	}
}
