<?php
/**
 * Ability registration and dispatch platform.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Ability_Platform {
	/**
	 * Operation handler catalogue for MCP abilities.
	 *
	 * This is the deep dispatch Interface. Public ability names, callback
	 * registration, and integrity checks all resolve through this catalogue
	 * instead of duplicating operation-to-method knowledge.
	 *
	 * @return array<string,string>
	 */
	private static function ability_dispatch_handlers(): array {
		return array_merge(
			self::translation_job_dispatch_handlers(),
			array(
			'rebuild_source_inventory'         => 'rebuild_source_inventory',
			'source_inventory'                 => 'source_inventory',
			'translation_obligation_queue'     => 'translation_obligation_queue',
			'translation_job_next'          => 'translation_job_next',
			'translation_exhaustion_proof'     => 'translation_exhaustion_proof',
			'get_mode'                         => 'workflow_mode_status',
			'update_mode'                      => 'update_workflow_mode',
			'list_languages'                  => 'run_list_languages_operation',
			'get_presentation_surface'        => 'run_get_presentation_surface_operation',
			'translation_fitness_status'      => 'translation_fitness_regression_status',
			'lifecycle_regression_status'     => 'translation_lifecycle_regression_status',
			'language_packs_status'           => 'run_language_packs_status_operation',
			'translation_index_status'        => 'translation_index_status',
			'translation_fitness_scan'        => 'translation_fitness_scan',
			'wrong_language_carryover_scan'   => 'wrong_language_carryover_scan',
			'gutenberg_content_safety_scan'   => 'gutenberg_content_safety_scan',
			'frontend_performance_status'     => 'frontend_performance_status',
			'frontend_integrity_status'       => 'frontend_integrity_status',
			'warm_cache'                      => 'warm_translation_cache',
			'update_runtime_text'             => 'update_runtime_language_text',
			'update_public_header_manifest'   => 'update_public_header_manifest',
			'migrate_public_header_label_authority' => 'migrate_public_header_label_authority',
			'update_featured_image_alt'       => 'update_featured_image_alt',
			'get_quality_profile'             => 'get_runtime_quality_profile',
			'update_quality_profile'          => 'update_runtime_quality_profile',
			'record_language_rule_event'      => 'record_language_rule_event',
			'list_language_rule_events'       => 'list_language_rule_events',
			'learning_inbox'                  => 'learning_inbox',
			'review_learning_event'           => 'review_learning_event',
			'language_policy_status'          => 'language_policy_status',
			'agency_copy_brief'               => 'agency_copy_brief',
			'record_copy_feedback'            => 'record_copy_feedback',
			'get_reviewer_style_profile'      => 'get_reviewer_style_profile',
			'record_reviewer_style_edit'      => 'record_reviewer_style_edit',
			'repair_term_archive_self_redirects' => 'repair_term_archive_seo_self_redirects',
			'list_taxonomy_terms'             => 'list_translation_taxonomy_terms',
			'mark_source_taxonomy_reviewed'   => 'mark_source_taxonomy_reviewed',
			'mark_source_design_reviewed'     => 'mark_source_design_reviewed',
			'mark_source_content_integrity_reviewed' => 'mark_source_content_integrity_reviewed',
			'update_source_qa_options'        => 'update_source_qa_options',
			'authored_original_intake_queue'  => 'authored_original_intake_queue',
			'update_authored_original_intake' => 'update_authored_original_intake',
			'create_source_from_authored_original' => 'create_source_from_authored_original',
			'mark_source_generation_reviewed' => 'mark_source_generation_reviewed',
			'source_editor_status'            => 'source_editor_status',
			'get_source'                      => 'run_get_source_operation',
			'repair_translation_author'       => 'repair_translation_author',
			'reproject_source_design'         => 'reproject_source_design',
			'migrate_source_design_fragments' => 'migrate_source_design_fragments',
			'list_translations'               => 'list_translations',
			'verify_live_translation'         => 'verify_live_translation',
			'author_archive_queue'            => 'author_archive_queue',
			'update_author_archive_translation' => 'update_author_archive_translation',
			'quality_verdict'                 => 'quality_verdict',
			'mark_quality_reviewed'           => 'mark_quality_reviewed',
			'internal_link_opportunities'     => 'internal_link_opportunities',
			'sync_menu'                       => 'sync_public_header_projection',
			'repair_url_hierarchy'            => 'repair_url_hierarchy',
			'repair_internal_links'           => 'repair_internal_links',
			'repair_featured_images'          => 'repair_featured_images',
			)
		);
	}

	/**
	 * Normalize ability metadata through the operation handler catalogue.
	 *
	 * @param array<string,array<string,mixed>> $catalogue Raw ability catalogue.
	 * @return array<string,array<string,mixed>>
	 */
	private static function normalize_ability_catalogue_entries( array $catalogue ): array {
		$handlers = self::ability_operation_handlers();
		foreach ( $catalogue as $name => $args ) {
			$operation = self::ability_operation_from_name( (string) $name );
			if ( isset( $handlers[ $operation ] ) ) {
				$args['operation']        = $operation;
				$args['execute_callback'] = self::ability_operation_callback( $operation );
			}
			$catalogue[ $name ] = $args;
		}

		return $catalogue;
	}
}
