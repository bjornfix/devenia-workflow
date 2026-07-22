<?php
/**
 * Source design review policy for AI Translation Workflow.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Source_Design_Review_Policy {
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
		$review_passed     = ! empty( $validation['available'] ) && ! empty( $review['passed'] ) && 'validation_passed' !== (string) ( $review['state'] ?? '' );

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
		if ( empty( $validation['available'] ) ) {
			return array(
				'passed'        => false,
				'state'         => 'source_design_validation_unavailable',
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

		$public_url = esc_url_raw( (string) ( $input['public_url'] ?? '' ) );
		if ( '' === $public_url || ! preg_match( '#^https?://#i', $public_url ) ) {
			return self::error( 'A public HTTP(S) URL inspected in a browser is required.', 'source_design_review_public_url_required' );
		}
		if ( ! self::source_review_url_matches_canonical_reader_surface( $source, $public_url ) ) {
			return self::error( 'The reviewed URL must be the canonical reader surface for this source.', 'source_design_review_url_mismatch' );
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
		if ( empty( $validation['available'] ) ) {
			return self::error( 'Source design review cannot be recorded while the configured validation Adapter is unavailable.', 'source_design_validation_unavailable' );
		}
		$summary    = self::source_editorial_design_validation_summary( $validation );
		$reviewer   = sanitize_text_field( (string) ( $input['reviewer'] ?? 'Devenia Workflow' ) );
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

	/** Bind review evidence to the exact source reader route, allowing query-only browser flags. */
	private static function source_review_url_matches_canonical_reader_surface( WP_Post $source, string $url ): bool {
		$canonical = get_permalink( (int) $source->ID );
		if ( ! is_string( $canonical ) || '' === $canonical ) {
			return false;
		}
		$review_parts    = wp_parse_url( $url );
		$canonical_parts = wp_parse_url( $canonical );
		if ( ! is_array( $review_parts ) || ! is_array( $canonical_parts ) ) {
			return false;
		}
		$review_host    = strtolower( (string) ( $review_parts['host'] ?? '' ) );
		$canonical_host = strtolower( (string) ( $canonical_parts['host'] ?? '' ) );
		$review_scheme  = strtolower( (string) ( $review_parts['scheme'] ?? '' ) );
		$canonical_scheme = strtolower( (string) ( $canonical_parts['scheme'] ?? '' ) );
		$review_port    = (int) ( $review_parts['port'] ?? 0 );
		$canonical_port = (int) ( $canonical_parts['port'] ?? 0 );
		$review_path    = '/' . trim( (string) ( $review_parts['path'] ?? '' ), '/' );
		$canonical_path = '/' . trim( (string) ( $canonical_parts['path'] ?? '' ), '/' );

		return '' !== $review_scheme
			&& '' !== $review_host
			&& hash_equals( $canonical_scheme, $review_scheme )
			&& hash_equals( $canonical_host, $review_host )
			&& $canonical_port === $review_port
			&& hash_equals( $canonical_path, $review_path );
	}
}
