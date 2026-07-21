<?php
/**
 * Uninstall cleanup for Devenia Workflow.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Clean plugin-owned options and custom tables for one site.
 */
function devenia_workflow_uninstall_site(): void {
	global $wpdb;

	$options = array(
		'devenia_workflow_language_registry',
		'devenia_workflow_version',
		'devenia_workflow_translation_language_pack_status',
		'devenia_workflow_translation_language_text_seeded',
		'devenia_workflow_translation_index_schema',
		'devenia_workflow_frontend_slow_log',
		'devenia_workflow_reviewer_style_profiles',
		'devenia_workflow_localized_menu_identities',
		'devenia_workflow_translation_rule_events_schema',
		'devenia_workflow_mode',
		'devenia_workflow_translation_author_archives',
		'devenia_workflow_runtime_mutation_provenance',
		'devenia_workflow_public_header_manifest',
		'devenia_workflow_pending_public_header_manifest',
		'devenia_workflow_public_header_enrollment',
		'devenia_workflow_source_inventory_schema',
		'devenia_workflow_source_inventory_active',
		'devenia_workflow_source_inventory_dirty',
		'devenia_workflow_source_inventory_epoch',
		'devenia_workflow_obligation_projection_epoch',
		'devenia_workflow_obligation_projection_lease',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	$option_prefixes = array(
		'devenia_workflow_translation_job_',
		'devenia_workflow_translation_run_',
		'devenia_workflow_translation_artifact_',
		'devenia_workflow_translation_quality_',
		'devenia_workflow_inventory_',
	);
	foreach ( $option_prefixes as $option_prefix ) {
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must remove plugin-owned dynamic Job, Run, artifact, quality, and inventory options.
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

	if ( is_plugin_active_for_network( 'devenia-workflow/devenia-workflow.php' ) ) {
		$devenia_workflow_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $devenia_workflow_site_ids as $devenia_workflow_site_id ) {
			switch_to_blog( (int) $devenia_workflow_site_id );
			devenia_workflow_uninstall_site();
			restore_current_blog();
		}

		return;
	}
}

devenia_workflow_uninstall_site();
