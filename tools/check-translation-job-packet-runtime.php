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

<!-- wp:details -->
<details class="wp-block-details"><summary>Open technical specifications</summary><!-- wp:paragraph -->
<p>Technical details remain inside the disclosure.</p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->
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
	$details_summary = null;

	foreach ( $fragments as $fragment ) {
		$block = (string) ( $fragment['block'] ?? '' );
		if ( 'core/paragraph' === $block && null === $paragraph ) {
			$paragraph = $fragment;
		}
		if ( 'core/list-item' === $block || 'core/list:item' === $block ) {
			$list_item = $fragment;
		}
		if ( 'core/details:summary' === $block ) {
			$details_summary = $fragment;
		}
	}

	$paragraph_html = (string) ( $paragraph['source_html'] ?? '' );
	$list_html      = (string) ( $list_item['source_html'] ?? '' );
	$failures       = array();

	if ( 4 !== count( $fragments ) ) {
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
	if ( 'Open technical specifications' !== (string) ( $details_summary['text'] ?? '' ) ) {
		$failures[] = 'details_summary_missing';
	}

	$localized_fragments = array();
	foreach ( $fragments as $fragment ) {
		$localized_fragments[] = array(
			'key'  => (string) ( $fragment['key'] ?? '' ),
			'html' => 'core/details:summary' === (string) ( $fragment['block'] ?? '' )
				? '<summary>Åpne tekniske spesifikasjoner</summary>'
				: (string) ( $fragment['source_html'] ?? '' ),
		);
	}
	$projection_method = new ReflectionMethod( Devenia_Workflow::class, 'inherited_source_design_content' );
	$projection_method->setAccessible( true );
	$projection = $projection_method->invoke(
		null,
		get_post( $post_id ),
		array(
			'localized_fragments' => $localized_fragments,
			'strict_source_design_fragments' => true,
		),
		'nb'
	);
	$projected_content = (string) ( $projection['content'] ?? '' );
	if ( empty( $projection['success'] ) || false === strpos( $projected_content, '<summary>Åpne tekniske spesifikasjoner</summary>' ) ) {
		$failures[] = 'details_summary_projection_failed';
	}

	$canonical_method = new ReflectionMethod( Devenia_Workflow::class, 'extract_canonical_url_from_html' );
	$canonical_method->setAccessible( true );
	$canonical_url = (string) $canonical_method->invoke(
		null,
		'<link rel="canonical" href="https://example.com/fr/plugins/" />'
	);
	$url_match_method = new ReflectionMethod( Devenia_Workflow::class, 'urls_match_case_insensitively' );
	$url_match_method->setAccessible( true );
	if ( 'https://example.com/fr/plugins/' !== $canonical_url ) {
		$failures[] = 'canonical_url_extraction_failed';
	}
	if ( ! $url_match_method->invoke( null, 'https://example.com/fr/Plugins/', $canonical_url ) ) {
		$failures[] = 'canonical_url_case_match_failed';
	}
	if ( $url_match_method->invoke( null, 'https://example.com/fr/other/', $canonical_url ) ) {
		$failures[] = 'canonical_url_distinct_path_match_failed';
	}
	$canonical_post_method = new ReflectionMethod( Devenia_Workflow::class, 'canonical_url_for_post_id' );
	$canonical_post_method->setAccessible( true );
	if ( get_permalink( $post_id ) !== $canonical_post_method->invoke( null, $post_id ) ) {
		$failures[] = 'canonical_post_url_resolution_failed';
	}
	update_post_meta( $post_id, Devenia_Workflow::META_CANONICAL_ROUTE, array( 'path' => 'fr/plugins' ) );
	if ( home_url( '/fr/plugins/' ) !== $canonical_post_method->invoke( null, $post_id ) ) {
		$failures[] = 'canonical_route_contract_url_resolution_failed';
	}
	$canonical_request_method = new ReflectionMethod( Devenia_Workflow::class, 'canonical_url_for_current_request' );
	$canonical_request_method->setAccessible( true );
	global $wp;
	if ( ! is_object( $wp ) ) {
		$wp = new stdClass();
	}
	$before_request_path = is_object( $wp ) && isset( $wp->request ) ? (string) $wp->request : '';
	$wp->request = 'fr/plugins';
	if ( home_url( '/fr/plugins/' ) !== $canonical_request_method->invoke( null, '<article>Translated content only</article>' ) ) {
		$failures[] = 'canonical_current_request_url_resolution_failed';
	}
	$wp->request = $before_request_path;

	if ( $failures ) {
		throw new RuntimeException( wp_json_encode( $failures ) );
	}

	echo wp_json_encode(
		array(
			'success'               => true,
			'fragment_count'        => count( $fragments ),
			'paragraph_markup_kept' => true,
			'list_markup_kept'      => true,
			'details_summary_kept'  => true,
			'canonical_share_url'    => true,
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
