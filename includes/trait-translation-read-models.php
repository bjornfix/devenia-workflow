<?php
/**
 * Translation/source read models and payload shaping helpers.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Translation_Read_Models {
	/**
	 * Translation rows for a source page.
	 */
	private static function translation_rows_for_source( int $source_id, array $post_status = array() ): array {
		$rows = array();
		foreach ( self::translation_posts_for_source( $source_id, $post_status ) as $post ) {
			$rows[] = self::translation_payload( $post );
		}

		return $rows;
	}

	/**
	 * Translation post objects for a source page without expanding review payload.
	 *
	 * @return WP_Post[]
	 */
	private static function translation_posts_for_source( int $source_id, array $post_status = array() ): array {
		static $cache = array();

		$post_status = self::sanitize_translation_post_statuses( $post_status, false );
		$status_key  = implode( '|', array_map( 'sanitize_key', $post_status ) );
		$cache_key   = $source_id . ':' . $status_key;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$posts = array();
		$translation_ids = self::translation_index_ids_for_source( $source_id, $post_status );
		if ( ! empty( $translation_ids ) ) {
			foreach ( $translation_ids as $translation_id ) {
				$post = get_post( $translation_id );
				if ( $post instanceof WP_Post ) {
					$posts[] = $post;
				}
			}
			$cache[ $cache_key ] = $posts;
			return $cache[ $cache_key ];
		}

		$query = self::translation_page_query(
			array(
				'post_status'    => $post_status,
				'posts_per_page' => 1000,
			)
		);

		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post && $source_id === absint( get_post_meta( $post->ID, self::META_SOURCE_ID, true ) ) ) {
				$posts[] = $post;
			}
		}

		$cache[ $cache_key ] = $posts;

		return $cache[ $cache_key ];
	}

	/**
	 * Source hash for stale detection.
	 */
	private static function source_hash( WP_Post $post ): string {
		return self::source_hash_from_values(
			(string) $post->post_title,
			(string) $post->post_excerpt,
			self::normalize_gutenberg_content_for_storage( (string) $post->post_content )
		);
	}

	/**
	 * Legacy raw source hash used before saved-markup normalization became the hash boundary.
	 */
	private static function legacy_source_hash( WP_Post $post ): string {
		return self::source_hash_from_values(
			(string) $post->post_title,
			(string) $post->post_excerpt,
			(string) $post->post_content
		);
	}

	/**
	 * Hash source fields with a single, stable field separator contract.
	 */
	private static function source_hash_from_values( string $title, string $excerpt, string $content ): string {
		return hash( 'sha256', $title . "\n" . $excerpt . "\n" . $content );
	}

	/**
	 * Basic post payload.
	 */
	private static function post_payload( WP_Post $post ): array {
		return array(
			'id'       => (int) $post->ID,
			'post_type'=> (string) $post->post_type,
			'title'    => get_the_title( $post ),
			'slug'     => $post->post_name,
			'status'   => $post->post_status,
			'url'      => get_permalink( $post ),
			'content'  => $post->post_content,
			'excerpt'  => $post->post_excerpt,
			'modified' => $post->post_modified_gmt,
			'featured_image_id' => self::featured_image_id_for_post( $post ),
			'taxonomies' => self::post_taxonomy_payload( $post ),
			'source_generation' => self::source_generation_status_for_source( (int) $post->ID ),
		);
	}

	/**
	 * Compact source payload for queue listings.
	 */
	private static function source_summary_payload( WP_Post $post ): array {
		return array(
			'id'       => (int) $post->ID,
			'post_type'=> (string) $post->post_type,
			'title'    => get_the_title( $post ),
			'slug'     => $post->post_name,
			'status'   => $post->post_status,
			'url'      => get_permalink( $post ),
			'modified' => $post->post_modified_gmt,
			'featured_image_id' => self::featured_image_id_for_post( $post ),
			'taxonomies' => self::post_taxonomy_payload( $post ),
		);
	}

	/**
	 * Translation payload.
	 */
	private static function translation_payload( ?WP_Post $post ): array {
		if ( ! $post ) {
			return array();
		}

		$source_id               = absint( get_post_meta( $post->ID, self::META_SOURCE_ID, true ) );
		$source                  = $source_id ? get_post( $source_id ) : null;
		$hash                    = (string) get_post_meta( $post->ID, self::META_SOURCE_HASH, true );
		$current                 = $source ? self::source_hash( $source ) : '';
		$language                = sanitize_key( (string) get_post_meta( $post->ID, self::META_LANGUAGE, true ) );
		$linguistic_review_state = self::linguistic_review_state_for_post( (int) $post->ID );
		$quality_review_state    = self::quality_review_readiness_for_post( $post, $language );
		$final_review_state      = self::final_review_readiness_for_post( $post, $language );
		$generated_source_id     = absint( get_post_meta( $post->ID, self::META_GENERATED_SOURCE_ID, true ) );
		$featured_image_id       = self::featured_image_id_for_post( $post );

		return array(
			'id'                 => (int) $post->ID,
			'post_type'          => (string) $post->post_type,
			'source_id'          => $source_id,
			'language'           => $language,
			'title'              => get_the_title( $post ),
			'slug'               => $post->post_name,
			'status'             => $post->post_status,
			'translation_status' => (string) get_post_meta( $post->ID, self::META_STATUS, true ),
			'url'                => get_permalink( $post ),
			'featured_image_id'  => $featured_image_id,
			'featured_image_alt' => self::localized_featured_image_alt_for_post( (int) $post->ID, $featured_image_id ),
			'localized_path'     => (string) get_post_meta( $post->ID, self::META_LOCALIZED_PATH, true ),
			'source_hash'        => $hash,
			'current_source_hash'=> $current,
			'is_stale'           => $hash && $current && $hash !== $current,
			'design_inheritance_state' => self::translation_source_design_state( $post, $source ),
			'route_integrity'    => self::translation_route_integrity( (int) $post->ID, $language ),
			'reviewed_at'        => (string) get_post_meta( $post->ID, self::META_REVIEWED_AT, true ),
			'writer_provenance'  => self::translation_writer_provenance( (int) $post->ID ),
			'visible_media_provenance' => self::translation_visible_media_provenance( (int) $post->ID ),
			'linguistic_reviewed_at' => (string) get_post_meta( $post->ID, self::META_LINGUISTIC_REVIEWED_AT, true ),
			'linguistic_reviewer'    => (string) get_post_meta( $post->ID, self::META_LINGUISTIC_REVIEWER, true ),
			'linguistic_reviewer_process' => (string) get_post_meta( $post->ID, self::META_LINGUISTIC_REVIEWER_PROCESS, true ),
			'linguistic_review_note' => (string) get_post_meta( $post->ID, self::META_LINGUISTIC_REVIEW_NOTE, true ),
			'linguistic_review_checks' => self::linguistic_review_checks_for_post( $post->ID ),
			'linguistic_review_evidence' => self::linguistic_review_evidence_for_post( $post->ID ),
			'linguistic_review_state' => $linguistic_review_state,
			'quality_reviewed_at' => (string) get_post_meta( $post->ID, self::META_QUALITY_REVIEWED_AT, true ),
			'quality_reviewer'    => (string) get_post_meta( $post->ID, self::META_QUALITY_REVIEWER, true ),
			'quality_reviewer_process' => (string) get_post_meta( $post->ID, self::META_QUALITY_REVIEWER_PROCESS, true ),
			'quality_review_note' => (string) get_post_meta( $post->ID, self::META_QUALITY_REVIEW_NOTE, true ),
			'quality_review_checks' => self::quality_review_checks_for_post( $post->ID ),
			'quality_review_evidence' => self::quality_review_evidence_for_post( $post->ID ),
			'quality_review_state' => $quality_review_state,
			'final_reviewed_at' => (string) get_post_meta( $post->ID, self::META_FINAL_REVIEWED_AT, true ),
			'final_reviewer'    => (string) get_post_meta( $post->ID, self::META_FINAL_REVIEWER, true ),
			'final_reviewer_process' => (string) get_post_meta( $post->ID, self::META_FINAL_REVIEWER_PROCESS, true ),
			'final_review_note' => (string) get_post_meta( $post->ID, self::META_FINAL_REVIEW_NOTE, true ),
			'final_review_checks' => self::final_review_checks_for_post( $post->ID ),
			'final_review_evidence' => self::final_review_evidence_for_post( $post->ID ),
			'final_review_state' => $final_review_state,
			'copy_feedback_open_count' => count( self::open_copy_feedback_for_post( (int) $post->ID ) ),
			'copy_feedback' => self::copy_feedback_for_post( (int) $post->ID ),
			'taxonomies'         => self::post_taxonomy_payload( $post ),
			'authored_original'  => array(
				'is_authored_original' => $generated_source_id > 0,
				'generated_source_id'  => $generated_source_id,
				'authored_language'    => sanitize_key( (string) get_post_meta( $post->ID, self::META_AUTHORED_LANGUAGE, true ) ),
				'source_generation_status' => $generated_source_id ? self::source_generation_status_for_source( $generated_source_id ) : array(),
			),
		);
	}

	/**
	 * Category and tag payload for posts.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private static function post_taxonomy_payload( WP_Post $post ): array {
		if ( 'post' !== $post->post_type ) {
			return array();
		}

		$out = array();
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$terms = wp_get_post_terms( (int) $post->ID, $taxonomy, array( 'hide_empty' => false ) );
			if ( is_wp_error( $terms ) ) {
				$out[ $taxonomy ] = array();
				continue;
			}
			$out[ $taxonomy ] = array_map(
				static function ( WP_Term $term ): array {
					return array(
						'id'             => (int) $term->term_id,
						'name'           => (string) $term->name,
						'slug'           => (string) $term->slug,
						'taxonomy'       => (string) $term->taxonomy,
						'parent'         => (int) $term->parent,
						'source_term_id' => absint( get_term_meta( (int) $term->term_id, self::TERM_META_SOURCE_ID, true ) ),
						'language'       => sanitize_key( (string) get_term_meta( (int) $term->term_id, self::TERM_META_LANGUAGE, true ) ),
					);
				},
				is_array( $terms ) ? $terms : array()
			);
		}

		return $out;
	}

}
