<?php
/**
 * Canonical WordPress content resolution for internal links.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Internal_Content_Link_Resolver {

	/**
	 * Resolve an internal URL through core routes or the translation registry.
	 *
	 * Custom localized translation routes are not guaranteed to resolve through
	 * url_to_postid(), so the indexed canonical target path is authoritative when
	 * the core resolver has no result.
	 *
	 * @param array<string,mixed> $parts Parsed URL parts when already available.
	 */
	private static function wordpress_content_id_from_internal_url( string $url, array $parts = array() ): int {
		if ( '' === $url ) {
			return 0;
		}
		if ( empty( $parts ) ) {
			$parts = wp_parse_url( $url );
		}
		if ( ! is_array( $parts ) ) {
			return 0;
		}

		$site_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$host = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		if ( '' !== $host && $site_host !== $host ) {
			return 0;
		}

		$query_post_id = self::wordpress_content_query_id_from_parts( $parts );
		if ( $query_post_id && get_post( $query_post_id ) ) {
			return $query_post_id;
		}

		$post_id = url_to_postid( $url );
		$path = self::normalized_url_path( $url );
		if ( ! $post_id && '' === $host && '' !== $path ) {
			$post_id = url_to_postid( home_url( $path ) );
		}
		if ( $post_id && get_post( $post_id ) ) {
			return $post_id;
		}

		if ( '' === $path ) {
			return 0;
		}
		$translation_id = self::find_translation_id_by_target_path( $path, array( 'publish' ) );

		return $translation_id && get_post( $translation_id ) ? $translation_id : 0;
	}
}
