<?php
/**
 * Heartbeat work-selection Module for AI Translation Workflow.
 */
trait Devenia_AI_Translations_Heartbeat_Workflow {
	/**
	 * Return one safe next action for a real independent heartbeat session.
	 *
	 * This is intentionally conservative. It centralizes the work-selection
	 * rules so heartbeat clients do not each reinterpret queue state, writer
	 * provenance, reservations, and self-review constraints.
	 */
	private static function next_heartbeat_action( array $input ): array {
		$limit = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 500;
		$claim = ! empty( $input['claim'] );
		$ttl_seconds = isset( $input['ttl_seconds'] )
			? max( 60, min( self::MAX_TRANSLATION_CLAIM_TTL, absint( $input['ttl_seconds'] ) ) )
			: 600;
		$note = ! empty( $input['note'] ) ? sanitize_textarea_field( (string) $input['note'] ) : '';

		$identity = self::translation_step_token_gate( 'quality_review', $input );
		if ( empty( $identity['success'] ) ) {
			self::record_heartbeat_state(
				$input,
				array(
					'action' => 'escalate',
					'reason' => sanitize_key( (string) ( $identity['code'] ?? 'workflow_identity_not_confirmed' ) ),
				),
				is_array( $identity ) ? $identity : array()
			);
			return array(
				'success' => false,
				'action'  => 'escalate',
				'code'    => sanitize_key( (string) ( $identity['code'] ?? 'workflow_identity_not_confirmed' ) ),
				'message' => (string) ( $identity['message'] ?? 'Heartbeat identity is not confirmed by the workflow authority adapter.' ),
				'identity' => self::public_heartbeat_identity( is_array( $identity ) ? $identity : array() ),
			);
		}

		$obligations = self::heartbeat_obligations(
			array(
				'limit' => $limit,
				'include_items' => true,
			)
		);
		if ( empty( $obligations['success'] ) ) {
			self::record_heartbeat_state( $input, array( 'action' => 'escalate', 'reason' => 'workflow_obligations_failed' ), $identity );
			return $obligations;
		}

		$skipped = array();
		foreach ( $obligations['items'] ?? array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$translation_id = absint( $item['translation_id'] ?? 0 );
			$source_id = absint( $item['source_id'] ?? 0 );
			$language = sanitize_key( (string) ( $item['language'] ?? '' ) );
			$work_scope = 'source' === sanitize_key( (string) ( $item['work_scope'] ?? '' ) ) ? 'source' : 'translation';
			$work_type  = sanitize_key( (string) ( $item['work_type'] ?? '' ) );
			if ( ! $source_id || ( 'translation' === $work_scope && ! self::is_translation_language( $language ) ) ) {
				continue;
			}

			$reservation = 'source' === $work_scope
				? self::source_work_reservation_for_type( $source_id, $work_type )
				: self::translation_reservation_for_language( $source_id, $language );
			if ( $reservation ) {
				$skipped[] = self::heartbeat_skip_summary( $item, 'reserved' );
				continue;
			}

			$item_obligations = self::heartbeat_actionable_obligations( $item['obligations'] ?? array() );
			if ( empty( $item_obligations ) ) {
				continue;
			}

			foreach ( $item_obligations as $obligation ) {
				$eligibility = in_array( $obligation, array( 'draft_write', 'source_reprojection', 'route_repair', 'source_design_repair', 'content_integrity_repair' ), true )
					? self::heartbeat_draft_work_eligibility( $identity )
					: self::heartbeat_translation_review_eligibility( $translation_id, $identity, $obligation );
				if ( empty( $eligibility['success'] ) ) {
					$skipped[] = self::heartbeat_skip_summary( $item, sanitize_key( (string) ( $eligibility['code'] ?? 'not_eligible' ) . '_' . $obligation ) );
					continue;
				}

				$action = self::heartbeat_action_for_obligation( $obligation );
				if ( self::heartbeat_repeats_previous_item_without_change( $input, $identity, $translation_id, $source_id, $language, $action['action'] ) ) {
					$skipped[] = self::heartbeat_skip_summary( $item, 'repeated_same_item_for_actor_' . $obligation );
					continue;
				}
				$selected = array(
					'action' => $action['action'],
					'workflow_step' => $action['workflow_step'],
					'required_ability' => $action['required_ability'],
					'work_scope' => $work_scope,
					'work_type' => $work_type,
					'source_id' => $source_id,
					'source_title' => sanitize_text_field( (string) ( $item['source_title'] ?? '' ) ),
					'translation_id' => $translation_id,
					'language' => $language,
					'post_status' => sanitize_key( (string) ( $item['post_status'] ?? '' ) ),
					'obligation' => $obligation,
					'instructions' => $action['instructions'],
					'review_surface_guidance' => self::heartbeat_review_surface_guidance( $obligation, $translation_id, $language, sanitize_key( (string) ( $item['post_status'] ?? '' ) ) ),
					'design_ownership' => isset( $action['design_ownership'] ) && is_array( $action['design_ownership'] ) ? $action['design_ownership'] : array(),
					'claim_required_for_writes' => true,
					'independence' => self::heartbeat_independence_summary( $eligibility, $obligation ),
				);

				$claim_result = null;
				if ( $claim ) {
					$claim_result = self::reserve_translation_work(
						array(
							'source_id' => $source_id,
							'work_scope' => $work_scope,
							'work_type' => $work_type,
							'language' => $language,
							'owner' => 'heartbeat:' . (string) ( $identity['actor_id'] ?? $identity['step_token_label'] ?? 'unknown' ),
							'note' => '' !== $note ? $note : 'Reserved by next-heartbeat-action.',
							'agent_session_id' => (string) ( $identity['agent_session_id'] ?? $input['agent_session_id'] ?? '' ),
							'llm_vendor' => (string) ( $input['llm_vendor'] ?? $identity['llm_vendor'] ?? '' ),
							'llm_client' => (string) ( $input['llm_client'] ?? $identity['llm_client'] ?? '' ),
							'authority_vendor' => (string) ( $input['authority_vendor'] ?? $identity['authority_vendor'] ?? $identity['authority'] ?? '' ),
							'authority_client' => (string) ( $input['authority_client'] ?? $identity['authority_client'] ?? '' ),
							'session_binding_token' => (string) ( $input['session_binding_token'] ?? '' ),
							'actor_id' => (string) ( $identity['actor_id'] ?? $identity['step_token_label'] ?? '' ),
							'ttl_seconds' => $ttl_seconds,
						)
					);
					if ( empty( $claim_result['success'] ) ) {
						$skipped[] = self::heartbeat_skip_summary( $item, 'claim_conflict' );
						continue 2;
					}
					$selected['claim_token'] = (string) ( $claim_result['claim_token'] ?? '' );
					$selected['reservation'] = $claim_result['claims'][0] ?? null;
				}

				self::record_heartbeat_state( $input, $selected, $identity );
				return array(
					'success' => true,
					'action' => $selected['action'],
					'mode' => $claim ? 'claimed' : 'observe',
					'identity' => self::public_heartbeat_identity( $identity ),
					'selected' => $selected,
					'totals' => $obligations['totals'] ?? array(),
					'skipped_count' => count( $skipped ),
					'skipped_sample' => array_slice( $skipped, 0, 10 ),
					'heartbeat_policy' => self::heartbeat_policy(),
				);
			}
		}

		$wait = array(
			'action' => 'wait',
			'reason' => 'no_safe_action_for_this_actor',
			'instructions' => 'No eligible unreserved action was found for this heartbeat. Wait, back off, or let another independent heartbeat handle the remaining work.',
		);
		self::record_heartbeat_state( $input, $wait, $identity );
		return array(
			'success' => true,
			'action' => 'wait',
			'mode' => 'observe',
			'identity' => self::public_heartbeat_identity( $identity ),
			'selected' => $wait,
			'totals' => $obligations['totals'] ?? array(),
			'skipped_count' => count( $skipped ),
			'skipped_sample' => array_slice( $skipped, 0, 10 ),
			'heartbeat_policy' => self::heartbeat_policy(),
		);
	}

