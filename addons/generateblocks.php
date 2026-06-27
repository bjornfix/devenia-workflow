<?php
/**
 * Optional GenerateBlocks integration.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AI_Translation_Workflow_GenerateBlocks_Addon {
	/**
	 * Register optional GenerateBlocks assets.
	 */
	public static function register(): void {
		add_action( 'wp', array( __CLASS__, 'maybe_register_hooks' ), 20 );
		add_filter( 'ai_translation_workflow_quick_copy_edit_supported_block_names', array( __CLASS__, 'add_quick_copy_edit_text_blocks' ) );
		add_filter( 'ai_translation_workflow_quick_copy_edit_button_block_names', array( __CLASS__, 'add_button_blocks' ) );
		add_filter( 'ai_translation_workflow_quick_copy_edit_stable_render_class', array( __CLASS__, 'stable_render_class' ), 10, 3 );
		add_filter( 'ai_translation_workflow_copy_quality_text_block_names', array( __CLASS__, 'add_copy_quality_text_blocks' ) );
		add_filter( 'ai_translation_workflow_semantic_text_unit_block_names', array( __CLASS__, 'add_semantic_text_unit_blocks' ) );
		add_filter( 'ai_translation_workflow_semantic_button_block_names', array( __CLASS__, 'add_button_blocks' ) );
		add_filter( 'ai_translation_workflow_semantic_image_block_names', array( __CLASS__, 'add_image_blocks' ) );
		add_filter( 'ai_translation_workflow_is_heading_block', array( __CLASS__, 'is_heading_block' ), 10, 3 );
		add_filter( 'ai_translation_workflow_gutenberg_guardrails', array( __CLASS__, 'gutenberg_guardrails' ), 10, 3 );
	}

	/**
	 * Register only when a source GenerateBlocks stylesheet exists.
	 */
	public static function maybe_register_hooks(): void {
		if ( ! self::has_source_stylesheet() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_source_styles' ), 20 );
	}

	public static function enqueue_source_styles(): void {
		if ( ! Devenia_AI_Translations::is_translated_posts_page_request() ) {
			return;
		}

		$asset = self::source_stylesheet_asset();
		if ( empty( $asset['path'] ) || empty( $asset['url'] ) || ! is_readable( (string) $asset['path'] ) ) {
			return;
		}

		wp_enqueue_style(
			'ai-translation-workflow-generateblocks-source',
			(string) $asset['url'],
			array(),
			(string) filemtime( (string) $asset['path'] )
		);
	}

	/**
	 * Add GenerateBlocks simple text blocks to Quick Copy Edit.
	 *
	 * @param array<int,string> $names Block names.
	 * @return array<int,string>
	 */
	public static function add_quick_copy_edit_text_blocks( array $names ): array {
		return self::merge_block_names( $names, array( 'generateblocks/headline', 'generateblocks/button' ) );
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

		$summary = isset( $result['summary'] ) && is_array( $result['summary'] ) ? $result['summary'] : array();
		$summary['generateblocks_grid_count']       = count( $grid_ids );
		$summary['generateblocks_grid_child_count'] = count( $grid_refs );

		$result['issues']   = $issues;
		$result['warnings'] = isset( $result['warnings'] ) && is_array( $result['warnings'] ) ? $result['warnings'] : array();
		$result['summary']  = $summary;

		return $result;
	}

	/**
	 * Whether a generated source posts-page stylesheet is present.
	 */
	private static function has_source_stylesheet(): bool {
		$posts_page_id = absint( get_option( 'page_for_posts' ) );
		if ( ! $posts_page_id ) {
			return false;
		}

		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) ) {
			return false;
		}

		$asset = self::source_stylesheet_asset();
		return ! empty( $asset['path'] ) && is_readable( (string) $asset['path'] );
	}

	/**
	 * @return array{path:string,url:string}
	 */
	private static function source_stylesheet_asset(): array {
		$posts_page_id = absint( get_option( 'page_for_posts' ) );
		if ( ! $posts_page_id ) {
			return array( 'path' => '', 'url' => '' );
		}
		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['baseurl'] ) || empty( $upload_dir['basedir'] ) ) {
			return array( 'path' => '', 'url' => '' );
		}
		$relative = 'generateblocks/style-' . $posts_page_id . '.css';
		return array(
			'path' => trailingslashit( (string) $upload_dir['basedir'] ) . $relative,
			'url'  => trailingslashit( (string) $upload_dir['baseurl'] ) . $relative,
		);
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

AI_Translation_Workflow_GenerateBlocks_Addon::register();
