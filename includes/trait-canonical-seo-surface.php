<?php
/**
 * Canonical SEO Surface Module.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Canonical_SEO_Surface {
	/**
	 * Resolve the complete SEO member of one Staged Translation Artifact.
	 *
	 * A Translation Job surface is complete even when artifact.seo is omitted:
	 * title and description derive deterministically, while focus keyword has one
	 * canonical empty identity.
	 *
	 * @param array<string,mixed> $artifact Submitted artifact.
	 * @return array{title:string,description:string,focus_keyword:string}
	 */
	private static function canonical_seo_surface_for_translation_job( array $artifact, string $title, string $excerpt, string $content ): array {
		$seo_input       = isset( $artifact['seo'] ) && is_array( $artifact['seo'] ) ? $artifact['seo'] : array();
		$seo_title       = self::canonical_seo_input_value( $seo_input, array( 'seo_title', 'title' ) );
		$seo_description = self::canonical_seo_input_value( $seo_input, array( 'seo_description', 'description' ) );

		return array(
			'title'         => '' !== $seo_title ? $seo_title : $title,
			'description'   => '' !== $seo_description ? $seo_description : ( '' !== $excerpt ? $excerpt : self::seo_description_from_content( $content ) ),
			'focus_keyword' => self::canonical_seo_input_value( $seo_input, array( 'focus_keyword', 'keyword' ) ),
		);
	}

	/**
	 * Synchronize SEO through explicit per-field operations.
	 *
	 * Localized Presentation Publication supplies _canonical_seo_surface from
	 * the immutable surface manifest and therefore gets complete-replace
	 * semantics. General upsert and generated-source callers get patch/derive:
	 * title and description retain their deterministic defaults, but a genuinely
	 * absent focus field is preserved rather than silently treated as empty.
	 *
	 * @param array<string,mixed> $input Caller input.
	 * @return array<string,mixed>
	 */
	private static function sync_translation_seo_meta( int $translation_id, array $input, string $title, string $excerpt, string $content ): array {
		$complete_surface = isset( $input['_canonical_seo_surface'] ) && is_array( $input['_canonical_seo_surface'] )
			? $input['_canonical_seo_surface']
			: null;
		$seo_input        = isset( $input['seo'] ) && is_array( $input['seo'] ) ? $input['seo'] : array();
		$mode             = is_array( $complete_surface ) ? 'complete_replace' : 'patch_derive';
		$operations       = is_array( $complete_surface )
			? self::canonical_seo_complete_operations( $complete_surface )
			: self::canonical_seo_patch_operations( $seo_input, $title, $excerpt, $content );
		$result           = array(
			'success'        => true,
			'updated'        => array(),
			'auto_generated' => 'patch_derive' === $mode && empty( $seo_input ),
			'surface_mode'   => $mode,
			'operations'     => $operations,
			'adapters'       => array(),
		);

		$result = apply_filters(
			'devenia_workflow_translation_sync_seo_meta',
			$result,
			$translation_id,
			$operations,
			array(
				'surface_mode'   => $mode,
				'auto_generated' => 'patch_derive' === $mode && empty( $seo_input ),
				'content_hash'   => hash( 'sha256', wp_strip_all_tags( $title ) . "\n" . wp_strip_all_tags( $excerpt ) . "\n" . self::normalize_review_text( wp_strip_all_tags( $content ) ) ),
			)
		);

		return is_array( $result ) ? $result : array(
			'success'        => false,
			'updated'        => array(),
			'auto_generated' => 'patch_derive' === $mode && empty( $seo_input ),
			'surface_mode'   => $mode,
			'operations'     => $operations,
			'adapters'       => array(),
			'message'        => 'SEO metadata adapter returned an invalid result.',
		);
	}

	/**
	 * Turn one complete canonical surface into exact replacement operations.
	 *
	 * @param array<string,mixed> $surface Immutable manifest SEO member.
	 * @return array<string,array{operation:string,value:string}>
	 */
	private static function canonical_seo_complete_operations( array $surface ): array {
		$operations = array();
		foreach ( array( 'title', 'description', 'focus_keyword' ) as $field ) {
			$value = (string) ( $surface[ $field ] ?? '' );
			$operations[ $field ] = array(
				'operation' => '' === $value ? 'delete' : 'set',
				'value'     => $value,
			);
		}

		return $operations;
	}

	/**
	 * Resolve optional legacy/general input without erasing field presence.
	 *
	 * @param array<string,mixed> $seo_input Optional caller SEO object.
	 * @return array<string,array{operation:string,value:string}>
	 */
	private static function canonical_seo_patch_operations( array $seo_input, string $title, string $excerpt, string $content ): array {
		$seo_title       = self::canonical_seo_input_value( $seo_input, array( 'seo_title', 'title' ) );
		$seo_description = self::canonical_seo_input_value( $seo_input, array( 'seo_description', 'description' ) );
		$focus_input     = self::canonical_seo_input_field( $seo_input, array( 'focus_keyword', 'keyword' ) );
		$focus_value     = (string) $focus_input['value'];

		return array(
			'title'       => array(
				'operation' => 'set',
				'value'     => '' !== $seo_title ? $seo_title : $title,
			),
			'description' => array(
				'operation' => 'set',
				'value'     => '' !== $seo_description ? $seo_description : ( '' !== $excerpt ? $excerpt : self::seo_description_from_content( $content ) ),
			),
			'focus_keyword' => array(
				'operation' => empty( $focus_input['present'] ) ? 'preserve' : ( '' === $focus_value ? 'delete' : 'set' ),
				'value'     => $focus_value,
			),
		);
	}

	/**
	 * Resolve the first present alias and retain explicit-empty identity.
	 *
	 * @param array<string,mixed> $seo_input Input object.
	 * @param array<int,string>   $keys      Accepted aliases in precedence order.
	 * @return array{present:bool,key:string,value:string}
	 */
	private static function canonical_seo_input_field( array $seo_input, array $keys ): array {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $seo_input ) ) {
				return array(
					'present' => true,
					'key'     => $key,
					'value'   => self::normalize_review_text( (string) $seo_input[ $key ] ),
				);
			}
		}

		return array( 'present' => false, 'key' => '', 'value' => '' );
	}

	/**
	 * Resolve one SEO value while keeping presence handling inside this Module.
	 *
	 * @param array<string,mixed> $seo_input Input object.
	 * @param array<int,string>   $keys      Accepted aliases in precedence order.
	 */
	private static function canonical_seo_input_value( array $seo_input, array $keys ): string {
		$field = self::canonical_seo_input_field( $seo_input, $keys );
		return (string) $field['value'];
	}
}
