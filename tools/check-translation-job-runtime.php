<?php
/**
 * Dev runtime contract for the finite Translation Job lifecycle.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$source_id = 0;
$linked_source_id = 0;
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
$source_inventory_dirty_before = get_option( 'devenia_workflow_source_inventory_dirty' );
$nav_menu_locations_before = get_theme_mod( 'nav_menu_locations', array() );
$runtime_menu_ids = array();
$runtime_source_menu_id = 0;
$runtime_page_link = null;
$runtime_http_surface = null;
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
$keep_previous_menu = static function (): bool {
	return false;
};
add_filter( 'devenia_workflow_frontend_cache_invalidation_result', $cache_adapter, 10, 3 );
add_filter( 'devenia_workflow_retire_previous_localized_menu_projection', $keep_previous_menu, 10, 4 );

$call = static function ( string $method, ...$arguments ) {
	$reflection = new ReflectionMethod( Devenia_Workflow::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $arguments );
};
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
	foreach ( array( 'desktop' => array( 'width' => 1440, 'height' => 1100, 'device_scale_factor' => 1 ), 'mobile' => array( 'width' => 390, 'height' => 844, 'device_scale_factor' => 1 ) ) as $viewport_scheme => $viewport ) {
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

try {
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
			'post_content' => '<!-- wp:paragraph --><p><strong>Useful source</strong><br>Read <a href="' . esc_url( $linked_source_url ) . '">the linked source</a>, then <a href="mailto:hello@example.com?subject=Source%20question&amp;body=Hello%20from%20the%20source">contact us</a> for a concrete next step.</p><!-- /wp:paragraph -->',
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
	$contract_gap_job['active_run_id'] = '';
	update_option( $contract_gap_job_key, $contract_gap_job, false );
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
		|| 'artifact_fragment_contract_changed' !== (string) ( $contract_refresh_claim['job']['contract_refresh']['reason'] ?? '' )
		|| empty( $contract_refresh_claim['job']['contract_refresh']['coverage']['missing_keys'] )
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
	$expected_packet_link_url = isset( $links[0]['target_url'] ) ? (string) $links[0]['target_url'] : '';
	if (
		empty( $packet['success'] )
		|| $translation_id !== absint( $packet['packet']['route']['existing']['translation_id'] ?? 0 )
		|| $runtime_localized_path !== (string) ( $packet['packet']['route']['existing']['localized_path'] ?? '' )
		|| 1 !== count( $fragments )
		|| false === stripos( (string) $fragments[0]['source_html'], '<strong>' )
		|| 1 !== count( $links )
		|| '' === $expected_packet_link_url
		|| ! in_array( (string) ( $links[0]['policy'] ?? '' ), array( 'retain_source_url_until_localized_target_is_published', 'use_published_localized_target' ), true )
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

	$localized = array();
	foreach ( $fragments as $fragment ) {
		$localized[] = array( 'key' => (string) $fragment['key'], 'html' => '<strong>Nyttig innhold</strong><br>Les <a href="' . esc_url( $expected_packet_link_url ) . '">den lenkede kilden</a>, og <a href="mailto:hello@example.com?subject=Sp%C3%B8rsm%C3%A5l%20om%20testen&amp;body=Hei%20fra%20oversettelsen">kontakt oss</a> for et konkret neste steg.' );
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

	$runtime_page_link = static function ( string $url, int $post_id ) use ( &$translation_id, $language ): string {
		if ( $post_id !== $translation_id ) {
			return $url;
		}
		$post = get_post( $post_id );
		return home_url( '/' . trim( $language . '/' . ( $post instanceof WP_Post ? $post->post_name : '' ), '/' ) . '/' );
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
	if (
		$legacy_effective_route !== (array) ( $legacy_staged_record['surface_manifest']['route']['canonical_route'] ?? array() )
		|| metadata_exists( 'post', $translation_id, '_devenia_translation_canonical_route_v1' )
	) {
		throw new RuntimeException( 'Staging did not sign the deterministic legacy Canonical Route Contract without a public write: ' . wp_json_encode( $legacy_staged_record['surface_manifest']['route'] ?? array() ) );
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
	$runtime_source_menu_id = wp_create_nav_menu( 'Workflow runtime source ' . wp_generate_password( 8, false, false ) );
	if ( is_wp_error( $runtime_source_menu_id ) ) {
		throw new RuntimeException( 'Could not create the runtime source menu: ' . $runtime_source_menu_id->get_error_message() );
	}
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
			'menu-item-object-id' => $source_id,
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
	$runtime_languages = get_option( 'devenia_workflow_language_registry', array() );
	$runtime_languages = is_array( $runtime_languages ) ? $runtime_languages : array();
	$runtime_languages['en']['menu_name'] = (string) wp_get_nav_menu_object( $runtime_source_menu_id )->name;
	$runtime_languages[ $language ]['menu_name'] = 'Workflow runtime target ' . wp_generate_password( 8, false, false );
	update_option( 'devenia_workflow_language_registry', $runtime_languages, false );
	Devenia_Workflow::languages( true );
	$source_language_code = $call( 'source_language_code' );
	$runtime_group_labels = array();
	$runtime_source_labels = array();
	foreach ( array_keys( Devenia_Workflow::languages() ) as $runtime_header_language ) {
		$runtime_group_labels[ $runtime_header_language ] = $source_language_code === $runtime_header_language ? 'Runtime group' : 'Runtime group ' . $runtime_header_language;
		$runtime_source_labels[ $runtime_header_language ] = $source_language_code === $runtime_header_language ? 'Runtime source' : 'Runtime source ' . $runtime_header_language;
	}
	$runtime_manifest = $call(
		'update_public_header_manifest',
		array(
			'items' => array(
				array( 'source_item_id' => (int) $runtime_source_menu_parent_id, 'type' => 'custom', 'title' => 'Runtime group', 'labels' => $runtime_group_labels, 'url' => home_url( '/' ), 'parent_source_item_id' => 0, 'position' => 1 ),
				array( 'source_item_id' => (int) $runtime_source_menu_item_id, 'type' => 'page', 'title' => 'Runtime source', 'labels' => $runtime_source_labels, 'object_id' => $source_id, 'parent_source_item_id' => (int) $runtime_source_menu_parent_id, 'position' => 2 ),
			),
		)
	);
	if ( empty( $runtime_manifest['success'] ) ) {
		throw new RuntimeException( 'Could not register the runtime Public Header Projection manifest: ' . wp_json_encode( $runtime_manifest ) );
	}
	$runtime_pending_manifest = get_option( 'devenia_workflow_pending_public_header_manifest', array() );
	update_option( 'devenia_workflow_public_header_manifest', $runtime_pending_manifest, false );

	$html_lang_method = new ReflectionMethod( Devenia_Workflow::class, 'html_lang_for_language' );
	$html_lang_method->setAccessible( true );
	$runtime_html_lang = (string) $html_lang_method->invoke( null, $language );
	$hreflang_method = new ReflectionMethod( Devenia_Workflow::class, 'hreflang_for_language' );
	$hreflang_method->setAccessible( true );
	$runtime_hreflang = (string) $hreflang_method->invoke( null, $language );
	$runtime_source_url = (string) get_permalink( $source_id );
	$runtime_http_surface = static function ( $preempt, array $args, string $url ) use ( &$translation_id, $source_id, $language, $runtime_source_url, &$runtime_translation_url, $runtime_html_lang, $runtime_hreflang ) {
		$request_url = untrailingslashit( strtok( $url, '?' ) ?: '' );
		if ( $translation_id > 0 ) { $runtime_translation_url = (string) get_permalink( $translation_id ); }
		$is_translation = $translation_id > 0 && $request_url === untrailingslashit( $runtime_translation_url );
		$is_source = $request_url === untrailingslashit( $runtime_source_url );
		if ( ! $is_translation && ! $is_source ) {
			return $preempt;
		}
		$surface_url = $is_translation ? $runtime_translation_url : $runtime_source_url;
		$surface_language = $is_translation ? $runtime_html_lang : 'en-US';
		$surface_hreflang = $is_translation ? $runtime_hreflang : 'en';
		$surface_id = $is_translation ? $translation_id : $source_id;
		$identities = get_option( 'devenia_workflow_localized_menu_identities', array() );
		$menu_id = absint( $identities[ $language ]['menu_id'] ?? 0 );
		$navigation = (string) wp_nav_menu( array( 'menu' => $menu_id, 'container' => false, 'echo' => false, 'fallback_cb' => false, 'items_wrap' => '%3$s' ) );
		$thumbnail_url = (string) wp_get_attachment_image_url( absint( get_post_thumbnail_id( $surface_id ) ), 'full' );
		$media_head = '' !== $thumbnail_url ? '<meta property="og:image" content="' . esc_url( $thumbnail_url ) . '">' : '';
		$media_body = '' !== $thumbnail_url ? '<img class="wp-post-image" src="' . esc_url( $thumbnail_url ) . '">' : '';
		$body = '<!doctype html><html lang="' . esc_attr( $surface_language ) . '"><head><link rel="alternate" hreflang="' . esc_attr( $surface_hreflang ) . '" href="' . esc_url( $surface_url ) . '">' . $media_head . '</head><body><nav id="site-navigation">' . $navigation . '</nav><main><h1>' . esc_html( (string) get_the_title( $surface_id ) ) . '</h1>' . $media_body . '</main></body></html>';
		return array(
			'headers'  => array( 'cf-cache-status' => false === strpos( $url, 'devenia_frontend_integrity=' ) ? 'HIT' : 'DYNAMIC', 'age' => '0' ),
			'body'     => $body,
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $runtime_http_surface, 10, 3 );

	// The translation fixture is already public before the staged Job begins.
	// Seed its current localized primary-menu identity explicitly so every
	// pre-publish frontend check starts from a real, internally consistent
	// presentation surface instead of depending on residue from an earlier run.
	$runtime_seed_menu = $call(
		'sync_language_menu',
		array(
			'language'             => $language,
			'include_untranslated' => false,
			'include_custom_links' => true,
			'retire_previous'      => false,
		)
	);
	$runtime_seed_menu_id = absint( $runtime_seed_menu['target_menu']['id'] ?? 0 );
	if (
		empty( $runtime_seed_menu['success'] )
		|| empty( $runtime_seed_menu['validation']['passed'] )
		|| $runtime_seed_menu_id < 1
	) {
		throw new RuntimeException( 'Could not seed the runtime localized primary-menu identity: ' . wp_json_encode( $runtime_seed_menu ) );
	}
	$runtime_menu_ids[] = $runtime_seed_menu_id;

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
	$option_keys[] = 'devenia_workflow_translation_run_' . $quality_run_id;
	$quality_packet = $call( 'translation_job_fetch_packet', array( 'job_id' => $job_id, 'run_id' => $quality_run_id, 'claim_token' => $quality_token ) );
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
	$attempt_limit_job_key = 'devenia_workflow_translation_job_' . $job_id;
	$attempt_limit_job = get_option( $attempt_limit_job_key );
	$attempt_limit_job['status'] = 'quality_pending';
	update_option( $attempt_limit_job_key, $attempt_limit_job, false );
	$fourth_quality_claim = $call(
		'translation_job_claim',
		array(
			'job_id' => $job_id,
			'run_id' => 'runtime-quality-fourth-' . wp_generate_password( 8, false, false ),
			'coordinator_id' => 'runtime-coordinator',
			'role' => 'quality',
			'ttl_seconds' => 600,
		)
	);
	if ( ! empty( $fourth_quality_claim['success'] ) || 'run_attempt_limit' !== (string) ( $fourth_quality_claim['code'] ?? '' ) ) {
		throw new RuntimeException( 'Three substantive Quality Decisions did not enforce the bounded attempt limit: ' . wp_json_encode( $fourth_quality_claim ) );
	}
	$attempt_limit_job['status'] = 'changes_requested';
	update_option( $attempt_limit_job_key, $attempt_limit_job, false );
	$fourth_translator_claim = $call(
		'translation_job_claim',
		array(
			'job_id' => $job_id,
			'run_id' => 'runtime-translator-fourth-' . wp_generate_password( 8, false, false ),
			'coordinator_id' => 'runtime-coordinator',
			'role' => 'translator',
			'ttl_seconds' => 600,
		)
	);
	if ( ! empty( $fourth_translator_claim['success'] ) || 'run_attempt_limit' !== (string) ( $fourth_translator_claim['code'] ?? '' ) ) {
		throw new RuntimeException( 'Three substantive translator submissions did not enforce the bounded attempt limit: ' . wp_json_encode( $fourth_translator_claim ) );
	}
	$attempt_limit_job['status'] = 'ready_to_publish';
	update_option( $attempt_limit_job_key, $attempt_limit_job, false );

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
	delete_post_meta( $translation_id, '_thumbnail_id' );
	add_post_meta( $translation_id, '_thumbnail_id', $linked_source_id );
	add_post_meta( $translation_id, '_thumbnail_id', $source_thumbnail_id );
	$refresh_artifact = $call(
		'translation_job_submit_artifact',
		array( 'job_id' => $job_id, 'run_id' => (string) $refresh_writer_claim['run']['run_id'], 'claim_token' => (string) $refresh_writer_claim['claim_token'], 'artifact' => $artifact, 'usage' => array( 'input_tokens' => 700, 'cached_input_tokens' => 0, 'output_tokens' => 200, 'attempts' => 1, 'duration_ms' => 700, 'estimated_cost_microusd' => 50 ) )
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
		throw new RuntimeException( 'Identical refreshed payload did not create a new immutable generation/baseline/writer artifact: ' . wp_json_encode( $refresh_artifact ) );
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
	$generation_three_artifact = $call(
		'translation_job_submit_artifact',
		array( 'job_id' => $job_id, 'run_id' => (string) $generation_three_writer['run']['run_id'], 'claim_token' => (string) $generation_three_writer['claim_token'], 'artifact' => $artifact, 'usage' => array( 'input_tokens' => 700, 'cached_input_tokens' => 0, 'output_tokens' => 200, 'attempts' => 1, 'duration_ms' => 700, 'estimated_cost_microusd' => 50 ) )
	);
	$option_keys[] = 'devenia_workflow_translation_artifact_' . (string) $generation_three_artifact['artifact_revision'];
	$generation_three_quality_claim = $call(
		'translation_job_claim',
		array( 'job_id' => $job_id, 'run_id' => 'runtime-refresh-quality-three-' . wp_generate_password( 8, false, false ), 'coordinator_id' => 'runtime-observability-only', 'role' => 'quality', 'ttl_seconds' => 600 )
	);
	$option_keys[] = 'devenia_workflow_translation_run_' . (string) $generation_three_quality_claim['run']['run_id'];
	$generation_three_quality = $call(
		'translation_job_submit_quality_decision',
		$quality_payload( $generation_three_quality_claim, (string) $generation_three_artifact['artifact_revision'], 'pass', 'A fresh generation-three Quality principal reviewed the exact immutable artifact after both baseline refreshes.', array() )
	);
	$track_quality_result( $generation_three_quality );
	if (
		empty( $generation_three_quality['success'] )
		|| (string) ( $generation_three_quality['quality_decision']['reviewer_principal']['principal_id'] ?? '' ) === (string) ( $generation_three_writer['run']['principal']['principal_id'] ?? '' )
		|| 3 !== absint( $generation_three_quality['quality_decision']['submission_generation'] ?? 0 )
	) {
		throw new RuntimeException( 'Fresh generation-three Quality Decision failed: ' . wp_json_encode( $generation_three_quality ) );
	}
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
		|| 'rollback_featured_image_verification_failed' !== (string) ( $rollback_media_failure['rollback']['error'] ?? '' )
		|| empty( $rollback_media_failure['rollback']['media_verification']['issues'] )
	) {
		throw new RuntimeException( 'Rollback accepted stale featured media from origin/canonical cache verification: ' . wp_json_encode( $rollback_media_failure ) );
	}

	// Source Publication Surface: timestamps are diagnostic, bytes are authority.
	$source_surface_a = $call( 'source_publication_surface_revision', get_post( $source_id ) );
	$source_media_a = $call( 'publication_featured_image_revision_identity', $source_id );
	$published_authority_job = get_option( 'devenia_workflow_translation_job_' . $job_id );
	$published_obligation = $call( 'project_translation_obligation', $source_id, $language, $source_surface_a );
	$published_quality = get_option( 'devenia_workflow_translation_quality_' . (string) ( $published_authority_job['quality_revision'] ?? '' ) );
	$published_evidence_key = 'devenia_tj_quality_evidence_' . (string) ( $published_quality['evidence_revision'] ?? '' );
	$published_evidence = get_option( $published_evidence_key );
	delete_option( $published_evidence_key );
	$missing_evidence_obligation = $call( 'project_translation_obligation', $source_id, $language, $source_surface_a );
	update_option( $published_evidence_key, $published_evidence, false );
	$published_artifact_key = 'devenia_workflow_translation_artifact_' . (string) ( $published_authority_job['artifact_revision'] ?? '' );
	$published_artifact_exact = get_option( $published_artifact_key );
	delete_option( $published_artifact_key );
	$missing_artifact_obligation = $call( 'project_translation_obligation', $source_id, $language, $source_surface_a );
	update_option( $published_artifact_key, $published_artifact_exact, false );
	if (
		'published_verified' !== (string) ( $published_obligation['state'] ?? '' )
		|| 'publication_authority_stale' !== (string) ( $missing_evidence_obligation['state'] ?? '' )
		|| 'publication_authority_stale' !== (string) ( $missing_artifact_obligation['state'] ?? '' )
	) {
		throw new RuntimeException( 'Published obligation accepted a missing immutable Artifact or Quality Evidence binding: ' . wp_json_encode( compact( 'published_obligation', 'missing_evidence_obligation', 'missing_artifact_obligation' ) ) );
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

	// Preserve direct coverage for the original baseline-drift code as a
	// separate deterministic transaction-boundary scenario. The current public
	// surface is the locked snapshot, while the cloned staged artifact retains
	// its older captured baseline.
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
	$baseline_original_excerpt = (string) $baseline_public_post->post_excerpt;
	$baseline_original_surface_revision = $call( 'translation_job_current_surface_revision', $translation_id );
	$baseline_drift_surface_revision = '';
	$baseline_restored_surface_revision = '';
	$baseline_snapshot = array();
	$baseline_failure = array();
	$baseline_refresh = array();
	try {
		$baseline_drift_excerpt = $baseline_original_excerpt . ' Runtime public baseline drift ' . wp_generate_password( 8, false, false ) . '.';
		$baseline_drift_update = wp_update_post(
			array(
				'ID' => $translation_id,
				'post_excerpt' => $baseline_drift_excerpt,
			),
			true
		);
		if ( is_wp_error( $baseline_drift_update ) || $translation_id !== absint( $baseline_drift_update ) ) {
			throw new RuntimeException( 'Baseline-drift lower-level fixture could not establish public drift: ' . ( is_wp_error( $baseline_drift_update ) ? $baseline_drift_update->get_error_message() : 'unexpected post ID' ) );
		}
		clean_post_cache( $translation_id );
		$call( 'sync_translation_index_row', $translation_id );
		$baseline_drift_surface_revision = $call( 'translation_job_current_surface_revision', $translation_id );
		$baseline_snapshot = $call( 'translation_job_capture_surface_snapshot', $translation_id, (array) $baseline_artifact_record['surface_manifest'], $baseline_scope );
		$baseline_failure = $call( 'translation_job_apply_staged_artifact', $baseline_job, $baseline_artifact_record, $baseline_snapshot );
		$baseline_refresh = $call( 'translation_job_refresh_drifted_surface', $baseline_job, 'publish_baseline_mismatch', $baseline_failure );
	} finally {
		$baseline_restore = wp_update_post(
			array(
				'ID' => $translation_id,
				'post_excerpt' => $baseline_original_excerpt,
			),
			true
		);
		if ( is_wp_error( $baseline_restore ) || $translation_id !== absint( $baseline_restore ) ) {
			throw new RuntimeException( 'Baseline-drift lower-level fixture could not restore the published translation: ' . ( is_wp_error( $baseline_restore ) ? $baseline_restore->get_error_message() : 'unexpected post ID' ) );
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
		|| (string) ( $baseline_artifact_record['baseline_surface_revision'] ?? '' ) === $baseline_drift_surface_revision
		|| $baseline_drift_surface_revision !== (string) ( $baseline_snapshot['captured_surface_revision'] ?? '' )
		|| $baseline_original_surface_revision !== $baseline_restored_surface_revision
		|| $baseline_original_excerpt !== (string) get_post_field( 'post_excerpt', $translation_id )
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
		throw new RuntimeException( 'Baseline-drift lower-level proof did not preserve zero mutation, rollback, immutable records, and exact CAS refresh: ' . wp_json_encode( array( 'failure' => $baseline_failure, 'refresh' => $baseline_refresh, 'job' => $stored_baseline_job, 'original_surface_revision' => $baseline_original_surface_revision, 'drift_surface_revision' => $baseline_drift_surface_revision, 'restored_surface_revision' => $baseline_restored_surface_revision, 'public_drift_and_fixture_restoration_proven' => false ) ) );
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

	$migration_identities = $runtime_identities;
	$migration_languages = Devenia_Workflow::languages( true );
	$migration_menu_id = wp_create_nav_menu( (string) ( $migration_languages[ $language ]['menu_name'] ?? '' ) );
	if ( is_wp_error( $migration_menu_id ) ) {
		throw new RuntimeException( 'Could not create the configured-name migration fixture: ' . $migration_menu_id->get_error_message() );
	}
	add_term_meta( (int) $migration_menu_id, '_devenia_workflow_localized_menu_managed', '1', true );
	add_term_meta( (int) $migration_menu_id, '_devenia_workflow_localized_menu_language', $language, true );
	add_term_meta( (int) $migration_menu_id, '_devenia_workflow_public_header_manifest_revision', (string) $runtime_manifest['revision'], true );
	$runtime_menu_ids[] = (int) $migration_menu_id;
	unset( $migration_identities[ $language ] );
	update_option( 'devenia_workflow_localized_menu_identities', $migration_identities, false );
	$localized_menu_id = new ReflectionMethod( Devenia_Workflow::class, 'localized_menu_id' );
	$localized_menu_id->setAccessible( true );
	$migrated_menu_id = (int) $localized_menu_id->invoke( null, $language, true );
	$migrated_identities = get_option( 'devenia_workflow_localized_menu_identities', array() );
	if ( $migrated_menu_id < 1 || $migrated_menu_id !== absint( $migrated_identities[ $language ]['menu_id'] ?? 0 ) ) {
		throw new RuntimeException( 'Stable menu identity did not migrate deterministically from the configured name.' );
	}
	update_option( 'devenia_workflow_localized_menu_identities', $runtime_identities, false );

	$fail_projection_write = static function () {
		return new WP_Error( 'runtime_projection_failure', 'Runtime projection failure fixture.' );
	};
	add_filter( 'devenia_workflow_localized_menu_projection_write_result', $fail_projection_write, 10, 5 );
	$failed_projection = $call( 'sync_language_menu', array( 'language' => $language, 'include_untranslated' => false, 'include_custom_links' => true ) );
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
	$post_publish_artifact = $artifact;
	$post_publish_artifact['title'] = 'Runtime translated title corrected after browser QA';
	$post_publish_artifact['localized_slug'] = 'must-not-replace-published-route';
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
			'stable_menu_identity_migrated' => true,
			'frontend_cache_invalidation_adapter_consumed' => true,
			'canonical_english_menu_cache_rejected' => true,
			'publish_surface_drift_reopened_after_zero_mutation_and_rollback' => true,
			'publish_snapshot_to_lock_surface_drift_reopened_in_same_call' => true,
			'publish_identity_drift_lower_level_transaction_proven' => true,
			'publish_baseline_drift_lower_level_transaction_proven' => true,
			'publish_lifecycle_lease_blocked_concurrent_claim' => true,
			'claim_winner_forced_publish_reread_before_mutation' => true,
			'claim_lifecycle_lease_released_before_run_lifetime' => true,
			'surface_refresh_history_preserved_immutable_records' => true,
			'refreshed_identical_payload_created_new_generation_artifact' => true,
			'refreshed_translator_abandon_restored_changes_requested' => true,
			'quality_path_defensively_reopened_drifted_baseline' => true,
			'ready_to_publish_claim_defensively_reopened' => true,
			'surface_refresh_generation_limit_failed_closed' => true,
			'publish_coordinator_label_not_required_for_authority' => true,
			'same_second_lifecycle_lease_renewal_advanced_owned_cas' => true,
		);
} catch ( Throwable $error ) {
	$runtime_error = $error;
} finally {
	if ( $runtime_http_surface ) {
		remove_filter( 'pre_http_request', $runtime_http_surface, 10 );
	}
	if ( $runtime_page_link ) {
		remove_filter( 'page_link', $runtime_page_link, 10 );
	}
	remove_filter( 'devenia_workflow_frontend_cache_invalidation_result', $cache_adapter, 10 );
	remove_filter( 'devenia_workflow_retire_previous_localized_menu_projection', $keep_previous_menu, 10 );
	wp_set_current_user( $original_user_id );
	update_option( 'devenia_workflow_language_registry', $languages_option_before, false );
	update_option( 'devenia_workflow_runtime_mutation_provenance', $runtime_provenance_before, false );
	set_theme_mod( 'nav_menu_locations', is_array( $nav_menu_locations_before ) ? $nav_menu_locations_before : array() );
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
	if ( false === $source_inventory_dirty_before ) {
		delete_option( 'devenia_workflow_source_inventory_dirty' );
	} else {
		update_option( 'devenia_workflow_source_inventory_dirty', $source_inventory_dirty_before, false );
	}
	foreach ( array_unique( $runtime_menu_ids ) as $runtime_menu_id ) {
		if ( $runtime_menu_id > 0 && '1' === (string) get_term_meta( $runtime_menu_id, '_devenia_workflow_localized_menu_managed', true ) ) {
			wp_delete_nav_menu( $runtime_menu_id );
		}
	}
	if ( $runtime_source_menu_id > 0 ) {
		wp_delete_nav_menu( $runtime_source_menu_id );
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
}

if ( $runtime_error instanceof Throwable ) {
	fwrite( STDERR, wp_json_encode( array( 'success' => false, 'error' => $runtime_error->getMessage() ) ) . PHP_EOL );
	exit( 1 );
}

echo wp_json_encode( $runtime_result ) . PHP_EOL;
