<?php
/**
 * Presentation adapter helpers.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Presentation_Adapter {
	/**
	 * Resolve a presentation surface through one seam.
	 *
	 * @param array<string,mixed> $input Presentation request.
	 * @return array<string,mixed>
	 */
	private static function presentation_surface_from_adapter( array $input ): array {
		$surface_type = self::presentation_surface_type( $input );

		switch ( $surface_type ) {
		case 'singular':
			$post_id = absint( $input['post_id'] ?? 0 );
			if ( ! $post_id ) {
				$post_id = self::frontend_surface_post_id();
			}
			return $post_id ? self::singular_presentation_surface( $post_id, (string) ( $input['language'] ?? '' ) ) : array();
		case 'blog_archive':
			return self::blog_archive_presentation_surface( $input );
		case 'author_archive':
			return self::author_archive_presentation_surface( $input );
		case 'term_archive':
			return self::term_archive_presentation_surface( $input );
		case 'not_found':
			return self::not_found_presentation_surface( (string) ( $input['language'] ?? '' ) );
		default:
			return array();
		}
	}
}
