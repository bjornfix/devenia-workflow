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
