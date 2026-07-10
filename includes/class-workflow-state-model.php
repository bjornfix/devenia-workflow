<?php
/**
 * Pure translation workflow-state model.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Devenia_AI_Translations_Workflow_State_Model {
	/**
	 * Classify one translation read-model row.
	 *
	 * @param array<string,mixed> $translation Translation read-model row.
	 */
	public static function classify_translation( array $translation ): string {
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
	 * Return the default next action for a translation state.
	 */
	public static function next_action( string $state ): string {
		$actions = array(
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
}
