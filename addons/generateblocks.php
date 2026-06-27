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
		add_action( 'wp', array( __CLASS__, 'maybe_register_hooks' ), 20 );
	}

	/**
	 * Register only when a source GenerateBlocks stylesheet exists.
	 */
	public static function maybe_register_hooks(): void {
		if ( ! self::has_source_stylesheet() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( 'Devenia_AI_Translations', 'enqueue_translated_posts_page_source_styles' ), 20 );
	}

	/**
	 * Whether a generated source posts-page stylesheet is present.
	 */
	private static function has_source_stylesheet(): bool {
		$posts_page_id = absint( get_option( 'page_for_posts' ) );
		if ( ! $posts_page_id ) {
			return false;
		}

		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) ) {
			return false;
		}

		$path = trailingslashit( (string) $upload_dir['basedir'] ) . 'generateblocks/style-' . $posts_page_id . '.css';
		return is_readable( $path );
	}
}

AI_Translation_Workflow_GenerateBlocks_Addon::register();
