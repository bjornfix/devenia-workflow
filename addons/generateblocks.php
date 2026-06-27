<?php
/**
 * Optional GenerateBlocks integration.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AI_Translation_Workflow_GenerateBlocks_Addon {
	/**
	 * Register optional GenerateBlocks assets.
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( 'Devenia_AI_Translations', 'enqueue_translated_posts_page_source_styles' ), 20 );
	}
}

AI_Translation_Workflow_GenerateBlocks_Addon::register();
