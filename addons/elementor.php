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
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_register_hooks' ), 20 );
	}

	/**
	 * Register hooks only when Elementor is available.
	 */
	public static function maybe_register_hooks(): void {
		if ( ! self::is_active() ) {
			return;
		}

		add_filter(
			'mcp_abilities_elementor_translation_sibling_post_ids',
			array( 'Devenia_AI_Translations', 'filter_elementor_translation_sibling_post_ids' ),
			10,
			3
		);
	}

	/**
	 * Whether Elementor is available.
	 */
	private static function is_active(): bool {
		return defined( 'ELEMENTOR_VERSION' )
			|| did_action( 'elementor/loaded' )
			|| class_exists( '\Elementor\Plugin' );
	}
}

AI_Translation_Workflow_Elementor_Addon::register();
