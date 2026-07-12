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
		add_filter(
			'devenia_ai_workflow_source_editor_contract',
			array( __CLASS__, 'filter_source_editor_contract' ),
			10,
			2
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
	 * Select the native Elementor source editor Adapter for Elementor documents.
	 *
	 * @param array   $contract Default editor contract.
	 * @param WP_Post $source Source post/page.
	 * @return array
	 */
	public static function filter_source_editor_contract( array $contract, WP_Post $source ): array {
		$edit_mode = (string) get_post_meta( (int) $source->ID, '_elementor_edit_mode', true );
		$data      = (string) get_post_meta( (int) $source->ID, '_elementor_data', true );
		if ( 'builder' !== $edit_mode || '' === trim( $data ) ) {
			return $contract;
		}

		$available = function_exists( 'mcp_abilities_elementor_load_document' );
		return array(
			'editor'                 => 'elementor',
			'available'              => $available,
			'read_ability'           => 'elementor/get-data',
			'content_write_ability'  => 'elementor/merge-element-settings',
			'design_write_ability'   => 'elementor/update-element',
			'completion_abilities'   => array(
				'elementor/merge-element-settings',
				'elementor/update-element',
				'elementor/delete-element',
				'ai-translations/mark-source-content-integrity-reviewed',
			),
			'native_controls_only'   => true,
			'public_route_immutable' => true,
			'instructions'           => 'This source is owned by Elementor. Read it through elementor/get-data and make only targeted native Elementor widget/container changes through the assigned Elementor ability. Preserve element IDs, responsive settings, global Kit styles, and the established Public Route. Do not use custom CSS, inline CSS, raw database writes, Gutenberg replacement content, or translation abilities.',
			'reason'                 => $available ? 'elementor_native_source_editor' : 'elementor_mcp_adapter_unavailable',
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
