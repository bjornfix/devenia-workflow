<?php
/**
 * Persistent translation index and localized frontend read model.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Translation_Index_Read_Model {
	/**
	 * Translation registry table name.
	 */
	private static function translation_index_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'devenia_translation_index';
	}

	/**
	 * Install or upgrade the persistent translation registry schema.
	 */
	private static function install_translation_index_schema(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::translation_index_table();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			translation_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			language varchar(20) NOT NULL DEFAULT '',
			localized_path varchar(255) NOT NULL DEFAULT '',
			source_path varchar(255) NOT NULL DEFAULT '',
			target_path varchar(255) NOT NULL DEFAULT '',
			target_url varchar(255) NOT NULL DEFAULT '',
			translation_status varchar(30) NOT NULL DEFAULT '',
			post_status varchar(20) NOT NULL DEFAULT '',
			source_hash char(64) NOT NULL DEFAULT '',
			reviewed_at varchar(40) NOT NULL DEFAULT '',
			linguistic_reviewed_at varchar(40) NOT NULL DEFAULT '',
			quality_reviewed_at varchar(40) NOT NULL DEFAULT '',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY source_language (source_post_id, language),
			UNIQUE KEY translation_post (translation_post_id),
			KEY source_post (source_post_id),
			KEY language_path (language, localized_path),
			KEY language_source_path (language, source_path),
			KEY language_target_path (language, target_path),
			KEY post_status (post_status),
			KEY translation_status (translation_status)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::OPTION_TRANSLATION_INDEX_SCHEMA, self::TRANSLATION_INDEX_SCHEMA_VERSION, false );
		self::translation_index_available( true );
	}

	/**
	 * Check whether the translation registry table is currently available.
	 */
	private static function translation_index_available( bool $refresh = false ): bool {
		static $available = null;

		if ( ! $refresh && null !== $available ) {
			return $available;
		}

		global $wpdb;
		$table     = self::translation_index_table();

		$available = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional schema availability check for custom table.
		return $available;
	}

	/**
	 * Rebuild the registry from existing WordPress translation metadata.
	 */
	private static function rebuild_translation_index(): int {
		global $wpdb;

		if ( ! self::translation_index_available() ) {
			self::install_translation_index_schema();
		}

		$table     = self::translation_index_table();
		$post_types = self::translatable_post_types();
		$statuses   = self::translation_workflow_post_statuses();
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional custom registry table rebuild.

		$post_type_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$status_placeholders    = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$sql                    = "
			SELECT DISTINCT posts.ID
			FROM {$wpdb->posts} AS posts
			INNER JOIN {$wpdb->postmeta} AS source_meta
				ON posts.ID = source_meta.post_id
				AND source_meta.meta_key = %s
				AND source_meta.meta_value <> ''
				AND source_meta.meta_value <> '0'
			INNER JOIN {$wpdb->postmeta} AS language_meta
				ON posts.ID = language_meta.post_id
				AND language_meta.meta_key = %s
				AND language_meta.meta_value <> ''
			WHERE posts.post_type IN ({$post_type_placeholders})
				AND posts.post_status IN ({$status_placeholders})
			ORDER BY posts.ID ASC
		";
		$args                   = array_merge( array( self::META_SOURCE_ID, self::META_LANGUAGE ), $post_types, $statuses );
		$translation_ids        = $wpdb->get_col( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional prepared custom registry rebuild from controlled placeholders and translation metadata.

		$count = 0;
		foreach ( array_map( 'absint', $translation_ids ) as $translation_id ) {
			if ( self::sync_translation_index_row( $translation_id ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Report and optionally rebuild the persistent translation registry.
	 */
	private static function translation_index_status( array $input ): array {
		$rebuilt = null;
		if ( ! empty( $input['rebuild'] ) ) {
			self::install_translation_index_schema();
			$rebuilt = self::rebuild_translation_index();
		}

		$available = self::translation_index_available();
		$rows      = 0;
		$by_lang   = array();

		if ( $available ) {
			global $wpdb;
			$table = self::translation_index_table();
			$rows  = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional custom registry table status read.
			$lang_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT language, COUNT(*) AS total FROM %i GROUP BY language ORDER BY language ASC', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional custom registry table status read.
			foreach ( $lang_rows as $row ) {
				$by_lang[ (string) $row['language'] ] = absint( $row['total'] );
			}
		}

		return array(
			'success'        => $available,
			'table'          => self::translation_index_table(),
			'available'      => $available,
			'schema_version' => (string) get_option( self::OPTION_TRANSLATION_INDEX_SCHEMA, '' ),
			'expected_schema_version' => self::TRANSLATION_INDEX_SCHEMA_VERSION,
			'row_count'      => $rows,
			'by_language'    => $by_lang,
			'rebuilt_count'  => $rebuilt,
		);
	}

	/**
	 * Keep one translation registry row aligned with WordPress translation metadata.
	 */
	private static function sync_translation_index_row( int $translation_id ): bool {
		if ( ! self::translation_index_available() ) {
			return false;
		}

		$post = get_post( $translation_id );
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			self::delete_translation_index_row( $translation_id );
			return false;
		}

		$source_id = absint( get_post_meta( $translation_id, self::META_SOURCE_ID, true ) );
		$language  = sanitize_key( (string) get_post_meta( $translation_id, self::META_LANGUAGE, true ) );
		if ( ! $source_id || '' === $language ) {
			self::delete_translation_index_row( $translation_id );
			return false;
		}

		global $wpdb;
		$table = self::translation_index_table();
		$source_url = $source_id ? (string) get_permalink( $source_id ) : '';
		$target_url = (string) get_permalink( $translation_id );
		$target_url = $target_url ?: '';
		$source_path = $source_url ? self::normalized_url_path( $source_url ) : '';
		$target_path = $target_url ? self::normalized_url_path( $target_url ) : '';
		$localized_path = self::localized_path_for_post( $translation_id, $language );

		$result = $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional custom registry table write.
			$table,
			array(
				'source_post_id'         => $source_id,
				'translation_post_id'    => $translation_id,
				'language'               => $language,
				'localized_path'         => $localized_path,
				'source_path'            => $source_path,
				'target_path'            => $target_path,
				'target_url'             => $target_url,
				'translation_status'     => self::sanitize_translation_status( (string) get_post_meta( $translation_id, self::META_STATUS, true ) ),
				'post_status'            => (string) $post->post_status,
				'source_hash'            => (string) get_post_meta( $translation_id, self::META_SOURCE_HASH, true ),
				'reviewed_at'            => (string) get_post_meta( $translation_id, self::META_REVIEWED_AT, true ),
				'linguistic_reviewed_at' => (string) get_post_meta( $translation_id, self::META_LINGUISTIC_REVIEWED_AT, true ),
				'quality_reviewed_at'    => (string) get_post_meta( $translation_id, self::META_QUALITY_REVIEWED_AT, true ),
				'updated_at'             => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Delete one registry row.
	 */
	private static function delete_translation_index_row( int $translation_id ): void {
		if ( ! self::translation_index_available() ) {
			return;
		}

		global $wpdb;
		$wpdb->delete( self::translation_index_table(), array( 'translation_post_id' => $translation_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional custom registry table cleanup.
	}

	/**
	 * Keep the registry clean when translated content is deleted.
	 */
	public static function delete_translation_index_for_post( int $post_id, WP_Post $post ): void {
		if ( ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			return;
		}

		self::delete_translation_index_row( $post_id );
	}

	/**
	 * Sanitize registry post status filters.
	 *
	 * @param array<int,string> $post_status Post statuses.
	 * @return array<int,string>
	 */
	private static function translation_index_statuses( array $post_status ): array {
		return self::sanitize_translation_post_statuses( $post_status );
	}

	/**
	 * Post statuses that belong to the translation workflow.
	 *
	 * @return array<int,string>
	 */
	private static function translation_workflow_post_statuses( bool $include_future = true ): array {
		$statuses = array( 'publish', 'draft', 'pending', 'private' );
		if ( $include_future ) {
			$statuses[] = 'future';
		}

		return $statuses;
	}

	/**
	 * Sanitize a caller-supplied post-status list against workflow statuses.
	 *
	 * @param mixed $raw Raw post-status list.
	 * @return array<int,string>
	 */
	private static function sanitize_translation_post_statuses( $raw, bool $include_future = true ): array {
		$default = self::translation_workflow_post_statuses( $include_future );
		if ( ! is_array( $raw ) ) {
			return $default;
		}

		$allowed  = self::translation_workflow_post_statuses();
		$statuses = array_values( array_intersect( array_map( 'sanitize_key', $raw ), $allowed ) );

		return empty( $statuses ) ? $default : $statuses;
	}

	/**
	 * Filter indexed translation IDs by current WordPress post status.
	 *
	 * @param array<int,int>    $ids         Translation post IDs.
	 * @param array<int,string> $post_status Post statuses.
	 * @return array<int,int>
	 */
	private static function filter_translation_index_ids_by_status( array $ids, array $post_status ): array {
		$statuses = self::translation_index_statuses( $post_status );

		return array_values(
			array_filter(
				array_map( 'absint', $ids ),
				static function ( int $translation_id ) use ( $statuses ): bool {
					$status = get_post_status( $translation_id );
					return is_string( $status ) && in_array( $status, $statuses, true );
				}
			)
		);
	}

	/**
	 * Look up one translation ID through the registry table.
	 */
	private static function translation_index_id_for_source_language( int $source_id, string $language, array $post_status ): int {
		if ( ! self::translation_index_available() ) {
			return 0;
		}

		global $wpdb;
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional indexed custom table read.
			$wpdb->prepare(
				'SELECT translation_post_id FROM %i WHERE source_post_id = %d AND language = %s ORDER BY translation_post_id DESC',
				self::translation_index_table(),
				$source_id,
				sanitize_key( $language )
			)
		);
		$ids = self::filter_translation_index_ids_by_status( $ids, $post_status );

		return isset( $ids[0] ) ? (int) $ids[0] : 0;
	}

	/**
	 * Look up a translated post/page by its frontend target path.
	 */
	private static function find_translation_id_by_target_path( string $target_path, array $post_status ): int {
		if ( ! self::translation_index_available() ) {
			return 0;
		}

		$target_path = '/' . trim( $target_path, '/' ) . '/';
		if ( '//' === $target_path ) {
			return 0;
		}

		global $wpdb;
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional indexed custom table read.
			$wpdb->prepare(
				'SELECT translation_post_id FROM %i WHERE target_path = %s ORDER BY translation_post_id DESC',
				self::translation_index_table(),
				$target_path
			)
		);
		$ids = self::filter_translation_index_ids_by_status( $ids, $post_status );

		return isset( $ids[0] ) ? (int) $ids[0] : 0;
	}

	/**
	 * Look up translation IDs for a source page through the registry table.
	 *
	 * @return array<int,int>
	 */
	private static function translation_index_ids_for_source( int $source_id, array $post_status ): array {
		if ( ! self::translation_index_available() ) {
			return array();
		}

		global $wpdb;
		$ids           = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional indexed custom table read.
			$wpdb->prepare(
				'SELECT translation_post_id FROM %i WHERE source_post_id = %d ORDER BY language ASC',
				self::translation_index_table(),
				$source_id
			)
		);

		return self::filter_translation_index_ids_by_status( $ids, $post_status );
	}

	/**
	 * Look up translation IDs for a language through the registry table.
	 *
	 * @return array<int,int>
	 */
	private static function translation_index_ids_for_language( string $language, array $post_status ): array {
		if ( ! self::translation_index_available() ) {
			return array();
		}

		global $wpdb;
		$ids           = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional indexed custom table read.
			$wpdb->prepare(
				'SELECT translation_post_id FROM %i WHERE language = %s ORDER BY source_post_id ASC',
				self::translation_index_table(),
				sanitize_key( $language )
			)
		);

		return self::filter_translation_index_ids_by_status( $ids, $post_status );
	}

	/**
	 * Batch-load translation IDs for exact source IDs and one language.
	 *
	 * Each physical query is capped at 100 source IDs. This keeps frontend menu
	 * projection on the indexed registry seam instead of scanning WordPress
	 * posts and inflating per-post metadata caches.
	 *
	 * @param array<int,int>    $source_ids  Source post IDs.
	 * @param string            $language    Target language.
	 * @param array<int,string> $post_status Accepted post statuses.
	 * @return array<int,int> Source ID to translation ID map.
	 */
	private static function translation_index_ids_for_sources_language( array $source_ids, string $language, array $post_status ): array {
		if ( ! self::translation_index_available() ) {
			return array();
		}

		$source_ids = array_values( array_unique( array_filter( array_map( 'absint', $source_ids ) ) ) );
		$language   = sanitize_key( $language );
		$statuses   = self::translation_index_statuses( $post_status );
		if ( empty( $source_ids ) || '' === $language || empty( $statuses ) ) {
			return array();
		}

		global $wpdb;
		$map = array();
		foreach ( array_chunk( $source_ids, 100 ) as $source_chunk ) {
			$source_placeholders = implode( ', ', array_fill( 0, count( $source_chunk ), '%d' ) );
			$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
			$sql = "SELECT source_post_id, translation_post_id FROM %i WHERE language = %s AND source_post_id IN ({$source_placeholders}) AND post_status IN ({$status_placeholders}) ORDER BY source_post_id ASC";
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact bounded read from the plugin-owned translation registry.
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders are generated only from bounded sanitized ID/status arrays.
					$sql,
					array_merge( array( self::translation_index_table(), $language ), $source_chunk, $statuses )
				),
				ARRAY_A
			);

			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$source_id      = absint( $row['source_post_id'] ?? 0 );
				$translation_id = absint( $row['translation_post_id'] ?? 0 );
				if ( $source_id && $translation_id ) {
					$map[ $source_id ] = $translation_id;
				}
			}
		}

		return $map;
	}

	/**
	 * Look up all indexed translation IDs.
	 *
	 * @return array<int,int>
	 */
	private static function translation_index_ids( array $post_status ): array {
		if ( ! self::translation_index_available() ) {
			return array();
		}

		global $wpdb;
		$ids           = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional indexed custom table read.
			$wpdb->prepare(
				'SELECT translation_post_id FROM %i ORDER BY source_post_id ASC, language ASC',
				self::translation_index_table()
			)
		);

		return self::filter_translation_index_ids_by_status( $ids, $post_status );
	}

	/**
	 * Read normalized registry rows by source without inflating full translation payloads.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function translation_index_rows_for_source( int $source_id, array $post_status ): array {
		if ( ! self::translation_index_available() ) {
			return array();
		}

		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional indexed custom table read.
			$wpdb->prepare(
				'SELECT source_post_id, translation_post_id, language, localized_path, source_path, target_path, target_url, translation_status, post_status, source_hash, reviewed_at, linguistic_reviewed_at, quality_reviewed_at FROM %i WHERE source_post_id = %d ORDER BY language ASC',
				self::translation_index_table(),
				$source_id
			),
			ARRAY_A
		);

		return self::normalize_translation_index_rows( is_array( $rows ) ? $rows : array(), $post_status );
	}

	/**
	 * Read normalized registry rows by language.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function translation_index_rows_for_language( string $language, array $post_status ): array {
		if ( ! self::translation_index_available() ) {
			return array();
		}

		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional indexed custom table read.
			$wpdb->prepare(
				'SELECT source_post_id, translation_post_id, language, localized_path, source_path, target_path, target_url, translation_status, post_status, source_hash, reviewed_at, linguistic_reviewed_at, quality_reviewed_at FROM %i WHERE language = %s ORDER BY source_post_id ASC',
				self::translation_index_table(),
				sanitize_key( $language )
			),
			ARRAY_A
		);

		return self::normalize_translation_index_rows( is_array( $rows ) ? $rows : array(), $post_status );
	}

	/**
	 * Read one normalized registry row by translation ID.
	 *
	 * @return array<string,mixed>
	 */
	private static function translation_index_row_for_translation( int $translation_id, array $post_status = array() ): array {
		static $cache = array();

		$status_key = implode( '|', array_map( 'sanitize_key', $post_status ) );
		$cache_key  = $translation_id . ':' . $status_key;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		if ( ! self::translation_index_available() ) {
			$cache[ $cache_key ] = array();
			return $cache[ $cache_key ];
		}

		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional indexed custom table read.
			$wpdb->prepare(
				'SELECT source_post_id, translation_post_id, language, localized_path, source_path, target_path, target_url, translation_status, post_status, source_hash, reviewed_at, linguistic_reviewed_at, quality_reviewed_at FROM %i WHERE translation_post_id = %d',
				self::translation_index_table(),
				$translation_id
			),
			ARRAY_A
		);

		$rows = self::normalize_translation_index_rows( is_array( $row ) ? array( $row ) : array(), $post_status );
		$cache[ $cache_key ] = $rows[0] ?? array();

		return $cache[ $cache_key ];
	}

	/**
	 * Normalize and post-status-filter registry rows.
	 *
	 * @param array<int,array<string,mixed>> $rows Registry rows.
	 * @param array<int,string>             $post_status Accepted post statuses.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_translation_index_rows( array $rows, array $post_status ): array {
		$statuses = self::translation_index_statuses( $post_status );
		$normalized = array();

		foreach ( $rows as $row ) {
			$status = isset( $row['post_status'] ) ? sanitize_key( (string) $row['post_status'] ) : '';
			if ( '' !== $status && ! in_array( $status, $statuses, true ) ) {
				continue;
			}

			$translation_id = absint( $row['translation_post_id'] ?? 0 );
			$source_id      = absint( $row['source_post_id'] ?? 0 );
			$language       = sanitize_key( (string) ( $row['language'] ?? '' ) );
			if ( ! $translation_id || ! $source_id || '' === $language ) {
				continue;
			}

			$normalized[] = array(
				'id'                     => $translation_id,
				'translation_post_id'    => $translation_id,
				'source_id'              => $source_id,
				'source_post_id'         => $source_id,
				'language'               => $language,
				'localized_path'         => trim( (string) ( $row['localized_path'] ?? '' ), '/' ),
				'source_path'            => trim( (string) ( $row['source_path'] ?? '' ), '/' ),
				'target_path'            => trim( (string) ( $row['target_path'] ?? '' ), '/' ),
				'target_url'             => esc_url_raw( (string) ( $row['target_url'] ?? '' ) ),
				'translation_status'     => self::sanitize_translation_status( (string) ( $row['translation_status'] ?? '' ) ),
				'status'                 => $status,
				'post_status'            => $status,
				'source_hash'            => (string) ( $row['source_hash'] ?? '' ),
				'reviewed_at'            => (string) ( $row['reviewed_at'] ?? '' ),
				'linguistic_reviewed_at' => (string) ( $row['linguistic_reviewed_at'] ?? '' ),
				'quality_reviewed_at'    => (string) ( $row['quality_reviewed_at'] ?? '' ),
			);
		}

		return $normalized;
	}

	/**
	 * Frontend row read model backed by the registry table.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function translation_frontend_rows_for_source( int $source_id, array $post_status = array( 'publish' ) ): array {
		static $cache = array();

		$status_key = implode( '|', array_map( 'sanitize_key', $post_status ) );
		$cache_key  = $source_id . ':' . $status_key;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$rows = self::translation_index_rows_for_source( $source_id, $post_status );
		$cache[ $cache_key ] = self::frontend_rows_from_index_rows( $rows );

		return $cache[ $cache_key ];
	}

	/**
	 * Frontend row read model backed by the registry table and scoped by language.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function translation_frontend_rows_for_language( string $language, array $post_status = array( 'publish' ) ): array {
		static $cache = array();

		$language   = sanitize_key( $language );
		$status_key = implode( '|', array_map( 'sanitize_key', $post_status ) );
		$cache_key  = $language . ':' . $status_key;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$rows = self::translation_index_rows_for_language( $language, $post_status );
		$cache[ $cache_key ] = self::frontend_rows_from_index_rows( $rows );

		return $cache[ $cache_key ];
	}

	/**
	 * Resolve one indexed frontend row by language and source path.
	 *
	 * @return array<string,mixed>
	 */
	private static function translation_frontend_row_for_language_source_path( string $language, string $source_path, array $post_status = array( 'publish' ) ): array {
		static $cache = array();

		$language    = sanitize_key( $language );
		$source_path = trim( $source_path, '/' );
		$post_status = self::sanitize_translation_post_statuses( $post_status, false );
		$status_key  = implode( '|', array_map( 'sanitize_key', $post_status ) );
		$cache_key   = $language . ':' . md5( $source_path ) . ':' . $status_key;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		if ( '' === $language || '' === $source_path || ! self::translation_index_available() ) {
			$cache[ $cache_key ] = array();
			return $cache[ $cache_key ];
		}

		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional indexed custom table read for frontend route lookup.
			$wpdb->prepare(
				'SELECT source_post_id, translation_post_id, language, localized_path, source_path, target_path, target_url, translation_status, post_status, source_hash, reviewed_at, linguistic_reviewed_at, quality_reviewed_at FROM %i WHERE language = %s AND source_path = %s ORDER BY translation_post_id DESC LIMIT 1',
				self::translation_index_table(),
				$language,
				$source_path
			),
			ARRAY_A
		);

		$rows = self::frontend_rows_from_index_rows(
			self::normalize_translation_index_rows( is_array( $row ) ? array( $row ) : array(), $post_status )
		);
		$cache[ $cache_key ] = $rows[0] ?? array();

		return $cache[ $cache_key ];
	}

	/**
	 * Add URL/path fields to normalized registry rows.
	 *
	 * @param array<int,array<string,mixed>> $rows Registry rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function frontend_rows_from_index_rows( array $rows ): array {
		$frontend_rows = array();
		foreach ( $rows as $row ) {
			$source_id      = absint( $row['source_id'] ?? 0 );
			$translation_id = absint( $row['id'] ?? 0 );
			$source_path    = trim( (string) ( $row['source_path'] ?? '' ), '/' );
			$source_url     = '' === $source_path ? '' : home_url( '/' . $source_path . '/' );
			$stored_target_path = trim( (string) ( $row['target_path'] ?? '' ), '/' );
			$target_path    = $stored_target_path;
			$target_url     = esc_url_raw( (string) ( $row['target_url'] ?? '' ) );

			if ( '' === $target_path && '' !== $target_url ) {
				$target_path = self::normalized_url_path( $target_url );
			}
			if ( '' === $target_url || '' === $target_path || ( '' === $source_url && '' === $source_path ) ) {
				continue;
			}

			$stored_localized_path = trim( (string) ( $row['localized_path'] ?? '' ), '/' );
			$canonical_path        = trim( (string) $target_path, '/' );
			$localized_variants    = array_values(
				array_unique(
					array_filter(
						array( $stored_localized_path, $stored_target_path, $canonical_path ),
						static function ( string $path ): bool {
							return '' !== $path;
						}
					)
				)
			);

			$row['source_url']  = $source_url;
			$row['url']         = (string) $target_url;
			$row['target_url']  = (string) $target_url;
			$row['source_path'] = $source_path;
			$row['target_path'] = $target_path;
			$row['localized_path'] = $canonical_path ?: $stored_localized_path;
			$row['localized_path_variants'] = $localized_variants;
			$row['established_canonical_route'] = array();
			$row['observed_target_url'] = '';
			$row['observed_target_path'] = '';
			$row['route_drift'] = false;

			$frontend_rows[] = $row;
		}

		return $frontend_rows;
	}
}
