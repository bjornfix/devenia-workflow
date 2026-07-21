<?php
/**
 * Dev runtime contract for the finite Translation Job lifecycle.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$source_id = 0;
$linked_source_id = 0;
$localized_link_source_id = 0;
$localized_link_target_id = 0;
$translation_id = 0;
$nested_route_translation_id = 0;
$nested_route_parent_ids = array();
$source_thumbnail_id = 0;
$source_thumbnail_file = '';
$replacement_thumbnail_id = 0;
$replacement_thumbnail_file = '';
$option_keys = array();
$original_user_id = get_current_user_id();
$languages_option_before = get_option( 'devenia_workflow_language_registry' );
$runtime_provenance_before = get_option( 'devenia_workflow_runtime_mutation_provenance' );
$menu_identities_before = get_option( 'devenia_workflow_localized_menu_identities' );
$public_header_manifest_before = get_option( 'devenia_workflow_public_header_manifest' );
$pending_public_header_manifest_before = get_option( 'devenia_workflow_pending_public_header_manifest' );
$public_header_enrollment_before = get_option( 'devenia_workflow_public_header_enrollment' );
$runtime_option_missing = '__devenia_workflow_runtime_option_missing__';
$show_on_front_before = get_option( 'show_on_front', $runtime_option_missing );
$page_on_front_before = get_option( 'page_on_front', $runtime_option_missing );
$page_for_posts_before = get_option( 'page_for_posts', $runtime_option_missing );
$source_inventory_dirty_before = get_option( 'devenia_workflow_source_inventory_dirty' );
$source_inventory_active_before = get_option( 'devenia_workflow_source_inventory_active' );
$source_inventory_rebuild_before = get_option( 'devenia_workflow_source_inventory_rebuild' );
$source_inventory_epoch_before = get_option( 'devenia_workflow_source_inventory_epoch' );
$nav_menu_locations_before = get_theme_mod( 'nav_menu_locations', array() );
$runtime_menu_ids = array();
$runtime_source_menu_id = 0;
$runtime_translation_ids_by_language = array();
$runtime_header_source_id = 0;
$runtime_header_translation_ids_by_language = array();
$runtime_header_blog_source_id = 0;
$runtime_header_blog_translation_ids_by_language = array();
$runtime_page_link = null;
$runtime_http_surface = null;
$runtime_batch_http = null;
$cache_invalidation_calls = array();
$call = null;
$publish_claim_probe_enabled = false;
$publish_claim_probe_job_id = '';
$publish_claim_probe_run_id = '';
$publish_claim_probe = null;
$runtime_error = null;
$runtime_result = null;
$cache_adapter = static function ( $default_result, array $urls, array $context ) use ( &$cache_invalidation_calls, &$call, &$publish_claim_probe_enabled, &$publish_claim_probe_job_id, &$publish_claim_probe_run_id, &$publish_claim_probe ): array {
	$cache_invalidation_calls[] = array( 'default' => $default_result, 'urls' => $urls, 'context' => $context );
	if (
		$publish_claim_probe_enabled
		&& is_callable( $call )
		&& 'localized_presentation_publication' === (string) ( $context['event'] ?? '' )
		&& $publish_claim_probe_job_id === (string) ( $context['job_id'] ?? '' )
	) {
		$publish_claim_probe_enabled = false;
		$publish_claim_probe = $call(
			'translation_job_claim',
			array( 'job_id' => $publish_claim_probe_job_id, 'run_id' => $publish_claim_probe_run_id, 'coordinator_id' => 'runtime-concurrent-claim', 'role' => 'translator', 'ttl_seconds' => 600 )
		);
	}
	return array( 'success' => true, 'purged_urls' => $urls, 'adapter' => 'translation-job-runtime' );
};
add_filter( 'devenia_workflow_frontend_cache_invalidation_result', $cache_adapter, 10, 3 );

$call = static function ( string $method, ...$arguments ) {
	$reflection = new ReflectionMethod( Devenia_Workflow::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $arguments );
};
$raw_option_records = static function ( array $option_names ): array {
	global $wpdb;
	$option_names = array_values( array_unique( array_map( 'strval', $option_names ) ) );
	if ( empty( $option_names ) ) { return array(); }
	$placeholders = implode( ', ', array_fill( 0, count( $option_names ), '%s' ) );
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name IN ({$placeholders}) ORDER BY option_name ASC, option_id ASC", $option_names ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Generated placeholders only; immutable runtime oracle bypasses option caches.
};
$raw_translation_content_surface = static function ( int $post_id ): array {
	global $wpdb;
	$post = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw publication oracle.
	$postmeta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_key ASC, meta_id ASC", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw publication oracle.
	$terms = $wpdb->get_results( $wpdb->prepare( "SELECT tr.object_id, tr.term_taxonomy_id, tr.term_order, tt.term_id, tt.taxonomy, tt.description, tt.parent, tt.count, t.name, t.slug, t.term_group FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id = %d ORDER BY tt.taxonomy ASC, tt.term_id ASC, tr.term_taxonomy_id ASC", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Raw taxonomy surface is part of exact content state.
	return array( 'post' => $post, 'postmeta' => $postmeta, 'terms' => $terms );
};
$raw_nav_menu_surface = static function ( array $menu_ids ): array {
	global $wpdb;
	$menu_ids = array_values( array_unique( array_filter( array_map( 'absint', $menu_ids ) ) ) );
	sort( $menu_ids, SORT_NUMERIC );
	if ( empty( $menu_ids ) ) { return array(); }
	$menu_placeholders = implode( ', ', array_fill( 0, count( $menu_ids ), '%d' ) );
	$terms = $wpdb->get_results( $wpdb->prepare( "SELECT t.*, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, tt.count FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.term_id IN ({$menu_placeholders}) AND tt.taxonomy = %s ORDER BY t.term_id ASC, tt.term_taxonomy_id ASC", array_merge( $menu_ids, array( 'nav_menu' ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Generated placeholders only; raw menu authority.
	$taxonomy_ids = array_values( array_unique( array_filter( array_map( 'absint', array_column( (array) $terms, 'term_taxonomy_id' ) ) ) ) );
	$relationships = array(); $posts = array(); $postmeta = array();
	if ( ! empty( $taxonomy_ids ) ) {
		$taxonomy_placeholders = implode( ', ', array_fill( 0, count( $taxonomy_ids ), '%d' ) );
		$relationships = $wpdb->get_results( $wpdb->prepare( "SELECT object_id, term_taxonomy_id, term_order FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$taxonomy_placeholders}) ORDER BY term_taxonomy_id ASC, term_order ASC, object_id ASC", $taxonomy_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Generated placeholders only; raw menu relationships.
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', array_column( (array) $relationships, 'object_id' ) ) ) ) );
		if ( ! empty( $post_ids ) ) {
			$post_placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );
			$posts = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID IN ({$post_placeholders}) ORDER BY ID ASC", $post_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Generated placeholders only; raw menu-item posts.
			$postmeta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$post_placeholders}) ORDER BY post_id ASC, meta_key ASC, meta_id ASC", $post_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Generated placeholders only; raw menu-item metadata.
		}
	}
	$termmeta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id IN ({$menu_placeholders}) ORDER BY term_id ASC, meta_key ASC, meta_id ASC", $menu_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Generated placeholders only; raw managed-menu metadata.
	return array( 'terms' => $terms, 'relationships' => $relationships, 'posts' => $posts, 'postmeta' => $postmeta, 'termmeta' => $termmeta );
};
$raw_nav_menu_inventory = static function (): array {
	global $wpdb;
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, tt.term_taxonomy_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s ORDER BY t.term_id ASC, tt.term_taxonomy_id ASC", 'nav_menu' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact raw inventory detects an orphan staged menu outside the known authority IDs.
};
$workflow_reflection = new ReflectionClass( Devenia_Workflow::class );
$runtime_meta_status_key = (string) $workflow_reflection->getConstant( 'META_STATUS' );
$track_quality_result = static function ( array $result ) use ( &$option_keys ): void {
	$quality = isset( $result['quality_decision'] ) && is_array( $result['quality_decision'] )
		? $result['quality_decision']
		: array();
	$quality_revision = (string) ( $quality['quality_revision'] ?? '' );
	$evidence_revision = (string) ( $quality['evidence_revision'] ?? '' );
	if ( '' !== $quality_revision ) {
		$option_keys[] = 'devenia_workflow_translation_quality_' . $quality_revision;
	}
	if ( '' !== $evidence_revision ) {
		$option_keys[] = 'devenia_tj_quality_evidence_' . $evidence_revision;
	}
};
$quality_payload = static function ( array $claim, string $artifact_revision, string $decision, string $observations, array $corrections = array() ) use ( $call, &$option_keys ): array {
	$job_id = (string) ( $claim['run']['job_id'] ?? '' );
	$run_id = (string) ( $claim['run']['run_id'] ?? '' );
	$token = (string) ( $claim['claim_token'] ?? '' );
	$packet_result = $call( 'translation_job_fetch_packet', array( 'job_id' => $job_id, 'run_id' => $run_id, 'claim_token' => $token ) );
	if ( empty( $packet_result['success'] ) ) { throw new RuntimeException( 'Quality packet fixture failed: ' . wp_json_encode( $packet_result ) ); }
	$packet = (array) $packet_result['packet'];
	foreach ( (array) ( $packet['evidence_contract']['server_receipt_ids'] ?? array() ) as $receipt_id ) { $option_keys[] = 'devenia_tj_quality_receipt_' . sanitize_key( (string) $receipt_id ); }
	$surface_revision = (string) ( $packet['surface_revision'] ?? '' );
	$digest = str_repeat( 'a', 64 );
	$browser = array();
	foreach ( array( 'desktop' => array( 'width' => 1140, 'height' => 800, 'device_scale_factor' => 1 ), 'mobile' => array( 'width' => 390, 'height' => 844, 'device_scale_factor' => 1 ) ) as $viewport_scheme => $viewport ) {
		foreach ( array( 'light', 'dark' ) as $color_scheme ) {
			$browser[] = array( 'artifact_revision' => $artifact_revision, 'surface_revision' => $surface_revision, 'viewport_scheme' => $viewport_scheme, 'viewport' => $viewport, 'color_scheme' => $color_scheme, 'url' => home_url( '/runtime-quality-preview/' ), 'response_digest' => $digest, 'document_language' => 'nb-NO', 'document_direction' => 'ltr', 'layout_digest' => hash( 'sha256', $viewport_scheme . $color_scheme . 'layout' ), 'screenshot_digest' => hash( 'sha256', $viewport_scheme . $color_scheme . 'screenshot' ), 'checked_at' => gmdate( 'c' ), 'adapter' => 'runtime-fixture' );
		}
	}
	return array(
		'job_id' => $job_id, 'run_id' => $run_id, 'claim_token' => $token,
		'artifact_revision' => $artifact_revision, 'surface_revision' => $surface_revision, 'decision' => $decision,
		'evidence_receipt_ids' => array_values( (array) ( $packet['evidence_contract']['server_receipt_ids'] ?? array() ) ),
		'reviewer_attestations' => array(
			array( 'kind' => 'natural_language', 'passed' => true, 'observation' => 'Runtime reviewer inspected the complete localized wording and found concrete natural-language evidence.' ),
			array( 'kind' => 'factual_accuracy', 'passed' => true, 'observation' => 'Runtime reviewer compared every fixture claim with the approved source and found factual alignment.' ),
		),
		'reviewer_observations' => $observations, 'browser_receipts' => $browser, 'corrections' => $corrections,
		'usage' => array( 'input_tokens' => 700, 'cached_input_tokens' => 0, 'output_tokens' => 200, 'attempts' => 1, 'duration_ms' => 700, 'estimated_cost_microusd' => 50 ),
	);
};

// This oracle exercises the Translation Job Interface independently from the
// whole-site Inventory Generation Store. Fixture sources are intentionally
// drafts and therefore have no public obligation row. Preserve and restore the
// exact inventory control options so Job transitions cannot mutate a real dev
// generation or leave its epoch/dirty state changed by fixture hooks.
delete_option( 'devenia_workflow_source_inventory_active' );
delete_option( 'devenia_workflow_source_inventory_rebuild' );

try {
	$query_identity_unknown_id = 2147483000;
	$query_identity_root_target = home_url( '/runtime-query-identity-must-not-match/' );
	$query_identity_root_path = (string) $call( 'normalized_url_path', home_url( '/' ) );
	$query_identity_root_map = array();
	foreach ( array_filter( array_unique( array( home_url( '/' ), '/', '//', $query_identity_root_path, trailingslashit( $query_identity_root_path ), untrailingslashit( $query_identity_root_path ) ) ) ) as $root_candidate ) {
		$query_identity_root_map[ (string) $root_candidate ] = $query_identity_root_target;
	}
	foreach ( array( 'page_id', 'p', 'post_id' ) as $query_identity_var ) {
		foreach ( array( home_url( '/?' . $query_identity_var . '=' . $query_identity_unknown_id . '#runtime-query-identity' ), '/?' . $query_identity_var . '=' . $query_identity_unknown_id . '#runtime-query-identity' ) as $query_identity_url ) {
			if ( null !== $call( 'localized_internal_link_target', $query_identity_url, $query_identity_root_map ) ) {
				throw new RuntimeException( 'Unknown WordPress query-ID link fell through to root-path localized authority.' );
			}
		}
	}

	$replace_fragment = new ReflectionMethod( Devenia_Workflow::class, 'replace_source_design_text_html' );
	$replace_fragment->setAccessible( true );
	$projected_heading = (string) $replace_fragment->invoke(
		null,
		'<h1 class="gb-headline gb-headline-fixture">Source heading</h1>',
		'<h1 class="gb-headline gb-headline-fixture">Titolo tradotto</h1>'
	);
	$projected_button = (string) $replace_fragment->invoke(
		null,
		'<div class="wp-block-button"><a href="/source/">Source action</a></div>',
		'<div class="wp-block-button"><a href="/it/azione/">Azione tradotta</a></div>'
	);
	if ( 1 !== substr_count( strtolower( $projected_heading ), '<h1' ) || 1 !== substr_count( $projected_button, 'wp-block-button' ) ) {
		throw new RuntimeException( 'Full-wrapper localized fragments created duplicate block shells.' );
	}

	$linked_source_id = wp_insert_post(
		array(
			'post_type' => 'page',
			'post_status' => 'draft',
			'post_title' => 'Translation Job linked source fixture',
			'post_content' => '<!-- wp:paragraph --><p>A valid internal link target.</p><!-- /wp:paragraph -->',
		),
		true
	);
	if ( is_wp_error( $linked_source_id ) ) {
		throw new RuntimeException( $linked_source_id->get_error_message() );
	}
	$linked_source_url = (string) get_permalink( $linked_source_id );

	$source_id = wp_insert_post(
		array(
			'post_type' => 'page',
			'post_status' => 'draft',
			'post_title' => 'Translation Job source fixture',
			'post_excerpt' => 'A useful source excerpt.',
			'post_content' => '<!-- wp:paragraph --><p><strong>Useful source</strong><br>Read <a href="' . esc_url( $linked_source_url ) . '">the linked source</a>, then <a href="mailto:hello@example.com?subject=Source%20question&amp;body=Hello%20from%20the%20source">contact us</a> for a concrete next step.</p><!-- /wp:paragraph -->'
				. '<!-- wp:generateblocks/query {"uniqueId":"runtime-query","tagName":"section","query":{"post_type":["page"]}} --><section>'
				. '<!-- wp:generateblocks/looper {"uniqueId":"runtime-loop","tagName":"div"} --><div>'
				. '<!-- wp:generateblocks/loop-item {"uniqueId":"runtime-item","tagName":"div"} --><div>'
				. '<!-- wp:generateblocks/text {"uniqueId":"runtime-dynamic-link","tagName":"a","htmlAttributes":{"href":"{{post_permalink}}"}} -->'
				. '<a class="gb-text" href="{{post_permalink}}">View plugin</a>'
				. '<!-- /wp:generateblocks/text --></div><!-- /wp:generateblocks/loop-item -->'
				. '</div><!-- /wp:generateblocks/looper --></section><!-- /wp:generateblocks/query -->',
		),
		true
	);
	if ( is_wp_error( $source_id ) ) {
		throw new RuntimeException( $source_id->get_error_message() );
	}
	$uploads = wp_upload_dir();
	$source_thumbnail_file = trailingslashit( (string) $uploads['path'] ) . 'translation-job-source-media-' . wp_generate_password( 8, false, false ) . '.png';
	$fixture_png_a = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true );
	if ( false === $fixture_png_a || false === file_put_contents( $source_thumbnail_file, $fixture_png_a ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Ephemeral real-byte identity fixture.
		throw new RuntimeException( 'Could not write the source featured-image byte fixture.' );
	}
	$source_thumbnail_id = wp_insert_attachment(
		array(
			'post_title' => 'Translation Job source media fixture',
			'post_status' => 'inherit',
			'post_mime_type' => 'image/png',
		),
		$source_thumbnail_file
	);
	if ( ! $source_thumbnail_id || is_wp_error( $source_thumbnail_id ) ) {
		throw new RuntimeException( 'Could not create the source featured-image fixture.' );
	}
	update_attached_file( $source_thumbnail_id, $source_thumbnail_file );
	update_post_meta( $source_thumbnail_id, '_wp_attachment_metadata', array( 'width' => 1, 'height' => 1, 'file' => basename( $source_thumbnail_file ) ) );
	update_post_meta( $source_id, '_thumbnail_id', $source_thumbnail_id );

	$languages_method = new ReflectionMethod( Devenia_Workflow::class, 'target_languages' );
	$languages_method->setAccessible( true );
	$languages = $languages_method->invoke( null );
	$language_keys = array_keys( $languages );
	$language = isset( $languages['nb'] ) ? 'nb' : ( isset( $language_keys[0] ) ? (string) $language_keys[0] : '' );
	if ( '' === $language ) {
		throw new RuntimeException( 'No target language is configured.' );
	}
	$localized_link_source_insert = wp_insert_post(
		array(
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_title' => 'Translation Job published link source fixture',
			'post_content' => '<!-- wp:paragraph --><p>A published source with one exact localized destination.</p><!-- /wp:paragraph -->',
			'post_name' => 'runtime-link-source-' . strtolower( wp_generate_password( 6, false, false ) ),
		),
		true
	);
	if ( is_wp_error( $localized_link_source_insert ) || $localized_link_source_insert < 1 ) {
		throw new RuntimeException( 'Could not create the published localized-link source fixture.' );
	}
	$localized_link_source_id = (int) $localized_link_source_insert;
	$localized_link_target_slug = 'runtime-link-target-' . strtolower( wp_generate_password( 6, false, false ) );
	$localized_link_target_insert = wp_insert_post(
		array(
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_title' => 'Translation Job published link target fixture',
			'post_content' => '<!-- wp:paragraph --><p>The exact published localized destination.</p><!-- /wp:paragraph -->',
			'post_name' => $localized_link_target_slug,
		),
		true
	);
	if ( is_wp_error( $localized_link_target_insert ) || $localized_link_target_insert < 1 ) {
		throw new RuntimeException( 'Could not create the published localized-link target fixture.' );
	}
	$localized_link_target_id = (int) $localized_link_target_insert;
	update_post_meta( $localized_link_target_id, '_devenia_translation_source_id', $localized_link_source_id );
	update_post_meta( $localized_link_target_id, '_devenia_translation_language', $language );
	update_post_meta( $localized_link_target_id, '_devenia_translation_status', 'published' );
	update_post_meta( $localized_link_target_id, '_devenia_translation_localized_path', trim( $language . '/' . $localized_link_target_slug, '/' ) );
	clean_post_cache( $localized_link_target_id );
	if ( ! $call( 'sync_translation_index_row', $localized_link_target_id ) ) {
		throw new RuntimeException( 'Could not index the published localized-link target fixture.' );
	}
	$localized_link_source_url = (string) get_permalink( $localized_link_source_id );
	$localized_link_target_url = (string) get_permalink( $localized_link_target_id );
	if ( '' === $localized_link_source_url || '' === $localized_link_target_url || $call( 'normalized_comparable_url', $localized_link_source_url ) === $call( 'normalized_comparable_url', $localized_link_target_url ) ) {
		throw new RuntimeException( 'Published localized-link fixture did not establish distinct source and target URLs.' );
	}
	$source_post_for_links = get_post( $source_id );
	$localized_link_content = $source_post_for_links instanceof WP_Post
		? str_replace(
			', then <a href="mailto:',
			', read <a href="' . esc_url( $localized_link_source_url ) . '">the published localized source</a>, then <a href="mailto:',
			(string) $source_post_for_links->post_content
		)
		: '';
	if ( '' === $localized_link_content || $source_post_for_links->post_content === $localized_link_content || $source_id !== wp_update_post( wp_slash( array( 'ID' => $source_id, 'post_content' => $localized_link_content ) ) ) ) {
		throw new RuntimeException( 'Could not add both untranslated and published-localized links to the source packet fixture.' );
	}
	clean_post_cache( $source_id );
	$localized_query_identity_map = $call( 'localized_internal_link_map', $language, true );
	foreach ( array( 'page_id', 'p', 'post_id' ) as $localized_query_var ) {
		foreach ( array( $localized_link_source_id, $localized_link_target_id ) as $localized_query_post_id ) {
			$localized_query_target = $call( 'localized_internal_link_target', home_url( '/?' . $localized_query_var . '=' . $localized_query_post_id ), $localized_query_identity_map );
			if ( $call( 'normalized_comparable_url', (string) $localized_query_target ) !== $call( 'normalized_comparable_url', $localized_link_target_url ) ) {
				throw new RuntimeException( 'Known WordPress query-ID link did not resolve by exact source/translation identity.' );
			}
		}
	}
	// Hierarchical translations can legitimately have nested localized routes.
	// Prove the parity resolver accepts an observed/stored WordPress hierarchy
	// without knowing a language code, route base, site, or post identity.
	$nested_route_slug = 'runtime-nested-route-' . strtolower( wp_generate_password( 6, false, false ) );
	$nested_root_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Runtime nested root', 'post_name' => 'runtime-root-' . strtolower( wp_generate_password( 6, false, false ) ) ), true );
	$nested_parent_id = ! is_wp_error( $nested_root_id ) ? wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Runtime nested archive', 'post_name' => 'runtime-archive', 'post_parent' => (int) $nested_root_id ), true ) : $nested_root_id;
	if ( is_wp_error( $nested_root_id ) || is_wp_error( $nested_parent_id ) || $nested_root_id < 1 || $nested_parent_id < 1 ) {
		throw new RuntimeException( 'Could not create the nested route parent hierarchy.' );
	}
	$nested_route_parent_ids = array( (int) $nested_parent_id, (int) $nested_root_id );
	$nested_route_translation_id = wp_insert_post(
		array(
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_title' => 'Runtime nested translation route',
			'post_content' => '<!-- wp:paragraph --><p>Nested route fixture.</p><!-- /wp:paragraph -->',
			'post_name' => $nested_route_slug,
			'post_parent' => (int) $nested_parent_id,
		),
		true
	);
	if ( is_wp_error( $nested_route_translation_id ) || $nested_route_translation_id < 1 ) {
		throw new RuntimeException( 'Could not create the nested route translation fixture.' );
	}
	update_post_meta( $nested_route_translation_id, '_devenia_translation_language', $language );
	$nested_route_path = trim( (string) get_page_uri( $nested_route_translation_id ), '/' );
	$nested_route_url = (string) get_permalink( $nested_route_translation_id );
	update_post_meta( $nested_route_translation_id, '_devenia_translation_localized_path', $nested_route_path );
	delete_post_meta( $nested_route_translation_id, '_devenia_translation_canonical_route_v1' );
	$nested_route_resolution = $call( 'effective_translation_canonical_route', get_post( $nested_route_translation_id ), $language );
	if (
		empty( $nested_route_resolution['success'] )
		|| $nested_route_path !== (string) ( $nested_route_resolution['route']['path'] ?? '' )
		|| $nested_route_url !== (string) ( $nested_route_resolution['route']['url'] ?? '' )
		|| metadata_exists( 'post', $nested_route_translation_id, '_devenia_translation_canonical_route_v1' )
	) {
		throw new RuntimeException( 'A legitimate observed nested WordPress route did not pass canonical parity: ' . wp_json_encode( $nested_route_resolution ) );
	}
	wp_delete_post( $nested_route_translation_id, true );
	$nested_route_translation_id = 0;
	foreach ( $nested_route_parent_ids as $nested_route_parent_id ) {
		wp_delete_post( $nested_route_parent_id, true );
	}
	$nested_route_parent_ids = array();
	$call( 'localized_internal_link_map', $language, true );
	$call( 'localized_link_expected_target_map', $language, true );
	$call( 'localized_link_module', $language, true );
	// Acquisition and renewal commonly occur within one wall-clock second.
	// Prove the renewal always advances the serialized expiry, retains the
	// owner token, blocks a competing owner, and releases only the renewed value.
	$lease_fixture_job = array(
		'job_id'         => 'tj_runtime_lease_' . wp_generate_password( 8, false, false ),
		'source_id'      => $source_id,
		'target_language' => $language,
	);
	$lease_fixture_key = 'devenia_workflow_lifecycle_lease_' . substr( hash( 'sha256', $source_id . '|' . $language ), 0, 32 );
	$option_keys[] = $lease_fixture_key;
	$lease_acquired = $call( 'translation_job_acquire_lifecycle_lease', $lease_fixture_job, 'runtime_same_second_acquire' );
	$lease_renewed = $call( 'translation_job_renew_lifecycle_lease', $lease_acquired );
	$call( 'translation_job_release_lifecycle_lease', $lease_acquired );
	$lease_after_stale_release = get_option( $lease_fixture_key );
	$lease_competitor = $call( 'translation_job_acquire_lifecycle_lease', $lease_fixture_job, 'runtime_competing_acquire' );
	if (
		empty( $lease_acquired['success'] )
		|| empty( $lease_renewed['success'] )
		|| (string) ( $lease_acquired['lease']['token'] ?? '' ) !== (string) ( $lease_renewed['lease']['token'] ?? '' )
		|| absint( $lease_renewed['lease']['expires_at'] ?? 0 ) <= absint( $lease_acquired['lease']['expires_at'] ?? 0 )
		|| maybe_serialize( $lease_renewed['lease'] ?? null ) !== maybe_serialize( $lease_after_stale_release )
		|| 'translation_job_lifecycle_lease_conflict' !== (string) ( $lease_competitor['code'] ?? '' )
	) {
		throw new RuntimeException( 'Same-second lifecycle lease renewal did not advance an owned CAS while rejecting stale release and excluding a competitor: ' . wp_json_encode( array( 'acquired' => $lease_acquired, 'renewed' => $lease_renewed, 'after_stale_release' => $lease_after_stale_release, 'competitor' => $lease_competitor ) ) );
	}
	$call( 'translation_job_release_lifecycle_lease', $lease_renewed );
	if ( false !== get_option( $lease_fixture_key ) ) {
		throw new RuntimeException( 'Renewed lifecycle lease was not released by its exact owned value.' );
	}
	$claim_release_job_id = 'tj_runtime_claim_release_' . wp_generate_password( 8, false, false );
	$claim_release_key = 'devenia_workflow_translation_job_claim_' . $claim_release_job_id;
	$option_keys[] = $claim_release_key;
	$old_claim_owner = array( 'job_id' => $claim_release_job_id, 'run_id' => 'runtime-old-owner', 'token_hash' => hash( 'sha256', 'runtime-old-owner' ) );
	$new_claim_owner = array( 'job_id' => $claim_release_job_id, 'run_id' => 'runtime-new-owner', 'token_hash' => hash( 'sha256', 'runtime-new-owner' ) );
	add_option( $claim_release_key, $old_claim_owner, '', false );
	update_option( $claim_release_key, $new_claim_owner, false );
	$old_release_result = $call( 'translation_job_release_claim', $old_claim_owner );
	if ( false !== $old_release_result || maybe_serialize( $new_claim_owner ) !== maybe_serialize( get_option( $claim_release_key ) ) ) {
		throw new RuntimeException( 'Exact claim release removed a successor claim owned by a newer Run.' );
	}
	if ( empty( $call( 'translation_job_release_claim', $new_claim_owner ) ) || false !== get_option( $claim_release_key ) ) {
		throw new RuntimeException( 'Exact claim release could not remove its own current claim.' );
	}
	$atomic_noop_key = 'devenia_workflow_runtime_atomic_noop_' . strtolower( wp_generate_password( 8, false, false ) );
	$option_keys[] = $atomic_noop_key;
	$atomic_noop_owned = array( 'owner' => 'runtime-exact', 'revision' => 1 );
	$atomic_noop_successor = array( 'owner' => 'runtime-successor', 'revision' => 2 );
	add_option( $atomic_noop_key, $atomic_noop_owned, '', false );
	$atomic_noop_owned_result = $call( 'atomic_replace_option_value', $atomic_noop_key, $atomic_noop_owned, $atomic_noop_owned );
	update_option( $atomic_noop_key, $atomic_noop_successor, false );
	$atomic_noop_stale_result = $call( 'atomic_replace_option_value', $atomic_noop_key, $atomic_noop_owned, $atomic_noop_owned );
	if (
		empty( $atomic_noop_owned_result )
		|| false !== $atomic_noop_stale_result
		|| maybe_serialize( $atomic_noop_successor ) !== maybe_serialize( get_option( $atomic_noop_key ) )
	) {
		throw new RuntimeException( 'Idempotent exact CAS did not distinguish the owned no-op from a changed current value.' );
	}

	// Contract Refresh may only retire the exact running owner. A different
	// immutable terminal outcome must fail without changing the Job or claim.
	$retirement_failure_job_id = 'tj_runtime_retirement_failure_' . wp_generate_password( 8, false, false );
	$retirement_failure_run_id = 'runtime-retirement-failure-' . wp_generate_password( 8, false, false );
	$retirement_failure_job_key = 'devenia_workflow_translation_job_' . $retirement_failure_job_id;
	$retirement_failure_run_key = 'devenia_workflow_translation_run_' . $retirement_failure_run_id;
	$retirement_failure_claim_key = 'devenia_workflow_translation_job_claim_' . $retirement_failure_job_id;
	array_push( $option_keys, $retirement_failure_job_key, $retirement_failure_run_key, $retirement_failure_claim_key );
	$retirement_failure_job = array( 'job_id' => $retirement_failure_job_id, 'active_run_id' => $retirement_failure_run_id, 'artifact_revision' => 'a_runtime_retirement_failure', 'status' => 'quality_pending' );
	$retirement_failure_run = array( 'run_id' => $retirement_failure_run_id, 'job_id' => $retirement_failure_job_id, 'status' => 'completed', 'outcome' => 'submitted' );
	$retirement_failure_claim = array( 'job_id' => $retirement_failure_job_id, 'run_id' => $retirement_failure_run_id, 'token_hash' => hash( 'sha256', $retirement_failure_run_id ) );
	add_option( $retirement_failure_job_key, $retirement_failure_job, '', false );
	add_option( $retirement_failure_run_key, $retirement_failure_run, '', false );
	add_option( $retirement_failure_claim_key, $retirement_failure_claim, '', false );
	$retirement_failure_job_bytes = maybe_serialize( get_option( $retirement_failure_job_key ) );
	$retirement_failure_result = $call( 'translation_job_retire_contract_refresh_run_and_claim', $retirement_failure_job, $retirement_failure_claim );
	if (
		'contract_refresh_run_terminal_conflict' !== (string) ( $retirement_failure_result['code'] ?? '' )
		|| empty( $retirement_failure_result['retryable'] )
		|| $retirement_failure_job_bytes !== maybe_serialize( get_option( $retirement_failure_job_key ) )
		|| maybe_serialize( $retirement_failure_run ) !== maybe_serialize( get_option( $retirement_failure_run_key ) )
		|| maybe_serialize( $retirement_failure_claim ) !== maybe_serialize( get_option( $retirement_failure_claim_key ) )
	) {
		throw new RuntimeException( 'Contract Refresh retirement failure changed Job state or immutable Run outcome: ' . wp_json_encode( $retirement_failure_result ) );
	}

	// A successor claim can appear between the caller snapshot and retirement.
	// It must be preserved, and the active Job/old Run must remain retryable.
	$successor_job_id = 'tj_runtime_successor_claim_' . wp_generate_password( 8, false, false );
	$successor_old_run_id = 'runtime-successor-old-' . wp_generate_password( 8, false, false );
	$successor_new_run_id = 'runtime-successor-new-' . wp_generate_password( 8, false, false );
	$successor_job_key = 'devenia_workflow_translation_job_' . $successor_job_id;
	$successor_run_key = 'devenia_workflow_translation_run_' . $successor_old_run_id;
	$successor_claim_key = 'devenia_workflow_translation_job_claim_' . $successor_job_id;
	array_push( $option_keys, $successor_job_key, $successor_run_key, $successor_claim_key );
	$successor_job = array( 'job_id' => $successor_job_id, 'active_run_id' => $successor_old_run_id, 'artifact_revision' => 'a_runtime_successor', 'status' => 'quality_pending' );
	$successor_old_run = array( 'run_id' => $successor_old_run_id, 'job_id' => $successor_job_id, 'status' => 'running' );
	$successor_old_claim = array( 'job_id' => $successor_job_id, 'run_id' => $successor_old_run_id, 'token_hash' => hash( 'sha256', $successor_old_run_id ) );
	$successor_new_claim = array( 'job_id' => $successor_job_id, 'run_id' => $successor_new_run_id, 'token_hash' => hash( 'sha256', $successor_new_run_id ) );
	add_option( $successor_job_key, $successor_job, '', false );
	add_option( $successor_run_key, $successor_old_run, '', false );
	add_option( $successor_claim_key, $successor_new_claim, '', false );
	$successor_job_bytes = maybe_serialize( get_option( $successor_job_key ) );
	$successor_result = $call( 'translation_job_retire_contract_refresh_run_and_claim', $successor_job, $successor_old_claim );
	if (
		'contract_refresh_claim_release_conflict' !== (string) ( $successor_result['code'] ?? '' )
		|| empty( $successor_result['retryable'] )
		|| $successor_job_bytes !== maybe_serialize( get_option( $successor_job_key ) )
		|| maybe_serialize( $successor_old_run ) !== maybe_serialize( get_option( $successor_run_key ) )
		|| maybe_serialize( $successor_new_claim ) !== maybe_serialize( get_option( $successor_claim_key ) )
	) {
		throw new RuntimeException( 'Contract Refresh successor-claim conflict lost the successor claim, active refs, or old Run: ' . wp_json_encode( $successor_result ) );
	}

	// Once Contract Refresh wins the Run CAS, an old submit holding the stale
	// running snapshot cannot replace its terminal contract-refresh outcome.
	$old_submit_job_id = 'tj_runtime_old_submit_' . wp_generate_password( 8, false, false );
	$old_submit_run_id = 'runtime-old-submit-' . wp_generate_password( 8, false, false );
	$old_submit_run_key = 'devenia_workflow_translation_run_' . $old_submit_run_id;
	$old_submit_claim_key = 'devenia_workflow_translation_job_claim_' . $old_submit_job_id;
	array_push( $option_keys, $old_submit_run_key, $old_submit_claim_key );
	$old_submit_job = array( 'job_id' => $old_submit_job_id, 'active_run_id' => $old_submit_run_id );
	$old_submit_running = array( 'run_id' => $old_submit_run_id, 'job_id' => $old_submit_job_id, 'status' => 'running', 'started_at' => gmdate( 'c' ) );
	$old_submit_claim = array( 'job_id' => $old_submit_job_id, 'run_id' => $old_submit_run_id, 'token_hash' => hash( 'sha256', $old_submit_run_id ) );
	add_option( $old_submit_run_key, $old_submit_running, '', false );
	add_option( $old_submit_claim_key, $old_submit_claim, '', false );
	$old_submit_retirement = $call( 'translation_job_retire_contract_refresh_run_and_claim', $old_submit_job, $old_submit_claim );
	$old_submit_result = $call( 'translation_job_finish_run', $old_submit_running, 'submitted', array( 'measurement_state' => 'runtime_stale_submit' ) );
	$old_submit_stored = get_option( $old_submit_run_key );
	if (
		empty( $old_submit_retirement['success'] )
		|| false !== $old_submit_result
		|| 'completed' !== (string) ( $old_submit_stored['status'] ?? '' )
		|| 'contract_refresh_required' !== (string) ( $old_submit_stored['outcome'] ?? '' )
		|| false !== get_option( $old_submit_claim_key )
	) {
		throw new RuntimeException( 'Old submit overwrote the terminal contract_refresh_required Run: ' . wp_json_encode( compact( 'old_submit_retirement', 'old_submit_result', 'old_submit_stored' ) ) );
	}
	$runtime_text_input = array(
		'language' => $language,
		'section' => 'share_text',
		'source' => 'social_sharing_heading',
		'translated' => 'Runtime fixture share heading',
		'writer_process_id' => 'translation-job-runtime',
		'writer_actor' => 'Runtime contract',
	);
	wp_set_current_user( 0 );
	$unauthorized_runtime_text = $call( 'update_runtime_language_text', $runtime_text_input );
	if ( ! empty( $unauthorized_runtime_text['success'] ) ) {
		throw new RuntimeException( 'Runtime text update did not enforce manage_options.' );
	}
	$administrator_ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
	if ( empty( $administrator_ids ) ) {
		throw new RuntimeException( 'No administrator is available for the runtime text capability fixture.' );
	}
	wp_set_current_user( (int) $administrator_ids[0] );
	$authorized_runtime_text = $call( 'update_runtime_language_text', $runtime_text_input );
	if ( empty( $authorized_runtime_text['success'] ) ) {
		throw new RuntimeException( 'Capability-authorized runtime text update failed: ' . wp_json_encode( $authorized_runtime_text ) );
	}
	$sharing_heading = $call( 'localized_social_sharing_runtime_value', 'Owner runtime fixture heading', $language, 'share_text.social_sharing_heading' );
	if ( 'Runtime fixture share heading' !== $sharing_heading ) {
		throw new RuntimeException( 'The Translation Job runtime did not resolve the semantic owned sharing heading key.' );
	}
	$runtime_readiness = $call( 'language_runtime_readiness', $language, 'post' );
	if ( in_array( 'share_text.social_sharing_heading', (array) ( $runtime_readiness['missing'] ?? array() ), true ) ) {
		throw new RuntimeException( 'Post runtime readiness rejected the configured semantic owned sharing heading key.' );
	}
	$source_qa_options = $call(
		'update_source_qa_options',
		array(
			'source_id' => $source_id,
			'language' => $language,
			'terms' => array( 'Google Search Console' ),
		)
	);
	if ( empty( $source_qa_options['success'] ) ) {
		throw new RuntimeException( 'Source-scoped QA options failed: ' . wp_json_encode( $source_qa_options ) );
	}
	$carryover_candidates = new ReflectionMethod( Devenia_Workflow::class, 'source_language_carryover_candidates' );
	$carryover_candidates->setAccessible( true );
	$product_name_candidates = (array) $carryover_candidates->invoke(
		null,
		'<!-- wp:heading --><h2 class="wp-block-heading">Google Search Console</h2><!-- /wp:heading -->',
		$language,
		$source_id
	);
	if ( in_array( 'Search', $product_name_candidates, true ) || in_array( 'Console', $product_name_candidates, true ) ) {
		throw new RuntimeException( 'Tokens inside a source-scoped preserved product name were still treated as carryover.' );
	}
	$global_phrase_patch = array( 'preserve_terms' => array( 'Cascading Style Sheets' ) );
	$global_phrase_candidates = (array) $carryover_candidates->invoke(
		null,
		'<!-- wp:heading --><h2 class="wp-block-heading">Cascading Style Sheets</h2><!-- /wp:heading -->',
		$language,
		0,
		$global_phrase_patch
	);
	if ( in_array( 'Cascading', $global_phrase_candidates, true ) || in_array( 'Style', $global_phrase_candidates, true ) ) {
		throw new RuntimeException( 'Tokens inside a globally preserved multiword technical term were still treated as carryover.' );
	}
	$mixed_phrase_candidates = (array) $carryover_candidates->invoke(
		null,
		'<!-- wp:heading --><h2 class="wp-block-heading">Cascading Style Sheets</h2><!-- /wp:heading --><!-- wp:heading --><h2 class="wp-block-heading">Style Audit</h2><!-- /wp:heading -->',
		$language,
		0,
		$global_phrase_patch
	);
	if ( ! in_array( 'Style', $mixed_phrase_candidates, true ) || in_array( 'Cascading', $mixed_phrase_candidates, true ) ) {
		throw new RuntimeException( 'A global phrase exemption leaked to an isolated component token.' );
	}
	$strip_preserved_phrases = new ReflectionMethod( Devenia_Workflow::class, 'text_without_source_language_carryover_preserve_terms' );
	$strip_preserved_phrases->setAccessible( true );
	$phrase_filtered_text = (string) $strip_preserved_phrases->invoke( null, 'Gebruik Cascading Style Sheets; Style blijft hier los staan.', array( 'Cascading Style Sheets' ) );
	if ( false !== stripos( $phrase_filtered_text, 'Cascading' ) || false === stripos( $phrase_filtered_text, 'Style blijft' ) ) {
		throw new RuntimeException( 'Phrase-aware carryover normalization removed more than the configured complete phrase.' );
	}
	$unapproved_discover = $call( 'translation_job_discover', array( 'source_id' => $source_id, 'language' => $language ) );
	if ( ! empty( $unapproved_discover['success'] ) || 'source_quality_approval_required' !== (string) ( $unapproved_discover['code'] ?? '' ) ) {
		throw new RuntimeException( 'Unapproved source was not blocked: ' . wp_json_encode( $unapproved_discover ) );
	}
	// The approval is bound to the complete public Source Publication Surface.
	// Establish the final public post state before recording that immutable evidence.
	wp_update_post( array( 'ID' => $source_id, 'post_status' => 'publish' ) );
	$source_review = $call(
		'mark_source_content_integrity_reviewed',
		array(
			'source_id' => $source_id,
			'content_integrity_already_clean' => true,
			'audit_notes' => 'Runtime fixture passed current source integrity, Gutenberg, source revision, and bounded packet checks without a useful rewrite requirement.',
			'public_url' => get_permalink( $source_id ),
			'no_rewrite_reason' => 'The runtime fixture is intentionally minimal and complete for the contract it verifies; additional source copy would not improve the test evidence.',
			'reviewer_statement' => 'The runtime contract inspected the entire fixture source and accepts responsibility for this hash-bound source approval before translation.',
			'reviewer' => 'translation-job-runtime',
		)
	);
	if ( empty( $source_review['success'] ) ) {
		throw new RuntimeException( 'Source approval failed: ' . wp_json_encode( $source_review ) );
	}

	$expiry_languages = array_values( array_filter( $language_keys, static function ( $candidate ) use ( $language ) { return (string) $candidate !== $language; } ) );
	if ( empty( $expiry_languages ) ) {
		throw new RuntimeException( 'A second configured target language is required for the expired-Run fixture.' );
	}
	$expiry_discover = $call( 'translation_job_discover', array( 'source_id' => $source_id, 'language' => (string) $expiry_languages[0], 'observability_label' => 'runtime-expired-run' ) );
	$expiry_job_id = (string) ( $expiry_discover['job']['job_id'] ?? '' );
	if ( empty( $expiry_discover['success'] ) || '' === $expiry_job_id ) {
		throw new RuntimeException( 'Expired-Run fixture discovery failed: ' . wp_json_encode( $expiry_discover ) );
	}
	$option_keys[] = 'devenia_workflow_translation_job_' . $expiry_job_id;
	$option_keys[] = 'devenia_workflow_translation_job_claim_' . $expiry_job_id;
	$expiry_claim = $call(
		'translation_job_claim',
		array(
			'job_id' => $expiry_job_id,
			'run_id' => 'runtime-expiring-' . wp_generate_password( 8, false, false ),
			'coordinator_id' => 'runtime-coordinator',
			'role' => 'translator',
			'ttl_seconds' => 600,
		)
	);
	if ( empty( $expiry_claim['success'] ) ) {
		throw new RuntimeException( 'Expired-Run fixture claim failed: ' . wp_json_encode( $expiry_claim ) );
	}
	$expired_run_id = (string) $expiry_claim['run']['run_id'];
	$option_keys[] = 'devenia_workflow_translation_run_' . $expired_run_id;
	$expired_lock = get_option( 'devenia_workflow_translation_job_claim_' . $expiry_job_id );
	$expired_lock['expires_at'] = gmdate( 'c', time() - 1 );
	update_option( 'devenia_workflow_translation_job_claim_' . $expiry_job_id, $expired_lock, false );
	$replacement_claim = $call(
		'translation_job_claim',
		array(
			'job_id' => $expiry_job_id,
			'run_id' => 'runtime-replacement-' . wp_generate_password( 8, false, false ),
			'coordinator_id' => 'runtime-coordinator',
			'role' => 'translator',
			'ttl_seconds' => 600,
		)
	);
	$replacement_run_id = (string) ( $replacement_claim['run']['run_id'] ?? '' );
	$expired_run = get_option( 'devenia_workflow_translation_run_' . $expired_run_id );
	if (
		empty( $replacement_claim['success'] )
		|| 'completed' !== (string) ( $expired_run['status'] ?? '' )
		|| 'expired' !== (string) ( $expired_run['outcome'] ?? '' )
		|| empty( $expired_run['finished_at'] )
	) {
		throw new RuntimeException( 'Expired Run was not finalized before replacement claim: ' . wp_json_encode( array( 'replacement' => $replacement_claim, 'expired_run' => $expired_run ) ) );
	}
	$option_keys[] = 'devenia_workflow_translation_run_' . $replacement_run_id;
	$abandoned = $call(
		'translation_job_abandon',
		array(
			'job_id' => $expiry_job_id,
			'run_id' => $replacement_run_id,
			'claim_token' => (string) ( $replacement_claim['claim_token'] ?? '' ),
			'reason' => 'Runtime contract intentionally abandons the replacement fixture Run.',
		)
	);
	$abandoned_run = get_option( 'devenia_workflow_translation_run_' . $replacement_run_id );
	$abandoned_claim = get_option( 'devenia_workflow_translation_job_claim_' . $expiry_job_id );
	if (
		empty( $abandoned['success'] )
		|| 'completed' !== (string) ( $abandoned_run['status'] ?? '' )
		|| 'abandoned' !== (string) ( $abandoned_run['outcome'] ?? '' )
		|| false !== $abandoned_claim
		|| 'queued' !== (string) ( $abandoned['job']['status'] ?? '' )
	) {
		throw new RuntimeException( 'Run abandon did not restore the previous Job state: ' . wp_json_encode( array( 'abandoned' => $abandoned, 'run' => $abandoned_run, 'claim' => $abandoned_claim ) ) );
	}
	$contract_gap_active_claim = $call(
		'translation_job_claim',
		array(
			'job_id' => $expiry_job_id,
			'run_id' => 'runtime-contract-active-' . wp_generate_password( 8, false, false ),
			'coordinator_id' => 'runtime-contract-active-coordinator',
			'role' => 'translator',
			'ttl_seconds' => 600,
		)
	);
	if ( empty( $contract_gap_active_claim['success'] ) ) {
		throw new RuntimeException( 'Could not create the active Run used by the Contract Refresh retirement fixture: ' . wp_json_encode( $contract_gap_active_claim ) );
	}
	$contract_gap_active_run_id = (string) $contract_gap_active_claim['run']['run_id'];
	$contract_gap_active_run_key = 'devenia_workflow_translation_run_' . $contract_gap_active_run_id;
	$option_keys[] = $contract_gap_active_run_key;
	$contract_gap_revision = 'a_runtime_contract_gap_' . wp_generate_password( 8, false, false );
	$contract_gap_artifact_key = 'devenia_workflow_translation_artifact_' . $contract_gap_revision;
	$option_keys[] = $contract_gap_artifact_key;
	add_option(
		$contract_gap_artifact_key,
		array(
			'artifact_revision' => $contract_gap_revision,
			'artifact' => array( 'localized_fragments' => array() ),
		),
		'',
		false
	);
	$contract_gap_job_key = 'devenia_workflow_translation_job_' . $expiry_job_id;
	$contract_gap_job = get_option( $contract_gap_job_key );
	$contract_gap_job['status'] = 'quality_pending';
	$contract_gap_job['artifact_revision'] = $contract_gap_revision;
	$contract_gap_job['quality_revision'] = '';
	$contract_gap_job['active_run_id'] = $contract_gap_active_run_id;
	update_option( $contract_gap_job_key, $contract_gap_job, false );
	$contract_gap_artifact_bytes = maybe_serialize( get_option( $contract_gap_artifact_key ) );
	$contract_gap_quality_run_id = 'runtime-contract-quality-' . wp_generate_password( 8, false, false );
	$contract_gap_quality_run_key = 'devenia_workflow_translation_run_' . $contract_gap_quality_run_id;
	$option_keys[] = $contract_gap_quality_run_key;
	$contract_refresh_quality = $call(
		'translation_job_claim',
		array(
			'job_id' => $expiry_job_id,
			'run_id' => $contract_gap_quality_run_id,
			'coordinator_id' => 'runtime-quality-coordinator',
			'role' => 'quality',
			'ttl_seconds' => 600,
		)
	);
	$contract_gap_refreshed_job = get_option( $contract_gap_job_key );
	$contract_gap_history = (array) ( $contract_gap_refreshed_job['contract_refresh_history'] ?? array() );
	$contract_gap_latest = $contract_gap_history ? (array) end( $contract_gap_history ) : array();
	$contract_gap_retired_run = get_option( $contract_gap_active_run_key );
	if (
		'contract_refresh_required' !== (string) ( $contract_refresh_quality['code'] ?? '' )
		|| false !== get_option( $contract_gap_quality_run_key, false )
		|| false !== get_option( 'devenia_workflow_translation_job_claim_' . $expiry_job_id, false )
		|| 2 !== absint( $contract_gap_refreshed_job['submission_generation'] ?? 0 )
		|| 'changes_requested' !== (string) ( $contract_gap_refreshed_job['status'] ?? '' )
		|| '' !== (string) ( $contract_gap_refreshed_job['artifact_revision'] ?? '' )
		|| '' !== (string) ( $contract_gap_refreshed_job['quality_revision'] ?? '' )
		|| $contract_gap_revision !== (string) ( $contract_gap_latest['active_refs']['artifact_revision'] ?? '' )
		|| empty( $contract_gap_latest['mismatch_evidence']['coverage']['missing_keys'] )
		|| $contract_gap_artifact_bytes !== maybe_serialize( get_option( $contract_gap_artifact_key ) )
		|| 'completed' !== (string) ( $contract_gap_retired_run['status'] ?? '' )
		|| 'contract_refresh_required' !== (string) ( $contract_gap_retired_run['outcome'] ?? '' )
	) {
		throw new RuntimeException( 'Stale Quality contract created a Run/claim or failed to preserve immutable artifact evidence during exact Job refresh: ' . wp_json_encode( array( 'quality' => $contract_refresh_quality, 'job' => $contract_gap_refreshed_job, 'history' => $contract_gap_latest ) ) );
	}
	if ( 'completed' !== (string) ( $contract_gap_retired_run['status'] ?? '' ) || 'contract_refresh_required' !== (string) ( $contract_gap_retired_run['outcome'] ?? '' ) ) {
		throw new RuntimeException( 'Contract Refresh did not terminally retire the active Run with an explicit contract-refresh outcome.' );
	}
	$contract_gap_refreshed_job_bytes = maybe_serialize( $contract_gap_refreshed_job );
	$contract_gap_retired_run_bytes = maybe_serialize( $contract_gap_retired_run );
	$stale_contract_endpoint_input = array(
		'job_id' => $expiry_job_id,
		'run_id' => $contract_gap_active_run_id,
		'claim_token' => (string) ( $contract_gap_active_claim['claim_token'] ?? '' ),
	);
	$stale_fetch_after_refresh = $call( 'translation_job_fetch_packet', $stale_contract_endpoint_input );
	if (
		! empty( $stale_fetch_after_refresh['success'] )
		|| 'job_claim_missing' !== (string) ( $stale_fetch_after_refresh['code'] ?? '' )
		|| $contract_gap_refreshed_job_bytes !== maybe_serialize( get_option( $contract_gap_job_key ) )
		|| $contract_gap_retired_run_bytes !== maybe_serialize( get_option( $contract_gap_active_run_key ) )
	) {
		throw new RuntimeException( 'Stale fetch_packet after Contract Refresh changed the terminal Run or refreshed Job: ' . wp_json_encode( $stale_fetch_after_refresh ) );
	}
	$stale_abandon_after_refresh = $call(
		'translation_job_abandon',
		array_merge( $stale_contract_endpoint_input, array( 'reason' => 'Runtime stale owner must not abandon a Contract Refresh terminal Run.' ) )
	);
	if (
		! empty( $stale_abandon_after_refresh['success'] )
		|| 'job_claim_missing' !== (string) ( $stale_abandon_after_refresh['code'] ?? '' )
		|| $contract_gap_refreshed_job_bytes !== maybe_serialize( get_option( $contract_gap_job_key ) )
		|| $contract_gap_retired_run_bytes !== maybe_serialize( get_option( $contract_gap_active_run_key ) )
	) {
		throw new RuntimeException( 'Stale abandon after Contract Refresh changed the terminal Run or refreshed Job: ' . wp_json_encode( $stale_abandon_after_refresh ) );
	}

	// The budget migration uses the same exact-value CAS seam. A migration
	// prepared from a stale running owner cannot replace a terminal refresh Run.
	$budget_migration_stale = $contract_gap_active_claim['run'];
	$budget_migration_candidate = $budget_migration_stale;
	$budget_migration_candidate['budget'] = $call( 'translation_job_budget', (string) ( $budget_migration_stale['role'] ?? 'translator' ) );
	$budget_migration_candidate['budget_migrated_at'] = gmdate( 'c' );
	$budget_migration_result = $call( 'atomic_replace_option_value', $contract_gap_active_run_key, $budget_migration_stale, $budget_migration_candidate );
	if ( false !== $budget_migration_result || $contract_gap_retired_run_bytes !== maybe_serialize( get_option( $contract_gap_active_run_key ) ) ) {
		throw new RuntimeException( 'Budget migration stale-owner CAS replaced a terminal Run.' );
	}
	$contract_refresh_claim = $call(
		'translation_job_claim',
		array(
			'job_id' => $expiry_job_id,
			'run_id' => 'runtime-contract-refresh-' . wp_generate_password( 8, false, false ),
			'coordinator_id' => 'runtime-coordinator',
			'role' => 'translator',
			'ttl_seconds' => 600,
		)
	);
	$contract_refresh_run_id = (string) ( $contract_refresh_claim['run']['run_id'] ?? '' );
	$option_keys[] = 'devenia_workflow_translation_run_' . $contract_refresh_run_id;
	if (
		empty( $contract_refresh_claim['success'] )
		|| 'claimed' !== (string) ( $contract_refresh_claim['job']['status'] ?? '' )
		|| 2 !== absint( $contract_refresh_claim['run']['submission_generation'] ?? 0 )
		|| 2 !== absint( $contract_refresh_claim['run']['principal']['submission_generation'] ?? 0 )
		|| empty( $contract_refresh_claim['job']['contract_refresh_history'][0]['mismatch_evidence']['coverage']['missing_keys'] )
	) {
		throw new RuntimeException( 'Invalid quality-pending artifact did not reopen for translator correction: ' . wp_json_encode( $contract_refresh_claim ) );
	}
	$contract_refresh_abandoned = $call(
		'translation_job_abandon',
		array(
			'job_id' => $expiry_job_id,
			'run_id' => $contract_refresh_run_id,
			'claim_token' => (string) ( $contract_refresh_claim['claim_token'] ?? '' ),
			'reason' => 'Runtime contract completed the automatic contract-refresh claim fixture.',
		)
	);
	if ( empty( $contract_refresh_abandoned['success'] ) || 'changes_requested' !== (string) ( $contract_refresh_abandoned['job']['status'] ?? '' ) ) {
		throw new RuntimeException( 'Contract-refresh fixture did not restore changes_requested after abandon: ' . wp_json_encode( $contract_refresh_abandoned ) );
	}
	$contract_limit_job = get_option( $contract_gap_job_key );
	$contract_limit_job['submission_generation'] = 3;
	$contract_limit_job['publication_surface_contract_revision'] = 'pscr_runtime_obsolete';
	$contract_limit_job['contract_refresh_history'] = array();
	$contract_limit_job['contract_refresh_limit'] = array();
	$contract_limit_run_id = 'runtime-contract-limit-' . wp_generate_password( 8, false, false );
	$contract_limit_successor_run_id = 'runtime-contract-limit-successor-' . wp_generate_password( 8, false, false );
	$contract_limit_run_key = 'devenia_workflow_translation_run_' . $contract_limit_run_id;
	$contract_limit_claim_key = 'devenia_workflow_translation_job_claim_' . $expiry_job_id;
	$option_keys[] = $contract_limit_run_key;
	$contract_limit_run = array( 'run_id' => $contract_limit_run_id, 'job_id' => $expiry_job_id, 'status' => 'running', 'submission_generation' => 3 );
	$contract_limit_claim = array( 'job_id' => $expiry_job_id, 'run_id' => $contract_limit_run_id, 'token_hash' => hash( 'sha256', $contract_limit_run_id ), 'submission_generation' => 3 );
	$contract_limit_successor_claim = array( 'job_id' => $expiry_job_id, 'run_id' => $contract_limit_successor_run_id, 'token_hash' => hash( 'sha256', $contract_limit_successor_run_id ), 'submission_generation' => 3 );
	$contract_limit_job['active_run_id'] = $contract_limit_run_id;
	add_option( $contract_limit_run_key, $contract_limit_run, '', false );
	update_option( $contract_limit_claim_key, $contract_limit_successor_claim, false );
	update_option( $contract_gap_job_key, $contract_limit_job, false );
	$contract_limit_before_retry_bytes = maybe_serialize( get_option( $contract_gap_job_key ) );
	$contract_limit_conflict = $call( 'translation_job_refresh_publication_surface_contract_under_lifecycle_lease', $contract_limit_job, 'discover' );
	if (
		'contract_refresh_active_claim_mismatch' !== (string) ( $contract_limit_conflict['code'] ?? '' )
		|| empty( $contract_limit_conflict['retryable'] )
		|| $contract_limit_before_retry_bytes !== maybe_serialize( get_option( $contract_gap_job_key ) )
		|| maybe_serialize( $contract_limit_successor_claim ) !== maybe_serialize( get_option( $contract_limit_claim_key ) )
		|| maybe_serialize( $contract_limit_run ) !== maybe_serialize( get_option( $contract_limit_run_key ) )
	) {
		throw new RuntimeException( 'Generation-ceiling retirement conflict was not retryable with active refs intact: ' . wp_json_encode( $contract_limit_conflict ) );
	}
	update_option( $contract_limit_claim_key, $contract_limit_claim, false );
	$contract_limit_first = $call( 'translation_job_refresh_publication_surface_contract_under_lifecycle_lease', $contract_limit_job, 'discover' );
	$contract_limit_after_first = get_option( $contract_gap_job_key );
	$contract_limit_first_bytes = maybe_serialize( $contract_limit_after_first );
	$contract_limit_first_history_count = count( (array) ( $contract_limit_after_first['contract_refresh_history'] ?? array() ) );
	$contract_limit_retired_run = get_option( $contract_limit_run_key );
	$contract_limit_second = $call( 'translation_job_refresh_publication_surface_contract_under_lifecycle_lease', $contract_limit_after_first, 'discover' );
	$contract_limit_after_second = get_option( $contract_gap_job_key );
	if (
		'contract_refresh_generation_limit' !== (string) ( $contract_limit_first['code'] ?? '' )
		|| 'contract_refresh_generation_limit' !== (string) ( $contract_limit_second['code'] ?? '' )
		|| empty( $contract_limit_second['idempotent'] )
		|| 'completed' !== (string) ( $contract_limit_retired_run['status'] ?? '' )
		|| 'contract_refresh_required' !== (string) ( $contract_limit_retired_run['outcome'] ?? '' )
		|| false !== get_option( $contract_limit_claim_key )
		|| '' !== (string) ( $contract_limit_after_first['active_run_id'] ?? '' )
		|| 1 !== $contract_limit_first_history_count
		|| $contract_limit_first_history_count !== count( (array) ( $contract_limit_after_second['contract_refresh_history'] ?? array() ) )
		|| $contract_limit_first_bytes !== maybe_serialize( $contract_limit_after_second )
	) {
		throw new RuntimeException( 'Generation-ceiling retry did not finish pending retirement before the idempotent terminal state: ' . wp_json_encode( compact( 'contract_limit_first', 'contract_limit_second', 'contract_limit_after_first', 'contract_limit_after_second', 'contract_limit_retired_run' ) ) );
	}
	if ( $contract_limit_first_history_count !== count( (array) ( $contract_limit_after_second['contract_refresh_history'] ?? array() ) ) || $contract_limit_first_bytes !== maybe_serialize( $contract_limit_after_second ) ) {
		throw new RuntimeException( 'Repeated generation-limit Contract Refresh mutated history or Job state.' );
	}

	// Exercise the update path against a real public translation. Register the
	// fixture before Job discovery so the persisted Job identity matches the
	// real lifecycle of an already-existing translation.
	$runtime_localized_slug = 'oversatt-testside-' . strtolower( wp_generate_password( 6, false, false ) );
	$translation_id = wp_insert_post(
		array(
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_title' => 'Runtime pre-existing translation',
			'post_excerpt' => 'Runtime baseline before the staged artifact.',
			'post_content' => '<!-- wp:paragraph --><p>Runtime public baseline.</p><!-- /wp:paragraph -->',
			'post_name' => $runtime_localized_slug,
		),
		true
	);
	if ( is_wp_error( $translation_id ) || $translation_id < 1 ) {
		throw new RuntimeException( 'Could not create the pre-existing public translation fixture.' );
	}
	$runtime_translation_ids_by_language[ $language ] = (int) $translation_id;
	update_post_meta( $translation_id, '_devenia_translation_source_id', $source_id );
	update_post_meta( $translation_id, '_devenia_translation_language', $language );
	update_post_meta( $translation_id, '_devenia_translation_status', 'needs_review' );
	update_post_meta( $translation_id, '_thumbnail_id', $source_thumbnail_id );
	update_post_meta( $translation_id, 'rank_math_focus_keyword', 'stale-runtime-focus-keyword' );
	$runtime_localized_path = trim( $language . '/' . $runtime_localized_slug, '/' );
	update_post_meta( $translation_id, '_devenia_translation_localized_path', $runtime_localized_path );
	$runtime_translation_url = home_url( '/' . $runtime_localized_path . '/' );
	delete_post_meta( $translation_id, '_devenia_translation_canonical_route_v1' );
	clean_post_cache( $translation_id );
	$legacy_translation = get_post( $translation_id );
	$legacy_mismatch_resolution = $legacy_translation instanceof WP_Post
		? $call( 'effective_translation_canonical_route', $legacy_translation, $language )
		: array();
	if (
		! $legacy_translation instanceof WP_Post
		|| ! empty( $legacy_mismatch_resolution['success'] )
		|| 'canonical_route_observed_path_mismatch' !== (string) ( $legacy_mismatch_resolution['code'] ?? '' )
		|| $runtime_localized_path !== (string) ( $legacy_mismatch_resolution['stored_localized_path'] ?? '' )
		|| metadata_exists( 'post', $translation_id, '_devenia_translation_canonical_route_v1' )
	) {
		throw new RuntimeException( 'Legacy canonical route mismatch did not fail closed before staging: ' . wp_json_encode( $legacy_mismatch_resolution ) );
	}
	if ( ! $call( 'sync_translation_index_row', $translation_id ) ) {
		throw new RuntimeException( 'Could not index the pre-existing public translation fixture.' );
	}

	$discover = $call( 'translation_job_discover', array( 'source_id' => $source_id, 'language' => $language, 'observability_label' => 'runtime-contract' ) );
	if ( empty( $discover['success'] ) || empty( $discover['job']['job_id'] ) ) {
		throw new RuntimeException( 'Discover failed: ' . wp_json_encode( $discover ) );
	}
	$job_id = (string) $discover['job']['job_id'];
	$option_keys[] = 'devenia_workflow_translation_job_' . $job_id;
	$option_keys[] = 'devenia_workflow_translation_job_claim_' . $job_id;

	$claim = $call(
		'translation_job_claim',
		array(
			'job_id' => $job_id,
			'run_id' => 'runtime-translator-' . wp_generate_password( 8, false, false ),
			'coordinator_id' => 'runtime-coordinator',
			'role' => 'translator',
			'ttl_seconds' => 600,
		)
	);
	if ( empty( $claim['success'] ) || empty( $claim['claim_token'] ) ) {
		throw new RuntimeException( 'Translator claim failed: ' . wp_json_encode( $claim ) );
	}
	$translator_run_id = (string) $claim['run']['run_id'];
	$translator_token = (string) $claim['claim_token'];
	$option_keys[] = 'devenia_workflow_translation_run_' . $translator_run_id;
	$stored_claim = get_option( 'devenia_workflow_translation_job_claim_' . $job_id );
	if ( false !== strpos( wp_json_encode( $stored_claim ) ?: '', $translator_token ) ) {
		throw new RuntimeException( 'Claim token was stored in plaintext.' );
	}

	$packet = $call( 'translation_job_fetch_packet', array( 'job_id' => $job_id, 'run_id' => $translator_run_id, 'claim_token' => $translator_token ) );
	$fragments = $packet['packet']['fragments'] ?? array();
	$links = $packet['packet']['links'] ?? array();
	$language_profile = $packet['packet']['language_profile'] ?? array();
	$submission_contract = $packet['packet']['submission_contract'] ?? array();
	$artifact_schema_properties = $submission_contract['input_schema']['properties']['artifact']['properties'] ?? array();
	$fragment_schema_properties = $artifact_schema_properties['localized_fragments']['items']['properties'] ?? array();
	$untranslated_packet_link_rows = array_values( array_filter( (array) $links, static function ( $row ) use ( $linked_source_id ): bool { return is_array( $row ) && $linked_source_id === absint( $row['source_post_id'] ?? 0 ); } ) );
	$localized_packet_link_rows = array_values( array_filter( (array) $links, static function ( $row ) use ( $localized_link_source_id ): bool { return is_array( $row ) && $localized_link_source_id === absint( $row['source_post_id'] ?? 0 ); } ) );
	$untranslated_packet_link = 1 === count( $untranslated_packet_link_rows ) ? (array) $untranslated_packet_link_rows[0] : array();
	$localized_packet_link = 1 === count( $localized_packet_link_rows ) ? (array) $localized_packet_link_rows[0] : array();
	$expected_packet_link_url = (string) ( $untranslated_packet_link['target_url'] ?? '' );
	$expected_localized_packet_link_url = (string) ( $localized_packet_link['target_url'] ?? '' );
	$dynamic_packet_links = array_values( array_filter( (array) $links, static function ( $row ): bool { return is_array( $row ) && false !== strpos( (string) ( $row['source_url'] ?? '' ), '{{post_permalink}}' ); } ) );
	if (
		empty( $packet['success'] )
		|| $translation_id !== absint( $packet['packet']['route']['existing']['translation_id'] ?? 0 )
		|| $runtime_localized_path !== (string) ( $packet['packet']['route']['existing']['localized_path'] ?? '' )
		|| 1 !== count( $fragments )
		|| false === stripos( (string) $fragments[0]['source_html'], '<strong>' )
		|| 2 !== count( $links )
		|| ! empty( $dynamic_packet_links )
		|| 1 !== count( $untranslated_packet_link_rows )
		|| 1 !== count( $localized_packet_link_rows )
		|| '' === $expected_packet_link_url
		|| '' === $expected_localized_packet_link_url
		|| $linked_source_id !== absint( $untranslated_packet_link['target_post_id'] ?? 0 )
		|| false !== ( $untranslated_packet_link['published_target_available'] ?? null )
		|| 'retain_source_url_until_localized_target_is_published' !== (string) ( $untranslated_packet_link['policy'] ?? '' )
		|| $localized_link_target_id !== absint( $localized_packet_link['target_post_id'] ?? 0 )
		|| true !== ( $localized_packet_link['published_target_available'] ?? null )
		|| 'use_published_localized_target' !== (string) ( $localized_packet_link['policy'] ?? '' )
		|| $call( 'normalized_comparable_url', $expected_packet_link_url ) !== $call( 'normalized_comparable_url', $linked_source_url )
		|| $call( 'normalized_comparable_url', $expected_localized_packet_link_url ) !== $call( 'normalized_comparable_url', $localized_link_target_url )
		|| $call( 'normalized_comparable_url', (string) ( $localized_packet_link['source_url'] ?? '' ) ) === $call( 'normalized_comparable_url', $expected_localized_packet_link_url )
		|| ! in_array( 'Google Search Console', (array) ( $language_profile['source_qa_preserve_terms'] ?? array() ), true )
		|| 'devenia-workflow/translation-job-submit-artifact' !== (string) ( $submission_contract['ability'] ?? '' )
		|| ! in_array( 'usage', (array) ( $submission_contract['input_schema']['required'] ?? array() ), true )
		|| ! isset( $artifact_schema_properties['seo'] )
		|| isset( $artifact_schema_properties['seo_title'] )
		|| ! isset( $fragment_schema_properties['html'], $fragment_schema_properties['text'] )
		|| isset( $fragment_schema_properties['html_or_text'] )
	) {
		throw new RuntimeException( 'Bounded packet failed: ' . wp_json_encode( $packet ) );
	}

	$packet_link_markup = array();
	foreach ( (array) $links as $link ) {
		$packet_link_markup[] = '<a href="' . esc_url( (string) ( $link['target_url'] ?? '' ) ) . '">den lenkede kilden</a>';
	}
	$localized = array();
	foreach ( $fragments as $fragment ) {
		$localized[] = array( 'key' => (string) $fragment['key'], 'html' => '<strong>Nyttig innhold</strong><br>Les ' . implode( ' og ', $packet_link_markup ) . ', og <a href="mailto:hello@example.com?subject=Sp%C3%B8rsm%C3%A5l%20om%20testen&amp;body=Hei%20fra%20oversettelsen">kontakt oss</a> for et konkret neste steg.' );
	}
	$artifact = array(
		'title' => 'Oversatt testside',
		'excerpt' => 'En nyttig oversatt ingress.',
		'localized_slug' => $runtime_localized_slug,
		'localized_fragments' => $localized,
		'seo' => array( 'title' => 'Oversatt testside', 'description' => 'En nyttig beskrivelse av den oversatte testsiden.', 'focus_keyword' => '' ),
	);
	$pre_submit_surface_revision = $call( 'translation_job_current_surface_revision', $translation_id );
	$invalid_artifact = $artifact;
	$extra_internal_link_artifact = $artifact;
	$extra_internal_link_artifact['localized_fragments'][0]['html'] .= '<a href="' . esc_url( home_url( '/invented-localized-route/' ) ) . '">Oppdiktet mål</a>';
	$extra_internal_link_submit = $call(
		'translation_job_submit_artifact',
		array(
			'job_id' => $job_id,
			'run_id' => $translator_run_id,
			'claim_token' => $translator_token,
			'artifact' => $extra_internal_link_artifact,
			'usage' => array( 'input_tokens' => 1200, 'cached_input_tokens' => 0, 'output_tokens' => 500, 'attempts' => 1, 'duration_ms' => 1000, 'estimated_cost_microusd' => 100 ),
		)
	);
	$extra_internal_link_issues = (array) ( $extra_internal_link_submit['issues'] ?? array() );
	$unexpected_internal_issues = array_values( array_filter( $extra_internal_link_issues, static function ( $issue ): bool { return is_array( $issue ) && 'unexpected_internal_target' === (string) ( $issue['policy'] ?? '' ); } ) );
	if ( ! empty( $extra_internal_link_submit['success'] ) || 'artifact_link_policy_invalid' !== (string) ( $extra_internal_link_submit['code'] ?? '' ) || 1 !== count( $unexpected_internal_issues ) ) {
		throw new RuntimeException( 'An extra invented internal target was not rejected through Translation Artifact submit: ' . wp_json_encode( $extra_internal_link_submit ) );
	}
	$invalid_contact_artifact = $artifact;
	$invalid_contact_artifact['localized_fragments'][0]['html'] = str_replace(
		array( 'Sp%C3%B8rsm%C3%A5l%20om%20testen', 'Hei%20fra%20oversettelsen' ),
		array( 'Source%20question', 'Hello%20from%20the%20source' ),
		$invalid_contact_artifact['localized_fragments'][0]['html']
	);
	$invalid_contact_submit = $call(
		'translation_job_submit_artifact',
		array(
			'job_id' => $job_id,
			'run_id' => $translator_run_id,
			'claim_token' => $translator_token,
			'artifact' => $invalid_contact_artifact,
			'usage' => array( 'input_tokens' => 1200, 'cached_input_tokens' => 0, 'output_tokens' => 500, 'attempts' => 1, 'duration_ms' => 1000, 'estimated_cost_microusd' => 100 ),
		)
	);
	if ( ! empty( $invalid_contact_submit['success'] ) || 'artifact_contact_action_not_localized' !== (string) ( $invalid_contact_submit['code'] ?? '' ) ) {
		throw new RuntimeException( 'Source-language mailto query text was not rejected: ' . wp_json_encode( $invalid_contact_submit ) );
	}
	$invalid_artifact['localized_fragments'][0]['html'] = str_replace( $expected_packet_link_url, home_url( '/invented-localized-route/' ), $invalid_artifact['localized_fragments'][0]['html'] );
	$invalid_submit = $call(
		'translation_job_submit_artifact',
		array(
			'job_id' => $job_id,
			'run_id' => $translator_run_id,
			'claim_token' => $translator_token,
			'artifact' => $invalid_artifact,
			'usage' => array( 'input_tokens' => 1200, 'cached_input_tokens' => 0, 'output_tokens' => 500, 'attempts' => 1, 'duration_ms' => 1000, 'estimated_cost_microusd' => 100 ),
		)
	);
	if ( ! empty( $invalid_submit['success'] ) || 'artifact_link_policy_invalid' !== (string) ( $invalid_submit['code'] ?? '' ) ) {
		throw new RuntimeException( 'Invented localized link was not rejected: ' . wp_json_encode( $invalid_submit ) );
	}
	$route_mismatch_job_before = maybe_serialize( get_option( 'devenia_workflow_translation_job_' . $job_id ) );
	$route_mismatch_run_before = maybe_serialize( get_option( 'devenia_workflow_translation_run_' . $translator_run_id ) );
	$route_mismatch_claim_before = maybe_serialize( get_option( 'devenia_workflow_translation_job_claim_' . $job_id ) );
	$route_mismatch_submit = $call(
		'translation_job_submit_artifact',
		array(
			'job_id' => $job_id,
			'run_id' => $translator_run_id,
			'claim_token' => $translator_token,
			'artifact' => $artifact,
			'usage' => array( 'input_tokens' => 1200, 'cached_input_tokens' => 0, 'output_tokens' => 500, 'attempts' => 1, 'duration_ms' => 1000, 'estimated_cost_microusd' => 100 ),
		)
	);
	if (
		! empty( $route_mismatch_submit['success'] )
		|| 'canonical_route_observed_path_mismatch' !== (string) ( $route_mismatch_submit['code'] ?? '' )
		|| ! empty( $route_mismatch_submit['mutation_started'] )
		|| $route_mismatch_job_before !== maybe_serialize( get_option( 'devenia_workflow_translation_job_' . $job_id ) )
		|| $route_mismatch_run_before !== maybe_serialize( get_option( 'devenia_workflow_translation_run_' . $translator_run_id ) )
		|| $route_mismatch_claim_before !== maybe_serialize( get_option( 'devenia_workflow_translation_job_claim_' . $job_id ) )
		|| $pre_submit_surface_revision !== $call( 'translation_job_current_surface_revision', $translation_id )
		|| metadata_exists( 'post', $translation_id, '_devenia_translation_canonical_route_v1' )
	) {
		throw new RuntimeException( 'Observed/stored route mismatch changed Job, Run, claim, artifact authority, or public state: ' . wp_json_encode( $route_mismatch_submit ) );
	}

	$runtime_page_link = static function ( string $url, int $post_id ) use ( &$runtime_translation_ids_by_language, &$runtime_header_translation_ids_by_language, &$runtime_header_blog_translation_ids_by_language ): string {
		$runtime_language = array_search( $post_id, $runtime_translation_ids_by_language, true );
		if ( false === $runtime_language ) {
			$runtime_language = array_search( $post_id, $runtime_header_translation_ids_by_language, true );
		}
		if ( false === $runtime_language ) {
			$runtime_language = array_search( $post_id, $runtime_header_blog_translation_ids_by_language, true );
		}
		if ( false === $runtime_language ) {
			return $url;
		}
		$post = get_post( $post_id );
		$languages = Devenia_Workflow::languages();
		$prefix = sanitize_key( (string) ( $languages[ $runtime_language ]['prefix'] ?? $runtime_language ) );
		return home_url( '/' . trim( $prefix . '/' . ( $post instanceof WP_Post ? $post->post_name : '' ), '/' ) . '/' );
	};
	add_filter( 'page_link', $runtime_page_link, 10, 2 );
	$runtime_translation_url = (string) get_permalink( $translation_id );
	if ( ! $call( 'sync_translation_index_row', $translation_id ) ) {
		throw new RuntimeException( 'Could not resync the legacy translation index through the runtime route adapter.' );
	}
	$call( 'localized_internal_link_map', $language, true );
	$call( 'localized_link_expected_target_map', $language, true );
	$call( 'localized_link_module', $language, true );
	$legacy_effective_resolution = $call( 'effective_translation_canonical_route', get_post( $translation_id ), $language );
	$legacy_effective_resolution_repeat = $call( 'effective_translation_canonical_route', get_post( $translation_id ), $language );
	$legacy_effective_route = (array) ( $legacy_effective_resolution['route'] ?? array() );
	if (
		empty( $legacy_effective_resolution['success'] )
		|| $legacy_effective_resolution !== $legacy_effective_resolution_repeat
		|| $runtime_localized_path !== (string) ( $legacy_effective_route['path'] ?? '' )
		|| $runtime_translation_url !== (string) ( $legacy_effective_route['url'] ?? '' )
		|| array_key_exists( 'established_at', $legacy_effective_route )
		|| metadata_exists( 'post', $translation_id, '_devenia_translation_canonical_route_v1' )
	) {
		throw new RuntimeException( 'Matching observed and stored routes did not resolve one deterministic legacy contract: ' . wp_json_encode( $legacy_effective_resolution ) );
	}

	$submit = $call(
		'translation_job_submit_artifact',
		array(
			'job_id' => $job_id,
			'run_id' => $translator_run_id,
			'claim_token' => $translator_token,
			'artifact' => $artifact,
			'usage' => array( 'input_tokens' => 1200, 'cached_input_tokens' => 0, 'output_tokens' => 500, 'attempts' => 1, 'duration_ms' => 1000, 'estimated_cost_microusd' => 100 ),
		)
	);
	if ( empty( $submit['success'] ) || 'quality_pending' !== (string) ( $submit['job']['status'] ?? '' ) ) {
		throw new RuntimeException( 'Artifact submission failed: ' . wp_json_encode( $submit ) );
	}
	if (
		$translation_id !== absint( $submit['translation_id'] ?? 0 )
		|| empty( $submit['staged'] )
		|| $pre_submit_surface_revision !== $call( 'translation_job_current_surface_revision', $translation_id )
	) {
		throw new RuntimeException( 'Artifact submission changed WordPress instead of remaining staged: ' . wp_json_encode( $submit ) );
	}
	$artifact_revision = (string) $submit['artifact_revision'];
	$option_keys[] = 'devenia_workflow_translation_artifact_' . $artifact_revision;
	$legacy_staged_record = $call( 'translation_job_unpack_artifact_record', get_option( 'devenia_workflow_translation_artifact_' . $artifact_revision ) );
	$staged_gutenberg = (string) ( $legacy_staged_record['surface_manifest']['content']['gutenberg'] ?? '' );
	$source_gutenberg = (string) get_post_field( 'post_content', $source_id );
	$extract_runtime_query = static function ( string $content ): string {
		$start = strpos( $content, '<!-- wp:generateblocks/query {"uniqueId":"runtime-query"' );
		$closing = '<!-- /wp:generateblocks/query -->';
		$end = false !== $start ? strpos( $content, $closing, $start ) : false;
		return false !== $start && false !== $end ? substr( $content, $start, $end + strlen( $closing ) - $start ) : '';
	};
	$source_query_subtree = $extract_runtime_query( $source_gutenberg );
	$staged_query_subtree = $extract_runtime_query( $staged_gutenberg );
	$loose_dynamic_guardrails = $call( 'link_integrity_guardrails', '<!-- wp:paragraph --><p><a href="{{post_permalink}}">Loose placeholder</a></p><!-- /wp:paragraph -->', $language );
	$loose_dynamic_issues = array_values( array_filter( (array) ( $loose_dynamic_guardrails['issues'] ?? array() ), static function ( $issue ): bool { return is_array( $issue ) && 'unresolved_internal_content_link' === (string) ( $issue['code'] ?? '' ); } ) );
	if (
		$legacy_effective_route !== (array) ( $legacy_staged_record['surface_manifest']['route']['canonical_route'] ?? array() )
		|| '' === $source_query_subtree
		|| $source_query_subtree !== $staged_query_subtree
		|| 1 !== count( $loose_dynamic_issues )
		|| metadata_exists( 'post', $translation_id, '_devenia_translation_canonical_route_v1' )
	) {
		throw new RuntimeException( 'Staging did not preserve the deterministic Canonical Route and exact native dynamic Query subtree, or a loose placeholder escaped validation: ' . wp_json_encode( array( 'route' => $legacy_staged_record['surface_manifest']['route'] ?? array(), 'source_query_hash' => hash( 'sha256', $source_query_subtree ), 'staged_query_hash' => hash( 'sha256', $staged_query_subtree ), 'loose_dynamic_issues' => $loose_dynamic_issues ) ) );
	}
	$legacy_missing_meta_verification = $call(
		'translation_job_verify_applied_surface',
		get_post( $source_id ),
		$translation_id,
		(array) ( $legacy_staged_record['surface_manifest'] ?? array() )
	);
	if ( ! in_array( 'route_canonical', (array) ( $legacy_missing_meta_verification['failed'] ?? array() ), true ) ) {
		throw new RuntimeException( 'Applied verification accepted an effective route that was not actually persisted.' );
	}

	// Dev does not have production language routing or localized menus. Build a
	// bounded presentation fixture so fail-closed publication exercises the real
	// stable-menu identity plus origin/canonical verification paths.
	$runtime_header_source_insert = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Runtime complete-set header source',
			'post_excerpt' => 'Runtime complete-set header source fixture.',
			'post_content' => '<!-- wp:paragraph --><p>Runtime complete-set header source fixture.</p><!-- /wp:paragraph -->',
			'post_name'    => 'runtime-header-source-' . strtolower( wp_generate_password( 6, false, false ) ),
		),
		true
	);
	if ( is_wp_error( $runtime_header_source_insert ) || $runtime_header_source_insert < 1 ) {
		throw new RuntimeException( 'Could not create the isolated complete-set runtime header source.' );
	}
	$runtime_header_source_id = (int) $runtime_header_source_insert;
	update_post_meta( $runtime_header_source_id, '_thumbnail_id', $source_thumbnail_id );
	$runtime_header_blog_source_insert = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Runtime complete-set blog source',
			'post_excerpt' => 'Runtime complete-set blog source fixture.',
			'post_content' => '<!-- wp:paragraph --><p>Runtime complete-set blog source fixture.</p><!-- /wp:paragraph -->',
			'post_name'    => 'runtime-blog-source-' . strtolower( wp_generate_password( 6, false, false ) ),
		),
		true
	);
	if ( is_wp_error( $runtime_header_blog_source_insert ) || $runtime_header_blog_source_insert < 1 ) {
		throw new RuntimeException( 'Could not create the isolated complete-set runtime blog source.' );
	}
	$runtime_header_blog_source_id = (int) $runtime_header_blog_source_insert;
	update_post_meta( $runtime_header_blog_source_id, '_thumbnail_id', $source_thumbnail_id );
	update_option( 'show_on_front', 'page', false );
	update_option( 'page_on_front', $runtime_header_source_id, false );
	update_option( 'page_for_posts', $runtime_header_blog_source_id, false );
	$runtime_source_menu_insert = wp_create_nav_menu( 'Workflow runtime source ' . wp_generate_password( 8, false, false ) );
	if ( is_wp_error( $runtime_source_menu_insert ) ) {
		throw new RuntimeException( 'Could not create the runtime source menu: ' . $runtime_source_menu_insert->get_error_message() );
	}
	$runtime_source_menu_id = (int) $runtime_source_menu_insert;
	$runtime_source_menu_parent_id = wp_update_nav_menu_item(
		(int) $runtime_source_menu_id,
		0,
		array(
			'menu-item-title'     => 'Runtime group',
			'menu-item-url'       => home_url( '/' ),
			'menu-item-type'      => 'custom',
			'menu-item-status'    => 'publish',
		)
	);
	$runtime_source_menu_item_id = wp_update_nav_menu_item(
		(int) $runtime_source_menu_id,
		0,
		array(
			'menu-item-title'     => 'Runtime source',
			'menu-item-object'    => 'page',
			'menu-item-object-id' => $runtime_header_source_id,
			'menu-item-type'      => 'post_type',
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => is_wp_error( $runtime_source_menu_parent_id ) ? 0 : (int) $runtime_source_menu_parent_id,
		)
	);
	if ( is_wp_error( $runtime_source_menu_parent_id ) || is_wp_error( $runtime_source_menu_item_id ) ) {
		$runtime_menu_error = is_wp_error( $runtime_source_menu_parent_id ) ? $runtime_source_menu_parent_id : $runtime_source_menu_item_id;
		throw new RuntimeException( 'Could not populate the runtime source menu: ' . $runtime_menu_error->get_error_message() );
	}
	$runtime_locations = is_array( $nav_menu_locations_before ) ? $nav_menu_locations_before : array();
	$runtime_locations['primary'] = (int) $runtime_source_menu_id;
	set_theme_mod( 'nav_menu_locations', $runtime_locations );
	$runtime_languages = Devenia_Workflow::languages( true );
	$runtime_source_language_code = $call( 'source_language_code' );
	if ( '' === $runtime_source_language_code || empty( $runtime_languages[ $runtime_source_language_code ] ) || empty( $runtime_languages[ $language ] ) ) {
		throw new RuntimeException( 'The runtime header fixture requires one configured source and target language.' );
	}
	$runtime_header_token = wp_generate_password( 8, false, false );
	foreach ( $runtime_languages as $runtime_header_language => &$runtime_header_config ) {
		$runtime_header_config['menu_name'] = $runtime_source_language_code === $runtime_header_language
			? (string) wp_get_nav_menu_object( $runtime_source_menu_id )->name
			: 'Workflow runtime target ' . $runtime_header_language . ' ' . $runtime_header_token;
	}
	unset( $runtime_header_config );
	update_option( 'devenia_workflow_language_registry', $runtime_languages, false );
	Devenia_Workflow::languages( true );
	foreach ( array_keys( $call( 'target_languages' ) ) as $runtime_header_language ) {
		$runtime_header_translation_slug = 'runtime-header-' . $runtime_header_language . '-' . strtolower( wp_generate_password( 6, false, false ) );
		$runtime_header_translation_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Runtime header translation ' . $runtime_header_language,
				'post_excerpt' => 'Runtime complete-set header fixture.',
				'post_content' => '<!-- wp:paragraph --><p>Runtime complete-set header fixture.</p><!-- /wp:paragraph -->',
				'post_name'    => $runtime_header_translation_slug,
			),
			true
		);
		if ( is_wp_error( $runtime_header_translation_id ) || $runtime_header_translation_id < 1 ) {
			throw new RuntimeException( 'Could not create the complete-set runtime header translation for ' . $runtime_header_language . '.' );
		}
		$runtime_header_translation_ids_by_language[ $runtime_header_language ] = (int) $runtime_header_translation_id;
		$runtime_header_prefix = sanitize_key( (string) ( $runtime_languages[ $runtime_header_language ]['prefix'] ?? $runtime_header_language ) );
		update_post_meta( $runtime_header_translation_id, '_devenia_translation_source_id', $runtime_header_source_id );
		update_post_meta( $runtime_header_translation_id, '_devenia_translation_language', $runtime_header_language );
		update_post_meta( $runtime_header_translation_id, '_devenia_translation_status', 'published' );
		update_post_meta( $runtime_header_translation_id, '_devenia_translation_localized_path', trim( $runtime_header_prefix . '/' . $runtime_header_translation_slug, '/' ) );
		update_post_meta( $runtime_header_translation_id, '_thumbnail_id', $source_thumbnail_id );
		clean_post_cache( $runtime_header_translation_id );
		if ( ! $call( 'sync_translation_index_row', $runtime_header_translation_id ) ) {
			throw new RuntimeException( 'Could not index the complete-set runtime header translation for ' . $runtime_header_language . '.' );
		}
		$runtime_header_blog_translation_slug = 'runtime-blog-' . $runtime_header_language . '-' . strtolower( wp_generate_password( 6, false, false ) );
		$runtime_header_blog_translation_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Runtime blog translation ' . $runtime_header_language,
				'post_excerpt' => 'Runtime complete-set blog fixture.',
				'post_content' => '<!-- wp:paragraph --><p>Runtime complete-set blog fixture.</p><!-- /wp:paragraph -->',
				'post_name'    => $runtime_header_blog_translation_slug,
			),
			true
		);
		if ( is_wp_error( $runtime_header_blog_translation_id ) || $runtime_header_blog_translation_id < 1 ) {
			throw new RuntimeException( 'Could not create the complete-set runtime blog translation for ' . $runtime_header_language . '.' );
		}
		$runtime_header_blog_translation_ids_by_language[ $runtime_header_language ] = (int) $runtime_header_blog_translation_id;
		update_post_meta( $runtime_header_blog_translation_id, '_devenia_translation_source_id', $runtime_header_blog_source_id );
		update_post_meta( $runtime_header_blog_translation_id, '_devenia_translation_language', $runtime_header_language );
		update_post_meta( $runtime_header_blog_translation_id, '_devenia_translation_status', 'published' );
		update_post_meta( $runtime_header_blog_translation_id, '_devenia_translation_localized_path', trim( $runtime_header_prefix . '/' . $runtime_header_blog_translation_slug, '/' ) );
		update_post_meta( $runtime_header_blog_translation_id, '_thumbnail_id', $source_thumbnail_id );
		clean_post_cache( $runtime_header_blog_translation_id );
		if ( ! $call( 'sync_translation_index_row', $runtime_header_blog_translation_id ) ) {
			throw new RuntimeException( 'Could not index the complete-set runtime blog translation for ' . $runtime_header_language . '.' );
		}
	}
	update_option( 'devenia_workflow_localized_menu_identities', array(), false );
	delete_option( 'devenia_workflow_public_header_enrollment' );
	$source_language_code = $call( 'source_language_code' );
	$runtime_source_labels = array();
	foreach ( array_keys( Devenia_Workflow::languages() ) as $runtime_header_language ) {
		$runtime_source_labels[ $runtime_header_language ] = $source_language_code === $runtime_header_language ? 'Runtime source' : 'Runtime source ' . $runtime_header_language;
	}
	$runtime_manifest = $call(
		'update_public_header_manifest',
		array(
			'items' => array(
				array( 'source_item_id' => (int) $runtime_source_menu_item_id, 'type' => 'page', 'title' => 'Runtime source', 'labels' => $runtime_source_labels, 'object_id' => $runtime_header_source_id, 'parent_source_item_id' => 0, 'position' => 1 ),
			),
		)
	);
	if ( empty( $runtime_manifest['success'] ) || empty( $runtime_manifest['activation_receipt'] ) ) {
		throw new RuntimeException( 'Could not register the runtime Public Header Projection manifest: ' . wp_json_encode( $runtime_manifest ) );
	}
	$runtime_pending_manifest = get_option( 'devenia_workflow_pending_public_header_manifest', array() );
	update_option( 'devenia_workflow_public_header_manifest', $runtime_pending_manifest, false );

	$html_lang_method = new ReflectionMethod( Devenia_Workflow::class, 'html_lang_for_language' );
	$html_lang_method->setAccessible( true );
	$runtime_html_lang = (string) $html_lang_method->invoke( null, $language );
	$hreflang_method = new ReflectionMethod( Devenia_Workflow::class, 'hreflang_for_language' );
	$hreflang_method->setAccessible( true );
	$runtime_source_url = (string) get_permalink( $source_id );
	$runtime_header_surfaces = array();
	$runtime_header_surface_profiles = array();
	foreach ( array_keys( $runtime_languages ) as $runtime_header_language ) {
		$runtime_header_home_surface_id = $source_language_code === $runtime_header_language
			? $runtime_header_source_id
			: absint( $runtime_header_translation_ids_by_language[ $runtime_header_language ] ?? 0 );
		$runtime_header_blog_surface_id = $source_language_code === $runtime_header_language
			? $runtime_header_blog_source_id
			: absint( $runtime_header_blog_translation_ids_by_language[ $runtime_header_language ] ?? 0 );
		if ( $runtime_header_home_surface_id < 1 || $runtime_header_blog_surface_id < 1 ) {
			throw new RuntimeException( 'The complete-set runtime header surface is missing for ' . $runtime_header_language . '.' );
		}
		$runtime_header_surface_profile = array(
			'language'  => $runtime_header_language,
			'html_lang' => (string) $html_lang_method->invoke( null, $runtime_header_language ),
			'hreflang'  => (string) $hreflang_method->invoke( null, $runtime_header_language ),
		);
		$runtime_header_surface_profiles[ $runtime_header_language ] = $runtime_header_surface_profile;
		foreach (
			array(
				array( 'url' => $call( 'localized_home_url_for_language', $runtime_header_language ), 'surface_id' => $runtime_header_home_surface_id ),
				array( 'url' => $call( 'public_blog_archive_url_for_language', $runtime_header_language ), 'surface_id' => $runtime_header_blog_surface_id ),
			) as $runtime_header_surface
		) {
			$runtime_header_url = (string) ( $runtime_header_surface['url'] ?? '' );
			if ( '' === $runtime_header_url ) {
				throw new RuntimeException( 'The complete-set runtime header URL is missing for ' . $runtime_header_language . '.' );
			}
			$runtime_header_surfaces[ untrailingslashit( $runtime_header_url ) ] = array_merge( $runtime_header_surface_profile, $runtime_header_surface );
		}
	}
	$runtime_http_surface = static function ( $preempt, array $args, string $url ) use ( &$translation_id, $source_id, $language, $source_language_code, $runtime_source_url, &$runtime_translation_url, $runtime_html_lang, $runtime_header_surfaces, $runtime_header_surface_profiles ) {
		$request_url = untrailingslashit( strtok( $url, '?' ) ?: '' );
		if ( $translation_id > 0 ) { $runtime_translation_url = (string) get_permalink( $translation_id ); }
		$is_translation = $translation_id > 0 && $request_url === untrailingslashit( $runtime_translation_url );
		$is_source = $request_url === untrailingslashit( $runtime_source_url );
		$surface = $runtime_header_surfaces[ $request_url ] ?? null;
		if ( $is_translation ) {
			$surface = array_merge( (array) ( $runtime_header_surface_profiles[ $language ] ?? array() ), array( 'url' => $runtime_translation_url, 'language' => $language, 'html_lang' => $runtime_html_lang, 'surface_id' => $translation_id ) );
		} elseif ( $is_source ) {
			$surface = array_merge( (array) ( $runtime_header_surface_profiles[ $source_language_code ] ?? array() ), array( 'url' => $runtime_source_url, 'language' => $source_language_code, 'surface_id' => $source_id ) );
		}
		if ( ! is_array( $surface ) ) {
			return $preempt;
		}
		$surface_url = (string) ( $surface['url'] ?? '' );
		$surface_language_code = sanitize_key( (string) ( $surface['language'] ?? '' ) );
		$surface_language = (string) ( $surface['html_lang'] ?? '' );
		$surface_hreflang = (string) ( $surface['hreflang'] ?? '' );
		$surface_id = absint( $surface['surface_id'] ?? 0 );
		$identities = get_option( 'devenia_workflow_localized_menu_identities', array() );
		$menu_id = absint( $identities[ $surface_language_code ]['menu_id'] ?? 0 );
		$navigation = (string) wp_nav_menu( array( 'menu' => $menu_id, 'container' => false, 'echo' => false, 'fallback_cb' => false, 'items_wrap' => '%3$s' ) );
		$thumbnail_id = absint( get_post_thumbnail_id( $surface_id ) );
		$thumbnail_url = (string) wp_get_attachment_image_url( $thumbnail_id, 'full' );
		$thumbnail_srcset = (string) wp_get_attachment_image_srcset( $thumbnail_id, 'full' );
		$media_head = '' !== $thumbnail_url ? '<meta property="og:image" content="' . esc_url( $thumbnail_url ) . '">' : '';
		$media_body = '' !== $thumbnail_url ? '<img class="wp-post-image" src="' . esc_url( $thumbnail_url ) . '"' . ( '' !== $thumbnail_srcset ? ' srcset="' . esc_attr( $thumbnail_srcset ) . '"' : '' ) . '>' : '';
		$body = '<!doctype html><html lang="' . esc_attr( $surface_language ) . '"><head><link rel="alternate" hreflang="' . esc_attr( $surface_hreflang ) . '" href="' . esc_url( $surface_url ) . '">' . $media_head . '</head><body><nav id="site-navigation"><ul class="menu">' . $navigation . '</ul></nav><main><h1>' . esc_html( (string) get_the_title( $surface_id ) ) . '</h1>' . $media_body . '</main></body></html>';
		return array(
			'headers'  => array( 'cf-cache-status' => false === strpos( $url, 'devenia_frontend_integrity=' ) ? 'HIT' : 'DYNAMIC', 'age' => '0' ),
			'body'     => $body,
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $runtime_http_surface, 10, 3 );
	$runtime_batch_http = static function ( $default, array $requests ) use ( $runtime_http_surface ): array {
		$responses = array();
		foreach ( $requests as $key => $request ) {
			$response = $runtime_http_surface( false, array(), (string) ( $request['url'] ?? '' ) );
			$responses[ $key ] = false === $response ? new WP_Error( 'unknown_translation_job_header_fixture_url', 'The Translation Job header fixture received an unknown frontend URL.' ) : $response;
		}
		return $responses;
	};
	add_filter( 'devenia_workflow_frontend_cache_batch_adapter_result', $runtime_batch_http, 10, 3 );

	// Establish the same complete managed reader surface production requires.
	// One-language menu staging is not activation authority.
	$runtime_header_activation = $call(
		'sync_public_header_projection',
		array(
			'timeout'            => 3,
			'activation_receipt' => (string) $runtime_manifest['activation_receipt'],
		)
	);
	$runtime_header_identities = get_option( 'devenia_workflow_localized_menu_identities', array() );
	// Record every fixture-owned projection before interpreting the result. A
	// real activation followed by a verification RED must still leave finally
	// with complete deletion authority for every generated managed menu.
	foreach ( (array) ( $runtime_header_activation['projections'] ?? array() ) as $runtime_header_projection ) {
		$runtime_menu_ids[] = absint( $runtime_header_projection['target_menu']['id'] ?? 0 );
	}
	foreach ( (array) $runtime_header_identities as $runtime_header_identity ) {
		$runtime_menu_ids[] = absint( $runtime_header_identity['menu_id'] ?? 0 );
	}
	$runtime_seed_menu_id = absint( $runtime_header_identities[ $language ]['menu_id'] ?? 0 );
	if (
		empty( $runtime_header_activation['success'] )
		|| $runtime_seed_menu_id < 1
		|| count( (array) $runtime_header_identities ) !== count( (array) $runtime_languages )
		|| empty( $runtime_header_activation['verification']['passed'] )
	) {
		throw new RuntimeException( 'Could not activate the complete runtime Public Header Projection: ' . wp_json_encode( $runtime_header_activation ) );
	}
	// A failure after the owner has backfilled the route must restore the exact
	// legacy state, including absence of the route meta. This proves the
	// deterministic backfill participates in the normal publication rollback.
	$legacy_job_before_quality = get_option( 'devenia_workflow_translation_job_' . $job_id );
	$legacy_rollback_snapshot = $call(
		'translation_job_capture_surface_snapshot',
		$translation_id,
		(array) ( $legacy_staged_record['surface_manifest'] ?? array() ),
		$call( 'translation_job_publication_identity_scope', (array) $legacy_job_before_quality )
	);
	if ( empty( $legacy_rollback_snapshot['snapshot_valid'] ) ) {
		throw new RuntimeException( 'Could not capture the legacy canonical rollback fixture: ' . wp_json_encode( $legacy_rollback_snapshot ) );
	}
	$legacy_rollback_snapshot['mutation_started'] = true;
	$legacy_backfilled_route = $call( 'store_translation_canonical_route', get_post( $translation_id ), $language );
	$legacy_established_resolution = $call( 'effective_translation_canonical_route', get_post( $translation_id ), $language );
	$legacy_established_route = (array) ( $legacy_established_resolution['route'] ?? array() );
	if ( empty( $legacy_established_resolution['success'] ) || $legacy_effective_route !== $legacy_backfilled_route || $legacy_backfilled_route !== $legacy_established_route ) {
		throw new RuntimeException( 'A newly established legacy route was not preserved exactly by the shared resolver.' );
	}
	$legacy_rollback_snapshot['rollback_expected_surface_revision'] = $call(
		'translation_job_rollback_cas_revision',
		$translation_id,
		(array) ( $legacy_rollback_snapshot['term_scope'] ?? array() ),
		(array) ( $legacy_rollback_snapshot['identity_scope'] ?? array() )
	);
	$legacy_rollback_result = $call(
		'translation_job_publish_failure_with_rollback',
		array( 'success' => false, 'code' => 'runtime_legacy_canonical_backfill_rollback', 'purge_urls' => array( $runtime_translation_url ) ),
		$legacy_rollback_snapshot,
		$translation_id
	);
	if (
		empty( $legacy_rollback_result['rollback']['success'] )
		|| metadata_exists( 'post', $translation_id, '_devenia_translation_canonical_route_v1' )
		|| $runtime_localized_slug !== (string) get_post_field( 'post_name', $translation_id )
		|| $runtime_translation_url !== (string) get_permalink( $translation_id )
	) {
		throw new RuntimeException( 'Legacy canonical backfill rollback did not restore exact absent meta and route identity: ' . wp_json_encode( $legacy_rollback_result ) );
	}

	$expired_quality_claim = $call(
		'translation_job_claim',
		array(
			'job_id' => $job_id,
			'run_id' => 'runtime-quality-expiring-' . wp_generate_password( 8, false, false ),
			'coordinator_id' => 'runtime-coordinator',
			'role' => 'quality',
			'ttl_seconds' => 600,
		)
	);
	if ( empty( $expired_quality_claim['success'] ) ) {
		throw new RuntimeException( 'Expiring Quality fixture claim failed: ' . wp_json_encode( $expired_quality_claim ) );
	}
	$expired_quality_run_id = (string) $expired_quality_claim['run']['run_id'];
	$option_keys[] = 'devenia_workflow_translation_run_' . $expired_quality_run_id;
	$expired_quality_lock_key = 'devenia_workflow_translation_job_claim_' . $job_id;
	$expired_quality_lock = get_option( $expired_quality_lock_key );
	$expired_quality_lock['expires_at'] = gmdate( 'c', time() - 1 );
	update_option( $expired_quality_lock_key, $expired_quality_lock, false );
	$replacement_quality_claim = $call(
		'translation_job_claim',
		array(
			'job_id' => $job_id,
			'run_id' => 'runtime-quality-replacement-' . wp_generate_password( 8, false, false ),
			'coordinator_id' => 'runtime-coordinator',
			'role' => 'quality',
			'ttl_seconds' => 600,
		)
	);
	$replacement_quality_run_id = (string) ( $replacement_quality_claim['run']['run_id'] ?? '' );
	$option_keys[] = 'devenia_workflow_translation_run_' . $replacement_quality_run_id;
	$expired_quality_run = get_option( 'devenia_workflow_translation_run_' . $expired_quality_run_id );
	if (
		empty( $replacement_quality_claim['success'] )
		|| 'completed' !== (string) ( $expired_quality_run['status'] ?? '' )
		|| 'expired' !== (string) ( $expired_quality_run['outcome'] ?? '' )
	) {
		throw new RuntimeException( 'Expired Quality Run did not permit a replacement claim: ' . wp_json_encode( array( 'replacement' => $replacement_quality_claim, 'expired_run' => $expired_quality_run ) ) );
	}
	$replacement_quality_abandoned = $call(
		'translation_job_abandon',
		array(
			'job_id' => $job_id,
			'run_id' => $replacement_quality_run_id,
			'claim_token' => (string) ( $replacement_quality_claim['claim_token'] ?? '' ),
			'reason' => 'Runtime contract abandons the replacement Quality fixture before the real decision.',
		)
	);
	if ( empty( $replacement_quality_abandoned['success'] ) || 'quality_pending' !== (string) ( $replacement_quality_abandoned['job']['status'] ?? '' ) ) {
		throw new RuntimeException( 'Abandoned replacement Quality Run did not restore quality_pending: ' . wp_json_encode( $replacement_quality_abandoned ) );
	}

	$quality_claim = $call(
		'translation_job_claim',
		array(
			'job_id' => $job_id,
			'run_id' => 'runtime-quality-' . wp_generate_password( 8, false, false ),
			'coordinator_id' => 'runtime-coordinator',
			'role' => 'quality',
			'ttl_seconds' => 600,
		)
	);
	if ( empty( $quality_claim['success'] ) ) {
		throw new RuntimeException( 'Quality claim failed: ' . wp_json_encode( $quality_claim ) );
	}
	$quality_run_id = (string) $quality_claim['run']['run_id'];
	$quality_token = (string) $quality_claim['claim_token'];
	$quality_run_key = 'devenia_workflow_translation_run_' . $quality_run_id;
	$option_keys[] = $quality_run_key;
	$quality_packet = $call( 'translation_job_fetch_packet', array( 'job_id' => $job_id, 'run_id' => $quality_run_id, 'claim_token' => $quality_token ) );
	$quality_run_after_first_packet = maybe_serialize( get_option( $quality_run_key ) );
	$quality_packet_repeat = $call( 'translation_job_fetch_packet', array( 'job_id' => $job_id, 'run_id' => $quality_run_id, 'claim_token' => $quality_token ) );
	if (
		empty( $quality_packet_repeat['success'] )
		|| (string) ( $quality_packet['packet']['packet_revision'] ?? '' ) !== (string) ( $quality_packet_repeat['packet']['packet_revision'] ?? '' )
		|| $quality_run_after_first_packet !== maybe_serialize( get_option( $quality_run_key ) )
	) {
		throw new RuntimeException( 'Repeated same-second packet fetch did not pass the exact idempotent Run CAS: ' . wp_json_encode( $quality_packet_repeat ) );
	}
	$quality_contact_actions = $quality_packet['packet']['contact_actions'] ?? array();
	if (
		empty( $quality_packet['success'] )
		|| $links !== ( $quality_packet['packet']['links'] ?? array() )
		|| 'devenia-workflow/translation-job-submit-quality-decision' !== (string) ( $quality_packet['packet']['submission_contract']['ability'] ?? '' )
		|| 'array' !== (string) ( $quality_packet['packet']['submission_contract']['input_schema']['properties']['corrections']['type'] ?? '' )
		|| 'string' !== (string) ( $quality_packet['packet']['submission_contract']['input_schema']['properties']['corrections']['items']['type'] ?? '' )
		|| 'Source question' !== (string) ( $quality_contact_actions['source'][0]['subject'] ?? '' )
		|| 'Spørsmål om testen' !== (string) ( $quality_contact_actions['translation'][0]['subject'] ?? '' )
	) {
		throw new RuntimeException( 'Quality packet did not preserve the authoritative link policy: ' . wp_json_encode( $quality_packet ) );
	}
	$quality_evidence = 'Runtime contract reviewed every required dimension and requests a deliberate revision.';
	$quality_corrections = array( 'Runtime fixture intentionally stops before publication.' );
	$quality = $call(
		'translation_job_submit_quality_decision',
		$quality_payload( $quality_claim, $artifact_revision, 'revise', $quality_evidence, $quality_corrections )
	);
	$track_quality_result( $quality );
	if ( empty( $quality['success'] ) || 'changes_requested' !== (string) ( $quality['job']['status'] ?? '' ) ) {
		throw new RuntimeException( 'Idempotent orphaned Quality Decision recovery failed: ' . wp_json_encode( $quality ) );
	}
	$correction_claim = $call(
		'translation_job_claim',
		array(
			'job_id' => $job_id,
			'run_id' => 'runtime-correction-' . wp_generate_password( 8, false, false ),
			'coordinator_id' => 'runtime-coordinator',
			'role' => 'translator',
			'ttl_seconds' => 600,
		)
	);
	if ( empty( $correction_claim['success'] ) ) {
		throw new RuntimeException( 'Correction claim failed: ' . wp_json_encode( $correction_claim ) );
	}
	$correction_run_id = (string) $correction_claim['run']['run_id'];
	$option_keys[] = 'devenia_workflow_translation_run_' . $correction_run_id;
	$correction_packet = $call(
		'translation_job_fetch_packet',
		array(
			'job_id' => $job_id,
			'run_id' => $correction_run_id,
			'claim_token' => (string) $correction_claim['claim_token'],
		)
	);
	$correction_context = $correction_packet['packet']['correction_context'] ?? array();
	if (
		empty( $correction_packet['success'] )
		|| $artifact_revision !== (string) ( $correction_context['previous_artifact_revision'] ?? '' )
		|| empty( $correction_context['previous_artifact']['artifact'] )
		|| empty( $correction_context['corrections'] )
	) {
		throw new RuntimeException( 'Correction context missing: ' . wp_json_encode( $correction_packet ) );
	}
	$second_artifact = $call(
		'translation_job_submit_artifact',
		array(
			'job_id' => $job_id,
			'run_id' => $correction_run_id,
			'claim_token' => (string) $correction_claim['claim_token'],
			'artifact' => $artifact,
			'usage' => array( 'input_tokens' => 700, 'cached_input_tokens' => 0, 'output_tokens' => 200, 'attempts' => 1, 'duration_ms' => 700, 'estimated_cost_microusd' => 50 ),
		)
	);
	if ( empty( $second_artifact['success'] ) || 'quality_pending' !== (string) ( $second_artifact['job']['status'] ?? '' ) ) {
		throw new RuntimeException( 'Second artifact submission failed: ' . wp_json_encode( $second_artifact ) );
	}
	$second_quality_claim = $call(
		'translation_job_claim',
		array(
			'job_id' => $job_id,
			'run_id' => 'runtime-quality-second-' . wp_generate_password( 8, false, false ),
			'coordinator_id' => 'runtime-coordinator',
			'role' => 'quality',
			'ttl_seconds' => 600,
		)
	);
	if ( empty( $second_quality_claim['success'] ) ) {
		throw new RuntimeException( 'Second quality claim failed: ' . wp_json_encode( $second_quality_claim ) );
	}
	$second_quality_run_id = (string) $second_quality_claim['run']['run_id'];
	$option_keys[] = 'devenia_workflow_translation_run_' . $second_quality_run_id;
	$second_quality = $call(
		'translation_job_submit_quality_decision',
		$quality_payload( $second_quality_claim, (string) $second_artifact['artifact_revision'], 'revise', 'The second bounded quality Run found one final wording correction that must remain actionable.', array( 'Apply the final bounded wording correction.' ) )
	);
	$track_quality_result( $second_quality );
	if ( empty( $second_quality['success'] ) || 'changes_requested' !== (string) ( $second_quality['job']['status'] ?? '' ) ) {
		throw new RuntimeException( 'Second Quality Decision failed: ' . wp_json_encode( $second_quality ) );
	}
	$third_correction_claim = $call(
		'translation_job_claim',
		array(
			'job_id' => $job_id,
			'run_id' => 'runtime-correction-third-' . wp_generate_password( 8, false, false ),
			'coordinator_id' => 'runtime-coordinator',
			'role' => 'translator',
			'ttl_seconds' => 600,
		)
	);
	if ( empty( $third_correction_claim['success'] ) ) {
		throw new RuntimeException( 'Third translator Run was not available after a valid second Quality Decision: ' . wp_json_encode( $third_correction_claim ) );
	}
	$third_correction_run_id = (string) $third_correction_claim['run']['run_id'];
	$option_keys[] = 'devenia_workflow_translation_run_' . $third_correction_run_id;
	$third_correction_packet = $call(
		'translation_job_fetch_packet',
		array(
			'job_id' => $job_id,
			'run_id' => $third_correction_run_id,
			'claim_token' => (string) $third_correction_claim['claim_token'],
		)
	);
	if (
		empty( $third_correction_packet['success'] )
		|| (string) $second_artifact['artifact_revision'] !== (string) ( $third_correction_packet['packet']['correction_context']['previous_artifact_revision'] ?? '' )
		|| empty( $third_correction_packet['packet']['correction_context']['previous_artifact']['artifact'] )
		|| empty( $third_correction_packet['packet']['correction_context']['corrections'] )
	) {
		throw new RuntimeException( 'Third correction packet missing: ' . wp_json_encode( $third_correction_packet ) );
	}
	$third_artifact = $call(
		'translation_job_submit_artifact',
		array(
			'job_id' => $job_id,
			'run_id' => $third_correction_run_id,
			'claim_token' => (string) $third_correction_claim['claim_token'],
			'artifact' => $artifact,
			'usage' => array( 'input_tokens' => 700, 'cached_input_tokens' => 0, 'output_tokens' => 200, 'attempts' => 1, 'duration_ms' => 700, 'estimated_cost_microusd' => 50 ),
		)
	);
	if ( empty( $third_artifact['success'] ) || 'quality_pending' !== (string) ( $third_artifact['job']['status'] ?? '' ) ) {
		throw new RuntimeException( 'Third artifact submission failed: ' . wp_json_encode( $third_artifact ) );
	}
	$third_quality_claim = $call(
		'translation_job_claim',
		array(
			'job_id' => $job_id,
			'run_id' => 'runtime-quality-third-' . wp_generate_password( 8, false, false ),
			'coordinator_id' => 'runtime-coordinator',
			'role' => 'quality',
			'ttl_seconds' => 600,
		)
	);
	if ( empty( $third_quality_claim['success'] ) ) {
		throw new RuntimeException( 'Third quality Run was not available after the final correction: ' . wp_json_encode( $third_quality_claim ) );
	}
	$option_keys[] = 'devenia_workflow_translation_run_' . (string) $third_quality_claim['run']['run_id'];
	$third_quality = $call(
		'translation_job_submit_quality_decision',
		$quality_payload( $third_quality_claim, (string) $third_artifact['artifact_revision'], 'pass', 'Runtime contract confirms the final bounded artifact passes every required quality dimension before publication.', array() )
	);
	$track_quality_result( $third_quality );
	if ( empty( $third_quality['success'] ) || 'ready_to_publish' !== (string) ( $third_quality['job']['status'] ?? '' ) ) {
		throw new RuntimeException( 'Final Quality Decision failed: ' . wp_json_encode( $third_quality ) );
	}

	// Ordinary Translation Job publication must mint fresh all-language relation
	// receipts before it writes pending header state. Let pre-publication reader
	// verification observe the intact active relation, then remove one exact
	// read-model row only after the real content COMMIT and before manifest
	// staging. This models concurrent Index loss without making the stronger
	// frontend-integrity gate pre-empt the publication-boundary oracle.
	$wrong_index_job_key = 'devenia_workflow_translation_job_' . $job_id;
	$wrong_index_artifact_key = 'devenia_workflow_translation_artifact_' . (string) $third_artifact['artifact_revision'];
	$wrong_index_quality_revision = (string) ( $third_quality['quality_decision']['quality_revision'] ?? '' );
	$wrong_index_quality_key = 'devenia_workflow_translation_quality_' . $wrong_index_quality_revision;
	$wrong_index_quality_record = get_option( $wrong_index_quality_key, array() );
	$wrong_index_evidence_key = 'devenia_tj_quality_evidence_' . (string) ( $wrong_index_quality_record['evidence_revision'] ?? '' );
	$wrong_index_header_options = array( 'devenia_workflow_public_header_manifest', 'devenia_workflow_pending_public_header_manifest', 'devenia_workflow_localized_menu_identities', 'devenia_workflow_public_header_enrollment' );
	$wrong_index_inventory_options = array( 'devenia_workflow_source_inventory_schema', 'devenia_workflow_source_inventory_active', 'devenia_workflow_source_inventory_dirty' );
	$wrong_index_menu_ids = array( $runtime_source_menu_id );
	foreach ( (array) get_option( 'devenia_workflow_localized_menu_identities', array() ) as $wrong_index_identity ) { $wrong_index_menu_ids[] = absint( is_array( $wrong_index_identity ) ? ( $wrong_index_identity['menu_id'] ?? 0 ) : 0 ); }
	$wrong_index_authority_options = $raw_option_records( array( $wrong_index_job_key, $wrong_index_artifact_key, $wrong_index_quality_key, $wrong_index_evidence_key ) );
	$wrong_index_content_before = $raw_translation_content_surface( $translation_id );
	$wrong_index_header_before = $raw_option_records( $wrong_index_header_options );
	$wrong_index_menu_before = $raw_nav_menu_surface( $wrong_index_menu_ids );
	$wrong_index_menu_inventory_before = $raw_nav_menu_inventory();
	$wrong_index_inventory_before = $raw_option_records( $wrong_index_inventory_options );
	$wrong_index_target_language = (string) array_key_first( $call( 'target_languages' ) );
	$wrong_index_target_id = absint( $runtime_header_translation_ids_by_language[ $wrong_index_target_language ] ?? 0 );
	$wrong_index_target_post = get_post( $wrong_index_target_id );
	$wrong_index_table = (string) $call( 'translation_index_table' );
	global $wpdb;
	$wrong_index_rows_before = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE translation_post_id = %d ORDER BY source_post_id ASC, language ASC, translation_post_id ASC', $wrong_index_table, $wrong_index_target_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact fixture row is restored byte-for-byte below.
	if ( ! $wrong_index_target_post instanceof WP_Post || '' === $wrong_index_target_language || 1 !== count( (array) $wrong_index_rows_before ) ) { throw new RuntimeException( 'Could not establish the exact ordinary-publication wrong-index fixture.' ); }
	$wrong_index_publish = array(); $wrong_index_restore_success = true; $wrong_index_injected = false; $wrong_index_commit_calls = 0; $wrong_index_commit_receipt = array(); $wrong_index_rows_missing = array();
	$wrong_index_commit_adapter = static function ( $default, int $committed_translation_id, string $before_revision, string $replacement_revision ) use ( $call, $translation_id, $wrong_index_target_id, $wrong_index_target_post, $wrong_index_table, $wpdb, &$wrong_index_injected, &$wrong_index_commit_calls, &$wrong_index_commit_receipt, &$wrong_index_rows_missing ) {
		unset( $default );
		++$wrong_index_commit_calls;
		if ( 1 !== $wrong_index_commit_calls ) { throw new RuntimeException( 'Wrong-index COMMIT Adapter was invoked more than once.' ); }
		if ( $translation_id !== $committed_translation_id || '' === $before_revision || '' === $replacement_revision || hash_equals( $before_revision, $replacement_revision ) ) { throw new RuntimeException( 'Wrong-index COMMIT Adapter received an invalid publication ownership receipt.' ); }
		$actual = $call( 'translation_job_commit_recovery_transaction' );
		if ( empty( $actual['success'] ) || ! array_key_exists( 'committed', $actual ) || true !== $actual['committed'] ) { throw new RuntimeException( 'Wrong-index fixture could not establish the real content COMMIT.' ); }
		$call( 'delete_translation_index_for_post', $wrong_index_target_id, $wrong_index_target_post );
		$wrong_index_rows_missing = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE translation_post_id = %d', $wrong_index_table, $wrong_index_target_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact post-COMMIT proof before ordinary publication stages fresh relation receipts.
		if ( ! empty( $wrong_index_rows_missing ) ) { throw new RuntimeException( 'Could not remove the exact post-COMMIT Translation Index row.' ); }
		$wrong_index_commit_receipt = $actual;
		$wrong_index_injected = true;
		return $actual;
	};
	add_filter( 'devenia_workflow_localized_presentation_commit_adapter_result', $wrong_index_commit_adapter, 10, 4 );
	try {
		$wrong_index_publish = $call( 'translation_job_publish', array( 'job_id' => $job_id, 'coordinator_id' => 'runtime-coordinator', 'sync_menu' => true, 'verify_live' => true ) );
	} finally {
		remove_filter( 'devenia_workflow_localized_presentation_commit_adapter_result', $wrong_index_commit_adapter, 10 );
		$wpdb->delete( $wrong_index_table, array( 'translation_post_id' => $wrong_index_target_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deterministic fixture cleanup before exact row restoration.
		foreach ( (array) $wrong_index_rows_before as $wrong_index_row ) {
			if ( false === $wpdb->insert( $wrong_index_table, $wrong_index_row ) ) { $wrong_index_restore_success = false; } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore every original persisted column exactly, including original timestamps.
		}
	}
	$wrong_index_rows_after = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE translation_post_id = %d ORDER BY source_post_id ASC, language ASC, translation_post_id ASC', $wrong_index_table, $wrong_index_target_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact cleanup oracle.
	$wrong_index_content_after = $raw_translation_content_surface( $translation_id );
	$wrong_index_meta_without_ids = static function ( array $rows ): array {
		$normalized = array();
		foreach ( $rows as $row ) { unset( $row['meta_id'] ); $normalized[] = $row; }
		usort( $normalized, static function ( array $left, array $right ): int { return strcmp( maybe_serialize( $left ), maybe_serialize( $right ) ); } );
		return $normalized;
	};
	$wrong_index_content_authority_exact = (array) ( $wrong_index_content_before['post'] ?? array() ) === (array) ( $wrong_index_content_after['post'] ?? array() )
		&& $wrong_index_meta_without_ids( (array) ( $wrong_index_content_before['postmeta'] ?? array() ) ) === $wrong_index_meta_without_ids( (array) ( $wrong_index_content_after['postmeta'] ?? array() ) )
		&& (array) ( $wrong_index_content_before['terms'] ?? array() ) === (array) ( $wrong_index_content_after['terms'] ?? array() );
	$wrong_index_nested_failure = (array) ( $wrong_index_publish['menu'] ?? array() );
	if (
		! $wrong_index_injected
		|| 1 !== $wrong_index_commit_calls
		|| empty( $wrong_index_commit_receipt['success'] )
		|| true !== ( $wrong_index_commit_receipt['committed'] ?? null )
		|| ! empty( $wrong_index_rows_missing )
		|| ! empty( $wrong_index_publish['success'] )
		|| 'public_header_projection_publication_failed' !== (string) ( $wrong_index_publish['code'] ?? '' )
		|| true !== ( $wrong_index_publish['forward_publication_applied'] ?? null )
		|| false !== ( $wrong_index_publish['published'] ?? null )
		|| empty( $wrong_index_publish['rollback']['success'] )
		|| empty( $wrong_index_publish['rollback']['commit_reconciliation']['restored_exact'] )
		|| 'restored_verified' !== (string) ( $wrong_index_publish['final_reader_state']['state'] ?? '' )
		|| 'public_header_relation_receipt_build_failed' !== (string) ( $wrong_index_nested_failure['code'] ?? '' )
		|| false === strpos( wp_json_encode( $wrong_index_nested_failure ), 'public_header_page_relation_index_mismatch' )
		|| ! $wrong_index_restore_success
		|| $wrong_index_rows_before !== $wrong_index_rows_after
		|| $wrong_index_authority_options !== $raw_option_records( array( $wrong_index_job_key, $wrong_index_artifact_key, $wrong_index_quality_key, $wrong_index_evidence_key ) )
		|| ! $wrong_index_content_authority_exact
		|| $wrong_index_header_before !== $raw_option_records( $wrong_index_header_options )
		|| $wrong_index_menu_before !== $raw_nav_menu_surface( $wrong_index_menu_ids )
		|| $wrong_index_menu_inventory_before !== $raw_nav_menu_inventory()
		|| $wrong_index_inventory_before !== $raw_option_records( $wrong_index_inventory_options )
	) {
		$wrong_index_post_changed_fields = array();
		foreach ( array_unique( array_merge( array_keys( (array) ( $wrong_index_content_before['post'] ?? array() ) ), array_keys( (array) ( $wrong_index_content_after['post'] ?? array() ) ) ) ) as $wrong_index_post_field ) {
			if ( ( $wrong_index_content_before['post'][ $wrong_index_post_field ] ?? null ) !== ( $wrong_index_content_after['post'][ $wrong_index_post_field ] ?? null ) ) { $wrong_index_post_changed_fields[] = $wrong_index_post_field; }
		}
		$wrong_index_evidence = array(
			'injected'                 => $wrong_index_injected,
			'commit_calls_exact'        => 1 === $wrong_index_commit_calls,
			'commit_receipt_valid'      => ! empty( $wrong_index_commit_receipt['success'] ) && true === ( $wrong_index_commit_receipt['committed'] ?? null ),
			'row_absent_after_commit'   => empty( $wrong_index_rows_missing ),
			'publish_code_exact'        => 'public_header_projection_publication_failed' === (string) ( $wrong_index_publish['code'] ?? '' ),
			'nested_code_exact'         => 'public_header_relation_receipt_build_failed' === (string) ( $wrong_index_nested_failure['code'] ?? '' ),
			'index_mismatch_present'    => false !== strpos( wp_json_encode( $wrong_index_nested_failure ), 'public_header_page_relation_index_mismatch' ),
			'row_restore_success'       => $wrong_index_restore_success,
			'rows_restored_exact'       => $wrong_index_rows_before === $wrong_index_rows_after,
			'authority_options_exact'   => $wrong_index_authority_options === $raw_option_records( array( $wrong_index_job_key, $wrong_index_artifact_key, $wrong_index_quality_key, $wrong_index_evidence_key ) ),
			'content_authority_exact'   => $wrong_index_content_authority_exact,
			'content_post_drift_fields' => $wrong_index_post_changed_fields,
			'content_meta_exact'        => (array) ( $wrong_index_content_before['postmeta'] ?? array() ) === (array) ( $wrong_index_content_after['postmeta'] ?? array() ),
			'content_meta_values_exact' => $wrong_index_meta_without_ids( (array) ( $wrong_index_content_before['postmeta'] ?? array() ) ) === $wrong_index_meta_without_ids( (array) ( $wrong_index_content_after['postmeta'] ?? array() ) ),
			'content_terms_exact'       => (array) ( $wrong_index_content_before['terms'] ?? array() ) === (array) ( $wrong_index_content_after['terms'] ?? array() ),
			'forward_applied_exact'     => true === ( $wrong_index_publish['forward_publication_applied'] ?? null ),
			'published_false_exact'     => false === ( $wrong_index_publish['published'] ?? null ),
			'rollback_restored_exact'   => ! empty( $wrong_index_publish['rollback']['success'] ) && ! empty( $wrong_index_publish['rollback']['commit_reconciliation']['restored_exact'] ),
			'final_reader_restored'     => 'restored_verified' === (string) ( $wrong_index_publish['final_reader_state']['state'] ?? '' ),
			'header_options_exact'      => $wrong_index_header_before === $raw_option_records( $wrong_index_header_options ),
			'menu_surface_exact'        => $wrong_index_menu_before === $raw_nav_menu_surface( $wrong_index_menu_ids ),
			'menu_inventory_exact'      => $wrong_index_menu_inventory_before === $raw_nav_menu_inventory(),
			'source_inventory_exact'    => $wrong_index_inventory_before === $raw_option_records( $wrong_index_inventory_options ),
		);
		throw new RuntimeException( 'Ordinary translation_job_publish did not fail closed on a post-COMMIT wrong Translation Index row with exact reader and authority restoration: ' . wp_json_encode( array( 'evidence' => $wrong_index_evidence, 'result' => $wrong_index_publish ) ) );
	}
	$attempt_limit_job_key = 'devenia_workflow_translation_job_' . $job_id;
	$attempt_limit_job = get_option( $attempt_limit_job_key );
	$attempt_limit_max = absint( $workflow_reflection->getConstant( 'TRANSLATION_JOB_MAX_RUNS_PER_ROLE' ) );
	$attempt_limit_generation = $call( 'translation_job_submission_generation', $attempt_limit_job );
	if ( $attempt_limit_max < 1 ) {
		throw new RuntimeException( 'Runtime could not derive the substantive Run ceiling from TRANSLATION_JOB_MAX_RUNS_PER_ROLE.' );
	}
	$attempt_limit_counts = array();
	foreach ( array( 'quality', 'translator' ) as $attempt_limit_role ) {
		$attempt_limit_count = $call( 'translation_job_role_attempt_count', (array) ( $attempt_limit_job['run_ids'] ?? array() ), $attempt_limit_role, $attempt_limit_generation );
		if ( $attempt_limit_count > $attempt_limit_max ) {
			throw new RuntimeException( 'Existing substantive runtime attempts already exceed the production role ceiling.' );
		}
		for ( $attempt_limit_index = $attempt_limit_count; $attempt_limit_index < $attempt_limit_max; ++$attempt_limit_index ) {
			$attempt_limit_fill_run_id = 'runtime-attempt-fill-' . $attempt_limit_role . '-' . $attempt_limit_index . '-' . wp_generate_password( 8, false, false );
			$attempt_limit_fill_run_key = 'devenia_workflow_translation_run_' . $attempt_limit_fill_run_id;
			$attempt_limit_fill_run = array(
				'run_id' => $attempt_limit_fill_run_id,
				'job_id' => $job_id,
				'role' => $attempt_limit_role,
				'status' => 'completed',
				'outcome' => 'quality' === $attempt_limit_role ? 'revise' : 'submitted',
				'submission_generation' => $attempt_limit_generation,
				'finished_at' => gmdate( 'c' ),
			);
			if ( ! add_option( $attempt_limit_fill_run_key, $attempt_limit_fill_run, '', false ) ) {
				throw new RuntimeException( 'Could not create a bounded runtime-only substantive Run fixture.' );
			}
			$option_keys[] = $attempt_limit_fill_run_key;
			$attempt_limit_job['run_ids'][] = array( 'run_id' => $attempt_limit_fill_run_id, 'role' => $attempt_limit_role, 'submission_generation' => $attempt_limit_generation );
		}
		$attempt_limit_counts[ $attempt_limit_role ] = $call( 'translation_job_role_attempt_count', (array) $attempt_limit_job['run_ids'], $attempt_limit_role, $attempt_limit_generation );
	}
	if ( $attempt_limit_max !== (int) $attempt_limit_counts['quality'] || $attempt_limit_max !== (int) $attempt_limit_counts['translator'] ) {
		throw new RuntimeException( 'Runtime-only substantive Run fixtures did not reach the exact production ceiling: ' . wp_json_encode( $attempt_limit_counts ) );
	}
	$attempt_limit_job['status'] = 'quality_pending';
	update_option( $attempt_limit_job_key, $attempt_limit_job, false );
	$over_limit_quality_claim = $call(
		'translation_job_claim',
		array(
			'job_id' => $job_id,
			'run_id' => 'runtime-quality-over-limit-' . wp_generate_password( 8, false, false ),
			'coordinator_id' => 'runtime-coordinator',
			'role' => 'quality',
			'ttl_seconds' => 600,
		)
	);
	if ( ! empty( $over_limit_quality_claim['success'] ) || 'run_attempt_limit' !== (string) ( $over_limit_quality_claim['code'] ?? '' ) || $attempt_limit_generation !== absint( $over_limit_quality_claim['submission_generation'] ?? 0 ) ) {
		throw new RuntimeException( 'The Quality claim after the production-derived substantive ceiling did not fail at run_attempt_limit: ' . wp_json_encode( $over_limit_quality_claim ) );
	}
	$attempt_limit_job['status'] = 'changes_requested';
	update_option( $attempt_limit_job_key, $attempt_limit_job, false );
	$over_limit_translator_claim = $call(
		'translation_job_claim',
		array(
			'job_id' => $job_id,
			'run_id' => 'runtime-translator-over-limit-' . wp_generate_password( 8, false, false ),
			'coordinator_id' => 'runtime-coordinator',
			'role' => 'translator',
			'ttl_seconds' => 600,
		)
	);
	if ( ! empty( $over_limit_translator_claim['success'] ) || 'run_attempt_limit' !== (string) ( $over_limit_translator_claim['code'] ?? '' ) || $attempt_limit_generation !== absint( $over_limit_translator_claim['submission_generation'] ?? 0 ) ) {
		throw new RuntimeException( 'The translator claim after the production-derived substantive ceiling did not fail at run_attempt_limit: ' . wp_json_encode( $over_limit_translator_claim ) );
	}
	$attempt_limit_job['status'] = 'ready_to_publish';
	update_option( $attempt_limit_job_key, $attempt_limit_job, false );

	// A persisted generation-1 Job with a complete immutable Artifact/Quality
	// chain but a stale applied Surface authority must be discoverable as work
	// again. Keep this proof before the later runtime scenarios intentionally
	// consume generations 2 and 3, so the production finite-generation ceiling
	// remains part of the assertion instead of becoming a fixture dependency.
	$published_drift_fixture_before = get_option( $attempt_limit_job_key );
	$published_drift_fixture = $published_drift_fixture_before;
	$published_drift_fixture['status'] = 'published';
	$published_drift_fixture['translation_id'] = $translation_id;
	$published_drift_fixture['applied_surface_revision'] = 'sr_runtime_stale_applied_surface';
	$published_drift_fixture['published_at'] = gmdate( 'c' );
	$published_drift_fixture['live_verification_passed'] = true;
	$published_drift_fixture['active_run_id'] = '';
	update_option( $attempt_limit_job_key, $published_drift_fixture, false );
	$published_drift_obligation = array();
	$published_drift_discover = array();
	$published_drift_reopened_job = array();
	try {
		$published_drift_source_surface = $call( 'source_publication_surface_revision', get_post( $source_id ) );
		$published_drift_source_contract = $call( 'translation_job_publication_surface_contract_revision', get_post( $source_id ), $language );
		$published_drift_obligation = $call( 'project_translation_obligation', $source_id, $language, $published_drift_source_surface, $published_drift_source_contract );
		$published_drift_discover = $call( 'translation_job_discover', array( 'source_id' => $source_id, 'language' => $language, 'observability_label' => 'runtime-published-authority-drift' ) );
		$published_drift_reopened_job = get_option( $attempt_limit_job_key );
	} finally {
		$published_drift_current_job = get_option( $attempt_limit_job_key );
		if ( is_array( $published_drift_current_job ) && ! empty( $published_drift_current_job ) ) {
			$restore_published_drift_job = $call( 'translation_job_transition', $published_drift_current_job, $published_drift_fixture_before );
			if ( empty( $restore_published_drift_job['success'] ) ) {
				throw new RuntimeException( 'Generation-1 published authority drift fixture could not restore the exact Job through its transition seam: ' . wp_json_encode( $restore_published_drift_job ) );
			}
			// The transition seam intentionally merges lifecycle fields. The fixture
			// then restores the byte-exact precondition so later runtime scenarios do
			// not inherit synthetic published-only keys.
			update_option( $attempt_limit_job_key, $published_drift_fixture_before, false );
		}
	}
	$published_drift_history = is_array( $published_drift_reopened_job['surface_refresh_history'] ?? null ) ? $published_drift_reopened_job['surface_refresh_history'] : array();
	$published_drift_latest = $published_drift_history ? (array) end( $published_drift_history ) : array();
	$published_drift_restored_job = get_option( $attempt_limit_job_key );
	if (
		1 !== absint( $published_drift_fixture_before['submission_generation'] ?? 0 )
		|| 'publication_authority_stale' !== (string) ( $published_drift_obligation['state'] ?? '' )
		|| empty( $published_drift_discover['success'] )
		|| 'changes_requested' !== (string) ( $published_drift_reopened_job['status'] ?? '' )
		|| 2 !== absint( $published_drift_reopened_job['submission_generation'] ?? 0 )
		|| ! empty( $published_drift_reopened_job['live_verification_passed'] )
		|| 'discover_published_authority_drift' !== (string) ( $published_drift_latest['reason'] ?? '' )
		|| ! in_array( (string) ( $published_drift_latest['authority_code'] ?? '' ), array( 'published_content_revision_stale', 'published_surface_revision_stale' ), true )
		|| 'ready_to_publish' !== (string) ( $published_drift_restored_job['status'] ?? '' )
		|| 1 !== absint( $published_drift_restored_job['submission_generation'] ?? 0 )
		|| (string) ( $published_drift_fixture_before['artifact_revision'] ?? '' ) !== (string) ( $published_drift_restored_job['artifact_revision'] ?? '' )
		|| (string) ( $published_drift_fixture_before['quality_revision'] ?? '' ) !== (string) ( $published_drift_restored_job['quality_revision'] ?? '' )
	) {
		throw new RuntimeException( 'Discover did not reopen repairable generation-1 published authority drift as exactly one fresh translator/Quality generation and restore the fixture: ' . wp_json_encode( compact( 'published_drift_obligation', 'published_drift_discover', 'published_drift_reopened_job', 'published_drift_latest', 'published_drift_restored_job' ) ) );
	}

	// A passing generation-1 Quality Decision must reopen in the same publish
	// call when the public surface changes after the snapshot commits but before
	// the staged-write transaction acquires its locks. The existing WordPress SQL
	// query filter deterministically injects that concurrent write immediately
	// before the second recovery transaction starts; production gains no test hook.
	$prior_artifact_revision = (string) $third_artifact['artifact_revision'];
	$prior_quality_revision = (string) $third_quality['quality_decision']['quality_revision'];
	$prior_artifact_bytes = maybe_serialize( get_option( 'devenia_workflow_translation_artifact_' . $prior_artifact_revision ) );
	$prior_quality_record = get_option( 'devenia_workflow_translation_quality_' . $prior_quality_revision );
	$prior_quality_bytes = maybe_serialize( $prior_quality_record );
	$prior_evidence_key = 'devenia_tj_quality_evidence_' . (string) ( $prior_quality_record['evidence_revision'] ?? '' );
	$option_keys[] = $prior_evidence_key;
	$prior_evidence_bytes = maybe_serialize( get_option( $prior_evidence_key ) );
	$drift_title = 'Runtime snapshot-to-lock public surface drift';
	$surface_race_start_count = 0;
	$surface_race_triggered = false;
	$surface_race_filter = null;
	$surface_race_filter = static function ( string $query ) use ( &$surface_race_start_count, &$surface_race_triggered, &$surface_race_filter, $translation_id, $drift_title ): string {
		if ( 'START TRANSACTION' !== strtoupper( trim( $query ) ) ) {
			return $query;
		}
		++$surface_race_start_count;
		if ( 2 === $surface_race_start_count ) {
			remove_filter( 'query', $surface_race_filter, PHP_INT_MAX );
			$updated = wp_update_post( array( 'ID' => $translation_id, 'post_title' => $drift_title ), true );
			$surface_race_triggered = ! is_wp_error( $updated ) && $translation_id === absint( $updated );
		}
		return $query;
	};
	add_filter( 'query', $surface_race_filter, PHP_INT_MAX );
	try {
		$surface_refresh_publish = $call(
			'translation_job_publish',
			array( 'job_id' => $job_id, 'sync_menu' => true, 'verify_live' => true )
		);
	} finally {
		remove_filter( 'query', $surface_race_filter, PHP_INT_MAX );
	}
	$surface_refresh_job = get_option( $attempt_limit_job_key );
	if (
		! $surface_race_triggered
		|| 'staged_surface_drifted_before_locked_write' !== (string) ( $surface_refresh_publish['code'] ?? '' )
		|| ! empty( $surface_refresh_publish['mutation_started'] )
		|| empty( $surface_refresh_publish['transaction_rollback']['success'] )
		|| empty( $surface_refresh_publish['transaction_rollback']['rolled_back'] )
		|| empty( $surface_refresh_publish['surface_refresh']['refreshed'] )
		|| 'changes_requested' !== (string) ( $surface_refresh_job['status'] ?? '' )
		|| 2 !== absint( $surface_refresh_job['submission_generation'] ?? 0 )
		|| '' !== (string) ( $surface_refresh_job['artifact_revision'] ?? '' )
		|| '' !== (string) ( $surface_refresh_job['quality_revision'] ?? '' )
		|| $drift_title !== (string) get_post_field( 'post_title', $translation_id )
	) {
		throw new RuntimeException( 'Snapshot-to-lock Surface Refresh did not prove same-call zero mutation, rollback, and an exact Job reopen: ' . wp_json_encode( array( 'publish' => $surface_refresh_publish, 'transaction_starts' => $surface_race_start_count, 'race_triggered' => $surface_race_triggered ) ) );
	}
	if (
		$prior_artifact_bytes !== maybe_serialize( get_option( 'devenia_workflow_translation_artifact_' . $prior_artifact_revision ) )
		|| $prior_quality_bytes !== maybe_serialize( get_option( 'devenia_workflow_translation_quality_' . $prior_quality_revision ) )
		|| $prior_evidence_bytes !== maybe_serialize( get_option( $prior_evidence_key ) )
	) {
		throw new RuntimeException( 'Surface Refresh changed an immutable prior artifact, Quality, or evidence record.' );
	}
	$refresh_history = (array) ( $surface_refresh_job['surface_refresh_history'] ?? array() );
	$latest_refresh = $refresh_history ? (array) end( $refresh_history ) : array();
	if (
		$prior_artifact_revision !== (string) ( $latest_refresh['prior_artifact_revision'] ?? '' )
		|| $prior_quality_revision !== (string) ( $latest_refresh['prior_quality_revision'] ?? '' )
		|| empty( $latest_refresh['prior_baseline_surface_revision'] )
		|| empty( $latest_refresh['current_surface_revision'] )
	) {
		throw new RuntimeException( 'Surface Refresh history did not retain immutable prior bindings.' );
	}

	$refresh_abandon_claim = $call(
		'translation_job_claim',
		array( 'job_id' => $job_id, 'run_id' => 'runtime-refresh-abandon-' . wp_generate_password( 8, false, false ), 'coordinator_id' => 'runtime-observability-only', 'role' => 'translator', 'ttl_seconds' => 600 )
	);
	if ( empty( $refresh_abandon_claim['success'] ) || 2 !== absint( $refresh_abandon_claim['run']['submission_generation'] ?? 0 ) ) {
		throw new RuntimeException( 'Fresh generation-2 translator claim failed after Surface Refresh: ' . wp_json_encode( $refresh_abandon_claim ) );
	}
	$option_keys[] = 'devenia_workflow_translation_run_' . (string) $refresh_abandon_claim['run']['run_id'];
	$refresh_abandoned = $call(
		'translation_job_abandon',
		array( 'job_id' => $job_id, 'run_id' => (string) $refresh_abandon_claim['run']['run_id'], 'claim_token' => (string) $refresh_abandon_claim['claim_token'], 'reason' => 'Runtime proves an abandoned refreshed translator claim restores changes_requested.' )
	);
	if ( empty( $refresh_abandoned['success'] ) || 'changes_requested' !== (string) ( $refresh_abandoned['job']['status'] ?? '' ) ) {
		throw new RuntimeException( 'Refreshed translator abandon did not restore changes_requested: ' . wp_json_encode( $refresh_abandoned ) );
	}

	$refresh_writer_claim = $call(
		'translation_job_claim',
		array( 'job_id' => $job_id, 'run_id' => 'runtime-refresh-writer-' . wp_generate_password( 8, false, false ), 'coordinator_id' => 'runtime-observability-only', 'role' => 'translator', 'ttl_seconds' => 600 )
	);
	$option_keys[] = 'devenia_workflow_translation_run_' . (string) ( $refresh_writer_claim['run']['run_id'] ?? '' );
	$refresh_writer_packet = $call( 'translation_job_fetch_packet', array( 'job_id' => $job_id, 'run_id' => (string) $refresh_writer_claim['run']['run_id'], 'claim_token' => (string) $refresh_writer_claim['claim_token'] ) );
	if (
		empty( $refresh_writer_packet['success'] )
		|| $prior_artifact_revision !== (string) ( $refresh_writer_packet['packet']['correction_context']['previous_artifact_revision'] ?? '' )
		|| empty( $refresh_writer_packet['packet']['correction_context']['previous_artifact']['artifact'] )
	) {
		throw new RuntimeException( 'Refreshed translator packet could not recover the previous approved artifact: ' . wp_json_encode( $refresh_writer_packet ) );
	}
	$refresh_link_rows = array_values(
		array_filter(
			(array) ( $refresh_writer_packet['packet']['links'] ?? array() ),
			static function ( $row ) use ( $linked_source_id ): bool {
				return is_array( $row ) && $linked_source_id === absint( $row['source_post_id'] ?? 0 );
			}
		)
	);
	$refresh_link_row = 1 === count( $refresh_link_rows ) ? (array) $refresh_link_rows[0] : array();
	$refresh_link_source_url = (string) get_permalink( $linked_source_id );
	if (
		1 !== count( $refresh_link_rows )
		|| $linked_source_id !== absint( $refresh_link_row['target_post_id'] ?? 0 )
		|| false !== ( $refresh_link_row['published_target_available'] ?? null )
		|| 'retain_source_url_until_localized_target_is_published' !== (string) ( $refresh_link_row['policy'] ?? '' )
		|| $call( 'normalized_comparable_url', $refresh_link_source_url ) !== $call( 'normalized_comparable_url', (string) ( $refresh_link_row['target_url'] ?? '' ) )
	) {
		throw new RuntimeException( 'Untranslated query-ID source link was aliased to unrelated localized route authority: ' . wp_json_encode( array( 'linked_source_id' => $linked_source_id, 'linked_source_url' => $refresh_link_source_url, 'link_rows' => $refresh_link_rows ) ) );
	}
	$build_runtime_refresh_artifact = static function ( array $fresh_packet, array $prototype_artifact ) use ( $call ): array {
		$packet = (array) ( $fresh_packet['packet'] ?? array() );
		$packet_fragments = array_values( (array) ( $packet['fragments'] ?? array() ) );
		$packet_links = array_values( (array) ( $packet['links'] ?? array() ) );
		$expected_targets = array();
		$link_markup = array();
		$link_issues = array();
		foreach ( $packet_links as $link_index => $link ) {
			$source_url = (string) ( $link['source_url'] ?? '' );
			$target_url = (string) ( $link['target_url'] ?? '' );
			$policy = (string) ( $link['policy'] ?? '' );
			$target_key = '' !== $target_url ? (string) $call( 'normalized_comparable_url', $target_url ) : '';
			if (
				'' === $source_url
				|| '' === $target_url
				|| '' === $target_key
				|| ! in_array( $policy, array( 'retain_source_url_until_localized_target_is_published', 'use_published_localized_target' ), true )
			) {
				$link_issues[] = array( 'index' => $link_index, 'source_url' => $source_url, 'target_url' => $target_url, 'policy' => $policy );
				continue;
			}
			$expected_targets[ $target_key ] = $target_url;
			$link_markup[] = '<a href="' . esc_url( $target_url ) . '">den lenkede kilden</a>';
		}

		$localized_fragments = array();
		$fragment_keys = array();
		foreach ( $packet_fragments as $fragment_index => $fragment ) {
			$key = (string) ( $fragment['key'] ?? '' );
			if ( '' === $key || isset( $fragment_keys[ $key ] ) ) {
				return array( 'success' => false, 'code' => 'runtime_refresh_packet_fragment_invalid', 'fragment_index' => $fragment_index, 'fragment_key' => $key );
			}
			$fragment_keys[ $key ] = true;
			$localized_fragments[] = array(
				'key' => $key,
				'html' => '<strong>Nyttig innhold</strong><br>'
					. ( $link_markup ? 'Les ' . implode( ' og ', $link_markup ) . ', og ' : '' )
					. '<a href="mailto:hello@example.com?subject=Sp%C3%B8rsm%C3%A5l%20om%20testen&amp;body=Hei%20fra%20oversettelsen">kontakt oss</a> for et konkret neste steg.',
			);
		}

		$refreshed_artifact = $prototype_artifact;
		$refreshed_artifact['localized_fragments'] = $localized_fragments;
		$consumed_targets = array();
		foreach ( $localized_fragments as $fragment ) {
			foreach ( $call( 'translation_job_anchor_hrefs', (string) $fragment['html'] ) as $href ) {
				$absolute = (string) $call( 'translation_job_absolute_internal_url', (string) $href );
				$key = '' !== $absolute ? (string) $call( 'normalized_comparable_url', $absolute ) : '';
				if ( '' !== $key && isset( $expected_targets[ $key ] ) ) {
					$consumed_targets[ $key ] = $expected_targets[ $key ];
				}
			}
		}
		$missing_targets = array_values( array_diff_key( $expected_targets, $consumed_targets ) );

		return array(
			'success' => empty( $link_issues ) && ! empty( $packet_fragments ) && empty( $missing_targets ),
			'code' => empty( $link_issues ) ? ( $packet_fragments ? ( $missing_targets ? 'runtime_refresh_packet_targets_missing' : 'runtime_refresh_artifact_built' ) : 'runtime_refresh_packet_fragments_missing' ) : 'runtime_refresh_packet_links_invalid',
			'artifact' => $refreshed_artifact,
			'packet_link_count' => count( $packet_links ),
			'expected_target_urls' => array_values( $expected_targets ),
			'consumed_target_urls' => array_values( $consumed_targets ),
			'missing_target_urls' => $missing_targets,
			'link_issues' => $link_issues,
		);
	};
	$refresh_previous_artifact = (array) $refresh_writer_packet['packet']['correction_context']['previous_artifact']['artifact'];
	$refresh_artifact_build = $build_runtime_refresh_artifact( $refresh_writer_packet, $refresh_previous_artifact );
	$refresh_generation_two_excerpt = 'Runtime generation-two packet-owned correction.';
	$refresh_artifact_build['artifact']['excerpt'] = $refresh_generation_two_excerpt;
	if (
		empty( $refresh_artifact_build['success'] )
		|| empty( $refresh_artifact_build['artifact'] )
		|| (array) ( $refresh_artifact_build['expected_target_urls'] ?? array() ) !== (array) ( $refresh_artifact_build['consumed_target_urls'] ?? array() )
	) {
		throw new RuntimeException( 'Refreshed translator artifact did not consume the fresh packet authority exactly: ' . wp_json_encode( $refresh_artifact_build ) );
	}
	delete_post_meta( $translation_id, '_thumbnail_id' );
	add_post_meta( $translation_id, '_thumbnail_id', $linked_source_id );
	add_post_meta( $translation_id, '_thumbnail_id', $source_thumbnail_id );
	$refresh_artifact = $call(
		'translation_job_submit_artifact',
		array( 'job_id' => $job_id, 'run_id' => (string) $refresh_writer_claim['run']['run_id'], 'claim_token' => (string) $refresh_writer_claim['claim_token'], 'artifact' => $refresh_artifact_build['artifact'], 'usage' => array( 'input_tokens' => 700, 'cached_input_tokens' => 0, 'output_tokens' => 200, 'attempts' => 1, 'duration_ms' => 700, 'estimated_cost_microusd' => 50 ) )
	);
	$refresh_artifact_revision = (string) ( $refresh_artifact['artifact_revision'] ?? '' );
	$option_keys[] = 'devenia_workflow_translation_artifact_' . $refresh_artifact_revision;
	$refresh_artifact_record = $call( 'translation_job_unpack_artifact_record', get_option( 'devenia_workflow_translation_artifact_' . $refresh_artifact_revision ) );
	$prior_artifact_record = $call( 'translation_job_unpack_artifact_record', get_option( 'devenia_workflow_translation_artifact_' . $prior_artifact_revision ) );
	if (
		empty( $refresh_artifact['success'] )
		|| $refresh_artifact_revision === $prior_artifact_revision
		|| 2 !== absint( $refresh_artifact_record['submission_generation'] ?? 0 )
		|| empty( $refresh_artifact_record['baseline_surface_revision'] )
		|| (string) ( $refresh_artifact_record['writer_principal']['principal_id'] ?? '' ) === (string) ( $prior_artifact_record['writer_principal']['principal_id'] ?? '' )
	) {
		throw new RuntimeException( 'Packet-coherent refreshed payload did not create a new immutable generation/baseline/writer artifact: ' . wp_json_encode( $refresh_artifact ) );
	}
	$fresh_quality_required = $call( 'translation_job_publish', array( 'job_id' => $job_id ) );
	if ( 'job_not_ready_to_publish' !== (string) ( $fresh_quality_required['code'] ?? '' ) ) {
		throw new RuntimeException( 'Refreshed artifact did not require a fresh Quality Decision.' );
	}

	// Quality defensively reopens generation 2 when the baseline drifts after
	// its packet was issued but before the decision is submitted, preserving the
	// claimed Run as immutable history and rejecting the now-stale receipts.
	$refresh_quality_claim = $call(
		'translation_job_claim',
		array( 'job_id' => $job_id, 'run_id' => 'runtime-refresh-quality-drift-' . wp_generate_password( 8, false, false ), 'coordinator_id' => 'runtime-observability-only', 'role' => 'quality', 'ttl_seconds' => 600 )
	);
	$option_keys[] = 'devenia_workflow_translation_run_' . (string) ( $refresh_quality_claim['run']['run_id'] ?? '' );
	$quality_drift_payload = $quality_payload(
		$refresh_quality_claim,
		$refresh_artifact_revision,
		'pass',
		'Runtime prepared a complete Quality Decision against generation two before the public baseline drifted.',
		array()
	);
	wp_update_post( array( 'ID' => $translation_id, 'post_excerpt' => 'Runtime second public drift before Quality submission.' ) );
	$quality_drift_submission = $call( 'translation_job_submit_quality_decision', $quality_drift_payload );
	$generation_three_job = get_option( $attempt_limit_job_key );
	if (
		'surface_refresh_required' !== (string) ( $quality_drift_submission['code'] ?? '' )
		|| 'changes_requested' !== (string) ( $generation_three_job['status'] ?? '' )
		|| 3 !== absint( $generation_three_job['submission_generation'] ?? 0 )
		|| 'completed' !== (string) ( get_option( 'devenia_workflow_translation_run_' . (string) $refresh_quality_claim['run']['run_id'] )['status'] ?? '' )
	) {
		throw new RuntimeException( 'Quality defensive Surface Refresh path failed: ' . wp_json_encode( $quality_drift_submission ) );
	}

	$generation_three_writer = $call(
		'translation_job_claim',
		array( 'job_id' => $job_id, 'run_id' => 'runtime-refresh-writer-three-' . wp_generate_password( 8, false, false ), 'coordinator_id' => 'runtime-observability-only', 'role' => 'translator', 'ttl_seconds' => 600 )
	);
	$option_keys[] = 'devenia_workflow_translation_run_' . (string) $generation_three_writer['run']['run_id'];
	$generation_three_packet = $call( 'translation_job_fetch_packet', array( 'job_id' => $job_id, 'run_id' => (string) $generation_three_writer['run']['run_id'], 'claim_token' => (string) $generation_three_writer['claim_token'] ) );
	if (
		empty( $generation_three_packet['success'] )
		|| $refresh_artifact_revision !== (string) ( $generation_three_packet['packet']['correction_context']['previous_artifact_revision'] ?? '' )
		|| empty( $generation_three_packet['packet']['correction_context']['previous_artifact']['artifact'] )
	) {
		throw new RuntimeException( 'Generation-three packet could not recover the latest immutable generation-two artifact: ' . wp_json_encode( $generation_three_packet ) );
	}
	$generation_three_previous_artifact = (array) $generation_three_packet['packet']['correction_context']['previous_artifact']['artifact'];
	$generation_three_artifact_build = $build_runtime_refresh_artifact( $generation_three_packet, $generation_three_previous_artifact );
	$generation_three_previous_non_fragments = $generation_three_previous_artifact;
	$generation_three_built_non_fragments = (array) ( $generation_three_artifact_build['artifact'] ?? array() );
	unset( $generation_three_previous_non_fragments['localized_fragments'], $generation_three_built_non_fragments['localized_fragments'] );
	if (
		empty( $generation_three_artifact_build['success'] )
		|| empty( $generation_three_artifact_build['artifact'] )
		|| (array) ( $generation_three_artifact_build['expected_target_urls'] ?? array() ) !== (array) ( $generation_three_artifact_build['consumed_target_urls'] ?? array() )
		|| $refresh_generation_two_excerpt !== (string) ( $generation_three_previous_artifact['excerpt'] ?? '' )
		|| $generation_three_previous_non_fragments !== $generation_three_built_non_fragments
	) {
		throw new RuntimeException( 'Generation-three artifact did not consume the fresh packet authority exactly: ' . wp_json_encode( $generation_three_artifact_build ) );
	}
	$generation_three_artifact = $call(
		'translation_job_submit_artifact',
		array( 'job_id' => $job_id, 'run_id' => (string) $generation_three_writer['run']['run_id'], 'claim_token' => (string) $generation_three_writer['claim_token'], 'artifact' => $generation_three_artifact_build['artifact'], 'usage' => array( 'input_tokens' => 700, 'cached_input_tokens' => 0, 'output_tokens' => 200, 'attempts' => 1, 'duration_ms' => 700, 'estimated_cost_microusd' => 50 ) )
	);
	$generation_three_artifact_revision = (string) ( $generation_three_artifact['artifact_revision'] ?? '' );
	if ( empty( $generation_three_artifact['success'] ) || '' === $generation_three_artifact_revision ) {
		throw new RuntimeException( 'Generation-three packet-coherent artifact submission failed: ' . wp_json_encode( $generation_three_artifact ) );
	}
	$option_keys[] = 'devenia_workflow_translation_artifact_' . $generation_three_artifact_revision;
	$generation_three_quality_claim = $call(
		'translation_job_claim',
		array( 'job_id' => $job_id, 'run_id' => 'runtime-refresh-quality-three-' . wp_generate_password( 8, false, false ), 'coordinator_id' => 'runtime-observability-only', 'role' => 'quality', 'ttl_seconds' => 600 )
	);
	if ( empty( $generation_three_quality_claim['success'] ) || empty( $generation_three_quality_claim['run']['run_id'] ) || empty( $generation_three_quality_claim['claim_token'] ) ) {
		throw new RuntimeException( 'Generation-three Quality claim failed: ' . wp_json_encode( $generation_three_quality_claim ) );
	}
	$option_keys[] = 'devenia_workflow_translation_run_' . (string) $generation_three_quality_claim['run']['run_id'];
	$generation_three_quality = $call(
		'translation_job_submit_quality_decision',
		$quality_payload( $generation_three_quality_claim, $generation_three_artifact_revision, 'pass', 'A fresh generation-three Quality principal reviewed the exact immutable artifact after both baseline refreshes.', array() )
	);
	$track_quality_result( $generation_three_quality );
	if (
		empty( $generation_three_quality['success'] )
		|| (string) ( $generation_three_quality['quality_decision']['reviewer_principal']['principal_id'] ?? '' ) === (string) ( $generation_three_writer['run']['principal']['principal_id'] ?? '' )
		|| 3 !== absint( $generation_three_quality['quality_decision']['submission_generation'] ?? 0 )
	) {
		throw new RuntimeException( 'Fresh generation-three Quality Decision failed: ' . wp_json_encode( $generation_three_quality ) );
	}

	// Staged Translation Artifact application owns the first public mutation.
	// Exercise that complete Translation Job Interface for both applied and
	// foreign outcomes after a real COMMIT whose Adapter reports true or unknown.
	// Define the matrix here beside the generation fixtures it protects, but run
	// it only after the later published-correction lifecycle establishes a fresh
	// independently reviewed artifact on one valid visible-media baseline.
	$run_commit_reconciliation_matrices = static function ( string $matrix_job_id, string $matrix_job_key ) use ( $call, $translation_id, $runtime_meta_status_key, &$cache_invalidation_calls ): void {
		$job_id = $matrix_job_id;
		$attempt_limit_job_key = $matrix_job_key;
	$staged_commit_job_before = get_option( $attempt_limit_job_key );
	$staged_commit_artifact = $call( 'translation_job_unpack_artifact_record', get_option( 'devenia_workflow_translation_artifact_' . (string) ( $staged_commit_job_before['artifact_revision'] ?? '' ) ) );
	$staged_commit_quality_key = 'devenia_workflow_translation_quality_' . (string) ( $staged_commit_job_before['quality_revision'] ?? '' );
	// Only the exact null sentinel may select the internal COMMIT Interface. A
	// present non-array value and a structurally complete receipt that claims a
	// COMMIT without ending the transaction are both invalid Adapter receipts.
	// Each case must terminalize the still-owned transaction and stop before the
	// Localized Presentation publication phase receives any authority.
	$staged_active_receipt_modes = array(
		'non_array' => array(
			'receipt'                    => 'runtime-non-array-receipt-' . wp_generate_uuid4(),
			'expect_missing_committed'   => true,
			'expect_adapter_receipt_type' => 'string',
		),
		'claimed_commit_while_active' => array(
			'receipt'                    => array( 'success' => true, 'committed' => true, 'code' => 'runtime_claimed_commit_while_transaction_active' ),
			'expect_missing_committed'   => false,
			'expect_adapter_receipt_type' => '',
		),
	);
	foreach ( $staged_active_receipt_modes as $staged_active_mode => $staged_active_expectation ) {
		$staged_active_snapshot = $call( 'translation_job_capture_surface_snapshot', $translation_id, (array) ( $staged_commit_artifact['surface_manifest'] ?? array() ), $call( 'translation_job_publication_identity_scope', $staged_commit_job_before ) );
		if ( empty( $staged_active_snapshot['snapshot_valid'] ) ) { throw new RuntimeException( 'Active-transaction staged receipt fixture could not capture its exact pre-attempt surface for ' . $staged_active_mode . '.' ); }
		$staged_active_before_revision = (string) ( $staged_active_snapshot['captured_cas_revision'] ?? '' );
		$staged_active_post_before = get_post( $translation_id );
		$staged_active_surface_before = maybe_serialize( array( 'post' => $staged_active_post_before instanceof WP_Post ? $staged_active_post_before->to_array() : null, 'meta' => get_post_meta( $translation_id ) ) );
		$staged_active_job_bytes_before = maybe_serialize( get_option( $attempt_limit_job_key ) );
		$staged_active_quality_bytes_before = maybe_serialize( get_option( $staged_commit_quality_key ) );
		$staged_active_header_before = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
		$staged_active_rollback_invalidations_before = count( array_filter( $cache_invalidation_calls, static function ( array $entry ): bool { return 'localized_presentation_rollback' === (string) ( $entry['context']['event'] ?? '' ); } ) );
		$staged_active_adapter_called = false;
		$staged_active_content_called = false;
		$staged_active_receipt = $staged_active_expectation['receipt'];
		$staged_active_commit_adapter = static function ( $default, int $committed_translation_id, string $before_revision, string $replacement_revision ) use ( $translation_id, $staged_active_before_revision, $staged_active_receipt, &$staged_active_adapter_called ) {
			unset( $default );
			if ( $translation_id !== $committed_translation_id || ! hash_equals( $staged_active_before_revision, $before_revision ) || '' === $replacement_revision || hash_equals( $before_revision, $replacement_revision ) ) { throw new RuntimeException( 'Active-transaction staged receipt Adapter received an invalid ownership receipt.' ); }
			$staged_active_adapter_called = true;
			return $staged_active_receipt;
		};
		$staged_active_content_adapter = static function ( $default ) use ( &$staged_active_content_called ) {
			$staged_active_content_called = true;
			return $default;
		};
		add_filter( 'devenia_workflow_staged_artifact_commit_adapter_result', $staged_active_commit_adapter, 10, 4 );
		add_filter( 'devenia_workflow_localized_presentation_commit_adapter_result', $staged_active_content_adapter, 10, 4 );
		try {
			$staged_active_publish = $call( 'translation_job_publish', array( 'job_id' => $job_id, 'coordinator_id' => 'runtime-coordinator', 'sync_menu' => true, 'verify_live' => true ) );
		} finally {
			remove_filter( 'devenia_workflow_staged_artifact_commit_adapter_result', $staged_active_commit_adapter, 10 );
			remove_filter( 'devenia_workflow_localized_presentation_commit_adapter_result', $staged_active_content_adapter, 10 );
		}
		clean_post_cache( $translation_id );
		$staged_active_post_after = get_post( $translation_id );
		$staged_active_surface_after = maybe_serialize( array( 'post' => $staged_active_post_after instanceof WP_Post ? $staged_active_post_after->to_array() : null, 'meta' => get_post_meta( $translation_id ) ) );
		$staged_active_current_revision = (string) $call( 'translation_job_rollback_cas_revision', $translation_id, (array) ( $staged_active_snapshot['term_scope'] ?? array() ), (array) ( $staged_active_snapshot['identity_scope'] ?? array() ) );
		$staged_active_header_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
		$staged_active_rollback_invalidations_after = count( array_filter( $cache_invalidation_calls, static function ( array $entry ): bool { return 'localized_presentation_rollback' === (string) ( $entry['context']['event'] ?? '' ); } ) );
		$staged_active_reconciliation = (array) ( $staged_active_publish['commit_reconciliation'] ?? array() );
		$staged_active_receipt_validation = (array) ( $staged_active_reconciliation['receipt_validation'] ?? array() );
		$staged_active_terminalization = (array) ( $staged_active_receipt_validation['terminalization'] ?? array() );
		$staged_active_transaction_commit = (array) ( $staged_active_publish['transaction_commit'] ?? array() );
		$staged_active_missing_committed_observed = in_array( 'missing_committed', (array) ( $staged_active_receipt_validation['violations'] ?? array() ), true );
		if (
			! $staged_active_adapter_called
			|| $staged_active_content_called
			|| ! empty( $staged_active_publish['success'] )
			|| ! empty( $staged_active_publish['published'] )
			|| 'staged_publication_transaction_commit_receipt_invalid' !== (string) ( $staged_active_publish['code'] ?? '' )
			|| 'critical' !== (string) ( $staged_active_publish['severity'] ?? '' )
			|| false !== ( $staged_active_publish['rollback_authorized'] ?? null )
			|| false !== ( $staged_active_publish['rollback']['attempted'] ?? null )
			|| 'rollback_not_authorized' !== (string) ( $staged_active_publish['rollback']['action'] ?? '' )
			|| 'invalid_receipt' !== (string) ( $staged_active_reconciliation['state_outcome'] ?? '' )
			|| ! empty( $staged_active_receipt_validation['valid'] )
			|| empty( $staged_active_receipt_validation['transaction_still_owned_at_boundary'] )
			|| ! in_array( 'transaction_not_terminal', (array) ( $staged_active_receipt_validation['violations'] ?? array() ), true )
			|| empty( $staged_active_terminalization['success'] )
			|| empty( $staged_active_terminalization['rolled_back'] )
			|| 'transaction_rolled_back' !== (string) ( $staged_active_terminalization['code'] ?? '' )
			|| (bool) $staged_active_expectation['expect_missing_committed'] !== $staged_active_missing_committed_observed
			|| ( '' !== (string) $staged_active_expectation['expect_adapter_receipt_type'] && (string) $staged_active_expectation['expect_adapter_receipt_type'] !== (string) ( $staged_active_transaction_commit['adapter_receipt_type'] ?? '' ) )
			|| ( 'non_array' === $staged_active_mode && 'recovery_commit_adapter_receipt_not_array' !== (string) ( $staged_active_transaction_commit['code'] ?? '' ) )
			|| ( 'claimed_commit_while_active' === $staged_active_mode && $staged_active_receipt !== $staged_active_transaction_commit )
			|| ! hash_equals( $staged_active_before_revision, $staged_active_current_revision )
			|| $staged_active_surface_before !== $staged_active_surface_after
			|| $staged_active_job_bytes_before !== maybe_serialize( get_option( $attempt_limit_job_key ) )
			|| 'ready_to_publish' !== (string) ( get_option( $attempt_limit_job_key )['status'] ?? '' )
			|| $staged_active_quality_bytes_before !== maybe_serialize( get_option( $staged_commit_quality_key ) )
			|| $staged_active_header_before !== $staged_active_header_after
			|| $staged_active_rollback_invalidations_before !== $staged_active_rollback_invalidations_after
		) {
			throw new RuntimeException( 'Present non-array or falsely terminal staged receipt selected default COMMIT, escaped owned terminalization, or advanced publication for ' . $staged_active_mode . ': ' . wp_json_encode( array( 'result' => $staged_active_publish, 'adapter_called' => $staged_active_adapter_called, 'content_called' => $staged_active_content_called, 'surface_preserved' => $staged_active_surface_before === $staged_active_surface_after, 'job_preserved' => $staged_active_job_bytes_before === maybe_serialize( get_option( $attempt_limit_job_key ) ), 'quality_preserved' => $staged_active_quality_bytes_before === maybe_serialize( get_option( $staged_commit_quality_key ) ), 'header_preserved' => $staged_active_header_before === $staged_active_header_after ) ) );
		}
	}
	// A real COMMIT followed by a malformed Adapter receipt must never be
	// mistaken for the legitimate committed=null outcome, even while the exact
	// staged replacement is observable. Publication stops without granting
	// rollback authority; this fixture then restores only the exact revision it
	// just proved so the remaining reconciliation matrix starts cleanly.
	$invalid_staged_snapshot = $call( 'translation_job_capture_surface_snapshot', $translation_id, (array) ( $staged_commit_artifact['surface_manifest'] ?? array() ), $call( 'translation_job_publication_identity_scope', $staged_commit_job_before ) );
	if ( empty( $invalid_staged_snapshot['snapshot_valid'] ) ) { throw new RuntimeException( 'Malformed staged-commit fixture could not capture its exact pre-attempt surface.' ); }
	$invalid_staged_before_revision = (string) ( $invalid_staged_snapshot['captured_cas_revision'] ?? '' );
	$invalid_staged_post_before = get_post( $translation_id );
	$invalid_staged_surface_before = maybe_serialize( array( 'post' => $invalid_staged_post_before instanceof WP_Post ? $invalid_staged_post_before->to_array() : null, 'meta' => get_post_meta( $translation_id ) ) );
	$invalid_staged_job_bytes_before = maybe_serialize( get_option( $attempt_limit_job_key ) );
	$invalid_staged_quality_bytes_before = maybe_serialize( get_option( $staged_commit_quality_key ) );
	$invalid_staged_header_before = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
	$invalid_staged_rollback_invalidations_before = count( array_filter( $cache_invalidation_calls, static function ( array $entry ): bool { return 'localized_presentation_rollback' === (string) ( $entry['context']['event'] ?? '' ); } ) );
	$invalid_staged_adapter_called = false;
	$invalid_staged_content_called = false;
	$invalid_staged_replacement_revision = '';
	$invalid_staged_commit_adapter = static function ( $default, int $committed_translation_id, string $before_revision, string $replacement_revision ) use ( $call, $translation_id, $invalid_staged_before_revision, &$invalid_staged_adapter_called, &$invalid_staged_replacement_revision ): array {
		unset( $default );
		if ( $translation_id !== $committed_translation_id || ! hash_equals( $invalid_staged_before_revision, $before_revision ) || '' === $replacement_revision || hash_equals( $before_revision, $replacement_revision ) ) { throw new RuntimeException( 'Malformed staged-commit Adapter received an invalid ownership receipt.' ); }
		$actual = $call( 'translation_job_commit_recovery_transaction' );
		if ( empty( $actual['success'] ) || ! array_key_exists( 'committed', $actual ) || true !== $actual['committed'] ) { throw new RuntimeException( 'Malformed staged-commit fixture could not establish its real COMMIT.' ); }
		$invalid_staged_adapter_called = true;
		$invalid_staged_replacement_revision = $replacement_revision;
		return array( 'success' => false, 'code' => 'runtime_staged_commit_missing_committed', 'actual' => $actual );
	};
	$invalid_staged_content_adapter = static function ( $default ) use ( &$invalid_staged_content_called ) {
		$invalid_staged_content_called = true;
		return $default;
	};
	add_filter( 'devenia_workflow_staged_artifact_commit_adapter_result', $invalid_staged_commit_adapter, 10, 4 );
	add_filter( 'devenia_workflow_localized_presentation_commit_adapter_result', $invalid_staged_content_adapter, 10, 4 );
	try {
		$invalid_staged_publish = $call( 'translation_job_publish', array( 'job_id' => $job_id, 'coordinator_id' => 'runtime-coordinator', 'sync_menu' => true, 'verify_live' => true ) );
	} finally {
		remove_filter( 'devenia_workflow_staged_artifact_commit_adapter_result', $invalid_staged_commit_adapter, 10 );
		remove_filter( 'devenia_workflow_localized_presentation_commit_adapter_result', $invalid_staged_content_adapter, 10 );
	}
	clean_post_cache( $translation_id );
	$invalid_staged_post_after = get_post( $translation_id );
	$invalid_staged_surface_after = maybe_serialize( array( 'post' => $invalid_staged_post_after instanceof WP_Post ? $invalid_staged_post_after->to_array() : null, 'meta' => get_post_meta( $translation_id ) ) );
	$invalid_staged_current_revision = (string) $call( 'translation_job_rollback_cas_revision', $translation_id, (array) ( $invalid_staged_snapshot['term_scope'] ?? array() ), (array) ( $invalid_staged_snapshot['identity_scope'] ?? array() ) );
	$invalid_staged_header_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
	$invalid_staged_rollback_invalidations_after = count( array_filter( $cache_invalidation_calls, static function ( array $entry ): bool { return 'localized_presentation_rollback' === (string) ( $entry['context']['event'] ?? '' ); } ) );
	$invalid_staged_reconciliation = (array) ( $invalid_staged_publish['commit_reconciliation'] ?? array() );
	$invalid_staged_receipt_validation = (array) ( $invalid_staged_reconciliation['receipt_validation'] ?? array() );
	if (
		! $invalid_staged_adapter_called
		|| $invalid_staged_content_called
		|| ! empty( $invalid_staged_publish['success'] )
		|| 'staged_publication_transaction_commit_receipt_invalid' !== (string) ( $invalid_staged_publish['code'] ?? '' )
		|| 'critical' !== (string) ( $invalid_staged_publish['severity'] ?? '' )
		|| false !== ( $invalid_staged_publish['rollback_authorized'] ?? null )
		|| false !== ( $invalid_staged_publish['rollback']['attempted'] ?? null )
		|| 'rollback_not_authorized' !== (string) ( $invalid_staged_publish['rollback']['action'] ?? '' )
		|| 'invalid_receipt' !== (string) ( $invalid_staged_reconciliation['state_outcome'] ?? '' )
		|| ! empty( $invalid_staged_receipt_validation['valid'] )
		|| ! in_array( 'missing_committed', (array) ( $invalid_staged_receipt_validation['violations'] ?? array() ), true )
		|| array_key_exists( 'committed', (array) ( $invalid_staged_publish['transaction_commit'] ?? array() ) )
		|| ! hash_equals( $invalid_staged_replacement_revision, $invalid_staged_current_revision )
		|| $invalid_staged_surface_before === $invalid_staged_surface_after
		|| $invalid_staged_job_bytes_before !== maybe_serialize( get_option( $attempt_limit_job_key ) )
		|| 'ready_to_publish' !== (string) ( get_option( $attempt_limit_job_key )['status'] ?? '' )
		|| $invalid_staged_quality_bytes_before !== maybe_serialize( get_option( $staged_commit_quality_key ) )
		|| $invalid_staged_header_before !== $invalid_staged_header_after
		|| $invalid_staged_rollback_invalidations_before !== $invalid_staged_rollback_invalidations_after
	) {
		throw new RuntimeException( 'Malformed staged COMMIT receipt was accepted, advanced, or granted rollback authority despite an exact applied replacement: ' . wp_json_encode( array( 'result' => $invalid_staged_publish, 'adapter_called' => $invalid_staged_adapter_called, 'content_called' => $invalid_staged_content_called, 'replacement_revision' => $invalid_staged_replacement_revision, 'current_revision' => $invalid_staged_current_revision, 'surface_changed' => $invalid_staged_surface_before !== $invalid_staged_surface_after, 'job_preserved' => $invalid_staged_job_bytes_before === maybe_serialize( get_option( $attempt_limit_job_key ) ), 'quality_preserved' => $invalid_staged_quality_bytes_before === maybe_serialize( get_option( $staged_commit_quality_key ) ), 'header_preserved' => $invalid_staged_header_before === $invalid_staged_header_after ) ) );
	}
	$invalid_staged_snapshot['mutation_started'] = true;
	$invalid_staged_snapshot['rollback_expected_surface_revision'] = $invalid_staged_current_revision;
	$invalid_staged_fixture_restore = $call( 'translation_job_restore_surface_snapshot', $invalid_staged_snapshot, $translation_id );
	clean_post_cache( $translation_id );
	$invalid_staged_restored_post = get_post( $translation_id );
	$invalid_staged_restored_surface = maybe_serialize( array( 'post' => $invalid_staged_restored_post instanceof WP_Post ? $invalid_staged_restored_post->to_array() : null, 'meta' => get_post_meta( $translation_id ) ) );
	if ( empty( $invalid_staged_fixture_restore['success'] ) || ! hash_equals( $invalid_staged_before_revision, (string) $call( 'translation_job_rollback_cas_revision', $translation_id, (array) ( $invalid_staged_snapshot['term_scope'] ?? array() ), (array) ( $invalid_staged_snapshot['identity_scope'] ?? array() ) ) ) || $invalid_staged_surface_before !== $invalid_staged_restored_surface ) {
		throw new RuntimeException( 'Malformed staged COMMIT fixture could not restore only its exact proved replacement: ' . wp_json_encode( $invalid_staged_fixture_restore ) );
	}
	foreach ( array( 'staged_committed_true_applied' => true, 'staged_committed_unknown_applied' => null ) as $staged_applied_mode => $staged_applied_committed ) {
		$staged_applied_snapshot = $call( 'translation_job_capture_surface_snapshot', $translation_id, (array) ( $staged_commit_artifact['surface_manifest'] ?? array() ), $call( 'translation_job_publication_identity_scope', $staged_commit_job_before ) );
		if ( empty( $staged_applied_snapshot['snapshot_valid'] ) ) { throw new RuntimeException( 'Applied staged-commit fixture could not capture its exact pre-attempt surface.' ); }
		$staged_applied_before_revision = (string) ( $staged_applied_snapshot['captured_cas_revision'] ?? '' );
		$staged_applied_header_before = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
		$staged_applied_job_bytes_before = maybe_serialize( get_option( $attempt_limit_job_key ) );
		$staged_applied_quality_bytes_before = maybe_serialize( get_option( $staged_commit_quality_key ) );
		$staged_applied_rollback_invalidations_before = count( array_filter( $cache_invalidation_calls, static function ( array $entry ): bool { return 'localized_presentation_rollback' === (string) ( $entry['context']['event'] ?? '' ); } ) );
		$staged_applied_adapter_called = false;
		$staged_applied_content_called = false;
		$staged_applied_replacement = '';
		$staged_applied_content_before = '';
		$staged_applied_commit_adapter = static function ( $default, int $committed_translation_id, string $before_revision, string $replacement_revision ) use ( $call, $translation_id, $staged_applied_committed, $staged_applied_mode, $staged_applied_before_revision, &$staged_applied_adapter_called, &$staged_applied_replacement ): array {
			if ( $translation_id !== $committed_translation_id || ! hash_equals( $staged_applied_before_revision, $before_revision ) || '' === $replacement_revision || hash_equals( $before_revision, $replacement_revision ) ) { throw new RuntimeException( 'Applied staged-commit Adapter received an invalid ownership receipt.' ); }
			$actual = $call( 'translation_job_commit_recovery_transaction' );
			if ( empty( $actual['success'] ) || ! array_key_exists( 'committed', $actual ) || true !== $actual['committed'] ) { throw new RuntimeException( 'Applied staged-commit fixture could not establish its real COMMIT.' ); }
			$staged_applied_adapter_called = true;
			$staged_applied_replacement = $replacement_revision;
			return array( 'success' => false, 'committed' => $staged_applied_committed, 'code' => true === $staged_applied_committed ? 'runtime_staged_commit_applied' : 'runtime_staged_commit_unknown_applied', 'mode' => $staged_applied_mode, 'actual' => $actual );
		};
		$staged_applied_content_adapter = static function ( $default, int $committed_translation_id, string $before_revision ) use ( $call, $translation_id, &$staged_applied_content_called, &$staged_applied_content_before ): array {
			if ( $translation_id !== $committed_translation_id ) { throw new RuntimeException( 'Applied staged-commit continuation reached the wrong content identity.' ); }
			$staged_applied_content_called = true;
			$staged_applied_content_before = $before_revision;
			$actual = $call( 'translation_job_rollback_recovery_transaction' );
			if ( empty( $actual['success'] ) || empty( $actual['rolled_back'] ) ) { throw new RuntimeException( 'Applied staged-commit fixture could not establish its controlled later failure.' ); }
			return array( 'success' => false, 'committed' => false, 'code' => 'runtime_content_transition_rolled_back_after_staged_apply', 'actual' => $actual );
		};
		add_filter( 'devenia_workflow_staged_artifact_commit_adapter_result', $staged_applied_commit_adapter, 10, 4 );
		add_filter( 'devenia_workflow_localized_presentation_commit_adapter_result', $staged_applied_content_adapter, 10, 4 );
		try {
			$staged_applied_publish = $call( 'translation_job_publish', array( 'job_id' => $job_id, 'coordinator_id' => 'runtime-coordinator', 'sync_menu' => true, 'verify_live' => true ) );
		} finally {
			remove_filter( 'devenia_workflow_staged_artifact_commit_adapter_result', $staged_applied_commit_adapter, 10 );
			remove_filter( 'devenia_workflow_localized_presentation_commit_adapter_result', $staged_applied_content_adapter, 10 );
		}
		$staged_applied_current_revision = (string) $call( 'translation_job_rollback_cas_revision', $translation_id, (array) ( $staged_applied_snapshot['term_scope'] ?? array() ), (array) ( $staged_applied_snapshot['identity_scope'] ?? array() ) );
		$staged_applied_header_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
		$staged_applied_rollback_invalidations_after = count( array_filter( $cache_invalidation_calls, static function ( array $entry ): bool { return 'localized_presentation_rollback' === (string) ( $entry['context']['event'] ?? '' ); } ) );
		if ( ! $staged_applied_adapter_called || ! $staged_applied_content_called || ! hash_equals( $staged_applied_replacement, $staged_applied_content_before ) || 'publication_transaction_commit_rolled_back' !== (string) ( $staged_applied_publish['code'] ?? '' ) || true !== ( $staged_applied_publish['rollback_authorized'] ?? null ) || empty( $staged_applied_publish['rollback']['success'] ) || 'restore_existing' !== (string) ( $staged_applied_publish['rollback']['action'] ?? '' ) || ! array_key_exists( 'forward_publication_applied', $staged_applied_publish ) || true !== $staged_applied_publish['forward_publication_applied'] || ! array_key_exists( 'published', $staged_applied_publish ) || false !== $staged_applied_publish['published'] || 'restored_verified' !== (string) ( $staged_applied_publish['final_reader_state']['state'] ?? '' ) || ! hash_equals( $staged_applied_before_revision, $staged_applied_current_revision ) || $staged_applied_job_bytes_before !== maybe_serialize( get_option( $attempt_limit_job_key ) ) || 'ready_to_publish' !== (string) ( get_option( $attempt_limit_job_key )['status'] ?? '' ) || $staged_applied_quality_bytes_before !== maybe_serialize( get_option( $staged_commit_quality_key ) ) || $staged_applied_header_before !== $staged_applied_header_after || $staged_applied_rollback_invalidations_before + 1 !== $staged_applied_rollback_invalidations_after ) {
			throw new RuntimeException( 'Applied staged-commit outcome did not continue with exact owned rollback authority for ' . $staged_applied_mode . ': ' . wp_json_encode( array( 'result' => $staged_applied_publish, 'adapter_called' => $staged_applied_adapter_called, 'content_called' => $staged_applied_content_called, 'owned_replacement' => $staged_applied_replacement, 'content_before' => $staged_applied_content_before, 'before_revision' => $staged_applied_before_revision, 'current_revision' => $staged_applied_current_revision, 'job_preserved' => $staged_applied_job_bytes_before === maybe_serialize( get_option( $attempt_limit_job_key ) ), 'quality_preserved' => $staged_applied_quality_bytes_before === maybe_serialize( get_option( $staged_commit_quality_key ) ), 'header_preserved' => $staged_applied_header_before === $staged_applied_header_after, 'rollback_invalidations_before' => $staged_applied_rollback_invalidations_before, 'rollback_invalidations_after' => $staged_applied_rollback_invalidations_after ) ) );
		}
	}
	foreach ( array( 'staged_committed_true_foreign' => true, 'staged_committed_unknown_foreign' => null ) as $staged_foreign_mode => $staged_foreign_committed ) {
		$staged_foreign_snapshot = $call( 'translation_job_capture_surface_snapshot', $translation_id, (array) ( $staged_commit_artifact['surface_manifest'] ?? array() ), $call( 'translation_job_publication_identity_scope', $staged_commit_job_before ) );
		if ( empty( $staged_foreign_snapshot['snapshot_valid'] ) ) { throw new RuntimeException( 'Foreign staged-commit fixture could not capture its exact pre-attempt surface.' ); }
		$staged_foreign_header_before = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
		$staged_foreign_job_bytes_before = maybe_serialize( get_option( $attempt_limit_job_key ) );
		$staged_foreign_quality_bytes_before = maybe_serialize( get_option( $staged_commit_quality_key ) );
		$staged_foreign_rollback_invalidations_before = count( array_filter( $cache_invalidation_calls, static function ( array $entry ): bool { return 'localized_presentation_rollback' === (string) ( $entry['context']['event'] ?? '' ); } ) );
		$staged_foreign_excerpt = 'Foreign staged-apply surface ' . $staged_foreign_mode . ' ' . wp_generate_uuid4();
		$staged_foreign_cached_excerpt = '';
		$staged_foreign_commit_adapter = static function ( $default, int $committed_translation_id ) use ( $call, $staged_foreign_committed, $staged_foreign_mode, $staged_foreign_excerpt, &$staged_foreign_cached_excerpt ): array {
			global $wpdb;
			$actual = $call( 'translation_job_commit_recovery_transaction' );
			if ( empty( $actual['success'] ) || ! array_key_exists( 'committed', $actual ) || true !== $actual['committed'] ) { throw new RuntimeException( 'Foreign staged-commit fixture could not establish its real COMMIT.' ); }
			$cached = get_post( $committed_translation_id );
			$staged_foreign_cached_excerpt = $cached instanceof WP_Post ? (string) $cached->post_excerpt : '';
			$updated = $wpdb->update( $wpdb->posts, array( 'post_excerpt' => $staged_foreign_excerpt ), array( 'ID' => $committed_translation_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deliberately bypass cache to prove post-COMMIT reconciliation evicts the stale owned replacement before reading a concurrent foreign state.
			if ( 1 !== $updated ) { throw new RuntimeException( 'Foreign staged-commit fixture could not write its uncached concurrent state.' ); }
			return array( 'success' => false, 'committed' => $staged_foreign_committed, 'code' => true === $staged_foreign_committed ? 'runtime_staged_commit_applied_then_foreign' : 'runtime_staged_commit_unknown_then_foreign', 'mode' => $staged_foreign_mode, 'actual' => $actual );
		};
		add_filter( 'devenia_workflow_staged_artifact_commit_adapter_result', $staged_foreign_commit_adapter, 10, 4 );
		try {
			$staged_foreign_publish = $call( 'translation_job_publish', array( 'job_id' => $job_id, 'coordinator_id' => 'runtime-coordinator', 'sync_menu' => true, 'verify_live' => true ) );
		} finally {
			remove_filter( 'devenia_workflow_staged_artifact_commit_adapter_result', $staged_foreign_commit_adapter, 10 );
		}
		$staged_foreign_post = get_post( $translation_id );
		$staged_foreign_current_revision = (string) $call( 'translation_job_rollback_cas_revision', $translation_id, (array) ( $staged_foreign_snapshot['term_scope'] ?? array() ), (array) ( $staged_foreign_snapshot['identity_scope'] ?? array() ) );
		$staged_foreign_header_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
		$staged_foreign_rollback_invalidations_after = count( array_filter( $cache_invalidation_calls, static function ( array $entry ): bool { return 'localized_presentation_rollback' === (string) ( $entry['context']['event'] ?? '' ); } ) );
		if ( 'staged_publication_transaction_commit_reconciliation_conflict' !== (string) ( $staged_foreign_publish['code'] ?? '' ) || 'critical' !== (string) ( $staged_foreign_publish['severity'] ?? '' ) || false !== ( $staged_foreign_publish['rollback_authorized'] ?? null ) || false !== ( $staged_foreign_publish['rollback']['attempted'] ?? null ) || 'rollback_not_authorized' !== (string) ( $staged_foreign_publish['rollback']['action'] ?? '' ) || 'foreign_surface_not_owned' !== (string) ( $staged_foreign_publish['rollback']['error'] ?? '' ) || '' !== (string) ( $staged_foreign_publish['mutation_cas_revision'] ?? '' ) || $staged_foreign_current_revision !== (string) ( $staged_foreign_publish['observed_mutation_cas_revision'] ?? '' ) || 'foreign' !== (string) ( $staged_foreign_publish['commit_reconciliation']['state_outcome'] ?? '' ) || ! $staged_foreign_post instanceof WP_Post || $staged_foreign_excerpt !== (string) $staged_foreign_post->post_excerpt || $staged_foreign_excerpt === $staged_foreign_cached_excerpt || $staged_foreign_job_bytes_before !== maybe_serialize( get_option( $attempt_limit_job_key ) ) || 'ready_to_publish' !== (string) ( get_option( $attempt_limit_job_key )['status'] ?? '' ) || $staged_foreign_quality_bytes_before !== maybe_serialize( get_option( $staged_commit_quality_key ) ) || $staged_foreign_header_before !== $staged_foreign_header_after || $staged_foreign_rollback_invalidations_before !== $staged_foreign_rollback_invalidations_after ) {
			throw new RuntimeException( 'Foreign staged-commit outcome was overwritten or promoted to rollback authority for ' . $staged_foreign_mode . ': ' . wp_json_encode( array( 'result' => $staged_foreign_publish, 'foreign_revision' => $staged_foreign_current_revision, 'foreign_excerpt' => $staged_foreign_excerpt, 'cached_excerpt_before_direct_write' => $staged_foreign_cached_excerpt, 'current_excerpt' => $staged_foreign_post instanceof WP_Post ? $staged_foreign_post->post_excerpt : null, 'job_preserved' => $staged_foreign_job_bytes_before === maybe_serialize( get_option( $attempt_limit_job_key ) ), 'quality_preserved' => $staged_foreign_quality_bytes_before === maybe_serialize( get_option( $staged_commit_quality_key ) ), 'header_preserved' => $staged_foreign_header_before === $staged_foreign_header_after, 'rollback_invalidations_before' => $staged_foreign_rollback_invalidations_before, 'rollback_invalidations_after' => $staged_foreign_rollback_invalidations_after ) ) );
		}
		$staged_foreign_snapshot['mutation_started'] = true;
		$staged_foreign_snapshot['rollback_expected_surface_revision'] = $staged_foreign_current_revision;
		$staged_foreign_fixture_restore = $call( 'translation_job_restore_surface_snapshot', $staged_foreign_snapshot, $translation_id );
		if ( empty( $staged_foreign_fixture_restore['success'] ) || (string) ( $staged_foreign_snapshot['captured_cas_revision'] ?? '' ) !== (string) $call( 'translation_job_rollback_cas_revision', $translation_id, (array) ( $staged_foreign_snapshot['term_scope'] ?? array() ), (array) ( $staged_foreign_snapshot['identity_scope'] ?? array() ) ) ) { throw new RuntimeException( 'Foreign staged-commit fixture cleanup failed for ' . $staged_foreign_mode . ': ' . wp_json_encode( $staged_foreign_fixture_restore ) ); }
	}
	// The Localized Presentation Publication Module owns rollback authority.
	// Exercise the complete Translation Job caller twice: a real COMMIT followed
	// by a foreign write must remain diagnostic-only for both true and unknown
	// commit receipts, with no caller rollback or header mutation.
	$foreign_publish_job_before = get_option( $attempt_limit_job_key );
	$foreign_publish_artifact = $call( 'translation_job_unpack_artifact_record', get_option( 'devenia_workflow_translation_artifact_' . (string) ( $foreign_publish_job_before['artifact_revision'] ?? '' ) ) );
	foreach ( array( 'committed_true_foreign' => true, 'committed_unknown_foreign' => null ) as $foreign_publish_mode => $foreign_committed ) {
		$foreign_fixture_snapshot = $call( 'translation_job_capture_surface_snapshot', $translation_id, (array) ( $foreign_publish_artifact['surface_manifest'] ?? array() ), $call( 'translation_job_publication_identity_scope', $foreign_publish_job_before ) );
		if ( empty( $foreign_fixture_snapshot['snapshot_valid'] ) ) { throw new RuntimeException( 'Foreign publication caller fixture could not capture its exact pre-attempt surface.' ); }
		$foreign_header_before = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
		$foreign_job_bytes_before = maybe_serialize( get_option( $attempt_limit_job_key ) );
		$foreign_revision = ''; $foreign_surface_bytes = '';
		$rollback_invalidation_before = count( array_filter( $cache_invalidation_calls, static function ( array $entry ): bool { return 'localized_presentation_rollback' === (string) ( $entry['context']['event'] ?? '' ); } ) );
		$foreign_commit_adapter = static function ( $default, int $committed_translation_id ) use ( $call, $foreign_committed, $foreign_publish_mode, $foreign_fixture_snapshot, &$foreign_revision, &$foreign_surface_bytes ) {
			$actual = $call( 'translation_job_commit_recovery_transaction' );
			if ( empty( $actual['success'] ) || ! array_key_exists( 'committed', $actual ) || true !== $actual['committed'] ) { throw new RuntimeException( 'Foreign publication caller fixture could not establish its real COMMIT.' ); }
			$foreign_excerpt = 'Foreign caller-owned surface ' . $foreign_publish_mode . ' ' . wp_generate_uuid4();
			$updated = wp_update_post( array( 'ID' => $committed_translation_id, 'post_excerpt' => $foreign_excerpt ), true );
			if ( is_wp_error( $updated ) || $committed_translation_id !== absint( $updated ) ) { throw new RuntimeException( 'Foreign publication caller fixture could not write the concurrent surface.' ); }
			clean_post_cache( $committed_translation_id );
			$foreign_revision = (string) $call( 'translation_job_rollback_cas_revision', $committed_translation_id, (array) ( $foreign_fixture_snapshot['term_scope'] ?? array() ), (array) ( $foreign_fixture_snapshot['identity_scope'] ?? array() ) );
			$foreign_post = get_post( $committed_translation_id );
			$foreign_surface_bytes = maybe_serialize( array( 'post' => $foreign_post instanceof WP_Post ? $foreign_post->to_array() : null, 'meta' => get_post_meta( $committed_translation_id ) ) );
			return array( 'success' => false, 'committed' => $foreign_committed, 'code' => true === $foreign_committed ? 'runtime_commit_applied_then_foreign' : 'runtime_commit_unknown_then_foreign', 'actual' => $actual );
		};
		add_filter( 'devenia_workflow_localized_presentation_commit_adapter_result', $foreign_commit_adapter, 10, 4 );
		try {
			$foreign_publish = $call( 'translation_job_publish', array( 'job_id' => $job_id, 'coordinator_id' => 'runtime-coordinator', 'sync_menu' => true, 'verify_live' => true ) );
		} finally {
			remove_filter( 'devenia_workflow_localized_presentation_commit_adapter_result', $foreign_commit_adapter, 10 );
		}
		clean_post_cache( $translation_id );
		$foreign_surface_after_bytes = maybe_serialize( array( 'post' => ( get_post( $translation_id ) instanceof WP_Post ? get_post( $translation_id )->to_array() : null ), 'meta' => get_post_meta( $translation_id ) ) );
		$foreign_current_revision = (string) $call( 'translation_job_rollback_cas_revision', $translation_id, (array) ( $foreign_fixture_snapshot['term_scope'] ?? array() ), (array) ( $foreign_fixture_snapshot['identity_scope'] ?? array() ) );
		$foreign_header_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
		$rollback_invalidation_after = count( array_filter( $cache_invalidation_calls, static function ( array $entry ): bool { return 'localized_presentation_rollback' === (string) ( $entry['context']['event'] ?? '' ); } ) );
		if ( 'publication_transaction_commit_reconciliation_conflict' !== (string) ( $foreign_publish['code'] ?? '' ) || 'critical' !== (string) ( $foreign_publish['severity'] ?? '' ) || false !== ( $foreign_publish['rollback_authorized'] ?? null ) || ! isset( $foreign_publish['rollback'] ) || false !== ( $foreign_publish['rollback']['attempted'] ?? null ) || 'rollback_not_authorized' !== (string) ( $foreign_publish['rollback']['action'] ?? '' ) || 'foreign_surface_not_owned' !== (string) ( $foreign_publish['rollback']['error'] ?? '' ) || ! array_key_exists( 'published', $foreign_publish ) || null !== $foreign_publish['published'] || 'foreign' !== (string) ( $foreign_publish['final_reader_state']['state'] ?? '' ) || '' !== (string) ( $foreign_publish['mutation_cas_revision'] ?? '' ) || $foreign_revision !== (string) ( $foreign_publish['observed_mutation_cas_revision'] ?? '' ) || $foreign_revision !== $foreign_current_revision || $foreign_surface_bytes !== $foreign_surface_after_bytes || $foreign_job_bytes_before !== maybe_serialize( get_option( $attempt_limit_job_key ) ) || 'ready_to_publish' !== (string) ( get_option( $attempt_limit_job_key )['status'] ?? '' ) || $foreign_header_before !== $foreign_header_after || $rollback_invalidation_before !== $rollback_invalidation_after ) {
			throw new RuntimeException( 'Translation Job caller promoted a foreign diagnostic revision to rollback authority for ' . $foreign_publish_mode . ': ' . wp_json_encode( array( 'result' => $foreign_publish, 'foreign_revision' => $foreign_revision, 'current_revision' => $foreign_current_revision, 'surface_bytes_preserved' => $foreign_surface_bytes === $foreign_surface_after_bytes, 'job_preserved' => $foreign_job_bytes_before === maybe_serialize( get_option( $attempt_limit_job_key ) ), 'header_preserved' => $foreign_header_before === $foreign_header_after, 'rollback_invalidations_before' => $rollback_invalidation_before, 'rollback_invalidations_after' => $rollback_invalidation_after ) ) );
		}
		$foreign_fixture_snapshot['mutation_started'] = true;
		$foreign_fixture_snapshot['rollback_expected_surface_revision'] = $foreign_revision;
		$foreign_fixture_restore = $call( 'translation_job_restore_surface_snapshot', $foreign_fixture_snapshot, $translation_id );
		if ( empty( $foreign_fixture_restore['success'] ) || (string) ( $foreign_fixture_snapshot['captured_cas_revision'] ?? '' ) !== (string) $call( 'translation_job_rollback_cas_revision', $translation_id, (array) ( $foreign_fixture_snapshot['term_scope'] ?? array() ), (array) ( $foreign_fixture_snapshot['identity_scope'] ?? array() ) ) ) { throw new RuntimeException( 'Foreign publication caller fixture cleanup failed for ' . $foreign_publish_mode . ': ' . wp_json_encode( $foreign_fixture_restore ) ); }
	}

	// restore_commit_applied_true_and_unknown_public_publish:
	// A public Translation Job failure must treat an exact applied restore as
	// owned for both true and unknown receipts, then purge and verify it.
	// restore_commit_foreign_true_and_unknown_public_publish:
	// A foreign post-COMMIT writer remains byte-exact and never receives a
	// rollback invalidation or a false successful-restore claim.
	$restore_commit_modes = array(
		'applied_true'    => array( 'committed' => true, 'foreign' => false ),
		'applied_unknown' => array( 'committed' => null, 'foreign' => false ),
		'foreign_true'    => array( 'committed' => true, 'foreign' => true ),
		'foreign_unknown' => array( 'committed' => null, 'foreign' => true ),
	);
	foreach ( $restore_commit_modes as $restore_commit_mode => $restore_expectation ) {
		$restore_job_before = get_option( $attempt_limit_job_key );
		$restore_artifact = $call( 'translation_job_unpack_artifact_record', get_option( 'devenia_workflow_translation_artifact_' . (string) ( $restore_job_before['artifact_revision'] ?? '' ) ) );
		$restore_fixture_snapshot = $call( 'translation_job_capture_surface_snapshot', $translation_id, (array) ( $restore_artifact['surface_manifest'] ?? array() ), $call( 'translation_job_publication_identity_scope', $restore_job_before ) );
		if ( empty( $restore_fixture_snapshot['snapshot_valid'] ) ) { throw new RuntimeException( 'Restore commit fixture could not capture its exact pre-attempt surface for ' . $restore_commit_mode . '.' ); }
		$restore_post_before = get_post( $translation_id );
		$restore_surface_before = maybe_serialize( array( 'post' => $restore_post_before instanceof WP_Post ? $restore_post_before->to_array() : null, 'meta' => get_post_meta( $translation_id ) ) );
		$restore_job_bytes_before = maybe_serialize( $restore_job_before );
		$restore_header_before = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
		$restore_rollback_invalidations_before = count( array_filter( $cache_invalidation_calls, static function ( array $entry ): bool { return 'localized_presentation_rollback' === (string) ( $entry['context']['event'] ?? '' ); } ) );
		$restore_foreign_revision = '';
		$restore_foreign_surface = '';
		$restore_commit_adapter = static function ( $default, array $snapshot, int $restored_translation_id ) use ( $call, $restore_commit_mode, $restore_expectation, &$restore_foreign_revision, &$restore_foreign_surface ) {
			unset( $default );
			$actual = $call( 'translation_job_commit_recovery_transaction' );
			if ( empty( $actual['success'] ) || ! array_key_exists( 'committed', $actual ) || true !== $actual['committed'] ) { throw new RuntimeException( 'Restore commit fixture could not establish a real COMMIT for ' . $restore_commit_mode . '.' ); }
			if ( ! empty( $restore_expectation['foreign'] ) ) {
				$foreign_excerpt = 'Foreign restore surface ' . $restore_commit_mode . ' ' . wp_generate_uuid4();
				$updated = wp_update_post( array( 'ID' => $restored_translation_id, 'post_excerpt' => $foreign_excerpt ), true );
				if ( is_wp_error( $updated ) || $restored_translation_id !== absint( $updated ) ) { throw new RuntimeException( 'Restore commit fixture could not write its foreign surface.' ); }
				clean_post_cache( $restored_translation_id );
				$restore_foreign_revision = (string) $call( 'translation_job_rollback_cas_revision', $restored_translation_id, (array) ( $snapshot['term_scope'] ?? array() ), (array) ( $snapshot['identity_scope'] ?? array() ) );
				$foreign_post = get_post( $restored_translation_id );
				$restore_foreign_surface = maybe_serialize( array( 'post' => $foreign_post instanceof WP_Post ? $foreign_post->to_array() : null, 'meta' => get_post_meta( $restored_translation_id ) ) );
			}
			return array( 'success' => true === $restore_expectation['committed'], 'committed' => $restore_expectation['committed'], 'code' => 'runtime_restore_' . $restore_commit_mode, 'actual' => $actual );
		};
		$force_publication_failure = static function ( $result, array $urls, array $context ) {
			unset( $urls );
			return 'localized_presentation_publication' === (string) ( $context['event'] ?? '' )
				? array( 'success' => false, 'code' => 'runtime_forced_publication_failure_for_restore' )
				: $result;
		};
		add_filter( 'devenia_workflow_translation_job_restore_commit_adapter_result', $restore_commit_adapter, 10, 4 );
		add_filter( 'devenia_workflow_frontend_cache_invalidation_result', $force_publication_failure, 20, 3 );
		try {
			$restore_publish = $call( 'translation_job_publish', array( 'job_id' => $job_id, 'coordinator_id' => 'runtime-coordinator', 'sync_menu' => false, 'verify_live' => true ) );
		} finally {
			remove_filter( 'devenia_workflow_translation_job_restore_commit_adapter_result', $restore_commit_adapter, 10 );
			remove_filter( 'devenia_workflow_frontend_cache_invalidation_result', $force_publication_failure, 20 );
		}
		clean_post_cache( $translation_id );
		$restore_post_after = get_post( $translation_id );
		$restore_surface_after = maybe_serialize( array( 'post' => $restore_post_after instanceof WP_Post ? $restore_post_after->to_array() : null, 'meta' => get_post_meta( $translation_id ) ) );
		$restore_current_revision = (string) $call( 'translation_job_rollback_cas_revision', $translation_id, (array) ( $restore_fixture_snapshot['term_scope'] ?? array() ), (array) ( $restore_fixture_snapshot['identity_scope'] ?? array() ) );
		$restore_header_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
		$restore_rollback_invalidations_after = count( array_filter( $cache_invalidation_calls, static function ( array $entry ): bool { return 'localized_presentation_rollback' === (string) ( $entry['context']['event'] ?? '' ); } ) );
		$restore_result = (array) ( $restore_publish['rollback'] ?? array() );
		$restore_commit_reconciliation = (array) ( $restore_result['commit_reconciliation'] ?? array() );
		if ( empty( $restore_expectation['foreign'] ) ) {
			if ( 'runtime_forced_publication_failure_for_restore' !== (string) ( $restore_publish['code'] ?? '' ) || empty( $restore_result['success'] ) || 'applied' !== (string) ( $restore_result['commit_reconciliation']['state_outcome'] ?? '' ) || ! array_key_exists( 'committed', $restore_commit_reconciliation ) || $restore_expectation['committed'] !== $restore_commit_reconciliation['committed'] || ! array_key_exists( 'forward_publication_applied', $restore_publish ) || true !== $restore_publish['forward_publication_applied'] || ! array_key_exists( 'published', $restore_publish ) || false !== $restore_publish['published'] || 'restored_verified' !== (string) ( $restore_publish['final_reader_state']['state'] ?? '' ) || empty( $restore_result['cache_invalidation']['success'] ) || empty( $restore_result['media_verification']['success'] ) || ! isset( $restore_result['media_verification']['responses']['origin'], $restore_result['media_verification']['responses']['canonical'] ) || $restore_surface_before !== $restore_surface_after || (string) ( $restore_fixture_snapshot['captured_cas_revision'] ?? '' ) !== $restore_current_revision || $restore_job_bytes_before !== maybe_serialize( get_option( $attempt_limit_job_key ) ) || 'ready_to_publish' !== (string) ( get_option( $attempt_limit_job_key )['status'] ?? '' ) || $restore_header_before !== $restore_header_after || $restore_rollback_invalidations_before + 1 !== $restore_rollback_invalidations_after ) {
				throw new RuntimeException( 'Applied restore commit was not reconciled, invalidated, and verified for ' . $restore_commit_mode . ': ' . wp_json_encode( array( 'result' => $restore_publish, 'surface_restored' => $restore_surface_before === $restore_surface_after, 'job_preserved' => $restore_job_bytes_before === maybe_serialize( get_option( $attempt_limit_job_key ) ), 'header_preserved' => $restore_header_before === $restore_header_after, 'rollback_invalidations_before' => $restore_rollback_invalidations_before, 'rollback_invalidations_after' => $restore_rollback_invalidations_after ) ) );
			}
		} else {
			if ( 'publication_rollback_failed' !== (string) ( $restore_publish['code'] ?? '' ) || ! empty( $restore_result['success'] ) || 'critical' !== (string) ( $restore_result['severity'] ?? '' ) || 'recovery_transaction_commit_reconciliation_conflict' !== (string) ( $restore_result['error'] ?? '' ) || 'foreign' !== (string) ( $restore_result['commit_reconciliation']['state_outcome'] ?? '' ) || ! array_key_exists( 'committed', $restore_commit_reconciliation ) || $restore_expectation['committed'] !== $restore_commit_reconciliation['committed'] || ! array_key_exists( 'published', $restore_publish ) || null !== $restore_publish['published'] || 'foreign' !== (string) ( $restore_publish['final_reader_state']['state'] ?? '' ) || $restore_foreign_revision !== $restore_current_revision || $restore_foreign_surface !== $restore_surface_after || $restore_job_bytes_before !== maybe_serialize( get_option( $attempt_limit_job_key ) ) || 'ready_to_publish' !== (string) ( get_option( $attempt_limit_job_key )['status'] ?? '' ) || $restore_header_before !== $restore_header_after || $restore_rollback_invalidations_before !== $restore_rollback_invalidations_after ) {
				throw new RuntimeException( 'Foreign restore commit state was not preserved and rejected for ' . $restore_commit_mode . ': ' . wp_json_encode( array( 'result' => $restore_publish, 'foreign_revision' => $restore_foreign_revision, 'current_revision' => $restore_current_revision, 'foreign_surface_preserved' => $restore_foreign_surface === $restore_surface_after, 'job_preserved' => $restore_job_bytes_before === maybe_serialize( get_option( $attempt_limit_job_key ) ), 'header_preserved' => $restore_header_before === $restore_header_after ) ) );
			}
			$restore_fixture_snapshot['mutation_started'] = true;
			$restore_fixture_snapshot['rollback_expected_surface_revision'] = $restore_foreign_revision;
			$restore_fixture_cleanup = $call( 'translation_job_restore_surface_snapshot', $restore_fixture_snapshot, $translation_id );
			if ( empty( $restore_fixture_cleanup['success'] ) || (string) ( $restore_fixture_snapshot['captured_cas_revision'] ?? '' ) !== (string) $call( 'translation_job_rollback_cas_revision', $translation_id, (array) ( $restore_fixture_snapshot['term_scope'] ?? array() ), (array) ( $restore_fixture_snapshot['identity_scope'] ?? array() ) ) ) { throw new RuntimeException( 'Foreign restore commit fixture cleanup failed for ' . $restore_commit_mode . ': ' . wp_json_encode( $restore_fixture_cleanup ) ); }
		}
	}
	// A structurally valid success/committed receipt is still invalid while the
	// snapshot transaction is active. It must be rolled back by the receipt
	// boundary and never expose a usable snapshot to publication.
	$active_snapshot_job_bytes_before = maybe_serialize( get_option( $attempt_limit_job_key ) );
	$active_snapshot_quality_bytes_before = maybe_serialize( get_option( $staged_commit_quality_key ) );
	$active_snapshot_post_before = get_post( $translation_id );
	$active_snapshot_surface_before = maybe_serialize( array( 'post' => $active_snapshot_post_before instanceof WP_Post ? $active_snapshot_post_before->to_array() : null, 'meta' => get_post_meta( $translation_id ) ) );
	$active_snapshot_header_before = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
	$active_snapshot_receipt = array( 'success' => true, 'committed' => true, 'code' => 'runtime_snapshot_claimed_commit_while_transaction_active' );
	$active_snapshot_adapter_called = false;
	$active_snapshot_commit_adapter = static function ( $default, array $snapshot, int $snapshot_translation_id ) use ( $translation_id, $active_snapshot_receipt, &$active_snapshot_adapter_called ): array {
		unset( $default );
		if ( $translation_id !== $snapshot_translation_id || empty( $snapshot['snapshot_valid'] ) || '' === (string) ( $snapshot['captured_cas_revision'] ?? '' ) ) { throw new RuntimeException( 'Active snapshot receipt Adapter did not receive an exact captured snapshot.' ); }
		$active_snapshot_adapter_called = true;
		return $active_snapshot_receipt;
	};
	add_filter( 'devenia_workflow_translation_job_snapshot_commit_adapter_result', $active_snapshot_commit_adapter, 10, 3 );
	try {
		$active_snapshot_result = $call( 'translation_job_capture_surface_snapshot', $translation_id, (array) ( $staged_commit_artifact['surface_manifest'] ?? array() ), $call( 'translation_job_publication_identity_scope', get_option( $attempt_limit_job_key ) ) );
	} finally {
		remove_filter( 'devenia_workflow_translation_job_snapshot_commit_adapter_result', $active_snapshot_commit_adapter, 10 );
	}
	clean_post_cache( $translation_id );
	$active_snapshot_post_after = get_post( $translation_id );
	$active_snapshot_surface_after = maybe_serialize( array( 'post' => $active_snapshot_post_after instanceof WP_Post ? $active_snapshot_post_after->to_array() : null, 'meta' => get_post_meta( $translation_id ) ) );
	$active_snapshot_header_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
	$active_snapshot_reconciliation = (array) ( $active_snapshot_result['commit_reconciliation'] ?? array() );
	$active_snapshot_validation = (array) ( $active_snapshot_reconciliation['receipt_validation'] ?? array() );
	$active_snapshot_terminalization = (array) ( $active_snapshot_validation['terminalization'] ?? array() );
	if (
		! $active_snapshot_adapter_called
		|| ! empty( $active_snapshot_result['snapshot_valid'] )
		|| 'publication_snapshot_commit_receipt_invalid' !== (string) ( $active_snapshot_result['message'] ?? '' )
		|| 'critical' !== (string) ( $active_snapshot_result['severity'] ?? '' )
		|| 'invalid_receipt' !== (string) ( $active_snapshot_reconciliation['state_outcome'] ?? '' )
		|| empty( $active_snapshot_reconciliation['exact'] )
		|| $active_snapshot_receipt !== (array) ( $active_snapshot_result['transaction_commit'] ?? array() )
		|| ! empty( $active_snapshot_validation['valid'] )
		|| empty( $active_snapshot_validation['transaction_still_owned_at_boundary'] )
		|| array( 'transaction_not_terminal' ) !== array_values( (array) ( $active_snapshot_validation['violations'] ?? array() ) )
		|| empty( $active_snapshot_terminalization['success'] )
		|| empty( $active_snapshot_terminalization['rolled_back'] )
		|| 'transaction_rolled_back' !== (string) ( $active_snapshot_terminalization['code'] ?? '' )
		|| $active_snapshot_surface_before !== $active_snapshot_surface_after
		|| $active_snapshot_job_bytes_before !== maybe_serialize( get_option( $attempt_limit_job_key ) )
		|| $active_snapshot_quality_bytes_before !== maybe_serialize( get_option( $staged_commit_quality_key ) )
		|| $active_snapshot_header_before !== $active_snapshot_header_after
	) {
		throw new RuntimeException( 'A falsely terminal success receipt escaped owned rollback or exposed a usable publication snapshot: ' . wp_json_encode( array( 'result' => $active_snapshot_result, 'adapter_called' => $active_snapshot_adapter_called, 'surface_preserved' => $active_snapshot_surface_before === $active_snapshot_surface_after, 'job_preserved' => $active_snapshot_job_bytes_before === maybe_serialize( get_option( $attempt_limit_job_key ) ), 'quality_preserved' => $active_snapshot_quality_bytes_before === maybe_serialize( get_option( $staged_commit_quality_key ) ), 'header_preserved' => $active_snapshot_header_before === $active_snapshot_header_after ) ) );
	}

	// The restore boundary must likewise roll back its own uncommitted restore
	// when an Adapter merely claims success. The deliberately drifted fixture
	// must remain exact until a later unfiltered, valid restore cleans it up.
	$active_restore_job_bytes_before = maybe_serialize( get_option( $attempt_limit_job_key ) );
	$active_restore_quality_bytes_before = maybe_serialize( get_option( $staged_commit_quality_key ) );
	$active_restore_header_before = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
	$active_restore_snapshot = $call( 'translation_job_capture_surface_snapshot', $translation_id, (array) ( $staged_commit_artifact['surface_manifest'] ?? array() ), $call( 'translation_job_publication_identity_scope', get_option( $attempt_limit_job_key ) ) );
	if ( empty( $active_restore_snapshot['snapshot_valid'] ) ) { throw new RuntimeException( 'Active restore receipt fixture could not capture its exact original surface.' ); }
	$active_restore_original_post = get_post( $translation_id );
	$active_restore_original_surface = maybe_serialize( array( 'post' => $active_restore_original_post instanceof WP_Post ? $active_restore_original_post->to_array() : null, 'meta' => get_post_meta( $translation_id ) ) );
	$active_restore_drift_excerpt = 'Runtime active restore receipt drift ' . wp_generate_uuid4();
	$active_restore_drift_update = wp_update_post( array( 'ID' => $translation_id, 'post_excerpt' => $active_restore_drift_excerpt ), true );
	if ( is_wp_error( $active_restore_drift_update ) || $translation_id !== absint( $active_restore_drift_update ) ) { throw new RuntimeException( 'Active restore receipt fixture could not create its controlled pre-restore drift.' ); }
	clean_post_cache( $translation_id );
	$active_restore_drift_post = get_post( $translation_id );
	$active_restore_drift_surface = maybe_serialize( array( 'post' => $active_restore_drift_post instanceof WP_Post ? $active_restore_drift_post->to_array() : null, 'meta' => get_post_meta( $translation_id ) ) );
	$active_restore_drift_revision = (string) $call( 'translation_job_rollback_cas_revision', $translation_id, (array) ( $active_restore_snapshot['term_scope'] ?? array() ), (array) ( $active_restore_snapshot['identity_scope'] ?? array() ) );
	if ( '' === $active_restore_drift_revision || hash_equals( (string) ( $active_restore_snapshot['captured_cas_revision'] ?? '' ), $active_restore_drift_revision ) || $active_restore_original_surface === $active_restore_drift_surface ) { throw new RuntimeException( 'Active restore receipt fixture did not establish a distinct controlled drift.' ); }
	$active_restore_snapshot['mutation_started'] = true;
	$active_restore_snapshot['rollback_expected_surface_revision'] = $active_restore_drift_revision;
	$active_restore_receipt = array( 'success' => true, 'committed' => true, 'code' => 'runtime_restore_claimed_commit_while_transaction_active' );
	$active_restore_adapter_called = false;
	$active_restore_commit_adapter = static function ( $default, array $snapshot, int $restored_translation_id, array $result ) use ( $translation_id, $active_restore_receipt, &$active_restore_adapter_called ): array {
		unset( $default );
		if ( $translation_id !== $restored_translation_id || empty( $result['success'] ) || '' === (string) ( $snapshot['captured_cas_revision'] ?? '' ) ) { throw new RuntimeException( 'Active restore receipt Adapter did not receive an exact uncommitted restore.' ); }
		$active_restore_adapter_called = true;
		return $active_restore_receipt;
	};
	add_filter( 'devenia_workflow_translation_job_restore_commit_adapter_result', $active_restore_commit_adapter, 10, 4 );
	try {
		$active_restore_result = $call( 'translation_job_restore_surface_snapshot', $active_restore_snapshot, $translation_id );
	} finally {
		remove_filter( 'devenia_workflow_translation_job_restore_commit_adapter_result', $active_restore_commit_adapter, 10 );
	}
	clean_post_cache( $translation_id );
	$active_restore_post_after = get_post( $translation_id );
	$active_restore_surface_after = maybe_serialize( array( 'post' => $active_restore_post_after instanceof WP_Post ? $active_restore_post_after->to_array() : null, 'meta' => get_post_meta( $translation_id ) ) );
	$active_restore_current_revision = (string) $call( 'translation_job_rollback_cas_revision', $translation_id, (array) ( $active_restore_snapshot['term_scope'] ?? array() ), (array) ( $active_restore_snapshot['identity_scope'] ?? array() ) );
	$active_restore_reconciliation = (array) ( $active_restore_result['commit_reconciliation'] ?? array() );
	$active_restore_validation = (array) ( $active_restore_reconciliation['receipt_validation'] ?? array() );
	$active_restore_terminalization = (array) ( $active_restore_validation['terminalization'] ?? array() );
	$active_restore_header_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
	if (
		! $active_restore_adapter_called
		|| ! empty( $active_restore_result['success'] )
		|| 'rollback_conflict' !== (string) ( $active_restore_result['action'] ?? '' )
		|| 'recovery_transaction_commit_receipt_invalid' !== (string) ( $active_restore_result['error'] ?? '' )
		|| 'critical' !== (string) ( $active_restore_result['severity'] ?? '' )
		|| 'invalid_receipt' !== (string) ( $active_restore_reconciliation['state_outcome'] ?? '' )
		|| empty( $active_restore_reconciliation['pre_restore_exact'] )
		|| ! empty( $active_restore_reconciliation['restored_exact'] )
		|| $active_restore_receipt !== (array) ( $active_restore_result['transaction_commit'] ?? array() )
		|| ! empty( $active_restore_validation['valid'] )
		|| empty( $active_restore_validation['transaction_still_owned_at_boundary'] )
		|| array( 'transaction_not_terminal' ) !== array_values( (array) ( $active_restore_validation['violations'] ?? array() ) )
		|| empty( $active_restore_terminalization['success'] )
		|| empty( $active_restore_terminalization['rolled_back'] )
		|| 'transaction_rolled_back' !== (string) ( $active_restore_terminalization['code'] ?? '' )
		|| ! hash_equals( $active_restore_drift_revision, $active_restore_current_revision )
		|| $active_restore_drift_surface !== $active_restore_surface_after
		|| $active_restore_job_bytes_before !== maybe_serialize( get_option( $attempt_limit_job_key ) )
		|| $active_restore_quality_bytes_before !== maybe_serialize( get_option( $staged_commit_quality_key ) )
		|| $active_restore_header_before !== $active_restore_header_after
	) {
		throw new RuntimeException( 'A falsely terminal success receipt escaped owned rollback or advanced an uncommitted surface restore: ' . wp_json_encode( array( 'result' => $active_restore_result, 'adapter_called' => $active_restore_adapter_called, 'drift_revision' => $active_restore_drift_revision, 'current_revision' => $active_restore_current_revision, 'drift_preserved' => $active_restore_drift_surface === $active_restore_surface_after, 'job_preserved' => $active_restore_job_bytes_before === maybe_serialize( get_option( $attempt_limit_job_key ) ), 'quality_preserved' => $active_restore_quality_bytes_before === maybe_serialize( get_option( $staged_commit_quality_key ) ), 'header_preserved' => $active_restore_header_before === $active_restore_header_after ) ) );
	}
	$active_restore_cleanup = $call( 'translation_job_restore_surface_snapshot', $active_restore_snapshot, $translation_id );
	clean_post_cache( $translation_id );
	$active_restore_cleanup_post = get_post( $translation_id );
	$active_restore_cleanup_surface = maybe_serialize( array( 'post' => $active_restore_cleanup_post instanceof WP_Post ? $active_restore_cleanup_post->to_array() : null, 'meta' => get_post_meta( $translation_id ) ) );
	if ( empty( $active_restore_cleanup['success'] ) || $active_restore_original_surface !== $active_restore_cleanup_surface || ! hash_equals( (string) ( $active_restore_snapshot['captured_cas_revision'] ?? '' ), (string) $call( 'translation_job_rollback_cas_revision', $translation_id, (array) ( $active_restore_snapshot['term_scope'] ?? array() ), (array) ( $active_restore_snapshot['identity_scope'] ?? array() ) ) ) ) {
		throw new RuntimeException( 'Active restore receipt fixture could not clean up only its exact controlled drift: ' . wp_json_encode( $active_restore_cleanup ) );
	}
	// The restore path must apply the same receipt grammar. Establish a real
	// restore COMMIT and return a receipt with no committed key. Even though the
	// exact restored revision is visible, the public publish Interface must keep
	// the Job/Quality authority immutable and report a critical failed rollback.
	// `forward_publication_applied=true` remains deliberate phase evidence that
	// the forward publication really committed before the forced follow-up
	// failure. Final reader status is unknown until the malformed restore receipt
	// is resolved, even though the database CAS proves the original surface is
	// present again.
	$invalid_restore_job_before = get_option( $attempt_limit_job_key );
	$invalid_restore_artifact = $call( 'translation_job_unpack_artifact_record', get_option( 'devenia_workflow_translation_artifact_' . (string) ( $invalid_restore_job_before['artifact_revision'] ?? '' ) ) );
	$invalid_restore_fixture_snapshot = $call( 'translation_job_capture_surface_snapshot', $translation_id, (array) ( $invalid_restore_artifact['surface_manifest'] ?? array() ), $call( 'translation_job_publication_identity_scope', $invalid_restore_job_before ) );
	if ( empty( $invalid_restore_fixture_snapshot['snapshot_valid'] ) ) { throw new RuntimeException( 'Malformed restore-commit fixture could not capture its exact pre-attempt surface.' ); }
	$invalid_restore_post_before = get_post( $translation_id );
	$invalid_restore_surface_before = maybe_serialize( array( 'post' => $invalid_restore_post_before instanceof WP_Post ? $invalid_restore_post_before->to_array() : null, 'meta' => get_post_meta( $translation_id ) ) );
	$invalid_restore_job_bytes_before = maybe_serialize( $invalid_restore_job_before );
	$invalid_restore_quality_bytes_before = maybe_serialize( get_option( $staged_commit_quality_key ) );
	$invalid_restore_header_before = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
	$invalid_restore_rollback_invalidations_before = count( array_filter( $cache_invalidation_calls, static function ( array $entry ): bool { return 'localized_presentation_rollback' === (string) ( $entry['context']['event'] ?? '' ); } ) );
	$invalid_restore_adapter_called = false;
	$invalid_restore_observed_revision = '';
	$invalid_restore_expected_revision = '';
	$invalid_restore_commit_adapter = static function ( $default, array $snapshot, int $restored_translation_id ) use ( $call, &$invalid_restore_adapter_called, &$invalid_restore_observed_revision, &$invalid_restore_expected_revision ): array {
		unset( $default );
		$actual = $call( 'translation_job_commit_recovery_transaction' );
		if ( empty( $actual['success'] ) || ! array_key_exists( 'committed', $actual ) || true !== $actual['committed'] ) { throw new RuntimeException( 'Malformed restore-commit fixture could not establish its real COMMIT.' ); }
		$invalid_restore_adapter_called = true;
		$invalid_restore_expected_revision = (string) ( $snapshot['captured_cas_revision'] ?? '' );
		$invalid_restore_observed_revision = (string) $call( 'translation_job_rollback_cas_revision', $restored_translation_id, (array) ( $snapshot['term_scope'] ?? array() ), (array) ( $snapshot['identity_scope'] ?? array() ) );
		if ( '' === $invalid_restore_expected_revision || ! hash_equals( $invalid_restore_expected_revision, $invalid_restore_observed_revision ) ) { throw new RuntimeException( 'Malformed restore-commit fixture did not observe the exact restored revision after its real COMMIT.' ); }
		return array( 'success' => false, 'code' => 'runtime_restore_commit_missing_committed', 'actual' => $actual );
	};
	$invalid_restore_force_publication_failure = static function ( $result, array $urls, array $context ) {
		unset( $urls );
		return 'localized_presentation_publication' === (string) ( $context['event'] ?? '' )
			? array( 'success' => false, 'code' => 'runtime_forced_publication_failure_for_invalid_restore_receipt' )
			: $result;
	};
	add_filter( 'devenia_workflow_translation_job_restore_commit_adapter_result', $invalid_restore_commit_adapter, 10, 4 );
	add_filter( 'devenia_workflow_frontend_cache_invalidation_result', $invalid_restore_force_publication_failure, 20, 3 );
	try {
		$invalid_restore_publish = $call( 'translation_job_publish', array( 'job_id' => $job_id, 'coordinator_id' => 'runtime-coordinator', 'sync_menu' => false, 'verify_live' => true ) );
	} finally {
		remove_filter( 'devenia_workflow_translation_job_restore_commit_adapter_result', $invalid_restore_commit_adapter, 10 );
		remove_filter( 'devenia_workflow_frontend_cache_invalidation_result', $invalid_restore_force_publication_failure, 20 );
	}
	clean_post_cache( $translation_id );
	$invalid_restore_post_after = get_post( $translation_id );
	$invalid_restore_surface_after = maybe_serialize( array( 'post' => $invalid_restore_post_after instanceof WP_Post ? $invalid_restore_post_after->to_array() : null, 'meta' => get_post_meta( $translation_id ) ) );
	$invalid_restore_current_revision = (string) $call( 'translation_job_rollback_cas_revision', $translation_id, (array) ( $invalid_restore_fixture_snapshot['term_scope'] ?? array() ), (array) ( $invalid_restore_fixture_snapshot['identity_scope'] ?? array() ) );
	$invalid_restore_header_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
	$invalid_restore_rollback_invalidations_after = count( array_filter( $cache_invalidation_calls, static function ( array $entry ): bool { return 'localized_presentation_rollback' === (string) ( $entry['context']['event'] ?? '' ); } ) );
	$invalid_restore_result = (array) ( $invalid_restore_publish['rollback'] ?? array() );
	$invalid_restore_reconciliation = (array) ( $invalid_restore_result['commit_reconciliation'] ?? array() );
	$invalid_restore_receipt_validation = (array) ( $invalid_restore_reconciliation['receipt_validation'] ?? array() );
	$invalid_restore_final_reader_state = (array) ( $invalid_restore_publish['final_reader_state'] ?? array() );
	$invalid_restore_response_translation = (array) ( $invalid_restore_publish['translation'] ?? array() );
	if (
		! $invalid_restore_adapter_called
		|| ! empty( $invalid_restore_publish['success'] )
		|| ! array_key_exists( 'published', $invalid_restore_publish )
		|| null !== $invalid_restore_publish['published']
		|| ! array_key_exists( 'forward_publication_applied', $invalid_restore_publish )
		|| true !== $invalid_restore_publish['forward_publication_applied']
		|| 'restored_unverified' !== (string) ( $invalid_restore_final_reader_state['state'] ?? '' )
		|| ! array_key_exists( 'published', $invalid_restore_final_reader_state )
		|| null !== $invalid_restore_final_reader_state['published']
		|| empty( $invalid_restore_final_reader_state['restored_exact'] )
		|| ! empty( $invalid_restore_final_reader_state['forward_exact'] )
		|| ! empty( $invalid_restore_final_reader_state['rollback_verified'] )
		|| ! hash_equals( $invalid_restore_current_revision, (string) ( $invalid_restore_final_reader_state['observed_cas_revision'] ?? '' ) )
		|| 'publication_rollback_failed' !== (string) ( $invalid_restore_publish['code'] ?? '' )
		|| ! empty( $invalid_restore_result['success'] )
		|| 'rollback_conflict' !== (string) ( $invalid_restore_result['action'] ?? '' )
		|| 'recovery_transaction_commit_receipt_invalid' !== (string) ( $invalid_restore_result['error'] ?? '' )
		|| 'critical' !== (string) ( $invalid_restore_result['severity'] ?? '' )
		|| 'invalid_receipt' !== (string) ( $invalid_restore_reconciliation['state_outcome'] ?? '' )
		|| ! empty( $invalid_restore_receipt_validation['valid'] )
		|| ! in_array( 'missing_committed', (array) ( $invalid_restore_receipt_validation['violations'] ?? array() ), true )
		|| array_key_exists( 'committed', (array) ( $invalid_restore_result['transaction_commit'] ?? array() ) )
		|| ! hash_equals( $invalid_restore_expected_revision, $invalid_restore_observed_revision )
		|| ! hash_equals( $invalid_restore_expected_revision, $invalid_restore_current_revision )
		|| $invalid_restore_surface_before !== $invalid_restore_surface_after
		|| $invalid_restore_job_bytes_before !== maybe_serialize( get_option( $attempt_limit_job_key ) )
		|| 'ready_to_publish' !== (string) ( get_option( $attempt_limit_job_key )['status'] ?? '' )
		|| $translation_id !== absint( $invalid_restore_response_translation['id'] ?? 0 )
		|| (string) get_post_field( 'post_title', $translation_id ) !== (string) ( $invalid_restore_response_translation['title'] ?? '' )
		|| (string) get_post_meta( $translation_id, $runtime_meta_status_key, true ) !== (string) ( $invalid_restore_response_translation['translation_status'] ?? '' )
		|| $invalid_restore_quality_bytes_before !== maybe_serialize( get_option( $staged_commit_quality_key ) )
		|| $invalid_restore_header_before !== $invalid_restore_header_after
		|| $invalid_restore_rollback_invalidations_before !== $invalid_restore_rollback_invalidations_after
	) {
		throw new RuntimeException( 'Malformed restore COMMIT receipt was accepted as a successful rollback or mutated immutable authority despite the exact restored state: ' . wp_json_encode( array( 'result' => $invalid_restore_publish, 'adapter_called' => $invalid_restore_adapter_called, 'expected_revision' => $invalid_restore_expected_revision, 'observed_revision' => $invalid_restore_observed_revision, 'current_revision' => $invalid_restore_current_revision, 'surface_restored' => $invalid_restore_surface_before === $invalid_restore_surface_after, 'job_preserved' => $invalid_restore_job_bytes_before === maybe_serialize( get_option( $attempt_limit_job_key ) ), 'quality_preserved' => $invalid_restore_quality_bytes_before === maybe_serialize( get_option( $staged_commit_quality_key ) ), 'header_preserved' => $invalid_restore_header_before === $invalid_restore_header_after ) ) );
	}
	};
	$pre_publish_concurrency_job = get_option( $attempt_limit_job_key );
	$pre_publish_concurrency_artifact = (string) ( $pre_publish_concurrency_job['artifact_revision'] ?? '' );
	$pre_publish_concurrency_quality = (string) ( $pre_publish_concurrency_job['quality_revision'] ?? '' );
	$pre_publish_concurrency_history = maybe_serialize( $pre_publish_concurrency_job['surface_refresh_history'] ?? array() );
	$publish_claim_probe_job_id = $job_id;
	$publish_claim_probe_run_id = 'runtime-publish-held-lease-claim-' . wp_generate_password( 8, false, false );
	$option_keys[] = 'devenia_workflow_translation_run_' . $publish_claim_probe_run_id;
	$publish_claim_probe_enabled = true;
	$published = $call(
		'translation_job_publish',
		// Public Header Projection has its own all-language WordPress fixture.
		// This lifecycle fixture keeps that independently proven Interface stable.
		array( 'job_id' => $job_id, 'coordinator_id' => 'runtime-coordinator', 'sync_menu' => false, 'verify_live' => true )
	);
	$publish_claim_probe_enabled = false;
	$runtime_identities = get_option( 'devenia_workflow_localized_menu_identities', array() );
	$runtime_active_menu_id = absint( $runtime_identities[ $language ]['menu_id'] ?? 0 );
	$published_concurrency_job = get_option( $attempt_limit_job_key );
	$published_artifact_record = $call( 'translation_job_unpack_artifact_record', get_option( 'devenia_workflow_translation_artifact_' . (string) ( $published_concurrency_job['artifact_revision'] ?? '' ) ) );
	$published_signed_route = (array) ( $published_artifact_record['surface_manifest']['route']['canonical_route'] ?? array() );
	$published_stored_route = get_post_meta( $translation_id, '_devenia_translation_canonical_route_v1', true );
	$publication_invalidation_calls = array_values(
		array_filter(
			$cache_invalidation_calls,
			static function ( array $entry ): bool {
				return 'localized_presentation_publication' === (string) ( $entry['context']['event'] ?? '' );
			}
		)
	);
	if (
		empty( $published['success'] )
		|| 'translation_job_lifecycle_lease_conflict' !== (string) ( $publish_claim_probe['code'] ?? '' )
		|| false !== get_option( 'devenia_workflow_translation_run_' . $publish_claim_probe_run_id )
		|| false !== get_option( 'devenia_workflow_translation_job_claim_' . $job_id )
		|| 'published' !== (string) ( $published_concurrency_job['status'] ?? '' )
		|| $pre_publish_concurrency_artifact !== (string) ( $published_concurrency_job['artifact_revision'] ?? '' )
		|| $pre_publish_concurrency_quality !== (string) ( $published_concurrency_job['quality_revision'] ?? '' )
		|| $pre_publish_concurrency_history !== maybe_serialize( $published_concurrency_job['surface_refresh_history'] ?? array() )
		|| $runtime_active_menu_id < 1
		|| empty( $publication_invalidation_calls )
		|| $job_id !== (string) ( $publication_invalidation_calls[0]['context']['job_id'] ?? '' )
		|| $legacy_effective_route !== $published_signed_route
		|| $published_signed_route !== $published_stored_route
		|| array_key_exists( 'established_at', $published_stored_route )
		|| $runtime_localized_slug !== (string) get_post_field( 'post_name', $translation_id )
		|| $runtime_translation_url !== (string) get_permalink( $translation_id )
	) {
		throw new RuntimeException( 'Publish-held lifecycle lease did not reject a concurrent translator claim while preserving final Job CAS: ' . wp_json_encode( array( 'published' => $published, 'concurrent_claim' => $publish_claim_probe, 'stored_job' => $published_concurrency_job, 'invalidation_calls' => $cache_invalidation_calls ) ) );
	}

	// Rollback media proof must purge and verify both cache surfaces. Exercise
	// both the restored-media success path and a stale-cache failure path.
	$rollback_artifact = $call( 'translation_job_unpack_artifact_record', get_option( 'devenia_workflow_translation_artifact_' . (string) $published_concurrency_job['artifact_revision'] ) );
	$rollback_snapshot = $call( 'translation_job_capture_surface_snapshot', $translation_id, (array) ( $rollback_artifact['surface_manifest'] ?? array() ), $call( 'translation_job_publication_identity_scope', $published_concurrency_job ) );
	$rollback_snapshot['mutation_started'] = true;
	update_post_meta( $translation_id, 'rank_math_title', 'Runtime rollback media success mutation' );
	clean_post_cache( $translation_id );
	$rollback_snapshot['rollback_expected_surface_revision'] = $call( 'translation_job_rollback_cas_revision', $translation_id, (array) ( $rollback_snapshot['term_scope'] ?? array() ), (array) ( $rollback_snapshot['identity_scope'] ?? array() ) );
	$rollback_invalidation_count = count( $cache_invalidation_calls );
	$rollback_media_success = $call( 'translation_job_publish_failure_with_rollback', array( 'success' => false, 'code' => 'runtime_rollback_media_success', 'purge_urls' => array( get_permalink( $translation_id ) ) ), $rollback_snapshot, $translation_id );
	$rollback_event = $cache_invalidation_calls[ $rollback_invalidation_count ]['context']['event'] ?? '';
	if (
		empty( $rollback_media_success['rollback']['success'] )
		|| ! array_key_exists( 'forward_publication_applied', $rollback_media_success )
		|| true !== $rollback_media_success['forward_publication_applied']
		|| ! array_key_exists( 'published', $rollback_media_success )
		|| false !== $rollback_media_success['published']
		|| 'restored_verified' !== (string) ( $rollback_media_success['final_reader_state']['state'] ?? '' )
		|| 'localized_presentation_rollback' !== $rollback_event
		|| empty( $rollback_media_success['rollback']['media_verification']['success'] )
		|| ! isset( $rollback_media_success['rollback']['media_verification']['responses']['origin'], $rollback_media_success['rollback']['media_verification']['responses']['canonical'] )
	) {
		throw new RuntimeException( 'Rollback did not purge and prove restored featured media on origin plus canonical surfaces: ' . wp_json_encode( $rollback_media_success ) );
	}
	$rollback_failure_snapshot = $call( 'translation_job_capture_surface_snapshot', $translation_id, (array) ( $rollback_artifact['surface_manifest'] ?? array() ), $call( 'translation_job_publication_identity_scope', $published_concurrency_job ) );
	$rollback_failure_snapshot['mutation_started'] = true;
	update_post_meta( $translation_id, 'rank_math_description', 'Runtime rollback stale-cache mutation' );
	clean_post_cache( $translation_id );
	$rollback_failure_snapshot['rollback_expected_surface_revision'] = $call( 'translation_job_rollback_cas_revision', $translation_id, (array) ( $rollback_failure_snapshot['term_scope'] ?? array() ), (array) ( $rollback_failure_snapshot['identity_scope'] ?? array() ) );
	$stale_rollback_http = static function ( $preempt, array $args, string $url ) use ( $runtime_source_url ) {
		unset( $args, $url );
		$body = '<html><head><meta property="og:image" content="' . esc_attr( $runtime_source_url ) . '"></head><body><img class="wp-post-image" src="' . esc_attr( $runtime_source_url ) . '"></body></html>';
		return array( 'headers' => array( 'cf-cache-status' => 'HIT', 'age' => '0' ), 'body' => $body, 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	};
	add_filter( 'pre_http_request', $stale_rollback_http, 20, 3 );
	try {
		$rollback_media_failure = $call( 'translation_job_publish_failure_with_rollback', array( 'success' => false, 'code' => 'runtime_rollback_media_failure', 'purge_urls' => array( get_permalink( $translation_id ) ) ), $rollback_failure_snapshot, $translation_id );
	} finally {
		remove_filter( 'pre_http_request', $stale_rollback_http, 20 );
	}
	if (
		'publication_rollback_failed' !== (string) ( $rollback_media_failure['code'] ?? '' )
		|| ! empty( $rollback_media_failure['rollback']['success'] )
		|| ! array_key_exists( 'published', $rollback_media_failure )
		|| null !== $rollback_media_failure['published']
		|| 'restored_unverified' !== (string) ( $rollback_media_failure['final_reader_state']['state'] ?? '' )
		|| 'rollback_featured_image_verification_failed' !== (string) ( $rollback_media_failure['rollback']['error'] ?? '' )
		|| empty( $rollback_media_failure['rollback']['media_verification']['issues'] )
	) {
		throw new RuntimeException( 'Rollback accepted stale featured media from origin/canonical cache verification: ' . wp_json_encode( $rollback_media_failure ) );
	}

	// Source Publication Surface: timestamps are diagnostic, bytes are authority.
	$source_surface_a = $call( 'source_publication_surface_revision', get_post( $source_id ) );
	$source_contract_a = $call( 'translation_job_publication_surface_contract_revision', get_post( $source_id ) );
	$source_media_a = $call( 'publication_featured_image_revision_identity', $source_id );
	$published_authority_job = get_option( 'devenia_workflow_translation_job_' . $job_id );
	$unverified_published_obligation = $call( 'project_translation_obligation', $source_id, $language, $source_surface_a, $source_contract_a );
	$published_job_key = 'devenia_workflow_translation_job_' . $job_id;
	if ( ! empty( $published_authority_job['live_verification_passed'] ) || 'published' !== (string) ( $unverified_published_obligation['state'] ?? '' ) ) {
		throw new RuntimeException( 'An authority-valid published Job awaiting the separate live-verification seam was not projected as published: ' . wp_json_encode( compact( 'published_authority_job', 'unverified_published_obligation' ) ) );
	}
	$published_contract_stale_job = $published_authority_job;
	$published_contract_stale_job['publication_surface_contract_revision'] = 'pscr_runtime_inventory_stale';
	update_option( $published_job_key, $published_contract_stale_job, false );
	$stale_contract_obligation = $call( 'project_translation_obligation', $source_id, $language, $source_surface_a, $source_contract_a );
	update_option( $published_job_key, $published_authority_job, false );
	$published_quality = get_option( 'devenia_workflow_translation_quality_' . (string) ( $published_authority_job['quality_revision'] ?? '' ) );
	$published_evidence_key = 'devenia_tj_quality_evidence_' . (string) ( $published_quality['evidence_revision'] ?? '' );
	$published_evidence = get_option( $published_evidence_key );
	delete_option( $published_evidence_key );
	$missing_evidence_obligation = $call( 'project_translation_obligation', $source_id, $language, $source_surface_a, $source_contract_a );
	$missing_evidence_refresh = $call( 'translation_job_refresh_drifted_surface', $published_authority_job, 'discover_published_authority_drift' );
	update_option( $published_evidence_key, $published_evidence, false );
	$published_artifact_key = 'devenia_workflow_translation_artifact_' . (string) ( $published_authority_job['artifact_revision'] ?? '' );
	$published_artifact_exact = get_option( $published_artifact_key );
	delete_option( $published_artifact_key );
	$missing_artifact_obligation = $call( 'project_translation_obligation', $source_id, $language, $source_surface_a, $source_contract_a );
	update_option( $published_artifact_key, $published_artifact_exact, false );
	if (
		'publication_contract_stale' !== (string) ( $stale_contract_obligation['state'] ?? '' )
		|| 'publication_authority_stale' !== (string) ( $missing_evidence_obligation['state'] ?? '' )
		|| 'publication_authority_stale' !== (string) ( $missing_artifact_obligation['state'] ?? '' )
		|| 'published_authority_manual_repair_required' !== (string) ( $missing_evidence_refresh['code'] ?? '' )
		|| 'quality_evidence_missing' !== (string) ( $missing_evidence_refresh['authority_code'] ?? '' )
	) {
		throw new RuntimeException( 'Unverified published obligation accepted a stale contract/evidence binding or ordinary drift recovery accepted corrupt authority: ' . wp_json_encode( compact( 'stale_contract_obligation', 'missing_evidence_obligation', 'missing_artifact_obligation', 'missing_evidence_refresh' ) ) );
	}
	$live_verification = $call( 'translation_job_verify_live', array( 'job_id' => $job_id, 'timeout' => 3 ) );
	$verified_authority_job = get_option( $published_job_key );
	$verified_published_obligation = $call( 'project_translation_obligation', $source_id, $language, $source_surface_a, $source_contract_a );
	if (
		empty( $live_verification['success'] )
		|| empty( $live_verification['passed'] )
		|| ! isset( $live_verification['live_verification']['cache_responses']['origin'], $live_verification['live_verification']['cache_responses']['canonical'] )
		|| empty( $verified_authority_job['live_verification_passed'] )
		|| 'published_verified' !== (string) ( $verified_published_obligation['state'] ?? '' )
	) {
		throw new RuntimeException( 'The actual translation-job-verify-live seam did not advance an authority-valid published obligation to published_verified: ' . wp_json_encode( compact( 'live_verification', 'verified_authority_job', 'verified_published_obligation' ) ) );
	}
	$inventory_signature_a = $call( 'current_source_inventory_signature' );
	$translation_media_cas_a = $call( 'translation_job_rollback_cas_revision', $translation_id );
	$source_file_bytes = file_get_contents( $source_thumbnail_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Ephemeral byte-identity fixture.
	$source_file_mtime = filemtime( $source_thumbnail_file );
	$source_file_stat = stat( $source_thumbnail_file );
	$mutated_stat = $source_file_stat;
	$mutated_stat['mtime'] = (int) $mutated_stat['mtime'] + 1;
	if ( empty( $call( 'publication_file_sample_is_stable', $source_file_stat, $source_file_stat ) ) || ! empty( $call( 'publication_file_sample_is_stable', $source_file_stat, $mutated_stat ) ) ) {
		throw new RuntimeException( 'Stable file-sampling predicate accepted a mutated stat boundary.' );
	}
	touch( $source_thumbnail_file, (int) $source_file_mtime + 5 );
	clearstatcache( true, $source_thumbnail_file );
	$source_surface_same_bytes_new_mtime = $call( 'source_publication_surface_revision', get_post( $source_id ) );
	if ( ! hash_equals( $source_surface_a, $source_surface_same_bytes_new_mtime ) ) {
		throw new RuntimeException( 'Diagnostic file mtime changed the content-addressed Source Publication Surface revision.' );
	}
	$fixture_png_mutated = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII=', true );
	file_put_contents( $source_thumbnail_file, $fixture_png_mutated ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Ephemeral same-ID mutation fixture.
	clearstatcache( true, $source_thumbnail_file );
	$source_surface_same_id_new_bytes = $call( 'source_publication_surface_revision', get_post( $source_id ) );
	$translation_media_cas_b = $call( 'translation_job_rollback_cas_revision', $translation_id );
	$inventory_dirty_from_bytes = $call( 'source_inventory_refresh_dirty_state', array( 'source_signature' => $inventory_signature_a ) );
	if ( hash_equals( $source_surface_a, $source_surface_same_id_new_bytes ) || hash_equals( $translation_media_cas_a, $translation_media_cas_b ) || empty( $inventory_dirty_from_bytes ) || '1' !== (string) get_option( 'devenia_workflow_source_inventory_dirty', '0' ) ) {
		throw new RuntimeException( 'Same-ID attachment byte replacement did not change Source Publication Surface revision.' );
	}
	file_put_contents( $source_thumbnail_file, $source_file_bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Restore ephemeral fixture.
	touch( $source_thumbnail_file, (int) $source_file_mtime );
	clearstatcache( true, $source_thumbnail_file );
	if ( ! hash_equals( $source_surface_a, $call( 'source_publication_surface_revision', get_post( $source_id ) ) ) ) {
		throw new RuntimeException( 'Restoring exact source bytes did not restore the content-addressed Source Publication Surface revision.' );
	}

	$replacement_thumbnail_file = trailingslashit( (string) $uploads['path'] ) . 'translation-job-replacement-media-' . wp_generate_password( 8, false, false ) . '.png';
	file_put_contents( $replacement_thumbnail_file, $fixture_png_mutated ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Ephemeral replacement fixture.
	$replacement_thumbnail_id = wp_insert_attachment( array( 'post_title' => 'Translation Job replacement media fixture', 'post_status' => 'inherit', 'post_mime_type' => 'image/png' ), $replacement_thumbnail_file );
	if ( ! $replacement_thumbnail_id || is_wp_error( $replacement_thumbnail_id ) ) { throw new RuntimeException( 'Could not create replacement featured-image fixture.' ); }
	update_attached_file( $replacement_thumbnail_id, $replacement_thumbnail_file );
	update_post_meta( $replacement_thumbnail_id, '_wp_attachment_metadata', array( 'width' => 600, 'height' => 600, 'file' => basename( $replacement_thumbnail_file ), 'sizes' => array( 'thumbnail' => array( 'file' => 'translation-job-replacement-thumbnail.png', 'width' => 150, 'height' => 150, 'mime-type' => 'image/png' ) ) ) );
	update_post_meta( $source_id, '_thumbnail_id', $replacement_thumbnail_id );
	clean_post_cache( $source_id );
	$source_surface_b = $call( 'source_publication_surface_revision', get_post( $source_id ) );
	$source_media_b = $call( 'publication_featured_image_revision_identity', $source_id );
	$stale_job = array( 'source_id' => $source_id, 'source_revision' => $source_surface_a );
	$stale_approval = $call( 'translation_job_source_approval', get_post( $source_id ) );
	$old_publish = $call( 'translation_job_publish', array( 'job_id' => $job_id ) );
	foreach ( array_keys( Devenia_Workflow::languages() ) as $target_language ) {
		if ( $target_language === $call( 'source_language_code' ) ) { continue; }
		$obligation = $call( 'project_translation_obligation', $source_id, (string) $target_language, $source_surface_a );
		if ( 'source_surface_stale' !== (string) ( $obligation['state'] ?? '' ) ) {
			throw new RuntimeException( 'A configured language remained resolved after source featured-image replacement: ' . wp_json_encode( $obligation ) );
		}
	}
	$translation_before_repair = $call( 'publication_featured_image_revision_identity', $translation_id );
	$call( 'sync_translation_index_row', $translation_id );
	$repair_dry_run = $call( 'repair_featured_images', array( 'source_ids' => array( $source_id ), 'languages' => array( $language ), 'dry_run' => true ) );
	wp_set_current_user( 0 );
	$repair_forbidden = $call( 'repair_featured_images', array( 'source_ids' => array( $source_id ), 'languages' => array( $language ), 'dry_run' => false ) );
	wp_set_current_user( (int) $administrator_ids[0] );
	$repair_bounded = $call( 'repair_featured_images', array( 'source_ids' => array( $source_id ), 'languages' => array( $language ), 'dry_run' => false ) );
	$translation_after_repair = $call( 'publication_featured_image_revision_identity', $translation_id );
	$media_issues = new ReflectionMethod( Devenia_Workflow::class, 'frontend_featured_image_html_issues' );
	$media_issues->setAccessible( true );
	$replacement_url = (string) ( $source_media_b['url'] ?? '' );
	$replacement_srcset = (string) wp_get_attachment_image_srcset( $replacement_thumbnail_id, 'full' );
	$correct_media_html = '<html><head><meta property="og:image" content="' . esc_attr( $replacement_url ) . '"></head><body><img class="wp-post-image" src="' . esc_attr( $replacement_url ) . '" srcset="' . esc_attr( $replacement_srcset ) . '"></body></html>';
	$correct_media_issues = $media_issues->invoke( null, $correct_media_html, $source_media_b, get_permalink( $translation_id ), 'canonical' );
	$missing_srcset_media_issues = $media_issues->invoke( null, preg_replace( '/\s+srcset="[^"]*"/', '', $correct_media_html ), $source_media_b, get_permalink( $translation_id ), 'canonical' );
	$srcset_candidates = preg_split( '/\s*,\s*/', $replacement_srcset ) ?: array();
	$partial_srcset_html = str_replace( esc_attr( $replacement_srcset ), esc_attr( (string) ( $srcset_candidates[0] ?? '' ) ), $correct_media_html );
	$partial_srcset_media_issues = $media_issues->invoke( null, $partial_srcset_html, $source_media_b, get_permalink( $translation_id ), 'canonical' );
	$invalid_descriptor_srcset = preg_replace( '/\s+[0-9]+w(,|$)/', ' 999q$1', $replacement_srcset, 1 );
	$invalid_descriptor_html = str_replace( esc_attr( $replacement_srcset ), esc_attr( (string) $invalid_descriptor_srcset ), $correct_media_html );
	$invalid_descriptor_media_issues = $media_issues->invoke( null, $invalid_descriptor_html, $source_media_b, get_permalink( $translation_id ), 'canonical' );
	$extra_token_srcset = preg_replace( '/\s+([0-9]+w)(,|$)/', ' $1 garbage$2', $replacement_srcset, 1 );
	$extra_token_html = str_replace( esc_attr( $replacement_srcset ), esc_attr( (string) $extra_token_srcset ), $correct_media_html );
	$extra_token_media_issues = $media_issues->invoke( null, $extra_token_html, $source_media_b, get_permalink( $translation_id ), 'canonical' );
	$empty_candidate_srcset = $replacement_srcset . ',';
	$empty_candidate_html = str_replace( esc_attr( $replacement_srcset ), esc_attr( $empty_candidate_srcset ), $correct_media_html );
	$empty_candidate_media_issues = $media_issues->invoke( null, $empty_candidate_html, $source_media_b, get_permalink( $translation_id ), 'canonical' );
	$wrong_media_issues = $media_issues->invoke( null, str_replace( $replacement_url, (string) ( $source_media_a['url'] ?? '' ), $correct_media_html ), $source_media_b, get_permalink( $translation_id ), 'origin' );
	$missing_media_issues = $media_issues->invoke( null, '<html><head></head><body></body></html>', $source_media_b, get_permalink( $translation_id ), 'canonical' );
	$extra_stale_media_issues = $media_issues->invoke( null, str_replace( '</body>', '<img class="wp-post-image" src="' . esc_attr( (string) ( $source_media_a['url'] ?? '' ) ) . '"></body>', $correct_media_html ), $source_media_b, get_permalink( $translation_id ), 'canonical' );
	$no_image_media = $call( 'publication_featured_image_revision_identity', $linked_source_id );
	$no_image_clean_issues = $media_issues->invoke( null, '<html><head></head><body></body></html>', $no_image_media, get_permalink( $linked_source_id ), 'origin' );
	$no_image_stale_og_issues = $media_issues->invoke( null, '<html><head><meta property="og:image" content="' . esc_attr( (string) ( $source_media_a['url'] ?? '' ) ) . '"></head><body></body></html>', $no_image_media, get_permalink( $linked_source_id ), 'canonical' );
	$no_image_empty_hero_issues = $media_issues->invoke( null, '<html><head></head><body><img class="wp-post-image" src=""></body></html>', $no_image_media, get_permalink( $linked_source_id ), 'origin' );
	$no_image_empty_og_issues = $media_issues->invoke( null, '<html><head><meta property="og:image" content=""></head><body></body></html>', $no_image_media, get_permalink( $linked_source_id ), 'canonical' );
	$no_image_parser_unavailable_issues = $media_issues->invoke( null, '<html><head></head><body></body></html>', $no_image_media, get_permalink( $linked_source_id ), 'origin', false );
	$no_image_parse_failure_issues = $media_issues->invoke( null, '', $no_image_media, get_permalink( $linked_source_id ), 'canonical' );
	if (
		hash_equals( $source_surface_a, $source_surface_b )
		|| empty( $call( 'translation_job_source_is_stale', $stale_job ) )
		|| ! empty( $stale_approval['evidence_matches_publication_surface'] )
		|| 'job_superseded' !== (string) ( $old_publish['code'] ?? '' )
		|| '1' !== (string) get_option( 'devenia_workflow_source_inventory_dirty', '0' )
		|| empty( $repair_dry_run['changed'] )
		|| 'featured_image_repair_forbidden' !== (string) ( $repair_forbidden['code'] ?? '' )
		|| empty( $repair_bounded['changed'][0]['bounded_lifecycle'] )
		|| maybe_serialize( $translation_before_repair ) !== maybe_serialize( $translation_after_repair )
		|| ! empty( $correct_media_issues )
		|| empty( $replacement_srcset )
		|| empty( $missing_srcset_media_issues )
		|| empty( $partial_srcset_media_issues )
		|| empty( $invalid_descriptor_media_issues )
		|| empty( $extra_token_media_issues )
		|| empty( $empty_candidate_media_issues )
		|| empty( $wrong_media_issues )
		|| empty( $missing_media_issues )
		|| empty( $extra_stale_media_issues )
		|| ! empty( $no_image_clean_issues )
		|| empty( $no_image_stale_og_issues )
		|| empty( $no_image_empty_hero_issues )
		|| empty( $no_image_empty_og_issues )
		|| empty( $no_image_parser_unavailable_issues )
		|| empty( $no_image_parse_failure_issues )
	) {
		throw new RuntimeException( 'Source Publication Surface media lifecycle failed closed incorrectly: ' . wp_json_encode( compact( 'source_surface_a', 'source_surface_b', 'stale_approval', 'old_publish', 'repair_dry_run', 'repair_forbidden', 'repair_bounded', 'correct_media_issues', 'missing_srcset_media_issues', 'partial_srcset_media_issues', 'invalid_descriptor_media_issues', 'extra_token_media_issues', 'empty_candidate_media_issues', 'wrong_media_issues', 'missing_media_issues', 'extra_stale_media_issues', 'no_image_clean_issues', 'no_image_stale_og_issues', 'no_image_empty_hero_issues', 'no_image_empty_og_issues', 'no_image_parser_unavailable_issues', 'no_image_parse_failure_issues' ) ) );
	}
	update_post_meta( $source_id, '_thumbnail_id', $source_thumbnail_id );
	clean_post_cache( $source_id );
	if (
		metadata_exists( 'post', $translation_id, 'rank_math_focus_keyword' )
		|| 'Oversatt testside' !== (string) get_post_meta( $translation_id, 'rank_math_title', true )
		|| 'En nyttig beskrivelse av den oversatte testsiden.' !== (string) get_post_meta( $translation_id, 'rank_math_description', true )
	) {
		throw new RuntimeException(
			'Approved empty Rank Math focus keyword did not delete the stale key while preserving exact approved title and description: ' .
			wp_json_encode(
				array(
					'focus_keyword_exists' => metadata_exists( 'post', $translation_id, 'rank_math_focus_keyword' ),
					'title'                => get_post_meta( $translation_id, 'rank_math_title', true ),
					'description'          => get_post_meta( $translation_id, 'rank_math_description', true ),
				)
			)
		);
	}

	// Claim-time drift detection must reopen a live ready_to_publish clone and
	// immediately issue a fresh generation-bound principal. The same direct path
	// must fail closed once the finite generation ceiling is reached.
	$current_main_job = get_option( $attempt_limit_job_key );
	$claim_refresh_job_id = 'tj_runtime_claim_refresh_' . wp_generate_password( 8, false, false );
	$claim_refresh_job_key = 'devenia_workflow_translation_job_' . $claim_refresh_job_id;
	$claim_refresh_artifact_revision = 'a_' . substr( hash( 'sha256', $claim_refresh_job_id ), 0, 32 );
	$claim_refresh_artifact_key = 'devenia_workflow_translation_artifact_' . $claim_refresh_artifact_revision;
	$claim_refresh_artifact_record = $call( 'translation_job_unpack_artifact_record', get_option( 'devenia_workflow_translation_artifact_' . (string) $current_main_job['artifact_revision'] ) );
	$claim_refresh_artifact_record['artifact_revision'] = $claim_refresh_artifact_revision;
	$claim_refresh_artifact_record['job_id'] = $claim_refresh_job_id;
	$claim_refresh_artifact_record['submission_generation'] = 1;
	$claim_refresh_artifact_record = $call( 'translation_job_pack_artifact_record', $claim_refresh_artifact_record );
	update_option( $claim_refresh_artifact_key, $claim_refresh_artifact_record, false );
	$option_keys[] = $claim_refresh_artifact_key;
	$claim_refresh_job = array_merge(
		$current_main_job,
		array(
			'job_id' => $claim_refresh_job_id,
			'status' => 'ready_to_publish',
			'submission_generation' => 1,
			'artifact_revision' => $claim_refresh_artifact_revision,
			'surface_refresh_history' => array(),
			'run_ids' => array(),
			'active_run_id' => '',
		)
	);
	update_option( $claim_refresh_job_key, $claim_refresh_job, false );
	$option_keys[] = $claim_refresh_job_key;
	$claim_wins_surface_before = $call( 'translation_job_current_surface_revision', $translation_id );
	$claim_refresh_run_id = 'runtime-ready-claim-refresh-' . wp_generate_password( 8, false, false );
	$claim_refresh = null;
	$claim_wins_filter_name = 'option_' . $claim_refresh_job_key;
	$claim_wins_filter = null;
	$claim_wins_filter = static function ( $stale_job ) use ( &$claim_wins_filter, $claim_wins_filter_name, &$claim_refresh, $call, $claim_refresh_job_id, $claim_refresh_run_id ) {
		remove_filter( $claim_wins_filter_name, $claim_wins_filter, PHP_INT_MAX );
		$claim_refresh = $call(
			'translation_job_claim',
			array( 'job_id' => $claim_refresh_job_id, 'run_id' => $claim_refresh_run_id, 'coordinator_id' => 'runtime-observability-only', 'role' => 'translator', 'ttl_seconds' => 600 )
		);
		return $stale_job;
	};
	add_filter( $claim_wins_filter_name, $claim_wins_filter, PHP_INT_MAX );
	try {
		$claim_wins_publish = $call( 'translation_job_publish', array( 'job_id' => $claim_refresh_job_id ) );
	} finally {
		remove_filter( $claim_wins_filter_name, $claim_wins_filter, PHP_INT_MAX );
	}
	$option_keys[] = 'devenia_workflow_translation_run_' . (string) ( $claim_refresh['run']['run_id'] ?? '' );
	$claim_wins_stored_job = get_option( $claim_refresh_job_key );
	if (
		empty( $claim_refresh['success'] )
		|| 'changes_requested' !== (string) ( $claim_refresh['claim']['previous_status'] ?? '' )
		|| 2 !== absint( $claim_refresh['run']['submission_generation'] ?? 0 )
		|| 2 !== absint( $claim_refresh['run']['principal']['submission_generation'] ?? 0 )
		|| 'job_not_ready_to_publish' !== (string) ( $claim_wins_publish['code'] ?? '' )
		|| 'claimed' !== (string) ( $claim_wins_stored_job['status'] ?? '' )
		|| $claim_refresh_run_id !== (string) ( $claim_wins_stored_job['active_run_id'] ?? '' )
		|| $claim_wins_surface_before !== $call( 'translation_job_current_surface_revision', $translation_id )
	) {
		throw new RuntimeException( 'Claim-winning lifecycle race did not make publish re-read fail before public mutation: ' . wp_json_encode( array( 'claim' => $claim_refresh, 'publish' => $claim_wins_publish, 'job' => $claim_wins_stored_job ) ) );
	}
	$claim_refresh_abandon = $call(
		'translation_job_abandon',
		array( 'job_id' => $claim_refresh_job_id, 'run_id' => (string) $claim_refresh['run']['run_id'], 'claim_token' => (string) $claim_refresh['claim_token'], 'reason' => 'Runtime releases the defensive claim after proving its refreshed state.' )
	);
	if ( empty( $claim_refresh_abandon['success'] ) || 'changes_requested' !== (string) ( $claim_refresh_abandon['job']['status'] ?? '' ) ) {
		throw new RuntimeException( 'Defensive ready-to-publish claim did not abandon to changes_requested.' );
	}

	$limit_job_id = 'tj_runtime_refresh_limit_' . wp_generate_password( 8, false, false );
	$limit_job_key = 'devenia_workflow_translation_job_' . $limit_job_id;
	$limit_artifact_revision = 'a_' . substr( hash( 'sha256', $limit_job_id ), 0, 32 );
	$limit_artifact_key = 'devenia_workflow_translation_artifact_' . $limit_artifact_revision;
	$limit_artifact_record = $call( 'translation_job_unpack_artifact_record', get_option( 'devenia_workflow_translation_artifact_' . (string) $current_main_job['artifact_revision'] ) );
	$limit_artifact_record['artifact_revision'] = $limit_artifact_revision;
	$limit_artifact_record['job_id'] = $limit_job_id;
	$limit_artifact_record['submission_generation'] = 3;
	$limit_artifact_record = $call( 'translation_job_pack_artifact_record', $limit_artifact_record );
	update_option( $limit_artifact_key, $limit_artifact_record, false );
	$option_keys[] = $limit_artifact_key;
	$limit_job = array_merge(
		$current_main_job,
		array(
			'job_id' => $limit_job_id,
			'status' => 'ready_to_publish',
			'submission_generation' => 3,
			'artifact_revision' => $limit_artifact_revision,
			'surface_refresh_history' => array(),
			'run_ids' => array(),
			'active_run_id' => '',
		)
	);
	update_option( $limit_job_key, $limit_job, false );
	$option_keys[] = $limit_job_key;
	$bounded_refresh = $call(
		'translation_job_claim',
		array( 'job_id' => $limit_job_id, 'run_id' => 'runtime-refresh-limit-' . wp_generate_password( 8, false, false ), 'coordinator_id' => 'runtime-observability-only', 'role' => 'translator', 'ttl_seconds' => 600 )
	);
	$stored_limit_job = get_option( $limit_job_key );
	if ( 'surface_refresh_generation_limit' !== (string) ( $bounded_refresh['code'] ?? '' ) || 'failed_technical' !== (string) ( $stored_limit_job['status'] ?? '' ) ) {
		throw new RuntimeException( 'Surface Refresh did not fail closed at the finite generation ceiling: ' . wp_json_encode( $bounded_refresh ) );
	}

	// Identity replacement is deterministically exercised at the private
	// transaction boundary: capture an exact snapshot, let a competing writer
	// remove the canonical identity before the next lock, then prove the owned
	// transaction did not mutate and rolled back. Static contracts below bind
	// this exact failure to the same publish-call CAS path.
	$identity_job_id = 'tj_runtime_identity_refresh_' . wp_generate_password( 8, false, false );
	$identity_job_key = 'devenia_workflow_translation_job_' . $identity_job_id;
	$identity_artifact_revision = 'a_' . substr( hash( 'sha256', $identity_job_id ), 0, 32 );
	$identity_artifact_key = 'devenia_workflow_translation_artifact_' . $identity_artifact_revision;
	$current_artifact_record = $call( 'translation_job_unpack_artifact_record', get_option( 'devenia_workflow_translation_artifact_' . (string) $current_main_job['artifact_revision'] ) );
	$identity_artifact_record = $current_artifact_record;
	$identity_artifact_record['artifact_revision'] = $identity_artifact_revision;
	$identity_artifact_record['job_id'] = $identity_job_id;
	$identity_artifact_record['submission_generation'] = 1;
	update_option( $identity_artifact_key, $call( 'translation_job_pack_artifact_record', $identity_artifact_record ), false );
	$option_keys[] = $identity_artifact_key;
	$identity_job = array_merge(
		$current_main_job,
		array(
			'job_id' => $identity_job_id,
			'schema_version' => 3,
			'status' => 'ready_to_publish',
			'submission_generation' => 1,
			'artifact_revision' => $identity_artifact_revision,
			'surface_refresh_history' => array(),
			'run_ids' => array(),
			'active_run_id' => '',
		)
	);
	update_option( $identity_job_key, $identity_job, false );
	$option_keys[] = $identity_job_key;
	$identity_scope = $call( 'translation_job_publication_identity_scope', $identity_job );
	$identity_snapshot = $call( 'translation_job_capture_surface_snapshot', $translation_id, (array) $identity_artifact_record['surface_manifest'], $identity_scope );
	if ( empty( $identity_snapshot['snapshot_valid'] ) || empty( $identity_snapshot['transaction_commit']['success'] ) ) {
		throw new RuntimeException( 'Identity-race lower-level snapshot failed: ' . wp_json_encode( $identity_snapshot ) );
	}
	$identity_artifact_bytes = maybe_serialize( get_option( $identity_artifact_key ) );
	$identity_quality_revision = (string) ( $identity_job['quality_revision'] ?? '' );
	$identity_quality_key = 'devenia_workflow_translation_quality_' . $identity_quality_revision;
	$option_keys[] = $identity_quality_key;
	$identity_quality_record = get_option( $identity_quality_key );
	$identity_quality_bytes = maybe_serialize( $identity_quality_record );
	$identity_evidence_key = 'devenia_tj_quality_evidence_' . (string) ( $identity_quality_record['evidence_revision'] ?? '' );
	$option_keys[] = $identity_evidence_key;
	$identity_evidence_bytes = maybe_serialize( get_option( $identity_evidence_key ) );
	$identity_failure = array();
	$identity_refresh = array();
	delete_post_meta( $translation_id, '_devenia_translation_source_id' );
	try {
		$identity_failure = $call( 'translation_job_apply_staged_artifact', $identity_job, $identity_artifact_record, $identity_snapshot );
		$identity_refresh = $call( 'translation_job_refresh_drifted_surface', $identity_job, 'publish_baseline_mismatch', $identity_failure );
	} finally {
		update_post_meta( $translation_id, '_devenia_translation_source_id', $source_id );
		clean_post_cache( $translation_id );
		$call( 'sync_translation_index_row', $translation_id );
	}
	$stored_identity_job = get_option( $identity_job_key );
	$identity_history = (array) ( $stored_identity_job['surface_refresh_history'] ?? array() );
	$identity_history_latest = $identity_history ? (array) end( $identity_history ) : array();
	if (
		'staged_translation_identity_changed_before_locked_write' !== (string) ( $identity_failure['code'] ?? '' )
		|| ! empty( $identity_failure['mutation_started'] )
		|| empty( $identity_failure['transaction_rollback']['success'] )
		|| empty( $identity_failure['transaction_rollback']['rolled_back'] )
		|| empty( $identity_refresh['refreshed'] )
		|| 'changes_requested' !== (string) ( $stored_identity_job['status'] ?? '' )
		|| 2 !== absint( $stored_identity_job['submission_generation'] ?? 0 )
		|| 'staged_translation_identity_changed_before_locked_write' !== (string) ( $identity_history_latest['publication_failure_code'] ?? '' )
		|| $identity_artifact_bytes !== maybe_serialize( get_option( $identity_artifact_key ) )
		|| $identity_quality_bytes !== maybe_serialize( get_option( $identity_quality_key ) )
		|| $identity_evidence_bytes !== maybe_serialize( get_option( $identity_evidence_key ) )
	) {
		throw new RuntimeException( 'Identity-race lower-level proof did not preserve zero mutation, rollback, immutable records, and exact CAS refresh: ' . wp_json_encode( array( 'failure' => $identity_failure, 'refresh' => $identity_refresh, 'job' => $stored_identity_job ) ) );
	}

	// Preserve direct coverage for the baseline-drift code as a separate
	// deterministic transaction-boundary scenario. A direct persisted change to
	// the localized attachment alt after approval must be part of the current
	// surface revision, remain untouched by the stale artifact, and reopen only
	// through the bounded lifecycle.
	$baseline_job_id = 'tj_runtime_baseline_refresh_' . wp_generate_password( 8, false, false );
	$baseline_job_key = 'devenia_workflow_translation_job_' . $baseline_job_id;
	$baseline_artifact_revision = 'a_' . substr( hash( 'sha256', $baseline_job_id ), 0, 32 );
	$baseline_artifact_key = 'devenia_workflow_translation_artifact_' . $baseline_artifact_revision;
	$baseline_artifact_record = $current_artifact_record;
	$baseline_artifact_record['artifact_revision'] = $baseline_artifact_revision;
	$baseline_artifact_record['job_id'] = $baseline_job_id;
	$baseline_artifact_record['submission_generation'] = 1;
	update_option( $baseline_artifact_key, $call( 'translation_job_pack_artifact_record', $baseline_artifact_record ), false );
	$option_keys[] = $baseline_artifact_key;
	$baseline_job = array_merge(
		$current_main_job,
		array(
			'job_id' => $baseline_job_id,
			'schema_version' => 3,
			'status' => 'ready_to_publish',
			'submission_generation' => 1,
			'artifact_revision' => $baseline_artifact_revision,
			'surface_refresh_history' => array(),
			'run_ids' => array(),
			'active_run_id' => '',
		)
	);
	update_option( $baseline_job_key, $baseline_job, false );
	$option_keys[] = $baseline_job_key;
	$baseline_scope = $call( 'translation_job_publication_identity_scope', $baseline_job );
	$baseline_artifact_bytes = maybe_serialize( get_option( $baseline_artifact_key ) );
	$baseline_public_post = get_post( $translation_id );
	if ( ! $baseline_public_post instanceof WP_Post ) {
		throw new RuntimeException( 'Baseline-drift lower-level fixture could not read the published translation.' );
	}
	$baseline_original_featured_alt_exists = metadata_exists( 'post', $translation_id, Devenia_Workflow::META_FEATURED_IMAGE_ALT );
	$baseline_original_featured_alt = (string) get_post_meta( $translation_id, Devenia_Workflow::META_FEATURED_IMAGE_ALT, true );
	$baseline_approved_featured_alt = (string) ( $baseline_artifact_record['surface_manifest']['media']['featured_image_alt'] ?? '' );
	$baseline_original_surface_revision = $call( 'translation_job_current_surface_revision', $translation_id );
	$baseline_drift_surface_revision = '';
	$baseline_restored_surface_revision = '';
	$baseline_drift_featured_alt = 'Runtime localized featured-image alt drift ' . wp_generate_password( 8, false, false );
	$baseline_observed_featured_alt_after_failure = '';
	$baseline_snapshot = array();
	$baseline_failure = array();
	$baseline_refresh = array();
	try {
		$baseline_drift_update = update_post_meta( $translation_id, Devenia_Workflow::META_FEATURED_IMAGE_ALT, $baseline_drift_featured_alt );
		if ( false === $baseline_drift_update || $baseline_drift_featured_alt !== (string) get_post_meta( $translation_id, Devenia_Workflow::META_FEATURED_IMAGE_ALT, true ) ) {
			throw new RuntimeException( 'Baseline-drift lower-level fixture could not establish localized featured-image alt drift.' );
		}
		clean_post_cache( $translation_id );
		$call( 'sync_translation_index_row', $translation_id );
		$baseline_drift_surface_revision = $call( 'translation_job_current_surface_revision', $translation_id );
		$baseline_snapshot = $call( 'translation_job_capture_surface_snapshot', $translation_id, (array) $baseline_artifact_record['surface_manifest'], $baseline_scope );
		$baseline_failure = $call( 'translation_job_apply_staged_artifact', $baseline_job, $baseline_artifact_record, $baseline_snapshot );
		$baseline_refresh = $call( 'translation_job_refresh_drifted_surface', $baseline_job, 'publish_baseline_mismatch', $baseline_failure );
		$baseline_observed_featured_alt_after_failure = (string) get_post_meta( $translation_id, Devenia_Workflow::META_FEATURED_IMAGE_ALT, true );
	} finally {
		if ( $baseline_original_featured_alt_exists ) {
			$baseline_restore = update_post_meta( $translation_id, Devenia_Workflow::META_FEATURED_IMAGE_ALT, $baseline_original_featured_alt );
			if ( false === $baseline_restore && $baseline_original_featured_alt !== (string) get_post_meta( $translation_id, Devenia_Workflow::META_FEATURED_IMAGE_ALT, true ) ) {
				throw new RuntimeException( 'Baseline-drift lower-level fixture could not restore the localized featured-image alt.' );
			}
		} elseif ( ! delete_post_meta( $translation_id, Devenia_Workflow::META_FEATURED_IMAGE_ALT ) && metadata_exists( 'post', $translation_id, Devenia_Workflow::META_FEATURED_IMAGE_ALT ) ) {
			throw new RuntimeException( 'Baseline-drift lower-level fixture could not restore absent localized featured-image alt meta.' );
		}
		clean_post_cache( $translation_id );
		$call( 'sync_translation_index_row', $translation_id );
		$baseline_restored_surface_revision = $call( 'translation_job_current_surface_revision', $translation_id );
	}
	$stored_baseline_job = get_option( $baseline_job_key );
	$baseline_history = (array) ( $stored_baseline_job['surface_refresh_history'] ?? array() );
	$baseline_history_latest = $baseline_history ? (array) end( $baseline_history ) : array();
	if (
		empty( $baseline_snapshot['snapshot_valid'] )
		|| $baseline_original_surface_revision === $baseline_drift_surface_revision
		|| $baseline_approved_featured_alt === $baseline_drift_featured_alt
		|| (string) ( $baseline_artifact_record['baseline_surface_revision'] ?? '' ) === $baseline_drift_surface_revision
		|| $baseline_drift_surface_revision !== (string) ( $baseline_snapshot['captured_surface_revision'] ?? '' )
		|| $baseline_original_surface_revision !== $baseline_restored_surface_revision
		|| $baseline_drift_featured_alt !== $baseline_observed_featured_alt_after_failure
		|| $baseline_original_featured_alt !== (string) get_post_meta( $translation_id, Devenia_Workflow::META_FEATURED_IMAGE_ALT, true )
		|| $baseline_original_featured_alt_exists !== metadata_exists( 'post', $translation_id, Devenia_Workflow::META_FEATURED_IMAGE_ALT )
		|| 'staged_surface_drifted' !== (string) ( $baseline_failure['code'] ?? '' )
		|| ! empty( $baseline_failure['mutation_started'] )
		|| empty( $baseline_failure['transaction_rollback']['success'] )
		|| empty( $baseline_failure['transaction_rollback']['rolled_back'] )
		|| empty( $baseline_refresh['refreshed'] )
		|| 'changes_requested' !== (string) ( $stored_baseline_job['status'] ?? '' )
		|| 2 !== absint( $stored_baseline_job['submission_generation'] ?? 0 )
		|| 'staged_surface_drifted' !== (string) ( $baseline_history_latest['publication_failure_code'] ?? '' )
		|| $baseline_artifact_bytes !== maybe_serialize( get_option( $baseline_artifact_key ) )
		|| $identity_quality_bytes !== maybe_serialize( get_option( $identity_quality_key ) )
		|| $identity_evidence_bytes !== maybe_serialize( get_option( $identity_evidence_key ) )
	) {
		throw new RuntimeException( 'Localized featured-image alt baseline-drift proof did not preserve the direct edit, zero publication mutation, rollback, immutable records, and exact CAS refresh: ' . wp_json_encode( array( 'failure' => $baseline_failure, 'refresh' => $baseline_refresh, 'job' => $stored_baseline_job, 'original_surface_revision' => $baseline_original_surface_revision, 'drift_surface_revision' => $baseline_drift_surface_revision, 'restored_surface_revision' => $baseline_restored_surface_revision, 'approved_featured_image_alt' => $baseline_approved_featured_alt, 'drift_featured_image_alt' => $baseline_drift_featured_alt, 'observed_featured_image_alt_after_failure' => $baseline_observed_featured_alt_after_failure, 'public_drift_and_fixture_restoration_proven' => false ) ) );
	}
	$runtime_menu_ids[] = $runtime_active_menu_id;
	$primary_nav_issues_method = new ReflectionMethod( Devenia_Workflow::class, 'localized_primary_navigation_html_issues' );
	$primary_nav_issues_method->setAccessible( true );
	$english_cache_issues = (array) $primary_nav_issues_method->invoke(
		null,
		'<html><body><nav id="site-navigation"><a href="' . esc_url( home_url( '/' ) ) . '">Home</a><a href="' . esc_url( home_url( '/services/' ) ) . '">Services</a></nav></body></html>',
		$language,
		(string) get_permalink( $translation_id ),
		'canonical'
	);
	if ( empty( $english_cache_issues ) || 'frontend_primary_menu_projection_mismatch' !== (string) ( $english_cache_issues[0]['code'] ?? '' ) ) {
		throw new RuntimeException( 'Canonical English-menu cache fixture did not fail localized primary-navigation integrity.' );
	}

	$reader_identities = $runtime_identities;
	$reader_languages = Devenia_Workflow::languages( true );
	$reader_candidate_menu_id = wp_create_nav_menu( (string) ( $reader_languages[ $language ]['menu_name'] ?? '' ) );
	if ( is_wp_error( $reader_candidate_menu_id ) ) {
		throw new RuntimeException( 'Could not create the configured-name read-only identity fixture: ' . $reader_candidate_menu_id->get_error_message() );
	}
	add_term_meta( (int) $reader_candidate_menu_id, '_devenia_workflow_localized_menu_managed', '1', true );
	add_term_meta( (int) $reader_candidate_menu_id, '_devenia_workflow_localized_menu_language', $language, true );
	add_term_meta( (int) $reader_candidate_menu_id, '_devenia_workflow_public_header_manifest_revision', (string) $runtime_manifest['revision'], true );
	$runtime_menu_ids[] = (int) $reader_candidate_menu_id;
	unset( $reader_identities[ $language ] );
	update_option( 'devenia_workflow_localized_menu_identities', $reader_identities, false );
	$localized_menu_id = new ReflectionMethod( Devenia_Workflow::class, 'localized_menu_id' );
	$localized_menu_id->setAccessible( true );
	$reader_identity_before = get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' );
	$read_menu_id = (int) $localized_menu_id->invoke( null, $language );
	$read_expected_navigation = (array) $call( 'expected_localized_primary_navigation', $language );
	$read_identity_issues = (array) $call( 'localized_primary_navigation_html_issues', '<html><body><nav id="site-navigation"><a href="' . esc_url( home_url( '/' ) ) . '">Reader fixture</a></nav></body></html>', $language, (string) get_permalink( $translation_id ), 'canonical' );
	$reader_identity_after = get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' );
	if ( 0 !== $read_menu_id || ! empty( $read_expected_navigation ) || 'frontend_primary_menu_identity_missing' !== (string) ( $read_identity_issues[0]['code'] ?? '' ) || $reader_identity_before !== $reader_identity_after || $reader_identities !== $reader_identity_after ) {
		throw new RuntimeException( 'Ordinary identity reading or verification rediscovered or mutated a missing identity instead of failing closed.' );
	}
	update_option( 'devenia_workflow_localized_menu_identities', $runtime_identities, false );

	$fail_projection_write = static function () {
		return new WP_Error( 'runtime_projection_failure', 'Runtime projection failure fixture.' );
	};
	add_filter( 'devenia_workflow_localized_menu_projection_write_result', $fail_projection_write, 10, 5 );
	$failed_projection = $call( 'sync_language_menu', array( 'language' => $language, 'include_untranslated' => false, 'include_custom_links' => true, 'manifest' => $runtime_pending_manifest ) );
	remove_filter( 'devenia_workflow_localized_menu_projection_write_result', $fail_projection_write, 10 );
	$identity_after_failure = get_option( 'devenia_workflow_localized_menu_identities', array() );
	if ( ! empty( $failed_projection['success'] ) || 'menu_projection_write_failed' !== (string) ( $failed_projection['code'] ?? '' ) || $runtime_active_menu_id !== absint( $identity_after_failure[ $language ]['menu_id'] ?? 0 ) ) {
		throw new RuntimeException( 'Failed atomic menu projection did not preserve the active menu identity: ' . wp_json_encode( $failed_projection ) );
	}
	if ( $source_thumbnail_id !== absint( get_post_meta( $translation_id, '_thumbnail_id', true ) ) ) {
		throw new RuntimeException( 'Ready-to-publish Translation Job call did not reconcile stale featured media: ' . wp_json_encode( $published ) );
	}
	$stored_job = get_option( 'devenia_workflow_translation_job_' . $job_id );
	$stored_job['status'] = 'published';
	update_option( 'devenia_workflow_translation_job_' . $job_id, $stored_job, false );
	$published_artifact_before_media_drift = get_option( 'devenia_workflow_translation_artifact_' . (string) ( $stored_job['artifact_revision'] ?? '' ) );
	$published_quality_before_media_drift = get_option( 'devenia_workflow_translation_quality_' . (string) ( $stored_job['quality_revision'] ?? '' ) );
	delete_post_meta( $translation_id, '_thumbnail_id' );
	add_post_meta( $translation_id, '_thumbnail_id', $linked_source_id );
	add_post_meta( $translation_id, '_thumbnail_id', $source_thumbnail_id );
	$republished = $call(
		'translation_job_publish',
		array( 'job_id' => $job_id, 'coordinator_id' => 'runtime-coordinator', 'sync_menu' => false, 'verify_live' => true )
	);
	$published_job_after_media_drift = get_option( 'devenia_workflow_translation_job_' . $job_id );
	if (
		! empty( $republished['success'] )
		|| 'staged_surface_drifted' !== (string) ( $republished['code'] ?? '' )
		|| ! empty( $republished['mutation_started'] )
		|| empty( $republished['transaction_rollback']['success'] )
		|| empty( $republished['transaction_rollback']['rolled_back'] )
		|| ! isset( $republished['surface_refresh'] )
		|| ! empty( $republished['surface_refresh']['success'] )
		|| 'surface_refresh_generation_limit' !== (string) ( $republished['surface_refresh']['code'] ?? '' )
		|| 'failed_technical' !== (string) ( $republished['surface_refresh']['job']['status'] ?? '' )
		|| 'failed_technical' !== (string) ( $published_job_after_media_drift['status'] ?? '' )
		|| (string) ( $stored_job['artifact_revision'] ?? '' ) !== (string) ( $published_job_after_media_drift['artifact_revision'] ?? '' )
		|| (string) ( $stored_job['quality_revision'] ?? '' ) !== (string) ( $published_job_after_media_drift['quality_revision'] ?? '' )
		|| maybe_serialize( $published_artifact_before_media_drift ) !== maybe_serialize( get_option( 'devenia_workflow_translation_artifact_' . (string) ( $stored_job['artifact_revision'] ?? '' ) ) )
		|| maybe_serialize( $published_quality_before_media_drift ) !== maybe_serialize( get_option( 'devenia_workflow_translation_quality_' . (string) ( $stored_job['quality_revision'] ?? '' ) ) )
		|| $linked_source_id !== absint( get_post_meta( $translation_id, '_thumbnail_id', true ) )
	) {
		throw new RuntimeException( 'Published Translation Job media drift did not fail closed with immutable approved records and an explicit correction path: ' . wp_json_encode( array( 'publish' => $republished, 'stored_job' => $published_job_after_media_drift, 'effective_thumbnail_id' => get_post_meta( $translation_id, '_thumbnail_id', true ) ) ) );
	}
	// The next scenario independently exercises the published correction entry
	// path, so restore its exact pre-limit Job fixture without changing public data.
	update_option( 'devenia_workflow_translation_job_' . $job_id, $stored_job, false );
	// The next fixture independently proves the explicit published correction
	// lifecycle. Restore this deliberate drift first so its packet starts from a
	// stable public baseline rather than silently laundering unreviewed media.
	delete_post_meta( $translation_id, '_thumbnail_id' );
	add_post_meta( $translation_id, '_thumbnail_id', $source_thumbnail_id );
	clean_post_cache( $translation_id );
	$call( 'sync_translation_index_row', $translation_id );
	$published_job = get_option( 'devenia_workflow_translation_job_' . $job_id );
	$published_job['run_ids'] = array_values(
		array_filter(
			(array) $published_job['run_ids'],
			static function ( $row ) use ( $correction_run_id, $second_quality_run_id ) {
				return is_array( $row ) && ! in_array( (string) ( $row['run_id'] ?? '' ), array( $correction_run_id, $second_quality_run_id ), true );
			}
		)
	);
	update_option( 'devenia_workflow_translation_job_' . $job_id, $published_job, false );
	$build_runtime_correction_artifact = static function ( array $fresh_packet, string $title ) use ( $call ): array {
		$packet = (array) ( $fresh_packet['packet'] ?? array() );
		$packet_fragments = array_values( (array) ( $packet['fragments'] ?? array() ) );
		$packet_links = array_values( (array) ( $packet['links'] ?? array() ) );
		$expected_targets = array();
		$link_markup = array();
		$link_issues = array();
		foreach ( $packet_links as $link_index => $link ) {
			$source_url = (string) ( $link['source_url'] ?? '' );
			$target_url = (string) ( $link['target_url'] ?? '' );
			$policy = (string) ( $link['policy'] ?? '' );
			$target_key = '' !== $target_url ? (string) $call( 'normalized_comparable_url', $target_url ) : '';
			if (
				'' === $source_url
				|| '' === $target_url
				|| '' === $target_key
				|| ! in_array( $policy, array( 'retain_source_url_until_localized_target_is_published', 'use_published_localized_target' ), true )
			) {
				$link_issues[] = array( 'index' => $link_index, 'source_url' => $source_url, 'target_url' => $target_url, 'policy' => $policy );
				continue;
			}
			$expected_targets[ $target_key ] = $target_url;
			$link_markup[] = '<a href="' . esc_url( $target_url ) . '">den lenkede kilden</a>';
		}

		$localized_fragments = array();
		$fragment_keys = array();
		foreach ( $packet_fragments as $fragment_index => $fragment ) {
			$key = (string) ( $fragment['key'] ?? '' );
			if ( '' === $key || isset( $fragment_keys[ $key ] ) ) {
				return array( 'success' => false, 'code' => 'runtime_correction_packet_fragment_invalid', 'fragment_index' => $fragment_index, 'fragment_key' => $key );
			}
			$fragment_keys[ $key ] = true;
			$localized_fragments[] = array(
				'key' => $key,
				'html' => '<strong>Nyttig innhold</strong><br>'
					. ( $link_markup ? 'Les ' . implode( ' og ', $link_markup ) . ', og ' : '' )
					. '<a href="mailto:hello@example.com?subject=Sp%C3%B8rsm%C3%A5l%20om%20testen&amp;body=Hei%20fra%20oversettelsen">kontakt oss</a> for et konkret neste steg.',
			);
		}

		$artifact = array(
			'title' => $title,
			'excerpt' => 'En nyttig oversatt ingress.',
			'localized_slug' => 'must-not-replace-published-route',
			'localized_fragments' => $localized_fragments,
			'seo' => array( 'title' => 'Oversatt testside', 'description' => 'En nyttig beskrivelse av den oversatte testsiden.', 'focus_keyword' => '' ),
		);
		$consumed_targets = array();
		foreach ( $localized_fragments as $fragment ) {
			foreach ( $call( 'translation_job_anchor_hrefs', (string) $fragment['html'] ) as $href ) {
				$absolute = (string) $call( 'translation_job_absolute_internal_url', (string) $href );
				$key = '' !== $absolute ? (string) $call( 'normalized_comparable_url', $absolute ) : '';
				if ( '' !== $key && isset( $expected_targets[ $key ] ) ) {
					$consumed_targets[ $key ] = $expected_targets[ $key ];
				}
			}
		}
		$missing_targets = array_values( array_diff_key( $expected_targets, $consumed_targets ) );

		return array(
			'success' => empty( $link_issues ) && ! empty( $packet_fragments ) && empty( $missing_targets ),
			'code' => empty( $link_issues ) ? ( $packet_fragments ? ( $missing_targets ? 'runtime_correction_packet_targets_missing' : 'runtime_correction_artifact_built' ) : 'runtime_correction_packet_fragments_missing' ) : 'runtime_correction_packet_links_invalid',
			'artifact' => $artifact,
			'packet_link_count' => count( $packet_links ),
			'expected_target_urls' => array_values( $expected_targets ),
			'consumed_target_urls' => array_values( $consumed_targets ),
			'missing_target_urls' => $missing_targets,
			'link_issues' => $link_issues,
		);
	};
	$post_publish_run_id = 'runtime-translator-post-publish-' . wp_generate_password( 8, false, false );
	$post_publish_claim = $call(
		'translation_job_claim',
		array(
			'job_id' => $job_id,
			'run_id' => $post_publish_run_id,
			'coordinator_id' => 'runtime-coordinator',
			'role' => 'translator',
			'ttl_seconds' => 600,
		)
	);
	if ( empty( $post_publish_claim['success'] ) || 'published' !== (string) ( $post_publish_claim['claim']['previous_status'] ?? '' ) ) {
		throw new RuntimeException( 'Published Job could not enter a bounded correction Run: ' . wp_json_encode( $post_publish_claim ) );
	}
	$option_keys[] = 'devenia_workflow_translation_run_' . $post_publish_run_id;
	$post_publish_packet = $call(
		'translation_job_fetch_packet',
		array(
			'job_id' => $job_id,
			'run_id' => $post_publish_run_id,
			'claim_token' => (string) $post_publish_claim['claim_token'],
		)
	);
	if (
		empty( $post_publish_packet['success'] )
		|| $translation_id !== absint( $post_publish_packet['packet']['route']['existing']['translation_id'] ?? 0 )
	) {
		throw new RuntimeException( 'Published correction packet missing: ' . wp_json_encode( $post_publish_packet ) );
	}
	$post_publish_artifact_build = $build_runtime_correction_artifact( $post_publish_packet, 'Runtime translated title corrected after browser QA' );
	$post_publish_artifact = (array) ( $post_publish_artifact_build['artifact'] ?? array() );
	if (
		empty( $post_publish_artifact_build['success'] )
		|| (array) ( $post_publish_artifact_build['expected_target_urls'] ?? array() ) !== (array) ( $post_publish_artifact_build['consumed_target_urls'] ?? array() )
		|| ! empty( $post_publish_artifact_build['missing_target_urls'] )
	) {
		throw new RuntimeException( 'Published correction Artifact did not consume every authoritative target from its fresh packet: ' . wp_json_encode( $post_publish_artifact_build ) );
	}
	$published_route_before = (string) get_permalink( $translation_id );
	$published_slug_before  = (string) get_post_field( 'post_name', $translation_id );
	$published_title_before = (string) get_post_field( 'post_title', $translation_id );
	$post_publish_submit = $call(
		'translation_job_submit_artifact',
		array(
			'job_id' => $job_id,
			'run_id' => $post_publish_run_id,
			'claim_token' => (string) $post_publish_claim['claim_token'],
			'artifact' => $post_publish_artifact,
			'usage' => array( 'input_tokens' => 700, 'cached_input_tokens' => 0, 'output_tokens' => 200, 'attempts' => 1, 'duration_ms' => 700, 'estimated_cost_microusd' => 50 ),
		)
	);
	if (
		empty( $post_publish_submit['success'] )
		|| 'publish' !== get_post_status( $translation_id )
		|| 'quality_pending' !== (string) ( $post_publish_submit['job']['status'] ?? '' )
		|| $published_title_before !== (string) get_post_field( 'post_title', $translation_id )
		|| $published_slug_before !== (string) get_post_field( 'post_name', $translation_id )
		|| $published_route_before !== (string) get_permalink( $translation_id )
	) {
		throw new RuntimeException( 'Published correction artifact was not saved safely: ' . wp_json_encode( $post_publish_submit ) );
	}
	$post_publish_artifact_record = $call(
		'translation_job_unpack_artifact_record',
		get_option( 'devenia_workflow_translation_artifact_' . (string) $post_publish_submit['artifact_revision'] )
	);
	if (
		'Runtime translated title corrected after browser QA' !== (string) ( $post_publish_artifact_record['artifact']['title'] ?? '' )
		|| $published_title_before === (string) ( $post_publish_artifact_record['artifact']['title'] ?? '' )
	) {
		throw new RuntimeException( 'Published correction did not preserve the new title exclusively in its immutable staged artifact.' );
	}
	$option_keys[] = 'devenia_workflow_translation_artifact_' . (string) $post_publish_submit['artifact_revision'];
	$post_publish_quality_run_id = 'runtime-quality-post-publish-' . wp_generate_password( 8, false, false );
	$post_publish_quality_claim = $call(
		'translation_job_claim',
		array(
			'job_id' => $job_id,
			'run_id' => $post_publish_quality_run_id,
			'coordinator_id' => 'runtime-coordinator',
			'role' => 'quality',
			'ttl_seconds' => 600,
		)
	);
	if ( empty( $post_publish_quality_claim['success'] ) ) {
		throw new RuntimeException( 'Published correction could not enter bounded quality review: ' . wp_json_encode( $post_publish_quality_claim ) );
	}
	$option_keys[] = 'devenia_workflow_translation_run_' . $post_publish_quality_run_id;
	$post_publish_quality = $call(
		'translation_job_submit_quality_decision',
		$quality_payload( $post_publish_quality_claim, (string) $post_publish_submit['artifact_revision'], 'revise', 'Runtime contract confirms browser QA corrections on a published translation remain bounded and require a new exact quality decision before republishing.', array( 'The fixture deliberately requests one more correction because its dev-only public URL has no language prefix.' ) )
	);
	$track_quality_result( $post_publish_quality );
	if ( empty( $post_publish_quality['success'] ) || 'changes_requested' !== (string) ( $post_publish_quality['job']['status'] ?? '' ) ) {
		throw new RuntimeException( 'Published correction did not receive an exact Quality Decision: ' . wp_json_encode( $post_publish_quality ) );
	}
	if (
		empty( $post_publish_artifact_record['writer_principal']['principal_id'] )
		|| (string) $post_publish_artifact_record['writer_principal']['principal_id'] === (string) ( $post_publish_quality['quality_decision']['reviewer_principal']['principal_id'] ?? '' )
		|| (string) ( $post_publish_artifact_record['writer_principal']['run_id'] ?? '' ) === (string) ( $post_publish_quality['quality_decision']['reviewer_principal']['run_id'] ?? '' )
	) {
		throw new RuntimeException( 'Published correction did not retain explicit translator and Quality Run principal separation.' );
	}
	if (
		'publish' !== get_post_status( $translation_id )
		|| $published_title_before !== (string) get_post_field( 'post_title', $translation_id )
		|| $published_slug_before !== (string) get_post_field( 'post_name', $translation_id )
		|| $published_route_before !== (string) get_permalink( $translation_id )
	) {
		throw new RuntimeException( 'Published correction staging changed the live title, status, slug, or route before a fresh passing Quality Decision and publication.' );
	}

	// Establish a fresh ready-to-publish correction whose captured reader
	// baseline has exactly one valid featured-image authority. The commit
	// reconciliation matrix must not inherit the deliberately invalid duplicate
	// thumbnail baseline used by the earlier generation-limit fixture.
	$post_publish_final_run_id = 'runtime-translator-post-publish-final-' . wp_generate_password( 8, false, false );
	$post_publish_final_claim = $call(
		'translation_job_claim',
		array(
			'job_id' => $job_id,
			'run_id' => $post_publish_final_run_id,
			'coordinator_id' => 'runtime-coordinator',
			'role' => 'translator',
			'ttl_seconds' => 600,
		)
	);
	if ( empty( $post_publish_final_claim['success'] ) || 'changes_requested' !== (string) ( $post_publish_final_claim['claim']['previous_status'] ?? '' ) ) {
		throw new RuntimeException( 'Final published-correction translator Run could not claim the reviewed correction: ' . wp_json_encode( $post_publish_final_claim ) );
	}
	$option_keys[] = 'devenia_workflow_translation_run_' . $post_publish_final_run_id;
	$post_publish_final_packet = $call(
		'translation_job_fetch_packet',
		array(
			'job_id' => $job_id,
			'run_id' => $post_publish_final_run_id,
			'claim_token' => (string) $post_publish_final_claim['claim_token'],
		)
	);
	if (
		empty( $post_publish_final_packet['success'] )
		|| (string) $post_publish_submit['artifact_revision'] !== (string) ( $post_publish_final_packet['packet']['correction_context']['previous_artifact_revision'] ?? '' )
		|| empty( $post_publish_final_packet['packet']['correction_context']['previous_artifact']['artifact'] )
		|| empty( $post_publish_final_packet['packet']['correction_context']['corrections'] )
	) {
		throw new RuntimeException( 'Final published-correction packet did not bind the exact prior artifact and requested correction: ' . wp_json_encode( $post_publish_final_packet ) );
	}
	$post_publish_final_artifact_build = $build_runtime_correction_artifact( $post_publish_final_packet, 'Runtime translated title approved after browser QA' );
	$post_publish_final_artifact = (array) ( $post_publish_final_artifact_build['artifact'] ?? array() );
	if (
		empty( $post_publish_final_artifact_build['success'] )
		|| (array) ( $post_publish_final_artifact_build['expected_target_urls'] ?? array() ) !== (array) ( $post_publish_final_artifact_build['consumed_target_urls'] ?? array() )
		|| ! empty( $post_publish_final_artifact_build['missing_target_urls'] )
	) {
		throw new RuntimeException( 'Final published correction Artifact did not consume every authoritative target from its fresh packet: ' . wp_json_encode( $post_publish_final_artifact_build ) );
	}
	$post_publish_final_submit = $call(
		'translation_job_submit_artifact',
		array(
			'job_id' => $job_id,
			'run_id' => $post_publish_final_run_id,
			'claim_token' => (string) $post_publish_final_claim['claim_token'],
			'artifact' => $post_publish_final_artifact,
			'usage' => array( 'input_tokens' => 700, 'cached_input_tokens' => 0, 'output_tokens' => 200, 'attempts' => 1, 'duration_ms' => 700, 'estimated_cost_microusd' => 50 ),
		)
	);
	$post_publish_final_artifact_revision = (string) ( $post_publish_final_submit['artifact_revision'] ?? '' );
	$option_keys[] = 'devenia_workflow_translation_artifact_' . $post_publish_final_artifact_revision;
	$post_publish_final_artifact_record = $call( 'translation_job_unpack_artifact_record', get_option( 'devenia_workflow_translation_artifact_' . $post_publish_final_artifact_revision ) );
	if (
		empty( $post_publish_final_submit['success'] )
		|| 'quality_pending' !== (string) ( $post_publish_final_submit['job']['status'] ?? '' )
		|| '' === $post_publish_final_artifact_revision
		|| $post_publish_final_artifact_revision === (string) $post_publish_submit['artifact_revision']
		|| $published_title_before !== (string) get_post_field( 'post_title', $translation_id )
		|| $published_slug_before !== (string) get_post_field( 'post_name', $translation_id )
		|| $published_route_before !== (string) get_permalink( $translation_id )
	) {
		throw new RuntimeException( 'Final published-correction artifact was not staged immutably over the stable public baseline: ' . wp_json_encode( $post_publish_final_submit ) );
	}
	$post_publish_final_quality_run_id = 'runtime-quality-post-publish-final-' . wp_generate_password( 8, false, false );
	$post_publish_final_quality_claim = $call(
		'translation_job_claim',
		array(
			'job_id' => $job_id,
			'run_id' => $post_publish_final_quality_run_id,
			'coordinator_id' => 'runtime-coordinator',
			'role' => 'quality',
			'ttl_seconds' => 600,
		)
	);
	if ( empty( $post_publish_final_quality_claim['success'] ) ) {
		throw new RuntimeException( 'Final published correction could not enter independent Quality review: ' . wp_json_encode( $post_publish_final_quality_claim ) );
	}
	$option_keys[] = 'devenia_workflow_translation_run_' . $post_publish_final_quality_run_id;
	$post_publish_final_quality = $call(
		'translation_job_submit_quality_decision',
		$quality_payload( $post_publish_final_quality_claim, $post_publish_final_artifact_revision, 'pass', 'A fresh independent Quality principal approved the exact final correction on a stable one-attachment public baseline.', array() )
	);
	$track_quality_result( $post_publish_final_quality );
	$post_publish_matrix_job = get_option( $attempt_limit_job_key );
	$post_publish_matrix_thumbnail_rows = array_values( array_map( 'absint', (array) get_post_meta( $translation_id, '_thumbnail_id', false ) ) );
	$post_publish_matrix_header = array(
		'manifest' => get_option( 'devenia_workflow_public_header_manifest', array() ),
		'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ),
		'identities' => get_option( 'devenia_workflow_localized_menu_identities', array() ),
		'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '' ),
	);
	$post_publish_matrix_header_complete = count( (array) $post_publish_matrix_header['identities'] ) === count( $runtime_languages );
	foreach ( array_keys( $runtime_languages ) as $post_publish_matrix_header_language ) {
		$post_publish_matrix_header_identity = (array) ( $post_publish_matrix_header['identities'][ $post_publish_matrix_header_language ] ?? array() );
		$post_publish_matrix_header_complete = $post_publish_matrix_header_complete
			&& absint( $post_publish_matrix_header_identity['menu_id'] ?? 0 ) > 0
			&& (string) ( $runtime_manifest['revision'] ?? '' ) === (string) ( $post_publish_matrix_header_identity['manifest_revision'] ?? '' );
	}
	if (
		empty( $post_publish_final_quality['success'] )
		|| 'ready_to_publish' !== (string) ( $post_publish_matrix_job['status'] ?? '' )
		|| $post_publish_final_artifact_revision !== (string) ( $post_publish_matrix_job['artifact_revision'] ?? '' )
		|| (string) ( $post_publish_final_artifact_record['writer_principal']['principal_id'] ?? '' ) === (string) ( $post_publish_final_quality['quality_decision']['reviewer_principal']['principal_id'] ?? '' )
		|| (string) ( $post_publish_final_artifact_record['writer_principal']['run_id'] ?? '' ) === (string) ( $post_publish_final_quality['quality_decision']['reviewer_principal']['run_id'] ?? '' )
		|| array( $source_thumbnail_id ) !== $post_publish_matrix_thumbnail_rows
		|| $source_thumbnail_id !== absint( get_post_thumbnail_id( $translation_id ) )
		|| ! $post_publish_matrix_header_complete
		|| '1' !== (string) $post_publish_matrix_header['enrollment']
		|| (string) ( $runtime_manifest['revision'] ?? '' ) !== (string) ( $post_publish_matrix_header['manifest']['revision'] ?? '' )
		|| '__workflow_missing__' !== $post_publish_matrix_header['pending']
	) {
		throw new RuntimeException( 'Commit reconciliation matrix did not receive a fresh separately reviewed artifact with one exact valid media authority and complete active Public Header: ' . wp_json_encode( array( 'quality' => $post_publish_final_quality, 'job' => $post_publish_matrix_job, 'thumbnail_rows' => $post_publish_matrix_thumbnail_rows, 'header' => $post_publish_matrix_header ) ) );
	}
	$run_commit_reconciliation_matrices( $job_id, $attempt_limit_job_key );
	if ( 'ready_to_publish' !== (string) ( get_option( $attempt_limit_job_key )['status'] ?? '' ) || array( $source_thumbnail_id ) !== array_values( array_map( 'absint', (array) get_post_meta( $translation_id, '_thumbnail_id', false ) ) ) ) {
		throw new RuntimeException( 'Commit reconciliation matrix did not preserve the fresh correction Job and its exact single featured-image authority.' );
	}
	$orphaned_run = get_option( 'devenia_workflow_translation_run_' . $translator_run_id );
	$orphaned_run['status'] = 'running';
	unset( $orphaned_run['outcome'], $orphaned_run['finished_at'] );
	update_option( 'devenia_workflow_translation_run_' . $translator_run_id, $orphaned_run, false );
	$finalized_count = $call( 'translation_job_finalize_orphaned_runs', get_option( 'devenia_workflow_translation_job_' . $job_id ) );
	$finalized_orphaned_run = get_option( 'devenia_workflow_translation_run_' . $translator_run_id );
	if (
		1 !== $finalized_count
		|| 'completed' !== (string) ( $finalized_orphaned_run['status'] ?? '' )
		|| 'expired' !== (string) ( $finalized_orphaned_run['outcome'] ?? '' )
	) {
		throw new RuntimeException( 'Orphaned Run finalization failed: ' . wp_json_encode( $finalized_orphaned_run ) );
	}

	$runtime_result = array(
			'success' => true,
			'job_status' => 'published_media_drift_failed_closed',
			'packet_fragment_count' => count( $fragments ),
			'inline_markup_kept' => true,
			'claim_token_hashed' => true,
			'unapproved_source_blocked' => true,
			'translation_saved' => $translation_id > 0,
			'correction_context_included' => true,
			'link_policy_in_packets' => true,
			'native_dynamic_query_link_preserved_outside_fragment_policy' => true,
			'extra_internal_target_rejected_at_artifact_submit' => true,
			'invented_localized_link_blocked' => true,
			'third_bounded_runs_available' => true,
			'full_fragment_wrappers_normalized' => true,
			'source_scoped_preserve_terms_in_packet' => true,
			'submission_contracts_in_packets' => true,
			'submission_contracts_share_live_schema' => true,
			'runtime_text_uses_wordpress_capability' => true,
				'social_sharing_uses_semantic_runtime_key' => true,
			'mailto_query_copy_must_be_localized' => true,
			'featured_image_synchronized_before_quality' => true,
			'source_publication_surface_media_reopens_all_language_obligations' => true,
			'same_id_attachment_bytes_change_revision_but_mtime_does_not' => true,
			'featured_image_srcset_candidate_descriptor_set_is_exact' => true,
			'rollback_media_purge_origin_canonical_success_and_failure_proven' => true,
			'featured_image_repair_routes_through_bounded_lifecycle_without_public_mutation' => true,
			'approved_empty_rankmath_focus_keyword_deleted_exactly' => true,
			'published_job_media_drift_failed_closed_without_silent_reconcile' => true,
			'duplicate_thumbnail_meta_drift_used_wordpress_effective_value' => true,
			'published_job_browser_correction_reentered_bounded_lifecycle' => true,
			'published_job_correction_remained_staged_during_quality_review' => true,
			'published_job_correction_used_separate_writer_and_quality_principals' => true,
			'published_job_route_preserved_during_correction' => true,
			'canonical_route_mismatch_rejected_before_artifact_job_or_public_mutation' => true,
			'nested_observed_wordpress_route_accepted_without_route_hardcoding' => true,
			'legacy_canonical_route_staged_backfilled_verified_without_url_migration' => true,
			'legacy_canonical_route_backfill_rollback_restored_absent_meta' => true,
			'orphaned_quality_decision_recovered' => true,
			'expired_run_finalized_before_reclaim' => true,
			'expired_quality_run_does_not_consume_attempt' => true,
			'abandoned_run_does_not_consume_attempt' => true,
			'substantive_run_attempt_limit_enforced' => true,
			'quality_pending_contract_gap_reopened_for_translator' => true,
			'orphaned_run_finalized_during_publish' => true,
			'translation_publish_preserved_seeded_menu_identity' => true,
			'atomic_menu_failure_preserved_active_identity' => true,
			'ordinary_identity_reader_failed_closed_without_migration' => true,
			'ordinary_translation_job_wrong_index_preserved_raw_authority' => true,
			'frontend_cache_invalidation_adapter_consumed' => true,
			'canonical_english_menu_cache_rejected' => true,
			'publish_surface_drift_reopened_after_zero_mutation_and_rollback' => true,
			'publish_snapshot_to_lock_surface_drift_reopened_in_same_call' => true,
			'publish_identity_drift_lower_level_transaction_proven' => true,
			'publish_baseline_drift_lower_level_transaction_proven' => true,
			'localized_featured_image_alt_drift_reopened_before_stale_publication_mutation' => true,
			'publish_lifecycle_lease_blocked_concurrent_claim' => true,
			'claim_winner_forced_publish_reread_before_mutation' => true,
			'claim_lifecycle_lease_released_before_run_lifetime' => true,
			'surface_refresh_history_preserved_immutable_records' => true,
			'refreshed_packet_coherent_payload_created_new_generation_artifact' => true,
			'refreshed_translator_abandon_restored_changes_requested' => true,
			'quality_path_defensively_reopened_drifted_baseline' => true,
			'ready_to_publish_claim_defensively_reopened' => true,
			'surface_refresh_generation_limit_failed_closed' => true,
			'publish_coordinator_label_not_required_for_authority' => true,
			'same_second_lifecycle_lease_renewal_advanced_owned_cas' => true,
			'foreign_publication_revision_never_became_rollback_authority' => true,
			'foreign_publication_true_and_unknown_preserved_job_content_header' => true,
			'malformed_staged_commit_receipt_rejected_after_exact_applied_replacement' => true,
			'malformed_restore_commit_receipt_rejected_after_exact_restored_state' => true,
			'present_non_array_commit_adapter_never_selected_default_commit' => true,
			'active_staged_transaction_claimed_commit_terminalized_without_publication' => true,
			'active_snapshot_transaction_claimed_commit_terminalized_without_snapshot_authority' => true,
			'active_restore_transaction_claimed_commit_terminalized_without_restore_progress' => true,
		);
} catch ( Throwable $error ) {
	$runtime_error = $error;
} finally {
	if ( $runtime_batch_http ) {
		remove_filter( 'devenia_workflow_frontend_cache_batch_adapter_result', $runtime_batch_http, 10 );
	}
	if ( $runtime_http_surface ) {
		remove_filter( 'pre_http_request', $runtime_http_surface, 10 );
	}
	if ( $runtime_page_link ) {
		remove_filter( 'page_link', $runtime_page_link, 10 );
	}
	remove_filter( 'devenia_workflow_frontend_cache_invalidation_result', $cache_adapter, 10 );
	wp_set_current_user( $original_user_id );
	update_option( 'devenia_workflow_language_registry', $languages_option_before, false );
	update_option( 'devenia_workflow_runtime_mutation_provenance', $runtime_provenance_before, false );
	set_theme_mod( 'nav_menu_locations', is_array( $nav_menu_locations_before ) ? $nav_menu_locations_before : array() );
	foreach (
		array(
			'show_on_front' => $show_on_front_before,
			'page_on_front' => $page_on_front_before,
			'page_for_posts' => $page_for_posts_before,
		) as $runtime_option_key => $runtime_option_before
	) {
		if ( $runtime_option_missing === $runtime_option_before ) {
			delete_option( $runtime_option_key );
		} else {
			update_option( $runtime_option_key, $runtime_option_before, false );
		}
	}
	if ( false === $menu_identities_before ) {
		delete_option( 'devenia_workflow_localized_menu_identities' );
	} else {
		update_option( 'devenia_workflow_localized_menu_identities', $menu_identities_before, false );
	}
	if ( false === $public_header_manifest_before ) {
		delete_option( 'devenia_workflow_public_header_manifest' );
	} else {
		update_option( 'devenia_workflow_public_header_manifest', $public_header_manifest_before, false );
	}
	if ( false === $pending_public_header_manifest_before ) {
		delete_option( 'devenia_workflow_pending_public_header_manifest' );
	} else {
		update_option( 'devenia_workflow_pending_public_header_manifest', $pending_public_header_manifest_before, false );
	}
	if ( false === $public_header_enrollment_before ) {
		delete_option( 'devenia_workflow_public_header_enrollment' );
	} else {
		update_option( 'devenia_workflow_public_header_enrollment', $public_header_enrollment_before, false );
	}
	Devenia_Workflow::languages( true );
	foreach ( array_unique( $runtime_menu_ids ) as $runtime_menu_id ) {
		if ( $runtime_menu_id > 0 && '1' === (string) get_term_meta( $runtime_menu_id, '_devenia_workflow_localized_menu_managed', true ) ) {
			wp_delete_nav_menu( $runtime_menu_id );
		}
	}
	if ( $runtime_source_menu_id > 0 ) {
		wp_delete_nav_menu( $runtime_source_menu_id );
	}
	foreach ( array_unique( array_values( $runtime_header_translation_ids_by_language ) ) as $runtime_header_translation_id ) {
		if ( $runtime_header_translation_id > 0 ) {
			wp_delete_post( $runtime_header_translation_id, true );
		}
	}
	foreach ( array_unique( array_values( $runtime_header_blog_translation_ids_by_language ) ) as $runtime_header_blog_translation_id ) {
		if ( $runtime_header_blog_translation_id > 0 ) {
			wp_delete_post( $runtime_header_blog_translation_id, true );
		}
	}
	if ( $runtime_header_source_id > 0 ) {
		wp_delete_post( $runtime_header_source_id, true );
	}
	if ( $runtime_header_blog_source_id > 0 ) {
		wp_delete_post( $runtime_header_blog_source_id, true );
	}
	if ( $translation_id > 0 ) {
		wp_delete_post( $translation_id, true );
	}
	if ( $nested_route_translation_id > 0 ) {
		wp_delete_post( $nested_route_translation_id, true );
	}
	foreach ( $nested_route_parent_ids as $nested_route_parent_id ) {
		if ( $nested_route_parent_id > 0 ) {
			wp_delete_post( $nested_route_parent_id, true );
		}
	}
	if ( $source_id > 0 ) {
		wp_delete_post( $source_id, true );
	}
	if ( $linked_source_id > 0 ) {
		wp_delete_post( $linked_source_id, true );
	}
	if ( $localized_link_target_id > 0 ) {
		wp_delete_post( $localized_link_target_id, true );
	}
	if ( $localized_link_source_id > 0 ) {
		wp_delete_post( $localized_link_source_id, true );
	}
	if ( $source_thumbnail_id > 0 ) {
		wp_delete_attachment( $source_thumbnail_id, true );
	}
	if ( $replacement_thumbnail_id > 0 ) {
		wp_delete_attachment( $replacement_thumbnail_id, true );
	}
	foreach ( array( $source_thumbnail_file, $replacement_thumbnail_file ) as $fixture_file ) {
		if ( is_string( $fixture_file ) && '' !== $fixture_file && file_exists( $fixture_file ) ) { unlink( $fixture_file ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Ephemeral runtime fixture cleanup.
	}
	foreach ( array_unique( $option_keys ) as $option_key ) {
		delete_option( $option_key );
	}
	foreach (
		array(
			'devenia_workflow_source_inventory_active' => $source_inventory_active_before,
			'devenia_workflow_source_inventory_rebuild' => $source_inventory_rebuild_before,
			'devenia_workflow_source_inventory_epoch' => $source_inventory_epoch_before,
			'devenia_workflow_source_inventory_dirty' => $source_inventory_dirty_before,
		) as $inventory_option_key => $inventory_option_before
	) {
		if ( false === $inventory_option_before ) {
			delete_option( $inventory_option_key );
		} else {
			update_option( $inventory_option_key, $inventory_option_before, false );
		}
	}
}

if ( $runtime_error instanceof Throwable ) {
	fwrite( STDERR, wp_json_encode( array( 'success' => false, 'error' => $runtime_error->getMessage() ) ) . PHP_EOL );
	exit( 1 );
}

echo wp_json_encode( $runtime_result ) . PHP_EOL;