	/**
	 * Fast obligation read model for heartbeat assignment.
	 *
	 * The full workflow_obligations response intentionally expands rich review
	 * state for dashboards. Heartbeat assignment only needs the next safe item,
	 * so this path uses indexed rows and cheap meta reads first. Publication
	 * remains guarded by the normal publish ability and reviewer provenance.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function heartbeat_obligations( array $input ): array {
		$source_id     = absint( $input['source_id'] ?? 0 );
		$limit         = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 500;
		$include_items = array_key_exists( 'include_items', $input ) ? (bool) $input['include_items'] : true;
		$include_publish_items = array_key_exists( 'include_publish_items', $input ) ? (bool) $input['include_publish_items'] : true;
		$sources       = self::workflow_source_candidates( $source_id, $limit );
		if ( $source_id && empty( $sources ) ) {
			return self::error( 'Source content not found.' );
		}
		$catalog = self::workflow_work_items_for_sources( $sources, $include_items, $include_publish_items );

		return array(
			'success' => true,
			'totals'  => $catalog['totals'],
			'items'   => $include_items ? $catalog['items'] : array(),
			'read_model' => 'work_item_catalog',
		);
	}

	/**
	 * Audit whether visible obligations are reachable by the heartbeat assignment Interface.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function heartbeat_assignment_coverage( array $input ): array {
		$limit = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 500;
		$include_items = ! empty( $input['include_items'] );
		$obligations = self::heartbeat_obligations(
			array(
				'limit' => $limit,
				'include_items' => true,
				'include_publish_items' => true,
			)
		);
		if ( empty( $obligations['success'] ) ) {
			return $obligations;
		}

		$supported_obligations = array( 'content_integrity_repair', 'source_design_repair', 'route_repair', 'linguistic_review', 'quality_review', 'final_review', 'publish', 'source_reprojection', 'draft_write' );
		$coverage = array(
			'items_seen' => 0,
			'actionable_items' => 0,
			'reserved_items' => 0,
			'uncovered_items' => 0,
			'claimable_for_actor' => null,
			'skipped_for_actor' => null,
			'by_obligation' => array_fill_keys( $supported_obligations, 0 ),
			'claimable_by_obligation' => array_fill_keys( $supported_obligations, 0 ),
			'uncovered_by_obligation' => array_fill_keys( $supported_obligations, 0 ),
			'skipped_by_reason' => array(),
		);
		$identity = array();
		$identity_error = null;
		$needs_identity = ! empty( $input['agent_session_id'] ) || ! empty( $input['session_binding_token'] );
		if ( $needs_identity ) {
			$identity_result = self::translation_step_token_gate( 'quality_review', $input );
			if ( ! empty( $identity_result['success'] ) ) {
				$identity = $identity_result;
				$coverage['claimable_for_actor'] = 0;
				$coverage['skipped_for_actor'] = 0;
			} else {
				$identity_error = array(
					'code' => sanitize_key( (string) ( $identity_result['code'] ?? 'workflow_identity_not_confirmed' ) ),
					'message' => sanitize_text_field( (string) ( $identity_result['message'] ?? 'Heartbeat identity is not confirmed by the workflow authority adapter.' ) ),
				);
			}
		}

		$uncovered_samples = array();
		$skipped_samples = array();
		foreach ( $obligations['items'] ?? array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$coverage['items_seen']++;
			$item_obligations = self::heartbeat_actionable_obligations( $item['obligations'] ?? array() );
			if ( empty( $item_obligations ) ) {
				continue;
			}
			$coverage['actionable_items']++;

			$source_id = absint( $item['source_id'] ?? 0 );
			$translation_id = absint( $item['translation_id'] ?? 0 );
			$language = sanitize_key( (string) ( $item['language'] ?? '' ) );
			$work_scope = 'source' === sanitize_key( (string) ( $item['work_scope'] ?? '' ) ) ? 'source' : 'translation';
			$work_type = sanitize_key( (string) ( $item['work_type'] ?? '' ) );
			$reservation = 'source' === $work_scope
				? self::source_work_reservation_for_type( $source_id, $work_type )
				: self::translation_reservation_for_language( $source_id, $language );
			$is_reserved = ! empty( $reservation );
			if ( $is_reserved ) {
				$coverage['reserved_items']++;
			}

			$item_uncovered = false;
			$item_claimable = false;
			$actor_skip_reason = '';
			foreach ( $item_obligations as $obligation ) {
				if ( ! isset( $coverage['by_obligation'][ $obligation ] ) ) {
					continue;
				}
				$coverage['by_obligation'][ $obligation ]++;
				$action = self::heartbeat_action_for_obligation( $obligation );
				if ( 'wait' === sanitize_key( (string) ( $action['action'] ?? '' ) ) || '' === (string) ( $action['required_ability'] ?? '' ) ) {
					$item_uncovered = true;
					$coverage['uncovered_by_obligation'][ $obligation ]++;
					continue;
				}

				if ( empty( $identity ) ) {
					continue;
				}
				if ( $is_reserved ) {
					$actor_skip_reason = 'reserved';
					continue;
				}
				$eligibility = in_array( $obligation, array( 'draft_write', 'source_reprojection', 'route_repair', 'source_design_repair', 'content_integrity_repair' ), true )
					? self::heartbeat_draft_work_eligibility( $identity )
					: self::heartbeat_translation_review_eligibility( $translation_id, $identity, $obligation );
				if ( empty( $eligibility['success'] ) ) {
					$actor_skip_reason = sanitize_key( (string) ( $eligibility['code'] ?? 'not_eligible' ) . '_' . $obligation );
					continue;
				}
				if ( self::heartbeat_repeats_previous_item_without_change( $input, $identity, $translation_id, $source_id, $language, sanitize_key( (string) $action['action'] ) ) ) {
					$actor_skip_reason = 'repeated_same_item_for_actor_' . $obligation;
					continue;
				}

				$item_claimable = true;
				$coverage['claimable_by_obligation'][ $obligation ]++;
				break;
			}

			if ( $item_uncovered ) {
				$coverage['uncovered_items']++;
				if ( $include_items && count( $uncovered_samples ) < 20 ) {
					$uncovered_samples[] = self::heartbeat_skip_summary( $item, 'unsupported_assignment_mapping' );
				}
			}
			if ( ! empty( $identity ) ) {
				if ( $item_claimable ) {
					$coverage['claimable_for_actor']++;
				} else {
					$coverage['skipped_for_actor']++;
					$reason = '' !== $actor_skip_reason ? $actor_skip_reason : 'not_eligible';
					$coverage['skipped_by_reason'][ $reason ] = absint( $coverage['skipped_by_reason'][ $reason ] ?? 0 ) + 1;
					if ( $include_items && count( $skipped_samples ) < 20 ) {
						$skipped_samples[] = self::heartbeat_skip_summary( $item, $reason );
					}
				}
			}
		}

		$result = array(
			'success' => true,
			'read_model' => 'heartbeat_assignment_coverage',
			'limit' => $limit,
			'totals' => $obligations['totals'] ?? array(),
			'coverage' => $coverage,
			'identity' => ! empty( $identity ) ? self::public_heartbeat_identity( $identity ) : null,
			'identity_error' => $identity_error,
			'heartbeat_policy' => self::heartbeat_policy(),
		);
		if ( $include_items ) {
			$result['uncovered_sample'] = $uncovered_samples;
			$result['skipped_sample'] = $skipped_samples;
		}

		return $result;
	}

	/**
	 * Compact translation rows for heartbeat obligation selection.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function heartbeat_translation_rows_for_source( int $source_id ): array {
		$rows = self::translation_index_rows_for_source( $source_id, self::translation_workflow_post_statuses( false ) );
		$out  = array();
		foreach ( $rows as $row ) {
			$translation_id = absint( $row['id'] ?? $row['translation_post_id'] ?? 0 );
			if ( ! $translation_id ) {
				continue;
			}
			$post = get_post( $translation_id );
			$content_integrity = $post instanceof WP_Post ? self::source_content_integrity_validation( $post ) : array();
			$out[] = array(
				'id'                     => $translation_id,
				'language'               => sanitize_key( (string) ( $row['language'] ?? '' ) ),
				'status'                 => sanitize_key( (string) ( $row['status'] ?? $row['post_status'] ?? '' ) ),
				'translation_status'     => self::sanitize_translation_status( (string) ( $row['translation_status'] ?? '' ) ),
				'slug'                   => $post ? (string) $post->post_name : '',
				'title'                  => $post ? get_the_title( $post ) : '',
				'url'                    => $post ? get_permalink( $post ) : '',
				'modified'               => $post ? (string) $post->post_modified_gmt : '',
				'localized_path'         => trim( (string) ( $row['localized_path'] ?? '' ), '/' ),
				'source_hash'            => (string) ( $row['source_hash'] ?? '' ),
				'linguistic_reviewed_at' => (string) ( $row['linguistic_reviewed_at'] ?? '' ),
				'quality_reviewed_at'    => (string) ( $row['quality_reviewed_at'] ?? '' ),
				'final_reviewed_at'      => (string) get_post_meta( $translation_id, self::META_FINAL_REVIEWED_AT, true ),
				'writer_token_label'     => sanitize_key( (string) ( self::translation_writer_provenance( $translation_id )['token_label'] ?? '' ) ),
				'content_integrity_issue_count' => absint( $content_integrity['issue_count'] ?? 0 ),
			);
		}

		if ( ! empty( $out ) || self::translation_index_available() ) {
			return $out;
		}

		foreach ( self::translation_posts_for_source( $source_id, self::translation_workflow_post_statuses( false ) ) as $post ) {
			$translation_id = (int) $post->ID;
			$content_integrity = self::source_content_integrity_validation( $post );
			$out[] = array(
				'id'                     => $translation_id,
				'language'               => sanitize_key( (string) get_post_meta( $translation_id, self::META_LANGUAGE, true ) ),
				'status'                 => sanitize_key( (string) $post->post_status ),
				'translation_status'     => self::sanitize_translation_status( (string) get_post_meta( $translation_id, self::META_STATUS, true ) ),
				'slug'                   => (string) $post->post_name,
				'title'                  => get_the_title( $post ),
				'url'                    => get_permalink( $post ),
				'modified'               => (string) $post->post_modified_gmt,
				'localized_path'         => trim( (string) get_post_meta( $translation_id, self::META_LOCALIZED_PATH, true ), '/' ),
				'source_hash'            => (string) get_post_meta( $translation_id, self::META_SOURCE_HASH, true ),
				'linguistic_reviewed_at' => (string) get_post_meta( $translation_id, self::META_LINGUISTIC_REVIEWED_AT, true ),
				'quality_reviewed_at'    => (string) get_post_meta( $translation_id, self::META_QUALITY_REVIEWED_AT, true ),
				'final_reviewed_at'      => (string) get_post_meta( $translation_id, self::META_FINAL_REVIEWED_AT, true ),
				'writer_token_label'     => sanitize_key( (string) ( self::translation_writer_provenance( $translation_id )['token_label'] ?? '' ) ),
				'content_integrity_issue_count' => absint( $content_integrity['issue_count'] ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * Fast route-contract check for heartbeat obligation selection.
	 *
	 * @param array<int,string> $source_language_tokens Precomputed source tokens.
	 */
	private static function heartbeat_translation_slug_language_issue( string $slug, string $language, WP_Post $source, array $source_language_tokens ): bool {
		$slug     = sanitize_title( $slug );
		$language = sanitize_key( $language );
		if ( '' === $slug || '' === $language || ! self::language_requires_transliterated_urls( $language ) ) {
			return false;
		}
		if ( self::has_wordpress_duplicate_slug_suffix( $slug ) ) {
			return true;
		}
		if ( self::validate_transliterated_segment_not_source_copy( $slug, array( (string) $source->post_name ), $language, false, '', 'localized_slug' ) ) {
			return true;
		}

		return null !== self::validate_transliterated_segment_not_source_vocabulary_tokens( $slug, $source_language_tokens, $language, 'localized_slug' );
	}

