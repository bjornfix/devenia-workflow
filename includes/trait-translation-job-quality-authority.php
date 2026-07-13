<?php
/**
 * Quality Authority and staged publication for Translation Jobs.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Translation_Job_Quality_Authority {
	/**
	 * Return the server-issued principal represented by one active Job claim.
	 *
	 * The principal authenticates the bounded Run, not a person's motivation.
	 * Its identity is derived from the server-generated claim secret and cannot
	 * be selected by a caller through coordinator labels.
	 *
	 * @return array<string,mixed>
	 */
	private static function translation_job_authenticated_principal( array $job, array $run, array $claim ): array {
		$role       = sanitize_key( (string) ( $run['role'] ?? $claim['role'] ?? '' ) );
		$run_id     = self::translation_job_clean_id( (string) ( $run['run_id'] ?? $claim['run_id'] ?? '' ) );
		$token_hash = sanitize_text_field( (string) ( $claim['token_hash'] ?? '' ) );
		$user_id    = get_current_user_id();
		$material   = implode( '|', array( (string) ( $job['job_id'] ?? '' ), $run_id, $role, (string) $user_id, $token_hash ) );

		return array(
			'principal_id'     => 'tjp_' . substr( hash( 'sha256', $material ), 0, 32 ),
			'job_id'           => (string) ( $job['job_id'] ?? '' ),
			'run_id'           => $run_id,
			'role'             => $role,
			'wordpress_user_id'=> $user_id,
			'authority'        => 'server_issued_translation_job_claim',
			'coordinator_label'=> sanitize_text_field( (string) ( $run['coordinator_id'] ?? '' ) ),
			'claim_digest'     => $token_hash,
			'issued_at'        => sanitize_text_field( (string) ( $claim['claimed_at'] ?? gmdate( 'c' ) ) ),
			'expires_at'       => sanitize_text_field( (string) ( $claim['expires_at'] ?? '' ) ),
		);
	}

	/**
	 * Build and validate the complete staged reader surface without writing it.
	 *
	 * @return array<string,mixed>
	 */
	private static function translation_job_stage_artifact( array $job, array $artifact ): array {
		$source = get_post( absint( $job['source_id'] ?? 0 ) );
		if ( ! $source instanceof WP_Post ) {
			return array( 'success' => false, 'code' => 'job_source_missing', 'message' => 'Translation Job source is unavailable.' );
		}
		$title   = sanitize_text_field( (string) ( $artifact['title'] ?? '' ) );
		$excerpt = sanitize_textarea_field( (string) ( $artifact['excerpt'] ?? '' ) );
		$language = sanitize_key( (string) ( $job['target_language'] ?? '' ) );
		if ( '' === $title ) {
			return array( 'success' => false, 'code' => 'staged_title_required', 'message' => 'A staged translation title is required.' );
		}

		$projection_input = array_merge(
			$artifact,
			array(
				'source_id'              => (int) $source->ID,
				'language'               => $language,
				'inherit_source_design'  => true,
				'strict_source_design_fragments' => true,
			)
		);
		$projection = self::inherited_source_design_content( $source, $projection_input, $language );
		if ( empty( $projection['success'] ) ) {
			return array_merge( $projection, array( 'code' => (string) ( $projection['code'] ?? 'staged_projection_failed' ) ) );
		}
		$content = self::normalize_gutenberg_content_for_storage(
			self::localize_internal_links_in_content( (string) ( $projection['content'] ?? '' ), $language )
		);
		$guardrails = self::translation_guardrails(
			$content,
			(string) $source->post_content,
			$title,
			$excerpt,
			self::translation_fitness_context( $language, (int) $source->ID )
		);
		if ( ! empty( $guardrails['issues'] ) ) {
			return array( 'success' => false, 'code' => 'staged_guardrails_failed', 'message' => 'The staged artifact failed translation guardrails.', 'guardrails' => $guardrails );
		}
		$taxonomy = self::validate_translated_post_terms_before_save( $source, $language, $artifact['taxonomies'] ?? array() );
		if ( empty( $taxonomy['success'] ) ) {
			return $taxonomy;
		}

		$translation_id = absint( $job['translation_id'] ?? 0 );
		if ( ! $translation_id ) {
			$translation_id = self::find_translation_id( (int) $source->ID, $language, self::translation_workflow_post_statuses( false ) );
		}
		$existing = $translation_id ? get_post( $translation_id ) : null;
		$route = $existing instanceof WP_Post
			? array(
				'translation_id' => (int) $existing->ID,
				'post_name'      => (string) $existing->post_name,
				'post_parent'    => (int) $existing->post_parent,
				'localized_path' => trim( (string) get_post_meta( (int) $existing->ID, self::META_LOCALIZED_PATH, true ), '/' ),
				'canonical_route'=> self::json_post_meta_value( (int) $existing->ID, self::META_CANONICAL_ROUTE ),
			)
			: array(
				'translation_id'       => 0,
				'localized_slug'       => sanitize_title( (string) ( $artifact['localized_slug'] ?? '' ) ),
				'localized_path'       => trim( sanitize_text_field( (string) ( $artifact['localized_path'] ?? '' ) ), '/' ),
				'localized_parent_id'  => absint( $artifact['localized_parent_id'] ?? 0 ),
				'localized_parent_path'=> trim( sanitize_text_field( (string) ( $artifact['localized_parent_path'] ?? '' ) ), '/' ),
			);
		if ( ! $existing instanceof WP_Post ) {
			$raw_slug = (string) ( $artifact['localized_slug'] ?? '' );
			$slug = sanitize_title( $raw_slug );
			if ( '' === $slug ) { return array( 'success' => false, 'code' => 'staged_localized_slug_required', 'message' => 'A new staged translation requires a localized slug.' ); }
			$slug_issue = self::validate_localized_slug( $raw_slug, $slug, $language, $source, ! empty( $artifact['allow_source_slug_in_url'] ), (string) ( $artifact['source_slug_reason'] ?? '' ) );
			if ( $slug_issue ) { return $slug_issue; }
			if ( self::has_wordpress_duplicate_slug_suffix( $slug ) ) { return array( 'success' => false, 'code' => 'staged_duplicate_slug_suffix', 'message' => 'A staged localized slug cannot use a WordPress duplicate suffix.' ); }
			$parent_id = absint( $artifact['localized_parent_id'] ?? 0 );
			if ( self::translation_slug_conflicts( $slug, (string) $source->post_type, $parent_id, 0 ) ) { return array( 'success' => false, 'code' => 'staged_localized_slug_collision', 'message' => 'The staged localized route collides with existing content.' ); }
		}

		$seo_input = isset( $artifact['seo'] ) && is_array( $artifact['seo'] ) ? $artifact['seo'] : array();
		$seo_title = self::seo_meta_input_value( $seo_input, array( 'seo_title', 'title' ) );
		$seo_description = self::seo_meta_input_value( $seo_input, array( 'seo_description', 'description' ) );
		$manifest = array(
			'schema_version'  => 1,
			'job_id'          => (string) $job['job_id'],
			'source_revision' => (string) $job['source_revision'],
			'language'        => $language,
			'content'         => array( 'title' => $title, 'excerpt' => $excerpt, 'gutenberg' => $content ),
			'seo'             => array(
				'title'         => '' !== $seo_title ? $seo_title : $title,
				'description'   => '' !== $seo_description ? $seo_description : ( '' !== $excerpt ? $excerpt : self::seo_description_from_content( $content ) ),
				'focus_keyword' => self::seo_meta_input_value( $seo_input, array( 'focus_keyword', 'keyword' ) ),
			),
			'taxonomies'      => self::translation_job_canonicalize( is_array( $artifact['taxonomies'] ?? null ) ? $artifact['taxonomies'] : array() ),
			'route'           => $route,
			'media'           => array(
				'featured_image_id' => (int) get_post_thumbnail_id( (int) $source->ID ),
				'featured_image_alt'=> sanitize_text_field( (string) ( $artifact['featured_image_alt'] ?? '' ) ),
			),
			'presentation'    => array(
				'source_design_hash' => (string) ( self::source_design_contract( $source )['design_hash'] ?? '' ),
				'localized_fragments' => self::translation_job_canonicalize( (array) ( $artifact['localized_fragments'] ?? array() ) ),
			),
		);
		$surface_revision = self::translation_job_surface_revision( $manifest );

		return array(
			'success'          => true,
			'content_revision' => hash( 'sha256', $title . "\n" . $excerpt . "\n" . $content ),
			'surface_revision' => $surface_revision,
			'manifest'         => $manifest,
			'projected_content'=> $content,
			'guardrails'       => $guardrails,
			'taxonomy'         => $taxonomy,
			'translation_id'   => $translation_id,
		);
	}

	/**
	 * Content-address one complete public surface contract.
	 */
	private static function translation_job_surface_revision( array $manifest ): string {
		return 'sr_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $manifest ) ) ?: '' ), 0, 40 );
	}

	/**
	 * Hash the current stored WordPress surface so drift invalidates approval.
	 */
	private static function translation_job_current_surface_revision( int $translation_id ): string {
		$post = get_post( $translation_id );
		if ( ! $post instanceof WP_Post ) {
			return '';
		}
		$manifest = array(
			'post' => array(
				'id' => (int) $post->ID,
				'title' => (string) $post->post_title,
				'excerpt' => (string) $post->post_excerpt,
				'content' => (string) $post->post_content,
				'status' => (string) $post->post_status,
				'slug' => (string) $post->post_name,
				'parent' => (int) $post->post_parent,
			),
			'seo' => array(
				'title' => (string) get_post_meta( $translation_id, 'rank_math_title', true ),
				'description' => (string) get_post_meta( $translation_id, 'rank_math_description', true ),
				'focus_keyword' => (string) get_post_meta( $translation_id, 'rank_math_focus_keyword', true ),
			),
			'taxonomies' => self::post_taxonomy_payload( $post ),
			'route' => array(
				'localized_path' => (string) get_post_meta( $translation_id, self::META_LOCALIZED_PATH, true ),
				'canonical_route' => self::json_post_meta_value( $translation_id, self::META_CANONICAL_ROUTE ),
			),
			'media' => array(
				'featured_image_id' => (int) get_post_thumbnail_id( $translation_id ),
				'visible_media_provenance' => self::json_post_meta_value( $translation_id, self::META_VISIBLE_MEDIA_PROVENANCE ),
			),
			'presentation' => array(
				'source_design_hash' => (string) get_post_meta( $translation_id, self::META_SOURCE_DESIGN_HASH, true ),
				'localized_fragments' => self::json_post_meta_value( $translation_id, self::META_LOCALIZED_FRAGMENTS ),
			),
		);
		return self::translation_job_surface_revision( $manifest );
	}

	/**
	 * Validate and issue immutable Quality evidence receipts.
	 *
	 * @return array<string,mixed>
	 */
	private static function translation_job_quality_evidence_receipts( array $job, array $artifact_record, array $input, array $reviewer_principal ): array {
		$required_kinds = array( 'deterministic_structure', 'source_coverage', 'localized_route_links', 'seo_taxonomy', 'offer_contact', 'http_live_dom' );
		$receipt_ids = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) ( $input['evidence_receipt_ids'] ?? array() ) ) ) ) );
		$resolved = array();
		$resolved_kinds = array();
		foreach ( $receipt_ids as $receipt_id ) {
			$receipt = get_option( self::translation_job_quality_receipt_key( $receipt_id ) );
			if ( ! is_array( $receipt ) ) {
				return array( 'success' => false, 'code' => 'quality_receipt_missing', 'message' => 'A submitted server Quality receipt ID does not exist.', 'receipt_id' => $receipt_id );
			}
			if (
				(string) ( $receipt['artifact_revision'] ?? '' ) !== (string) $artifact_record['artifact_revision']
				|| (string) ( $receipt['surface_revision'] ?? '' ) !== (string) $artifact_record['surface_revision']
				|| (string) ( $receipt['principal_id'] ?? '' ) !== (string) ( $reviewer_principal['principal_id'] ?? '' )
				|| 'workflow' !== (string) ( $receipt['issuer'] ?? '' )
				|| empty( $receipt['passed'] )
				|| 'quality-authority-v1' !== (string) ( $receipt['policy_revision'] ?? '' )
			) {
				return array( 'success' => false, 'code' => 'quality_receipt_binding_mismatch', 'message' => 'A server Quality receipt belongs to another artifact, surface, or Quality principal.', 'receipt_id' => $receipt_id );
			}
			$kind = sanitize_key( (string) ( $receipt['kind'] ?? '' ) );
			$resolved_kinds[ $kind ] = true;
			$resolved[] = $receipt;
		}
		$missing_kinds = array_values( array_diff( $required_kinds, array_keys( $resolved_kinds ) ) );
		if ( $missing_kinds || 6 !== count( $receipt_ids ) || 6 !== count( $resolved_kinds ) ) {
			return array( 'success' => false, 'code' => 'quality_receipt_set_incomplete', 'message' => 'The mandatory server Quality receipt set is incomplete.', 'missing_kinds' => $missing_kinds );
		}

		$attestations = array();
		foreach ( (array) ( $input['reviewer_attestations'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$kind = sanitize_key( (string) ( $row['kind'] ?? '' ) );
			$observation = trim( sanitize_textarea_field( (string) ( $row['observation'] ?? '' ) ) );
			if ( ! in_array( $kind, array( 'natural_language', 'factual_accuracy' ), true ) || strlen( $observation ) < 40 || self::is_generic_review_evidence( $observation ) ) { continue; }
			$attestations[ $kind ] = array(
				'kind' => $kind,
				'passed' => ! empty( $row['passed'] ),
				'observation' => $observation,
				'fragment_keys' => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $row['fragment_keys'] ?? array() ) ) ) ),
				'principal_id' => (string) ( $reviewer_principal['principal_id'] ?? '' ),
				'artifact_revision' => (string) $artifact_record['artifact_revision'],
				'surface_revision' => (string) $artifact_record['surface_revision'],
				'trust' => 'reviewer_attested',
			);
		}
		if ( array_diff( array( 'natural_language', 'factual_accuracy' ), array_keys( $attestations ) ) ) {
			return array( 'success' => false, 'code' => 'reviewer_attestations_incomplete', 'message' => 'Quality requires concrete natural-language and factual-accuracy attestations.' );
		}
		$browser = self::translation_job_browser_receipt( $job, $artifact_record, $input['browser_receipts'] ?? array(), $reviewer_principal, $input['browser_adapter_receipt_ids'] ?? array() );
		if ( empty( $browser['success'] ) ) { return $browser; }
		$record = array(
			'job_id'           => (string) $job['job_id'],
			'artifact_revision' => (string) $artifact_record['artifact_revision'],
			'surface_revision'  => (string) $artifact_record['surface_revision'],
			'reviewer_principal'=> $reviewer_principal,
			'server_receipt_ids'=> $receipt_ids,
			'server_receipts'   => $resolved,
			'reviewer_attestations' => $attestations,
			'browser_attestations'  => $browser['receipts'],
			'issued_at'         => gmdate( 'c' ),
			'issuer'            => 'devenia-workflow-quality-authority',
		);
		$record['evidence_revision'] = 'qe_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $record ) ) ?: '' ), 0, 40 );
		if ( ! self::atomic_create_option( self::translation_job_quality_evidence_key( $record['evidence_revision'] ), $record ) ) {
			$stored = get_option( self::translation_job_quality_evidence_key( $record['evidence_revision'] ) );
			if ( ! is_array( $stored ) || self::translation_job_canonicalize( $stored ) !== self::translation_job_canonicalize( $record ) ) {
				return array( 'success' => false, 'code' => 'quality_evidence_store_failed', 'message' => 'The immutable Quality evidence receipt could not be stored.' );
			}
			$record = $stored;
		}
		return array( 'success' => true, 'record' => $record );
	}

	/**
	 * Issue receipts only for checks Workflow can actually observe itself.
	 * Semantic language judgment remains an explicitly separate reviewer
	 * attestation and is never mislabeled as server-computed truth.
	 *
	 * @return array<string,mixed>
	 */
	private static function translation_job_server_quality_receipts( array $job, array $artifact_record, array $reviewer_principal ): array {
		$source = get_post( absint( $job['source_id'] ?? 0 ) );
		$artifact = isset( $artifact_record['artifact'] ) && is_array( $artifact_record['artifact'] ) ? $artifact_record['artifact'] : array();
		if ( ! $source instanceof WP_Post ) {
			return array( 'success' => false, 'code' => 'server_quality_source_missing', 'message' => 'Server Quality receipts require the current source.' );
		}
		$language = sanitize_key( (string) $job['target_language'] );
		$source_quality = self::translation_job_source_approval( $source );
		$coverage = self::translation_job_fragment_coverage( $job, $artifact['localized_fragments'] ?? array() );
		$links = self::translation_job_artifact_link_policy( $source, $language, $artifact['localized_fragments'] ?? array() );
		$contact = self::translation_job_artifact_contact_policy( $source, $artifact['localized_fragments'] ?? array() );
		$staged = ! empty( $artifact_record['staged_validation']['passed'] );
		$seo = isset( $artifact_record['surface_manifest']['seo'] ) && is_array( $artifact_record['surface_manifest']['seo'] ) ? $artifact_record['surface_manifest']['seo'] : array();
		$translation_id = absint( $artifact_record['translation_id'] ?? 0 );
		$surface_post = $translation_id ? get_post( $translation_id ) : $source;
		$surface_language = $translation_id ? $language : self::source_language_code();
		$surface_scope = $translation_id ? 'existing_translation_presentation_shell' : 'source_presentation_shell';
		$frontend = $surface_post instanceof WP_Post && 'publish' === (string) $surface_post->post_status
			? self::translation_job_http_live_dom_evidence( (string) get_permalink( $surface_post ), $surface_language )
			: array( 'success' => false, 'passed' => false, 'status_code' => 0, 'scope' => 'no_http_surface' );
		$projected_content = (string) ( $artifact_record['surface_manifest']['content']['gutenberg'] ?? '' );
		$staged_dom = do_blocks( $projected_content );
		$staged_dom_passed = '' !== trim( wp_strip_all_tags( $staged_dom ) ) && false === stripos( $staged_dom, '<!-- wp:' );
		$staged_dom_digest = hash( 'sha256', $staged_dom );
		$states = array(
			'deterministic_structure' => ! empty( $source_quality['passed'] ) && $staged,
			'source_coverage' => ! empty( $coverage['success'] ) && $staged,
			'localized_route_links' => ! empty( $links['success'] ),
			'seo_taxonomy' => '' !== trim( (string) ( $seo['title'] ?? '' ) ) && '' !== trim( (string) ( $seo['description'] ?? '' ) ) && isset( $artifact_record['surface_manifest']['taxonomies'] ),
			'offer_contact' => ! empty( $contact['success'] ),
			'http_live_dom' => ! empty( $frontend['success'] ) && ! empty( $frontend['passed'] ) && $staged_dom_passed,
		);
		$failed = array_keys( array_filter( $states, static function ( $passed ): bool { return ! $passed; } ) );
		if ( $failed ) {
			return array( 'success' => false, 'code' => 'server_quality_receipts_failed', 'message' => 'Workflow server checks failed and cannot issue Quality receipts.', 'failed_receipts' => $failed, 'frontend' => $frontend );
		}
		$receipts = array();
		$receipt_ids = array();
		foreach ( $states as $name => $passed ) {
			$body = array(
				'passed' => (bool) $passed,
				'issuer' => 'workflow',
				'trust' => 'server_computed',
				'kind' => $name,
				'artifact_revision' => (string) $artifact_record['artifact_revision'],
				'surface_revision' => (string) $artifact_record['surface_revision'],
				'principal_id' => (string) ( $reviewer_principal['principal_id'] ?? '' ),
				'adapter_revision' => defined( 'DEVENIA_WORKFLOW_VERSION' ) ? DEVENIA_WORKFLOW_VERSION : 'development',
				'policy_revision' => 'quality-authority-v1',
				'evidence_digest' => hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( array( $name, $passed, $frontend, $staged_dom_digest, $artifact_record['staged_validation'] ?? array() ) ) ) ?: '' ),
			);
			$receipt_id = 'qer_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $body ) ) ?: '' ), 0, 40 );
			$body['receipt_id'] = $receipt_id;
			$body['issued_at'] = gmdate( 'c' );
			if ( ! self::atomic_create_option( self::translation_job_quality_receipt_key( $receipt_id ), $body ) ) {
				$stored = get_option( self::translation_job_quality_receipt_key( $receipt_id ) );
				if ( ! is_array( $stored ) ) { return array( 'success' => false, 'code' => 'server_quality_receipt_store_failed', 'receipt_id' => $receipt_id ); }
				$body = $stored;
			}
			$receipt_ids[] = $receipt_id;
			$receipts[] = $body;
		}
		return array( 'success' => true, 'receipt_ids' => $receipt_ids, 'receipts' => $receipts, 'http_live_dom_scope' => $surface_scope );
	}

	/**
	 * Observe the reachable WordPress/theme shell without importing unrelated
	 * localized-menu or copy-policy checks into the staged DOM receipt.
	 */
	private static function translation_job_http_live_dom_evidence( string $url, string $language ): array {
		$request_url = add_query_arg( 'devenia_quality_receipt', wp_generate_uuid4(), $url );
		$response = wp_safe_remote_get( $request_url, array( 'timeout' => 15, 'redirection' => 3, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'passed' => false, 'status_code' => 0, 'url' => $url, 'language' => $language, 'error' => $response->get_error_message() );
		}
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$has_dom = false !== stripos( $body, '<html' ) && false !== stripos( $body, '<body' ) && '' !== trim( wp_strip_all_tags( $body ) );
		return array(
			'success' => 200 === $status_code && $has_dom,
			'passed' => 200 === $status_code && $has_dom,
			'status_code' => $status_code,
			'url' => $url,
			'language' => sanitize_key( $language ),
			'response_digest' => hash( 'sha256', $body ),
			'dom_bytes' => strlen( $body ),
			'scope' => 'http_wordpress_theme_shell',
		);
	}

	/**
	 * Validate four reviewer-attested browser receipts bound to the staged surface.
	 *
	 * @param mixed $raw_receipts Browser receipt payload.
	 * @return array<string,mixed>
	 */
	private static function translation_job_browser_receipt( array $job, array $artifact_record, $raw_receipts, array $reviewer_principal, $browser_adapter_receipt_ids = array() ): array {
		$raw_receipts = is_array( $raw_receipts ) ? $raw_receipts : array();
		$required = array( 'desktop:light', 'desktop:dark', 'mobile:light', 'mobile:dark' );
		$seen = array();
		$receipts = array();
		foreach ( $raw_receipts as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$viewport = sanitize_key( (string) ( $row['viewport_scheme'] ?? '' ) );
			$scheme = sanitize_key( (string) ( $row['color_scheme'] ?? '' ) );
			$key = $viewport . ':' . $scheme;
			$url = esc_url_raw( (string) ( $row['url'] ?? '' ) );
			$screenshot = strtolower( sanitize_text_field( (string) ( $row['screenshot_digest'] ?? '' ) ) );
			$dom = strtolower( sanitize_text_field( (string) ( $row['response_digest'] ?? '' ) ) );
			$layout = strtolower( sanitize_text_field( (string) ( $row['layout_digest'] ?? '' ) ) );
			$dimensions = is_array( $row['viewport'] ?? null ) ? $row['viewport'] : array();
			$policy_dimensions = 'desktop' === $viewport ? array( 1440, 1100, 1 ) : array( 390, 844, 1 );
			if (
				! in_array( $key, $required, true )
				|| (string) $artifact_record['artifact_revision'] !== (string) ( $row['artifact_revision'] ?? '' )
				|| (string) $artifact_record['surface_revision'] !== (string) ( $row['surface_revision'] ?? '' )
				|| '' === $url
				|| ! preg_match( '/^[a-f0-9]{64}$/', $screenshot )
				|| ! preg_match( '/^[a-f0-9]{64}$/', $dom )
				|| ! preg_match( '/^[a-f0-9]{64}$/', $layout )
				|| array( absint( $dimensions['width'] ?? 0 ), absint( $dimensions['height'] ?? 0 ), absint( $dimensions['device_scale_factor'] ?? 0 ) ) !== $policy_dimensions
				|| '' === sanitize_text_field( (string) ( $row['document_language'] ?? '' ) )
				|| ! in_array( sanitize_key( (string) ( $row['document_direction'] ?? '' ) ), array( 'ltr', 'rtl' ), true )
			) {
				continue;
			}
			$seen[ $key ] = true;
			$receipts[] = array(
				'artifact_revision' => (string) $artifact_record['artifact_revision'],
				'surface_revision'  => (string) $artifact_record['surface_revision'],
				'principal_id'      => (string) ( $reviewer_principal['principal_id'] ?? '' ),
				'viewport_scheme'   => $viewport,
				'viewport'          => array( 'width' => $policy_dimensions[0], 'height' => $policy_dimensions[1], 'device_scale_factor' => $policy_dimensions[2] ),
				'color_scheme'      => $scheme,
				'url'               => $url,
				'response_digest'   => $dom,
				'document_language' => sanitize_text_field( (string) $row['document_language'] ),
				'document_direction'=> sanitize_key( (string) $row['document_direction'] ),
				'layout_digest'     => $layout,
				'screenshot_digest' => $screenshot,
				'checked_at'        => sanitize_text_field( (string) ( $row['checked_at'] ?? gmdate( 'c' ) ) ),
				'adapter'           => sanitize_key( (string) ( $row['adapter'] ?? 'fresh_quality_browser' ) ),
				'trust'             => 'reviewer_attested',
			);
		}
		$missing = array_values( array_diff( $required, array_keys( $seen ) ) );
		$adapter_ids = array_values( array_filter( array_map( 'sanitize_text_field', (array) $browser_adapter_receipt_ids ) ) );
		$adapter_ids = apply_filters( 'devenia_workflow_translation_job_browser_adapter_receipt_ids', $adapter_ids, $job, $artifact_record, $reviewer_principal );
		return $missing
			? array( 'success' => false, 'code' => 'browser_receipts_incomplete', 'missing' => $missing )
			: array( 'success' => true, 'receipts' => $receipts, 'receipt_count' => count( $receipts ), 'browser_adapter_receipt_ids' => $adapter_ids );
	}

	/**
	 * Apply one approved staged artifact to WordPress at publication time only.
	 *
	 * @return array<string,mixed>
	 */
	private static function translation_job_apply_staged_artifact( array $job, array $artifact_record ): array {
		$artifact = isset( $artifact_record['artifact'] ) && is_array( $artifact_record['artifact'] ) ? $artifact_record['artifact'] : array();
		$source = get_post( absint( $job['source_id'] ?? 0 ) );
		if ( ! $source instanceof WP_Post ) {
			return array( 'success' => false, 'code' => 'job_source_missing', 'message' => 'Translation Job source is unavailable.' );
		}
		$translation_id = absint( $artifact_record['translation_id'] ?? $job['translation_id'] ?? 0 );
		if ( $translation_id && (string) ( $artifact_record['baseline_surface_revision'] ?? '' ) !== self::translation_job_current_surface_revision( $translation_id ) ) {
			return array( 'success' => false, 'code' => 'staged_surface_drifted', 'message' => 'The public translation surface changed after the staged artifact was submitted.' );
		}
		$status = $translation_id && 'publish' === get_post_status( $translation_id ) ? 'publish' : 'draft';
		$writer = isset( $artifact_record['writer_principal'] ) && is_array( $artifact_record['writer_principal'] ) ? $artifact_record['writer_principal'] : array();
		$upsert = array_merge(
			$artifact,
			array(
				'source_id' => (int) $job['source_id'],
				'language' => (string) $job['target_language'],
				'translation_id' => $translation_id,
				'inherit_source_design' => true,
				'strict_source_design_fragments' => true,
				'status' => $status,
				'translation_status' => 'needs_review',
				'allow_update_published' => true,
				'execution_id' => (string) ( $writer['principal_id'] ?? 'translation-job-writer' ),
				'writer_process_id' => (string) ( $writer['run_id'] ?? '' ),
				'writer_actor' => (string) ( $writer['principal_id'] ?? '' ),
			)
		);
		self::$translation_job_internal_identity = array(
			'success' => true,
			'step' => 'draft_write',
			'workflow_step' => 'draft_write',
			'process_id' => (string) ( $writer['run_id'] ?? '' ),
			'control_scope_id' => (string) ( $writer['principal_id'] ?? '' ),
			'execution_id' => (string) ( $writer['principal_id'] ?? '' ),
			'session_origin' => 'spawned_subagent',
			'actor' => 'translation-job:' . (string) ( $writer['principal_id'] ?? '' ),
			'actor_id' => 'translation_job_writer',
			'authority' => 'server_issued_translation_job_claim',
			'job_id' => (string) $job['job_id'],
			'run_id' => (string) ( $writer['run_id'] ?? '' ),
		);
		try {
			$result = self::upsert_translation( $upsert );
		} finally {
			self::$translation_job_internal_identity = array();
		}
		if ( empty( $result['success'] ) ) {
			return $result;
		}
		$translation_id = absint( $result['translation']['id'] ?? 0 );
		$featured_image_sync = self::sync_source_featured_image( $translation_id, $source );
		if ( empty( $featured_image_sync['write_verified'] ) ) {
			return array( 'success' => false, 'code' => 'featured_image_sync_failed', 'message' => 'The approved source featured image could not be synchronized.', 'featured_image_sync' => $featured_image_sync, 'translation_id' => $translation_id );
		}
		$featured_alt = trim( wp_strip_all_tags( (string) ( $artifact['featured_image_alt'] ?? '' ) ) );
		if ( '' !== $featured_alt ) {
			update_post_meta( $translation_id, self::META_FEATURED_IMAGE_ALT, $featured_alt );
		}
		$actual_content_revision = self::translation_job_translation_revision( $translation_id );
		if ( ! hash_equals( (string) $artifact_record['content_revision'], $actual_content_revision ) ) {
			return array( 'success' => false, 'code' => 'applied_content_revision_mismatch', 'message' => 'The applied WordPress content does not match the approved staged artifact.', 'translation_id' => $translation_id );
		}
		$surface_verification = self::translation_job_verify_applied_surface( $source, $translation_id, (array) $artifact_record['surface_manifest'] );
		if ( empty( $surface_verification['success'] ) ) {
			return array( 'success' => false, 'code' => 'applied_surface_revision_mismatch', 'message' => 'The applied WordPress surface does not match the complete approved staged surface.', 'translation_id' => $translation_id, 'surface_verification' => $surface_verification );
		}
		return array( 'success' => true, 'translation_id' => $translation_id, 'translation' => $result['translation'], 'featured_image_sync' => $featured_image_sync, 'surface_verification' => $surface_verification, 'current_surface_revision' => self::translation_job_current_surface_revision( $translation_id ) );
	}

	/** Verify every public surface family represented by the approved manifest. */
	private static function translation_job_verify_applied_surface( WP_Post $source, int $translation_id, array $manifest ): array {
		$post = get_post( $translation_id );
		if ( ! $post instanceof WP_Post ) { return array( 'success' => false, 'failed' => array( 'post_missing' ) ); }
		$failed = array();
		$content = (array) ( $manifest['content'] ?? array() );
		if ( (string) ( $content['title'] ?? '' ) !== (string) $post->post_title || (string) ( $content['excerpt'] ?? '' ) !== (string) $post->post_excerpt || (string) ( $content['gutenberg'] ?? '' ) !== (string) $post->post_content ) { $failed[] = 'content'; }
		$seo = (array) ( $manifest['seo'] ?? array() );
		if ( (string) ( $seo['title'] ?? '' ) !== (string) get_post_meta( $translation_id, 'rank_math_title', true ) || (string) ( $seo['description'] ?? '' ) !== (string) get_post_meta( $translation_id, 'rank_math_description', true ) || (string) ( $seo['focus_keyword'] ?? '' ) !== (string) get_post_meta( $translation_id, 'rank_math_focus_keyword', true ) ) { $failed[] = 'seo'; }
		$route = (array) ( $manifest['route'] ?? array() );
		$expected_slug = (string) ( $route['post_name'] ?? $route['localized_slug'] ?? '' );
		if ( '' !== $expected_slug && $expected_slug !== (string) $post->post_name ) { $failed[] = 'route_slug'; }
		if ( isset( $route['post_parent'] ) && (int) $route['post_parent'] !== (int) $post->post_parent ) { $failed[] = 'route_parent'; }
		$expected_path = trim( (string) ( $route['localized_path'] ?? '' ), '/' );
		if ( '' !== $expected_path && $expected_path !== trim( (string) get_post_meta( $translation_id, self::META_LOCALIZED_PATH, true ), '/' ) ) { $failed[] = 'route_path'; }
		$media = (array) ( $manifest['media'] ?? array() );
		if ( (int) ( $media['featured_image_id'] ?? 0 ) !== (int) get_post_thumbnail_id( $translation_id ) ) { $failed[] = 'media_image'; }
		if ( '' !== (string) ( $media['featured_image_alt'] ?? '' ) && (string) ( $media['featured_image_alt'] ?? '' ) !== (string) get_post_meta( $translation_id, self::META_FEATURED_IMAGE_ALT, true ) ) { $failed[] = 'media_alt'; }
		$presentation = (array) ( $manifest['presentation'] ?? array() );
		if ( (string) ( $presentation['source_design_hash'] ?? '' ) !== (string) get_post_meta( $translation_id, self::META_SOURCE_DESIGN_HASH, true ) || self::translation_job_canonicalize( (array) ( $presentation['localized_fragments'] ?? array() ) ) !== self::translation_job_canonicalize( (array) self::json_post_meta_value( $translation_id, self::META_LOCALIZED_FRAGMENTS ) ) ) { $failed[] = 'presentation'; }
		if ( 'post' === $source->post_type ) {
			$actual = self::post_taxonomy_payload( $post );
			foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
				$source_ids = array_values( array_map( static function ( WP_Term $term ): int { return (int) $term->term_id; }, (array) wp_get_post_terms( (int) $source->ID, $taxonomy, array( 'hide_empty' => false ) ) ) );
				$actual_source_ids = array_values( array_filter( array_map( static function ( array $term ): int { return absint( $term['source_term_id'] ?? 0 ); }, (array) ( $actual[ $taxonomy ] ?? array() ) ) ) );
				sort( $source_ids ); sort( $actual_source_ids );
				if ( $source_ids !== $actual_source_ids ) { $failed[] = 'taxonomy_' . $taxonomy; }
			}
		}
		$evidence = array( 'translation_id' => $translation_id, 'approved_surface_revision' => self::translation_job_surface_revision( $manifest ), 'failed' => $failed );
		$evidence['applied_surface_evidence_revision'] = 'asr_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $evidence ) ) ?: '' ), 0, 40 );
		return array_merge( $evidence, array( 'success' => empty( $failed ) ) );
	}

	/** Capture the complete mutable WordPress surface before staged publication. */
	private static function translation_job_capture_surface_snapshot( int $translation_id ): array {
		$post = $translation_id ? get_post( $translation_id ) : null;
		if ( ! $post instanceof WP_Post ) {
			return array( 'existed' => false, 'translation_id' => 0 );
		}
		$taxonomies = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$taxonomies[ $taxonomy ] = wp_get_object_terms( $translation_id, $taxonomy, array( 'fields' => 'ids' ) );
		}
		return array(
			'existed' => true,
			'translation_id' => $translation_id,
			'post' => array(
				'ID' => $translation_id,
				'post_title' => (string) $post->post_title,
				'post_excerpt' => (string) $post->post_excerpt,
				'post_content' => (string) $post->post_content,
				'post_status' => (string) $post->post_status,
				'post_name' => (string) $post->post_name,
				'post_parent' => (int) $post->post_parent,
				'menu_order' => (int) $post->menu_order,
			),
			'meta' => get_post_meta( $translation_id ),
			'taxonomies' => $taxonomies,
		);
	}

	/** Restore the pre-publication surface, or remove a newly-created candidate. */
	private static function translation_job_restore_surface_snapshot( array $snapshot, int $translation_id ): array {
		if ( empty( $snapshot['existed'] ) ) {
			if ( $translation_id && get_post( $translation_id ) ) {
				$result = wp_delete_post( $translation_id, true );
				return array( 'success' => $result instanceof WP_Post, 'action' => 'delete_new_candidate', 'translation_id' => $translation_id );
			}
			return array( 'success' => true, 'action' => 'no_candidate_created' );
		}
		$original_id = absint( $snapshot['translation_id'] ?? 0 );
		$updated = wp_update_post( (array) ( $snapshot['post'] ?? array() ), true );
		if ( is_wp_error( $updated ) || $original_id !== absint( $updated ) ) {
			return array( 'success' => false, 'action' => 'restore_existing', 'error' => is_wp_error( $updated ) ? $updated->get_error_message() : 'post_restore_failed' );
		}
		$existing_meta = get_post_meta( $original_id );
		foreach ( array_keys( $existing_meta ) as $key ) { delete_post_meta( $original_id, $key ); }
		foreach ( (array) ( $snapshot['meta'] ?? array() ) as $key => $values ) {
			foreach ( (array) $values as $value ) { add_post_meta( $original_id, (string) $key, maybe_unserialize( $value ) ); }
		}
		foreach ( (array) ( $snapshot['taxonomies'] ?? array() ) as $taxonomy => $term_ids ) {
			wp_set_object_terms( $original_id, array_map( 'absint', (array) $term_ids ), (string) $taxonomy, false );
		}
		clean_post_cache( $original_id );
		return array( 'success' => true, 'action' => 'restore_existing', 'translation_id' => $original_id );
	}

	/** Attach rollback evidence to any failure after the first staged mutation. */
	private static function translation_job_publish_failure_with_rollback( array $failure, $snapshot, int $translation_id ): array {
		if ( ! is_array( $snapshot ) ) { return $failure; }
		$failure['rollback'] = self::translation_job_restore_surface_snapshot( $snapshot, $translation_id );
		if ( empty( $failure['rollback']['success'] ) ) {
			$failure['code'] = 'publication_rollback_failed';
			$failure['message'] = 'Publication failed and the original WordPress surface could not be restored automatically.';
		}
		return $failure;
	}

	/** Revalidate the immutable Quality Authority record immediately before mutation. */
	private static function translation_job_validate_quality_evidence_record( array $quality, array $artifact_record ): array {
		$record = get_option( self::translation_job_quality_evidence_key( (string) ( $quality['evidence_revision'] ?? '' ) ) );
		if ( ! is_array( $record ) ) { return array( 'success' => false, 'code' => 'quality_evidence_missing' ); }
		$hash_input = $record;
		unset( $hash_input['evidence_revision'] );
		$expected_revision = 'qe_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $hash_input ) ) ?: '' ), 0, 40 );
		if ( ! hash_equals( $expected_revision, (string) ( $record['evidence_revision'] ?? '' ) ) ) { return array( 'success' => false, 'code' => 'quality_evidence_tampered' ); }
		if ( (string) ( $record['artifact_revision'] ?? '' ) !== (string) ( $artifact_record['artifact_revision'] ?? '' ) || (string) ( $record['surface_revision'] ?? '' ) !== (string) ( $artifact_record['surface_revision'] ?? '' ) ) { return array( 'success' => false, 'code' => 'quality_evidence_binding_mismatch' ); }
		$required = array( 'deterministic_structure', 'source_coverage', 'localized_route_links', 'seo_taxonomy', 'offer_contact', 'http_live_dom' );
		$kinds = array();
		foreach ( (array) ( $record['server_receipts'] ?? array() ) as $receipt ) {
			if ( ! is_array( $receipt ) || empty( $receipt['passed'] ) || 'workflow' !== (string) ( $receipt['issuer'] ?? '' ) || (string) ( $receipt['principal_id'] ?? '' ) !== (string) ( $record['reviewer_principal']['principal_id'] ?? '' ) ) { return array( 'success' => false, 'code' => 'quality_server_receipt_invalid' ); }
			$stored = get_option( self::translation_job_quality_receipt_key( (string) ( $receipt['receipt_id'] ?? '' ) ) );
			if ( ! is_array( $stored ) || self::translation_job_canonicalize( $stored ) !== self::translation_job_canonicalize( $receipt ) ) { return array( 'success' => false, 'code' => 'quality_server_receipt_missing' ); }
			$kinds[] = sanitize_key( (string) ( $receipt['kind'] ?? '' ) );
		}
		if ( array_diff( $required, array_unique( $kinds ) ) ) { return array( 'success' => false, 'code' => 'quality_server_receipt_set_incomplete' ); }
		if ( count( (array) ( $record['browser_attestations'] ?? array() ) ) < 4 || count( (array) ( $record['reviewer_attestations'] ?? array() ) ) < 2 ) { return array( 'success' => false, 'code' => 'quality_reviewer_evidence_incomplete' ); }
		if ( empty( $quality['usage']['usage_receipt_id'] ) || 'server_payload_estimate' !== (string) ( $quality['usage']['measurement_source'] ?? '' ) ) { return array( 'success' => false, 'code' => 'quality_usage_state_missing' ); }
		return array( 'success' => true, 'record' => $record );
	}

	private static function translation_job_quality_evidence_key( string $revision ): string {
		return 'devenia_tj_quality_evidence_' . self::translation_job_clean_id( $revision );
	}

	private static function translation_job_quality_receipt_key( string $receipt_id ): string {
		return 'devenia_tj_quality_receipt_' . self::translation_job_clean_id( $receipt_id );
	}
}
