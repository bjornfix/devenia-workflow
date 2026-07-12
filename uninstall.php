<?php
/**
 * Uninstall cleanup for Devenia AI Workflow.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Clean plugin-owned options and custom tables for one site.
 */
function ai_translation_workflow_uninstall_site(): void {
	global $wpdb;

	$options = array(
		'devenia_ai_translations_languages',
		'devenia_ai_translations_version',
		'devenia_ai_translations_language_pack_status',
		'devenia_ai_translations_language_text_seeded',
		'devenia_ai_translations_index_schema',
		'devenia_ai_translations_frontend_slow_log',
		'devenia_ai_translations_reviewer_style_profiles',
		'devenia_ai_translations_rule_events_schema',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	$option_prefixes = array(
		'devenia_ai_translation_claim_',
		'devenia_ai_work_claim_',
		'devenia_ai_assignment_',
		'devenia_ai_assignment_item_',
		'devenia_ai_assignment_outcome_',
		'devenia_ai_assignment_latest_outcome_',
		'devenia_ai_assignment_block_',
		'devenia_ai_translation_job_v2_',
		'devenia_ai_translation_run_v2_',
		'devenia_ai_translation_artifact_v2_',
		'devenia_ai_translation_quality_v2_',
	);
	foreach ( $option_prefixes as $option_prefix ) {
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must remove plugin-owned dynamic Assignment and Reservation options.
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $option_prefix ) . '%'
			)
		);
	}

	$tables = array(
		$wpdb->prefix . 'devenia_translation_index',
		$wpdb->prefix . 'devenia_translation_rule_events',
	);

	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are built from the current WordPress table prefix and fixed plugin suffixes during uninstall cleanup.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}
}

if ( is_multisite() ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	if ( is_plugin_active_for_network( 'devenia-ai-translations/devenia-ai-translations.php' ) ) {
		$ai_translation_workflow_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $ai_translation_workflow_site_ids as $ai_translation_workflow_site_id ) {
			switch_to_blog( (int) $ai_translation_workflow_site_id );
			ai_translation_workflow_uninstall_site();
			restore_current_blog();
		}

		return;
	}
}

ai_translation_workflow_uninstall_site();
