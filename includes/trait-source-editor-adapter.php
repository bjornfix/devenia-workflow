<?php
/**
 * Builder-aware source editor contract.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Source_Editor_Adapter {
	/**
	 * Resolve the native editor contract for a source object.
	 *
	 * @param WP_Post $source Source post/page.
	 * @return array<string,mixed>
	 */
	private static function source_editor_contract( WP_Post $source ): array {
		$is_page  = 'page' === (string) $source->post_type;
		$contract = array(
			'editor'                 => 'wordpress',
			'available'              => true,
			'read_ability'           => $is_page ? 'content/get-page' : 'content/get-post',
			'content_write_ability'  => $is_page ? 'content/update-page' : 'content/update-post',
			'design_write_ability'   => 'devenia-site-presentation/apply-article-contract-pattern',
			'completion_abilities'   => array(
				$is_page ? 'content/update-page' : 'content/update-post',
				'ai-translations/mark-source-content-integrity-reviewed',
			),
			'native_controls_only'   => true,
			'public_route_immutable' => true,
			'instructions'           => '',
			'reason'                 => 'wordpress_native_source_editor',
		);

		/**
		 * Let one native builder Adapter replace the default WordPress editor contract.
		 *
		 * @param array<string,mixed> $contract Default editor contract.
		 * @param WP_Post             $source Source post/page.
		 */
		$contract = apply_filters( 'devenia_ai_workflow_source_editor_contract', $contract, $source );
		$contract = is_array( $contract ) ? $contract : array();

		$editor = sanitize_key( (string) ( $contract['editor'] ?? 'wordpress' ) );
		return array(
			'editor'                 => '' !== $editor ? $editor : 'wordpress',
			'available'              => ! empty( $contract['available'] ),
			'read_ability'           => sanitize_text_field( (string) ( $contract['read_ability'] ?? '' ) ),
			'content_write_ability'  => sanitize_text_field( (string) ( $contract['content_write_ability'] ?? '' ) ),
			'design_write_ability'   => sanitize_text_field( (string) ( $contract['design_write_ability'] ?? '' ) ),
			'completion_abilities'   => isset( $contract['completion_abilities'] ) && is_array( $contract['completion_abilities'] )
				? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $contract['completion_abilities'] ) ) ) )
				: array(),
			'native_controls_only'   => ! empty( $contract['native_controls_only'] ),
			'public_route_immutable' => ! empty( $contract['public_route_immutable'] ),
			'instructions'           => sanitize_textarea_field( (string) ( $contract['instructions'] ?? '' ) ),
			'reason'                 => sanitize_key( (string) ( $contract['reason'] ?? '' ) ),
		);
	}

	/**
	 * Public read model for one source editor contract.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function source_editor_status( array $input ): array {
		$source_id = absint( $input['source_id'] ?? 0 );
		$source    = $source_id ? get_post( $source_id ) : null;
		if ( ! $source instanceof WP_Post || self::is_translation_post( $source_id ) ) {
			return self::error( 'Source content not found.' );
		}

		return array(
			'success'       => true,
			'source_id'     => $source_id,
			'source_title'  => get_the_title( $source ),
			'post_type'     => (string) $source->post_type,
			'public_url'    => get_permalink( $source_id ) ?: '',
			'source_editor' => self::source_editor_contract( $source ),
		);
	}
}