	private static function heartbeat_first_actionable_obligation( $obligations ): string {
		$ordered = self::heartbeat_actionable_obligations( $obligations );
		return $ordered[0] ?? '';
	}

	private static function heartbeat_actionable_obligations( $obligations ): array {
		if ( ! is_array( $obligations ) ) {
			return array();
		}
		$allowed = array( 'content_integrity_repair', 'source_design_repair', 'route_repair', 'linguistic_review', 'quality_review', 'final_review', 'publish', 'source_reprojection', 'draft_write' );
		$ordered = array();
		foreach ( $allowed as $obligation ) {
			if ( in_array( $obligation, $obligations, true ) ) {
				$ordered[] = $obligation;
			}
		}
		return $ordered;
	}

	private static function heartbeat_action_for_obligation( string $obligation ): array {
		$design_ownership = array(
			'design_contract_required' => true,
			'design_contract_ability'  => 'devenia-site-presentation/get-article-contract',
			'worker_ownership_rule'    => 'The worker owns the design judgment for the assigned item. Persona name, green technical gates, and later reviewers are not substitutes for doing the design work now.',
			'required_design_brief'    => array(
				'reader',
				'decision_moment',
				'promise',
				'proof_risk_next_action',
				'section_design_roles',
				'image_or_media_function',
				'hierarchy_rhythm_contrast',
				'rendered_desktop_mobile_observations',
				'good_or_bad_design_verdict',
				'alternative_design_solutions_considered',
				'chosen_design_rationale',
				'designed_experience_verdict',
			),
		);
		$map = array(
			'content_integrity_repair' => array(
				'action' => 'repair_content_integrity',
				'workflow_step' => 'draft_write',
				'required_ability' => 'content/update-post',
				'design_ownership' => $design_ownership,
				'instructions' => 'The assigned source or translation has invalid stored Gutenberg content that can break the public/rendered experience. Inspect the content_integrity issues on the work item, repair through the narrowest safe WordPress content ability, rerun the relevant QA/audit, then stop before reviewing or publishing your own correction.',
			),
			'source_design_repair' => array(
				'action' => 'repair_source_design',
				'workflow_step' => 'draft_write',
				'required_ability' => 'devenia-site-presentation/apply-article-contract-pattern',
				'design_ownership' => $design_ownership,
				'instructions' => 'The source post itself does not pass the selected Devenia presentation contract. Before designing or rebuilding anything, fetch the live design sources through MCP: the Gutenberg style guide, registered block patterns, and any relevant block pattern library abilities exposed for the article type and section role. Use the article_type/template fields on the work item when fetching devenia-site-presentation/get-article-contract; do not assume editorial_post. Inspect the source, at least one comparable live Devenia page, and the public render. Rebuild through devenia-site-presentation/apply-article-contract-pattern with explicit fragments for that contract, validate with stamp-article-contract dry-run, then browser-check against the comparable page. Stop before reviewing or publishing any downstream translation work. After the source is repaired, translations will enter source-design reprojection work.',
			),
			'linguistic_review' => array(
				'action' => 'review_translation_linguistic',
				'workflow_step' => 'linguistic_review',
				'required_ability' => 'ai-translations/mark-linguistic-reviewed',
				'design_ownership' => $design_ownership,
				'instructions' => 'Run QA, fetch the Site Presentation article contract for the work item article_type/source validation, and perform a real language/design/content review. If copy is stiff, wrong, visually broken, too padded for its post type, or the source design is not good enough to inherit, record/fix the problem instead of approving. Do not approve merely because checkboxes can be filled.',
			),
				'quality_review' => array(
					'action' => 'review_translation_quality',
					'workflow_step' => 'quality_review',
					'required_ability' => 'ai-translations/mark-quality-reviewed',
					'design_ownership' => $design_ownership,
					'instructions' => 'Fetch the Site Presentation article contract for the work item article_type/source validation and review the assigned visible surface like a publication designer and editor who owns the design decision. If the translation is published, use the public URL. If it is draft/pre-publish, use ai-translations/get-presentation-surface and mark-quality-reviewed with review_surface=presentation_surface plus presentation_surface_post_id. A draft public URL returning 404 is expected and is not by itself a blocker. Use the selected contract review questions: long editorial posts need design depth, while release/status notes must stay concise and not become padded articles. Do not approve class-name/template checklists, green technical gates, or flat text poured into bands.',
				),
			'final_review' => array(
				'action' => 'review_translation_final',
				'workflow_step' => 'final_review',
				'required_ability' => 'ai-translations/mark-final-reviewed',
				'design_ownership' => $design_ownership,
				'instructions' => 'Fetch the Site Presentation article contract for the work item article_type/source validation and confirm prior reviews, SEO/URL readiness, publication experience, source-design ownership, visible-page design quality for that post type, and final publish decision.',
			),
			'publish' => array(
				'action' => 'publish_translation',
				'workflow_step' => 'publish',
				'required_ability' => 'ai-translations/publish-translation',
				'instructions' => 'Publish only with current linguistic, quality, and final review evidence. Use live verification.',
			),
				'source_reprojection' => array(
					'action' => 'reproject_source_design',
					'workflow_step' => 'draft_write',
					'required_ability' => 'ai-translations/reproject-source-design',
					'design_ownership' => $design_ownership,
					'instructions' => 'The source design has moved ahead of this translation. Fetch the Site Presentation article contract for the source article_type, inspect the source and existing translation, migrate/source-design fragments if needed, run ai-translations/reproject-source-design through approved workflow abilities, then stop before reviewing or publishing your own reprojection.',
				),
				'route_repair' => array(
					'action' => 'repair_translation_route',
					'workflow_step' => 'draft_write',
					'required_ability' => 'ai-translations/upsert-page',
					'design_ownership' => $design_ownership,
					'instructions' => 'The existing translation route violates the localized URL contract. Fetch the source, the current translation, workflow status, QA route_integrity details, and the Site Presentation article contract for the source article_type. Preserve the existing translated content/design unless it also needs normal content fixes, then use ai-translations/upsert-page to set a correct localized_slug/localized_path. For languages with transliterated URLs, use target-language transliteration in ASCII, not English/source-language route words. Do not mark review or publish your own route repair.',
				),
				'draft_write' => array(
				'action' => 'write_missing_translation',
				'workflow_step' => 'draft_write',
				'required_ability' => 'ai-translations/upsert-page',
				'design_ownership' => $design_ownership,
				'instructions' => 'This language is missing for the source. Fetch the source, the workflow status, and the Site Presentation article contract for the source article_type. Create a real localized draft with inherited source design, localized slug/path, title, excerpt, SEO metadata, taxonomy where needed, and complete localized_fragments. If the source post has categories or tags, call ai-translations/list-taxonomy-terms with source_id and language before upsert so you can reuse existing localized terms, see required source_term_id values, and follow the expected localized slug contract instead of guessing. Before mirroring categories, check whether the source categories actually fit the article and include taxonomies.category_assignment_review with source_categories_fit=true and a concrete note; if they do not fit, stop and report the source category problem instead of copying it into another language. For category/tag terms, provide useful localized archive descriptions when the archive helps readers understand what they will find; if a term should intentionally have no archive description, include a concrete description_not_useful_reason instead of silently skipping it. For languages with transliterated URLs, use target-language transliteration in ASCII; do not use English/source-language route words. Use ai-translations/upsert-page with the claim token. Do not mark review or publish the draft you create.',
			),
		);
		return $map[ $obligation ] ?? array(
			'action' => 'wait',
			'workflow_step' => '',
			'required_ability' => '',
			'instructions' => 'No supported obligation selected.',
			);
	}

