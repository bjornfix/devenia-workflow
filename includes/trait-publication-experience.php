<?php
/**
 * Publication experience readiness for AI Translation Workflow.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Publication_Experience {
	/**
	 * Current publication-experience readiness for source and translated content.
	 *
	 * This is the shared Module behind review and publish gates. Callers should
	 * not duplicate visual/design heuristics; they only consume this result.
	 *
	 * @return array<string,mixed>
	 */
	private static function publication_experience_readiness_for_post( WP_Post $post, string $language = '', string $stage = 'post_publish' ): array {
		$post_id          = (int) $post->ID;
		$language_context = self::review_language_context_for_post( $post );
		$is_translation   = ! empty( $language_context['is_translation'] );
		$source_id        = $is_translation ? absint( $language_context['source_id'] ?? 0 ) : 0;
		$source           = $source_id ? get_post( $source_id ) : null;
		$language         = sanitize_key( '' !== $language ? $language : (string) ( $language_context['target_language'] ?? '' ) );
		$blockers         = array();
		$warnings         = array();
		$subjects         = array(
			'content' => self::publication_experience_subject_state( $post, $language, $stage ),
		);

		if ( $is_translation ) {
			if ( $source instanceof WP_Post && self::is_translatable_post_type( (string) $source->post_type ) ) {
				$subjects['source'] = self::publication_experience_subject_state( $source, self::source_language_code(), 'source_for_translation' );
			} else {
				$subjects['source'] = array(
					'passed'   => false,
					'state'    => 'missing_source',
					'post_id'  => $source_id,
					'blockers' => array(
						self::quality_verdict_blocker( 'source_publication_experience_missing_source', 'block_publish', 'Source content is missing, so publication experience cannot be verified.' ),
					),
					'warnings' => array(),
				);
			}
		}

		foreach ( $subjects as $name => $subject ) {
			foreach ( $subject['blockers'] ?? array() as $blocker ) {
				$blocker['details'] = array_merge(
					is_array( $blocker['details'] ?? null ) ? $blocker['details'] : array(),
					array( 'subject' => $name )
				);
				$blockers[] = $blocker;
			}
			foreach ( $subject['warnings'] ?? array() as $warning ) {
				$warnings[] = array_merge(
					is_array( $warning ) ? $warning : array( 'message' => (string) $warning ),
					array( 'subject' => $name )
				);
			}
		}

		$state = array(
			'passed'           => empty( $blockers ),
			'state'            => empty( $blockers ) ? 'publication_experience_ready' : 'publication_experience_blocked',
			'post_id'          => $post_id,
			'language'         => $language,
			'stage'            => sanitize_key( $stage ),
			'is_translation'   => $is_translation,
			'source_id'        => $source_id,
			'blockers'         => $blockers,
			'warnings'         => $warnings,
			'subjects'         => $subjects,
			'content_hash'     => self::translation_review_content_hash( $post ),
			'source_hash'      => $source instanceof WP_Post ? self::source_hash( $source ) : '',
			'checked_at'       => gmdate( 'c' ),
		);

		$filtered = apply_filters(
			'ai_translation_workflow_publication_experience_state',
			$state,
			$post,
			$language,
			array(
				'caller'         => 'devenia-ai-translations',
				'stage'          => sanitize_key( $stage ),
				'is_translation' => $is_translation,
				'source_id'      => $source_id,
			)
		);

		if ( is_array( $filtered ) ) {
			$filtered_blockers = isset( $filtered['blockers'] ) && is_array( $filtered['blockers'] ) ? $filtered['blockers'] : array();
			$filtered_warnings = isset( $filtered['warnings'] ) && is_array( $filtered['warnings'] ) ? $filtered['warnings'] : array();
			$state = array_merge( $state, $filtered );
			$state['blockers'] = array_values( array_unique( array_merge( $blockers, $filtered_blockers ), SORT_REGULAR ) );
			$state['warnings'] = array_values( array_unique( array_merge( $warnings, $filtered_warnings ), SORT_REGULAR ) );
		}

		$state['passed'] = empty( $state['blockers'] ) && ! empty( $state['passed'] );
		$state['state']  = $state['passed'] ? 'publication_experience_ready' : 'publication_experience_blocked';

		return self::compact_quality_profile( $state );
	}

	/**
	 * Readiness for one concrete source/translation post.
	 *
	 * @return array<string,mixed>
	 */
	private static function publication_experience_subject_state( WP_Post $post, string $language, string $stage ): array {
		$post_id   = (int) $post->ID;
		$post_type = (string) $post->post_type;
		$content   = self::normalize_gutenberg_content_for_storage( (string) $post->post_content );
		$blockers  = array();
		$warnings  = array();
		$signals   = array(
			'post_type'   => $post_type,
			'post_status' => (string) $post->post_status,
		);

		$source_design_gate = self::source_design_gate_state( $post, $content );
		$editorial_validation = isset( $source_design_gate['validation'] ) && is_array( $source_design_gate['validation'] ) ? $source_design_gate['validation'] : array();
		$source_design_review = isset( $source_design_gate['review'] ) && is_array( $source_design_gate['review'] ) ? $source_design_gate['review'] : array();
		$signals['editorial_source_validation'] = $editorial_validation;
		$signals['source_design_review'] = $source_design_review;
		$signals['source_design_gate'] = $source_design_gate;
		if ( empty( $source_design_gate['passed'] ) ) {
			$blockers[] = self::quality_verdict_blocker(
				empty( $editorial_validation['available'] ) ? 'publication_experience_editorial_adapter_unavailable' : 'publication_experience_editorial_design_failed',
				'block_publish',
				empty( $editorial_validation['available'] )
					? 'Devenia editorial design validation is unavailable, so publication experience cannot be trusted.'
					: 'The content does not pass the Devenia editorial design gate.',
				array(
					'post_id'    => $post_id,
					'issue_codes'=> $editorial_validation['issue_codes'] ?? array(),
					'source_design_review' => $source_design_review,
					'source_design_gate' => $source_design_gate,
				)
			);
		} elseif ( 'reviewed_no_rewrite_needed' === (string) ( $source_design_gate['pass_source'] ?? '' ) ) {
			$warnings[] = self::quality_verdict_blocker(
				'publication_experience_source_design_reviewed_no_rewrite',
				'review_note',
				'The source did not pass the automated presentation stamp, but a hash-bound source design review marked the current page suitable without rewriting.',
				array(
					'post_id' => $post_id,
					'state'   => $source_design_review['state'] ?? '',
				)
			);
		}

		if ( 'publish' === (string) $post->post_status && self::is_translation_post( $post_id ) && self::is_translation_language( $language ) ) {
			$frontend_integrity = self::frontend_public_surface_integrity_for_url(
				(string) get_permalink( $post ),
				$language,
				15,
				self::source_id_for_context( $post_id ) === absint( get_option( 'page_on_front' ) ) ? 'homepage' : 'singular'
			);
			$signals['frontend_integrity'] = $frontend_integrity;
			if ( empty( $frontend_integrity['passed'] ) ) {
				$blockers[] = self::quality_verdict_blocker(
					'publication_experience_frontend_integrity_failed',
					'block_publish',
					'Rendered public frontend output contains localization or source-language remnants that are not visible in stored Gutenberg content.',
					array(
						'post_id'     => $post_id,
						'language'    => $language,
						'url'         => (string) ( $frontend_integrity['url'] ?? '' ),
						'issue_codes' => self::qa_item_codes( $frontend_integrity['issues'] ?? array() ),
					)
				);
			}
		}

		$state = array(
			'passed'   => empty( $blockers ),
			'state'    => empty( $blockers ) ? 'ready' : 'blocked',
			'post_id'  => $post_id,
			'language' => sanitize_key( $language ),
			'stage'    => sanitize_key( $stage ),
			'blockers' => $blockers,
			'warnings' => $warnings,
			'signals'  => $signals,
		);

		$filtered = apply_filters(
			'ai_translation_workflow_publication_experience_subject_state',
			$state,
			$post,
			sanitize_key( $language ),
			sanitize_key( $stage ),
			$content
		);

		if ( is_array( $filtered ) ) {
			$state = array_merge( $state, $filtered );
		}
		$state['blockers'] = isset( $state['blockers'] ) && is_array( $state['blockers'] ) ? $state['blockers'] : array();
		$state['passed']   = empty( $state['blockers'] ) && ! empty( $state['passed'] );
		$state['state']    = $state['passed'] ? 'ready' : 'blocked';

		return $state;
	}
}
