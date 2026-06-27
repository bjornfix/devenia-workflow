<?php
/**
 * Optional Elementor write-guard integration.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AI_Translation_Workflow_Elementor_Addon {
	/**
	 * Register Elementor-adjacent shared hooks.
	 */
	public static function register(): void {
		add_filter(
			'mcp_abilities_elementor_translation_sibling_post_ids',
			array( 'Devenia_AI_Translations', 'filter_elementor_translation_sibling_post_ids' ),
			10,
			3
		);
	}
}

AI_Translation_Workflow_Elementor_Addon::register();
