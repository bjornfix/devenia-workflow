<?php
/**
 * Featured-image repair and canonical thumbnail reads for AI Translation Workflow.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Translation_Featured_Image_Repair {
	/**
	 * Input schema for featured-image workflow repairs.
	 */
	private static function repair_featured_images_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'languages'                        => array(
					'type'        => 'array',
					'description' => 'Optional list of target languages to repair. Defaults to all target languages.',
					'items'       => array( 'type' => 'string' ),
				),
				'source_ids'                       => array(
					'type'        => 'array',
					'description' => 'Optional source page IDs to repair. Defaults to all translated source pages.',
					'items'       => array( 'type' => 'integer' ),
				),
				'dry_run'                          => array( 'type' => 'boolean', 'default' => false ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Repair featured-image drift for existing translated content.
	 */
	private static function repair_featured_images( array $input ): array {
		$dry_run    = ! empty( $input['dry_run'] );
		$languages  = self::repair_language_filter( $input['languages'] ?? array() );
		$source_ids = self::repair_source_filter( $input['source_ids'] ?? array() );
		if ( ! $dry_run && ! current_user_can( 'manage_options' ) ) {
			return array( 'success' => false, 'code' => 'featured_image_repair_forbidden', 'message' => 'Managing Translation Job repair intake requires manage_options.' );
		}

		$posts = array();
		if ( ! self::translation_index_available() ) {
			return array( 'success' => false, 'code' => 'translation_index_required', 'message' => 'Authoritative featured-image repair intake requires the complete Translation Index.' );
		}
		foreach ( self::translation_index_ids( self::translation_workflow_post_statuses( false ) ) as $translation_id ) {
			$post = get_post( $translation_id );
			if ( ! $post instanceof WP_Post ) { continue; }
			if ( $source_ids && ! in_array( absint( get_post_meta( $translation_id, self::META_SOURCE_ID, true ) ), $source_ids, true ) ) { continue; }
			$posts[] = $post;
		}

		$checked = 0;
		$changed = array();
		$skipped = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$translation_id = (int) $post->ID;
			$language       = (string) get_post_meta( $translation_id, self::META_LANGUAGE, true );
			$source_id      = absint( get_post_meta( $translation_id, self::META_SOURCE_ID, true ) );

			if ( '' === $language || ! $source_id ) {
				continue;
			}
			if ( ! in_array( $language, $languages, true ) ) {
				continue;
			}
			if ( $source_ids && ! in_array( $source_id, $source_ids, true ) ) {
				continue;
			}

			$source = get_post( $source_id );
			if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) ) {
				$skipped[] = array(
					'translation_id' => $translation_id,
					'source_id'      => $source_id,
					'language'       => $language,
					'reason'         => 'missing_source',
				);
				continue;
			}
			++$checked;
			$source_media = self::publication_featured_image_revision_identity( $source );
			$translation_media = self::publication_featured_image_revision_identity( $translation_id );
			if ( self::translation_job_canonicalize( $source_media ) === self::translation_job_canonicalize( $translation_media ) ) {
				continue;
			}
			$lifecycle = array( 'success' => true, 'queued' => false );
			if ( ! $dry_run ) {
				self::mark_source_inventory_dirty( $source_id );
				$source_revision = self::source_publication_surface_revision( $source );
				$job_id = self::translation_job_id( $source_id, $language, $source_revision );
				$job = self::translation_job_get_job( $job_id );
				if ( ! $job ) {
					$lifecycle = self::translation_job_discover( array( 'source_id' => $source_id, 'language' => $language, 'observability_label' => 'visible-media-repair' ) );
					$lifecycle['queued'] = ! empty( $lifecycle['success'] );
				} else {
					$lifecycle = self::translation_job_refresh_drifted_surface( $job, 'repair_visible_media_drift' );
					$lifecycle['queued'] = ! empty( $lifecycle['success'] ) && ( ! empty( $lifecycle['refreshed'] ) || in_array( (string) ( $job['status'] ?? '' ), array( 'queued', 'changes_requested' ), true ) );
				}
			}

			$changed[] = array(
				'translation_id'      => $translation_id,
				'source_id'           => $source_id,
				'language'            => $language,
				'changed'             => false,
				'before_thumbnail_id' => absint( $translation_media['attachment_id'] ?? 0 ),
				'after_thumbnail_id'  => absint( $source_media['attachment_id'] ?? 0 ),
				'bounded_lifecycle'   => $lifecycle,
				'url'                 => get_permalink( $translation_id ) ?: '',
			);
		}

		return array(
			'success'       => true,
			'dry_run'       => $dry_run,
			'checked_count' => $checked,
			'changed_count' => 0,
			'queued_count'  => count( array_filter( $changed, static function ( $row ): bool { return ! empty( $row['bounded_lifecycle']['queued'] ); } ) ),
			'skipped_count' => count( $skipped ),
			'changed'       => $changed,
			'skipped'       => $skipped,
		);
	}

	/**
	 * Mirror the source featured image onto a translation.
	 */
	private static function sync_source_featured_image( int $translation_id, WP_Post $source, bool $dry_run = false ): array {
		$source_thumbnail_id      = self::featured_image_id_for_post( $source );
		$translation_thumbnail_id = self::featured_image_id_for_post( $translation_id );
		$changed                  = $source_thumbnail_id !== $translation_thumbnail_id;

		if ( $changed && ! $dry_run ) {
			if ( $source_thumbnail_id ) {
				update_post_meta( $translation_id, '_thumbnail_id', $source_thumbnail_id );
			} else {
				delete_post_meta( $translation_id, '_thumbnail_id' );
			}
			wp_cache_delete( $translation_id, 'post_meta' );
			clean_post_cache( $translation_id );
		}

		$verified_thumbnail_id = $dry_run ? $translation_thumbnail_id : self::featured_image_id_for_post( $translation_id );

		return array(
			'changed'               => $changed,
			'before_thumbnail_id'   => $translation_thumbnail_id,
			'after_thumbnail_id'    => $source_thumbnail_id,
			'verified_thumbnail_id' => $verified_thumbnail_id,
			'write_verified'        => $dry_run || $verified_thumbnail_id === $source_thumbnail_id,
		);
	}

	/**
	 * Return the effective WordPress featured image ID from postmeta, bypassing primed meta caches.
	 *
	 * Translation upsert/publish flows can prime postmeta for many translations in
	 * one request. WordPress uses the first stored single-meta value when duplicate
	 * rows exist, so this read must use the oldest row. Reading the newest row can
	 * make a repair falsely report success while the frontend still renders an
	 * older thumbnail.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 */
	private static function featured_image_id_for_post( $post ): int {
		$post_id = $post instanceof WP_Post ? (int) $post->ID : absint( $post );
		if ( ! $post_id ) {
			return 0;
		}

		global $wpdb;
		$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional canonical _thumbnail_id read; must bypass stale meta caches during translation repair/status flows.
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
				$post_id,
				'_thumbnail_id'
			)
		);

		return absint( $value );
	}
}
