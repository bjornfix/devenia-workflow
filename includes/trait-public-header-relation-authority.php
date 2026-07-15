<?php
/**
 * Ephemeral canonical relation authority for Public Header Projection staging.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Public_Header_Relation_Authority {
	/** Build one complete short-lived relation receipt set for a pending manifest. */
	private static function public_header_relation_receipts_for_manifest( array $manifest ): array {
		$manifest_revision = (string) ( $manifest['revision'] ?? '' );
		$items = isset( $manifest['items'] ) && is_array( $manifest['items'] ) ? $manifest['items'] : array();
		$languages = self::configured_public_header_languages();
		if ( '' === $manifest_revision || empty( $items ) || empty( $languages ) ) {
			return array( 'success' => false, 'code' => 'public_header_relation_receipts_missing' );
		}

		$receipts = array();
		foreach ( $languages as $language ) {
			$fresh = self::public_header_ephemeral_relation_snapshot( (string) $language, $items );
			if ( empty( $fresh['success'] ) ) {
				return array( 'success' => false, 'code' => 'public_header_relation_receipt_build_failed', 'language' => (string) $language, 'fresh' => $fresh );
			}
			$receipt = array(
				'language'          => sanitize_key( (string) $language ),
				'manifest_revision' => $manifest_revision,
				'relations'         => (array) $fresh['relations'],
				'relation_revision' => (string) $fresh['revision'],
			);
			$receipt['receipt_revision'] = 'phrrc_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $receipt ) ) ?: '' ), 0, 40 );
			$receipts[ (string) $language ] = $receipt;
		}
		ksort( $receipts );
		return array( 'success' => true, 'receipts' => $receipts );
	}

	/** Require and revalidate every canonical relation receipt carried by pending state. */
	private static function validate_public_header_relation_receipts( array $manifest ): array {
		if ( ! array_key_exists( 'relation_receipts', $manifest ) ) {
			return array( 'success' => false, 'code' => 'public_header_relation_receipts_missing' );
		}
		if ( ! is_array( $manifest['relation_receipts'] ) || empty( $manifest['relation_receipts'] ) ) {
			return array( 'success' => false, 'code' => 'public_header_relation_receipts_invalid' );
		}

		$receipts = $manifest['relation_receipts'];
		$languages = self::configured_public_header_languages();
		$receipt_languages = array_map( 'sanitize_key', array_keys( $receipts ) );
		sort( $receipt_languages );
		sort( $languages );
		if ( $receipt_languages !== $languages ) {
			return array( 'success' => false, 'code' => 'public_header_relation_receipt_language_set_invalid' );
		}

		foreach ( $languages as $language ) {
			$receipt = isset( $receipts[ $language ] ) && is_array( $receipts[ $language ] ) ? $receipts[ $language ] : array();
			$receipt_revision = (string) ( $receipt['receipt_revision'] ?? '' );
			$receipt_payload = $receipt;
			unset( $receipt_payload['receipt_revision'] );
			$expected_revision = 'phrrc_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $receipt_payload ) ) ?: '' ), 0, 40 );
			if ( $language !== sanitize_key( (string) ( $receipt['language'] ?? '' ) ) || '' === (string) ( $manifest['revision'] ?? '' ) || ! hash_equals( (string) $manifest['revision'], (string) ( $receipt['manifest_revision'] ?? '' ) ) || '' === $receipt_revision || ! hash_equals( $expected_revision, $receipt_revision ) ) {
				return array( 'success' => false, 'code' => 'public_header_relation_receipt_revision_invalid', 'language' => $language );
			}
			$relations = isset( $receipt['relations'] ) && is_array( $receipt['relations'] ) ? $receipt['relations'] : array();
			if ( count( $relations ) !== count( (array) ( $manifest['items'] ?? array() ) ) ) {
				return array( 'success' => false, 'code' => 'public_header_relation_receipt_incomplete', 'language' => $language );
			}
			foreach ( $relations as $relation ) {
				$type = sanitize_key( (string) ( is_array( $relation ) ? ( $relation['type'] ?? '' ) : '' ) );
				if ( 'page' === $type && absint( $relation['object_id'] ?? 0 ) > 0 ) { continue; }
				if ( 'custom' !== $type || '' === (string) ( $relation['url'] ?? '' ) ) {
					return array( 'success' => false, 'code' => 'public_header_relation_receipt_item_invalid', 'language' => $language );
				}
				$scope = sanitize_key( (string) ( $relation['scope'] ?? '' ) );
				if ( 'external' === $scope ) { continue; }
				if ( 'internal' !== $scope || absint( $relation['source_post_id'] ?? 0 ) < 1 || absint( $relation['target_post_id'] ?? 0 ) < 1 || '' === (string) ( $relation['source_url'] ?? '' ) || '' === (string) ( $relation['target_url'] ?? '' ) || ! hash_equals( (string) $relation['target_url'], (string) $relation['url'] ) || '' === (string) ( $relation['route_revision'] ?? '' ) || empty( $relation['route_post_ids'] ) || ! is_array( $relation['route_post_ids'] ) ) {
					return array( 'success' => false, 'code' => 'public_header_relation_receipt_internal_route_invalid', 'language' => $language );
				}
			}

			$fresh = self::public_header_ephemeral_relation_snapshot( $language, (array) ( $manifest['items'] ?? array() ) );
			if ( empty( $fresh['success'] ) || self::translation_job_canonicalize( (array) ( $fresh['relations'] ?? array() ) ) !== self::translation_job_canonicalize( $relations ) || '' === (string) ( $receipt['relation_revision'] ?? '' ) || ! hash_equals( (string) $receipt['relation_revision'], (string) ( $fresh['revision'] ?? '' ) ) ) {
				return array( 'success' => false, 'code' => 'public_header_relation_authority_changed', 'language' => $language, 'fresh' => $fresh );
			}
		}
		return array( 'success' => true, 'present' => true );
	}

	/**
	 * Resolve the exact page/custom relation consumed by one projection from
	 * canonical posts and postmeta, without request-static link maps.
	 *
	 * @param array<int,array<string,mixed>> $manifest_items Manifest rows.
	 */
	private static function public_header_ephemeral_relation_snapshot( string $language, array $manifest_items ): array {
		$language = sanitize_key( $language );
		$is_source = self::source_language_code() === $language;
		$relations = array();
		$missing = array();
		$page_source_ids = array();
		foreach ( $manifest_items as $manifest_item ) {
			if ( is_array( $manifest_item ) && 'page' === sanitize_key( (string) ( $manifest_item['type'] ?? '' ) ) ) {
				$page_source_ids[] = absint( $manifest_item['object_id'] ?? 0 );
			}
		}
		$page_snapshot = self::public_header_ephemeral_page_relations( $page_source_ids, $language, $is_source );
		if ( empty( $page_snapshot['success'] ) ) { return $page_snapshot; }

		foreach ( $manifest_items as $manifest_item ) {
			$source_item_id = absint( is_array( $manifest_item ) ? ( $manifest_item['source_item_id'] ?? 0 ) : 0 );
			$type = sanitize_key( (string) ( is_array( $manifest_item ) ? ( $manifest_item['type'] ?? '' ) : '' ) );
			if ( $source_item_id < 1 ) { $missing[] = array( 'source_item_id' => 0, 'reason' => 'relation_source_item_invalid' ); continue; }
			if ( 'page' === $type ) {
				$source_id = absint( $manifest_item['object_id'] ?? 0 );
				$target_id = absint( $page_snapshot['relations'][ $source_id ] ?? 0 );
				if ( $target_id < 1 ) { $missing[] = array( 'source_item_id' => $source_item_id, 'reason' => 'page_relation_missing' ); continue; }
				$relations[ $source_item_id ] = array( 'type' => 'page', 'object_id' => $target_id );
				continue;
			}
			if ( 'custom' === $type ) {
				$custom = self::public_header_fresh_custom_relation( (string) ( $manifest_item['url'] ?? '' ), $language, $is_source );
				if ( empty( $custom['success'] ) ) { $missing[] = array( 'source_item_id' => $source_item_id, 'reason' => (string) ( $custom['code'] ?? 'custom_relation_missing' ), 'details' => $custom ); continue; }
				$relations[ $source_item_id ] = (array) $custom['relation'];
				continue;
			}
			$missing[] = array( 'source_item_id' => $source_item_id, 'reason' => 'relation_type_unsupported' );
		}
		if ( ! empty( $missing ) || count( $relations ) !== count( $manifest_items ) ) {
			return array( 'success' => false, 'code' => 'public_header_relation_authority_incomplete', 'missing' => $missing );
		}
		ksort( $relations, SORT_NUMERIC );
		return array( 'success' => true, 'relations' => $relations, 'revision' => 'phrr_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $relations ) ) ?: '' ), 0, 40 ) );
	}

	/** Resolve a custom link as either URL-only external authority or a canonical internal route pair. */
	private static function public_header_fresh_custom_relation( string $url, string $language, bool $is_source ): array {
		$url = self::normalize_primary_navigation_url( $url );
		if ( '' === $url ) { return array( 'success' => false, 'code' => 'custom_relation_url_invalid' ); }
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) { return array( 'success' => false, 'code' => 'custom_relation_url_invalid' ); }
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		$site_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$internal = ( '' === $scheme || in_array( $scheme, array( 'http', 'https' ), true ) ) && ( '' === $host || $host === $site_host );
		if ( ! $internal ) {
			return array( 'success' => true, 'relation' => array( 'type' => 'custom', 'scope' => 'external', 'url' => $url ) );
		}

		$source_post_id = self::public_header_core_internal_content_id( $url );
		$source_post = $source_post_id > 0 ? get_post( $source_post_id ) : null;
		if ( ! $source_post instanceof WP_Post || 'publish' !== (string) $source_post->post_status ) {
			return array( 'success' => false, 'code' => 'custom_relation_internal_source_missing' );
		}
		$canonical = self::public_header_fresh_content_relations( array( $source_post_id ), $language, $is_source, array( $source_post_id => (string) $source_post->post_type ) );
		if ( empty( $canonical['success'] ) ) { return $canonical; }
		$target_post_id = absint( $canonical['relations'][ $source_post_id ] ?? 0 );
		$route = self::public_header_route_authority_snapshot( array( $source_post_id, $target_post_id ) );
		if ( empty( $route['success'] ) || empty( $route['urls'][ $target_post_id ] ) ) {
			return array( 'success' => false, 'code' => 'custom_relation_route_authority_missing', 'route' => $route );
		}
		return array(
			'success'  => true,
			'relation' => array(
				'type'            => 'custom',
				'scope'           => 'internal',
				'source_post_id'  => $source_post_id,
				'target_post_id'  => $target_post_id,
				'source_url'      => (string) $route['urls'][ $source_post_id ],
				'target_url'      => (string) $route['urls'][ $target_post_id ],
				'url'             => (string) $route['urls'][ $target_post_id ],
				'route_post_ids'  => (array) $route['post_ids'],
				'route_revision'  => (string) $route['revision'],
			),
		);
	}

	/** Resolve only through WordPress core routes/query identities, never the Translation Index. */
	private static function public_header_core_internal_content_id( string $url ): int {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) { return 0; }
		foreach ( array( 'p', 'page_id', 'attachment_id' ) as $key ) {
			if ( isset( $parts['query'] ) ) {
				parse_str( (string) $parts['query'], $query );
				$id = absint( $query[ $key ] ?? 0 );
				if ( $id > 0 && get_post( $id ) ) { return $id; }
			}
		}
		$id = url_to_postid( $url );
		if ( $id < 1 && '' === (string) ( $parts['host'] ?? '' ) ) { $id = url_to_postid( home_url( '/' . ltrim( (string) ( $parts['path'] ?? '' ), '/' ) ) ); }
		return $id > 0 && get_post( $id ) ? $id : 0;
	}

	/** Resolve all page relations from canonical posts/postmeta. */
	private static function public_header_ephemeral_page_relations( array $source_ids, string $language, bool $is_source ): array {
		$required_types = array();
		foreach ( array_values( array_unique( array_filter( array_map( 'absint', $source_ids ) ) ) ) as $source_id ) { $required_types[ $source_id ] = 'page'; }
		return self::public_header_fresh_content_relations( $source_ids, $language, $is_source, $required_types );
	}

	/**
	 * Resolve source/translation objects from canonical posts and exact identity
	 * metadata. The Translation Index can reject disagreement but never selects.
	 */
	private static function public_header_fresh_content_relations( array $source_ids, string $language, bool $is_source, array $required_types ): array {
		$source_ids = array_values( array_unique( array_filter( array_map( 'absint', $source_ids ) ) ) );
		if ( empty( $source_ids ) ) { return array( 'success' => true, 'relations' => array() ); }
		global $wpdb;
		$id_placeholders = implode( ', ', array_fill( 0, count( $source_ids ), '%d' ) );
		$post_sql = "SELECT ID, post_type, post_status FROM {$wpdb->posts} WHERE ID IN ({$id_placeholders}) ORDER BY ID ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated integer placeholders are interpolated.
		$post_rows = $wpdb->get_results( $wpdb->prepare( $post_sql, $source_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical post rows are relation authority.
		$posts_by_id = array();
		foreach ( is_array( $post_rows ) ? $post_rows : array() as $row ) { $posts_by_id[ absint( $row['ID'] ?? 0 ) ] = $row; }
		$source_meta_sql = "SELECT post_id, meta_key AS identity_key, meta_value AS identity_value FROM {$wpdb->postmeta} WHERE post_id IN ({$id_placeholders}) AND meta_key IN (%s, %s) ORDER BY post_id ASC, meta_id ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated integer placeholders are interpolated.
		$source_meta_rows = $wpdb->get_results( $wpdb->prepare( $source_meta_sql, array_merge( $source_ids, array( self::META_SOURCE_ID, self::META_LANGUAGE ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical source rows must remain published originals even while resolving a target language.
		$source_identity = array();
		foreach ( is_array( $source_meta_rows ) ? $source_meta_rows : array() as $row ) { $source_identity[ absint( $row['post_id'] ?? 0 ) ][] = $row; }
		$source_missing = array();
		foreach ( $source_ids as $source_id ) {
			$row = (array) ( $posts_by_id[ $source_id ] ?? array() );
			$required_type = sanitize_key( (string) ( $required_types[ $source_id ] ?? '' ) );
			if ( '' === $required_type || $required_type !== sanitize_key( (string) ( $row['post_type'] ?? '' ) ) || 'publish' !== (string) ( $row['post_status'] ?? '' ) ) { $source_missing[] = array( 'source_id' => $source_id, 'reason' => 'relation_source_not_published' ); continue; }
			if ( ! empty( $source_identity[ $source_id ] ) ) { $source_missing[] = array( 'source_id' => $source_id, 'reason' => 'relation_source_translation_identity_present' ); }
		}
		if ( ! empty( $source_missing ) ) { return array( 'success' => false, 'code' => 'public_header_page_relation_authority_incomplete', 'missing' => $source_missing ); }

		if ( $is_source ) {
			$relations = array_combine( $source_ids, $source_ids );
			$index = self::public_header_relation_index_cross_check( $source_ids, $language, true, $relations );
			return empty( $index['success'] ) ? $index : array( 'success' => true, 'relations' => $relations, 'index_cross_check' => $index );
		}

		$language = sanitize_key( $language );
		$sql = "SELECT source_meta.meta_value AS source_post_id, p.ID AS translation_post_id FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} source_meta ON source_meta.post_id = p.ID AND source_meta.meta_key = %s INNER JOIN {$wpdb->postmeta} language_meta ON language_meta.post_id = p.ID AND language_meta.meta_key = %s AND language_meta.meta_value = %s WHERE CAST(source_meta.meta_value AS UNSIGNED) IN ({$id_placeholders}) AND p.post_status = %s ORDER BY source_meta.meta_value ASC, p.ID ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated integer placeholders are interpolated.
		$args = array_merge( array( self::META_SOURCE_ID, self::META_LANGUAGE, $language ), $source_ids, array( 'publish' ) );
		$relation_rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical posts and identity metadata select candidates.
		$candidates = array(); $translation_ids = array();
		foreach ( is_array( $relation_rows ) ? $relation_rows : array() as $row ) {
			$source_id = absint( $row['source_post_id'] ?? 0 ); $translation_id = absint( $row['translation_post_id'] ?? 0 );
			if ( in_array( $source_id, $source_ids, true ) && $translation_id > 0 ) { $candidates[ $source_id ][ $translation_id ] = true; $translation_ids[ $translation_id ] = true; }
		}
		$translation_ids = array_keys( $translation_ids );
		$query_ids = empty( $translation_ids ) ? array( 0 ) : array_map( 'absint', $translation_ids );
		$translation_placeholders = implode( ', ', array_fill( 0, count( $query_ids ), '%d' ) );
		$translation_post_sql = "SELECT ID, post_type, post_status FROM {$wpdb->posts} WHERE ID IN ({$translation_placeholders}) ORDER BY ID ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated integer placeholders are interpolated.
		$translation_post_rows = $wpdb->get_results( $wpdb->prepare( $translation_post_sql, $query_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact target state read.
		$meta_sql = "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$translation_placeholders}) AND meta_key IN (%s, %s) ORDER BY post_id ASC, meta_id ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated integer placeholders are interpolated.
		$meta_rows = $wpdb->get_results( $wpdb->prepare( $meta_sql, array_merge( $query_ids, array( self::META_SOURCE_ID, self::META_LANGUAGE ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact identity row-count validation.
		$target_posts = array(); $meta_by_id = array();
		foreach ( is_array( $translation_post_rows ) ? $translation_post_rows : array() as $row ) { $target_posts[ absint( $row['ID'] ?? 0 ) ] = $row; }
		foreach ( is_array( $meta_rows ) ? $meta_rows : array() as $row ) { $meta_by_id[ absint( $row['post_id'] ?? 0 ) ][ (string) ( $row['meta_key'] ?? '' ) ][] = (string) ( $row['meta_value'] ?? '' ); }
		$relations = array(); $missing = array();
		foreach ( $source_ids as $source_id ) {
			$ids = array_map( 'absint', array_keys( (array) ( $candidates[ $source_id ] ?? array() ) ) );
			if ( 1 !== count( $ids ) ) { $missing[] = array( 'source_id' => $source_id, 'reason' => empty( $ids ) ? 'relation_translation_missing' : 'relation_translation_ambiguous' ); continue; }
			$target_id = (int) $ids[0]; $post_row = (array) ( $target_posts[ $target_id ] ?? array() );
			$source_rows = (array) ( $meta_by_id[ $target_id ][ self::META_SOURCE_ID ] ?? array() ); $language_rows = (array) ( $meta_by_id[ $target_id ][ self::META_LANGUAGE ] ?? array() );
			$required_type = sanitize_key( (string) ( $required_types[ $source_id ] ?? '' ) );
			if ( '' === $required_type || $required_type !== sanitize_key( (string) ( $post_row['post_type'] ?? '' ) ) || 'publish' !== (string) ( $post_row['post_status'] ?? '' ) || 1 !== count( $source_rows ) || (string) $source_id !== (string) $source_rows[0] || 1 !== count( $language_rows ) || $language !== sanitize_key( (string) $language_rows[0] ) ) { $missing[] = array( 'source_id' => $source_id, 'reason' => 'relation_translation_identity_invalid' ); continue; }
			$relations[ $source_id ] = $target_id;
		}
		if ( ! empty( $missing ) ) { return array( 'success' => false, 'code' => 'public_header_page_relation_authority_incomplete', 'missing' => $missing ); }
		$index = self::public_header_relation_index_cross_check( $source_ids, $language, false, $relations );
		return empty( $index['success'] ) ? $index : array( 'success' => true, 'relations' => $relations, 'index_cross_check' => $index );
	}

	/** Cross-check canonical relations against the read model; unavailable is a closed failure. */
	private static function public_header_relation_index_cross_check( array $source_ids, string $language, bool $is_source, array $canonical_relations ): array {
		if ( ! self::translation_index_available() ) { return array( 'success' => false, 'code' => 'public_header_page_relation_index_unavailable' ); }
		global $wpdb;
		$source_ids = array_values( array_unique( array_filter( array_map( 'absint', $source_ids ) ) ) );
		$id_placeholders = implode( ', ', array_fill( 0, count( $source_ids ), '%d' ) );
		if ( $is_source ) {
			$sql = "SELECT source_post_id, translation_post_id, language, post_status FROM %i WHERE translation_post_id IN ({$id_placeholders}) ORDER BY translation_post_id ASC, source_post_id ASC, language ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated integer placeholders are interpolated.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( self::translation_index_table() ), $source_ids ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-model mismatch check only.
			if ( ! is_array( $rows ) ) { return array( 'success' => false, 'code' => 'public_header_page_relation_index_unavailable' ); }
			return empty( $rows ) ? array( 'success' => true, 'present' => true ) : array( 'success' => false, 'code' => 'public_header_page_relation_index_mismatch', 'reason' => 'source_page_classified_as_translation', 'rows' => $rows );
		}
		$language = sanitize_key( $language );
		$sql = "SELECT source_post_id, translation_post_id, language, post_status FROM %i WHERE source_post_id IN ({$id_placeholders}) AND language = %s ORDER BY source_post_id ASC, translation_post_id ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated integer placeholders are interpolated.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( self::translation_index_table() ), $source_ids, array( $language ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-model mismatch check only.
		if ( ! is_array( $rows ) ) { return array( 'success' => false, 'code' => 'public_header_page_relation_index_unavailable' ); }
		$by_source = array();
		foreach ( $rows as $row ) { $by_source[ absint( $row['source_post_id'] ?? 0 ) ][] = $row; }
		$mismatches = array();
		foreach ( $source_ids as $source_id ) {
			$indexed = (array) ( $by_source[ $source_id ] ?? array() ); $canonical_id = absint( $canonical_relations[ $source_id ] ?? 0 );
			if ( 1 !== count( $indexed ) || $canonical_id < 1 || $canonical_id !== absint( $indexed[0]['translation_post_id'] ?? 0 ) || $language !== sanitize_key( (string) ( $indexed[0]['language'] ?? '' ) ) || 'publish' !== (string) ( $indexed[0]['post_status'] ?? '' ) ) { $mismatches[] = array( 'source_id' => $source_id, 'canonical_translation_id' => $canonical_id, 'index_rows' => $indexed ); }
		}
		return empty( $mismatches ) ? array( 'success' => true, 'present' => true ) : array( 'success' => false, 'code' => 'public_header_page_relation_index_mismatch', 'reason' => 'canonical_relation_disagrees_with_index', 'mismatches' => $mismatches );
	}

	/** Capture route-bearing post hierarchy and route metadata from canonical rows. */
	private static function public_header_route_authority_snapshot( array $requested_ids ): array {
		$requested_ids = array_values( array_unique( array_filter( array_map( 'absint', $requested_ids ) ) ) );
		if ( empty( $requested_ids ) ) { return array( 'success' => false, 'code' => 'public_header_route_authority_missing' ); }
		global $wpdb;
		$pending = $requested_ids; $posts = array();
		while ( ! empty( $pending ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $pending ), '%d' ) );
			$sql = "SELECT ID, post_parent, post_name, post_type, post_status FROM {$wpdb->posts} WHERE ID IN ({$placeholders}) ORDER BY ID ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated integer placeholders are interpolated.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $pending ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical route-bearing post state.
			$pending = array();
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$id = absint( $row['ID'] ?? 0 ); if ( $id < 1 || isset( $posts[ $id ] ) ) { continue; }
				$posts[ $id ] = array( 'ID' => $id, 'post_parent' => absint( $row['post_parent'] ?? 0 ), 'post_name' => (string) ( $row['post_name'] ?? '' ), 'post_type' => (string) ( $row['post_type'] ?? '' ), 'post_status' => (string) ( $row['post_status'] ?? '' ) );
				$parent = absint( $row['post_parent'] ?? 0 ); if ( $parent > 0 && ! isset( $posts[ $parent ] ) ) { $pending[] = $parent; }
			}
			$pending = array_values( array_unique( $pending ) );
		}
		foreach ( $requested_ids as $id ) { if ( empty( $posts[ $id ] ) || 'publish' !== (string) $posts[ $id ]['post_status'] ) { return array( 'success' => false, 'code' => 'public_header_route_post_invalid', 'post_id' => $id ); } }
		ksort( $posts, SORT_NUMERIC );
		$post_ids = array_map( 'absint', array_keys( $posts ) );
		$placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );
		$meta_sql = "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$placeholders}) AND meta_key IN (%s, %s) ORDER BY post_id ASC, meta_key ASC, meta_id ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated integer placeholders are interpolated.
		$meta_rows = $wpdb->get_results( $wpdb->prepare( $meta_sql, array_merge( $post_ids, array( self::META_LOCALIZED_PATH, self::META_CANONICAL_ROUTE ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical route metadata.
		$meta = array();
		foreach ( is_array( $meta_rows ) ? $meta_rows : array() as $row ) { $meta[ absint( $row['post_id'] ?? 0 ) ][ (string) ( $row['meta_key'] ?? '' ) ][] = array( 'meta_id' => absint( $row['meta_id'] ?? 0 ), 'value' => (string) ( $row['meta_value'] ?? '' ) ); }
		foreach ( $meta as $post_id => $keys ) { foreach ( $keys as $key => $rows ) { if ( count( $rows ) > 1 ) { return array( 'success' => false, 'code' => 'public_header_route_meta_ambiguous', 'post_id' => (int) $post_id, 'meta_key' => (string) $key ); } } }
		$urls = array();
		foreach ( $requested_ids as $post_id ) {
			$canonical_raw = (string) ( $meta[ $post_id ][ self::META_CANONICAL_ROUTE ][0]['value'] ?? '' );
			$canonical = '' !== $canonical_raw ? maybe_unserialize( $canonical_raw ) : array();
			$localized_path = trim( (string) ( $meta[ $post_id ][ self::META_LOCALIZED_PATH ][0]['value'] ?? '' ), '/' );
			$contract_url = is_array( $canonical ) ? esc_url_raw( (string) ( $canonical['url'] ?? '' ) ) : '';
			if ( '' === $contract_url && is_array( $canonical ) && '' !== trim( (string) ( $canonical['path'] ?? '' ), '/' ) ) { $contract_url = home_url( '/' . trim( (string) $canonical['path'], '/' ) . '/' ); }
			if ( '' === $contract_url && '' !== $localized_path ) { $contract_url = home_url( '/' . $localized_path . '/' ); }
			$contract_url = self::normalize_primary_navigation_url( $contract_url );
			clean_post_cache( $post_id );
			$url = self::normalize_primary_navigation_url( (string) get_permalink( $post_id ) );
			if ( '' === $url ) { return array( 'success' => false, 'code' => 'public_header_route_url_missing', 'post_id' => $post_id ); }
			if ( '' !== $contract_url && ! hash_equals( $contract_url, $url ) ) { return array( 'success' => false, 'code' => 'public_header_route_permalink_drift', 'post_id' => $post_id, 'contract_url' => $contract_url, 'permalink_url' => $url ); }
			$urls[ $post_id ] = $url;
		}
		ksort( $meta, SORT_NUMERIC ); ksort( $urls, SORT_NUMERIC );
		$surface = array( 'posts' => array_values( $posts ), 'meta' => $meta, 'urls' => $urls );
		return array( 'success' => true, 'post_ids' => $post_ids, 'urls' => $urls, 'surface' => $surface, 'revision' => 'phroute_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $surface ) ) ?: '' ), 0, 40 ) );
	}

	/**
	 * Lock authority/current/staged menus plus canonical relation and route
	 * predicates in deterministic order before the final receipt revalidation.
	 */
	private static function lock_public_header_relation_authority_surface( array $manifest, array $expected, array $replacement, array $staged ): array {
		$receipts = isset( $manifest['relation_receipts'] ) && is_array( $manifest['relation_receipts'] ) ? $manifest['relation_receipts'] : array();
		$relation_authority_present = ! empty( $receipts );
		if ( ! $relation_authority_present && ! empty( $manifest['items'] ) ) { return array( 'success' => false, 'code' => 'public_header_relation_receipts_missing' ); }
		$menu_ids = array(); $post_ids = array(); $route_post_ids = array();
		foreach ( (array) ( $manifest['authority_receipts'] ?? array() ) as $authority_receipt ) {
			foreach ( (array) ( is_array( $authority_receipt ) ? ( $authority_receipt['candidates'] ?? array() ) : array() ) as $candidate ) { $menu_ids[ absint( is_array( $candidate ) ? ( $candidate['menu_id'] ?? 0 ) : 0 ) ] = true; }
		}
		foreach ( array( $expected, $replacement ) as $state ) {
			foreach ( (array) ( is_array( $state['identities'] ?? null ) ? $state['identities'] : array() ) as $identity ) { $menu_ids[ absint( is_array( $identity ) ? ( $identity['menu_id'] ?? 0 ) : 0 ) ] = true; }
		}
		foreach ( $staged as $projection ) { $menu_ids[ absint( is_array( $projection ) ? ( $projection['target_menu']['id'] ?? 0 ) : 0 ) ] = true; }
		foreach ( (array) ( $manifest['items'] ?? array() ) as $item ) { if ( is_array( $item ) && 'page' === sanitize_key( (string) ( $item['type'] ?? '' ) ) ) { $post_ids[ absint( $item['object_id'] ?? 0 ) ] = true; } }
		foreach ( $receipts as $receipt ) {
			foreach ( (array) ( is_array( $receipt ) ? ( $receipt['relations'] ?? array() ) : array() ) as $relation ) {
				if ( ! is_array( $relation ) ) { continue; }
				if ( 'page' === sanitize_key( (string) ( $relation['type'] ?? '' ) ) ) { $post_ids[ absint( $relation['object_id'] ?? 0 ) ] = true; }
				if ( 'internal' === sanitize_key( (string) ( $relation['scope'] ?? '' ) ) ) {
					$post_ids[ absint( $relation['source_post_id'] ?? 0 ) ] = true; $post_ids[ absint( $relation['target_post_id'] ?? 0 ) ] = true;
					foreach ( (array) ( $relation['route_post_ids'] ?? array() ) as $route_post_id ) { $route_post_ids[ absint( $route_post_id ) ] = true; $post_ids[ absint( $route_post_id ) ] = true; }
				}
			}
		}
		unset( $menu_ids[0], $post_ids[0], $route_post_ids[0] );
		$menu_ids = array_keys( $menu_ids ); $post_ids = array_keys( $post_ids ); $route_post_ids = array_keys( $route_post_ids );
		sort( $menu_ids, SORT_NUMERIC ); sort( $post_ids, SORT_NUMERIC ); sort( $route_post_ids, SORT_NUMERIC );
		foreach ( $menu_ids as $menu_id ) {
			$locked = self::lock_localized_menu_projection_surface( (int) $menu_id );
			if ( empty( $locked['success'] ) ) { return array( 'success' => false, 'code' => 'public_header_relation_menu_lock_failed', 'menu_id' => (int) $menu_id, 'lock' => $locked ); }
		}
		global $wpdb;
		if ( ! empty( $post_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );
			$sql = "SELECT ID FROM {$wpdb->posts} WHERE ID IN ({$placeholders}) ORDER BY ID ASC FOR UPDATE"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated integer placeholders are interpolated.
			if ( false === $wpdb->query( $wpdb->prepare( $sql, $post_ids ) ) ) { return array( 'success' => false, 'code' => 'public_header_relation_post_lock_failed' ); } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical route/status rows are locked.
		}
		if ( $relation_authority_present ) {
			$identity_lock = $wpdb->query( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s) ORDER BY meta_key ASC, meta_id ASC FOR UPDATE", self::META_SOURCE_ID, self::META_LANGUAGE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializable predicate lock prevents new canonical relation candidates.
			if ( false === $identity_lock ) { return array( 'success' => false, 'code' => 'public_header_relation_meta_predicate_lock_failed' ); }
		}
		if ( ! empty( $route_post_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $route_post_ids ), '%d' ) );
			$sql = "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id IN ({$placeholders}) AND meta_key IN (%s, %s) ORDER BY post_id ASC, meta_key ASC, meta_id ASC FOR UPDATE"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated integer placeholders are interpolated.
			$args = array_merge( $route_post_ids, array( self::META_LOCALIZED_PATH, self::META_CANONICAL_ROUTE ) );
			if ( false === $wpdb->query( $wpdb->prepare( $sql, $args ) ) ) { return array( 'success' => false, 'code' => 'public_header_route_meta_predicate_lock_failed' ); } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Missing/present route-meta rows share the locked predicate.
		}
		return array( 'success' => true, 'present' => $relation_authority_present, 'menu_ids' => $menu_ids, 'post_ids' => $post_ids, 'route_post_ids' => $route_post_ids, 'canonical_meta_predicate_locked' => $relation_authority_present, 'canonical_route_predicate_locked' => ! empty( $route_post_ids ) );
	}
}