	private static function heartbeat_review_surface_guidance( string $obligation, int $translation_id, string $language, string $post_status ): array {
		$obligation = sanitize_key( $obligation );
		if ( 'quality_review' !== $obligation ) {
			return array();
		}

		$translation_id = absint( $translation_id );
		$language       = sanitize_key( $language );
		$post_status    = sanitize_key( $post_status );
		if ( 'publish' === $post_status ) {
			return array(
				'review_surface' => 'public_url',
				'mark_quality_reviewed_params' => array(
					'review_surface' => 'public_url',
					'visible_page_url' => $translation_id ? get_permalink( $translation_id ) : '',
				),
				'instructions' => 'This translation is published. Review the rendered public URL and pass review_surface=public_url plus the visible_page_url you actually inspected to ai-translations/mark-quality-reviewed.',
			);
		}

		return array(
			'review_surface' => 'presentation_surface',
			'presentation_ability' => 'ai-translations/get-presentation-surface',
			'presentation_params' => array(
				'surface_type' => 'singular',
				'post_id' => $translation_id,
				'language' => $language,
			),
			'mark_quality_reviewed_params' => array(
				'review_surface' => 'presentation_surface',
				'presentation_surface_post_id' => $translation_id,
			),
			'instructions' => 'This translation is draft/pre-publish. Its public URL may return 404. That is expected. Review the WordPress presentation payload from ai-translations/get-presentation-surface and pass review_surface=presentation_surface plus presentation_surface_post_id to ai-translations/mark-quality-reviewed.',
		);
	}

