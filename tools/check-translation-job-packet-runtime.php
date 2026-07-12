<?php
/**
 * Dev runtime contract for bounded source fragment packet markup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$post_id = 0;

try {
	$content = <<<'HTML'
<!-- wp:paragraph -->
<p><strong>1. Content</strong><br>Useful source copy with a <a href="/services/seo/">clear next step</a>.</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><strong>Evidence</strong> should remain visible.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->
HTML;

	$post_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Translation Job packet runtime fixture',
			'post_content' => $content,
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( $post_id->get_error_message() );
	}

	$method = new ReflectionMethod( Devenia_Workflow::class, 'source_design_contract' );
	$method->setAccessible( true );
	$contract  = $method->invoke( null, get_post( $post_id ) );
	$fragments = isset( $contract['fragments'] ) && is_array( $contract['fragments'] ) ? $contract['fragments'] : array();
	$paragraph = null;
	$list_item = null;

	foreach ( $fragments as $fragment ) {
		$block = (string) ( $fragment['block'] ?? '' );
		if ( 'core/paragraph' === $block ) {
			$paragraph = $fragment;
		}
		if ( 'core/list-item' === $block || 'core/list:item' === $block ) {
			$list_item = $fragment;
		}
	}

	$paragraph_html = (string) ( $paragraph['source_html'] ?? '' );
	$list_html      = (string) ( $list_item['source_html'] ?? '' );
	$failures       = array();

	if ( 2 !== count( $fragments ) ) {
		$failures[] = 'unexpected_fragment_count';
	}
	foreach ( array( '<strong>', '<br>', '<a ' ) as $needle ) {
		if ( false === stripos( $paragraph_html, $needle ) ) {
			$failures[] = 'paragraph_missing_' . sanitize_key( $needle );
		}
	}
	if ( false === stripos( $list_html, '<strong>' ) ) {
		$failures[] = 'list_item_missing_strong';
	}

	if ( $failures ) {
		throw new RuntimeException( wp_json_encode( $failures ) );
	}

	echo wp_json_encode(
		array(
			'success'               => true,
			'fragment_count'        => count( $fragments ),
			'paragraph_markup_kept' => true,
			'list_markup_kept'      => true,
		)
	) . PHP_EOL;
} catch ( Throwable $error ) {
	fwrite(
		STDERR,
		wp_json_encode(
			array(
				'success' => false,
				'error'   => $error->getMessage(),
			)
		) . PHP_EOL
	);
	exit( 1 );
} finally {
	if ( $post_id > 0 ) {
		wp_delete_post( $post_id, true );
	}
}
