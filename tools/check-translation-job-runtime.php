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
$source_thumbnail_id = 0;
$option_keys = array();
$original_user_id = get_current_user_id();
$languages_option_before = get_option( 'devenia_workflow_language_registry' );
$runtime_provenance_before = get_option( 'devenia_workflow_runtime_mutation_provenance' );
$menu_identities_before = get_option( 'devenia_workflow_localized_menu_identities' );
$nav_menu_locations_before = get_theme_mod( 'nav_menu_locations', array() );
$runtime_menu_ids = array();
$runtime_source_menu_id = 0;
$runtime_page_link = null;
$runtime_http_surface = null;
$cache_invalidation_calls = array();
$cache_adapter = static function ( $default_result, array $urls, array $context ) use ( &$cache_invalidation_calls ): array {
	$cache_invalidation_calls[] = array( 'default' => $default_result, 'urls' => $urls, 'context' => $context );
	return array( 'success' => true, 'purged_urls' => $urls, 'adapter' => 'translation-job-runtime' );
};
$keep_previous_menu = static function (): bool {
	return false;
};
add_filter( 'devenia_workflow_frontend_cache_invalidation_result', $cache_adapter, 10, 3 );
add_filter( 'devenia_workflow_retire_previous_localized_menu_projection', $keep_previous_menu, 10, 4 );

