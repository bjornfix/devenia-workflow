<?php
/**
 * Plugin Name: AI Translation Workflow
 * Description: AI/MCP workflow for WordPress content translations, localized URLs, hreflang, QA guardrails, and language menu sync.
 * Version: 0.1.258
 * Author: basicus
 * Author URI: https://profiles.wordpress.org/basicus/
 * License: GPL-2.0-or-later
 * Text Domain: ai-translation-workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Devenia_AI_Translations {
	const VERSION = '0.1.258';

	const OPTION_LANGUAGES = 'devenia_ai_translations_languages';
	const OPTION_VERSION   = 'devenia_ai_translations_version';
	const OPTION_LANGUAGE_PACK_STATUS = 'devenia_ai_translations_language_pack_status';
	const OPTION_LANGUAGE_TEXT_SEEDED = 'devenia_ai_translations_language_text_seeded';
	const OPTION_TRANSLATION_INDEX_SCHEMA = 'devenia_ai_translations_index_schema';
	const OPTION_FRONTEND_SLOW_LOG = 'devenia_ai_translations_frontend_slow_log';
	const OPTION_REVIEWER_STYLE_PROFILES = 'devenia_ai_translations_reviewer_style_profiles';
	const TRANSLATION_INDEX_SCHEMA_VERSION = '2';
	const OPTION_LANGUAGE_RULE_EVENTS_SCHEMA = 'devenia_ai_translations_rule_events_schema';
	const LANGUAGE_RULE_EVENTS_SCHEMA_VERSION = '1';

	const META_SOURCE_ID      = '_devenia_translation_source_id';
	const META_LANGUAGE       = '_devenia_translation_language';
	const META_SOURCE_HASH    = '_devenia_translation_source_hash';
	const META_STATUS         = '_devenia_translation_status';
	const META_LOCALIZED_PATH = '_devenia_translation_localized_path';
	const META_LEGACY_SOURCE_REDIRECT_LANGUAGES = '_devenia_translation_block_legacy_source_routes';
	const META_REVIEWED_AT    = '_devenia_translation_reviewed_at';
	const META_LINGUISTIC_REVIEWED_AT = '_devenia_translation_linguistic_reviewed_at';
	const META_LINGUISTIC_REVIEWER    = '_devenia_translation_linguistic_reviewer';
	const META_LINGUISTIC_REVIEW_NOTE = '_devenia_translation_linguistic_review_note';
	const META_LINGUISTIC_REVIEW_CHECKS = '_devenia_translation_linguistic_review_checks';
	const META_LINGUISTIC_REVIEW_EVIDENCE = '_devenia_translation_linguistic_review_evidence';
	const META_QUALITY_REVIEWED_AT = '_devenia_translation_quality_reviewed_at';
	const META_QUALITY_REVIEWER    = '_devenia_translation_quality_reviewer';
	const META_QUALITY_REVIEW_NOTE = '_devenia_translation_quality_review_note';
	const META_QUALITY_REVIEW_CHECKS = '_devenia_translation_quality_review_checks';
	const META_QUALITY_REVIEW_EVIDENCE = '_devenia_translation_quality_review_evidence';
	const META_COPY_FEEDBACK = '_devenia_translation_copy_feedback';
	const META_QA_OPTIONS = '_devenia_translation_qa_options';
	const META_AUTHORED_ORIGINAL_ID = '_devenia_translation_authored_original_id';
	const META_AUTHORED_LANGUAGE = '_devenia_translation_authored_language';
	const META_GENERATED_SOURCE_ID = '_devenia_translation_generated_source_id';
	const META_GENERATED_SOURCE_FROM_HASH = '_devenia_translation_generated_source_from_hash';
	const META_SOURCE_GENERATION_STATUS = '_devenia_translation_source_generation_status';
	const META_SOURCE_GENERATION_REVIEWED_AT = '_devenia_translation_source_generation_reviewed_at';
	const META_SOURCE_GENERATION_REVIEWER = '_devenia_translation_source_generation_reviewer';
	const META_SOURCE_GENERATION_NOTE = '_devenia_translation_source_generation_note';
	const META_SOURCE_GENERATION_EVIDENCE = '_devenia_translation_source_generation_evidence';
	const META_AUTHORED_INTAKE_STATUS = '_devenia_translation_authored_intake_status';
	const META_AUTHORED_INTAKE_LANGUAGE = '_devenia_translation_authored_intake_language';
	const META_AUTHORED_INTAKE_REASON = '_devenia_translation_authored_intake_reason';
	const META_AUTHORED_INTAKE_QUEUED_AT = '_devenia_translation_authored_intake_queued_at';
	const META_AUTHORED_INTAKE_HASH = '_devenia_translation_authored_intake_hash';
	const META_AUTHORED_INTAKE_ERROR = '_devenia_translation_authored_intake_error';
	const TERM_META_SOURCE_ID = '_devenia_translation_source_term_id';
	const TERM_META_LANGUAGE  = '_devenia_translation_language';
	const MENU_ITEM_META_SOURCE_ITEM_ID = '_devenia_translation_source_menu_item_id';
	const MENU_ITEM_META_SOURCE_OBJECT_ID = '_devenia_translation_source_menu_object_id';
	const MENU_ITEM_META_LANGUAGE = '_devenia_translation_menu_language';
	const MENU_ITEM_META_MANAGED = '_devenia_translation_menu_managed';

	/**
	 * Language for the translated posts-page loop while GeneratePress is treated as is_home().
	 *
	 * @var string
	 */
	private static $translated_posts_page_loop_language = '';

	/**
	 * Whether reviewer-style learning should ignore this plugin's own writes.
	 *
	 * @var bool
	 */
	private static $suspend_reviewer_style_capture = false;

	/**
	 * Whether source-stale marking should ignore output-preserving maintenance writes.
	 *
	 * @var bool
	 */
	private static $suspend_source_stale_marking = false;

	/**
	 * Whether direct-save storage guardrails should ignore trusted internal repairs.
	 *
	 * @var bool
	 */
	private static $suspend_direct_save_storage_guardrails = false;

	/**
	 * Bootstrap hooks.
	 */
	public static function init(): void {
		$elementor_translation_siblings_filter = 'mcp_abilities_elementor_translation_sibling_post_ids';

		add_filter( 'locale', array( __CLASS__, 'filter_locale' ) );
		add_filter( 'language_attributes', array( __CLASS__, 'filter_language_attributes' ) );
		add_filter( $elementor_translation_siblings_filter, array( __CLASS__, 'filter_elementor_translation_sibling_post_ids' ), 10, 3 );
		add_filter( 'query_vars', array( __CLASS__, 'register_translation_query_vars' ) );
		add_filter( 'post_link', array( __CLASS__, 'filter_translated_post_link' ), 20, 2 );
		add_filter( 'term_link', array( __CLASS__, 'filter_translated_term_link' ), 20, 3 );
		add_filter( 'previous_post_link', array( __CLASS__, 'filter_adjacent_post_link_for_translation' ), 20, 5 );
		add_filter( 'next_post_link', array( __CLASS__, 'filter_adjacent_post_link_for_translation' ), 20, 5 );
		add_filter( 'generate_body_itemtype', array( __CLASS__, 'filter_translated_posts_page_body_itemtype' ), 20 );
		add_filter( 'rank_math/opengraph/type', array( __CLASS__, 'filter_translated_posts_page_opengraph_type' ), 20 );
		add_filter( 'rank_math/frontend/canonical', array( __CLASS__, 'filter_translated_posts_page_canonical' ), 20 );
		add_filter( 'rank_math/frontend/title', array( __CLASS__, 'filter_translated_posts_page_seo_title' ), 20 );
		add_filter( 'rank_math/frontend/description', array( __CLASS__, 'filter_translated_posts_page_seo_description' ), 20 );
		add_filter( 'rank_math/json_ld', array( __CLASS__, 'filter_translated_posts_page_json_ld' ), 99, 2 );
		add_filter( 'template_include', array( __CLASS__, 'use_translated_posts_page_template' ), 20 );
		add_filter( 'the_content', array( __CLASS__, 'mark_quick_copy_edit_rendered_content' ), 99 );
		add_filter( 'body_class', array( __CLASS__, 'add_translated_posts_page_body_class' ), 999 );
		add_filter( 'body_class', array( __CLASS__, 'add_translated_front_page_body_class' ), 999 );
		add_action( 'generate_after_entry_title', array( __CLASS__, 'render_source_blog_archive_updated_on' ), 12 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_translated_posts_page_source_styles' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_heading_fit_assets' ), 25 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_quick_copy_edit_assets' ), 30 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_quick_copy_edit_admin_bar_node' ), 90 );
		add_action( 'init', array( __CLASS__, 'maybe_run_upgrade' ), 20 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_quick_copy_edit_rest_routes' ) );
		add_action( 'parse_request', array( __CLASS__, 'map_translated_post_request' ), 1 );
			add_action( 'wp_head', array( __CLASS__, 'print_language_links' ), 6 );
			add_action( 'wp_head', array( __CLASS__, 'print_rtl_layout_styles' ), 17 );
			add_action( 'wp_head', array( __CLASS__, 'print_translated_posts_page_styles' ), 18 );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_language_menu_styles' ), 24 );
			add_action( 'wp', array( __CLASS__, 'switch_frontend_locale' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_translated_posts_page_first_page_query' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_start_not_found_localization' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_localized_source_paths_with_language_prefix' ), 2 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_start_frontend_text_localization' ), 99 );
		add_action( 'shutdown', array( __CLASS__, 'record_slow_frontend_request' ), 0 );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'normalize_invalid_translation_content_before_save' ), 5, 2 );
		add_action( 'pre_post_update', array( __CLASS__, 'block_invalid_translation_content_save' ), 5, 2 );
		add_action( 'post_updated', array( __CLASS__, 'capture_manual_reviewer_style_on_post_update' ), 30, 3 );
		add_action( 'save_post', array( __CLASS__, 'mark_translations_stale_on_source_save' ), 20, 3 );
		add_action( 'save_post', array( __CLASS__, 'queue_authored_original_intake_on_save' ), 25, 3 );
		add_action( 'delete_post', array( __CLASS__, 'delete_translation_index_for_post' ), 20, 2 );
		add_filter( 'wp_nav_menu_args', array( __CLASS__, 'use_language_primary_menu' ), 20 );
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'localize_nav_menu_objects' ), 20, 2 );
		add_filter( 'wp_nav_menu_items', array( __CLASS__, 'append_language_menu_items' ), 20, 2 );
		add_action( 'generate_menu_bar_items', array( __CLASS__, 'render_mobile_language_menu_bar_item' ), 18 );
		add_filter( 'widget_block_content', array( __CLASS__, 'localize_widget_block_content' ), 20, 3 );
		add_filter( 'widget_title', array( __CLASS__, 'localize_widget_title' ), 20, 3 );
		add_filter( 'comment_form_defaults', array( __CLASS__, 'localize_comment_form_defaults' ), 20 );
		add_filter( 'comment_form_default_fields', array( __CLASS__, 'localize_comment_form_default_fields' ), 20 );
		add_filter( 'generate_logo_href', array( __CLASS__, 'filter_logo_home_href' ), 20 );
		add_filter( 'generate_site_title_href', array( __CLASS__, 'filter_logo_home_href' ), 20 );
		add_filter( 'manage_page_posts_columns', array( __CLASS__, 'add_admin_columns' ) );
		add_action( 'manage_page_posts_custom_column', array( __CLASS__, 'render_admin_column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_admin_filters' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_source_blog_archive_query' ), 20 );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_admin_filters' ) );
	}

	/**
	 * Share translation/WPML/Polylang translation siblings with Elementor write guards.
	 *
	 * @param array   $sibling_ids Existing sibling IDs.
	 * @param int     $post_id Source post ID.
	 * @param WP_Post $post Source post.
	 * @return array
	 */
	public static function filter_elementor_translation_sibling_post_ids( array $sibling_ids, int $post_id, WP_Post $post ): array {
		unset( $post );

		$ids = array_merge(
			$sibling_ids,
			self::translation_sibling_post_ids( $post_id ),
			self::wpml_sibling_post_ids( $post_id ),
			self::polylang_sibling_post_ids( $post_id )
		);

		return array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $ids ),
					static function ( int $sibling_id ) use ( $post_id ): bool {
						return $sibling_id > 0 && $sibling_id !== $post_id;
					}
				)
			)
		);
	}

	/**
	 * Return sibling IDs from this plugin's own source/translation model.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	private static function translation_sibling_post_ids( int $post_id ): array {
		$source_id = absint( get_post_meta( $post_id, self::META_SOURCE_ID, true ) );
		$ids       = array();

		if ( $source_id > 0 ) {
			$ids[] = $source_id;
		} else {
			$source_id = $post_id;
		}

		foreach ( self::translation_rows_for_source( $source_id, array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ) ) as $translation ) {
			if ( ! empty( $translation['id'] ) ) {
				$ids[] = (int) $translation['id'];
			}
		}

		return $ids;
	}

	/**
	 * Return sibling IDs from WPML when it is active.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	private static function wpml_sibling_post_ids( int $post_id ): array {
		$wpml_element_type_hook             = 'wpml_element_type';
		$wpml_element_language_details_hook = 'wpml_element_language_details';
		$wpml_translations_hook             = 'wpml_get_element_translations';

		if (
			! has_filter( $wpml_element_type_hook )
			|| ! has_filter( $wpml_element_language_details_hook )
			|| ! has_filter( $wpml_translations_hook )
		) {
			return array();
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$element_type = call_user_func_array( 'apply_filters', array( $wpml_element_type_hook, 'post_' . $post->post_type ) );
		if ( ! is_string( $element_type ) || '' === $element_type ) {
			return array();
		}

		$details = call_user_func_array(
			'apply_filters',
			array(
				$wpml_element_language_details_hook,
				null,
				array(
					'element_id'   => $post_id,
					'element_type' => $element_type,
				),
			)
		);

		if ( ! is_object( $details ) || empty( $details->trid ) ) {
			return array();
		}

		$translations = call_user_func_array( 'apply_filters', array( $wpml_translations_hook, null, $details->trid, $element_type ) );
		if ( ! is_array( $translations ) ) {
			return array();
		}

		$ids = array();
		foreach ( $translations as $translation ) {
			if ( is_object( $translation ) && ! empty( $translation->element_id ) ) {
				$ids[] = (int) $translation->element_id;
			}
		}

		return $ids;
	}

	/**
	 * Return sibling IDs from Polylang when it is active.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	private static function polylang_sibling_post_ids( int $post_id ): array {
		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			return array();
		}

		$translations = pll_get_post_translations( $post_id );
		return is_array( $translations ) ? array_map( 'intval', $translations ) : array();
	}

	/**
	 * Install persistent data structures when the plugin is activated.
	 */
	public static function activate(): void {
		self::install_translation_index_schema();
		self::install_language_rule_events_schema();
		self::rebuild_translation_index();
	}

	/**
	 * Classify the current request once so runtime gates stay consistent.
	 *
	 * @return array{admin:bool,ajax:bool,rest:bool,cli:bool,frontend:bool,upgrade_allowed:bool}
	 */
	private static function request_context(): array {
		static $context = null;

		if ( null !== $context ) {
			return $context;
		}

		$request_uri = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );
		if ( ! is_string( $request_uri ) || '' === $request_uri ) {
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		}

		$is_rest = ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| false !== strpos( $request_uri, '/wp-json' )
			|| false !== strpos( $request_uri, 'rest_route=' );
		$is_ajax = wp_doing_ajax();
		$is_admin = is_admin();
		$is_cli = defined( 'WP_CLI' ) && WP_CLI;

		$context = array(
			'admin'           => $is_admin,
			'ajax'            => $is_ajax,
			'rest'            => $is_rest,
			'cli'             => $is_cli,
			'frontend'        => ! $is_admin && ! $is_ajax && ! $is_rest && ! $is_cli,
			'upgrade_allowed' => ( $is_admin || $is_cli || $is_rest ) && ! $is_ajax,
		);

		return $context;
	}

	/**
	 * Detect REST/MCP-style requests early enough to skip frontend-only work.
	 */
	private static function is_rest_like_request(): bool {
		$context = self::request_context();
		return $context['rest'];
	}

	/**
	 * Frontend-only filters should not run during admin, Ajax, REST, CLI, or MCP calls.
	 */
	private static function is_frontend_runtime_request(): bool {
		$context = self::request_context();
		return $context['frontend'];
	}

	/**
	 * WordPress content types that can participate in the translation workflow.
	 *
	 * @return array<int,string>
	 */
	private static function translatable_post_types(): array {
		return array( 'page', 'post' );
	}

	/**
	 * Check whether a WordPress post type is supported by the translation workflow.
	 */
	private static function is_translatable_post_type( string $post_type ): bool {
		return in_array( sanitize_key( $post_type ), self::translatable_post_types(), true );
	}

	/**
	 * Human readable label for generic errors.
	 */
	private static function content_type_label( string $post_type ): string {
		return 'post' === $post_type ? 'post' : 'page';
	}

	/**
	 * Register query vars used by translated post request mapping.
	 *
	 * @param array<int,string> $vars Query vars.
	 * @return array<int,string>
	 */
	public static function register_translation_query_vars( array $vars ): array {
		$vars[] = 'devenia_translation_post';
		return array_values( array_unique( $vars ) );
	}

	/**
	 * Return localized permalinks for translated blog posts.
	 */
	public static function filter_translated_post_link( string $permalink, WP_Post $post ): string {
		if ( 'post' !== $post->post_type || ! self::is_translation_post( (int) $post->ID ) ) {
			return $permalink;
		}

		$language = sanitize_key( (string) get_post_meta( (int) $post->ID, self::META_LANGUAGE, true ) );
		if ( ! self::is_translation_language( $language ) ) {
			return $permalink;
		}

		$path = self::localized_path_for_post( (int) $post->ID, $language );
		return $path ? home_url( '/' . trim( $path, '/' ) . '/' ) : $permalink;
	}

	/**
	 * Return localized category/tag permalinks for language-scoped translated terms.
	 *
	 * @param string  $termlink Core term link.
	 * @param WP_Term $term     Term object.
	 * @param string  $taxonomy Taxonomy name.
	 */
	public static function filter_translated_term_link( string $termlink, WP_Term $term, string $taxonomy ): string {
		if ( ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
			return $termlink;
		}

		$language = sanitize_key( (string) get_term_meta( (int) $term->term_id, self::TERM_META_LANGUAGE, true ) );
		if ( ! self::is_translation_language( $language ) ) {
			return $termlink;
		}

		$base = self::localized_taxonomy_base_path( $language, $taxonomy );
		if ( '' === $base ) {
			return $termlink;
		}

		return home_url( '/' . trim( $base . '/' . $term->slug, '/' ) . '/' );
	}

	/**
	 * Avoid leaking source-language adjacent-post navigation on translated posts.
	 *
	 * @param string  $output   Rendered adjacent post link.
	 * @param string  $format   Link format.
	 * @param string  $link     Link text format.
	 * @param WP_Post $post     Adjacent post object.
	 * @param string  $adjacent Previous or next.
	 */
	public static function filter_adjacent_post_link_for_translation( string $output, string $format = '', string $link = '', $post = null, string $adjacent = '' ): string {
		$current_id = (int) get_queried_object_id();
		if ( $current_id && self::is_translation_post( $current_id ) && 'post' === get_post_type( $current_id ) ) {
			return '';
		}

		return $output;
	}

	/**
	 * Map localized translated post paths to native single-post queries.
	 */
	public static function map_translated_post_request( WP $wp ): void {
		if ( is_admin() || empty( $wp->request ) ) {
			return;
		}

		$path = '/' . trim( (string) $wp->request, '/' ) . '/';
		$term_match = self::translated_term_request_for_path( $path );
		if ( ! empty( $term_match ) ) {
			if ( 'post_tag' === $term_match['taxonomy'] ) {
				$wp->query_vars = array(
					'tag' => (string) $term_match['slug'],
				);
			} else {
				$wp->query_vars = array(
					'category_name' => (string) $term_match['slug'],
				);
			}
			return;
		}

		$translation_id = self::find_translation_id_by_target_path( $path, array( 'publish' ) );
		if ( ! $translation_id ) {
			return;
		}

		$post = get_post( $translation_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return;
		}

		$wp->query_vars = array(
			'p'                         => (int) $post->ID,
			'post_type'                 => 'post',
			'devenia_translation_post'  => (int) $post->ID,
		);
	}

	/**
	 * Match a localized translated term archive path to a native WordPress term query.
	 *
	 * @return array{taxonomy:string,slug:string,term_id:int}|array<string,mixed>
	 */
	private static function translated_term_request_for_path( string $path ): array {
		$clean_path = trim( $path, '/' );
		if ( '' === $clean_path ) {
			return array();
		}

		foreach ( self::target_languages() as $language => $config ) {
			foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
				$base = self::localized_taxonomy_base_path( (string) $language, $taxonomy );
				if ( '' === $base ) {
					continue;
				}

				$base = trim( $base, '/' );
				if ( 0 !== strpos( $clean_path, $base . '/' ) ) {
					continue;
				}

				$slug = trim( substr( $clean_path, strlen( $base ) + 1 ), '/' );
				if ( '' === $slug || false !== strpos( $slug, '/' ) ) {
					continue;
				}

				$term = get_term_by( 'slug', sanitize_title( $slug ), $taxonomy );
				if ( ! $term instanceof WP_Term ) {
					continue;
				}

				$term_language = sanitize_key( (string) get_term_meta( (int) $term->term_id, self::TERM_META_LANGUAGE, true ) );
				if ( $term_language !== sanitize_key( (string) $language ) ) {
					continue;
				}

				return array(
					'taxonomy' => $taxonomy,
					'slug'     => (string) $term->slug,
					'term_id'  => (int) $term->term_id,
				);
			}
		}

		return array();
	}

	/**
	 * Run lightweight upgrade tasks once per deployed plugin version.
	 */
	public static function maybe_run_upgrade(): void {
		$context = self::request_context();
		if ( ! $context['upgrade_allowed'] ) {
			return;
		}

		$stored_version = (string) get_option( self::OPTION_VERSION, '' );
		$translation_index_schema_current = self::TRANSLATION_INDEX_SCHEMA_VERSION === (string) get_option( self::OPTION_TRANSLATION_INDEX_SCHEMA, '' );
		$language_rule_events_schema_current = self::LANGUAGE_RULE_EVENTS_SCHEMA_VERSION === (string) get_option( self::OPTION_LANGUAGE_RULE_EVENTS_SCHEMA, '' );
		if ( self::VERSION === $stored_version && $translation_index_schema_current && $language_rule_events_schema_current ) {
			return;
		}

		if ( '' === $stored_version || version_compare( $stored_version, '0.1.63', '<' ) ) {
			self::migrate_market_language_codes();
		}

		if ( '' === $stored_version || version_compare( $stored_version, '0.1.87', '<' ) ) {
			self::validate_language_files();
			self::ensure_supported_wordpress_language_packs();
		}

		if ( '' === $stored_version || version_compare( $stored_version, '0.1.104', '<' ) ) {
			self::seed_runtime_language_text_options();
		}

		if ( ! $translation_index_schema_current ) {
			self::install_translation_index_schema();
			self::rebuild_translation_index();
		}

		if ( ! $language_rule_events_schema_current ) {
			self::install_language_rule_events_schema();
		}

		if ( '' === $stored_version || version_compare( $stored_version, '0.1.180', '<' ) ) {
			self::repair_translated_post_publication_dates();
		}

		update_option( self::OPTION_VERSION, self::VERSION, false );
	}

	/**
	 * Move legacy language records and locale defaults to the market-specific standard.
	 */
	private static function migrate_market_language_codes(): void {
		$legacy_query = self::translation_page_query(
			array(
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1000,
				'fields'         => 'ids',
			)
		);
		foreach ( $legacy_query->posts as $post_id ) {
			if ( 'no' === (string) get_post_meta( (int) $post_id, self::META_LANGUAGE, true ) ) {
				update_post_meta( (int) $post_id, self::META_LANGUAGE, 'nb' );
			}

			$path = (string) get_post_meta( (int) $post_id, self::META_LOCALIZED_PATH, true );
			if ( 'no' === $path || 'nb/no' === $path ) {
				update_post_meta( (int) $post_id, self::META_LOCALIZED_PATH, 'nb' );
			} elseif ( str_starts_with( $path, 'no/' ) ) {
				update_post_meta( (int) $post_id, self::META_LOCALIZED_PATH, 'nb/' . substr( $path, 3 ) );
			} elseif ( str_starts_with( $path, 'nb/no/' ) ) {
				update_post_meta( (int) $post_id, self::META_LOCALIZED_PATH, 'nb/' . substr( $path, 6 ) );
			}
		}

		$front_page_id = absint( get_option( 'page_on_front' ) );
		$legacy_root   = get_page_by_path( 'no', OBJECT, 'page' );
		$nb_root_id    = $front_page_id ? self::find_translation_id( $front_page_id, 'nb' ) : 0;
		$root_id       = $nb_root_id ?: ( $legacy_root instanceof WP_Post ? (int) $legacy_root->ID : 0 );

		if ( $root_id ) {
			self::with_slug_change_unlock(
				static function () use ( $root_id ): void {
					wp_update_post(
						array(
							'ID'          => $root_id,
							'post_name'   => 'nb',
							'post_parent' => 0,
						)
					);
				}
			);
			clean_post_cache( $root_id );
		}

		self::repair_url_hierarchy(
			array(
				'languages' => array( 'nb' ),
				'dry_run'   => false,
			)
		);

		$legacy_menu = wp_get_nav_menu_object( 'Main Menu NO' );
		$target_menu = wp_get_nav_menu_object( 'Main Menu NB' );
		if ( $legacy_menu && ! $target_menu ) {
			self::with_slug_change_unlock(
				static function () use ( $legacy_menu ): void {
					wp_update_term(
						(int) $legacy_menu->term_id,
						'nav_menu',
						array(
							'name' => 'Main Menu NB',
							'slug' => 'main-menu-nb',
						)
					);
				}
			);
		}

		$languages = get_option( self::OPTION_LANGUAGES );
		if ( is_array( $languages ) && isset( $languages['no'] ) ) {
			if ( ! isset( $languages['nb'] ) ) {
				$languages['nb'] = $languages['no'];
			}
			unset( $languages['no'] );
		}
		if ( is_array( $languages ) ) {
			if ( isset( $languages['en'] ) && is_array( $languages['en'] ) ) {
				$languages['en']['locale'] = 'en_GB';
			}
			if ( isset( $languages['fi'] ) && is_array( $languages['fi'] ) ) {
				$languages['fi']['locale'] = 'fi_FI';
			}
			update_option( self::OPTION_LANGUAGES, $languages, false );
		}

		flush_rewrite_rules( false );
	}

	/**
	 * Temporarily allow intentional slug changes during this plugin's own migrations.
	 *
	 * URL Change Lockdown deliberately blocks programmatic slug changes. This migration
	 * is an explicit language-prefix change, so only this callback is unlocked.
	 *
	 * @param callable $callback Migration callback.
	 */
	private static function with_slug_change_unlock( callable $callback ): void {
		$removed_post_filter = false;
		$removed_term_filter = false;

		if ( function_exists( 'url_change_lockdown_guard_post_data' ) ) {
			$removed_post_filter = remove_filter( 'wp_insert_post_data', 'url_change_lockdown_guard_post_data', PHP_INT_MAX );
		}
		if ( function_exists( 'url_change_lockdown_guard_term_data' ) ) {
			$removed_term_filter = remove_filter( 'wp_update_term_data', 'url_change_lockdown_guard_term_data', PHP_INT_MAX );
		}

		try {
			$callback();
		} finally {
			if ( $removed_post_filter ) {
				add_filter( 'wp_insert_post_data', 'url_change_lockdown_guard_post_data', PHP_INT_MAX, 2 );
			}
			if ( $removed_term_filter ) {
				add_filter( 'wp_update_term_data', 'url_change_lockdown_guard_term_data', PHP_INT_MAX, 4 );
			}
		}
	}

	/**
	 * Temporarily disable reviewer-style capture for writes this plugin performs itself.
	 *
	 * @param callable $callback Internal write callback.
	 */
	private static function with_reviewer_style_capture_suspended( callable $callback ): void {
		$previous = self::$suspend_reviewer_style_capture;
		self::$suspend_reviewer_style_capture = true;
		try {
			$callback();
		} finally {
			self::$suspend_reviewer_style_capture = $previous;
		}
	}

	/**
	 * Temporarily disable stale marking for output-preserving maintenance writes.
	 *
	 * @param callable $callback Internal write callback.
	 */
	private static function with_source_stale_marking_suspended( callable $callback ): void {
		$previous = self::$suspend_source_stale_marking;
		self::$suspend_source_stale_marking = true;
		try {
			$callback();
		} finally {
			self::$suspend_source_stale_marking = $previous;
		}
	}

	private static function with_direct_save_storage_guardrails_suspended( callable $callback ): void {
		$previous = self::$suspend_direct_save_storage_guardrails;
		self::$suspend_direct_save_storage_guardrails = true;
		try {
			$callback();
		} finally {
			self::$suspend_direct_save_storage_guardrails = $previous;
		}
	}

	/**
	 * Translation registry table name.
	 */
	private static function translation_index_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'devenia_translation_index';
	}

	/**
	 * Install or upgrade the persistent translation registry schema.
	 */
	private static function install_translation_index_schema(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::translation_index_table();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			translation_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			language varchar(20) NOT NULL DEFAULT '',
			localized_path varchar(255) NOT NULL DEFAULT '',
			source_path varchar(255) NOT NULL DEFAULT '',
			target_path varchar(255) NOT NULL DEFAULT '',
			target_url varchar(255) NOT NULL DEFAULT '',
			translation_status varchar(30) NOT NULL DEFAULT '',
			post_status varchar(20) NOT NULL DEFAULT '',
			source_hash char(64) NOT NULL DEFAULT '',
			reviewed_at varchar(40) NOT NULL DEFAULT '',
			linguistic_reviewed_at varchar(40) NOT NULL DEFAULT '',
			quality_reviewed_at varchar(40) NOT NULL DEFAULT '',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY source_language (source_post_id, language),
			UNIQUE KEY translation_post (translation_post_id),
			KEY source_post (source_post_id),
			KEY language_path (language, localized_path),
			KEY language_source_path (language, source_path),
			KEY language_target_path (language, target_path),
			KEY post_status (post_status),
			KEY translation_status (translation_status)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::OPTION_TRANSLATION_INDEX_SCHEMA, self::TRANSLATION_INDEX_SCHEMA_VERSION, false );
		self::translation_index_available( true );
	}

	/**
	 * Check whether the translation registry table is currently available.
	 */
	private static function translation_index_available( bool $refresh = false ): bool {
		static $available = null;

		if ( ! $refresh && null !== $available ) {
			return $available;
		}

		global $wpdb;
		$table     = self::translation_index_table();

		$available = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional schema availability check for custom table.
		return $available;
	}

	/**
	 * Translation quality rule-event table name.
	 */
	private static function language_rule_events_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'devenia_translation_rule_events';
	}

	/**
	 * Install or upgrade the persistent language-rule event schema.
	 */
	private static function install_language_rule_events_schema(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::language_rule_events_table();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_key varchar(80) NOT NULL DEFAULT '',
			language varchar(20) NOT NULL DEFAULT '',
			reviewer_key varchar(80) NOT NULL DEFAULT '',
			rule_type varchar(40) NOT NULL DEFAULT '',
			scope varchar(40) NOT NULL DEFAULT '',
			selector varchar(255) NOT NULL DEFAULT '',
			decision varchar(40) NOT NULL DEFAULT '',
			source_text text NULL,
			target_text text NULL,
			replacement text NULL,
			reason text NULL,
			payload longtext NULL,
			source varchar(40) NOT NULL DEFAULT '',
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			translation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			reviewer varchar(120) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			confidence decimal(5,2) NOT NULL DEFAULT 1.00,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY event_key (event_key),
			KEY language_status (language, status),
			KEY language_type (language, rule_type),
			KEY reviewer_language (reviewer_key, language),
			KEY source_translation (source_id, translation_id),
			KEY rule_scope (rule_type, scope),
			KEY updated_at (updated_at)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::OPTION_LANGUAGE_RULE_EVENTS_SCHEMA, self::LANGUAGE_RULE_EVENTS_SCHEMA_VERSION, false );
		self::language_rule_events_available( true );
	}

	/**
	 * Check whether the language-rule event table is currently available.
	 */
	private static function language_rule_events_available( bool $refresh = false ): bool {
		static $available = null;

		if ( ! $refresh && null !== $available ) {
			return $available;
		}

		global $wpdb;
		$table     = self::language_rule_events_table();
		$available = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional schema availability check for custom table.

		return $available;
	}

	/**
	 * Rebuild the registry from existing WordPress translation metadata.
	 */
	private static function rebuild_translation_index(): int {
		global $wpdb;

		if ( ! self::translation_index_available() ) {
			self::install_translation_index_schema();
		}

		$table = self::translation_index_table();
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional custom registry table rebuild.

		$query = self::translation_content_query(
			array(
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1000,
			)
		);

		$count = 0;
		foreach ( $query->posts as $post ) {
			if ( self::sync_translation_index_row( (int) $post->ID ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Report and optionally rebuild the persistent translation registry.
	 */
	private static function translation_index_status( array $input ): array {
		$rebuilt = null;
		if ( ! empty( $input['rebuild'] ) ) {
			self::install_translation_index_schema();
			$rebuilt = self::rebuild_translation_index();
		}

		$available = self::translation_index_available();
		$rows      = 0;
		$by_lang   = array();

		if ( $available ) {
			global $wpdb;
			$table = self::translation_index_table();
			$rows  = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional custom registry table status read.
			$lang_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT language, COUNT(*) AS total FROM %i GROUP BY language ORDER BY language ASC', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional custom registry table status read.
			foreach ( $lang_rows as $row ) {
				$by_lang[ (string) $row['language'] ] = absint( $row['total'] );
			}
		}

		return array(
			'success'        => $available,
			'table'          => self::translation_index_table(),
			'available'      => $available,
			'schema_version' => (string) get_option( self::OPTION_TRANSLATION_INDEX_SCHEMA, '' ),
			'expected_schema_version' => self::TRANSLATION_INDEX_SCHEMA_VERSION,
			'row_count'      => $rows,
			'by_language'    => $by_lang,
				'rebuilt_count'  => $rebuilt,
			);
	}

	/**
	 * Scan stored posts/pages with the same Gutenberg content-safety module used at save time.
	 */
	private static function gutenberg_content_safety_scan( array $input ): array {
		$post_types = isset( $input['post_types'] ) && is_array( $input['post_types'] )
			? array_values( array_filter( array_map( 'sanitize_key', $input['post_types'] ) ) )
			: array( 'page', 'post' );
		$post_types = array_values(
			array_filter(
				$post_types,
				static function ( string $post_type ): bool {
					return post_type_exists( $post_type );
				}
			)
		);
		if ( empty( $post_types ) ) {
			$post_types = array( 'page', 'post' );
		}

		$allowed_statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );
		$post_statuses   = isset( $input['post_statuses'] ) && is_array( $input['post_statuses'] )
			? array_values( array_intersect( array_map( 'sanitize_key', $input['post_statuses'] ), $allowed_statuses ) )
			: array( 'publish', 'draft', 'pending', 'private' );
		if ( empty( $post_statuses ) ) {
			$post_statuses = array( 'publish', 'draft', 'pending', 'private' );
		}

		$limit         = min( 1000, max( 1, absint( $input['limit'] ?? 200 ) ) );
		$page          = max( 1, absint( $input['page'] ?? 1 ) );
		$repair        = ! empty( $input['repair'] );
		$include_clean = ! empty( $input['include_clean'] );

		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => $post_statuses,
				'posts_per_page'         => $limit,
				'paged'                  => $page,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$items          = array();
		$issue_count    = 0;
		$warning_count  = 0;
		$repairable     = 0;
		$repaired       = 0;
		$hashes_synced  = 0;
		$repair_errors  = array();
		$checked        = 0;

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			++$checked;
			$safety = self::gutenberg_content_safety( (string) $post->post_content );
			$has_findings = ! empty( $safety['issues'] ) || ! empty( $safety['warnings'] ) || ! empty( $safety['changed'] );
			$issue_count += count( $safety['issues'] );
			$warning_count += count( $safety['warnings'] );
			if ( ! empty( $safety['repairable'] ) ) {
				++$repairable;
			}

			$repaired_this = false;
			if ( $repair && ! empty( $safety['repairable'] ) ) {
				$result = null;
				$old_source_hash = self::is_translation_post( (int) $post->ID ) ? '' : self::source_hash( $post );
				self::with_source_stale_marking_suspended(
					static function () use ( &$result, $post, $safety ): void {
						self::with_reviewer_style_capture_suspended(
							static function () use ( &$result, $post, $safety ): void {
								self::with_direct_save_storage_guardrails_suspended(
									static function () use ( &$result, $post, $safety ): void {
										$result = wp_update_post(
											wp_slash(
												array(
													'ID'                => (int) $post->ID,
													'post_content'      => (string) $safety['normalized_content'],
													'post_modified'     => (string) $post->post_modified,
													'post_modified_gmt' => (string) $post->post_modified_gmt,
													'edit_date'         => true,
												)
											),
											true
										);
									}
								);
							}
						);
					}
				);
				if ( is_wp_error( $result ) ) {
					$repair_errors[] = array(
						'post_id' => (int) $post->ID,
						'error'   => $result->get_error_message(),
					);
				} else {
					++$repaired;
					$repaired_this = true;
					if ( '' !== $old_source_hash ) {
						$repaired_post = get_post( (int) $post->ID );
						if ( $repaired_post instanceof WP_Post ) {
							$new_source_hash = self::source_hash( $repaired_post );
							$hashes_synced += self::sync_translation_source_hashes_after_content_safety_repair( $repaired_post, array( $old_source_hash ) );
							if ( $new_source_hash !== $old_source_hash ) {
								clean_post_cache( (int) $post->ID );
							}
						}
					}
				}
			}

			if ( $repair && empty( $safety['repairable'] ) && ! self::is_translation_post( (int) $post->ID ) ) {
				$hashes_synced += self::sync_translation_source_hashes_after_recent_content_safety_repair( $post );
			}

			if ( $has_findings || $include_clean ) {
				$items[] = array(
					'post_id'       => (int) $post->ID,
					'post_type'     => (string) $post->post_type,
					'post_status'   => (string) $post->post_status,
					'title'         => get_the_title( $post ),
					'url'           => get_permalink( $post ) ?: '',
					'passed'        => ! empty( $safety['passed'] ),
					'changed'       => ! empty( $safety['changed'] ),
					'repairable'    => ! empty( $safety['repairable'] ),
					'repaired'      => $repaired_this,
					'issue_count'   => count( $safety['issues'] ),
					'warning_count' => count( $safety['warnings'] ),
					'issues'        => $safety['issues'],
					'warnings'      => $safety['warnings'],
					'summary'       => $safety['summary'],
				);
			}
		}

		return array(
			'success'        => 0 === $issue_count && empty( $repair_errors ),
			'checked'        => $checked,
			'total'          => (int) $query->found_posts,
			'total_pages'    => (int) $query->max_num_pages,
			'page'           => $page,
			'limit'          => $limit,
			'post_types'     => $post_types,
			'post_statuses'  => $post_statuses,
			'issue_count'    => $issue_count,
			'warning_count'  => $warning_count,
			'repairable'     => $repairable,
			'repaired'       => $repaired,
			'source_hashes_synced' => $hashes_synced,
			'repair_errors'  => $repair_errors,
			'items'          => $items,
		);
	}

	/**
	 * Return recent slow translated frontend requests recorded on shutdown.
	 */
	private static function frontend_performance_status( array $input ): array {
		$log = get_option( self::OPTION_FRONTEND_SLOW_LOG, array() );
		$log = is_array( $log ) ? array_values( $log ) : array();
		if ( ! empty( $input['clear'] ) ) {
			update_option( self::OPTION_FRONTEND_SLOW_LOG, array(), false );
		}

		return array(
			'success' => true,
			'count'   => count( $log ),
			'entries' => $log,
			'cleared' => ! empty( $input['clear'] ),
		);
	}

	/**
	 * Fetch public translated URLs without query strings to warm anonymous HTML caches.
	 */
	private static function warm_translation_cache( array $input ): array {
		$requested_languages = isset( $input['languages'] ) && is_array( $input['languages'] )
			? array_values( array_filter( array_map( 'sanitize_key', $input['languages'] ) ) )
			: array();
		$languages = array();
		foreach ( self::languages() as $language => $config ) {
			$language = sanitize_key( (string) $language );
			if ( 'en' === $language ) {
				continue;
			}
			if ( $requested_languages && ! in_array( $language, $requested_languages, true ) ) {
				continue;
			}
			$languages[] = $language;
		}

		$urls = array();
		if ( isset( $input['urls'] ) && is_array( $input['urls'] ) ) {
			foreach ( $input['urls'] as $url ) {
				$url = esc_url_raw( (string) $url );
				if ( $url ) {
					$urls[] = $url;
				}
			}
		}

		$include_home = array_key_exists( 'include_home', $input ) ? (bool) $input['include_home'] : true;
		if ( $include_home ) {
			foreach ( $languages as $language ) {
				$home = self::localized_home_url_for_language( $language );
				if ( $home ) {
					$urls[] = $home;
				}
			}
		}

		foreach ( $languages as $language ) {
			foreach ( self::translation_frontend_rows_for_language( $language, array( 'publish' ) ) as $row ) {
				$url = esc_url_raw( (string) ( $row['target_url'] ?? $row['url'] ?? '' ) );
				if ( $url ) {
					$urls[] = $url;
				}
			}
		}

		$unique = array();
		foreach ( $urls as $url ) {
			$parts = wp_parse_url( $url );
			if ( empty( $parts['host'] ) ) {
				continue;
			}
			$path = isset( $parts['path'] ) ? '/' . trim( (string) $parts['path'], '/' ) : '/';
			if ( '/' !== $path ) {
				$path .= '/';
			}
			$normalized = home_url( $path );
			$unique[ $normalized ] = $normalized;
		}

		$limit   = max( 1, min( 500, absint( $input['limit'] ?? 100 ) ) );
		$timeout = max( 3, min( 30, absint( $input['timeout'] ?? 15 ) ) );
		$urls    = array_slice( array_values( $unique ), 0, $limit );
		$results = array();

		foreach ( $urls as $url ) {
			$started = microtime( true );
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => $timeout,
					'redirection' => 3,
					'headers'     => array(
						'Accept'     => 'text/html,application/xhtml+xml',
						'User-Agent' => 'AITranslationCacheWarm/' . self::VERSION,
					),
				)
			);
			$duration_ms = round( ( microtime( true ) - $started ) * 1000, 2 );
			if ( is_wp_error( $response ) ) {
				$results[] = array(
					'url'         => $url,
					'success'     => false,
					'status_code' => 0,
					'duration_ms' => $duration_ms,
					'error'       => $response->get_error_message(),
				);
				continue;
			}

			$results[] = array(
				'url'             => $url,
				'success'         => true,
				'status_code'     => (int) wp_remote_retrieve_response_code( $response ),
				'duration_ms'     => $duration_ms,
				'cf_cache_status' => (string) wp_remote_retrieve_header( $response, 'cf-cache-status' ),
				'cf_ray'          => (string) wp_remote_retrieve_header( $response, 'cf-ray' ),
				'bytes'           => strlen( (string) wp_remote_retrieve_body( $response ) ),
			);
		}

		return array(
			'success'   => true,
			'requested' => count( $urls ),
			'results'   => $results,
		);
	}

	/**
	 * Keep one translation registry row aligned with WordPress translation metadata.
	 */
	private static function sync_translation_index_row( int $translation_id ): bool {
		if ( ! self::translation_index_available() ) {
			return false;
		}

		$post = get_post( $translation_id );
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			self::delete_translation_index_row( $translation_id );
			return false;
		}

		$source_id = absint( get_post_meta( $translation_id, self::META_SOURCE_ID, true ) );
		$language  = sanitize_key( (string) get_post_meta( $translation_id, self::META_LANGUAGE, true ) );
		if ( ! $source_id || '' === $language ) {
			self::delete_translation_index_row( $translation_id );
			return false;
		}

		global $wpdb;
		$table = self::translation_index_table();
		$source_url = $source_id ? (string) get_permalink( $source_id ) : '';
		$target_url = (string) get_permalink( $translation_id );
		$target_url = $target_url ?: '';
		$source_path = $source_url ? self::normalized_url_path( $source_url ) : '';
		$target_path = $target_url ? self::normalized_url_path( $target_url ) : '';
		$localized_path = self::localized_path_for_post( $translation_id, $language );

		$result = $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional custom registry table write.
			$table,
			array(
				'source_post_id'         => $source_id,
				'translation_post_id'    => $translation_id,
				'language'               => $language,
				'localized_path'         => $localized_path,
				'source_path'            => $source_path,
				'target_path'            => $target_path,
				'target_url'             => $target_url,
				'translation_status'     => self::sanitize_translation_status( (string) get_post_meta( $translation_id, self::META_STATUS, true ) ),
				'post_status'            => (string) $post->post_status,
				'source_hash'            => (string) get_post_meta( $translation_id, self::META_SOURCE_HASH, true ),
				'reviewed_at'            => (string) get_post_meta( $translation_id, self::META_REVIEWED_AT, true ),
				'linguistic_reviewed_at' => (string) get_post_meta( $translation_id, self::META_LINGUISTIC_REVIEWED_AT, true ),
				'quality_reviewed_at'    => (string) get_post_meta( $translation_id, self::META_QUALITY_REVIEWED_AT, true ),
				'updated_at'             => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Delete one registry row.
	 */
	private static function delete_translation_index_row( int $translation_id ): void {
		if ( ! self::translation_index_available() ) {
			return;
		}

		global $wpdb;
		$wpdb->delete( self::translation_index_table(), array( 'translation_post_id' => $translation_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional custom registry table cleanup.
	}

	/**
	 * Keep the registry clean when translated content is deleted.
	 */
	public static function delete_translation_index_for_post( int $post_id, WP_Post $post ): void {
		if ( ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			return;
		}

		self::delete_translation_index_row( $post_id );
	}

	/**
	 * Sanitize registry post status filters.
	 *
	 * @param array<int,string> $post_status Post statuses.
	 * @return array<int,string>
	 */
	private static function translation_index_statuses( array $post_status ): array {
		$statuses = array_values( array_filter( array_map( 'sanitize_key', $post_status ) ) );
		if ( empty( $statuses ) ) {
			$statuses = array( 'publish', 'draft', 'pending', 'private' );
		}

		return $statuses;
	}

	/**
	 * Filter indexed translation IDs by current WordPress post status.
	 *
	 * @param array<int,int>    $ids         Translation post IDs.
	 * @param array<int,string> $post_status Post statuses.
	 * @return array<int,int>
	 */
	private static function filter_translation_index_ids_by_status( array $ids, array $post_status ): array {
		$statuses = self::translation_index_statuses( $post_status );

		return array_values(
			array_filter(
				array_map( 'absint', $ids ),
				static function ( int $translation_id ) use ( $statuses ): bool {
					$status = get_post_status( $translation_id );
					return is_string( $status ) && in_array( $status, $statuses, true );
				}
			)
		);
	}

	/**
	 * Look up one translation ID through the registry table.
	 */
	private static function translation_index_id_for_source_language( int $source_id, string $language, array $post_status ): int {
		if ( ! self::translation_index_available() ) {
			return 0;
		}

		global $wpdb;
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional indexed custom table read.
			$wpdb->prepare(
				'SELECT translation_post_id FROM %i WHERE source_post_id = %d AND language = %s ORDER BY translation_post_id DESC',
				self::translation_index_table(),
				$source_id,
				sanitize_key( $language )
			)
		);
		$ids = self::filter_translation_index_ids_by_status( $ids, $post_status );

		return isset( $ids[0] ) ? (int) $ids[0] : 0;
	}

	/**
	 * Look up a translated post/page by its frontend target path.
	 */
	private static function find_translation_id_by_target_path( string $target_path, array $post_status ): int {
		if ( ! self::translation_index_available() ) {
			return 0;
		}

		$target_path = '/' . trim( $target_path, '/' ) . '/';
		if ( '//' === $target_path ) {
			return 0;
		}

		global $wpdb;
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional indexed custom table read.
			$wpdb->prepare(
				'SELECT translation_post_id FROM %i WHERE target_path = %s ORDER BY translation_post_id DESC',
				self::translation_index_table(),
				$target_path
			)
		);
		$ids = self::filter_translation_index_ids_by_status( $ids, $post_status );

		return isset( $ids[0] ) ? (int) $ids[0] : 0;
	}

	/**
	 * Look up translation IDs for a source page through the registry table.
	 *
	 * @return array<int,int>
	 */
	private static function translation_index_ids_for_source( int $source_id, array $post_status ): array {
		if ( ! self::translation_index_available() ) {
			return array();
		}

		global $wpdb;
		$ids           = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional indexed custom table read.
			$wpdb->prepare(
				'SELECT translation_post_id FROM %i WHERE source_post_id = %d ORDER BY language ASC',
				self::translation_index_table(),
				$source_id
			)
		);

		return self::filter_translation_index_ids_by_status( $ids, $post_status );
	}

	/**
	 * Look up translation IDs for a language through the registry table.
	 *
	 * @return array<int,int>
	 */
	private static function translation_index_ids_for_language( string $language, array $post_status ): array {
		if ( ! self::translation_index_available() ) {
			return array();
		}

		global $wpdb;
		$ids           = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional indexed custom table read.
			$wpdb->prepare(
				'SELECT translation_post_id FROM %i WHERE language = %s ORDER BY source_post_id ASC',
				self::translation_index_table(),
				sanitize_key( $language )
			)
		);

		return self::filter_translation_index_ids_by_status( $ids, $post_status );
	}

	/**
	 * Look up all indexed translation IDs.
	 *
	 * @return array<int,int>
	 */
	private static function translation_index_ids( array $post_status ): array {
		if ( ! self::translation_index_available() ) {
			return array();
		}

		global $wpdb;
		$ids           = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional indexed custom table read.
			$wpdb->prepare(
				'SELECT translation_post_id FROM %i ORDER BY source_post_id ASC, language ASC',
				self::translation_index_table()
			)
		);

		return self::filter_translation_index_ids_by_status( $ids, $post_status );
	}

	/**
	 * Read normalized registry rows by source without inflating full translation payloads.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function translation_index_rows_for_source( int $source_id, array $post_status ): array {
		if ( ! self::translation_index_available() ) {
			return array();
		}

		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional indexed custom table read.
			$wpdb->prepare(
				'SELECT source_post_id, translation_post_id, language, localized_path, source_path, target_path, target_url, translation_status, post_status, source_hash, reviewed_at, linguistic_reviewed_at, quality_reviewed_at FROM %i WHERE source_post_id = %d ORDER BY language ASC',
				self::translation_index_table(),
				$source_id
			),
			ARRAY_A
		);

		return self::normalize_translation_index_rows( is_array( $rows ) ? $rows : array(), $post_status );
	}

	/**
	 * Read normalized registry rows by language.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function translation_index_rows_for_language( string $language, array $post_status ): array {
		if ( ! self::translation_index_available() ) {
			return array();
		}

		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional indexed custom table read.
			$wpdb->prepare(
				'SELECT source_post_id, translation_post_id, language, localized_path, source_path, target_path, target_url, translation_status, post_status, source_hash, reviewed_at, linguistic_reviewed_at, quality_reviewed_at FROM %i WHERE language = %s ORDER BY source_post_id ASC',
				self::translation_index_table(),
				sanitize_key( $language )
			),
			ARRAY_A
		);

		return self::normalize_translation_index_rows( is_array( $rows ) ? $rows : array(), $post_status );
	}

	/**
	 * Read one normalized registry row by translation ID.
	 *
	 * @return array<string,mixed>
	 */
	private static function translation_index_row_for_translation( int $translation_id, array $post_status = array( 'publish', 'draft', 'pending', 'private' ) ): array {
		static $cache = array();

		$status_key = implode( '|', array_map( 'sanitize_key', $post_status ) );
		$cache_key  = $translation_id . ':' . $status_key;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		if ( ! self::translation_index_available() ) {
			$cache[ $cache_key ] = array();
			return $cache[ $cache_key ];
		}

		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional indexed custom table read.
			$wpdb->prepare(
				'SELECT source_post_id, translation_post_id, language, localized_path, source_path, target_path, target_url, translation_status, post_status, source_hash, reviewed_at, linguistic_reviewed_at, quality_reviewed_at FROM %i WHERE translation_post_id = %d',
				self::translation_index_table(),
				$translation_id
			),
			ARRAY_A
		);

		$rows = self::normalize_translation_index_rows( is_array( $row ) ? array( $row ) : array(), $post_status );
		$cache[ $cache_key ] = $rows[0] ?? array();

		return $cache[ $cache_key ];
	}

	/**
	 * Normalize and post-status-filter registry rows.
	 *
	 * @param array<int,array<string,mixed>> $rows Registry rows.
	 * @param array<int,string>             $post_status Accepted post statuses.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_translation_index_rows( array $rows, array $post_status ): array {
		$statuses = self::translation_index_statuses( $post_status );
		$normalized = array();

		foreach ( $rows as $row ) {
			$status = isset( $row['post_status'] ) ? sanitize_key( (string) $row['post_status'] ) : '';
			if ( '' !== $status && ! in_array( $status, $statuses, true ) ) {
				continue;
			}

			$translation_id = absint( $row['translation_post_id'] ?? 0 );
			$source_id      = absint( $row['source_post_id'] ?? 0 );
			$language       = sanitize_key( (string) ( $row['language'] ?? '' ) );
			if ( ! $translation_id || ! $source_id || '' === $language ) {
				continue;
			}

			$normalized[] = array(
				'id'                     => $translation_id,
				'translation_post_id'    => $translation_id,
				'source_id'              => $source_id,
				'source_post_id'         => $source_id,
					'language'               => $language,
					'localized_path'         => trim( (string) ( $row['localized_path'] ?? '' ), '/' ),
					'source_path'            => trim( (string) ( $row['source_path'] ?? '' ), '/' ),
					'target_path'            => trim( (string) ( $row['target_path'] ?? '' ), '/' ),
					'target_url'             => esc_url_raw( (string) ( $row['target_url'] ?? '' ) ),
					'translation_status'     => self::sanitize_translation_status( (string) ( $row['translation_status'] ?? '' ) ),
				'status'                 => $status,
				'post_status'            => $status,
				'source_hash'            => (string) ( $row['source_hash'] ?? '' ),
				'reviewed_at'            => (string) ( $row['reviewed_at'] ?? '' ),
				'linguistic_reviewed_at' => (string) ( $row['linguistic_reviewed_at'] ?? '' ),
				'quality_reviewed_at'    => (string) ( $row['quality_reviewed_at'] ?? '' ),
			);
		}

		return $normalized;
	}

	/**
	 * Frontend row read model backed by the registry table.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function translation_frontend_rows_for_source( int $source_id, array $post_status = array( 'publish' ) ): array {
		static $cache = array();

		$status_key = implode( '|', array_map( 'sanitize_key', $post_status ) );
		$cache_key  = $source_id . ':' . $status_key;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$rows = self::translation_index_rows_for_source( $source_id, $post_status );
		$cache[ $cache_key ] = self::frontend_rows_from_index_rows( $rows );

		return $cache[ $cache_key ];
	}

	/**
	 * Frontend row read model backed by the registry table and scoped by language.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function translation_frontend_rows_for_language( string $language, array $post_status = array( 'publish' ) ): array {
		static $cache = array();

		$language   = sanitize_key( $language );
		$status_key = implode( '|', array_map( 'sanitize_key', $post_status ) );
		$cache_key  = $language . ':' . $status_key;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$rows = self::translation_index_rows_for_language( $language, $post_status );
		$cache[ $cache_key ] = self::frontend_rows_from_index_rows( $rows );

		return $cache[ $cache_key ];
	}

	/**
	 * Add URL/path fields to normalized registry rows.
	 *
	 * @param array<int,array<string,mixed>> $rows Registry rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function frontend_rows_from_index_rows( array $rows ): array {
		$frontend_rows = array();
		foreach ( $rows as $row ) {
			$source_id      = absint( $row['source_id'] ?? 0 );
			$translation_id = absint( $row['id'] ?? 0 );
			$source_path    = trim( (string) ( $row['source_path'] ?? '' ), '/' );
			$target_path    = trim( (string) ( $row['target_path'] ?? '' ), '/' );
			$target_url     = esc_url_raw( (string) ( $row['target_url'] ?? '' ) );
			if ( '' === $source_path && $source_id ) {
				$source_url  = (string) get_permalink( $source_id );
				$source_path = $source_url ? self::normalized_url_path( $source_url ) : '';
			}
			if ( '' === $target_url && $translation_id ) {
				$target_url = (string) get_permalink( $translation_id );
			}
			if ( '' === $target_path && $target_url ) {
				$target_path = self::normalized_url_path( $target_url );
			}
			if ( '' === $source_path || '' === $target_url || '' === $target_path ) {
				continue;
			}

			$stored_localized_path = trim( (string) ( $row['localized_path'] ?? '' ), '/' );
			$meta_localized_path   = $translation_id ? trim( (string) get_post_meta( $translation_id, self::META_LOCALIZED_PATH, true ), '/' ) : '';
			$canonical_path        = trim( (string) $target_path, '/' );
			$localized_variants    = array_values(
				array_unique(
					array_filter(
						array( $stored_localized_path, $meta_localized_path, $canonical_path ),
						static function ( string $path ): bool {
							return '' !== $path;
						}
					)
				)
			);

			$row['source_url'] = home_url( '/' . trim( $source_path, '/' ) . '/' );
			$row['url']        = (string) $target_url;
			$row['target_url'] = (string) $target_url;
			$row['source_path'] = $source_path;
			$row['target_path'] = $target_path;
			$row['localized_path'] = $canonical_path ?: $stored_localized_path;
			$row['localized_path_variants'] = $localized_variants;

			$frontend_rows[] = $row;
		}

		return $frontend_rows;
	}

	/**
	 * Default language registry.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function default_languages(): array {
		return array(
			'en' => array(
				'name'      => 'English',
				'native_name' => 'English',
				'locale'    => 'en_GB',
				'wordpress_locale' => 'en_GB',
				'prefix'    => '',
				'menu_name' => 'Main Menu',
				'source'    => '1',
				'flag'      => '🇬🇧',
			),
			'nb' => array(
				'name'      => 'Norwegian Bokmal',
				'native_name' => 'Norsk bokmal',
				'locale'    => 'nb_NO',
				'wordpress_locale' => 'nb_NO',
				'prefix'    => 'nb',
				'menu_name' => 'Main Menu NB',
				'source'    => '0',
				'flag'      => '🇳🇴',
			),
			'de' => array(
				'name'      => 'German',
				'native_name' => 'Deutsch',
				'locale'    => 'de_DE',
				'wordpress_locale' => 'de_DE',
				'prefix'    => 'de',
				'menu_name' => 'Main Menu DE',
				'source'    => '0',
				'flag'      => '🇩🇪',
			),
			'fr' => array(
				'name'      => 'French',
				'native_name' => 'Francais',
				'locale'    => 'fr_FR',
				'wordpress_locale' => 'fr_FR',
				'prefix'    => 'fr',
				'menu_name' => 'Main Menu FR',
				'source'    => '0',
				'flag'      => '🇫🇷',
			),
			'es' => array(
				'name'      => 'Spanish',
				'native_name' => 'Espanol',
				'locale'    => 'es_ES',
				'wordpress_locale' => 'es_ES',
				'prefix'    => 'es',
				'menu_name' => 'Main Menu ES',
				'source'    => '0',
				'flag'      => '🇪🇸',
			),
			'sv' => array(
				'name'      => 'Swedish',
				'native_name' => 'Svenska',
				'locale'    => 'sv_SE',
				'wordpress_locale' => 'sv_SE',
				'prefix'    => 'sv',
				'menu_name' => 'Main Menu SV',
				'source'    => '0',
				'flag'      => '🇸🇪',
			),
			'da' => array(
				'name'      => 'Danish',
				'native_name' => 'Dansk',
				'locale'    => 'da_DK',
				'wordpress_locale' => 'da_DK',
				'prefix'    => 'da',
				'menu_name' => 'Main Menu DA',
				'source'    => '0',
				'flag'      => '🇩🇰',
			),
			'fi' => array(
				'name'      => 'Finnish',
				'native_name' => 'Suomi',
				'locale'    => 'fi_FI',
				'wordpress_locale' => 'fi',
				'prefix'    => 'fi',
				'menu_name' => 'Main Menu FI',
				'source'    => '0',
				'flag'      => '🇫🇮',
			),
			'it' => array(
				'name'      => 'Italian',
				'native_name' => 'Italiano',
				'locale'    => 'it_IT',
				'wordpress_locale' => 'it_IT',
				'prefix'    => 'it',
				'menu_name' => 'Main Menu IT',
				'source'    => '0',
				'flag'      => '🇮🇹',
			),
			'nl' => array(
				'name'      => 'Dutch',
				'native_name' => 'Nederlands',
				'locale'    => 'nl_NL',
				'wordpress_locale' => 'nl_NL',
				'prefix'    => 'nl',
				'menu_name' => 'Main Menu NL',
				'source'    => '0',
				'flag'      => '🇳🇱',
			),
			'pt' => array(
				'name'      => 'Portuguese',
				'native_name' => 'Português',
				'locale'    => 'pt_PT',
				'wordpress_locale' => 'pt_PT',
				'prefix'    => 'pt',
				'menu_name' => 'Main Menu PT',
				'menu_region' => 'Europe',
				'source'    => '0',
				'flag'      => '🇵🇹',
			),
			'zh' => array(
				'name'      => 'Chinese (Simplified)',
				'native_name' => '简体中文',
				'locale'    => 'zh_CN',
				'wordpress_locale' => 'zh_CN',
				'script'    => 'zh-Hans',
				'prefix'    => 'zh',
				'menu_name' => 'Main Menu ZH',
				'menu_region' => 'Asia',
				'source'    => '0',
				'flag'      => '🇨🇳',
				'transliterate_urls' => '1',
			),
			'ja' => array(
				'name'      => 'Japanese',
				'native_name' => '日本語',
				'locale'    => 'ja_JP',
				'wordpress_locale' => 'ja',
				'prefix'    => 'ja',
				'menu_name' => 'Main Menu JA',
				'menu_region' => 'Asia',
				'source'    => '0',
				'flag'      => '🇯🇵',
				'transliterate_urls' => '1',
			),
			'vi' => array(
				'name'      => 'Vietnamese',
				'native_name' => 'Tiếng Việt',
				'locale'    => 'vi_VN',
				'wordpress_locale' => 'vi',
				'prefix'    => 'vi',
				'menu_name' => 'Main Menu VI',
				'menu_region' => 'Asia',
				'source'    => '0',
				'flag'      => '🇻🇳',
			),
			'ar' => array(
				'name'      => 'Arabic (Egypt)',
				'native_name' => 'العربية (مصر)',
				'locale'    => 'ar_EG',
				'wordpress_locale' => 'ar',
				'prefix'    => 'ar',
				'menu_name' => 'Main Menu AR',
				'source'    => '0',
				'flag'      => '🇪🇬',
				'direction' => 'rtl',
				'transliterate_urls' => '1',
			),
		);
	}

	/**
	 * Check whether a source page keeps legacy prefixed source-path access blocked
	 * for a given frontend language.
	 *
	 * @param int    $source_id Source page ID.
	 * @param string $language  Language code.
	 * @param int    $translation_id Optional translation ID for this language.
	 * @return bool
	 */
	private static function should_block_legacy_source_path_redirect( int $source_id, string $language, int $translation_id = 0 ): bool {
		if ( $source_id <= 0 ) {
			return false;
		}

		$language = sanitize_key( (string) $language );
		if ( '' === $language ) {
			return false;
		}

		$raw = get_post_meta( $source_id, self::META_LEGACY_SOURCE_REDIRECT_LANGUAGES, true );
		if ( '' === $raw || null === $raw ) {
			$raw = array();
		}

		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			} else {
				$raw = array_filter(
					array_map(
						'sanitize_key',
						preg_split( '/[,\s]+/', $raw )
					)
				);
			}
		}

		if ( ! is_array( $raw ) ) {
			return false;
		}

		if ( isset( $raw[ $language ] ) ) {
			return (bool) $raw[ $language ];
		}

		foreach ( $raw as $value ) {
			if ( is_string( $value ) && sanitize_key( $value ) === $language ) {
				return true;
			}
		}

		if ( empty( $raw ) ) {
			$translation_id = 0 < $translation_id ? $translation_id : self::find_translation_id( $source_id, $language, array( 'publish' ) );
			if ( ! $translation_id ) {
				return false;
			}

			if ( ! self::language_requires_transliterated_urls( $language ) ) {
				return false;
			}

			$source_permalink = (string) get_permalink( $source_id );
			$translation_permalink = (string) get_permalink( $translation_id );
			$source_path = self::normalized_url_path( $source_permalink );
			$translation_path = self::normalized_url_path( $translation_permalink );
			if ( '' === $source_path || '' === $translation_path ) {
				return false;
			}

			return $source_path !== $translation_path;
		}

		return false;
	}

	/**
	 * Get configured languages.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function languages( bool $refresh = false ): array {
		static $cache = null;

		if ( ! $refresh && null !== $cache ) {
			return $cache;
		}

		$defaults = self::default_languages();
		self::maybe_seed_runtime_language_text_options();

		$file_languages = self::strip_runtime_text_sections(
			self::local_language_registry( array_keys( $defaults ) )
		);
		$languages = get_option( self::OPTION_LANGUAGES );

		if ( ! is_array( $languages ) ) {
			$cache = array_replace_recursive( $defaults, $file_languages );
			return $cache;
		}

		if ( isset( $languages['no'] ) ) {
			if ( ! isset( $languages['nb'] ) ) {
				$languages['nb'] = $languages['no'];
			}
			unset( $languages['no'] );
		}

		$cache = array_replace_recursive( $defaults, $file_languages, $languages );
		return $cache;
	}

	/**
	 * Precompiled runtime text replacements for one frontend language.
	 *
	 * @return array{search:array<int,string>,replace:array<int,string>,has_replacements:bool}
	 */
	private static function runtime_text_replacements_for_language( string $language ): array {
		static $cache = array();

		$language = sanitize_key( $language );
		if ( isset( $cache[ $language ] ) ) {
			return $cache[ $language ];
		}

		$languages = self::languages();
		$config    = isset( $languages[ $language ] ) && is_array( $languages[ $language ] ) ? $languages[ $language ] : array();
		$profile   = self::language_review_profile( $language );
		$replacements = array();

		foreach ( array( 'widget_text', 'not_found_text' ) as $section ) {
			if ( isset( $config[ $section ] ) && is_array( $config[ $section ] ) ) {
				foreach ( $config[ $section ] as $source => $translated ) {
					if ( is_string( $source ) && is_string( $translated ) && '' !== $source ) {
						$replacements[ $source ] = $translated;
					}
				}
			}
		}

		if ( isset( $profile['localized_terms'] ) && is_array( $profile['localized_terms'] ) ) {
			foreach ( $profile['localized_terms'] as $source => $translated_terms ) {
				$source = trim( (string) $source );
				if ( '' === $source || ! is_array( $translated_terms ) ) {
					continue;
				}
				$translated = $translated_terms[0] ?? '';
				if ( is_string( $translated ) && '' !== trim( $translated ) ) {
					$replacements[ $source ] = trim( (string) $translated );
				}
			}
		}

		if ( isset( $profile['frontend_replacements'] ) && is_array( $profile['frontend_replacements'] ) ) {
			foreach ( $profile['frontend_replacements'] as $source => $translated ) {
				if ( is_string( $source ) && is_string( $translated ) && '' !== trim( $source ) ) {
					$replacements[ trim( $source ) ] = trim( $translated );
				}
			}
		}

		uksort(
			$replacements,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		$cache[ $language ] = array(
			'search'           => array_keys( $replacements ),
			'replace'          => array_values( $replacements ),
			'has_replacements' => ! empty( $replacements ),
		);

		return $cache[ $language ];
	}

	/**
	 * Sections that operators may correct in WordPress data without a plugin release.
	 *
	 * @return array<int,string>
	 */
	private static function runtime_text_sections(): array {
		return array( 'menu_items', 'custom_menu_items', 'widget_text', 'not_found_text', 'comment_form_text', 'not_found_routes' );
	}

	/**
	 * Sections editable through the narrow MCP runtime text ability.
	 *
	 * @return array<int,string>
	 */
	private static function editable_runtime_text_sections(): array {
		return array( 'menu_items', 'custom_menu_items', 'widget_text', 'not_found_text', 'comment_form_text' );
	}

	/**
	 * Remove mutable runtime text from packaged language-file data.
	 *
	 * Packaged JSON files are install/seed material. Runtime copy must come from
	 * WordPress data so typo fixes do not require a plugin release.
	 *
	 * @param array<string,array<string,mixed>> $languages Language data.
	 * @return array<string,array<string,mixed>>
	 */
	private static function strip_runtime_text_sections( array $languages ): array {
		foreach ( $languages as $language => $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}
			foreach ( self::runtime_text_sections() as $section ) {
				unset( $languages[ $language ][ $section ] );
			}
		}

		return $languages;
	}

	/**
	 * Seed packaged mutable text into WordPress options once.
	 */
	private static function maybe_seed_runtime_language_text_options(): void {
		if ( '1' === (string) get_option( self::OPTION_LANGUAGE_TEXT_SEEDED, '' ) && ! self::runtime_language_text_seed_needed() ) {
			return;
		}

		self::seed_runtime_language_text_options();
	}

	/**
	 * Existing installs may already have seeded runtime text before a new
	 * language was packaged. Detect missing language/section defaults without
	 * overwriting operator-maintained runtime copy.
	 */
	private static function runtime_language_text_seed_needed(): bool {
		$defaults      = self::default_languages();
		$file_registry = self::local_language_registry( array_keys( $defaults ) );
		$languages     = get_option( self::OPTION_LANGUAGES );

		if ( ! is_array( $languages ) ) {
			return ! empty( $file_registry );
		}

		if ( isset( $languages['no'] ) && ! isset( $languages['nb'] ) ) {
			return true;
		}

		foreach ( $file_registry as $language => $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}
			if ( ! isset( $languages[ $language ] ) || ! is_array( $languages[ $language ] ) ) {
				return true;
			}

			foreach ( self::runtime_text_sections() as $section ) {
				if ( empty( $config[ $section ] ) || ! is_array( $config[ $section ] ) ) {
					continue;
				}
				if ( ! isset( $languages[ $language ][ $section ] ) || ! is_array( $languages[ $language ][ $section ] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Copy packaged mutable text into the runtime WordPress option without
	 * overwriting existing operator edits.
	 */
	private static function seed_runtime_language_text_options(): void {
		$defaults      = self::default_languages();
		$file_registry = self::local_language_registry( array_keys( $defaults ) );
		$languages     = get_option( self::OPTION_LANGUAGES );

		if ( ! is_array( $languages ) ) {
			$languages = array();
		}

		if ( isset( $languages['no'] ) ) {
			if ( ! isset( $languages['nb'] ) ) {
				$languages['nb'] = $languages['no'];
			}
			unset( $languages['no'] );
		}

		$changed = false;
		foreach ( $file_registry as $language => $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}
			if ( ! isset( $languages[ $language ] ) || ! is_array( $languages[ $language ] ) ) {
				$languages[ $language ] = array();
				$changed = true;
			}

			foreach ( self::runtime_text_sections() as $section ) {
				if ( empty( $config[ $section ] ) || ! is_array( $config[ $section ] ) ) {
					continue;
				}

				$current = isset( $languages[ $language ][ $section ] ) && is_array( $languages[ $language ][ $section ] )
					? $languages[ $language ][ $section ]
					: array();

				$merged = array_replace_recursive( $config[ $section ], $current );
				if ( $merged !== $current ) {
					$languages[ $language ][ $section ] = $merged;
					$changed = true;
				}
			}
		}

		if ( $changed ) {
			update_option( self::OPTION_LANGUAGES, $languages, false );
		}
		if ( '1' !== (string) get_option( self::OPTION_LANGUAGE_TEXT_SEEDED, '' ) ) {
			update_option( self::OPTION_LANGUAGE_TEXT_SEEDED, '1', false );
		}
	}

	/**
	 * Update one small runtime text mapping in WordPress options.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function update_runtime_language_text( array $input ): array {
		self::maybe_seed_runtime_language_text_options();

		$language = sanitize_key( (string) ( $input['language'] ?? '' ) );
		$section  = sanitize_key( (string) ( $input['section'] ?? '' ) );
		$source   = isset( $input['source'] ) ? trim( wp_kses_post( (string) $input['source'] ) ) : '';
		$delete   = ! empty( $input['delete'] );

		if ( '' === $language || ! isset( self::default_languages()[ $language ] ) ) {
			return self::error( 'Unknown language.' );
		}

		if ( ! in_array( $section, self::editable_runtime_text_sections(), true ) ) {
			return self::error( 'Unsupported runtime text section.' );
		}

		if ( '' === $source ) {
			return self::error( 'Missing source key.' );
		}

		if ( ! $delete && ! array_key_exists( 'translated', $input ) ) {
			return self::error( 'Missing translated value.' );
		}

		$languages = get_option( self::OPTION_LANGUAGES );
		if ( ! is_array( $languages ) ) {
			$languages = array();
		}

		if ( ! isset( $languages[ $language ] ) || ! is_array( $languages[ $language ] ) ) {
			$languages[ $language ] = array();
		}
		if ( ! isset( $languages[ $language ][ $section ] ) || ! is_array( $languages[ $language ][ $section ] ) ) {
			$languages[ $language ][ $section ] = array();
		}

		if ( $delete ) {
			unset( $languages[ $language ][ $section ][ $source ] );
		} else {
			$languages[ $language ][ $section ][ $source ] = wp_kses_post( (string) $input['translated'] );
		}

		update_option( self::OPTION_LANGUAGES, $languages, false );
		self::languages( true );

		return array(
			'success'    => true,
			'language'   => $language,
			'section'    => $section,
			'source'     => $source,
			'deleted'    => $delete,
			'translated' => $delete ? null : $languages[ $language ][ $section ][ $source ],
			'message'    => $delete ? 'Runtime text override removed.' : 'Runtime text override updated.',
		);
	}

	/**
	 * Return runtime/effective quality profiles used by translation QA.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function get_runtime_quality_profile( array $input ): array {
		$language = isset( $input['language'] ) ? sanitize_key( (string) $input['language'] ) : '';
		if ( '' !== $language && ! isset( self::languages()[ $language ] ) ) {
			return self::error( 'Unknown language.' );
		}

		return array(
			'success'  => true,
			'language' => $language,
			'profiles' => self::quality_profile_payload( $language ),
		);
	}

	/**
	 * Update language quality profile runtime overrides stored in WordPress options.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function update_runtime_quality_profile( array $input ): array {
		self::maybe_seed_runtime_language_text_options();

		$language = sanitize_key( (string) ( $input['language'] ?? '' ) );
		if ( '' === $language || ! isset( self::languages()[ $language ] ) ) {
			return self::error( 'Unknown language.' );
		}

		$clear         = ! empty( $input['clear'] );
		$replace       = ! empty( $input['replace'] );
		$delete_fields = self::sanitize_quality_profile_field_list( $input['delete_fields'] ?? array() );
		$patch         = self::sanitize_quality_profile_patch( $input['profile'] ?? array() );

		if ( ! $clear && empty( $delete_fields ) && empty( $patch ) ) {
			return self::error( 'No quality profile changes supplied.' );
		}

		$languages = get_option( self::OPTION_LANGUAGES );
		if ( ! is_array( $languages ) ) {
			$languages = array();
		}
		if ( ! isset( $languages[ $language ] ) || ! is_array( $languages[ $language ] ) ) {
			$languages[ $language ] = array();
		}

		$current = isset( $languages[ $language ]['language_profile'] ) && is_array( $languages[ $language ]['language_profile'] )
			? $languages[ $language ]['language_profile']
			: array();

		if ( $clear ) {
			$next = array();
		} else {
			foreach ( $delete_fields as $field ) {
				unset( $current[ $field ] );
			}
			$next = $replace ? $patch : self::merge_quality_profile_patch( $current, $patch );
		}

		$next = self::compact_quality_profile( $next );
		if ( $next ) {
			$languages[ $language ]['language_profile'] = $next;
		} else {
			unset( $languages[ $language ]['language_profile'] );
		}

		update_option( self::OPTION_LANGUAGES, $languages, false );
		self::languages( true );

		return array(
			'success'       => true,
			'message'       => $clear ? 'Runtime quality profile override removed.' : 'Runtime quality profile override updated.',
			'language'      => $language,
			'cleared'       => $clear,
			'replaced'      => $replace,
			'deleted_fields'=> $delete_fields,
			'profile'       => self::quality_profile_payload( $language )[ $language ],
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function quality_profile_payload( string $language = '' ): array {
		$languages = self::languages();
		$codes     = '' !== $language ? array( sanitize_key( $language ) ) : array_keys( $languages );
		$payload   = array();

		foreach ( $codes as $code ) {
			if ( ! isset( $languages[ $code ] ) ) {
				continue;
			}
			$payload[ $code ] = array(
				'language'         => $code,
				'packaged_profile' => self::packaged_language_review_profile( $code ),
				'packaged_rules'   => self::packaged_language_quality_rules( $code ),
				'runtime_profile'  => self::runtime_language_review_profile( $code ),
				'learned_profile'  => self::learned_language_rule_profile( $code ),
				'effective_profile'=> self::effective_language_review_profile( $code ),
			);
		}

		return $payload;
	}

	/**
	 * Record one reusable language rule or learning event.
	 *
	 * @param array<string,mixed> $input Ability/internal input.
	 * @return array<string,mixed>
	 */
	private static function record_language_rule_event( array $input ): array {
		$event = self::sanitize_language_rule_event( $input );
		if ( empty( $event['language'] ) || ! self::is_configured_content_language( (string) $event['language'] ) ) {
			return self::error( 'Configured language is required.' );
		}
		if ( empty( $event['rule_type'] ) ) {
			return self::error( 'Rule type is required.' );
		}

		if ( ! self::language_rule_events_available() ) {
			self::install_language_rule_events_schema();
		}
		if ( ! self::language_rule_events_available() ) {
			return self::error( 'Language rule event table is not available.' );
		}

		global $wpdb;
		$table = esc_sql( self::language_rule_events_table() );
		$now   = current_time( 'mysql', true );
		if ( empty( $event['event_key'] ) ) {
			$event['event_key'] = 'rule-' . substr( hash( 'sha256', wp_json_encode( $event ) . '|' . $now ), 0, 24 );
		}
		$event['updated_at'] = $now;
		if ( empty( $event['created_at'] ) ) {
			$event['created_at'] = $now;
		}

		$result = $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional write to this plugin's custom rule-event table.
			$table,
			array(
				'event_key'      => $event['event_key'],
				'language'       => $event['language'],
				'reviewer_key'   => $event['reviewer_key'],
				'rule_type'      => $event['rule_type'],
				'scope'          => $event['scope'],
				'selector'       => $event['selector'],
				'decision'       => $event['decision'],
				'source_text'    => $event['source_text'],
				'target_text'    => $event['target_text'],
				'replacement'    => $event['replacement'],
				'reason'         => $event['reason'],
				'payload'        => wp_json_encode( $event['payload'] ),
				'source'         => $event['source'],
				'source_id'      => $event['source_id'],
				'translation_id' => $event['translation_id'],
				'created_by'     => $event['created_by'],
				'reviewer'       => $event['reviewer'],
				'status'         => $event['status'],
				'confidence'     => $event['confidence'],
				'created_at'     => $event['created_at'],
				'updated_at'     => $event['updated_at'],
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%f', '%s', '%s' )
		);
		if ( false === $result ) {
			return self::error( 'Could not store language rule event.' );
		}

		return array(
			'success' => true,
			'message' => 'Language rule event recorded.',
			'event'   => $event,
		);
	}

	/**
	 * List stored language-rule events.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function list_language_rule_events( array $input ): array {
		if ( ! self::language_rule_events_available() ) {
			return array(
				'success'          => true,
				'table_available'  => false,
				'events'           => array(),
				'event_count'      => 0,
				'schema_version'   => (string) get_option( self::OPTION_LANGUAGE_RULE_EVENTS_SCHEMA, '' ),
				'expected_schema'  => self::LANGUAGE_RULE_EVENTS_SCHEMA_VERSION,
			);
		}

		$language  = sanitize_key( (string) ( $input['language'] ?? '' ) );
		$status    = sanitize_key( (string) ( $input['status'] ?? '' ) );
		$rule_type = sanitize_key( (string) ( $input['rule_type'] ?? '' ) );
		$limit     = min( 200, max( 1, absint( $input['limit'] ?? 50 ) ) );

		global $wpdb;
		$table  = self::language_rule_events_table();
		$where  = array( '1=1' );
		$params = array();
		if ( '' !== $language ) {
			$where[]  = 'language = %s';
			$params[] = $language;
		}
		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		if ( '' !== $rule_type ) {
			$where[]  = 'rule_type = %s';
			$params[] = $rule_type;
		}
		$params[] = $limit;
		$sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC, id DESC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is built from fixed clauses and prepared values for this plugin's custom rule-event table.

		$events = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$events[] = self::language_rule_event_from_db_row( $row );
		}

		return array(
			'success'         => true,
			'table_available' => true,
			'schema_version'  => (string) get_option( self::OPTION_LANGUAGE_RULE_EVENTS_SCHEMA, '' ),
			'expected_schema' => self::LANGUAGE_RULE_EVENTS_SCHEMA_VERSION,
			'events'          => $events,
			'event_count'     => count( $events ),
		);
	}

	/**
	 * List captured human edits that may deserve operator review.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function learning_inbox( array $input ): array {
		if ( ! self::language_rule_events_available() ) {
			return array(
				'success'         => true,
				'table_available' => false,
				'items'           => array(),
				'item_count'      => 0,
			);
		}

		$language     = sanitize_key( (string) ( $input['language'] ?? '' ) );
		$reviewer     = sanitize_text_field( (string) ( $input['reviewer'] ?? '' ) );
		$reviewer_key = '' !== $reviewer ? self::reviewer_style_key( $reviewer ) : '';
		$inbox_status = sanitize_key( (string) ( $input['inbox_status'] ?? 'pending' ) );
		if ( ! in_array( $inbox_status, array( 'pending', 'used_as_style', 'promoted_to_rule', 'ignored', 'all' ), true ) ) {
			$inbox_status = 'pending';
		}
		$limit = min( 200, max( 1, absint( $input['limit'] ?? 50 ) ) );

		global $wpdb;
		$table  = self::language_rule_events_table();
		$where  = array( 'rule_type = %s', 'source = %s' );
		$params = array( 'reviewer_style', 'human_edit' );
		if ( '' !== $language ) {
			$where[] = 'language = %s';
			$params[] = $language;
		}
		if ( '' !== $reviewer_key ) {
			$where[] = 'reviewer_key = %s';
			$params[] = $reviewer_key;
		}
		$query_limit = 'all' === $inbox_status ? $limit : min( 500, max( $limit * 5, $limit + 25 ) );
		$params[] = $query_limit;
		$sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC, id DESC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is built from fixed clauses and prepared values for this plugin's custom rule-event table.

		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$event  = self::language_rule_event_from_db_row( $row );
			$status = self::learning_event_inbox_status( $event );
			if ( 'all' !== $inbox_status && $status !== $inbox_status ) {
				continue;
			}
			$items[] = self::learning_inbox_item( $event, $status );
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return array(
			'success'         => true,
			'table_available' => true,
			'inbox_status'    => $inbox_status,
			'items'           => $items,
			'item_count'      => count( $items ),
			'query_limit'     => $query_limit,
			'next_actions'    => array(
				'use_as_style'    => 'Keep the edit as reviewer-style guidance and mark the inbox item reviewed.',
				'promote_to_rule' => 'Create an active naturalness QA rule from the before/after edit, then mark the item promoted.',
				'ignore'          => 'Hide the item from the pending learning inbox without changing public content.',
			),
		);
	}

	/**
	 * Review one captured learning event.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function review_learning_event( array $input ): array {
		if ( ! self::language_rule_events_available() ) {
			return self::error( 'Language rule event table is not available.' );
		}

		$event = self::learning_event_by_input( $input );
		if ( empty( $event ) ) {
			return self::error( 'Learning event was not found.' );
		}
		if ( 'reviewer_style' !== (string) ( $event['rule_type'] ?? '' ) ) {
			return self::error( 'Only reviewer-style learning events can be reviewed through the learning inbox.' );
		}

		$action = sanitize_key( (string) ( $input['action'] ?? '' ) );
		if ( ! in_array( $action, array( 'use_as_style', 'promote_to_rule', 'ignore' ), true ) ) {
			return self::error( 'Action must be use_as_style, promote_to_rule, or ignore.' );
		}

		$note = self::copy_brief_excerpt( self::normalize_review_text( wp_strip_all_tags( (string) ( $input['note'] ?? '' ) ) ), 500 );
		$payload = isset( $event['payload'] ) && is_array( $event['payload'] ) ? $event['payload'] : array();
		$payload['learning_review_status'] = 'use_as_style' === $action ? 'used_as_style' : ( 'ignore' === $action ? 'ignored' : 'promoted_to_rule' );
		$payload['learning_reviewed_at']     = gmdate( 'c' );
		$payload['learning_reviewed_by']     = self::reviewer_name_for_current_user();
		if ( '' !== $note ) {
			$payload['learning_review_note'] = $note;
		}

		$promoted = null;
		if ( 'promote_to_rule' === $action ) {
			$promoted = self::promote_learning_event_to_naturalness_rule( $event, $input );
			if ( empty( $promoted['success'] ) ) {
				return $promoted;
			}
			$payload['promoted_event_key'] = (string) ( $promoted['event']['event_key'] ?? '' );
		}

		$updated = self::update_language_rule_event_payload( (int) ( $event['id'] ?? 0 ), $payload );
		if ( empty( $updated['success'] ) ) {
			return $updated;
		}

		return array(
			'success'        => true,
			'message'        => 'Learning event reviewed.',
			'action'         => $action,
			'event'          => $updated['event'],
			'promoted_event' => $promoted['event'] ?? null,
		);
	}

	/**
	 * Sanitize a language-rule event.
	 *
	 * @param array<string,mixed> $input Raw event.
	 * @return array<string,mixed>
	 */
	private static function sanitize_language_rule_event( array $input ): array {
		$language       = sanitize_key( (string) ( $input['language'] ?? '' ) );
		$translation_id = absint( $input['translation_id'] ?? 0 );
		if ( $translation_id && self::is_translation_post( $translation_id ) ) {
			$language = sanitize_key( (string) get_post_meta( $translation_id, self::META_LANGUAGE, true ) );
		}

		$reviewer = sanitize_text_field( (string) ( $input['reviewer'] ?? '' ) );
		$status   = sanitize_key( (string) ( $input['status'] ?? 'active' ) );
		if ( ! in_array( $status, array( 'draft', 'active', 'rejected', 'expired' ), true ) ) {
			$status = 'active';
		}

		$decision = sanitize_key( (string) ( $input['decision'] ?? 'flag' ) );
		if ( ! in_array( $decision, array( 'allow', 'block', 'prefer', 'rewrite', 'flag', 'learn' ), true ) ) {
			$decision = 'flag';
		}

		$scope = sanitize_key( (string) ( $input['scope'] ?? 'language' ) );
		if ( ! in_array( $scope, array( 'global', 'language', 'site', 'source', 'translation', 'reviewer' ), true ) ) {
			$scope = 'language';
		}

		$payload = isset( $input['payload'] ) && is_array( $input['payload'] ) ? $input['payload'] : array();
		$payload = self::sanitize_language_rule_payload( $payload );
		if ( empty( $payload ) ) {
			foreach ( array( 'principles', 'preferred_terms', 'avoid_terms', 'suggestions' ) as $field ) {
				if ( array_key_exists( $field, $input ) ) {
					$payload[ $field ] = $input[ $field ];
				}
			}
			$payload = self::sanitize_language_rule_payload( $payload );
		}

		$event = array(
			'event_key'      => sanitize_key( (string) ( $input['event_key'] ?? '' ) ),
			'language'       => $language,
			'reviewer_key'   => self::reviewer_style_key( (string) ( $input['reviewer_key'] ?? $reviewer ) ),
			'rule_type'      => sanitize_key( (string) ( $input['rule_type'] ?? '' ) ),
			'scope'          => $scope,
			'selector'       => self::copy_brief_excerpt( self::normalize_review_text( wp_strip_all_tags( (string) ( $input['selector'] ?? '' ) ) ), 240 ),
			'decision'       => $decision,
			'source_text'    => self::copy_brief_excerpt( self::normalize_review_text( wp_strip_all_tags( (string) ( $input['source_text'] ?? ( $input['before'] ?? '' ) ) ) ), 1000 ),
			'target_text'    => self::copy_brief_excerpt( self::normalize_review_text( wp_strip_all_tags( (string) ( $input['target_text'] ?? ( $input['after'] ?? '' ) ) ) ), 1000 ),
			'replacement'    => self::copy_brief_excerpt( self::normalize_review_text( wp_strip_all_tags( (string) ( $input['replacement'] ?? '' ) ) ), 1000 ),
			'reason'         => self::copy_brief_excerpt( self::normalize_review_text( wp_strip_all_tags( (string) ( $input['reason'] ?? ( $input['lesson'] ?? '' ) ) ) ), 1000 ),
			'payload'        => $payload,
			'source'         => sanitize_key( (string) ( $input['source'] ?? 'human_feedback' ) ),
			'source_id'      => absint( $input['source_id'] ?? 0 ),
			'translation_id' => $translation_id,
			'created_by'     => absint( $input['created_by'] ?? get_current_user_id() ),
			'reviewer'       => $reviewer,
			'status'         => $status,
			'confidence'     => max( 0.0, min( 1.0, (float) ( $input['confidence'] ?? 1.0 ) ) ),
			'created_at'     => sanitize_text_field( (string) ( $input['created_at'] ?? '' ) ),
			'updated_at'     => sanitize_text_field( (string) ( $input['updated_at'] ?? '' ) ),
		);

		if ( '' === $event['selector'] ) {
			$event['selector'] = $event['target_text'] ?: $event['source_text'];
		}

		return $event;
	}

	/**
	 * Sanitize JSON payload stored on a rule event.
	 *
	 * @param mixed $payload Raw payload.
	 * @return array<string,mixed>
	 */
	private static function sanitize_language_rule_payload( $payload ): array {
		if ( ! is_array( $payload ) ) {
			return array();
		}

		$out = array();
		foreach ( $payload as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( is_array( $value ) ) {
				if ( 'script_signals' === $key ) {
					$signals = self::sanitize_quality_profile_script_signals( $value );
					if ( ! empty( $signals ) ) {
						$out[ $key ] = $signals;
					}
				} elseif ( 'preferred_terms' === $key ) {
					$out[ $key ] = self::sanitize_reviewer_preferred_terms( $value );
				} else {
					$out[ $key ] = self::sanitize_quality_profile_string_list( $value );
				}
				continue;
			}
			$value = self::copy_brief_excerpt( self::normalize_review_text( wp_strip_all_tags( (string) $value ) ), 1000 );
			if ( '' !== $value ) {
				$out[ $key ] = $value;
			}
		}

		return self::compact_quality_profile( $out );
	}

	/**
	 * Normalize a DB row to a public event shape.
	 *
	 * @param array<string,mixed> $row DB row.
	 * @return array<string,mixed>
	 */
	private static function language_rule_event_from_db_row( array $row ): array {
		$payload = array();
		if ( ! empty( $row['payload'] ) ) {
			$decoded = json_decode( (string) $row['payload'], true );
			if ( is_array( $decoded ) ) {
				$payload = self::sanitize_language_rule_payload( $decoded );
			}
		}

		return self::compact_quality_profile(
			array(
				'id'             => absint( $row['id'] ?? 0 ),
				'event_key'      => sanitize_key( (string) ( $row['event_key'] ?? '' ) ),
				'language'       => sanitize_key( (string) ( $row['language'] ?? '' ) ),
				'reviewer_key'   => sanitize_key( (string) ( $row['reviewer_key'] ?? '' ) ),
				'rule_type'      => sanitize_key( (string) ( $row['rule_type'] ?? '' ) ),
				'scope'          => sanitize_key( (string) ( $row['scope'] ?? '' ) ),
				'selector'       => (string) ( $row['selector'] ?? '' ),
				'decision'       => sanitize_key( (string) ( $row['decision'] ?? '' ) ),
				'source_text'    => (string) ( $row['source_text'] ?? '' ),
				'target_text'    => (string) ( $row['target_text'] ?? '' ),
				'replacement'    => (string) ( $row['replacement'] ?? '' ),
				'reason'         => (string) ( $row['reason'] ?? '' ),
				'payload'        => $payload,
				'source'         => sanitize_key( (string) ( $row['source'] ?? '' ) ),
				'source_id'      => absint( $row['source_id'] ?? 0 ),
				'translation_id' => absint( $row['translation_id'] ?? 0 ),
				'created_by'     => absint( $row['created_by'] ?? 0 ),
				'reviewer'       => sanitize_text_field( (string) ( $row['reviewer'] ?? '' ) ),
				'status'         => sanitize_key( (string) ( $row['status'] ?? '' ) ),
				'confidence'     => (float) ( $row['confidence'] ?? 0 ),
				'created_at'     => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
				'updated_at'     => sanitize_text_field( (string) ( $row['updated_at'] ?? '' ) ),
			)
		);
	}

	/**
	 * Status used by the operator-facing learning inbox.
	 *
	 * @param array<string,mixed> $event Normalized event.
	 */
	private static function learning_event_inbox_status( array $event ): string {
		$payload = isset( $event['payload'] ) && is_array( $event['payload'] ) ? $event['payload'] : array();
		$status = sanitize_key( (string) ( $payload['learning_review_status'] ?? '' ) );
		if ( in_array( $status, array( 'used_as_style', 'promoted_to_rule', 'ignored' ), true ) ) {
			return $status;
		}

		return 'pending';
	}

	/**
	 * Shape one learning inbox item for AI/operator review.
	 *
	 * @param array<string,mixed> $event  Normalized event.
	 * @param string              $status Inbox status.
	 * @return array<string,mixed>
	 */
	private static function learning_inbox_item( array $event, string $status ): array {
		$source_id        = absint( $event['source_id'] ?? 0 );
		$translation_id   = absint( $event['translation_id'] ?? 0 );
		$source_post      = $source_id ? get_post( $source_id ) : null;
		$translation_post = $translation_id ? get_post( $translation_id ) : null;

		return self::compact_quality_profile(
			array(
				'id'                  => absint( $event['id'] ?? 0 ),
				'event_key'           => sanitize_key( (string) ( $event['event_key'] ?? '' ) ),
				'language'            => sanitize_key( (string) ( $event['language'] ?? '' ) ),
				'reviewer'            => sanitize_text_field( (string) ( $event['reviewer'] ?? '' ) ),
				'inbox_status'        => $status,
				'before'              => (string) ( $event['source_text'] ?? '' ),
				'after'               => (string) ( $event['target_text'] ?? '' ),
				'lesson'              => (string) ( $event['reason'] ?? '' ),
				'category'            => (string) ( $event['payload']['category'] ?? '' ),
				'source_id'           => $source_id,
				'source_title'        => $source_post ? get_the_title( $source_post ) : '',
				'translation_id'      => $translation_id,
				'translation_title'   => $translation_post ? get_the_title( $translation_post ) : '',
				'edit_url'            => $translation_id ? get_edit_post_link( $translation_id, 'raw' ) : '',
				'created_at'          => sanitize_text_field( (string) ( $event['created_at'] ?? '' ) ),
				'updated_at'          => sanitize_text_field( (string) ( $event['updated_at'] ?? '' ) ),
				'recommended_actions' => array( 'use_as_style', 'promote_to_rule', 'ignore' ),
			)
		);
	}

	/**
	 * Fetch one event by id or event key from ability input.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function learning_event_by_input( array $input ): array {
		$id  = absint( $input['event_id'] ?? 0 );
		$key = sanitize_key( (string) ( $input['event_key'] ?? '' ) );
		if ( ! $id && '' === $key ) {
			return array();
		}

		global $wpdb;
		$table = esc_sql( self::language_rule_events_table() );
		if ( $id ) {
			$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Intentional read from this plugin's custom rule-event table.
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is this plugin's own fixed custom table name.
					$id
				),
				ARRAY_A
			);
		} else {
			$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Intentional read from this plugin's custom rule-event table.
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE event_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is this plugin's own fixed custom table name.
					$key
				),
				ARRAY_A
			);
		}

		return is_array( $row ) ? self::language_rule_event_from_db_row( $row ) : array();
	}

	/**
	 * Update only the JSON payload for one stored event.
	 *
	 * @param int                 $event_id Event row id.
	 * @param array<string,mixed> $payload  New payload.
	 * @return array<string,mixed>
	 */
	private static function update_language_rule_event_payload( int $event_id, array $payload ): array {
		if ( ! $event_id ) {
			return self::error( 'Event id is required.' );
		}

		global $wpdb;
		$table   = self::language_rule_events_table();
		$payload = self::sanitize_language_rule_payload( $payload );
		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional write to this plugin's custom rule-event table.
			$table,
			array(
				'payload'    => wp_json_encode( $payload ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $event_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return self::error( 'Could not update learning event.' );
		}

		return array(
			'success' => true,
			'event'   => self::learning_event_by_input( array( 'event_id' => $event_id ) ),
		);
	}

	/**
	 * Promote a reviewer-style before/after edit into a hard naturalness QA rule.
	 *
	 * @param array<string,mixed> $event Stored reviewer-style event.
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function promote_learning_event_to_naturalness_rule( array $event, array $input ): array {
		$before = self::normalize_review_text( (string) ( $event['source_text'] ?? '' ) );
		$after  = self::normalize_review_text( (string) ( $event['target_text'] ?? '' ) );
		if ( '' === $before || '' === $after || $before === $after ) {
			return self::error( 'A before/after edit is required before a learning event can be promoted to a QA rule.' );
		}

		$event_key = sanitize_key( (string) ( $input['promoted_event_key'] ?? '' ) );
		if ( '' === $event_key ) {
			$event_key = 'qa-' . sanitize_key( (string) ( $event['event_key'] ?? ( 'event-' . (int) ( $event['id'] ?? 0 ) ) ) );
		}

		$reason = self::copy_brief_excerpt(
			self::normalize_review_text(
				wp_strip_all_tags(
					(string) ( $input['reason'] ?? ( (string) ( $event['reason'] ?? '' ) ?: 'Human reviewer rejected this target-language phrasing. Future translations should fail QA until rewritten naturally.' ) )
				)
			),
			1000
		);

		return self::record_language_rule_event(
			array(
				'event_key'      => $event_key,
				'language'       => (string) ( $event['language'] ?? '' ),
				'reviewer'       => (string) ( $event['reviewer'] ?? '' ),
				'rule_type'      => 'naturalness_pattern',
				'scope'          => 'language',
				'selector'       => $before,
				'decision'       => 'block',
				'target_text'    => $before,
				'replacement'    => $after,
				'reason'         => $reason,
				'source'         => 'learning_inbox_promotion',
				'source_id'      => absint( $event['source_id'] ?? 0 ),
				'translation_id' => absint( $event['translation_id'] ?? 0 ),
				'status'         => 'active',
				'confidence'     => max( 0.0, min( 1.0, (float) ( $input['confidence'] ?? (float) ( $event['confidence'] ?? 0.9 ) ) ) ),
					'payload'        => array(
						'suggestions'            => array( $after ),
						'promoted_from_event'    => (string) ( $event['event_key'] ?? '' ),
						'learning_review_status' => 'promoted_rule',
				),
			)
		);
	}

	/**
	 * Current user's stable reviewer label for learning review metadata.
	 */
	private static function reviewer_name_for_current_user(): string {
		$user_id = get_current_user_id();
		$user    = $user_id ? get_userdata( $user_id ) : false;
		return $user instanceof WP_User ? self::reviewer_name_for_user( $user ) : 'System';
	}

	/**
	 * Effective profile patch learned from active rule events.
	 *
	 * @return array<string,mixed>
	 */
	private static function learned_language_rule_profile( string $language ): array {
		$language = sanitize_key( $language );
		if ( '' === $language || ! self::language_rule_events_available() ) {
			return array();
		}

		$events = self::active_language_rule_events( $language );
		$profile = array();
		foreach ( $events as $event ) {
			$profile = self::merge_quality_profile_patch( $profile, self::language_rule_event_profile_patch( $event ) );
		}

		return self::compact_quality_profile( $profile );
	}

	/**
	 * Active language rule events for the effective profile.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function active_language_rule_events( string $language ): array {
		$language = sanitize_key( $language );
		if ( '' === $language || ! self::language_rule_events_available() ) {
			return array();
		}

		global $wpdb;
		$table = self::language_rule_events_table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Intentional read from this plugin's custom rule-event table.
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE language = %s AND status = %s ORDER BY confidence DESC, updated_at ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is this plugin's own fixed custom table name.
				$language,
				'active'
			),
			ARRAY_A
		);

		$events = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$events[] = self::language_rule_event_from_db_row( $row );
		}

		return $events;
	}

	/**
	 * Convert one stored event into a quality-profile patch.
	 *
	 * @param array<string,mixed> $event Stored event.
	 * @return array<string,mixed>
	 */
	private static function language_rule_event_profile_patch( array $event ): array {
		$type        = sanitize_key( (string) ( $event['rule_type'] ?? '' ) );
		$decision    = sanitize_key( (string) ( $event['decision'] ?? '' ) );
		$selector    = self::normalize_review_text( (string) ( $event['selector'] ?? '' ) );
		$source_text = self::normalize_review_text( (string) ( $event['source_text'] ?? '' ) );
		$target_text = self::normalize_review_text( (string) ( $event['target_text'] ?? '' ) );
		$replacement = self::normalize_review_text( (string) ( $event['replacement'] ?? '' ) );
		$reason      = self::normalize_review_text( (string) ( $event['reason'] ?? '' ) );
		$payload     = isset( $event['payload'] ) && is_array( $event['payload'] ) ? $event['payload'] : array();
		$term        = $selector ?: ( $target_text ?: $source_text );

		switch ( $type ) {
			case 'source_carryover_homograph':
				return '' !== $term && 'allow' === $decision ? array( 'source_carryover_homographs' => array( $term ) ) : array();
			case 'script_shadow_exclusion':
				return '' !== $term && 'allow' === $decision ? array( 'script_signals' => array( 'shadow_exclusions' => array( $term ) ) ) : array();
			case 'script_signal_option':
				if ( empty( $payload['script_signals'] ) || ! is_array( $payload['script_signals'] ) ) {
					return array();
				}
				$signals = self::sanitize_quality_profile_script_signals( $payload['script_signals'] );
				return empty( $signals ) ? array() : array( 'script_signals' => $signals );
			case 'preserve_term':
				return '' !== $term ? array( 'preserve_terms' => array( $term ) ) : array();
			case 'avoid_term':
				return '' !== $term ? array( 'never_translate_terms' => array( $term ) ) : array();
			case 'review_pattern':
				return '' !== $term ? array( 'review_patterns' => array( $term ) ) : array();
			case 'preferred_term':
				if ( '' !== $source_text && '' !== $replacement ) {
					return array( 'localized_terms' => array( $source_text => array( $replacement ) ) );
				}
				return array();
			case 'naturalness_pattern':
				if ( '' === $target_text && '' === $selector ) {
					return array();
				}
				$row = array(
					'id'      => sanitize_key( (string) ( $event['event_key'] ?? '' ) ),
					'target'  => $target_text ?: $selector,
					'message' => '' !== $reason ? $reason : 'Human or QA feedback marked this phrasing as unnatural for the target language.',
				);
				if ( '' !== $source_text ) {
					$row['source'] = $source_text;
				}
				$suggestions = isset( $payload['suggestions'] ) && is_array( $payload['suggestions'] ) ? $payload['suggestions'] : array();
				if ( '' !== $replacement ) {
					array_unshift( $suggestions, $replacement );
				}
				$suggestions = self::sanitize_quality_profile_string_list( $suggestions );
				if ( $suggestions ) {
					$row['suggestions'] = $suggestions;
				}
				return array( 'naturalness_patterns' => array( $row ) );
		}

		return array();
	}

	/**
	 * Build an agency-level copy brief for a source or translation.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function agency_copy_brief( array $input ): array {
		$translation_id = absint( $input['translation_id'] ?? 0 );
		$source_id      = absint( $input['source_id'] ?? 0 );
		$requested_language = sanitize_key( (string) ( $input['language'] ?? '' ) );
		$language       = $requested_language;
		$reviewer       = sanitize_text_field( (string) ( $input['reviewer'] ?? '' ) );
		$translation    = null;
		$language_context = array();

		if ( $translation_id ) {
			$translation_post = get_post( $translation_id );
			if ( ! $translation_post || ! self::is_translatable_post_type( (string) $translation_post->post_type ) || ! self::is_translation_post( $translation_id ) ) {
				return self::error( 'Translation content not found.' );
			}
			$translation = self::translation_payload( $translation_post );
			$source_id   = absint( get_post_meta( $translation_id, self::META_SOURCE_ID, true ) );
			$language_context = self::review_language_context_for_post( $translation_post, $requested_language );
			$language    = (string) $language_context['target_language'];
		}

		if ( ! $source_id ) {
			return self::error( 'Source content is required.' );
		}
		$source = get_post( $source_id );
		if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) ) {
			return self::error( 'Source content not found.' );
		}
		if ( ! $language_context ) {
			$language_context = self::review_language_context_for_post( $source, $language );
			$language = (string) $language_context['target_language'];
		}
		if ( '' === $language || ! self::is_configured_content_language( $language ) ) {
			return self::error( 'Content language is missing or not configured.' );
		}

		$profile     = self::language_review_profile( $language );
		$agency_copy = self::agency_copy_review_profile( $language );
		$reviewer_style = '' !== $reviewer ? self::reviewer_style_profile( $language, $reviewer ) : array();

		$payload = array(
			'success'        => true,
			'language'       => $language,
			'language_context' => $language_context,
			'agency_copy_enabled' => self::agency_copy_review_enabled( $language ),
			'agency_copy_profile' => $agency_copy,
			'source'         => self::source_summary_payload( $source ),
			'translation'    => $translation,
			'language_profile_summary' => array(
				'review_language' => (string) ( $profile['review_language'] ?? '' ),
				'tone'            => (string) ( $profile['tone'] ?? '' ),
				'formality'       => (string) ( $profile['formality'] ?? '' ),
				'locale_guidance' => (string) ( $profile['locale_guidance'] ?? '' ),
			),
			'required_linguistic_checks' => self::required_linguistic_review_checks( $language ),
			'required_quality_checks'    => self::required_quality_review_checks( $language ),
			'source_fragments'           => self::copy_brief_fragments( (string) $source->post_content ),
			'review_questions'           => self::agency_copy_review_questions( $language ),
			'internal_linking'           => self::internal_link_opportunities_for_post( $translation_post ?? $source, $language, 3 ),
			'internal_linking_instructions' => array(
				'Use these as moderated suggestions, not mandatory links.',
				'Add zero to two contextual internal links when they genuinely help the reader continue.',
				'Prefer the localized target URL supplied by the system. Do not add repeated keyword links or boilerplate link blocks.',
			),
		);
		if ( $reviewer_style ) {
			$payload['reviewer_style_profile']      = $reviewer_style;
			$payload['reviewer_style_instructions'] = self::reviewer_style_instructions( $reviewer_style );
		}

		return $payload;
	}

	/**
	 * Compact agency-copy review profile for a language.
	 *
	 * @return array<string,mixed>
	 */
	private static function agency_copy_review_profile( string $language ): array {
		$profile = self::language_review_profile( $language );
		$agency  = isset( $profile['agency_copy'] ) && is_array( $profile['agency_copy'] ) ? $profile['agency_copy'] : array();

		return self::compact_quality_profile( $agency );
	}

	/**
	 * Whether a language has the agency-copy review gate enabled.
	 */
	private static function agency_copy_review_enabled( string $language ): bool {
		$agency = self::agency_copy_review_profile( $language );
		if ( empty( $agency ) ) {
			return false;
		}

		return ! array_key_exists( 'enabled', $agency ) || ! empty( $agency['enabled'] );
	}

	/**
	 * Questions reviewers must answer mentally before marking agency copy done.
	 *
	 * @return array<int,string>
	 */
	private static function agency_copy_review_questions( string $language ): array {
		$agency   = self::agency_copy_review_profile( $language );
		$defaults = array(
			'Can the intended reader understand the problem and offer without already knowing marketing or technical service jargon?',
			'Does the first screen make the buyer, promise, proof, and next action clear?',
			'Are abstract service labels translated into concrete customer outcomes where needed?',
			'Does the language sound like local commercial copy rather than a sentence-by-sentence translation?',
			'Have internal-link opportunities been considered in moderation, with links added only where they genuinely help the reader?',
		);
		$configured = isset( $agency['review_questions'] ) && is_array( $agency['review_questions'] ) ? $agency['review_questions'] : array();

		return self::unique_source_qa_terms( array_merge( $configured, $defaults ) );
	}

	/**
	 * Short source text samples for copy brief responses.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function copy_brief_fragments( string $content ): array {
		$rows = array();
		foreach ( self::text_fragments_for_copy_quality( $content ) as $fragment ) {
			$text = self::normalize_review_text( (string) ( $fragment['text'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$rows[] = array(
				'block'      => (string) ( $fragment['block'] ?? '' ),
				'heading'    => ! empty( $fragment['heading'] ),
				'unique_id'  => (string) ( $fragment['unique_id'] ?? '' ),
				'text'       => self::copy_brief_excerpt( $text, 220 ),
			);
			if ( count( $rows ) >= 16 ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * Keep copy-brief samples compact without requiring mbstring.
	 */
	private static function copy_brief_excerpt( string $text, int $limit ): string {
		$limit = max( 20, $limit );
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			return mb_strlen( $text, 'UTF-8' ) > $limit ? mb_substr( $text, 0, $limit - 3, 'UTF-8' ) . '...' : $text;
		}

		return strlen( $text ) > $limit ? substr( $text, 0, $limit - 3 ) . '...' : $text;
	}

	/**
	 * Record native/agency copy feedback against one reviewable content item.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function record_copy_feedback( array $input ): array {
		$content_id = absint( $input['content_id'] ?? ( $input['translation_id'] ?? 0 ) );
		$post       = get_post( $content_id );
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			return self::error( 'Content not found.' );
		}

		$feedback_id = sanitize_key( (string) ( $input['feedback_id'] ?? '' ) );
		$status      = sanitize_key( (string) ( $input['status'] ?? 'open' ) );
		$severity    = sanitize_key( (string) ( $input['severity'] ?? 'needs_work' ) );
		if ( ! in_array( $status, array( 'open', 'resolved' ), true ) ) {
			$status = 'open';
		}
		if ( ! in_array( $severity, array( 'info', 'needs_work', 'blocking' ), true ) ) {
			$severity = 'needs_work';
		}

		$feedback_text = self::normalize_review_text( wp_strip_all_tags( (string) ( $input['feedback'] ?? '' ) ) );
		$note          = self::normalize_review_text( wp_strip_all_tags( (string) ( $input['note'] ?? '' ) ) );
		if ( '' === $feedback_text && '' === $feedback_id ) {
			return self::error( 'Feedback text is required when adding copy feedback.' );
		}

		$items  = self::copy_feedback_for_post( $content_id );
		$now    = gmdate( 'c' );
		$found  = false;
		$language_context = self::review_language_context_for_post( $post );
		$source_id = absint( $language_context['source_id'] ?? 0 );
		$source    = $source_id ? get_post( $source_id ) : null;
		$language  = sanitize_key( (string) ( $language_context['target_language'] ?? '' ) );
			$base_item = array(
				'status'          => $status,
				'severity'        => $severity,
				'reviewer'        => sanitize_text_field( (string) ( $input['reviewer'] ?? 'AI Translation Workflow' ) ),
				'updated_at'      => $now,
				'content_hash'    => self::translation_review_content_hash( $post ),
				'source_hash'     => $source ? self::source_hash( $source ) : '',
				'language'        => $language,
				'language_context'=> $language_context,
			);
		if ( '' !== $feedback_text ) {
			$base_item['feedback'] = $feedback_text;
		}
		if ( '' !== $note ) {
			$base_item['note'] = $note;
		}

		foreach ( $items as &$item ) {
			if ( '' === $feedback_id || (string) ( $item['id'] ?? '' ) !== $feedback_id ) {
				continue;
			}
			$item  = array_merge( $item, $base_item );
			$found = true;
			break;
		}
		unset( $item );

		if ( ! $found ) {
			$new_id  = '' !== $feedback_id ? $feedback_id : 'copy-' . substr( hash( 'sha256', $content_id . '|' . $feedback_text . '|' . $now ), 0, 16 );
			$items[] = array_merge(
				array(
					'id'          => $new_id,
					'recorded_at' => $now,
					'feedback'    => $feedback_text,
				),
				$base_item
			);
			$feedback_id = $new_id;
		}

		$items = self::sanitize_copy_feedback_items( $items );
		if ( $items ) {
			update_post_meta( $content_id, self::META_COPY_FEEDBACK, wp_json_encode( $items ) );
		} else {
			delete_post_meta( $content_id, self::META_COPY_FEEDBACK );
		}

		$reviewer_style_result = null;
		if ( ! empty( $input['learn_from_feedback'] ) ) {
			$reviewer_style_result = self::record_reviewer_style_edit(
				array(
					'language'       => $language,
					'reviewer'       => (string) ( $base_item['reviewer'] ?? '' ),
					'source_id'      => $source_id,
						'translation_id' => absint( $language_context['translation_id'] ?? 0 ),
					'before'         => (string) ( $input['before'] ?? '' ),
					'after'          => (string) ( $input['after'] ?? '' ),
					'lesson'         => (string) ( $input['lesson'] ?? $feedback_text ),
					'category'       => (string) ( $input['category'] ?? 'other' ),
					'principles'     => $input['principles'] ?? array(),
					'preferred_terms'=> $input['preferred_terms'] ?? array(),
					'avoid_terms'    => $input['avoid_terms'] ?? array(),
				)
			);
		}

		$response = array(
			'success'       => true,
			'message'       => $found ? 'Copy feedback updated.' : 'Copy feedback recorded.',
			'content_id'    => $content_id,
			'feedback_id'   => $feedback_id,
			'feedback'      => self::copy_feedback_for_post( $content_id ),
			'open_feedback' => self::open_copy_feedback_for_post( $content_id ),
			'quality_item'  => self::quality_review_queue_item( $post ),
		);
		if ( null !== $reviewer_style_result ) {
			$response['reviewer_style'] = $reviewer_style_result;
		}

		return $response;
	}

	/**
	 * Return reviewer-specific style profiles stored in WordPress options.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function get_reviewer_style_profile( array $input ): array {
		$language = sanitize_key( (string) ( $input['language'] ?? '' ) );
		$reviewer = sanitize_text_field( (string) ( $input['reviewer'] ?? '' ) );
		if ( '' !== $language && ! self::is_configured_content_language( $language ) ) {
			return self::error( 'Language is not configured.' );
		}

		$profiles = self::reviewer_style_profiles_option();
		if ( '' !== $language && '' !== $reviewer ) {
			return array(
				'success'  => true,
				'language' => $language,
				'reviewer' => $reviewer,
				'profile'  => self::reviewer_style_profile( $language, $reviewer ),
			);
		}

		if ( '' !== $language ) {
			return array(
				'success'  => true,
				'language' => $language,
				'profiles' => $profiles[ $language ] ?? array(),
			);
		}
		if ( '' !== $reviewer ) {
			$key = self::reviewer_style_key( $reviewer );
			$filtered = array();
			foreach ( $profiles as $profile_language => $language_profiles ) {
				if ( isset( $language_profiles[ $key ] ) ) {
					$filtered[ $profile_language ][ $key ] = $language_profiles[ $key ];
				}
			}

			return array(
				'success'  => true,
				'reviewer' => $reviewer,
				'profiles' => $filtered,
			);
		}

		return array(
			'success'  => true,
			'profiles' => $profiles,
		);
	}

	/**
	 * Record one approved reviewer edit or lesson as reusable future guidance.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function record_reviewer_style_edit( array $input ): array {
		$language = sanitize_key( (string) ( $input['language'] ?? '' ) );
		$translation_id = absint( $input['translation_id'] ?? 0 );
		if ( $translation_id && self::is_translation_post( $translation_id ) ) {
			$language = sanitize_key( (string) get_post_meta( $translation_id, self::META_LANGUAGE, true ) );
		}
		if ( '' === $language || ! self::is_configured_content_language( $language ) ) {
			return self::error( 'Configured language is required.' );
		}

		$reviewer = sanitize_text_field( (string) ( $input['reviewer'] ?? '' ) );
		if ( '' === $reviewer ) {
			return self::error( 'Reviewer is required.' );
		}

		$before = self::copy_brief_excerpt( self::normalize_review_text( wp_strip_all_tags( (string) ( $input['before'] ?? '' ) ) ), 700 );
		$after  = self::copy_brief_excerpt( self::normalize_review_text( wp_strip_all_tags( (string) ( $input['after'] ?? '' ) ) ), 700 );
		$lesson = self::copy_brief_excerpt( self::normalize_review_text( wp_strip_all_tags( (string) ( $input['lesson'] ?? '' ) ) ), 500 );
		$principles = self::sanitize_quality_profile_string_list( $input['principles'] ?? array() );
		$avoid_terms = self::sanitize_quality_profile_string_list( $input['avoid_terms'] ?? array() );
		$preferred_terms = self::sanitize_reviewer_preferred_terms( $input['preferred_terms'] ?? array() );
		if ( '' === $before && '' === $after && '' === $lesson && empty( $principles ) && empty( $avoid_terms ) && empty( $preferred_terms ) ) {
			return self::error( 'At least one edit, lesson, principle, preferred term, or avoided term is required.' );
		}

		$source_id = absint( $input['source_id'] ?? 0 );
		if ( ! $source_id && $translation_id ) {
			$source_id = absint( get_post_meta( $translation_id, self::META_SOURCE_ID, true ) );
		}
		$category = sanitize_key( (string) ( $input['category'] ?? 'other' ) );
		if ( ! in_array( $category, array( 'idiom', 'tone', 'terminology', 'clarity', 'cta', 'structure', 'culture', 'other' ), true ) ) {
			$category = 'other';
		}

		$now      = gmdate( 'c' );
		$example  = array();
		if ( '' !== $before || '' !== $after || '' !== $lesson ) {
			$example = array(
				'id'             => sanitize_key( (string) ( $input['example_id'] ?? '' ) ),
				'source_id'      => $source_id,
				'translation_id' => $translation_id,
				'before'         => $before,
				'after'          => $after,
				'lesson'         => $lesson,
				'category'       => $category,
				'recorded_at'    => $now,
			);
			if ( '' === $example['id'] ) {
				$example['id'] = 'style-' . substr( hash( 'sha256', $language . '|' . $reviewer . '|' . $before . '|' . $after . '|' . $lesson . '|' . $now ), 0, 16 );
			}
		}

		$profiles = self::reviewer_style_profiles_option();
		$key      = self::reviewer_style_key( $reviewer );
		$current  = $profiles[ $language ][ $key ] ?? array();
		$superseded_event_keys = $example
			? self::supersede_recent_pending_reviewer_style_events( $language, $key, $source_id, $translation_id, (string) $example['id'], $before, $after )
			: array();
		if ( $superseded_event_keys ) {
			$current = self::remove_reviewer_style_examples( $current, $superseded_event_keys );
		}
		$profile  = self::merge_reviewer_style_profile(
			$current,
			array(
				'language'        => $language,
				'reviewer'        => $reviewer,
				'reviewer_key'    => $key,
				'updated_at'      => $now,
				'principles'      => $principles,
				'preferred_terms' => $preferred_terms,
				'avoid_terms'     => $avoid_terms,
				'examples'        => $example ? array( $example ) : array(),
			)
		);

		$profiles[ $language ][ $key ] = $profile;
		update_option( self::OPTION_REVIEWER_STYLE_PROFILES, $profiles, false );

		self::record_language_rule_event(
			array(
				'event_key'      => $example ? (string) $example['id'] : '',
				'language'       => $language,
				'reviewer'       => $reviewer,
				'reviewer_key'   => $key,
				'rule_type'      => 'reviewer_style',
				'scope'          => 'reviewer',
				'selector'       => $category,
				'decision'       => 'learn',
				'source_text'    => $before,
				'target_text'    => $after,
				'reason'         => $lesson,
				'source'         => 'human_edit',
				'source_id'      => $source_id,
				'translation_id' => $translation_id,
				'payload'        => array(
					'category'        => $category,
					'principles'      => $principles,
					'preferred_terms' => $preferred_terms,
					'avoid_terms'     => $avoid_terms,
					'learning_review_status' => 'pending',
					'superseded_event_keys' => $superseded_event_keys,
				),
			)
		);

		return array(
			'success'  => true,
			'message'  => 'Reviewer style profile updated.',
			'language' => $language,
			'reviewer' => $reviewer,
			'profile'  => $profile,
		);
	}

	/**
	 * Hide earlier pending human-edit learning when a reviewer quickly saves a
	 * better follow-up wording for the same content.
	 *
	 * @return array<int,string> Superseded event keys.
	 */
	private static function supersede_recent_pending_reviewer_style_events( string $language, string $reviewer_key, int $source_id, int $translation_id, string $replacement_event_key, string $replacement_before, string $replacement_after ): array {
		if ( '' === $language || '' === $reviewer_key || '' === $replacement_event_key || ! self::language_rule_events_available() ) {
			return array();
		}
		if ( ! $source_id && ! $translation_id ) {
			return array();
		}

		global $wpdb;
		$table = esc_sql( self::language_rule_events_table() );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional read from this plugin's custom rule-event table.
			$wpdb->prepare(
				'SELECT * FROM ' . $table . ' WHERE language = %s AND reviewer_key = %s AND rule_type = %s AND source = %s AND source_id = %d AND translation_id = %d AND status = %s ORDER BY updated_at DESC, id DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is this plugin's own fixed table name.
				$language,
				$reviewer_key,
				'reviewer_style',
				'human_edit',
				$source_id,
				$translation_id,
				'active',
				10
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array();
		}

		$now              = current_time( 'timestamp', true );
		$reviewed_at      = gmdate( 'c', $now );
		$supersede_window = self::reviewer_style_supersede_window_seconds();
		$superseded_keys  = array();
		foreach ( $rows as $row ) {
			$event = self::language_rule_event_from_db_row( $row );
			$event_key = sanitize_key( (string) ( $event['event_key'] ?? '' ) );
			if ( '' === $event_key || $event_key === $replacement_event_key ) {
				continue;
			}
			$payload = isset( $event['payload'] ) && is_array( $event['payload'] ) ? $event['payload'] : array();
			if ( 'pending' !== sanitize_key( (string) ( $payload['learning_review_status'] ?? 'pending' ) ) ) {
				continue;
			}
			$updated_at = strtotime( (string) ( $event['updated_at'] ?? '' ) );
			if ( false === $updated_at || $supersede_window <= 0 || abs( $now - $updated_at ) > $supersede_window ) {
				continue;
			}
			if ( ! self::reviewer_style_events_overlap( $event, $replacement_before, $replacement_after ) ) {
				continue;
			}

			$payload['learning_review_status'] = 'ignored';
			$payload['learning_reviewed_at']   = $reviewed_at;
			$payload['learning_reviewed_by']   = 'system';
			$payload['learning_review_note']   = 'Superseded by a later human edit on the same content/reviewer within the configured refinement window.';
			$payload['superseded_by_event_key'] = $replacement_event_key;

			$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional write to this plugin's custom rule-event table.
				$table,
				array(
					'payload'    => wp_json_encode( $payload ),
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => absint( $event['id'] ?? 0 ) ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			if ( false !== $updated ) {
				$superseded_keys[] = $event_key;
			}
		}

		return array_values( array_unique( $superseded_keys ) );
	}

	/**
	 * Time window where repeated saves are treated as one human refinement.
	 */
	private static function reviewer_style_supersede_window_seconds(): int {
		$default = 30 * MINUTE_IN_SECONDS;
		$value   = apply_filters( 'devenia_ai_translations_reviewer_style_supersede_window_seconds', $default );

		return max( 0, min( DAY_IN_SECONDS, absint( $value ) ) );
	}

	/**
	 * Decide whether a later edit is a refinement of an earlier learning item.
	 */
	private static function reviewer_style_events_overlap( array $event, string $replacement_before, string $replacement_after ): bool {
		$candidates = array(
			(string) ( $event['source_text'] ?? '' ),
			(string) ( $event['target_text'] ?? '' ),
		);
		$replacements = array(
			$replacement_before,
			$replacement_after,
		);

		foreach ( $candidates as $candidate ) {
			foreach ( $replacements as $replacement ) {
				if ( self::review_text_has_meaningful_overlap( $candidate, $replacement ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check text overlap while avoiding unrelated edits on the same long page.
	 */
	private static function review_text_has_meaningful_overlap( string $a, string $b ): bool {
		$a = self::review_text_comparison_key( $a );
		$b = self::review_text_comparison_key( $b );
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( $a === $b ) {
			return true;
		}

		$min_chars = 28;
		if ( strlen( $a ) >= $min_chars && false !== strpos( $b, $a ) ) {
			return true;
		}
		if ( strlen( $b ) >= $min_chars && false !== strpos( $a, $b ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Normalize review text for simple overlap checks.
	 */
	private static function review_text_comparison_key( string $text ): string {
		$text = self::normalize_review_text( wp_strip_all_tags( $text ) );
		$text = trim( $text, ". \t\n\r\0\x0B" );
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $text, 'UTF-8' );
		}

		return strtolower( $text );
	}

	/**
	 * Remove superseded examples from an in-memory reviewer-style profile.
	 *
	 * @param array<string,mixed> $profile   Reviewer profile.
	 * @param array<int,string>   $event_keys Superseded event/example IDs.
	 * @return array<string,mixed>
	 */
	private static function remove_reviewer_style_examples( array $profile, array $event_keys ): array {
		$event_keys = array_filter( array_map( 'sanitize_key', $event_keys ) );
		if ( empty( $event_keys ) || empty( $profile['examples'] ) || ! is_array( $profile['examples'] ) ) {
			return $profile;
		}

		$profile['examples'] = array_values(
			array_filter(
				$profile['examples'],
				static function ( $example ) use ( $event_keys ): bool {
					return ! is_array( $example ) || ! in_array( sanitize_key( (string) ( $example['id'] ?? '' ) ), $event_keys, true );
				}
			)
		);
		$profile['edit_count'] = count( $profile['examples'] );

		return $profile;
	}

	/**
	 * Get one reviewer profile for a language.
	 *
	 * @return array<string,mixed>
	 */
	private static function reviewer_style_profile( string $language, string $reviewer ): array {
		$language = sanitize_key( $language );
		$key      = self::reviewer_style_key( $reviewer );
		if ( '' === $language || '' === $key ) {
			return array();
		}

		$profiles = self::reviewer_style_profiles_option();
		return isset( $profiles[ $language ][ $key ] ) && is_array( $profiles[ $language ][ $key ] )
			? $profiles[ $language ][ $key ]
			: array();
	}

	/**
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	private static function reviewer_style_profiles_option(): array {
		$raw = get_option( self::OPTION_REVIEWER_STYLE_PROFILES, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $language => $profiles ) {
			$language = sanitize_key( (string) $language );
			if ( '' === $language || ! self::is_configured_content_language( $language ) || ! is_array( $profiles ) ) {
				continue;
			}
			foreach ( $profiles as $key => $profile ) {
				$key = sanitize_key( (string) $key );
				if ( '' === $key || ! is_array( $profile ) ) {
					continue;
				}
				$clean = self::sanitize_reviewer_style_profile( array_merge( $profile, array( 'language' => $language, 'reviewer_key' => $key ) ) );
				if ( $clean ) {
					$out[ $language ][ $key ] = $clean;
				}
			}
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $current Current profile.
	 * @param array<string,mixed> $patch Incoming profile patch.
	 * @return array<string,mixed>
	 */
	private static function merge_reviewer_style_profile( array $current, array $patch ): array {
		$current = self::sanitize_reviewer_style_profile( $current );
		$patch   = self::sanitize_reviewer_style_profile( $patch );
		$profile = array_merge( $current, array_filter( $patch, static function ( $value ): bool {
			return ! is_array( $value ) && '' !== (string) $value;
		} ) );

		$profile['language']     = sanitize_key( (string) ( $patch['language'] ?? ( $current['language'] ?? '' ) ) );
		$profile['reviewer']     = sanitize_text_field( (string) ( $patch['reviewer'] ?? ( $current['reviewer'] ?? '' ) ) );
		$profile['reviewer_key'] = self::reviewer_style_key( (string) ( $profile['reviewer'] ?? ( $patch['reviewer_key'] ?? '' ) ) );
		$profile['updated_at']   = sanitize_text_field( (string) ( $patch['updated_at'] ?? gmdate( 'c' ) ) );
		$profile['principles']   = self::unique_source_qa_terms( array_merge( $current['principles'] ?? array(), $patch['principles'] ?? array() ) );
		$profile['avoid_terms']  = self::unique_source_qa_terms( array_merge( $current['avoid_terms'] ?? array(), $patch['avoid_terms'] ?? array() ) );
		$profile['preferred_terms'] = self::merge_reviewer_preferred_terms( $current['preferred_terms'] ?? array(), $patch['preferred_terms'] ?? array() );

		$examples = array_merge( $patch['examples'] ?? array(), $current['examples'] ?? array() );
		$profile['examples'] = array_slice( self::sanitize_reviewer_style_examples( $examples ), 0, 30 );
		$profile['edit_count'] = absint( $current['edit_count'] ?? 0 ) + count( $patch['examples'] ?? array() );

		return self::compact_quality_profile( $profile );
	}

	/**
	 * @param mixed $raw Raw profile.
	 * @return array<string,mixed>
	 */
	private static function sanitize_reviewer_style_profile( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$reviewer = sanitize_text_field( (string) ( $raw['reviewer'] ?? '' ) );
		$key      = self::reviewer_style_key( (string) ( $raw['reviewer_key'] ?? $reviewer ) );
		$language = sanitize_key( (string) ( $raw['language'] ?? '' ) );
		if ( '' === $language || '' === $key ) {
			return array();
		}

		$out = array(
			'language'        => $language,
			'reviewer'        => '' !== $reviewer ? $reviewer : $key,
			'reviewer_key'    => $key,
			'updated_at'      => sanitize_text_field( (string) ( $raw['updated_at'] ?? '' ) ),
			'edit_count'      => absint( $raw['edit_count'] ?? 0 ),
			'principles'      => self::sanitize_quality_profile_string_list( $raw['principles'] ?? array() ),
			'preferred_terms' => self::sanitize_reviewer_preferred_terms( $raw['preferred_terms'] ?? array() ),
			'avoid_terms'     => self::sanitize_quality_profile_string_list( $raw['avoid_terms'] ?? array() ),
			'examples'        => array_slice( self::sanitize_reviewer_style_examples( $raw['examples'] ?? array() ), 0, 30 ),
		);

		return self::compact_quality_profile( $out );
	}

	/**
	 * @param mixed $raw Raw examples.
	 * @return array<int,array<string,mixed>>
	 */
	private static function sanitize_reviewer_style_examples( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$examples = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $item['id'] ?? '' ) );
			if ( '' === $id ) {
				$id = substr( hash( 'sha256', wp_json_encode( $item ) ?: '' ), 0, 16 );
			}
			$row = array(
				'id'             => $id,
				'source_id'      => absint( $item['source_id'] ?? 0 ),
				'translation_id' => absint( $item['translation_id'] ?? 0 ),
				'before'         => self::copy_brief_excerpt( self::normalize_review_text( wp_strip_all_tags( (string) ( $item['before'] ?? '' ) ) ), 700 ),
				'after'          => self::copy_brief_excerpt( self::normalize_review_text( wp_strip_all_tags( (string) ( $item['after'] ?? '' ) ) ), 700 ),
				'lesson'         => self::copy_brief_excerpt( self::normalize_review_text( wp_strip_all_tags( (string) ( $item['lesson'] ?? '' ) ) ), 500 ),
				'category'       => sanitize_key( (string) ( $item['category'] ?? 'other' ) ),
				'recorded_at'    => sanitize_text_field( (string) ( $item['recorded_at'] ?? '' ) ),
			);
			if ( '' === $row['before'] && '' === $row['after'] && '' === $row['lesson'] ) {
				continue;
			}
			$examples[ $id ] = self::compact_quality_profile( $row );
		}

		return array_values( $examples );
	}

	/**
	 * @param mixed $raw Raw preferred term map.
	 * @return array<string,array<int,string>>
	 */
	private static function sanitize_reviewer_preferred_terms( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $source => $values ) {
			$source = self::normalize_review_text( wp_strip_all_tags( (string) $source ) );
			if ( '' === $source ) {
				continue;
			}
			$list = is_array( $values ) ? $values : array( $values );
			$list = self::sanitize_quality_profile_string_list( $list );
			if ( $list ) {
				$out[ $source ] = $list;
			}
		}

		return $out;
	}

	/**
	 * @param mixed $current Existing preferred terms.
	 * @param mixed $patch Incoming preferred terms.
	 * @return array<string,array<int,string>>
	 */
	private static function merge_reviewer_preferred_terms( $current, $patch ): array {
		$current = self::sanitize_reviewer_preferred_terms( $current );
		$patch   = self::sanitize_reviewer_preferred_terms( $patch );
		foreach ( $patch as $source => $values ) {
			$current[ $source ] = self::unique_source_qa_terms( array_merge( $current[ $source ] ?? array(), $values ) );
		}

		return $current;
	}

	/**
	 * Compact reviewer profile into direct instructions for agency-copy briefs.
	 *
	 * @param array<string,mixed> $profile Reviewer style profile.
	 * @return array<int,string>
	 */
	private static function reviewer_style_instructions( array $profile ): array {
		$reviewer = sanitize_text_field( (string) ( $profile['reviewer'] ?? '' ) );
		$language = sanitize_key( (string) ( $profile['language'] ?? '' ) );
		$instructions = array();
		if ( '' !== $reviewer && '' !== $language ) {
			$instructions[] = 'Follow the approved ' . $language . ' style preferences learned from ' . $reviewer . '.';
		}
		foreach ( $profile['principles'] ?? array() as $principle ) {
			$instructions[] = 'Principle: ' . $principle;
		}
		foreach ( $profile['avoid_terms'] ?? array() as $term ) {
			$instructions[] = 'Avoid: ' . $term;
		}
		foreach ( $profile['preferred_terms'] ?? array() as $source => $values ) {
			$instructions[] = 'Prefer for "' . $source . '": ' . implode( ', ', (array) $values );
		}
		foreach ( array_slice( $profile['examples'] ?? array(), 0, 5 ) as $example ) {
			$lesson = (string) ( $example['lesson'] ?? '' );
			$after  = (string) ( $example['after'] ?? '' );
			if ( '' !== $lesson ) {
				$instructions[] = 'Example lesson: ' . $lesson;
			}
			if ( '' !== $after ) {
				$instructions[] = 'Approved phrasing: ' . $after;
			}
		}

		return self::unique_source_qa_terms( $instructions );
	}

	/**
	 * Stable storage key for one reviewer.
	 */
	private static function reviewer_style_key( string $reviewer ): string {
		$key = sanitize_key( sanitize_title( $reviewer ) );
		if ( '' === $key && '' !== trim( $reviewer ) ) {
			$key = 'reviewer-' . substr( hash( 'sha256', $reviewer ), 0, 10 );
		}

		return $key;
	}

	/**
	 * Whether a code is one of the source or target content languages.
	 */
	private static function is_configured_content_language( string $language ): bool {
		$language = sanitize_key( $language );
		$languages = self::languages();
		return isset( $languages[ $language ] ) || self::source_language_code() === $language;
	}

	/**
	 * Resolve which language profile review-oriented operations should use.
	 *
	 * Source posts/pages normally do not carry translation language metadata. When
	 * no target language is requested, review the source as the configured source
	 * language. When a target language is requested for a source, review against
	 * that locale's profile before translation.
	 *
	 * @return array<string,mixed>
	 */
	private static function review_language_context_for_post( WP_Post $post, string $requested_language = '' ): array {
		$source_language = self::source_language_code();
		$requested       = sanitize_key( $requested_language );
		$is_translation  = self::is_translation_post( (int) $post->ID );
		$source_id       = $is_translation ? absint( get_post_meta( (int) $post->ID, self::META_SOURCE_ID, true ) ) : (int) $post->ID;
		$translation_id  = $is_translation ? (int) $post->ID : 0;
		$target          = $is_translation ? sanitize_key( (string) get_post_meta( (int) $post->ID, self::META_LANGUAGE, true ) ) : ( '' !== $requested ? $requested : $source_language );

		return array(
			'mode'               => $is_translation ? 'translation' : ( $target === $source_language ? 'source' : 'target' ),
			'resolved_from'      => $is_translation ? 'translation_meta' : ( '' !== $requested ? 'input_language' : 'configured_source_language' ),
			'requested_language' => $requested,
			'source_language'    => $source_language,
			'target_language'    => $target,
			'content_id'         => (int) $post->ID,
			'source_id'          => $source_id,
			'translation_id'     => $translation_id,
			'is_source'          => ! $is_translation,
			'is_translation'     => $is_translation,
		);
	}

	/**
	 * Stored copy feedback for a page/post.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function copy_feedback_for_post( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_COPY_FEEDBACK, true );
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}

		return self::sanitize_copy_feedback_items( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Open copy feedback items that should keep quality review stale.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function open_copy_feedback_for_post( int $post_id ): array {
		return array_values(
			array_filter(
				self::copy_feedback_for_post( $post_id ),
				static function ( array $item ): bool {
					return 'open' === (string) ( $item['status'] ?? '' ) && in_array( (string) ( $item['severity'] ?? '' ), array( 'needs_work', 'blocking' ), true );
				}
			)
		);
	}

	/**
	 * @param mixed $raw Raw feedback rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function sanitize_copy_feedback_items( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$items = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id       = sanitize_key( (string) ( $item['id'] ?? '' ) );
			$feedback = self::normalize_review_text( wp_strip_all_tags( (string) ( $item['feedback'] ?? '' ) ) );
			if ( '' === $id || '' === $feedback ) {
				continue;
			}
			$status   = sanitize_key( (string) ( $item['status'] ?? 'open' ) );
			$severity = sanitize_key( (string) ( $item['severity'] ?? 'needs_work' ) );
			$row      = array(
				'id'          => $id,
				'status'      => in_array( $status, array( 'open', 'resolved' ), true ) ? $status : 'open',
				'severity'    => in_array( $severity, array( 'info', 'needs_work', 'blocking' ), true ) ? $severity : 'needs_work',
				'reviewer'    => sanitize_text_field( (string) ( $item['reviewer'] ?? '' ) ),
				'feedback'    => $feedback,
				'recorded_at' => sanitize_text_field( (string) ( $item['recorded_at'] ?? '' ) ),
				'updated_at'  => sanitize_text_field( (string) ( $item['updated_at'] ?? '' ) ),
				'language'    => sanitize_key( (string) ( $item['language'] ?? '' ) ),
				'content_hash'=> sanitize_text_field( (string) ( $item['content_hash'] ?? '' ) ),
				'source_hash' => sanitize_text_field( (string) ( $item['source_hash'] ?? '' ) ),
			);
			$note = self::normalize_review_text( wp_strip_all_tags( (string) ( $item['note'] ?? '' ) ) );
			if ( '' !== $note ) {
				$row['note'] = $note;
			}
			$items[ $id ] = self::compact_quality_profile( $row );
		}

		return array_values( $items );
	}

	/**
	 * Packaged seed/default profile for one language.
	 */
	private static function packaged_language_review_profile( string $language ): array {
		$language = sanitize_key( $language );
		if ( '' === $language ) {
			return array();
		}

		$registry = self::local_language_registry( array( $language ) );
		return isset( $registry[ $language ]['language_profile'] ) && is_array( $registry[ $language ]['language_profile'] )
			? $registry[ $language ]['language_profile']
			: array();
	}

	/**
	 * Packaged QA rules for one language from the language-quality registry.
	 */
	private static function packaged_language_quality_rules( string $language ): array {
		$language = sanitize_key( $language );
		if ( '' === $language ) {
			return array();
		}

		$registry = self::language_quality_rule_registry();
		return isset( $registry[ $language ] ) && is_array( $registry[ $language ] )
			? self::compact_quality_profile( $registry[ $language ] )
			: array();
	}

	/**
	 * Load packaged language-quality rules.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function language_quality_rule_registry(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$file = plugin_dir_path( __FILE__ ) . 'quality-rules/language-quality.json';
		if ( ! is_readable( $file ) ) {
			$cache = array();
			return $cache;
		}

		$decoded = json_decode( (string) file_get_contents( $file ), true );
		$cache = isset( $decoded['languages'] ) && is_array( $decoded['languages'] ) ? $decoded['languages'] : array();
		return $cache;
	}

	/**
	 * Runtime profile override for one language from WordPress options.
	 */
	private static function runtime_language_review_profile( string $language ): array {
		$language = sanitize_key( $language );
		$languages = get_option( self::OPTION_LANGUAGES );
		if ( '' === $language || ! is_array( $languages ) ) {
			return array();
		}

		return isset( $languages[ $language ]['language_profile'] ) && is_array( $languages[ $language ]['language_profile'] )
			? self::compact_quality_profile( $languages[ $language ]['language_profile'] )
			: array();
	}

	/**
	 * Effective profile used by QA after packaged and runtime data are merged.
	 */
	private static function effective_language_review_profile( string $language ): array {
		$language = sanitize_key( $language );
		if ( '' === $language ) {
			return array();
		}

		return self::merge_quality_profile_patch(
			self::merge_quality_profile_patch(
				self::merge_quality_profile_patch(
					self::compact_quality_profile( self::packaged_language_review_profile( $language ) ),
					self::packaged_language_quality_rules( $language )
				),
				self::runtime_language_review_profile( $language )
			),
			self::learned_language_rule_profile( $language )
		);
	}

	/**
	 * @param mixed $raw Raw profile field list.
	 * @return array<int,string>
	 */
	private static function sanitize_quality_profile_field_list( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$allowed = array_fill_keys( self::quality_profile_runtime_fields(), true );
		$fields  = array();
		foreach ( $raw as $field ) {
			$field = sanitize_key( (string) $field );
			if ( isset( $allowed[ $field ] ) ) {
				$fields[] = $field;
			}
		}

		return array_values( array_unique( $fields ) );
	}

	/**
	 * @return array<int,string>
	 */
	private static function quality_profile_runtime_fields(): array {
		return array(
			'review_language',
			'tone',
			'formality',
			'locale_guidance',
			'preserve_terms',
			'never_translate_terms',
			'source_carryover_homographs',
			'localized_terms',
			'review_patterns',
			'naturalness_patterns',
			'agency_copy',
			'script_signals',
			'frontend_replacements',
		);
	}

	/**
	 * @param mixed $raw Raw profile object.
	 * @return array<string,mixed>
	 */
	private static function sanitize_quality_profile_patch( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( array( 'review_language', 'tone', 'formality', 'locale_guidance' ) as $field ) {
			if ( ! array_key_exists( $field, $raw ) ) {
				continue;
			}
			$value = self::normalize_review_text( wp_strip_all_tags( (string) $raw[ $field ] ) );
			if ( '' !== $value ) {
				$out[ $field ] = $value;
			}
		}

		foreach ( array( 'preserve_terms', 'never_translate_terms', 'source_carryover_homographs', 'review_patterns' ) as $field ) {
			if ( array_key_exists( $field, $raw ) ) {
				$out[ $field ] = self::sanitize_quality_profile_string_list( $raw[ $field ] );
			}
		}

		if ( array_key_exists( 'localized_terms', $raw ) ) {
			$out['localized_terms'] = self::sanitize_quality_profile_localized_terms( $raw['localized_terms'] );
		}

		if ( array_key_exists( 'naturalness_patterns', $raw ) ) {
			$out['naturalness_patterns'] = self::sanitize_quality_profile_naturalness_patterns( $raw['naturalness_patterns'] );
		}

		if ( array_key_exists( 'agency_copy', $raw ) ) {
			$agency_copy = self::sanitize_quality_profile_agency_copy( $raw['agency_copy'] );
			if ( $agency_copy ) {
				$out['agency_copy'] = $agency_copy;
			}
		}

		if ( array_key_exists( 'frontend_replacements', $raw ) ) {
			$out['frontend_replacements'] = self::sanitize_quality_profile_string_map( $raw['frontend_replacements'] );
		}

		if ( array_key_exists( 'script_signals', $raw ) ) {
			$signals = self::sanitize_quality_profile_script_signals( $raw['script_signals'] );
			if ( $signals ) {
				$out['script_signals'] = $signals;
			}
		}

		return $out;
	}

	/**
	 * @param mixed $raw Raw list.
	 * @return array<int,string>
	 */
	private static function sanitize_quality_profile_string_list( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$values = array();
		foreach ( $raw as $value ) {
			$value = self::normalize_review_text( wp_strip_all_tags( (string) $value ) );
			if ( '' !== $value ) {
				$values[] = $value;
			}
		}

		return self::unique_source_qa_terms( $values );
	}

	/**
	 * @param mixed $raw Raw naturalness pattern list.
	 * @return array<int,array<string,mixed>>
	 */
	private static function sanitize_quality_profile_naturalness_patterns( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$patterns = array();
		foreach ( $raw as $item ) {
			if ( is_string( $item ) ) {
				$item = array( 'target' => $item );
			}
			if ( ! is_array( $item ) ) {
				continue;
			}

			$target       = self::normalize_review_text( wp_strip_all_tags( (string) ( $item['target'] ?? '' ) ) );
			$target_regex = self::sanitize_review_regex_pattern( (string) ( $item['target_regex'] ?? '' ) );
			if ( '' === $target && '' === $target_regex ) {
				continue;
			}

			$source       = self::normalize_review_text( wp_strip_all_tags( (string) ( $item['source'] ?? '' ) ) );
			$source_regex = self::sanitize_review_regex_pattern( (string) ( $item['source_regex'] ?? '' ) );
			$id           = sanitize_key( (string) ( $item['id'] ?? '' ) );
			if ( '' === $id ) {
				$id = substr( hash( 'sha256', strtolower( $source . '|' . $source_regex . '|' . $target . '|' . $target_regex ) ), 0, 16 );
			}

			$row = array(
				'id' => $id,
			);
			if ( '' !== $target ) {
				$row['target'] = $target;
			}
			if ( '' !== $target_regex ) {
				$row['target_regex'] = $target_regex;
			}
			if ( '' !== $source ) {
				$row['source'] = $source;
			}
			if ( '' !== $source_regex ) {
				$row['source_regex'] = $source_regex;
			}

			$message = self::normalize_review_text( wp_strip_all_tags( (string) ( $item['message'] ?? '' ) ) );
			if ( '' !== $message ) {
				$row['message'] = $message;
			}

			$suggestions = self::sanitize_quality_profile_string_list( $item['suggestions'] ?? array() );
			if ( $suggestions ) {
				$row['suggestions'] = $suggestions;
			}

			$patterns[ $row['id'] . ':' . strtolower( $source . '|' . $source_regex . '|' . $target . '|' . $target_regex ) ] = $row;
		}

		return array_values( $patterns );
	}

	/**
	 * Sanitize a user-supplied regex fragment for review matching.
	 */
	private static function sanitize_review_regex_pattern( string $pattern ): string {
		$pattern = trim( wp_strip_all_tags( $pattern ) );
		if ( '' === $pattern || strlen( $pattern ) > 240 ) {
			return '';
		}

		$regex = '/' . str_replace( '/', '\/', $pattern ) . '/iu';
		return false === @preg_match( $regex, '' ) ? '' : $pattern;
	}

	/**
	 * Sanitize agency-level copy review profile data.
	 *
	 * @param mixed $raw Raw agency-copy profile object.
	 * @return array<string,mixed>
	 */
	private static function sanitize_quality_profile_agency_copy( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		if ( array_key_exists( 'enabled', $raw ) ) {
			$out['enabled'] = (bool) $raw['enabled'];
		}

		foreach ( array( 'reader', 'buyer_stage', 'promise', 'proof', 'action', 'clarity_standard' ) as $field ) {
			if ( ! array_key_exists( $field, $raw ) ) {
				continue;
			}
			$value = self::normalize_review_text( wp_strip_all_tags( (string) $raw[ $field ] ) );
			if ( '' !== $value ) {
				$out[ $field ] = $value;
			}
		}

		foreach ( array( 'jargon_terms', 'review_questions', 'rewrite_principles' ) as $field ) {
			if ( array_key_exists( $field, $raw ) ) {
				$out[ $field ] = self::sanitize_quality_profile_string_list( $raw[ $field ] );
			}
		}

		return self::compact_quality_profile( $out );
	}

	/**
	 * @param mixed $raw Raw localized term map.
		 * @return array<string,array<int,string>>
		 */
	private static function sanitize_quality_profile_localized_terms( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $source => $expected_terms ) {
			$source = self::normalize_review_text( wp_strip_all_tags( (string) $source ) );
			if ( '' === $source ) {
				continue;
			}
			$out[ $source ] = self::sanitize_quality_profile_string_list(
				is_array( $expected_terms ) ? $expected_terms : array( $expected_terms )
			);
		}

		return $out;
	}

	/**
	 * @param mixed $raw Raw string map.
	 * @return array<string,string>
	 */
	private static function sanitize_quality_profile_string_map( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $source => $translated ) {
			$source     = self::normalize_review_text( wp_strip_all_tags( (string) $source ) );
			$translated = trim( wp_kses_post( (string) $translated ) );
			if ( '' === $source ) {
				continue;
			}
			$out[ $source ] = $translated;
		}

		return $out;
	}

	/**
	 * @param mixed $raw Raw script signal object.
	 * @return array<string,mixed>
	 */
	private static function sanitize_quality_profile_script_signals( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( array( 'required_characters', 'shadow_terms', 'shadow_exclusions' ) as $field ) {
			if ( array_key_exists( $field, $raw ) ) {
				$out[ $field ] = self::sanitize_quality_profile_string_list( $raw[ $field ] );
			}
		}
		if ( array_key_exists( 'shadow_context_exclusions', $raw ) ) {
			$out['shadow_context_exclusions'] = self::sanitize_quality_profile_shadow_context_exclusions( $raw['shadow_context_exclusions'] );
		}
		if ( array_key_exists( 'shadow_term_exclusions', $raw ) ) {
			$out['shadow_exclusions'] = self::sanitize_quality_profile_string_list( $raw['shadow_term_exclusions'] );
		}
		foreach ( array( 'required_pattern', 'policy' ) as $field ) {
			if ( array_key_exists( $field, $raw ) ) {
				$value = trim( wp_strip_all_tags( (string) $raw[ $field ] ) );
				if ( '' !== $value ) {
					$out[ $field ] = $value;
				}
			}
		}
		foreach ( array( 'minimum_letters', 'minimum_matches' ) as $field ) {
			if ( array_key_exists( $field, $raw ) ) {
				$out[ $field ] = max( 0, absint( $raw[ $field ] ) );
			}
		}
		if ( array_key_exists( 'minimum_matches_per_1000_letters', $raw ) ) {
			$out['minimum_matches_per_1000_letters'] = max( 0.0, (float) $raw['minimum_matches_per_1000_letters'] );
		}
		if ( array_key_exists( 'infer_text_shadow_terms', $raw ) ) {
			$out['infer_text_shadow_terms'] = (bool) $raw['infer_text_shadow_terms'];
		}

		return self::compact_quality_profile( $out );
	}

	/**
	 * @param mixed $raw Raw context exclusion rows.
	 * @return array<int,array<string,string>>
	 */
	private static function sanitize_quality_profile_shadow_context_exclusions( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$term = isset( $row['term'] ) ? self::normalize_review_text( (string) $row['term'] ) : '';
			$shadow = isset( $row['shadow'] ) ? self::normalize_review_text( (string) $row['shadow'] ) : '';
			$pattern = isset( $row['pattern'] ) ? trim( wp_strip_all_tags( (string) $row['pattern'] ) ) : '';
			if ( '' === $pattern ) {
				continue;
			}

			$entry = array(
				'pattern' => $pattern,
			);
			if ( '' !== $term ) {
				$entry['term'] = $term;
			}
			if ( '' !== $shadow ) {
				$entry['shadow'] = $shadow;
			}
			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * Runtime-only script-signal settings.
	 *
	 * These are editorial/operational decisions that must be stored as audited
	 * rule events or runtime options, never as packaged language registry data.
	 *
	 * @return array<int,string>
	 */
	private static function runtime_script_signal_option_keys(): array {
		return array(
			'infer_text_shadow_terms',
			'shadow_context_exclusions',
		);
	}

	/**
	 * @param array<string,mixed> $current Current runtime profile.
	 * @param array<string,mixed> $patch Sanitized patch.
	 * @return array<string,mixed>
	 */
	private static function merge_quality_profile_patch( array $current, array $patch ): array {
		$next = $current;
		foreach ( $patch as $field => $value ) {
			if ( in_array( $field, array( 'preserve_terms', 'never_translate_terms', 'review_patterns' ), true ) ) {
				$existing       = isset( $next[ $field ] ) && is_array( $next[ $field ] ) ? $next[ $field ] : array();
				$next[ $field ] = self::unique_source_qa_terms( array_merge( $existing, is_array( $value ) ? $value : array() ) );
				continue;
			}

			if ( 'naturalness_patterns' === $field && is_array( $value ) ) {
				$existing       = isset( $next[ $field ] ) && is_array( $next[ $field ] ) ? $next[ $field ] : array();
				$next[ $field ] = self::merge_quality_profile_naturalness_patterns( $existing, $value );
				continue;
			}

			if ( 'agency_copy' === $field && is_array( $value ) ) {
				$existing       = isset( $next[ $field ] ) && is_array( $next[ $field ] ) ? $next[ $field ] : array();
				$next[ $field ] = self::merge_quality_profile_agency_copy( $existing, $value );
				continue;
			}

			if ( 'localized_terms' === $field && is_array( $value ) ) {
				$existing = isset( $next[ $field ] ) && is_array( $next[ $field ] ) ? $next[ $field ] : array();
				foreach ( $value as $source => $expected_terms ) {
					if ( empty( $expected_terms ) ) {
						unset( $existing[ $source ] );
					} else {
						$existing[ $source ] = $expected_terms;
					}
				}
				$next[ $field ] = $existing;
				continue;
			}

			if ( 'frontend_replacements' === $field && is_array( $value ) ) {
				$existing = isset( $next[ $field ] ) && is_array( $next[ $field ] ) ? $next[ $field ] : array();
				foreach ( $value as $source => $translated ) {
					if ( '' === trim( (string) $translated ) ) {
						unset( $existing[ $source ] );
					} else {
						$existing[ $source ] = $translated;
					}
				}
				$next[ $field ] = $existing;
				continue;
			}

			if ( 'script_signals' === $field && is_array( $value ) ) {
				$existing = isset( $next[ $field ] ) && is_array( $next[ $field ] ) ? $next[ $field ] : array();
				foreach ( $value as $signal_key => $signal_value ) {
					if ( in_array( $signal_key, array( 'required_characters', 'shadow_terms', 'shadow_exclusions' ), true ) ) {
						$current_values = isset( $existing[ $signal_key ] ) && is_array( $existing[ $signal_key ] ) ? $existing[ $signal_key ] : array();
						$existing[ $signal_key ] = self::unique_source_qa_terms( array_merge( $current_values, is_array( $signal_value ) ? $signal_value : array() ) );
					} elseif ( 'shadow_context_exclusions' === $signal_key ) {
						$current_values = isset( $existing[ $signal_key ] ) && is_array( $existing[ $signal_key ] ) ? $existing[ $signal_key ] : array();
						$existing[ $signal_key ] = array_merge( $current_values, is_array( $signal_value ) ? $signal_value : array() );
					} else {
						$existing[ $signal_key ] = $signal_value;
					}
				}
				$next[ $field ] = $existing;
				continue;
			}

			$next[ $field ] = $value;
		}

		return self::compact_quality_profile( $next );
	}

	/**
	 * @param array<int,array<string,mixed>> $existing Existing pattern rows.
	 * @param array<int,array<string,mixed>> $patch New pattern rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function merge_quality_profile_naturalness_patterns( array $existing, array $patch ): array {
		$rows = array();
		foreach ( array_merge( $existing, $patch ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$target       = self::normalize_review_text( wp_strip_all_tags( (string) ( $row['target'] ?? '' ) ) );
			$target_regex = self::sanitize_review_regex_pattern( (string) ( $row['target_regex'] ?? '' ) );
			if ( '' === $target && '' === $target_regex ) {
				continue;
			}
			$source       = self::normalize_review_text( wp_strip_all_tags( (string) ( $row['source'] ?? '' ) ) );
			$source_regex = self::sanitize_review_regex_pattern( (string) ( $row['source_regex'] ?? '' ) );
			$id           = sanitize_key( (string) ( $row['id'] ?? '' ) );
			if ( '' === $id ) {
				$id = substr( hash( 'sha256', strtolower( $source . '|' . $source_regex . '|' . $target . '|' . $target_regex ) ), 0, 16 );
			}
			$row['id'] = $id;
			if ( '' !== $target ) {
				$row['target'] = $target;
			} else {
				unset( $row['target'] );
			}
			if ( '' !== $target_regex ) {
				$row['target_regex'] = $target_regex;
			} else {
				unset( $row['target_regex'] );
			}
			if ( '' !== $source ) {
				$row['source'] = $source;
			} else {
				unset( $row['source'] );
			}
			if ( '' !== $source_regex ) {
				$row['source_regex'] = $source_regex;
			} else {
				unset( $row['source_regex'] );
			}
			$rows[ $id . ':' . strtolower( $source . '|' . $source_regex . '|' . $target . '|' . $target_regex ) ] = $row;
		}

		return array_values( $rows );
	}

	/**
	 * Merge agency copy profile rows.
	 *
	 * @param array<string,mixed> $existing Existing agency-copy profile.
	 * @param array<string,mixed> $patch New agency-copy patch.
	 * @return array<string,mixed>
	 */
	private static function merge_quality_profile_agency_copy( array $existing, array $patch ): array {
		$next = $existing;
		foreach ( $patch as $field => $value ) {
			if ( in_array( $field, array( 'jargon_terms', 'review_questions', 'rewrite_principles' ), true ) ) {
				$current       = isset( $next[ $field ] ) && is_array( $next[ $field ] ) ? $next[ $field ] : array();
				$next[ $field ] = self::unique_source_qa_terms( array_merge( $current, is_array( $value ) ? $value : array() ) );
				continue;
			}
			$next[ $field ] = $value;
		}

		return self::compact_quality_profile( $next );
	}

	/**
	 * Remove empty profile fields before storage/response.
	 *
	 * @param array<string,mixed> $profile Profile data.
	 * @return array<string,mixed>
	 */
	private static function compact_quality_profile( array $profile ): array {
		foreach ( $profile as $field => $value ) {
			if ( is_array( $value ) ) {
				$value = self::compact_quality_profile( $value );
				if ( empty( $value ) ) {
					unset( $profile[ $field ] );
				} else {
					$profile[ $field ] = $value;
				}
				continue;
			}
			if ( is_bool( $value ) ) {
				continue;
			}
			if ( '' === trim( (string) $value ) ) {
				unset( $profile[ $field ] );
			}
		}

		return $profile;
	}

	/**
	 * Update language-specific blog category/tag URL path segments in WordPress options.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function update_blog_taxonomy_paths( array $input ): array {
		self::maybe_seed_runtime_language_text_options();

		$language = sanitize_key( (string) ( $input['language'] ?? '' ) );
		if ( '' === $language || ! isset( self::languages()[ $language ] ) ) {
			return self::error( 'Unknown language.' );
		}

		$delete = ! empty( $input['delete'] );
		$blog_path = isset( $input['blog_path'] ) ? trim( sanitize_text_field( (string) $input['blog_path'] ), '/' ) : '';
		$category  = isset( $input['category'] ) ? sanitize_title( (string) $input['category'] ) : '';
		$tag       = isset( $input['tag'] ) ? sanitize_title( (string) $input['tag'] ) : '';
		if ( ! $delete && ( '' === $category || '' === $tag ) ) {
			return self::error( 'Both category and tag path segments are required.' );
		}
		if ( ! $delete && '' === $blog_path ) {
			$blog_path = self::detect_localized_blog_base_path( $language );
		}
		if ( ! $delete && '' === $blog_path ) {
			return self::error( 'Blog path is required for post translation readiness.' );
		}

		if ( ! $delete && self::language_requires_transliterated_urls( $language ) ) {
			foreach ( array( 'blog_path' => $blog_path, 'category' => $category, 'tag' => $tag ) as $key => $segment ) {
				$segments = 'blog_path' === $key ? explode( '/', trim( $segment, '/' ) ) : array( $segment );
				foreach ( $segments as $path_segment ) {
					if ( '' === $path_segment || ! preg_match( '/^[A-Za-z0-9_-]+$/', $path_segment ) ) {
						return self::error( 'Blog taxonomy path segment must be transliterated ASCII for this language: ' . $key );
					}
				}
			}
		}

		$languages = get_option( self::OPTION_LANGUAGES );
		if ( ! is_array( $languages ) ) {
			$languages = array();
		}
		if ( ! isset( $languages[ $language ] ) || ! is_array( $languages[ $language ] ) ) {
			$languages[ $language ] = array();
		}

		if ( $delete ) {
			unset( $languages[ $language ]['blog_taxonomy_paths'] );
			unset( $languages[ $language ]['blog_path'] );
		} else {
			$languages[ $language ]['blog_path'] = $blog_path;
			$languages[ $language ]['blog_taxonomy_paths'] = array(
				'category' => $category,
				'tag'      => $tag,
			);
		}

		update_option( self::OPTION_LANGUAGES, $languages, false );
		self::languages( true );

		return array(
			'success'             => true,
			'language'            => $language,
			'deleted'             => $delete,
			'blog_path'           => $delete ? null : $languages[ $language ]['blog_path'],
			'blog_taxonomy_paths' => $delete ? null : $languages[ $language ]['blog_taxonomy_paths'],
			'message'             => $delete ? 'Blog taxonomy paths removed.' : 'Blog taxonomy paths updated.',
		);
	}

	/**
	 * Runtime language configuration status for operational checks.
	 *
	 * @return array{complete:bool,missing:array<string,array<int,string>>,languages:array<string,array<string,mixed>>}
	 */
	private static function language_configuration_status(): array {
		$languages = self::languages();
		$out       = array();
		$missing   = array();

		foreach ( $languages as $language => $config ) {
			$is_source = ! empty( $config['source'] );
			$blog_path = isset( $config['blog_path'] ) ? trim( sanitize_text_field( (string) $config['blog_path'] ), '/' ) : '';
			$paths     = isset( $config['blog_taxonomy_paths'] ) && is_array( $config['blog_taxonomy_paths'] ) ? $config['blog_taxonomy_paths'] : array();
			$category  = isset( $paths['category'] ) ? sanitize_title( (string) $paths['category'] ) : '';
			$tag       = isset( $paths['tag'] ) ? sanitize_title( (string) $paths['tag'] ) : '';
			$row       = array(
				'is_source'                      => $is_source,
				'post_translation_ready'         => $is_source || ( '' !== $blog_path && '' !== $category && '' !== $tag ),
				'blog_path_configured'           => $is_source || '' !== $blog_path,
				'blog_path'                      => '' !== $blog_path ? $blog_path : null,
				'blog_taxonomy_paths_configured' => $is_source || ( '' !== $category && '' !== $tag ),
				'blog_taxonomy_paths'            => ( '' !== $category || '' !== $tag )
					? array(
						'category' => $category,
						'tag'      => $tag,
					)
					: null,
				'missing'                        => array(),
			);

			if ( ! $is_source && ! $row['blog_path_configured'] ) {
				$row['missing'][] = 'blog_path';
			}
			if ( ! $is_source && ! $row['blog_taxonomy_paths_configured'] ) {
				$row['missing'][] = 'blog_taxonomy_paths';
			}
			if ( ! $is_source && $row['missing'] ) {
				$missing[ $language ] = $row['missing'];
			}

			$out[ $language ] = $row;
		}

		return array(
			'complete'  => empty( $missing ),
			'missing'   => $missing,
			'languages' => $out,
		);
	}

	/**
	 * Required runtime configuration for a language/content pair.
	 *
	 * @return array{success:bool,missing:array<int,string>,language:string,post_type:string,configuration?:array<string,mixed>,message?:string}
	 */
	private static function language_runtime_readiness( string $language, string $post_type ): array {
		$language  = sanitize_key( $language );
		$post_type = sanitize_key( $post_type );
		if ( ! self::is_translation_language( $language ) ) {
			return array(
				'success'   => false,
				'missing'   => array( 'language' ),
				'language'  => $language,
				'post_type' => $post_type,
				'message'   => 'Unknown or source language.',
			);
		}

		$status = self::language_configuration_status();
		$row    = isset( $status['languages'][ $language ] ) && is_array( $status['languages'][ $language ] ) ? $status['languages'][ $language ] : array();
		$missing = array();
		if ( 'post' === $post_type ) {
			if ( empty( $row['blog_path_configured'] ) ) {
				$missing[] = 'blog_path';
			}
			if ( empty( $row['blog_taxonomy_paths_configured'] ) ) {
				$missing[] = 'blog_taxonomy_paths';
			}
		}

		return array(
			'success'       => empty( $missing ),
			'missing'       => $missing,
			'language'      => $language,
			'post_type'     => $post_type,
			'configuration' => $row,
			'message'       => empty( $missing ) ? 'Language runtime configuration is ready.' : 'Language runtime configuration is incomplete for this content type.',
		);
	}

	/**
	 * Runtime readiness for every target language for a content type.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function language_runtime_readiness_map( string $post_type ): array {
		$out = array();
		foreach ( self::languages() as $language => $config ) {
			if ( ! empty( $config['source'] ) ) {
				continue;
			}
			$out[ $language ] = self::language_runtime_readiness( (string) $language, $post_type );
		}
		return $out;
	}

	/**
	 * Update page-specific QA options stored on the source WordPress page.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function update_source_qa_options( array $input ): array {
		$source_id = absint( $input['source_id'] ?? 0 );
		$source    = get_post( $source_id );
		if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) || self::is_translation_post( $source_id ) ) {
			return self::error( 'Source content not found.' );
		}

		$language = sanitize_key( (string) ( $input['language'] ?? 'all' ) );
		if ( '' === $language ) {
			$language = 'all';
		}
		if ( 'all' !== $language && ! self::is_translation_language( $language ) ) {
			return self::error( 'Unknown target language.' );
		}

		$terms        = self::sanitize_source_qa_term_list( $input['terms'] ?? array() );
		$delete_terms = self::sanitize_source_qa_term_list( $input['delete_terms'] ?? array() );
		$replace      = ! empty( $input['replace'] );
		$address_form = self::sanitize_address_form( (string) ( $input['address_form'] ?? '' ) );
		$audience     = self::sanitize_source_qa_audience( (string) ( $input['audience'] ?? '' ) );
		$clear_addressing = ! empty( $input['clear_addressing'] );
		if ( ! $replace && empty( $terms ) && empty( $delete_terms ) && '' === $address_form && '' === $audience && ! $clear_addressing ) {
			return self::error( 'No QA options supplied.' );
		}

		$options = self::source_qa_options( $source_id );
		if ( ! isset( $options['source_carryover_preserve_terms'] ) || ! is_array( $options['source_carryover_preserve_terms'] ) ) {
			$options['source_carryover_preserve_terms'] = array();
		}

		$current = isset( $options['source_carryover_preserve_terms'][ $language ] ) && is_array( $options['source_carryover_preserve_terms'][ $language ] )
			? $options['source_carryover_preserve_terms'][ $language ]
			: array();
		$next    = $replace ? $terms : array_merge( $current, $terms );

		if ( $delete_terms ) {
			$delete_map = array_fill_keys( array_map( array( __CLASS__, 'source_qa_term_key' ), $delete_terms ), true );
			$next       = array_values(
				array_filter(
					$next,
					static function ( string $term ) use ( $delete_map ): bool {
						return empty( $delete_map[ self::source_qa_term_key( $term ) ] );
					}
				)
			);
		}

		$next = self::unique_source_qa_terms( $next );
		if ( $next ) {
			$options['source_carryover_preserve_terms'][ $language ] = $next;
		} else {
			unset( $options['source_carryover_preserve_terms'][ $language ] );
		}

		if ( empty( $options['source_carryover_preserve_terms'] ) ) {
			unset( $options['source_carryover_preserve_terms'] );
		}

		if ( ! isset( $options['addressing'] ) || ! is_array( $options['addressing'] ) ) {
			$options['addressing'] = array();
		}
		if ( $clear_addressing ) {
			unset( $options['addressing'][ $language ] );
		} elseif ( '' !== $address_form || '' !== $audience ) {
			$current_addressing = isset( $options['addressing'][ $language ] ) && is_array( $options['addressing'][ $language ] )
				? $options['addressing'][ $language ]
				: array();
			if ( '' !== $address_form ) {
				$current_addressing['address_form'] = $address_form;
			}
			if ( '' !== $audience ) {
				$current_addressing['audience'] = $audience;
			}
			$options['addressing'][ $language ] = $current_addressing;
		}
		if ( empty( $options['addressing'] ) ) {
			unset( $options['addressing'] );
		}

		if ( empty( $options ) ) {
			delete_post_meta( $source_id, self::META_QA_OPTIONS );
		} else {
			update_post_meta( $source_id, self::META_QA_OPTIONS, wp_json_encode( $options ) );
		}

		return array(
			'success'   => true,
			'message'   => 'Source QA options updated.',
			'source_id' => $source_id,
			'language'  => $language,
			'options'   => $options,
		);
	}

	/**
	 * Load sanitized page-specific QA options from source post meta.
	 *
	 * @return array<string,mixed>
	 */
	private static function source_qa_options( int $source_id ): array {
		$raw = get_post_meta( $source_id, self::META_QA_OPTIONS, true );
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$options = array();
		if ( isset( $raw['source_carryover_preserve_terms'] ) && is_array( $raw['source_carryover_preserve_terms'] ) ) {
			foreach ( $raw['source_carryover_preserve_terms'] as $language => $terms ) {
				$scope = sanitize_key( (string) $language );
				if ( '' === $scope ) {
					continue;
				}
				if ( 'all' !== $scope && ! self::is_translation_language( $scope ) ) {
					continue;
				}
				$list = self::sanitize_source_qa_term_list( $terms );
				if ( $list ) {
					$options['source_carryover_preserve_terms'][ $scope ] = $list;
				}
			}
		}
		if ( isset( $raw['addressing'] ) && is_array( $raw['addressing'] ) ) {
			foreach ( $raw['addressing'] as $language => $addressing ) {
				$scope = sanitize_key( (string) $language );
				if ( '' === $scope ) {
					continue;
				}
				if ( 'all' !== $scope && ! self::is_translation_language( $scope ) ) {
					continue;
				}
				if ( ! is_array( $addressing ) ) {
					continue;
				}
				$address_form = self::sanitize_address_form( (string) ( $addressing['address_form'] ?? '' ) );
				$audience     = self::sanitize_source_qa_audience( (string) ( $addressing['audience'] ?? '' ) );
				if ( '' !== $address_form || '' !== $audience ) {
					$options['addressing'][ $scope ] = array_filter(
						array(
							'address_form' => $address_form,
							'audience'     => $audience,
						),
						static function ( string $value ): bool {
							return '' !== $value;
						}
					);
				}
			}
		}

		return $options;
	}

	/**
	 * Page-specific addressing rules for a source page/language.
	 *
	 * @return array{address_form?:string,audience?:string}
	 */
	private static function source_qa_addressing( int $source_id, string $language ): array {
		if ( ! $source_id ) {
			return array();
		}

		$options = self::source_qa_options( $source_id );
		$scopes  = array( 'all', sanitize_key( $language ) );
		$addressing = array();
		foreach ( $scopes as $scope ) {
			if ( empty( $options['addressing'][ $scope ] ) || ! is_array( $options['addressing'][ $scope ] ) ) {
				continue;
			}
			$addressing = array_merge( $addressing, $options['addressing'][ $scope ] );
		}

		return $addressing;
	}

	/**
	 * Source-language terms that may remain visible for a specific source page.
	 *
	 * @return array<int,string>
	 */
	private static function source_qa_carryover_preserve_terms( int $source_id, string $language ): array {
		if ( ! $source_id ) {
			return array();
		}

		$options = self::source_qa_options( $source_id );
		$terms   = array();
		$scopes  = array( 'all', sanitize_key( $language ) );
		foreach ( $scopes as $scope ) {
			if ( empty( $options['source_carryover_preserve_terms'][ $scope ] ) || ! is_array( $options['source_carryover_preserve_terms'][ $scope ] ) ) {
				continue;
			}
			$terms = array_merge( $terms, $options['source_carryover_preserve_terms'][ $scope ] );
		}

		return self::unique_source_qa_terms( $terms );
	}

	/**
	 * @param mixed $terms Raw terms.
	 * @return array<int,string>
	 */
	private static function sanitize_source_qa_term_list( $terms ): array {
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $terms as $term ) {
			$term = self::normalize_review_text( wp_strip_all_tags( (string) $term ) );
			if ( '' !== $term ) {
				$sanitized[] = $term;
			}
		}

		return self::unique_source_qa_terms( $sanitized );
	}

	/**
	 * @param array<int,string> $terms Terms.
	 * @return array<int,string>
	 */
	private static function unique_source_qa_terms( array $terms ): array {
		$seen = array();
		$out  = array();
		foreach ( $terms as $term ) {
			$term = trim( (string) $term );
			$key  = self::source_qa_term_key( $term );
			if ( '' === $term || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $term;
		}

		return $out;
	}

	private static function source_qa_term_key( string $term ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $term ), 'UTF-8' ) : strtolower( trim( $term ) );
	}

	private static function sanitize_address_form( string $address_form ): string {
		$address_form = sanitize_key( $address_form );
		return in_array( $address_form, array( 'singular', 'plural', 'neutral' ), true ) ? $address_form : '';
	}

	private static function sanitize_source_qa_audience( string $audience ): string {
		$audience = sanitize_key( $audience );
		return in_array( $audience, array( 'individual_operator', 'business_team', 'general_reader' ), true ) ? $audience : '';
	}

	/**
	 * Load packaged local language files.
	 *
	 * @param array<int,string> $language_codes Language codes to load.
	 * @return array<string,array<string,mixed>>
	 */
	private static function local_language_registry( array $language_codes ): array {
		static $cache = array();

		$cache_key = implode( '|', array_map( 'sanitize_key', $language_codes ) );
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$registry = array();

		foreach ( $language_codes as $language ) {
			$file = plugin_dir_path( __FILE__ ) . 'languages/' . sanitize_key( $language ) . '.json';
			if ( ! is_readable( $file ) ) {
				continue;
			}

			$decoded = json_decode( (string) file_get_contents( $file ), true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$registry[ sanitize_key( $language ) ] = $decoded;
		}

		$cache[ $cache_key ] = $registry;

		return $registry;
	}

	/**
	 * Report packaged language file coverage.
	 */
	private static function validate_language_files(): array {
		$status = array();

		foreach ( array_keys( self::default_languages() ) as $language ) {
			$file    = plugin_dir_path( __FILE__ ) . 'languages/' . sanitize_key( $language ) . '.json';
			$exists  = is_readable( $file );
			$decoded = $exists ? json_decode( (string) file_get_contents( $file ), true ) : null;

			$status[ $language ] = array(
				'file'       => 'languages/' . sanitize_key( $language ) . '.json',
				'exists'     => $exists,
				'valid_json' => is_array( $decoded ),
				'has_wordpress_locale' => is_array( $decoded ) && isset( $decoded['wordpress_locale'] ) && is_string( $decoded['wordpress_locale'] ) && '' !== trim( $decoded['wordpress_locale'] ),
				'has_menu'   => is_array( $decoded ) && isset( $decoded['menu_items'] ) && is_array( $decoded['menu_items'] ),
				'has_widget_text' => is_array( $decoded ) && isset( $decoded['widget_text'] ) && is_array( $decoded['widget_text'] ),
				'has_not_found_text' => is_array( $decoded ) && isset( $decoded['not_found_text'] ) && is_array( $decoded['not_found_text'] ),
				'has_not_found_routes' => is_array( $decoded ) && isset( $decoded['not_found_routes'] ) && is_array( $decoded['not_found_routes'] ),
				'has_language_profile' => is_array( $decoded ) && isset( $decoded['language_profile'] ) && is_array( $decoded['language_profile'] ),
				'language_profile_issues' => is_array( $decoded ) ? self::validate_language_profile( $language, $decoded ) : array(),
				'widget_link_issues' => is_array( $decoded ) ? self::validate_widget_text_links( $language, $decoded ) : array(),
				'link_issues' => is_array( $decoded ) ? self::validate_language_file_links( $language, $decoded ) : array(),
			);
		}

		return $status;
	}

	/**
	 * Run the packaged translation-fitness regression corpus.
	 */
	private static function translation_fitness_regression_status( array $input ): array {
		$loaded = self::translation_fitness_regression_cases();
		if ( empty( $loaded['success'] ) ) {
			return $loaded;
		}

		$include_cases = array_key_exists( 'include_cases', $input ) ? (bool) $input['include_cases'] : true;
		$rows          = array();
		$failed        = array();
		$totals        = array(
			'case_count' => 0,
			'passed'     => 0,
			'failed'     => 0,
		);

		foreach ( $loaded['cases'] as $case ) {
			++$totals['case_count'];
			$id       = isset( $case['id'] ) ? sanitize_text_field( (string) $case['id'] ) : 'case-' . $totals['case_count'];
			$language = isset( $case['language'] ) ? sanitize_key( (string) $case['language'] ) : '';
			$content  = isset( $case['content'] ) ? (string) $case['content'] : '';
			$title    = isset( $case['title'] ) ? sanitize_text_field( (string) $case['title'] ) : '';
			$excerpt  = isset( $case['excerpt'] ) ? sanitize_textarea_field( (string) $case['excerpt'] ) : '';
			$source_content = isset( $case['source_content'] ) ? (string) $case['source_content'] : '';
			$expected_passed = array_key_exists( 'expected_passed', $case ) ? (bool) $case['expected_passed'] : false;
			$minimum_issue_count = isset( $case['minimum_issue_count'] ) ? max( 0, absint( $case['minimum_issue_count'] ) ) : ( $expected_passed ? 0 : 1 );
			$expected_issue_codes = self::sanitize_qa_code_list( $case['expected_issue_codes'] ?? array() );
			$profile_patch = self::sanitize_quality_profile_patch( $case['profile_patch'] ?? array() );

			$fitness = array();
			$case_passed = false;
			$messages = array();
			$missing_expected_codes = $expected_issue_codes;

			if ( '' === $language || '' === trim( $content ) ) {
				$messages[] = 'Regression case is missing language or content.';
			} else {
				$fitness = self::translation_fitness( $content, $source_content, $language, $title, $excerpt, 0, $profile_patch );
				$issue_codes = self::qa_item_codes( $fitness['issues'] ?? array() );
				$missing_expected_codes = array_values( array_diff( $expected_issue_codes, $issue_codes ) );
				$actual_passed = ! empty( $fitness['passed'] );
				$actual_issue_count = count( $fitness['issues'] ?? array() );
				$case_passed = $expected_passed === $actual_passed && empty( $missing_expected_codes );
				if ( ! $expected_passed && $actual_issue_count < $minimum_issue_count ) {
					$case_passed = false;
					$messages[] = 'Expected more QA issues for a failing regression case.';
				}
				if ( $missing_expected_codes ) {
					$messages[] = 'Expected issue codes were not produced.';
				}
			}

			if ( $case_passed ) {
				++$totals['passed'];
			} else {
				++$totals['failed'];
				$failed[] = $id;
			}

			if ( $include_cases ) {
				$rows[] = array(
					'id'                           => $id,
					'language'                     => $language,
					'description'                  => isset( $case['description'] ) ? sanitize_text_field( (string) $case['description'] ) : '',
					'passed'                       => $case_passed,
					'expected_passed'              => $expected_passed,
					'expected_issue_codes'         => $expected_issue_codes,
					'minimum_issue_count'          => $minimum_issue_count,
					'actual_passed'                => ! empty( $fitness['passed'] ),
					'actual_issue_count'           => count( $fitness['issues'] ?? array() ),
					'actual_warning_count'         => count( $fitness['warnings'] ?? array() ),
					'actual_issue_codes'           => self::qa_item_codes( $fitness['issues'] ?? array() ),
					'missing_expected_issue_codes' => $missing_expected_codes,
					'messages'                     => $messages,
					'dimensions'                   => $fitness['dimensions'] ?? array(),
				);
			}
		}

		return array(
			'success'        => empty( $failed ),
			'message'        => empty( $failed ) ? 'Translation-fitness regression corpus passed.' : 'Translation-fitness regression corpus failed.',
			'schema_version' => $loaded['schema_version'],
			'file'           => $loaded['file'],
			'totals'         => $totals,
			'failed_cases'   => $failed,
			'cases'          => $include_cases ? $rows : array(),
		);
	}

	/**
	 * Report and optionally prove the full translation lifecycle on temporary posts.
	 */
	private static function translation_lifecycle_regression_status( array $input ): array {
		$language = self::lifecycle_regression_language( $input );
		if ( '' === $language ) {
			return self::error( 'No target translation languages are configured.' );
		}

		$run_write_test = ! empty( $input['run_write_test'] );
		$readiness      = self::language_runtime_readiness( $language, 'post' );
		$fitness_status = self::translation_fitness_regression_status( array( 'include_cases' => false ) );
		$status         = array(
			'success'                => ! empty( $readiness['success'] ) && ! empty( $fitness_status['success'] ),
			'message'                => $run_write_test ? 'Translation lifecycle regression prerequisites checked.' : 'Translation lifecycle regression write test was not run.',
			'plugin_version'         => self::VERSION,
			'language'               => $language,
			'target_languages'       => array_keys( self::target_languages() ),
			'write_test_ran'         => false,
			'lifecycle_proven'       => false,
			'write_test_required'    => true,
			'runtime_readiness'      => $readiness,
			'fitness_regressions'    => $fitness_status,
		);

		if ( ! $run_write_test ) {
			return $status;
		}
		if ( empty( $readiness['success'] ) ) {
			$status['success'] = false;
			$status['message'] = 'Language runtime configuration is incomplete; lifecycle write regression was not run.';
			return $status;
		}
		if ( empty( $fitness_status['success'] ) ) {
			$status['success'] = false;
			$status['message'] = 'Translation-fitness corpus failed; lifecycle write regression was not run.';
			return $status;
		}

		return self::run_translation_lifecycle_regression(
			$language,
			$input,
			array_key_exists( 'verify_live', $input ) ? (bool) $input['verify_live'] : false,
			array_key_exists( 'cleanup', $input ) ? (bool) $input['cleanup'] : true
		);
	}

	/**
	 * Choose the lifecycle regression target language.
	 */
	private static function lifecycle_regression_language( array $input ): string {
		$requested = sanitize_key( (string) ( $input['language'] ?? '' ) );
		if ( '' !== $requested && self::is_translation_language( $requested ) ) {
			return $requested;
		}
		if ( self::is_translation_language( 'sv' ) ) {
			return 'sv';
		}

		$targets = array_keys( self::target_languages() );
		return isset( $targets[0] ) ? sanitize_key( (string) $targets[0] ) : '';
	}

	/**
	 * Run a temporary source/translation lifecycle proof.
	 */
	private static function run_translation_lifecycle_regression( string $language, array $input, bool $verify_live, bool $cleanup ): array {
		$fixture = self::translation_lifecycle_regression_fixture( $language, $input );
		if ( empty( $fixture['success'] ) ) {
			return $fixture;
		}

		$created = array(
			'source_id'      => 0,
			'translation_id' => 0,
		);
		$cleanup_results = array();
		$cleanup_completed = false;
		$checks          = array();
		$failed          = array();
		$suffix          = strtolower( wp_generate_password( 8, false, false ) );
		$source_slug     = 'devenia-lifecycle-regression-' . $suffix;
		$localized_slug  = sanitize_title( (string) $fixture['localized_slug'] . '-' . $suffix );
		$changed_content = self::translation_lifecycle_changed_content( (string) $fixture['content'], (string) $fixture['change_sentence'] );

		try {
			$source_id = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => 'post',
						'post_status'  => 'draft',
						'post_title'   => (string) $fixture['source_title'] . ' ' . $suffix,
						'post_name'    => $source_slug,
						'post_content' => (string) $fixture['source_content'],
						'post_excerpt' => (string) $fixture['source_excerpt'],
					)
				),
				true
			);
			if ( is_wp_error( $source_id ) ) {
				return self::error( $source_id->get_error_message() );
			}
			$created['source_id'] = (int) $source_id;
			wp_set_post_terms( $created['source_id'], array(), 'category', false );
			wp_set_post_terms( $created['source_id'], array(), 'post_tag', false );

			if ( self::language_requires_transliterated_urls( $language ) ) {
				$source_slug_copy = self::upsert_translation(
					array(
						'source_id'          => $created['source_id'],
						'language'           => $language,
						'localized_slug'     => $source_slug,
						'localized_path'     => trim( self::localized_blog_base_path( $language ) . '/' . $source_slug, '/' ),
						'title'              => (string) $fixture['title'] . ' ' . $suffix,
						'content'            => (string) $fixture['content'],
						'excerpt'            => (string) $fixture['excerpt'],
						'status'             => 'draft',
						'translation_status' => 'needs_review',
					)
				);
				self::add_lifecycle_check(
					$checks,
					$failed,
					'transliterated_source_slug_copy_rejected',
					empty( $source_slug_copy['success'] ) && 'localized_slug_copied_from_source' === (string) ( $source_slug_copy['code'] ?? '' ),
					self::lifecycle_compact_upsert_result( $source_slug_copy )
				);
			}

			$upsert = self::upsert_translation(
				array(
					'source_id'          => $created['source_id'],
					'language'           => $language,
					'localized_slug'     => $localized_slug,
					'localized_path'     => trim( self::localized_blog_base_path( $language ) . '/' . $localized_slug, '/' ),
					'title'              => (string) $fixture['title'] . ' ' . $suffix,
					'content'            => (string) $fixture['content'],
					'excerpt'            => (string) $fixture['excerpt'],
					'status'             => 'draft',
					'translation_status' => 'needs_review',
				)
			);
			self::add_lifecycle_check( $checks, $failed, 'translation_created', ! empty( $upsert['success'] ), self::lifecycle_compact_upsert_result( $upsert ) );
			if ( empty( $upsert['success'] ) ) {
				if ( $cleanup ) {
					$cleanup_results   = self::cleanup_translation_lifecycle_regression_posts( $created );
					$cleanup_completed = true;
				}
				return self::translation_lifecycle_regression_result( false, $language, $created, $checks, $failed, $cleanup, $cleanup_results, 'Translation lifecycle regression failed while creating the translation.' );
			}

			$created['translation_id'] = absint( $upsert['translation']['id'] ?? 0 );
			$qa = self::qa_translation( array( 'translation_id' => $created['translation_id'] ) );
			self::add_lifecycle_check( $checks, $failed, 'draft_qa_passed', ! empty( $qa['success'] ) && ! empty( $qa['passed'] ), self::lifecycle_compact_qa_result( $qa ) );

			$publish_without_review = self::publish_translation(
				array(
					'translation_id' => $created['translation_id'],
					'verify_live'    => $verify_live,
					'sync_menu'      => false,
				)
			);
			self::add_lifecycle_check(
				$checks,
				$failed,
				'publish_without_review_blocked',
				empty( $publish_without_review['success'] ) && isset( $publish_without_review['review_state'] ),
				self::lifecycle_compact_publish_result( $publish_without_review )
			);

			$linguistic_review = self::mark_linguistic_reviewed(
				array_merge(
					array(
						'translation_id' => $created['translation_id'],
						'reviewer'       => 'Lifecycle regression',
						'note'           => 'Temporary lifecycle regression proof.',
						'run_qa'         => true,
					),
					self::review_check_input( self::required_linguistic_review_checks( $language ) )
				)
			);
			self::add_lifecycle_check( $checks, $failed, 'linguistic_review_marked', ! empty( $linguistic_review['success'] ), self::lifecycle_compact_review_result( $linguistic_review ) );

			$review_state = self::linguistic_review_state_for_post( $created['translation_id'] );
			self::add_lifecycle_check( $checks, $failed, 'review_current_after_mark', ! empty( $review_state['passed'] ), $review_state );

			$publish = self::publish_translation(
				array(
					'translation_id' => $created['translation_id'],
					'verify_live'    => $verify_live,
					'sync_menu'      => false,
				)
			);
			self::add_lifecycle_check( $checks, $failed, 'publish_after_review_succeeds', ! empty( $publish['success'] ), self::lifecycle_compact_publish_result( $publish ) );

			$quality_review = self::mark_quality_reviewed(
				array_merge(
					array(
						'page_id'  => $created['translation_id'],
						'reviewer' => 'Lifecycle regression',
						'note'     => 'Temporary lifecycle regression proof.',
					),
					self::review_check_input( self::required_quality_review_checks( $language ) )
				)
			);
			self::add_lifecycle_check( $checks, $failed, 'quality_review_marked', ! empty( $quality_review['success'] ), self::lifecycle_compact_review_result( $quality_review ) );

			$update_after_review = self::upsert_translation(
				array(
					'translation_id'       => $created['translation_id'],
					'source_id'            => $created['source_id'],
					'language'             => $language,
					'localized_slug'       => $localized_slug,
					'localized_path'       => trim( self::localized_blog_base_path( $language ) . '/' . $localized_slug, '/' ),
					'title'                => (string) $fixture['title'] . ' ' . $suffix,
					'content'              => $changed_content,
					'excerpt'              => (string) $fixture['excerpt'],
					'status'               => 'publish',
					'translation_status'   => 'published',
					'allow_update_published' => true,
				)
			);
			self::add_lifecycle_check( $checks, $failed, 'review_invalidates_after_content_change', ! empty( $update_after_review['success'] ) && ! empty( $update_after_review['review_invalidated'] ), self::lifecycle_compact_upsert_result( $update_after_review ) );

			$state_after_change = self::linguistic_review_state_for_post( $created['translation_id'] );
			self::add_lifecycle_check( $checks, $failed, 'review_state_stale_after_change', empty( $state_after_change['passed'] ) && in_array( 'missing_linguistic_review', $state_after_change['stale_reasons'] ?? array(), true ), $state_after_change );

			$quality_evidence_after_change = self::quality_review_evidence_for_post( $created['translation_id'] );
			self::add_lifecycle_check( $checks, $failed, 'quality_review_evidence_removed_after_change', empty( $quality_evidence_after_change ), array( 'quality_evidence' => $quality_evidence_after_change ) );

			$publish_after_change = self::publish_translation(
				array(
					'translation_id' => $created['translation_id'],
					'verify_live'    => $verify_live,
					'sync_menu'      => false,
				)
			);
			self::add_lifecycle_check(
				$checks,
				$failed,
				'publish_after_change_blocked',
				empty( $publish_after_change['success'] ) && isset( $publish_after_change['review_state'] ),
				self::lifecycle_compact_publish_result( $publish_after_change )
			);
		} finally {
			if ( $cleanup && ! $cleanup_completed ) {
				$cleanup_results = self::cleanup_translation_lifecycle_regression_posts( $created );
			}
		}

		return self::translation_lifecycle_regression_result( empty( $failed ), $language, $created, $checks, $failed, $cleanup, $cleanup_results );
	}

	/**
	 * Return the built-in fixture or a caller-provided fixture for lifecycle tests.
	 */
	private static function translation_lifecycle_regression_fixture( string $language, array $input ): array {
		$fixture = array(
			'source_title'    => sanitize_text_field( (string) ( $input['source_title'] ?? '' ) ),
			'source_content'  => (string) ( $input['source_content'] ?? '' ),
			'source_excerpt'  => sanitize_textarea_field( (string) ( $input['source_excerpt'] ?? '' ) ),
			'title'           => sanitize_text_field( (string) ( $input['title'] ?? '' ) ),
			'content'         => (string) ( $input['content'] ?? '' ),
			'excerpt'         => sanitize_textarea_field( (string) ( $input['excerpt'] ?? '' ) ),
			'localized_slug'  => sanitize_title( (string) ( $input['localized_slug'] ?? '' ) ),
			'change_sentence' => sanitize_text_field( (string) ( $input['change_sentence'] ?? '' ) ),
		);
		if ( $fixture['source_title'] && trim( $fixture['source_content'] ) && $fixture['title'] && trim( $fixture['content'] ) && $fixture['localized_slug'] ) {
			if ( '' === $fixture['change_sentence'] ) {
				$fixture['change_sentence'] = 'Extra lifecycle evidence sentence.';
			}
			$fixture['success'] = true;
			return $fixture;
		}

		$source_content = '<!-- wp:heading --><h2>Lifecycle proof</h2><!-- /wp:heading -->' . "\n\n" . '<!-- wp:paragraph --><p>This temporary post proves that translation review evidence follows the exact content before publishing.</p><!-- /wp:paragraph -->';
		$defaults = array(
			'nb' => array(
				'title'           => 'Livsløpskontroll',
				'content'         => '<!-- wp:heading --><h2>Livsløpskontroll</h2><!-- /wp:heading -->' . "\n\n" . '<!-- wp:paragraph --><p>Dette midlertidige innlegget viser at oversettelsesgjennomgangen følger det nøyaktige innholdet før publisering.</p><!-- /wp:paragraph -->',
				'excerpt'         => 'Midlertidig test av oversettelsesflyten.',
				'localized_slug'  => 'livslopskontroll',
				'change_sentence' => 'Ekstra kontrollsetning for review-bevis.',
			),
			'de' => array(
				'title'           => 'Lebenszykluspruefung',
				'content'         => '<!-- wp:heading --><h2>Lebenszyklusprüfung</h2><!-- /wp:heading -->' . "\n\n" . '<!-- wp:paragraph --><p>Dieser temporäre Beitrag zeigt, dass die Übersetzungsprüfung dem genauen Inhalt vor der Veröffentlichung folgt.</p><!-- /wp:paragraph -->',
				'excerpt'         => 'Temporärer Test des Übersetzungsablaufs.',
				'localized_slug'  => 'lebenszykluspruefung',
				'change_sentence' => 'Zusätzlicher Kontrollsatz für Review-Nachweise.',
			),
			'fr' => array(
				'title'           => 'Controle du cycle de vie',
				'content'         => '<!-- wp:heading --><h2>Contrôle du cycle de vie</h2><!-- /wp:heading -->' . "\n\n" . '<!-- wp:paragraph --><p>Cet article temporaire montre que la vérification des traductions suit le contenu exact avant publication.</p><!-- /wp:paragraph -->',
				'excerpt'         => 'Test temporaire du flux de traduction.',
				'localized_slug'  => 'controle-du-cycle-de-vie',
				'change_sentence' => 'Phrase de contrôle supplémentaire pour les preuves de revue.',
			),
			'es' => array(
				'title'           => 'Prueba del ciclo de vida',
				'content'         => '<!-- wp:heading --><h2>Prueba del ciclo de vida</h2><!-- /wp:heading -->' . "\n\n" . '<!-- wp:paragraph --><p>Esta entrada temporal demuestra que la revisión de traducciones sigue el contenido exacto antes de la publicación.</p><!-- /wp:paragraph -->',
				'excerpt'         => 'Prueba temporal del flujo de traducción.',
				'localized_slug'  => 'prueba-del-ciclo-de-vida',
				'change_sentence' => 'Frase de control adicional para la evidencia de revisión.',
			),
			'sv' => array(
				'title'           => 'Livscykelkontroll',
				'content'         => '<!-- wp:heading --><h2>Livscykelkontroll</h2><!-- /wp:heading -->' . "\n\n" . '<!-- wp:paragraph --><p>Det här tillfälliga inlägget visar att översättningsgranskningen följer exakt innehåll före publicering.</p><!-- /wp:paragraph -->',
				'excerpt'         => 'Tillfälligt test av översättningsflödet.',
				'localized_slug'  => 'livscykelkontroll',
				'change_sentence' => 'Extra kontrollmening för granskningsbevis.',
			),
			'da' => array(
				'title'           => 'Livscykluskontrol',
				'content'         => '<!-- wp:heading --><h2>Livscykluskontrol</h2><!-- /wp:heading -->' . "\n\n" . '<!-- wp:paragraph --><p>Dette midlertidige indlæg viser, at oversættelsesgennemgangen følger det nøjagtige indhold før udgivelse.</p><!-- /wp:paragraph -->',
				'excerpt'         => 'Midlertidig test af oversættelsesflowet.',
				'localized_slug'  => 'livscykluskontrol',
				'change_sentence' => 'Ekstra kontrolsætning for review-bevis.',
			),
			'fi' => array(
				'title'           => 'Elinkaaritarkistus',
				'content'         => '<!-- wp:heading --><h2>Elinkaaritarkistus</h2><!-- /wp:heading -->' . "\n\n" . '<!-- wp:paragraph --><p>Tämä väliaikainen artikkeli osoittaa, että käännösten tarkistus seuraa täsmälleen julkaistavaa sisältöä ennen julkaisua.</p><!-- /wp:paragraph -->',
				'excerpt'         => 'Väliaikainen testi käännöstyönkulusta.',
				'localized_slug'  => 'elinkaaritarkistus',
				'change_sentence' => 'Ylimääräinen tarkistuslause arviointinäytölle.',
			),
			'ar' => array(
				'title'           => 'ikhtibar-dawrat-hayat-altarjama',
				'content'         => '<!-- wp:heading --><h2>اختبار دورة حياة الترجمة</h2><!-- /wp:heading -->' . "\n\n" . '<!-- wp:paragraph --><p>يوضح هذا المنشور المؤقت أن مراجعة ترجمات ديفينيا تتبع المحتوى الدقيق قبل النشر.</p><!-- /wp:paragraph -->',
				'excerpt'         => 'اختبار مؤقت لمسار الترجمة.',
				'localized_slug'  => 'ikhtibar-dawrat-hayat-altarjama',
				'change_sentence' => 'جملة تحقق إضافية لإثبات المراجعة.',
			),
			'ja' => array(
				'title'           => '翻訳ライフサイクル確認',
				'content'         => '<!-- wp:heading --><h2>翻訳ライフサイクル確認</h2><!-- /wp:heading -->' . "\n\n" . '<!-- wp:paragraph --><p>この一時的な投稿は、翻訳レビューが公開前の正確な内容に紐づくことを確認します。</p><!-- /wp:paragraph -->',
				'excerpt'         => '翻訳ワークフローの一時テスト。',
				'localized_slug'  => 'honyaku-raifusaikuru-kakunin',
				'change_sentence' => 'レビュー証跡のための追加確認文です。',
			),
		);

		if ( empty( $defaults[ $language ] ) ) {
			return array(
				'success' => false,
				'message' => 'No lifecycle regression fixture is bundled for this language. Provide source_title, source_content, title, content, and localized_slug in the ability input.',
				'language' => $language,
			);
		}

		return array_merge(
			array(
				'success'        => true,
				'source_title'   => 'Translation lifecycle regression',
				'source_content' => $source_content,
				'source_excerpt' => 'Temporary translation workflow regression test.',
			),
			$defaults[ $language ]
		);
	}

	/**
	 * Append one sentence inside the last paragraph block for change-invalidation tests.
	 */
	private static function translation_lifecycle_changed_content( string $content, string $sentence ): string {
		$sentence = trim( $sentence );
		if ( '' === $sentence ) {
			$sentence = 'Extra lifecycle evidence sentence.';
		}
		$needle = '</p><!-- /wp:paragraph -->';
		if ( false !== strpos( $content, $needle ) ) {
			$changed = preg_replace( '/' . preg_quote( $needle, '/' ) . '(?!.*' . preg_quote( $needle, '/' ) . ')/s', ' ' . esc_html( $sentence ) . $needle, $content );
			return is_string( $changed ) ? $changed : $content;
		}

		return $content . "\n\n" . '<!-- wp:paragraph --><p>' . esc_html( $sentence ) . '</p><!-- /wp:paragraph -->';
	}

	/**
	 * Mark one lifecycle regression check.
	 *
	 * @param array<int,array<string,mixed>> $checks Check rows.
	 * @param array<int,string>              $failed Failed check codes.
	 * @param array<string,mixed>            $details Check details.
	 */
	private static function add_lifecycle_check( array &$checks, array &$failed, string $code, bool $passed, array $details = array() ): void {
		$checks[] = array(
			'code'    => sanitize_key( $code ),
			'passed'  => $passed,
			'details' => $details,
		);
		if ( ! $passed ) {
			$failed[] = sanitize_key( $code );
		}
	}

	/**
	 * Build boolean review input from required check keys.
	 *
	 * @param array<int,string> $checks Required check keys.
	 * @return array<string,bool>
	 */
	private static function review_check_input( array $checks ): array {
		$out = array();
		foreach ( $checks as $check ) {
			$out[ sanitize_key( (string) $check ) ] = true;
		}

		return $out;
	}

	/**
	 * Delete temporary lifecycle regression posts.
	 *
	 * @param array{source_id:int,translation_id:int} $created Temporary post IDs.
	 * @return array<string,bool>
	 */
	private static function cleanup_translation_lifecycle_regression_posts( array $created ): array {
		$results = array();
		foreach ( array( 'translation_id', 'source_id' ) as $key ) {
			$post_id = absint( $created[ $key ] ?? 0 );
			if ( ! $post_id ) {
				$results[ $key ] = true;
				continue;
			}
			$results[ $key ] = (bool) wp_delete_post( $post_id, true );
		}

		return $results;
	}

	/**
	 * Compact QA output for lifecycle responses.
	 */
	private static function lifecycle_compact_qa_result( array $qa ): array {
		return array(
			'success'       => ! empty( $qa['success'] ),
			'passed'        => ! empty( $qa['passed'] ),
			'issue_count'   => absint( $qa['issue_count'] ?? 0 ),
			'warning_count' => absint( $qa['warning_count'] ?? 0 ),
			'issue_codes'   => self::qa_item_codes( $qa['issues'] ?? array() ),
			'warning_codes' => self::qa_item_codes( $qa['warnings'] ?? array() ),
		);
	}

	/**
	 * Compact upsert output for lifecycle responses.
	 */
	private static function lifecycle_compact_upsert_result( array $result ): array {
		$translation = isset( $result['translation'] ) && is_array( $result['translation'] ) ? $result['translation'] : array();

		return array(
			'success'            => ! empty( $result['success'] ),
			'message'            => isset( $result['message'] ) ? sanitize_text_field( (string) $result['message'] ) : '',
			'code'               => isset( $result['code'] ) ? sanitize_key( (string) $result['code'] ) : '',
			'review_invalidated' => ! empty( $result['review_invalidated'] ),
			'translation'        => array(
				'id'                    => absint( $translation['id'] ?? 0 ),
				'post_type'             => isset( $translation['post_type'] ) ? sanitize_key( (string) $translation['post_type'] ) : '',
				'language'              => isset( $translation['language'] ) ? sanitize_key( (string) $translation['language'] ) : '',
				'status'                => isset( $translation['status'] ) ? sanitize_key( (string) $translation['status'] ) : '',
				'translation_status'    => isset( $translation['translation_status'] ) ? sanitize_key( (string) $translation['translation_status'] ) : '',
				'localized_path'        => isset( $translation['localized_path'] ) ? sanitize_text_field( (string) $translation['localized_path'] ) : '',
				'linguistic_review_state' => $translation['linguistic_review_state'] ?? null,
				'quality_review_evidence_present' => ! empty( $translation['quality_review_evidence'] ),
			),
			'taxonomies'         => $result['taxonomies'] ?? null,
		);
	}

	/**
	 * Compact publish output for lifecycle responses.
	 */
	private static function lifecycle_compact_publish_result( array $result ): array {
		$published = ! empty( $result['published'] ) || ( ! empty( $result['success'] ) && isset( $result['translation'] ) );

		return array(
			'success'              => ! empty( $result['success'] ),
			'message'              => isset( $result['message'] ) ? sanitize_text_field( (string) $result['message'] ) : '',
			'published'            => $published,
			'review_state'         => $result['review_state'] ?? null,
			'qa'                   => isset( $result['qa'] ) && is_array( $result['qa'] ) ? self::lifecycle_compact_qa_result( $result['qa'] ) : null,
			'live_verification'    => isset( $result['live_verification'] ) ? $result['live_verification'] : null,
		);
	}

	/**
	 * Compact review output for lifecycle responses.
	 */
	private static function lifecycle_compact_review_result( array $result ): array {
		return array(
			'success'      => ! empty( $result['success'] ),
			'message'      => isset( $result['message'] ) ? sanitize_text_field( (string) $result['message'] ) : '',
			'review_state' => $result['review_state'] ?? null,
			'item'         => $result['item'] ?? null,
		);
	}

	/**
	 * Final lifecycle regression response.
	 *
	 * @param array{source_id:int,translation_id:int} $created Temporary post IDs.
	 * @param array<int,array<string,mixed>>          $checks Check rows.
	 * @param array<int,string>                       $failed Failed checks.
	 * @param array<string,bool>                      $cleanup_results Cleanup results.
	 */
	private static function translation_lifecycle_regression_result( bool $passed, string $language, array $created, array $checks, array $failed, bool $cleanup, array $cleanup_results, string $message = '' ): array {
		return array(
			'success'            => $passed,
			'message'            => '' !== $message ? $message : ( $passed ? 'Translation lifecycle regression passed.' : 'Translation lifecycle regression failed.' ),
			'plugin_version'     => self::VERSION,
			'language'           => $language,
			'write_test_ran'     => true,
			'lifecycle_proven'   => $passed,
			'checks'             => $checks,
			'failed_checks'      => array_values( array_unique( $failed ) ),
			'temporary_posts'    => $created,
			'cleanup_requested'  => $cleanup,
			'cleanup'            => $cleanup_results,
		);
	}

	/**
	 * Load packaged translation-fitness regression cases.
	 */
	private static function translation_fitness_regression_cases(): array {
		$relative_file = 'qa-corpus/translation-fitness-regressions.json';
		$file          = plugin_dir_path( __FILE__ ) . $relative_file;
		if ( ! is_readable( $file ) ) {
			return array(
				'success' => false,
				'message' => 'Translation-fitness regression corpus is missing.',
				'file'    => $relative_file,
				'cases'   => array(),
			);
		}

		$decoded = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'success' => false,
				'message' => 'Translation-fitness regression corpus is not valid JSON: ' . json_last_error_msg(),
				'file'    => $relative_file,
				'cases'   => array(),
			);
		}

		$cases = isset( $decoded['cases'] ) && is_array( $decoded['cases'] ) ? $decoded['cases'] : array();
		if ( empty( $cases ) ) {
			return array(
				'success' => false,
				'message' => 'Translation-fitness regression corpus contains no cases.',
				'file'    => $relative_file,
				'cases'   => array(),
			);
		}

		return array(
			'success'        => true,
			'file'           => $relative_file,
			'schema_version' => isset( $decoded['schema_version'] ) ? absint( $decoded['schema_version'] ) : 0,
			'cases'          => $cases,
		);
	}

	/**
	 * Best-effort install of WordPress core language packs for supported locales.
	 */
	private static function ensure_supported_wordpress_language_packs(): array {
		$status = self::wordpress_language_pack_status( true );
		update_option( self::OPTION_LANGUAGE_PACK_STATUS, $status, false );

		return $status;
	}

	/**
	 * Report and optionally install WordPress core language packs.
	 */
	private static function wordpress_language_pack_status( bool $install_missing = false ): array {
		if ( ! function_exists( 'wp_get_available_translations' ) || ! function_exists( 'wp_download_language_pack' ) || ! function_exists( 'wp_can_install_language_pack' ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		}

		$installed     = get_available_languages();
		$available     = function_exists( 'wp_get_available_translations' ) ? wp_get_available_translations() : array();
		$can_install   = function_exists( 'wp_can_install_language_pack' ) && wp_can_install_language_pack();
		$rows          = array();
		$missing       = array();
		$installations = array();

		foreach ( self::languages() as $language => $config ) {
			$locale = isset( $config['locale'] ) ? (string) $config['locale'] : '';
			$wp_locale = isset( $config['wordpress_locale'] ) && '' !== (string) $config['wordpress_locale'] ? (string) $config['wordpress_locale'] : $locale;
			if ( '' === $wp_locale ) {
				continue;
			}

			$is_default_english = 'en_US' === $wp_locale;
			$is_installed       = $is_default_english || in_array( $wp_locale, $installed, true );
			$is_available       = $is_default_english || isset( $available[ $wp_locale ] );
			$row                = array(
				'language'      => (string) $language,
				'locale'        => $locale,
				'wordpress_locale' => $wp_locale,
				'installed'     => $is_installed,
				'available'     => $is_available,
				'install_tried' => false,
				'install_ok'    => false,
			);

			if ( ! $is_installed && $install_missing ) {
				$row['install_tried'] = true;
				if ( $is_available && $can_install && function_exists( 'wp_download_language_pack' ) ) {
					$result            = wp_download_language_pack( $wp_locale );
					$row['install_ok'] = $wp_locale === $result;
					$row['installed']  = $row['install_ok'] || in_array( $wp_locale, get_available_languages(), true );
				}
				$installations[] = $row;
			}

			if ( empty( $row['installed'] ) ) {
				$missing[] = $wp_locale;
			}

			$rows[ (string) $language ] = $row;
		}

		return array(
			'success'       => empty( $missing ),
			'can_install'   => $can_install,
			'install_missing' => $install_missing,
			'language_packs' => $rows,
			'missing'       => array_values( array_unique( $missing ) ),
			'installations' => $installations,
		);
	}

	/**
	 * Validate required language review profile fields.
	 */
	private static function validate_language_profile( string $language, array $decoded ): array {
		$profile = isset( $decoded['language_profile'] ) && is_array( $decoded['language_profile'] ) ? $decoded['language_profile'] : array();
		if ( ! $profile ) {
			return array(
				array(
					'language' => $language,
					'reason'   => 'missing_language_profile',
				),
			);
		}

		$issues = array();
		foreach ( array( 'review_language', 'tone', 'formality', 'locale_guidance', 'preserve_terms', 'never_translate_terms', 'localized_terms' ) as $key ) {
			if ( ! array_key_exists( $key, $profile ) ) {
				$issues[] = array(
					'language' => $language,
					'field'    => $key,
					'reason'   => 'missing_language_profile_field',
				);
				continue;
			}
			if ( in_array( $key, array( 'preserve_terms', 'never_translate_terms', 'localized_terms' ), true ) && ! is_array( $profile[ $key ] ) ) {
				$issues[] = array(
					'language' => $language,
					'field'    => $key,
					'reason'   => 'language_profile_field_must_be_array',
				);
			}
		}
		if ( array_key_exists( 'agency_copy', $profile ) && ! is_array( $profile['agency_copy'] ) ) {
			$issues[] = array(
				'language' => $language,
				'field'    => 'agency_copy',
				'reason'   => 'language_profile_field_must_be_array',
			);
		}
		foreach ( array( 'review_patterns', 'naturalness_patterns', 'script_signals', 'source_carryover_homographs' ) as $field ) {
			if ( array_key_exists( $field, $profile ) ) {
				$issues[] = array(
					'language' => $language,
					'field'    => $field,
					'reason'   => 'language_quality_rule_in_packaged_language_file',
				);
			}
		}

		return $issues;
	}

	/**
	 * Check that language QA rules live behind the rule repository surfaces.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function language_policy_status( array $input = array() ): array {
		unset( $input );

		$issues = array();
		$base   = plugin_dir_path( __FILE__ );
		$php    = $base . 'devenia-ai-translations.php';
		$source = is_readable( $php ) ? (string) file_get_contents( $php ) : '';
		$language_codes = array_keys( self::default_languages() );
		$language_pattern = implode( '|', array_map( 'preg_quote', $language_codes ) );
		$qa_tokens = array(
			'review_patterns',
			'naturalness_patterns',
			'script_signals',
			'source_carryover_homographs',
			'shadow_exclusions',
			'shadow_context_exclusions',
		);

		if ( '' !== $source && preg_match_all( '/([\'"](?:' . $language_pattern . ')[\'"]\s*=>\s*(?:array\s*\(|\[)[\s\S]{0,900}(?:' . implode( '|', array_map( 'preg_quote', $qa_tokens ) ) . '))/m', $source, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[1] as $match ) {
				$issues[] = array(
					'code'   => 'language_specific_qa_rule_in_php',
					'file'   => 'devenia-ai-translations.php',
					'line'   => self::line_number_for_offset( $source, (int) $match[1] ),
					'sample' => self::copy_brief_excerpt( trim( preg_replace( '/\s+/', ' ', (string) $match[0] ) ), 220 ),
				);
			}
		}

		foreach ( glob( $base . 'languages/*.json' ) ?: array() as $file ) {
			$decoded = json_decode( (string) file_get_contents( $file ), true );
			$profile = isset( $decoded['language_profile'] ) && is_array( $decoded['language_profile'] ) ? $decoded['language_profile'] : array();
			foreach ( $qa_tokens as $field ) {
				if ( array_key_exists( $field, $profile ) ) {
					$issues[] = array(
						'code' => 'language_quality_rule_in_packaged_language_file',
						'file' => 'languages/' . basename( $file ),
						'field' => $field,
					);
				}
			}
		}

		$registry_status = self::language_quality_rule_registry_status();
		foreach ( $registry_status['runtime_option_issues'] ?? array() as $runtime_option_issue ) {
			$issues[] = $runtime_option_issue;
		}
		$event_table_ok  = self::language_rule_events_available();
		if ( ! $event_table_ok ) {
			$issues[] = array(
				'code' => 'language_rule_events_table_missing',
				'message' => 'The audited language-rule event table is not installed.',
			);
		}
		if ( empty( $registry_status['valid'] ) ) {
			$issues[] = array(
				'code' => 'language_quality_registry_invalid',
				'message' => 'The packaged language-quality rule registry is missing or invalid.',
				'details' => $registry_status,
			);
		}

		return array(
			'success'       => empty( $issues ),
			'passed'        => empty( $issues ),
			'issue_count'   => count( $issues ),
			'issues'        => $issues,
			'registry'      => $registry_status,
			'event_table'   => array(
				'available'       => $event_table_ok,
				'schema_version'  => (string) get_option( self::OPTION_LANGUAGE_RULE_EVENTS_SCHEMA, '' ),
				'expected_schema' => self::LANGUAGE_RULE_EVENTS_SCHEMA_VERSION,
			),
		);
	}

	/**
	 * Packaged language-quality registry health.
	 *
	 * @return array<string,mixed>
	 */
	private static function language_quality_rule_registry_status(): array {
		$registry = self::language_quality_rule_registry();
		$configured = array_keys( self::languages() );
		$missing = array();
		$runtime_option_issues = array();
		foreach ( $configured as $language ) {
			if ( self::source_language_code() === $language ) {
				continue;
			}
			if ( empty( $registry[ $language ] ) || ! is_array( $registry[ $language ] ) ) {
				$missing[] = $language;
			}
		}
		$runtime_script_signal_options = self::runtime_script_signal_option_keys();
		foreach ( $registry as $language => $rules ) {
			$signals = isset( $rules['script_signals'] ) && is_array( $rules['script_signals'] ) ? $rules['script_signals'] : array();
			foreach ( $runtime_script_signal_options as $option_key ) {
				if ( array_key_exists( $option_key, $signals ) ) {
					$runtime_option_issues[] = array(
						'code' => 'runtime_script_signal_option_in_packaged_registry',
						'file' => 'quality-rules/language-quality.json',
						'language' => (string) $language,
						'field' => 'script_signals.' . $option_key,
						'message' => 'Runtime script-signal mode decisions must be stored as audited language-rule events, not packaged registry rules.',
					);
				}
			}
		}

		return array(
			'valid' => empty( $missing ) && empty( $runtime_option_issues ) && ! empty( $registry ),
			'language_count' => count( $registry ),
			'missing_languages' => $missing,
			'runtime_option_issues' => $runtime_option_issues,
			'path' => 'quality-rules/language-quality.json',
		);
	}

	/**
	 * Return 1-based line number for a string offset.
	 */
	private static function line_number_for_offset( string $source, int $offset ): int {
		return substr_count( substr( $source, 0, max( 0, $offset ) ), "\n" ) + 1;
	}

	/**
	 * Validate configured widget link targets against published translations.
	 *
	 * @param string              $language Language code.
	 * @param array<string,mixed> $decoded  Decoded language file.
	 * @return array<int,array<string,mixed>>
	 */
	private static function validate_widget_text_links( string $language, array $decoded ): array {
		$issues       = array();
		$widget_text  = isset( $decoded['widget_text'] ) && is_array( $decoded['widget_text'] ) ? $decoded['widget_text'] : array();
		$link_targets = isset( $decoded['widget_link_targets'] ) && is_array( $decoded['widget_link_targets'] ) ? $decoded['widget_link_targets'] : array();

		foreach ( $link_targets as $source_url => $source_id ) {
			$source_url = esc_url_raw( (string) $source_url );
			$source_id  = absint( $source_id );
			if ( '' === $source_url || ! $source_id ) {
				$issues[] = array(
					'source_url' => $source_url,
					'source_id'  => $source_id,
					'reason'     => 'invalid_link_target',
				);
				continue;
			}

			$expected_url = self::expected_translation_url_for_source( $source_id, $language );
			if ( '' === $expected_url ) {
				$issues[] = array(
					'source_url' => $source_url,
					'source_id'  => $source_id,
					'reason'     => 'missing_published_translation_target',
				);
				continue;
			}

			foreach ( $widget_text as $source_text => $translated_text ) {
				if ( ! is_string( $source_text ) || ! is_string( $translated_text ) || false === strpos( $source_text, $source_url ) ) {
					continue;
				}

				$actual_urls = self::html_href_values( $translated_text );
				if ( ! in_array( $expected_url, $actual_urls, true ) ) {
					$issues[] = array(
						'source_url'   => $source_url,
						'source_id'    => $source_id,
						'expected_url' => $expected_url,
						'actual_urls'  => $actual_urls,
						'reason'       => 'widget_link_target_mismatch',
					);
				}
			}
		}

		return $issues;
	}

	/**
	 * Validate every href stored in a language file value.
	 *
	 * @param string              $language Language code.
	 * @param array<string,mixed> $decoded  Decoded language file.
	 * @return array<int,array<string,mixed>>
	 */
	private static function validate_language_file_links( string $language, array $decoded ): array {
		$issues = array();

		foreach ( self::language_file_strings( $decoded ) as $path => $value ) {
			foreach ( self::localized_link_issues_for_html( $value, $language ) as $issue ) {
				$issue['language_file_path'] = $path;
				$issues[] = $issue;
			}
		}

		return $issues;
	}

	/**
	 * Recursively collect string values from language-file data.
	 *
	 * @param mixed  $value Data value.
	 * @param string $path  Dot path.
	 * @return array<string,string>
	 */
	private static function language_file_strings( $value, string $path = '' ): array {
		if ( is_string( $value ) ) {
			return array( $path => $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$strings = array();
		foreach ( $value as $key => $child ) {
			$child_path = '' === $path ? (string) $key : $path . '.' . (string) $key;
			$strings    = array_merge( $strings, self::language_file_strings( $child, $child_path ) );
		}

		return $strings;
	}

	/**
	 * Expected frontend URL for a source page in a language.
	 */
	private static function expected_translation_url_for_source( int $source_id, string $language ): string {
		if ( 'en' === $language ) {
			$url = get_permalink( $source_id );
			return $url ? trailingslashit( (string) $url ) : '';
		}

		$translation_id = self::find_translation_id( $source_id, $language, array( 'publish' ) );
		if ( ! $translation_id ) {
			return '';
		}

		$url = get_permalink( $translation_id );
		return $url ? trailingslashit( (string) $url ) : '';
	}

	/**
	 * Extract href values from a small HTML fragment.
	 *
	 * @return array<int,string>
	 */
	private static function html_href_values( string $html ): array {
		if ( ! preg_match_all( '/href=[\"\']([^\"\']+)[\"\']/i', $html, $matches ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_map(
					static function ( string $url ): string {
						return trailingslashit( esc_url_raw( html_entity_decode( $url, ENT_QUOTES ) ) );
					},
					$matches[1]
				)
			)
		);
	}

	/**
	 * Capability guard for MCP abilities.
	 */
		public static function ability_permission_callback(): bool {
			return current_user_can( 'manage_options' );
		}

		/**
		 * Normalize stable ability aliases before the operation implementation runs.
		 *
		 * The public schemas stay strict. This seam exists so older operator flows and
		 * closely related abilities can share the same content/time concepts without
		 * spreading alias handling into every implementation.
		 *
		 * @param string $operation Operation name.
		 * @param array<string,mixed> $input Raw ability input.
		 * @return array<string,mixed>
		 */
		private static function normalize_ability_input( string $operation, array $input ): array {
			$content_id = self::first_positive_int_from_input(
				$input,
				array( 'content_id', 'page_id', 'post_id', 'translation_id' )
			);

			if ( in_array( $operation, array( 'qa_translation', 'mark_reviewed', 'mark_linguistic_reviewed', 'publish_translation', 'verify_live_translation' ), true ) && empty( $input['translation_id'] ) && $content_id ) {
				$input['translation_id'] = $content_id;
			}

			if ( in_array( $operation, array( 'quality_verdict', 'mark_quality_reviewed', 'internal_link_opportunities' ), true ) && empty( $input['content_id'] ) && empty( $input['page_id'] ) && $content_id ) {
				$input['content_id'] = $content_id;
			}

			if ( 'publish_translation' === $operation && isset( $input['timeout'] ) && ! isset( $input['live_verification_timeout'] ) ) {
				$input['live_verification_timeout'] = absint( $input['timeout'] );
				unset( $input['timeout'] );
			}

			if ( 'verify_live_translation' === $operation && isset( $input['live_verification_timeout'] ) && ! isset( $input['timeout'] ) ) {
				$input['timeout'] = absint( $input['live_verification_timeout'] );
				unset( $input['live_verification_timeout'] );
			}

			return $input;
		}

		/**
		 * Return the first positive integer from a known alias list.
		 *
		 * @param array<string,mixed> $input Input payload.
		 * @param array<int,string> $keys Candidate keys in priority order.
		 */
		private static function first_positive_int_from_input( array $input, array $keys ): int {
			foreach ( $keys as $key ) {
				if ( ! array_key_exists( $key, $input ) ) {
					continue;
				}
				$value = absint( $input[ $key ] );
				if ( $value > 0 ) {
					return $value;
				}
			}

			return 0;
		}

		/**
		 * Register MCP abilities.
		 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( self::ability_catalogue() as $name => $args ) {
			$args['permission_callback'] = array( __CLASS__, 'ability_permission_callback' );
			self::register_ability( $name, $args );
		}
	}

	/**
	 * Execute a named translation workflow operation.
	 *
	 * @param string $operation Operation name.
	 * @param mixed  $input     Ability input.
	 * @return array
	 */
		private static function run_ability_operation( string $operation, $input = array() ): array {
			$input = is_array( $input ) ? $input : array();
			$input = self::normalize_ability_input( $operation, $input );

			switch ( $operation ) {
			case 'list_languages':
				$configuration = self::language_configuration_status();
				return array(
					'success'               => true,
					'languages'              => self::languages(),
					'configuration'          => $configuration['languages'],
					'configuration_complete' => $configuration['complete'],
					'missing_configuration'  => $configuration['missing'],
				);
			case 'language_files_status':
				$status  = self::validate_language_files();
				$missing = array();

				foreach ( $status as $language => $row ) {
					if ( empty( $row['exists'] ) || empty( $row['valid_json'] ) || empty( $row['has_wordpress_locale'] ) || empty( $row['has_menu'] ) || empty( $row['has_widget_text'] ) || empty( $row['has_not_found_text'] ) || empty( $row['has_not_found_routes'] ) || empty( $row['has_language_profile'] ) || ! empty( $row['language_profile_issues'] ) || ! empty( $row['widget_link_issues'] ) || ! empty( $row['link_issues'] ) ) {
						$missing[] = $language;
					}
				}

				return array(
					'success'        => empty( $missing ),
					'language_files' => $status,
					'missing'        => $missing,
				);
			case 'translation_fitness_status':
				return self::translation_fitness_regression_status( $input );
			case 'lifecycle_regression_status':
				return self::translation_lifecycle_regression_status( $input );
			case 'language_packs_status':
				return self::wordpress_language_pack_status( ! empty( $input['install_missing'] ) );
			case 'translation_index_status':
				return self::translation_index_status( $input );
			case 'gutenberg_content_safety_scan':
				return self::gutenberg_content_safety_scan( $input );
			case 'frontend_performance_status':
				return self::frontend_performance_status( $input );
			case 'warm_cache':
				return self::warm_translation_cache( $input );
			case 'update_runtime_text':
				return self::update_runtime_language_text( $input );
			case 'get_quality_profile':
				return self::get_runtime_quality_profile( $input );
			case 'update_quality_profile':
				return self::update_runtime_quality_profile( $input );
			case 'record_language_rule_event':
				return self::record_language_rule_event( $input );
			case 'list_language_rule_events':
				return self::list_language_rule_events( $input );
			case 'learning_inbox':
				return self::learning_inbox( $input );
			case 'review_learning_event':
				return self::review_learning_event( $input );
			case 'language_policy_status':
				return self::language_policy_status( $input );
			case 'agency_copy_brief':
				return self::agency_copy_brief( $input );
			case 'record_copy_feedback':
				return self::record_copy_feedback( $input );
			case 'get_reviewer_style_profile':
				return self::get_reviewer_style_profile( $input );
			case 'record_reviewer_style_edit':
				return self::record_reviewer_style_edit( $input );
			case 'update_blog_taxonomy_paths':
				return self::update_blog_taxonomy_paths( $input );
			case 'update_source_qa_options':
				return self::update_source_qa_options( $input );
			case 'authored_original_intake_queue':
				return self::authored_original_intake_queue( $input );
			case 'update_authored_original_intake':
				return self::update_authored_original_intake( $input );
			case 'create_source_from_authored_original':
				return self::create_source_from_authored_original( $input );
			case 'mark_source_generation_reviewed':
				return self::mark_source_generation_reviewed( $input );
			case 'get_source':
				return self::get_source_payload( (int) ( $input['source_id'] ?? 0 ) );
			case 'upsert_page':
				return self::upsert_translation( $input );
			case 'list_translations':
				return self::list_translations( $input );
			case 'mark_reviewed':
				return self::mark_reviewed( (int) ( $input['translation_id'] ?? 0 ), (string) ( $input['translation_status'] ?? '' ) );
			case 'qa_translation':
				return self::qa_translation( $input );
			case 'mark_linguistic_reviewed':
				return self::mark_linguistic_reviewed( $input );
			case 'publish_translation':
				return self::publish_translation( $input );
			case 'verify_live_translation':
				return self::verify_live_translation( $input );
			case 'workflow_status':
				return self::workflow_status( (int) ( $input['source_id'] ?? 0 ) );
			case 'queue':
				return self::translation_queue( $input );
			case 'review_queue':
				return self::review_queue( $input );
			case 'quality_review_queue':
				return self::quality_review_queue( $input );
			case 'quality_verdict':
				return self::quality_verdict( $input );
			case 'internal_link_opportunities':
				return self::internal_link_opportunities( $input );
			case 'mark_quality_reviewed':
				return self::mark_quality_reviewed( $input );
			case 'sync_menu':
				return self::sync_language_menu( $input );
			case 'repair_url_hierarchy':
				return self::repair_url_hierarchy( $input );
			case 'repair_internal_links':
				return self::repair_internal_links( $input );
			case 'repair_featured_images':
				return self::repair_featured_images( $input );
		}

		return self::error( 'Unknown translation operation.' );
	}

	/**
	 * MCP ability catalogue.
	 *
	 * New abilities should be added here so registration metadata, schemas, and
	 * callbacks are reviewable without scanning hook wiring.
	 */
	private static function ability_catalogue(): array {
		return array(
			'ai-translations/list-languages' => array(
				'label'            => 'List Translation Languages',
				'description'      => 'Returns the configured AI Translation Workflow translation language registry.',
				'input_schema'     => self::empty_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input = array() ) {
					return self::run_ability_operation( 'list_languages', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/language-files-status' => array(
				'label'            => 'Check Translation Language Files',
				'description'      => 'Returns packaged local language file coverage for all supported AI Translation Workflow translation languages.',
				'input_schema'     => self::empty_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input = array() ) {
					return self::run_ability_operation( 'language_files_status', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/translation-fitness-status' => array(
				'label'            => 'Check Translation Fitness Regressions',
				'description'      => 'Runs the packaged translation-fitness regression corpus so known bad naturalness and orthography failures cannot pass silently in future releases.',
				'input_schema'     => self::translation_fitness_status_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input = array() ) {
					return self::run_ability_operation( 'translation_fitness_status', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/lifecycle-regression-status' => array(
				'label'            => 'Check Translation Lifecycle Regression',
				'description'      => 'Reports translation workflow lifecycle readiness and can explicitly run a temporary post translation regression for QA, review evidence, publish gate, and review invalidation.',
				'input_schema'     => self::translation_lifecycle_regression_status_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input = array() ) {
					return self::run_ability_operation( 'lifecycle_regression_status', $input );
				},
				'meta'             => self::ability_meta( false, false, false ),
			),
				'ai-translations/language-packs-status' => array(
					'label'            => 'Check WordPress Core Language Packs',
					'description'      => 'Returns WordPress core language-pack status for all configured AI Translation Workflow translation locales, and can install missing packs.',
				'input_schema'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'install_missing' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Install missing WordPress core language packs when possible.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'language_packs_status', $input );
					},
						'meta'             => self::ability_meta( true, false, true ),
					),
						'ai-translations/translation-index-status' => array(
					'label'            => 'Check Translation Index',
					'description'      => 'Reports the MariaDB translation registry table status and can rebuild it from WordPress page metadata.',
					'input_schema'     => array(
						'type'                 => 'object',
						'properties'           => array(
							'rebuild' => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => 'Rebuild the translation registry from WordPress page metadata before reporting status.',
							),
						),
						'additionalProperties' => false,
					),
						'output_schema'    => self::generic_output_schema(),
						'execute_callback' => function ( $input ) {
							return self::run_ability_operation( 'translation_index_status', $input );
						},
							'meta'             => self::ability_meta( false, false, true ),
						),
							'ai-translations/gutenberg-content-safety-scan' => array(
							'label'            => 'Scan Gutenberg Content Safety',
							'description'      => 'Scans stored pages/posts with the same Gutenberg content-safety module used before saves and translation QA. Can optionally repair safe, output-preserving serialization mismatches.',
							'input_schema'     => self::gutenberg_content_safety_scan_input_schema(),
							'output_schema'    => self::generic_output_schema(),
							'execute_callback' => function ( $input ) {
								return self::run_ability_operation( 'gutenberg_content_safety_scan', $input );
							},
							'meta'             => self::ability_meta( false, false, true ),
						),
							'ai-translations/frontend-performance-status' => array(
							'label'            => 'Check Translation Frontend Performance',
							'description'      => 'Returns recent slow translated frontend requests recorded by the plugin. Can clear the slow log after review.',
							'input_schema'     => array(
							'type'                 => 'object',
							'properties'           => array(
								'clear' => array(
									'type'        => 'boolean',
									'default'     => false,
									'description' => 'Clear the recorded slow frontend request log after reading it.',
								),
							),
							'additionalProperties' => false,
							),
							'output_schema'    => self::generic_output_schema(),
							'execute_callback' => function ( $input ) {
								return self::run_ability_operation( 'frontend_performance_status', $input );
							},
							'meta'             => self::ability_meta( false, false, true ),
						),
						'ai-translations/warm-cache' => array(
							'label'            => 'Warm Translation Cache',
						'description'      => 'Fetches published translated content URLs without query strings so Cloudflare/WordPress anonymous HTML cache can be warmed after purges or publication.',
							'input_schema'     => self::warm_cache_input_schema(),
							'output_schema'    => self::generic_output_schema(),
							'execute_callback' => function ( $input ) {
								return self::run_ability_operation( 'warm_cache', $input );
							},
							'meta'             => self::ability_meta( false, false, false ),
						),
				'ai-translations/update-runtime-text' => array(
						'label'            => 'Update Runtime Translation Text',
					'description'      => 'Updates small runtime translation text stored in WordPress options, such as shared widget, 404, or short menu labels. Use this for typo/copy fixes instead of editing packaged language JSON files or releasing the plugin.',
					'input_schema'     => self::runtime_text_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'update_runtime_text', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'ai-translations/get-quality-profile' => array(
					'label'            => 'Get Translation Quality Profile',
					'description'      => 'Returns packaged, runtime, and effective language quality profiles used by translation QA.',
					'input_schema'     => self::quality_profile_get_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'get_quality_profile', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
				),
				'ai-translations/update-quality-profile' => array(
					'label'            => 'Update Translation Quality Profile',
					'description'      => 'Updates runtime language quality profile overrides in WordPress options. Use this for glossary, terminology, agency-copy, review-pattern, and script-signal corrections instead of editing packaged language JSON files.',
					'input_schema'     => self::quality_profile_update_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'update_quality_profile', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'ai-translations/record-language-rule-event' => array(
					'label'            => 'Record Language Rule Event',
					'description'      => 'Stores a language QA rule, human feedback decision, or reviewer learning event in the audited rule-event table instead of hardcoding it in PHP or packaged language files.',
					'input_schema'     => self::language_rule_event_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'record_language_rule_event', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'ai-translations/list-language-rule-events' => array(
					'label'            => 'List Language Rule Events',
					'description'      => 'Lists audited language QA rule and reviewer-learning events, optionally filtered by language, status, or rule type.',
					'input_schema'     => self::language_rule_events_list_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'list_language_rule_events', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
				),
				'ai-translations/learning-inbox' => array(
					'label'            => 'List Translation Learning Inbox',
					'description'      => 'Lists captured human editor changes that can be kept as reviewer-style guidance, promoted to a QA rule, or ignored for future rule work.',
					'input_schema'     => self::learning_inbox_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'learning_inbox', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
				),
				'ai-translations/review-learning-event' => array(
					'label'            => 'Review Translation Learning Event',
					'description'      => 'Marks a captured human edit as reviewed style guidance, promotes it to a hard naturalness QA rule, or hides it from the pending learning inbox.',
					'input_schema'     => self::learning_event_review_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'review_learning_event', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'ai-translations/language-policy-status' => array(
					'label'            => 'Check Language Rule Policy',
					'description'      => 'Fails when language-specific QA rules are hardcoded in PHP or placed in packaged language files instead of the rule registry/runtime/event store.',
					'input_schema'     => self::empty_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'language_policy_status', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
				),
				'ai-translations/agency-copy-brief' => array(
					'label'            => 'Get Agency Copy Brief',
					'description'      => 'Returns the target-reader, promise, proof, action, jargon, and review checks that should guide agency-level translation review for a source or translation.',
					'input_schema'     => self::agency_copy_brief_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'agency_copy_brief', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
				),
					'ai-translations/record-copy-feedback' => array(
						'label'            => 'Record Translation Copy Feedback',
						'description'      => 'Stores native or agency copy feedback on a source or translation. Open needs-work/blocking feedback keeps the page in the quality queue until resolved.',
						'input_schema'     => self::copy_feedback_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'record_copy_feedback', $input );
					},
						'meta'             => self::ability_meta( false, false, false ),
					),
					'ai-translations/get-reviewer-style-profile' => array(
					'label'            => 'Get Reviewer Style Profile',
					'description'      => 'Returns approved per-reviewer style learning for one language or all languages. Use this to shape future translation briefs without hardcoding language-specific copy rules.',
					'input_schema'     => self::reviewer_style_get_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'get_reviewer_style_profile', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
					),
					'ai-translations/record-reviewer-style-edit' => array(
					'label'            => 'Record Reviewer Style Edit',
					'description'      => 'Stores a human reviewer edit, lesson, terminology preference, or copy principle as reusable per-language and per-reviewer guidance for future translations.',
					'input_schema'     => self::reviewer_style_record_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'record_reviewer_style_edit', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
					),
					'ai-translations/update-blog-taxonomy-paths' => array(
					'label'            => 'Update Blog Taxonomy Paths',
					'description'      => 'Updates language-specific blog category/tag URL path segments in WordPress runtime options. Use this when adding or correcting a language; do not hardcode these paths in PHP.',
					'input_schema'     => self::blog_taxonomy_paths_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'update_blog_taxonomy_paths', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'ai-translations/update-source-qa-options' => array(
					'label'            => 'Update Source Translation QA Options',
					'description'      => 'Updates page-specific translation QA options stored on the source WordPress page. Use this for source-carryover preserve terms instead of editing packaged language JSON files.',
					'input_schema'     => self::source_qa_options_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'update_source_qa_options', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'ai-translations/authored-original-intake-queue' => array(
					'label'            => 'List Authored Original Intake Queue',
					'description'      => 'Lists posts/pages authored in a configured non-source language that need an English technical source, source review, or downstream translation handoff.',
					'input_schema'     => self::authored_original_intake_queue_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'authored_original_intake_queue', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
				),
				'ai-translations/update-authored-original-intake' => array(
					'label'            => 'Update Authored Original Intake Status',
					'description'      => 'Marks an authored-original intake item ignored, pending again, or failed with an operator-visible note.',
					'input_schema'     => self::authored_original_intake_update_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'update_authored_original_intake', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'ai-translations/create-source-from-authored-original' => array(
					'label'            => 'Create English Source From Authored Original',
					'description'      => 'Creates or updates an English technical source from a post/page authored in another configured language, then attaches the authored original as that language translation without rewriting it.',
					'input_schema'     => self::authored_original_source_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'create_source_from_authored_original', $input );
					},
					'meta'             => self::ability_meta( false, false, false ),
				),
				'ai-translations/mark-source-generation-reviewed' => array(
					'label'            => 'Mark Generated English Source Reviewed',
					'description'      => 'Marks a generated English technical source as reviewed against its authored original so downstream translations can safely use it.',
					'input_schema'     => self::source_generation_review_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'mark_source_generation_reviewed', $input );
					},
					'meta'             => self::ability_meta( false, false, false ),
				),
				'ai-translations/get-source' => array(
					'label'            => 'Get Translation Source Content',
					'description'      => 'Returns source page/post content, metadata, source hash, taxonomies, and existing translations.',
				'input_schema'     => array(
					'type'                 => 'object',
					'required'             => array( 'source_id' ),
					'properties'           => array(
						'source_id' => array(
							'type'        => 'integer',
							'description' => 'Original WordPress page or post ID.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'get_source', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/upsert-page' => array(
				'label'            => 'Create or Update Translated Content',
				'description'      => 'Creates or updates a translated WordPress page or post with localized slug/path, taxonomies, and translation metadata. The AI/client supplies translated Gutenberg content.',
				'input_schema'     => self::upsert_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'upsert_page', $input );
				},
				'meta'             => self::ability_meta( false, false, false ),
			),
			'ai-translations/list-translations' => array(
				'label'            => 'List Content Translations',
				'description'      => 'Lists translation mappings, optionally filtered by source content, language, and status.',
				'input_schema'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'source_id' => array( 'type' => 'integer' ),
						'language'  => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string' ),
						'limit'     => array( 'type' => 'integer', 'default' => 100 ),
					),
					'additionalProperties' => false,
				),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'list_translations', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/mark-reviewed' => array(
				'label'            => 'Mark Translation Reviewed',
				'description'      => 'Legacy status marker. Reviewed status now requires current linguistic review evidence; publishing must use publish-translation.',
				'input_schema'     => array(
					'type'                 => 'object',
					'required'             => array( 'translation_id', 'translation_status' ),
					'properties'           => array(
						'translation_id'     => array( 'type' => 'integer' ),
						'translation_status' => array(
							'type' => 'string',
							'enum' => array( 'draft', 'needs_review', 'reviewed', 'stale' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'mark_reviewed', $input );
				},
				'meta'             => self::ability_meta( false, false, false ),
			),
			'ai-translations/qa-translation' => array(
				'label'            => 'QA Translation',
				'description'      => 'Runs lightweight workflow QA for translated content before review or publishing.',
				'input_schema'     => self::qa_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'qa_translation', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/mark-linguistic-reviewed' => array(
				'label'            => 'Mark Translation Linguistically Reviewed',
				'description'      => 'Marks a translation as linguistically reviewed after a human or agent language review. Publishing requires this marker.',
				'input_schema'     => self::linguistic_review_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'mark_linguistic_reviewed', $input );
				},
				'meta'             => self::ability_meta( false, false, false ),
			),
			'ai-translations/publish-translation' => array(
				'label'            => 'Publish Translation',
				'description'      => 'Runs QA, publishes translated content, updates translation metadata, cleans WordPress post caches, and optionally syncs the language menu for pages.',
				'input_schema'     => self::publish_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'publish_translation', $input );
				},
				'meta'             => self::ability_meta( false, false, false ),
			),
			'ai-translations/verify-live-translation' => array(
				'label'            => 'Verify Live Translation',
				'description'      => 'Fetches published translated content from the frontend and checks HTTP status, language prefix, html lang, hreflang, and localized internal links.',
				'input_schema'     => self::verify_live_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'verify_live_translation', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/workflow-status' => array(
				'label'            => 'Get Translation Workflow Status',
				'description'      => 'Returns per-language translation status for source content.',
				'input_schema'     => array(
					'type'                 => 'object',
					'required'             => array( 'source_id' ),
					'properties'           => array(
						'source_id' => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'workflow_status', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/queue' => array(
				'label'            => 'List Translation Queue',
				'description'      => 'Lists source pages/posts with missing, stale, review-needed, or publish-ready translations.',
				'input_schema'     => self::queue_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'queue', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/review-queue' => array(
				'label'            => 'List Translation Review Queue',
				'description'      => 'Lists translated content that needs review, linguistic review, or publishing.',
				'input_schema'     => self::review_queue_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'review_queue', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/quality-review-queue' => array(
				'label'            => 'List Translation Quality Review Queue',
				'description'      => 'Lists published translated content whose language/copy quality review is missing or older than the WordPress modified timestamp.',
				'input_schema'     => self::quality_review_queue_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'quality_review_queue', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/quality-verdict' => array(
				'label'            => 'Get Translation Quality Verdict',
				'description'      => 'Returns one consolidated publishability and copy-quality verdict for source or translated content. The default ai_operator audience gives safe issue codes; internal_debug includes raw local evidence.',
				'input_schema'     => self::quality_verdict_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'quality_verdict', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/internal-link-opportunities' => array(
				'label'            => 'Find Internal Link Opportunities',
				'description'      => 'Finds a small, moderated set of relevant internal pages/posts that the current source or translation could link to, preferring localized target URLs when available.',
				'input_schema'     => self::internal_link_opportunities_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'internal_link_opportunities', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/mark-quality-reviewed' => array(
				'label'            => 'Mark Translation Quality Reviewed',
				'description'      => 'Marks a published page or translation as quality-reviewed after a full visible-page review. The review becomes stale when the WordPress page is modified later.',
				'input_schema'     => self::quality_review_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'mark_quality_reviewed', $input );
				},
				'meta'             => self::ability_meta( false, false, false ),
			),
			'ai-translations/sync-menu' => array(
				'label'            => 'Sync Language Menu',
				'description'      => 'Creates or rebuilds a language-specific menu from the source menu, using translated page mappings where available.',
				'input_schema'     => self::sync_menu_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'sync_menu', $input );
				},
				'meta'             => self::ability_meta( false, true, false ),
			),
			'ai-translations/repair-url-hierarchy' => array(
				'label'            => 'Repair Translation URL Hierarchy',
				'description'      => 'Moves translated pages under the correct language root and translated parent pages, then refreshes localized path metadata.',
				'input_schema'     => self::repair_url_hierarchy_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'repair_url_hierarchy', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'ai-translations/repair-internal-links' => array(
				'label'            => 'Repair Translation Internal Links',
				'description'      => 'Rewrites translated content links that still point at English source content to the matching localized translated content.',
				'input_schema'     => self::repair_url_hierarchy_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'repair_internal_links', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'ai-translations/repair-featured-images' => array(
				'label'            => 'Repair Translation Featured Images',
				'description'      => 'Mirrors source featured image state to existing translated pages and posts.',
				'input_schema'     => self::repair_url_hierarchy_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'repair_featured_images', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
		);
	}

	/**
	 * Wrapper that also works if called after the API init hook.
	 *
	 * @param string $name Ability name.
	 * @param array  $args Ability args.
	 */
	private static function register_ability( string $name, array $args ): void {
		$args['category'] = 'site';
		wp_register_ability( $name, $args );
	}

	/**
	 * Empty input schema.
	 */
	private static function empty_input_schema(): array {
		return array(
			'type'                 => array( 'object', 'array', 'null' ),
			'properties'           => array(
				'_' => array(
					'type'        => array( 'string', 'number', 'boolean', 'null' ),
					'description' => 'Optional no-op compatibility field.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Generic output schema.
	 */
	private static function generic_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'message' => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Input schema for the stored Gutenberg content-safety scanner.
	 */
	private static function gutenberg_content_safety_scan_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_types' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'default'     => array( 'page', 'post' ),
					'description' => 'Post types to scan.',
				),
				'post_statuses' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'default'     => array( 'publish', 'draft', 'pending', 'private' ),
					'description' => 'Post statuses to scan.',
				),
				'limit' => array(
					'type'        => 'integer',
					'default'     => 200,
					'minimum'     => 1,
					'maximum'     => 1000,
					'description' => 'Maximum number of posts to scan in this pass.',
				),
				'page' => array(
					'type'        => 'integer',
					'default'     => 1,
					'minimum'     => 1,
					'description' => 'Result page for batched scans.',
				),
				'repair' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Apply safe, output-preserving normalization repairs where possible.',
				),
				'include_clean' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Include clean posts in the response items.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for translation-fitness regression status.
	 */
	private static function translation_fitness_status_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'include_cases' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Include per-case corpus results in the response.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for translation lifecycle regression status.
	 */
	private static function translation_lifecycle_regression_status_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'run_write_test' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'When true, create temporary source and translation posts, run the lifecycle regression, and delete the temporary posts by default.',
				),
				'language' => array(
					'type'        => 'string',
					'description' => 'Optional configured target language. Defaults to sv when available, otherwise the first configured target language.',
				),
				'verify_live' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Run frontend live verification during the temporary publish step. Defaults to false to keep the regression deterministic.',
				),
				'cleanup' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Delete temporary source and translation posts after the write regression.',
				),
				'source_title' => array(
					'type'        => 'string',
					'description' => 'Optional custom source post title for languages without a bundled lifecycle fixture.',
				),
				'source_content' => array(
					'type'        => 'string',
					'description' => 'Optional custom source Gutenberg content for languages without a bundled lifecycle fixture.',
				),
				'source_excerpt' => array(
					'type'        => 'string',
					'description' => 'Optional custom source excerpt.',
				),
				'title' => array(
					'type'        => 'string',
					'description' => 'Optional custom translated title for languages without a bundled lifecycle fixture.',
				),
				'content' => array(
					'type'        => 'string',
					'description' => 'Optional custom translated Gutenberg content for languages without a bundled lifecycle fixture.',
				),
				'excerpt' => array(
					'type'        => 'string',
					'description' => 'Optional custom translated excerpt.',
				),
				'localized_slug' => array(
					'type'        => 'string',
					'description' => 'Optional custom translated slug. Required with custom fixtures.',
				),
				'change_sentence' => array(
					'type'        => 'string',
					'description' => 'Optional sentence appended after review to prove content-change invalidation.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for runtime text updates.
	 */
	private static function runtime_text_input_schema(): array {
		return array(
		'type'                 => 'object',
		'required'             => array( 'language', 'section', 'source' ),
		'properties'           => array(
			'language'   => array(
				'type'        => 'string',
				'description' => 'Configured language code, for example nb, de, ar.',
			),
			'section'    => array(
				'type'        => 'string',
				'enum'        => self::editable_runtime_text_sections(),
				'description' => 'Runtime text section to update.',
			),
			'source'     => array(
				'type'        => 'string',
				'description' => 'Exact source key. For menu_items this is the source page ID as a string.',
			),
			'translated' => array(
				'type'        => 'string',
				'description' => 'Runtime translation value. May include safe HTML for widget/not-found text.',
			),
			'delete'     => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => 'Remove this runtime text override.',
			),
		),
		'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for reading runtime quality profiles.
	 */
	private static function quality_profile_get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'language' => array(
					'type'        => 'string',
					'description' => 'Optional configured language code, for example nb, de, ar. Omit to return all languages.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for updating runtime quality profile overrides.
	 */
	private static function quality_profile_update_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'language' ),
			'properties'           => array(
				'language' => array(
					'type'        => 'string',
					'description' => 'Configured language code, for example nb, de, ar.',
				),
				'profile'  => array(
					'type'        => 'object',
					'description' => 'Runtime quality profile patch. Supported fields include review_language, tone, formality, locale_guidance, preserve_terms, never_translate_terms, localized_terms, review_patterns, naturalness_patterns, agency_copy, script_signals, and frontend_replacements.',
				),
				'delete_fields' => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => self::quality_profile_runtime_fields(),
					),
					'description' => 'Top-level runtime profile fields to remove before applying the patch.',
				),
				'replace' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Replace the whole runtime profile override for the language instead of merging the patch.',
				),
				'clear'   => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Remove the runtime quality profile override for this language.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for audited language-rule events.
	 */
	private static function language_rule_event_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'language', 'rule_type' ),
			'properties'           => array(
				'event_key' => array(
					'type'        => 'string',
					'description' => 'Optional stable key for replacing the same event.',
				),
				'language' => array(
					'type'        => 'string',
					'description' => 'Configured language code.',
				),
				'rule_type' => array(
					'type'        => 'string',
					'enum'        => array( 'source_carryover_homograph', 'script_shadow_exclusion', 'script_signal_option', 'preserve_term', 'avoid_term', 'review_pattern', 'naturalness_pattern', 'preferred_term', 'reviewer_style' ),
					'description' => 'Rule/event type. Active QA-affecting types are merged into the effective language profile.',
				),
				'scope' => array(
					'type'        => 'string',
					'enum'        => array( 'global', 'language', 'site', 'source', 'translation', 'reviewer' ),
					'default'     => 'language',
				),
				'selector' => array( 'type' => 'string' ),
				'decision' => array(
					'type'    => 'string',
					'enum'    => array( 'allow', 'block', 'prefer', 'rewrite', 'flag', 'learn' ),
					'default' => 'flag',
				),
				'source_text' => array( 'type' => 'string' ),
				'target_text' => array( 'type' => 'string' ),
				'replacement' => array( 'type' => 'string' ),
				'reason' => array( 'type' => 'string' ),
				'source' => array( 'type' => 'string' ),
				'source_id' => array( 'type' => 'integer' ),
				'translation_id' => array( 'type' => 'integer' ),
				'reviewer' => array( 'type' => 'string' ),
				'status' => array(
					'type'    => 'string',
					'enum'    => array( 'draft', 'active', 'rejected', 'expired' ),
					'default' => 'active',
				),
				'confidence' => array(
					'type'    => 'number',
					'default' => 1,
				),
				'payload' => array(
					'type'        => 'object',
					'description' => 'Optional structured evidence such as suggestions, principles, preferred_terms, or avoid_terms.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for listing audited language-rule events.
	 */
	private static function language_rule_events_list_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'language' => array( 'type' => 'string' ),
				'status' => array(
					'type' => 'string',
					'enum' => array( 'draft', 'active', 'rejected', 'expired' ),
				),
				'rule_type' => array( 'type' => 'string' ),
				'limit' => array(
					'type'    => 'integer',
					'default' => 50,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for the operator learning inbox.
	 */
	private static function learning_inbox_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'language' => array(
					'type'        => 'string',
					'description' => 'Optional configured language code.',
				),
				'reviewer' => array(
					'type'        => 'string',
					'description' => 'Optional reviewer name.',
				),
				'inbox_status' => array(
					'type'    => 'string',
					'enum'    => array( 'pending', 'used_as_style', 'promoted_to_rule', 'ignored', 'all' ),
					'default' => 'pending',
				),
				'limit' => array(
					'type'    => 'integer',
					'default' => 50,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for reviewing one learning inbox item.
	 */
	private static function learning_event_review_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'action' ),
			'properties'           => array(
				'event_id' => array(
					'type'        => 'integer',
					'description' => 'Learning event row id. Required when event_key is omitted.',
				),
				'event_key' => array(
					'type'        => 'string',
					'description' => 'Learning event key. Required when event_id is omitted.',
				),
				'action' => array(
					'type' => 'string',
					'enum' => array( 'use_as_style', 'promote_to_rule', 'ignore' ),
				),
				'note' => array(
					'type'        => 'string',
					'description' => 'Optional operator review note.',
				),
				'promoted_event_key' => array(
					'type'        => 'string',
					'description' => 'Optional stable event key for a promoted QA rule.',
				),
				'reason' => array(
					'type'        => 'string',
					'description' => 'Optional reason/message for the promoted QA rule.',
				),
				'confidence' => array(
					'type'    => 'number',
					'default' => 0.9,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for agency copy brief.
	 */
	private static function agency_copy_brief_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'source_id' => array(
					'type'        => 'integer',
					'description' => 'Optional source content ID. Required when translation_id is omitted.',
				),
				'translation_id' => array(
					'type'        => 'integer',
					'description' => 'Optional translated content ID. When supplied, source_id and language are inferred from metadata.',
				),
				'language' => array(
					'type'        => 'string',
					'description' => 'Optional language profile for source-only briefs. Omit to review the source in the configured source language, or pass a target language such as ar before translation.',
				),
				'reviewer' => array(
					'type'        => 'string',
					'description' => 'Optional reviewer name. When supplied, approved per-reviewer style learning for this language is included in the brief.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for copy feedback metadata.
	 */
	private static function copy_feedback_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'content_id' => array(
					'type'        => 'integer',
					'description' => 'Source or translated content ID that the feedback belongs to.',
				),
				'translation_id' => array(
					'type'        => 'integer',
					'description' => 'Alias for content_id when feedback belongs to a translation.',
				),
				'feedback_id' => array(
					'type'        => 'string',
					'description' => 'Existing feedback ID to update. Omit to append a new feedback item.',
				),
					'reviewer' => array(
						'type'        => 'string',
						'description' => 'Reviewer name or source.',
					),
				'feedback' => array(
					'type'        => 'string',
					'description' => 'Concrete native or agency copy feedback.',
				),
				'severity' => array(
					'type'        => 'string',
					'enum'        => array( 'info', 'needs_work', 'blocking' ),
					'default'     => 'needs_work',
				),
				'status' => array(
					'type'        => 'string',
					'enum'        => array( 'open', 'resolved' ),
					'default'     => 'open',
				),
					'note' => array(
						'type'        => 'string',
						'description' => 'Optional resolution or handling note.',
					),
					'learn_from_feedback' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Also store this feedback as reusable per-reviewer style guidance.',
					),
					'before' => array(
						'type'        => 'string',
						'description' => 'Optional wording before the human edit.',
					),
					'after' => array(
						'type'        => 'string',
						'description' => 'Optional wording approved after the human edit.',
					),
					'lesson' => array(
						'type'        => 'string',
						'description' => 'Reusable lesson learned from the feedback.',
					),
					'category' => array(
						'type'        => 'string',
						'enum'        => array( 'idiom', 'tone', 'terminology', 'clarity', 'cta', 'structure', 'culture', 'other' ),
						'default'     => 'other',
					),
					'principles' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Reusable reviewer copy principles to remember.',
					),
					'preferred_terms' => array(
						'type'                 => 'object',
						'additionalProperties' => array(
							'oneOf' => array(
								array( 'type' => 'string' ),
								array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
							),
						),
						'description'          => 'Source terms mapped to reviewer-preferred local phrasings.',
					),
					'avoid_terms' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Terms or phrasings this reviewer rejects for the language.',
					),
				),
				'additionalProperties' => false,
			);
	}

	/**
	 * Input schema for reviewer style profile retrieval.
	 */
	private static function reviewer_style_get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'language' => array(
					'type'        => 'string',
					'description' => 'Optional configured language code, for example ar.',
				),
				'reviewer' => array(
					'type'        => 'string',
					'description' => 'Optional reviewer name. Requires language when supplied.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for reviewer style learning.
	 */
	private static function reviewer_style_record_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'reviewer' ),
			'properties'           => array(
				'language' => array(
					'type'        => 'string',
					'description' => 'Configured language code. Inferred from translation_id when possible.',
				),
				'reviewer' => array(
					'type'        => 'string',
					'description' => 'Human reviewer name or stable identifier.',
				),
				'source_id' => array(
					'type'        => 'integer',
					'description' => 'Optional source content ID the edit came from.',
				),
				'translation_id' => array(
					'type'        => 'integer',
					'description' => 'Optional translated content ID the edit came from. Language is inferred from this when possible.',
				),
				'example_id' => array(
					'type'        => 'string',
					'description' => 'Optional stable ID when updating or de-duplicating an example.',
				),
				'before' => array(
					'type'        => 'string',
					'description' => 'Wording before the human edit.',
				),
				'after' => array(
					'type'        => 'string',
					'description' => 'Human-approved wording after the edit.',
				),
				'lesson' => array(
					'type'        => 'string',
					'description' => 'Reusable principle learned from the edit.',
				),
				'category' => array(
					'type'        => 'string',
					'enum'        => array( 'idiom', 'tone', 'terminology', 'clarity', 'cta', 'structure', 'culture', 'other' ),
					'default'     => 'other',
				),
				'principles' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Reusable reviewer copy principles to remember.',
				),
				'preferred_terms' => array(
					'type'                 => 'object',
					'additionalProperties' => array(
						'oneOf' => array(
							array( 'type' => 'string' ),
							array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
					),
					'description'          => 'Source terms mapped to reviewer-preferred local phrasings.',
				),
				'avoid_terms' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Terms or phrasings this reviewer rejects for the language.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for blog taxonomy path updates.
	 */
	private static function blog_taxonomy_paths_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'language' ),
			'properties'           => array(
				'language' => array(
					'type'        => 'string',
					'description' => 'Configured language code, for example nb, de, ar.',
				),
				'blog_path' => array(
					'type'        => 'string',
					'description' => 'Full language-specific blog archive path, for example nb/blogg or ar/almudawana. If omitted, the plugin will use the translated posts page path when available.',
				),
				'category' => array(
					'type'        => 'string',
					'description' => 'Language-specific category URL segment for translated blog archives.',
				),
				'tag'      => array(
					'type'        => 'string',
					'description' => 'Language-specific tag URL segment for translated blog archives.',
				),
				'delete'   => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Remove the configured blog taxonomy path segments for this language.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for page-specific source QA options.
	 */
	private static function source_qa_options_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'source_id' ),
			'properties'           => array(
				'source_id' => array(
					'type'        => 'integer',
					'description' => 'Source WordPress page ID that owns these QA options.',
				),
				'language'  => array(
					'type'        => 'string',
					'description' => 'Optional target language code. Omit or use all for all target languages.',
				),
				'terms'     => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Source-language product, UI, brand, or technical terms that may remain visible in translations for this source page.',
				),
				'delete_terms' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Optional terms to remove from the selected scope.',
				),
				'address_form' => array(
					'type'        => 'string',
					'enum'        => array( 'singular', 'plural', 'neutral' ),
					'description' => 'Expected address form for this source and language. Use singular for practical guides written to one operator; plural for company/team-facing pages.',
				),
				'audience' => array(
					'type'        => 'string',
					'enum'        => array( 'individual_operator', 'business_team', 'general_reader' ),
					'description' => 'Who the page is written to. This is used by QA to keep address form consistent.',
				),
				'clear_addressing' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Remove the selected address-form/audience rule.',
				),
				'replace'   => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Replace the selected scope instead of merging terms.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for public translation cache warming.
	 */
	private static function warm_cache_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'languages'    => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Optional language codes to warm. Defaults to all configured translation languages.',
				),
				'urls'         => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Optional explicit public URLs to warm before indexed translation URLs.',
				),
				'include_home' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Include each language home URL before translated child pages.',
				),
				'limit'        => array(
					'type'        => 'integer',
					'default'     => 100,
					'minimum'     => 1,
					'maximum'     => 500,
					'description' => 'Maximum number of URLs to request.',
				),
				'timeout'      => array(
					'type'        => 'integer',
					'default'     => 15,
					'minimum'     => 3,
					'maximum'     => 30,
					'description' => 'Per-request timeout in seconds.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for translation queue ability.
	 */
	private static function queue_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'source_id'        => array(
					'type'        => 'integer',
					'description' => 'Optional source page ID. When omitted, scans recent published source pages.',
				),
				'limit'            => array(
					'type'        => 'integer',
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 500,
					'description' => 'Maximum number of source pages to inspect when source_id is omitted.',
				),
				'include_complete' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Include source pages where every target language is complete.',
				),
				'statuses'         => array(
					'type'        => 'array',
					'description' => 'Optional queue states to include: missing, stale, draft, needs_review, needs_linguistic_review, ready_to_publish, complete.',
					'items'       => array( 'type' => 'string' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for translation review queue ability.
	 */
	private static function review_queue_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'source_id' => array(
					'type'        => 'integer',
					'description' => 'Optional source page ID. When omitted, scans recent published source pages.',
				),
				'limit'     => array(
					'type'        => 'integer',
					'default'     => 100,
					'minimum'     => 1,
					'maximum'     => 500,
					'description' => 'Maximum number of source pages to inspect when source_id is omitted.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for page quality review queue.
	 */
	private static function quality_review_queue_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'source_id'        => array(
					'type'        => 'integer',
					'description' => 'Optional source page ID. When set, returns quality state for that source and its translations.',
				),
				'page_id'          => array(
					'type'        => 'integer',
					'description' => 'Optional exact page ID, source or translation.',
				),
				'languages'        => array(
					'type'        => 'array',
					'description' => 'Optional language codes to include. Defaults to all configured translation languages.',
					'items'       => array( 'type' => 'string' ),
				),
				'statuses'         => array(
					'type'        => 'array',
					'description' => 'Optional quality states to include: needs_quality_review, quality_review_stale, reviewed, not_published.',
					'items'       => array( 'type' => 'string' ),
				),
				'limit'            => array(
					'type'        => 'integer',
					'default'     => 100,
					'minimum'     => 1,
					'maximum'     => 1000,
					'description' => 'Maximum number of pages to inspect when neither source_id nor page_id is set.',
				),
				'include_reviewed' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Include pages whose quality review is current.',
				),
				'include_source'   => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Include source-language pages in addition to translations.',
				),
				'order'            => array(
					'type'        => 'string',
					'enum'        => array( 'modified_asc', 'modified_desc', 'title_asc' ),
					'default'     => 'modified_asc',
					'description' => 'Queue order. modified_asc surfaces the oldest unreviewed change first.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for the consolidated quality verdict.
	 */
	private static function quality_verdict_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'content_id' => array(
					'type'        => 'integer',
					'description' => 'Source or translated content ID to evaluate.',
				),
				'page_id' => array(
					'type'        => 'integer',
					'description' => 'Alias for content_id.',
				),
				'translation_id' => array(
					'type'        => 'integer',
					'description' => 'Translated content ID to evaluate.',
				),
				'source_id' => array(
					'type'        => 'integer',
					'description' => 'Source content ID. When language is supplied, the matching translation is evaluated if it exists.',
				),
				'language' => array(
					'type'        => 'string',
					'description' => 'Configured language code used with source_id to locate a translation.',
				),
				'include_qa' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Include full local QA details for translations. Only honored for the internal_debug audience.',
				),
				'stage' => array(
					'type'        => 'string',
					'enum'        => array( 'auto', 'pre_publish', 'post_publish' ),
					'default'     => 'auto',
					'description' => 'Verdict stage. pre_publish ignores whole-page quality review, because that review happens after a page can be viewed live.',
				),
				'audience' => array(
					'type'        => 'string',
					'enum'        => array( 'ai_operator', 'internal_debug' ),
					'default'     => 'ai_operator',
					'description' => 'Presentation surface for the verdict. ai_operator is safe for free AI workflows; internal_debug includes raw local evidence.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for marking a page quality-reviewed.
	 */
	private static function quality_review_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'page_id' => array(
					'type'        => 'integer',
					'description' => 'Page ID to mark as quality-reviewed. Can be a source page or translated page.',
				),
				'content_id' => array(
					'type'        => 'integer',
					'description' => 'Content ID to mark as quality-reviewed. Alias for page_id and supports translated posts.',
				),
				'reviewer' => array(
					'type'        => 'string',
					'description' => 'Reviewer name.',
				),
				'note' => array(
					'type'        => 'string',
					'description' => 'Short review note.',
				),
				'full_page_reviewed' => array(
					'type'        => 'boolean',
					'description' => 'The whole visible page was reviewed, not only a single phrase.',
				),
				'native_language_reviewed' => array(
					'type'        => 'boolean',
					'description' => 'The page was reviewed in the target language by native-language standards.',
				),
				'customer_visible_copy_reviewed' => array(
					'type'        => 'boolean',
					'description' => 'Customer-visible wording, CTAs, headings, and action language were reviewed.',
				),
				'factual_accuracy_reviewed' => array(
					'type'        => 'boolean',
					'description' => 'Facts, links, offers, names, and technical claims were preserved or corrected.',
				),
				'links_and_actions_reviewed' => array(
					'type'        => 'boolean',
					'description' => 'Links, internal-link opportunities, forms, share text, mailto subjects, and action wording were checked in moderation.',
				),
				'agency_copy_reviewed' => array(
					'type'        => 'boolean',
					'description' => 'Required when agency-copy profile is enabled: buyer, promise, proof, objection risk, and action were reviewed as conversion copy.',
				),
				'reader_action_clear' => array(
					'type'        => 'boolean',
					'description' => 'Required when agency-copy profile is enabled: the reader knows what to send or do next and what AI Translation Workflow will return.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Ability metadata helper.
	 */
	private static function ability_meta( bool $readonly, bool $destructive, bool $idempotent ): array {
		return array(
			'annotations' => array(
				'readonly'    => $readonly,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
		);
	}

	/**
	 * Input schema for upsert ability.
	 */
	private static function upsert_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'source_id', 'language', 'localized_slug', 'title', 'content' ),
			'properties'           => array(
				'source_id'         => array( 'type' => 'integer' ),
				'language'          => array( 'type' => 'string' ),
				'localized_slug'    => array( 'type' => 'string' ),
				'localized_parent_path' => array(
					'type'        => 'string',
					'description' => 'Optional page parent path under the language prefix, such as tjenester or tjenester/seo. Page translations only.',
				),
				'localized_parent_id' => array( 'type' => 'integer' ),
				'localized_path'      => array(
					'type'        => 'string',
					'description' => 'Optional full localized path for translated posts, such as nb/blogg/flerspraklige-utfordringer. Defaults to the localized blog archive path plus slug.',
				),
				'allow_year_in_url'   => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Set true only when the year is the article basis, not just a freshness/update marker.',
				),
				'year_url_reason'     => array(
					'type'        => 'string',
					'description' => 'Required when allow_year_in_url is true. Explain why the year belongs in the URL.',
				),
				'allow_source_slug_in_url' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Set true only when a transliterated-language URL segment should intentionally keep the source slug, such as a brand or proper-name URL.',
				),
				'source_slug_reason'  => array(
					'type'        => 'string',
					'description' => 'Required when allow_source_slug_in_url is true. Explain why the source slug belongs in the localized URL.',
				),
				'taxonomies'          => array(
					'type'        => 'object',
					'description' => 'Optional localized taxonomy terms for post translations. If omitted, source categories and tags are mirrored into language-scoped term variants.',
					'properties'  => array(
						'category' => array(
							'type'        => 'array',
							'description' => 'Localized categories keyed by source term. Each item supports source_term_id, name, and slug.',
							'items'       => self::taxonomy_term_input_schema(),
						),
						'post_tag' => array(
							'type'        => 'array',
							'description' => 'Localized tags keyed by source term. Each item supports source_term_id, name, and slug.',
							'items'       => self::taxonomy_term_input_schema(),
						),
					),
					'additionalProperties' => false,
				),
				'title'             => array( 'type' => 'string' ),
				'content'           => array( 'type' => 'string' ),
				'excerpt'           => array( 'type' => 'string' ),
				'seo'               => array(
					'type'        => 'object',
					'description' => 'Optional Rank Math SEO metadata for the translated content. If omitted, the workflow syncs the SEO title from the translated title and the description from the excerpt or visible content.',
					'properties'  => array(
						'title' => array(
							'type'        => 'string',
							'description' => 'Localized SEO title. Alias: seo_title.',
						),
						'seo_title' => array(
							'type'        => 'string',
							'description' => 'Localized SEO title.',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'Localized meta description. Alias: seo_description.',
						),
						'seo_description' => array(
							'type'        => 'string',
							'description' => 'Localized meta description.',
						),
						'focus_keyword' => array(
							'type'        => 'string',
							'description' => 'Localized Rank Math focus keyword(s), comma-separated when needed.',
						),
						'keyword' => array(
							'type'        => 'string',
							'description' => 'Alias for focus_keyword.',
						),
					),
					'additionalProperties' => false,
				),
				'status'            => array(
					'type'    => 'string',
					'default' => 'draft',
					'enum'    => array( 'draft', 'pending', 'private', 'publish' ),
				),
				'translation_status' => array(
					'type'        => 'string',
					'default'     => 'needs_review',
					'description' => 'Use publish-translation to publish reviewed translations.',
					'enum'        => array( 'draft', 'needs_review', 'reviewed', 'stale' ),
				),
				'parent_status'     => array(
					'type'    => 'string',
					'default' => 'draft',
					'enum'    => array( 'draft', 'private', 'publish' ),
				),
				'translation_id'    => array( 'type' => 'integer' ),
				'allow_update_published' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for authored-original intake queue.
	 */
	private static function authored_original_intake_queue_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'    => array(
					'type'        => 'integer',
					'description' => 'Optional exact authored original post/page ID.',
				),
				'limit'      => array(
					'type'        => 'integer',
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 500,
					'description' => 'Maximum number of intake items to return.',
				),
				'statuses'   => array(
					'type'        => 'array',
					'description' => 'Optional intake statuses: pending, stale, source_created, completed, ignored, error.',
					'items'       => array( 'type' => 'string' ),
				),
				'languages'  => array(
					'type'        => 'array',
					'description' => 'Optional authored original language filter.',
					'items'       => array( 'type' => 'string' ),
				),
				'post_types' => array(
					'type'        => 'array',
					'description' => 'Optional post type filter. Defaults to page and post.',
					'items'       => array( 'type' => 'string' ),
				),
				'include_ignored' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Include ignored items when no explicit statuses filter is supplied.',
				),
				'include_completed' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Include completed items when no explicit statuses filter is supplied.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for updating authored-original intake state.
	 */
	private static function authored_original_intake_update_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'status' ),
			'properties'           => array(
				'post_id'  => array( 'type' => 'integer' ),
				'status'   => array(
					'type' => 'string',
					'enum' => array( 'pending', 'ignored', 'error' ),
				),
				'language' => array(
					'type'        => 'string',
					'description' => 'Optional authored original language. Required when reopening an item that has no detected language.',
				),
				'note'     => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for creating an English technical source from a non-source authored original.
	 */
	private static function authored_original_source_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'authored_id', 'authored_language', 'english_title', 'english_content', 'english_slug' ),
			'properties'           => array(
				'authored_id'       => array(
					'type'        => 'integer',
					'description' => 'Existing WordPress post/page ID containing the human-authored original in a configured non-source language.',
				),
				'authored_language' => array( 'type' => 'string' ),
				'english_source_id' => array(
					'type'        => 'integer',
					'description' => 'Optional existing English source post/page to update. If omitted, an existing generated source linked from the authored original is reused, or a new source is created.',
				),
				'english_title'     => array( 'type' => 'string' ),
				'english_content'   => array(
					'type'        => 'string',
					'description' => 'English Gutenberg content generated from the authored original.',
				),
				'english_excerpt'   => array( 'type' => 'string' ),
				'english_slug'      => array( 'type' => 'string' ),
				'english_parent_id' => array(
					'type'        => 'integer',
					'description' => 'Optional parent page for generated English pages.',
				),
				'english_status'    => array(
					'type'    => 'string',
					'default' => 'draft',
					'enum'    => array( 'draft', 'pending', 'private' ),
				),
				'allow_year_in_url' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Set true only when the year is the article basis, not just a freshness/update marker.',
				),
				'year_url_reason'   => array(
					'type'        => 'string',
					'description' => 'Required when allow_year_in_url is true. Explain why the year belongs in the URL.',
				),
				'attach_original'   => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Attach the authored original as the translation for authored_language without rewriting its content.',
				),
				'original_translation_status' => array(
					'type'    => 'string',
					'default' => 'reviewed',
					'enum'    => array( 'draft', 'needs_review', 'reviewed' ),
				),
				'seo'               => array(
					'type'        => 'object',
					'description' => 'Optional Rank Math metadata for the generated English source.',
					'properties'  => array(
						'title' => array( 'type' => 'string' ),
						'seo_title' => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'seo_description' => array( 'type' => 'string' ),
						'focus_keyword' => array( 'type' => 'string' ),
						'keyword' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for reviewing a generated English technical source.
	 */
	private static function source_generation_review_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'source_id', 'meaning_preserved' ),
			'properties'           => array(
				'source_id'         => array( 'type' => 'integer' ),
				'reviewer'          => array( 'type' => 'string' ),
				'note'              => array( 'type' => 'string' ),
				'meaning_preserved' => array(
					'type'        => 'boolean',
					'description' => 'Required true: the generated English source preserves the authored original meaning, claims, intent, and structure closely enough for downstream translation.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for one localized taxonomy term.
	 */
	private static function taxonomy_term_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'source_term_id' ),
			'properties'           => array(
				'source_term_id' => array(
					'type'        => 'integer',
					'description' => 'Source category/tag term ID from the English post.',
				),
				'name'           => array(
					'type'        => 'string',
					'description' => 'Localized term name.',
				),
				'slug'           => array(
					'type'        => 'string',
					'description' => 'Localized term slug. Languages requiring transliterated URLs must use ASCII.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for menu sync.
	 */
	private static function sync_menu_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'language' ),
			'properties'           => array(
				'language'             => array( 'type' => 'string' ),
				'source_menu'          => array(
					'type'        => array( 'string', 'integer' ),
					'description' => 'Source menu name, slug, or ID. Defaults to Main Menu.',
				),
				'target_menu_name'     => array( 'type' => 'string' ),
				'clear_existing'       => array( 'type' => 'boolean', 'default' => true ),
				'preserve_existing_labels' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Preserve labels already stored on matching target menu items. Set false only for an intentional label rebuild.',
				),
				'include_untranslated' => array( 'type' => 'boolean', 'default' => false ),
				'include_custom_links' => array( 'type' => 'boolean', 'default' => true ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for URL hierarchy repair.
	 */
	private static function repair_url_hierarchy_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'languages'  => array(
					'type'        => 'array',
					'description' => 'Optional list of target languages to repair. Defaults to all target languages.',
					'items'       => array( 'type' => 'string' ),
				),
				'source_ids' => array(
					'type'        => 'array',
					'description' => 'Optional source page IDs to repair. Defaults to all translated source pages.',
					'items'       => array( 'type' => 'integer' ),
				),
				'dry_run'    => array( 'type' => 'boolean', 'default' => false ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for QA ability.
	 */
	private static function qa_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'translation_id' ),
			'properties'           => array(
				'translation_id'  => array( 'type' => 'integer' ),
				'forbidden_terms' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'required_terms'  => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for internal-link opportunity analysis.
	 */
	private static function internal_link_opportunities_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'content_id'     => array( 'type' => 'integer' ),
				'page_id'        => array( 'type' => 'integer' ),
				'post_id'        => array( 'type' => 'integer' ),
				'translation_id' => array( 'type' => 'integer' ),
				'source_id'      => array( 'type' => 'integer' ),
				'language'       => array( 'type' => 'string' ),
				'limit'          => array(
					'type'    => 'integer',
					'default' => 3,
					'minimum' => 1,
					'maximum' => 5,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for publish ability.
	 */
	private static function publish_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'translation_id' ),
			'properties'           => array(
				'translation_id'       => array( 'type' => 'integer' ),
				'run_qa'               => array( 'type' => 'boolean', 'default' => true ),
				'sync_menu'            => array( 'type' => 'boolean', 'default' => true ),
				'include_custom_links' => array( 'type' => 'boolean', 'default' => true ),
				'allow_warnings'       => array( 'type' => 'boolean', 'default' => true ),
				'verify_live'          => array( 'type' => 'boolean', 'default' => true ),
				'live_verification_timeout' => array(
					'type'        => 'integer',
					'default'     => 15,
					'minimum'     => 3,
					'maximum'     => 30,
					'description' => 'Frontend fetch timeout in seconds for the post-publish live verification.',
				),
				'forbidden_terms'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'required_terms'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for live verification ability.
	 */
	private static function verify_live_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'translation_id' ),
			'properties'           => array(
				'translation_id' => array( 'type' => 'integer' ),
				'timeout'        => array(
					'type'        => 'integer',
					'default'     => 15,
					'minimum'     => 3,
					'maximum'     => 30,
					'description' => 'Frontend fetch timeout in seconds.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for linguistic review marker.
	 */
	private static function linguistic_review_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'translation_id' ),
			'properties'           => array(
				'translation_id' => array( 'type' => 'integer' ),
				'reviewer'       => array( 'type' => 'string' ),
				'note'           => array( 'type' => 'string' ),
				'natural_language_reviewed' => array(
					'type'        => 'boolean',
					'description' => 'Required true: text reads naturally in the target language, not as translated English.',
				),
				'direct_translation_reviewed' => array(
					'type'        => 'boolean',
					'description' => 'Required true: literal/direct-translation phrasing has been actively checked and fixed.',
				),
				'conversion_copy_reviewed' => array(
					'type'        => 'boolean',
					'description' => 'Required true: response-critical headings, leads, CTAs, and contact copy have been reviewed for the target language.',
				),
				'source_fidelity_reviewed' => array(
					'type'        => 'boolean',
					'description' => 'Required true: translation preserves the source page meaning, claims, structure, and intent without unsupported additions or free copywriting.',
				),
				'locale_terminology_reviewed' => array(
					'type'        => 'boolean',
					'description' => 'Required true: terminology, brand/product names, idioms, and market-specific wording have been checked against the target country/language, not just translated mechanically.',
				),
				'non_specialist_clarity_reviewed' => array(
					'type'        => 'boolean',
					'description' => 'Required when agency-copy profile is enabled: a reader who does not already know the service category can still understand the problem, offer, and next step.',
				),
				'jargon_explained_or_removed' => array(
					'type'        => 'boolean',
					'description' => 'Required when agency-copy profile is enabled: marketing, SEO, AI, or technical jargon is explained through concrete customer outcomes or removed.',
				),
				'local_market_copy_reviewed' => array(
					'type'        => 'boolean',
					'description' => 'Required when agency-copy profile is enabled: wording, register, and rhythm were reviewed as local market copy, not only correct translation.',
				),
				'run_qa'         => array( 'type' => 'boolean', 'default' => true ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Query translated content with consistent low-overhead defaults.
	 *
	 * @param array<string,mixed> $args Query args.
	 * @return WP_Query
	 */
	private static function translation_content_query( array $args ): WP_Query {
		$defaults = array(
			'post_type'              => self::translatable_post_types(),
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		);

		return new WP_Query( array_replace( $defaults, $args ) );
	}

	/**
	 * Backward-compatible wrapper for older page-named call sites.
	 *
	 * @param array<string,mixed> $args Query args.
	 * @return WP_Query
	 */
	private static function translation_page_query( array $args ): WP_Query {
		return self::translation_content_query( $args );
	}

	/**
	 * Query source content with consistent low-overhead defaults.
	 *
	 * @param array<string,mixed> $args Query args.
	 * @return WP_Query
	 */
	private static function source_content_query( array $args ): WP_Query {
		$defaults = array(
			'post_type'              => self::translatable_post_types(),
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		);

		return new WP_Query( array_replace( $defaults, $args ) );
	}

	/**
	 * Backward-compatible wrapper for older page-named call sites.
	 *
	 * @param array<string,mixed> $args Query args.
	 * @return WP_Query
	 */
	private static function source_page_query( array $args ): WP_Query {
		return self::source_content_query( $args );
	}

	/**
	 * Get a source content payload.
	 */
	private static function get_source_payload( int $source_id ): array {
		$post = get_post( $source_id );
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) || self::is_translation_post( $source_id ) ) {
			return self::error( 'Source content not found.' );
		}

		return array(
			'success'      => true,
			'source'       => self::post_payload( $post ),
			'source_hash'  => self::source_hash( $post ),
			'runtime_readiness' => self::language_runtime_readiness_map( (string) $post->post_type ),
			'translations' => self::translation_rows_for_source( $source_id ),
		);
	}

	/**
	 * Create or update an English technical source from a human-authored non-source original.
	 */
	private static function create_source_from_authored_original( array $input ): array {
		$authored_id = absint( $input['authored_id'] ?? 0 );
		$authored    = get_post( $authored_id );
		if ( ! $authored || ! self::is_translatable_post_type( (string) $authored->post_type ) ) {
			return self::error( 'Authored original content not found.' );
		}

		$authored_language = sanitize_key( (string) ( $input['authored_language'] ?? '' ) );
		if ( ! self::is_translation_language( $authored_language ) ) {
			return self::error( 'Authored original language must be a configured non-source language.' );
		}

		$existing_language = sanitize_key( (string) get_post_meta( $authored_id, self::META_LANGUAGE, true ) );
		$existing_source_id = absint( get_post_meta( $authored_id, self::META_SOURCE_ID, true ) );
		$existing_generated_source_id = absint( get_post_meta( $authored_id, self::META_GENERATED_SOURCE_ID, true ) );
		if ( $existing_language && ( $existing_language !== $authored_language || ( $existing_source_id && ! $existing_generated_source_id ) ) ) {
			return self::error( 'Authored original is already attached to another translation family.' );
		}

		$english_title = sanitize_text_field( (string) ( $input['english_title'] ?? '' ) );
		if ( '' === $english_title ) {
			return self::error( 'English title is required.' );
		}
		$english_content = self::normalize_gutenberg_content_for_storage( (string) ( $input['english_content'] ?? '' ) );
		if ( '' === trim( $english_content ) ) {
			return self::error( 'English content is required.' );
		}
		$english_excerpt = isset( $input['english_excerpt'] ) ? sanitize_textarea_field( (string) $input['english_excerpt'] ) : '';
		$raw_slug        = (string) ( $input['english_slug'] ?? '' );
		$slug            = sanitize_title( $raw_slug );
		if ( '' === $slug ) {
			return self::error( 'English slug is required.' );
		}
		$year_issue = self::validate_years_in_url_parts(
			array( $slug ),
			! empty( $input['allow_year_in_url'] ),
			(string) ( $input['year_url_reason'] ?? '' )
		);
		if ( $year_issue ) {
			return $year_issue;
		}
		if ( self::has_wordpress_duplicate_slug_suffix( $slug ) ) {
			return self::error( 'English slug must not end with a WordPress duplicate suffix such as -2. Resolve the route collision instead.' );
		}

		$post_type = (string) $authored->post_type;
		$source_id = absint( $input['english_source_id'] ?? 0 );
		if ( ! $source_id ) {
			$source_id = $existing_generated_source_id;
		}
		if ( $source_id ) {
			$source = get_post( $source_id );
			if ( ! $source || $post_type !== (string) $source->post_type || self::is_translation_post( $source_id ) ) {
				return self::error( 'Existing English source does not match the authored original post type or is already a translation.' );
			}
		}

		$parent_id = 0;
		if ( 'page' === $post_type ) {
			$parent_id = isset( $input['english_parent_id'] ) ? absint( $input['english_parent_id'] ) : ( $source_id ? (int) get_post_field( 'post_parent', $source_id ) : 0 );
		}
		$slug_conflicts = self::translation_slug_conflicts( $slug, $post_type, $parent_id, $source_id );
		if ( $slug_conflicts ) {
			return array(
				'success'          => false,
				'message'          => 'English source slug is already in use. Resolve the route collision before saving; WordPress duplicate slugs such as -2 are not allowed.',
				'code'             => 'english_source_slug_collision',
				'requested_slug'   => $slug,
				'post_type'        => $post_type,
				'parent_id'        => $parent_id,
				'source_id'        => $source_id,
				'conflicting_posts'=> $slug_conflicts,
			);
		}

		$status  = self::sanitize_post_status( (string) ( $input['english_status'] ?? 'draft' ), 'draft' );
		if ( 'publish' === $status ) {
			return self::error( 'Generated English sources must be reviewed before publishing.' );
		}
		$postarr = array(
			'post_type'    => $post_type,
			'post_author'  => (int) $authored->post_author,
			'post_title'   => $english_title,
			'post_name'    => $slug,
			'post_content' => $english_content,
			'post_excerpt' => $english_excerpt,
			'post_status'  => $status,
		);
		if ( 'page' === $post_type ) {
			$postarr['post_parent'] = $parent_id;
		}
		if ( 'post' === $post_type ) {
			$postarr = array_merge( $postarr, self::source_publication_date_fields( $authored ) );
		}

		$result = 0;
		self::with_slug_change_unlock(
			static function () use ( &$result, $source_id, $postarr ): void {
				self::with_reviewer_style_capture_suspended(
					static function () use ( &$result, $source_id, $postarr ): void {
						if ( $source_id ) {
							$postarr['ID'] = $source_id;
							$result        = wp_update_post( wp_slash( $postarr ), true );
						} else {
							$result = wp_insert_post( wp_slash( $postarr ), true );
						}
					}
				);
			}
		);
		if ( is_wp_error( $result ) ) {
			return self::error( $result->get_error_message() );
		}

		$source_id = (int) $result;
		$source    = get_post( $source_id );
		if ( ! $source || $slug !== (string) $source->post_name || self::has_wordpress_duplicate_slug_suffix( (string) $source->post_name ) ) {
			return array(
				'success'        => false,
				'message'        => 'WordPress changed the English source slug during save. Resolve the route collision before saving.',
				'code'           => 'english_source_slug_rewritten',
				'requested_slug' => $slug,
				'actual_slug'    => $source ? (string) $source->post_name : '',
				'source_id'      => $source_id,
			);
		}

		self::copy_authored_original_presentation_to_generated_source( $source_id, $authored );
		$english_seo = self::sync_rank_math_translation_seo_meta( $source_id, $input, $english_title, $english_excerpt, $english_content );
		$authored_hash = self::source_hash( $authored );
		$source_hash   = self::source_hash( $source );
		self::store_generated_source_provenance( $source_id, $authored_id, $authored_language, $authored_hash, 'needs_review' );
		self::mark_authored_original_intake( $authored_id, $authored_language, 'source_created', 'english_source_created' );

		$attach_result = array( 'success' => true, 'attached' => false );
		if ( ! array_key_exists( 'attach_original', $input ) || ! empty( $input['attach_original'] ) ) {
			$attach_result = self::attach_authored_original_translation(
				$authored_id,
				$source_id,
				$authored_language,
				self::sanitize_translation_status( (string) ( $input['original_translation_status'] ?? 'reviewed' ) )
			);
			if ( empty( $attach_result['success'] ) ) {
				return $attach_result;
			}
		}

		return array(
			'success'                  => true,
			'message'                  => 'Generated English source saved and authored original attached.',
			'english_source'           => self::post_payload( get_post( $source_id ) ),
			'authored_original'        => self::translation_payload( get_post( $authored_id ) ),
			'authored_language'        => $authored_language,
			'authored_original_hash'   => $authored_hash,
			'generated_source_hash'    => $source_hash,
			'source_generation_status' => self::source_generation_status_for_source( $source_id ),
			'downstream_ready'         => false,
			'attach_original'          => $attach_result,
			'seo_meta'                 => $english_seo,
		);
	}

	/**
	 * Mark a generated English source reviewed against its authored original.
	 */
	private static function mark_source_generation_reviewed( array $input ): array {
		$source_id = absint( $input['source_id'] ?? 0 );
		$source    = get_post( $source_id );
		if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) || self::is_translation_post( $source_id ) ) {
			return self::error( 'Generated English source not found.' );
		}
		$authored_id = absint( get_post_meta( $source_id, self::META_AUTHORED_ORIGINAL_ID, true ) );
		$authored    = $authored_id ? get_post( $authored_id ) : null;
		if ( ! $authored ) {
			return self::error( 'Generated English source is not linked to an authored original.' );
		}
		if ( empty( $input['meaning_preserved'] ) ) {
			return self::error( 'meaning_preserved must be true before downstream translations can use this generated source.' );
		}

		$reviewer = sanitize_text_field( (string) ( $input['reviewer'] ?? '' ) );
		if ( '' === $reviewer ) {
			$user = wp_get_current_user();
			$reviewer = $user && $user->exists() ? self::reviewer_name_for_user( $user ) : 'unknown';
		}
		$note = sanitize_textarea_field( (string) ( $input['note'] ?? '' ) );
		$authored_language = sanitize_key( (string) get_post_meta( $source_id, self::META_AUTHORED_LANGUAGE, true ) );
		$authored_hash     = self::source_hash( $authored );
		$source_hash       = self::source_hash( $source );
		$evidence = array(
			'schema_version'      => 1,
			'plugin_version'      => self::VERSION,
			'recorded_at'         => gmdate( 'c' ),
			'reviewer'            => $reviewer,
			'authored_original_id'=> $authored_id,
			'authored_language'   => $authored_language,
			'authored_hash'       => $authored_hash,
			'generated_source_id' => $source_id,
			'generated_source_hash' => $source_hash,
			'meaning_preserved'   => true,
			'note'                => $note,
		);

		foreach ( array( $source_id, $authored_id ) as $post_id ) {
			update_post_meta( $post_id, self::META_SOURCE_GENERATION_STATUS, 'reviewed' );
			update_post_meta( $post_id, self::META_SOURCE_GENERATION_REVIEWED_AT, gmdate( 'c' ) );
			update_post_meta( $post_id, self::META_SOURCE_GENERATION_REVIEWER, $reviewer );
			update_post_meta( $post_id, self::META_SOURCE_GENERATION_NOTE, $note );
			update_post_meta( $post_id, self::META_SOURCE_GENERATION_EVIDENCE, wp_json_encode( $evidence ) );
			update_post_meta( $post_id, self::META_GENERATED_SOURCE_FROM_HASH, $authored_hash );
		}
		update_post_meta( $authored_id, self::META_SOURCE_HASH, $source_hash );
		self::sync_translation_index_row( $authored_id );
		self::mark_authored_original_intake( $authored_id, $authored_language, 'completed', 'source_generation_reviewed' );

		return array(
			'success'                  => true,
			'message'                  => 'Generated English source reviewed against authored original.',
			'english_source'           => self::post_payload( $source ),
			'authored_original'        => self::translation_payload( $authored ),
			'source_generation_status' => self::source_generation_status_for_source( $source_id ),
			'downstream_ready'         => true,
		);
	}

	/**
	 * Store provenance shared by the generated English source and authored original.
	 */
	private static function store_generated_source_provenance( int $source_id, int $authored_id, string $authored_language, string $authored_hash, string $status ): void {
		$status = self::sanitize_source_generation_status( $status );
		update_post_meta( $source_id, self::META_AUTHORED_ORIGINAL_ID, $authored_id );
		update_post_meta( $source_id, self::META_AUTHORED_LANGUAGE, sanitize_key( $authored_language ) );
		update_post_meta( $source_id, self::META_GENERATED_SOURCE_FROM_HASH, $authored_hash );
		update_post_meta( $source_id, self::META_SOURCE_GENERATION_STATUS, $status );
		update_post_meta( $authored_id, self::META_AUTHORED_ORIGINAL_ID, $authored_id );
		update_post_meta( $authored_id, self::META_AUTHORED_LANGUAGE, sanitize_key( $authored_language ) );
		update_post_meta( $authored_id, self::META_GENERATED_SOURCE_ID, $source_id );
		update_post_meta( $authored_id, self::META_GENERATED_SOURCE_FROM_HASH, $authored_hash );
		update_post_meta( $authored_id, self::META_SOURCE_GENERATION_STATUS, $status );
		if ( 'reviewed' !== $status ) {
			foreach ( array( self::META_SOURCE_GENERATION_REVIEWED_AT, self::META_SOURCE_GENERATION_REVIEWER, self::META_SOURCE_GENERATION_NOTE, self::META_SOURCE_GENERATION_EVIDENCE ) as $meta_key ) {
				delete_post_meta( $source_id, $meta_key );
				delete_post_meta( $authored_id, $meta_key );
			}
		}
	}

	/**
	 * Attach the authored original as its language's translation row.
	 */
	private static function attach_authored_original_translation( int $authored_id, int $source_id, string $language, string $translation_status ): array {
		$authored = get_post( $authored_id );
		$source   = get_post( $source_id );
		if ( ! $authored || ! $source ) {
			return self::error( 'Cannot attach authored original without both source and original posts.' );
		}
		$language = sanitize_key( $language );
		if ( ! self::is_translation_language( $language ) ) {
			return self::error( 'Cannot attach authored original for an unknown translation language.' );
		}
		if ( $authored->post_type !== $source->post_type ) {
			return self::error( 'Authored original and generated source post types do not match.' );
		}
		$existing_id = self::find_translation_id( $source_id, $language, array( 'publish', 'draft', 'pending', 'private', 'future' ) );
		if ( $existing_id && $existing_id !== $authored_id ) {
			return array(
				'success' => false,
				'message' => 'A different translation already exists for this generated source and language.',
				'code'    => 'authored_original_translation_collision',
				'existing_translation_id' => $existing_id,
				'authored_id' => $authored_id,
				'source_id' => $source_id,
				'language'  => $language,
			);
		}

		update_post_meta( $authored_id, self::META_SOURCE_ID, $source_id );
		update_post_meta( $authored_id, self::META_LANGUAGE, $language );
		update_post_meta( $authored_id, self::META_SOURCE_HASH, self::source_hash( $source ) );
		update_post_meta( $authored_id, self::META_STATUS, self::sanitize_translation_status( $translation_status ) );
		update_post_meta( $authored_id, self::META_LOCALIZED_PATH, self::localized_path_for_post( $authored_id, $language ) );
		if ( in_array( $translation_status, array( 'reviewed', 'published' ), true ) ) {
			update_post_meta( $authored_id, self::META_REVIEWED_AT, gmdate( 'c' ) );
		}
		self::sync_translation_index_row( $authored_id );

		return array(
			'success' => true,
			'attached' => true,
			'translation' => self::translation_payload( get_post( $authored_id ) ),
		);
	}

	/**
	 * Copy authored-original presentation choices to the generated English source.
	 */
	private static function copy_authored_original_presentation_to_generated_source( int $source_id, WP_Post $authored ): void {
		$thumbnail_id = absint( get_post_thumbnail_id( $authored ) );
		if ( $thumbnail_id ) {
			set_post_thumbnail( $source_id, $thumbnail_id );
		}
		if ( 'page' === $authored->post_type ) {
			$template = get_page_template_slug( $authored );
			if ( $template ) {
				update_post_meta( $source_id, '_wp_page_template', $template );
			} else {
				delete_post_meta( $source_id, '_wp_page_template' );
			}
		}
		foreach ( array( '_generate-disable-headline', '_generate-disable-nav', '_generate-disable-footer', '_generate-disable-footer-widgets', '_generate-sidebar-layout-meta', '_generate-full-width-content', '_generate-transparent-header' ) as $meta_key ) {
			$value = get_post_meta( $authored->ID, $meta_key, true );
			if ( '' === (string) $value ) {
				delete_post_meta( $source_id, $meta_key );
			} else {
				update_post_meta( $source_id, $meta_key, $value );
			}
		}
	}

	/**
	 * Status and evidence for a generated English source.
	 */
	private static function source_generation_status_for_source( int $source_id ): array {
		$source = get_post( $source_id );
		if ( ! $source ) {
			return array();
		}
		$authored_id = absint( get_post_meta( $source_id, self::META_AUTHORED_ORIGINAL_ID, true ) );
		$authored    = $authored_id ? get_post( $authored_id ) : null;
		if ( ! $authored ) {
			return array(
				'is_generated_source' => false,
			);
		}
		$stored_hash  = (string) get_post_meta( $source_id, self::META_GENERATED_SOURCE_FROM_HASH, true );
		$current_hash = self::source_hash( $authored );
		$status       = self::sanitize_source_generation_status( (string) get_post_meta( $source_id, self::META_SOURCE_GENERATION_STATUS, true ) );
		if ( $stored_hash && $current_hash && $stored_hash !== $current_hash ) {
			$status = 'stale';
		}
		$evidence = self::source_generation_evidence_for_post( $source_id );

		return array(
			'is_generated_source' => true,
			'status'             => $status,
			'downstream_ready'   => 'reviewed' === $status,
			'authored_original_id' => $authored_id,
			'authored_language'  => sanitize_key( (string) get_post_meta( $source_id, self::META_AUTHORED_LANGUAGE, true ) ),
			'stored_authored_hash' => $stored_hash,
			'current_authored_hash'=> $current_hash,
			'reviewed_at'        => (string) get_post_meta( $source_id, self::META_SOURCE_GENERATION_REVIEWED_AT, true ),
			'reviewer'           => (string) get_post_meta( $source_id, self::META_SOURCE_GENERATION_REVIEWER, true ),
			'note'               => (string) get_post_meta( $source_id, self::META_SOURCE_GENERATION_NOTE, true ),
			'evidence'           => $evidence,
		);
	}

	/**
	 * Gate downstream translation from generated English sources until reviewed.
	 */
	private static function generated_source_downstream_gate( int $source_id, string $target_language ): ?array {
		$status = self::source_generation_status_for_source( $source_id );
		if ( empty( $status['is_generated_source'] ) ) {
			return null;
		}
		$authored_language = sanitize_key( (string) ( $status['authored_language'] ?? '' ) );
		if ( $target_language === $authored_language ) {
			return null;
		}
		if ( ! empty( $status['downstream_ready'] ) ) {
			return null;
		}

		return array(
			'success' => false,
			'message' => 'Generated English source must be reviewed against its authored original before downstream translations can be created or updated.',
			'code'    => 'generated_source_needs_review',
			'source_id' => $source_id,
			'target_language' => $target_language,
			'source_generation_status' => $status,
			'suggested_ability' => 'ai-translations/mark-source-generation-reviewed',
		);
	}

	/**
	 * Mark a generated source and downstream translations stale after the authored original changes.
	 */
	private static function mark_generated_source_stale_from_authored_original( int $authored_id ): void {
		$source_id = absint( get_post_meta( $authored_id, self::META_GENERATED_SOURCE_ID, true ) );
		if ( ! $source_id ) {
			return;
		}
		$authored = get_post( $authored_id );
		if ( ! $authored ) {
			return;
		}
		$current_hash = self::source_hash( $authored );
		foreach ( array( $source_id, $authored_id ) as $post_id ) {
			update_post_meta( $post_id, self::META_SOURCE_GENERATION_STATUS, 'stale' );
			update_post_meta( $post_id, self::META_GENERATED_SOURCE_FROM_HASH, $current_hash );
			delete_post_meta( $post_id, self::META_SOURCE_GENERATION_REVIEWED_AT );
			delete_post_meta( $post_id, self::META_SOURCE_GENERATION_REVIEWER );
			delete_post_meta( $post_id, self::META_SOURCE_GENERATION_NOTE );
			delete_post_meta( $post_id, self::META_SOURCE_GENERATION_EVIDENCE );
		}
		$authored_language = sanitize_key( (string) get_post_meta( $authored_id, self::META_AUTHORED_LANGUAGE, true ) );
		if ( self::is_translation_language( $authored_language ) ) {
			self::mark_authored_original_intake( $authored_id, $authored_language, 'stale', 'authored_original_changed' );
		}
		foreach ( self::translation_rows_for_source( $source_id ) as $translation ) {
			$translation_id = absint( $translation['id'] ?? 0 );
			if ( ! $translation_id || $translation_id === $authored_id ) {
				continue;
			}
			update_post_meta( $translation_id, self::META_STATUS, 'stale' );
			self::sync_translation_index_row( $translation_id );
		}
	}

	/**
	 * Sanitize generated-source review status.
	 */
	private static function sanitize_source_generation_status( string $status ): string {
		$status  = sanitize_key( $status );
		$allowed = array( 'needs_review', 'reviewed', 'stale' );
		return in_array( $status, $allowed, true ) ? $status : 'needs_review';
	}

	/**
	 * Load generated-source review evidence.
	 *
	 * @return array<string,mixed>
	 */
	private static function source_generation_evidence_for_post( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_SOURCE_GENERATION_EVIDENCE, true );
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$evidence = array();
		foreach ( array( 'schema_version', 'authored_original_id', 'generated_source_id' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$evidence[ $key ] = absint( $raw[ $key ] );
			}
		}
		foreach ( array( 'plugin_version', 'recorded_at', 'reviewer', 'authored_language', 'authored_hash', 'generated_source_hash', 'note' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$evidence[ $key ] = sanitize_text_field( (string) $raw[ $key ] );
			}
		}
		if ( array_key_exists( 'meaning_preserved', $raw ) ) {
			$evidence['meaning_preserved'] = (bool) $raw['meaning_preserved'];
		}
		return $evidence;
	}

	/**
	 * Mark a newly saved human-authored non-source post/page for English source intake.
	 */
	public static function queue_authored_original_intake_on_save( int $post_id, WP_Post $post, bool $update ): void {
		unset( $update );

		if ( self::$suspend_reviewer_style_capture || self::$suspend_source_stale_marking ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}
		if ( ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			return;
		}
		if ( in_array( (string) $post->post_status, array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
			return;
		}
		if ( self::is_generated_english_source_post( $post_id ) ) {
			return;
		}

		$generated_source_id = absint( get_post_meta( $post_id, self::META_GENERATED_SOURCE_ID, true ) );
		if ( self::is_translation_post( $post_id ) && ! $generated_source_id ) {
			return;
		}

		$language = self::detect_authored_original_language_for_post( $post_id, $post );
		if ( ! self::is_translation_language( $language ) ) {
			return;
		}

		$status = $generated_source_id ? 'stale' : 'pending';
		$reason = $generated_source_id ? 'authored_original_changed' : self::authored_intake_detection_reason( $post_id );
		self::mark_authored_original_intake( $post_id, $language, $status, $reason );
	}

	/**
	 * Whether this content is a generated English source rather than the authored original.
	 */
	private static function is_generated_english_source_post( int $post_id ): bool {
		$authored_id = absint( get_post_meta( $post_id, self::META_AUTHORED_ORIGINAL_ID, true ) );
		if ( ! $authored_id || $authored_id === $post_id ) {
			return false;
		}

		return '' !== (string) get_post_meta( $post_id, self::META_SOURCE_GENERATION_STATUS, true );
	}

	/**
	 * Store authored-original intake state on the original post/page.
	 */
	private static function mark_authored_original_intake( int $post_id, string $language, string $status, string $reason = '', string $error = '' ): void {
		$post = get_post( $post_id );
		if ( ! $post || ! self::is_translation_language( $language ) ) {
			return;
		}

		$status = self::sanitize_authored_intake_status( $status );
		update_post_meta( $post_id, self::META_AUTHORED_INTAKE_STATUS, $status );
		update_post_meta( $post_id, self::META_AUTHORED_INTAKE_LANGUAGE, sanitize_key( $language ) );
		update_post_meta( $post_id, self::META_AUTHORED_INTAKE_REASON, sanitize_key( $reason ) );
		update_post_meta( $post_id, self::META_AUTHORED_INTAKE_QUEUED_AT, gmdate( 'c' ) );
		update_post_meta( $post_id, self::META_AUTHORED_INTAKE_HASH, self::source_hash( $post ) );
		if ( '' !== trim( $error ) ) {
			update_post_meta( $post_id, self::META_AUTHORED_INTAKE_ERROR, sanitize_textarea_field( $error ) );
		} else {
			delete_post_meta( $post_id, self::META_AUTHORED_INTAKE_ERROR );
		}
	}

	/**
	 * Sanitize authored-original intake state.
	 */
	private static function sanitize_authored_intake_status( string $status ): string {
		$status  = sanitize_key( $status );
		$allowed = array( 'pending', 'stale', 'source_created', 'completed', 'ignored', 'error' );
		return in_array( $status, $allowed, true ) ? $status : 'pending';
	}

	/**
	 * Resolve the best authored original language from explicit metadata, user locale, or content signals.
	 */
	private static function detect_authored_original_language_for_post( int $post_id, WP_Post $post ): string {
		foreach ( array( self::META_AUTHORED_INTAKE_LANGUAGE, self::META_AUTHORED_LANGUAGE, self::META_LANGUAGE ) as $meta_key ) {
			$language = sanitize_key( (string) get_post_meta( $post_id, $meta_key, true ) );
			if ( self::is_translation_language( $language ) ) {
				return $language;
			}
		}

		$user_id = get_current_user_id();
		if ( $user_id && current_user_can( 'edit_post', $post_id ) ) {
			$language = self::language_for_locale( (string) get_user_locale( $user_id ) );
			if ( self::is_translation_language( $language ) && self::post_has_language_signal( $post, $language ) ) {
				return $language;
			}
		}

		return self::detect_language_from_script_signals( (string) $post->post_title . "\n\n" . (string) $post->post_content );
	}

	/**
	 * Explain why an intake item was detected.
	 */
	private static function authored_intake_detection_reason( int $post_id ): string {
		if ( self::is_translation_language( sanitize_key( (string) get_post_meta( $post_id, self::META_AUTHORED_INTAKE_LANGUAGE, true ) ) ) ) {
			return 'stored_intake_language';
		}
		if ( self::is_translation_language( sanitize_key( (string) get_post_meta( $post_id, self::META_AUTHORED_LANGUAGE, true ) ) ) ) {
			return 'stored_authored_language';
		}
		if ( self::is_translation_language( sanitize_key( (string) get_post_meta( $post_id, self::META_LANGUAGE, true ) ) ) ) {
			return 'stored_translation_language';
		}

		$user_id = get_current_user_id();
		if ( $user_id ) {
			$language = self::language_for_locale( (string) get_user_locale( $user_id ) );
			if ( self::is_translation_language( $language ) ) {
				return 'user_locale_and_content_signal';
			}
		}

		return 'content_script_signal';
	}

	/**
	 * Map a WordPress locale to the configured AI Translation Workflow language code.
	 */
	private static function language_for_locale( string $locale ): string {
		$normalized = strtolower( str_replace( '-', '_', trim( $locale ) ) );
		if ( '' === $normalized ) {
			return '';
		}

		foreach ( self::languages() as $language => $config ) {
			$config_locale = strtolower( str_replace( '-', '_', (string) ( $config['locale'] ?? '' ) ) );
			if ( $config_locale === $normalized ) {
				return sanitize_key( (string) $language );
			}
		}

		$primary = strtok( $normalized, '_' );
		foreach ( self::languages() as $language => $config ) {
			$config_locale = strtolower( str_replace( '-', '_', (string) ( $config['locale'] ?? '' ) ) );
			if ( $primary && 0 === strpos( $config_locale, $primary . '_' ) ) {
				return sanitize_key( (string) $language );
			}
		}

		return '';
	}

	/**
	 * Confirm that content has at least one configured signal for a candidate language.
	 */
	private static function post_has_language_signal( WP_Post $post, string $language ): bool {
		$text = self::normalized_plain_text_for_review( (string) $post->post_title . "\n\n" . (string) $post->post_content );
		return self::language_signal_score( $text, $language ) > 0;
	}

	/**
	 * Detect one target language from configured script/diacritic signals.
	 */
	private static function detect_language_from_script_signals( string $content ): string {
		$text = self::normalized_plain_text_for_review( $content );
		if ( '' === $text ) {
			return '';
		}

		$best_language = '';
		$best_score    = 0;
		foreach ( array_keys( self::target_languages() ) as $language ) {
			$score = self::language_signal_score( $text, (string) $language );
			if ( $score > $best_score ) {
				$best_score    = $score;
				$best_language = (string) $language;
			}
		}

		return $best_score > 0 ? sanitize_key( $best_language ) : '';
	}

	/**
	 * Count configured script/diacritic signals for one language.
	 */
	private static function language_signal_score( string $text, string $language ): int {
		$signals = self::effective_language_script_signals( $language );
		if ( empty( $signals ) ) {
			return 0;
		}
		$characters = isset( $signals['required_characters'] ) && is_array( $signals['required_characters'] )
			? array_values( array_filter( array_map( 'strval', $signals['required_characters'] ) ) )
			: array();
		$score = self::language_script_signal_match_count( $text, $characters );
		$pattern = isset( $signals['required_pattern'] ) ? trim( (string) $signals['required_pattern'] ) : '';
		if ( '' !== $pattern ) {
			$score += self::language_script_signal_pattern_count( $text, $pattern );
		}

		return $score;
	}

	/**
	 * Return the authored-original intake queue for AI/operator handoff.
	 */
	private static function authored_original_intake_queue( array $input ): array {
		$post_id       = absint( $input['post_id'] ?? 0 );
		$limit         = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 50;
		$status_filter = self::authored_intake_status_filter( $input );
		$language_filter = self::authored_intake_language_filter( $input['languages'] ?? array() );
		$post_types    = self::authored_intake_post_type_filter( $input['post_types'] ?? array() );
		$posts         = array();

		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
				return self::error( 'Authored original content not found.' );
			}
			$posts[] = $post;
		} else {
			global $wpdb;

			$post_statuses      = array( 'publish', 'draft', 'pending', 'private', 'future' );
			$post_type_marks    = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
			$post_status_marks  = implode( ', ', array_fill( 0, count( $post_statuses ), '%s' ) );
			$inspection_limit   = min( 1000, max( 100, $limit * 5 ) );
			$prepared_arguments = array_merge(
				array( self::META_AUTHORED_INTAKE_STATUS ),
				$post_types,
				$post_statuses,
				array( $inspection_limit )
			);
			$sql = "
				SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm
					ON pm.post_id = p.ID
					AND pm.meta_key = %s
				WHERE p.post_type IN ({$post_type_marks})
					AND p.post_status IN ({$post_status_marks})
				ORDER BY p.post_modified_gmt DESC
				LIMIT %d
			";
			$ids = $wpdb->get_col( $wpdb->prepare( $sql, $prepared_arguments ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Intentional bounded postmeta lookup for the operator queue; SQL placeholders are built from sanitized fixed arrays and prepared values.
			foreach ( $ids as $id ) {
				$post = get_post( absint( $id ) );
				if ( $post ) {
					$posts[] = $post;
				}
			}
		}

		$items  = array();
		$totals = array_fill_keys( array( 'pending', 'stale', 'source_created', 'completed', 'ignored', 'error' ), 0 );
		foreach ( $posts as $post ) {
			$item = self::authored_original_intake_item( $post );
			if ( empty( $item ) ) {
				continue;
			}
			$status   = (string) ( $item['intake_status'] ?? '' );
			$language = (string) ( $item['authored_language'] ?? '' );
			if ( isset( $totals[ $status ] ) ) {
				++$totals[ $status ];
			}
			if ( $status_filter && ! in_array( $status, $status_filter, true ) ) {
				continue;
			}
			if ( $language_filter && ! in_array( $language, $language_filter, true ) ) {
				continue;
			}
			$items[] = $item;
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return array(
			'success'         => true,
			'queue'           => 'authored_original_intake',
			'items'           => $items,
			'item_count'      => count( $items ),
			'inspected_count' => count( $posts ),
			'totals'          => $totals,
			'status_filter'   => array_values( $status_filter ),
			'language_filter' => array_values( $language_filter ),
		);
	}

	/**
	 * Update one authored-original intake item.
	 */
	private static function update_authored_original_intake( array $input ): array {
		$post_id = absint( $input['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			return self::error( 'Authored original content not found.' );
		}

		$status = self::sanitize_authored_intake_status( (string) ( $input['status'] ?? '' ) );
		if ( ! in_array( $status, array( 'pending', 'ignored', 'error' ), true ) ) {
			return self::error( 'Unsupported manual intake status.' );
		}
		$language = sanitize_key( (string) ( $input['language'] ?? get_post_meta( $post_id, self::META_AUTHORED_INTAKE_LANGUAGE, true ) ) );
		if ( ! self::is_translation_language( $language ) ) {
			return self::error( 'A configured non-source language is required for this intake item.' );
		}

		self::mark_authored_original_intake(
			$post_id,
			$language,
			$status,
			'manual_update',
			'error' === $status ? (string) ( $input['note'] ?? '' ) : ''
		);

		return array(
			'success' => true,
			'message' => 'Authored original intake status updated.',
			'item'    => self::authored_original_intake_item( get_post( $post_id ) ),
		);
	}

	/**
	 * Sanitize queue status filters.
	 *
	 * @param array<string,mixed> $input Raw ability input.
	 * @return array<int,string>
	 */
	private static function authored_intake_status_filter( array $input ): array {
		$allowed = array( 'pending', 'stale', 'source_created', 'completed', 'ignored', 'error' );
		if ( ! empty( $input['statuses'] ) && is_array( $input['statuses'] ) ) {
			return array_values(
				array_unique(
					array_filter(
						array_map( 'sanitize_key', $input['statuses'] ),
						static function ( string $status ) use ( $allowed ): bool {
							return in_array( $status, $allowed, true );
						}
					)
				)
			);
		}

		$statuses = array( 'pending', 'stale', 'source_created', 'error' );
		if ( ! empty( $input['include_ignored'] ) ) {
			$statuses[] = 'ignored';
		}
		if ( ! empty( $input['include_completed'] ) ) {
			$statuses[] = 'completed';
		}

		return $statuses;
	}

	/**
	 * Sanitize authored-original language filters.
	 *
	 * @param mixed $languages Raw language filters.
	 * @return array<int,string>
	 */
	private static function authored_intake_language_filter( $languages ): array {
		if ( ! is_array( $languages ) ) {
			return array();
		}
		$clean = array();
		foreach ( $languages as $language ) {
			$language = sanitize_key( (string) $language );
			if ( self::is_translation_language( $language ) ) {
				$clean[] = $language;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Sanitize authored-original post type filters.
	 *
	 * @param mixed $post_types Raw post type filters.
	 * @return array<int,string>
	 */
	private static function authored_intake_post_type_filter( $post_types ): array {
		if ( ! is_array( $post_types ) || empty( $post_types ) ) {
			return self::translatable_post_types();
		}
		$clean = array();
		foreach ( $post_types as $post_type ) {
			$post_type = sanitize_key( (string) $post_type );
			if ( self::is_translatable_post_type( $post_type ) ) {
				$clean[] = $post_type;
			}
		}

		return $clean ? array_values( array_unique( $clean ) ) : self::translatable_post_types();
	}

	/**
	 * Present one authored-original intake item.
	 *
	 * @return array<string,mixed>
	 */
	private static function authored_original_intake_item( ?WP_Post $post ): array {
		if ( ! $post ) {
			return array();
		}
		$post_id = (int) $post->ID;
		$status  = self::sanitize_authored_intake_status( (string) get_post_meta( $post_id, self::META_AUTHORED_INTAKE_STATUS, true ) );
		$language = sanitize_key( (string) get_post_meta( $post_id, self::META_AUTHORED_INTAKE_LANGUAGE, true ) );
		if ( ! self::is_translation_language( $language ) ) {
			return array();
		}
		$generated_source_id = absint( get_post_meta( $post_id, self::META_GENERATED_SOURCE_ID, true ) );
		$generated_source    = $generated_source_id ? get_post( $generated_source_id ) : null;

		return array(
			'authored_original' => self::post_payload( $post ),
			'authored_language' => $language,
			'intake_status'     => $status,
			'intake_reason'     => sanitize_key( (string) get_post_meta( $post_id, self::META_AUTHORED_INTAKE_REASON, true ) ),
			'queued_at'         => (string) get_post_meta( $post_id, self::META_AUTHORED_INTAKE_QUEUED_AT, true ),
			'queued_hash'       => (string) get_post_meta( $post_id, self::META_AUTHORED_INTAKE_HASH, true ),
			'current_hash'      => self::source_hash( $post ),
			'last_error'        => (string) get_post_meta( $post_id, self::META_AUTHORED_INTAKE_ERROR, true ),
			'generated_source'  => $generated_source ? self::post_payload( $generated_source ) : null,
			'source_generation_status' => $generated_source ? self::source_generation_status_for_source( $generated_source_id ) : array(),
			'suggested_next'    => self::authored_original_intake_suggested_next( $post_id, $language, $status, $generated_source_id ),
		);
	}

	/**
	 * Suggested next MCP action for one intake item.
	 *
	 * @return array<string,mixed>
	 */
	private static function authored_original_intake_suggested_next( int $post_id, string $language, string $status, int $generated_source_id ): array {
		if ( in_array( $status, array( 'pending', 'stale', 'error' ), true ) ) {
			return array(
				'ability' => 'ai-translations/create-source-from-authored-original',
				'input_basis' => array(
					'authored_id'       => $post_id,
					'authored_language' => $language,
					'english_source_id' => $generated_source_id,
					'attach_original'   => true,
					'original_translation_status' => 'reviewed',
				),
				'ai_must_supply' => array( 'english_title', 'english_content', 'english_slug', 'english_excerpt', 'seo' ),
			);
		}
		if ( 'source_created' === $status && $generated_source_id ) {
			return array(
				'ability' => 'ai-translations/mark-source-generation-reviewed',
				'input_basis' => array(
					'source_id' => $generated_source_id,
					'meaning_preserved' => true,
				),
			);
		}

		return array(
			'ability' => '',
			'input_basis' => array(),
		);
	}

	/**
	 * Create or update translated content.
	 */
	private static function upsert_translation( array $input ): array {
		$source_id = absint( $input['source_id'] ?? 0 );
		$source    = get_post( $source_id );
		if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) || self::is_translation_post( $source_id ) ) {
			return self::error( 'Source content not found.' );
		}

		$language = sanitize_key( (string) ( $input['language'] ?? '' ) );
		if ( ! self::is_translation_language( $language ) ) {
			return self::error( 'Unknown or source language.' );
		}
		$source_generation_gate = self::generated_source_downstream_gate( $source_id, $language );
		if ( $source_generation_gate ) {
			return $source_generation_gate;
		}

		$target_post_type = (string) $source->post_type;
		$readiness        = self::language_runtime_readiness( $language, $target_post_type );
		if ( empty( $readiness['success'] ) ) {
			return array(
				'success'              => false,
				'message'              => 'Language runtime configuration is incomplete for this content type. Configure it before creating translations.',
				'language'             => $language,
				'post_type'            => $target_post_type,
				'missing_configuration' => $readiness['missing'],
				'configuration'        => $readiness['configuration'] ?? array(),
			);
		}

		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		if ( '' === $title ) {
			return self::error( 'Title is required.' );
		}

		$content = (string) ( $input['content'] ?? '' );
		if ( '' === trim( $content ) ) {
			return self::error( 'Content is required.' );
		}
		$content    = self::mirror_rtl_block_layout_from_source( $content, (string) $source->post_content, $language );
		$content    = self::localize_internal_links_in_content( $content, $language );
		$content    = self::normalize_gutenberg_content_for_storage( $content );
		$excerpt    = isset( $input['excerpt'] ) ? sanitize_textarea_field( (string) $input['excerpt'] ) : '';
		$guardrails = self::translation_guardrails( $content, (string) $source->post_content, $language, $title, $excerpt, $source_id );
		if ( ! empty( $guardrails['issues'] ) ) {
			return array(
				'success'    => false,
				'message'    => 'Translation guardrails failed. Fix structural content issues before saving.',
				'guardrails' => $guardrails,
			);
		}

		$status             = self::sanitize_post_status( (string) ( $input['status'] ?? 'draft' ), 'draft' );
		$translation_status = self::sanitize_translation_status( (string) ( $input['translation_status'] ?? 'needs_review' ) );
		$raw_slug           = (string) ( $input['localized_slug'] ?? '' );
		$slug               = sanitize_title( $raw_slug );
		if ( '' === $slug ) {
			return self::error( 'Localized slug is required.' );
		}
		$allow_source_slug_in_url = ! empty( $input['allow_source_slug_in_url'] );
		$source_slug_reason       = (string) ( $input['source_slug_reason'] ?? '' );
		$slug_issue = self::validate_localized_slug( $raw_slug, $slug, $language, $source, $allow_source_slug_in_url, $source_slug_reason );
		if ( $slug_issue ) {
			return $slug_issue;
		}
		$year_issue = self::validate_years_in_url_parts(
			array( $slug ),
			! empty( $input['allow_year_in_url'] ),
			(string) ( $input['year_url_reason'] ?? '' )
		);
		if ( $year_issue ) {
			return $year_issue;
		}
		if ( self::has_wordpress_duplicate_slug_suffix( $slug ) ) {
			return self::error( 'Localized slug must not end with a WordPress duplicate suffix such as -2. Resolve the route collision instead.' );
		}
		$parent_id = 0;
		if ( 'page' === $target_post_type ) {
			$parent_id = isset( $input['localized_parent_id'] ) ? absint( $input['localized_parent_id'] ) : 0;
		}
		if ( 'page' === $target_post_type && ! $parent_id && ! empty( $input['localized_parent_path'] ) ) {
			$parent_path_issue = self::validate_localized_parent_path( (string) $input['localized_parent_path'], $language, $source, $allow_source_slug_in_url, $source_slug_reason );
			if ( $parent_path_issue ) {
				return $parent_path_issue;
			}
			$parent_status = self::sanitize_post_status( (string) ( $input['parent_status'] ?? 'draft' ), 'draft' );
			$parent_result = self::ensure_parent_path( $language, (string) $input['localized_parent_path'], $parent_status );
			if ( ! $parent_result['success'] ) {
				return $parent_result;
			}
			$parent_id = (int) $parent_result['parent_id'];
		} elseif ( 'page' === $target_post_type && ! $parent_id ) {
			$parent_id = self::default_translation_parent_id( $source, $language );
		}

		$translation_id = isset( $input['translation_id'] ) ? absint( $input['translation_id'] ) : 0;
		if ( ! $translation_id ) {
			$translation_id = self::find_translation_id( $source_id, $language );
		}

		$previous_review_hash = '';
		$new_review_hash      = hash( 'sha256', $title . "\n" . $excerpt . "\n" . $content );
		$content_changed_after_review = false;
		if ( $translation_id ) {
			$existing = get_post( $translation_id );
			if ( ! $existing || $target_post_type !== $existing->post_type ) {
				return self::error( 'Translation ID does not match the source post type.' );
			}
			if ( 'publish' === $existing->post_status && empty( $input['allow_update_published'] ) ) {
				return self::error( 'Refusing to update a published translation without allow_update_published=true.' );
			}
			if ( 'publish' !== $existing->post_status && 'publish' === $status ) {
				return self::error( 'Use publish-translation to publish reviewed translations.' );
			}
			$previous_review_hash = self::translation_review_content_hash( $existing );
			$had_review_before_update = '' !== (string) get_post_meta( $translation_id, self::META_REVIEWED_AT, true )
				|| '' !== (string) get_post_meta( $translation_id, self::META_LINGUISTIC_REVIEWED_AT, true )
				|| '' !== (string) get_post_meta( $translation_id, self::META_QUALITY_REVIEWED_AT, true )
				|| ! empty( self::linguistic_review_evidence_for_post( $translation_id ) )
				|| ! empty( self::quality_review_evidence_for_post( $translation_id ) );
			$content_changed_after_review = '' !== $previous_review_hash && $previous_review_hash !== $new_review_hash && $had_review_before_update;
			if ( $content_changed_after_review && in_array( $translation_status, array( 'reviewed', 'published' ), true ) ) {
				$translation_status = 'needs_review';
			}
		} elseif ( 'publish' === $status || 'published' === $translation_status ) {
			return self::error( 'Use publish-translation to publish reviewed translations.' );
		}

		$slug_conflicts = self::translation_slug_conflicts( $slug, $target_post_type, $parent_id, $translation_id );
		if ( $slug_conflicts ) {
			return array(
				'success'          => false,
				'message'          => 'Localized slug is already in use. Resolve the route collision before saving; WordPress duplicate slugs such as -2 are not allowed.',
				'code'             => 'localized_slug_collision',
				'requested_slug'   => $slug,
				'post_type'        => $target_post_type,
				'parent_id'        => $parent_id,
				'translation_id'   => $translation_id,
				'conflicting_posts'=> $slug_conflicts,
			);
		}

		$postarr = array(
			'post_type'    => $target_post_type,
			'post_author'  => (int) $source->post_author,
			'post_title'   => $title,
		'post_name'    => $slug,
		'post_content' => $content,
			'post_excerpt' => $excerpt,
			'post_status'  => $status,
		);
		if ( 'post' === $target_post_type ) {
			$postarr = array_merge( $postarr, self::source_publication_date_fields( $source ) );
		}
		if ( 'page' === $target_post_type ) {
			$postarr['post_parent'] = $parent_id;
		}

		$result = 0;
		self::with_slug_change_unlock(
			static function () use ( &$result, $translation_id, $postarr ): void {
				self::with_reviewer_style_capture_suspended(
					static function () use ( &$result, $translation_id, $postarr ): void {
						if ( $translation_id ) {
							$postarr['ID'] = $translation_id;
							$result        = wp_update_post( wp_slash( $postarr ), true );
						} else {
							$result = wp_insert_post( wp_slash( $postarr ), true );
						}
					}
				);
			}
		);

		if ( is_wp_error( $result ) ) {
			return self::error( $result->get_error_message() );
		}

		$translation_id = (int) $result;
		$saved_post = get_post( $translation_id );
		if ( ! $saved_post || $slug !== (string) $saved_post->post_name || self::has_wordpress_duplicate_slug_suffix( (string) $saved_post->post_name ) ) {
			return array(
				'success'        => false,
				'message'        => 'WordPress changed the localized slug during save. Resolve the route collision before saving; duplicate slugs such as -2 are not allowed.',
				'code'           => 'localized_slug_rewritten',
				'requested_slug' => $slug,
				'actual_slug'    => $saved_post ? (string) $saved_post->post_name : '',
				'post_type'      => $target_post_type,
				'parent_id'      => $parent_id,
				'translation_id' => $translation_id,
			);
		}
		if ( 'post' === $target_post_type && ! empty( $input['localized_path'] ) ) {
			$localized_path = trim( sanitize_text_field( (string) $input['localized_path'] ), '/' );
			$path_year_issue = self::validate_years_in_url_parts(
				explode( '/', $localized_path ),
				! empty( $input['allow_year_in_url'] ),
				(string) ( $input['year_url_reason'] ?? '' )
			);
			if ( $path_year_issue ) {
				return $path_year_issue;
			}
			update_post_meta( $translation_id, self::META_LOCALIZED_PATH, $localized_path );
		}
		if ( 'post' === $target_post_type ) {
			$term_result = self::sync_translated_post_terms( $translation_id, $source, $language, $input['taxonomies'] ?? array() );
			if ( empty( $term_result['success'] ) ) {
				return $term_result;
			}
		}
		$seo_meta = self::sync_rank_math_translation_seo_meta( $translation_id, $input, $title, $excerpt, $content );
		$lifecycle = self::apply_translation_lifecycle_meta( $translation_id, $source_id, $language, $translation_status, $source );
		$review_invalidated = '' !== $previous_review_hash
			? self::invalidate_translation_reviews_if_content_changed( $translation_id, 'upsert_translation', $previous_review_hash )
			: false;
		if ( ! $review_invalidated && $content_changed_after_review ) {
			$review_invalidated = true;
		}

		return array(
			'success'            => true,
			'message'            => $translation_id ? 'Translation saved.' : 'Translation created.',
			'translation'        => self::translation_payload( get_post( $translation_id ) ),
			'source_hash'        => $lifecycle['source_hash'],
			'internal_linking'   => self::internal_link_opportunities_for_post( get_post( $translation_id ) ?: $source, $language, 3 ),
			'review_invalidated' => $review_invalidated,
			'taxonomies'         => isset( $term_result ) ? $term_result : null,
			'seo_meta'           => $seo_meta,
			'presentation'       => $lifecycle['presentation'] ?? array(),
		);
	}

	/**
	 * Keep Rank Math metadata aligned with translated content updates.
	 */
	private static function sync_rank_math_translation_seo_meta( int $translation_id, array $input, string $title, string $excerpt, string $content ): array {
		$seo_input = isset( $input['seo'] ) && is_array( $input['seo'] ) ? $input['seo'] : array();
		$seo_title = self::seo_meta_input_value( $seo_input, array( 'seo_title', 'title' ) );
		if ( '' === $seo_title ) {
			$seo_title = $title;
		}

		$seo_description = self::seo_meta_input_value( $seo_input, array( 'seo_description', 'description' ) );
		if ( '' === $seo_description ) {
			$seo_description = '' !== trim( $excerpt ) ? $excerpt : self::seo_description_from_content( $content );
		}

		$focus_keyword = self::seo_meta_input_value( $seo_input, array( 'focus_keyword', 'keyword' ) );
		$updated       = array();

		if ( '' !== $seo_title ) {
			update_post_meta( $translation_id, 'rank_math_title', sanitize_text_field( $seo_title ) );
			$updated[] = 'rank_math_title';
		}
		if ( '' !== $seo_description ) {
			update_post_meta( $translation_id, 'rank_math_description', sanitize_textarea_field( $seo_description ) );
			$updated[] = 'rank_math_description';
		}
		if ( '' !== $focus_keyword ) {
			update_post_meta( $translation_id, 'rank_math_focus_keyword', sanitize_text_field( $focus_keyword ) );
			$updated[] = 'rank_math_focus_keyword';
		}

		if ( $updated ) {
			clean_post_cache( $translation_id );
		}

		return array(
			'success'        => true,
			'updated'        => $updated,
			'auto_generated' => empty( $seo_input ),
		);
	}

	/**
	 * Extract an SEO metadata alias from ability input.
	 *
	 * @param array<string,mixed> $seo_input Input object.
	 * @param array<int,string>   $keys      Accepted aliases.
	 */
	private static function seo_meta_input_value( array $seo_input, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $seo_input[ $key ] ) && '' !== trim( (string) $seo_input[ $key ] ) ) {
				return self::normalize_review_text( (string) $seo_input[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Build a concise meta description from visible translated content.
	 */
	private static function seo_description_from_content( string $content ): string {
		$fragments = self::text_fragments_for_copy_quality( $content );
		$text      = '';
		foreach ( $fragments as $fragment ) {
			$piece = trim( (string) ( $fragment['text'] ?? '' ) );
			if ( '' === $piece ) {
				continue;
			}
			$text = trim( $text . ' ' . $piece );
			if ( 180 <= strlen( $text ) ) {
				break;
			}
		}
		if ( '' === $text ) {
			$text = self::normalize_review_text( wp_strip_all_tags( strip_shortcodes( $content ) ) );
		}

		return self::trim_meta_description( $text );
	}

	/**
	 * Trim meta descriptions without leaving broken words.
	 */
	private static function trim_meta_description( string $text, int $max_length = 155 ): string {
		$text = self::normalize_review_text( $text );
		if ( '' === $text ) {
			return '';
		}
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) <= $max_length ) {
			return $text;
		}
		if ( ! function_exists( 'mb_strlen' ) && strlen( $text ) <= $max_length ) {
			return $text;
		}

		$snippet = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max_length, 'UTF-8' ) : substr( $text, 0, $max_length );
		$snippet = preg_replace( '/\s+\S*$/u', '', (string) $snippet );
		$snippet = trim( (string) $snippet, " \t\n\r\0\x0B.,;:-" );

		return '' !== $snippet ? $snippet : ( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max_length, 'UTF-8' ) : substr( $text, 0, $max_length ) );
	}

	/**
	 * Sync translated post categories and tags through language-scoped term variants.
	 *
	 * @param mixed $taxonomy_input Optional per-taxonomy term overrides from the client.
	 * @return array<string,mixed>
	 */
	private static function sync_translated_post_terms( int $translation_id, WP_Post $source, string $language, $taxonomy_input ): array {
		if ( 'post' !== $source->post_type || ! self::is_translation_language( $language ) ) {
			return array( 'success' => true, 'synced' => array() );
		}

		$taxonomy_input = is_array( $taxonomy_input ) ? $taxonomy_input : array();
		$synced = array();

		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$source_terms = wp_get_post_terms( (int) $source->ID, $taxonomy, array( 'hide_empty' => false ) );
			if ( is_wp_error( $source_terms ) ) {
				return self::error( $source_terms->get_error_message() );
			}

			$input_terms = self::taxonomy_input_by_source_term( $taxonomy_input[ $taxonomy ] ?? array() );
			$target_term_ids = array();
			foreach ( $source_terms as $source_term ) {
				if ( ! $source_term instanceof WP_Term ) {
					continue;
				}

				$term_data = $input_terms[ (int) $source_term->term_id ] ?? array();
				$term_id = self::ensure_translated_term( $source_term, $language, $term_data );
				if ( ! $term_id ) {
					continue;
				}
				$target_term_ids[] = $term_id;
			}

			$result = wp_set_post_terms( $translation_id, $target_term_ids, $taxonomy, false );
			if ( is_wp_error( $result ) ) {
				return self::error( $result->get_error_message() );
			}

			$synced[ $taxonomy ] = array_values( array_map( 'absint', $target_term_ids ) );
		}

		return array(
			'success' => true,
			'synced'  => $synced,
		);
	}

	/**
	 * Normalize optional taxonomy input by source term ID.
	 *
	 * @param mixed $terms Raw taxonomy terms.
	 * @return array<int,array{name?:string,slug?:string}>
	 */
	private static function taxonomy_input_by_source_term( $terms ): array {
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $terms as $term ) {
			if ( is_numeric( $term ) ) {
				$normalized[ absint( $term ) ] = array();
				continue;
			}
			if ( ! is_array( $term ) ) {
				continue;
			}
			$source_term_id = absint( $term['source_term_id'] ?? 0 );
			if ( ! $source_term_id ) {
				continue;
			}
			$row = array();
			if ( isset( $term['name'] ) ) {
				$row['name'] = sanitize_text_field( (string) $term['name'] );
			}
			if ( isset( $term['slug'] ) ) {
				$row['slug'] = sanitize_title( (string) $term['slug'] );
			}
			$normalized[ $source_term_id ] = $row;
		}

		return $normalized;
	}

	/**
	 * Find or create one language-scoped category/tag term for a source term.
	 *
	 * @param array{name?:string,slug?:string} $term_data Client-provided localized term data.
	 */
	private static function ensure_translated_term( WP_Term $source_term, string $language, array $term_data ): int {
		$existing_id = self::find_translated_term_id( (int) $source_term->term_id, $language, (string) $source_term->taxonomy );
		if ( $existing_id ) {
			return $existing_id;
		}

		$name = isset( $term_data['name'] ) && '' !== trim( (string) $term_data['name'] )
			? trim( (string) $term_data['name'] )
			: (string) $source_term->name;
		$slug = isset( $term_data['slug'] ) && '' !== trim( (string) $term_data['slug'] )
			? sanitize_title( (string) $term_data['slug'] )
			: sanitize_title( $language . '-' . (string) $source_term->slug );

		if ( self::language_requires_transliterated_urls( $language ) && ! preg_match( '/^[A-Za-z0-9_-]+$/', $slug ) ) {
			$slug = sanitize_title( $language . '-' . (int) $source_term->term_id );
		}

		$args = array( 'slug' => $slug );
		if ( 'category' === $source_term->taxonomy && $source_term->parent ) {
			$translated_parent = self::find_translated_term_id( (int) $source_term->parent, $language, (string) $source_term->taxonomy );
			if ( $translated_parent ) {
				$args['parent'] = $translated_parent;
			}
		}

		$created = wp_insert_term( $name, (string) $source_term->taxonomy, $args );
		if ( is_wp_error( $created ) && 'term_exists' === $created->get_error_code() ) {
			$term_exists_data = $created->get_error_data();
			$term_id          = is_array( $term_exists_data )
				? absint( $term_exists_data['term_id'] ?? 0 )
				: absint( $term_exists_data );
		} elseif ( is_wp_error( $created ) ) {
			return 0;
		} else {
			$term_id = absint( $created['term_id'] ?? 0 );
		}

		if ( $term_id ) {
			update_term_meta( $term_id, self::TERM_META_SOURCE_ID, (int) $source_term->term_id );
			update_term_meta( $term_id, self::TERM_META_LANGUAGE, sanitize_key( $language ) );
		}

		return $term_id;
	}

	/**
	 * Find one translated term by source term/language/taxonomy.
	 */
	private static function find_translated_term_id( int $source_term_id, string $language, string $taxonomy ): int {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Narrow operator workflow lookup for term translations.
					'relation' => 'AND',
					array(
						'key'   => self::TERM_META_SOURCE_ID,
						'value' => (string) $source_term_id,
					),
					array(
						'key'   => self::TERM_META_LANGUAGE,
						'value' => sanitize_key( $language ),
					),
				),
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return 0;
		}

		return absint( $terms[0] );
	}

	/**
	 * Validate language-specific URL slug rules before WordPress sanitizes meaning away.
	 */
	private static function validate_localized_slug( string $raw_slug, string $slug, string $language, ?WP_Post $source = null, bool $allow_source_slug = false, string $source_slug_reason = '' ): ?array {
		if ( ! self::language_requires_transliterated_urls( $language ) ) {
			return null;
		}

		$raw_slug = trim( $raw_slug );
		if ( '' === $raw_slug || $raw_slug !== $slug || ! preg_match( '/^[A-Za-z0-9_-]+$/', $raw_slug ) ) {
			return self::error( 'Localized slug must be transliterated ASCII for this language. Do not use native-script URL characters.' );
		}

		$source_slug_issue = self::validate_transliterated_segment_not_source_copy(
			$slug,
			$source ? array( (string) $source->post_name ) : array(),
			$language,
			$allow_source_slug,
			$source_slug_reason,
			'localized_slug'
		);
		if ( $source_slug_issue ) {
			return $source_slug_issue;
		}

		return null;
	}

	/**
	 * Reject transliterated-language URL segments that are merely copied from the source route.
	 *
	 * @param array<int,string> $source_segments Source-language segments to compare against.
	 */
	private static function validate_transliterated_segment_not_source_copy( string $segment, array $source_segments, string $language, bool $allow_source_slug, string $source_slug_reason, string $field ): ?array {
		$segment = sanitize_title( $segment );
		if ( '' === $segment || ! self::language_requires_transliterated_urls( $language ) ) {
			return null;
		}

		$source_segments = array_values(
			array_filter(
				array_map(
					static function ( $source_segment ): string {
						return sanitize_title( (string) $source_segment );
					},
					$source_segments
				),
				'strlen'
			)
		);
		if ( ! in_array( $segment, $source_segments, true ) ) {
			return null;
		}

		$reason = self::normalize_review_text( wp_strip_all_tags( $source_slug_reason ) );
		if ( $allow_source_slug && '' !== $reason ) {
			return null;
		}

		return array(
			'success' => false,
			'message' => 'Localized URL segment appears copied from the source-language route. Use a target-language transliteration, or pass allow_source_slug_in_url with source_slug_reason only for brand/proper-name URLs.',
			'code'    => 'localized_slug_copied_from_source',
			'field'   => $field,
			'segment' => $segment,
			'language'=> $language,
		);
	}

	/**
	 * WordPress appends numeric suffixes when a requested slug collides.
	 */
	private static function has_wordpress_duplicate_slug_suffix( string $slug ): bool {
		return 1 === preg_match( '/-[2-9]\d?$/', $slug );
	}

	/**
	 * Block freshness years in URLs unless the operator explicitly marks the
	 * year as the basis of the article.
	 *
	 * @param array<int,string> $parts URL path or slug parts.
	 */
	private static function validate_years_in_url_parts( array $parts, bool $allow_year_in_url, string $reason = '' ): ?array {
		$years = array();
		foreach ( $parts as $part ) {
			$part = sanitize_title( (string) $part );
			if ( '' === $part ) {
				continue;
			}
			if ( preg_match_all( '/(?<!\d)(?:19|20)\d{2}(?!\d)/', $part, $matches ) ) {
				foreach ( $matches[0] as $year ) {
					$years[ $year ] = $year;
				}
			}
		}

		if ( ! $years ) {
			return null;
		}

		$reason = self::normalize_review_text( wp_strip_all_tags( $reason ) );
		if ( $allow_year_in_url && '' !== $reason ) {
			return null;
		}

		return array(
			'success' => false,
			'message' => 'Localized URL must not contain a year unless the year is the article basis. Remove freshness/update years from the slug, or pass allow_year_in_url with a clear reason.',
			'code'    => 'year_in_url_requires_reason',
			'years'   => array_values( $years ),
		);
	}

	/**
	 * Find posts that would force WordPress to rewrite the requested translation slug.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function translation_slug_conflicts( string $slug, string $post_type, int $parent_id, int $exclude_id ): array {
		$query = new WP_Query(
			array(
				'name'              => $slug,
				'post_type'         => $post_type,
				'post_status'       => array_values( get_post_stati( array(), 'names' ) ),
				'posts_per_page'    => 20,
				'orderby'           => 'ID',
				'order'             => 'ASC',
				'no_found_rows'     => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$posts = is_array( $query->posts ) ? $query->posts : array();
		if ( $exclude_id ) {
			$posts = array_values(
				array_filter(
					$posts,
					static function ( WP_Post $candidate ) use ( $exclude_id ): bool {
						return (int) $candidate->ID !== $exclude_id;
					}
				)
			);
		}
		if ( is_post_type_hierarchical( $post_type ) ) {
			$posts = array_values(
				array_filter(
					$posts,
					static function ( WP_Post $candidate ) use ( $parent_id ): bool {
						return (int) $candidate->post_parent === $parent_id;
					}
				)
			);
		}
		if ( empty( $posts ) ) {
			return array();
		}

		$conflicts = array();
		foreach ( $posts as $candidate ) {
			$id = absint( $candidate->ID ?? 0 );
			if ( ! $id ) {
				continue;
			}
			$conflicts[] = array(
				'id'        => $id,
				'title'     => get_the_title( $id ),
				'status'    => sanitize_key( (string) ( $candidate->post_status ?? '' ) ),
				'parent_id' => absint( $candidate->post_parent ?? 0 ),
				'url'       => get_permalink( $id ),
				'source_id' => absint( get_post_meta( $id, self::META_SOURCE_ID, true ) ),
				'language'  => sanitize_key( (string) get_post_meta( $id, self::META_LANGUAGE, true ) ),
			);
		}

		return $conflicts;
	}

	/**
	 * Validate parent path segments for languages that require transliterated URLs.
	 */
	private static function validate_localized_parent_path( string $parent_path, string $language, ?WP_Post $source = null, bool $allow_source_slug = false, string $source_slug_reason = '' ): ?array {
		if ( ! self::language_requires_transliterated_urls( $language ) ) {
			return null;
		}

		$segments = array_values( array_filter( explode( '/', trim( $parent_path, '/' ) ), 'strlen' ) );
		$source_parent_segments = $source ? self::source_parent_slug_segments( $source ) : array();
		foreach ( $segments as $segment ) {
			$sanitized = sanitize_title( $segment );
			if ( $segment !== $sanitized || ! preg_match( '/^[A-Za-z0-9_-]+$/', $segment ) ) {
				return self::error( 'Localized parent path must use transliterated ASCII segments for this language. Do not use native-script URL characters.' );
			}
			$source_slug_issue = self::validate_transliterated_segment_not_source_copy(
				$sanitized,
				$source_parent_segments,
				$language,
				$allow_source_slug,
				$source_slug_reason,
				'localized_parent_path'
			);
			if ( $source_slug_issue ) {
				return $source_slug_issue;
			}
		}

		return null;
	}

	/**
	 * Source-language ancestor slug segments, ordered from root to direct parent.
	 *
	 * @return array<int,string>
	 */
	private static function source_parent_slug_segments( WP_Post $source ): array {
		if ( 'page' !== (string) $source->post_type ) {
			return array();
		}

		$ancestor_ids = array_reverse( array_map( 'absint', get_post_ancestors( $source ) ) );
		$segments     = array();
		foreach ( $ancestor_ids as $ancestor_id ) {
			$ancestor = get_post( $ancestor_id );
			if ( $ancestor instanceof WP_Post && '' !== (string) $ancestor->post_name ) {
				$segments[] = (string) $ancestor->post_name;
			}
		}

		return $segments;
	}

	/**
	 * List translations.
	 */
	private static function list_translations( array $input ): array {
		$limit         = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 100;
		$source_filter = ! empty( $input['source_id'] ) ? absint( $input['source_id'] ) : 0;
		$lang_filter   = ! empty( $input['language'] ) ? sanitize_key( (string) $input['language'] ) : '';
		$status_filter = ! empty( $input['status'] ) ? self::sanitize_translation_status( (string) $input['status'] ) : '';
		$query         = self::translation_page_query(
			array(
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1000,
			)
		);

		$rows = array();
		foreach ( $query->posts as $post ) {
			$language  = (string) get_post_meta( $post->ID, self::META_LANGUAGE, true );
			$source_id = absint( get_post_meta( $post->ID, self::META_SOURCE_ID, true ) );
			$status    = self::sanitize_translation_status( (string) get_post_meta( $post->ID, self::META_STATUS, true ) );
			if ( '' === $language ) {
				continue;
			}
			if ( $source_filter && $source_id !== $source_filter ) {
				continue;
			}
			if ( '' !== $lang_filter && $language !== $lang_filter ) {
				continue;
			}
			if ( '' !== $status_filter && $status !== $status_filter ) {
				continue;
			}
			$rows[] = self::translation_payload( $post );
			if ( count( $rows ) >= $limit ) {
				break;
			}
		}

		return array(
			'success'      => true,
			'translations' => $rows,
			'total'        => count( $rows ),
		);
	}

	/**
	 * Mark translation reviewed/status.
	 */
	private static function mark_reviewed( int $translation_id, string $translation_status ): array {
		$post = get_post( $translation_id );
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			return self::error( 'Translation content not found.' );
		}

		$status = self::sanitize_translation_status( $translation_status );
		if ( 'published' === $status ) {
			return self::error( 'Use publish-translation to publish reviewed translations.' );
		}
		if ( 'reviewed' === $status ) {
			$review_state = self::linguistic_review_state_for_post( $translation_id );
			if ( empty( $review_state['passed'] ) ) {
				return array(
					'success'      => false,
					'message'      => 'Current linguistic review evidence is required before marking the translation reviewed.',
					'review_state' => $review_state,
				);
			}
		}
		update_post_meta( $translation_id, self::META_STATUS, $status );
		if ( 'reviewed' === $status ) {
			update_post_meta( $translation_id, self::META_REVIEWED_AT, gmdate( 'c' ) );
		}
		self::sync_translation_index_row( $translation_id );

		return array(
			'success'     => true,
			'translation' => self::translation_payload( get_post( $translation_id ) ),
		);
	}

	/**
	 * Run lightweight QA for a translation.
	 */
	private static function qa_translation( array $input ): array {
		$translation_id = absint( $input['translation_id'] ?? 0 );
		$post           = get_post( $translation_id );
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			return self::error( 'Translation content not found.' );
		}

		$translation = self::translation_payload( $post );
		$issues      = array();
		$warnings    = array();
		$content     = (string) $post->post_content;
		$text        = trim( wp_strip_all_tags( do_shortcode( $content ) ) );
		$language    = (string) ( $translation['language'] ?? '' );

		if ( ! self::is_translation_language( $language ) ) {
			$issues[] = self::qa_item( 'missing_or_unknown_language', 'Translation language is missing or not configured.' );
		}
		if ( empty( $translation['source_id'] ) || ! get_post( (int) $translation['source_id'] ) ) {
			$issues[] = self::qa_item( 'missing_source', 'Translation source content is missing.' );
		}
		if ( '' === $text ) {
			$issues[] = self::qa_item( 'empty_content', 'Translation content is empty.' );
		}
		if ( ! has_blocks( $content ) ) {
			$warnings[] = self::qa_item( 'no_blocks_detected', 'No Gutenberg blocks were detected in the translation content.' );
		}
		$source_id = ! empty( $translation['source_id'] ) ? absint( $translation['source_id'] ) : 0;
		$fitness = self::translation_fitness(
			$content,
			$source_id ? (string) get_post_field( 'post_content', $source_id ) : '',
			$language,
			(string) $post->post_title,
			(string) $post->post_excerpt,
			$source_id
		);
		$guardrails = $fitness['guardrails'];
		$issues     = array_merge( $issues, $fitness['issues'] );
		$warnings   = array_merge( $warnings, $fitness['warnings'] );
		$language_profile = self::language_review_profile( $language );
		$agency_copy = array(
			'enabled' => self::agency_copy_review_enabled( $language ),
			'profile' => self::agency_copy_review_profile( $language ),
			'review_questions' => self::agency_copy_review_questions( $language ),
			'required_linguistic_checks' => self::required_linguistic_review_checks( $language ),
			'required_quality_checks' => self::required_quality_review_checks( $language ),
		);
		if ( ! empty( $translation['is_stale'] ) ) {
			$issues[] = self::qa_item( 'stale_source', 'The source content has changed since this translation was created.' );
		}

		$prefix      = $language ? self::language_prefix( $language ) : '';
		$actual_path = '';
		if ( ! empty( $translation['url'] ) ) {
			$parsed_path = wp_parse_url( (string) $translation['url'], PHP_URL_PATH );
			$actual_path = is_string( $parsed_path ) ? trim( $parsed_path, '/' ) : '';
		}
		if ( in_array( (string) $post->post_status, array( 'publish', 'private' ), true ) && $prefix && $actual_path && 0 !== strpos( $actual_path, $prefix . '/' ) && $actual_path !== $prefix ) {
			$issues[] = self::qa_item( 'localized_permalink_mismatch', 'Actual permalink does not start with the configured language prefix.' );
		}
		if ( $prefix && $translation['localized_path'] && 0 !== strpos( (string) $translation['localized_path'], $prefix ) ) {
			$issues[] = self::qa_item( 'localized_path_mismatch', 'Stored localized path does not start with the configured language prefix.' );
		}
		$route_integrity = self::translation_route_integrity( (int) $post->ID, $language );
		$issues = array_merge( $issues, $route_integrity['issues'] );
		$warnings = array_merge( $warnings, $route_integrity['warnings'] );

		if ( false !== strpos( $content, 'href=""' ) || false !== strpos( $content, "href=''" ) ) {
			$issues[] = self::qa_item( 'empty_href', 'Translation contains an empty link href.' );
		}
		if ( false !== strpos( $content, 'Homepage%20review' ) ) {
			$warnings[] = self::qa_item( 'english_mail_subject', 'Translation still contains the default English homepage mail subject.' );
		}

		$internal_linking = self::internal_link_opportunities_for_post( $post, $language, 3 );

		$forbidden_terms = self::qa_terms_from_input( $input['forbidden_terms'] ?? array() );
		foreach ( self::default_forbidden_terms() as $term ) {
			$forbidden_terms[] = $term;
		}
		$forbidden_terms = array_values( array_unique( array_filter( $forbidden_terms ) ) );
		foreach ( $forbidden_terms as $term ) {
			if ( false !== stripos( $content, $term ) ) {
				$warnings[] = self::qa_item( 'forbidden_term', 'Translation contains a term that should be reviewed: ' . $term, array( 'term' => $term ) );
			}
		}
		foreach ( self::qa_terms_from_input( $input['required_terms'] ?? array() ) as $term ) {
			if ( false === stripos( $content, $term ) ) {
				$issues[] = self::qa_item( 'required_term_missing', 'Translation is missing a required term: ' . $term, array( 'term' => $term ) );
			}
		}

		return array(
			'success'       => true,
			'passed'        => empty( $issues ),
			'issues'        => $issues,
			'warnings'      => $warnings,
			'issue_count'   => count( $issues ),
			'warning_count' => count( $warnings ),
			'fitness'       => $fitness,
			'guardrails'    => $guardrails,
			'gutenberg'     => $guardrails['gutenberg'],
			'shortcodes'    => $guardrails['shortcodes'],
			'source_structure' => $guardrails['source_structure'],
			'route_integrity' => $route_integrity,
			'internal_linking' => $internal_linking,
			'language_profile' => $language_profile,
			'agency_copy'   => $agency_copy,
			'translation'   => $translation,
		);
	}

	/**
	 * Consolidated local quality verdict for source or translated content.
	 *
	 * This Module sits above translation_fitness and review metadata. It is the
	 * small Interface future cloud quality Adapters should satisfy too.
	 */
	private static function quality_verdict( array $input ): array {
		$content_id = self::quality_verdict_content_id_from_input( $input );
		if ( ! $content_id ) {
			return self::error( 'Content, source, or translation ID is required.' );
		}

		$post = get_post( $content_id );
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			return self::error( 'Content not found.' );
		}

		$audience = self::quality_verdict_audience( (string) ( $input['audience'] ?? 'ai_operator' ) );
		$raw      = self::quality_verdict_for_post(
			$post,
			'internal_debug' === $audience && ( ! array_key_exists( 'include_qa', $input ) || (bool) $input['include_qa'] ),
			self::quality_verdict_stage( (string) ( $input['stage'] ?? 'auto' ), $post )
		);

		return self::quality_verdict_present_for_audience( $raw, $audience );
	}

	/**
	 * Resolve quality-verdict content ID from supported caller shapes.
	 */
	private static function quality_verdict_content_id_from_input( array $input ): int {
		$content_id = absint( $input['content_id'] ?? ( $input['page_id'] ?? ( $input['translation_id'] ?? 0 ) ) );
		if ( $content_id ) {
			return $content_id;
		}

		$source_id = absint( $input['source_id'] ?? 0 );
		$language  = sanitize_key( (string) ( $input['language'] ?? '' ) );
		if ( $source_id && '' !== $language && self::is_translation_language( $language ) ) {
			return self::find_translation_id( $source_id, $language, array( 'publish', 'draft', 'pending', 'private' ) );
		}

		return $source_id;
	}

	/**
	 * Resolve the verdict stage without making pre-publish flows require live review.
	 */
	private static function quality_verdict_stage( string $stage, WP_Post $post ): string {
		$stage = sanitize_key( $stage );
		if ( in_array( $stage, array( 'pre_publish', 'post_publish' ), true ) ) {
			return $stage;
		}

		return 'publish' === (string) $post->post_status ? 'post_publish' : 'pre_publish';
	}

	/**
	 * Resolve the quality-verdict presentation audience.
	 */
	private static function quality_verdict_audience( string $audience ): string {
		$audience = sanitize_key( $audience );
		if ( 'internal_debug' === $audience ) {
			return 'internal_debug';
		}

		return 'ai_operator';
	}

	/**
	 * Present a raw quality verdict through the requested audience surface.
	 */
	private static function quality_verdict_present_for_audience( array $raw, string $audience = 'ai_operator' ): array {
		$audience = self::quality_verdict_audience( $audience );
		if ( 'internal_debug' === $audience ) {
			$raw['audience'] = 'internal_debug';
			return $raw;
		}

		$signals = isset( $raw['signals'] ) && is_array( $raw['signals'] ) ? $raw['signals'] : array();
		$quality_review = isset( $signals['quality_review'] ) && is_array( $signals['quality_review'] ) ? $signals['quality_review'] : array();
		$copy_feedback = isset( $signals['copy_feedback'] ) && is_array( $signals['copy_feedback'] ) ? $signals['copy_feedback'] : array();
		$agency_copy = isset( $signals['agency_copy'] ) && is_array( $signals['agency_copy'] ) ? $signals['agency_copy'] : array();
		$linguistic_review = isset( $signals['linguistic_review'] ) && is_array( $signals['linguistic_review'] ) ? $signals['linguistic_review'] : null;

		return array(
			'success'        => ! empty( $raw['success'] ),
			'schema_version' => (int) ( $raw['schema_version'] ?? 1 ),
			'adapter'        => (string) ( $raw['adapter'] ?? 'local' ),
			'audience'       => 'ai_operator',
			'stage'          => (string) ( $raw['stage'] ?? 'post_publish' ),
			'verdict'        => (string) ( $raw['verdict'] ?? 'needs_work' ),
			'publishable'    => ! empty( $raw['publishable'] ),
			'blockers'       => self::quality_verdict_present_blockers( isset( $raw['blockers'] ) && is_array( $raw['blockers'] ) ? $raw['blockers'] : array() ),
			'next_actions'   => self::quality_verdict_next_actions( isset( $raw['blockers'] ) && is_array( $raw['blockers'] ) ? $raw['blockers'] : array(), $raw ),
			'scores'         => isset( $raw['scores'] ) && is_array( $raw['scores'] ) ? $raw['scores'] : array(),
			'content'        => $raw['content'] ?? null,
			'source'         => $raw['source'] ?? null,
			'language'       => (string) ( $raw['language'] ?? '' ),
			'is_source'      => ! empty( $raw['is_source'] ),
			'is_translation' => ! empty( $raw['is_translation'] ),
			'signals'        => array(
				'technical_qa' => array(
					'passed' => 100 === (int) ( $raw['scores']['technical'] ?? 0 ),
				),
				'linguistic_review' => is_array( $linguistic_review )
					? array(
						'passed' => ! empty( $linguistic_review['passed'] ),
						'state'  => (string) ( $linguistic_review['state'] ?? '' ),
					)
					: null,
				'quality_review' => array(
					'state'          => (string) ( $quality_review['state'] ?? '' ),
					'missing_checks' => self::quality_verdict_public_string_list( $quality_review['missing_checks'] ?? array() ),
				),
				'copy_feedback' => array(
					'open_count' => absint( $copy_feedback['open_count'] ?? 0 ),
				),
				'agency_copy' => array(
					'enabled' => ! empty( $agency_copy['enabled'] ),
				),
			),
			'evidence'       => array(
				'plugin_version' => (string) ( $raw['evidence']['plugin_version'] ?? self::VERSION ),
				'evaluated_at'   => (string) ( $raw['evidence']['evaluated_at'] ?? gmdate( 'c' ) ),
				'source_id'      => absint( $raw['evidence']['source_id'] ?? 0 ),
			),
		);
	}

	/**
	 * Convert raw verdict blockers into a safe AI-operator issue list.
	 */
	private static function quality_verdict_present_blockers( array $blockers ): array {
		return array_values(
			array_map(
				static function ( array $blocker ): array {
					$code = sanitize_key( (string) ( $blocker['code'] ?? '' ) );
					return array(
						'code'                 => $code,
						'severity'             => sanitize_key( (string) ( $blocker['severity'] ?? '' ) ),
						'message'              => (string) ( $blocker['message'] ?? '' ),
						'next_action_category' => Devenia_AI_Translations::quality_verdict_next_action_category( $code ),
					);
				},
				$blockers
			)
		);
	}

	/**
	 * Safe, ordered next actions for AI operators.
	 */
	private static function quality_verdict_next_actions( array $blockers, array $raw ): array {
		if ( empty( $blockers ) ) {
			if ( ! empty( $raw['publishable'] ) ) {
				return array();
			}

			return array(
				self::quality_verdict_next_action(
					'inspect_verdict',
					'inspect_verdict',
					'Verify the verdict state before continuing.',
					'low',
					false,
					''
				),
			);
		}

		$actions = array();
		$seen    = array();
		foreach ( $blockers as $blocker ) {
			$code = sanitize_key( (string) ( $blocker['code'] ?? '' ) );
			if ( '' === $code || isset( $seen[ $code ] ) ) {
				continue;
			}
			$seen[ $code ] = true;
			$actions[] = self::quality_verdict_next_action_for_code( $code );
		}

		usort(
			$actions,
			static function ( array $a, array $b ): int {
				$order = array(
					'critical' => 0,
					'high'     => 1,
					'medium'   => 2,
					'low'      => 3,
				);
				return ( $order[ (string) ( $a['priority'] ?? 'low' ) ] ?? 9 ) <=> ( $order[ (string) ( $b['priority'] ?? 'low' ) ] ?? 9 );
			}
		);

		return $actions;
	}

	/**
	 * Map a verdict blocker code to a safe AI-operator action.
	 */
	private static function quality_verdict_next_action_for_code( string $code ): array {
		switch ( sanitize_key( $code ) ) {
			case 'technical_qa_failed':
				return self::quality_verdict_next_action(
					'run_translation_qa',
					'fix_translation_integrity',
					'Run translation QA and repair the reported integrity issue before publishing.',
					'critical',
					false,
					'ai-translations/qa-translation'
				);
			case 'linguistic_review_not_current':
				return self::quality_verdict_next_action(
					'refresh_linguistic_review',
					'refresh_linguistic_review',
					'Refresh linguistic review evidence after checking the current translated content.',
					'high',
					true,
					'ai-translations/mark-linguistic-reviewed'
				);
			case 'missing_source':
				return self::quality_verdict_next_action(
					'inspect_source_mapping',
					'inspect_source_mapping',
					'Inspect the translation mapping because the source content could not be resolved.',
					'critical',
					true,
					'ai-translations/list-translations'
				);
			case 'content_not_published':
				return self::quality_verdict_next_action(
					'publish_or_use_pre_publish_stage',
					'publish_or_switch_stage',
					'Publish the content or request a pre-publish verdict for draft-stage work.',
					'medium',
					false,
					'ai-translations/publish-translation'
				);
			case 'quality_review_not_current':
				return self::quality_verdict_next_action(
					'review_visible_page',
					'review_visible_page',
					'Review the visible page and mark quality review evidence when it passes.',
					'high',
					true,
					'ai-translations/mark-quality-reviewed'
				);
			case 'seo_meta_not_current':
				return self::quality_verdict_next_action(
					'refresh_seo_meta',
					'refresh_seo_meta',
					'Refresh stored SEO metadata so search, social, and schema titles match the current content.',
					'medium',
					false,
					'rankmath/update-meta'
				);
			case 'open_copy_feedback':
				return self::quality_verdict_next_action(
					'resolve_copy_feedback',
					'resolve_copy_feedback',
					'Resolve open native or agency copy feedback before treating the page as ready.',
					'high',
					true,
					'ai-translations/record-copy-feedback'
				);
			default:
				return self::quality_verdict_next_action(
					'inspect_verdict',
					'inspect_verdict',
					'Inspect the verdict state before continuing.',
					'low',
					true,
					''
				);
		}
	}

	/**
	 * Compact public next-action row.
	 */
	private static function quality_verdict_next_action( string $code, string $category, string $message, string $priority, bool $requires_human, string $ability ): array {
		$out = array(
			'code'           => sanitize_key( $code ),
			'category'       => sanitize_key( $category ),
			'priority'       => sanitize_key( $priority ),
			'requires_human' => $requires_human,
			'message'        => self::normalize_review_text( $message ),
		);
		if ( '' !== $ability ) {
			$out['suggested_ability'] = sanitize_text_field( $ability );
		}

		return $out;
	}

	/**
	 * Stable public action taxonomy for AI operators.
	 */
	private static function quality_verdict_next_action_category( string $code ): string {
		switch ( sanitize_key( $code ) ) {
			case 'technical_qa_failed':
				return 'fix_translation_integrity';
			case 'linguistic_review_not_current':
				return 'refresh_linguistic_review';
			case 'missing_source':
				return 'inspect_source_mapping';
			case 'content_not_published':
				return 'publish_or_switch_stage';
			case 'quality_review_not_current':
				return 'review_visible_page';
			case 'seo_meta_not_current':
				return 'refresh_seo_meta';
			case 'open_copy_feedback':
				return 'resolve_copy_feedback';
			default:
				return 'inspect_verdict';
		}
	}

	/**
	 * Sanitize a list of public string tokens.
	 */
	private static function quality_verdict_public_string_list( $items ): array {
		if ( ! is_array( $items ) ) {
			return array();
		}

		$out = array();
		foreach ( $items as $item ) {
			$item = sanitize_key( (string) $item );
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Build the local quality verdict for a loaded post.
	 */
	private static function quality_verdict_for_post( WP_Post $post, bool $include_qa = true, string $stage = 'auto' ): array {
		$post_id        = (int) $post->ID;
		$language_context = self::review_language_context_for_post( $post );
		$is_translation = ! empty( $language_context['is_translation'] );
		$stage          = self::quality_verdict_stage( $stage, $post );
		$source_id      = absint( $language_context['source_id'] ?? 0 );
		$source         = $source_id ? get_post( $source_id ) : null;
		$language       = sanitize_key( (string) ( $language_context['target_language'] ?? '' ) );
		$signals        = array();
		$blockers       = array();
		$qa             = null;
		$fitness        = null;
		$internal_linking = self::internal_link_opportunities_for_post( $post, $language, 3 );

		if ( $is_translation ) {
			$qa = self::qa_translation( array( 'translation_id' => $post_id ) );
			$fitness = isset( $qa['fitness'] ) && is_array( $qa['fitness'] ) ? $qa['fitness'] : null;
			if ( empty( $qa['success'] ) || empty( $qa['passed'] ) ) {
				$blockers[] = self::quality_verdict_blocker(
					'technical_qa_failed',
					'block_publish',
					'Local translation QA has unresolved issues.',
					array(
						'issue_codes'   => self::qa_item_codes( $qa['issues'] ?? array() ),
						'warning_codes' => self::qa_item_codes( $qa['warnings'] ?? array() ),
					)
				);
			}

			$linguistic = self::linguistic_review_state_for_post( $post_id );
			if ( empty( $linguistic['passed'] ) ) {
				$blockers[] = self::quality_verdict_blocker(
					'linguistic_review_not_current',
					'human_review_required',
					'Current linguistic review evidence is missing or stale.',
					array(
						'state'         => (string) ( $linguistic['state'] ?? '' ),
						'stale_reasons' => $linguistic['stale_reasons'] ?? array(),
						'missing_checks'=> $linguistic['missing_checks'] ?? array(),
					)
				);
			}
			$signals['linguistic_review'] = $linguistic;
		} else {
			$signals['linguistic_review'] = null;
		}

		if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) ) {
			$blockers[] = self::quality_verdict_blocker( 'missing_source', 'block_publish', 'Source content is missing.' );
		}

		if ( 'post_publish' === $stage && 'publish' !== $post->post_status ) {
			$blockers[] = self::quality_verdict_blocker(
				'content_not_published',
				'needs_work',
				'Quality verdict is based on non-published content.',
				array( 'post_status' => $post->post_status )
			);
		}

		$seo_meta_state = self::rank_math_seo_meta_state_for_post( $post );
		if ( 'post_publish' === $stage && empty( $seo_meta_state['passed'] ) ) {
			$blockers[] = self::quality_verdict_blocker(
				'seo_meta_not_current',
				'needs_work',
				'Stored SEO metadata no longer matches the current visible title.',
				array(
					'state'        => (string) ( $seo_meta_state['state'] ?? '' ),
					'stale_fields' => $seo_meta_state['stale_fields'] ?? array(),
				)
			);
		}

		$reviewed_at      = (string) get_post_meta( $post_id, self::META_QUALITY_REVIEWED_AT, true );
		$quality_state    = self::quality_review_state_for_post( $post, $reviewed_at, $language );
		$quality_checks   = self::quality_review_checks_for_post( $post_id );
		$required_quality = self::required_quality_review_checks( $language );
		$missing_quality  = self::missing_review_checks( $quality_checks, $required_quality );
		$quality_evidence = self::quality_review_evidence_for_post( $post_id );
		if ( 'post_publish' === $stage && 'reviewed' !== $quality_state ) {
			$blockers[] = self::quality_verdict_blocker(
				'quality_review_not_current',
				'human_review_required',
				'Whole-page quality review is missing or stale.',
				array(
					'state'          => $quality_state,
					'missing_checks' => $missing_quality,
				)
			);
		}

		$open_feedback = self::open_copy_feedback_for_post( $post_id );
		if ( $open_feedback ) {
			$has_blocking = false;
			foreach ( $open_feedback as $feedback ) {
				if ( 'blocking' === (string) ( $feedback['severity'] ?? '' ) ) {
					$has_blocking = true;
					break;
				}
			}
			$blockers[] = self::quality_verdict_blocker(
				'open_copy_feedback',
				$has_blocking ? 'block_publish' : 'human_review_required',
				'Open native or agency copy feedback must be resolved.',
				array( 'open_feedback_count' => count( $open_feedback ) )
			);
		}

		$agency_copy = array(
			'enabled'          => self::agency_copy_review_enabled( $language ),
			'profile'          => self::agency_copy_review_profile( $language ),
			'review_questions' => self::agency_copy_review_questions( $language ),
		);

		$verdict = self::quality_verdict_status_from_blockers( $blockers );
		$scores  = self::quality_verdict_scores( $is_translation, $qa, $signals['linguistic_review'], $quality_state, $open_feedback, $agency_copy, $stage );

		$response = array(
			'success'        => true,
			'schema_version' => 1,
			'adapter'        => 'local',
			'stage'          => $stage,
			'verdict'        => $verdict,
			'publishable'    => 'pass' === $verdict,
			'blockers'       => $blockers,
			'scores'         => $scores,
				'content'        => self::source_summary_payload( $post ),
				'source'         => $source ? self::source_summary_payload( $source ) : null,
				'language'       => $language,
				'language_context' => $language_context,
				'is_source'      => ! $is_translation,
			'is_translation' => $is_translation,
			'signals'        => array_merge(
				$signals,
				array(
					'quality_review' => array(
						'state'          => $quality_state,
						'reviewed_at'    => $reviewed_at,
						'required_checks'=> $required_quality,
						'missing_checks' => $missing_quality,
						'checks'         => $quality_checks,
						'evidence'       => $quality_evidence,
					),
					'copy_feedback' => array(
						'open_count' => count( $open_feedback ),
						'open'       => $open_feedback,
					),
					'seo_meta' => $seo_meta_state,
					'internal_linking' => $internal_linking,
					'agency_copy' => $agency_copy,
				)
			),
			'evidence'       => array(
				'plugin_version'   => self::VERSION,
				'evaluated_at'     => gmdate( 'c' ),
				'source_id'        => $source_id,
				'content_hash'     => self::translation_review_content_hash( $post ),
				'source_hash'      => $source ? self::source_hash( $source ) : '',
				'fitness_passed'   => is_array( $fitness ) ? ! empty( $fitness['passed'] ) : null,
				'fitness_issue_codes' => is_array( $fitness ) ? self::qa_item_codes( $fitness['issues'] ?? array() ) : array(),
			),
		);
		if ( $include_qa && null !== $qa ) {
			$response['qa'] = $qa;
		}

		return $response;
	}

	/**
	 * Compact blocker row for a quality verdict.
	 */
	private static function quality_verdict_blocker( string $code, string $severity, string $message, array $details = array() ): array {
		return self::compact_quality_profile(
			array(
				'code'     => sanitize_key( $code ),
				'severity' => sanitize_key( $severity ),
				'message'  => self::normalize_review_text( $message ),
				'details'  => $details,
			)
		);
	}

	/**
	 * Final verdict classification from blocker severities.
	 */
	private static function quality_verdict_status_from_blockers( array $blockers ): string {
		$severities = array_map(
			static function ( array $blocker ): string {
				return (string) ( $blocker['severity'] ?? '' );
			},
			$blockers
		);
		if ( in_array( 'block_publish', $severities, true ) ) {
			return 'block_publish';
		}
		if ( in_array( 'human_review_required', $severities, true ) ) {
			return 'human_review_required';
		}
		if ( in_array( 'needs_work', $severities, true ) ) {
			return 'needs_work';
		}

		return 'pass';
	}

	/**
	 * Simple local scorecard for the verdict surface.
	 */
	private static function quality_verdict_scores( bool $is_translation, $qa, $linguistic_review, string $quality_state, array $open_feedback, array $agency_copy, string $stage = 'post_publish' ): array {
		$whole_page_quality = 'post_publish' === $stage ? ( 'reviewed' === $quality_state ? 100 : 0 ) : null;
		$commercial_clarity = null;
		if ( ! empty( $agency_copy['enabled'] ) && 'post_publish' === $stage ) {
			$commercial_clarity = 'reviewed' === $quality_state && empty( $open_feedback ) ? 100 : 0;
		}

		return array(
			'technical'          => ! $is_translation || ( is_array( $qa ) && ! empty( $qa['passed'] ) ) ? 100 : 0,
			'linguistic'         => ! $is_translation || ( is_array( $linguistic_review ) && ! empty( $linguistic_review['passed'] ) ) ? 100 : 0,
			'whole_page_quality' => $whole_page_quality,
			'copy_feedback'      => empty( $open_feedback ) ? 100 : 0,
			'commercial_clarity' => $commercial_clarity,
		);
	}

	/**
	 * Detect custom Rank Math titles that were left behind after a content title change.
	 */
	private static function rank_math_seo_meta_state_for_post( WP_Post $post ): array {
		$post_id       = (int) $post->ID;
		$current_title = self::normalize_review_text( wp_strip_all_tags( get_the_title( $post ) ) );
		$seo_title     = self::normalize_review_text( (string) get_post_meta( $post_id, 'rank_math_title', true ) );
		$description   = self::normalize_review_text( (string) get_post_meta( $post_id, 'rank_math_description', true ) );
		$focus_keyword = self::normalize_review_text( (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ) );
		$stale_fields  = array();

		if ( '' !== $seo_title && '' !== $current_title && ! self::rank_math_seo_title_matches_post_title( $seo_title, $current_title ) ) {
			$stale_fields[] = 'rank_math_title';
		}

		return array(
			'passed'       => empty( $stale_fields ),
			'state'        => empty( $stale_fields ) ? ( '' === $seo_title ? 'default_title_pattern' : 'current' ) : 'stale',
			'stale_fields' => $stale_fields,
			'has_custom_title' => '' !== $seo_title,
			'has_description'  => '' !== $description,
			'has_focus_keyword'=> '' !== $focus_keyword,
		);
	}

	/**
	 * Rank Math titles may be exact titles, contain the title, or use %title%.
	 */
	private static function rank_math_seo_title_matches_post_title( string $seo_title, string $post_title ): bool {
		$seo_title  = self::normalize_review_text( $seo_title );
		$post_title = self::normalize_review_text( $post_title );
		if ( '' === $seo_title || '' === $post_title ) {
			return true;
		}
		if ( false !== strpos( $seo_title, '%title%' ) ) {
			return true;
		}
		$seo_lower  = self::lower_review_text( $seo_title );
		$post_lower = self::lower_review_text( $post_title );

		return $seo_lower === $post_lower || false !== strpos( $seo_lower, $post_lower );
	}

	/**
	 * Publish a translation after optional QA.
	 */
	private static function publish_translation( array $input ): array {
		$translation_id = absint( $input['translation_id'] ?? 0 );
		$post           = get_post( $translation_id );
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			return self::error( 'Translation content not found.' );
		}
		if ( ! self::is_translation_post( $translation_id ) ) {
			return self::error( 'The content is not registered as a registered translation.' );
		}

		$gate = self::translation_publish_gate( $input, $translation_id );
		if ( empty( $gate['success'] ) ) {
			return $gate;
		}

		$qa         = $gate['qa'];
		$review_state = $gate['review_state'] ?? null;
		$quality_verdict = $gate['quality_verdict'] ?? null;
		$language   = (string) $gate['language'];
		$source_id  = (int) $gate['source_id'];
		$transition = self::apply_translation_publish_transition( $translation_id, $language, $source_id );
		if ( empty( $transition['success'] ) ) {
			return $transition;
		}

		$menu = null;
		if ( 'page' === $post->post_type && ( array_key_exists( 'sync_menu', $input ) ? (bool) $input['sync_menu'] : true ) ) {
			$menu = self::sync_language_menu(
				array(
					'language'             => $language,
					'clear_existing'       => true,
						'include_untranslated' => false,
						'include_custom_links' => array_key_exists( 'include_custom_links', $input ) ? (bool) $input['include_custom_links'] : true,
				)
			);
		}

		$translation = self::translation_payload( get_post( $translation_id ) );
		$purge_urls  = $transition['purge_urls'];
		$live_verification = null;
		if ( array_key_exists( 'verify_live', $input ) ? (bool) $input['verify_live'] : true ) {
			$live_verification = self::verify_live_translation(
				array(
					'translation_id' => $translation_id,
					'timeout'        => absint( $input['live_verification_timeout'] ?? 15 ),
				)
			);
			if ( empty( $live_verification['success'] ) || empty( $live_verification['passed'] ) ) {
				return array(
					'success'           => false,
					'published'         => true,
					'message'           => 'Translation was published, but live verification failed.',
					'translation'       => $translation,
					'qa'                => $qa,
					'review_state'      => $review_state,
					'quality_verdict'   => $quality_verdict,
					'menu'              => $menu,
					'purge_urls'        => $purge_urls,
					'link_repair'       => $transition['link_repair'] ?? null,
					'live_verification' => $live_verification,
				);
			}
		}

		return array(
			'success'           => true,
			'message'           => 'Translation published.',
			'translation'       => $translation,
			'qa'                => $qa,
			'review_state'      => $review_state,
			'quality_verdict'   => $quality_verdict,
			'menu'              => $menu,
			'purge_urls'        => $purge_urls,
			'link_repair'       => $transition['link_repair'] ?? null,
			'live_verification' => $live_verification,
		);
	}

	/**
	 * Verify translated content through the frontend after publication.
	 */
	private static function verify_live_translation( array $input ): array {
		$translation_id = absint( $input['translation_id'] ?? 0 );
		$post           = get_post( $translation_id );
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			return self::error( 'Translation content not found.' );
		}
		if ( ! self::is_translation_post( $translation_id ) ) {
			return self::error( 'The content is not registered as a registered translation.' );
		}

		$translation = self::translation_payload( $post );
		$language    = sanitize_key( (string) ( $translation['language'] ?? '' ) );
		$url         = isset( $translation['url'] ) ? esc_url_raw( (string) $translation['url'] ) : '';
		$issues      = array();
		$warnings    = array();
		$body        = '';
		$status_code = 0;
		$final_url   = $url;

		if ( 'publish' !== $post->post_status ) {
			$issues[] = self::qa_item( 'translation_not_published', 'Live verification requires a published translation.', array( 'status' => $post->post_status ) );
		}
		if ( ! self::is_translation_language( $language ) ) {
			$issues[] = self::qa_item( 'missing_or_unknown_language', 'Translation language is missing or not configured.' );
		}
		if ( '' === $url ) {
			$issues[] = self::qa_item( 'missing_permalink', 'Translation has no frontend permalink.' );
		}

		if ( $url && empty( $issues ) ) {
			$timeout  = max( 3, min( 30, absint( $input['timeout'] ?? 15 ) ) );
			$attempts = array(
				add_query_arg( 'devenia_translation_verify', (string) time(), $url ),
				$url,
			);
			$attempt_errors = array();

			foreach ( $attempts as $test_url ) {
				$response = wp_remote_get(
					$test_url,
					array(
						'timeout'     => $timeout,
						'redirection' => 0,
						'headers'     => array(
							'Cache-Control' => 'no-cache',
						),
					)
				);

				if ( is_wp_error( $response ) ) {
					$attempt_errors[] = array(
						'url'   => $test_url,
						'error' => $response->get_error_message(),
					);
					continue;
				}

				$status_code = (int) wp_remote_retrieve_response_code( $response );
				$body        = (string) wp_remote_retrieve_body( $response );
				$final_url   = $test_url;

				if ( isset( $response['http_response'] ) && is_object( $response['http_response'] ) && method_exists( $response['http_response'], 'get_response_object' ) ) {
					$response_object = $response['http_response']->get_response_object();
					if ( is_object( $response_object ) && ! empty( $response_object->url ) ) {
						$final_url = (string) $response_object->url;
					}
				}

				if ( 200 === $status_code && '' !== trim( $body ) ) {
					break;
				}

				$attempt_errors[] = array(
					'url'    => $test_url,
					'status' => $status_code,
					'empty'  => '' === trim( $body ),
					'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
				);
			}

			if ( 200 !== $status_code ) {
				$issues[] = self::qa_item( 'frontend_http_status_not_ok', 'Frontend translation URL did not return HTTP 200.', array( 'attempts' => $attempt_errors ) );
			}
			if ( '' === trim( $body ) ) {
				$issues[] = self::qa_item( 'frontend_empty_body', 'Frontend translation URL returned an empty body.', array( 'attempts' => $attempt_errors ) );
			}
		}

		if ( '' !== $body ) {
			$prefix = self::language_prefix( $language );
			if ( $prefix ) {
				$path = (string) wp_parse_url( $final_url, PHP_URL_PATH );
				$path = trim( $path, '/' );
				if ( $path !== $prefix && 0 !== strpos( $path, $prefix . '/' ) ) {
					$issues[] = self::qa_item( 'frontend_language_prefix_mismatch', 'Live frontend URL is not under the configured language prefix.', array( 'expected_prefix' => $prefix, 'final_url' => $final_url ) );
				}
			}

			$expected_lang = self::html_lang_for_language( $language );
			if ( $expected_lang && ! preg_match( '/<html\b[^>]*\blang=["\']' . preg_quote( $expected_lang, '/' ) . '["\']/i', $body ) ) {
				$issues[] = self::qa_item( 'frontend_html_lang_mismatch', 'Live content html lang does not match the translation language.', array( 'expected_lang' => $expected_lang ) );
			}

			$expected_hreflang = self::hreflang_for_language( $language );
			if ( ! preg_match( '/<link\b[^>]*rel=["\']alternate["\'][^>]*hreflang=["\']' . preg_quote( $expected_hreflang, '/' ) . '["\']/i', $body ) ) {
				$warnings[] = self::qa_item( 'frontend_hreflang_missing', 'Live page does not expose the expected hreflang alternate for this language.', array( 'language' => $language, 'hreflang' => $expected_hreflang ) );
			}

			foreach ( self::localized_link_issues_for_html( self::frontend_link_check_html( $body ), $language ) as $link_issue ) {
				$issues[] = self::qa_item(
					'frontend_localized_link_target_mismatch',
					'Live content contains an internal link that points to the wrong language target.',
					$link_issue
				);
			}
		}

		return array(
			'success'       => true,
			'passed'        => empty( $issues ),
			'issues'        => $issues,
			'warnings'      => $warnings,
			'issue_count'   => count( $issues ),
			'warning_count' => count( $warnings ),
			'status_code'   => $status_code,
			'url'           => $url,
			'final_url'     => $final_url,
			'translation'   => $translation,
		);
	}

	/**
	 * Restrict frontend link checks to the main content surface, not shared chrome.
	 */
	private static function frontend_link_check_html( string $body ): string {
		if ( preg_match( '/<main\b[^>]*>.*?<\/main>/is', $body, $match ) ) {
			return $match[0];
		}

		return $body;
	}

	/**
	 * Validate publish prerequisites in one place.
	 */
	private static function translation_publish_gate( array $input, int $translation_id ): array {
		$run_qa = array_key_exists( 'run_qa', $input ) ? (bool) $input['run_qa'] : true;
		$qa     = null;
		if ( $run_qa ) {
			$qa = self::qa_translation( $input );
			if ( empty( $qa['success'] ) || empty( $qa['passed'] ) ) {
				return array(
					'success' => false,
					'message' => 'QA failed. Translation was not published.',
					'qa'      => $qa,
				);
			}
			if ( empty( $input['allow_warnings'] ) && ! empty( $qa['warning_count'] ) ) {
				return array(
					'success' => false,
					'message' => 'QA warnings found and allow_warnings is false. Translation was not published.',
					'qa'      => $qa,
				);
			}
		}
		$source_id = absint( get_post_meta( $translation_id, self::META_SOURCE_ID, true ) );
		$integrity = self::translation_integrity_guardrails( (string) get_post_field( 'post_content', $translation_id ), $source_id );
		if ( ! empty( $integrity['issues'] ) ) {
			return array(
				'success'              => false,
				'message'              => 'Translation integrity guard failed. Do not publish manual halfway localization surfaces.',
				'translation_integrity' => $integrity,
				'qa'                   => $qa,
			);
		}
		$route_integrity = self::translation_route_integrity( $translation_id, (string) get_post_meta( $translation_id, self::META_LANGUAGE, true ) );
		if ( ! empty( $route_integrity['issues'] ) ) {
			return array(
				'success'          => false,
				'message'          => 'Translation route integrity failed. Resolve localized URL collisions before publishing.',
				'route_integrity'  => $route_integrity,
				'qa'               => $qa,
			);
		}

		$review_state = self::linguistic_review_state_for_post( $translation_id );
		if ( empty( $review_state['passed'] ) ) {
			return array(
				'success'      => false,
				'message'      => 'Current linguistic review evidence is required before publishing.',
				'review_state' => $review_state,
				'qa'           => $qa,
			);
		}

		$translation_post = get_post( $translation_id );
		$quality_verdict  = $translation_post instanceof WP_Post ? self::quality_verdict_present_for_audience( self::quality_verdict_for_post( $translation_post, false, 'pre_publish' ), 'ai_operator' ) : null;

		return array(
			'success'         => true,
			'qa'              => $qa,
			'review_state'    => $review_state,
			'quality_verdict' => $quality_verdict,
			'source_id'       => $source_id,
			'language'        => (string) get_post_meta( $translation_id, self::META_LANGUAGE, true ),
		);
	}

	/**
	 * Apply the publish transition and its side effects in one place.
	 */
	private static function apply_translation_publish_transition( int $translation_id, string $language, int $source_id ): array {
		$source = $source_id ? get_post( $source_id ) : null;
		$postarr = array(
			'ID'          => $translation_id,
			'post_status' => 'publish',
		);
		if ( $source && 'post' === get_post_type( $translation_id ) && 'post' === (string) $source->post_type ) {
			$postarr = array_merge( $postarr, self::source_publication_date_fields( $source ) );
		}

		$result = wp_update_post(
			wp_slash( $postarr ),
			true
		);
		if ( is_wp_error( $result ) ) {
			return self::error( $result->get_error_message() );
		}

		if ( $source ) {
			self::enforce_translation_parent( $translation_id, $source, $language );
			self::localize_internal_links_for_post( $translation_id, $language );
			self::apply_translation_lifecycle_meta( $translation_id, $source_id, $language, 'published', $source );
			self::localized_internal_link_map( $language, true );
			self::localized_link_expected_target_map( $language, true );
			self::localized_link_module( $language, true );
			$route_integrity = self::translation_route_integrity( $translation_id, $language );
			if ( ! empty( $route_integrity['issues'] ) ) {
				return array(
					'success'         => false,
					'message'         => 'Translation route integrity failed after publish transition.',
					'route_integrity' => $route_integrity,
				);
			}
			$link_repair = self::repair_internal_links(
				array(
					'languages' => array( $language ),
				)
			);
		} else {
			update_post_meta( $translation_id, self::META_STATUS, 'published' );
			update_post_meta( $translation_id, self::META_REVIEWED_AT, gmdate( 'c' ) );
			update_post_meta( $translation_id, self::META_LOCALIZED_PATH, self::localized_path_for_post( $translation_id, $language ) );
			self::sync_translation_index_row( $translation_id );
			$link_repair = null;
		}

		clean_post_cache( $translation_id );
		if ( $source_id ) {
			clean_post_cache( $source_id );
		}
		self::flush_sitemap_cache();

		$translation = self::translation_payload( get_post( $translation_id ) );
		$link_repair_urls = array();
		if ( is_array( $link_repair ) && ! empty( $link_repair['changed'] ) && is_array( $link_repair['changed'] ) ) {
			foreach ( $link_repair['changed'] as $changed_item ) {
				$changed_url = isset( $changed_item['url'] ) ? (string) $changed_item['url'] : '';
				if ( '' !== $changed_url ) {
					$link_repair_urls[] = $changed_url;
				}
			}
		}

		$blog_archive_urls = array();
		if ( 'post' === get_post_type( $translation_id ) || ( $source_id && 'post' === get_post_type( $source_id ) ) ) {
			$blog_archive_urls = self::localized_blog_archive_purge_urls();
		}

		$purge_urls  = array_values(
			array_filter(
				array_unique(
					array_merge(
						array(
							$translation['url'] ?? '',
							$source_id ? get_permalink( $source_id ) : '',
						),
						$link_repair_urls,
						$blog_archive_urls
					)
				)
			)
		);

		return array(
			'success'     => true,
			'purge_urls'  => $purge_urls,
			'link_repair' => $link_repair,
		);
	}

	/**
	 * Keep SEO discovery files fresh after translation changes.
	 */
	private static function flush_sitemap_cache(): void {
		if ( class_exists( '\RankMath\Sitemap\Cache' ) && method_exists( '\RankMath\Sitemap\Cache', 'invalidate_storage' ) ) {
			\RankMath\Sitemap\Cache::invalidate_storage();
		}
	}

	/**
	 * Mark a translation as linguistically reviewed.
	 */
	private static function mark_linguistic_reviewed( array $input ): array {
		$translation_id = absint( $input['translation_id'] ?? 0 );
		$post           = get_post( $translation_id );
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			return self::error( 'Translation content not found.' );
		}
		if ( ! self::is_translation_post( $translation_id ) ) {
			return self::error( 'The content is not registered as a registered translation.' );
		}
		$language = sanitize_key( (string) get_post_meta( $translation_id, self::META_LANGUAGE, true ) );

		if ( array_key_exists( 'run_qa', $input ) ? (bool) $input['run_qa'] : true ) {
			$qa = self::qa_translation( $input );
			if ( empty( $qa['success'] ) || empty( $qa['passed'] ) ) {
				return array(
					'success' => false,
					'message' => 'QA failed. Linguistic review was not marked complete.',
					'qa'      => $qa,
				);
			}
		} else {
			$qa = null;
		}

		$fitness = is_array( $qa ) && isset( $qa['fitness'] ) && is_array( $qa['fitness'] )
			? $qa['fitness']
			: self::translation_fitness(
				(string) $post->post_content,
				absint( get_post_meta( $translation_id, self::META_SOURCE_ID, true ) ) ? (string) get_post_field( 'post_content', absint( get_post_meta( $translation_id, self::META_SOURCE_ID, true ) ) ) : '',
				(string) get_post_meta( $translation_id, self::META_LANGUAGE, true ),
				(string) $post->post_title,
				(string) $post->post_excerpt,
				absint( get_post_meta( $translation_id, self::META_SOURCE_ID, true ) )
			);
		if ( empty( $fitness['passed'] ) ) {
			return array(
				'success' => false,
				'message' => 'Translation fitness failed. Linguistic review was not marked complete.',
				'fitness' => $fitness,
				'qa'      => $qa,
			);
		}

		$required_checks = self::required_linguistic_review_checks( $language );
		$review_checks   = self::review_checks_from_input( $input, $required_checks );
		$missing_checks  = self::missing_review_checks( $review_checks, $required_checks );
		if ( $missing_checks ) {
			return array(
				'success'         => false,
				'message'         => 'Linguistic review requires natural-language, direct-translation, conversion-copy, source-fidelity, and locale-terminology checks.',
				'missing_checks'  => $missing_checks,
				'required_checks' => $required_checks,
				'qa'              => $qa,
			);
		}

		$reviewer = ! empty( $input['reviewer'] ) ? sanitize_text_field( (string) $input['reviewer'] ) : 'AI Translation Workflow';
		$note     = ! empty( $input['note'] ) ? sanitize_textarea_field( (string) $input['note'] ) : '';

		update_post_meta( $translation_id, self::META_LINGUISTIC_REVIEWED_AT, gmdate( 'c' ) );
		update_post_meta( $translation_id, self::META_LINGUISTIC_REVIEWER, $reviewer );
		update_post_meta( $translation_id, self::META_LINGUISTIC_REVIEW_CHECKS, wp_json_encode( $review_checks ) );
		update_post_meta( $translation_id, self::META_LINGUISTIC_REVIEW_EVIDENCE, wp_json_encode( self::translation_review_evidence( $translation_id, $fitness ) ) );
		if ( '' !== $note ) {
			update_post_meta( $translation_id, self::META_LINGUISTIC_REVIEW_NOTE, $note );
		} else {
			delete_post_meta( $translation_id, self::META_LINGUISTIC_REVIEW_NOTE );
		}
		update_post_meta( $translation_id, self::META_STATUS, 'reviewed' );
		update_post_meta( $translation_id, self::META_REVIEWED_AT, gmdate( 'c' ) );
		self::sync_translation_index_row( $translation_id );

		return array(
			'success'     => true,
			'message'     => 'Linguistic review marked complete.',
			'qa'          => $qa,
			'fitness'     => $fitness,
			'review_state'=> self::linguistic_review_state_for_post( $translation_id ),
			'translation' => self::translation_payload( get_post( $translation_id ) ),
		);
	}

	/**
	 * Return per-language workflow status for a source page.
	 */
	private static function workflow_status( int $source_id ): array {
		$source = get_post( $source_id );
		if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) || self::is_translation_post( $source_id ) ) {
			return self::error( 'Source content not found.' );
		}

		$translations = array();
		foreach ( self::translation_rows_for_source( $source_id ) as $row ) {
			$translations[ $row['language'] ] = $row;
		}

		$languages = self::languages();
		$rows      = array();
		foreach ( $languages as $language => $config ) {
			if ( ! empty( $config['source'] ) ) {
				continue;
			}
			$row      = $translations[ $language ] ?? null;
			$rows[] = array(
				'language'    => $language,
				'name'        => $config['name'] ?? strtoupper( $language ),
				'flag'        => $config['flag'] ?? strtoupper( $language ),
				'prefix'      => $config['prefix'] ?? '',
				'state'       => $row ? self::queue_state_for_translation( $row ) : 'missing',
				'translation' => $row,
			);
		}

		return array(
			'success'     => true,
			'source'      => self::post_payload( $source ),
			'source_hash' => self::source_hash( $source ),
			'languages'   => $rows,
		);
	}

	/**
	 * Return a compact translation work queue for source pages.
	 */
	private static function translation_queue( array $input ): array {
		$source_id        = absint( $input['source_id'] ?? 0 );
		$limit            = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 50;
		$include_complete = ! empty( $input['include_complete'] );
		$status_filter    = self::queue_status_filter( $input['statuses'] ?? array() );
		$sources          = array();

		if ( $source_id ) {
			$source = get_post( $source_id );
			if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) || self::is_translation_post( $source_id ) ) {
				return self::error( 'Source content not found.' );
			}
			$sources[] = $source;
		} else {
			$query = self::source_page_query(
				array(
					'post_status'    => 'publish',
					'posts_per_page' => 1000,
					'orderby'        => 'modified',
					'order'          => 'DESC',
				)
			);
			foreach ( $query->posts as $candidate ) {
				if ( self::is_translation_post( (int) $candidate->ID ) ) {
					continue;
				}
				$sources[] = $candidate;
				if ( count( $sources ) >= $limit ) {
					break;
				}
			}
		}

		$items  = array();
		$totals = array(
			'missing'                 => 0,
			'stale'                   => 0,
			'draft'                   => 0,
			'needs_review'            => 0,
			'needs_linguistic_review' => 0,
			'ready_to_publish'        => 0,
			'complete'                => 0,
		);

		foreach ( $sources as $source ) {
			$item = self::queue_item_for_source( $source, $status_filter );
			foreach ( $item['languages'] as $language_row ) {
				if ( isset( $totals[ $language_row['state'] ] ) ) {
					++$totals[ $language_row['state'] ];
				}
			}

			if ( ( ! empty( $status_filter ) && ! empty( $item['languages'] ) ) || $item['action_count'] > 0 || $include_complete ) {
				$items[] = $item;
			}
		}

		return array(
			'success'          => true,
			'items'            => $items,
			'item_count'       => count( $items ),
			'inspected_count'  => count( $sources ),
			'totals'           => $totals,
			'status_filter'    => array_values( $status_filter ),
			'include_complete' => $include_complete,
		);
	}

	/**
	 * Return only translations that are waiting for review or publish.
	 */
	private static function review_queue( array $input ): array {
		$params = array(
			'limit'            => isset( $input['limit'] ) ? absint( $input['limit'] ) : 100,
			'include_complete' => false,
			'statuses'         => array( 'needs_review', 'needs_linguistic_review', 'ready_to_publish' ),
		);

		if ( ! empty( $input['source_id'] ) ) {
			$params['source_id'] = absint( $input['source_id'] );
		}

		$result = self::translation_queue( $params );
		if ( ! empty( $result['success'] ) ) {
			$result['queue'] = 'review';
			$result['review_status_order'] = array( 'needs_review', 'needs_linguistic_review', 'ready_to_publish' );
		}

		return $result;
	}

	/**
	 * Return published pages that need a full visible-page quality review.
	 */
	private static function quality_review_queue( array $input ): array {
		$page_id          = absint( $input['page_id'] ?? 0 );
		$source_id        = absint( $input['source_id'] ?? 0 );
		$limit            = isset( $input['limit'] ) ? max( 1, min( 1000, absint( $input['limit'] ) ) ) : 100;
		$include_reviewed = ! empty( $input['include_reviewed'] );
		$include_source   = ! empty( $input['include_source'] );
		$requested_order  = (string) ( $input['order'] ?? 'modified_asc' );
		$order            = in_array( $requested_order, array( 'modified_asc', 'modified_desc', 'title_asc' ), true ) ? $requested_order : 'modified_asc';
		$languages        = self::quality_review_language_filter( $input['languages'] ?? array(), $include_source );
		$status_filter    = self::quality_review_status_filter( $input['statuses'] ?? array() );
		$posts            = array();

		if ( $page_id ) {
			$post = get_post( $page_id );
			if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
				return self::error( 'Content not found.' );
			}
			$posts[] = $post;
		} elseif ( $source_id ) {
			$source = get_post( $source_id );
			if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) || self::is_translation_post( $source_id ) ) {
				return self::error( 'Source content not found.' );
			}
			if ( $include_source ) {
				$posts[] = $source;
			}
			foreach ( self::translation_rows_for_source( $source_id, array( 'publish' ) ) as $row ) {
				if ( ! empty( $row['id'] ) ) {
					$post = get_post( absint( $row['id'] ) );
					if ( $post ) {
						$posts[] = $post;
					}
				}
			}
		} else {
			$target_language_filter = array_values(
				array_intersect(
					$languages,
					array_keys( self::target_languages() )
				)
			);

			$query = self::translation_page_query(
				array(
					'post_status'    => array( 'publish' ),
					'posts_per_page' => 1000,
					'orderby'        => 'modified',
					'order'          => 'modified_desc' === $order ? 'DESC' : 'ASC',
				)
			);
			foreach ( $query->posts as $candidate ) {
				$candidate_language = (string) get_post_meta( $candidate->ID, self::META_LANGUAGE, true );
				if ( '' === $candidate_language ) {
					continue;
				}
				if ( $target_language_filter && ! in_array( $candidate_language, $target_language_filter, true ) ) {
					continue;
				}
				$posts[] = $candidate;
			}

			if ( $include_source ) {
				$source_query = self::source_page_query(
					array(
						'post_status'    => array( 'publish' ),
						'posts_per_page' => 1000,
						'orderby'        => 'modified',
						'order'          => 'modified_desc' === $order ? 'DESC' : 'ASC',
					)
				);
				foreach ( $source_query->posts as $candidate ) {
					if ( self::is_translation_post( (int) $candidate->ID ) ) {
						continue;
					}
					$posts[] = $candidate;
				}
			}
		}

		$items = array();
		$totals = array(
			'needs_quality_review' => 0,
			'quality_review_stale' => 0,
			'reviewed'             => 0,
			'not_published'        => 0,
		);

		foreach ( $posts as $post ) {
			$item = self::quality_review_queue_item( $post );
			if ( ! in_array( $item['language'], $languages, true ) ) {
				continue;
			}
			if ( isset( $totals[ $item['state'] ] ) ) {
				++$totals[ $item['state'] ];
			}
			if ( ! empty( $status_filter ) && ! in_array( $item['state'], $status_filter, true ) ) {
				continue;
			}
			if ( 'reviewed' === $item['state'] && ! $include_reviewed ) {
				continue;
			}
			$items[] = $item;
		}

		self::sort_quality_review_items( $items, $order );
		$items = array_slice( $items, 0, $limit );

		return array(
			'success'          => true,
			'queue'            => 'quality_review',
			'items'            => $items,
			'next_item'        => $items[0] ?? null,
			'item_count'       => count( $items ),
			'inspected_count'  => count( $posts ),
			'totals'           => $totals,
			'languages'        => array_values( $languages ),
			'status_filter'    => array_values( $status_filter ),
			'include_reviewed' => $include_reviewed,
			'include_source'   => $include_source,
			'order'            => $order,
		);
	}

	/**
	 * Mark a source or translated page as quality-reviewed.
	 */
	private static function mark_quality_reviewed( array $input ): array {
		$page_id = absint( $input['page_id'] ?? ( $input['content_id'] ?? 0 ) );
		$post    = get_post( $page_id );
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			return self::error( 'Content not found.' );
		}

		$language        = self::is_translation_post( $page_id ) ? sanitize_key( (string) get_post_meta( $page_id, self::META_LANGUAGE, true ) ) : self::source_language_code();
		$required_checks = self::required_quality_review_checks( $language );
		$review_checks   = self::review_checks_from_input( $input, $required_checks );
		$missing_checks  = self::missing_review_checks( $review_checks, $required_checks );
		if ( $missing_checks ) {
			return array(
				'success'         => false,
				'message'         => 'Quality review requires full-page, native-language, customer-visible-copy, factual-accuracy, and links/actions checks.',
				'missing_checks'  => $missing_checks,
				'required_checks' => $required_checks,
			);
		}

		$reviewer = ! empty( $input['reviewer'] ) ? sanitize_text_field( (string) $input['reviewer'] ) : 'AI Translation Workflow';
		$note     = ! empty( $input['note'] ) ? sanitize_textarea_field( (string) $input['note'] ) : '';

		update_post_meta( $page_id, self::META_QUALITY_REVIEWED_AT, gmdate( 'c' ) );
		update_post_meta( $page_id, self::META_QUALITY_REVIEWER, $reviewer );
		update_post_meta( $page_id, self::META_QUALITY_REVIEW_CHECKS, wp_json_encode( $review_checks ) );
		update_post_meta( $page_id, self::META_QUALITY_REVIEW_EVIDENCE, wp_json_encode( self::translation_review_evidence( $page_id ) ) );
		if ( '' !== $note ) {
			update_post_meta( $page_id, self::META_QUALITY_REVIEW_NOTE, $note );
		} else {
			delete_post_meta( $page_id, self::META_QUALITY_REVIEW_NOTE );
		}
		self::sync_translation_index_row( $page_id );

		return array(
			'success' => true,
			'message' => 'Quality review marked complete.',
			'item'    => self::quality_review_queue_item( get_post( $page_id ) ),
		);
	}

	/**
	 * Repair existing translation page parents and stored post paths.
	 */
	private static function repair_url_hierarchy( array $input ): array {
		$dry_run    = ! empty( $input['dry_run'] );
		$languages  = self::repair_language_filter( $input['languages'] ?? array() );
		$source_ids = self::repair_source_filter( $input['source_ids'] ?? array() );

		$query = self::translation_page_query(
			array(
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1000,
			)
		);

		$posts = array_values(
			array_filter(
				$query->posts,
				static function ( WP_Post $post ): bool {
					return '' !== (string) get_post_meta( $post->ID, self::META_LANGUAGE, true )
						&& 0 < absint( get_post_meta( $post->ID, self::META_SOURCE_ID, true ) );
				}
			)
		);
		usort(
			$posts,
			static function ( WP_Post $a, WP_Post $b ): int {
				$a_source = absint( get_post_meta( $a->ID, self::META_SOURCE_ID, true ) );
				$b_source = absint( get_post_meta( $b->ID, self::META_SOURCE_ID, true ) );
				return self::source_depth( $a_source ) <=> self::source_depth( $b_source );
			}
		);

		$checked = 0;
		$changed = array();
		$skipped = array();

		foreach ( $posts as $post ) {
			$translation_id = (int) $post->ID;
			$language       = (string) get_post_meta( $translation_id, self::META_LANGUAGE, true );
			$source_id      = absint( get_post_meta( $translation_id, self::META_SOURCE_ID, true ) );

			if ( ! in_array( $language, $languages, true ) ) {
				continue;
			}
			if ( $source_ids && ! in_array( $source_id, $source_ids, true ) ) {
				continue;
			}

			$source = get_post( $source_id );
			if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) || self::is_translation_post( $source_id ) ) {
				$skipped[] = array(
					'translation_id' => $translation_id,
					'source_id'      => $source_id,
					'language'       => $language,
					'reason'         => 'missing_source',
				);
				continue;
			}

			++$checked;
			$before_parent = (int) $post->post_parent;
			$target_parent = 'page' === (string) $source->post_type ? self::default_translation_parent_id( $source, $language ) : 0;
			$before_url    = get_permalink( $translation_id );
			$before_localized_path = trim( (string) get_post_meta( $translation_id, self::META_LOCALIZED_PATH, true ), '/' );
			$target_localized_path = self::expected_localized_path_for_post( $translation_id, $language );
			$localized_path_stale = '' !== $before_localized_path && '' !== $target_localized_path && $before_localized_path !== $target_localized_path;
			$duplicate_slug_repair = self::repair_translation_duplicate_slug_suffix( $translation_id, $language, $dry_run );
			if ( empty( $duplicate_slug_repair['success'] ) ) {
				$skipped[] = array(
					'translation_id' => $translation_id,
					'source_id'      => $source_id,
					'language'       => $language,
					'reason'         => 'duplicate_slug_repair_failed',
					'duplicate_slug_repair' => $duplicate_slug_repair,
				);
				continue;
			}
			if ( ! empty( $duplicate_slug_repair['changed'] ) && ! $dry_run ) {
				$post = get_post( $translation_id );
				if ( ! $post ) {
					$skipped[] = array(
						'translation_id' => $translation_id,
						'source_id'      => $source_id,
						'language'       => $language,
						'reason'         => 'missing_after_duplicate_slug_repair',
					);
					continue;
				}
				$target_localized_path = self::expected_localized_path_for_post( $translation_id, $language );
				$localized_path_stale = '' !== $before_localized_path && '' !== $target_localized_path && $before_localized_path !== $target_localized_path;
			}
			$duplicate_slug_changed = ! empty( $duplicate_slug_repair['changed'] );
			if ( $duplicate_slug_changed && $dry_run ) {
				$self_redirect_repair = self::repair_translation_rank_math_self_redirects( $translation_id, true );
				$changed[] = array(
					'translation_id' => $translation_id,
					'source_id'      => $source_id,
					'language'       => $language,
					'before_parent'  => $before_parent,
					'after_parent'   => $target_parent,
					'before_localized_path' => $before_localized_path,
					'after_localized_path'  => $target_localized_path,
					'duplicate_slug_repair' => $duplicate_slug_repair,
					'self_redirect_repair' => $self_redirect_repair,
					'before_url'     => $before_url,
					'after_url'      => $before_url,
				);
				continue;
			}
			$self_redirect_repair = self::repair_translation_rank_math_self_redirects( $translation_id, $dry_run );
			if ( empty( $self_redirect_repair['success'] ) ) {
				$skipped[] = array(
					'translation_id' => $translation_id,
					'source_id'      => $source_id,
					'language'       => $language,
					'reason'         => 'rank_math_self_redirect_repair_failed',
					'self_redirect_repair' => $self_redirect_repair,
				);
				continue;
			}
			$self_redirect_changed = ! empty( $self_redirect_repair['changed'] );
			if ( $duplicate_slug_changed ) {
				$route_integrity_after_slug_repair = self::translation_route_integrity( $translation_id, $language );
				if ( ! empty( $route_integrity_after_slug_repair['issues'] ) ) {
					$blocking_route_issues_after_slug_repair = array_values(
						array_filter(
							(array) $route_integrity_after_slug_repair['issues'],
							static function ( $issue ): bool {
								return ! is_array( $issue ) || 'localized_path_stale' !== (string) ( $issue['code'] ?? '' );
							}
						)
					);
					if ( empty( $blocking_route_issues_after_slug_repair ) ) {
						$localized_path_stale = true;
					} else {
						$skipped[] = array(
							'translation_id' => $translation_id,
							'source_id'      => $source_id,
							'language'       => $language,
							'reason'         => 'route_integrity_failed_after_duplicate_slug_repair',
							'route_integrity'=> $route_integrity_after_slug_repair,
							'duplicate_slug_repair' => $duplicate_slug_repair,
						);
						continue;
					}
				}
			}
			$route_integrity = self::translation_route_integrity( $translation_id, $language );
			if ( ! empty( $route_integrity['issues'] ) ) {
				$blocking_route_issues = array_values(
					array_filter(
						(array) $route_integrity['issues'],
						static function ( $issue ): bool {
							return ! is_array( $issue ) || 'localized_path_stale' !== (string) ( $issue['code'] ?? '' );
						}
					)
				);
				if ( empty( $blocking_route_issues ) ) {
					$localized_path_stale = true;
				} else {
					$skipped[] = array(
						'translation_id' => $translation_id,
						'source_id'      => $source_id,
						'language'       => $language,
						'reason'         => 'route_integrity_failed',
						'route_integrity'=> $route_integrity,
					);
					continue;
				}
			}

			if ( $before_parent === $target_parent ) {
				if ( ! $dry_run ) {
					update_post_meta( $translation_id, self::META_LOCALIZED_PATH, $target_localized_path );
					self::sync_translation_index_row( $translation_id );
				}
				if ( $duplicate_slug_changed || $self_redirect_changed || $localized_path_stale ) {
					$changed[] = array(
						'translation_id' => $translation_id,
						'source_id'      => $source_id,
						'language'       => $language,
						'before_parent'  => $before_parent,
						'after_parent'   => $target_parent,
						'before_localized_path' => $before_localized_path,
						'after_localized_path'  => $target_localized_path,
						'duplicate_slug_repair' => $duplicate_slug_repair,
						'self_redirect_repair' => $self_redirect_repair,
						'before_url'     => $before_url,
						'after_url'      => $dry_run ? $before_url : get_permalink( $translation_id ),
					);
				}
				continue;
			}

			if ( ! $dry_run ) {
				$result = 0;
				self::with_direct_save_storage_guardrails_suspended(
					static function () use ( &$result, $translation_id, $target_parent ): void {
						$result = wp_update_post(
							wp_slash(
								array(
									'ID'          => $translation_id,
									'post_parent' => $target_parent,
								)
							),
							true
						);
					}
				);
				if ( is_wp_error( $result ) ) {
					$skipped[] = array(
						'translation_id' => $translation_id,
						'source_id'      => $source_id,
						'language'       => $language,
						'reason'         => $result->get_error_message(),
					);
					continue;
				}
				clean_post_cache( $translation_id );
				$route_integrity_after = self::translation_route_integrity( $translation_id, $language );
				if ( ! empty( $route_integrity_after['issues'] ) ) {
					$blocking_route_issues_after_parent_repair = array_values(
						array_filter(
							(array) $route_integrity_after['issues'],
							static function ( $issue ): bool {
								return ! is_array( $issue ) || 'localized_path_stale' !== (string) ( $issue['code'] ?? '' );
							}
						)
					);
					if ( empty( $blocking_route_issues_after_parent_repair ) ) {
						$localized_path_stale = true;
					} else {
						$skipped[] = array(
							'translation_id' => $translation_id,
							'source_id'      => $source_id,
							'language'       => $language,
							'reason'         => 'route_integrity_failed_after_parent_repair',
							'route_integrity'=> $route_integrity_after,
						);
						continue;
					}
				}
				update_post_meta( $translation_id, self::META_LOCALIZED_PATH, self::expected_localized_path_for_post( $translation_id, $language ) );
				self::sync_translation_index_row( $translation_id );
			}

			$changed[] = array(
				'translation_id' => $translation_id,
				'source_id'      => $source_id,
				'language'       => $language,
				'before_parent'  => $before_parent,
				'after_parent'   => $target_parent,
				'before_localized_path' => $before_localized_path,
				'after_localized_path'  => self::expected_localized_path_for_post( $translation_id, $language ),
				'duplicate_slug_repair' => $duplicate_slug_repair,
				'self_redirect_repair' => $self_redirect_repair,
				'before_url'     => $before_url,
				'after_url'      => $dry_run ? $before_url : get_permalink( $translation_id ),
			);
		}

		if ( ! $dry_run && $changed ) {
			self::flush_sitemap_cache();
		}

		return array(
			'success'       => true,
			'dry_run'       => $dry_run,
			'checked_count' => $checked,
			'changed_count' => count( $changed ),
			'skipped_count' => count( $skipped ),
			'changed'       => $changed,
			'skipped'       => $skipped,
		);
	}

	/**
	 * Compute the canonical localized path from the current WordPress route.
	 *
	 * Unlike localized_path_for_post(), this intentionally ignores stored post
	 * meta so repair-url-hierarchy can replace stale translated post paths.
	 */
	private static function expected_localized_path_for_post( int $post_id, string $language ): string {
		$post = get_post( $post_id );
		if ( $post && 'post' === (string) $post->post_type ) {
			$blog_path = self::localized_blog_base_path( $language );
			$slug      = $post->post_name ?: sanitize_title( get_the_title( $post ) );
			if ( '' !== $blog_path && '' !== $slug ) {
				return trim( $blog_path . '/' . $slug, '/' );
			}

			$prefix = self::language_prefix( $language );
			if ( $prefix && '' !== $slug ) {
				return trim( $prefix . '/blog/' . $slug, '/' );
			}
		}

		return self::localized_path_for_post( $post_id, $language );
	}

	/**
	 * Repair translated page content links to point at localized translated pages.
	 */
	private static function repair_internal_links( array $input ): array {
		$dry_run    = ! empty( $input['dry_run'] );
		$languages  = self::repair_language_filter( $input['languages'] ?? array() );
		$source_ids = self::repair_source_filter( $input['source_ids'] ?? array() );

		$query = self::translation_page_query(
			array(
				'post_status'    => array( 'publish' ),
				'posts_per_page' => 1000,
			)
		);

		$checked = 0;
		$changed = array();
		$skipped = array();

		foreach ( $query->posts as $post ) {
			$translation_id = (int) $post->ID;
			$language       = (string) get_post_meta( $translation_id, self::META_LANGUAGE, true );
			$source_id      = absint( get_post_meta( $translation_id, self::META_SOURCE_ID, true ) );

			if ( '' === $language || ! $source_id ) {
				continue;
			}
			if ( ! in_array( $language, $languages, true ) ) {
				continue;
			}
			if ( $source_ids && ! in_array( $source_id, $source_ids, true ) ) {
				continue;
			}

			++$checked;
			$updated = self::localize_internal_links_in_content( $post->post_content, $language );
			if ( $updated === $post->post_content ) {
				continue;
			}

			if ( ! $dry_run ) {
				$result = null;
				self::with_reviewer_style_capture_suspended(
					static function () use ( &$result, $translation_id, $updated ): void {
						self::with_direct_save_storage_guardrails_suspended(
							static function () use ( &$result, $translation_id, $updated ): void {
								$result = wp_update_post(
									wp_slash(
										array(
											'ID'           => $translation_id,
											'post_content' => $updated,
										)
									),
									true
								);
							}
						);
					}
				);
				if ( is_wp_error( $result ) ) {
					$skipped[] = array(
						'translation_id' => $translation_id,
						'source_id'      => $source_id,
						'language'       => $language,
						'reason'         => $result->get_error_message(),
					);
					continue;
				}
				clean_post_cache( $translation_id );
			}

			$changed[] = array(
				'translation_id' => $translation_id,
				'source_id'      => $source_id,
				'language'       => $language,
				'url'            => get_permalink( $translation_id ) ?: '',
			);
		}

		if ( ! $dry_run && $changed ) {
			self::flush_sitemap_cache();
		}

		return array(
			'success'       => true,
			'dry_run'       => $dry_run,
			'checked_count' => $checked,
			'changed_count' => count( $changed ),
			'skipped_count' => count( $skipped ),
			'changed'       => $changed,
			'skipped'       => $skipped,
		);
	}

	/**
	 * Repair featured-image drift for existing translated content.
	 */
	private static function repair_featured_images( array $input ): array {
		$dry_run    = ! empty( $input['dry_run'] );
		$languages  = self::repair_language_filter( $input['languages'] ?? array() );
		$source_ids = self::repair_source_filter( $input['source_ids'] ?? array() );

		$query = self::translation_content_query(
			array(
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1000,
			)
		);

		$checked = 0;
		$changed = array();
		$skipped = array();

		foreach ( $query->posts as $post ) {
			$translation_id = (int) $post->ID;
			$language       = (string) get_post_meta( $translation_id, self::META_LANGUAGE, true );
			$source_id      = absint( get_post_meta( $translation_id, self::META_SOURCE_ID, true ) );

			if ( '' === $language || ! $source_id ) {
				continue;
			}
			if ( ! in_array( $language, $languages, true ) ) {
				continue;
			}
			if ( $source_ids && ! in_array( $source_id, $source_ids, true ) ) {
				continue;
			}

			$source = get_post( $source_id );
			if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) ) {
				$skipped[] = array(
					'translation_id' => $translation_id,
					'source_id'      => $source_id,
					'language'       => $language,
					'reason'         => 'missing_source',
				);
				continue;
			}

			++$checked;
			$sync = self::sync_source_featured_image( $translation_id, $source, $dry_run );
			if ( empty( $sync['changed'] ) ) {
				continue;
			}

			$changed[] = array(
				'translation_id'     => $translation_id,
				'source_id'          => $source_id,
				'language'           => $language,
				'before_thumbnail_id'=> $sync['before_thumbnail_id'],
				'after_thumbnail_id' => $sync['after_thumbnail_id'],
				'url'                => get_permalink( $translation_id ) ?: '',
			);
		}

		if ( ! $dry_run && $changed ) {
			self::flush_sitemap_cache();
		}

		return array(
			'success'       => true,
			'dry_run'       => $dry_run,
			'checked_count' => $checked,
			'changed_count' => count( $changed ),
			'skipped_count' => count( $skipped ),
			'changed'       => $changed,
			'skipped'       => $skipped,
		);
	}

	/**
	 * Build one queue row for a source page.
	 */
	private static function queue_item_for_source( WP_Post $source, array $status_filter ): array {
		$translations = array();
		foreach ( self::translation_rows_for_source( (int) $source->ID ) as $row ) {
			if ( ! empty( $row['language'] ) ) {
				$translations[ $row['language'] ] = $row;
			}
		}

		$language_rows = array();
		$action_count  = 0;
		foreach ( self::target_languages() as $language => $config ) {
			$translation = $translations[ $language ] ?? array();
			$state       = self::queue_state_for_translation( $translation );
			$action      = self::queue_action_for_state( $state );

			if ( ! empty( $status_filter ) && ! in_array( $state, $status_filter, true ) ) {
				continue;
			}

			if ( 'complete' !== $state ) {
				++$action_count;
			}

			$language_rows[] = array(
				'language'    => $language,
				'name'        => $config['name'] ?? strtoupper( $language ),
				'flag'        => $config['flag'] ?? strtoupper( $language ),
				'state'       => $state,
				'action'      => $action,
				'translation' => $translation,
			);
		}

		return array(
			'source'      => self::source_summary_payload( $source ),
			'source_hash' => self::source_hash( $source ),
			'languages'   => $language_rows,
			'action_count'=> $action_count,
		);
	}

	/**
	 * Classify one language row for the translation queue.
	 */
	private static function queue_state_for_translation( array $translation ): string {
		if ( empty( $translation ) ) {
			return 'missing';
		}

		$translation_status = (string) ( $translation['translation_status'] ?? '' );
		$post_status        = (string) ( $translation['status'] ?? '' );

		if ( ! empty( $translation['is_stale'] ) || 'stale' === $translation_status ) {
			return 'stale';
		}
		if ( 'publish' !== $post_status || 'draft' === $translation_status ) {
			return 'draft';
		}
		if ( '' === $translation_status || 'needs_review' === $translation_status ) {
			return 'needs_review';
		}
		$review_state = isset( $translation['linguistic_review_state'] ) && is_array( $translation['linguistic_review_state'] )
			? $translation['linguistic_review_state']
			: array();
		if ( empty( $review_state['passed'] ) ) {
			return 'needs_linguistic_review';
		}
		if ( 'published' !== $translation_status ) {
			return 'ready_to_publish';
		}

		return 'complete';
	}

	/**
	 * Suggested next action for a queue state.
	 */
	private static function queue_action_for_state( string $state ): string {
		$actions = array(
			'missing'                 => 'create_translation',
			'stale'                   => 'refresh_translation_from_source',
			'draft'                   => 'finish_translation',
			'needs_review'            => 'run_qa_and_review',
			'needs_linguistic_review' => 'mark_linguistic_reviewed_after_review',
			'ready_to_publish'        => 'publish_translation',
			'complete'                => 'none',
		);

		return $actions[ $state ] ?? 'review';
	}

	/**
	 * Target translation languages only.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function target_languages(): array {
		return array_filter(
			self::languages(),
			static function ( array $config ): bool {
				return empty( $config['source'] );
			}
		);
	}

	/**
	 * Required checks for linguistic review.
	 *
	 * @return array<int,string>
	 */
	private static function required_linguistic_review_checks( string $language = '' ): array {
		$checks = array(
			'natural_language_reviewed',
			'direct_translation_reviewed',
			'conversion_copy_reviewed',
			'source_fidelity_reviewed',
			'locale_terminology_reviewed',
		);

		if ( self::agency_copy_review_enabled( $language ) ) {
			$checks[] = 'non_specialist_clarity_reviewed';
			$checks[] = 'jargon_explained_or_removed';
			$checks[] = 'local_market_copy_reviewed';
		}

		return $checks;
	}

	/**
	 * Required checks for whole-page quality review.
	 *
	 * @return array<int,string>
	 */
	private static function required_quality_review_checks( string $language = '' ): array {
		$checks = array(
			'full_page_reviewed',
			'native_language_reviewed',
			'customer_visible_copy_reviewed',
			'factual_accuracy_reviewed',
			'links_and_actions_reviewed',
		);

		if ( self::agency_copy_review_enabled( $language ) ) {
			$checks[] = 'agency_copy_reviewed';
			$checks[] = 'reader_action_clear';
		}

		return $checks;
	}

	/**
	 * Extract boolean review flags from ability input.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @param array<int,string>   $required Required check keys.
	 * @return array<string,bool>
	 */
	private static function review_checks_from_input( array $input, array $required ): array {
		$checks = array();
		foreach ( $required as $key ) {
			$checks[ $key ] = ! empty( $input[ $key ] );
		}

		return $checks;
	}

	/**
	 * Return required review checks that have not passed.
	 *
	 * @param array<string,bool> $checks Review checks.
	 * @param array<int,string>  $required Required check keys.
	 * @return array<int,string>
	 */
	private static function missing_review_checks( array $checks, array $required ): array {
		return array_keys(
			array_filter(
				array_fill_keys( $required, true ),
				static function ( bool $unused, string $key ) use ( $checks ): bool {
					return empty( $checks[ $key ] );
				},
				ARRAY_FILTER_USE_BOTH
			)
		);
	}

	/**
	 * Stable hash for reviewable translated content.
	 */
	private static function translation_review_content_hash( WP_Post $post ): string {
		return hash( 'sha256', $post->post_title . "\n" . $post->post_excerpt . "\n" . $post->post_content );
	}

	/**
	 * Store compact evidence for the content that was reviewed.
	 */
	private static function translation_review_evidence( int $translation_id, ?array $fitness = null ): array {
		$post = get_post( $translation_id );
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			return array();
		}

		$source_id = absint( get_post_meta( $translation_id, self::META_SOURCE_ID, true ) );
		$source    = $source_id ? get_post( $source_id ) : null;
		$language  = sanitize_key( (string) get_post_meta( $translation_id, self::META_LANGUAGE, true ) );
		if ( null === $fitness ) {
			$fitness = self::translation_fitness(
				(string) $post->post_content,
				$source ? (string) $source->post_content : '',
				$language,
				(string) $post->post_title,
				(string) $post->post_excerpt,
				$source_id
			);
		}
		$internal_linking = self::internal_link_opportunities_for_post( $post, $language, 3 );

		return array(
			'schema_version'        => 1,
			'plugin_version'        => self::VERSION,
			'recorded_at'           => gmdate( 'c' ),
			'source_id'             => $source_id,
			'language'              => $language,
			'translation_hash'      => self::translation_review_content_hash( $post ),
			'source_hash'           => $source ? self::source_hash( $source ) : '',
			'fitness_passed'        => ! empty( $fitness['passed'] ),
			'fitness_issue_count'   => isset( $fitness['issue_count'] ) ? absint( $fitness['issue_count'] ) : count( $fitness['issues'] ?? array() ),
			'fitness_warning_count' => isset( $fitness['warning_count'] ) ? absint( $fitness['warning_count'] ) : count( $fitness['warnings'] ?? array() ),
			'fitness_issue_codes'   => self::qa_item_codes( $fitness['issues'] ?? array() ),
			'fitness_warning_codes' => self::qa_item_codes( $fitness['warnings'] ?? array() ),
			'internal_linking'      => array(
				'existing_internal_link_count' => absint( $internal_linking['existing_internal_link_count'] ?? 0 ),
				'suggested_count'              => count( $internal_linking['opportunities'] ?? array() ),
				'review_suggested'             => ! empty( $internal_linking['should_review'] ),
			),
		);
	}

	/**
	 * Load compact linguistic review evidence from post meta.
	 *
	 * @return array<string,mixed>
	 */
	private static function linguistic_review_evidence_for_post( int $post_id ): array {
		return self::review_evidence_for_post( $post_id, self::META_LINGUISTIC_REVIEW_EVIDENCE );
	}

	/**
	 * Load compact quality review evidence from post meta.
	 *
	 * @return array<string,mixed>
	 */
	private static function quality_review_evidence_for_post( int $post_id ): array {
		return self::review_evidence_for_post( $post_id, self::META_QUALITY_REVIEW_EVIDENCE );
	}

	/**
	 * Load compact review evidence from post meta.
	 *
	 * @return array<string,mixed>
	 */
	private static function review_evidence_for_post( int $post_id, string $meta_key ): array {
		$raw = get_post_meta( $post_id, $meta_key, true );
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$evidence = array();
		foreach ( array( 'schema_version', 'source_id', 'fitness_issue_count', 'fitness_warning_count' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$evidence[ $key ] = absint( $raw[ $key ] );
			}
		}
		foreach ( array( 'plugin_version', 'recorded_at', 'language', 'translation_hash', 'source_hash' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$evidence[ $key ] = sanitize_text_field( (string) $raw[ $key ] );
			}
		}
		if ( array_key_exists( 'fitness_passed', $raw ) ) {
			$evidence['fitness_passed'] = (bool) $raw['fitness_passed'];
		}
		foreach ( array( 'fitness_issue_codes', 'fitness_warning_codes' ) as $key ) {
			$evidence[ $key ] = self::sanitize_qa_code_list( $raw[ $key ] ?? array() );
		}
		if ( isset( $raw['internal_linking'] ) && is_array( $raw['internal_linking'] ) ) {
			$evidence['internal_linking'] = array(
				'existing_internal_link_count' => absint( $raw['internal_linking']['existing_internal_link_count'] ?? 0 ),
				'suggested_count'              => absint( $raw['internal_linking']['suggested_count'] ?? 0 ),
				'review_suggested'             => ! empty( $raw['internal_linking']['review_suggested'] ),
			);
		}

		return $evidence;
	}

	/**
	 * Current review readiness for one translation.
	 *
	 * @return array<string,mixed>
	 */
	private static function linguistic_review_state_for_post( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			return array(
				'passed'        => false,
				'state'         => 'missing_translation',
				'stale_reasons' => array( 'missing_translation' ),
			);
		}

		$language = sanitize_key( (string) get_post_meta( $post_id, self::META_LANGUAGE, true ) );
		$required = self::required_linguistic_review_checks( $language );
		$checks   = self::linguistic_review_checks_for_post( $post_id );
		$missing  = self::missing_review_checks( $checks, $required );
		$evidence = self::linguistic_review_evidence_for_post( $post_id );
		$reviewed_at = (string) get_post_meta( $post_id, self::META_LINGUISTIC_REVIEWED_AT, true );
		$source_id   = absint( get_post_meta( $post_id, self::META_SOURCE_ID, true ) );
		$source      = $source_id ? get_post( $source_id ) : null;
		$current_translation_hash = self::translation_review_content_hash( $post );
		$current_source_hash      = $source ? self::source_hash( $source ) : '';
		$stale_reasons = array();

		if ( '' === $reviewed_at ) {
			$stale_reasons[] = 'missing_linguistic_review';
		}
		if ( $missing ) {
			$stale_reasons[] = 'missing_required_checks';
		}
		if ( empty( $evidence ) ) {
			$stale_reasons[] = 'missing_review_evidence';
		} else {
			if ( ! empty( $evidence['translation_hash'] ) && $evidence['translation_hash'] !== $current_translation_hash ) {
				$stale_reasons[] = 'translation_changed_since_review';
			}
			if ( ! empty( $evidence['source_hash'] ) && '' !== $current_source_hash && $evidence['source_hash'] !== $current_source_hash ) {
				$stale_reasons[] = 'source_changed_since_review';
			}
			if ( array_key_exists( 'fitness_passed', $evidence ) && empty( $evidence['fitness_passed'] ) ) {
				$stale_reasons[] = 'fitness_failed_at_review';
			}
		}

		$passed = empty( $stale_reasons );

		return array(
			'passed'                   => $passed,
			'state'                    => $passed ? 'reviewed_current' : 'needs_linguistic_review',
			'required_checks'          => $required,
			'missing_checks'           => $missing,
			'stale_reasons'            => array_values( array_unique( $stale_reasons ) ),
			'checks'                   => $checks,
			'evidence'                 => $evidence,
			'current_translation_hash' => $current_translation_hash,
			'current_source_hash'      => $current_source_hash,
			'reviewed_at'              => $reviewed_at,
		);
	}

	/**
	 * Stored linguistic review checks for a translated post.
	 *
	 * @return array<string,bool>
	 */
	private static function linguistic_review_checks_for_post( int $post_id ): array {
		$raw     = (string) get_post_meta( $post_id, self::META_LINGUISTIC_REVIEW_CHECKS, true );
		$decoded = '' !== $raw ? json_decode( $raw, true ) : array();
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$checks = array();
		foreach ( $decoded as $key => $value ) {
			$checks[ sanitize_key( (string) $key ) ] = (bool) $value;
		}

		return $checks;
	}

	/**
	 * Remove review markers when translated content is changed after review.
	 */
	private static function invalidate_translation_reviews_if_content_changed( int $translation_id, string $reason, string $previous_hash = '' ): bool {
		$post = get_post( $translation_id );
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) || ! self::is_translation_post( $translation_id ) ) {
			return false;
		}

		$current_hash = self::translation_review_content_hash( $post );
		if ( '' !== $previous_hash && $previous_hash === $current_hash ) {
			return false;
		}

		$had_linguistic_review = '' !== (string) get_post_meta( $translation_id, self::META_LINGUISTIC_REVIEWED_AT, true );
		$had_quality_review    = '' !== (string) get_post_meta( $translation_id, self::META_QUALITY_REVIEWED_AT, true );
		$linguistic_evidence   = self::linguistic_review_evidence_for_post( $translation_id );
		$quality_evidence      = self::quality_review_evidence_for_post( $translation_id );

		if ( '' === $previous_hash && ! empty( $linguistic_evidence['translation_hash'] ) && $linguistic_evidence['translation_hash'] === $current_hash && ( empty( $quality_evidence['translation_hash'] ) || $quality_evidence['translation_hash'] === $current_hash ) ) {
			return false;
		}

		if ( ! $had_linguistic_review && ! $had_quality_review && empty( $linguistic_evidence ) && empty( $quality_evidence ) ) {
			return false;
		}

		self::delete_linguistic_review_meta( $translation_id );
		self::delete_quality_review_meta( $translation_id );
		update_post_meta( $translation_id, self::META_STATUS, 'needs_review' );
		delete_post_meta( $translation_id, self::META_REVIEWED_AT );
		update_post_meta( $translation_id, '_devenia_translation_review_invalidated_reason', sanitize_key( $reason ) );
		update_post_meta( $translation_id, '_devenia_translation_review_invalidated_at', gmdate( 'c' ) );
		self::sync_translation_index_row( $translation_id );

		return true;
	}

	/**
	 * Delete linguistic review markers.
	 */
	private static function delete_linguistic_review_meta( int $translation_id ): void {
		foreach ( array( self::META_LINGUISTIC_REVIEWED_AT, self::META_LINGUISTIC_REVIEWER, self::META_LINGUISTIC_REVIEW_NOTE, self::META_LINGUISTIC_REVIEW_CHECKS, self::META_LINGUISTIC_REVIEW_EVIDENCE ) as $meta_key ) {
			delete_post_meta( $translation_id, $meta_key );
		}
	}

	/**
	 * Delete whole-page quality review markers.
	 */
	private static function delete_quality_review_meta( int $post_id ): void {
		foreach ( array( self::META_QUALITY_REVIEWED_AT, self::META_QUALITY_REVIEWER, self::META_QUALITY_REVIEW_NOTE, self::META_QUALITY_REVIEW_CHECKS, self::META_QUALITY_REVIEW_EVIDENCE ) as $meta_key ) {
			delete_post_meta( $post_id, $meta_key );
		}
	}

	/**
	 * Stored quality review checks for a page.
	 *
	 * @return array<string,bool>
	 */
	private static function quality_review_checks_for_post( int $post_id ): array {
		$raw     = (string) get_post_meta( $post_id, self::META_QUALITY_REVIEW_CHECKS, true );
		$decoded = '' !== $raw ? json_decode( $raw, true ) : array();
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$checks = array();
		foreach ( $decoded as $key => $value ) {
			$checks[ sanitize_key( (string) $key ) ] = (bool) $value;
		}

		return $checks;
	}

	/**
	 * Compact queue item for quality review.
	 */
	private static function quality_review_queue_item( WP_Post $post ): array {
		$post_id       = (int) $post->ID;
		$language_context = self::review_language_context_for_post( $post );
		$is_translation = ! empty( $language_context['is_translation'] );
		$source_id     = absint( $language_context['source_id'] ?? 0 );
		$source        = $source_id ? get_post( $source_id ) : null;
		$language      = sanitize_key( (string) ( $language_context['target_language'] ?? '' ) );
		$reviewed_at   = (string) get_post_meta( $post_id, self::META_QUALITY_REVIEWED_AT, true );
		$modified_gmt  = get_post_modified_time( 'Y-m-d H:i:s', true, $post );
		$state         = self::quality_review_state_for_post( $post, $reviewed_at, $language );
		$required_checks = self::required_quality_review_checks( $language );
		$quality_checks  = self::quality_review_checks_for_post( $post_id );
		$missing_checks  = self::missing_review_checks( $quality_checks, $required_checks );
		$copy_feedback   = self::copy_feedback_for_post( $post_id );
		$open_feedback   = self::open_copy_feedback_for_post( $post_id );

		return array(
			'page' => array(
				'id'           => $post_id,
				'title'        => get_the_title( $post ),
				'slug'         => $post->post_name,
				'status'       => $post->post_status,
				'url'          => get_permalink( $post ),
				'modified_gmt' => $modified_gmt,
			),
			'source' => $source ? self::source_summary_payload( $source ) : null,
			'language' => $language,
			'language_context' => $language_context,
			'is_source' => ! $is_translation,
			'is_translation' => $is_translation,
			'state' => $state,
			'action' => self::quality_review_action_for_state( $state ),
			'quality_reviewed_at' => $reviewed_at,
			'quality_reviewer' => (string) get_post_meta( $post_id, self::META_QUALITY_REVIEWER, true ),
			'quality_review_note' => (string) get_post_meta( $post_id, self::META_QUALITY_REVIEW_NOTE, true ),
			'quality_required_checks' => $required_checks,
			'quality_missing_checks' => $missing_checks,
			'quality_review_checks' => $quality_checks,
			'quality_review_evidence' => self::quality_review_evidence_for_post( $post_id ),
			'quality_verdict' => self::quality_verdict_present_for_audience( self::quality_verdict_for_post( $post, false ), 'ai_operator' ),
			'copy_feedback_open_count' => count( $open_feedback ),
			'copy_feedback' => $copy_feedback,
		);
	}

	/**
	 * Classify quality review state from WordPress modified timestamp.
	 */
	private static function quality_review_state_for_post( WP_Post $post, string $reviewed_at, string $language = '' ): string {
		if ( 'publish' !== $post->post_status ) {
			return 'not_published';
		}
		if ( '' === $reviewed_at ) {
			return 'needs_quality_review';
		}

		$post_id  = (int) $post->ID;
		$required = self::required_quality_review_checks( $language );
		$checks   = self::quality_review_checks_for_post( $post_id );
		if ( self::missing_review_checks( $checks, $required ) ) {
			return 'quality_review_stale';
		}

		if ( self::open_copy_feedback_for_post( $post_id ) ) {
			return 'quality_review_stale';
		}

		$evidence = self::quality_review_evidence_for_post( $post_id );
		if ( empty( $evidence ) ) {
			return 'quality_review_stale';
		}
		if ( array_key_exists( 'fitness_passed', $evidence ) && empty( $evidence['fitness_passed'] ) ) {
			return 'quality_review_stale';
		}
		$current_translation_hash = self::translation_review_content_hash( $post );
		if ( ! empty( $evidence['translation_hash'] ) && $evidence['translation_hash'] !== $current_translation_hash ) {
			return 'quality_review_stale';
		}
		$source_id = self::is_translation_post( $post_id ) ? absint( get_post_meta( $post_id, self::META_SOURCE_ID, true ) ) : 0;
		$source    = $source_id ? get_post( $source_id ) : null;
		$current_source_hash = $source ? self::source_hash( $source ) : '';
		if ( ! empty( $evidence['source_hash'] ) && '' !== $current_source_hash && $evidence['source_hash'] !== $current_source_hash ) {
			return 'quality_review_stale';
		}

		$reviewed_ts = strtotime( $reviewed_at );
		$modified_ts = (int) get_post_modified_time( 'U', true, $post );
		if ( ! $reviewed_ts || ( $modified_ts && $modified_ts > $reviewed_ts ) ) {
			return 'quality_review_stale';
		}

		return 'reviewed';
	}

	/**
	 * Suggested action for a quality review state.
	 */
	private static function quality_review_action_for_state( string $state ): string {
		$actions = array(
			'needs_quality_review' => 'review_full_visible_page',
			'quality_review_stale' => 'review_changes_since_last_quality_review',
			'reviewed'             => 'none',
			'not_published'        => 'publish_or_skip',
		);

		return $actions[ $state ] ?? 'review';
	}

	/**
	 * Sort quality review queue items deterministically.
	 *
	 * @param array<int,array<string,mixed>> $items Queue items.
	 */
	private static function sort_quality_review_items( array &$items, string $order ): void {
		usort(
			$items,
			static function ( array $a, array $b ) use ( $order ): int {
				if ( 'title_asc' === $order ) {
					return strcasecmp( (string) ( $a['page']['title'] ?? '' ), (string) ( $b['page']['title'] ?? '' ) );
				}

				$a_modified = strtotime( (string) ( $a['page']['modified_gmt'] ?? '' ) ) ?: 0;
				$b_modified = strtotime( (string) ( $b['page']['modified_gmt'] ?? '' ) ) ?: 0;
				if ( $a_modified === $b_modified ) {
					return (int) ( $a['page']['id'] ?? 0 ) <=> (int) ( $b['page']['id'] ?? 0 );
				}

				return 'modified_desc' === $order ? $b_modified <=> $a_modified : $a_modified <=> $b_modified;
			}
		);
	}

	/**
	 * Source language code from registry.
	 */
	private static function source_language_code(): string {
		foreach ( self::languages() as $code => $config ) {
			if ( ! empty( $config['source'] ) ) {
				return sanitize_key( (string) $code );
			}
		}

		return 'en';
	}

	/**
	 * Sanitized language filter for quality review queue.
	 *
	 * @param mixed $languages Requested languages.
	 * @return array<int,string>
	 */
	private static function quality_review_language_filter( $languages, bool $include_source ): array {
		$allowed = array_keys( self::target_languages() );
		if ( $include_source ) {
			$allowed[] = self::source_language_code();
		}
		if ( ! is_array( $languages ) || empty( $languages ) ) {
			return array_values( array_unique( $allowed ) );
		}

		$clean = array();
		foreach ( $languages as $language ) {
			$language = sanitize_key( (string) $language );
			if ( in_array( $language, $allowed, true ) ) {
				$clean[] = $language;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Sanitized quality review status filter.
	 *
	 * @param mixed $statuses Requested statuses.
	 * @return array<int,string>
	 */
	private static function quality_review_status_filter( $statuses ): array {
		if ( ! is_array( $statuses ) ) {
			return array();
		}

		$allowed = array( 'needs_quality_review', 'quality_review_stale', 'reviewed', 'not_published' );
		$clean   = array();
		foreach ( $statuses as $status ) {
			$status = sanitize_key( (string) $status );
			if ( in_array( $status, $allowed, true ) ) {
				$clean[] = $status;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Sanitized target languages for repair routines.
	 *
	 * @param mixed $languages Requested languages.
	 * @return array<int,string>
	 */
	private static function repair_language_filter( $languages ): array {
		$targets = array_keys( self::target_languages() );
		if ( ! is_array( $languages ) || empty( $languages ) ) {
			return $targets;
		}

		$clean = array();
		foreach ( $languages as $language ) {
			$language = sanitize_key( (string) $language );
			if ( in_array( $language, $targets, true ) ) {
				$clean[] = $language;
			}
		}

		return $clean ? array_values( array_unique( $clean ) ) : $targets;
	}

	/**
	 * Sanitized source ID filter for repair routines.
	 *
	 * @param mixed $source_ids Requested source IDs.
	 * @return array<int,int>
	 */
	private static function repair_source_filter( $source_ids ): array {
		if ( ! is_array( $source_ids ) ) {
			return array();
		}

		$clean = array();
		foreach ( $source_ids as $source_id ) {
			$source_id = absint( $source_id );
			if ( $source_id ) {
				$clean[] = $source_id;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Determine a translated page's default parent.
	 */
	private static function default_translation_parent_id( WP_Post $source, string $language ): int {
		$front_page_id = absint( get_option( 'page_on_front' ) );
		if ( $front_page_id && (int) $source->ID === $front_page_id ) {
			return 0;
		}

		if ( $source->post_parent ) {
			$translated_parent = self::find_translation_id( (int) $source->post_parent, $language );
			if ( $translated_parent ) {
				return $translated_parent;
			}
		}

		return self::language_root_page_id( $language );
	}

	/**
	 * Find the translated homepage/root page for a language.
	 */
	private static function language_root_page_id( string $language ): int {
		$front_page_id = absint( get_option( 'page_on_front' ) );
		if ( ! $front_page_id ) {
			return 0;
		}

		return self::find_translation_id( $front_page_id, $language );
	}

	/**
	 * Enforce the expected parent for one translation page.
	 */
	private static function enforce_translation_parent( int $translation_id, WP_Post $source, string $language ): void {
		if ( '' === $language || ! self::is_translation_language( $language ) ) {
			return;
		}

		$post = get_post( $translation_id );
		if ( ! $post || 'page' !== $post->post_type ) {
			return;
		}

		$target_parent = self::default_translation_parent_id( $source, $language );
		if ( (int) $post->post_parent === $target_parent ) {
			return;
		}

		wp_update_post(
			wp_slash(
				array(
					'ID'          => $translation_id,
					'post_parent' => $target_parent,
				)
			)
		);
		clean_post_cache( $translation_id );
	}

	/**
	 * Rewrite one translated page's internal source links.
	 */
	private static function localize_internal_links_for_post( int $translation_id, string $language ): void {
		$post = get_post( $translation_id );
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			return;
		}

		$updated = self::localize_internal_links_in_content( $post->post_content, $language );
		if ( $updated === $post->post_content ) {
			return;
		}

		self::with_reviewer_style_capture_suspended(
			static function () use ( $translation_id, $updated ): void {
				self::with_direct_save_storage_guardrails_suspended(
					static function () use ( $translation_id, $updated ): void {
						wp_update_post(
							wp_slash(
								array(
									'ID'           => $translation_id,
									'post_content' => $updated,
								)
							)
						);
					}
				);
			}
		);
		clean_post_cache( $translation_id );
	}

	/**
	 * Rewrite href attributes that point to English source pages.
	 */
	private static function localize_internal_links_in_content( string $content, string $language ): string {
		if ( '' === $content || ! self::is_translation_language( $language ) ) {
			return $content;
		}

		$map = self::localized_internal_link_map( $language );
		if ( empty( $map ) ) {
			return $content;
		}

		return (string) preg_replace_callback(
			"/\\bhref=([\"'])([^\"']+)\\1/i",
			static function ( array $matches ) use ( $map ): string {
				$quote = $matches[1];
				$url   = html_entity_decode( (string) $matches[2], ENT_QUOTES );
				$new   = self::localized_internal_link_target( $url, $map );

				if ( null === $new ) {
					return $matches[0];
				}

				return 'href=' . $quote . esc_url( $new ) . $quote;
			},
			$content
		);
	}

	/**
	 * Return wrong-language internal link issues for an HTML fragment.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function localized_link_issues_for_html( string $html, string $language ): array {
		if ( '' === $html || '' === $language ) {
			return array();
		}

		$map = self::localized_link_expected_target_map( $language );
		if ( empty( $map ) || ! preg_match_all( '/\bhref=([\"\'])([^\"\']+)\1/i', $html, $matches ) ) {
			return array();
		}

		$issues = array();
		foreach ( $matches[2] as $raw_url ) {
			$url = html_entity_decode( (string) $raw_url, ENT_QUOTES );
			if ( '' === $url || '#' === $url[0] || preg_match( '/^(mailto|tel|sms|javascript):/i', $url ) ) {
				continue;
			}

			$expected = self::localized_internal_link_target( $url, $map );
			if ( null === $expected ) {
				continue;
			}

			if ( self::normalized_comparable_url( $url ) === self::normalized_comparable_url( $expected ) ) {
				continue;
			}

			$issues[] = array(
				'actual_url'   => $url,
				'expected_url' => $expected,
				'language'     => $language,
				'reason'       => 'wrong_localized_internal_link_target',
			);
		}

		return $issues;
	}

	/**
	 * Build a source URL to translated URL map for one language.
	 */
	private static function localized_internal_link_map( string $language, bool $force_refresh = false ): array {
		static $cache = array();

		if ( $force_refresh ) {
			unset( $cache[ $language ] );
		}

		if ( isset( $cache[ $language ] ) ) {
			return $cache[ $language ];
		}

		$frontend_rows = self::translation_frontend_rows_for_language( $language, array( 'publish', 'draft', 'pending', 'private' ) );
		if ( ! empty( $frontend_rows ) ) {
			$map = array();
			foreach ( $frontend_rows as $row ) {
				$source_url  = (string) ( $row['source_url'] ?? '' );
				$target_url  = (string) ( $row['target_url'] ?? '' );
				$source_path = (string) ( $row['source_path'] ?? '' );
				$target_path = (string) ( $row['target_path'] ?? '' );
				if ( '' === $source_path || '' === $target_path || '' === $target_url ) {
					continue;
				}

				self::add_link_map_variants( $map, (string) $source_url, (string) $target_url );
				self::add_link_map_variants( $map, (string) $source_path, (string) $target_url );
				foreach ( self::frontend_row_target_link_variants( $row ) as $variant_url ) {
					self::add_link_map_variants( $map, $variant_url, (string) $target_url );
				}
			}

			$cache[ $language ] = $map;
			return $cache[ $language ];
		}

		$query = self::translation_page_query(
			array(
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1000,
			)
		);

		$map = array();
		foreach ( $query->posts as $translation ) {
			if ( $language !== (string) get_post_meta( $translation->ID, self::META_LANGUAGE, true ) ) {
				continue;
			}
			$source_id = absint( get_post_meta( $translation->ID, self::META_SOURCE_ID, true ) );
			if ( ! $source_id ) {
				continue;
			}

			$source_url = get_permalink( $source_id );
			$target_url = get_permalink( $translation );
			if ( ! $source_url || ! $target_url ) {
				continue;
			}

			$source_path = self::normalized_url_path( $source_url );
			$target_path = self::normalized_url_path( $target_url );
			if ( '' === $source_path || '' === $target_path ) {
				continue;
			}

			self::add_link_map_variants( $map, (string) $source_url, (string) $target_url );
			self::add_link_map_variants( $map, (string) $source_path, (string) $target_url );
			self::add_link_map_variants( $map, (string) $target_url, (string) $target_url );
			self::add_link_map_variants( $map, (string) $target_path, (string) $target_url );
			$localized_path = trim( (string) get_post_meta( $translation->ID, self::META_LOCALIZED_PATH, true ), '/' );
			if ( '' !== $localized_path ) {
				self::add_link_map_variants( $map, $localized_path, (string) $target_url );
			}
		}

		$cache[ $language ] = $map;

		return $cache[ $language ];
	}

	/**
	 * Build a map from any known language variant URL to the expected URL for one language.
	 */
	private static function localized_link_expected_target_map( string $language, bool $force_refresh = false ): array {
		static $cache = array();

		if ( $force_refresh ) {
			unset( $cache[ $language ] );
		}

		if ( isset( $cache[ $language ] ) ) {
			return $cache[ $language ];
		}

		if ( self::translation_index_available() ) {
			global $wpdb;
			$indexed_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional custom registry table read for link verification.
				$wpdb->prepare(
					'SELECT source_post_id, translation_post_id, language, localized_path, source_path, target_path, target_url, translation_status, post_status, source_hash, reviewed_at, linguistic_reviewed_at, quality_reviewed_at FROM %i ORDER BY source_post_id ASC, language ASC',
					self::translation_index_table()
				),
				ARRAY_A
			);
			$indexed_rows = self::frontend_rows_from_index_rows(
				self::normalize_translation_index_rows( is_array( $indexed_rows ) ? $indexed_rows : array(), array( 'publish' ) )
			);
		} else {
			$indexed_rows = array();
		}

		if ( ! empty( $indexed_rows ) ) {
			$by_source = array();
			foreach ( $indexed_rows as $row ) {
				$source_id = absint( $row['source_id'] ?? 0 );
				$lang      = (string) ( $row['language'] ?? '' );
				$url       = (string) ( $row['target_url'] ?? '' );
				if ( ! $source_id || '' === $lang || ! $url ) {
					continue;
				}

				$by_source[ $source_id ][ $lang ] = array(
					'target_url' => (string) $url,
					'variants'   => self::frontend_row_target_link_variants( $row ),
				);
			}

			$map = array();
			foreach ( $by_source as $source_id => $translations ) {
				$source_url = get_permalink( (int) $source_id );
				$target_url = 'en' === $language ? $source_url : ( (string) ( $translations[ $language ]['target_url'] ?? '' ) );
				if ( ! $source_url || ! $target_url ) {
					continue;
				}

				$variants = array( (string) $source_url );
				foreach ( $translations as $translation ) {
					$variants = array_merge( $variants, (array) ( $translation['variants'] ?? array() ) );
				}
				foreach ( $variants as $variant_url ) {
					self::add_link_map_variants( $map, (string) $variant_url, (string) $target_url );
				}
			}

			$cache[ $language ] = $map;
			return $map;
		}

		$query = self::translation_page_query(
			array(
				'post_status'    => array( 'publish' ),
				'posts_per_page' => 1000,
			)
		);

		$by_source = array();
		foreach ( $query->posts as $translation ) {
			$source_id = absint( get_post_meta( $translation->ID, self::META_SOURCE_ID, true ) );
			$lang      = (string) get_post_meta( $translation->ID, self::META_LANGUAGE, true );
			$url       = get_permalink( $translation );
			if ( ! $source_id || '' === $lang || ! $url ) {
				continue;
			}

			$target_path     = self::normalized_url_path( (string) $url );
			$localized_path  = trim( (string) get_post_meta( $translation->ID, self::META_LOCALIZED_PATH, true ), '/' );
			$variants        = array_filter( array( (string) $url, $target_path, $localized_path ) );
			$by_source[ $source_id ][ $lang ] = array(
				'target_url' => (string) $url,
				'variants'   => array_values( array_unique( array_map( 'strval', $variants ) ) ),
			);
		}

		$map = array();
		foreach ( $by_source as $source_id => $translations ) {
			$source_url = get_permalink( (int) $source_id );
			$target_url = 'en' === $language ? $source_url : ( (string) ( $translations[ $language ]['target_url'] ?? '' ) );
			if ( ! $source_url || ! $target_url ) {
				continue;
			}

			$variants = array( (string) $source_url );
			foreach ( $translations as $translation ) {
				$variants = array_merge( $variants, (array) ( $translation['variants'] ?? array() ) );
			}
			foreach ( $variants as $variant_url ) {
				self::add_link_map_variants( $map, (string) $variant_url, (string) $target_url );
			}
		}

		$cache[ $language ] = $map;

		return $map;
	}

	/**
	 * Known target URL/path variants for a frontend registry row.
	 *
	 * @param array<string,mixed> $row Frontend registry row.
	 * @return array<int,string>
	 */
	private static function frontend_row_target_link_variants( array $row ): array {
		$variants = array(
			(string) ( $row['target_url'] ?? '' ),
			(string) ( $row['target_path'] ?? '' ),
			(string) ( $row['localized_path'] ?? '' ),
		);

		if ( ! empty( $row['localized_path_variants'] ) && is_array( $row['localized_path_variants'] ) ) {
			foreach ( $row['localized_path_variants'] as $variant ) {
				$variants[] = (string) $variant;
			}
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( string $variant ): string {
							return trim( $variant );
						},
						$variants
					),
					static function ( string $variant ): bool {
						return '' !== $variant;
					}
				)
			)
		);
	}

	/**
	 * Add absolute and path variants to a link map.
	 *
	 * @param array<string,string> $map Link map.
	 */
	private static function add_link_map_variants( array &$map, string $from_url, string $to_url ): void {
		$from_path = self::normalized_url_path( $from_url );
		$to_path   = self::normalized_url_path( $to_url );

		foreach ( array( $from_url, trailingslashit( $from_url ), untrailingslashit( $from_url ), $from_path, trailingslashit( $from_path ), untrailingslashit( $from_path ) ) as $from ) {
			if ( '' === $from ) {
				continue;
			}

			$map[ $from ] = $to_url;
			if ( '' !== $to_path ) {
				$map[ $from ] = $to_url;
				$map[ trailingslashit( $from ) ] = trailingslashit( $to_url );
				$map[ untrailingslashit( $from ) ] = untrailingslashit( $to_url );
			}
		}
	}

	/**
	 * Return localized link target if a URL points at a mapped English source page.
	 */
	private static function localized_internal_link_target( string $url, array $map ): ?string {
		if ( '' === $url || '#' === $url[0] || preg_match( '/^(mailto|tel|sms|javascript):/i', $url ) ) {
			return null;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return null;
		}

		$site_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( ! empty( $parts['host'] ) && strtolower( (string) $parts['host'] ) !== strtolower( $site_host ) ) {
			return null;
		}

		$path     = self::normalized_url_path( $url );
		$query    = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

		$candidates = array_filter(
			array_unique(
				array(
					$url,
					trailingslashit( $url ),
					untrailingslashit( $url ),
					$path,
					trailingslashit( $path ),
					untrailingslashit( $path ),
				)
			)
		);

		foreach ( $candidates as $candidate ) {
			if ( ! isset( $map[ $candidate ] ) ) {
				continue;
			}

			$target = $map[ $candidate ];
			if ( empty( $parts['host'] ) ) {
				$target = self::normalized_url_path( $target );
			}

			return $target . $query . $fragment;
		}

		return null;
	}

	/**
	 * Normalize URLs for equality checks while preserving query and fragment.
	 */
	private static function normalized_comparable_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		$path = self::normalized_url_path( $url );
		if ( '' === $path ) {
			return untrailingslashit( $url );
		}

		$parts    = wp_parse_url( $url );
		$query    = is_array( $parts ) && isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$fragment = is_array( $parts ) && isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

		return trailingslashit( $path ) . $query . $fragment;
	}

	/**
	 * Normalize URL/path to a root-relative path with a leading slash.
	 */
	private static function normalized_url_path( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		return '/' . trim( $path, '/' ) . '/';
	}

	/**
	 * Count source ancestors so parent translations are repaired before children.
	 */
	private static function source_depth( int $source_id ): int {
		$depth = 0;
		$post  = get_post( $source_id );
		while ( $post && $post->post_parent ) {
			++$depth;
			$post = get_post( (int) $post->post_parent );
		}

		return $depth;
	}

	/**
	 * Sanitize optional queue status filter.
	 */
	private static function queue_status_filter( $statuses ): array {
		if ( ! is_array( $statuses ) ) {
			return array();
		}

		$allowed = array( 'missing', 'stale', 'draft', 'needs_review', 'needs_linguistic_review', 'ready_to_publish', 'complete' );
		$clean   = array();
		foreach ( $statuses as $status ) {
			$status = sanitize_key( (string) $status );
			if ( in_array( $status, $allowed, true ) ) {
				$clean[] = $status;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Sync language menu from original menu.
	 */
	private static function sync_language_menu( array $input ): array {
		$language = sanitize_key( (string) ( $input['language'] ?? '' ) );
		if ( ! self::is_translation_language( $language ) ) {
			return self::error( 'Unknown or source language.' );
		}

		$languages        = self::languages();
		$source_menu_ref  = $input['source_menu'] ?? ( $languages['en']['menu_name'] ?? 'Main Menu' );
		$target_menu_name = ! empty( $input['target_menu_name'] ) ? sanitize_text_field( (string) $input['target_menu_name'] ) : ( $languages[ $language ]['menu_name'] ?? ( 'Main Menu ' . strtoupper( $language ) ) );
		$clear_existing   = array_key_exists( 'clear_existing', $input ) ? (bool) $input['clear_existing'] : true;
		$preserve_labels  = array_key_exists( 'preserve_existing_labels', $input ) ? (bool) $input['preserve_existing_labels'] : true;
		$include_missing  = ! empty( $input['include_untranslated'] );
		$include_custom   = array_key_exists( 'include_custom_links', $input ) ? (bool) $input['include_custom_links'] : true;

		$source_menu = wp_get_nav_menu_object( $source_menu_ref );
		if ( ! $source_menu ) {
			return self::error( 'Source menu not found.' );
		}

		$target_menu = wp_get_nav_menu_object( $target_menu_name );
		if ( ! $target_menu ) {
			$target_menu_id = wp_create_nav_menu( $target_menu_name );
			if ( is_wp_error( $target_menu_id ) ) {
				return self::error( $target_menu_id->get_error_message() );
			}
			$target_menu = wp_get_nav_menu_object( $target_menu_id );
		}

		if ( ! $target_menu ) {
			return self::error( 'Could not create target menu.' );
		}

		$existing_labels = $preserve_labels ? self::existing_menu_label_map( (int) $target_menu->term_id ) : array(
			'by_source_item' => array(),
			'by_signature'   => array(),
		);

		if ( $clear_existing ) {
			foreach ( wp_get_nav_menu_items( $target_menu->term_id ) ?: array() as $item ) {
				wp_delete_post( (int) $item->ID, true );
			}
		}

		$source_items = wp_get_nav_menu_items( $source_menu->term_id, array( 'orderby' => 'menu_order' ) );
		if ( ! $source_items ) {
			return self::error( 'Source menu has no items.' );
		}

		$id_map  = array();
		$added   = array();
		$skipped = array();

		foreach ( $source_items as $item ) {
			$new_parent = ! empty( $item->menu_item_parent ) && isset( $id_map[ (int) $item->menu_item_parent ] ) ? $id_map[ (int) $item->menu_item_parent ] : 0;
			$args       = null;

			if ( 'post_type' === $item->type && 'page' === $item->object ) {
				$translation_id = self::find_translation_id( (int) $item->object_id, $language, array( 'publish' ) );
				if ( $translation_id ) {
					$args = array(
						'menu-item-title'     => self::localized_menu_item_title( $item, $language, get_the_title( $translation_id ) ),
						'menu-item-object'    => 'page',
						'menu-item-object-id' => $translation_id,
						'menu-item-type'      => 'post_type',
						'menu-item-status'    => 'publish',
						'menu-item-parent-id' => $new_parent,
					);
					$args['menu-item-title'] = self::preserved_menu_item_title( $item, $language, $args, $existing_labels, $args['menu-item-title'] );
				} elseif ( $include_missing ) {
					$args = array(
						'menu-item-title'     => $item->title,
						'menu-item-object'    => 'page',
						'menu-item-object-id' => (int) $item->object_id,
						'menu-item-type'      => 'post_type',
						'menu-item-status'    => 'publish',
						'menu-item-parent-id' => $new_parent,
					);
					$args['menu-item-title'] = self::preserved_menu_item_title( $item, $language, $args, $existing_labels, $args['menu-item-title'] );
				} else {
					$skipped[] = array(
						'source_item_id' => (int) $item->ID,
						'source_page_id' => (int) $item->object_id,
						'title'          => $item->title,
						'reason'         => 'missing_published_translation',
					);
					continue;
				}
			} elseif ( 'custom' === $item->type && $include_custom ) {
				$localized_url = self::localized_internal_link_target( (string) $item->url, self::localized_internal_link_map( $language ) );
				$args = array(
					'menu-item-title'     => self::localized_menu_item_title( $item, $language, $item->title ),
					'menu-item-url'       => $localized_url ?: $item->url,
					'menu-item-type'      => 'custom',
					'menu-item-status'    => 'publish',
					'menu-item-parent-id' => $new_parent,
				);
				$args['menu-item-title'] = self::preserved_menu_item_title( $item, $language, $args, $existing_labels, $args['menu-item-title'] );
			} else {
				$skipped[] = array(
					'source_item_id' => (int) $item->ID,
					'title'          => $item->title,
					'type'           => $item->type,
					'reason'         => 'unsupported_or_disabled',
				);
				continue;
			}

			$new_id = wp_update_nav_menu_item( $target_menu->term_id, 0, $args );
			if ( is_wp_error( $new_id ) ) {
				$skipped[] = array(
					'source_item_id' => (int) $item->ID,
					'title'          => $item->title,
					'reason'         => $new_id->get_error_message(),
				);
				continue;
			}

			$id_map[ (int) $item->ID ] = (int) $new_id;
			update_post_meta( (int) $new_id, self::MENU_ITEM_META_SOURCE_ITEM_ID, (int) $item->ID );
			update_post_meta( (int) $new_id, self::MENU_ITEM_META_SOURCE_OBJECT_ID, isset( $item->object_id ) ? (int) $item->object_id : 0 );
			update_post_meta( (int) $new_id, self::MENU_ITEM_META_LANGUAGE, $language );
			update_post_meta( (int) $new_id, self::MENU_ITEM_META_MANAGED, '1' );
			$added[] = array(
				'source_item_id' => (int) $item->ID,
				'menu_item_id'   => (int) $new_id,
				'title'          => $args['menu-item-title'] ?? $item->title,
			);
		}

		return array(
			'success'         => true,
			'source_menu'     => array( 'id' => (int) $source_menu->term_id, 'name' => $source_menu->name ),
			'target_menu'     => array( 'id' => (int) $target_menu->term_id, 'name' => $target_menu->name ),
			'language'        => $language,
			'added'           => $added,
			'skipped'         => $skipped,
			'added_count'     => count( $added ),
			'skipped_count'   => count( $skipped ),
		);
	}

	/**
	 * Build lookup maps for labels already stored in a target WordPress menu.
	 *
	 * @return array{by_source_item:array<int,string>,by_signature:array<string,string>}
	 */
	private static function existing_menu_label_map( int $menu_id ): array {
		$map = array(
			'by_source_item' => array(),
			'by_signature'   => array(),
		);

		foreach ( wp_get_nav_menu_items( $menu_id ) ?: array() as $item ) {
			if ( ! is_object( $item ) || empty( $item->ID ) ) {
				continue;
			}

			$title = isset( $item->title ) ? sanitize_text_field( (string) $item->title ) : '';
			if ( '' === $title ) {
				continue;
			}

			$source_item_id = absint( get_post_meta( (int) $item->ID, self::MENU_ITEM_META_SOURCE_ITEM_ID, true ) );
			if ( $source_item_id > 0 ) {
				$map['by_source_item'][ $source_item_id ] = $title;
			}

			$signature = self::menu_item_signature_from_object( $item );
			if ( '' !== $signature ) {
				$map['by_signature'][ $signature ] = $title;
			}
		}

		return $map;
	}

	/**
	 * Preserve a matching existing WordPress menu label when rebuilding a menu.
	 *
	 * @param array{by_source_item?:array<int,string>,by_signature?:array<string,string>} $existing_labels Existing label maps.
	 * @param array<string,mixed>                                                        $args            Menu item args for wp_update_nav_menu_item().
	 */
	private static function preserved_menu_item_title( object $source_item, string $language, array $args, array $existing_labels, string $generated_title ): string {
		$generated_title = sanitize_text_field( $generated_title );
		$source_item_id  = isset( $source_item->ID ) ? (int) $source_item->ID : 0;

		if ( self::has_explicit_localized_menu_item_title( $source_item, $language ) ) {
			return $generated_title;
		}

		if ( $source_item_id > 0 && ! empty( $existing_labels['by_source_item'][ $source_item_id ] ) ) {
			return sanitize_text_field( (string) $existing_labels['by_source_item'][ $source_item_id ] );
		}

		$signature = self::menu_item_signature_from_args( $args );
		if ( '' !== $signature && ! empty( $existing_labels['by_signature'][ $signature ] ) ) {
			return sanitize_text_field( (string) $existing_labels['by_signature'][ $signature ] );
		}

		return $generated_title;
	}

	/**
	 * Detect menu labels that are intentionally configured for this source item.
	 */
	private static function has_explicit_localized_menu_item_title( object $item, string $language ): bool {
		$languages = self::languages();
		$config    = $languages[ $language ] ?? array();
		$labels    = isset( $config['menu_items'] ) && is_array( $config['menu_items'] ) ? $config['menu_items'] : array();
		$custom    = isset( $config['custom_menu_items'] ) && is_array( $config['custom_menu_items'] ) ? $config['custom_menu_items'] : array();
		$page_id   = isset( $item->object_id ) ? (string) (int) $item->object_id : '';
		$title     = isset( $item->title ) ? (string) $item->title : '';

		return ( '' !== $page_id && isset( $labels[ $page_id ] ) && '' !== (string) $labels[ $page_id ] )
			|| ( '' !== $title && isset( $custom[ $title ] ) && '' !== (string) $custom[ $title ] );
	}

	/**
	 * Return a stable target-menu item signature for matching old items before rebuild.
	 */
	private static function menu_item_signature_from_object( object $item ): string {
		$type = isset( $item->type ) ? (string) $item->type : '';
		if ( 'post_type' === $type && isset( $item->object, $item->object_id ) ) {
			return 'post_type:' . sanitize_key( (string) $item->object ) . ':' . (int) $item->object_id;
		}

		if ( 'custom' === $type && ! empty( $item->url ) ) {
			return self::menu_custom_url_signature( (string) $item->url );
		}

		return '';
	}

	/**
	 * Return a stable target-menu item signature from wp_update_nav_menu_item args.
	 *
	 * @param array<string,mixed> $args Menu item args.
	 */
	private static function menu_item_signature_from_args( array $args ): string {
		$type = isset( $args['menu-item-type'] ) ? (string) $args['menu-item-type'] : '';
		if ( 'post_type' === $type && isset( $args['menu-item-object'], $args['menu-item-object-id'] ) ) {
			return 'post_type:' . sanitize_key( (string) $args['menu-item-object'] ) . ':' . (int) $args['menu-item-object-id'];
		}

		if ( 'custom' === $type && ! empty( $args['menu-item-url'] ) ) {
			return self::menu_custom_url_signature( (string) $args['menu-item-url'] );
		}

		return '';
	}

	/**
	 * Normalize a custom menu URL for label-preservation matching.
	 */
	private static function menu_custom_url_signature( string $url ): string {
		$normalized = self::normalized_comparable_url( $url );
		if ( '' === $normalized ) {
			$normalized = untrailingslashit( trim( $url ) );
		}

		return '' === $normalized ? '' : 'custom:' . $normalized;
	}

	/**
	 * Return a short localized menu label from the packaged language file.
	 */
	private static function localized_menu_item_title( object $item, string $language, string $fallback ): string {
		$languages = self::languages();
		$config    = $languages[ $language ] ?? array();
		$labels    = isset( $config['menu_items'] ) && is_array( $config['menu_items'] ) ? $config['menu_items'] : array();
		$custom    = isset( $config['custom_menu_items'] ) && is_array( $config['custom_menu_items'] ) ? $config['custom_menu_items'] : array();
		$page_id   = isset( $item->object_id ) ? (string) (int) $item->object_id : '';
		$title     = isset( $item->title ) ? (string) $item->title : '';
		$fallback  = sanitize_text_field( $fallback );

		if ( '' !== $page_id && isset( $labels[ $page_id ] ) && '' !== (string) $labels[ $page_id ] ) {
			return sanitize_text_field( (string) $labels[ $page_id ] );
		}

		if ( '' !== $title && isset( $custom[ $title ] ) && '' !== (string) $custom[ $title ] ) {
			return sanitize_text_field( (string) $custom[ $title ] );
		}

		if ( '' !== $page_id && '' !== $fallback && $fallback !== $title ) {
			return $fallback;
		}

		return $fallback;
	}

	/**
	 * Create missing parent path pages for localized URL hierarchy.
	 */
	private static function ensure_parent_path( string $language, string $parent_path, string $parent_status ): array {
		$segments = array_values( array_filter( array_map( 'sanitize_title', explode( '/', trim( $parent_path, '/' ) ) ) ) );
		$prefix   = self::language_prefix( $language );
		if ( '' !== $prefix ) {
			array_unshift( $segments, $prefix );
		}

		if ( empty( $segments ) ) {
			return array( 'success' => true, 'parent_id' => 0, 'created' => array() );
		}

		$parent_id = 0;
		$path      = '';
		$created   = array();

		foreach ( $segments as $segment ) {
			if ( self::has_wordpress_duplicate_slug_suffix( $segment ) ) {
				return self::error( 'Localized parent path must not contain a WordPress duplicate suffix such as -2. Resolve the route collision instead.' );
			}
			$path = '' === $path ? $segment : $path . '/' . $segment;
			$page = get_page_by_path( $path, OBJECT, 'page' );
			if ( $page ) {
				$parent_id = (int) $page->ID;
				continue;
			}

			return array(
				'success'        => false,
				'message'        => 'Localized parent path does not exist. Create or publish the translated parent page first; the workflow will not create empty placeholder parent pages.',
				'code'           => 'localized_parent_path_missing',
				'requested_path' => $path,
				'requested_slug' => $segment,
				'parent_id'      => $parent_id,
			);
		}

		return array( 'success' => true, 'parent_id' => $parent_id, 'created' => $created );
	}

	/**
	 * Print hreflang/canonical links for source and translation pages.
	 */
	public static function print_language_links(): void {
		if ( ! self::is_frontend_language_surface() ) {
			return;
		}

		$surface = self::frontend_surface( self::frontend_surface_post_id() );
		$links   = $surface['links'];
		if ( empty( $links ) ) {
			return;
		}

		foreach ( $links as $lang => $link ) {
			if ( empty( $link['url'] ) ) {
				continue;
			}
			printf( '<link rel="alternate" hreflang="%s" href="%s" />' . "\n", esc_attr( self::hreflang_for_language( (string) $lang ) ), esc_url( $link['url'] ) );
		}
		if ( ! empty( $links['en'] ) ) {
			printf( '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n", esc_url( $links['en']['url'] ) );
		}
	}

	/**
	 * Append compact language links to the primary menu when alternatives exist.
	 *
	 * @param string $items Rendered menu items.
	 * @param object $args  Menu args.
	 */
	public static function append_language_menu_items( string $items, $args ): string {
		if ( ! self::is_frontend_language_surface() ) {
			return $items;
		}

		$theme_location = isset( $args->theme_location ) ? (string) $args->theme_location : '';
		if ( 'primary' !== $theme_location ) {
			return $items;
		}

		$menu_id = isset( $args->menu_id ) ? (string) $args->menu_id : '';
		if ( 'mobile-menu' === $menu_id ) {
			return $items;
		}

		$surface = self::frontend_surface( self::frontend_surface_post_id() );
		$links   = $surface['links'];
		if ( count( $links ) < 2 ) {
			return $items;
		}

		$output = self::render_language_dropdown_markup( $surface, 'devenia-language-menu-dropdown', true );
		if ( '' === $output ) {
			return $items;
		}

		return $items . $output;
	}

	/**
	 * Render the mobile header language selector in GeneratePress menu bar items.
	 */
	public static function render_mobile_language_menu_bar_item(): void {
		if ( ! self::is_frontend_language_surface() ) {
			return;
		}

		$surface = self::frontend_surface( self::frontend_surface_post_id() );
		$links   = $surface['links'];
		if ( count( $links ) < 2 ) {
			return;
		}

		$markup = self::render_language_dropdown_markup( $surface, 'devenia-mobile-language-selector', false );
		if ( '' === $markup ) {
			return;
		}

		printf(
			'<span class="menu-bar-item devenia-mobile-language-menu-bar-item">%s</span>',
			$markup // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped internal markup.
		);
	}

	/**
	 * Render language dropdown markup for menu-list and menu-bar surfaces.
	 *
	 * @param array<string,mixed> $surface Frontend language surface.
	 * @param string              $class   Dropdown class.
	 * @param bool                $as_li   Whether to render a menu list item.
	 */
	private static function render_language_dropdown_markup( array $surface, string $class, bool $as_li ): string {
		$links            = isset( $surface['links'] ) && is_array( $surface['links'] ) ? $surface['links'] : array();
		$current_language = (string) $surface['language'];
		$languages        = self::languages();
		$current_config   = $languages[ $current_language ] ?? array();
		$current_name     = isset( $current_config['name'] ) ? (string) $current_config['name'] : strtoupper( $current_language );
		$current_label    = self::language_menu_current_label( $current_language, $current_config );
		$submenu          = array();
		$links            = self::sort_language_menu_links( $links );

		foreach ( $links as $lang => $link ) {
			$language   = $languages[ $lang ] ?? array();
			$name       = isset( $language['name'] ) ? (string) $language['name'] : strtoupper( (string) $lang );
			$native     = self::language_menu_native_name( (string) $lang, $language, $name );
			$flag       = isset( $language['flag'] ) && '' !== $language['flag'] ? (string) $language['flag'] : strtoupper( (string) $lang );
			$hreflang   = self::hreflang_for_language( (string) $lang );
			$current    = $lang === $current_language;
			$group      = self::language_menu_group_for_language( (string) $lang );
			$dir        = self::language_direction_for_language( (string) $lang );
			$classes    = 'devenia-language-menu-item devenia-language-menu-item-' . sanitize_html_class( (string) $lang );
			$item_label = sprintf( '%s, %s', $native, $hreflang );
			if ( $current ) {
				$classes .= ' current-menu-item';
			}

			$submenu[ $group ][] = sprintf(
				'<a class="%1$s" role="menuitem" href="%2$s" hreflang="%3$s" lang="%3$s" title="%4$s" aria-label="%4$s"><span class="devenia-language-flag" aria-hidden="true">%5$s</span><span class="devenia-language-name"%6$s>%7$s</span><span class="devenia-language-locale">%3$s</span></a>',
				esc_attr( $classes ),
				esc_url( $link['url'] ),
				esc_attr( $hreflang ),
				esc_attr( $item_label ),
				esc_html( $flag ),
				'rtl' === $dir ? ' dir="rtl"' : '',
				esc_html( $native )
			);
		}

		if ( empty( $submenu ) || ! isset( $links[ $current_language ]['url'] ) ) {
			return '';
		}

		$classes = trim( $class . ( $as_li ? ' menu-item menu-item-type-custom menu-item-has-children' : '' ) );
		$label   = sprintf( 'Choose language. Current language: %s', $current_label );
		$icon    = self::language_menu_trigger_icon();

		if ( $as_li ) {
			return sprintf(
				'<li class="%1$s"><a class="devenia-language-trigger" href="%2$s" title="%3$s" aria-label="%3$s">%4$s<span class="screen-reader-text">%3$s</span></a><ul class="sub-menu devenia-language-submenu" aria-label="%3$s">%5$s</ul></li>',
				esc_attr( $classes ),
				esc_url( $links[ $current_language ]['url'] ?? '#' ),
				esc_attr( $label ),
				$icon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG icon.
				self::render_language_menu_groups( $submenu, true ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped internal markup.
			);
		}

		return sprintf(
			'<span class="%1$s"><button type="button" class="devenia-mobile-language-toggle" aria-haspopup="true" aria-expanded="false" aria-label="%2$s">%3$s<span class="screen-reader-text">%2$s</span></button><span class="devenia-mobile-language-submenu" role="menu" aria-label="%2$s">%4$s</span></span>',
			esc_attr( $classes ),
			esc_attr( $label ),
			$icon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG icon.
			self::render_language_menu_groups( $submenu, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped internal markup.
		);
	}

	/**
	 * Render grouped language menu rows.
	 *
	 * @param array<string,array<int,string>> $groups Escaped language rows grouped by region.
	 */
	private static function render_language_menu_groups( array $groups, bool $as_list ): string {
		$output = '';
		foreach ( array( 'Europe', 'Asia', 'Middle East', 'Other' ) as $group ) {
			if ( empty( $groups[ $group ] ) ) {
				continue;
			}

			$heading = sprintf( '<span class="devenia-language-group-heading">%s</span>', esc_html( $group ) );
			if ( $as_list ) {
				$output .= sprintf(
					'<li class="devenia-language-group devenia-language-group-%1$s">%2$s<div class="devenia-language-group-list">%3$s</div></li>',
					esc_attr( sanitize_title( $group ) ),
					$heading,
					implode( '', $groups[ $group ] )
				);
				continue;
			}

			$output .= sprintf(
				'<span class="devenia-language-group devenia-language-group-%1$s">%2$s<span class="devenia-language-group-list">%3$s</span></span>',
				esc_attr( sanitize_title( $group ) ),
				$heading,
				implode( '', $groups[ $group ] )
			);
		}

		return $output;
	}

	/**
	 * Compact universal language trigger icon.
	 */
	private static function language_menu_trigger_icon(): string {
		return '<span class="devenia-language-trigger-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false" role="img"><circle cx="12" cy="12" r="9"></circle><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"></path></svg></span>';
	}

	/**
	 * Visible current-language label for assistive technology.
	 *
	 * @param array<string,mixed> $config Language configuration.
	 */
	private static function language_menu_current_label( string $language, array $config ): string {
		$name = isset( $config['name'] ) && '' !== (string) $config['name'] ? (string) $config['name'] : strtoupper( $language );
		return self::language_menu_native_name( $language, $config, $name );
	}

	/**
	 * Native display name for language picker rows.
	 *
	 * @param array<string,mixed> $config Language configuration.
	 */
	private static function language_menu_native_name( string $language, array $config, string $fallback ): string {
		$names = array(
			'en' => 'English',
			'nb' => 'Norsk bokmål',
			'de' => 'Deutsch',
			'fr' => 'Français',
			'es' => 'Español',
			'sv' => 'Svenska',
			'da' => 'Dansk',
			'fi' => 'Suomi',
			'ar' => 'العربية',
			'it' => 'Italiano',
			'nl' => 'Nederlands',
		);

		$language = sanitize_key( $language );
		if ( isset( $names[ $language ] ) ) {
			return $names[ $language ];
		}

		if ( isset( $config['native_name'] ) && '' !== (string) $config['native_name'] ) {
			return (string) $config['native_name'];
		}

		return $fallback;
	}

	/**
	 * Group language choices for the compact language mega-menu.
	 */
	private static function language_menu_group_for_language( string $language ): string {
		$language = sanitize_key( $language );
		$config   = self::languages()[ $language ] ?? array();
		if ( isset( $config['menu_region'] ) && is_string( $config['menu_region'] ) ) {
			$region = trim( (string) $config['menu_region'] );
			if ( in_array( $region, array( 'Europe', 'Asia', 'Middle East', 'Other' ), true ) ) {
				return $region;
			}
		}

		if ( 'ar' === $language ) {
			return 'Middle East';
		}
		if ( in_array( $language, array( 'zh', 'ja', 'vi' ), true ) ) {
			return 'Asia';
		}
		if ( in_array( $language, array( 'en', 'nb', 'de', 'fr', 'es', 'sv', 'da', 'fi', 'it', 'nl', 'pt' ), true ) ) {
			return 'Europe';
		}
		return 'Other';
	}

	/**
	 * Text direction for language labels inside the language picker.
	 */
	private static function language_direction_for_language( string $language ): string {
		$languages = self::languages();
		$config    = $languages[ sanitize_key( $language ) ] ?? array();
		$direction = isset( $config['direction'] ) ? strtolower( (string) $config['direction'] ) : '';
		return 'rtl' === $direction ? 'rtl' : 'ltr';
	}

	/**
	 * Sort language alternates by the configured market priority while keeping the
	 * Scandinavian flags adjacent in the dropdown.
	 *
	 * @param array<string,array<string,string>> $links Alternate language links.
	 * @return array<string,array<string,string>>
	 */
	private static function sort_language_menu_links( array $links ): array {
		$order = array(
			'en' => 10,
			'de' => 20,
			'fr' => 30,
			'es' => 40,
			'ar' => 50,
			'sv' => 60,
			'nb' => 61,
			'da' => 62,
			'fi' => 70,
			'it' => 80,
			'nl' => 90,
		);

		uksort(
			$links,
			static function ( string $left, string $right ) use ( $order ): int {
				$left_order  = $order[ $left ] ?? 999;
				$right_order = $order[ $right ] ?? 999;

				if ( $left_order === $right_order ) {
					return strcmp( $left, $right );
				}

				return $left_order <=> $right_order;
			}
		);

		return $links;
	}

	/**
	 * Use the matching translated primary menu on translated pages.
	 *
	 * @param array<string,mixed> $args Nav menu args.
	 * @return array<string,mixed>
	 */
	public static function use_language_primary_menu( array $args ): array {
		$theme_location = isset( $args['theme_location'] ) ? (string) $args['theme_location'] : '';
		if ( 'primary' !== $theme_location || ! self::is_frontend_runtime_request() ) {
			return $args;
		}

		$post_id  = get_queried_object_id();
		$surface  = self::frontend_surface( (int) $post_id );
		$language = (string) $surface['language'];
		if ( 'en' === $language ) {
			return $args;
		}

		$languages = self::languages();
		$menu_name = isset( $languages[ $language ]['menu_name'] ) ? (string) $languages[ $language ]['menu_name'] : '';
		if ( '' === $menu_name ) {
			return $args;
		}

		$menu = wp_get_nav_menu_object( $menu_name );
		if ( ! $menu ) {
			return $args;
		}

		$args['menu'] = $menu->term_id;

		return $args;
	}

	/**
	 * Localize page-based navigation menus outside translated menu locations.
	 *
	 * @param array<int,object> $items Rendered menu item objects.
	 * @param object            $args  Nav menu args.
	 * @return array<int,object>
	 */
	public static function localize_nav_menu_objects( array $items, $args ): array {
		if ( ! self::is_frontend_runtime_request() ) {
			return $items;
		}

		$language = self::frontend_language();
		if ( 'en' === $language ) {
			return $items;
		}
		if ( self::is_language_menu_already_selected( $args, $language ) ) {
			return $items;
		}

		foreach ( $items as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}

			if ( isset( $item->type, $item->object ) && 'post_type' === $item->type && 'page' === $item->object && ! empty( $item->object_id ) ) {
				$source_id      = self::source_id_for_context( (int) $item->object_id );
				$translation_id = self::find_translation_id( $source_id, $language, array( 'publish' ) );
				if ( ! $translation_id ) {
					$localized_url = self::localized_internal_link_target( (string) $item->url, self::localized_internal_link_map( $language ) );
					if ( $localized_url ) {
						$item->url = $localized_url;
					}

					$source_item            = clone $item;
					$source_item->object_id = (string) $source_id;
					$item->title            = self::localized_menu_item_title( $source_item, $language, isset( $item->title ) ? (string) $item->title : '' );
					continue;
				}

				$source_item            = clone $item;
				$source_item->object_id = (string) $source_id;
				$item->object_id = (string) $translation_id;
				$item->url       = get_permalink( $translation_id );
				$item->title     = self::localized_menu_item_title( $source_item, $language, get_the_title( $translation_id ) );
			} elseif ( isset( $item->type ) && 'custom' === $item->type && ! empty( $item->url ) ) {
				$localized_url = self::localized_internal_link_target( (string) $item->url, self::localized_internal_link_map( $language ) );
				if ( $localized_url ) {
					$item->url = $localized_url;
				}
				$item->title = self::localized_menu_item_title( $item, $language, isset( $item->title ) ? (string) $item->title : '' );
			}
		}

		return $items;
	}

	/**
	 * Detect when WordPress is already rendering the configured language menu.
	 */
	private static function is_language_menu_already_selected( $args, string $language ): bool {
		if ( ! is_object( $args ) ) {
			return false;
		}

		$languages = self::languages();
		$menu_name = isset( $languages[ $language ]['menu_name'] ) ? (string) $languages[ $language ]['menu_name'] : '';
		if ( '' === $menu_name || empty( $args->menu ) ) {
			return false;
		}

		$configured_menu = wp_get_nav_menu_object( $menu_name );
		$current_menu    = wp_get_nav_menu_object( $args->menu );
		if ( ! $configured_menu || ! $current_menu ) {
			return false;
		}

		return (int) $configured_menu->term_id === (int) $current_menu->term_id;
	}

	/**
	 * Localize shared block widgets for translated frontend contexts.
	 *
	 * @param string              $content  Rendered block-widget content.
	 * @param array<string,mixed> $instance Widget instance.
	 * @param WP_Widget|null      $widget   Widget object.
	 */
	public static function localize_widget_block_content( string $content, array $instance = array(), $widget = null ): string {
		if ( ! self::is_frontend_runtime_request() ) {
			return $content;
		}

		$language = self::frontend_language();
		$languages = self::languages();
		$replacements = isset( $languages[ $language ]['widget_text'] ) && is_array( $languages[ $language ]['widget_text'] )
			? $languages[ $language ]['widget_text']
			: array();
		if ( ! $replacements ) {
			return $content;
		}

		foreach ( $replacements as $source => $translated ) {
			if ( is_string( $source ) && is_string( $translated ) && '' !== $source ) {
				$content = str_replace( $source, $translated, $content );
			}
		}

		return self::localize_logo_home_links( $content, $language );
	}

	/**
	 * Point GeneratePress header/site-title logo links at the active language root.
	 */
	public static function filter_logo_home_href( string $href ): string {
		if ( ! self::is_frontend_language_surface() ) {
			return $href;
		}

		return self::localized_home_url_for_language( self::frontend_language() ) ?: $href;
	}

	/**
	 * Point footer logo links at the active language root.
	 */
	private static function localize_logo_home_links( string $content, string $language ): string {
		$home_url = self::localized_home_url_for_language( $language );
		if ( '' === $home_url || 'en' === $language ) {
			return $content;
		}

		if ( false === strpos( $content, 'devenia-footer-logo' ) && false === strpos( $content, 'wp-block-site-logo' ) ) {
			return $content;
		}

		$root_urls = array_filter(
			array_unique(
				array(
					home_url( '/' ),
					trailingslashit( home_url( '/' ) ),
					untrailingslashit( home_url( '/' ) ),
					'/',
				)
			)
		);

		return preg_replace_callback(
			'/<a\b([^>]*)\bhref=(["\'])([^"\']+)\2([^>]*)>(.*?)<\/a>/is',
			static function ( array $match ) use ( $root_urls, $home_url ): string {
				$href = html_entity_decode( (string) $match[3], ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );
				$body = (string) $match[5];
				if ( ! in_array( untrailingslashit( $href ), array_map( 'untrailingslashit', $root_urls ), true ) ) {
					return $match[0];
				}
				if ( false === strpos( $body, 'devenia-footer-logo' ) && false === strpos( $body, 'wp-block-site-logo' ) && false === stripos( $body, '<img' ) ) {
					return $match[0];
				}

				return '<a' . $match[1] . 'href=' . $match[2] . esc_url( $home_url ) . $match[2] . $match[4] . '>' . $body . '</a>';
			},
			$content
		) ?: $content;
	}

	/**
	 * Localize shared widget titles for translated frontend contexts.
	 *
	 * @param string $title    Widget title.
	 * @param mixed  $instance Widget instance.
	 * @param mixed  $id_base  Widget ID base.
	 */
	public static function localize_widget_title( string $title, $instance = null, $id_base = null ): string {
		if ( ! self::is_frontend_runtime_request() ) {
			return $title;
		}

		$language = self::frontend_language();
		$languages = self::languages();
		$replacements = isset( $languages[ $language ]['widget_text'] ) && is_array( $languages[ $language ]['widget_text'] )
			? $languages[ $language ]['widget_text']
			: array();

		return isset( $replacements[ $title ] ) && is_string( $replacements[ $title ] )
			? $replacements[ $title ]
			: $title;
	}

	/**
	 * Start language-specific visible text normalization for translated frontend pages.
	 */
	public static function maybe_start_frontend_text_localization(): void {
		if ( ! self::is_frontend_runtime_request() ) {
			return;
		}

		$language = self::frontend_language();
		$runtime  = self::runtime_text_replacements_for_language( $language );
		if ( 'en' === $language || empty( $runtime['has_replacements'] ) ) {
			return;
		}

		ob_start( array( __CLASS__, 'localize_frontend_visible_text' ) );
	}

	/**
	 * Record slow translated frontend renders for later MCP inspection.
	 */
	public static function record_slow_frontend_request(): void {
		if ( ! self::is_frontend_runtime_request() ) {
			return;
		}

		$started = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : 0.0;
		if ( $started <= 0 ) {
			return;
		}

		$duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( $duration_ms < 5000 ) {
			return;
		}

		$language = self::frontend_language();
		$path     = self::current_request_path();
		$entry    = array(
			'timestamp'     => current_time( 'mysql', true ),
			'duration_ms'   => $duration_ms,
			'language'      => $language,
			'path'          => '/' . trim( $path, '/' ),
			'post_id'       => self::frontend_surface_post_id(),
			'is_404'        => is_404(),
			'is_singular'   => is_singular( 'page' ),
			'is_home'       => is_home(),
			'query_count'   => function_exists( 'get_num_queries' ) ? get_num_queries() : null,
			'memory_peak_mb' => round( memory_get_peak_usage( true ) / 1048576, 2 ),
		);

		$log = get_option( self::OPTION_FRONTEND_SLOW_LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, 50 );
		update_option( self::OPTION_FRONTEND_SLOW_LOG, $log, false );
	}

	/**
	 * Redirect legacy language-prefixed paths to the current translated target.
	 *
	 * Example: /ar/services/ should resolve to /ar/khadamat/ when the Arabic
	 * translation exists but uses a localized slug.
	 */
	public static function redirect_localized_source_paths_with_language_prefix(): void {
		if ( ! self::is_frontend_runtime_request() ) {
			return;
		}
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		$is_get         = 'GET' === $request_method;
		$is_head        = 'HEAD' === $request_method;
		$is_allowed = $is_get || $is_head;
		if ( ! $is_allowed ) {
			return;
		}

		$request_path = self::current_request_path();
		if ( '' === $request_path ) {
			return;
		}

		$segments = array_values( array_filter( array_map( 'sanitize_title', explode( '/', trim( $request_path, '/' ) ) ) ) );
		if ( count( $segments ) < 2 ) {
			return;
		}

		$language  = '';
		$first     = $segments[0];
		$languages = self::languages();
		foreach ( $languages as $code => $config ) {
			$prefix = isset( $config['prefix'] ) ? sanitize_title( (string) $config['prefix'] ) : '';
			if ( '' !== $prefix && $prefix === $first ) {
				$language = sanitize_key( (string) $code );
				break;
			}
		}
		if ( '' === $language ) {
			return;
		}

		$source_path = implode( '/', array_slice( $segments, 1 ) );
		if ( '' === $source_path ) {
			return;
		}

			$legacy_route = self::legacy_compatibility_source_route_for_language_path( $language, $source_path );
		if ( empty( $legacy_route ) ) {
			return;
		}

		$source_id      = absint( $legacy_route['source_id'] ?? 0 );
		$translation_id = absint( $legacy_route['translation_id'] ?? 0 );
		if ( ! $translation_id ) {
			return;
		}

			if ( self::legacy_compatibility_should_block_source_path_redirect( $source_id, $language, $translation_id ) ) {
				status_header( 404 );
				nocache_headers();
				exit;
		}

		$target_url = (string) ( $legacy_route['target_url'] ?? '' );
		if ( ! $target_url ) {
			return;
		}

		$current_path = self::normalized_url_path( '/' . trim( $request_path, '/' ) );
		$target_path  = self::normalized_url_path( $target_url );
		if ( '' === $target_path || $current_path === $target_path ) {
			return;
		}

		$query_string = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['QUERY_STRING'] ) ) : '';
		if ( '' !== $query_string ) {
			$query_params = array();
			wp_parse_str( $query_string, $query_params );
			$target_url = '' === $query_string ? $target_url : add_query_arg( $query_params, $target_url );
		}

			wp_safe_redirect( $target_url, 301 );
			exit;
		}

		/**
		 * Compatibility seam for old language-prefixed source paths.
		 *
		 * This keeps historical route behavior isolated from the current localized
		 * URL model. New route behavior should not be added to the legacy helpers.
		 *
		 * @return array<string,mixed>
		 */
		private static function legacy_compatibility_source_route_for_language_path( string $language, string $source_path ): array {
			return self::legacy_source_route_for_language_path( $language, $source_path );
		}

		/**
		 * Compatibility seam for blocking obsolete source-path redirects.
		 */
		private static function legacy_compatibility_should_block_source_path_redirect( int $source_id, string $language, int $translation_id = 0 ): bool {
			return self::should_block_legacy_source_path_redirect( $source_id, $language, $translation_id );
		}

		/**
		 * Resolve language-prefixed English source paths through the registry read model.
	 *
	 * @return array<string,mixed>
	 */
	private static function legacy_source_route_for_language_path( string $language, string $source_path ): array {
		$language = sanitize_key( $language );
		$source_path = implode(
			'/',
			array_values(
				array_filter(
					array_map( 'sanitize_title', explode( '/', trim( $source_path, '/' ) ) )
				)
			)
		);
		if ( '' === $language || '' === $source_path ) {
			return array();
		}

		foreach ( self::translation_frontend_rows_for_language( $language, array( 'publish' ) ) as $row ) {
			$row_source_path = trim( (string) ( $row['source_path'] ?? '' ), '/' );
			if ( $source_path !== $row_source_path ) {
				continue;
			}

			return array(
				'source_id'      => absint( $row['source_id'] ?? 0 ),
				'translation_id' => absint( $row['id'] ?? 0 ),
				'target_url'     => (string) ( $row['target_url'] ?? '' ),
				'target_path'    => (string) ( $row['target_path'] ?? '' ),
			);
		}

		$source_page = get_page_by_path( $source_path, OBJECT, 'page' );
		if ( ! $source_page || 'page' !== $source_page->post_type ) {
			return array();
		}

		$translation_id = self::find_translation_id( (int) $source_page->ID, $language, array( 'publish' ) );
		$target_url     = $translation_id ? get_permalink( $translation_id ) : '';
		if ( ! $translation_id || ! $target_url ) {
			return array();
		}

		return array(
			'source_id'      => (int) $source_page->ID,
			'translation_id' => $translation_id,
			'target_url'     => (string) $target_url,
			'target_path'    => self::normalized_url_path( (string) $target_url ),
		);
	}

	/**
	 * Normalize visible Arabic text without changing tags, URLs, attributes, or scripts.
	 */
	public static function localize_frontend_visible_text( string $html ): string {
		$language = self::frontend_language();
		$runtime  = self::runtime_text_replacements_for_language( $language );
		if ( empty( $runtime['has_replacements'] ) ) {
			return $html;
		}

		$html = self::localize_scriptless_social_sharing_html( $html, $runtime );
		$html = self::localize_akismet_privacy_notice_html( $html, $runtime );

		return self::rewrite_visible_text_segments(
			$html,
			static function ( string $text ) use ( $language, $runtime ): string {
				$protected_tokens = array();

				if ( 'ar' === $language ) {
					$text = self::protect_arabic_runtime_tokens( $text, $protected_tokens );
				}

				$text = self::apply_runtime_text_replacements( $text, $runtime );
				if ( ! empty( $protected_tokens ) ) {
					$text = strtr( $text, $protected_tokens );
				}

				if ( 'ar' !== $language ) {
					return $text;
				}

				return strtr(
					$text,
					array(
						'0' => '٠',
						'1' => '١',
						'2' => '٢',
						'3' => '٣',
						'4' => '٤',
						'5' => '٥',
						'6' => '٦',
						'7' => '٧',
						'8' => '٨',
						'9' => '٩',
					)
				);
			}
		);
	}

	/**
	 * Localize Scriptless Social Sharing UI even when it is rendered inside article content.
	 *
	 * @param array{search:array<int,string>,replace:array<int,string>,has_replacements:bool} $runtime Runtime replacement map.
	 */
	private static function localize_scriptless_social_sharing_html( string $html, array $runtime ): string {
		return (string) preg_replace_callback(
			'/(<h[1-6]\b[^>]*class=(["\'])[^"\']*\bscriptlesssocialsharing__heading\b[^"\']*\2[^>]*>)(.*?)(<\/h[1-6]>)/isu',
			static function ( array $match ) use ( $runtime ): string {
				$text = html_entity_decode( wp_strip_all_tags( (string) $match[3] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$updated = self::apply_runtime_text_replacements( $text, $runtime );
				if ( $updated === $text ) {
					return (string) $match[0];
				}

				return (string) $match[1] . esc_html( $updated ) . (string) $match[4];
			},
			$html
		);
	}

	/**
	 * Localize Akismet's comment privacy notice when it is injected after comment_form().
	 *
	 * @param array{search:array<int,string>,replace:array<int,string>,has_replacements:bool} $runtime Runtime replacement map.
	 */
	private static function localize_akismet_privacy_notice_html( string $html, array $runtime ): string {
		return (string) preg_replace_callback(
			'/(<p\b[^>]*class=(["\'])[^"\']*\bakismet_comment_form_privacy_notice\b[^"\']*\2[^>]*>)(.*?)(<\/p>)/isu',
			static function ( array $match ) use ( $runtime ): string {
				$content = self::rewrite_visible_text_segments(
					(string) $match[3],
					static function ( string $text ) use ( $runtime ): string {
						return self::apply_runtime_text_replacements( $text, $runtime );
					}
				);

				return (string) $match[1] . $content . (string) $match[4];
			},
			$html
		);
	}

	/**
	 * Apply runtime text replacements without matching inside already localized words.
	 *
	 * @param array{search:array<int,string>,replace:array<int,string>,has_replacements:bool} $runtime Runtime replacement map.
	 */
	private static function apply_runtime_text_replacements( string $text, array $runtime ): string {
		$search  = isset( $runtime['search'] ) && is_array( $runtime['search'] ) ? array_values( $runtime['search'] ) : array();
		$replace = isset( $runtime['replace'] ) && is_array( $runtime['replace'] ) ? array_values( $runtime['replace'] ) : array();

		foreach ( $search as $index => $needle ) {
			$needle      = (string) $needle;
			$replacement = isset( $replace[ $index ] ) ? (string) $replace[ $index ] : '';
			if ( '' === $needle ) {
				continue;
			}

			$pattern = self::runtime_text_replacement_pattern( $needle );
			$updated = preg_replace_callback(
				$pattern,
				static function () use ( $replacement ): string {
					return $replacement;
				},
				$text
			);

			if ( is_string( $updated ) ) {
				$text = $updated;
				continue;
			}

			$text = str_ireplace( $needle, $replacement, $text );
		}

		return $text;
	}

	/**
	 * Build a Unicode-aware replacement pattern for a runtime text key.
	 */
	private static function runtime_text_replacement_pattern( string $needle ): string {
		$pattern = preg_quote( $needle, '/' );
		if ( preg_match( '/^[\p{L}\p{N}_]/u', $needle ) ) {
			$pattern = '(?<![\p{L}\p{N}_])' . $pattern;
		}
		if ( preg_match( '/[\p{L}\p{N}_]$/u', $needle ) ) {
			$pattern .= '(?![\p{L}\p{N}_])';
		}

		return '/' . $pattern . '/iu';
	}

	/**
	 * Protect URLs and email tokens before Arabic digit normalization.
	 *
	 * @param array<string,string> $protected_tokens Tokens restored after rewriting.
	 */
	private static function protect_arabic_runtime_tokens( string $text, array &$protected_tokens ): string {
		foreach ( array( '/\bmailto:[^\s"\'>]+/i', '/\b(?:https?|ftp):\/\/[^\s"\'>]+/i', '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/i' ) as $pattern ) {
			$text = (string) preg_replace_callback(
				$pattern,
				static function ( array $match ) use ( &$protected_tokens ): string {
					$token = '##AR_SKIP_' . count( $protected_tokens ) . '##';
					$protected_tokens[ $token ] = $match[0];
					return $token;
				},
				$text
			);
		}

		return $text;
	}

	/**
	 * Rewrite text nodes while preserving markup and non-visible code-bearing sections.
	 */
	private static function rewrite_visible_text_segments( string $html, callable $rewriter ): string {
		$parts = preg_split( '/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $html;
		}

		$skip_stack = array();
		$skip_tags  = array( 'script', 'style', 'svg', 'math', 'article', 'nav' );
		$output   = '';
		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			if ( '<' === $part[0] ) {
				if ( preg_match( '/^<\s*\/\s*([a-z0-9_-]+)\s*>/i', $part, $match ) ) {
					$closing_tag = strtolower( (string) $match[1] );
					if ( ! empty( $skip_stack ) && end( $skip_stack ) === $closing_tag ) {
						array_pop( $skip_stack );
					}
					$output .= $part;
					continue;
				}

				if ( preg_match( '/^<\s*([a-z0-9_-]+)\b/i', $part, $match ) ) {
					$open_tag = strtolower( (string) $match[1] );
					if ( in_array( $open_tag, $skip_tags, true ) ) {
						if ( ! preg_match( '/\/\s*>$/', trim( $part ) ) ) {
							$skip_stack[] = $open_tag;
						}
					}
				}

				$output .= $part;
				continue;
			}

			$output .= empty( $skip_stack ) ? $rewriter( $part ) : $part;
		}

		return $output;
	}

	/**
	 * Start localized 404 output rewriting.
	 */
	public static function maybe_start_not_found_localization(): void {
		if ( ! self::is_frontend_runtime_request() || ! is_404() ) {
			return;
		}

		ob_start( array( __CLASS__, 'localize_not_found_html' ) );
	}

	/**
	 * Localize the custom 404 block output.
	 */
	public static function localize_not_found_html( string $html ): string {
		if ( false === strpos( $html, 'devenia-custom-404' ) ) {
			return $html;
		}

		$language = self::frontend_language();
		$languages    = self::languages();
		$translations = isset( $languages[ $language ]['not_found_text'] ) && is_array( $languages[ $language ]['not_found_text'] )
			? $languages[ $language ]['not_found_text']
			: array();

		if ( $translations ) {
			uksort(
				$translations,
				static function ( string $a, string $b ): int {
					return strlen( $b ) <=> strlen( $a );
				}
			);

			foreach ( $translations as $source => $translated ) {
				if ( is_string( $source ) && is_string( $translated ) && '' !== $source ) {
					$html = str_replace( $source, $translated, $html );
				}
			}
		}

		$html = self::filter_not_found_route_cards( $html, $language );

		return self::localize_internal_links_in_content( $html, $language );
	}

	/**
	 * Remove 404 route cards that are not approved for the active language.
	 */
	private static function filter_not_found_route_cards( string $html, string $language ): string {
		$languages = self::languages();
		$routes    = isset( $languages[ $language ]['not_found_routes'] ) && is_array( $languages[ $language ]['not_found_routes'] )
			? $languages[ $language ]['not_found_routes']
			: array();

		$allowed = array();
		foreach ( $routes as $route ) {
			$route = '/' . trim( (string) $route, '/' ) . '/';
			if ( '//' !== $route ) {
				$allowed[ $route ] = true;
			}
		}

		if ( ! $allowed ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'~<style>[^<]*\\.gb-container-dv-not-found-card-(\\d+)[\\s\\S]*?</div></div>~',
			static function ( array $matches ) use ( $allowed ): string {
				$card = (string) $matches[0];
				if ( ! preg_match( '/\\bhref=([\"\'])([^\"\']+)\\1/i', $card, $href_match ) ) {
					return $card;
				}

				$href = html_entity_decode( (string) $href_match[2], ENT_QUOTES );
				$path = wp_parse_url( $href, PHP_URL_PATH );
				$path = is_string( $path ) ? trailingslashit( '/' . trim( $path, '/' ) ) : '';
				if ( isset( $allowed[ $path ] ) ) {
					return $card;
				}

				return '';
			},
			$html
		);
	}

	/**
	 * Load language selector styles as a versioned frontend asset.
	 */
	public static function enqueue_language_menu_styles(): void {
		if ( ! self::is_frontend_language_surface() ) {
			return;
		}

		$surface = self::frontend_surface( self::frontend_surface_post_id() );
		$links   = $surface['links'];
		if ( count( $links ) < 2 ) {
			return;
		}

		$base = plugin_dir_url( __FILE__ );
		$dir  = plugin_dir_path( __FILE__ );
		$path = $dir . 'assets/language-menu.css';

		wp_enqueue_style(
			'ai-translation-workflow-language-menu',
			$base . 'assets/language-menu.css',
			array(),
			is_readable( $path ) ? (string) filemtime( $path ) : self::VERSION
		);
	}

	/**
	 * Add translation workflow columns to the page list.
	 */
	public static function add_admin_columns( array $columns ): array {
		$columns['devenia_translation_language'] = 'Lang';
		$columns['devenia_translation_status']   = 'Translation';
		$columns['devenia_translation_source']   = 'Source';
		return $columns;
	}

	/**
	 * Render translation workflow columns.
	 */
	public static function render_admin_column( string $column, int $post_id ): void {
		if ( 'devenia_translation_language' === $column ) {
			$language = self::language_for_context( $post_id );
			$config   = self::languages()[ $language ] ?? array();
			echo esc_html( ( $config['flag'] ?? strtoupper( $language ) ) . ' ' . strtoupper( $language ) );
			return;
		}

		if ( 'devenia_translation_status' === $column ) {
			if ( ! self::is_translation_post( $post_id ) ) {
				echo esc_html( 'source' );
				return;
			}
			$payload = self::translation_payload( get_post( $post_id ) );
			$status  = $payload['translation_status'] ?: 'needs_review';
			if ( empty( $payload['linguistic_reviewed_at'] ) ) {
				$status .= ' / needs linguistic review';
			}
			if ( ! empty( $payload['is_stale'] ) ) {
				$status .= ' / stale';
			}
			echo esc_html( $status );
			return;
		}

		if ( 'devenia_translation_source' === $column ) {
			$source_id = absint( get_post_meta( $post_id, self::META_SOURCE_ID, true ) );
			if ( ! $source_id ) {
				echo '&mdash;';
				return;
			}
			$edit_url = get_edit_post_link( $source_id );
			$title    = get_the_title( $source_id );
			if ( $edit_url ) {
				printf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html( $title ?: '#' . $source_id ) );
				return;
			}
			echo esc_html( $title ?: '#' . $source_id );
		}
	}

	/**
	 * Render admin filters for translation workflow.
	 */
	public static function render_admin_filters( string $post_type ): void {
		if ( 'page' !== $post_type ) {
			return;
		}

		$current_language = sanitize_key( (string) filter_input( INPUT_GET, 'devenia_translation_language', FILTER_UNSAFE_RAW ) );
		$current_status   = sanitize_key( (string) filter_input( INPUT_GET, 'devenia_translation_status', FILTER_UNSAFE_RAW ) );

		echo '<select name="devenia_translation_language">';
		echo '<option value="">All translation languages</option>';
		foreach ( self::languages() as $language => $config ) {
			if ( ! empty( $config['source'] ) ) {
				continue;
			}
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $language ),
				selected( $current_language, $language, false ),
				esc_html( ( $config['flag'] ?? strtoupper( $language ) ) . ' ' . ( $config['name'] ?? strtoupper( $language ) ) )
			);
		}
		echo '</select>';

		echo '<select name="devenia_translation_status">';
		echo '<option value="">All translation statuses</option>';
		foreach ( array( 'draft', 'needs_review', 'reviewed', 'published', 'stale' ) as $status ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $status ), selected( $current_status, $status, false ), esc_html( $status ) );
		}
		echo '</select>';
	}

	/**
	 * Apply admin filters for translation workflow.
	 */
	public static function apply_admin_filters( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'page' !== $query->get( 'post_type' ) ) {
			return;
		}

		$requested_language = sanitize_key( (string) filter_input( INPUT_GET, 'devenia_translation_language', FILTER_UNSAFE_RAW ) );
		$requested_status   = sanitize_key( (string) filter_input( INPUT_GET, 'devenia_translation_status', FILTER_UNSAFE_RAW ) );

		if ( '' === $requested_language && '' === $requested_status ) {
			return;
		}

		$status = '' !== $requested_status ? self::sanitize_translation_status( $requested_status ) : '';
		$pages  = self::translation_page_query(
			array(
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1000,
				'fields'         => 'ids',
			)
		);
		$ids    = array();
		foreach ( $pages->posts as $page_id ) {
			$page_id  = (int) $page_id;
			$language = (string) get_post_meta( $page_id, self::META_LANGUAGE, true );
			if ( '' === $language ) {
				continue;
			}
			if ( '' !== $requested_language && $language !== $requested_language ) {
				continue;
			}
			if ( '' !== $status && self::sanitize_translation_status( (string) get_post_meta( $page_id, self::META_STATUS, true ) ) !== $status ) {
				continue;
			}
			$ids[] = $page_id;
		}

		$query->set( 'post__in', $ids ?: array( 0 ) );
	}

	/**
	 * Mark translations stale when source content changes.
	 */
	public static function mark_translations_stale_on_source_save( int $post_id, WP_Post $post, bool $update ): void {
		if ( self::$suspend_source_stale_marking ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( self::is_translation_post( $post_id ) ) {
			self::invalidate_translation_reviews_if_content_changed( $post_id, 'translation_content_changed' );
			self::mark_generated_source_stale_from_authored_original( $post_id );
			return;
		}

		$current_hash = self::source_hash( $post );
		foreach ( self::translation_rows_for_source( $post_id ) as $translation ) {
			if ( $translation['source_hash'] && $translation['source_hash'] !== $current_hash ) {
				update_post_meta( (int) $translation['id'], self::META_STATUS, 'stale' );
				self::sync_translation_index_row( (int) $translation['id'] );
			}
		}
	}

	/**
	 * Learn from a real logged-in human edit to translated content.
	 */
	public static function capture_manual_reviewer_style_on_post_update( int $post_id, WP_Post $post_after, WP_Post $post_before ): void {
		if ( self::$suspend_reviewer_style_capture ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}
		if ( ! self::is_translatable_post_type( (string) $post_after->post_type ) ) {
			return;
		}
		if ( (string) $post_before->post_content === (string) $post_after->post_content ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$is_translation = self::is_translation_post( $post_id );
		$language = $is_translation ? sanitize_key( (string) get_post_meta( $post_id, self::META_LANGUAGE, true ) ) : self::source_language_code();
		if ( '' === $language || ! self::is_configured_content_language( $language ) ) {
			return;
		}

		$before_text = self::normalized_plain_text_for_review( (string) $post_before->post_content );
		$after_text  = self::normalized_plain_text_for_review( (string) $post_after->post_content );
		if ( '' === $after_text || $before_text === $after_text ) {
			return;
		}

		$diff = self::manual_reviewer_edit_excerpt( (string) $post_before->post_content, (string) $post_after->post_content );
		if ( '' === (string) ( $diff['before'] ?? '' ) && '' === (string) ( $diff['after'] ?? '' ) ) {
			return;
		}

		$reviewer = self::reviewer_name_for_user( $user );
		$result = self::record_reviewer_style_edit(
			array(
				'language'       => $language,
				'reviewer'       => $reviewer,
				'source_id'      => $is_translation ? absint( get_post_meta( $post_id, self::META_SOURCE_ID, true ) ) : $post_id,
				'translation_id' => $is_translation ? $post_id : 0,
				'before'         => (string) ( $diff['before'] ?? '' ),
				'after'          => (string) ( $diff['after'] ?? '' ),
				'lesson'         => 'Human editor approved this wording change in the WordPress editor. Prefer the approved phrasing when the same pattern appears again.',
				'category'       => 'other',
			)
		);
		if ( empty( $result['success'] ) ) {
			return;
		}

		update_post_meta(
			$post_id,
			'_devenia_translation_last_manual_style_capture',
			wp_json_encode(
				array(
					'captured_at' => gmdate( 'c' ),
					'user_id'     => $user_id,
					'reviewer'    => $reviewer,
					'language'    => $language,
					'example_id'  => (string) ( $result['profile']['examples'][0]['id'] ?? '' ),
					'before_hash' => hash( 'sha256', $before_text ),
					'after_hash'  => hash( 'sha256', $after_text ),
				)
			)
		);
	}

	/**
	 * Extract the first meaningful text fragment changed by a human editor.
	 *
	 * @return array{before:string,after:string}
	 */
	private static function manual_reviewer_edit_excerpt( string $before_content, string $after_content ): array {
		$window = self::manual_review_changed_text_window(
			self::normalized_plain_text_for_review( $before_content ),
			self::normalized_plain_text_for_review( $after_content ),
			260
		);
		if ( '' !== $window['before'] || '' !== $window['after'] ) {
			return $window;
		}

		return array(
			'before' => self::copy_brief_excerpt( self::normalized_plain_text_for_review( $before_content ), 700 ),
			'after'  => self::copy_brief_excerpt( self::normalized_plain_text_for_review( $after_content ), 700 ),
		);
	}

	/**
	 * Return a compact before/after window around the real changed text.
	 *
	 * @return array{before:string,after:string}
	 */
	private static function manual_review_changed_text_window( string $before_text, string $after_text, int $context_chars = 220 ): array {
		$before_text   = self::normalize_review_text( $before_text );
		$after_text    = self::normalize_review_text( $after_text );
		$context_chars = max( 40, $context_chars );
		if ( $before_text === $after_text ) {
			return array(
				'before' => '',
				'after'  => '',
			);
		}

		$before_chars = self::review_text_characters( $before_text );
		$after_chars  = self::review_text_characters( $after_text );
		if ( empty( $before_chars ) || empty( $after_chars ) ) {
			return array(
				'before' => self::copy_brief_excerpt( $before_text, 700 ),
				'after'  => self::copy_brief_excerpt( $after_text, 700 ),
			);
		}

		$before_count = count( $before_chars );
		$after_count  = count( $after_chars );
		$prefix       = 0;
		$prefix_limit = min( $before_count, $after_count );
		while ( $prefix < $prefix_limit && $before_chars[ $prefix ] === $after_chars[ $prefix ] ) {
			++$prefix;
		}

		$suffix = 0;
		while (
			$suffix < ( $before_count - $prefix )
			&& $suffix < ( $after_count - $prefix )
			&& $before_chars[ $before_count - 1 - $suffix ] === $after_chars[ $after_count - 1 - $suffix ]
		) {
			++$suffix;
		}

		$before_change_end = max( $prefix, $before_count - $suffix );
		$after_change_end  = max( $prefix, $after_count - $suffix );
		$before_start      = max( 0, $prefix - $context_chars );
		$after_start       = max( 0, $prefix - $context_chars );
		$before_end        = min( $before_count, $before_change_end + $context_chars );
		$after_end         = min( $after_count, $after_change_end + $context_chars );

		return array(
			'before' => self::review_text_window_excerpt( $before_chars, $before_start, $before_end ),
			'after'  => self::review_text_window_excerpt( $after_chars, $after_start, $after_end ),
		);
	}

	/**
	 * Split review text into Unicode characters when possible.
	 *
	 * @return array<int,string>
	 */
	private static function review_text_characters( string $text ): array {
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( is_array( $chars ) ) {
			return $chars;
		}

		return '' !== $text ? str_split( $text ) : array();
	}

	/**
	 * Join a diff window and show whether surrounding text was omitted.
	 *
	 * @param array<int,string> $chars Review text characters.
	 */
	private static function review_text_window_excerpt( array $chars, int $start, int $end ): string {
		$count = count( $chars );
		$start = max( 0, min( $start, $count ) );
		$end   = max( $start, min( $end, $count ) );
		$text  = implode( '', array_slice( $chars, $start, $end - $start ) );
		$text  = self::normalize_review_text( $text );
		if ( $start > 0 ) {
			$text = '...' . ltrim( $text );
		}
		if ( $end < $count ) {
			$text = rtrim( $text ) . '...';
		}

		return self::copy_brief_excerpt( $text, 700 );
	}

	/**
	 * Stable reviewer display name for per-person learning.
	 */
	private static function reviewer_name_for_user( WP_User $user ): string {
		$name = trim( (string) $user->display_name );
		if ( '' === $name ) {
			$name = trim( (string) $user->user_login );
		}
		if ( '' === $name ) {
			$name = 'User ' . (int) $user->ID;
		}

		return $name;
	}

	/**
	 * Normalize known Gutenberg serialization mismatches before WordPress stores content.
	 *
	 * @param array<string,mixed> $data    Sanitized post data about to be written.
	 * @param array<string,mixed> $postarr Raw post array passed to wp_insert_post().
	 *
	 * @return array<string,mixed>
	 */
	public static function normalize_invalid_translation_content_before_save( array $data, array $postarr ): array {
		if ( ! array_key_exists( 'post_content', $data ) ) {
			return $data;
		}

		$post_type = isset( $data['post_type'] ) ? sanitize_key( (string) $data['post_type'] ) : '';
		if ( '' === $post_type && isset( $postarr['ID'] ) ) {
			$post = get_post( (int) $postarr['ID'] );
			$post_type = $post ? (string) $post->post_type : '';
		}
		if ( '' !== $post_type && ! self::is_translatable_post_type( $post_type ) ) {
			return $data;
		}

		$data['post_content'] = self::normalize_gutenberg_content_for_storage( (string) $data['post_content'] );

		$post_id = isset( $postarr['ID'] ) ? absint( $postarr['ID'] ) : 0;
		if ( $post_id > 0 && self::is_translation_post( $post_id ) && ! self::$suspend_direct_save_storage_guardrails ) {
			$language = sanitize_key( (string) get_post_meta( $post_id, self::META_LANGUAGE, true ) );
			$issues   = self::hard_invalid_link_issues_for_content( (string) $data['post_content'], $language );
			if ( ! empty( $issues ) ) {
				self::reject_invalid_content_save( $post_id, $issues );
			}
		}

		return $data;
	}

	/**
	 * Persist the rejection evidence and block the unsafe save.
	 *
	 * @param int                         $post_id Existing post ID being updated.
	 * @param array<int,array<string,mixed>> $issues Hard storage issues.
	 */
	private static function reject_invalid_content_save( int $post_id, array $issues ): void {
		update_post_meta(
			$post_id,
			'_devenia_translation_rejected_invalid_content_save',
			wp_json_encode(
				array(
					'rejected_at' => gmdate( 'c' ),
					'issue_count' => count( $issues ),
					'issues'      => $issues,
				)
			)
		);

		wp_die(
			esc_html__( 'Content save blocked: storage integrity guardrails failed. Fix the reported route, link, or block markup issues before saving.', 'ai-translation-workflow' ),
			esc_html__( 'Invalid content storage', 'ai-translation-workflow' ),
			array( 'response' => 400 )
		);
	}

	/**
	 * Block direct WordPress/MCP saves that would break stored content integrity
	 * after normalization has run.
	 *
	 * Human editor saves must not be blocked by general translation/copy QA.
	 * Those issues are captured by review state and learning hooks after save;
	 * this pre-save guard is only for route collisions, invalid links, and
	 * malformed static Gutenberg markup that should never be stored.
	 *
	 * @param int                 $post_id Existing post ID being updated.
	 * @param array<string,mixed> $data    Sanitized post data about to be written.
	 */
	public static function block_invalid_translation_content_save( int $post_id, array $data ): void {
		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}

		$post_type = isset( $data['post_type'] ) ? sanitize_key( (string) $data['post_type'] ) : '';
		if ( '' !== $post_type && ! self::is_translatable_post_type( $post_type ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			return;
		}
		if ( self::$suspend_direct_save_storage_guardrails ) {
			return;
		}

		$content = array_key_exists( 'post_content', $data )
			? wp_unslash( (string) $data['post_content'] )
			: (string) $post->post_content;
		$issues  = array();

		if ( self::is_translation_post( $post_id ) ) {
			$language = sanitize_key( (string) get_post_meta( $post_id, self::META_LANGUAGE, true ) );
			$issues   = array_merge( $issues, self::translation_direct_save_route_issues( $post_id, $data ) );
			$issues   = array_merge( $issues, self::hard_invalid_link_issues_for_content( $content, $language ) );
		}

		$gutenberg_integrity = self::gutenberg_saved_markup_integrity( $content );
		if ( ! empty( $gutenberg_integrity['issues'] ) ) {
			$issues = array_merge( $issues, $gutenberg_integrity['issues'] );
		}
		if ( empty( $issues ) ) {
			delete_post_meta( $post_id, '_devenia_translation_rejected_invalid_content_save' );
			return;
		}

		self::reject_invalid_content_save( $post_id, $issues );
	}

	/**
	 * Guard translated post route changes made through generic WordPress saves.
	 *
	 * @param array<string,mixed> $data Sanitized post data about to be written.
	 * @return array<int,array<string,mixed>>
	 */
	private static function translation_direct_save_route_issues( int $post_id, array $data ): array {
		$post = get_post( $post_id );
		if ( ! $post || ! self::is_translation_post( $post_id ) ) {
			return array();
		}

		$slug = array_key_exists( 'post_name', $data )
			? sanitize_title( (string) $data['post_name'] )
			: (string) $post->post_name;
		$parent_id = array_key_exists( 'post_parent', $data ) ? absint( $data['post_parent'] ) : (int) $post->post_parent;
		$issues = array();

		if ( self::has_wordpress_duplicate_slug_suffix( $slug ) ) {
			$issues[] = self::qa_item(
				'localized_slug_duplicate_suffix',
				'Translation permalink uses a WordPress duplicate slug suffix such as -2. Resolve the route collision instead of saving the duplicate URL.',
				array(
					'slug' => $slug,
				)
			);
		}

		$conflicts = self::translation_slug_conflicts( $slug, (string) $post->post_type, $parent_id, $post_id );
		if ( $conflicts ) {
			$issues[] = self::qa_item(
				'localized_slug_collision',
				'Localized slug is already in use. Resolve the route collision before saving; WordPress duplicate slugs such as -2 are not allowed.',
				array(
					'requested_slug'    => $slug,
					'parent_id'         => $parent_id,
					'conflicting_posts' => $conflicts,
				)
			);
		}

		return $issues;
	}

	/**
	 * Find translation page ID.
	 */
	private static function find_translation_id( int $source_id, string $language, array $post_status = array( 'publish', 'draft', 'pending', 'private' ) ): int {
		static $cache = array();

		$status_key = implode( '|', array_map( 'sanitize_key', $post_status ) );
		$cache_key  = $source_id . ':' . sanitize_key( $language ) . ':' . $status_key;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$indexed_id = self::translation_index_id_for_source_language( $source_id, $language, $post_status );
		if ( $indexed_id ) {
			$cache[ $cache_key ] = $indexed_id;
			return $cache[ $cache_key ];
		}

		$query = self::translation_page_query(
			array(
				'post_status'    => $post_status,
				'posts_per_page' => 1000,
				'fields'         => 'ids',
			)
		);

		$cache[ $cache_key ] = 0;
		foreach ( $query->posts as $post_id ) {
			$post_id = (int) $post_id;
			if (
				$source_id === absint( get_post_meta( $post_id, self::META_SOURCE_ID, true ) )
				&& $language === (string) get_post_meta( $post_id, self::META_LANGUAGE, true )
			) {
				$cache[ $cache_key ] = $post_id;
				break;
			}
		}

		return $cache[ $cache_key ];
	}

	/**
	 * Build language links for a source or translated page.
	 */
	private static function language_links_for_post( int $post_id ): array {
		$is_translation = self::is_translation_post( $post_id );
		$source_id      = self::source_id_for_context( $post_id );
		if ( ! $source_id ) {
			return array();
		}

		$source = get_post( $source_id );
		if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) ) {
			return array();
		}

		$translations = self::translation_frontend_rows_for_source( $source_id, array( 'publish' ) );
		if ( empty( $translations ) ) {
			$translations = self::translation_rows_for_source( $source_id, array( 'publish' ) );
		}
		if ( ! $is_translation && empty( $translations ) ) {
			return array();
		}

		$links = array(
			'en' => array(
				'id'  => $source_id,
				'url' => get_permalink( $source_id ),
			),
		);

		foreach ( $translations as $translation ) {
			if ( empty( $translation['language'] ) || empty( $translation['url'] ) ) {
				continue;
			}
			$links[ $translation['language'] ] = array(
				'id'  => (int) $translation['id'],
				'url' => $translation['url'],
			);
		}

		return array_filter(
			$links,
			static function ( array $link ): bool {
				return ! empty( $link['url'] );
			}
		);
	}

	/**
	 * Build language links for 404 pages.
	 *
	 * A 404 should not create language variants of a URL we already know is
	 * missing. Send visitors to existing language roots instead.
	 */
	private static function language_links_for_not_found(): array {
		$links = array();

		foreach ( self::languages() as $language => $config ) {
			$url = home_url( '/' );
			if ( 'en' !== $language ) {
				$root_id = self::language_root_page_id( (string) $language );
				if ( $root_id ) {
					$url = (string) get_permalink( $root_id );
				} else {
					$prefix = isset( $config['prefix'] ) ? trim( sanitize_title( (string) $config['prefix'] ), '/' ) : '';
					$url    = '' === $prefix ? home_url( '/' ) : home_url( '/' . $prefix . '/' );
				}
			}

			$links[ $language ] = array(
				'id'  => 0,
				'url' => trailingslashit( $url ),
			);
		}

		return $links;
	}

	/**
	 * Current request path with the active language root removed.
	 */
	private static function request_path_without_language_prefix(): string {
		$path = self::current_request_path();
		if ( '' === $path ) {
			return '';
		}

		$segments      = explode( '/', $path );
		$first_segment = sanitize_key( (string) ( $segments[0] ?? '' ) );
		foreach ( self::languages() as $config ) {
			$prefix = isset( $config['prefix'] ) ? sanitize_key( (string) $config['prefix'] ) : '';
			if ( '' !== $prefix && $prefix === $first_segment ) {
				array_shift( $segments );
				break;
			}
		}

		return trim( implode( '/', array_map( 'sanitize_title', $segments ) ), '/' );
	}

	/**
	 * Current request path normalized for frontend language routing.
	 */
	private static function current_request_path(): string {
		$request_uri = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );
		if ( ! is_string( $request_uri ) || '' === $request_uri ) {
			return '';
		}

		$path = (string) wp_parse_url( esc_url_raw( wp_unslash( $request_uri ) ), PHP_URL_PATH );

		return trim( $path, '/' );
	}

	/**
	 * Current page language.
	 */
	private static function language_for_context( int $post_id ): string {
		$indexed = self::translation_index_row_for_translation( $post_id );
		if ( ! empty( $indexed['language'] ) ) {
			return (string) $indexed['language'];
		}

		$language = (string) get_post_meta( $post_id, self::META_LANGUAGE, true );
		return '' !== $language ? $language : 'en';
	}

	/**
	 * Frontend language from the current translated page or URL prefix.
	 */
	private static function frontend_language(): string {
		$surface = self::frontend_surface();
		return (string) $surface['language'];
	}

	/**
	 * Whether the current frontend request should receive language handling.
	 */
	private static function is_frontend_language_surface(): bool {
		return self::is_frontend_runtime_request() && ( is_singular( array( 'page', 'post' ) ) || is_home() || is_404() );
	}

	/**
	 * Resolve the content ID used for language handling on special query types.
	 */
	private static function frontend_surface_post_id( int $post_id = 0 ): int {
		if ( $post_id ) {
			return $post_id;
		}

		if ( is_home() ) {
			$posts_page_id = absint( get_option( 'page_for_posts' ) );
			if ( $posts_page_id ) {
				return $posts_page_id;
			}
		}

		return (int) get_queried_object_id();
	}

	/**
	 * Current page locale, using the translation registry when available.
	 */
	private static function locale_for_context( int $post_id ): string {
		$surface = self::frontend_surface( $post_id );
		return (string) $surface['locale'];
	}

	/**
	 * WordPress gettext locale for the current context.
	 */
	private static function wordpress_locale_for_context( int $post_id ): string {
		$surface = self::frontend_surface( $post_id );
		return (string) $surface['wordpress_locale'];
	}

	/**
	 * Use an installed WordPress locale for translated frontend gettext strings.
	 */
	public static function filter_locale( string $locale ): string {
		if ( ! self::is_frontend_language_surface() ) {
			return $locale;
		}

		return self::wordpress_locale_for_context( self::frontend_surface_post_id() );
	}

	/**
	 * Switch gettext to the active translated frontend locale before templates render.
	 */
	public static function switch_frontend_locale(): void {
		if ( ! self::is_frontend_language_surface() || ! function_exists( 'switch_to_locale' ) ) {
			return;
		}

		$locale = self::wordpress_locale_for_context( self::frontend_surface_post_id() );
		if ( '' === $locale || $locale === determine_locale() ) {
			return;
		}

		switch_to_locale( $locale );
	}

	/**
	 * Localize WordPress comment-form defaults on translated frontend posts.
	 *
	 * @param array<string,mixed> $defaults Comment form defaults.
	 * @return array<string,mixed>
	 */
	public static function localize_comment_form_defaults( array $defaults ): array {
		if ( ! self::is_frontend_language_surface() ) {
			return $defaults;
		}

		$strings = self::comment_form_strings( self::frontend_language() );

		$defaults['title_reply']       = $strings['title_reply'];
		$defaults['cancel_reply_link'] = $strings['cancel_reply_link'];
		$defaults['label_submit']      = $strings['label_submit'];
		$defaults['comment_field']     = sprintf(
			'<p class="comment-form-comment"><label for="comment" class="screen-reader-text">%1$s</label><textarea id="comment" name="comment" cols="45" rows="8" required></textarea></p>',
			esc_html( $strings['comment'] )
		);

		return $defaults;
	}

	/**
	 * Localize WordPress comment-form fields on translated frontend posts.
	 *
	 * @param array<string,string> $fields Comment form fields.
	 * @return array<string,string>
	 */
	public static function localize_comment_form_default_fields( array $fields ): array {
		if ( ! self::is_frontend_language_surface() ) {
			return $fields;
		}

		$strings = self::comment_form_strings( self::frontend_language() );

		$fields['author'] = sprintf(
			'<label for="author" class="screen-reader-text">%1$s</label><input placeholder="%2$s" id="author" name="author" type="text" value="" size="30" required />',
			esc_html( $strings['name'] ),
			esc_attr( $strings['name_required'] )
		);
		$fields['email'] = sprintf(
			'<label for="email" class="screen-reader-text">%1$s</label><input placeholder="%2$s" id="email" name="email" type="email" value="" size="30" required />',
			esc_html( $strings['email'] ),
			esc_attr( $strings['email_required'] )
		);
		$fields['url'] = sprintf(
			'<label for="url" class="screen-reader-text">%1$s</label><input placeholder="%2$s" id="url" name="url" type="url" value="" size="30" />',
			esc_html( $strings['website'] ),
			esc_attr( $strings['website'] )
		);

		return $fields;
	}

	/**
	 * Comment-form UI strings for translated frontend posts.
	 *
	 * @return array<string,string>
	 */
	private static function comment_form_strings( string $language ): array {
		$defaults = array(
			'title_reply'       => 'Leave a Reply',
			'cancel_reply_link' => 'Cancel reply',
			'comment'           => 'Comment',
			'name'              => 'Name',
			'name_required'     => 'Name *',
			'email'             => 'Email',
			'email_required'    => 'Email *',
			'website'           => 'Website',
			'label_submit'      => 'Post Comment',
		);

		$languages = self::languages();
		$config    = isset( $languages[ $language ]['comment_form_text'] ) && is_array( $languages[ $language ]['comment_form_text'] )
			? $languages[ $language ]['comment_form_text']
			: array();

		foreach ( $defaults as $key => $fallback ) {
			if ( isset( $config[ $key ] ) && is_string( $config[ $key ] ) && '' !== trim( $config[ $key ] ) ) {
				$defaults[ $key ] = trim( $config[ $key ] );
			}
		}

		return $defaults;
	}

	/**
	 * Keep the root html lang attribute aligned with the rendered translation.
	 */
	public static function filter_language_attributes( string $output ): string {
		if ( ! self::is_frontend_language_surface() ) {
			return $output;
		}

		$surface   = self::frontend_surface( self::frontend_surface_post_id() );
		$html_lang = str_replace( '_', '-', (string) $surface['locale'] );
		$direction = isset( $surface['direction'] ) ? (string) $surface['direction'] : 'ltr';
		if ( '' === $html_lang ) {
			return $output;
		}

		if ( preg_match( '/\blang=(["\']).*?\1/', $output ) ) {
			$output = (string) preg_replace( '/\blang=(["\']).*?\1/', 'lang="' . esc_attr( $html_lang ) . '"', $output, 1 );
		} else {
			$output = trim( $output . ' lang="' . esc_attr( $html_lang ) . '"' );
		}

		if ( preg_match( '/\bdir=(["\']).*?\1/', $output ) ) {
			return (string) preg_replace( '/\bdir=(["\']).*?\1/', 'dir="' . esc_attr( $direction ) . '"', $output, 1 );
		}

		return trim( $output . ' dir="' . esc_attr( $direction ) . '"' );
	}

	/**
	 * Give translated blog archives the same GeneratePress body schema as /blog/.
	 */
	public static function filter_translated_posts_page_body_itemtype( string $itemtype ): string {
		return self::is_translated_posts_page_request() ? 'Blog' : $itemtype;
	}

	/**
	 * Give translated blog archives the same OpenGraph type as /blog/.
	 */
	public static function filter_translated_posts_page_opengraph_type( string $type ): string {
		return self::is_translated_posts_page_request() ? 'website' : $type;
	}

	/**
	 * Keep translated posts-page archive canonical URLs query-free.
	 */
	public static function filter_translated_posts_page_canonical( string $canonical ): string {
		if ( ! self::is_translated_posts_page_request() ) {
			return $canonical;
		}

		$base_url = self::translated_posts_page_base_url();

		return $base_url ?: $canonical;
	}

	/**
	 * Keep translated posts-page archive SEO titles on the archive surface.
	 */
	public static function filter_translated_posts_page_seo_title( string $title ): string {
		if ( ! self::is_translated_posts_page_request() ) {
			return $title;
		}

		$post_title = trim( wp_strip_all_tags( get_the_title( get_queried_object_id() ) ) );
		if ( '' === $post_title ) {
			$post_title = __( 'Blog', 'ai-translation-workflow' );
		}

		$site_name = trim( wp_strip_all_tags( get_bloginfo( 'name' ) ) );

		return '' === $site_name ? $post_title : sprintf( '%s | %s', $post_title, $site_name );
	}

	/**
	 * Keep translated posts-page archive descriptions accurate even when page meta is stale.
	 */
	public static function filter_translated_posts_page_seo_description( string $description ): string {
		if ( ! self::is_translated_posts_page_request() ) {
			return $description;
		}

		return self::translated_posts_page_meta_description( self::frontend_language() );
	}

	/**
	 * Redirect duplicate translated posts-page page-1 query URLs to the clean archive URL.
	 */
	public static function redirect_translated_posts_page_first_page_query(): void {
		if ( ! self::is_translated_posts_page_request() ) {
			return;
		}

		$page_value = filter_input( INPUT_GET, 'devenia_blog_page', FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR );
		if ( null === $page_value ) {
			return;
		}

		if ( false === $page_value ) {
			return;
		}

		$page = absint( $page_value );
		if ( $page > 1 ) {
			return;
		}

		$base_url = self::translated_posts_page_base_url();
		if ( '' === $base_url ) {
			return;
		}

		wp_safe_redirect( $base_url, 301 );
		exit;
	}

	/**
	 * Remove singular Article schema from translated blog archives.
	 *
	 * @param array<string,mixed> $data Rank Math JSON-LD data.
	 * @param mixed               $jsonld Rank Math JSON-LD context object.
	 * @return array<string,mixed>
	 */
	public static function filter_translated_posts_page_json_ld( array $data, $jsonld ): array {
		if ( ! self::is_translated_posts_page_request() ) {
			return $data;
		}

		foreach ( $data as $key => $entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}

			$type = $entity['@type'] ?? '';
			if ( is_array( $type ) ) {
				$type = reset( $type );
			}

			if ( in_array( $type, array( 'Article', 'BlogPosting', 'Person' ), true ) ) {
				unset( $data[ $key ] );
				continue;
			}

			if ( 'WebPage' === $type || 'CollectionPage' === $type ) {
				$data[ $key ]['@type'] = 'CollectionPage';
				unset(
					$data[ $key ]['datePublished'],
					$data[ $key ]['dateModified'],
					$data[ $key ]['primaryImageOfPage']
				);
			}
		}

		return $data;
	}

	/**
	 * Render translated blog pages with the same GeneratePress archive loop as the source posts page.
	 */
	public static function use_translated_posts_page_template( string $template ): string {
		if ( ! self::is_translated_posts_page_request() ) {
			return $template;
		}

		$translated_template = plugin_dir_path( __FILE__ ) . 'templates/translated-posts-page.php';
		return is_readable( $translated_template ) ? $translated_template : $template;
	}

	/**
	 * Add archive-like body classes to translated blog pages.
	 *
	 * @param array<int,string> $classes Body classes.
	 * @return array<int,string>
	 */
	public static function add_translated_posts_page_body_class( array $classes ): array {
		if ( self::is_translated_posts_page_request() ) {
			$classes = array_values(
				array_filter(
					$classes,
					static function ( string $class ): bool {
						if ( in_array( $class, array( 'wp-singular', 'page', 'page-template-default', 'page-child', 'contained-content', 'post-image-aligned-right' ), true ) ) {
							return false;
						}

						return ! preg_match( '/^(page-id-|parent-pageid-)/', $class );
					}
				)
			);
			$classes[] = 'blog';
			$classes[] = 'post-image-aligned-left';
			$classes[] = 'devenia-translated-posts-page';
		}

		return array_values( array_unique( $classes ) );
	}

	/**
	 * Keep translated static front pages on the same theme surface as the source front page.
	 *
	 * @param array<int,string> $classes Body classes.
	 * @return array<int,string>
	 */
	public static function add_translated_front_page_body_class( array $classes ): array {
		if ( is_admin() || ! is_singular( 'page' ) ) {
			return $classes;
		}

		$post_id = (int) get_queried_object_id();
		if ( ! $post_id || ! self::is_translation_post( $post_id ) ) {
			return $classes;
		}

		$front_page_id = absint( get_option( 'page_on_front' ) );
		if ( ! $front_page_id || self::source_id_for_context( $post_id ) !== $front_page_id ) {
			return $classes;
		}

		$classes[] = 'home';

		return array_values( array_unique( $classes ) );
	}

	/**
	 * Load the source posts-page GenerateBlocks CSS for translated blog archives.
	 */
	public static function enqueue_translated_posts_page_source_styles(): void {
		if ( ! self::is_translated_posts_page_request() ) {
			return;
		}

		$posts_page_id = absint( get_option( 'page_for_posts' ) );
		if ( ! $posts_page_id ) {
			return;
		}

		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['baseurl'] ) || empty( $upload_dir['basedir'] ) ) {
			return;
		}

		$relative = 'generateblocks/style-' . $posts_page_id . '.css';
		$path     = trailingslashit( (string) $upload_dir['basedir'] ) . $relative;
		$url      = trailingslashit( (string) $upload_dir['baseurl'] ) . $relative;
		if ( ! is_readable( $path ) ) {
			return;
		}

		wp_enqueue_style(
			'devenia-ai-translations-posts-page-source',
			$url,
			array(),
			(string) filemtime( $path )
		);
	}

	/**
	 * Add an admin-bar entry for editor-only frontend text fixes.
	 */
	public static function add_quick_copy_edit_admin_bar_node( WP_Admin_Bar $admin_bar ): void {
		if ( ! self::quick_copy_edit_context_allowed() ) {
			return;
		}

		$admin_bar->add_node(
			array(
				'id'    => 'devenia-quick-copy-edit',
				'title' => esc_html__( 'Quick Copy Edit', 'ai-translation-workflow' ),
				'href'  => '#devenia-quick-copy-edit',
				'meta'  => array(
					'class' => 'devenia-quick-copy-edit-admin-bar',
				),
			)
		);
	}

	/**
	 * Load editor-only frontend quick-copy-edit assets.
	 */
	public static function enqueue_quick_copy_edit_assets(): void {
		if ( ! self::quick_copy_edit_context_allowed() ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		$base    = plugin_dir_url( __FILE__ );
		$dir     = plugin_dir_path( __FILE__ );

		wp_enqueue_style(
			'devenia-ai-translations-quick-copy-edit',
			$base . 'assets/quick-copy-edit.css',
			array(),
			is_readable( $dir . 'assets/quick-copy-edit.css' ) ? (string) filemtime( $dir . 'assets/quick-copy-edit.css' ) : self::VERSION
		);
		wp_enqueue_script(
			'devenia-ai-translations-quick-copy-edit',
			$base . 'assets/quick-copy-edit.js',
			array(),
			is_readable( $dir . 'assets/quick-copy-edit.js' ) ? (string) filemtime( $dir . 'assets/quick-copy-edit.js' ) : self::VERSION,
			true
		);
		wp_localize_script(
			'devenia-ai-translations-quick-copy-edit',
			'AITranslationWorkflowQuickCopyEdit',
			array(
				'postId'   => $post_id,
				'endpoint' => esc_url_raw( rest_url( 'ai-translation-workflow/v1/quick-copy-edit' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'labels'   => array(
					'open'        => __( 'Quick Copy Edit', 'ai-translation-workflow' ),
					'close'       => __( 'Close', 'ai-translation-workflow' ),
					'active'      => __( 'Click text to edit it inline.', 'ai-translation-workflow' ),
					'inactive'    => __( 'Quick Copy Edit is off.', 'ai-translation-workflow' ),
					'save'        => __( 'Save', 'ai-translation-workflow' ),
					'cancel'      => __( 'Cancel', 'ai-translation-workflow' ),
					'saved'       => __( 'Saved.', 'ai-translation-workflow' ),
					'error'       => __( 'Could not save this text change.', 'ai-translation-workflow' ),
					'unchanged'   => __( 'No text change to save.', 'ai-translation-workflow' ),
				),
			)
		);
	}

	/**
	 * Load frontend heading fit assets for localized page/post surfaces.
	 */
	public static function enqueue_frontend_heading_fit_assets(): void {
		if ( ! self::is_frontend_language_surface() ) {
			return;
		}

		$base = plugin_dir_url( __FILE__ );
		$dir  = plugin_dir_path( __FILE__ );

		wp_enqueue_style(
			'devenia-ai-translations-heading-fit',
			$base . 'assets/frontend-heading-fit.css',
			array(),
			is_readable( $dir . 'assets/frontend-heading-fit.css' ) ? (string) filemtime( $dir . 'assets/frontend-heading-fit.css' ) : self::VERSION
		);
		wp_enqueue_script(
			'devenia-ai-translations-heading-fit',
			$base . 'assets/frontend-heading-fit.js',
			array(),
			is_readable( $dir . 'assets/frontend-heading-fit.js' ) ? (string) filemtime( $dir . 'assets/frontend-heading-fit.js' ) : self::VERSION,
			true
		);
		wp_localize_script(
			'devenia-ai-translations-heading-fit',
			'AITranslationWorkflowHeadingFit',
			array(
				'minScale' => 0.88,
				'step'     => 0.02,
			)
		);
	}

	/**
	 * Add inline-edit markers to supported rendered block text for logged-in editors.
	 */
	public static function mark_quick_copy_edit_rendered_content( string $content ): string {
		if ( ! self::quick_copy_edit_context_allowed() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id = (int) get_queried_object_id();
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return $content;
		}

		foreach ( self::quick_copy_edit_items_for_content( (string) $post->post_content ) as $item ) {
			$html = (string) ( $item['html'] ?? '' );
			if ( '' === $html ) {
				continue;
			}

			if ( self::quick_copy_edit_item_is_segment( $item ) ) {
				$content = self::quick_copy_edit_mark_rendered_segment_text( $content, $item );
				continue;
			}

			if ( false !== strpos( $content, $html ) ) {
				$marked = self::quick_copy_edit_mark_html( $html, $item );
				if ( '' === $marked || $marked === $html ) {
					continue;
				}

				$content = self::replace_first_string( $html, $marked, $content );
				continue;
			}

			$next_content = self::quick_copy_edit_mark_rendered_equivalent( $content, $item );
			if ( $next_content !== $content ) {
				$content = $next_content;
				continue;
			}

			$content = self::quick_copy_edit_mark_rendered_simple_text( $content, $item );
		}

		return $content;
	}

	/**
	 * Register REST routes for frontend quick text edits.
	 */
	public static function register_quick_copy_edit_rest_routes(): void {
		register_rest_route(
			'ai-translation-workflow/v1',
			'/quick-copy-edit',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_quick_copy_edit_items' ),
					'permission_callback' => array( __CLASS__, 'rest_quick_copy_edit_permission' ),
					'args'                => array(
						'post_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_quick_copy_edit_update' ),
					'permission_callback' => array( __CLASS__, 'rest_quick_copy_edit_permission' ),
					'args'                => array(
						'post_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'path'    => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'text'    => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => array( __CLASS__, 'sanitize_quick_copy_edit_text' ),
						),
						'hash'    => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * REST permission callback for quick copy edits.
	 */
	public static function rest_quick_copy_edit_permission( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			return new WP_Error( 'devenia_quick_copy_edit_invalid_post', __( 'This content cannot be quick-edited.', 'ai-translation-workflow' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'devenia_quick_copy_edit_forbidden', __( 'You are not allowed to edit this content.', 'ai-translation-workflow' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Return simple text blocks that can be safely edited from the frontend.
	 */
	public static function rest_quick_copy_edit_items( WP_REST_Request $request ): WP_REST_Response {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return rest_ensure_response( array( 'success' => false, 'items' => array() ) );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'post_id' => $post_id,
				'items'   => self::quick_copy_edit_public_items( self::quick_copy_edit_items_for_content( (string) $post->post_content ) ),
			)
		);
	}

	/**
	 * Save one quick copy edit back into the stored block tree.
	 */
	public static function rest_quick_copy_edit_update( WP_REST_Request $request ): WP_REST_Response {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$post    = get_post( $post_id );
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			return rest_ensure_response( self::error( 'This content cannot be quick-edited.' ) );
		}

		$text = self::sanitize_quick_copy_edit_text( $request->get_param( 'text' ) );
		$selection = self::quick_copy_edit_parse_item_path( (string) $request->get_param( 'path' ) );
		if ( empty( $selection['path'] ) ) {
			return rest_ensure_response( self::error( 'Invalid block path.' ) );
		}

		$blocks  = parse_blocks( (string) $post->post_content );
		$updated = self::quick_copy_edit_update_block_at_path( $blocks, $selection['path'], $text, (string) $request->get_param( 'hash' ), $selection['segment_index'] );
		if ( ! $updated['success'] ) {
			return rest_ensure_response( $updated );
		}

		$content = self::normalize_gutenberg_content_for_storage( serialize_blocks( $blocks ) );
		$safety  = self::gutenberg_saved_markup_integrity( $content );
		if ( ! empty( $safety['issues'] ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => 'Gutenberg storage guardrails rejected the edited content.',
					'code'    => 'gutenberg_storage_guardrails_failed',
					'issues'  => $safety['issues'],
				)
			);
		}

		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_content' => $content,
				)
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( self::error( $result->get_error_message() ) );
		}

		clean_post_cache( $post_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'post_id' => $post_id,
				'item'    => $updated['item'],
			)
		);
	}

	/**
	 * Sanitize quick-edit text as plain visible copy.
	 */
	public static function sanitize_quick_copy_edit_text( $text ): string {
		$text = is_scalar( $text ) ? (string) $text : '';
		$text = wp_unslash( $text );
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/[ \t]+/u', ' ', $text );
		$text = preg_replace( '/\R+/u', "\n", (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Whether the current frontend request can use quick copy edit.
	 */
	private static function quick_copy_edit_context_allowed(): bool {
		if ( is_admin() || ! is_singular() ) {
			return false;
		}

		$post_id = (int) get_queried_object_id();
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Find supported simple text blocks in a Gutenberg document.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function quick_copy_edit_items_for_content( string $content ): array {
		$items = array();
		self::quick_copy_edit_collect_items( parse_blocks( $content ), array(), $items );

		return $items;
	}

	/**
	 * Strip internal matching markup from API responses.
	 *
	 * @param array<int,array<string,mixed>> $items Internal item rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function quick_copy_edit_public_items( array $items ): array {
		return array_map(
			static function ( array $item ): array {
				unset( $item['html'] );
				unset( $item['segment'] );
				return $item;
			},
			$items
		);
	}

	/**
	 * Recursively collect editable block text.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param array<int,int>                 $path   Current block path.
	 * @param array<int,array<string,mixed>> $items  Collected items.
	 */
	private static function quick_copy_edit_collect_items( array $blocks, array $path, array &$items ): void {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$current_path = array_merge( $path, array( (int) $index ) );
			$item         = self::quick_copy_edit_item_for_block( $block, $current_path );
			if ( $item ) {
				$items[] = $item;
			}
			foreach ( self::quick_copy_edit_segment_items_for_block( $block, $current_path ) as $segment_item ) {
				$items[] = $segment_item;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::quick_copy_edit_collect_items( $block['innerBlocks'], $current_path, $items );
			}
		}
	}

	/**
	 * Build an editable item for one supported block.
	 *
	 * @param array<string,mixed> $block Parsed block.
	 * @param array<int,int>      $path  Stable path in parsed block tree.
	 * @return array<string,mixed>
	 */
	private static function quick_copy_edit_item_for_block( array $block, array $path ): array {
		$block_name = (string) ( $block['blockName'] ?? '' );
		$html       = (string) ( $block['innerHTML'] ?? '' );
		$text       = self::quick_copy_edit_plain_text( $html );
		if ( '' === $text || ! self::quick_copy_edit_block_supported( $block_name, $html ) ) {
			return array();
		}

		return array(
			'path'       => implode( '.', $path ),
			'blockName'  => $block_name,
			'label'      => self::quick_copy_edit_block_label( $block_name ),
			'text'       => $text,
			'hash'       => self::quick_copy_edit_hash( $block_name, $html ),
			'preview'    => self::copy_brief_excerpt( $text, 140 ),
			'html'       => $html,
		);
	}

	/**
	 * Build editable segment items for one supported rich text block.
	 *
	 * @param array<string,mixed> $block Parsed block.
	 * @param array<int,int>      $path  Stable path in parsed block tree.
	 * @return array<int,array<string,mixed>>
	 */
	private static function quick_copy_edit_segment_items_for_block( array $block, array $path ): array {
		$block_name = (string) ( $block['blockName'] ?? '' );
		$html       = (string) ( $block['innerHTML'] ?? '' );
		if ( 'core/paragraph' !== $block_name || '' === $html ) {
			return array();
		}

		$segments = self::quick_copy_edit_rich_paragraph_segments( $html );
		if ( empty( $segments ) ) {
			return array();
		}

		$items = array();
		foreach ( $segments as $index => $segment ) {
			$text = (string) ( $segment['text'] ?? '' );
			if ( '' === $text ) {
				continue;
			}
			$items[] = array(
				'path'       => implode( '.', $path ) . ':segment:' . (int) $index,
				'blockName'  => $block_name,
				'label'      => self::quick_copy_edit_segment_label( (string) ( $segment['type'] ?? '' ) ),
				'text'       => $text,
				'hash'       => self::quick_copy_edit_hash( $block_name, $html, (int) $index ),
				'preview'    => self::copy_brief_excerpt( $text, 140 ),
				'html'       => $html,
				'segment'    => array(
					'index' => (int) $index,
					'type'  => (string) ( $segment['type'] ?? '' ),
				),
			);
		}

		return $items;
	}

	/**
	 * Update a supported block selected by path.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks, by reference.
	 * @param array<int,int>                 $path   Block path.
	 * @return array<string,mixed>
	 */
	private static function quick_copy_edit_update_block_at_path( array &$blocks, array $path, string $text, string $hash, ?int $segment_index = null ): array {
		$block =& self::quick_copy_edit_block_reference_at_path( $blocks, $path );
		if ( ! is_array( $block ) ) {
			return self::error( 'The selected block no longer exists.', 'quick_copy_edit_block_missing' );
		}

		$block_name = (string) ( $block['blockName'] ?? '' );
		$html       = (string) ( $block['innerHTML'] ?? '' );
		if ( $hash !== self::quick_copy_edit_hash( $block_name, $html, $segment_index ) ) {
			return self::error( 'The selected text changed before save. Reload the page and try again.', 'quick_copy_edit_conflict' );
		}
		if ( null !== $segment_index ) {
			$new_html = self::quick_copy_edit_replace_segment_text( $block_name, $html, $segment_index, $text );
			if ( '' === $new_html || $new_html === $html ) {
				return self::error( 'No text change to save.', 'quick_copy_edit_unchanged' );
			}
		} elseif ( ! self::quick_copy_edit_block_supported( $block_name, $html ) ) {
			return self::error( 'This block is not safe for quick text editing.', 'quick_copy_edit_unsupported_block' );
		} else {
			$new_html = self::quick_copy_edit_replace_block_text( $block_name, $html, $text );
			if ( '' === $new_html || $new_html === $html ) {
				return self::error( 'No text change to save.', 'quick_copy_edit_unchanged' );
			}
		}

		$block['innerHTML'] = $new_html;
		if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			foreach ( $block['innerContent'] as $index => $chunk ) {
				if ( is_string( $chunk ) && $chunk === $html ) {
					$block['innerContent'][ $index ] = $new_html;
					break;
				}
			}
		}

		$item = null !== $segment_index
			? self::quick_copy_edit_segment_item_by_index( $block, $path, $segment_index )
			: self::quick_copy_edit_item_for_block( $block, $path );

		return array(
			'success' => true,
			'item'    => self::quick_copy_edit_public_items( array( $item ) )[0],
		);
	}

	/**
	 * Add the data attributes used by the inline frontend editor.
	 *
	 * @param array<string,mixed> $item Editable item.
	 */
	private static function quick_copy_edit_mark_html( string $html, array $item ): string {
		$attributes = self::quick_copy_edit_marker_attributes( $item );

		if ( in_array( (string) ( $item['blockName'] ?? '' ), array( 'core/button', 'generateblocks/button' ), true ) ) {
			return (string) preg_replace( '/<a\b/i', '<a' . $attributes, $html, 1 );
		}

		return (string) preg_replace( '/^<([a-z][a-z0-9]*)(\s|>)/i', '<$1' . $attributes . '$2', trim( $html ), 1 );
	}

	/**
	 * Data attributes used by the inline frontend editor.
	 *
	 * @param array<string,mixed> $item Editable item.
	 */
	private static function quick_copy_edit_marker_attributes( array $item ): string {
		return sprintf(
			' data-devenia-qce-path="%s" data-devenia-qce-hash="%s" data-devenia-qce-label="%s"',
			esc_attr( (string) ( $item['path'] ?? '' ) ),
			esc_attr( (string) ( $item['hash'] ?? '' ) ),
			esc_attr( (string) ( $item['label'] ?? '' ) )
		);
	}

	/**
	 * Whether an editable item targets a rich-text segment inside a block.
	 *
	 * @param array<string,mixed> $item Editable item.
	 */
	private static function quick_copy_edit_item_is_segment( array $item ): bool {
		return isset( $item['segment'] ) && is_array( $item['segment'] );
	}

	/**
	 * Mark a rendered rich-text segment inside a supported block.
	 *
	 * @param array<string,mixed> $item Editable item.
	 */
	private static function quick_copy_edit_mark_rendered_segment_text( string $content, array $item ): string {
		$segment = isset( $item['segment'] ) && is_array( $item['segment'] ) ? $item['segment'] : array();
		$type    = (string) ( $segment['type'] ?? '' );
		$text    = (string) ( $item['text'] ?? '' );
		if ( '' === $type || '' === $text ) {
			return $content;
		}

		if ( 'strong' === $type ) {
			return self::quick_copy_edit_mark_rendered_strong_segment( $content, $item );
		}
		if ( 'after_break' === $type ) {
			return self::quick_copy_edit_mark_rendered_after_break_segment( $content, $item );
		}

		return $content;
	}

	/**
	 * Mark a rendered strong segment with quick-edit attributes.
	 *
	 * @param array<string,mixed> $item Editable item.
	 */
	private static function quick_copy_edit_mark_rendered_strong_segment( string $content, array $item ): string {
		$text    = (string) ( $item['text'] ?? '' );
		$pattern = '/<strong\b(?![^>]*\bdata-devenia-qce-path=)[^>]*>[^<]*<\/strong>/is';
		if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $content;
		}

		foreach ( $matches[0] as $match ) {
			$candidate = (string) $match[0];
			if ( self::quick_copy_edit_plain_text( $candidate ) !== $text ) {
				continue;
			}

			$marked = (string) preg_replace( '/<strong\b/i', '<strong' . self::quick_copy_edit_marker_attributes( $item ), $candidate, 1 );
			return substr_replace( $content, $marked, (int) $match[1], strlen( $candidate ) );
		}

		return $content;
	}

	/**
	 * Wrap and mark a rendered text segment that follows a paragraph line break.
	 *
	 * @param array<string,mixed> $item Editable item.
	 */
	private static function quick_copy_edit_mark_rendered_after_break_segment( string $content, array $item ): string {
		$text    = (string) ( $item['text'] ?? '' );
		$pattern = '/<p\b(?![^>]*\bdata-devenia-qce-path=)[^>]*>.*?<\/p>/is';
		if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $content;
		}

		foreach ( $matches[0] as $match ) {
			$candidate = (string) $match[0];
			$segments = self::quick_copy_edit_rich_paragraph_segments( $candidate );
			if ( empty( $segments[1] ) || (string) $segments[1]['text'] !== $text ) {
				continue;
			}

			$marked = self::quick_copy_edit_wrap_rich_paragraph_after_break_segment( $candidate, $item );
			if ( '' === $marked || $marked === $candidate ) {
				return $content;
			}

			return substr_replace( $content, $marked, (int) $match[1], strlen( $candidate ) );
		}

		return $content;
	}

	/**
	 * Mark a dynamically-rendered equivalent of a stored text block.
	 *
	 * GenerateBlocks dynamic blocks can render markup that is semantically the
	 * same as stored block HTML while not being byte-for-byte identical. Keep the
	 * editable surface narrow by requiring a stable generated class and matching
	 * visible text before adding quick-edit attributes.
	 *
	 * @param array<string,mixed> $item Editable item.
	 */
	private static function quick_copy_edit_mark_rendered_equivalent( string $content, array $item ): string {
		$html        = (string) ( $item['html'] ?? '' );
		$text        = (string) ( $item['text'] ?? '' );
		$tag_name    = self::quick_copy_edit_first_tag_name( $html );
		$stable_class = self::quick_copy_edit_stable_render_class( $html );
		if ( '' === $html || '' === $text || '' === $tag_name || '' === $stable_class ) {
			return $content;
		}

		$pattern = sprintf(
			'/<%1$s\b(?=[^>]*\bclass\s*=\s*["\'][^"\']*\b%2$s\b[^"\']*["\'])[^>]*>.*?<\/%1$s>/is',
			preg_quote( $tag_name, '/' ),
			preg_quote( $stable_class, '/' )
		);
		if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $content;
		}

		foreach ( $matches[0] as $match ) {
			$candidate = (string) $match[0];
			if ( self::quick_copy_edit_plain_text( $candidate ) !== $text ) {
				continue;
			}

			$marked = self::quick_copy_edit_mark_html( $candidate, $item );
			if ( '' === $marked || $marked === $candidate ) {
				return $content;
			}

			return substr_replace( $content, $marked, (int) $match[1], strlen( $candidate ) );
		}

		return $content;
	}

	/**
	 * Mark a simple rendered text block when exact HTML and generated class
	 * matching both miss. This covers simple core text blocks and buttons whose
	 * frontend markup may be normalized by WordPress or theme filters.
	 *
	 * @param array<string,mixed> $item Editable item.
	 */
	private static function quick_copy_edit_mark_rendered_simple_text( string $content, array $item ): string {
		$block_name = (string) ( $item['blockName'] ?? '' );
		if ( ! in_array( $block_name, array( 'core/paragraph', 'core/heading', 'core/list-item', 'core/button', 'generateblocks/headline', 'generateblocks/button' ), true ) ) {
			return $content;
		}

		$html      = (string) ( $item['html'] ?? '' );
		$text      = (string) ( $item['text'] ?? '' );
		$tag_names = self::quick_copy_edit_rendered_match_tag_names( $block_name, $html );
		if ( '' === $html || '' === $text || empty( $tag_names ) ) {
			return $content;
		}

		$pattern = sprintf(
			'/<(%1$s)\b(?![^>]*\bdata-devenia-qce-path=)[^>]*>.*?<\/\1>/is',
			implode(
				'|',
				array_map(
					static function ( string $tag_name ): string {
						return preg_quote( $tag_name, '/' );
					},
					$tag_names
				)
			)
		);
		if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $content;
		}

		foreach ( $matches[0] as $match ) {
			$candidate = (string) $match[0];
			if ( self::quick_copy_edit_plain_text( $candidate ) !== $text ) {
				continue;
			}

			$marked = self::quick_copy_edit_mark_html( $candidate, $item );
			if ( '' === $marked || $marked === $candidate ) {
				return $content;
			}

			return substr_replace( $content, $marked, (int) $match[1], strlen( $candidate ) );
		}

		return $content;
	}

	/**
	 * Candidate rendered tags that can safely represent one editable text block.
	 *
	 * @return array<int,string>
	 */
	private static function quick_copy_edit_rendered_match_tag_names( string $block_name, string $html ): array {
		if ( in_array( $block_name, array( 'core/button', 'generateblocks/button' ), true ) ) {
			return array( 'a' );
		}
		if ( 'core/list-item' === $block_name ) {
			return array( 'li' );
		}

		$tag_name = self::quick_copy_edit_first_tag_name( $html );
		if ( '' === $tag_name ) {
			return array();
		}

		return array( $tag_name );
	}

	/**
	 * Return the first HTML tag name from stored block markup.
	 */
	private static function quick_copy_edit_first_tag_name( string $html ): string {
		if ( ! preg_match( '/^\s*<([a-z][a-z0-9]*)\b/i', $html, $match ) ) {
			return '';
		}

		return strtolower( (string) $match[1] );
	}

	/**
	 * Return a class specific enough to match a rendered dynamic block safely.
	 */
	private static function quick_copy_edit_stable_render_class( string $html ): string {
		if ( ! preg_match( '/^\s*<[a-z][a-z0-9]*\b[^>]*\bclass=(["\'])(.*?)\1/is', $html, $match ) ) {
			return '';
		}

		$classes = preg_split( '/\s+/', trim( (string) $match[2] ) );
		if ( ! is_array( $classes ) ) {
			return '';
		}

		foreach ( $classes as $class ) {
			$class = trim( (string) $class );
			if ( preg_match( '/^gb-(?:headline|button)-[a-z0-9-]+$/i', $class ) ) {
				return $class;
			}
		}

		return '';
	}

	/**
	 * Replace the first exact occurrence of a string.
	 */
	private static function replace_first_string( string $search, string $replace, string $subject ): string {
		$position = strpos( $subject, $search );
		if ( false === $position ) {
			return $subject;
		}

		return substr_replace( $subject, $replace, $position, strlen( $search ) );
	}

	/**
	 * Get a parsed block by path.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 */
	private static function &quick_copy_edit_block_reference_at_path( array &$blocks, array $path ) {
		$null = null;
		if ( empty( $path ) ) {
			return $null;
		}

		$cursor =& $blocks;
		$last   = count( $path ) - 1;
		foreach ( $path as $depth => $index ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $index, $cursor ) || ! is_array( $cursor[ $index ] ) ) {
				return $null;
			}
			if ( $depth === $last ) {
				return $cursor[ $index ];
			}
			if ( ! isset( $cursor[ $index ]['innerBlocks'] ) || ! is_array( $cursor[ $index ]['innerBlocks'] ) ) {
				return $null;
			}
			$cursor =& $cursor[ $index ]['innerBlocks'];
		}

		return $null;
	}

	/**
	 * Parse a dot-separated block path.
	 *
	 * @return array<int,int>
	 */
	private static function quick_copy_edit_parse_path( string $path ): array {
		if ( ! preg_match( '/^\d+(?:\.\d+)*$/', $path ) ) {
			return array();
		}

		return array_map( 'absint', explode( '.', $path ) );
	}

	/**
	 * Parse a block path with an optional rich-text segment suffix.
	 *
	 * @return array{path:array<int,int>,segment_index:?int}
	 */
	private static function quick_copy_edit_parse_item_path( string $path ): array {
		$segment_index = null;
		if ( preg_match( '/^(\d+(?:\.\d+)*):segment:(\d+)$/', $path, $match ) ) {
			$path          = (string) $match[1];
			$segment_index = absint( $match[2] );
		}

		return array(
			'path'          => self::quick_copy_edit_parse_path( $path ),
			'segment_index' => $segment_index,
		);
	}

	/**
	 * Whether this block can be edited by replacing plain text only.
	 */
	private static function quick_copy_edit_block_supported( string $block_name, string $html ): bool {
		if ( ! in_array( $block_name, array( 'core/paragraph', 'core/heading', 'core/list-item', 'core/button', 'generateblocks/headline', 'generateblocks/button' ), true ) ) {
			return false;
		}
		if ( '' === self::quick_copy_edit_plain_text( $html ) ) {
			return false;
		}

		if ( in_array( $block_name, array( 'core/button', 'generateblocks/button' ), true ) ) {
			return 1 === preg_match( '/<a\b[^>]*>[^<]*<\/a>/is', $html );
		}

		return 1 === preg_match( '/^<([a-z][a-z0-9]*)\b[^>]*>[^<]*<\/\1>$/is', trim( $html ) );
	}

	/**
	 * Replace text while preserving the wrapper element and attributes.
	 */
	private static function quick_copy_edit_replace_block_text( string $block_name, string $html, string $text ): string {
		$escaped = esc_html( $text );
		if ( in_array( $block_name, array( 'core/button', 'generateblocks/button' ), true ) ) {
			return (string) preg_replace( '/(<a\b[^>]*>)[^<]*(<\/a>)/is', '$1' . $escaped . '$2', $html, 1 );
		}

		return (string) preg_replace( '/^(<([a-z][a-z0-9]*)\b[^>]*>)[^<]*(<\/\2>)$/is', '$1' . $escaped . '$3', trim( $html ), 1 );
	}

	/**
	 * Replace one safe rich-text segment while preserving the block wrapper.
	 */
	private static function quick_copy_edit_replace_segment_text( string $block_name, string $html, int $segment_index, string $text ): string {
		if ( 'core/paragraph' !== $block_name ) {
			return '';
		}

		$segments = self::quick_copy_edit_rich_paragraph_segments( $html );
		if ( empty( $segments[ $segment_index ] ) ) {
			return '';
		}

		$escaped = esc_html( $text );
		if ( 0 === $segment_index ) {
			return (string) preg_replace_callback(
				self::quick_copy_edit_rich_paragraph_pattern(),
				static function ( array $match ) use ( $escaped ): string {
					return (string) $match['p_open'] . (string) $match['prefix'] . (string) $match['strong_open'] . $escaped . (string) $match['strong_close'] . (string) $match['between'] . (string) $match['body'] . (string) $match['p_close'];
				},
				trim( $html ),
				1
			);
		}

		if ( 1 === $segment_index ) {
			return (string) preg_replace_callback(
				self::quick_copy_edit_rich_paragraph_pattern(),
				static function ( array $match ) use ( $escaped ): string {
					return (string) $match['p_open'] . (string) $match['prefix'] . (string) $match['strong_open'] . (string) $match['strong_text'] . (string) $match['strong_close'] . (string) $match['between'] . $escaped . (string) $match['p_close'];
				},
				trim( $html ),
				1
			);
		}

		return '';
	}

	/**
	 * Return one segment item after a successful rich-text update.
	 *
	 * @param array<string,mixed> $block Parsed block.
	 * @param array<int,int>      $path  Stable path in parsed block tree.
	 */
	private static function quick_copy_edit_segment_item_by_index( array $block, array $path, int $segment_index ): array {
		foreach ( self::quick_copy_edit_segment_items_for_block( $block, $path ) as $item ) {
			if ( isset( $item['segment']['index'] ) && (int) $item['segment']['index'] === $segment_index ) {
				return $item;
			}
		}

		return array();
	}

	/**
	 * Pattern for a safe rich paragraph with a strong intro and body after break.
	 */
	private static function quick_copy_edit_rich_paragraph_pattern(): string {
		return '/^(?P<p_open><p\b[^>]*>)(?P<prefix>\s*)(?P<strong_open><strong\b[^>]*>)(?P<strong_text>[^<]*)(?P<strong_close><\/strong>)(?P<between>\s*<br\s*\/?>\s*)(?P<body>[^<]*)(?P<p_close><\/p>)$/is';
	}

	/**
	 * Safe editable segments from a paragraph that uses strong intro + break.
	 *
	 * @return array<int,array{type:string,text:string}>
	 */
	private static function quick_copy_edit_rich_paragraph_segments( string $html ): array {
		if ( ! preg_match( self::quick_copy_edit_rich_paragraph_pattern(), trim( $html ), $match ) ) {
			return array();
		}

		$strong_text = self::quick_copy_edit_plain_text( (string) $match['strong_text'] );
		$body_text   = self::quick_copy_edit_plain_text( (string) $match['body'] );
		if ( '' === $strong_text || '' === $body_text ) {
			return array();
		}

		return array(
			array(
				'type' => 'strong',
				'text' => $strong_text,
			),
			array(
				'type' => 'after_break',
				'text' => $body_text,
			),
		);
	}

	/**
	 * Wrap the paragraph body segment after a line break with quick-edit marker attributes.
	 *
	 * @param array<string,mixed> $item Editable item.
	 */
	private static function quick_copy_edit_wrap_rich_paragraph_after_break_segment( string $html, array $item ): string {
		return (string) preg_replace_callback(
			self::quick_copy_edit_rich_paragraph_pattern(),
			static function ( array $match ) use ( $item ): string {
				$body = (string) $match['body'];
				return (string) $match['p_open']
					. (string) $match['prefix']
					. (string) $match['strong_open']
					. (string) $match['strong_text']
					. (string) $match['strong_close']
					. (string) $match['between']
					. '<span' . Devenia_AI_Translations::quick_copy_edit_marker_attributes( $item ) . '>'
					. $body
					. '</span>'
					. (string) $match['p_close'];
			},
			trim( $html ),
			1
		);
	}

	/**
	 * Plain visible block text used by quick copy edit.
	 */
	private static function quick_copy_edit_plain_text( string $html ): string {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Stable optimistic-concurrency hash for one block's editable surface.
	 */
	private static function quick_copy_edit_hash( string $block_name, string $html, ?int $segment_index = null ): string {
		return hash( 'sha256', $block_name . "\n" . $html . "\n" . ( null === $segment_index ? 'block' : 'segment:' . $segment_index ) );
	}

	/**
	 * Human label for the quick edit list.
	 */
	private static function quick_copy_edit_block_label( string $block_name ): string {
		switch ( $block_name ) {
			case 'core/heading':
			case 'generateblocks/headline':
				return __( 'Heading', 'ai-translation-workflow' );
			case 'core/button':
			case 'generateblocks/button':
				return __( 'Button', 'ai-translation-workflow' );
			case 'core/list-item':
				return __( 'List item', 'ai-translation-workflow' );
			default:
				return __( 'Paragraph', 'ai-translation-workflow' );
		}
	}

	/**
	 * Human label for an editable rich-text segment.
	 */
	private static function quick_copy_edit_segment_label( string $segment_type ): string {
		if ( 'strong' === $segment_type ) {
			return __( 'Paragraph heading', 'ai-translation-workflow' );
		}
		if ( 'after_break' === $segment_type ) {
			return __( 'Paragraph body', 'ai-translation-workflow' );
		}

		return __( 'Paragraph', 'ai-translation-workflow' );
	}

	/**
	 * Check whether the current request is a translation of the configured posts page.
	 */
	public static function is_translated_posts_page_request(): bool {
		if ( is_admin() || ! is_singular( 'page' ) ) {
			return false;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id || ! self::is_translation_post( (int) $post_id ) ) {
			return false;
		}

		$posts_page_id = absint( get_option( 'page_for_posts' ) );
		if ( ! $posts_page_id ) {
			return false;
		}

		return self::source_id_for_context( (int) $post_id ) === $posts_page_id;
	}

	/**
	 * Return the clean URL for the current translated posts page.
	 */
	private static function translated_posts_page_base_url(): string {
		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return '';
		}

		$base_url = get_permalink( (int) $post_id );

		return $base_url ? (string) $base_url : '';
	}

	/**
	 * Build a translated posts-page URL without creating a duplicate page-1 query URL.
	 */
	private static function translated_posts_page_url( string $base_url, int $page ): string {
		if ( $page <= 1 ) {
			return $base_url;
		}

		return add_query_arg( 'devenia_blog_page', $page, $base_url );
	}

	/**
	 * Localized archive meta descriptions for translated posts pages.
	 */
	private static function translated_posts_page_meta_description( string $language ): string {
		$descriptions = array(
			'ar'    => 'مقالات وأدلة وتحديثات حول المواقع وSEO والتقنية.',
			'da'    => 'Artikler, guider og opdateringer om websites, SEO og teknik.',
			'de'    => 'Artikel, Leitfäden und Updates zu Websites, SEO und Technik.',
			'es'    => 'Artículos, guías y novedades sobre sitios web, SEO y tecnología.',
				'fi'    => 'Artikkeleita, oppaita ja päivityksiä verkkosivuista, SEO:sta ja teknologiasta.',
			'fr'    => 'Articles, guides et actualités sur les sites web, le SEO et la technologie.',
			'it'    => 'Articoli, guide e aggiornamenti su siti web, SEO e tecnologia.',
			'ja'    => 'Webサイト、SEO、テクノロジーに関する記事、ガイド、最新情報。',
			'nb'    => 'Artikler, guider og oppdateringer om nettsteder, SEO og teknologi.',
			'nl'    => 'Artikelen, gidsen en updates over websites, SEO en technologie.',
			'pt-br' => 'Artigos, guias e novidades sobre sites, SEO e tecnologia.',
			'pt-pt' => 'Artigos, guias e novidades sobre sites, SEO e tecnologia.',
			'sv'    => 'Artiklar, guider och uppdateringar om webbplatser, SEO och teknik.',
			'vi'    => 'Bài viết, hướng dẫn và cập nhật về website, SEO và công nghệ.',
			'zh'    => '关于网站、SEO 和技术的文章、指南与更新。',
		);

		$language = sanitize_key( $language );

			return $descriptions[ $language ] ?? 'Articles, guides and updates about websites, SEO and technology.';
	}

	/**
	 * Build the query used by translated blog archive templates.
	 */
	public static function translated_posts_page_query(): WP_Query {
		$page_from_query = max( absint( get_query_var( 'paged' ) ), absint( get_query_var( 'page' ) ) );
		$page_from_get   = absint( filter_input( INPUT_GET, 'devenia_blog_page', FILTER_UNSAFE_RAW ) );
		$paged           = max( 1, $page_from_query, $page_from_get );
		$language        = self::frontend_language();
		self::$translated_posts_page_loop_language = $language;
		$post_ids        = self::translated_posts_page_visible_post_ids( $language );

		return new WP_Query(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'post__in'            => $post_ids ?: array( 0 ),
				'paged'               => $paged,
				'orderby'             => 'post__in',
				'ignore_sticky_posts' => false,
			)
		);
	}

	/**
	 * Return posts visible on a translated blog archive.
	 *
	 * A language archive should show that language's translated posts where they
	 * exist, plus source-language posts that do not yet have a published
	 * translation in that language. It must not show other languages' translations.
	 *
	 * @return array<int,int>
	 */
	private static function translated_posts_page_visible_post_ids( string $language ): array {
		return array_map(
			static function ( array $item ): int {
				return absint( $item['display_id'] ?? 0 );
			},
			self::translated_posts_page_archive_items( $language )
		);
	}

	/**
	 * Return the replacement/fallback rows for a translated blog archive.
	 *
	 * The source blog controls archive order by last modified date. For each published source post,
	 * show the published local translation when one exists in the requested
	 * language; otherwise show the source post. This keeps gradual rollout
	 * possible without leaking other languages into the archive.
	 *
	 * @return array<int,array{source_id:int,display_id:int,mode:string,source_modified_gmt:string}>
	 */
	private static function translated_posts_page_archive_items( string $language ): array {
		$language = sanitize_key( $language );
		if ( ! self::is_translation_language( $language ) ) {
			return array();
		}

		$translations_by_source = array();
		foreach ( self::translation_frontend_rows_for_language( $language, array( 'publish' ) ) as $row ) {
			$translation_id = absint( $row['id'] ?? 0 );
			$source_id      = absint( $row['source_id'] ?? 0 );
			if ( ! $translation_id || ! $source_id ) {
				continue;
			}

			if ( 'post' !== get_post_type( $translation_id ) || 'post' !== get_post_type( $source_id ) ) {
				continue;
			}

			$translations_by_source[ $source_id ] = $translation_id;
		}

		$source_args = array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => false,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Narrow archive source/translation separation.
				array(
				'key'     => self::META_SOURCE_ID,
				'compare' => 'NOT EXISTS',
			),
			),
		);
		$source_query = new WP_Query( $source_args );
		$items        = array();
		foreach ( array_map( 'absint', $source_query->posts ) as $source_id ) {
			$translation_id = absint( $translations_by_source[ $source_id ] ?? 0 );
			$items[] = array(
				'source_id'       => $source_id,
				'display_id'      => $translation_id ?: $source_id,
				'mode'            => $translation_id ? 'local_translation' : 'source_fallback',
				'source_modified_gmt' => (string) get_post_field( 'post_modified_gmt', $source_id ),
			);
		}

		return $items;
	}

	/**
	 * Keep the source-language blog archive scoped to source posts.
	 *
	 * Translated posts are ordinary WordPress posts so their single permalinks
	 * work naturally. The source `/blog/` archive must still behave as the
	 * English/source archive and exclude translated post objects.
	 */
	public static function filter_source_blog_archive_query( WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_home() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		if ( $post_type && 'post' !== $post_type ) {
			return;
		}

		$meta_query = $query->get( 'meta_query' );
		if ( ! is_array( $meta_query ) ) {
			$meta_query = array();
		}

		$meta_query[] = array(
			'key'     => self::META_SOURCE_ID,
			'compare' => 'NOT EXISTS',
		);

		$query->set( 'meta_query', $meta_query );
		$query->set( 'orderby', 'modified' );
		$query->set( 'order', 'DESC' );
	}

	/**
	 * Temporarily make theme template parts treat this request as the posts page.
	 *
	 * @return array<string,bool>
	 */
	public static function enter_translated_posts_page_loop_context(): array {
		global $wp_query;

		if ( ! $wp_query instanceof WP_Query ) {
			return array();
		}

		$state = array(
			'is_home'              => (bool) $wp_query->is_home,
			'is_archive'           => (bool) $wp_query->is_archive,
			'is_singular'          => (bool) $wp_query->is_singular,
			'is_page'              => (bool) $wp_query->is_page,
			'is_post_type_archive' => (bool) $wp_query->is_post_type_archive,
		);

		$wp_query->is_home              = true;
		$wp_query->is_archive           = false;
		$wp_query->is_singular          = false;
		$wp_query->is_page              = false;
		$wp_query->is_post_type_archive = false;

		return $state;
	}

	/**
	 * Restore query flags changed by enter_translated_posts_page_loop_context().
	 *
	 * @param array<string,bool> $state Previous query flag values.
	 */
	public static function leave_translated_posts_page_loop_context( array $state ): void {
		global $wp_query;

		if ( ! $wp_query instanceof WP_Query || empty( $state ) ) {
			return;
		}

		foreach ( $state as $key => $value ) {
			if ( property_exists( $wp_query, $key ) ) {
				$wp_query->{$key} = (bool) $value;
			}
		}
	}

	/**
	 * Render one post in the translated blog archive loop.
	 */
	public static function render_translated_posts_page_article(): void {
		?>
		<article id="post-<?php the_ID(); ?>" <?php post_class(); ?> itemtype="https://schema.org/CreativeWork" itemscope>
			<div class="inside-article">
				<?php if ( has_post_thumbnail() ) : ?>
					<div class="post-image">
						<a href="<?php the_permalink(); ?>">
							<?php the_post_thumbnail( 'medium', array( 'itemprop' => 'image' ) ); ?>
						</a>
					</div>
				<?php endif; ?>

				<header class="entry-header">
					<h2 class="entry-title" itemprop="headline"><a href="<?php the_permalink(); ?>" rel="bookmark"><?php the_title(); ?></a></h2>
					<?php self::render_blog_archive_updated_on( self::translated_posts_page_loop_language() ); ?>
				</header>

				<div class="entry-summary" itemprop="text">
					<?php the_excerpt(); ?>
				</div>

				<footer class="entry-meta" aria-label="<?php echo esc_attr__( 'Entry meta', 'ai-translation-workflow' ); ?>">
					<span class="cat-links"><span class="screen-reader-text"><?php echo esc_html__( 'Categories', 'ai-translation-workflow' ); ?> </span><?php echo wp_kses_post( get_the_category_list( ', ' ) ); ?></span>
				</footer>
			</div>
		</article>
		<?php
	}

	/**
	 * Print generic RTL layout corrections for translated pages.
	 */
	public static function print_rtl_layout_styles(): void {
		$language = self::frontend_language();
		if ( ! self::is_rtl_language( $language ) ) {
			return;
		}

		?>
		<style id="devenia-ai-translations-rtl-layout-css">
			:root[dir="rtl"] .entry-content .gb-container[class*="dv-"][class*="-hero-side"] {
				padding-right: 28px !important;
				padding-left: 0 !important;
				border-right: 1px solid rgba(255,244,232,0.18) !important;
				border-left-width: 0 !important;
			}
			:root[dir="rtl"] .entry-content .gb-container[class*="dv-"][class*="-split-side"],
			:root[dir="rtl"] .entry-content .gb-container[class*="dv-"][class*="-contact-right"] {
				padding-right: 26px !important;
				padding-left: 0 !important;
				border-right: 1px solid rgba(255,248,240,0.16) !important;
				border-left-width: 0 !important;
			}
			@media (max-width: 767px) {
				:root[dir="rtl"] .entry-content .gb-grid-wrapper[class*="dv-"] {
					margin-right: 0 !important;
					margin-left: 0 !important;
				}
				:root[dir="rtl"] .entry-content .gb-grid-wrapper[class*="dv-"] > .gb-grid-column {
					padding-right: 0 !important;
					padding-left: 0 !important;
				}
				:root[dir="rtl"] .entry-content .gb-container[class*="dv-"][class*="-hero-side"],
				:root[dir="rtl"] .entry-content .gb-container[class*="dv-"][class*="-split-side"],
				:root[dir="rtl"] .entry-content .gb-container[class*="dv-"][class*="-contact-right"] {
					padding-right: 0 !important;
					border-right-width: 0 !important;
				}
			}
		</style>
		<?php
	}

	/**
	 * Print the GeneratePress archive image rule that GP omits for page-backed translated blog URLs.
	 */
	public static function print_translated_posts_page_styles(): void {
		if ( ! self::is_translated_posts_page_request() ) {
			return;
		}

		?>
		<style id="devenia-ai-translations-blog-archive-css">
			@media (min-width: 769px) {
				.devenia-translated-posts-page.post-image-aligned-left .inside-article .post-image {
					float: left;
					margin-right: 2em;
					margin-top: 0;
					text-align: left;
				}
			}
			:root[dir="rtl"] body.devenia-translated-posts-page .entry-meta .cat-links {
				display: inline-flex;
				align-items: baseline;
				gap: 0.35em;
				flex-wrap: wrap;
				max-width: 100%;
				min-width: 0;
			}
			:root[dir="rtl"] body.devenia-translated-posts-page .entry-meta .cat-links a {
				overflow-wrap: anywhere;
			}
			:root[dir="rtl"] body.devenia-translated-posts-page .entry-meta .cat-links:before {
				position: static;
				margin: 0 0 0 0.35em;
				flex: 0 0 auto;
			}
			:root[dir="rtl"] body.devenia-translated-posts-page .gb-grid-wrapper-dv-blog-hero-grid,
			:root[dir="rtl"] body.devenia-translated-posts-page .gb-grid-wrapper-dv-blog-contact-grid {
				margin-left: 0;
				margin-right: -54px;
			}
			:root[dir="rtl"] body.devenia-translated-posts-page .gb-grid-wrapper-dv-blog-contact-grid {
				margin-right: -52px;
			}
			:root[dir="rtl"] body.devenia-translated-posts-page .gb-grid-wrapper-dv-blog-hero-grid > .gb-grid-column,
			:root[dir="rtl"] body.devenia-translated-posts-page .gb-grid-wrapper-dv-blog-contact-grid > .gb-grid-column {
				padding-left: 0;
				padding-right: 54px;
			}
			:root[dir="rtl"] body.devenia-translated-posts-page .gb-grid-wrapper-dv-blog-contact-grid > .gb-grid-column {
				padding-right: 52px;
			}
			:root[dir="rtl"] body.devenia-translated-posts-page .gb-container-dv-blog-hero-side {
				padding-right: 28px;
				padding-left: 0;
				border-right: 1px solid rgba(255,244,232,0.18);
				border-left: 0;
			}
			:root[dir="rtl"] body.devenia-translated-posts-page .gb-container-dv-blog-contact-right {
				padding-right: 26px;
				padding-left: 0;
				border-right: 1px solid rgba(255,248,240,0.16);
				border-left: 0;
			}
			@media (max-width: 767px) {
				:root[dir="rtl"] body.devenia-translated-posts-page .gb-grid-wrapper-dv-blog-hero-grid,
				:root[dir="rtl"] body.devenia-translated-posts-page .gb-grid-wrapper-dv-blog-contact-grid {
					margin-right: 0;
					margin-left: 0;
					max-width: 100%;
					width: 100%;
					box-sizing: border-box;
				}
				:root[dir="rtl"] body.devenia-translated-posts-page .gb-grid-wrapper-dv-blog-hero-grid > .gb-grid-column,
				:root[dir="rtl"] body.devenia-translated-posts-page .gb-grid-wrapper-dv-blog-contact-grid > .gb-grid-column {
					padding-right: 0;
					padding-left: 0;
					box-sizing: border-box;
				}
				:root[dir="rtl"] body.devenia-translated-posts-page .gb-container-dv-blog-hero-side,
				:root[dir="rtl"] body.devenia-translated-posts-page .gb-container-dv-blog-contact-right {
					padding-right: 0;
					border-right-width: 0;
				}
			}
		</style>
		<?php
	}

	/**
	 * Render source blog archive updated-on markup through GeneratePress.
	 */
	public static function render_source_blog_archive_updated_on(): void {
		if ( is_admin() || ! is_home() || ! in_the_loop() || ! is_main_query() ) {
			return;
		}

		self::render_blog_archive_updated_on( 'en' );
	}

	/**
	 * Current translated posts-page loop language.
	 */
	private static function translated_posts_page_loop_language(): string {
		$language = sanitize_key( self::$translated_posts_page_loop_language );
		return self::is_translation_language( $language ) ? $language : self::frontend_language();
	}

	/**
	 * Render a last-updated archive meta row for source and translated blog archives.
	 */
	private static function render_blog_archive_updated_on( string $language ): void {
		$modified_timestamp = absint( get_the_modified_time( 'U' ) );
		if ( ! $modified_timestamp ) {
			return;
		}

		$modified_attr  = get_the_modified_date( 'c' );
		$modified_label = self::translated_posts_page_date_label( $modified_timestamp, $language );
		$label          = self::blog_archive_updated_label( $language );
		if ( '' === $modified_label ) {
			return;
		}
		?>
		<div class="entry-meta">
			<span class="updated-on">
				<span class="screen-reader-text"><?php echo esc_html( $label ); ?> </span>
				<span aria-hidden="true"><?php echo esc_html( $label ); ?> </span>
				<time class="updated" datetime="<?php echo esc_attr( $modified_attr ); ?>" itemprop="dateModified"><?php echo esc_html( $modified_label ); ?></time>
			</span>
		</div>
		<?php
	}

	/**
	 * Localized last-updated label for blog archive cards.
	 */
	private static function blog_archive_updated_label( string $language ): string {
		$labels = array(
			'en' => 'Last updated:',
			'nb' => 'Sist oppdatert:',
			'de' => 'Zuletzt aktualisiert:',
			'fr' => 'Mis à jour :',
			'es' => 'Última actualización:',
			'sv' => 'Senast uppdaterad:',
			'da' => 'Senest opdateret:',
			'fi' => 'Päivitetty viimeksi:',
			'ar' => 'آخر تحديث:',
			'it' => 'Ultimo aggiornamento:',
			'nl' => 'Laatst bijgewerkt:',
		);

		return $labels[ sanitize_key( $language ) ] ?? $labels['en'];
	}

	/**
	 * Localized date label for translated blog archive posts.
	 */
	private static function translated_posts_page_date_label( int $timestamp, string $language = '' ): string {
		if ( $timestamp <= 0 ) {
			return '';
		}

		$language = '' !== $language ? sanitize_key( $language ) : self::frontend_language();

		$locale   = self::wordpress_locale_for_context( (int) get_queried_object_id() );
		$switched = '' !== $locale && function_exists( 'switch_to_locale' ) && switch_to_locale( $locale );
		try {
			return self::localized_short_date_label( $timestamp, $language, $locale );
		} finally {
			if ( $switched && function_exists( 'restore_previous_locale' ) ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Local short date for blog archive meta.
	 */
	private static function localized_short_date_label( int $timestamp, string $language, string $locale ): string {
		if ( 'ar' === sanitize_key( $language ) ) {
			return self::arabic_short_date_label( $timestamp );
		}

		$formats = array(
			'en' => 'd/m/Y',
			'nb' => 'd.m.Y',
			'de' => 'd.m.Y',
			'fr' => 'd/m/Y',
			'es' => 'd/m/Y',
			'sv' => 'Y-m-d',
			'da' => 'd.m.Y',
			'fi' => 'j.n.Y',
			'it' => 'd/m/Y',
			'nl' => 'd-m-Y',
		);

		$language = sanitize_key( $language );
		if ( isset( $formats[ $language ] ) ) {
			return wp_date( $formats[ $language ], $timestamp, wp_timezone() );
		}

		$intl_date = self::intl_short_date_label( $timestamp, $locale );
		if ( '' !== $intl_date ) {
			return $intl_date;
		}

		return wp_date( get_option( 'date_format' ), $timestamp, wp_timezone() );
	}

	/**
	 * Arabic short date with Arabic-Indic digits and Arabic month names.
	 */
	private static function arabic_short_date_label( int $timestamp ): string {
		$months = array(
			1  => 'يناير',
			2  => 'فبراير',
			3  => 'مارس',
			4  => 'أبريل',
			5  => 'مايو',
			6  => 'يونيو',
			7  => 'يوليو',
			8  => 'أغسطس',
			9  => 'سبتمبر',
			10 => 'أكتوبر',
			11 => 'نوفمبر',
			12 => 'ديسمبر',
		);
		$month = (int) wp_date( 'n', $timestamp, wp_timezone() );
		$day   = wp_date( 'j', $timestamp, wp_timezone() );
		$year  = wp_date( 'Y', $timestamp, wp_timezone() );

		return self::arabic_indic_digits( $day ) . ' ' . ( $months[ $month ] ?? '' ) . ' ' . self::arabic_indic_digits( $year );
	}

	/**
	 * Locale-aware short date fallback for future languages when PHP intl is available.
	 */
	private static function intl_short_date_label( int $timestamp, string $locale ): string {
		if ( '' === $locale || ! class_exists( 'IntlDateFormatter' ) ) {
			return '';
		}

		$timezone = wp_timezone();
		$formatter = new IntlDateFormatter(
			str_replace( '_', '-', $locale ),
			IntlDateFormatter::SHORT,
			IntlDateFormatter::NONE,
			$timezone->getName()
		);
		if ( ! $formatter ) {
			return '';
		}

		$date = $formatter->format( $timestamp );
		return is_string( $date ) ? $date : '';
	}

	/**
	 * Convert ASCII digits to Arabic-Indic digits.
	 */
	private static function arabic_indic_digits( string $text ): string {
		return strtr(
			$text,
			array(
				'0' => '٠',
				'1' => '١',
				'2' => '٢',
				'3' => '٣',
				'4' => '٤',
				'5' => '٥',
				'6' => '٦',
				'7' => '٧',
				'8' => '٨',
				'9' => '٩',
			)
		);
	}

	/**
	 * Render archive pagination for translated blog pages.
	 */
	public static function render_translated_posts_page_pagination( WP_Query $query ): void {
		if ( $query->max_num_pages < 2 ) {
			return;
		}

		$current  = max( 1, (int) $query->get( 'paged' ) );
		$base_url = get_permalink( get_queried_object_id() );
		if ( ! $base_url ) {
			return;
		}

		$older_url     = self::translated_posts_page_url( $base_url, min( $query->max_num_pages, $current + 1 ) );
		$page_one_url  = self::translated_posts_page_url( $base_url, 1 );
		$page_one_dupe = add_query_arg( 'devenia_blog_page', 1, $base_url );
		$links         = paginate_links(
			array(
				'base'      => esc_url_raw( add_query_arg( 'devenia_blog_page', '%#%', $base_url ) ),
				'format'    => '',
				'current'   => $current,
				'total'     => (int) $query->max_num_pages,
				'prev_text' => __( 'Previous', 'ai-translation-workflow' ),
				'next_text' => __( 'Next', 'ai-translation-workflow' ) . ' <span aria-hidden="true">&rarr;</span>',
				'type'      => 'plain',
				'mid_size'  => 1,
				'end_size'  => 1,
				'before_page_number' => '<span class="screen-reader-text">' . esc_html__( 'Page', 'ai-translation-workflow' ) . '</span>',
			)
		);
		if ( $links && $page_one_url !== $page_one_dupe ) {
			$links = str_replace( esc_url( $page_one_dupe ), esc_url( $page_one_url ), $links );
		}

		echo '<nav id="nav-below" class="paging-navigation" aria-label="' . esc_attr__( 'Archive Page', 'ai-translation-workflow' ) . '">';
		if ( $current < (int) $query->max_num_pages ) {
			echo '<div class="nav-previous"><span class="prev" title="' . esc_attr__( 'Previous', 'ai-translation-workflow' ) . '"><a href="' . esc_url( $older_url ) . '">' . esc_html__( 'Older posts', 'ai-translation-workflow' ) . '</a></span></div>';
		}
		if ( $links ) {
			echo '<div class="nav-links">' . wp_kses_post( $links ) . '</div>';
		}
		echo '</nav>';
	}

	/**
	 * Source ID for source or translated page context.
	 */
	private static function source_id_for_context( int $post_id ): int {
		$indexed = self::translation_index_row_for_translation( $post_id );
		if ( ! empty( $indexed['source_id'] ) ) {
			return absint( $indexed['source_id'] );
		}

		$source_id = absint( get_post_meta( $post_id, self::META_SOURCE_ID, true ) );
		return $source_id ?: $post_id;
	}

	/**
	 * Check if page is a translation.
	 */
	private static function is_translation_post( int $post_id ): bool {
		if ( ! empty( self::translation_index_row_for_translation( $post_id ) ) ) {
			return true;
		}

		return (bool) get_post_meta( $post_id, self::META_LANGUAGE, true );
	}

	/**
	 * Translation rows for a source page.
	 */
	private static function translation_rows_for_source( int $source_id, array $post_status = array( 'publish', 'draft', 'pending', 'private' ) ): array {
		static $cache = array();

		$status_key = implode( '|', array_map( 'sanitize_key', $post_status ) );
		$cache_key  = $source_id . ':' . $status_key;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$translation_ids = self::translation_index_ids_for_source( $source_id, $post_status );
		if ( ! empty( $translation_ids ) ) {
			$rows = array();
			foreach ( $translation_ids as $translation_id ) {
				$post = get_post( $translation_id );
				if ( $post ) {
					$rows[] = self::translation_payload( $post );
				}
			}
			$cache[ $cache_key ] = $rows;
			return $cache[ $cache_key ];
		}

		$query = self::translation_page_query(
			array(
				'post_status'    => $post_status,
				'posts_per_page' => 1000,
			)
		);

		$rows = array();
		foreach ( $query->posts as $post ) {
			if ( $source_id !== absint( get_post_meta( $post->ID, self::META_SOURCE_ID, true ) ) ) {
				continue;
			}
			$rows[] = self::translation_payload( $post );
		}

		$cache[ $cache_key ] = $rows;

		return $cache[ $cache_key ];
	}

	/**
	 * Source hash for stale detection.
	 */
	private static function source_hash( WP_Post $post ): string {
		return self::source_hash_from_values(
			(string) $post->post_title,
			(string) $post->post_excerpt,
			self::normalize_gutenberg_content_for_storage( (string) $post->post_content )
		);
	}

	/**
	 * Legacy raw source hash used before saved-markup normalization became the hash boundary.
	 */
	private static function legacy_source_hash( WP_Post $post ): string {
		return self::source_hash_from_values(
			(string) $post->post_title,
			(string) $post->post_excerpt,
			(string) $post->post_content
		);
	}

	/**
	 * Hash source fields with a single, stable field separator contract.
	 */
	private static function source_hash_from_values( string $title, string $excerpt, string $content ): string {
		return hash( 'sha256', $title . "\n" . $excerpt . "\n" . $content );
	}

	/**
	 * Move translations to the current normalized source hash after output-preserving repairs.
	 *
	 * @param array<int,string> $compatible_previous_hashes Hashes known to represent the same rendered source.
	 */
	private static function sync_translation_source_hashes_after_content_safety_repair( WP_Post $source, array $compatible_previous_hashes ): int {
		if ( self::is_translation_post( (int) $source->ID ) || ! self::is_translatable_post_type( (string) $source->post_type ) ) {
			return 0;
		}

		$current_hash = self::source_hash( $source );
		$compatible   = array();
		foreach ( $compatible_previous_hashes as $hash ) {
			$hash = is_string( $hash ) ? trim( $hash ) : '';
			if ( '' !== $hash && $hash !== $current_hash ) {
				$compatible[ $hash ] = true;
			}
		}
		if ( empty( $compatible ) ) {
			return 0;
		}

		$synced = 0;
		foreach ( self::translation_rows_for_source( (int) $source->ID ) as $translation ) {
			$translation_id = absint( $translation['id'] ?? 0 );
			$stored_hash    = isset( $translation['source_hash'] ) ? trim( (string) $translation['source_hash'] ) : '';
			if ( ! $translation_id || '' === $stored_hash || $stored_hash === $current_hash || empty( $compatible[ $stored_hash ] ) ) {
				continue;
			}

			update_post_meta( $translation_id, self::META_SOURCE_HASH, $current_hash );
			self::sync_translation_index_row( $translation_id );
			++$synced;
		}

		return $synced;
	}

	/**
	 * Repair source-hash drift after an earlier output-preserving content-safety repair.
	 */
	private static function sync_translation_source_hashes_after_recent_content_safety_repair( WP_Post $source ): int {
		if ( self::is_translation_post( (int) $source->ID ) || ! self::is_translatable_post_type( (string) $source->post_type ) ) {
			return 0;
		}

		$current_hash = self::source_hash( $source );
		$wanted       = array();
		foreach ( self::translation_rows_for_source( (int) $source->ID ) as $translation ) {
			$stored_hash = isset( $translation['source_hash'] ) ? trim( (string) $translation['source_hash'] ) : '';
			if ( '' !== $stored_hash && $stored_hash !== $current_hash ) {
				$wanted[ $stored_hash ] = true;
			}
		}
		if ( empty( $wanted ) ) {
			return 0;
		}

		$current_normalized_content = self::normalize_gutenberg_content_for_storage( (string) $source->post_content );
		$compatible_hashes          = array();
		$revisions                  = wp_get_post_revisions(
			(int) $source->ID,
			array(
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		foreach ( $revisions as $revision ) {
			if ( ! $revision instanceof WP_Post ) {
				continue;
			}
			if ( self::normalize_gutenberg_content_for_storage( (string) $revision->post_content ) !== $current_normalized_content ) {
				continue;
			}

			foreach ( array( self::legacy_source_hash( $revision ), self::source_hash( $revision ) ) as $hash ) {
				if ( isset( $wanted[ $hash ] ) ) {
					$compatible_hashes[] = $hash;
				}
			}
		}

		return self::sync_translation_source_hashes_after_content_safety_repair( $source, $compatible_hashes );
	}

	/**
	 * Keep translated content on the same WordPress presentation path as the source.
	 */
	private static function sync_source_presentation_meta( int $translation_id, WP_Post $source ): array {
		$result = array(
			'featured_image' => self::sync_source_featured_image( $translation_id, $source ),
			'page_template'  => array(
				'changed' => false,
				'skipped' => 'page' !== $source->post_type,
			),
			'generatepress_meta' => array(
				'updated' => array(),
				'deleted' => array(),
			),
		);

		if ( 'page' === $source->post_type ) {
			$template = get_page_template_slug( $source );
			$before_template = (string) get_post_meta( $translation_id, '_wp_page_template', true );
			if ( $template ) {
				update_post_meta( $translation_id, '_wp_page_template', $template );
			} else {
				delete_post_meta( $translation_id, '_wp_page_template' );
			}
			$result['page_template'] = array(
				'changed' => $before_template !== (string) $template,
				'before'  => $before_template,
				'after'   => (string) $template,
			);
		}

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
			$value = get_post_meta( $source->ID, $meta_key, true );
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
	 * Mirror the source featured image onto a translation.
	 */
	private static function sync_source_featured_image( int $translation_id, WP_Post $source, bool $dry_run = false ): array {
		$source_thumbnail_id      = absint( get_post_thumbnail_id( $source ) );
		$translation_thumbnail_id = absint( get_post_thumbnail_id( $translation_id ) );
		$changed                  = $source_thumbnail_id !== $translation_thumbnail_id;

		if ( $changed && ! $dry_run ) {
			if ( $source_thumbnail_id ) {
				update_post_meta( $translation_id, '_thumbnail_id', $source_thumbnail_id );
			} else {
				delete_post_meta( $translation_id, '_thumbnail_id' );
			}
			clean_post_cache( $translation_id );
		}

		return array(
			'changed'             => $changed,
			'before_thumbnail_id' => $translation_thumbnail_id,
			'after_thumbnail_id'  => $source_thumbnail_id,
		);
	}

	/**
	 * Basic post payload.
	 */
	private static function post_payload( WP_Post $post ): array {
		return array(
			'id'       => (int) $post->ID,
			'post_type'=> (string) $post->post_type,
			'title'    => get_the_title( $post ),
			'slug'     => $post->post_name,
			'status'   => $post->post_status,
			'url'      => get_permalink( $post ),
			'content'  => $post->post_content,
			'excerpt'  => $post->post_excerpt,
			'modified' => $post->post_modified_gmt,
			'featured_image_id' => absint( get_post_thumbnail_id( $post ) ),
			'taxonomies' => self::post_taxonomy_payload( $post ),
			'source_generation' => self::source_generation_status_for_source( (int) $post->ID ),
		);
	}

	/**
	 * Compact source payload for queue listings.
	 */
	private static function source_summary_payload( WP_Post $post ): array {
		return array(
			'id'       => (int) $post->ID,
			'post_type'=> (string) $post->post_type,
			'title'    => get_the_title( $post ),
			'slug'     => $post->post_name,
			'status'   => $post->post_status,
			'url'      => get_permalink( $post ),
			'modified' => $post->post_modified_gmt,
			'featured_image_id' => absint( get_post_thumbnail_id( $post ) ),
			'taxonomies' => self::post_taxonomy_payload( $post ),
		);
	}

	/**
	 * Translation payload.
	 */
	private static function translation_payload( ?WP_Post $post ): array {
		if ( ! $post ) {
			return array();
		}

		$source_id = absint( get_post_meta( $post->ID, self::META_SOURCE_ID, true ) );
		$source    = $source_id ? get_post( $source_id ) : null;
		$hash      = (string) get_post_meta( $post->ID, self::META_SOURCE_HASH, true );
		$current   = $source ? self::source_hash( $source ) : '';
		$linguistic_review_state = self::linguistic_review_state_for_post( (int) $post->ID );
		$generated_source_id = absint( get_post_meta( $post->ID, self::META_GENERATED_SOURCE_ID, true ) );

		return array(
			'id'                 => (int) $post->ID,
			'post_type'          => (string) $post->post_type,
			'source_id'          => $source_id,
			'language'           => (string) get_post_meta( $post->ID, self::META_LANGUAGE, true ),
			'title'              => get_the_title( $post ),
			'slug'               => $post->post_name,
			'status'             => $post->post_status,
			'translation_status' => (string) get_post_meta( $post->ID, self::META_STATUS, true ),
			'url'                => get_permalink( $post ),
			'featured_image_id'  => absint( get_post_thumbnail_id( $post ) ),
			'localized_path'     => (string) get_post_meta( $post->ID, self::META_LOCALIZED_PATH, true ),
			'source_hash'        => $hash,
			'current_source_hash'=> $current,
			'is_stale'           => $hash && $current && $hash !== $current,
			'reviewed_at'        => (string) get_post_meta( $post->ID, self::META_REVIEWED_AT, true ),
			'linguistic_reviewed_at' => (string) get_post_meta( $post->ID, self::META_LINGUISTIC_REVIEWED_AT, true ),
			'linguistic_reviewer'    => (string) get_post_meta( $post->ID, self::META_LINGUISTIC_REVIEWER, true ),
			'linguistic_review_note' => (string) get_post_meta( $post->ID, self::META_LINGUISTIC_REVIEW_NOTE, true ),
			'linguistic_review_checks' => self::linguistic_review_checks_for_post( $post->ID ),
			'linguistic_review_evidence' => self::linguistic_review_evidence_for_post( $post->ID ),
			'linguistic_review_state' => $linguistic_review_state,
			'quality_reviewed_at' => (string) get_post_meta( $post->ID, self::META_QUALITY_REVIEWED_AT, true ),
			'quality_reviewer'    => (string) get_post_meta( $post->ID, self::META_QUALITY_REVIEWER, true ),
			'quality_review_note' => (string) get_post_meta( $post->ID, self::META_QUALITY_REVIEW_NOTE, true ),
			'quality_review_checks' => self::quality_review_checks_for_post( $post->ID ),
			'quality_review_evidence' => self::quality_review_evidence_for_post( $post->ID ),
			'copy_feedback_open_count' => count( self::open_copy_feedback_for_post( (int) $post->ID ) ),
			'copy_feedback' => self::copy_feedback_for_post( (int) $post->ID ),
			'taxonomies'         => self::post_taxonomy_payload( $post ),
			'authored_original'  => array(
				'is_authored_original' => $generated_source_id > 0,
				'generated_source_id'  => $generated_source_id,
				'authored_language'    => sanitize_key( (string) get_post_meta( $post->ID, self::META_AUTHORED_LANGUAGE, true ) ),
				'source_generation_status' => $generated_source_id ? self::source_generation_status_for_source( $generated_source_id ) : array(),
			),
		);
	}

	/**
	 * Category and tag payload for posts.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private static function post_taxonomy_payload( WP_Post $post ): array {
		if ( 'post' !== $post->post_type ) {
			return array();
		}

		$out = array();
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$terms = wp_get_post_terms( (int) $post->ID, $taxonomy, array( 'hide_empty' => false ) );
			if ( is_wp_error( $terms ) ) {
				$out[ $taxonomy ] = array();
				continue;
			}
			$out[ $taxonomy ] = array_map(
				static function ( WP_Term $term ): array {
					return array(
						'id'             => (int) $term->term_id,
						'name'           => (string) $term->name,
						'slug'           => (string) $term->slug,
						'taxonomy'       => (string) $term->taxonomy,
						'parent'         => (int) $term->parent,
						'source_term_id' => absint( get_term_meta( (int) $term->term_id, self::TERM_META_SOURCE_ID, true ) ),
						'language'       => sanitize_key( (string) get_term_meta( (int) $term->term_id, self::TERM_META_LANGUAGE, true ) ),
					);
				},
				is_array( $terms ) ? $terms : array()
			);
		}

		return $out;
	}

	/**
	 * Localized path for a post.
	 */
	private static function localized_path_for_post( int $post_id, string $language ): string {
		$post = get_post( $post_id );
		if ( $post && 'post' === $post->post_type ) {
			$stored = trim( (string) get_post_meta( $post_id, self::META_LOCALIZED_PATH, true ), '/' );
			if ( '' !== $stored ) {
				return $stored;
			}

			$prefix = self::language_prefix( $language );
			$blog_path = self::localized_blog_base_path( $language );
			$slug = $post->post_name ?: sanitize_title( get_the_title( $post ) );
			if ( '' !== $blog_path && '' !== $slug ) {
				return trim( $blog_path . '/' . $slug, '/' );
			}
			if ( $prefix && '' !== $slug ) {
				return trim( $prefix . '/blog/' . $slug, '/' );
			}
		}

		if ( $post && 'page' === $post->post_type ) {
			$path   = trim( get_page_uri( $post ), '/' );
			$prefix = self::language_prefix( $language );

			if ( $prefix && '' !== $path && 0 !== strpos( $path, $prefix . '/' ) && $path !== $prefix ) {
				return trim( $prefix . '/' . $path, '/' );
			}

			return $path;
		}

		$url    = get_permalink( $post_id );
		$parsed = $url ? wp_parse_url( $url, PHP_URL_PATH ) : '';
		$path   = is_string( $parsed ) ? trim( $parsed, '/' ) : '';
		$prefix = self::language_prefix( $language );

		if ( $prefix && 0 !== strpos( $path, $prefix . '/' ) && $path !== $prefix ) {
			return trim( $prefix . '/' . $path, '/' );
		}

		return $path;
	}

	/**
	 * Localized blog archive path used as the default parent path for translated posts.
	 */
	private static function localized_blog_base_path( string $language ): string {
		$languages = self::languages();
		$config    = isset( $languages[ $language ] ) && is_array( $languages[ $language ] ) ? $languages[ $language ] : array();
		$blog_path = isset( $config['blog_path'] ) ? trim( sanitize_text_field( (string) $config['blog_path'] ), '/' ) : '';
		if ( '' !== $blog_path ) {
			return $blog_path;
		}

		$detected = self::detect_localized_blog_base_path( $language );
		if ( '' !== $detected ) {
			return $detected;
		}

		$prefix = self::language_prefix( $language );
		return $prefix ? trim( $prefix . '/blog', '/' ) : 'blog';
	}

	/**
	 * Public blog archive URLs affected when translated post listings change.
	 *
	 * Translated archives replace source posts with local translations when they
	 * exist, while the source archive excludes translations. Publishing a post
	 * translation can therefore make every language archive stale.
	 *
	 * @return array<int,string>
	 */
	private static function localized_blog_archive_purge_urls(): array {
		$urls          = array();
		$posts_page_id = absint( get_option( 'page_for_posts' ) );

		if ( $posts_page_id ) {
			$posts_page_url = get_permalink( $posts_page_id );
			if ( $posts_page_url ) {
				$urls[] = (string) $posts_page_url;
			}
		}

		$urls[] = home_url( '/blog/' );

		foreach ( array_keys( self::languages() ) as $language ) {
			$path = self::localized_blog_base_path( (string) $language );
			if ( '' === $path ) {
				continue;
			}

			$urls[] = home_url( '/' . trim( $path, '/' ) . '/' );
		}

		$urls = array_filter(
			array_map(
				static function ( $url ): string {
					return esc_url_raw( (string) $url );
				},
				$urls
			)
		);

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Detect the localized blog archive path from the translated posts page.
	 */
	private static function detect_localized_blog_base_path( string $language ): string {
		$posts_page_id = absint( get_option( 'page_for_posts' ) );
		if ( ! $posts_page_id ) {
			return '';
		}

		if ( 'en' === sanitize_key( $language ) ) {
			$url = get_permalink( $posts_page_id );
		} else {
			$translation_id = self::find_translation_id( $posts_page_id, $language, array( 'publish', 'draft', 'pending', 'private' ) );
			$url = $translation_id ? get_permalink( $translation_id ) : '';
		}

		if ( $url ) {
			return trim( self::normalized_url_path( (string) $url ), '/' );
		}

		return '';
	}

	/**
	 * Localized category/tag archive base path for translated blog terms.
	 */
	private static function localized_taxonomy_base_path( string $language, string $taxonomy ): string {
		$blog_base = self::localized_blog_base_path( $language );
		if ( '' === $blog_base ) {
			return '';
		}

		$segment = self::localized_taxonomy_segment( $language, $taxonomy );
		return trim( $blog_base . '/' . $segment, '/' );
	}

	/**
	 * Localized category/tag URL segment from language registry data.
	 */
	private static function localized_taxonomy_segment( string $language, string $taxonomy ): string {
		$languages = self::languages();
		$config    = isset( $languages[ $language ] ) && is_array( $languages[ $language ] ) ? $languages[ $language ] : array();
		$paths     = isset( $config['blog_taxonomy_paths'] ) && is_array( $config['blog_taxonomy_paths'] ) ? $config['blog_taxonomy_paths'] : array();
		$key       = 'post_tag' === $taxonomy ? 'tag' : 'category';
		$segment   = isset( $paths[ $key ] ) ? sanitize_title( (string) $paths[ $key ] ) : '';
		if ( '' !== $segment ) {
			return $segment;
		}

		global $wp_rewrite;
		if ( 'post_tag' === $taxonomy ) {
			$base = is_object( $wp_rewrite ) && ! empty( $wp_rewrite->tag_base ) ? (string) $wp_rewrite->tag_base : 'tag';
		} else {
			$base = is_object( $wp_rewrite ) && ! empty( $wp_rewrite->category_base ) ? (string) $wp_rewrite->category_base : 'category';
		}

		return sanitize_title( basename( trim( $base, '/' ) ) );
	}

	/**
	 * Language prefix.
	 */
	private static function language_prefix( string $language ): string {
		$languages = self::languages();
		return isset( $languages[ $language ]['prefix'] ) ? sanitize_title( $languages[ $language ]['prefix'] ) : '';
	}

	/**
	 * Frontend home URL for a configured language.
	 */
	private static function localized_home_url_for_language( string $language ): string {
		$language = sanitize_key( $language );
		if ( '' === $language || 'en' === $language ) {
			return trailingslashit( home_url( '/' ) );
		}

		if ( ! self::is_translation_language( $language ) ) {
			return '';
		}

		$root_id = self::language_root_page_id( $language );
		if ( $root_id ) {
			$url = get_permalink( $root_id );
			return $url ? trailingslashit( (string) $url ) : '';
		}

		$prefix = self::language_prefix( $language );
		return '' !== $prefix ? home_url( '/' . trim( $prefix, '/' ) . '/' ) : '';
	}

	/**
	 * Language review profile for QA output.
	 */
	private static function language_review_profile( string $language ): array {
		return self::effective_language_review_profile( $language );
	}

	/**
	 * Expected HTML lang attribute value for a configured language.
	 */
	private static function html_lang_for_language( string $language ): string {
		$languages = self::languages();
		$locale    = isset( $languages[ $language ]['locale'] ) ? (string) $languages[ $language ]['locale'] : '';

		return str_replace( '_', '-', $locale );
	}

	/**
	 * Expected BCP 47 hreflang value for a configured language.
	 */
	private static function hreflang_for_language( string $language ): string {
		$html_lang = self::html_lang_for_language( $language );

		return '' !== $html_lang ? $html_lang : sanitize_key( $language );
	}

	/**
	 * Whether this language must use ASCII-transliterated public URL segments.
	 */
	private static function language_requires_transliterated_urls( string $language ): bool {
		$languages = self::languages();
		return ! empty( $languages[ $language ]['transliterate_urls'] );
	}

	/**
	 * Whether language is configured and not source.
	 */
	private static function is_translation_language( string $language ): bool {
		$languages = self::languages();
		return isset( $languages[ $language ] ) && empty( $languages[ $language ]['source'] );
	}

	/**
	 * Sanitize post status for controlled operations.
	 */
	private static function sanitize_post_status( string $status, string $fallback ): string {
		$allowed = array( 'draft', 'pending', 'private', 'publish' );
		return in_array( $status, $allowed, true ) ? $status : $fallback;
	}

	/**
	 * Sanitize translation status.
	 */
	private static function sanitize_translation_status( string $status ): string {
		$allowed = array( 'draft', 'needs_review', 'reviewed', 'published', 'stale' );
		return in_array( $status, $allowed, true ) ? $status : 'needs_review';
	}

	/**
	 * Publication date fields inherited by translated posts.
	 *
	 * A translated post is the same source work in another language, so its
	 * publication date should represent the source work's publication date.
	 *
	 * @return array<string,string>
	 */
	private static function source_publication_date_fields( WP_Post $source ): array {
		$post_date     = (string) $source->post_date;
		$post_date_gmt = (string) $source->post_date_gmt;

		if ( '' === $post_date_gmt || '0000-00-00 00:00:00' === $post_date_gmt ) {
			$post_date_gmt = get_gmt_from_date( $post_date );
		}

		return array(
			'post_date'     => $post_date,
			'post_date_gmt' => $post_date_gmt,
		);
	}

	/**
	 * Repair already-created translated posts so they share the source publication date.
	 *
	 * This runs as an upgrade repair. It updates only `post_date` and
	 * `post_date_gmt`, preserving each translation's own `post_modified` fields
	 * so last-updated ordering remains tied to the source archive model.
	 */
	private static function repair_translated_post_publication_dates(): void {
		$query = self::translation_page_query(
			array(
				'post_type'              => 'post',
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( array_map( 'absint', $query->posts ) as $translation_id ) {
			$source_id = absint( get_post_meta( $translation_id, self::META_SOURCE_ID, true ) );
			$source    = $source_id ? get_post( $source_id ) : null;
			if ( ! $source || 'post' !== (string) $source->post_type ) {
				continue;
			}

			$dates            = self::source_publication_date_fields( $source );
			$translation      = get_post( $translation_id );
			if ( ! $translation ) {
				continue;
			}
			$current_date     = (string) get_post_field( 'post_date', $translation_id );
			$current_date_gmt = (string) get_post_field( 'post_date_gmt', $translation_id );
			if ( $current_date === $dates['post_date'] && $current_date_gmt === $dates['post_date_gmt'] ) {
				continue;
			}

			$result = wp_update_post(
				array(
					'ID'            => $translation_id,
					'post_date'     => $dates['post_date'],
					'post_date_gmt' => $dates['post_date_gmt'],
					'post_modified' => (string) $translation->post_modified,
					'post_modified_gmt' => (string) $translation->post_modified_gmt,
					'edit_date'     => true,
				),
				true
			);

			if ( ! is_wp_error( $result ) ) {
				self::sync_translation_index_row( $translation_id );
			}
		}
	}

	/**
	 * Deep module for translation fitness.
	 *
	 * This is the stable Interface for translation quality gates. The
	 * Implementation can grow from deterministic guardrails to runtime profiles
	 * and external review evidence without making callers choose individual
	 * checks.
	 */
	private static function translation_fitness( string $content, string $source_content = '', string $language = '', string $title = '', string $excerpt = '', int $source_id = 0, array $profile_patch = array() ): array {
		$guardrails = self::translation_guardrails( $content, $source_content, $language, $title, $excerpt, $source_id, $profile_patch );
		$dimensions = array(
			'editor_integrity' => self::translation_fitness_dimension(
				$guardrails,
				array( 'gutenberg', 'shortcodes', 'source_structure', 'translation_integrity' )
			),
			'orthography' => self::translation_fitness_dimension(
				$guardrails,
				array( 'script_signals' )
			),
			'source_fidelity' => self::translation_fitness_dimension(
				$guardrails,
				array( 'source_carryover', 'source_structure', 'link_integrity' )
			),
			'locale_terminology' => self::translation_fitness_dimension(
				$guardrails,
				array( 'locale_terminology', 'address_form' )
			),
			'copy_quality' => self::translation_fitness_dimension(
				$guardrails,
				array( 'copy_quality' )
			),
		);

		return array(
			'passed'        => empty( $guardrails['issues'] ),
			'issues'        => $guardrails['issues'],
			'warnings'      => $guardrails['warnings'],
			'issue_count'   => count( $guardrails['issues'] ),
			'warning_count' => count( $guardrails['warnings'] ),
			'issue_codes'   => self::qa_item_codes( $guardrails['issues'] ),
			'warning_codes' => self::qa_item_codes( $guardrails['warnings'] ),
			'dimensions'    => $dimensions,
			'guardrails'    => $guardrails,
			'profile'       => array(
				'language' => $language,
				'source_id'=> $source_id,
				'profile_patch_applied' => ! empty( $profile_patch ),
			),
		);
	}

	/**
	 * Compact fitness dimension assembled from existing guardrail modules.
	 *
	 * @param array<string,mixed> $guardrails Full guardrail result.
	 * @param array<int,string>   $module_keys Guardrail module keys.
	 */
		private static function translation_fitness_dimension( array $guardrails, array $module_keys ): array {
			$issues   = array();
			$warnings = array();
		foreach ( $module_keys as $key ) {
			if ( empty( $guardrails[ $key ] ) || ! is_array( $guardrails[ $key ] ) ) {
				continue;
			}
			$issues   = array_merge( $issues, isset( $guardrails[ $key ]['issues'] ) && is_array( $guardrails[ $key ]['issues'] ) ? $guardrails[ $key ]['issues'] : array() );
			$warnings = array_merge( $warnings, isset( $guardrails[ $key ]['warnings'] ) && is_array( $guardrails[ $key ]['warnings'] ) ? $guardrails[ $key ]['warnings'] : array() );
		}

		return array(
			'passed'        => empty( $issues ),
			'issue_count'   => count( $issues ),
			'warning_count' => count( $warnings ),
			'issue_codes'   => self::qa_item_codes( $issues ),
			'warning_codes' => self::qa_item_codes( $warnings ),
			'modules'       => array_values( $module_keys ),
			);
		}

		/**
		 * Policy module for turning guardrail signals into blocking issues and warnings.
		 *
		 * Individual guardrail modules should describe what they found. This module
		 * owns the aggregate pass/fail surface so feature-specific tolerance does not
		 * get reimplemented in every caller.
		 *
		 * @param array<string,array<string,mixed>> $modules Guardrail results keyed by module name.
		 * @return array{passed:bool,issues:array<int,array<string,mixed>>,warnings:array<int,array<string,mixed>>,issue_count:int,warning_count:int,modules:array<string,array<string,mixed>>}
		 */
		private static function translation_fitness_policy( array $modules ): array {
			$issues   = array();
			$warnings = array();

			foreach ( $modules as $result ) {
				if ( ! is_array( $result ) ) {
					continue;
				}

				if ( isset( $result['issues'] ) && is_array( $result['issues'] ) ) {
					$issues = array_merge( $issues, $result['issues'] );
				}

				if ( isset( $result['warnings'] ) && is_array( $result['warnings'] ) ) {
					$warnings = array_merge( $warnings, $result['warnings'] );
				}
			}

			return array(
				'passed'        => empty( $issues ),
				'issues'        => $issues,
				'warnings'      => $warnings,
				'issue_count'   => count( $issues ),
				'warning_count' => count( $warnings ),
				'modules'       => $modules,
			);
		}

		/**
		 * Deep module for translation technical guardrails.
	 *
	 * Callers should not decide which structural checks run for a translation.
	 * This module owns the hard issue/warning split for editor-safe content.
	 */
	private static function translation_guardrails( string $content, string $source_content = '', string $language = '', string $title = '', string $excerpt = '', int $source_id = 0, array $profile_patch = array() ): array {
		$gutenberg  = self::gutenberg_guardrails( $content );
		$shortcodes = self::shortcode_guardrails( $content, $source_content, $source_id );
		$source_structure = self::source_structure_guardrails( $content, $source_content, $source_id, $language );
		$copy_quality = self::copy_quality_guardrails( $content, $source_content, $language, $profile_patch );
		$script_signals = self::language_script_signal_guardrails( $content, $language, $profile_patch );
		$source_carryover = self::source_language_carryover_guardrails( $content, $source_content, $language, $source_id );
		$address_form = self::address_form_guardrails( $content, $language, $source_id, $title, $excerpt );
			$locale_terminology = self::locale_terminology_guardrails( $content, $language, $title, $excerpt );
			$integrity = self::translation_integrity_guardrails( $content, $source_id );
			$link_integrity = self::link_integrity_guardrails( $content, $language );
			$policy = self::translation_fitness_policy(
				array(
					'gutenberg'             => $gutenberg,
					'shortcodes'            => $shortcodes,
					'source_structure'      => $source_structure,
					'copy_quality'          => $copy_quality,
					'script_signals'        => $script_signals,
					'source_carryover'      => $source_carryover,
					'address_form'          => $address_form,
					'locale_terminology'    => $locale_terminology,
					'translation_integrity' => $integrity,
					'link_integrity'        => $link_integrity,
				)
			);

			return array(
				'passed'        => $policy['passed'],
				'issues'        => $policy['issues'],
				'warnings'      => $policy['warnings'],
				'issue_count'   => $policy['issue_count'],
				'warning_count' => $policy['warning_count'],
				'gutenberg'     => $gutenberg,
				'shortcodes'    => $shortcodes,
				'source_structure' => $source_structure,
			'copy_quality'  => $copy_quality,
			'script_signals' => $script_signals,
			'source_carryover' => $source_carryover,
			'address_form'  => $address_form,
				'locale_terminology' => $locale_terminology,
				'translation_integrity' => $integrity,
				'link_integrity' => $link_integrity,
			);
	}

	/**
	 * Normalize known static Gutenberg serialization mismatches before storage.
	 */
	private static function normalize_gutenberg_content_for_storage( string $content ): string {
		return (string) self::gutenberg_content_safety( $content )['normalized_content'];
	}

	/**
	 * Detect stored block-comment/HTML mismatches that produce invalid blocks.
	 *
	 * @return array{passed:bool,issues:array<int,array<string,mixed>>,warnings:array<int,array<string,mixed>>,issue_count:int,warning_count:int,summary:array<string,mixed>}
	 */
	private static function gutenberg_saved_markup_integrity( string $content ): array {
		$safety = self::gutenberg_content_safety( $content );

		return array(
			'passed'        => empty( $safety['issues'] ),
			'issues'        => $safety['issues'],
			'warnings'      => $safety['warnings'],
			'issue_count'   => count( $safety['issues'] ),
			'warning_count' => count( $safety['warnings'] ),
			'summary'       => $safety['summary'],
		);
	}

	/**
	 * Deep module for editor-safe stored Gutenberg content.
	 *
	 * This is the single Interface for save hooks, translation upserts, QA,
	 * and sitewide scans. Adapters for individual static blocks live behind
	 * this module so callers do not need to know block-specific rules.
	 *
	 * @return array{passed:bool,normalized_content:string,changed:bool,repairable:bool,issues:array<int,array<string,mixed>>,warnings:array<int,array<string,mixed>>,issue_count:int,warning_count:int,summary:array<string,mixed>}
	 */
	private static function gutenberg_content_safety( string $content ): array {
		$normalized = self::normalize_gutenberg_saved_markup_with_adapters( $content );
		$issues     = array();
		$warnings   = array();
		$summary    = array(
			'has_blocks'       => has_blocks( $normalized ),
			'checked_blocks'   => 0,
			'adapter_count'    => 3,
			'adapter_blocks'   => array(
				'core/spacer'    => 0,
				'core/heading'   => 0,
				'core/paragraph' => 0,
			),
			'changed'          => $normalized !== $content,
			'repairable'       => $normalized !== $content,
			'content_length'   => strlen( $normalized ),
		);

		if ( has_blocks( $normalized ) ) {
			self::inspect_gutenberg_content_safety_blocks( parse_blocks( $normalized ), $issues, $warnings, $summary );
		}

		return array(
			'passed'             => empty( $issues ),
			'normalized_content' => $normalized,
			'changed'            => $normalized !== $content,
			'repairable'         => $normalized !== $content && empty( $issues ),
			'issues'             => $issues,
			'warnings'           => $warnings,
			'issue_count'        => count( $issues ),
			'warning_count'      => count( $warnings ),
			'summary'            => $summary,
		);
	}

	/**
	 * Apply output-preserving normalizers for known static core blocks.
	 */
	private static function normalize_gutenberg_saved_markup_with_adapters( string $content ): string {
		if ( false === strpos( $content, '<!-- wp:' ) ) {
			return $content;
		}

		$content = self::normalize_core_spacer_saved_markup( $content );
		$content = self::normalize_core_heading_saved_markup( $content );
		$content = self::normalize_core_paragraph_saved_markup( $content );

		return $content;
	}

	/**
	 * Update a block comment's JSON attrs from saved frontend HTML.
	 *
	 * @param callable(array<string,mixed>, string): array<string,mixed> $updater Attr updater.
	 */
	private static function normalize_core_block_saved_markup( string $content, string $block_name, string $pattern, callable $updater ): string {
		if ( false === strpos( $content, '<!-- wp:' . $block_name ) ) {
			return $content;
		}

		$normalized = preg_replace_callback(
			$pattern,
			static function ( array $matches ) use ( $block_name, $updater ): string {
				$attrs_json = isset( $matches['attrs'] ) ? (string) $matches['attrs'] : '';
				$inner_html = isset( $matches['inner'] ) ? (string) $matches['inner'] : '';
				$attrs      = array();
				if ( '' !== $attrs_json ) {
					$decoded = json_decode( html_entity_decode( $attrs_json, ENT_QUOTES, 'UTF-8' ), true );
					if ( ! is_array( $decoded ) ) {
						return (string) $matches[0];
					}
					$attrs = $decoded;
				}

				$updated = $updater( $attrs, $inner_html );
				if ( $updated === $attrs ) {
					return (string) $matches[0];
				}

				if ( empty( $updated ) ) {
					return '<!-- wp:' . $block_name . ' -->' . $inner_html . '<!-- /wp:' . $block_name . ' -->';
				}

				$encoded = wp_json_encode( $updated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( ! is_string( $encoded ) || '' === $encoded ) {
					return (string) $matches[0];
				}

				return '<!-- wp:' . $block_name . ' ' . $encoded . ' -->' . $inner_html . '<!-- /wp:' . $block_name . ' -->';
			},
			$content
		);

		return is_string( $normalized ) ? $normalized : $content;
	}

	private static function normalize_core_spacer_saved_markup( string $content ): string {
		return self::normalize_core_block_saved_markup(
			$content,
			'spacer',
			'/<!--\s+wp:spacer(?:\s+(?P<attrs>{.*?}))?\s+-->(?P<inner>\s*<div\b[^>]*class="[^"]*\bwp-block-spacer\b[^"]*"[^>]*><\/div>\s*)<!--\s+\/wp:spacer\s+-->/is',
			static function ( array $attrs, string $inner_html ): array {
				if ( preg_match( '/<div\b[^>]*style="[^"]*\bheight\s*:\s*([^;"]+)/i', $inner_html, $match ) ) {
					$height = trim( (string) $match[1] );
					if ( '' !== $height ) {
						$attrs['height'] = $height;
					}
				}
				return $attrs;
			}
		);
	}

	private static function normalize_core_heading_saved_markup( string $content ): string {
		return self::normalize_core_block_saved_markup(
			$content,
			'heading',
			'/<!--\s+wp:heading(?:\s+(?P<attrs>{.*?}))?\s+-->(?P<inner>\s*<h[1-6]\b.*?<\/h[1-6]>\s*)<!--\s+\/wp:heading\s+-->/is',
			static function ( array $attrs, string $inner_html ): array {
				if ( preg_match( '/<h([1-6])\b/i', $inner_html, $match ) ) {
					$attrs['level'] = (int) $match[1];
					if ( 2 === $attrs['level'] ) {
						unset( $attrs['level'] );
					}
				}
				return $attrs;
			}
		);
	}

	private static function normalize_core_paragraph_saved_markup( string $content ): string {
		return self::normalize_core_block_saved_markup(
			$content,
			'paragraph',
			'/<!--\s+wp:paragraph(?:\s+(?P<attrs>{.*?}))?\s+-->(?P<inner>\s*<p\b.*?<\/p>\s*)<!--\s+\/wp:paragraph\s+-->/is',
			static function ( array $attrs, string $inner_html ): array {
				if ( preg_match( '/<p\b[^>]*class="[^"]*\bhas-text-align-(left|center|right)\b/i', $inner_html, $match ) ) {
					$saved_align = (string) $match[1];
					if ( isset( $attrs['style'] ) && is_array( $attrs['style'] ) ) {
						$attrs['style']['typography'] = isset( $attrs['style']['typography'] ) && is_array( $attrs['style']['typography'] ) ? $attrs['style']['typography'] : array();
						$attrs['style']['typography']['textAlign'] = $saved_align;
					} elseif ( isset( $attrs['align'] ) && in_array( (string) $attrs['align'], array( 'left', 'center', 'right' ), true ) ) {
						$attrs['align'] = $saved_align;
					} else {
						$attrs['style'] = array(
							'typography' => array(
								'textAlign' => $saved_align,
							),
						);
					}
				} elseif ( isset( $attrs['align'] ) && in_array( (string) $attrs['align'], array( 'left', 'center', 'right' ), true ) ) {
					unset( $attrs['align'] );
				} elseif ( isset( $attrs['style']['typography']['textAlign'] ) && in_array( (string) $attrs['style']['typography']['textAlign'], array( 'left', 'center', 'right' ), true ) ) {
					unset( $attrs['style']['typography']['textAlign'] );
					if ( empty( $attrs['style']['typography'] ) ) {
						unset( $attrs['style']['typography'] );
					}
					if ( empty( $attrs['style'] ) ) {
						unset( $attrs['style'] );
					}
				}
				return $attrs;
			}
		);
	}

	/**
	 * Walk parsed blocks and collect saved-markup integrity failures.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed block tree.
	 * @param array<int,array<string,mixed>> $issues Hard QA failures.
	 * @param array<int,array<string,mixed>> $warnings Soft QA warnings.
	 * @param array<string,mixed>            $summary QA summary.
	 */
	private static function inspect_gutenberg_content_safety_blocks( array $blocks, array &$issues, array &$warnings, array &$summary ): void {
		foreach ( $blocks as $block ) {
			$name  = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$html  = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';

			if ( '' !== $name ) {
				$summary['checked_blocks']++;
			}
			if ( isset( $summary['adapter_blocks'][ $name ] ) ) {
				$summary['adapter_blocks'][ $name ]++;
			}

			if ( 'core/spacer' === $name ) {
				self::inspect_core_spacer_saved_markup( $attrs, $html, $issues );
			} elseif ( 'core/heading' === $name ) {
				self::inspect_core_heading_saved_markup( $attrs, $html, $issues );
			} elseif ( 'core/paragraph' === $name ) {
				self::inspect_core_paragraph_saved_markup( $attrs, $html, $issues );
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::inspect_gutenberg_content_safety_blocks( $block['innerBlocks'], $issues, $warnings, $summary );
			}
		}
	}

	private static function inspect_core_spacer_saved_markup( array $attrs, string $html, array &$issues ): void {
		if ( false === strpos( $html, 'wp-block-spacer' ) ) {
			return;
		}
		$expected = isset( $attrs['height'] ) ? trim( (string) $attrs['height'] ) : '';
		$saved    = '';
		if ( preg_match( '/<div\b[^>]*style="[^"]*\bheight\s*:\s*([^;"]+)/i', $html, $match ) ) {
			$saved = trim( (string) $match[1] );
		}
		if ( '' !== $expected && '' !== $saved && $expected !== $saved ) {
			$issues[] = self::qa_item(
				'invalid_gutenberg_saved_markup',
				'Core spacer block has mismatched height in block attributes and saved HTML, which makes the block editor report invalid content.',
				array(
					'block'            => 'core/spacer',
					'attribute_height' => $expected,
					'saved_height'     => $saved,
				)
			);
		}
	}

	private static function inspect_core_heading_saved_markup( array $attrs, string $html, array &$issues ): void {
		if ( ! preg_match( '/<h([1-6])\b/i', $html, $match ) ) {
			return;
		}
		$expected = isset( $attrs['level'] ) ? (int) $attrs['level'] : 2;
		$saved    = (int) $match[1];
		if ( $expected !== $saved ) {
			$issues[] = self::qa_item(
				'invalid_gutenberg_saved_markup',
				'Core heading block has mismatched heading level in block attributes and saved HTML, which makes the block editor report invalid content.',
				array(
					'block'           => 'core/heading',
					'attribute_level' => $expected,
					'saved_level'     => $saved,
				)
			);
		}
	}

	private static function inspect_core_paragraph_saved_markup( array $attrs, string $html, array &$issues ): void {
		$expected = isset( $attrs['align'] ) ? trim( (string) $attrs['align'] ) : '';
		if ( '' === $expected && isset( $attrs['style']['typography']['textAlign'] ) ) {
			$expected = trim( (string) $attrs['style']['typography']['textAlign'] );
		}
		if ( '' !== $expected && ! in_array( $expected, array( 'left', 'center', 'right' ), true ) ) {
			return;
		}
		$saved    = '';
		if ( preg_match( '/<p\b[^>]*class="[^"]*\bhas-text-align-(left|center|right)\b/i', $html, $match ) ) {
			$saved = (string) $match[1];
		}
		if ( $expected !== $saved ) {
			if ( '' === $expected && '' === $saved ) {
				return;
			}
			$issues[] = self::qa_item(
				'invalid_gutenberg_saved_markup',
				'Core paragraph block has mismatched text alignment in block attributes and saved HTML, which can make the block editor report invalid content.',
				array(
					'block'           => 'core/paragraph',
					'attribute_align' => $expected,
					'saved_align'     => $saved,
				)
			);
		}
	}

	/**
	 * Guard against malformed links that keep the source link count but break clicks.
	 */
	private static function link_integrity_guardrails( string $content, string $language = '' ): array {
		$issues   = array();
		$warnings = array();

		if ( ! preg_match_all( '/\bhref=([\"\'])([^\"\']*)\1/i', $content, $matches ) ) {
			return array(
				'passed'        => true,
				'issues'        => array(),
				'warnings'      => array(),
				'issue_count'   => 0,
				'warning_count' => 0,
				'summary'       => array(
					'link_count' => 0,
				),
			);
		}

		foreach ( $matches[2] as $raw_url ) {
			$url = trim( html_entity_decode( (string) $raw_url, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' ) );
			if ( '' === $url ) {
				$issues[] = self::qa_item(
					'invalid_link_href',
					'Translation contains an empty link href.',
					array(
						'actual_url' => (string) $raw_url,
						'reason'     => 'empty_href',
					)
				);
				continue;
			}

			if ( '#' === $url[0] || preg_match( '/^(mailto|tel|sms|javascript):/i', $url ) ) {
				continue;
			}

			$parts = wp_parse_url( $url );
			if ( false === $parts || preg_match( '/^\/{2,}$/', $url ) ) {
				$issues[] = self::qa_item(
					'invalid_link_href',
					'Translation contains a malformed link href.',
					array(
						'actual_url' => $url,
						'reason'     => false === $parts ? 'parse_failed' : 'empty_protocol_relative_url',
					)
				);
				continue;
			}

			if ( is_array( $parts ) && isset( $parts['host'] ) && '' === trim( (string) $parts['host'] ) ) {
				$issues[] = self::qa_item(
					'invalid_link_href',
					'Translation contains a malformed link href.',
					array(
						'actual_url' => $url,
						'reason'     => 'empty_host',
					)
				);
				continue;
			}

			$internal_resolution = self::internal_content_link_resolution( $url, is_array( $parts ) ? $parts : array() );
			if ( isset( $internal_resolution['resolved'] ) && false === $internal_resolution['resolved'] ) {
				$issues[] = self::qa_item(
					'unresolved_internal_content_link',
					'Translation contains an internal content link that no longer resolves to a current WordPress target.',
					array(
						'actual_url'   => $url,
						'path'         => $internal_resolution['path'] ?? '',
						'reason'       => $internal_resolution['reason'] ?? 'unresolved',
						'review_scope' => self::link_review_scope_for_url( $content, $raw_url ),
					)
				);
			}
		}

		foreach ( self::localized_link_issues_for_html( $content, $language ) as $link_issue ) {
			$issues[] = self::qa_item(
				'localized_link_target_mismatch',
				'Translation contains an internal link that points to the wrong language target.',
				$link_issue
			);
		}

		return array(
			'passed'        => empty( $issues ),
			'issues'        => $issues,
			'warnings'      => $warnings,
			'issue_count'   => count( $issues ),
			'warning_count' => count( $warnings ),
			'summary'       => array(
				'link_count' => count( $matches[2] ),
			),
		);
	}

	/**
	 * Return only hard malformed href issues suitable for pre-save blocking.
	 *
	 * The full link-integrity module also checks localized target mismatch.
	 * Direct WordPress saves should not bypass malformed href protection, but
	 * broader translation QA still belongs in qa-translation/publish gates.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function hard_invalid_link_issues_for_content( string $content, string $language = '' ): array {
		$link_integrity = self::link_integrity_guardrails( $content, $language );
		$issues         = isset( $link_integrity['issues'] ) && is_array( $link_integrity['issues'] )
			? $link_integrity['issues']
			: array();

		return array_values(
			array_filter(
				$issues,
				static function ( $issue ): bool {
					return is_array( $issue )
						&& isset( $issue['code'] )
						&& 'invalid_link_href' === (string) $issue['code'];
				}
			)
		);
	}

	/**
	 * Resolve site-internal content links without following frontend redirects.
	 *
	 * @param array<string,mixed> $parts Parsed URL parts.
	 * @return array{resolved:bool,path?:string,reason?:string}|array<string,mixed>
	 */
	private static function internal_content_link_resolution( string $url, array $parts ): array {
		$site_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host      = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		if ( '' !== $host && strtolower( $site_host ) !== $host ) {
			return array();
		}

		$path = self::normalized_url_path( $url );
		if ( '' === $path ) {
			return array();
		}

		$clean_path = trim( $path, '/' );
		if ( '' === $clean_path || self::is_allowed_non_content_internal_path( $clean_path ) ) {
			return array( 'resolved' => true, 'path' => $path );
		}

		if ( preg_match( '/\.[A-Za-z0-9]{2,8}$/', basename( $clean_path ) ) ) {
			return array( 'resolved' => true, 'path' => $path, 'reason' => 'asset_or_file' );
		}

		$post_id = url_to_postid( home_url( $path ) );
		if ( $post_id ) {
			$canonical_path = self::normalized_url_path( (string) get_permalink( $post_id ) );
			if ( $canonical_path === $path ) {
				return array( 'resolved' => true, 'path' => $path, 'reason' => 'post' );
			}

			return array(
				'resolved' => false,
				'path'     => $path,
				'reason'   => 'non_canonical_or_redirected_internal_path',
			);
		}

		if ( self::internal_archive_link_resolves( $clean_path ) ) {
			return array( 'resolved' => true, 'path' => $path, 'reason' => 'archive' );
		}

		return array(
			'resolved' => false,
			'path'     => $path,
			'reason'   => 'no_wordpress_target',
		);
	}

	/**
	 * Allow internal operational paths that are not WordPress content targets.
	 */
	private static function is_allowed_non_content_internal_path( string $clean_path ): bool {
		foreach ( array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'comments', 'cdn-cgi' ) as $prefix ) {
			if ( $clean_path === $prefix || 0 === strpos( $clean_path, $prefix . '/' ) ) {
				return true;
			}
		}

		foreach ( self::target_languages() as $language => $config ) {
			$prefix = self::language_prefix( (string) $language );
			if ( '' !== $prefix && $clean_path === $prefix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve WordPress archive-like links that do not have a post ID.
	 */
	private static function internal_archive_link_resolves( string $clean_path ): bool {
		$posts_page_id = absint( get_option( 'page_for_posts' ) );
		if ( $posts_page_id && trim( self::normalized_url_path( (string) get_permalink( $posts_page_id ) ), '/' ) === $clean_path ) {
			return true;
		}

		foreach ( self::target_languages() as $language => $config ) {
			$blog_base = self::localized_blog_base_path( (string) $language );
			if ( '' !== $blog_base && trim( $blog_base, '/' ) === $clean_path ) {
				return true;
			}
		}

		$term_match = self::translated_term_request_for_path( '/' . $clean_path . '/' );
		if ( ! empty( $term_match ) ) {
			return true;
		}

		global $wp_rewrite;
		$category_base = is_object( $wp_rewrite ) && ! empty( $wp_rewrite->category_base ) ? trim( (string) $wp_rewrite->category_base, '/' ) : 'category';
		$tag_base      = is_object( $wp_rewrite ) && ! empty( $wp_rewrite->tag_base ) ? trim( (string) $wp_rewrite->tag_base, '/' ) : 'tag';
		foreach ( array( $category_base => 'category', $tag_base => 'post_tag' ) as $base => $taxonomy ) {
			if ( '' === $base || 0 !== strpos( $clean_path, $base . '/' ) ) {
				continue;
			}
			$slug = trim( substr( $clean_path, strlen( $base ) + 1 ), '/' );
			if ( '' === $slug || false !== strpos( $slug, '/' ) ) {
				continue;
			}
			if ( get_term_by( 'slug', sanitize_title( $slug ), $taxonomy ) instanceof WP_Term ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return nearby visible block copy so obsolete internal links trigger whole-section review.
	 */
	private static function link_review_scope_for_url( string $content, string $raw_url ): array {
		$raw_url = (string) $raw_url;
		if ( '' === $raw_url ) {
			return array();
		}

		$block_scope = self::link_review_scope_from_blocks( parse_blocks( $content ), $raw_url );
		if ( ! empty( $block_scope ) ) {
			return $block_scope;
		}

		$position = strpos( $content, 'href="' . $raw_url . '"' );
		if ( false === $position ) {
			$position = strpos( $content, "href='" . $raw_url . "'" );
		}
		if ( false === $position ) {
			return array();
		}

		$start = strrpos( substr( $content, 0, $position ), '<!-- wp:' );
		$end   = strpos( $content, '<!-- /wp:', $position );
		if ( false !== $end ) {
			$closing_end = strpos( $content, '-->', $end );
			if ( false !== $closing_end ) {
				$end = $closing_end + 3;
			}
		}

		if ( false === $start ) {
			$start = max( 0, $position - 500 );
		}
		if ( false === $end ) {
			$end = min( strlen( $content ), $position + 500 );
		}

		$fragment = substr( $content, (int) $start, max( 0, (int) $end - (int) $start ) );
		$text     = self::normalized_plain_text_for_review( (string) $fragment );
		if ( '' === $text ) {
			return array();
		}

		return array(
			'visible_text' => mb_substr( $text, 0, 500 ),
			'action'       => 'review_or_remove_entire_linked_section',
		);
	}

	/**
	 * Find the smallest meaningful block scope that contains a stale link.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 */
	private static function link_review_scope_from_blocks( array $blocks, string $raw_url ): array {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$serialized = serialize_block( $block );
			if ( false === strpos( $serialized, $raw_url ) ) {
				continue;
			}

			$child_scope = array();
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$child_scope = self::link_review_scope_from_blocks( $block['innerBlocks'], $raw_url );
			}

			$current_text = self::normalized_plain_text_for_review( $serialized );
			$child_text   = isset( $child_scope['visible_text'] ) ? (string) $child_scope['visible_text'] : '';

			if ( mb_strlen( $current_text ) >= 80 && mb_strlen( $current_text ) > mb_strlen( $child_text ) ) {
				return array(
					'visible_text' => mb_substr( $current_text, 0, 500 ),
					'action'       => 'review_or_remove_entire_linked_section',
					'block_name'   => isset( $block['blockName'] ) ? (string) $block['blockName'] : '',
				);
			}

			if ( ! empty( $child_scope ) ) {
				return $child_scope;
			}

			if ( '' !== $current_text ) {
				return array(
					'visible_text' => mb_substr( $current_text, 0, 500 ),
					'action'       => 'review_or_remove_entire_linked_section',
					'block_name'   => isset( $block['blockName'] ) ? (string) $block['blockName'] : '',
				);
			}
		}

		return array();
	}

	/**
	 * Guard against manual halfway localization surfaces that break source automation.
	 */
	private static function translation_integrity_guardrails( string $content, int $source_id = 0 ): array {
		$posts_page = self::translated_posts_page_manual_surface_guardrails( $content, $source_id );
		$issues     = $posts_page['issues'];
		$warnings   = $posts_page['warnings'];

		return array(
			'passed'        => empty( $issues ),
			'issues'        => $issues,
			'warnings'      => $warnings,
			'issue_count'   => count( $issues ),
			'warning_count' => count( $warnings ),
			'posts_page'    => $posts_page,
		);
	}

	/**
	 * Deep module for the stored route of a translated post.
	 *
	 * Callers should not individually reason about WordPress slug rewrites,
	 * localized path metadata, or language prefixes. This module owns those
	 * invariants for QA, publish gates, and repair abilities.
	 *
	 * @return array{passed:bool,issues:array<int,array<string,mixed>>,warnings:array<int,array<string,mixed>>,issue_count:int,warning_count:int,summary:array<string,mixed>}
	 */
	private static function translation_route_integrity( int $translation_id, string $language = '' ): array {
		$post     = get_post( $translation_id );
		$issues   = array();
		$warnings = array();
		$summary  = array(
			'translation_id' => $translation_id,
			'language'       => $language,
			'slug'           => $post ? (string) $post->post_name : '',
			'url'            => $post ? (string) get_permalink( $translation_id ) : '',
			'localized_path' => $post ? (string) get_post_meta( $translation_id, self::META_LOCALIZED_PATH, true ) : '',
			'expected_path'  => '',
			'prefix'         => $language ? self::language_prefix( $language ) : '',
		);

		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			$issues[] = self::qa_item( 'translation_route_missing_post', 'Translation route integrity could not find a translatable post.' );
			return array(
				'passed'        => false,
				'issues'        => $issues,
				'warnings'      => $warnings,
				'issue_count'   => count( $issues ),
				'warning_count' => 0,
				'summary'       => $summary,
			);
		}

		if ( '' === $language ) {
			$language = sanitize_key( (string) get_post_meta( $translation_id, self::META_LANGUAGE, true ) );
			$summary['language'] = $language;
			$summary['prefix']   = $language ? self::language_prefix( $language ) : '';
		}

		if ( self::has_wordpress_duplicate_slug_suffix( (string) $post->post_name ) ) {
			$issues[] = self::qa_item(
				'localized_slug_duplicate_suffix',
				'Translation permalink uses a WordPress duplicate slug suffix such as -2. Resolve the route collision instead of publishing the duplicate URL.',
				array(
					'slug' => (string) $post->post_name,
					'url'  => (string) get_permalink( $translation_id ),
				)
			);
		}

		$prefix = (string) $summary['prefix'];
		$actual_path = trim( self::normalized_url_path( (string) get_permalink( $translation_id ) ), '/' );
		if ( in_array( (string) $post->post_status, array( 'publish', 'private' ), true ) && '' !== $prefix && '' !== $actual_path && 0 !== strpos( $actual_path, $prefix . '/' ) && $actual_path !== $prefix ) {
			$issues[] = self::qa_item(
				'localized_permalink_mismatch',
				'Actual permalink does not start with the configured language prefix.',
				array(
					'actual_path' => $actual_path,
					'prefix'      => $prefix,
				)
			);
		}

		$expected_path = self::localized_path_for_post( $translation_id, $language );
		$summary['expected_path'] = $expected_path;
		$stored_path = trim( (string) get_post_meta( $translation_id, self::META_LOCALIZED_PATH, true ), '/' );
		if ( '' !== $stored_path && '' !== $expected_path && $stored_path !== $expected_path ) {
			$issues[] = self::qa_item(
				'localized_path_stale',
				'Stored localized path no longer matches the translated WordPress permalink.',
				array(
					'stored_path'   => $stored_path,
					'expected_path' => $expected_path,
				)
			);
		}

		foreach ( self::rank_math_self_redirects_for_url( (string) get_permalink( $translation_id ) ) as $redirect ) {
			$issues[] = self::qa_item(
				'localized_permalink_self_redirect',
				'An active Rank Math redirection source matches this translated page canonical URL and redirects back to the same URL.',
				$redirect
			);
		}

		return array(
			'passed'        => empty( $issues ),
			'issues'        => $issues,
			'warnings'      => $warnings,
			'issue_count'   => count( $issues ),
			'warning_count' => count( $warnings ),
			'summary'       => $summary,
		);
	}

	/**
	 * Repair a WordPress duplicate slug suffix when the intended base slug is free.
	 *
	 * @return array<string,mixed>
	 */
	private static function repair_translation_duplicate_slug_suffix( int $translation_id, string $language, bool $dry_run ): array {
		$post = get_post( $translation_id );
		if ( ! $post || ! self::is_translation_post( $translation_id ) || ! self::has_wordpress_duplicate_slug_suffix( (string) $post->post_name ) ) {
			return array(
				'success' => true,
				'changed' => false,
			);
		}

		$target_slug = preg_replace( '/-[2-9]\d*$/', '', (string) $post->post_name );
		$target_slug = is_string( $target_slug ) ? sanitize_title( $target_slug ) : '';
		if ( '' === $target_slug || self::has_wordpress_duplicate_slug_suffix( $target_slug ) ) {
			return array(
				'success' => false,
				'message' => 'Could not derive a safe base slug from the duplicate translation slug.',
				'code'    => 'duplicate_slug_base_unavailable',
				'before_slug' => (string) $post->post_name,
			);
		}

		$conflicts = self::translation_slug_conflicts( $target_slug, (string) $post->post_type, (int) $post->post_parent, $translation_id );
		if ( $conflicts ) {
			return array(
				'success' => false,
				'message' => 'Base slug is still blocked by another post. Remove or rename the collision before repairing the translation slug.',
				'code'    => 'duplicate_slug_base_still_blocked',
				'before_slug' => (string) $post->post_name,
				'target_slug' => $target_slug,
				'conflicting_posts' => $conflicts,
			);
		}

		$before_url = (string) get_permalink( $translation_id );
		if ( $dry_run ) {
			return array(
				'success'     => true,
				'changed'     => true,
				'dry_run'     => true,
				'before_slug' => (string) $post->post_name,
				'target_slug' => $target_slug,
				'before_url'  => $before_url,
			);
		}

		$result = 0;
		self::with_slug_change_unlock(
			static function () use ( &$result, $translation_id, $target_slug ): void {
				self::with_direct_save_storage_guardrails_suspended(
					static function () use ( &$result, $translation_id, $target_slug ): void {
						$result = wp_update_post(
							wp_slash(
								array(
									'ID'        => $translation_id,
									'post_name' => $target_slug,
								)
							),
							true
						);
					}
				);
			}
		);
		if ( is_wp_error( $result ) ) {
			return self::error( $result->get_error_message() );
		}

		clean_post_cache( $translation_id );
		$updated = get_post( $translation_id );
		if ( ! $updated || $target_slug !== (string) $updated->post_name || self::has_wordpress_duplicate_slug_suffix( (string) $updated->post_name ) ) {
			return array(
				'success'     => false,
				'message'     => 'WordPress changed the repaired translation slug during save.',
				'code'        => 'duplicate_slug_repair_rewritten',
				'before_slug' => (string) $post->post_name,
				'target_slug' => $target_slug,
				'actual_slug' => $updated ? (string) $updated->post_name : '',
			);
		}

		update_post_meta( $translation_id, self::META_LOCALIZED_PATH, self::localized_path_for_post( $translation_id, $language ) );
		self::sync_translation_index_row( $translation_id );

		return array(
			'success'     => true,
			'changed'     => true,
			'before_slug' => (string) $post->post_name,
			'after_slug'  => $target_slug,
			'before_url'  => $before_url,
			'after_url'   => (string) get_permalink( $translation_id ),
		);
	}

	/**
	 * Remove active Rank Math redirects that would redirect a translated page to itself.
	 *
	 * @return array<string,mixed>
	 */
	private static function repair_translation_rank_math_self_redirects( int $translation_id, bool $dry_run ): array {
		$post = get_post( $translation_id );
		if ( ! $post || ! self::is_translation_post( $translation_id ) ) {
			return array(
				'success' => true,
				'changed' => false,
			);
		}

		$conflicts = self::rank_math_self_redirects_for_url( (string) get_permalink( $translation_id ) );
		if ( empty( $conflicts ) ) {
			return array(
				'success' => true,
				'changed' => false,
			);
		}

		if ( $dry_run ) {
			return array(
				'success' => true,
				'changed' => true,
				'dry_run' => true,
				'conflicts' => $conflicts,
			);
		}

		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		$ids   = array_values( array_unique( array_map( 'absint', wp_list_pluck( $conflicts, 'id' ) ) ) );
		$ids   = array_values( array_filter( $ids ) );
		if ( empty( $ids ) || ! self::rank_math_redirections_table_exists( $table ) ) {
			return array(
				'success' => false,
				'changed' => false,
				'message' => 'Rank Math self-redirect conflicts were detected, but no removable redirection IDs were available.',
			);
		}

		$deleted = 0;
		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM `' . esc_sql( $table ) . '` WHERE id = %d',
					$id
				)
			);
			if ( false === $result ) {
				return array(
					'success' => false,
					'changed' => false,
					'message' => 'Failed to delete Rank Math self-redirect conflicts.',
				);
			}
			$deleted += (int) $result;
		}

		return array(
			'success' => true,
			'changed' => 0 < (int) $deleted,
			'deleted_count' => (int) $deleted,
			'conflicts' => $conflicts,
		);
	}

	/**
	 * Find active Rank Math redirects whose source and destination normalize to the same URL path.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function rank_math_self_redirects_for_url( string $url ): array {
		$target_path = self::normalized_url_path( $url );
		if ( '' === $target_path ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		if ( ! self::rank_math_redirections_table_exists( $table ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, sources, url_to, header_code, status FROM `' . esc_sql( $table ) . '` WHERE status = %s',
				'active'
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$conflicts = array();
		foreach ( $rows as $row ) {
			$destination = isset( $row['url_to'] ) ? (string) $row['url_to'] : '';
			$destination_path = self::normalized_url_path( $destination );
			if ( '' === $destination_path || $destination_path !== $target_path ) {
				continue;
			}

			foreach ( self::rank_math_redirection_sources( $row['sources'] ?? array() ) as $source ) {
				$comparison = isset( $source['comparison'] ) ? (string) $source['comparison'] : 'exact';
				if ( 'exact' !== $comparison ) {
					continue;
				}
				$pattern = isset( $source['pattern'] ) ? (string) $source['pattern'] : '';
				if ( self::normalized_url_path( $pattern ) !== $target_path ) {
					continue;
				}

				$conflicts[] = array(
					'id' => absint( $row['id'] ?? 0 ),
					'source' => $pattern,
					'destination' => $destination,
					'path' => $target_path,
					'header_code' => absint( $row['header_code'] ?? 0 ),
					'status' => (string) ( $row['status'] ?? '' ),
				);
			}
		}

		return $conflicts;
	}

	/**
	 * Decode Rank Math's stored redirection source list.
	 *
	 * @param mixed $raw Raw DB value.
	 * @return array<int,array<string,mixed>>
	 */
	private static function rank_math_redirection_sources( $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = maybe_unserialize( $raw );
			if ( is_string( $decoded ) ) {
				$json = json_decode( $decoded, true );
				if ( JSON_ERROR_NONE === json_last_error() ) {
					$decoded = $json;
				}
			}
		} else {
			$decoded = $raw;
		}

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$sources = array();
		foreach ( $decoded as $source ) {
			if ( is_array( $source ) ) {
				$sources[] = $source;
			}
		}

		return $sources;
	}

	/**
	 * Check whether the Rank Math redirections table exists.
	 */
	private static function rank_math_redirections_table_exists( string $table ): bool {
		static $cache = array();
		if ( isset( $cache[ $table ] ) ) {
			return $cache[ $table ];
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		$cache[ $table ] = $exists;

		return $exists;
	}

	/**
	 * Translated posts pages must stay archive/query driven until posts are translated.
	 */
	private static function translated_posts_page_manual_surface_guardrails( string $content, int $source_id = 0 ): array {
		$issues        = array();
		$warnings      = array();
		$posts_page_id = absint( get_option( 'page_for_posts' ) );
		$summary       = array(
			'is_posts_page_source'       => false,
			'disallowed_marker_count'    => 0,
			'disallowed_block_count'     => 0,
			'disallowed_blocks'          => array(),
			'static_source_post_links'   => 0,
			'mismatched_static_titles'   => 0,
		);

		if ( ! $posts_page_id || $source_id !== $posts_page_id ) {
			return array(
				'passed'        => true,
				'issues'        => $issues,
				'warnings'      => $warnings,
				'issue_count'   => 0,
				'warning_count' => 0,
				'summary'       => $summary,
			);
		}

		$summary['is_posts_page_source'] = true;
		$is_query_driven_archive = self::is_query_driven_posts_archive_content( $content );
		if ( ! $is_query_driven_archive ) {
			$issues[] = self::qa_item(
				'translated_posts_archive_not_query_loop',
				'Translated posts archives must use the automatic Query Loop posts-page model.',
				array(
					'source_id' => $source_id,
				)
			);
		}

		$disallowed_blocks = self::translated_posts_page_archive_block_violations( $content );
		if ( $disallowed_blocks ) {
			$summary['disallowed_blocks']      = $disallowed_blocks;
			$summary['disallowed_block_count'] = count( $disallowed_blocks );
			$issues[] = self::qa_item(
				'translated_posts_archive_static_page_blocks',
				'Translated posts archives must keep hero, presentation, and shortcode output outside stored page content. Use only the Query Loop archive surface; GeneratePress Elements own the hero.',
				array(
					'source_id'           => $source_id,
					'disallowed_blocks'   => $disallowed_blocks,
					'allowed_block_model' => 'query_loop_archive_surface',
				)
			);
		}

		$manual_markers = array(
			'dv-blog-simple-posts',
			'devenia-manual-post-list',
		'devenia-translated-post-card',
		'wp:latest-posts',
		);

		foreach ( $manual_markers as $marker ) {
			if ( false === strpos( $content, $marker ) ) {
				continue;
			}
			if ( 'dv-blog-simple-posts' === $marker && $is_query_driven_archive ) {
				continue;
			}
			++$summary['disallowed_marker_count'];
			$issues[] = self::qa_item(
				'manual_translated_posts_archive_marker',
				'Translated posts archives must use the automatic archive template, not a manual localized post list.',
				array(
					'marker'    => $marker,
					'source_id' => $source_id,
				)
			);
		}

		$post_links = self::source_post_link_map_for_integrity_guardrail();
		if ( $post_links && preg_match_all( '/<a\b[^>]*\bhref=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$href = html_entity_decode( (string) $match[2], ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
				$path = self::normalized_url_path( $href );
				if ( '' === $path || empty( $post_links[ $path ] ) ) {
					continue;
				}

				$post_link = $post_links[ $path ];
				$visible   = trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( (string) $match[3] ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' ) ) );
				$expected  = (string) $post_link['title'];
				++$summary['static_source_post_links'];
				if ( $visible !== $expected ) {
					++$summary['mismatched_static_titles'];
				}

				$issues[] = self::qa_item(
					'manual_source_post_link_on_translated_archive',
					'Translated posts archives must not contain static source-post links or manually localized post titles. Use the archive template until the linked posts have real translations.',
					array(
						'source_id'      => $source_id,
						'post_id'        => (int) $post_link['id'],
						'href'           => $href,
						'expected_title' => $expected,
						'visible_text'   => $visible,
						'title_matches'  => $visible === $expected,
					)
				);
			}
		}

		return array(
			'passed'        => empty( $issues ),
			'issues'        => $issues,
			'warnings'      => $warnings,
			'issue_count'   => count( $issues ),
			'warning_count' => count( $warnings ),
			'summary'       => $summary,
		);
	}

	/**
	 * Query Loop archives are the expected automatic posts-page surface.
	 */
	private static function is_query_driven_posts_archive_content( string $content ): bool {
		$summary = self::semantic_structure_summary( $content );

		return in_array( 'core/query', $summary['block_names'], true )
			&& in_array( 'core/post-template', $summary['block_names'], true );
	}

	/**
	 * Find non-archive blocks in translated posts-page content.
	 *
	 * @return array<int,string>
	 */
	private static function translated_posts_page_archive_block_violations( string $content ): array {
		$summary = self::semantic_structure_summary( $content );
		$allowed = array(
			'core/query',
			'core/post-template',
			'core/post-featured-image',
			'core/post-title',
			'core/post-excerpt',
			'core/query-pagination',
			'core/query-pagination-previous',
			'core/query-pagination-next',
			'core/query-no-results',
			'core/paragraph',
		);

		$violations = array();
		foreach ( $summary['block_names'] as $block_name ) {
			if ( in_array( $block_name, $allowed, true ) ) {
				continue;
			}
			$violations[] = $block_name;
		}

		return array_values( array_unique( $violations ) );
	}

	/**
	 * Map current source post permalinks so manual translated archive lists can be detected.
	 *
	 * @return array<string,array{id:int,title:string}>
	 */
	private static function source_post_link_map_for_integrity_guardrail(): array {
		$query = new WP_Query(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => 50,
				'ignore_sticky_posts' => false,
				'no_found_rows'       => true,
			)
		);

		$links = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$path = self::normalized_url_path( get_permalink( $post ) );
			if ( '' === $path ) {
				continue;
			}
			$links[ $path ] = array(
				'id'    => (int) $post->ID,
				'title' => get_the_title( $post ),
			);
		}

		wp_reset_postdata();

		return $links;
	}

	/**
	 * Detect machine-junk and configured language review patterns in text blocks.
	 */
	private static function copy_quality_guardrails( string $content, string $source_content = '', string $language = '', array $profile_patch = array() ): array {
		$issues    = array();
		$warnings  = array();
		$fragments = self::text_fragments_for_copy_quality( $content );

		foreach ( $fragments as $fragment ) {
			$text = (string) $fragment['text'];
			if ( '' === trim( $text ) ) {
				continue;
			}

			if ( preg_match( '/\\b([A-Za-z])\\1{3,}\\b/', $text, $match ) ) {
				$issues[] = self::qa_item(
					'machine_junk_repeated_character',
					'Translation text contains repeated-character machine junk.',
					array(
						'text'      => $text,
						'match'     => $match[0],
						'block'     => $fragment['block'],
						'unique_id' => $fragment['unique_id'],
					)
				);
			}

			if ( ! empty( $fragment['heading'] ) && preg_match( '/(?:->|=>|<-|<=)/', $text, $match ) ) {
				$issues[] = self::qa_item(
					'machine_junk_heading_arrow_debris',
					'Heading contains arrow debris that should not appear in reviewed translation copy.',
					array(
						'text'      => $text,
						'match'     => $match[0],
						'block'     => $fragment['block'],
						'unique_id' => $fragment['unique_id'],
					)
				);
			}
		}

		$profile = self::language_review_profile( $language );
		if ( ! empty( $profile_patch ) ) {
			$profile = self::merge_quality_profile_patch( $profile, self::sanitize_quality_profile_patch( $profile_patch ) );
		}
		$patterns = isset( $profile['review_patterns'] ) && is_array( $profile['review_patterns'] ) ? $profile['review_patterns'] : array();
		$naturalness_patterns = isset( $profile['naturalness_patterns'] ) && is_array( $profile['naturalness_patterns'] ) ? $profile['naturalness_patterns'] : array();
		$plain_text = self::normalized_plain_text_for_review( $content );
		foreach ( $patterns as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			if ( false !== stripos( $plain_text, $pattern ) ) {
				$issues[] = self::qa_item(
					'language_review_pattern_found',
					'Translation contains a configured language review pattern that must be rewritten before publishing.',
					array(
						'language' => $language,
						'pattern'  => $pattern,
					)
				);
			}
		}
		$naturalness = self::naturalness_pattern_guardrails( $plain_text, self::normalized_plain_text_for_review( $source_content ), $language, $naturalness_patterns );
		$issues      = array_merge( $issues, $naturalness['issues'] );
		$warnings    = array_merge( $warnings, $naturalness['warnings'] );

		return array(
			'passed'        => empty( $issues ),
			'issues'        => $issues,
			'warnings'      => $warnings,
			'issue_count'   => count( $issues ),
			'warning_count' => count( $warnings ),
			'summary'       => array(
				'fragment_count' => count( $fragments ),
				'language'       => $language,
				'review_pattern_count' => count( $patterns ),
				'naturalness_pattern_count' => count( $naturalness_patterns ),
				'naturalness_matches' => $naturalness['summary']['match_count'] ?? 0,
			),
		);
	}

	/**
	 * Detect source-aware target-language calques and translationese.
	 *
	 * @param array<int,array<string,mixed>> $patterns Language profile patterns.
	 */
	private static function naturalness_pattern_guardrails( string $target_text, string $source_text, string $language, array $patterns ): array {
		$issues      = array();
		$warnings    = array();
		$match_count = 0;

		if ( '' === $language || empty( $patterns ) || '' === trim( $target_text ) ) {
			return array(
				'passed'        => true,
				'issues'        => $issues,
				'warnings'      => $warnings,
				'issue_count'   => 0,
				'warning_count' => 0,
				'summary'       => array(
					'language'    => $language,
					'match_count' => 0,
				),
			);
		}

		foreach ( $patterns as $pattern ) {
			if ( ! is_array( $pattern ) ) {
				continue;
			}

			$target       = self::normalize_review_text( wp_strip_all_tags( (string) ( $pattern['target'] ?? '' ) ) );
			$target_regex = self::sanitize_review_regex_pattern( (string) ( $pattern['target_regex'] ?? '' ) );
			if (
				( '' === $target || false === stripos( $target_text, $target ) )
				&& ( '' === $target_regex || ! self::review_regex_matches( $target_regex, $target_text ) )
			) {
				continue;
			}

			$source       = self::normalize_review_text( wp_strip_all_tags( (string) ( $pattern['source'] ?? '' ) ) );
			$source_regex = self::sanitize_review_regex_pattern( (string) ( $pattern['source_regex'] ?? '' ) );
			if (
				'' !== $source
				&& ( '' === trim( $source_text ) || false === stripos( $source_text, $source ) )
			) {
				continue;
			}
			if (
				'' !== $source_regex
				&& ( '' === trim( $source_text ) || ! self::review_regex_matches( $source_regex, $source_text ) )
			) {
				continue;
			}

			++$match_count;
			$issues[] = self::qa_item(
				'language_naturalness_pattern_found',
				'Translation contains source-shaped phrasing that reads unnaturally in the target language.',
				array(
					'language'    => $language,
					'id'          => sanitize_key( (string) ( $pattern['id'] ?? '' ) ),
					'target'      => $target,
					'target_regex'=> $target_regex,
					'source'      => $source,
					'source_regex'=> $source_regex,
					'message'     => isset( $pattern['message'] ) ? sanitize_text_field( (string) $pattern['message'] ) : '',
					'suggestions' => isset( $pattern['suggestions'] ) && is_array( $pattern['suggestions'] ) ? self::sanitize_quality_profile_string_list( $pattern['suggestions'] ) : array(),
				)
			);
		}

		return array(
			'passed'        => empty( $issues ),
			'issues'        => $issues,
			'warnings'      => $warnings,
			'issue_count'   => count( $issues ),
			'warning_count' => count( $warnings ),
			'summary'       => array(
				'language'    => $language,
				'match_count' => $match_count,
			),
		);
	}

	/**
	 * Match a sanitized review regex fragment against normalized text.
	 */
	private static function review_regex_matches( string $pattern, string $text ): bool {
		if ( '' === $pattern || '' === $text ) {
			return false;
		}

		$regex = '/' . str_replace( '/', '\/', $pattern ) . '/iu';
		return 1 === @preg_match( $regex, $text );
	}

	/**
	 * Detect target text that has lost the expected script or diacritics.
	 */
	private static function language_script_signal_guardrails( string $content, string $language = '', array $profile_patch = array() ): array {
		$issues   = array();
		$warnings = array();
		$signals  = self::effective_language_script_signals( $language );
		if ( ! empty( $profile_patch ) ) {
			$profile = self::language_review_profile( $language );
			$profile = self::merge_quality_profile_patch( $profile, self::sanitize_quality_profile_patch( $profile_patch ) );
			$signals = isset( $profile['script_signals'] ) && is_array( $profile['script_signals'] ) ? $profile['script_signals'] : $signals;
		}

		if ( '' === $language || ! self::is_translation_language( $language ) || empty( $signals ) ) {
			return array(
				'passed'        => true,
				'issues'        => $issues,
				'warnings'      => $warnings,
				'issue_count'   => 0,
				'warning_count' => 0,
				'summary'       => array(
					'language' => $language,
					'configured' => false,
				),
			);
		}

		$text = self::normalized_plain_text_for_review( $content );
		$letter_count = self::unicode_letter_count( $text );
		$minimum_letters = isset( $signals['minimum_letters'] ) ? max( 0, absint( $signals['minimum_letters'] ) ) : 600;
		$minimum_matches = isset( $signals['minimum_matches'] ) ? max( 1, absint( $signals['minimum_matches'] ) ) : 1;
		$minimum_matches_per_1000 = isset( $signals['minimum_matches_per_1000_letters'] ) ? (float) $signals['minimum_matches_per_1000_letters'] : 0.0;
		if ( $minimum_matches_per_1000 > 0 && $letter_count > 0 ) {
			$minimum_matches = max( $minimum_matches, (int) ceil( ( $letter_count / 1000 ) * $minimum_matches_per_1000 ) );
		}
		$characters = isset( $signals['required_characters'] ) && is_array( $signals['required_characters'] )
			? array_values( array_filter( array_map( 'strval', $signals['required_characters'] ) ) )
			: array();
		$match_count = self::language_script_signal_match_count( $text, $characters );
		$required_pattern = isset( $signals['required_pattern'] ) ? trim( (string) $signals['required_pattern'] ) : '';
		$pattern_match_count = '' !== $required_pattern ? self::language_script_signal_pattern_count( $text, $required_pattern ) : 0;
		if ( '' !== $required_pattern ) {
			$match_count += $pattern_match_count;
		}
		$infer_text_shadow_terms = ! array_key_exists( 'infer_text_shadow_terms', $signals ) || (bool) $signals['infer_text_shadow_terms'];
		$shadow_terms = self::language_script_signal_shadow_terms( $signals );
		if ( $infer_text_shadow_terms ) {
			$shadow_terms = array_merge( $shadow_terms, self::language_script_signal_text_shadow_terms( $text ) );
		}
		$shadow_exclusions = isset( $signals['shadow_exclusions'] ) && is_array( $signals['shadow_exclusions'] )
			? $signals['shadow_exclusions']
			: array();
		$shadow_context_exclusions = isset( $signals['shadow_context_exclusions'] ) && is_array( $signals['shadow_context_exclusions'] )
			? $signals['shadow_context_exclusions']
			: array();
		$shadow_issues = self::language_script_signal_shadow_term_issues( $text, $language, $shadow_terms, $shadow_exclusions, $shadow_context_exclusions );
		$issues = array_merge( $issues, $shadow_issues );

		if ( $letter_count >= $minimum_letters && ( $characters || '' !== $required_pattern ) && $match_count < $minimum_matches ) {
			$issues[] = self::qa_item(
				'target_language_script_signal_missing',
				'Translation appears to be target-language text with required local characters stripped or transliterated.',
				array(
					'language'        => $language,
					'letter_count'    => $letter_count,
					'minimum_letters' => $minimum_letters,
					'match_count'     => $match_count,
					'minimum_matches' => $minimum_matches,
					'minimum_matches_per_1000_letters' => $minimum_matches_per_1000,
					'required_characters' => $characters,
					'required_pattern' => $required_pattern,
					'pattern_match_count' => $pattern_match_count,
				)
			);
		}

		return array(
			'passed'        => empty( $issues ),
			'issues'        => $issues,
			'warnings'      => $warnings,
			'issue_count'   => count( $issues ),
			'warning_count' => count( $warnings ),
			'summary'       => array(
				'language'        => $language,
				'configured'      => true,
				'letter_count'    => $letter_count,
				'minimum_letters' => $minimum_letters,
				'match_count'     => $match_count,
				'minimum_matches' => $minimum_matches,
				'minimum_matches_per_1000_letters' => $minimum_matches_per_1000,
				'required_pattern' => $required_pattern,
				'policy'          => isset( $signals['policy'] ) ? (string) $signals['policy'] : '',
				'infer_text_shadow_terms' => $infer_text_shadow_terms,
				'shadow_term_count' => count( $shadow_terms ),
				'shadow_exclusion_count' => count( $shadow_exclusions ),
				'shadow_context_exclusion_count' => count( $shadow_context_exclusions ),
				'shadow_issue_count' => count( $shadow_issues ),
			),
		);
	}

	/**
	 * Effective script/diacritic QA policy for a language.
	 */
	private static function effective_language_script_signals( string $language ): array {
		$language = sanitize_key( $language );
		if ( '' === $language ) {
			return array();
		}

		$profile = self::language_review_profile( $language );
		$signals = isset( $profile['script_signals'] ) && is_array( $profile['script_signals'] ) ? $profile['script_signals'] : array();
		if ( ! empty( $signals ) ) {
			$signals['policy'] = isset( $signals['policy'] ) ? (string) $signals['policy'] : 'language_profile';
			return $signals;
		}

		return array();
	}

	/**
	 * Count Unicode letters in visible text.
	 */
	private static function unicode_letter_count( string $text ): int {
		if ( ! preg_match_all( '/\p{L}/u', $text, $matches ) ) {
			return 0;
		}

		return count( $matches[0] );
	}

	/**
	 * Count configured language signal characters in visible text.
	 *
	 * @param array<int,string> $characters Required character list.
	 */
	private static function language_script_signal_match_count( string $text, array $characters ): int {
		$count = 0;
		foreach ( $characters as $character ) {
			$character = trim( (string) $character );
			if ( '' === $character ) {
				continue;
			}
			$count += substr_count( $text, $character );
		}

		return $count;
	}

	/**
	 * Count matches for a configured Unicode script pattern.
	 */
	private static function language_script_signal_pattern_count( string $text, string $pattern ): int {
		if ( '' === $pattern ) {
			return 0;
		}

		$regex = '/' . str_replace( '/', '\/', $pattern ) . '/u';
		if ( false === @preg_match_all( $regex, $text, $matches ) ) {
			return 0;
		}

		return count( $matches[0] );
	}

	/**
	 * Terms to scan for ASCII shadows.
	 *
	 * @return array<int,string>
	 */
	private static function language_script_signal_shadow_terms( array $signals ): array {
		$terms = array();
		foreach ( array( 'shadow_terms', 'protected_terms', 'required_words' ) as $key ) {
			if ( empty( $signals[ $key ] ) || ! is_array( $signals[ $key ] ) ) {
				continue;
			}
			foreach ( $signals[ $key ] as $term ) {
				$term = self::normalize_review_text( (string) $term );
				if ( '' !== $term ) {
					$terms[ self::lower_review_text( $term ) ] = $term;
				}
			}
		}

		return array_values( $terms );
	}

	/**
	 * Terms inferred from this text when both accented and stripped forms appear.
	 *
	 * @return array<int,string>
	 */
	private static function language_script_signal_text_shadow_terms( string $text ): array {
		if ( ! preg_match_all( '/\p{L}{4,}/u', $text, $matches ) ) {
			return array();
		}

		$terms = array();
		$seen  = array();
		foreach ( $matches[0] as $word ) {
			$word = self::normalize_review_text( (string) $word );
			if ( '' === $word ) {
				continue;
			}
			$lower = self::lower_review_text( $word );
			$ascii = self::lower_review_text( remove_accents( $word ) );
			$seen[ $lower ] = true;
			if ( $ascii !== $lower ) {
				$terms[ $lower ] = $word;
			}
		}

		foreach ( $terms as $lower => $word ) {
			$shadow = self::lower_review_text( remove_accents( $word ) );
			if ( ! isset( $seen[ $shadow ] ) ) {
				unset( $terms[ $lower ] );
			}
		}

		return array_values( $terms );
	}

	/**
	 * Detect stripped-diacritic variants of protected target-language terms.
	 *
	 * @param array<int,string> $terms Protected target terms.
	 * @return array<int,array<string,mixed>>
	 */
	private static function language_script_signal_shadow_term_issues( string $text, string $language, array $terms, array $exclusions = array(), array $context_exclusions = array() ): array {
		$issues = array();
		if ( empty( $terms ) || '' === trim( $text ) ) {
			return $issues;
		}

		$lower_text = self::lower_review_text( $text );
		$excluded_shadows = self::language_script_signal_shadow_exclusion_map( $exclusions );
		foreach ( $terms as $term ) {
			$term = self::normalize_review_text( (string) $term );
			if ( '' === $term ) {
				continue;
			}
			$shadow = self::lower_review_text( remove_accents( $term ) );
			$term_lower = self::lower_review_text( $term );
			if ( '' === $shadow || $shadow === $term_lower ) {
				continue;
			}
			if ( isset( $excluded_shadows[ $shadow ] ) || isset( $excluded_shadows[ $term_lower ] ) ) {
				continue;
			}

			$pattern = '/(?<!\p{L})' . preg_quote( $shadow, '/' ) . '(?!\p{L})/u';
			if ( ! preg_match_all( $pattern, $lower_text, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( array_slice( $matches[0], 0, 5 ) as $match ) {
				if ( self::language_script_signal_shadow_context_excluded( $lower_text, (int) $match[1], $term, $shadow, $context_exclusions ) ) {
					continue;
				}
				$issues[] = self::qa_item(
					'target_language_ascii_shadow_term',
					'Translation contains a stripped-diacritic target-language term that should use the configured local orthography.',
					array(
						'language' => $language,
						'term'     => $term,
						'shadow'   => $shadow,
						'sample'   => self::review_text_sample_at_offset( $lower_text, (int) $match[1] ),
					)
				);
			}
		}

		return $issues;
	}

	/**
	 * Check if one stripped-shadow match is allowed by an explicit context regex.
	 *
	 * @param array<int,array<string,string>> $context_exclusions Context rules.
	 */
	private static function language_script_signal_shadow_context_excluded( string $lower_text, int $offset, string $term, string $shadow, array $context_exclusions ): bool {
		if ( empty( $context_exclusions ) ) {
			return false;
		}

		$term_lower = self::lower_review_text( self::normalize_review_text( $term ) );
		$shadow_lower = self::lower_review_text( self::normalize_review_text( $shadow ) );
		$window_start = max( 0, $offset - 80 );
		$window = substr( $lower_text, $window_start, 180 );

		foreach ( $context_exclusions as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$rule_term = isset( $rule['term'] ) ? self::lower_review_text( self::normalize_review_text( (string) $rule['term'] ) ) : '';
			$rule_shadow = isset( $rule['shadow'] ) ? self::lower_review_text( self::normalize_review_text( (string) $rule['shadow'] ) ) : '';
			if ( '' !== $rule_term && $rule_term !== $term_lower ) {
				continue;
			}
			if ( '' !== $rule_shadow && $rule_shadow !== $shadow_lower ) {
				continue;
			}

			$pattern = isset( $rule['pattern'] ) ? trim( (string) $rule['pattern'] ) : '';
			if ( '' === $pattern ) {
				continue;
			}
			$regex = '/' . str_replace( '/', '\/', $pattern ) . '/u';
			if ( false !== @preg_match( $regex, $window, $matches ) && ! empty( $matches ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int,string> $exclusions Excluded terms or their ASCII shadows.
	 * @return array<string,bool>
	 */
	private static function language_script_signal_shadow_exclusion_map( array $exclusions ): array {
		$map = array();
		foreach ( $exclusions as $exclusion ) {
			$exclusion = self::normalize_review_text( (string) $exclusion );
			if ( '' === $exclusion ) {
				continue;
			}
			$lower = self::lower_review_text( $exclusion );
			$shadow = self::lower_review_text( remove_accents( $exclusion ) );
			if ( '' !== $lower ) {
				$map[ $lower ] = true;
			}
			if ( '' !== $shadow ) {
				$map[ $shadow ] = true;
			}
		}

		return $map;
	}

	/**
	 * Lowercase review text with Unicode support when available.
	 */
	private static function lower_review_text( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Small sample around a QA hit.
	 */
	private static function review_text_sample_at_offset( string $text, int $offset ): string {
		$start = max( 0, $offset - 80 );
		if ( function_exists( 'mb_substr' ) ) {
			return trim( mb_substr( $text, $start, 180, 'UTF-8' ) );
		}

		return trim( substr( $text, $start, 180 ) );
	}

	/**
	 * Detect untranslated English source labels carried into target-language visible copy.
	 */
	private static function source_language_carryover_guardrails( string $content, string $source_content = '', string $language = '', int $source_id = 0 ): array {
		$issues   = array();
		$warnings = array();

		if ( '' === trim( $source_content ) || '' === $language || ! self::is_translation_language( $language ) ) {
			return array(
				'passed'        => true,
				'issues'        => array(),
				'warnings'      => array(),
				'issue_count'   => 0,
				'warning_count' => 0,
				'summary'       => array(
					'source_available' => '' !== trim( $source_content ),
					'candidate_count'  => 0,
				),
			);
		}

		$candidates = self::source_language_carryover_candidates( $source_content, $language, $source_id );
		$fragments  = self::text_fragments_for_copy_quality( $content );
		foreach ( $fragments as $fragment ) {
			$text = (string) $fragment['text'];
			foreach ( $candidates as $term ) {
				if ( self::text_contains_review_term( $text, $term ) ) {
					$issues[] = self::qa_item(
						'source_language_carryover',
						'Translation contains visible source-language copy that is not configured as a preserved term.',
						array(
							'language'  => $language,
							'term'      => $term,
							'text'      => $text,
							'block'     => $fragment['block'],
							'unique_id' => $fragment['unique_id'],
						)
					);
				}
			}
		}

		return array(
			'passed'        => empty( $issues ),
			'issues'        => $issues,
			'warnings'      => $warnings,
			'issue_count'   => count( $issues ),
			'warning_count' => count( $warnings ),
			'summary'       => array(
				'source_available' => true,
				'candidate_count'  => count( $candidates ),
			),
		);
	}

	/**
	 * Enforce page-specific address form when the source page defines who it speaks to.
	 */
	private static function address_form_guardrails( string $content, string $language = '', int $source_id = 0, string $title = '', string $excerpt = '' ): array {
		$issues     = array();
		$warnings   = array();
		$addressing = self::source_qa_addressing( $source_id, $language );
		$address_form = self::sanitize_address_form( (string) ( $addressing['address_form'] ?? '' ) );
		$audience     = self::sanitize_source_qa_audience( (string) ( $addressing['audience'] ?? '' ) );
		$forbidden    = self::address_form_forbidden_terms( $language, $address_form );

		if ( '' === $address_form || empty( $forbidden ) ) {
			return array(
				'passed'        => true,
				'issues'        => $issues,
				'warnings'      => $warnings,
				'issue_count'   => 0,
				'warning_count' => 0,
				'summary'       => array(
					'language'     => $language,
					'source_id'    => $source_id,
					'address_form' => $address_form,
					'audience'     => $audience,
					'forbidden_count' => count( $forbidden ),
				),
			);
		}

		$fragments = self::text_fragments_for_copy_quality( $content );
		if ( '' !== trim( $title ) ) {
			$fragments[] = array(
				'text'      => $title,
				'block'     => 'post_title',
				'unique_id' => 'post-title',
				'heading'   => true,
			);
		}
		if ( '' !== trim( $excerpt ) ) {
			$fragments[] = array(
				'text'      => $excerpt,
				'block'     => 'post_excerpt',
				'unique_id' => 'post-excerpt',
				'heading'   => false,
			);
		}

		foreach ( $fragments as $fragment ) {
			$text = (string) $fragment['text'];
			foreach ( $forbidden as $term ) {
				if ( self::text_contains_review_term( $text, $term ) ) {
					$issues[] = self::qa_item(
						'address_form_mismatch',
						'Translation uses an address form that conflicts with the source page audience setting.',
						array(
							'language'     => $language,
							'source_id'    => $source_id,
							'address_form' => $address_form,
							'audience'     => $audience,
							'term'         => $term,
							'text'         => $text,
							'block'        => $fragment['block'],
							'unique_id'    => $fragment['unique_id'],
						)
					);
				}
			}
		}

		return array(
			'passed'        => empty( $issues ),
			'issues'        => $issues,
			'warnings'      => $warnings,
			'issue_count'   => count( $issues ),
			'warning_count' => count( $warnings ),
			'summary'       => array(
				'language'     => $language,
				'source_id'    => $source_id,
				'address_form' => $address_form,
				'audience'     => $audience,
				'forbidden_count' => count( $forbidden ),
			),
		);
	}

	/**
	 * Terms that clearly conflict with a configured page address form.
	 *
	 * @return array<int,string>
	 */
	private static function address_form_forbidden_terms( string $language, string $address_form ): array {
		if ( 'singular' !== $address_form ) {
			return array();
		}

		$language = sanitize_key( $language );
		$map = array(
			'nb' => array( 'dere', 'deres', 'Dere', 'Deres' ),
			'es' => array( 'vosotros', 'vosotras', 'vuestro', 'vuestra', 'vuestros', 'vuestras', 'tenéis', 'intentáis', 'escribidnos', 'enviad', 'haced', 'seleccionad', 'marcáis', 'buscad', 'queréis', 'necesitaréis', 'podéis', 'veréis', 'copiadlo', 'mantened', 'compartáis', 'guardéis', 'pegad', 'seréis', 'elegid', 'obtened', 'id' ),
				'sv' => array( 'ni', 'Ni', 'er', 'Er', 'ert', 'Ert', 'era', 'Era' ),
				'da' => array( 'jer', 'jeres' ),
				'fi' => array( 'Yritättekö', 'teillä', 'teidän', 'teitä', 'valitkaa', 'Valitkaa', 'menkää', 'Menkää', 'klikatkaa', 'Klikatkaa', 'kopioikaa', 'Kopioikaa', 'lähettäkää', 'Lähettäkää', 'tarvitsette', 'Tarvitsette', 'voitte', 'Voitte', 'näette', 'Näette', 'saatte', 'Saatte', 'haluatteko', 'Haluatteko', 'älkää', 'Älkää', 'hankkikaa', 'Hankkikaa' ),
				'ar' => array( 'تحاولون', 'موقعكم', 'لكم', 'ابدأوا', 'أرسلوا', 'استخدموا', 'انتقلوا', 'اضغطوا', 'املأوا', 'اختاروا', 'لديكم', 'ستحتاجون', 'ارفعوا', 'ضعوا', 'ابحثوا', 'يمكنكم', 'سترون', 'انسخوها', 'انسخوه', 'حافظوا', 'تشاركوه', 'تحفظوه', 'مرروا', 'أدخلوا', 'الصقوا', 'تحويلكم', 'إعادتكم', 'أردتم', 'اطلبوا', 'انتظروا', 'اعثروا', 'شركتكم', 'تحققوا', 'تحتاجون', 'احصلوا', 'راسلوا', 'شاركوا', 'لديكم' ),
			);

		return $map[ $language ] ?? array();
	}

	/**
	 * Enforce configured locale-specific terminology where a language profile can
	 * safely define expected market terms.
	 */
	private static function locale_terminology_guardrails( string $content, string $language = '', string $title = '', string $excerpt = '' ): array {
		$issues   = array();
		$warnings = array();
		$profile  = self::language_review_profile( $language );
		$terms    = isset( $profile['localized_terms'] ) && is_array( $profile['localized_terms'] ) ? $profile['localized_terms'] : array();

		if ( '' === $language || ! self::is_translation_language( $language ) || empty( $terms ) ) {
			return array(
				'passed'        => true,
				'issues'        => array(),
				'warnings'      => array(),
				'issue_count'   => 0,
				'warning_count' => 0,
				'summary'       => array(
					'language'   => $language,
					'term_count' => count( $terms ),
				),
			);
		}

		$fragments = self::text_fragments_for_copy_quality( $content );
		if ( '' !== trim( $title ) ) {
			$fragments[] = array(
				'text'      => $title,
				'block'     => 'post_title',
				'unique_id' => 'post-title',
				'heading'   => true,
			);
		}
		if ( '' !== trim( $excerpt ) ) {
			$fragments[] = array(
				'text'      => $excerpt,
				'block'     => 'post_excerpt',
				'unique_id' => 'post-excerpt',
				'heading'   => false,
			);
		}

		foreach ( $fragments as $fragment ) {
			$text = (string) $fragment['text'];
			foreach ( $terms as $source_term => $expected_terms ) {
				$source_term = trim( (string) $source_term );
				if ( '' === $source_term || ! self::text_contains_review_term( $text, $source_term ) ) {
					continue;
				}

				$expected_terms = is_array( $expected_terms ) ? $expected_terms : array( $expected_terms );
				$expected_terms = array_values(
					array_filter(
						array_map(
							static function ( $term ): string {
								return trim( (string) $term );
							},
							$expected_terms
						),
						static function ( string $term ): bool {
							return '' !== $term;
						}
					)
				);

				if ( ! self::text_contains_any_expected_term( $text, $expected_terms ) ) {
					$issues[] = self::qa_item(
						'locale_terminology_mismatch',
						'Translation contains a source-language term where the language profile defines a locale-appropriate term.',
						array(
							'language'       => $language,
							'source_term'    => $source_term,
							'expected_terms' => $expected_terms,
							'text'           => $text,
							'block'          => $fragment['block'],
							'unique_id'      => $fragment['unique_id'],
						)
					);
				}
			}
		}

		return array(
			'passed'        => empty( $issues ),
			'issues'        => $issues,
			'warnings'      => $warnings,
			'issue_count'   => count( $issues ),
			'warning_count' => count( $warnings ),
			'summary'       => array(
				'language'   => $language,
				'term_count' => count( $terms ),
			),
		);
	}

	/**
	 * Mirror source-driven left/right block layout attributes for RTL languages.
	 *
	 * This is source-relative so rerunning upsert on an already mirrored
	 * translation does not flip the layout back.
	 */
	private static function mirror_rtl_block_layout_from_source( string $content, string $source_content, string $language ): string {
		if ( '' === trim( $content ) || '' === trim( $source_content ) || ! self::is_rtl_language( $language ) ) {
			return $content;
		}

		$blocks        = parse_blocks( $content );
		$source_blocks = parse_blocks( $source_content );
		if ( empty( $blocks ) || empty( $source_blocks ) ) {
			return $content;
		}

		self::mirror_rtl_blocks_from_source( $blocks, $source_blocks );

		return serialize_blocks( $blocks );
	}

	private static function is_rtl_language( string $language ): bool {
		$languages = self::languages();
		return isset( $languages[ $language ]['direction'] ) && 'rtl' === (string) $languages[ $language ]['direction'];
	}

	private static function mirror_rtl_blocks_from_source( array &$blocks, array $source_blocks ): void {
		foreach ( $blocks as $index => &$block ) {
			if ( ! isset( $source_blocks[ $index ] ) || ! is_array( $source_blocks[ $index ] ) ) {
				continue;
			}

			$source_block = $source_blocks[ $index ];
			if ( isset( $block['attrs'], $source_block['attrs'] ) && is_array( $block['attrs'] ) && is_array( $source_block['attrs'] ) ) {
				self::mirror_rtl_attrs_from_source( $block['attrs'], $source_block['attrs'] );
			}

			if ( isset( $block['innerBlocks'], $source_block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && is_array( $source_block['innerBlocks'] ) ) {
				self::mirror_rtl_blocks_from_source( $block['innerBlocks'], $source_block['innerBlocks'] );
			}
		}
		unset( $block );
	}

	private static function mirror_rtl_attrs_from_source( array &$attrs, array $source_attrs ): void {
		self::mirror_rtl_attr_pairs_from_source( $attrs, $source_attrs );

		foreach ( $attrs as $key => &$value ) {
			if ( isset( $source_attrs[ $key ] ) && is_array( $value ) && is_array( $source_attrs[ $key ] ) ) {
				self::mirror_rtl_attrs_from_source( $value, $source_attrs[ $key ] );
			}
		}
		unset( $value );
	}

	private static function mirror_rtl_attr_pairs_from_source( array &$attrs, array $source_attrs ): void {
		foreach ( array_keys( $source_attrs ) as $left_key ) {
			if ( false === strpos( $left_key, 'Left' ) ) {
				continue;
			}

			$right_key = str_replace( 'Left', 'Right', $left_key );
			if ( ! array_key_exists( $right_key, $source_attrs ) && ! array_key_exists( $left_key, $attrs ) ) {
				continue;
			}

			$source_left  = $source_attrs[ $left_key ] ?? null;
			$source_right = $source_attrs[ $right_key ] ?? null;
			if ( self::directional_attr_values_equivalent( $source_left, $source_right ) ) {
				continue;
			}

			$target_left  = $attrs[ $left_key ] ?? $source_left;
			$target_right = $attrs[ $right_key ] ?? $source_right;

			if ( null !== $source_left && null !== $source_right ) {
				$attrs[ $left_key ]  = $target_right;
				$attrs[ $right_key ] = $target_left;
			} elseif ( null !== $source_left ) {
				$attrs[ $right_key ] = $target_left;
				unset( $attrs[ $left_key ] );
			} else {
				$attrs[ $left_key ] = $target_right;
				unset( $attrs[ $right_key ] );
			}
		}
	}

	private static function directional_attr_values_equivalent( $left_value, $right_value ): bool {
		if ( is_array( $left_value ) || is_array( $right_value ) ) {
			return $left_value === $right_value;
		}

		return trim( (string) $left_value ) === trim( (string) $right_value );
	}

	/**
	 * Build source-language carryover candidates from the actual source page text.
	 *
	 * @return array<int,string>
	 */
	private static function source_language_carryover_candidates( string $source_content, string $language, int $source_id = 0 ): array {
		$profile = self::language_review_profile( $language );
		$allowed = array();
		foreach ( array( 'preserve_terms', 'never_translate_terms', 'source_carryover_homographs' ) as $key ) {
			if ( empty( $profile[ $key ] ) || ! is_array( $profile[ $key ] ) ) {
				continue;
			}
			foreach ( $profile[ $key ] as $term ) {
				$allowed[ strtolower( trim( (string) $term ) ) ] = true;
			}
		}
		foreach ( self::source_qa_carryover_preserve_terms( $source_id, $language ) as $term ) {
			$allowed[ strtolower( trim( (string) $term ) ) ] = true;
		}

		$candidates = array();
		foreach ( self::text_fragments_for_copy_quality( $source_content ) as $fragment ) {
			$text = (string) $fragment['text'];
			if ( empty( $fragment['heading'] ) && ! in_array( $fragment['block'], array( 'core/button', 'generateblocks/button' ), true ) && strlen( $text ) > 60 ) {
				continue;
			}
			if ( ! preg_match_all( '/(?<![\p{L}\p{N}_])[\p{Lu}][\p{L}\p{N}_-]{3,}(?![\p{L}\p{N}_])/u', $text, $matches ) ) {
				continue;
			}
			foreach ( $matches[0] as $term ) {
				$term = trim( (string) $term );
				if ( '' === $term || mb_strlen( $term ) <= 4 || isset( $allowed[ strtolower( $term ) ] ) ) {
					continue;
				}
				$candidates[ $term ] = $term;
			}
		}

		return array_values( $candidates );
	}

	/**
	 * Check a visible text fragment for a configured review term.
	 */
	private static function text_contains_review_term( string $text, string $term ): bool {
		$text = self::text_without_review_ignored_tokens( $text );
		$quoted = preg_quote( $term, '/' );
		$pattern = '/(?<![\p{L}\p{N}_])' . $quoted . '(?![\p{L}\p{N}_])/u';

		return 1 === preg_match( $pattern, $text );
	}

	private static function text_without_review_ignored_tokens( string $text ): string {
		$text = (string) preg_replace( '#https?://\S+#i', '', $text );
		$text = (string) preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '', $text );

		return $text;
	}

	/**
	 * Check whether text includes at least one configured target-language term.
	 *
	 * @param array<int,string> $terms Expected localized terms.
	 */
	private static function text_contains_any_expected_term( string $text, array $terms ): bool {
		if ( empty( $terms ) ) {
			return false;
		}

		$text = self::text_without_review_ignored_tokens( $text );
		foreach ( $terms as $term ) {
			if ( '' !== $term && false !== mb_stripos( $text, $term, 0, 'UTF-8' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Collect normalized text fragments from content blocks without running shortcodes.
	 *
	 * @return array<int,array{text:string,block:string,unique_id:string,heading:bool}>
	 */
	private static function text_fragments_for_copy_quality( string $content ): array {
		$fragments = array();
		self::collect_text_fragments_for_copy_quality( parse_blocks( $content ), $fragments );

		return $fragments;
	}

	/**
	 * Recursively collect text-bearing block fragments.
	 *
	 * @param array<int,array<string,mixed>>                      $blocks Parsed blocks.
	 * @param array<int,array{text:string,block:string,unique_id:string,heading:bool}> $fragments Collected fragments.
	 */
	private static function collect_text_fragments_for_copy_quality( array $blocks, array &$fragments ): void {
		foreach ( $blocks as $block ) {
			$name  = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$html  = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';
			$is_text_block = in_array( $name, array( 'core/heading', 'core/paragraph', 'core/list', 'core/quote', 'core/button', 'generateblocks/headline', 'generateblocks/button' ), true );

			if ( $is_text_block && '' !== trim( $html ) ) {
				$text = self::normalize_review_text( wp_strip_all_tags( strip_shortcodes( $html ) ) );
				if ( '' !== $text ) {
					$fragments[] = array(
						'text'      => $text,
						'block'     => $name,
						'unique_id' => isset( $attrs['uniqueId'] ) ? (string) $attrs['uniqueId'] : '',
						'heading'   => self::is_heading_block( $name, $attrs ),
					);
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::collect_text_fragments_for_copy_quality( $block['innerBlocks'], $fragments );
			}
		}
	}

	/**
	 * Plain content text for configured language pattern checks.
	 */
	private static function normalized_plain_text_for_review( string $content ): string {
		return self::normalize_review_text( wp_strip_all_tags( strip_shortcodes( $content ) ) );
	}

	/**
	 * Normalize review text consistently without changing stored content.
	 */
	private static function normalize_review_text( string $text ): string {
		$charset = get_bloginfo( 'charset' ) ?: 'UTF-8';
		$text    = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, $charset );
		$text    = preg_replace( '/\s+/u', ' ', $text );

		return trim( is_string( $text ) ? $text : '' );
	}

	/**
	 * Validate that a translation keeps the source page's semantic structure.
	 */
	private static function source_structure_guardrails( string $content, string $source_content = '', int $source_id = 0, string $language = '' ): array {
		$issues   = array();
		$warnings = array();
		$source   = self::semantic_structure_summary( $source_content );
		$target   = self::semantic_structure_summary( $content );

		if ( '' === trim( $source_content ) ) {
			return array(
				'passed'        => true,
				'issues'        => array(),
				'warnings'      => array(),
				'issue_count'   => 0,
				'warning_count' => 0,
				'summary'       => array(
					'source_available' => false,
					'source'           => $source,
					'translation'      => $target,
				),
			);
		}

		$posts_page_id = absint( get_option( 'page_for_posts' ) );
		if ( $posts_page_id && $source_id === $posts_page_id && self::is_query_driven_posts_archive_content( $content ) ) {
			return array(
				'passed'        => true,
				'issues'        => array(),
				'warnings'      => array(),
				'issue_count'   => 0,
				'warning_count' => 0,
				'summary'       => array(
					'source_available'        => true,
					'posts_page_archive_mode' => 'query_loop',
					'source'                  => $source,
					'translation'             => $target,
				),
			);
		}

		if ( $source['block_names'] !== $target['block_names'] ) {
			$issues[] = self::qa_item(
				'source_block_structure_mismatch',
				'Translated page must keep the same Gutenberg block type sequence as the source page.',
				self::sequence_mismatch_context( $source['block_names'], $target['block_names'] )
			);
		}
		if ( $source['heading_levels'] !== $target['heading_levels'] ) {
			$issues[] = self::qa_item(
				'source_heading_structure_mismatch',
				'Translated page must keep the same heading level sequence as the source page.',
				self::sequence_mismatch_context( $source['heading_levels'], $target['heading_levels'] )
			);
		}

		foreach ( array( 'button_count', 'image_count', 'link_count' ) as $key ) {
			if ( $source[ $key ] !== $target[ $key ] ) {
				if ( 'link_count' === $key && self::extra_internal_link_count_is_allowed( $source_content, $content, $source, $target, $source_id, $language ) ) {
					$warnings[] = self::qa_item(
						'source_link_count_extra_internal_links',
						'Translation adds moderated internal links beyond the source link count. Review placement and usefulness before publishing.',
						array(
							'source'      => $source[ $key ],
							'translation' => $target[ $key ],
							'extra'       => $target[ $key ] - $source[ $key ],
						)
					);
					continue;
				}
				$issues[] = self::qa_item(
					'source_' . $key . '_mismatch',
					'Translated page must keep the same ' . str_replace( '_', ' ', $key ) . ' as the source page.',
					array(
						'source'      => $source[ $key ],
						'translation' => $target[ $key ],
					)
				);
			}
		}

		if ( $source['text_unit_count'] > 0 ) {
			$text_unit_delta = abs( $source['text_unit_count'] - $target['text_unit_count'] ) / max( 1, $source['text_unit_count'] );
			if ( $text_unit_delta > 0.25 ) {
				$warnings[] = self::qa_item(
					'source_text_unit_count_drift',
					'Translated page has a substantially different number of text-bearing blocks than the source. Review for omitted or invented sections.',
					array(
						'source'      => $source['text_unit_count'],
						'translation' => $target['text_unit_count'],
					)
				);
			}
		}

		if ( $source['text_length'] > 0 ) {
			$text_ratio = $target['text_length'] / max( 1, $source['text_length'] );
			if ( $text_ratio < 0.45 || $text_ratio > 2.5 ) {
				$warnings[] = self::qa_item(
					'source_text_length_ratio_outlier',
					'Translated page text length is far from the source. Review source fidelity before publishing.',
					array(
						'source_length'      => $source['text_length'],
						'translation_length' => $target['text_length'],
						'ratio'              => round( $text_ratio, 2 ),
					)
				);
			}
		}

		return array(
			'passed'        => empty( $issues ),
			'issues'        => $issues,
			'warnings'      => $warnings,
			'issue_count'   => count( $issues ),
			'warning_count' => count( $warnings ),
			'summary'       => array(
				'source_available' => true,
				'source'           => $source,
				'translation'      => $target,
			),
		);
	}

	/**
	 * Allow reviewed translations to add a small number of useful internal links.
	 *
	 * Source structure parity catches missing buttons, images, and links. The
	 * internal-link suggestion workflow intentionally asks editors to add a few
	 * contextual links where useful, so extra links need a narrow exception that
	 * still rejects external additions, malformed URLs, and source-link loss.
	 *
	 * @param array<string,mixed> $source Source semantic summary.
	 * @param array<string,mixed> $target Translation semantic summary.
	 */
	private static function extra_internal_link_count_is_allowed( string $source_content, string $target_content, array $source, array $target, int $source_id = 0, string $language = '' ): bool {
		$source_count = absint( $source['link_count'] ?? 0 );
		$target_count = absint( $target['link_count'] ?? 0 );
		if ( $target_count <= $source_count ) {
			return false;
		}

		$extra_count = $target_count - $source_count;
		$moderation  = self::internal_link_moderation_policy();
		$max_extra   = max( 0, min( 3, absint( $moderation['max_suggestions'] ?? 3 ) ) );
		if ( $extra_count > $max_extra ) {
			return false;
		}

		$source_linked = self::linked_source_ids_for_content( $source_content );
		$target_linked = self::linked_source_ids_for_content( $target_content );
		foreach ( array_keys( $source_linked ) as $linked_source_id ) {
			if ( empty( $target_linked[ $linked_source_id ] ) ) {
				return false;
			}
		}

		$new_internal_count = 0;
		foreach ( array_keys( $target_linked ) as $linked_source_id ) {
			if ( empty( $source_linked[ $linked_source_id ] ) && $linked_source_id !== $source_id ) {
				$new_internal_count++;
			}
		}

		if ( $new_internal_count < $extra_count ) {
			return false;
		}

		return self::non_content_link_count( $target_content ) <= self::non_content_link_count( $source_content );
	}

	/**
	 * Count links that do not resolve to known internal content.
	 */
	private static function non_content_link_count( string $content ): int {
		if ( '' === $content ) {
			return 0;
		}
		if ( ! preg_match_all( '/\bhref=([\"\'])([^\"\']+)\1/i', $content, $matches ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $matches[2] as $raw_url ) {
			$url = trim( html_entity_decode( (string) $raw_url, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' ) );
			if ( '' === $url || '#' === $url[0] || preg_match( '/^(mailto|tel|sms|javascript):/i', $url ) ) {
				continue;
			}

			if ( ! self::source_id_from_internal_url( $url ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Build a compact semantic structure signature for source/translation comparison.
	 */
	private static function semantic_structure_summary( string $content ): array {
		$blocks = parse_blocks( $content );
		$summary = array(
			'block_names'     => array(),
			'heading_levels'  => array(),
			'button_count'    => 0,
			'image_count'     => 0,
			'link_count'      => 0,
			'text_unit_count' => 0,
			'text_length'     => strlen( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( $content ) ) ) ) ),
		);

		self::collect_semantic_structure( $blocks, $summary );
		if ( preg_match_all( '/\bhref=([\"\'])([^\"\']+)\1/i', $content, $matches ) ) {
			$summary['link_count'] = count( $matches[2] );
		}

		$summary['block_count']      = count( $summary['block_names'] );
		$summary['block_names_hash'] = md5( implode( '|', $summary['block_names'] ) );

		return $summary;
	}

	/**
	 * Recursively collect semantic block signals.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param array<string,mixed>            $summary Mutable summary.
	 */
	private static function collect_semantic_structure( array $blocks, array &$summary ): void {
		foreach ( $blocks as $block ) {
			$name  = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

			if ( '' !== $name ) {
				$summary['block_names'][] = $name;
			}
			if ( self::is_heading_block( $name, $attrs ) ) {
				$summary['heading_levels'][] = self::heading_level_for_block( $name, $attrs );
				$summary['text_unit_count']++;
			} elseif ( in_array( $name, array( 'core/paragraph', 'core/list', 'core/quote', 'generateblocks/headline' ), true ) ) {
				$summary['text_unit_count']++;
			}
			if ( in_array( $name, array( 'core/button', 'generateblocks/button' ), true ) ) {
				$summary['button_count']++;
				$summary['text_unit_count']++;
			}
			if ( in_array( $name, array( 'core/image', 'generateblocks/image' ), true ) ) {
				$summary['image_count']++;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::collect_semantic_structure( $block['innerBlocks'], $summary );
			}
		}
	}

	/**
	 * Heading level for a heading-like block.
	 */
	private static function heading_level_for_block( string $name, array $attrs ): int {
		if ( 'core/heading' === $name ) {
			return isset( $attrs['level'] ) ? max( 1, min( 6, absint( $attrs['level'] ) ) ) : 2;
		}

		$element = isset( $attrs['element'] ) ? strtolower( (string) $attrs['element'] ) : '';
		if ( preg_match( '/^h([1-6])$/', $element, $match ) ) {
			return (int) $match[1];
		}

		return 0;
	}

	/**
	 * Compact mismatch context for two ordered signatures.
	 *
	 * @param array<int,mixed> $source Source sequence.
	 * @param array<int,mixed> $target Target sequence.
	 */
	private static function sequence_mismatch_context( array $source, array $target ): array {
		$first_mismatch = null;
		$limit          = max( count( $source ), count( $target ) );
		for ( $index = 0; $index < $limit; $index++ ) {
			$source_value = $source[ $index ] ?? null;
			$target_value = $target[ $index ] ?? null;
			if ( $source_value !== $target_value ) {
				$first_mismatch = array(
					'index'       => $index,
					'source'      => $source_value,
					'translation' => $target_value,
				);
				break;
			}
		}

		return array(
			'source_count'      => count( $source ),
			'translation_count' => count( $target ),
			'first_mismatch'    => $first_mismatch,
			'source_hash'       => md5( implode( '|', array_map( 'strval', $source ) ) ),
			'translation_hash'  => md5( implode( '|', array_map( 'strval', $target ) ) ),
		);
	}

	/**
	 * Deep module for localized link maps.
	 *
	 * The same maps back content rewrite, QA, widgets, and menu custom links.
	 */
	private static function localized_link_module( string $language, bool $force_refresh = false ): array {
		static $cache = array();

		if ( $force_refresh ) {
			unset( $cache[ $language ] );
		}

		if ( isset( $cache[ $language ] ) ) {
			return $cache[ $language ];
		}

		$cache[ $language ] = array(
			'rewrite_map'  => self::localized_internal_link_map( $language, $force_refresh ),
			'expected_map' => self::localized_link_expected_target_map( $language, $force_refresh ),
		);

		return $cache[ $language ];
	}

	/**
	 * Public ability wrapper for moderated internal-link opportunity analysis.
	 */
	private static function internal_link_opportunities( array $input ): array {
		$content_id = absint( $input['content_id'] ?? ( $input['page_id'] ?? ( $input['post_id'] ?? ( $input['translation_id'] ?? 0 ) ) ) );
		if ( ! $content_id ) {
			$source_id = absint( $input['source_id'] ?? 0 );
			$language  = sanitize_key( (string) ( $input['language'] ?? '' ) );
			$content_id = $source_id && self::is_translation_language( $language )
				? self::find_translation_id( $source_id, $language, array( 'publish', 'draft', 'pending', 'private' ) )
				: $source_id;
		}

		$post = $content_id ? get_post( $content_id ) : null;
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) ) {
			return self::error( 'Content not found.' );
		}

		$language = sanitize_key( (string) ( $input['language'] ?? '' ) );
		$limit    = isset( $input['limit'] ) ? max( 1, min( 5, absint( $input['limit'] ) ) ) : 3;

		return array(
			'success' => true,
			'result'  => self::internal_link_opportunities_for_post( $post, $language, $limit ),
		);
	}

	/**
	 * Find a small set of relevant internal pages/posts worth considering.
	 *
	 * This intentionally suggests links; it does not insert them. Contextual
	 * placement remains a copy/editor decision so the site does not overlink.
	 */
	private static function internal_link_opportunities_for_post( WP_Post $post, string $language = '', int $limit = 3 ): array {
		$limit = max( 1, min( 5, $limit ) );
		if ( '' === $language ) {
			$language = self::language_for_context( (int) $post->ID );
		}

		$source_id   = self::source_id_for_context( (int) $post->ID );
		$source      = $source_id ? get_post( $source_id ) : null;
		$basis       = $source instanceof WP_Post ? $source : $post;
		$basis_text  = self::internal_link_analysis_text( $basis );
		$basis_terms = self::internal_link_terms( $basis_text );
		$linked_ids  = self::linked_source_ids_for_content( (string) $post->post_content );

		if ( '' === $basis_text || empty( $basis_terms ) ) {
			return array(
				'content'                      => self::source_summary_payload( $post ),
				'source_id'                    => $source_id,
				'language'                     => $language,
				'existing_internal_link_count' => count( $linked_ids ),
				'should_review'                => false,
				'opportunities'                => array(),
				'moderation'                   => self::internal_link_moderation_policy(),
			);
		}

		$candidates = array();
		foreach ( self::internal_link_candidate_posts( (int) $basis->ID ) as $candidate ) {
			$candidate_id = (int) $candidate->ID;
			if ( $candidate_id === (int) $basis->ID || isset( $linked_ids[ $candidate_id ] ) ) {
				continue;
			}

			$target = self::internal_link_target_for_language( $candidate_id, $language );
			if ( empty( $target['url'] ) ) {
				continue;
			}

			$score = self::internal_link_relevance_score( $basis, $candidate, $basis_text, $basis_terms );
			$minimum_score = 'source_fallback' === (string) ( $target['status'] ?? '' ) ? 60 : 24;
			if ( $score < $minimum_score ) {
				continue;
			}

			$candidates[] = array(
				'source_id'     => $candidate_id,
				'title'         => get_the_title( $candidate ),
				'post_type'     => (string) $candidate->post_type,
				'url'           => (string) $target['url'],
				'target_status' => (string) $target['status'],
				'score'         => $score,
				'reason'        => self::internal_link_reason( $basis, $candidate, $score ),
			);
		}

		usort(
			$candidates,
			static function ( array $a, array $b ): int {
				$score = (int) ( $b['score'] ?? 0 ) <=> (int) ( $a['score'] ?? 0 );
				if ( 0 !== $score ) {
					return $score;
				}

				return strcasecmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) );
			}
		);

		$opportunities = array_slice( $candidates, 0, $limit );
		$existing_count = count( $linked_ids );

		return array(
			'content'                      => self::source_summary_payload( $post ),
			'source_id'                    => $source_id,
			'language'                     => $language,
			'existing_internal_link_count' => $existing_count,
			'should_review'                => ! empty( $opportunities ) && $existing_count < 4,
			'opportunities'                => $opportunities,
			'moderation'                   => self::internal_link_moderation_policy(),
		);
	}

	/**
	 * Moderation rules returned to AI operators with every suggestion set.
	 */
	private static function internal_link_moderation_policy(): array {
		return array(
			'max_suggestions'        => 3,
			'max_new_links_guidance' => 'Usually add zero to two links. Add three only on long pages where all three are clearly useful.',
			'placement_rule'         => 'Only add a link where the surrounding sentence already creates a useful reason to click.',
			'avoid'                  => array(
				'Do not link every matching keyword.',
				'Do not add sitewide or repeated boilerplate links.',
				'Do not add a link if it distracts from the main CTA.',
			),
		);
	}

	/**
	 * Build the English/source-side analysis text for relevance matching.
	 */
	private static function internal_link_analysis_text( WP_Post $post ): string {
		return self::normalize_review_text(
			wp_strip_all_tags(
				strip_shortcodes(
					(string) get_the_title( $post ) . ' ' . (string) $post->post_excerpt . ' ' . (string) $post->post_content
				)
			)
		);
	}

	/**
	 * Extract weighted terms for internal-link relevance.
	 *
	 * @return array<string,int>
	 */
	private static function internal_link_terms( string $text ): array {
		$text = self::lower_review_text( remove_accents( self::normalize_review_text( $text ) ) );
		if ( '' === $text ) {
			return array();
		}

		$parts = preg_split( '/[^\p{L}\p{N}]+/u', $text );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$stop = self::internal_link_stopwords();
		$out  = array();
		foreach ( $parts as $part ) {
			$term = trim( (string) $part );
			if ( '' === $term || isset( $stop[ $term ] ) ) {
				continue;
			}
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $term, 'UTF-8' ) : strlen( $term );
			if ( $length < 4 || is_numeric( $term ) ) {
				continue;
			}
			$out[ $term ] = ( $out[ $term ] ?? 0 ) + 1;
		}

		arsort( $out );
		return array_slice( $out, 0, 80, true );
	}

	/**
	 * Shared stopword map for conservative internal-link matching.
	 *
	 * @return array<string,bool>
	 */
	private static function internal_link_stopwords(): array {
		$words = array(
			'about', 'after', 'also', 'because', 'before', 'being', 'built', 'could', 'every', 'first', 'from', 'have', 'here', 'into', 'more', 'most', 'only', 'other', 'same', 'should', 'some', 'than', 'that', 'their', 'there', 'these', 'this', 'through', 'what', 'when', 'where', 'which', 'while', 'with', 'without', 'would', 'your',
			'dere', 'dette', 'eller', 'etter', 'flere', 'fordi', 'gjennom', 'ikke', 'ingen', 'mellom', 'noen', 'ogsa', 'skal', 'slik', 'som', 'under', 'uten', 'vaere',
			'wordpress', 'page', 'pages', 'post', 'posts', 'site', 'website', 'content',
		);

		return array_fill_keys( $words, true );
	}

	/**
	 * Candidate source posts/pages for internal-link suggestions.
	 *
	 * @return array<int,WP_Post>
	 */
	private static function internal_link_candidate_posts( int $exclude_source_id ): array {
		$query = self::source_content_query(
			array(
				'post_status'            => array( 'publish' ),
				'posts_per_page'         => 500,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'update_post_term_cache' => true,
			)
		);

		return array_values(
			array_filter(
				$query->posts,
				static function ( $post ) use ( $exclude_source_id ): bool {
					return $post instanceof WP_Post
						&& (int) $post->ID !== $exclude_source_id
						&& ! self::is_translation_post( (int) $post->ID );
				}
			)
		);
	}

	/**
	 * Score relevance between a current source and one candidate source.
	 */
	private static function internal_link_relevance_score( WP_Post $basis, WP_Post $candidate, string $basis_text, array $basis_terms ): int {
		$candidate_title = self::normalize_review_text( get_the_title( $candidate ) );
		$candidate_text  = self::internal_link_analysis_text( $candidate );
		$candidate_terms = self::internal_link_terms( $candidate_title . ' ' . $candidate_text );
		$title_terms     = self::internal_link_terms( $candidate_title );
		$basis_lower     = self::lower_review_text( remove_accents( $basis_text ) );
		$title_lower     = self::lower_review_text( remove_accents( $candidate_title ) );
		$score           = 0;
		$title_overlap   = 0;
		$structural_match = false;

		if ( '' !== $title_lower && false !== strpos( $basis_lower, $title_lower ) ) {
			$score += 30;
		}

		foreach ( $title_terms as $term => $weight ) {
			if ( isset( $basis_terms[ $term ] ) ) {
				++$title_overlap;
				$score += 8 + min( 4, (int) $weight );
			}
		}

		$shared = array_intersect_key( $basis_terms, $candidate_terms );
		$score += min( 12, count( $shared ) );

		if ( (string) $basis->post_type === (string) $candidate->post_type ) {
			$score += 3;
		}
		if ( $basis->post_parent && (int) $basis->post_parent === (int) $candidate->post_parent ) {
			$structural_match = true;
			$score += 10;
		}
		if ( (int) $candidate->post_parent === (int) $basis->ID || (int) $basis->post_parent === (int) $candidate->ID ) {
			$structural_match = true;
			$score += 14;
		}

		$score += self::internal_link_taxonomy_overlap_score( $basis, $candidate );

		if ( ! $structural_match && false === strpos( $basis_lower, $title_lower ) && $title_overlap < 2 ) {
			return 0;
		}

		return $score;
	}

	/**
	 * Boost post candidates that share category or tag context.
	 */
	private static function internal_link_taxonomy_overlap_score( WP_Post $basis, WP_Post $candidate ): int {
		if ( 'post' !== (string) $basis->post_type || 'post' !== (string) $candidate->post_type ) {
			return 0;
		}

		$score = 0;
		foreach ( array( 'category' => 6, 'post_tag' => 4 ) as $taxonomy => $weight ) {
			$a = wp_get_post_terms( (int) $basis->ID, $taxonomy, array( 'fields' => 'ids' ) );
			$b = wp_get_post_terms( (int) $candidate->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $a ) || is_wp_error( $b ) || ! is_array( $a ) || ! is_array( $b ) ) {
				continue;
			}
			$score += count( array_intersect( array_map( 'intval', $a ), array_map( 'intval', $b ) ) ) * $weight;
		}

		return min( 8, $score );
	}

	/**
	 * Short reason for why a candidate was suggested.
	 */
	private static function internal_link_reason( WP_Post $basis, WP_Post $candidate, int $score ): string {
		if ( (int) $candidate->post_parent === (int) $basis->ID ) {
			return 'child_page_context';
		}
		if ( (int) $basis->post_parent === (int) $candidate->ID ) {
			return 'parent_page_context';
		}
		if ( $basis->post_parent && (int) $basis->post_parent === (int) $candidate->post_parent ) {
			return 'sibling_page_context';
		}
		if ( 'post' === (string) $basis->post_type && 'post' === (string) $candidate->post_type && self::internal_link_taxonomy_overlap_score( $basis, $candidate ) > 0 ) {
			return 'shared_taxonomy_context';
		}

		return $score >= 30 ? 'strong_textual_relevance' : 'textual_relevance';
	}

	/**
	 * Resolve the frontend target URL for a candidate in a language.
	 */
	private static function internal_link_target_for_language( int $source_id, string $language ): array {
		if ( self::is_translation_language( $language ) ) {
			$translation_id = self::find_translation_id( $source_id, $language, array( 'publish' ) );
			if ( $translation_id ) {
				return array(
					'url'    => get_permalink( $translation_id ) ?: '',
					'status' => 'localized',
				);
			}
		}

		return array(
			'url'    => get_permalink( $source_id ) ?: '',
			'status' => self::is_translation_language( $language ) ? 'source_fallback' : 'source',
		);
	}

	/**
	 * Source IDs already linked from content.
	 *
	 * @return array<int,bool>
	 */
	private static function linked_source_ids_for_content( string $content ): array {
		if ( '' === $content || ! preg_match_all( '/\bhref=([\"\'])([^\"\']+)\1/i', $content, $matches ) ) {
			return array();
		}

		$linked = array();
		foreach ( $matches[2] as $raw_url ) {
			$url = html_entity_decode( (string) $raw_url, ENT_QUOTES );
			$source_id = self::source_id_from_internal_url( $url );
			if ( $source_id ) {
				$linked[ $source_id ] = true;
			}
		}

		return $linked;
	}

	/**
	 * Resolve a site-internal URL to the English/source post ID.
	 */
	private static function source_id_from_internal_url( string $url ): int {
		if ( '' === $url || '#' === $url[0] || preg_match( '/^(mailto|tel|sms|javascript):/i', $url ) ) {
			return 0;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return 0;
		}
		$site_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( ! empty( $parts['host'] ) && strtolower( (string) $parts['host'] ) !== strtolower( $site_host ) ) {
			return 0;
		}

		$post_id = url_to_postid( $url );
		if ( ! $post_id && empty( $parts['host'] ) ) {
			$post_id = url_to_postid( home_url( self::normalized_url_path( $url ) ) );
		}
		if ( ! $post_id ) {
			return 0;
		}

		return self::source_id_for_context( (int) $post_id );
	}

	/**
	 * Deep module for frontend language context.
	 *
	 * Hooks consume this one surface instead of recomputing language, locale,
	 * source page, and alternate links independently.
	 */
	private static function frontend_surface( int $post_id = 0 ): array {
		static $cache = array();

		$post_id      = self::frontend_surface_post_id( $post_id );
		$request_path = self::current_request_path();
		$key          = $post_id . '|' . md5( $request_path );
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$language = 'en';
		if ( $post_id ) {
			$language = self::language_for_context( $post_id );
		} else {
			$path = $request_path;
			if ( '' !== $path ) {
				$first_segment = sanitize_key( strtok( $path, '/' ) );
				foreach ( self::languages() as $code => $config ) {
					$prefix = isset( $config['prefix'] ) ? sanitize_key( (string) $config['prefix'] ) : '';
					if ( '' !== $prefix && $prefix === $first_segment ) {
						$language = (string) $code;
						break;
					}
				}
			}
		}

		$languages = self::languages();
		$locale    = isset( $languages[ $language ]['locale'] ) ? (string) $languages[ $language ]['locale'] : 'en_GB';
		$wordpress_locale = isset( $languages[ $language ]['wordpress_locale'] ) && '' !== (string) $languages[ $language ]['wordpress_locale']
			? (string) $languages[ $language ]['wordpress_locale']
			: $locale;
		$direction = isset( $languages[ $language ]['direction'] ) && 'rtl' === (string) $languages[ $language ]['direction'] ? 'rtl' : 'ltr';

		$cache[ $key ] = array(
			'post_id'   => $post_id,
			'language'  => $language,
			'locale'    => '' !== $locale ? $locale : 'en_GB',
			'wordpress_locale' => '' !== $wordpress_locale ? $wordpress_locale : 'en_GB',
			'direction' => $direction,
			'source_id' => $post_id ? self::source_id_for_context( $post_id ) : 0,
			'links'     => is_404() ? self::language_links_for_not_found() : ( $post_id ? self::language_links_for_post( $post_id ) : array() ),
		);

		return $cache[ $key ];
	}

	/**
	 * Store lifecycle metadata for a translation in one place.
	 */
	private static function apply_translation_lifecycle_meta( int $translation_id, int $source_id, string $language, string $translation_status, WP_Post $source ): array {
		$localized_path = self::localized_path_for_post( $translation_id, $language );
		$source_hash    = self::source_hash( $source );

		update_post_meta( $translation_id, self::META_SOURCE_ID, $source_id );
		update_post_meta( $translation_id, self::META_LANGUAGE, $language );
		update_post_meta( $translation_id, self::META_SOURCE_HASH, $source_hash );
		update_post_meta( $translation_id, self::META_STATUS, $translation_status );
		update_post_meta( $translation_id, self::META_LOCALIZED_PATH, $localized_path );
		if ( in_array( $translation_status, array( 'reviewed', 'published' ), true ) ) {
			update_post_meta( $translation_id, self::META_REVIEWED_AT, gmdate( 'c' ) );
		} else {
			delete_post_meta( $translation_id, self::META_REVIEWED_AT );
		}
		self::sync_translation_index_row( $translation_id );
		$presentation = self::sync_source_presentation_meta( $translation_id, $source );

		return array(
			'localized_path' => $localized_path,
			'source_hash'    => $source_hash,
			'presentation'    => $presentation,
		);
	}

	/**
	 * Validate that translated shortcode blocks keep source shortcode identifiers.
	 */
	private static function shortcode_guardrails( string $content, string $source_content = '', int $source_id = 0 ): array {
		$issues              = array();
		$warnings            = array();
		$source_shortcodes   = self::shortcode_block_signatures( $source_content );
		$translated_shortcodes = self::shortcode_block_signatures( $content );

		foreach ( $translated_shortcodes as $index => $shortcode ) {
			if ( ! $shortcode['valid'] ) {
				$issues[] = self::qa_item(
					'invalid_shortcode_syntax',
					'Translated shortcode block contains invalid or translated shortcode syntax.',
					array(
						'index'     => $index,
						'shortcode' => $shortcode['raw'],
					)
				);
			}
		}

		$posts_page_id = absint( get_option( 'page_for_posts' ) );
		if ( $posts_page_id && $source_id === $posts_page_id && self::is_query_driven_posts_archive_content( $content ) ) {
			return array(
				'passed'        => empty( $issues ),
				'issues'        => $issues,
				'warnings'      => $warnings,
				'issue_count'   => count( $issues ),
				'warning_count' => count( $warnings ),
				'summary'       => array(
					'source_shortcode_count'      => count( $source_shortcodes ),
					'translation_shortcode_count' => count( $translated_shortcodes ),
					'posts_page_archive_mode'     => 'query_loop',
				),
			);
		}

		if ( $source_shortcodes ) {
			if ( count( $source_shortcodes ) !== count( $translated_shortcodes ) ) {
				$issues[] = self::qa_item(
					'shortcode_count_mismatch',
					'Translated page must keep the same number of shortcode blocks as the source page.',
					array(
						'source_count'      => count( $source_shortcodes ),
						'translation_count' => count( $translated_shortcodes ),
					)
				);
			}

			foreach ( $source_shortcodes as $index => $source_shortcode ) {
				if ( ! isset( $translated_shortcodes[ $index ] ) ) {
					continue;
				}
				$translated_shortcode = $translated_shortcodes[ $index ];
				if (
					$source_shortcode['tag'] !== $translated_shortcode['tag']
					|| $source_shortcode['attribute_keys'] !== $translated_shortcode['attribute_keys']
				) {
					$issues[] = self::qa_item(
						'translated_shortcode_identifier',
						'Shortcode tag names and attribute keys must not be translated.',
						array(
							'index'                => $index,
							'source_shortcode'     => $source_shortcode['raw'],
							'translated_shortcode' => $translated_shortcode['raw'],
							'expected_tag'         => $source_shortcode['tag'],
							'actual_tag'           => $translated_shortcode['tag'],
							'expected_attributes'  => $source_shortcode['attribute_keys'],
							'actual_attributes'    => $translated_shortcode['attribute_keys'],
						)
					);
				}
			}
		}

		return array(
			'passed'        => empty( $issues ),
			'issues'        => $issues,
			'warnings'      => $warnings,
			'issue_count'   => count( $issues ),
			'warning_count' => count( $warnings ),
			'summary'       => array(
				'source_shortcode_count'      => count( $source_shortcodes ),
				'translation_shortcode_count' => count( $translated_shortcodes ),
			),
		);
	}

	/**
	 * Extract normalized shortcode signatures from Gutenberg shortcode blocks.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function shortcode_block_signatures( string $content ): array {
		$shortcodes = array();
		$blocks     = parse_blocks( $content );

		self::collect_shortcode_block_signatures( $blocks, $shortcodes );

		return $shortcodes;
	}

	/**
	 * Walk parsed blocks and collect shortcode signatures.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param array<int,array<string,mixed>> $shortcodes Collected shortcodes.
	 */
	private static function collect_shortcode_block_signatures( array $blocks, array &$shortcodes ): void {
		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
			if ( 'core/shortcode' === $name ) {
				$raw_shortcode = self::shortcode_text_from_block( $block );
				$shortcodes[]  = self::shortcode_signature( $raw_shortcode );
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::collect_shortcode_block_signatures( $block['innerBlocks'], $shortcodes );
			}
		}
	}

	/**
	 * Get the saved shortcode text from a parsed core/shortcode block.
	 */
	private static function shortcode_text_from_block( array $block ): string {
		$parts = isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : array();
		$text  = implode( '', array_map( 'strval', $parts ) );
		if ( '' === trim( $text ) && isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$text = $block['innerHTML'];
		}

		return trim( wp_strip_all_tags( $text ) );
	}

	/**
	 * Normalize shortcode tag and attribute keys without executing the shortcode.
	 *
	 * @return array<string,mixed>
	 */
	private static function shortcode_signature( string $shortcode ): array {
		$raw = trim( $shortcode );
		$signature = array(
			'raw'            => $raw,
			'valid'          => false,
			'tag'            => '',
			'attribute_keys' => array(),
		);

		if ( ! preg_match( '/^\[\s*([A-Za-z0-9_-]+)\b([^\]]*)\]\s*$/', $raw, $match ) ) {
			return $signature;
		}

		$attribute_source = (string) $match[2];
		$attribute_keys   = array();
		if ( preg_match_all( '/(?:^|\s)([A-Za-z_][A-Za-z0-9_-]*)\s*=/', $attribute_source, $attribute_matches ) ) {
			$attribute_keys = $attribute_matches[1];
		}

		$signature['valid']          = true;
		$signature['tag']            = (string) $match[1];
		$signature['attribute_keys'] = array_values( $attribute_keys );

		return $signature;
	}

	/**
	 * Run technical Gutenberg/editor guardrails for translated page content.
	 */
	private static function gutenberg_guardrails( string $content ): array {
		$issues   = array();
		$warnings = array();
		$summary  = array(
			'has_blocks'         => has_blocks( $content ),
			'block_count'        => 0,
			'unique_id_count'    => 0,
			'duplicate_ids'      => array(),
			'grid_count'         => 0,
			'grid_child_count'   => 0,
			'unknown_blocks'     => array(),
			'typography_blocks'  => array(),
				'markup_blocks'      => array(),
				'long_word_risks'    => array(),
				'saved_markup'       => array(),
				'content_length'     => strlen( $content ),
			);

		if ( false !== strpos( $content, '&amp;amp;' ) ) {
			$issues[] = self::qa_item( 'double_escaped_html_entity', 'Content contains double-escaped HTML entities such as &amp;amp;.', array( 'entity' => '&amp;amp;' ) );
		}
		if ( preg_match( '/<!--\s+wp:html\b/', $content ) ) {
			$issues[] = self::qa_item( 'custom_html_block', 'Custom HTML blocks are not allowed in translated pages.' );
		}
		if ( preg_match( '/<(script|style)\b/i', $content, $match ) ) {
			$issues[] = self::qa_item( 'inline_script_or_style', 'Translated page content contains inline script or style markup.', array( 'tag' => strtolower( $match[1] ) ) );
		}

		$blocks = parse_blocks( $content );
		if ( $summary['has_blocks'] && empty( $blocks ) ) {
			$issues[] = self::qa_item( 'block_parse_failed', 'WordPress detected block comments, but parse_blocks() returned no blocks.' );
		}

		$seen_unique_ids = array();
		$grid_ids        = array();
		$grid_refs       = array();

		self::inspect_gutenberg_blocks( $blocks, $seen_unique_ids, $grid_ids, $grid_refs, $summary, $issues, $warnings );
		$saved_markup = self::gutenberg_saved_markup_integrity( $content );
		$summary['saved_markup'] = $saved_markup['summary'];
		if ( ! empty( $saved_markup['issues'] ) ) {
			$issues = array_merge( $issues, $saved_markup['issues'] );
		}
		if ( ! empty( $saved_markup['warnings'] ) ) {
			$warnings = array_merge( $warnings, $saved_markup['warnings'] );
		}

		$duplicates = array();
		foreach ( $seen_unique_ids as $unique_id => $count ) {
			if ( $count > 1 ) {
				$duplicates[] = $unique_id;
			}
		}
		if ( $duplicates ) {
			$summary['duplicate_ids'] = $duplicates;
			$issues[] = self::qa_item( 'duplicate_generateblocks_unique_id', 'GenerateBlocks uniqueId values must be unique within a page.', array( 'unique_ids' => $duplicates ) );
		}

		foreach ( $grid_refs as $ref ) {
			if ( ! isset( $grid_ids[ $ref['grid_id'] ] ) ) {
				$issues[] = self::qa_item( 'dangling_generateblocks_grid_ref', 'A GenerateBlocks grid child references a missing gridId.', $ref );
			}
		}

		$summary['unique_id_count'] = count( $seen_unique_ids );
		$summary['grid_count']      = count( $grid_ids );
		$summary['grid_child_count'] = count( $grid_refs );

		if ( $summary['content_length'] > 50000 ) {
			$warnings[] = self::qa_item( 'large_editor_payload', 'Translated page content is large and may make the block editor slow to open.', array( 'bytes' => $summary['content_length'] ) );
		}

		return array(
			'passed'        => empty( $issues ),
			'issues'        => $issues,
			'warnings'      => $warnings,
			'issue_count'   => count( $issues ),
			'warning_count' => count( $warnings ),
			'summary'       => $summary,
		);
	}

	/**
	 * Walk parsed Gutenberg blocks and collect editor-risk signals.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed block tree.
	 * @param array<string,int>              $seen_unique_ids Unique ID counters.
	 * @param array<string,bool>             $grid_ids Known GenerateBlocks grid IDs.
	 * @param array<int,array<string,mixed>> $grid_refs GenerateBlocks grid child refs.
	 * @param array<string,mixed>            $summary QA summary.
	 * @param array<int,array<string,mixed>> $issues Hard QA failures.
	 * @param array<int,array<string,mixed>> $warnings Soft QA warnings.
	 */
	private static function inspect_gutenberg_blocks( array $blocks, array &$seen_unique_ids, array &$grid_ids, array &$grid_refs, array &$summary, array &$issues, array &$warnings, bool $narrow_context = false ): void {
		$registry = class_exists( 'WP_Block_Type_Registry' ) ? WP_Block_Type_Registry::get_instance() : null;

		foreach ( $blocks as $block ) {
			$name  = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$is_narrow_context = $narrow_context || self::is_narrow_block_context( $attrs );

			if ( '' !== $name ) {
				$summary['block_count']++;
				if ( $registry && ! $registry->is_registered( $name ) && ! in_array( $name, $summary['unknown_blocks'], true ) ) {
					$summary['unknown_blocks'][] = $name;
					$issues[] = self::qa_item( 'unknown_block_type', 'The page contains a block type that is not registered in WordPress.', array( 'block' => $name ) );
				}
				if ( 'core/html' === $name ) {
					$summary['markup_blocks'][] = $name;
					$issues[] = self::qa_item( 'custom_html_block', 'Custom HTML blocks are not allowed in translated pages.' );
				}
			}

			if ( isset( $attrs['uniqueId'] ) && is_string( $attrs['uniqueId'] ) && '' !== $attrs['uniqueId'] ) {
				$unique_id = $attrs['uniqueId'];
				$seen_unique_ids[ $unique_id ] = isset( $seen_unique_ids[ $unique_id ] ) ? $seen_unique_ids[ $unique_id ] + 1 : 1;
				if ( 'generateblocks/grid' === $name ) {
					$grid_ids[ $unique_id ] = true;
				}
			}

			if ( ! empty( $attrs['isGrid'] ) && isset( $attrs['gridId'] ) && is_string( $attrs['gridId'] ) ) {
				$grid_refs[] = array(
					'block'     => $name,
					'unique_id' => isset( $attrs['uniqueId'] ) ? (string) $attrs['uniqueId'] : '',
					'grid_id'   => $attrs['gridId'],
				);
			}

			$has_controlled_heading_fit = $is_narrow_context && self::is_heading_block( $name, $attrs ) && self::has_controlled_heading_fit_adjustment( $attrs );
			if ( self::has_block_typography_override( $attrs ) && ! $has_controlled_heading_fit ) {
				$block_id = isset( $attrs['uniqueId'] ) ? (string) $attrs['uniqueId'] : $name;
				$summary['typography_blocks'][] = $block_id;
				$issues[] = self::qa_item( 'block_typography_override', 'Translated pages must not add block-level typography overrides.', array( 'block' => $name, 'id' => $block_id ) );
			}

			if ( $is_narrow_context && self::is_heading_block( $name, $attrs ) && ! $has_controlled_heading_fit ) {
				foreach ( self::long_word_risks_for_block( $block ) as $risk ) {
					$summary['long_word_risks'][] = $risk;
					$warnings[] = self::qa_item( 'long_word_in_narrow_heading', 'A heading in a narrow column contains a long word that may need typography or layout review. Do not rewrite the copy automatically.', $risk );
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::inspect_gutenberg_blocks( $block['innerBlocks'], $seen_unique_ids, $grid_ids, $grid_refs, $summary, $issues, $warnings, $is_narrow_context );
			}
		}
	}

	/**
	 * Whether block attributes indicate a narrow visual column.
	 */
	private static function is_narrow_block_context( array $attrs ): bool {
		if ( ! isset( $attrs['sizing'] ) || ! is_array( $attrs['sizing'] ) ) {
			return false;
		}

		foreach ( array( 'width', 'widthTablet' ) as $key ) {
			if ( ! isset( $attrs['sizing'][ $key ] ) ) {
				continue;
			}
			$value = (string) $attrs['sizing'][ $key ];
			if ( preg_match( '/^([0-9]+(?:\.[0-9]+)?)%$/', $value, $match ) && (float) $match[1] <= 40.0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a block is rendered as heading-like text.
	 */
	private static function is_heading_block( string $name, array $attrs ): bool {
		if ( 'core/heading' === $name ) {
			return true;
		}
		if ( 'generateblocks/headline' !== $name ) {
			return false;
		}

		$element = isset( $attrs['element'] ) ? strtolower( (string) $attrs['element'] ) : '';
		return in_array( $element, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true );
	}

	/**
	 * Controlled exception for translated headings that need a smaller font to fit.
	 */
	private static function has_controlled_heading_fit_adjustment( array $attrs ): bool {
		$font_size = '';
		if ( isset( $attrs['fontSize'] ) ) {
			$font_size = (string) $attrs['fontSize'];
		} elseif ( isset( $attrs['typography']['fontSize'] ) ) {
			$font_size = (string) $attrs['typography']['fontSize'];
		}

		if ( '' === $font_size || ! preg_match( '/^([0-9]+(?:\.[0-9]+)?)(?:px)?$/', $font_size, $match ) ) {
			return false;
		}

		$size = (float) $match[1];
		if ( $size < 28.0 || $size > 34.0 ) {
			return false;
		}

		$allowed_keys = array( 'uniqueId', 'element', 'blockVersion', 'spacing', 'textColor', 'fontSize' );
		foreach ( array_keys( $attrs ) as $key ) {
			if ( ! in_array( $key, $allowed_keys, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Return long-word risks for a heading block.
	 *
	 * @param array<string,mixed> $block Parsed block.
	 * @return array<int,array<string,mixed>>
	 */
	private static function long_word_risks_for_block( array $block ): array {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$html  = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';
		$text  = trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' ) );
		if ( '' === $text ) {
			return array();
		}

		$risks = array();
		$words = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $words ) ) {
			return array();
		}

		foreach ( $words as $word ) {
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $word, 'UTF-8' ) : strlen( $word );
			if ( $length < 17 ) {
				continue;
			}
			$risks[] = array(
				'block'     => isset( $block['blockName'] ) ? (string) $block['blockName'] : '',
				'unique_id' => isset( $attrs['uniqueId'] ) ? (string) $attrs['uniqueId'] : '',
				'word'      => $word,
				'length'    => $length,
				'text'      => $text,
				'recommended_action' => 'review_typography_or_layout_before_copy_changes',
			);
		}

		return $risks;
	}

	/**
	 * Detect per-block typography overrides disallowed by translation page guardrails.
	 */
	private static function has_block_typography_override( array $attrs ): bool {
		if ( isset( $attrs['typography'] ) && is_array( $attrs['typography'] ) && ! empty( $attrs['typography'] ) ) {
			return true;
		}

		$disallowed = array(
			'fontFamily',
			'fontSize',
			'fontSizeMobile',
			'fontSizeTablet',
			'fontWeight',
			'lineHeight',
			'lineHeightMobile',
			'lineHeightTablet',
			'letterSpacing',
			'textTransform',
		);

		foreach ( $disallowed as $key ) {
			if ( array_key_exists( $key, $attrs ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a QA item.
	 */
	private static function qa_item( string $code, string $message, array $context = array() ): array {
		return array(
			'code'    => $code,
			'message' => $message,
			'context' => $context,
		);
	}

	/**
	 * Extract stable QA item codes.
	 *
	 * @param array<int,array<string,mixed>> $items QA issues or warnings.
	 * @return array<int,string>
	 */
	private static function qa_item_codes( array $items ): array {
		$codes = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['code'] ) ) {
				continue;
			}
			$code = sanitize_key( (string) $item['code'] );
			if ( '' !== $code ) {
				$codes[ $code ] = $code;
			}
		}

		return array_values( $codes );
	}

	/**
	 * Sanitize expected QA code lists from local regression corpus data.
	 *
	 * @param mixed $codes Raw codes.
	 * @return array<int,string>
	 */
	private static function sanitize_qa_code_list( $codes ): array {
		if ( ! is_array( $codes ) ) {
			return array();
		}

		$clean = array();
		foreach ( $codes as $code ) {
			$code = sanitize_key( (string) $code );
			if ( '' !== $code ) {
				$clean[ $code ] = $code;
			}
		}

		return array_values( $clean );
	}

	/**
	 * Sanitize QA terms from input.
	 */
	private static function qa_terms_from_input( $terms ): array {
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$clean = array();
		foreach ( $terms as $term ) {
			$term = trim( sanitize_text_field( (string) $term ) );
			if ( '' !== $term ) {
				$clean[] = $term;
			}
		}

		return $clean;
	}

	/**
	 * Default English fragments that usually indicate incomplete homepage translation.
	 */
	private static function default_forbidden_terms(): array {
		return array(
			'Get a homepage that makes people contact you',
			'Email us your homepage URL',
			'What you get',
			'Who is this for',
			'What stops people from contacting you',
			'When the homepage is not producing enough enquiries',
			'Useful for someone else',
			'Homepage%20review',
		);
	}

	/**
	 * Title-case a slug for placeholder parent pages.
	 */
	private static function title_from_slug( string $slug ): string {
		return ucwords( str_replace( '-', ' ', $slug ) );
	}

	/**
	 * Error payload.
	 */
	private static function error( string $message ): array {
		return array(
			'success' => false,
			'message' => $message,
		);
	}
}

register_activation_hook( __FILE__, array( 'Devenia_AI_Translations', 'activate' ) );
Devenia_AI_Translations::init();
