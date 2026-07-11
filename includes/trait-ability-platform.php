<?php
/**
 * Ability registration and dispatch platform.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Ability_Platform {
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
			self::translation_job_v2_dispatch_handlers(),
			array(
			'rebuild_source_inventory'         => 'rebuild_source_inventory',
			'source_inventory'                 => 'source_inventory',
			'translation_obligation_queue'     => 'translation_obligation_queue',
			'translation_job_v2_next'          => 'translation_job_v2_next',
			'translation_exhaustion_proof'     => 'translation_exhaustion_proof',
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
			'get_source'                      => 'run_get_source_operation',
			'reserve_work'                    => 'reserve_translation_work',
			'reserve_translation_work'        => 'reserve_translation_work',
			'release_reservation'             => 'release_translation_reservation',
			'release_translation_reservation' => 'release_translation_reservation',
			'list_reservations'               => 'list_translation_reservations',
			'list_translation_reservations'   => 'list_translation_reservations',
			'upsert_page'                     => 'upsert_translation',
			'repair_translation_author'       => 'repair_translation_author',
			'reproject_source_design'         => 'reproject_source_design',
			'migrate_source_design_fragments' => 'migrate_source_design_fragments',
			'list_translations'               => 'list_translations',
			'mark_reviewed'                   => 'run_mark_reviewed_operation',
			'qa_translation'                  => 'qa_translation',
			'mark_linguistic_reviewed'        => 'mark_linguistic_reviewed',
			'publish_translation'             => 'publish_translation',
			'verify_live_translation'         => 'verify_live_translation',
			'workflow_status'                 => 'workflow_status_from_input',
			'workflow_obligations'            => 'workflow_obligations',
			'production_flow'                 => 'production_flow',
			'accept_assignment'               => 'accept_assignment',
			'current_assignment'              => 'current_assignment',
			'renew_assignment'                => 'renew_assignment',
			'complete_assignment'             => 'complete_assignment',
			'resolve_assignment_block'        => 'resolve_assignment_block',
			'next_heartbeat_action'           => 'next_heartbeat_action',
			'heartbeat_assignment_coverage'   => 'heartbeat_assignment_coverage',
			'heartbeat_status'                => 'heartbeat_status',
			'queue'                           => 'translation_queue',
			'review_queue'                    => 'review_queue',
			'author_archive_queue'            => 'author_archive_queue',
			'update_author_archive_translation' => 'update_author_archive_translation',
			'quality_review_queue'            => 'quality_review_queue',
			'quality_verdict'                 => 'quality_verdict',
			'internal_link_opportunities'     => 'internal_link_opportunities',
			'mark_quality_reviewed'           => 'mark_quality_reviewed',
			'mark_final_reviewed'             => 'mark_final_reviewed',
			'sync_menu'                       => 'sync_language_menu',
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
			if ( isset( $args['input_schema'] ) && is_array( $args['input_schema'] ) ) {
				$args['input_schema'] = self::neutralize_agent_session_schema( $args['input_schema'] );
			}
			$catalogue[ $name ] = $args;
		}

		return $catalogue;
	}
}