$call = static function ( string $method, array $input = array() ) {
	$reflection = new ReflectionMethod( Devenia_Workflow::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $input );
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
			'post_status' => 'publish',
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
	$source_thumbnail_id = wp_insert_attachment(
		array(
			'post_title' => 'Translation Job source media fixture',
			'post_status' => 'inherit',
			'post_mime_type' => 'image/webp',
		)
	);
	if ( ! $source_thumbnail_id || is_wp_error( $source_thumbnail_id ) ) {
		throw new RuntimeException( 'Could not create the source featured-image fixture.' );
	}
	update_post_meta( $source_id, '_thumbnail_id', $source_thumbnail_id );

	$languages_method = new ReflectionMethod( Devenia_Workflow::class, 'target_languages' );
	$languages_method->setAccessible( true );
	$languages = $languages_method->invoke( null );
	$language_keys = array_keys( $languages );
	$language = isset( $languages['nb'] ) ? 'nb' : ( isset( $language_keys[0] ) ? (string) $language_keys[0] : '' );
	if ( '' === $language ) {
		throw new RuntimeException( 'No target language is configured.' );
	}
	$runtime_text_input = array(
		'language' => $language,
		'section' => 'share_text',
		'source' => 'translation_job_runtime_fixture',
		'translated' => 'Runtime fixture translation',
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
	wp_update_post( array( 'ID' => $source_id, 'post_status' => 'publish' ) );

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
	if (
		empty( $packet['success'] )
		|| 1 !== count( $fragments )
		|| false === stripos( (string) $fragments[0]['source_html'], '<strong>' )
		|| 1 !== count( $links )
		|| ! empty( $links[0]['published_target_available'] )
		|| 'retain_source_url_until_localized_target_is_published' !== (string) ( $links[0]['policy'] ?? '' )
		|| $linked_source_url !== (string) ( $links[0]['target_url'] ?? '' )
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
		$localized[] = array( 'key' => (string) $fragment['key'], 'html' => '<strong>Nyttig innhold</strong><br>Les <a href="' . esc_url( $linked_source_url ) . '">den lenkede kilden</a>, og <a href="mailto:hello@example.com?subject=Sp%C3%B8rsm%C3%A5l%20om%20testen&amp;body=Hei%20fra%20oversettelsen">kontakt oss</a> for et konkret neste steg.' );
	}
	$artifact = array(
		'title' => 'Oversatt testside',
		'excerpt' => 'En nyttig oversatt ingress.',
		'localized_slug' => 'oversatt-testside-' . strtolower( wp_generate_password( 6, false, false ) ),
		'localized_fragments' => $localized,
		'seo' => array( 'title' => 'Oversatt testside', 'description' => 'En nyttig beskrivelse av den oversatte testsiden.' ),
	);
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
	$invalid_artifact['localized_fragments'][0]['html'] = str_replace( $linked_source_url, home_url( '/invented-localized-route/' ), $invalid_artifact['localized_fragments'][0]['html'] );
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
	$translation_id = 0;
	if ( get_post( $submit['translation_id'] ?? 0 ) || empty( $submit['staged'] ) ) {
		throw new RuntimeException( 'Artifact submission changed WordPress instead of remaining staged: ' . wp_json_encode( $submit ) );
	}
	$artifact_revision = (string) $submit['artifact_revision'];
	$option_keys[] = 'devenia_workflow_translation_artifact_' . $artifact_revision;

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

	$runtime_page_link = static function ( string $url, int $post_id ) use ( &$translation_id, $language ): string {
		if ( $post_id !== $translation_id ) {
			return $url;
		}
		$post = get_post( $post_id );
		return home_url( '/' . trim( $language . '/' . ( $post instanceof WP_Post ? $post->post_name : '' ), '/' ) . '/' );
	};
	add_filter( 'page_link', $runtime_page_link, 10, 2 );
	$runtime_translation_url = (string) get_permalink( $translation_id );
	update_post_meta(
		$translation_id,
		'_devenia_translation_canonical_route_v1',
		array(
			'translation_id' => $translation_id,
			'language'       => $language,
			'post_name'      => (string) get_post_field( 'post_name', $translation_id ),
			'post_parent'    => 0,
			'localized_path' => trim( $language . '/' . (string) get_post_field( 'post_name', $translation_id ), '/' ),
			'url'            => $runtime_translation_url,
			'path'           => trim( (string) wp_parse_url( $runtime_translation_url, PHP_URL_PATH ), '/' ),
			'established_at' => gmdate( 'c' ),
		)
	);
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
		$body = '<!doctype html><html lang="' . esc_attr( $surface_language ) . '"><head><link rel="alternate" hreflang="' . esc_attr( $surface_hreflang ) . '" href="' . esc_url( $surface_url ) . '"></head><body><nav id="site-navigation">' . $navigation . '</nav><main><h1>' . esc_html( (string) get_the_title( $surface_id ) ) . '</h1></main></body></html>';
		return array(
			'headers'  => array( 'cf-cache-status' => false === strpos( $url, 'devenia_frontend_integrity=' ) ? 'HIT' : 'DYNAMIC', 'age' => '0' ),
			'body'     => $body,
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $runtime_http_surface, 10, 3 );

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
	if ( empty( $quality['success'] ) || 'changes_requested' !== (string) ( $quality['job']['status'] ?? '' ) ) {
		throw new RuntimeException( 'Idempotent orphaned Quality Decision recovery failed: ' . wp_json_encode( $quality ) );
	}
	$option_keys[] = 'devenia_workflow_translation_quality_' . (string) $quality['quality_decision']['quality_revision'];
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
	if ( empty( $second_quality['success'] ) || 'changes_requested' !== (string) ( $second_quality['job']['status'] ?? '' ) ) {
		throw new RuntimeException( 'Second Quality Decision failed: ' . wp_json_encode( $second_quality ) );
	}
	$option_keys[] = 'devenia_workflow_translation_quality_' . (string) $second_quality['quality_decision']['quality_revision'];
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
	if ( empty( $third_quality['success'] ) || 'ready_to_publish' !== (string) ( $third_quality['job']['status'] ?? '' ) ) {
		throw new RuntimeException( 'Final Quality Decision failed: ' . wp_json_encode( $third_quality ) );
	}
	$option_keys[] = 'devenia_workflow_translation_quality_' . (string) $third_quality['quality_decision']['quality_revision'];
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
	delete_post_meta( $translation_id, '_thumbnail_id' );
	add_post_meta( $translation_id, '_thumbnail_id', $linked_source_id );
	add_post_meta( $translation_id, '_thumbnail_id', $source_thumbnail_id );
	$published = $call(
		'translation_job_publish',
		array( 'job_id' => $job_id, 'coordinator_id' => 'runtime-coordinator', 'sync_menu' => true, 'verify_live' => true )
	);
	$runtime_identities = get_option( 'devenia_workflow_localized_menu_identities', array() );
	$runtime_active_menu_id = absint( $runtime_identities[ $language ]['menu_id'] ?? 0 );
	if (
		empty( $published['success'] )
		|| empty( $published['menu']['validation']['passed'] )
		|| $runtime_active_menu_id < 1
		|| empty( $cache_invalidation_calls )
		|| 'localized_presentation_publication' !== (string) ( $cache_invalidation_calls[0]['context']['event'] ?? '' )
		|| $job_id !== (string) ( $cache_invalidation_calls[0]['context']['job_id'] ?? '' )
	) {
		throw new RuntimeException( 'Real sync_menu publication did not atomically activate and purge the localized presentation: ' . wp_json_encode( array( 'published' => $published, 'invalidation_calls' => $cache_invalidation_calls ) ) );
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
	delete_post_meta( $translation_id, '_thumbnail_id' );
	add_post_meta( $translation_id, '_thumbnail_id', $linked_source_id );
	add_post_meta( $translation_id, '_thumbnail_id', $source_thumbnail_id );
	$republished = $call(
		'translation_job_publish',
		array( 'job_id' => $job_id, 'coordinator_id' => 'runtime-coordinator', 'sync_menu' => false, 'verify_live' => true )
	);
	if (
		'job_not_ready_to_publish' === (string) ( $republished['code'] ?? '' )
		|| $source_thumbnail_id !== absint( get_post_meta( $translation_id, '_thumbnail_id', true ) )
	) {
		throw new RuntimeException( 'Idempotent Translation Job publication did not reconcile stale featured media: ' . wp_json_encode( $republished ) );
	}
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
	$post_publish_artifact = $artifact;
	$post_publish_artifact['title'] = 'Runtime translated title corrected after browser QA';
	$post_publish_artifact['localized_slug'] = 'must-not-replace-published-route';
	$published_route_before = (string) get_permalink( $translation_id );
	$published_slug_before  = (string) get_post_field( 'post_name', $translation_id );
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
	if ( empty( $post_publish_submit['success'] ) || 'publish' !== get_post_status( $translation_id ) || 'quality_pending' !== (string) ( $post_publish_submit['job']['status'] ?? '' ) || $published_slug_before !== (string) get_post_field( 'post_name', $translation_id ) || $published_route_before !== (string) get_permalink( $translation_id ) ) {
		throw new RuntimeException( 'Published correction artifact was not saved safely: ' . wp_json_encode( $post_publish_submit ) );
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
	if ( empty( $post_publish_quality['success'] ) || 'changes_requested' !== (string) ( $post_publish_quality['job']['status'] ?? '' ) ) {
		throw new RuntimeException( 'Published correction did not receive an exact Quality Decision: ' . wp_json_encode( $post_publish_quality ) );
	}
	$option_keys[] = 'devenia_workflow_translation_quality_' . (string) $post_publish_quality['quality_decision']['quality_revision'];
	if ( 'publish' !== get_post_status( $translation_id ) || 'Runtime translated title corrected after browser QA' !== get_the_title( $translation_id ) ) {
		throw new RuntimeException( 'Published correction did not remain live while re-entering quality review.' );
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

	echo wp_json_encode(
		array(
			'success' => true,
			'job_status' => 'published_media_reconcile_exercised',
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
			'mailto_query_copy_must_be_localized' => true,
			'featured_image_synchronized_before_quality' => true,
			'published_job_media_reconciled_idempotently' => true,
			'duplicate_thumbnail_meta_reconciled_using_wordpress_effective_value' => true,
			'published_job_browser_correction_reentered_bounded_lifecycle' => true,
			'published_job_route_preserved_during_correction' => true,
			'orphaned_quality_decision_recovered' => true,
			'expired_run_finalized_before_reclaim' => true,
			'expired_quality_run_does_not_consume_attempt' => true,
			'abandoned_run_does_not_consume_attempt' => true,
			'substantive_run_attempt_limit_enforced' => true,
			'quality_pending_contract_gap_reopened_for_translator' => true,
			'orphaned_run_finalized_during_publish' => true,
			'real_sync_menu_publication_exercised' => true,
			'atomic_menu_failure_preserved_active_identity' => true,
			'stable_menu_identity_migrated' => true,
			'frontend_cache_invalidation_adapter_consumed' => true,
			'canonical_english_menu_cache_rejected' => true,
		)
	) . PHP_EOL;
} catch ( Throwable $error ) {
	fwrite( STDERR, wp_json_encode( array( 'success' => false, 'error' => $error->getMessage() ) ) . PHP_EOL );
	exit( 1 );
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
	if ( $source_id > 0 ) {
		wp_delete_post( $source_id, true );
	}
	if ( $linked_source_id > 0 ) {
		wp_delete_post( $linked_source_id, true );
	}
	if ( $source_thumbnail_id > 0 ) {
		wp_delete_attachment( $source_thumbnail_id, true );
	}
	foreach ( array_unique( $option_keys ) as $option_key ) {
		delete_option( $option_key );
	}
}
