<?php
/**
 * Optional GeneratePress integration.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AI_Translation_Workflow_GeneratePress_Addon {
	/**
	 * Register late enough for the active theme to have loaded.
	 */
	public static function register(): void {
		add_action( 'after_setup_theme', array( __CLASS__, 'maybe_register_hooks' ), 20 );
	}

	/**
	 * Register GeneratePress hooks only when GeneratePress functions are present.
	 */
	public static function maybe_register_hooks(): void {
		if ( ! self::is_active() ) {
			return;
		}

		add_filter( 'generate_body_itemtype', array( 'Devenia_AI_Translations', 'filter_translated_posts_page_body_itemtype' ), 20 );
		add_action( 'generate_after_entry_title', array( 'Devenia_AI_Translations', 'render_source_blog_archive_updated_on' ), 12 );
		add_action( 'generate_menu_bar_items', array( 'Devenia_AI_Translations', 'render_mobile_language_menu_bar_item' ), 18 );
		add_filter( 'generate_logo_href', array( 'Devenia_AI_Translations', 'filter_logo_home_href' ), 20 );
		add_filter( 'generate_site_title_href', array( 'Devenia_AI_Translations', 'filter_logo_home_href' ), 20 );
		add_action( 'wp_enqueue_scripts', array( 'Devenia_AI_Translations', 'enqueue_rtl_layout_styles' ), 22 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ), 24 );

		add_action( 'ai_translation_workflow_before_translated_posts_page_main_content', array( __CLASS__, 'before_main_content' ) );
		add_action( 'ai_translation_workflow_before_translated_posts_page_loop', array( __CLASS__, 'before_loop' ) );
		add_action( 'ai_translation_workflow_after_translated_posts_page_loop', array( __CLASS__, 'after_loop' ) );
		add_action( 'ai_translation_workflow_after_translated_posts_page_primary_content_area', array( __CLASS__, 'after_primary_content_area' ) );
		add_action( 'ai_translation_workflow_after_translated_posts_page_main_content', array( __CLASS__, 'after_main_content' ) );
		add_filter( 'ai_translation_workflow_render_translated_posts_page_default_sidebar', '__return_false' );
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
	 * Mirror GeneratePress archive wrapper hook.
	 */
	public static function before_main_content(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Optional GeneratePress adapter hook.
		do_action( 'generate_before_main_content' );
	}

	/**
	 * Mirror GeneratePress loop hook.
	 */
	public static function before_loop(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Optional GeneratePress adapter hook.
		do_action( 'generate_before_loop', 'index' );
	}

	/**
	 * Mirror GeneratePress loop hook.
	 */
	public static function after_loop(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Optional GeneratePress adapter hook.
		do_action( 'generate_after_loop', 'index' );
	}

	/**
	 * Mirror GeneratePress content-area hook and sidebar output.
	 */
	public static function after_primary_content_area(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Optional GeneratePress adapter hook.
		do_action( 'generate_after_primary_content_area' );

		if ( function_exists( 'generate_construct_sidebars' ) ) {
			generate_construct_sidebars();
		}
	}

	/**
	 * Mirror GeneratePress archive wrapper hook.
	 */
	public static function after_main_content(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Optional GeneratePress adapter hook.
		do_action( 'generate_after_main_content' );
	}

	/**
	 * Load GeneratePress/GenerateBlocks compatibility styles.
	 */
	public static function enqueue_styles(): void {
		if ( ! Devenia_AI_Translations::is_translated_posts_page_request() ) {
			return;
		}

		$path = dirname( __DIR__ ) . '/assets/generatepress-compat.css';
		wp_enqueue_style(
			'ai-translation-workflow-generatepress-compat',
			plugins_url( '../assets/generatepress-compat.css', __FILE__ ),
			array(),
			is_readable( $path ) ? (string) filemtime( $path ) : Devenia_AI_Translations::VERSION
		);
	}
}

AI_Translation_Workflow_GeneratePress_Addon::register();
