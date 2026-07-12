<?php
/**
 * Source design review policy for AI Translation Workflow.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Source_Design_Review_Policy {
	/**
	 * Return the unified source-design gate state used by queues and guardrails.
	 *
	 * @return array<string,mixed>
	 */
	private static function source_design_gate_state( WP_Post $source, string $content = '', array $validation = array() ): array {
		$content = '' !== $content ? $content : (string) $source->post_content;
		if ( empty( $validation ) ) {
			$validation = self::source_editorial_design_validation( $source, $content );
		}
		$review = self::source_design_review_state( $source, $validation );
		$validation_passed = ! empty( $validation['passed'] );
		$review_passed     = ! empty( $review['passed'] ) && 'validation_passed' !== (string) ( $review['state'] ?? '' );

		return array(
			'passed'      => $validation_passed || $review_passed,
			'pass_source' => $validation_passed ? 'validation' : ( $review_passed ? 'reviewed_no_rewrite_needed' : '' ),
			'validation'  => $validation,
			'review'      => $review,
			'issue_codes' => self::sanitize_qa_code_list( $validation['issue_codes'] ?? array() ),
			'available'   => ! empty( $validation['available'] ),
		);
	}

	private static function source_design_review_state( WP_Post $source, array $validation = array() ): array {
		$source_id = (int) $source->ID;
		if ( empty( $validation ) ) {
			$validation = self::source_editorial_design_validation( $source, (string) $source->post_content );
		}
		if ( ! empty( $validation['passed'] ) ) {
			return array(
				'passed'        => true,
				'state'         => 'validation_passed',
				'source_id'     => $source_id,
				'source_hash'   => self::source_hash( $source ),
				'review_needed' => false,
			);
		}

		$current_hash = self::source_design_review_hash( $source, $validation );
		$stored_hash  = (string) get_post_meta( $source_id, self::META_SOURCE_DESIGN_REVIEW_HASH, true );
		$reviewed_at  = (string) get_post_meta( $source_id, self::META_SOURCE_DESIGN_REVIEWED_AT, true );
		$evidence_raw = (string) get_post_meta( $source_id, self::META_SOURCE_DESIGN_REVIEW_EVIDENCE, true );
		$evidence     = array();
		if ( '' !== $evidence_raw ) {
			$decoded  = json_decode( wp_unslash( $evidence_raw ), true );
			$evidence = is_array( $decoded ) ? $decoded : array();
		}
		$passed = '' !== $stored_hash && hash_equals( $current_hash, $stored_hash ) && '' !== $reviewed_at && ! empty( $evidence['design_already_suitable'] );

		return array(
			'passed'        => $passed,
			'state'         => $passed ? 'reviewed_no_rewrite_needed' : ( '' === $stored_hash ? 'needs_source_design_review_or_repair' : 'source_design_review_stale' ),
			'source_id'     => $source_id,
			'source_hash'   => self::source_hash( $source ),
			'review_hash'   => $current_hash,
			'stored_hash'   => $stored_hash,
			'reviewed_at'   => $reviewed_at,
			'reviewer'      => (string) get_post_meta( $source_id, self::META_SOURCE_DESIGN_REVIEWER, true ),
			'note'          => (string) get_post_meta( $source_id, self::META_SOURCE_DESIGN_REVIEW_NOTE, true ),
			'review_needed' => ! $passed,
		);
	}

	private static function source_design_review_hash( WP_Post $source, array $validation ): string {
		$summary = self::source_editorial_design_validation_summary( $validation );
		$payload = array(
			'source_hash'      => self::source_hash( $source ),
			'article_type'     => sanitize_key( (string) ( $summary['article_type'] ?? '' ) ),
			'template_slug'    => sanitize_text_field( (string) ( $summary['template_slug'] ?? '' ) ),
			'template_version' => sanitize_text_field( (string) ( $summary['template_version'] ?? '' ) ),
			'issue_codes'      => self::sanitize_qa_code_list( $summary['issue_codes'] ?? array() ),
		);

		return hash( 'sha256', wp_json_encode( $payload ) );
	}

	private static function mark_source_design_reviewed( array $input ): array {
		$source_id = absint( $input['source_id'] ?? 0 );
		$source    = $source_id ? get_post( $source_id ) : null;
		if ( ! $source instanceof WP_Post || ! self::is_translatable_post_type( (string) $source->post_type ) || self::is_translation_post( $source_id ) ) {
			return self::error( 'Source post not found.', 'source_post_not_found' );
		}
		if ( empty( $input['design_already_suitable'] ) ) {
			return self::error( 'Only mark source design reviewed when the current page is already suitable and should not be rewritten.', 'source_design_not_marked_suitable' );
		}

		$reservation = self::source_work_reservation_for_type( $source_id, 'source_design_repair' );
		if ( $reservation ) {
			$claim_token = (string) ( $input['claim_token'] ?? '' );
			if ( '' === $claim_token || ! hash_equals( (string) ( $reservation['token'] ?? '' ), $claim_token ) ) {
				return self::error( 'Source design review requires the active source_design_repair claim token.', 'source_design_review_claim_token_mismatch', array( 'reservation' => self::public_source_work_reservation( $reservation ) ) );
			}
		}

		$public_url = esc_url_raw( (string) ( $input['public_url'] ?? '' ) );
		if ( '' === $public_url || ! preg_match( '#^https?://#i', $public_url ) ) {
			return self::error( 'A public HTTP(S) URL inspected in a browser is required.', 'source_design_review_public_url_required' );
		}

		$text_fields = array(
			'contract_notes'       => 100,
			'desktop_render_notes' => 80,
			'mobile_render_notes'  => 80,
			'no_rewrite_reason'    => 80,
			'reviewer_statement'   => 80,
		);
		$clean = array();
		foreach ( $text_fields as $field => $min_length ) {
			$value = trim( wp_strip_all_tags( (string) ( $input[ $field ] ?? '' ) ) );
			if ( strlen( $value ) < $min_length ) {
				return self::error( 'Source design review evidence is incomplete.', 'source_design_review_evidence_incomplete', array( 'field' => $field, 'min_chars' => $min_length ) );
			}
			$clean[ $field ] = sanitize_textarea_field( $value );
		}

		$validation = self::source_editorial_design_validation( $source, (string) $source->post_content );
		$summary    = self::source_editorial_design_validation_summary( $validation );
		$reviewer   = sanitize_text_field( (string) ( $input['reviewer'] ?? 'Devenia AI Workflow' ) );
		$evidence   = array(
			'design_already_suitable' => true,
			'public_url'              => $public_url,
			'article_type'            => sanitize_key( (string) ( $input['article_type'] ?? $summary['article_type'] ?? '' ) ),
			'template_slug'           => sanitize_text_field( (string) ( $input['template_slug'] ?? $summary['template_slug'] ?? '' ) ),
			'contract_notes'          => $clean['contract_notes'],
			'desktop_render_notes'    => $clean['desktop_render_notes'],
			'mobile_render_notes'     => $clean['mobile_render_notes'],
			'no_rewrite_reason'       => $clean['no_rewrite_reason'],
			'reviewer_statement'      => $clean['reviewer_statement'],
			'validation_passed'       => ! empty( $validation['passed'] ),
			'validation_issue_codes'  => self::sanitize_qa_code_list( $summary['issue_codes'] ?? array() ),
			'validation_summary'      => $summary,
			'source_hash'             => self::source_hash( $source ),
			'reviewed_at'             => gmdate( 'c' ),
			'reviewer'                => $reviewer,
		);

		update_post_meta( $source_id, self::META_SOURCE_DESIGN_REVIEW_HASH, self::source_design_review_hash( $source, $validation ) );
		update_post_meta( $source_id, self::META_SOURCE_DESIGN_REVIEWED_AT, $evidence['reviewed_at'] );
		update_post_meta( $source_id, self::META_SOURCE_DESIGN_REVIEWER, $reviewer );
		update_post_meta( $source_id, self::META_SOURCE_DESIGN_REVIEW_NOTE, $clean['no_rewrite_reason'] );
		self::update_json_post_meta( $source_id, self::META_SOURCE_DESIGN_REVIEW_EVIDENCE, $evidence );

		return array(
			'success'              => true,
			'message'              => 'Source design marked reviewed; no source rewrite needed for the current review hash.',
			'source_id'            => $source_id,
			'source_design_review' => self::source_design_review_state( $source, $validation ),
			'editorial_source_validation' => $summary,
		);
	}
}
