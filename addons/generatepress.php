<?php
/**
 * Optional GeneratePress integration.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Devenia_Workflow_GeneratePress_Adapter {
	/**
	 * Register late enough for the active theme to have loaded.
	 */
	public static function register(): void {
		add_action( 'after_setup_theme', array( __CLASS__, 'maybe_register_data_hooks' ), 20 );
	}

	/**
	 * Register only the data-mapping hook. Frontend presentation belongs to the
	 * active theme or a site presentation adapter.
	 */
	public static function maybe_register_data_hooks(): void {
		if ( ! self::is_active() ) {
			return;
		}

		add_filter( 'devenia_workflow_sync_source_presentation_meta', array( __CLASS__, 'sync_source_presentation_meta' ), 10, 3 );
	}

	/**
	 * Whether GeneratePress is available.
	 */
	private static function is_active(): bool {
		return function_exists( 'generate_do_attr' )
			|| function_exists( 'generate_construct_sidebars' )
			|| function_exists( 'generate_do_template_part' );
	}

	/**
	 * Mirror GeneratePress presentation meta from source content to translations.
	 *
	 * @param array<string,mixed> $result Presentation sync result.
	 * @param int                 $translation_id Translation post ID.
	 * @param WP_Post             $source Source post.
	 * @return array<string,mixed>
	 */
	public static function sync_source_presentation_meta( array $result, int $translation_id, WP_Post $source ): array {
		$result['generatepress_meta'] = array(
			'updated' => array(),
			'deleted' => array(),
		);

		$meta_keys = array(
			'_generate-disable-headline',
			'_generate-disable-nav',
			'_generate-disable-footer',
			'_generate-disable-footer-widgets',
			'_generate-sidebar-layout-meta',
			'_generate-full-width-content',
			'_generate-transparent-header',
			'_generate-sticky-navigation-meta',
		);

		foreach ( $meta_keys as $meta_key ) {
			$value  = get_post_meta( $source->ID, $meta_key, true );
			$before = get_post_meta( $translation_id, $meta_key, true );
			if ( '' === $value ) {
				delete_post_meta( $translation_id, $meta_key );
				if ( '' !== $before ) {
					$result['generatepress_meta']['deleted'][] = $meta_key;
				}
				continue;
			}

			update_post_meta( $translation_id, $meta_key, $value );
			if ( $before !== $value ) {
				$result['generatepress_meta']['updated'][] = $meta_key;
			}
		}

		return $result;
	}

	/**
	 * Normalize escaped block-comment markers from projected translated content.
	 */
	private static function normalize_block_comment_markers( string $content ): string {
		if ( false === strpos( $content, '\\u' ) ) {
			return $content;
		}

		return str_replace(
			array( '\\u002d', '\\u002D' ),
			'-',
			$content
		);
	}
}

Devenia_Workflow_GeneratePress_Adapter::register();
