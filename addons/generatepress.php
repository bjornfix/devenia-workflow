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
			add_filter( 'generate_excerpt_more_output', array( 'Devenia_AI_Translations', 'localize_read_more_output' ), 20 );
			add_filter( 'ai_translation_workflow_sync_source_presentation_meta', array( __CLASS__, 'sync_source_presentation_meta' ), 10, 3 );
			add_filter( 'ai_translation_workflow_publication_experience_subject_state', array( __CLASS__, 'publication_experience_subject_state' ), 10, 5 );
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
	 * Add GeneratePress/GenerateBlocks article-layout signals to publication readiness.
	 *
	 * @param array<string,mixed> $state Publication subject state.
	 * @param WP_Post             $post Post under review.
	 * @param string              $language Language code.
	 * @param string              $stage Calling stage.
	 * @param string              $content Normalized post content.
	 * @return array<string,mixed>
	 */
	public static function publication_experience_subject_state( array $state, WP_Post $post, string $language, string $stage, string $content ): array {
		unset( $language, $stage );

		if ( 'post' !== (string) $post->post_type ) {
			return $state;
		}

		$post_id = (int) $post->ID;
		$content = self::normalize_block_comment_markers( $content );
		$signals = isset( $state['signals'] ) && is_array( $state['signals'] ) ? $state['signals'] : array();
		$signals['generatepress_full_width_content'] = 'true' === (string) get_post_meta( $post_id, '_generate-full-width-content', true );
		$signals['generatepress_headline_disabled'] = 'true' === (string) get_post_meta( $post_id, '_generate-disable-headline', true );
		$signals['generateblocks_container_present'] = false !== strpos( $content, 'wp:generateblocks/container' );
		$signals['devenia_article_hero_present'] = false !== strpos( $content, 'dv-section--hero' )
			&& false !== strpos( $content, 'wp:generateblocks/container' );

		$blockers = isset( $state['blockers'] ) && is_array( $state['blockers'] ) ? $state['blockers'] : array();
		if ( empty( $signals['generatepress_full_width_content'] ) ) {
			$blockers[] = self::publication_experience_blocker( 'publication_experience_missing_full_width_content', 'Designed article posts must use the full-width content layout.', $post_id );
		}
		if ( empty( $signals['generatepress_headline_disabled'] ) ) {
			$blockers[] = self::publication_experience_blocker( 'publication_experience_default_headline_active', 'Designed article posts must disable the default theme headline so the in-content hero owns the H1 experience.', $post_id );
		}
		if ( empty( $signals['generateblocks_container_present'] ) ) {
			$blockers[] = self::publication_experience_blocker( 'publication_experience_section_system_missing', 'Designed article posts must use the canonical section block system.', $post_id );
		}
		if ( empty( $signals['devenia_article_hero_present'] ) ) {
			$blockers[] = self::publication_experience_blocker( 'publication_experience_hero_missing', 'Designed article posts must include the canonical article hero, not only the theme title and featured image.', $post_id );
		}

		$state['signals'] = $signals;
		$state['blockers'] = $blockers;
		$state['passed'] = empty( $blockers ) && ! empty( $state['passed'] );
		$state['state'] = $state['passed'] ? 'ready' : 'blocked';

		return $state;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function publication_experience_blocker( string $code, string $message, int $post_id ): array {
		return array(
			'code'     => sanitize_key( $code ),
			'severity' => 'block_publish',
			'message'  => sanitize_text_field( $message ),
			'details'  => array(
				'post_id' => $post_id,
				'adapter' => 'generatepress',
			),
		);
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

AI_Translation_Workflow_GeneratePress_Addon::register();