	private static function heartbeat_draft_work_eligibility( array $identity ): array {
		$actor_id = sanitize_key( (string) ( $identity['actor_id'] ?? '' ) );
		$control_scope_id = self::normalize_control_scope_id( (string) ( $identity['control_scope_id'] ?? '' ) );
		$process_id = self::normalize_process_id( (string) ( $identity['process_id'] ?? '' ) );
		$session_origin = self::normalize_session_origin( (string) ( $identity['session_origin'] ?? '' ) );

		if ( '' === $control_scope_id ) {
			return array( 'success' => false, 'code' => 'draft_writer_control_scope_required' );
		}
		if ( '' === $process_id ) {
			return array( 'success' => false, 'code' => 'draft_writer_process_required' );
		}
		if ( 'independent_session' !== $session_origin ) {
			return array( 'success' => false, 'code' => 'independent_draft_writer_session_required' );
		}

		return array(
			'success' => true,
			'draft_writer' => array(
				'actor_id' => $actor_id,
				'token_label' => sanitize_key( (string) ( $identity['step_token_label'] ?? '' ) ),
				'control_scope_id' => $control_scope_id,
				'process_id' => $process_id,
				'session_origin' => $session_origin,
			),
		);
	}

	private static function heartbeat_translation_review_eligibility( int $translation_id, array $identity, string $obligation = '' ): array {
		$reviewer = self::reviewer_provenance_from_verified_identity( $identity, $translation_id );
		if ( '' === $reviewer['control_scope_id'] ) {
			return array( 'success' => false, 'code' => 'reviewer_control_scope_required' );
		}
		if ( 'independent_session' !== $reviewer['session_origin'] ) {
			return array( 'success' => false, 'code' => 'independent_reviewer_session_required' );
		}

		$writer = $reviewer['writer'];
		$writer_control_scope_id = self::normalize_control_scope_id( (string) ( $writer['control_scope_id'] ?? '' ) );
		if ( '' === $writer_control_scope_id && ! self::writer_provenance_has_reviewable_legacy_identity( $writer ) ) {
			return array( 'success' => false, 'code' => 'writer_control_scope_required' );
		}
		if ( '' !== $writer_control_scope_id && $writer_control_scope_id === $reviewer['control_scope_id'] ) {
			return array( 'success' => false, 'code' => 'writer_reviewer_control_scope_match' );
		}
		if ( ! empty( $writer['process_id'] ) && $writer['process_id'] === $reviewer['process_id'] ) {
			return array( 'success' => false, 'code' => 'writer_reviewer_process_match' );
		}
		if ( ! empty( $writer['token_label'] ) && ! empty( $reviewer['token_label'] ) && $writer['token_label'] === $reviewer['token_label'] ) {
			return array( 'success' => false, 'code' => 'writer_reviewer_token_label_match' );
		}
		if ( ! empty( $writer['actor'] ) && $writer['actor'] === $reviewer['actor'] ) {
			return array( 'success' => false, 'code' => 'writer_reviewer_actor_match' );
		}
		if ( ! empty( $writer['actor_id'] ) && ! empty( $reviewer['actor_id'] ) && $writer['actor_id'] === $reviewer['actor_id'] ) {
			return array( 'success' => false, 'code' => 'writer_reviewer_actor_id_match' );
		}

		$language = sanitize_key( (string) get_post_meta( $translation_id, self::META_LANGUAGE, true ) );
		if ( self::reviewer_matches_runtime_language_mutation( $reviewer, $language ) ) {
			return array(
				'success' => false,
				'code' => 'current_actor_changed_runtime_language_surface',
			);
		}
		if ( self::reviewer_matches_visible_media_mutation( $reviewer, $translation_id ) ) {
			return array(
				'success' => false,
				'code' => 'current_actor_changed_visible_media_surface',
			);
		}

		$prior_stage_reviewers = self::heartbeat_prior_stage_reviewers_for_obligation( $translation_id, $obligation );
		if ( self::reviewer_matches_any_provenance( $reviewer, $prior_stage_reviewers ) ) {
			return array(
				'success' => false,
				'code' => 'current_actor_already_handled_' . sanitize_key( $obligation ),
			);
		}

		return array(
			'success' => true,
			'writer' => $writer,
			'reviewer' => $reviewer,
			'prior_stage_reviewers' => $prior_stage_reviewers,
			'language' => $language,
		);
	}

