<?php
/**
 * Featured-image repair and canonical thumbnail reads for AI Translation Workflow.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Featured_Image_Repair {
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
				'record_provenance_when_unchanged' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'When true, record writer and visible-media provenance and invalidate reviews even when the translation already matches the source image. Use only to bring an already-applied featured-image repair under workflow provenance.',
				),
				'claim_token'                      => array(
					'type'        => 'string',
					'description' => 'Optional reservation token from ai-translations/reserve-work. Used when repairing one claimed source/language item.',
				),
				'codex_thread_id'                  => array(
					'type'        => 'string',
					'description' => 'Required for writes: the exact CODEX_THREAD_ID environment value. The token authority uses it to verify the server-side workflow lease.',
				),
				'writer_process_id'                => array(
					'type'        => 'string',
					'description' => 'Optional stable identifier for the process/session doing this visible media repair. Defaults to codex_thread_id.',
				),
				'writer_actor'                     => array(
					'type'        => 'string',
					'description' => 'Optional human/operator label for the writer process.',
				),
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
		$record_provenance_when_unchanged = ! empty( $input['record_provenance_when_unchanged'] );
		$step_token_gate = array();
		if ( ! $dry_run ) {
			$step_token_gate = self::translation_step_token_gate( 'draft_write', $input );
			if ( empty( $step_token_gate['success'] ) ) {
				return $step_token_gate;
			}
		}

		$posts = array();
		if ( $source_ids ) {
			foreach ( $source_ids as $source_id ) {
				foreach ( self::translation_posts_for_source( (int) $source_id, self::translation_workflow_post_statuses( false ) ) as $post ) {
					$posts[] = $post;
				}
			}
		} else {
			$query = self::translation_content_query(
				array(
					'post_status'    => self::translation_workflow_post_statuses( false ),
					'posts_per_page' => 1000,
				)
			);
			$posts = $query->posts;
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
			if ( ! $dry_run ) {
				$claim_gate = self::translation_claim_write_gate( $source_id, $language, (string) ( $input['claim_token'] ?? '' ) );
				if ( $claim_gate ) {
					$skipped[] = array(
						'translation_id' => $translation_id,
						'source_id'      => $source_id,
						'language'       => $language,
						'reason'         => sanitize_key( (string) ( $claim_gate['code'] ?? 'reservation_conflict' ) ),
						'message'        => sanitize_text_field( (string) ( $claim_gate['message'] ?? 'Translation work is currently reserved by another worker.' ) ),
					);
					continue;
				}
			}

			++$checked;
			$sync = self::sync_source_featured_image( $translation_id, $source, $dry_run );
			if ( empty( $sync['changed'] ) && ( $dry_run || ! $record_provenance_when_unchanged ) ) {
				continue;
			}
			$review_invalidated  = false;
			$provenance_recorded = false;
			if ( ! $dry_run ) {
				$review_invalidated = self::invalidate_translation_reviews_after_visible_metadata_change( $translation_id, 'featured_image_repair' );
				self::record_translation_writer_provenance( $translation_id, $step_token_gate );
				self::record_translation_visible_media_provenance( $translation_id, $step_token_gate, 'featured_image_repair' );
				self::sync_translation_index_row( $translation_id );
				$provenance_recorded = true;
			}

			$changed[] = array(
				'translation_id'      => $translation_id,
				'source_id'           => $source_id,
				'language'            => $language,
				'changed'             => (bool) $sync['changed'],
				'before_thumbnail_id' => $sync['before_thumbnail_id'],
				'after_thumbnail_id'  => $sync['after_thumbnail_id'],
				'review_invalidated'  => $review_invalidated,
				'provenance_recorded' => $provenance_recorded,
				'media_provenance_recorded' => $provenance_recorded,
				'url'                 => get_permalink( $translation_id ) ?: '',
			);
		}

		if ( ! $dry_run && $changed ) {
			self::flush_sitemap_cache();
		}

		return array(
			'success'       => true,
			'dry_run'       => $dry_run,
			'checked_count' => $checked,
			'changed_count' => count( $changed ),
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
	 * Return the canonical featured image ID from postmeta, bypassing primed meta caches.
	 *
	 * Translation upsert/publish flows can prime postmeta for many translations in
	 * one request. Reading _thumbnail_id from the database keeps repair and status
	 * payloads tied to the stored value instead of a stale object-cache entry.
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
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
				$post_id,
				'_thumbnail_id'
			)
		);

		return absint( $value );
	}
}
