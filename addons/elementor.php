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
			array( __CLASS__, 'filter_translation_sibling_post_ids' ),
			10,
			3
		);
	}

	/**
	 * Share translation/WPML/Polylang sibling IDs with Elementor write guards.
	 *
	 * @param array   $sibling_ids Existing sibling IDs.
	 * @param int     $post_id Source post ID.
	 * @param WP_Post $post Source post.
	 * @return array
	 */
	public static function filter_translation_sibling_post_ids( array $sibling_ids, int $post_id, WP_Post $post ): array {
		unset( $post );

		return Devenia_AI_Translations::translation_sibling_ids_for_write_guards( $sibling_ids, $post_id );
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