	private static function heartbeat_independence_summary( array $eligibility, string $obligation ): array {
		if ( isset( $eligibility['draft_writer'] ) && is_array( $eligibility['draft_writer'] ) ) {
			$draft_writer = $eligibility['draft_writer'];
			return array(
				'server_checked' => true,
				'workflow_rule' => 'draft_work_creates_writer_provenance_and_must_be_reviewed_by_an_independent_actor_later',
				'obligation' => sanitize_key( $obligation ),
				'message' => 'The server selected this as production draft work for the current independent heartbeat. This actor may write or reproject, but must not review or publish the work it creates.',
				'draft_writer' => array(
					'actor_id' => sanitize_key( (string) ( $draft_writer['actor_id'] ?? '' ) ),
					'token_label' => sanitize_key( (string) ( $draft_writer['token_label'] ?? '' ) ),
					'control_scope_id' => self::normalize_control_scope_id( (string) ( $draft_writer['control_scope_id'] ?? '' ) ),
					'process_id' => self::normalize_process_id( (string) ( $draft_writer['process_id'] ?? '' ) ),
					'session_origin' => self::normalize_session_origin( (string) ( $draft_writer['session_origin'] ?? '' ) ),
				),
				'provenance_match' => false,
			);
		}

		$writer = isset( $eligibility['writer'] ) && is_array( $eligibility['writer'] ) ? $eligibility['writer'] : array();
		$reviewer = isset( $eligibility['reviewer'] ) && is_array( $eligibility['reviewer'] ) ? $eligibility['reviewer'] : array();
		$prior_reviewers = isset( $eligibility['prior_stage_reviewers'] ) && is_array( $eligibility['prior_stage_reviewers'] ) ? $eligibility['prior_stage_reviewers'] : array();
		$prior_actor_ids = array();
		foreach ( $prior_reviewers as $prior ) {
			if ( ! is_array( $prior ) ) {
				continue;
			}
			$actor_id = sanitize_key( (string) ( $prior['actor_id'] ?? '' ) );
			if ( '' !== $actor_id ) {
				$prior_actor_ids[] = $actor_id;
			}
		}

		return array(
			'server_checked' => true,
			'workflow_rule' => 'nobody_may_review_their_own_work',
			'obligation' => sanitize_key( $obligation ),
			'message' => 'The server selected this item only after comparing current writer, reviewer, token label, process, control scope, runtime mutation, and prior-stage reviewer provenance. Use this current server proof instead of stale local memory when deciding whether the assignment is safe.',
			'reviewer' => array(
				'actor_id' => sanitize_key( (string) ( $reviewer['actor_id'] ?? '' ) ),
				'token_label' => sanitize_key( (string) ( $reviewer['token_label'] ?? '' ) ),
				'control_scope_id' => self::normalize_control_scope_id( (string) ( $reviewer['control_scope_id'] ?? '' ) ),
				'process_id' => self::normalize_process_id( (string) ( $reviewer['process_id'] ?? '' ) ),
				'session_origin' => self::normalize_session_origin( (string) ( $reviewer['session_origin'] ?? '' ) ),
			),
			'current_writer' => array(
				'actor_id' => sanitize_key( (string) ( $writer['actor_id'] ?? '' ) ),
				'token_label' => sanitize_key( (string) ( $writer['token_label'] ?? '' ) ),
				'control_scope_id' => self::normalize_control_scope_id( (string) ( $writer['control_scope_id'] ?? '' ) ),
				'process_id' => self::normalize_process_id( (string) ( $writer['process_id'] ?? '' ) ),
				'recorded_at' => sanitize_text_field( (string) ( $writer['recorded_at'] ?? '' ) ),
			),
			'prior_stage_reviewer_actor_ids' => array_values( array_unique( $prior_actor_ids ) ),
			'provenance_match' => false,
		);
	}

	private static function heartbeat_prior_stage_reviewers_for_obligation( int $translation_id, string $obligation ): array {
		if ( 'linguistic_review' === $obligation ) {
			return self::review_attempt_prior_reviewers_for_stage( $translation_id, 'linguistic_review' );
		} elseif ( 'quality_review' === $obligation ) {
			return self::review_attempt_prior_reviewers_for_stage( $translation_id, 'quality_review' );
		} elseif ( 'final_review' === $obligation ) {
			return self::review_attempt_prior_reviewers_for_stage( $translation_id, 'final_review' );
		} elseif ( 'publish' === $obligation ) {
			return self::review_attempt_prior_reviewers_for_stage( $translation_id, 'publish' );
		}

		return array();
	}

	private static function heartbeat_repeats_previous_item_without_change( array $input, array $identity, int $translation_id, int $source_id, string $language, string $action ): bool {
		$agent_session_id = self::normalize_control_scope_id( (string) ( $input['agent_session_id'] ?? $identity['agent_session_id'] ?? $identity['control_scope_id'] ?? '' ) );
		if ( '' === $agent_session_id ) {
			return false;
		}

		$heartbeats = get_option( self::OPTION_HEARTBEATS, array() );
		if ( ! is_array( $heartbeats ) || empty( $heartbeats[ $agent_session_id ] ) || ! is_array( $heartbeats[ $agent_session_id ] ) ) {
			return false;
		}
		$previous = $heartbeats[ $agent_session_id ];
		if ( absint( $previous['last_source_id'] ?? 0 ) !== $source_id ) {
			return false;
		}
		if ( absint( $previous['last_translation_id'] ?? 0 ) !== $translation_id ) {
			return false;
		}
		if ( sanitize_key( (string) ( $previous['last_language'] ?? '' ) ) !== $language ) {
			return false;
		}
		if ( sanitize_key( (string) ( $previous['last_action'] ?? '' ) ) !== sanitize_key( $action ) ) {
			return false;
		}

		$last_seen_at = sanitize_text_field( (string) ( $previous['last_seen_at'] ?? '' ) );
		$last_seen_ts = '' !== $last_seen_at ? strtotime( $last_seen_at ) : false;
		$post = get_post( $translation_id );
		if ( ! $post || false === $last_seen_ts ) {
			return true;
		}
		$modified_ts = strtotime( (string) $post->post_modified_gmt . ' UTC' );
		if ( false === $modified_ts ) {
			return true;
		}

		return $modified_ts <= $last_seen_ts;
	}

	private static function reviewer_identity_matches_provenance( array $reviewer, array $provenance ): bool {
		$reviewer_control_scope = self::normalize_control_scope_id( (string) ( $reviewer['control_scope_id'] ?? '' ) );
		$provenance_control_scope = self::normalize_control_scope_id( (string) ( $provenance['control_scope_id'] ?? '' ) );
		if ( '' !== $reviewer_control_scope && '' !== $provenance_control_scope && $reviewer_control_scope === $provenance_control_scope ) {
			return true;
		}

		$reviewer_process = self::normalize_process_id( (string) ( $reviewer['process_id'] ?? '' ) );
		$provenance_process = self::normalize_process_id( (string) ( $provenance['process_id'] ?? '' ) );
		if ( '' !== $reviewer_process && '' !== $provenance_process && $reviewer_process === $provenance_process ) {
			return true;
		}

		$reviewer_agent_session = self::normalize_control_scope_id( (string) ( $reviewer['agent_session_id'] ?? '' ) );
		$provenance_agent_session = self::normalize_control_scope_id( (string) ( $provenance['agent_session_id'] ?? '' ) );
		if ( '' !== $reviewer_agent_session && '' !== $provenance_agent_session && $reviewer_agent_session === $provenance_agent_session ) {
			return true;
		}

		$reviewer_actor_id = sanitize_key( (string) ( $reviewer['actor_id'] ?? '' ) );
		$provenance_actor_id = sanitize_key( (string) ( $provenance['actor_id'] ?? '' ) );
		if ( '' !== $reviewer_actor_id && '' !== $provenance_actor_id && $reviewer_actor_id === $provenance_actor_id ) {
			return true;
		}

		$reviewer_token_label = sanitize_key( (string) ( $reviewer['token_label'] ?? '' ) );
		$provenance_token_label = sanitize_key( (string) ( $provenance['token_label'] ?? '' ) );
		if ( '' !== $reviewer_token_label && '' !== $provenance_token_label && $reviewer_token_label === $provenance_token_label ) {
			return true;
		}

		$reviewer_actor = sanitize_text_field( (string) ( $reviewer['actor'] ?? '' ) );
		$provenance_actor = sanitize_text_field( (string) ( $provenance['actor'] ?? '' ) );
		return '' !== $reviewer_actor && '' !== $provenance_actor && $reviewer_actor === $provenance_actor;
	}

