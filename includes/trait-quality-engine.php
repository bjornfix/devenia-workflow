<?php
/**
 * Translation quality engine helpers.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Quality_Engine {
	/**
	 * Build a cached content snapshot for quality and guardrail checks.
	 *
	 * @param array<string,mixed> $translation Translation payload.
	 * @return array<string,mixed>
	 */
	private static function quality_engine_content_snapshot( WP_Post $post, array $translation = array() ): array {
		$cache_key = array(
			'post_id'       => (int) $post->ID,
			'modified_gmt'  => (string) $post->post_modified_gmt,
			'content_hash'  => hash( 'sha256', (string) $post->post_content ),
			'translation_language' => (string) ( $translation['language'] ?? '' ),
		);
		$cached = self::request_analysis_cache_get( 'quality_content_snapshot', $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$content   = (string) $post->post_content;
		$source_id = ! empty( $translation['source_id'] ) ? absint( $translation['source_id'] ) : absint( get_post_meta( (int) $post->ID, self::META_SOURCE_ID, true ) );
		$snapshot  = array(
			'post_id'        => (int) $post->ID,
			'content'        => $content,
			'text'           => trim( wp_strip_all_tags( do_shortcode( $content ) ) ),
			'has_blocks'     => has_blocks( $content ),
			'language'       => sanitize_key( (string) ( $translation['language'] ?? get_post_meta( (int) $post->ID, self::META_LANGUAGE, true ) ) ),
			'source_id'      => $source_id,
			'source_content' => $source_id ? (string) get_post_field( 'post_content', $source_id ) : '',
		);

		return self::request_analysis_cache_set( 'quality_content_snapshot', $cache_key, $snapshot );
	}
}
