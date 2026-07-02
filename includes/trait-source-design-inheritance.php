<?php
/**
 * Source design inheritance for AI Translation Workflow.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Source_Design_Inheritance {
	/**
	 * Source design contract used by translation workers.
	 *
	 * The source block tree is the canonical design. Translators localize the
	 * fragments returned here; they do not rebuild the tree per language.
	 *
	 * @return array<string,mixed>
	 */
	private static function source_design_contract( WP_Post $source ): array {
		$content   = self::normalize_gutenberg_content_for_storage( (string) $source->post_content );
		$blocks    = parse_blocks( $content );
		$fragments = array();
		self::collect_source_design_fragments( $blocks, $fragments );

		return array(
			'schema_version' => 1,
			'source_id'      => (int) $source->ID,
			'design_hash'    => self::source_design_signature_hash( $content ),
			'fragment_count' => count( $fragments ),
			'fragments'      => $fragments,
		);
	}

	/**
	 * Project localized text into the source design tree.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function inherited_source_design_content( WP_Post $source, array $input, string $language ): array {
		$fragments = self::localized_fragment_map( $input['localized_fragments'] ?? array() );
		if ( empty( $fragments ) ) {
			return array(
				'success' => false,
				'message' => 'localized_fragments are required when inherit_source_design is enabled. Translators should supply text, not a redesigned Gutenberg tree.',
				'code'    => 'localized_fragments_required',
				'source_design' => self::source_design_contract( $source ),
			);
		}

		$source_content = self::normalize_gutenberg_content_for_storage( (string) $source->post_content );
		$blocks         = parse_blocks( $source_content );
		if ( empty( $blocks ) && has_blocks( $source_content ) ) {
			return self::error( 'Source Gutenberg content could not be parsed for design inheritance.' );
		}

		$contract = self::source_design_contract( $source );
		$missing  = self::missing_localized_fragment_keys( $contract['fragments'], $fragments );
		$strict   = ! array_key_exists( 'strict_source_design_fragments', $input ) || ! empty( $input['strict_source_design_fragments'] );
		if ( $strict && $missing ) {
			return array(
				'success'        => false,
				'message'        => 'Localized fragments are incomplete. Translate every source design fragment before saving.',
				'code'           => 'localized_fragments_incomplete',
				'missing_keys'   => $missing,
				'missing_count'  => count( $missing ),
				'source_design'  => $contract,
			);
		}

		$stats = array(
			'projected_count' => 0,
			'provided_count'  => count( $fragments ),
			'missing_count'   => count( $missing ),
		);
		self::project_source_design_blocks( $blocks, $fragments, $stats );

		$content = serialize_blocks( $blocks );
		$content = self::mirror_rtl_block_layout_from_source( $content, $source_content, $language );

		return array(
			'success'       => true,
			'content'       => $content,
			'source_design' => array_merge(
				$contract,
				array(
					'projection' => $stats,
				)
			),
		);
	}

	/**
	 * Sanitize localized text fragments supplied by a translator.
	 *
	 * @param mixed $raw Raw fragment list.
	 * @return array<string,string>
	 */
	private static function localized_fragment_map( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$map = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = self::source_design_fragment_key_from_input( (string) ( $row['key'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}
			if ( array_key_exists( 'html', $row ) && '' !== trim( (string) $row['html'] ) ) {
				$map[ $key ] = wp_kses_post( (string) $row['html'] );
				continue;
			}
			if ( array_key_exists( 'text', $row ) ) {
				$map[ $key ] = esc_html( self::normalize_review_text( (string) $row['text'] ) );
			}
		}

		return $map;
	}

	/**
	 * Normalize a source design fragment key from ability input.
	 */
	private static function source_design_fragment_key_from_input( string $key ): string {
		$key = trim( $key );
		if ( '' === $key || strlen( $key ) > 180 ) {
			return '';
		}

		return preg_match( '/^[a-z0-9:_\\-.]+$/i', $key ) ? $key : '';
	}

	/**
	 * Missing source fragment keys for strict inherited-design saves.
	 *
	 * @param array<int,array<string,mixed>> $source_fragments Source contract fragments.
	 * @param array<string,string>           $localized        Localized values by key.
	 * @return array<int,string>
	 */
	private static function missing_localized_fragment_keys( array $source_fragments, array $localized ): array {
		$missing = array();
		foreach ( $source_fragments as $fragment ) {
			$key = (string) ( $fragment['key'] ?? '' );
			if ( '' !== $key && ! array_key_exists( $key, $localized ) ) {
				$missing[] = $key;
			}
		}

		return $missing;
	}

	/**
	 * Collect text fragments from a source block tree with stable projection keys.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed source blocks.
	 * @param array<int,array<string,mixed>> $fragments Output fragments.
	 */
	private static function collect_source_design_fragments( array $blocks, array &$fragments, string $path = '' ): void {
		foreach ( $blocks as $index => $block ) {
			$current_path = '' === $path ? (string) $index : $path . '.' . $index;
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name  = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$html  = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';

			if ( in_array( $name, self::copy_quality_text_block_names(), true ) && '' !== trim( $html ) ) {
				$text = self::normalize_review_text( wp_strip_all_tags( strip_shortcodes( $html ) ) );
				if ( '' !== $text ) {
					$fragments[] = array(
						'key'       => self::source_design_fragment_key( $name, $attrs, $current_path, 'text' ),
						'path'      => $current_path,
						'block'     => $name,
						'unique_id' => isset( $attrs['uniqueId'] ) ? (string) $attrs['uniqueId'] : '',
						'format'    => 'inline_html',
						'heading'   => self::is_heading_block( $name, $attrs ),
						'text'      => $text,
					);
				}
			}

			foreach ( self::structured_text_attr_fragments( $name, $attrs ) as $attr_fragment ) {
				$text = self::normalize_review_text( wp_strip_all_tags( strip_shortcodes( (string) ( $attr_fragment['text'] ?? '' ) ) ) );
				if ( '' === $text ) {
					continue;
				}
				$fragments[] = array(
					'key'       => self::structured_text_attr_fragment_key( $current_path, $name, $attr_fragment ),
					'path'      => $current_path,
					'block'     => $name . ':' . (string) ( $attr_fragment['field'] ?? '' ),
					'attr_path' => (string) ( $attr_fragment['label_path'] ?? '' ),
					'row_id'    => (string) ( $attr_fragment['row_id'] ?? '' ),
					'role'      => (string) ( $attr_fragment['role'] ?? 'text' ),
					'format'    => 'inline_html',
					'heading'   => ! empty( $attr_fragment['heading'] ),
					'text'      => $text,
				);
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::collect_source_design_fragments( $block['innerBlocks'], $fragments, $current_path );
			}
		}
	}

	/**
	 * Project localized fragments into a parsed source block tree.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param array<string,string>           $fragments Localized values by key.
	 * @param array<string,int>              $stats Projection stats.
	 */
	private static function project_source_design_blocks( array &$blocks, array $fragments, array &$stats, string $path = '' ): void {
		foreach ( $blocks as $index => &$block ) {
			$current_path = '' === $path ? (string) $index : $path . '.' . $index;
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name  = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			if ( in_array( $name, self::copy_quality_text_block_names(), true ) && isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
				$key = self::source_design_fragment_key( $name, $attrs, $current_path, 'text' );
				if ( array_key_exists( $key, $fragments ) ) {
					$block['innerHTML']   = self::replace_source_design_text_html( (string) $block['innerHTML'], $fragments[ $key ] );
					$block['innerContent'] = array( $block['innerHTML'] );
					$stats['projected_count']++;
				}
			}

			foreach ( self::structured_text_attr_fragments( $name, $attrs ) as $attr_fragment ) {
				$key = self::structured_text_attr_fragment_key( $current_path, $name, $attr_fragment );
				if ( ! array_key_exists( $key, $fragments ) ) {
					continue;
				}

				$old_value = (string) ( $attr_fragment['text'] ?? '' );
				$new_value = $fragments[ $key ];
				self::set_nested_array_value( $block['attrs'], (array) ( $attr_fragment['attr_path'] ?? array() ), $new_value );
				if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
					$block['innerHTML'] = self::replace_source_design_structured_html_value( $block['innerHTML'], $old_value, $new_value );
					if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
						$block['innerContent'] = self::replace_source_design_inner_content_value( $block['innerContent'], $old_value, $new_value );
					} elseif ( empty( $block['innerBlocks'] ) ) {
						$block['innerContent'] = array( $block['innerHTML'] );
					}
				}
				$stats['projected_count']++;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::project_source_design_blocks( $block['innerBlocks'], $fragments, $stats, $current_path );
			}
		}
		unset( $block );
	}

	/**
	 * Stable fragment key for text-bearing blocks.
	 */
	private static function source_design_fragment_key( string $block_name, array $attrs, string $path, string $part ): string {
		$unique_id = isset( $attrs['uniqueId'] ) ? trim( (string) $attrs['uniqueId'] ) : '';
		if ( '' !== $unique_id ) {
			return 'uid:' . preg_replace( '/[^a-z0-9_\\-.]/i', '', $unique_id ) . ':' . sanitize_key( $part );
		}

		return 'path:' . preg_replace( '/[^0-9.]/', '', $path ) . ':' . sanitize_key( str_replace( '/', '_', $block_name ) ) . ':' . sanitize_key( $part );
	}

	/**
	 * Replace text inside a block's existing wrapper while keeping wrapper design.
	 */
	private static function replace_source_design_text_html( string $html, string $localized_html ): string {
		$localized_html = trim( $localized_html );
		if ( '' === $localized_html ) {
			return $html;
		}
		if ( preg_match( '/^(\\s*<([a-z][a-z0-9]*)\\b[^>]*>)(.*)(<\\/\\2>\\s*)$/is', $html, $matches ) ) {
			return $matches[1] . $localized_html . $matches[4];
		}

		return $localized_html;
	}

	/**
	 * Collect text fields stored in structured block attributes.
	 *
	 * This keeps design inheritance independent from the plugin that created the
	 * block. FAQ, how-to, and similar data-bearing blocks usually store repeated
	 * rows in semantic attribute collections such as questions or steps.
	 *
	 * @param array<string,mixed> $attrs Block attributes.
	 * @return array<int,array<string,mixed>>
	 */
	private static function structured_text_attr_fragments( string $block_name, array $attrs ): array {
		$fragments = array();
		foreach ( $attrs as $key => $value ) {
			if ( ! is_array( $value ) || ! self::is_structured_text_collection_key( (string) $key ) ) {
				continue;
			}
			self::collect_structured_text_attr_collection( $value, $fragments, array( $key ), array( $key ) );
		}

		$filtered = apply_filters( 'ai_translation_workflow_structured_text_attr_fragments', $fragments, $block_name, $attrs );
		if ( ! is_array( $filtered ) ) {
			return $fragments;
		}

		return array_values(
			array_filter(
				$filtered,
				static function ( $fragment ): bool {
					return is_array( $fragment ) && ! empty( $fragment['attr_path'] ) && array_key_exists( 'text', $fragment );
				}
			)
		);
	}

	/**
	 * @param array<int|string,mixed>        $collection Attribute row collection.
	 * @param array<int,array<string,mixed>> $fragments Output fragments.
	 * @param array<int,int|string>          $attr_path Current real attribute path.
	 * @param array<int,string>              $label_path Stable label path used for keys.
	 */
	private static function collect_structured_text_attr_collection( array $collection, array &$fragments, array $attr_path, array $label_path ): void {
		foreach ( $collection as $row_index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row_id = self::structured_text_row_identifier( $row, $row_index );
			foreach ( $row as $field => $value ) {
				$field_name = (string) $field;
				if ( is_string( $value ) && self::is_structured_text_field_key( $field_name ) ) {
					$role        = self::structured_text_field_role( $field_name );
					$fragments[] = array(
						'attr_path'  => array_merge( $attr_path, array( $row_index, $field_name ) ),
						'label_path' => self::structured_text_label_path( array_merge( $label_path, array( $row_id, $field_name ) ) ),
						'row_id'     => $row_id,
						'field'      => $field_name,
						'role'       => $role,
						'heading'    => in_array( $role, array( 'question', 'title', 'name' ), true ),
						'text'       => $value,
					);
					continue;
				}
				if ( is_array( $value ) && self::is_structured_text_collection_key( $field_name ) ) {
					self::collect_structured_text_attr_collection(
						$value,
						$fragments,
						array_merge( $attr_path, array( $row_index, $field_name ) ),
						array_merge( $label_path, array( $row_id, $field_name ) )
					);
				}
			}
		}
	}

	private static function is_structured_text_collection_key( string $key ): bool {
		$key = strtolower( preg_replace( '/[^a-z0-9_\\-]/i', '', $key ) ?: '' );

		return in_array( $key, array( 'question', 'questions', 'faq', 'faqs', 'step', 'steps', 'item', 'items' ), true );
	}

	private static function is_structured_text_field_key( string $key ): bool {
		$key = strtolower( preg_replace( '/[^a-z0-9_\\-]/i', '', $key ) ?: '' );

		return in_array(
			$key,
			array(
				'title',
				'content',
				'question',
				'answer',
				'jsonquestion',
				'jsonanswer',
				'name',
				'jsonname',
				'text',
				'jsontext',
				'description',
				'jsondescription',
			),
			true
		);
	}

	private static function structured_text_field_role( string $key ): string {
		$key = strtolower( preg_replace( '/[^a-z0-9_\\-]/i', '', $key ) ?: '' );
		if ( false !== strpos( $key, 'question' ) ) {
			return 'question';
		}
		if ( false !== strpos( $key, 'answer' ) || 'content' === $key || false !== strpos( $key, 'text' ) || false !== strpos( $key, 'description' ) ) {
			return 'answer';
		}
		if ( false !== strpos( $key, 'name' ) ) {
			return 'name';
		}
		if ( false !== strpos( $key, 'title' ) ) {
			return 'title';
		}

		return 'text';
	}

	/**
	 * @param array<string,mixed> $row Structured data row.
	 * @param int|string         $row_index Fallback row position.
	 */
	private static function structured_text_row_identifier( array $row, $row_index ): string {
		foreach ( array( 'id', 'uid', 'uniqueId', 'key' ) as $key ) {
			if ( isset( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
				return self::structured_text_key_part( (string) $row[ $key ] );
			}
		}

		return self::structured_text_key_part( (string) $row_index );
	}

	/**
	 * @param array<int,string> $path Human-readable stable path parts.
	 */
	private static function structured_text_label_path( array $path ): string {
		$parts = array_map( array( __CLASS__, 'structured_text_key_part' ), $path );

		return implode( '.', array_filter( $parts, 'strlen' ) );
	}

	private static function structured_text_key_part( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = str_replace( array( '/', '\\', ':', ' ' ), '_', $value );
		$value = preg_replace( '/[^a-z0-9_\\-.]/', '', $value );

		return is_string( $value ) ? trim( $value, '._-' ) : '';
	}

	/**
	 * Stable fragment key for structured block attribute fields.
	 *
	 * @param array<string,mixed> $fragment Attribute fragment descriptor.
	 */
	private static function structured_text_attr_fragment_key( string $path, string $block_name, array $fragment ): string {
		$block = self::structured_text_key_part( str_replace( '/', '_', $block_name ) );
		$label = self::structured_text_key_part( (string) ( $fragment['label_path'] ?? '' ) );

		return 'attr:' . preg_replace( '/[^0-9.]/', '', $path ) . ':' . $block . ':' . $label;
	}

	/**
	 * Set a nested array value by path when the path exists.
	 *
	 * @param array<string|int,mixed> $array Target array.
	 * @param array<int,string|int>   $path Nested keys.
	 * @param mixed                   $value New value.
	 */
	private static function set_nested_array_value( array &$array, array $path, $value ): void {
		if ( empty( $path ) ) {
			return;
		}
		$cursor =& $array;
		foreach ( $path as $index => $key ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $key, $cursor ) ) {
				return;
			}
			if ( count( $path ) - 1 === $index ) {
				$cursor[ $key ] = $value;
				return;
			}
			$cursor =& $cursor[ $key ];
		}
	}

	/**
	 * Replace a structured old value inside saved block HTML.
	 */
	private static function replace_source_design_structured_html_value( string $html, string $old_value, string $new_value ): string {
		$old_value = trim( $old_value );
		if ( '' === $old_value ) {
			return $html;
		}

		$plain = self::normalize_review_text( wp_strip_all_tags( strip_shortcodes( $old_value ) ) );
		$variants = array_unique(
			array_filter(
				array(
					wp_kses_post( $old_value ),
					esc_html( $plain ),
					esc_html( $old_value ),
					$old_value,
				),
				'strlen'
			)
		);

		foreach ( $variants as $variant ) {
			if ( false !== strpos( $html, $variant ) ) {
				return str_replace( $variant, $new_value, $html );
			}
		}

		return $html;
	}

	/**
	 * @param array<int,mixed> $inner_content Parsed block innerContent.
	 * @return array<int,mixed>
	 */
	private static function replace_source_design_inner_content_value( array $inner_content, string $old_value, string $new_value ): array {
		foreach ( $inner_content as &$part ) {
			if ( is_string( $part ) ) {
				$part = self::replace_source_design_structured_html_value( $part, $old_value, $new_value );
			}
		}
		unset( $part );

		return $inner_content;
	}

	/**
	 * Hash the non-text design signature of a Gutenberg block tree.
	 */
	private static function source_design_signature_hash( string $content ): string {
		return hash( 'sha256', wp_json_encode( self::source_design_signature( parse_blocks( $content ) ) ) ?: '' );
	}

	/**
	 * Expected design signature for a target language.
	 *
	 * LTR translations must match the source design exactly. RTL translations may
	 * differ only by deterministic source-derived RTL mirroring from language
	 * direction data.
	 */
	private static function expected_source_design_signature_hash( string $source_content, string $language ): string {
		$source_content = self::normalize_gutenberg_content_for_storage( $source_content );
		if ( self::is_rtl_language( $language ) ) {
			$source_content = self::mirror_rtl_block_layout_from_source( $source_content, $source_content, $language );
		}

		return self::source_design_signature_hash( $source_content );
	}

	/**
	 * Build a text-independent design signature for guardrails.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return array<int,array<string,mixed>>
	 */
	private static function source_design_signature( array $blocks, string $path = '' ): array {
		$signature = array();
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$current_path = '' === $path ? (string) $index : $path . '.' . $index;
			$name         = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
			$attrs        = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$html         = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';
			$signature[]  = array(
				'path'       => $current_path,
				'block'      => $name,
				'attrs'      => self::source_design_signature_attrs( $name, $attrs ),
				'html_shell' => self::source_design_html_shell( $name, $attrs, $html ),
				'children'   => ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? self::source_design_signature( $block['innerBlocks'], $current_path ) : array(),
			);
		}

		return $signature;
	}

	/**
	 * Remove translatable text fields from block attrs before design comparison.
	 *
	 * @param array<string,mixed> $attrs Block attrs.
	 * @return array<string,mixed>
	 */
	private static function source_design_signature_attrs( string $block_name, array $attrs ): array {
		foreach ( self::structured_text_attr_fragments( $block_name, $attrs ) as $attr_fragment ) {
			self::set_nested_array_value( $attrs, (array) ( $attr_fragment['attr_path'] ?? array() ), '{{text}}' );
		}

		return self::recursive_ksort_array( $attrs );
	}

	/**
	 * Preserve wrapper class/tag design while ignoring text content.
	 */
	private static function source_design_html_shell( string $block_name, array $attrs, string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}
		if ( in_array( $block_name, self::copy_quality_text_block_names(), true ) && preg_match( '/^(\\s*<([a-z][a-z0-9]*)\\b[^>]*>)(.*)(<\\/\\2>\\s*)$/is', $html, $matches ) ) {
			return trim( $matches[1] . '{{text}}' . $matches[4] );
		}

		$shell = $html;
		foreach ( self::structured_text_attr_fragments( $block_name, $attrs ) as $attr_fragment ) {
			$shell = self::replace_source_design_structured_html_value( $shell, (string) ( $attr_fragment['text'] ?? '' ), '{{text}}' );
		}
		if ( $shell !== $html ) {
			return trim( $shell );
		}

		return '';
	}

	/**
	 * Recursively sort associative arrays so signature hashes are stable.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed
	 */
	private static function recursive_ksort_array( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as &$child ) {
			$child = self::recursive_ksort_array( $child );
		}
		unset( $child );
		ksort( $value );

		return $value;
	}
}
