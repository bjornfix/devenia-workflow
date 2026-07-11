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

		$scan_limit = max( $limit, min( 500, max( 100, $limit * 2 ) ) );
		foreach ( self::source_work_queue_definitions() as $definition ) {
			$work_type = sanitize_key( (string) ( $definition['work_type'] ?? '' ) );
			if ( '' === $work_type ) {
				continue;
			}
			foreach ( self::source_workflow_source_candidates( $work_type, $scan_limit ) as $candidate ) {
				self::add_workflow_source_candidate( $sources, $candidate, $limit );
				if ( count( $sources ) >= $limit ) {
					return $sources;
				}
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
	 * Candidate source content for translation workflow work.
	 *
	 * @return array<int,WP_Post>
	 */
	private static function translation_workflow_source_candidates( int $scan_limit ): array {
		$manifest = get_option( self::OPTION_SOURCE_INVENTORY_ACTIVE, array() );
		if ( is_array( $manifest ) && ! empty( $manifest['generation'] ) && '1' !== get_option( self::OPTION_SOURCE_INVENTORY_DIRTY, '1' ) ) {
			global $wpdb;
			$source_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT source_id FROM %i WHERE generation = %s AND state <> 'published_verified' ORDER BY source_id ASC LIMIT %d",
					self::translation_obligations_table(),
					(string) $manifest['generation'],
					max( 1, min( 2000, $scan_limit ) )
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The active authoritative obligation projection replaces the old recent-content scan.
			return array_values(
				array_filter(
					array_map( 'get_post', array_map( 'absint', $source_ids ) ),
					static function ( $candidate ): bool { return $candidate instanceof WP_Post; }
				)
			);
		}

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
	 * Candidate source content for one source-scoped work type.
	 *
	 * @return array<int,WP_Post>
	 */
	private static function source_workflow_source_candidates( string $work_type, int $scan_limit ): array {
		$definition = self::source_work_queue_definition( $work_type );
		if ( ! $definition ) {
			return array();
		}

		$query_args = array(
			'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 500, max( $scan_limit, absint( $definition['scan_floor'] ?? 0 ) ) ) ),
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Source work queues must include original source content only.
				array(
					'key'     => self::META_SOURCE_ID,
					'compare' => 'NOT EXISTS',
				),
			),
		);
		if ( ! empty( $definition['post_type'] ) ) {
			$query_args['post_type'] = sanitize_key( (string) $definition['post_type'] );
		}

		$query   = self::source_content_query( $query_args );
		$sources = array();
		foreach ( $query->posts as $candidate ) {
			if ( ! $candidate instanceof WP_Post ) {
				continue;
			}
			if ( ! self::source_work_item_for_type( $candidate, $work_type ) ) {
				continue;
			}
			$sources[] = $candidate;
		}

		return $sources;
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
		$totals = array_merge(
			array(
				'sources_scanned' => count( $sources ),
			),
			self::source_work_totals(),
			array(
				'translations_seen'         => 0,
				'missing_translations'      => 0,
				'needs_source_reprojection' => 0,
				'needs_draft_work'          => 0,
				'needs_route_repair'        => 0,
				'needs_linguistic_review'   => 0,
				'needs_quality_review'      => 0,
				'needs_final_review'        => 0,
				'ready_to_publish'          => 0,
				'published'                 => 0,
				'blocked_from_publish'      => 0,
			)
		);
		$items = array();

		foreach ( $sources as $source ) {
			if ( ! $source instanceof WP_Post ) {
				continue;
			}

			$source_work_item = self::first_source_work_item_for_source( $source );
			if ( $source_work_item ) {
				$source_work_type = sanitize_key( (string) ( $source_work_item['work_type'] ?? '' ) );
				if ( isset( $totals[ $source_work_type ] ) ) {
					++$totals[ $source_work_type ];
				}
				++$totals['needs_draft_work'];
				if ( $include_items ) {
					$items[] = $source_work_item;
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
								'revision_evidence' => array(
									'current_source_hash' => $current_source_hash,
								),
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
				$route_issue = self::heartbeat_translation_slug_language_issue( $translation_id, $slug, $language, $source, $source_language_tokens );
				$content_integrity = ! empty( $translation['content_integrity_issue_count'] );
				$source_reprojection = '' !== $source_hash && '' !== $current_source_hash && $source_hash !== $current_source_hash;
				$has_repair_prerequisite = $content_integrity || $route_issue || $source_reprojection;
				$linguistic_current = '' !== $linguistic_reviewed_at;
				$quality_current = '' !== $quality_reviewed_at;
				$final_current = '' !== $final_reviewed_at;
				$translation_post = null;

				if ( ! $has_repair_prerequisite && $linguistic_current ) {
					$linguistic_current = ! empty( self::linguistic_review_state_for_post( $translation_id )['passed'] );
				}
				if ( ! $has_repair_prerequisite ) {
					if ( ! $linguistic_current ) {
						$quality_current = false;
						$final_current = false;
					} elseif ( $quality_current ) {
						$translation_post = get_post( $translation_id );
						$quality_current = $translation_post instanceof WP_Post
							&& ! empty( self::quality_review_readiness_for_post( $translation_post, $language, false )['passed'] );
					}
					if ( ! $quality_current ) {
						$final_current = false;
					} elseif ( $final_current ) {
						$translation_post = $translation_post instanceof WP_Post ? $translation_post : get_post( $translation_id );
						$final_current = $translation_post instanceof WP_Post
							&& ! empty( self::final_review_readiness_for_post( $translation_post, $language, false )['passed'] );
					}
				}

				$linguistic_state = $linguistic_current ? 'reviewed_current' : ( '' === $linguistic_reviewed_at ? 'needs_linguistic_review' : 'linguistic_review_stale' );
				$quality_state = $quality_current ? 'quality_review_current' : ( '' === $quality_reviewed_at ? 'needs_quality_review' : 'quality_review_stale' );
				$final_state = $final_current ? 'final_review_current' : ( '' === $final_reviewed_at ? 'needs_final_review' : 'final_review_stale' );

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
				} elseif ( $source_reprojection ) {
					++$totals['needs_source_reprojection'];
					++$totals['needs_draft_work'];
					$obligations[] = 'source_reprojection';
				} else {
					$review_obligation = Devenia_AI_Translations_Workflow_State_Model::next_review_obligation(
						$linguistic_current ? $linguistic_reviewed_at : '',
						$quality_current ? $quality_reviewed_at : '',
						$final_current ? $final_reviewed_at : ''
					);
					if ( 'linguistic_review' === $review_obligation ) {
						++$totals['needs_linguistic_review'];
					}
					if ( '' !== $review_obligation ) {
						$obligations[] = $review_obligation;
					}
				}
				if ( ! $quality_current ) {
					++$totals['needs_quality_review'];
				}
				if ( ! $final_current ) {
					++$totals['needs_final_review'];
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
								'revision_evidence' => self::translation_work_item_revision_evidence( $translation, $current_source_hash ),
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
							'content_integrity' => isset( $translation['content_integrity'] ) && is_array( $translation['content_integrity'] ) ? $translation['content_integrity'] : array(),
							'revision_evidence' => self::translation_work_item_revision_evidence( $translation, $current_source_hash ),
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
		$gate = self::source_content_integrity_gate_state( $source );
		if ( ! empty( $gate['passed'] ) ) {
			return array();
		}
		$validation = isset( $gate['validation'] ) && is_array( $gate['validation'] ) ? $gate['validation'] : self::source_content_integrity_validation( $source );

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
				'content_integrity_review' => isset( $gate['review'] ) && is_array( $gate['review'] ) ? $gate['review'] : array(),
				'obligations' => array( 'content_integrity_repair' ),
				'linguistic' => 'content_integrity_repair_required',
				'quality' => 'content_integrity_repair_required',
				'final' => 'content_integrity_repair_required',
				'revision_evidence' => self::source_work_item_revision_evidence( $source ),
			)
		);
	}

	private static function source_design_repair_work_item( WP_Post $source ): array {
		if ( 'post' !== (string) $source->post_type ) {
			return array();
		}

		$gate = self::source_design_gate_state( $source, (string) $source->post_content );
		if ( ! empty( $gate['passed'] ) ) {
			return array();
		}
		$validation = isset( $gate['validation'] ) && is_array( $gate['validation'] ) ? $gate['validation'] : array();
		$review     = isset( $gate['review'] ) && is_array( $gate['review'] ) ? $gate['review'] : array();

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
				'source_design_review' => $review,
				'obligations' => array( 'source_design_repair' ),
				'linguistic' => 'source_design_repair_required',
				'quality' => 'source_design_repair_required',
				'final' => 'source_design_repair_required',
				'revision_evidence' => self::source_work_item_revision_evidence( $source ),
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

		$validation         = self::source_editorial_design_validation( $source, (string) $source->post_content );
		$validation_summary = self::source_editorial_design_validation_summary( $validation );

		return self::workflow_work_item(
			'source_taxonomy_review',
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
				'source_taxonomy' => $review,
				'obligations' => array( 'source_taxonomy_review' ),
				'linguistic' => 'source_taxonomy_review_required',
				'quality' => 'source_taxonomy_review_required',
				'final' => 'source_taxonomy_review_required',
				'revision_evidence' => self::source_work_item_revision_evidence( $source ),
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

		$work_item_identity = array(
			'work_scope'     => $scope,
			'work_type'      => $work_type,
			'source_id'      => $source_id,
			'translation_id' => $translation_id,
			'language'       => sanitize_key( $language ),
		);
		$item['work_item_id'] = 'wi_' . substr( hash( 'sha256', wp_json_encode( $work_item_identity ) ), 0, 32 );
		$revision_payload = array_merge(
			$work_item_identity,
			array(
				'planner_version'    => self::VERSION,
				'post_status'        => $item['post_status'],
				'writer_token_label' => $item['writer_token_label'],
				'obligations'        => $item['obligations'],
				'linguistic'         => $item['linguistic'],
				'quality'            => $item['quality'],
				'final'              => $item['final'],
				'article_type'       => sanitize_key( (string) ( $item['article_type'] ?? '' ) ),
				'template_id'        => sanitize_text_field( (string) ( $item['template_id'] ?? '' ) ),
				'template_slug'      => sanitize_text_field( (string) ( $item['template_slug'] ?? '' ) ),
				'template_version'   => sanitize_text_field( (string) ( $item['template_version'] ?? '' ) ),
			)
		);
		$revision_payload['evidence'] = isset( $extra['revision_evidence'] ) && is_array( $extra['revision_evidence'] )
			? $extra['revision_evidence']
			: array();
		$revision_payload = self::canonical_work_item_revision_value( $revision_payload );
		$item['revision'] = 'r_' . substr( hash( 'sha256', wp_json_encode( $revision_payload ) ), 0, 32 );

		return $item;
	}

	/**
	 * Evidence that changes a source-scoped Work Item revision.
	 *
	 * @return array<string,string>
	 */
	private static function source_work_item_revision_evidence( WP_Post $source ): array {
		return array(
			'source_hash' => self::source_hash( $source ),
			'modified'    => sanitize_text_field( (string) $source->post_modified_gmt ),
		);
	}

	/**
	 * Evidence that changes a translation Work Item revision.
	 *
	 * @param array<string,mixed> $translation Compact translation row.
	 * @return array<string,mixed>
	 */
	private static function translation_work_item_revision_evidence( array $translation, string $current_source_hash ): array {
		return array(
			'current_source_hash'    => $current_source_hash,
			'stored_source_hash'     => sanitize_text_field( (string) ( $translation['source_hash'] ?? '' ) ),
			'translation_modified'   => sanitize_text_field( (string) ( $translation['modified'] ?? '' ) ),
			'slug'                   => sanitize_title( (string) ( $translation['slug'] ?? '' ) ),
			'localized_path'         => trim( sanitize_text_field( (string) ( $translation['localized_path'] ?? '' ) ), '/' ),
			'content_issue_count'    => absint( $translation['content_integrity_issue_count'] ?? 0 ),
			'linguistic_reviewed_at' => sanitize_text_field( (string) ( $translation['linguistic_reviewed_at'] ?? '' ) ),
			'quality_reviewed_at'    => sanitize_text_field( (string) ( $translation['quality_reviewed_at'] ?? '' ) ),
			'final_reviewed_at'      => sanitize_text_field( (string) ( $translation['final_reviewed_at'] ?? '' ) ),
		);
	}

	/**
	 * Recursively normalize associative evidence before hashing.
	 *
	 * @return mixed
	 */
	private static function canonical_work_item_revision_value( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_list = array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value );
		}
		foreach ( $value as $key => $nested ) {
			$value[ $key ] = self::canonical_work_item_revision_value( $nested );
		}

		return $value;
	}

	/**
	 * Source-scoped work rows for the public queue read model.
	 *
	 * @param WP_Post           $source Source post.
	 * @param array<int,string> $status_filter Optional queue-state filter.
	 * @return array<int,array<string,mixed>>
	 */
	private static function source_work_queue_entries( WP_Post $source, array $status_filter ): array {
		$entries = array();
		foreach ( self::source_work_queue_definitions() as $definition ) {
			$work_type = sanitize_key( (string) ( $definition['work_type'] ?? '' ) );
			if ( '' === $work_type ) {
				continue;
			}

			$item = self::source_work_item_for_type( $source, $work_type );
			if ( empty( $item ) || ! is_array( $item ) ) {
				continue;
			}

			$reservation = self::source_work_reservation_for_type( (int) $source->ID, $work_type );
			$state       = $reservation ? 'reserved' : $work_type;
			if ( ! empty( $status_filter ) && ! in_array( $state, $status_filter, true ) ) {
				continue;
			}

			$entries[] = array(
				'state'       => $state,
				'action'      => $reservation ? 'wait_for_reservation_or_claim_expiry' : sanitize_key( (string) ( $definition['action'] ?? $work_type ) ),
				'work_item'   => $item,
				'reservation' => $reservation ? self::public_source_work_reservation( $reservation ) : null,
			);
		}

		return $entries;
	}

	/**
	 * Source-scoped work definitions in queue priority order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function source_work_queue_definitions(): array {
		return array(
			array(
				'work_type'            => 'content_integrity_repair',
				'action'               => 'repair_content_integrity',
				'workflow_step'        => 'draft_write',
				'required_ability'     => 'content/update-post',
				'completion_abilities' => array(
					'content/update-post',
					'ai-translations/mark-source-content-integrity-reviewed',
				),
				'completion_policy'    => 'Choose the smallest honest completion path. If the current source content and audits are already clean, complete with ai-translations/mark-source-content-integrity-reviewed and concrete evidence instead of making a no-op content/update-post save.',
				'scan_floor'           => '200',
				'builder'              => 'source_content_integrity_repair_work_item',
			),
			array(
				'work_type'            => 'source_design_repair',
				'action'               => 'repair_source_design',
				'workflow_step'        => 'draft_write',
				'required_ability'     => 'devenia-site-presentation/apply-article-contract-pattern',
				'completion_abilities' => array(
					'ai-translations/mark-source-design-reviewed',
					'devenia-site-presentation/apply-article-contract-pattern',
				),
				'completion_policy'    => 'Choose the smallest honest completion path. If rendered desktop/mobile inspection and the article contract show the current source page is already suitable, complete the item with ai-translations/mark-source-design-reviewed and concrete evidence. Use devenia-site-presentation/apply-article-contract-pattern only when the source actually needs a rewrite or native source design repair.',
				'post_type'            => 'post',
				'builder'              => 'source_design_repair_work_item',
			),
			array(
				'work_type'        => 'source_taxonomy_review',
				'action'           => 'review_source_taxonomy',
				'workflow_step'    => 'draft_write',
				'required_ability' => 'ai-translations/mark-source-taxonomy-reviewed',
				'post_type'        => 'post',
				'builder'          => 'source_taxonomy_review_work_item',
			),
		);
	}

	/**
	 * One source-scoped work definition by type.
	 *
	 * @return array<string,string>
	 */
	private static function source_work_queue_definition( string $work_type ): array {
		$work_type = sanitize_key( $work_type );
		foreach ( self::source_work_queue_definitions() as $definition ) {
			if ( $work_type === sanitize_key( (string) ( $definition['work_type'] ?? '' ) ) ) {
				return $definition;
			}
		}

		return array();
	}

	/**
	 * Zeroed totals for all registered source-scoped work types.
	 *
	 * @return array<string,int>
	 */
	private static function source_work_totals(): array {
		$totals = array();
		foreach ( self::source_work_queue_states() as $work_type ) {
			$totals[ $work_type ] = 0;
		}

		return $totals;
	}

	/**
	 * Source-scoped queue states in source-work priority order.
	 *
	 * @return array<int,string>
	 */
	private static function source_work_queue_states(): array {
		$states = array();
		foreach ( self::source_work_queue_definitions() as $definition ) {
			$work_type = sanitize_key( (string) ( $definition['work_type'] ?? '' ) );
			if ( '' !== $work_type ) {
				$states[] = $work_type;
			}
		}

		return array_values( array_unique( $states ) );
	}

	/**
	 * Translation queue states that are not source-scoped work items.
	 *
	 * @return array<int,string>
	 */
	private static function translation_queue_states(): array {
		return array(
			'missing',
			'stale',
			'draft',
			'needs_review',
			'needs_linguistic_review',
			'ready_to_publish',
			'reserved',
			'complete',
		);
	}

	/**
	 * All accepted queue status filters in public queue order.
	 *
	 * @return array<int,string>
	 */
	private static function queue_states(): array {
		return array_values( array_unique( array_merge( self::source_work_queue_states(), self::translation_queue_states() ) ) );
	}

	private static function queue_states_description(): string {
		return 'Optional queue states to include: ' . implode( ', ', self::queue_states() ) . '.';
	}

	private static function source_work_types_description(): string {
		return 'Optional first-class source work item type: ' . implode( ', ', self::source_work_queue_states() ) . '.';
	}

	private static function default_source_work_reservation_type(): string {
		return 'source_design_repair';
	}

	/**
	 * First source-scoped work item in queue priority order.
	 *
	 * @return array<string,mixed>
	 */
	private static function first_source_work_item_for_source( WP_Post $source ): array {
		foreach ( self::source_work_queue_definitions() as $definition ) {
			$work_type = sanitize_key( (string) ( $definition['work_type'] ?? '' ) );
			if ( '' === $work_type ) {
				continue;
			}
			$item = self::source_work_item_for_type( $source, $work_type );
			if ( ! empty( $item ) && is_array( $item ) ) {
				return $item;
			}
		}

		return array();
	}

	/**
	 * Build one source-scoped work item by type.
	 *
	 * @return array<string,mixed>
	 */
	private static function source_work_item_for_type( WP_Post $source, string $work_type ): array {
		$definition = self::source_work_queue_definition( $work_type );
		$builder    = (string) ( $definition['builder'] ?? '' );
		if ( '' === $builder || ! preg_match( '/^[A-Za-z0-9_]+$/', $builder ) || ! method_exists( __CLASS__, $builder ) ) {
			return array();
		}

		$item = self::{$builder}( $source );
		return is_array( $item ) ? $item : array();
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
		$totals = array_merge(
			self::source_work_totals(),
			array_fill_keys( self::translation_queue_states(), 0 )
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

		$source_work_items = self::source_work_queue_entries( $source, $status_filter );

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
