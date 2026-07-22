<?php
/**
 * Dev runtime contract for bounded source fragment packet markup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$post_id = 0;
$translation_id = 0;
$missing_languages_marker = '__devenia_packet_languages_missing_' . wp_generate_password( 10, false, false );
$languages_before = get_option( Devenia_Workflow::OPTION_LANGUAGES, $missing_languages_marker );

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

	$priming_method = new ReflectionMethod( Devenia_Workflow::class, 'copy_quality_role_priming' );
	$priming_method->setAccessible( true );
	$translator_priming = (array) $priming_method->invoke( null, 'translator' );
	$quality_priming    = (array) $priming_method->invoke( null, 'quality' );
	if (
		'translator' !== (string) ( $translator_priming['role'] ?? '' )
		|| count( (array) ( $translator_priming['ogilvy_examples'] ?? array() ) ) < 4
		|| count( (array) ( $translator_priming['primary_reading_library'] ?? array() ) ) < 4
		|| false === strpos( wp_json_encode( $translator_priming ), 'electric clock' )
		|| false === strpos( wp_json_encode( $translator_priming ), 'Do not translate mechanically' )
	) {
		$failures[] = 'translator_role_priming_incomplete';
	}
	if (
		'quality' !== (string) ( $quality_priming['role'] ?? '' )
		|| count( (array) ( $quality_priming['ogilvy_examples'] ?? array() ) ) < 4
		|| count( (array) ( $quality_priming['primary_reading_library'] ?? array() ) ) < 4
		|| false === strpos( wp_json_encode( $quality_priming ), 'Reject the page if' )
	) {
		$failures[] = 'quality_role_priming_incomplete';
	}

	// A large Quality packet must expose every review fragment exactly once while
	// keeping the generated publication document behind the Workflow boundary.
	$large_localized_fragments = array();
	foreach ( range( 1, 92 ) as $index ) {
		$large_localized_fragments[] = array(
			'key' => 'runtime-large-fragment-' . $index,
			'html' => '<p>محتوى عربي مهني كامل للمراجعة المستقلة، مع نتيجة واضحة وخطوة تالية محددة ' . $index . '.</p>',
		);
	}
	$internal_gutenberg = str_repeat( '<!-- wp:paragraph --><p>Generated publication payload that Quality must not receive twice.</p><!-- /wp:paragraph -->', 1200 );
	$quality_projection_method = new ReflectionMethod( Devenia_Workflow::class, 'translation_job_bounded_artifact_view' );
	$quality_projection_method->setAccessible( true );
	$quality_projection = (array) $quality_projection_method->invoke(
		null,
		array(
			'state' => 'staged',
			'artifact_revision' => 'a_runtime_large_quality_projection',
			'job_id' => 'tj_runtime_large_quality_projection',
			'source_revision' => 'ssr_runtime_large_quality_projection',
			'publication_surface_contract_revision' => 'pscr_runtime_large_quality_projection',
			'translation_id' => 123,
			'content_revision' => hash( 'sha256', $internal_gutenberg ),
			'surface_revision' => 'sr_runtime_large_quality_projection',
			'baseline_surface_revision' => 'sr_runtime_large_quality_baseline',
			'submission_generation' => 1,
			'writer_principal' => array( 'principal_id' => 'runtime-translator' ),
			'staged' => true,
			'staged_validation' => array( 'passed' => true, 'guardrail_issue_count' => 0 ),
			'artifact' => array( 'title' => 'عنوان عربي', 'excerpt' => 'ملخص عربي', 'localized_fragments' => $large_localized_fragments ),
			'surface_manifest' => array(
				'content' => array( 'title' => 'عنوان عربي', 'excerpt' => 'ملخص عربي', 'gutenberg' => $internal_gutenberg ),
				'seo' => array( 'title' => 'عنوان تحسين محركات البحث', 'description' => 'وصف عربي واضح' ),
				'taxonomies' => array(),
				'route' => array( 'canonical_route' => array( 'path' => 'ar/runtime-large-quality-projection' ) ),
				'media' => array(),
				'presentation' => array( 'source_design_hash' => 'design-runtime-large', 'localized_fragments' => $large_localized_fragments ),
			),
		)
	);
	$quality_projection_json = wp_json_encode( $quality_projection ) ?: '';
	if ( 92 !== count( (array) ( $quality_projection['artifact']['localized_fragments'] ?? array() ) ) ) {
		$failures[] = 'quality_projection_fragment_loss';
	}
	if ( isset( $quality_projection['surface_manifest'] ) || isset( $quality_projection['staged_surface']['content']['gutenberg'] ) ) {
		$failures[] = 'quality_projection_exposed_publication_payload';
	}
	if ( false !== strpos( $quality_projection_json, 'Generated publication payload' ) ) {
		$failures[] = 'quality_projection_duplicated_generated_content';
	}
	if ( (int) ceil( strlen( $quality_projection_json ) / 4 ) >= 50000 ) {
		$failures[] = 'quality_projection_exceeds_existing_budget';
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
	$canonicalize_url_method = new ReflectionMethod( Devenia_Workflow::class, 'canonicalize_internal_wordpress_url_path_case' );
	$canonicalize_url_method->setAccessible( true );
	if ( home_url( '/fr/plugins/' ) !== $canonicalize_url_method->invoke( null, home_url( '/fr/Plugins/' ) ) ) {
		$failures[] = 'canonical_internal_url_path_case_failed';
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
	$canonical_translation_method = new ReflectionMethod( Devenia_Workflow::class, 'canonical_translation_url_for_post_id' );
	$canonical_translation_method->setAccessible( true );
	$sharing_permalink_method = new ReflectionMethod( Devenia_Workflow::class, 'canonical_social_sharing_permalink_for_context' );
	$sharing_permalink_method->setAccessible( true );
	if ( home_url( '/fr/plugins/' ) !== $sharing_permalink_method->invoke( null, home_url( '/fr/Plugins/' ), $post_id, 'fr' ) ) {
		$failures[] = 'canonical_social_sharing_permalink_filter_failed';
	}
	$languages = Devenia_Workflow::languages( true );
	foreach ( $languages as &$language_config ) {
		if ( is_array( $language_config ) ) {
			$language_config['source'] = '0';
		}
	}
	unset( $language_config );
	$languages['fr']['source'] = '1';
	$languages['en']['source'] = '0';
	update_option( Devenia_Workflow::OPTION_LANGUAGES, $languages, false );
	Devenia_Workflow::languages( true );
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
	$translation_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'English translation runtime fixture',
			'post_content' => $content,
		),
		true
	);
	if ( is_wp_error( $translation_id ) ) {
		throw new RuntimeException( $translation_id->get_error_message() );
	}
	update_post_meta( $translation_id, Devenia_Workflow::META_SOURCE_ID, $post_id );
	update_post_meta( $translation_id, Devenia_Workflow::META_LANGUAGE, 'en' );
	update_post_meta( $translation_id, Devenia_Workflow::META_CANONICAL_ROUTE, array( 'path' => 'en/plugins' ) );
	$sync_translation_index_method = new ReflectionMethod( Devenia_Workflow::class, 'sync_translation_index_row' );
	$sync_translation_index_method->setAccessible( true );
	$sync_translation_index_method->invoke( null, $translation_id );
	$find_translation_method = new ReflectionMethod( Devenia_Workflow::class, 'find_translation_id' );
	$find_translation_method->setAccessible( true );
	if ( $translation_id !== $find_translation_method->invoke( null, $post_id, 'en', array( 'publish' ) ) ) {
		$failures[] = 'real_non_english_source_english_target_lookup_failed';
	}
	if ( '' !== (string) get_post_meta( $post_id, Devenia_Workflow::META_SOURCE_ID, true ) ) {
		$failures[] = 'real_source_was_misclassified_as_translation';
	}
	if ( home_url( '/en/plugins/' ) !== $canonical_translation_method->invoke( null, $post_id, 'en' ) ) {
		$failures[] = 'non_english_source_english_target_canonical_failed';
	}
	if ( home_url( '/en/plugins/' ) !== $canonical_translation_method->invoke( null, $translation_id, 'en' ) ) {
		$failures[] = 'separate_english_target_canonical_failed';
	}
	if ( '' !== $canonical_translation_method->invoke( null, $post_id, 'fr' ) ) {
		$failures[] = 'registry_source_language_was_treated_as_target';
	}
	if ( home_url( '/en/plugins/' ) !== $sharing_permalink_method->invoke( null, home_url( '/fr/plugins/' ), $post_id, 'en' ) ) {
		$failures[] = 'source_context_did_not_resolve_separate_english_target';
	}
	$canonical_request_method = new ReflectionMethod( Devenia_Workflow::class, 'canonical_url_for_current_request' );
	$canonical_request_method->setAccessible( true );
	global $wp;
	if ( ! is_object( $wp ) ) {
		$wp = new stdClass();
	}
	$before_request_path = is_object( $wp ) && isset( $wp->request ) ? (string) $wp->request : '';
	$before_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	$_SERVER['REQUEST_URI'] = '/fr/plugins/?runtime-test=1';
	$wp->request = 'fr/Plugins';
	if ( home_url( '/fr/plugins/' ) !== $canonical_request_method->invoke( null, '<article>Translated content only</article>' ) ) {
		$failures[] = 'canonical_current_request_url_resolution_failed';
	}
	$wp->request = $before_request_path;
	$_SERVER['REQUEST_URI'] = $before_request_uri;

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
	if ( $translation_id > 0 ) {
		wp_delete_post( $translation_id, true );
	}
	if ( $post_id > 0 ) {
		wp_delete_post( $post_id, true );
	}
	if ( $missing_languages_marker === $languages_before ) {
		delete_option( Devenia_Workflow::OPTION_LANGUAGES );
	} else {
		update_option( Devenia_Workflow::OPTION_LANGUAGES, $languages_before, false );
	}
	Devenia_Workflow::languages( true );
}
