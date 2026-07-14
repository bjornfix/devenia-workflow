<?php
/**
 * Runtime proof for a fresh Devenia Workflow upgrade under WP-CLI.
 *
 * Run with wp eval-file after deleting devenia_workflow_version while loading
 * the plugin normally. WordPress runs init before this file, so reaching these
 * assertions proves the upgrade bootstrap completed without an admin-only API
 * fatal.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This regression proof must run through WP-CLI with WordPress loaded.' );
}

if ( ! class_exists( 'Devenia_Workflow' ) ) {
	throw new RuntimeException( 'Devenia Workflow was not loaded.' );
}

$required_functions = array(
	'request_filesystem_credentials',
	'wp_get_available_translations',
	'wp_download_language_pack',
	'wp_can_install_language_pack',
);

foreach ( $required_functions as $required_function ) {
	if ( ! function_exists( $required_function ) ) {
		throw new RuntimeException( 'Language-pack bootstrap dependency missing: ' . $required_function );
	}
}

if ( Devenia_Workflow::VERSION !== (string) get_option( 'devenia_workflow_version', '' ) ) {
	throw new RuntimeException( 'The fresh upgrade did not persist the current plugin version.' );
}

$language_pack_status = get_option( 'devenia_workflow_translation_language_pack_status', null );
if ( ! is_array( $language_pack_status ) || ! isset( $language_pack_status['language_packs'], $language_pack_status['missing'], $language_pack_status['installations'] ) ) {
	throw new RuntimeException( 'The fresh upgrade did not persist a complete language-pack status.' );
}

echo wp_json_encode(
	array(
		'success'       => true,
		'context'       => 'wp-cli',
		'fresh_upgrade' => true,
	),
	JSON_UNESCAPED_SLASHES
);