	private static function reviewer_matches_any_provenance( array $reviewer, array $provenance_items ): bool {
		foreach ( $provenance_items as $provenance ) {
			if ( is_array( $provenance ) && self::reviewer_identity_matches_provenance( $reviewer, $provenance ) ) {
				return true;
			}
		}

		return false;
	}

	private static function heartbeat_skip_summary( array $item, string $reason ): array {
		return array(
			'reason' => sanitize_key( $reason ),
			'source_id' => absint( $item['source_id'] ?? 0 ),
			'translation_id' => absint( $item['translation_id'] ?? 0 ),
			'language' => sanitize_key( (string) ( $item['language'] ?? '' ) ),
		);
	}

	private static function public_heartbeat_identity( array $identity ): array {
		$public_identity = self::agent_session_public_identity( $identity );
		return array(
			'actor_id' => sanitize_key( (string) ( $identity['actor_id'] ?? '' ) ),
			'step_token_label' => sanitize_key( (string) ( $identity['step_token_label'] ?? '' ) ),
			'process_id' => $public_identity['process_id'],
			'control_scope_id' => $public_identity['control_scope_id'],
			'agent_session_id' => $public_identity['agent_session_id'],
			'llm_vendor' => $public_identity['llm_vendor'],
			'llm_client' => $public_identity['llm_client'],
			'authority_vendor' => $public_identity['authority_vendor'],
			'authority_client' => $public_identity['authority_client'],
			'session_origin' => $public_identity['session_origin'],
		);
	}

	private static function heartbeat_policy(): array {
		return array(
			'mode' => 'one_safe_action_at_a_time',
			'default_claim' => false,
			'self_review' => 'forbidden_by_actor_process_token_and_control_scope',
			'subagent_review' => 'forbidden_unless_session_origin_is_independent_session',
			'watcher_role' => 'observe_only_no_review_or_publish_signature',
			'backoff_on_wait_or_escalate' => true,
			'assignment_coverage_audit' => 'ai-translations/heartbeat-assignment-coverage',
			'publish_assignment' => 'eligible_after_current_independent_reviews_and_publish_gate',
		);
	}

