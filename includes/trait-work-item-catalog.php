<?php
/**
 * Workflow work-item catalog, queue, and production-flow read models.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Work_Item_Catalog {
	/**
	 * Return per-language workflow status for a source page.
	 */
	private static function workflow_status_from_input( array $input ): array {
		return self::workflow_status( absint( $input['source_id'] ?? 0 ), self::queue_detail_level( $input ) );
	}

	/**
	 * Return per-language workflow status for a source page.
	 */
	private static function workflow_status( int $source_id, string $detail_level = 'compact' ): array {
		$source = get_post( $source_id );
		if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) || self::is_translation_post( $source_id ) ) {
			return self::error( 'Source content not found.' );
		}

		$translations = array();
		if ( 'full' === $detail_level ) {
			foreach ( self::translation_rows_for_source( $source_id ) as $row ) {
				$translations[ $row['language'] ] = $row;
			}
		} else {
			foreach ( self::heartbeat_translation_rows_for_source( $source_id ) as $workflow_row ) {
				$row = self::compact_translation_queue_payload_from_workflow_row( $workflow_row, $source );
				if ( ! empty( $row['language'] ) ) {
					$translations[ $row['language'] ] = $row;
				}
			}
		}

		$languages = self::languages();
		$rows      = array();
		foreach ( $languages as $language => $config ) {
			if ( ! empty( $config['source'] ) ) {
				continue;
			}
			$row         = $translations[ $language ] ?? null;
			$state       = $row ? self::queue_state_for_translation( $row ) : 'missing';
			$reservation = self::translation_reservation_for_language( $source_id, $language );
			if ( $reservation && 'complete' !== $state ) {
				$state = 'reserved';
			}
			$rows[] = array(
				'language'    => $language,
				'name'        => $config['name'] ?? strtoupper( $language ),
				'flag'        => $config['flag'] ?? strtoupper( $language ),
				'prefix'      => $config['prefix'] ?? '',
				'state'       => $state,
				'translation' => $row,
				'reservation' => $reservation ? self::public_translation_reservation( $reservation ) : null,
			);
		}

		return array(
			'success'     => true,
			'source'      => 'full' === $detail_level ? self::post_payload( $source ) : self::source_summary_payload( $source ),
			'source_hash' => self::source_hash( $source ),
			'languages'   => $rows,
			'detail_level'=> $detail_level,
			'read_model'  => 'full' === $detail_level ? 'translation_payload' : 'work_item_catalog',
		);
	}

	/**
	 * Report review and publish obligations without turning them into a global
	 * stop sign for ongoing source/draft production.
	 */
	private static function workflow_obligations( array $input ): array {
		$source_id = absint( $input['source_id'] ?? 0 );
		$limit = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 100;
		$include_items = array_key_exists( 'include_items', $input ) ? (bool) $input['include_items'] : true;
		$sources = self::workflow_source_candidates( $source_id, $limit );
		if ( $source_id && empty( $sources ) ) {
			return self::error( 'Source content not found.' );
		}
		$catalog = self::workflow_work_items_for_sources( $sources, $include_items );

		return array(
			'success' => true,
			'flow_policy' => array(
				'default_workflow_step' => 'draft_write',
				'open_reviews_must_be_visible' => true,
				'open_reviews_block_new_draft_work' => false,
				'publish_requires_current_reviews' => true,
				'source_updates_with_existing_translations_require_reprojection' => true,
				'real_reader_decision_safety_required' => true,
				'currentness_and_historical_context_required' => true,
				'purpose' => 'produce_source_content_translate_review_publish_when_quality_is_high_enough',
			),
			'totals' => $catalog['totals'],
			'items' => $include_items ? $catalog['items'] : array(),
			'read_model' => 'work_item_catalog',
		);
	}

	/**
	 * Report whether a proposed source rewrite would create translation drift.
	 *
	 * This is intentionally read-only. It lets production agents see that source
	 * design/copy work can continue as preparation while the actual live source
	 * update needs a translation reprojection plan first.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @param array<string,mixed> $obligation_items Compact translation rows.
	 * @return array<string,mixed>
	 */
	private static function proposed_source_update_impact( array $input, array $obligation_items ): array {
		$source_id = absint( $input['source_id'] ?? 0 );
		if ( ! $source_id || ! array_key_exists( 'proposed_source_content', $input ) ) {
			return array(
				'checked' => false,
				'reason'  => 'provide_source_id_and_proposed_source_content_to_check_before_applying_a_source_update',
			);
		}

		$source = get_post( $source_id );
		if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) || self::is_translation_post( $source_id ) ) {
			return array(
				'checked' => false,
				'reason'  => 'source_content_not_found',
			);
		}

		$proposed_title       = array_key_exists( 'proposed_source_title', $input ) ? (string) $input['proposed_source_title'] : (string) $source->post_title;
		$proposed_excerpt     = array_key_exists( 'proposed_source_excerpt', $input ) ? (string) $input['proposed_source_excerpt'] : (string) $source->post_excerpt;
		$proposed_content     = self::normalize_gutenberg_content_for_storage( (string) $input['proposed_source_content'] );
		$editorial_validation = self::source_editorial_design_validation( $source, $proposed_content );
		$editorial_blocks     = empty( $editorial_validation['passed'] );
		$current_hash         = self::source_hash( $source );
		$proposed_hash        = self::source_hash_from_values( $proposed_title, $proposed_excerpt, $proposed_content );
		$source_changes       = $proposed_hash !== $current_hash;
		$translations         = self::translation_rows_for_source( $source_id );
		$requires             = array();
		$published_count      = 0;

		foreach ( $translations as $translation ) {
			$translation_id = absint( $translation['id'] ?? 0 );
			if ( ! $translation_id ) {
				continue;
			}
			$post_status = sanitize_key( (string) ( $translation['status'] ?? '' ) );
			if ( 'publish' === $post_status ) {
				++$published_count;
			}
			$stored_hash = (string) ( $translation['source_hash'] ?? '' );
			if ( $source_changes && $stored_hash !== $proposed_hash ) {
				$requires[] = array(
					'source_id' => $source_id,
					'source_title' => get_the_title( $source ),
					'translation_id' => $translation_id,
					'language' => sanitize_key( (string) ( $translation['language'] ?? '' ) ),
					'post_status' => $post_status,
					'obligations' => array( 'source_reprojection' ),
				);
			}
		}

		return array(
			'checked' => true,
			'source_changes' => $source_changes,
			'current_source_hash' => $current_hash,
			'proposed_source_hash' => $proposed_hash,
			'existing_translation_count'      => count( $translations ),
			'published_translation_count'     => $published_count,
			'requires_reprojection_count'     => count( $requires ),
			'editorial_source_validation'     => $editorial_validation,
			'safe_to_apply_source_update_now' => ( ! $source_changes || 0 === count( $requires ) ) && ! $editorial_blocks,
			'blocking_reason'                 => $editorial_blocks
				? 'proposed_source_update_fails_devenia_presentation_contract'
				: ( ( $source_changes && $requires )
					? 'proposed_source_update_would_make_existing_translations_stale'
					: null ),
			'next_action'                     => $editorial_blocks
				? 'fix_source_design_until_selected_devenia_presentation_contract_passes'
				: ( ( $source_changes && $requires )
					? 'prepare_localized_fragments_and_reproject_translations_before_or_with_source_update'
					: null ),
			'items'                           => $obligation_items ? $requires : array(),
		);
	}

	/**
	 * One compact workflow dashboard for agents: production keeps moving, review
	 * debt stays visible, and publish is gated per translation.
	 */
	private static function production_flow( array $input ): array {
		$source_id = absint( $input['source_id'] ?? 0 );
		$limit = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 100;
		$include_items = array_key_exists( 'include_items', $input ) ? (bool) $input['include_items'] : true;
		$params = array(
			'limit' => $limit,
			'include_items' => $include_items,
		);
		if ( $source_id ) {
			$params['source_id'] = $source_id;
		}

		$obligations = self::workflow_obligations( $params );
		if ( empty( $obligations['success'] ) ) {
			return $obligations;
		}
		$totals = $obligations['totals'] ?? array();
		$review_backlog = absint( $totals['needs_linguistic_review'] ?? 0 ) + absint( $totals['needs_quality_review'] ?? 0 ) + absint( $totals['needs_final_review'] ?? 0 );
		$ready_to_publish = absint( $totals['ready_to_publish'] ?? 0 );
		$blocked_from_publish = absint( $totals['blocked_from_publish'] ?? 0 );
		$source_update = self::proposed_source_update_impact( $input, $include_items ? ( $obligations['items'] ?? array() ) : array() );
		$requires_reprojection = absint( $source_update['requires_reprojection_count'] ?? 0 );

		return array(
			'success' => true,
			'flow_policy' => $obligations['flow_policy'],
			'lanes' => array(
				'production' => array(
					'default_workflow_step' => 'draft_write',
					'can_continue_new_draft_work' => true,
					'can_apply_proposed_source_update_now' => (bool) ( $source_update['safe_to_apply_source_update_now'] ?? true ),
					'blocking_reason' => $source_update['blocking_reason'] ?? null,
					'next_action' => $requires_reprojection > 0
						? 'claim_draft_write_and_prepare_reprojection_before_applying_source_update'
						: 'claim_draft_write_when_writing_new_source_or_translation_draft',
				),
				'reprojection' => array(
					'requires_reprojection_count' => $requires_reprojection,
					'published_translation_count' => absint( $source_update['published_translation_count'] ?? 0 ),
					'blocks_new_draft_work' => false,
					'blocks_applying_that_source_update' => $requires_reprojection > 0,
					'next_action' => $source_update['next_action'] ?? null,
				),
				'review' => array(
					'open_review_obligation_count' => $review_backlog,
					'must_remain_visible' => $review_backlog > 0,
					'blocks_new_draft_work' => false,
					'next_action' => $review_backlog > 0 ? 'schedule_or_claim_separate_reviewer_work' : null,
				),
				'publish' => array(
					'ready_to_publish_count' => $ready_to_publish,
					'blocked_from_publish_count' => $blocked_from_publish,
					'publish_gate' => 'specific_translation_requires_current_linguistic_quality_and_final_review',
					'next_action' => $ready_to_publish > 0 ? 'claim_publish_when_explicitly_instructed' : null,
				),
			),
			'source_update' => $source_update,
			'totals' => $totals,
			'items' => $include_items ? ( $obligations['items'] ?? array() ) : array(),
		);
	}

	/**
	 * Return candidate source posts/pages for work-item planning.
	 *
	 * @return array<int,WP_Post>
	 */
	private static function workflow_source_candidates( int $source_id = 0, int $limit = 100 ): array {
		$sources = array();

		if ( $source_id ) {
			$source = get_post( $source_id );
			if ( $source && self::is_translatable_post_type( (string) $source->post_type ) && ! self::is_translation_post( $source_id ) ) {
				$sources[] = $source;
			}
			return $sources;
		}

		$scan_limit = max( $limit, min( 2000, max( 500, $limit * 4 ) ) );
		foreach ( self::source_content_integrity_workflow_source_candidates( $scan_limit ) as $candidate ) {
			self::add_workflow_source_candidate( $sources, $candidate, $limit );
			if ( count( $sources ) >= $limit ) {
				return $sources;
			}
		}

		foreach ( self::source_design_workflow_source_candidates( $scan_limit ) as $candidate ) {
			self::add_workflow_source_candidate( $sources, $candidate, $limit );
			if ( count( $sources ) >= $limit ) {
				return $sources;
			}
		}

		foreach ( self::source_taxonomy_workflow_source_candidates( $scan_limit ) as $candidate ) {
			self::add_workflow_source_candidate( $sources, $candidate, $limit );
			if ( count( $sources ) >= $limit ) {
				return $sources;
			}
		}

		foreach ( self::translation_workflow_source_candidates( $scan_limit ) as $candidate ) {
			self::add_workflow_source_candidate( $sources, $candidate, $limit );
			if ( count( $sources ) >= $limit ) {
				return $sources;
			}
		}

		return $sources;
	}

	/**
	 * Candidate source content that needs source content-integrity inspection.
	 *
	 * @return array<int,WP_Post>
	 */
	private static function source_content_integrity_workflow_source_candidates( int $scan_limit ): array {
		$query = self::source_content_query(
			array(
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 2000, max( $scan_limit, 2000 ) ) ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Integrity queue must include original source content only.
					array(
						'key'     => self::META_SOURCE_ID,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$sources = array();
		foreach ( $query->posts as $candidate ) {
			if ( ! $candidate instanceof WP_Post ) {
				continue;
			}
			if ( ! self::source_content_integrity_repair_work_item( $candidate ) ) {
				continue;
			}
			$sources[] = $candidate;
		}

		return $sources;
	}

	/**
	 * Candidate source posts that need source-design inspection, independent of translation rows.
	 *
	 * @return array<int,WP_Post>
	 */
	private static function source_design_workflow_source_candidates( int $scan_limit ): array {
		$query = self::source_content_query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 2000, $scan_limit ) ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Source-design queue must include legacy original posts, not translation posts.
					array(
						'key'     => self::META_SOURCE_ID,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$sources = array();
		foreach ( $query->posts as $candidate ) {
			if ( ! $candidate instanceof WP_Post ) {
				continue;
			}
			if ( ! self::source_design_repair_work_item( $candidate ) ) {
				continue;
			}
			$sources[] = $candidate;
		}

		return $sources;
	}

	/**
	 * Candidate source posts that need category/tag assignment review.
	 *
	 * @return array<int,WP_Post>
	 */
	private static function source_taxonomy_workflow_source_candidates( int $scan_limit ): array {
		$query = self::source_content_query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 2000, $scan_limit ) ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Source taxonomy queue must include original posts whose taxonomy review is missing or stale.
					array(
						'key'     => self::META_SOURCE_ID,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$sources = array();
		foreach ( $query->posts as $candidate ) {
			if ( ! $candidate instanceof WP_Post ) {
				continue;
			}
			if ( ! self::source_taxonomy_review_work_item( $candidate ) ) {
				continue;
			}
			$sources[] = $candidate;
		}

		return $sources;
	}

	/**
	 * Candidate source content for translation workflow work.
	 *
	 * @return array<int,WP_Post>
	 */
	private static function translation_workflow_source_candidates( int $scan_limit ): array {
		$query = self::source_page_query(
			array(
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 2000, $scan_limit ) ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		return array_values(
			array_filter(
				$query->posts,
				static function ( $candidate ): bool {
					return $candidate instanceof WP_Post;
				}
			)
		);
	}

	/**
	 * Add one source candidate while preserving first-seen priority and original-only scope.
	 *
	 * @param array<int,WP_Post> $sources Source accumulator.
	 */
	private static function add_workflow_source_candidate( array &$sources, WP_Post $candidate, int $limit ): void {
		if ( count( $sources ) >= $limit ) {
			return;
		}
		if ( ! self::is_translatable_post_type( (string) $candidate->post_type ) || self::is_translation_post( (int) $candidate->ID ) ) {
			return;
		}
		foreach ( $sources as $source ) {
			if ( (int) $source->ID === (int) $candidate->ID ) {
				return;
			}
		}
		$sources[] = $candidate;
	}

	/**
	 * Work Item Catalog for heartbeat and queue surfaces.
	 *
	 * This is the single Interface that names workflow work. Callers should not
	 * infer actions independently from translation rows.
	 *
	 * @param array<int,WP_Post> $sources Source posts/pages.
	 * @param bool               $include_items Include concrete work items.
	 * @param bool               $include_publish_items Include publish actions in item payloads. Heartbeat keeps this false.
	 * @return array{totals:array<string,int>,items:array<int,array<string,mixed>>}
	 */
	private static function workflow_work_items_for_sources( array $sources, bool $include_items = true, bool $include_publish_items = true ): array {
		$totals = array(
			'sources_scanned'            => count( $sources ),
			'content_integrity_repair'   => 0,
			'source_design_repair'       => 0,
			'source_taxonomy_review'     => 0,
			'translations_seen'          => 0,
			'missing_translations'       => 0,
			'needs_source_reprojection'  => 0,
			'needs_draft_work'           => 0,
			'needs_route_repair'         => 0,
			'needs_linguistic_review'    => 0,
			'needs_quality_review'       => 0,
			'needs_final_review'         => 0,
			'ready_to_publish'           => 0,
			'published'                  => 0,
			'blocked_from_publish'       => 0,
		);
		$items = array();

		foreach ( $sources as $source ) {
			if ( ! $source instanceof WP_Post ) {
				continue;
			}

			$source_content_item = self::source_content_integrity_repair_work_item( $source );
			if ( $source_content_item ) {
				++$totals['content_integrity_repair'];
				++$totals['needs_draft_work'];
				if ( $include_items ) {
					$items[] = $source_content_item;
				}
				continue;
			}

			$source_design_item = self::source_design_repair_work_item( $source );
			if ( $source_design_item ) {
				++$totals['source_design_repair'];
				++$totals['needs_draft_work'];
				if ( $include_items ) {
					$items[] = $source_design_item;
				}
				continue;
			}

			$source_taxonomy_item = self::source_taxonomy_review_work_item( $source );
			if ( $source_taxonomy_item ) {
				++$totals['source_taxonomy_review'];
				++$totals['needs_draft_work'];
				if ( $include_items ) {
					$items[] = $source_taxonomy_item;
				}
				continue;
			}

			$current_source_hash    = self::source_hash( $source );
			$source_language_tokens = self::source_language_slug_tokens_for_post( $source );
			$translations_by_language = array();
			foreach ( self::heartbeat_translation_rows_for_source( (int) $source->ID ) as $translation ) {
				$translation_language = sanitize_key( (string) ( $translation['language'] ?? '' ) );
				if ( '' !== $translation_language ) {
					$translations_by_language[ $translation_language ] = $translation;
				}
			}

			foreach ( self::target_languages() as $language => $config ) {
				$language    = sanitize_key( (string) $language );
				$translation = $translations_by_language[ $language ] ?? array();
				if ( empty( $translation ) ) {
					++$totals['missing_translations'];
					++$totals['needs_draft_work'];
					if ( $include_items ) {
						$items[] = self::workflow_work_item(
							'draft_write',
							'translation',
							(int) $source->ID,
							0,
							$language,
							array(
								'source_title' => get_the_title( $source ),
								'language_name' => sanitize_text_field( (string) ( $config['name'] ?? strtoupper( $language ) ) ),
								'post_status' => '',
								'writer_token_label' => '',
								'linguistic' => 'missing_translation',
								'quality' => 'missing_translation',
								'final' => 'missing_translation',
							)
						);
					}
					continue;
				}

				$translation_id = absint( $translation['id'] ?? 0 );
				if ( ! $translation_id ) {
					continue;
				}
				++$totals['translations_seen'];

				$post_status = sanitize_key( (string) ( $translation['status'] ?? '' ) );
				$source_hash = (string) ( $translation['source_hash'] ?? '' );
				$linguistic_reviewed_at = (string) ( $translation['linguistic_reviewed_at'] ?? '' );
				$quality_reviewed_at = (string) ( $translation['quality_reviewed_at'] ?? '' );
				$final_reviewed_at = (string) ( $translation['final_reviewed_at'] ?? '' );
				$slug = sanitize_title( (string) ( $translation['slug'] ?? '' ) );
				$obligations = array();
				$linguistic_state = '' === $linguistic_reviewed_at ? 'needs_linguistic_review' : 'reviewed_recorded';
				$quality_state = '' === $quality_reviewed_at ? 'needs_quality_review' : 'quality_review_recorded';
				$final_state = '' === $final_reviewed_at ? 'needs_final_review' : 'final_review_recorded';
				$route_issue = self::heartbeat_translation_slug_language_issue( $slug, $language, $source, $source_language_tokens );
				$content_integrity = ! empty( $translation['content_integrity_issue_count'] );

				if ( $content_integrity ) {
					++$totals['content_integrity_repair'];
					++$totals['needs_draft_work'];
					$obligations[]   = 'content_integrity_repair';
					$linguistic_state = 'content_integrity_repair_required';
				} elseif ( $route_issue ) {
					++$totals['needs_route_repair'];
					++$totals['needs_draft_work'];
					$obligations[]   = 'route_repair';
					$linguistic_state = 'route_repair_required';
				} elseif ( '' !== $source_hash && '' !== $current_source_hash && $source_hash !== $current_source_hash ) {
					++$totals['needs_source_reprojection'];
					++$totals['needs_draft_work'];
					$obligations[] = 'source_reprojection';
				} elseif ( '' === $linguistic_reviewed_at ) {
					++$totals['needs_linguistic_review'];
					$obligations[] = 'linguistic_review';
				}
				if ( '' === $quality_reviewed_at ) {
					++$totals['needs_quality_review'];
					$obligations[] = 'quality_review';
				}
				if ( '' === $final_reviewed_at ) {
					++$totals['needs_final_review'];
					$obligations[] = 'final_review';
				}

				if ( 'publish' === $post_status ) {
					++$totals['published'];
				} elseif ( empty( $obligations ) ) {
					++$totals['ready_to_publish'];
					if ( $include_items && $include_publish_items ) {
						$items[] = self::workflow_work_item(
							'publish',
							'translation',
							(int) $source->ID,
							$translation_id,
							$language,
							array(
								'source_title' => get_the_title( $source ),
								'post_status' => $post_status,
								'writer_token_label' => sanitize_key( (string) ( $translation['writer_token_label'] ?? '' ) ),
								'obligations' => array( 'publish' ),
								'linguistic' => $linguistic_state,
								'quality' => $quality_state,
								'final' => $final_state,
							)
						);
					}
				} else {
					++$totals['blocked_from_publish'];
				}

				if ( $include_items && $obligations ) {
					$items[] = self::workflow_work_item(
						$obligations[0],
						'translation',
						(int) $source->ID,
						$translation_id,
						$language,
						array(
							'source_title' => get_the_title( $source ),
							'post_status' => $post_status,
							'writer_token_label' => sanitize_key( (string) ( $translation['writer_token_label'] ?? '' ) ),
							'obligations' => array_values( array_unique( $obligations ) ),
							'linguistic' => $linguistic_state,
							'quality' => $quality_state,
							'final' => $final_state,
						)
					);
				}
			}
		}

		return array(
			'totals' => $totals,
			'items'  => $include_items ? $items : array(),
		);
	}

	private static function source_content_integrity_repair_work_item( WP_Post $source ): array {
		$validation = self::source_content_integrity_validation( $source );
		if ( empty( $validation['issue_count'] ) ) {
			return array();
		}

		return self::workflow_work_item(
			'content_integrity_repair',
			'source',
			(int) $source->ID,
			0,
			'',
			array(
				'source_title' => get_the_title( $source ),
				'post_status' => sanitize_key( (string) $source->post_status ),
				'content_integrity' => $validation,
				'obligations' => array( 'content_integrity_repair' ),
				'linguistic' => 'content_integrity_repair_required',
				'quality' => 'content_integrity_repair_required',
				'final' => 'content_integrity_repair_required',
			)
		);
	}

	private static function source_design_repair_work_item( WP_Post $source ): array {
		if ( 'post' !== (string) $source->post_type ) {
			return array();
		}

		$validation = self::source_editorial_design_validation( $source, (string) $source->post_content );
		if ( ! empty( $validation['passed'] ) ) {
			return array();
		}

		$validation_summary = self::source_editorial_design_validation_summary( $validation );

		return self::workflow_work_item(
			'source_design_repair',
			'source',
			(int) $source->ID,
			0,
			'',
			array(
				'source_title' => get_the_title( $source ),
				'post_status' => sanitize_key( (string) $source->post_status ),
				'article_type' => sanitize_key( (string) ( $validation_summary['article_type'] ?? '' ) ),
				'template_id' => sanitize_text_field( (string) ( $validation_summary['template_id'] ?? '' ) ),
				'template_slug' => sanitize_text_field( (string) ( $validation_summary['template_slug'] ?? '' ) ),
				'template_version' => sanitize_text_field( (string) ( $validation_summary['template_version'] ?? '' ) ),
				'editorial_source_validation' => $validation_summary,
				'obligations' => array( 'source_design_repair' ),
				'linguistic' => 'source_design_repair_required',
				'quality' => 'source_design_repair_required',
				'final' => 'source_design_repair_required',
			)
		);
	}

	private static function source_taxonomy_review_work_item( WP_Post $source ): array {
		if ( 'post' !== (string) $source->post_type ) {
			return array();
		}

		$review = self::source_taxonomy_review_state( $source );
		if ( ! empty( $review['passed'] ) ) {
			return array();
		}

		return self::workflow_work_item(
			'source_taxonomy_review',
			'source',
			(int) $source->ID,
			0,
			'',
			array(
				'source_title' => get_the_title( $source ),
				'post_status' => sanitize_key( (string) $source->post_status ),
				'source_taxonomy' => $review,
				'obligations' => array( 'source_taxonomy_review' ),
				'linguistic' => 'source_taxonomy_review_required',
				'quality' => 'source_taxonomy_review_required',
				'final' => 'source_taxonomy_review_required',
			)
		);
	}

	private static function workflow_work_item( string $work_type, string $scope, int $source_id, int $translation_id, string $language, array $extra = array() ): array {
		$work_type = sanitize_key( $work_type );
		$scope     = 'source' === sanitize_key( $scope ) ? 'source' : 'translation';
		$item = array(
			'work_type'      => $work_type,
			'work_scope'     => $scope,
			'reservation_key'=> 'source' === $scope ? self::source_work_reservation_option_name( $source_id, $work_type ) : self::translation_reservation_option_name( $source_id, $language ),
			'source_id'      => $source_id,
			'source_title'   => sanitize_text_field( (string) ( $extra['source_title'] ?? '' ) ),
			'translation_id' => $translation_id,
			'language'       => sanitize_key( $language ),
			'post_status'    => sanitize_key( (string) ( $extra['post_status'] ?? '' ) ),
			'writer_token_label' => sanitize_key( (string) ( $extra['writer_token_label'] ?? '' ) ),
			'obligations'    => isset( $extra['obligations'] ) && is_array( $extra['obligations'] ) ? array_values( array_unique( array_map( 'sanitize_key', $extra['obligations'] ) ) ) : array( $work_type ),
			'linguistic'     => sanitize_key( (string) ( $extra['linguistic'] ?? '' ) ),
			'quality'        => sanitize_key( (string) ( $extra['quality'] ?? '' ) ),
			'final'          => sanitize_key( (string) ( $extra['final'] ?? '' ) ),
		);

		if ( isset( $extra['language_name'] ) ) {
			$item['language_name'] = sanitize_text_field( (string) $extra['language_name'] );
		}
		foreach ( array( 'article_type', 'template_id', 'template_slug', 'template_version' ) as $contract_field ) {
			if ( isset( $extra[ $contract_field ] ) ) {
				$item[ $contract_field ] = 'article_type' === $contract_field
					? sanitize_key( (string) $extra[ $contract_field ] )
					: sanitize_text_field( (string) $extra[ $contract_field ] );
			}
		}
		if ( isset( $extra['editorial_source_validation'] ) && is_array( $extra['editorial_source_validation'] ) ) {
			$item['editorial_source_validation'] = $extra['editorial_source_validation'];
		}
		if ( isset( $extra['content_integrity'] ) && is_array( $extra['content_integrity'] ) ) {
			$item['content_integrity'] = $extra['content_integrity'];
		}
		if ( isset( $extra['source_taxonomy'] ) && is_array( $extra['source_taxonomy'] ) ) {
			$item['source_taxonomy'] = $extra['source_taxonomy'];
		}

		return $item;
	}

	/**
	 * Return a compact translation work queue for source pages.
	 */
	private static function translation_queue( array $input ): array {
		$source_id        = absint( $input['source_id'] ?? 0 );
		$limit            = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 50;
		$include_complete = ! empty( $input['include_complete'] );
		$status_filter    = self::queue_status_filter( $input['statuses'] ?? array() );
		$detail_level     = self::queue_detail_level( $input );
		$sources          = array();

		if ( $source_id ) {
			$source = get_post( $source_id );
			if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) || self::is_translation_post( $source_id ) ) {
				return self::error( 'Source content not found.' );
			}
			$sources[] = $source;
		} else {
			$sources = self::workflow_source_candidates( 0, $limit );
		}

		$items  = array();
		$totals = array(
			'content_integrity_repair' => 0,
			'source_design_repair'     => 0,
			'source_taxonomy_review'   => 0,
			'missing'                  => 0,
			'stale'                   => 0,
			'draft'                   => 0,
			'needs_review'            => 0,
			'needs_linguistic_review' => 0,
			'ready_to_publish'        => 0,
			'reserved'                => 0,
			'complete'                => 0,
		);

		foreach ( $sources as $source ) {
			$item = self::queue_item_for_source( $source, $status_filter, $detail_level );
			foreach ( $item['source_work_items'] ?? array() as $source_work_item ) {
				if ( isset( $totals[ $source_work_item['state'] ] ) ) {
					++$totals[ $source_work_item['state'] ];
				}
			}
			foreach ( $item['languages'] as $language_row ) {
				if ( isset( $totals[ $language_row['state'] ] ) ) {
					++$totals[ $language_row['state'] ];
				}
			}

			if ( ( ! empty( $status_filter ) && ! empty( $item['languages'] ) ) || $item['action_count'] > 0 || $include_complete ) {
				$items[] = $item;
			}
		}

		return array(
			'success'          => true,
			'items'            => $items,
			'item_count'       => count( $items ),
			'inspected_count'  => count( $sources ),
			'totals'           => $totals,
			'status_filter'    => array_values( $status_filter ),
			'include_complete' => $include_complete,
			'detail_level'     => $detail_level,
		);
	}

	/**
	 * Return only translations that are waiting for review or publish.
	 */
	private static function review_queue( array $input ): array {
		$params = array(
			'limit'            => isset( $input['limit'] ) ? absint( $input['limit'] ) : 100,
			'include_complete' => false,
			'statuses'         => array( 'needs_review', 'needs_linguistic_review', 'ready_to_publish' ),
			'detail_level'     => self::queue_detail_level( $input ),
		);

		if ( ! empty( $input['source_id'] ) ) {
			$params['source_id'] = absint( $input['source_id'] );
		}

		$result = self::translation_queue( $params );
		if ( ! empty( $result['success'] ) ) {
			$result['queue'] = 'review';
			$result['review_status_order'] = array( 'needs_review', 'needs_linguistic_review', 'ready_to_publish' );
		}

		return $result;
	}

	/**
	 * Return author archive localization queue rows.
	 */
	private static function author_archive_queue( array $input ): array {
		$author_id        = absint( $input['author_id'] ?? 0 );
		$limit            = isset( $input['limit'] ) ? max( 1, min( 200, absint( $input['limit'] ) ) ) : 50;
		$include_complete = ! empty( $input['include_complete'] );
		$status_filter    = self::queue_status_filter( $input['statuses'] ?? array() );
		$registry         = self::author_archive_registry();
		$users            = array();

		if ( $author_id ) {
			$user = get_user_by( 'id', $author_id );
			if ( ! $user instanceof WP_User ) {
				return self::error( 'Author not found.' );
			}
			$users[] = $user;
		} else {
			$users = get_users(
				array(
					'has_published_posts' => array( 'post' ),
					'number'              => $limit,
					'orderby'             => 'post_count',
					'order'               => 'DESC',
				)
			);
		}

		$items = array();
		$totals = array(
			'missing'      => 0,
			'stale'        => 0,
			'draft'        => 0,
			'needs_review' => 0,
			'complete'     => 0,
		);

		foreach ( $users as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}
			$source_hash   = self::author_archive_source_hash( $user );
			$language_rows = array();
			$action_count  = 0;
			foreach ( self::target_languages() as $language => $config ) {
				$record = $registry[ (int) $user->ID ][ $language ] ?? array();
				$state  = self::author_archive_queue_state( $record, $source_hash );
				if ( ! empty( $status_filter ) && ! in_array( $state, $status_filter, true ) ) {
					continue;
				}
				if ( isset( $totals[ $state ] ) ) {
					++$totals[ $state ];
				}
				if ( 'complete' !== $state ) {
					++$action_count;
				}
				$language_rows[] = array(
					'language'    => $language,
					'name'        => $config['name'] ?? strtoupper( (string) $language ),
					'flag'        => $config['flag'] ?? strtoupper( (string) $language ),
					'state'       => $state,
					'action'      => self::author_archive_queue_action_for_state( $state ),
					'url'         => self::author_archive_url( (int) $user->ID, (string) $language ),
					'translation' => $record,
				);
			}

			if ( ( ! empty( $status_filter ) && ! empty( $language_rows ) ) || $action_count > 0 || $include_complete ) {
				$items[] = array(
					'surface'      => 'author_archive',
					'author'       => self::author_archive_source_payload( $user ),
					'source_hash'  => $source_hash,
					'languages'    => $language_rows,
					'action_count' => $action_count,
				);
			}
		}

		return array(
			'success'          => true,
			'queue'            => 'author_archive',
			'items'            => $items,
			'item_count'       => count( $items ),
			'inspected_count'  => count( $users ),
			'totals'           => $totals,
			'status_filter'    => array_values( $status_filter ),
			'include_complete' => $include_complete,
		);
	}

	/**
	 * Return published pages that need a full visible-page quality review.
	 */
	private static function quality_review_queue( array $input ): array {
		$page_id          = absint( $input['page_id'] ?? 0 );
		$source_id        = absint( $input['source_id'] ?? 0 );
		$limit            = isset( $input['limit'] ) ? max( 1, min( 1000, absint( $input['limit'] ) ) ) : 100;
		$include_reviewed = ! empty( $input['include_reviewed'] );
		$include_source   = ! empty( $input['include_source'] );
		$requested_order  = (string) ( $input['order'] ?? 'modified_asc' );
		$order            = in_array( $requested_order, array( 'modified_asc', 'modified_desc', 'title_asc' ), true ) ? $requested_order : 'modified_asc';
		$detail_level     = self::queue_detail_level( $input );
		$languages        = self::quality_review_language_filter( $input['languages'] ?? array(), $include_source );
		$status_filter    = self::quality_review_status_filter( $input['statuses'] ?? array() );
		$posts            = array();
		$use_candidate_batches = false;
		$target_language_filter = array();
		$inspected_count = 0;
		$query_page_count = 0;

		if ( $page_id ) {
			$post = get_post( $page_id );
			if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
				return self::error( 'Content not found.' );
			}
			$posts[] = $post;
		} elseif ( $source_id ) {
			$source = get_post( $source_id );
			if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) || self::is_translation_post( $source_id ) ) {
				return self::error( 'Source content not found.' );
			}
			if ( $include_source ) {
				$posts[] = $source;
			}
			foreach ( self::translation_rows_for_source( $source_id, array( 'publish' ) ) as $row ) {
				if ( ! empty( $row['id'] ) ) {
					$post = get_post( absint( $row['id'] ) );
					if ( $post ) {
						$posts[] = $post;
					}
				}
			}
		} else {
			$target_language_filter = array_values(
				array_intersect(
					$languages,
					array_keys( self::target_languages() )
				)
			);

			if ( 'title_asc' === $order ) {
				$posts = self::quality_review_candidate_posts( $order, $target_language_filter, $include_source, 1000, 1 );
				$query_page_count = 1;
			} else {
				$use_candidate_batches = true;
			}
		}

		$items = array();
		$totals = array(
			'needs_quality_review' => 0,
			'quality_review_stale' => 0,
			'reviewed'             => 0,
			'not_published'        => 0,
		);

		$process_post = function ( WP_Post $post ) use ( &$items, &$totals, &$inspected_count, $languages, $status_filter, $include_reviewed, $detail_level, $limit ): bool {
			++$inspected_count;
			$item = self::quality_review_queue_item( $post, $detail_level );
			if ( ! in_array( $item['language'], $languages, true ) ) {
				return false;
			}
			if ( isset( $totals[ $item['state'] ] ) ) {
				++$totals[ $item['state'] ];
			}
			if ( ! empty( $status_filter ) && ! in_array( $item['state'], $status_filter, true ) ) {
				return false;
			}
			if ( 'reviewed' === $item['state'] && ! $include_reviewed ) {
				return false;
			}
			$items[] = $item;
			return count( $items ) >= $limit;
		};

		if ( $use_candidate_batches ) {
			$batch_size = min( 250, max( 50, $limit * 10 ) );
			$page       = 1;
			do {
				$candidates = self::quality_review_candidate_posts( $order, $target_language_filter, $include_source, $batch_size, $page );
				if ( empty( $candidates ) ) {
					break;
				}
				++$query_page_count;
				foreach ( $candidates as $post ) {
					if ( $process_post( $post ) ) {
						break 2;
					}
				}
				++$page;
			} while ( count( $candidates ) >= $batch_size );
		} else {
			foreach ( $posts as $post ) {
				if ( $process_post( $post ) && 'title_asc' !== $order ) {
					break;
				}
			}
		}

		self::sort_quality_review_items( $items, $order );
		$items = array_slice( $items, 0, $limit );

		return array(
			'success'          => true,
			'queue'            => 'quality_review',
			'items'            => $items,
			'next_item'        => $items[0] ?? null,
			'item_count'       => count( $items ),
			'inspected_count'  => $inspected_count,
			'query_page_count' => $query_page_count,
			'totals'           => $totals,
			'languages'        => array_values( $languages ),
			'status_filter'    => array_values( $status_filter ),
			'include_reviewed' => $include_reviewed,
			'include_source'   => $include_source,
			'order'            => $order,
			'detail_level'     => $detail_level,
		);
	}

	/**
	 * Build one queue row for a source page.
	 */
	private static function queue_item_for_source( WP_Post $source, array $status_filter, string $detail_level = 'compact' ): array {
		$translations = array();
		if ( 'full' === $detail_level ) {
			foreach ( self::translation_rows_for_source( (int) $source->ID ) as $row ) {
				if ( ! empty( $row['language'] ) ) {
					$translations[ $row['language'] ] = $row;
				}
			}
		} else {
			foreach ( self::heartbeat_translation_rows_for_source( (int) $source->ID ) as $workflow_row ) {
				$row = self::compact_translation_queue_payload_from_workflow_row( $workflow_row, $source );
				if ( ! empty( $row['language'] ) ) {
					$translations[ $row['language'] ] = $row;
				}
			}
		}

		$source_work_items = array();
		$source_content_item = self::source_content_integrity_repair_work_item( $source );
		if ( $source_content_item ) {
			$source_reservation = self::source_work_reservation_for_type( (int) $source->ID, 'content_integrity_repair' );
			$source_state = $source_reservation ? 'reserved' : 'content_integrity_repair';
			if ( empty( $status_filter ) || in_array( $source_state, $status_filter, true ) ) {
				$source_work_items[] = array(
					'state'       => $source_state,
					'action'      => $source_reservation ? 'wait_for_reservation_or_claim_expiry' : 'repair_content_integrity',
					'work_item'   => $source_content_item,
					'reservation' => $source_reservation ? self::public_source_work_reservation( $source_reservation ) : null,
				);
			}
		}

		$source_design_item = self::source_design_repair_work_item( $source );
			if ( $source_design_item ) {
				$source_reservation = self::source_work_reservation_for_type( (int) $source->ID, 'source_design_repair' );
				$source_state = $source_reservation ? 'reserved' : 'source_design_repair';
				if ( empty( $status_filter ) || in_array( $source_state, $status_filter, true ) ) {
					$source_work_items[] = array(
						'state'       => $source_state,
						'action'      => $source_reservation ? 'wait_for_reservation_or_claim_expiry' : 'repair_source_design',
						'work_item'   => $source_design_item,
						'reservation' => $source_reservation ? self::public_source_work_reservation( $source_reservation ) : null,
					);
				}
			}

		$source_taxonomy_item = self::source_taxonomy_review_work_item( $source );
		if ( $source_taxonomy_item ) {
			$source_reservation = self::source_work_reservation_for_type( (int) $source->ID, 'source_taxonomy_review' );
			$source_state = $source_reservation ? 'reserved' : 'source_taxonomy_review';
			if ( empty( $status_filter ) || in_array( $source_state, $status_filter, true ) ) {
				$source_work_items[] = array(
					'state'       => $source_state,
					'action'      => $source_reservation ? 'wait_for_reservation_or_claim_expiry' : 'review_source_taxonomy',
					'work_item'   => $source_taxonomy_item,
					'reservation' => $source_reservation ? self::public_source_work_reservation( $source_reservation ) : null,
				);
			}
		}

		$language_rows = array();
		$action_count  = count( $source_work_items );
		foreach ( self::target_languages() as $language => $config ) {
			$translation = $translations[ $language ] ?? array();
			$state       = self::queue_state_for_translation( $translation );
			$reservation = self::translation_reservation_for_language( (int) $source->ID, $language );
			if ( $reservation && 'complete' !== $state ) {
				$state = 'reserved';
			}
			$action      = self::queue_action_for_state( $state );

			if ( ! empty( $status_filter ) && ! in_array( $state, $status_filter, true ) ) {
				continue;
			}

			if ( 'complete' !== $state ) {
				++$action_count;
			}

			$language_rows[] = array(
				'language'    => $language,
				'name'        => $config['name'] ?? strtoupper( $language ),
				'flag'        => $config['flag'] ?? strtoupper( $language ),
				'state'       => $state,
				'action'      => $action,
				'translation' => $translation,
				'reservation' => $reservation ? self::public_translation_reservation( $reservation ) : null,
			);
		}

		return array(
			'source'      => self::source_summary_payload( $source ),
			'source_hash' => self::source_hash( $source ),
			'source_work_items' => $source_work_items,
			'languages'   => $language_rows,
			'action_count'=> $action_count,
		);
	}

	/**
	 * Compact translation row for queue listings.
	 */
	private static function compact_translation_queue_payload( WP_Post $post, ?WP_Post $source = null ): array {
		$post_id   = (int) $post->ID;
		$source_id = absint( get_post_meta( $post_id, self::META_SOURCE_ID, true ) );
		if ( ! $source && $source_id ) {
			$source = get_post( $source_id );
		}
		$language = sanitize_key( (string) get_post_meta( $post_id, self::META_LANGUAGE, true ) );
		$hash     = (string) get_post_meta( $post_id, self::META_SOURCE_HASH, true );
		$current  = $source ? self::source_hash( $source ) : '';
		$linguistic_state = self::linguistic_review_state_for_post( $post_id );
		$quality_reviewed_at = (string) get_post_meta( $post_id, self::META_QUALITY_REVIEWED_AT, true );
		$quality_state       = self::quality_review_state_for_post( $post, $quality_reviewed_at, $language );
		$final_reviewed_at   = (string) get_post_meta( $post_id, self::META_FINAL_REVIEWED_AT, true );
		$open_feedback       = self::open_copy_feedback_for_post( $post_id );

		return array(
			'id'                 => $post_id,
			'post_type'          => (string) $post->post_type,
			'source_id'          => $source_id,
			'language'           => $language,
			'title'              => get_the_title( $post ),
			'slug'               => $post->post_name,
			'status'             => $post->post_status,
			'translation_status' => (string) get_post_meta( $post_id, self::META_STATUS, true ),
			'url'                => get_permalink( $post ),
			'modified'           => $post->post_modified_gmt,
			'localized_path'     => (string) get_post_meta( $post_id, self::META_LOCALIZED_PATH, true ),
			'source_hash'        => $hash,
			'current_source_hash' => $current,
			'is_stale'           => $hash && $current && $hash !== $current,
			'writer_provenance'  => self::translation_writer_provenance( $post_id ),
			'visible_media_provenance' => self::translation_visible_media_provenance( $post_id ),
			'linguistic_review_state' => array(
				'passed'        => ! empty( $linguistic_state['passed'] ),
				'state'         => sanitize_key( (string) ( $linguistic_state['state'] ?? 'needs_linguistic_review' ) ),
				'stale_reasons' => self::sanitize_qa_code_list( $linguistic_state['stale_reasons'] ?? array() ),
				'reviewed_at'   => (string) ( $linguistic_state['reviewed_at'] ?? '' ),
			),
			'quality_review_state' => array(
				'state'       => $quality_state,
				'reviewed_at' => $quality_reviewed_at,
			),
			'final_review_state' => array(
				'state'       => '' === $final_reviewed_at ? 'needs_final_review' : 'final_review_recorded',
				'reviewed_at' => $final_reviewed_at,
			),
			'copy_feedback_open_count' => count( $open_feedback ),
			'copy_feedback_open_ids'   => array_values(
				array_filter(
					array_map(
						static function ( array $item ): string {
							return sanitize_key( (string) ( $item['id'] ?? '' ) );
						},
						$open_feedback
					)
				)
			),
			'content_integrity' => self::source_content_integrity_validation( $post ),
		);
	}

	/**
	 * Compact queue payload from the indexed workflow read model.
	 *
	 * @param array<string,mixed> $row Compact workflow row.
	 */
	private static function compact_translation_queue_payload_from_workflow_row( array $row, WP_Post $source ): array {
		$post_id   = absint( $row['id'] ?? 0 );
		$language  = sanitize_key( (string) ( $row['language'] ?? '' ) );
		$source_hash = (string) ( $row['source_hash'] ?? '' );
		$current     = self::source_hash( $source );
		$linguistic_reviewed_at = (string) ( $row['linguistic_reviewed_at'] ?? '' );
		$quality_reviewed_at    = (string) ( $row['quality_reviewed_at'] ?? '' );
		$final_reviewed_at      = (string) ( $row['final_reviewed_at'] ?? '' );
		$content_integrity_issue_count = absint( $row['content_integrity_issue_count'] ?? 0 );

		return array(
			'id'                 => $post_id,
			'post_type'          => $post_id ? (string) get_post_type( $post_id ) : '',
			'source_id'          => (int) $source->ID,
			'language'           => $language,
			'title'              => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
			'slug'               => sanitize_title( (string) ( $row['slug'] ?? '' ) ),
			'status'             => sanitize_key( (string) ( $row['status'] ?? '' ) ),
			'translation_status' => self::sanitize_translation_status( (string) ( $row['translation_status'] ?? '' ) ),
			'url'                => esc_url_raw( (string) ( $row['url'] ?? '' ) ),
			'modified'           => sanitize_text_field( (string) ( $row['modified'] ?? '' ) ),
			'localized_path'     => trim( (string) ( $row['localized_path'] ?? '' ), '/' ),
			'source_hash'        => $source_hash,
			'current_source_hash' => $current,
			'is_stale'           => $source_hash && $current && $source_hash !== $current,
			'writer_provenance'  => array(
				'token_label' => sanitize_key( (string) ( $row['writer_token_label'] ?? '' ) ),
			),
			'linguistic_review_state' => array(
				'passed'      => '' !== $linguistic_reviewed_at,
				'state'       => '' === $linguistic_reviewed_at ? 'needs_linguistic_review' : 'reviewed_recorded',
				'reviewed_at' => $linguistic_reviewed_at,
			),
			'quality_review_state' => array(
				'state'       => '' === $quality_reviewed_at ? 'needs_quality_review' : 'quality_review_recorded',
				'reviewed_at' => $quality_reviewed_at,
			),
			'final_review_state' => array(
				'state'       => '' === $final_reviewed_at ? 'needs_final_review' : 'final_review_recorded',
				'reviewed_at' => $final_reviewed_at,
			),
			'copy_feedback_open_count' => 0,
			'copy_feedback_open_ids'   => array(),
			'content_integrity' => array(
				'passed'      => 0 === $content_integrity_issue_count,
				'issue_count' => $content_integrity_issue_count,
			),
		);
	}

	/**
	 * Classify one language row for the translation queue.
	 */
	private static function queue_state_for_translation( array $translation ): string {
		if ( empty( $translation ) ) {
			return 'missing';
		}

		$translation_status = (string) ( $translation['translation_status'] ?? '' );
		$post_status        = (string) ( $translation['status'] ?? '' );
		$content_integrity  = isset( $translation['content_integrity'] ) && is_array( $translation['content_integrity'] ) ? $translation['content_integrity'] : array();

		if ( ! empty( $content_integrity['issue_count'] ) ) {
			return 'content_integrity_repair';
		}
		if ( ! empty( $translation['is_stale'] ) || 'stale' === $translation_status ) {
			return 'stale';
		}
		if ( '' === $translation_status || 'needs_review' === $translation_status ) {
			return 'needs_review';
		}
		if ( 'publish' !== $post_status || 'draft' === $translation_status ) {
			return 'draft';
		}
		$review_state = isset( $translation['linguistic_review_state'] ) && is_array( $translation['linguistic_review_state'] )
			? $translation['linguistic_review_state']
			: array();
		if ( empty( $review_state['passed'] ) ) {
			return 'needs_linguistic_review';
		}
		if ( 'published' !== $translation_status ) {
			return 'ready_to_publish';
		}

		return 'complete';
	}

	/**
	 * Suggested next action for a queue state.
	 */
	private static function queue_action_for_state( string $state ): string {
		$actions = array(
			'content_integrity_repair'   => 'repair_content_integrity',
			'missing'                 => 'create_translation',
			'stale'                   => 'refresh_translation_from_source',
			'draft'                   => 'finish_translation',
			'needs_review'            => 'run_qa_and_review',
			'needs_linguistic_review' => 'mark_linguistic_reviewed_after_review',
			'ready_to_publish'        => 'publish_translation',
			'reserved'                => 'wait_for_reservation_or_claim_expiry',
			'complete'                => 'none',
		);

		return $actions[ $state ] ?? 'review';
	}

	/**
	 * Classify one author archive translation row for the queue.
	 *
	 * @param array<string,mixed> $record Runtime author archive translation.
	 */
	private static function author_archive_queue_state( array $record, string $source_hash ): string {
		if ( empty( $record ) ) {
			return 'missing';
		}
		if ( ! empty( $record['source_hash'] ) && $source_hash && $source_hash !== (string) $record['source_hash'] ) {
			return 'stale';
		}
		$status = (string) ( $record['status'] ?? '' );
		if ( 'published' === $status ) {
			return 'complete';
		}
		if ( 'reviewed' === $status || 'needs_review' === $status ) {
			return 'needs_review';
		}

		return 'draft';
	}

	/**
	 * Suggested next action for an author archive queue state.
	 */
	private static function author_archive_queue_action_for_state( string $state ): string {
		$actions = array(
			'missing'      => 'create_author_archive_translation',
			'stale'        => 'refresh_author_archive_translation',
			'draft'        => 'finish_author_archive_translation',
			'needs_review' => 'review_and_publish_author_archive_translation',
			'complete'     => 'none',
		);

		return $actions[ $state ] ?? 'review';
	}

}
