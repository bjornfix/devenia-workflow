<?php
/**
 * Optional GenerateBlocks integration.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Devenia_Workflow_GenerateBlocks_Adapter {
	/**
	 * Register optional GenerateBlocks content and QA adapters.
	 */
	public static function register(): void {
		add_filter( 'devenia_workflow_copy_quality_text_block_names', array( __CLASS__, 'add_copy_quality_text_blocks' ) );
		add_filter( 'devenia_workflow_semantic_text_unit_block_names', array( __CLASS__, 'add_semantic_text_unit_blocks' ) );
		add_filter( 'devenia_workflow_semantic_button_block_names', array( __CLASS__, 'add_button_blocks' ) );
		add_filter( 'devenia_workflow_semantic_image_block_names', array( __CLASS__, 'add_image_blocks' ) );
		add_filter( 'devenia_workflow_is_heading_block', array( __CLASS__, 'is_heading_block' ), 10, 3 );
		add_filter( 'devenia_workflow_normalize_gutenberg_content_for_storage', array( __CLASS__, 'normalize_gutenberg_content_for_storage' ) );
		add_filter( 'devenia_workflow_gutenberg_content_safety', array( __CLASS__, 'gutenberg_guardrails' ), 10, 3 );
		add_filter( 'devenia_workflow_gutenberg_guardrails', array( __CLASS__, 'gutenberg_guardrails' ), 10, 3 );
		add_filter( 'devenia_workflow_mirror_rtl_block_layout', array( __CLASS__, 'normalize_rtl_grid_gaps' ), 10, 3 );
		add_action( 'devenia_workflow_source_design_reprojected', array( __CLASS__, 'on_source_design_reprojected' ) );
		add_action( 'save_post', array( __CLASS__, 'on_save_post_regenerate_css' ), 100, 1 );
	}

	/**
	 * Add GenerateBlocks button blocks.
	 *
	 * @param array<int,string> $names Block names.
	 * @return array<int,string>
	 */
	public static function add_button_blocks( array $names ): array {
		return self::merge_block_names( $names, array( 'generateblocks/button' ) );
	}

	/**
	 * Add GenerateBlocks text-bearing blocks for copy quality checks.
	 *
	 * @param array<int,string> $names Block names.
	 * @return array<int,string>
	 */
	public static function add_copy_quality_text_blocks( array $names ): array {
		return self::merge_block_names( $names, array( 'generateblocks/headline', 'generateblocks/button' ) );
	}

	/**
	 * Add GenerateBlocks text units for semantic structure checks.
	 *
	 * @param array<int,string> $names Block names.
	 * @return array<int,string>
	 */
	public static function add_semantic_text_unit_blocks( array $names ): array {
		return self::merge_block_names( $names, array( 'generateblocks/headline' ) );
	}

	/**
	 * Add GenerateBlocks image blocks for semantic structure checks.
	 *
	 * @param array<int,string> $names Block names.
	 * @return array<int,string>
	 */
	public static function add_image_blocks( array $names ): array {
		return self::merge_block_names( $names, array( 'generateblocks/image' ) );
	}

	/**
	 * Return a stable GenerateBlocks render class for dynamic block matching.
	 *
	 * @param string            $class Existing adapter class.
	 * @param string            $html Stored block HTML.
	 * @param array<int,string> $classes Parsed HTML classes.
	 */
	public static function stable_render_class( string $class, string $html, array $classes ): string {
		unset( $html );
		if ( '' !== $class ) {
			return $class;
		}

		foreach ( $classes as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( preg_match( '/^gb-(?:headline|button)-[a-z0-9]+$/i', $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Whether a GenerateBlocks block is heading-like.
	 *
	 * @param bool                $is_heading Existing result.
	 * @param string              $name Block name.
	 * @param array<string,mixed> $attrs Block attributes.
	 */
	public static function is_heading_block( bool $is_heading, string $name, array $attrs ): bool {
		if ( $is_heading || 'generateblocks/headline' !== $name ) {
			return $is_heading;
		}

		$element = isset( $attrs['element'] ) ? strtolower( (string) $attrs['element'] ) : '';
		return 1 === preg_match( '/^h[1-6]$/', $element );
	}

	/**
	 * Replace GenerateBlocks' left-negative grid gutter with native item spacing.
	 *
	 * GenerateBlocks implements horizontalGap with a negative left margin on the
	 * wrapper. In RTL that extends the document to the physical left. Reserve the
	 * same gutter inside each row with native width and spacing attributes instead.
	 *
	 * @param array<int,array<string,mixed>> $blocks Target blocks.
	 * @param array<int,array<string,mixed>> $source_blocks Source blocks.
	 * @return array<int,array<string,mixed>>
	 */
	public static function normalize_rtl_grid_gaps( array $blocks, array $source_blocks, string $language ): array {
		unset( $language );
		self::normalize_rtl_grid_gap_blocks( $blocks, $source_blocks );
		return $blocks;
	}

	/**
	 * @param array<int,array<string,mixed>> $blocks Target blocks.
	 * @param array<int,array<string,mixed>> $source_blocks Source blocks.
	 */
	private static function normalize_rtl_grid_gap_blocks( array &$blocks, array $source_blocks ): void {
		foreach ( $blocks as $index => &$block ) {
			$source_block = $source_blocks[ $index ] ?? null;
			if ( ! is_array( $block ) || ! is_array( $source_block ) ) {
				continue;
			}

			self::normalize_rtl_grid_gap_block( $block, $source_block );
			if ( isset( $block['innerBlocks'], $source_block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && is_array( $source_block['innerBlocks'] ) ) {
				self::normalize_rtl_grid_gap_blocks( $block['innerBlocks'], $source_block['innerBlocks'] );
			}
		}
		unset( $block );
	}

	private static function normalize_rtl_grid_gap_block( array &$block, array $source_block ): void {
		if ( 'generateblocks/grid' !== (string) ( $block['blockName'] ?? '' ) || 'generateblocks/grid' !== (string) ( $source_block['blockName'] ?? '' ) ) {
			return;
		}

		$source_attrs = is_array( $source_block['attrs'] ?? null ) ? $source_block['attrs'] : array();
		$gap          = (float) ( $source_attrs['horizontalGap'] ?? 0 );
		$columns      = absint( $source_attrs['columns'] ?? 0 );
		$source_items = is_array( $source_block['innerBlocks'] ?? null ) ? $source_block['innerBlocks'] : array();
		$target_items = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
		$item_count   = count( $source_items );
		if ( $gap <= 0 || $columns < 2 || 0 === $item_count || $item_count !== count( $target_items ) ) {
			return;
		}

		$widths = array();
		foreach ( $source_items as $item_index => $source_item ) {
			$target_item = $target_items[ $item_index ] ?? null;
			if ( ! is_array( $source_item ) || ! is_array( $target_item ) || 'generateblocks/container' !== (string) ( $source_item['blockName'] ?? '' ) || 'generateblocks/container' !== (string) ( $target_item['blockName'] ?? '' ) ) {
				return;
			}

			$spacing = is_array( $source_item['attrs']['spacing'] ?? null ) ? $source_item['attrs']['spacing'] : array();
			if ( ! self::spacing_value_is_zero( $spacing['marginLeft'] ?? '' ) || ! self::spacing_value_is_zero( $spacing['marginLeftMobile'] ?? '' ) ) {
				return;
			}

			$width = (string) ( $source_item['attrs']['sizing']['width'] ?? '' );
			if ( ! preg_match( '/^(?:100|[0-9]{1,2}(?:\.[0-9]+)?)%$/', $width ) ) {
				return;
			}
			$widths[ $item_index ] = $width;
		}

		if ( ! isset( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
			$block['attrs'] = array();
		}
		$block['attrs']['horizontalGap'] = 0;
		$gap_value                       = rtrim( rtrim( number_format( $gap, 4, '.', '' ), '0' ), '.' ) . 'px';

		foreach ( $block['innerBlocks'] as $item_index => &$target_item ) {
			$row_end = min( ( (int) floor( $item_index / $columns ) + 1 ) * $columns, $item_count ) - 1;
			if ( $item_index === $row_end ) {
				continue;
			}

			$target_item['attrs']            = is_array( $target_item['attrs'] ?? null ) ? $target_item['attrs'] : array();
			$target_item['attrs']['spacing'] = is_array( $target_item['attrs']['spacing'] ?? null ) ? $target_item['attrs']['spacing'] : array();
			$target_item['attrs']['sizing']  = is_array( $target_item['attrs']['sizing'] ?? null ) ? $target_item['attrs']['sizing'] : array();

			$target_item['attrs']['sizing']['width']            = 'calc(' . $widths[ $item_index ] . ' - ' . $gap_value . ')';
			$target_item['attrs']['spacing']['marginLeft']       = $gap_value;
			$target_item['attrs']['spacing']['marginLeftMobile'] = '0px';
			if ( '100%' === (string) ( $source_items[ $item_index ]['attrs']['sizing']['widthTablet'] ?? '' ) ) {
				$target_item['attrs']['spacing']['marginLeftTablet'] = '0px';
			}
		}
		unset( $target_item );
	}

	private static function spacing_value_is_zero( $value ): bool {
		$value = strtolower( trim( (string) $value ) );
		return '' === $value || in_array( $value, array( '0', '0px', '0em', '0rem', '0%' ), true );
	}

	/**
	 * Regenerate GenerateBlocks dynamic CSS after source-design reprojection.
	 *
	 * GenerateBlocks hooks save_post to update _generateblocks_dynamic_css_version,
	 * but it bails when current_user_can('edit_post') is false (common during
	 * MCP-triggered server operations). This hook runs inside the workflow's
	 * own design-reprojection seam and updates the CSS version meta directly.
	 */
	public static function on_source_design_reprojected( int $translation_id ): void {
		if ( ! defined( 'GENERATEBLOCKS_VERSION' ) ) {
			return;
		}
		$post = get_post( $translation_id );
		if ( ! $post || false === strpos( $post->post_content, 'wp:generateblocks' ) ) {
			return;
		}
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['basedir'] ) ) {
			$css_file = $upload_dir['basedir'] . '/generateblocks/style-' . absint( $translation_id ) . '.css';
			if ( file_exists( $css_file ) ) {
				wp_delete_file( $css_file );
			}
		}
		update_post_meta( $translation_id, '_generateblocks_dynamic_css_version', sanitize_text_field( GENERATEBLOCKS_VERSION ) );
	}

	/**
	 * Force GenerateBlocks CSS regeneration on save_post when the
	 * current_user_can check would normally block it (server-side saves).
	 */
	public static function on_save_post_regenerate_css( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! defined( 'GENERATEBLOCKS_VERSION' ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || false === strpos( $post->post_content, 'wp:generateblocks' ) ) {
			return;
		}
		update_post_meta( $post_id, '_generateblocks_dynamic_css_version', sanitize_text_field( GENERATEBLOCKS_VERSION ) );
	}

	/**
	 * Run GenerateBlocks-specific Gutenberg guardrails.
	 *
	 * @param array<string,mixed>             $result Existing adapter result.
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param string                         $content Raw post content.
	 * @return array<string,mixed>
	 */
	public static function gutenberg_guardrails( array $result, array $blocks, string $content ): array {
		unset( $content );

		$grid_ids  = array();
		$grid_refs = array();
		self::collect_grid_refs( $blocks, $grid_ids, $grid_refs );

		$issues = isset( $result['issues'] ) && is_array( $result['issues'] ) ? $result['issues'] : array();
		foreach ( $grid_refs as $ref ) {
			if ( ! isset( $grid_ids[ $ref['grid_id'] ] ) ) {
				$issues[] = self::qa_item( 'dangling_generateblocks_grid_ref', 'A GenerateBlocks grid child references a missing gridId.', $ref );
			}
		}
		self::collect_dynamic_container_saved_wrapper_issues( $blocks, $issues );

		$summary = isset( $result['summary'] ) && is_array( $result['summary'] ) ? $result['summary'] : array();
		$summary['generateblocks_grid_count']       = count( $grid_ids );
		$summary['generateblocks_grid_child_count'] = count( $grid_refs );

		$result['issues']   = $issues;
		$result['warnings'] = isset( $result['warnings'] ) && is_array( $result['warnings'] ) ? $result['warnings'] : array();
		$result['summary']  = $summary;

		return $result;
	}

	/**
	 * Strip stale frontend wrapper HTML from dynamic GenerateBlocks containers.
	 */
	public static function normalize_gutenberg_content_for_storage( string $content ): string {
		if ( false === strpos( $content, '<!-- wp:generateblocks/container' ) || false === strpos( $content, 'gb-container' ) ) {
			return $content;
		}

			$normalized = preg_replace(
				'/(<!--\s+wp:generateblocks\/container\b(?:(?!-->).)*"isDynamic"\s*:\s*true(?:(?!-->).)*-->)\s*<div\b[^>]*class="[^"]*\bgb-container\b[^"]*"[^>]*>\s*/is',
				'$1',
				$content,
				-1,
				$open_count
			);
			if ( ! is_string( $normalized ) ) {
				return $content;
			}

			$normalized = preg_replace(
				'/\s*<\/div>\s*(<!--\s+\/wp:generateblocks\/container\s+-->)/i',
				'$1',
				$normalized,
				-1,
				$close_count
			);
			if ( ! is_string( $normalized ) || ( 0 === $open_count && 0 === $close_count ) ) {
				return $content;
			}

			return $normalized;
		}

	/**
	 * Detect stored frontend wrappers for dynamic GenerateBlocks containers.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param array<int,array<string,mixed>> $issues Hard QA failures.
	 */
	private static function collect_dynamic_container_saved_wrapper_issues( array $blocks, array &$issues ): void {
		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
			$html = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';

			if (
				'generateblocks/container' === $name
				&& false !== strpos( $html, 'gb-container' )
				&& preg_match( '/^\s*<div\b[^>]*class="[^"]*\bgb-container\b/i', $html )
			) {
				$issues[] = self::qa_item(
					'generateblocks_dynamic_container_saved_wrapper',
					'GenerateBlocks dynamic container stores frontend wrapper markup. This can render nested containers and break the public layout even when the block tree looks structurally correct.',
					array(
						'block' => 'generateblocks/container',
					)
				);
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::collect_dynamic_container_saved_wrapper_issues( $block['innerBlocks'], $issues );
			}
		}
	}

	/**
	 * Merge and de-duplicate block names.
	 *
	 * @param array<int,string> $names Existing names.
	 * @param array<int,string> $extra Extra names.
	 * @return array<int,string>
	 */
	private static function merge_block_names( array $names, array $extra ): array {
		return array_values( array_unique( array_merge( array_map( 'strval', $names ), $extra ) ) );
	}

	/**
	 * Collect GenerateBlocks grid IDs and child references.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param array<string,bool>             $grid_ids Grid IDs.
	 * @param array<int,array<string,mixed>> $grid_refs Child references.
	 */
	private static function collect_grid_refs( array $blocks, array &$grid_ids, array &$grid_refs ): void {
		foreach ( $blocks as $block ) {
			$name  = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

			if ( 'generateblocks/grid' === $name && isset( $attrs['uniqueId'] ) && is_string( $attrs['uniqueId'] ) && '' !== $attrs['uniqueId'] ) {
				$grid_ids[ $attrs['uniqueId'] ] = true;
			}

			if ( ! empty( $attrs['isGrid'] ) && isset( $attrs['gridId'] ) && is_string( $attrs['gridId'] ) ) {
				$grid_refs[] = array(
					'block'     => $name,
					'unique_id' => isset( $attrs['uniqueId'] ) ? (string) $attrs['uniqueId'] : '',
					'grid_id'   => $attrs['gridId'],
				);
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::collect_grid_refs( $block['innerBlocks'], $grid_ids, $grid_refs );
			}
		}
	}

	/**
	 * Build a QA item compatible with core guardrail output.
	 *
	 * @param array<string,mixed> $context Issue context.
	 * @return array<string,mixed>
	 */
	private static function qa_item( string $code, string $message, array $context = array() ): array {
		return array(
			'code'    => $code,
			'message' => $message,
			'context' => $context,
		);
	}
}

Devenia_Workflow_GenerateBlocks_Adapter::register();
