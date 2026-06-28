<?php
/**
 * Optional Rank Math integration.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AI_Translation_Workflow_RankMath_Addon {
	/**
	 * Register Rank Math hooks.
	 */
	public static function register(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_register_hooks' ), 20 );
		add_filter( 'ai_translation_workflow_quick_copy_edit_segment_block_names', array( __CLASS__, 'add_quick_copy_edit_segment_blocks' ) );
		add_filter( 'ai_translation_workflow_quick_copy_edit_updated_block', array( __CLASS__, 'sync_quick_copy_edit_faq_attrs' ), 10, 2 );
	}

	/**
	 * Register hooks only when Rank Math is available.
	 */
	public static function maybe_register_hooks(): void {
		if ( ! self::is_active() ) {
			return;
		}

		add_filter( 'rank_math/opengraph/type', array( __CLASS__, 'filter_translated_posts_page_opengraph_type' ), 20 );
		add_filter( 'rank_math/frontend/canonical', array( __CLASS__, 'filter_translated_posts_page_canonical' ), 20 );
		add_filter( 'rank_math/frontend/title', array( __CLASS__, 'filter_translated_posts_page_seo_title' ), 20 );
		add_filter( 'rank_math/frontend/description', array( __CLASS__, 'filter_translated_posts_page_seo_description' ), 20 );
		add_filter( 'rank_math/json_ld', array( __CLASS__, 'filter_translated_posts_page_json_ld' ), 99, 2 );
		add_filter( 'ai_translation_workflow_sync_seo_meta', array( __CLASS__, 'sync_seo_meta' ), 10, 4 );
		add_filter( 'ai_translation_workflow_seo_meta_state', array( __CLASS__, 'seo_meta_state' ), 10, 2 );
		add_filter( 'ai_translation_workflow_route_integrity_issues', array( __CLASS__, 'route_integrity_issues' ), 10, 4 );
		add_filter( 'ai_translation_workflow_repair_translation_self_redirects', array( __CLASS__, 'repair_translation_self_redirects' ), 10, 3 );
	}

	public static function filter_translated_posts_page_opengraph_type( string $type ): string {
		return Devenia_AI_Translations::is_translated_posts_page_request() ? 'website' : $type;
	}

	public static function filter_translated_posts_page_canonical( string $canonical ): string {
		if ( ! Devenia_AI_Translations::is_translated_posts_page_request() ) {
			return $canonical;
		}

		$base_url = Devenia_AI_Translations::translated_posts_page_base_url();
		return $base_url ?: $canonical;
	}

	public static function filter_translated_posts_page_seo_title( string $title ): string {
		if ( ! Devenia_AI_Translations::is_translated_posts_page_request() ) {
			return $title;
		}

		$post_title = trim( wp_strip_all_tags( get_the_title( get_queried_object_id() ) ) );
		if ( '' === $post_title ) {
			$post_title = __( 'Blog', 'devenia-ai-translations' );
		}

		$site_name = trim( wp_strip_all_tags( get_bloginfo( 'name' ) ) );
		return '' === $site_name ? $post_title : sprintf( '%s | %s', $post_title, $site_name );
	}

	public static function filter_translated_posts_page_seo_description( string $description ): string {
		if ( ! Devenia_AI_Translations::is_translated_posts_page_request() ) {
			return $description;
		}

		$runtime_description = Devenia_AI_Translations::translated_posts_page_meta_description( Devenia_AI_Translations::frontend_language() );
		return '' !== $runtime_description ? $runtime_description : $description;
	}

	/**
	 * @param array<string,mixed> $data Rank Math JSON-LD data.
	 * @param mixed               $jsonld Rank Math JSON-LD context object.
	 * @return array<string,mixed>
	 */
	public static function filter_translated_posts_page_json_ld( array $data, $jsonld ): array {
		if ( ! Devenia_AI_Translations::is_translated_posts_page_request() ) {
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
				unset( $data[ $key ]['datePublished'], $data[ $key ]['dateModified'], $data[ $key ]['primaryImageOfPage'] );
			}
		}

		return $data;
	}

	/**
	 * Add Rank Math FAQ blocks to the Quick Copy Edit segment adapter.
	 *
	 * @param array<int,string> $names Block names.
	 * @return array<int,string>
	 */
	public static function add_quick_copy_edit_segment_blocks( array $names ): array {
		return self::merge_block_names( $names, array( 'rank-math/faq-block' ) );
	}

	/**
	 * Keep Rank Math FAQ block attributes in sync after a frontend text edit.
	 *
	 * @param array<string,mixed> $block Parsed block.
	 * @param array<string,mixed> $context Quick Copy Edit update context.
	 * @return array<string,mixed>
	 */
	public static function sync_quick_copy_edit_faq_attrs( array $block, array $context ): array {
		if ( 'rank-math/faq-block' !== (string) ( $context['block_name'] ?? '' ) ) {
			return $block;
		}

		$segment_index = $context['segment_index'] ?? null;
		if ( null === $segment_index || ! isset( $block['attrs']['questions'] ) || ! is_array( $block['attrs']['questions'] ) ) {
			return $block;
		}

		$segment_index  = absint( $segment_index );
		$question_index = (int) floor( $segment_index / 2 );
		$field          = 0 === $segment_index % 2 ? 'title' : 'content';
		if ( ! isset( $block['attrs']['questions'][ $question_index ] ) || ! is_array( $block['attrs']['questions'][ $question_index ] ) ) {
			return $block;
		}

		$text = sanitize_text_field( (string) ( $context['text'] ?? '' ) );
		if ( 'content' === $field ) {
			$text = sanitize_textarea_field( (string) ( $context['text'] ?? '' ) );
		}

		$block['attrs']['questions'][ $question_index ][ $field ] = $text;
		return $block;
	}

	/**
	 * @param array<string,mixed>  $result Current sync result.
	 * @param array<string,string> $fields Prepared SEO fields.
	 * @param array<string,mixed>  $context Adapter context.
	 */
	public static function sync_seo_meta( array $result, int $post_id, array $fields, array $context ): array {
		unset( $context );

		$updated = is_array( $result['updated'] ?? null ) ? $result['updated'] : array();
		foreach ( array( 'title' => 'rank_math_title', 'description' => 'rank_math_description', 'focus_keyword' => 'rank_math_focus_keyword' ) as $field => $meta_key ) {
			$value = trim( (string) ( $fields[ $field ] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}
			if ( 'description' === $field ) {
				update_post_meta( $post_id, $meta_key, sanitize_textarea_field( $value ) );
			} else {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( $value ) );
			}
			$updated[] = $meta_key;
		}

		if ( $updated ) {
			clean_post_cache( $post_id );
		}
		$result['success']  = true;
		$result['updated']  = array_values( array_unique( $updated ) );
		$result['adapters'] = self::append_adapter( $result['adapters'] ?? array() );
		return $result;
	}

	/**
	 * @param array<string,mixed> $state Current SEO metadata state.
	 */
	public static function seo_meta_state( array $state, WP_Post $post ): array {
		$post_id       = (int) $post->ID;
		$current_title = self::normalize_text( wp_strip_all_tags( get_the_title( $post ) ) );
		$seo_title     = self::normalize_text( (string) get_post_meta( $post_id, 'rank_math_title', true ) );
		$description   = self::normalize_text( (string) get_post_meta( $post_id, 'rank_math_description', true ) );
		$focus_keyword = self::normalize_text( (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ) );
		$stale_fields  = is_array( $state['stale_fields'] ?? null ) ? $state['stale_fields'] : array();

		if ( '' !== $seo_title && '' !== $current_title && ! self::seo_title_matches_post_title( $seo_title, $current_title ) ) {
			$stale_fields[] = 'rank_math_title';
		}

		$state['passed']            = empty( $stale_fields );
		$state['state']             = empty( $stale_fields ) ? ( '' === $seo_title ? 'default_title_pattern' : 'current' ) : 'stale';
		$state['stale_fields']      = array_values( array_unique( $stale_fields ) );
		$state['has_custom_title']  = '' !== $seo_title;
		$state['has_description']   = '' !== $description;
		$state['has_focus_keyword'] = '' !== $focus_keyword;
		$state['adapters']          = self::append_adapter( $state['adapters'] ?? array() );
		return $state;
	}

	/**
	 * @param array<int,array<string,mixed>> $issues Current route integrity issues.
	 * @param array<string,mixed>            $context Route context.
	 * @return array<int,array<string,mixed>>
	 */
	public static function route_integrity_issues( array $issues, int $translation_id, string $language, array $context ): array {
		unset( $language );

		$permalink = (string) ( $context['permalink'] ?? get_permalink( $translation_id ) );
		foreach ( self::self_redirects_for_url( $permalink ) as $redirect ) {
			$issues[] = array(
				'code'    => 'localized_permalink_self_redirect',
				'message' => 'An active Rank Math redirection source matches this translated page canonical URL and redirects back to the same URL.',
				'context' => $redirect,
			);
		}
		return $issues;
	}

	/**
	 * @param array<string,mixed> $result Current repair result.
	 * @return array<string,mixed>
	 */
	public static function repair_translation_self_redirects( array $result, int $translation_id, bool $dry_run ): array {
		$conflicts = self::self_redirects_for_url( (string) get_permalink( $translation_id ) );
		$result['adapters'] = self::append_adapter( $result['adapters'] ?? array() );
		if ( empty( $conflicts ) ) {
			return $result;
		}
		if ( $dry_run ) {
			$result['success']   = true;
			$result['changed']   = true;
			$result['dry_run']   = true;
			$result['conflicts'] = $conflicts;
			return $result;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		$ids   = array_values( array_filter( array_unique( array_map( 'absint', wp_list_pluck( $conflicts, 'id' ) ) ) ) );
		if ( empty( $ids ) || ! self::redirections_table_exists( $table ) ) {
			$result['success'] = false;
			$result['message'] = 'Rank Math self-redirect conflicts were detected, but no removable redirection IDs were available.';
			return $result;
		}

		$deleted = 0;
		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$delete_result = $wpdb->query( $wpdb->prepare( 'DELETE FROM `' . esc_sql( $table ) . '` WHERE id = %d', $id ) );
			if ( false === $delete_result ) {
				$result['success'] = false;
				$result['message'] = 'Failed to delete Rank Math self-redirect conflicts.';
				return $result;
			}
			$deleted += (int) $delete_result;
		}

		$result['success']       = true;
		$result['changed']       = 0 < $deleted;
		$result['deleted_count'] = $deleted;
		$result['conflicts']     = $conflicts;
		return $result;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function self_redirects_for_url( string $url ): array {
		$target_path = Devenia_AI_Translations::normalized_url_path( $url );
		if ( '' === $target_path ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		if ( ! self::redirections_table_exists( $table ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, sources, url_to, header_code, status FROM `' . esc_sql( $table ) . '` WHERE status = %s', 'active' ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$conflicts = array();
		foreach ( $rows as $row ) {
			$destination = isset( $row['url_to'] ) ? (string) $row['url_to'] : '';
			if ( Devenia_AI_Translations::normalized_url_path( $destination ) !== $target_path ) {
				continue;
			}
			foreach ( self::redirection_sources( $row['sources'] ?? array() ) as $source ) {
				if ( 'exact' !== (string) ( $source['comparison'] ?? 'exact' ) ) {
					continue;
				}
				$pattern = isset( $source['pattern'] ) ? (string) $source['pattern'] : '';
				if ( Devenia_AI_Translations::normalized_url_path( $pattern ) !== $target_path ) {
					continue;
				}
				$conflicts[] = array(
					'id'          => absint( $row['id'] ?? 0 ),
					'source'      => $pattern,
					'destination' => $destination,
					'path'        => $target_path,
					'header_code' => absint( $row['header_code'] ?? 0 ),
					'status'      => (string) ( $row['status'] ?? '' ),
				);
			}
		}
		return $conflicts;
	}

	/**
	 * @param mixed $raw Raw DB value.
	 * @return array<int,array<string,mixed>>
	 */
	private static function redirection_sources( $raw ): array {
		$decoded = is_string( $raw ) ? maybe_unserialize( $raw ) : $raw;
		if ( is_string( $decoded ) ) {
			$json = json_decode( $decoded, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				$decoded = $json;
			}
		}
		return is_array( $decoded ) ? array_values( array_filter( $decoded, 'is_array' ) ) : array();
	}

	private static function redirections_table_exists( string $table ): bool {
		static $cache = array();
		if ( isset( $cache[ $table ] ) ) {
			return $cache[ $table ];
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cache[ $table ] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		return $cache[ $table ];
	}

	private static function seo_title_matches_post_title( string $seo_title, string $post_title ): bool {
		$seo_title  = self::normalize_text( $seo_title );
		$post_title = self::normalize_text( $post_title );
		if ( '' === $seo_title || '' === $post_title || false !== strpos( $seo_title, '%title%' ) ) {
			return true;
		}
		$seo_lower  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $seo_title, 'UTF-8' ) : strtolower( $seo_title );
		$post_lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $post_title, 'UTF-8' ) : strtolower( $post_title );
		return $seo_lower === $post_lower || false !== strpos( $seo_lower, $post_lower );
	}

	private static function normalize_text( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( is_string( $text ) ? $text : '' );
	}

	/**
	 * @param mixed $adapters Current adapter list.
	 * @return array<int,string>
	 */
	private static function append_adapter( $adapters ): array {
		$adapters   = is_array( $adapters ) ? $adapters : array();
		$adapters[] = 'rank_math';
		return array_values( array_unique( array_map( 'sanitize_key', $adapters ) ) );
	}

	/**
	 * @param array<int,string> $names Existing block names.
	 * @param array<int,string> $extra Extra block names.
	 * @return array<int,string>
	 */
	private static function merge_block_names( array $names, array $extra ): array {
		return array_values( array_unique( array_merge( array_map( 'strval', $names ), array_map( 'strval', $extra ) ) ) );
	}

	private static function is_active(): bool {
		return defined( 'RANK_MATH_VERSION' )
			|| class_exists( 'RankMath' )
			|| class_exists( '\RankMath\Helper' );
	}
}

AI_Translation_Workflow_RankMath_Addon::register();