	private static function heartbeat_status( array $input ): array {
		$max_age_seconds = isset( $input['max_age_seconds'] )
			? max( 1, min( 86400, absint( $input['max_age_seconds'] ) ) )
			: 900;
		$expected_actors = array();
		if ( ! empty( $input['expected_actors'] ) && is_array( $input['expected_actors'] ) ) {
			foreach ( $input['expected_actors'] as $actor ) {
				$actor = sanitize_key( (string) $actor );
				if ( '' !== $actor ) {
					$expected_actors[] = $actor;
				}
			}
		}
		$expected_actors = array_values( array_unique( $expected_actors ) );
		sort( $expected_actors );

		$heartbeats = get_option( self::OPTION_HEARTBEATS, array() );
		if ( ! is_array( $heartbeats ) ) {
			$heartbeats = array();
		}

		$sessions = array();
		$fresh_actor_threads = array();
		$fresh_thread_actors = array();
		$now = time();
		foreach ( $heartbeats as $thread_id => $heartbeat ) {
			if ( ! is_array( $heartbeat ) ) {
				continue;
			}
			$agent_session_id = self::normalize_control_scope_id( (string) ( $heartbeat['agent_session_id'] ?? $thread_id ) );
			if ( '' === $agent_session_id ) {
				continue;
			}
			$actor = sanitize_key( (string) ( $heartbeat['actor_id'] ?? $heartbeat['step_token_label'] ?? '' ) );
			$last_seen_at = sanitize_text_field( (string) ( $heartbeat['last_seen_at'] ?? '' ) );
			$last_seen_ts = $last_seen_at ? strtotime( $last_seen_at ) : 0;
			$age_seconds = $last_seen_ts ? max( 0, $now - $last_seen_ts ) : null;
			$fresh = null !== $age_seconds && $age_seconds <= $max_age_seconds;

			if ( $fresh && '' !== $actor ) {
				$fresh_actor_threads[ $actor ][] = $agent_session_id;
			}
			if ( $fresh ) {
				$fresh_thread_actors[ $agent_session_id ][] = '' !== $actor ? $actor : '(unknown)';
			}

			$sessions[] = array(
				'agent_session_id' => $agent_session_id,
				'llm_vendor' => sanitize_text_field( (string) ( $heartbeat['llm_vendor'] ?? '' ) ),
				'llm_client' => sanitize_text_field( (string) ( $heartbeat['llm_client'] ?? '' ) ),
				'authority_vendor' => sanitize_text_field( (string) ( $heartbeat['authority_vendor'] ?? '' ) ),
				'authority_client' => sanitize_text_field( (string) ( $heartbeat['authority_client'] ?? '' ) ),
				'actor' => $actor,
				'step_token_label' => sanitize_key( (string) ( $heartbeat['step_token_label'] ?? '' ) ),
				'session_origin' => self::normalize_session_origin( (string) ( $heartbeat['session_origin'] ?? '' ) ),
				'last_seen_at' => $last_seen_at,
				'latest_age_seconds' => $age_seconds,
				'fresh' => $fresh,
				'last_action' => sanitize_key( (string) ( $heartbeat['last_action'] ?? '' ) ),
				'last_source_id' => absint( $heartbeat['last_source_id'] ?? 0 ),
				'last_translation_id' => absint( $heartbeat['last_translation_id'] ?? 0 ),
				'last_language' => sanitize_key( (string) ( $heartbeat['last_language'] ?? '' ) ),
				'last_reason' => sanitize_key( (string) ( $heartbeat['last_reason'] ?? '' ) ),
				'note' => sanitize_textarea_field( (string) ( $heartbeat['note'] ?? '' ) ),
			);
		}

		usort(
			$sessions,
			static function ( array $a, array $b ): int {
				return strcmp( (string) ( $b['last_seen_at'] ?? '' ), (string) ( $a['last_seen_at'] ?? '' ) );
			}
		);

		$actors_seen = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( array $session ): string {
							return sanitize_key( (string) ( $session['actor'] ?? '' ) );
						},
						$sessions
					)
				)
			)
		);
		sort( $actors_seen );
		$fresh_actor_sessions = array_values(
			array_filter(
				$sessions,
				static function ( array $session ): bool {
					return ! empty( $session['actor'] ) && ! empty( $session['fresh'] );
				}
			)
		);
		$fresh_actors_seen = array_values(
			array_unique(
				array_map(
					static function ( array $session ): string {
						return sanitize_key( (string) ( $session['actor'] ?? '' ) );
					},
					$fresh_actor_sessions
				)
			)
		);
		sort( $fresh_actors_seen );

		$actor_collisions = array();
		foreach ( $fresh_actor_threads as $actor => $threads ) {
			$threads = array_values( array_unique( array_map( 'strval', $threads ) ) );
			if ( count( $threads ) > 1 ) {
				sort( $threads );
				$actor_collisions[] = array(
					'actor' => sanitize_key( (string) $actor ),
					'threads' => $threads,
				);
			}
		}

		$thread_collisions = array();
		foreach ( $fresh_thread_actors as $thread_id => $actors ) {
			$actors = array_values( array_unique( array_map( 'strval', $actors ) ) );
			if ( count( $actors ) > 1 ) {
				sort( $actors );
				$thread_collisions[] = array(
					'agent_session_id' => self::normalize_control_scope_id( (string) $thread_id ),
					'actors' => $actors,
				);
			}
		}

		$missing_actors = array_values( array_diff( $expected_actors, $actors_seen ) );
		sort( $missing_actors );
		$missing_fresh_actors = array_values( array_diff( $expected_actors, $fresh_actors_seen ) );
		sort( $missing_fresh_actors );
		$unexpected_actors = $expected_actors ? array_values( array_diff( $actors_seen, $expected_actors ) ) : array();
		sort( $unexpected_actors );
		$unexpected_fresh_actors = $expected_actors ? array_values( array_diff( $fresh_actors_seen, $expected_actors ) ) : array();
		sort( $unexpected_fresh_actors );
		$stale_sessions = array_values(
			array_filter(
				$sessions,
				static function ( array $session ): bool {
					return empty( $session['fresh'] );
				}
			)
		);

		$session_count_ok = ! $expected_actors || count( $fresh_actor_sessions ) >= count( $expected_actors );
		$actor_set_ok = ! $expected_actors || ( empty( $missing_actors ) && empty( $unexpected_actors ) );
		$collisions_ok = empty( $actor_collisions ) && empty( $thread_collisions );
		$freshness_ok = ! $expected_actors
			? empty( $stale_sessions )
			: ( empty( $missing_fresh_actors ) && empty( $unexpected_fresh_actors ) );
		$healthy = $session_count_ok && $actor_set_ok && $collisions_ok && $freshness_ok;

		return array(
			'success' => true,
			'healthy' => $healthy,
			'max_age_seconds' => $max_age_seconds,
			'expected_actors' => $expected_actors,
			'actors_seen' => $actors_seen,
			'fresh_actors_seen' => $fresh_actors_seen,
			'missing_actors' => $missing_actors,
			'missing_fresh_actors' => $missing_fresh_actors,
			'unexpected_actors' => $unexpected_actors,
			'unexpected_fresh_actors' => $unexpected_fresh_actors,
			'session_count' => count( $sessions ),
			'fresh_actor_session_count' => count( $fresh_actor_sessions ),
			'checks' => array(
				'session_count_ok' => $session_count_ok,
				'actor_set_ok' => $actor_set_ok,
				'collisions_ok' => $collisions_ok,
				'freshness_ok' => $freshness_ok,
			),
			'collisions' => array(
				'actor' => $actor_collisions,
				'thread' => $thread_collisions,
				'scope' => 'fresh_sessions_only',
			),
			'stale_sessions' => array_map(
				static function ( array $session ): array {
					return array(
						'agent_session_id' => $session['agent_session_id'],
						'actor' => $session['actor'],
						'last_seen_at' => $session['last_seen_at'],
						'latest_age_seconds' => $session['latest_age_seconds'],
					);
				},
				$stale_sessions
			),
			'sessions' => $sessions,
			'policy' => self::heartbeat_policy(),
		);
	}

	private static function record_heartbeat_state( array $input, array $selected, array $identity ): void {
		$agent_session_id = self::normalize_control_scope_id( (string) ( $input['agent_session_id'] ?? $identity['agent_session_id'] ?? '' ) );
		if ( '' === $agent_session_id ) {
			return;
		}
		$heartbeats = get_option( self::OPTION_HEARTBEATS, array() );
		if ( ! is_array( $heartbeats ) ) {
			$heartbeats = array();
		}
		$heartbeats[ $agent_session_id ] = array(
			'agent_session_id' => $agent_session_id,
			'llm_vendor' => sanitize_text_field( (string) ( $input['llm_vendor'] ?? $identity['llm_vendor'] ?? '' ) ),
			'llm_client' => sanitize_text_field( (string) ( $input['llm_client'] ?? $identity['llm_client'] ?? '' ) ),
			'authority_vendor' => sanitize_text_field( (string) ( $input['authority_vendor'] ?? $identity['authority_vendor'] ?? $identity['authority'] ?? '' ) ),
			'authority_client' => sanitize_text_field( (string) ( $input['authority_client'] ?? $identity['authority_client'] ?? '' ) ),
			'actor_id' => sanitize_key( (string) ( $identity['actor_id'] ?? '' ) ),
			'step_token_label' => sanitize_key( (string) ( $identity['step_token_label'] ?? '' ) ),
			'session_origin' => self::normalize_session_origin( (string) ( $identity['session_origin'] ?? '' ) ),
			'last_seen_at' => gmdate( 'c' ),
			'last_action' => sanitize_key( (string) ( $selected['action'] ?? '' ) ),
			'last_source_id' => absint( $selected['source_id'] ?? 0 ),
			'last_translation_id' => absint( $selected['translation_id'] ?? 0 ),
			'last_language' => sanitize_key( (string) ( $selected['language'] ?? '' ) ),
			'last_reason' => sanitize_key( (string) ( $selected['reason'] ?? '' ) ),
			'note' => ! empty( $input['note'] ) ? sanitize_textarea_field( (string) $input['note'] ) : '',
		);

		uasort(
			$heartbeats,
			static function ( $a, $b ): int {
				return strcmp( (string) ( $b['last_seen_at'] ?? '' ), (string) ( $a['last_seen_at'] ?? '' ) );
			}
		);
		$heartbeats = array_slice( $heartbeats, 0, 20, true );
		update_option( self::OPTION_HEARTBEATS, $heartbeats, false );
	}
}
