<?php
/**
 * Dev runtime contract for the finite Translation Job v2 lifecycle.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$source_id = 0;
$linked_source_id = 0;
$translation_id = 0;
$option_keys = array();

$call = static function ( string $method, array $input = array() ) {
	$reflection = new ReflectionMethod( Devenia_AI_Translations::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, $input );
};

try {
	$linked_source_id = wp_insert_post(
		array(
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_title' => 'Translation Job V2 linked source fixture',
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
			'post_title' => 'Translation Job V2 source fixture',
			'post_excerpt' => 'A useful source excerpt.',
			'post_content' => '<!-- wp:paragraph --><p><strong>Useful source</strong><br>Read <a href="' . esc_url( $linked_source_url ) . '">the linked source</a>, then contact us for a concrete next step.</p><!-- /wp:paragraph -->',
		),
		true
	);
	if ( is_wp_error( $source_id ) ) {
		throw new RuntimeException( $source_id->get_error_message() );
	}

	$languages_method = new ReflectionMethod( Devenia_AI_Translations::class, 'target_languages' );
	$languages_method->setAccessible( true );
	$languages = $languages_method->invoke( null );
	$language_keys = array_keys( $languages );
	$language = isset( $languages['nb'] ) ? 'nb' : ( isset( $language_keys[0] ) ? (string) $language_keys[0] : '' );
	if ( '' === $language ) {
		throw new RuntimeException( 'No target language is configured.' );
	}
	$unapproved_discover = $call( 'translation_job_v2_discover', array( 'source_id' => $source_id, 'language' => $language ) );
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
			'reviewer' => 'translation-job-v2-runtime',
		)
	);
	if ( empty( $source_review['success'] ) ) {
		throw new RuntimeException( 'Source approval failed: ' . wp_json_encode( $source_review ) );
	}

	$discover = $call( 'translation_job_v2_discover', array( 'source_id' => $source_id, 'language' => $language, 'observability_label' => 'runtime-contract' ) );
	if ( empty( $discover['success'] ) || empty( $discover['job']['job_id'] ) ) {
		throw new RuntimeException( 'Discover failed: ' . wp_json_encode( $discover ) );
	}
	$job_id = (string) $discover['job']['job_id'];
	$option_keys[] = 'devenia_ai_translation_job_v2_' . $job_id;
	$option_keys[] = 'devenia_ai_translation_job_v2_claim_' . $job_id;

	$claim = $call(
		'translation_job_v2_claim',
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
	$option_keys[] = 'devenia_ai_translation_run_v2_' . $translator_run_id;
	$stored_claim = get_option( 'devenia_ai_translation_job_v2_claim_' . $job_id );
	if ( false !== strpos( wp_json_encode( $stored_claim ) ?: '', $translator_token ) ) {
		throw new RuntimeException( 'Claim token was stored in plaintext.' );
	}

	$packet = $call( 'translation_job_v2_fetch_packet', array( 'job_id' => $job_id, 'run_id' => $translator_run_id, 'claim_token' => $translator_token ) );
	$fragments = $packet['packet']['fragments'] ?? array();
	$links = $packet['packet']['links'] ?? array();
	if (
		empty( $packet['success'] )
		|| 1 !== count( $fragments )
		|| false === stripos( (string) $fragments[0]['source_html'], '<strong>' )
		|| 1 !== count( $links )
		|| ! empty( $links[0]['published_target_available'] )
		|| 'retain_source_url_until_localized_target_is_published' !== (string) ( $links[0]['policy'] ?? '' )
		|| $linked_source_url !== (string) ( $links[0]['target_url'] ?? '' )
	) {
		throw new RuntimeException( 'Bounded packet failed: ' . wp_json_encode( $packet ) );
	}

	$localized = array();
	foreach ( $fragments as $fragment ) {
		$localized[] = array( 'key' => (string) $fragment['key'], 'html' => '<strong>Nyttig innhold</strong><br>Les <a href="' . esc_url( $linked_source_url ) . '">den lenkede kilden</a>, og kontakt oss for et konkret neste steg.' );
	}
	$artifact = array(
		'title' => 'Oversatt testside',
		'excerpt' => 'En nyttig oversatt ingress.',
		'localized_slug' => 'oversatt-testside-' . strtolower( wp_generate_password( 6, false, false ) ),
		'localized_fragments' => $localized,
		'seo' => array( 'title' => 'Oversatt testside', 'description' => 'En nyttig beskrivelse av den oversatte testsiden.' ),
	);
	$invalid_artifact = $artifact;
	$invalid_artifact['localized_fragments'][0]['html'] = str_replace( $linked_source_url, home_url( '/invented-localized-route/' ), $invalid_artifact['localized_fragments'][0]['html'] );
	$invalid_submit = $call(
		'translation_job_v2_submit_artifact',
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
		'translation_job_v2_submit_artifact',
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
	$translation_id = absint( $submit['translation']['id'] ?? 0 );
	$artifact_revision = (string) $submit['artifact_revision'];
	$option_keys[] = 'devenia_ai_translation_artifact_v2_' . $artifact_revision;

	$quality_claim = $call(
		'translation_job_v2_claim',
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
	$option_keys[] = 'devenia_ai_translation_run_v2_' . $quality_run_id;
	$quality_packet = $call( 'translation_job_v2_fetch_packet', array( 'job_id' => $job_id, 'run_id' => $quality_run_id, 'claim_token' => $quality_token ) );
	if ( empty( $quality_packet['success'] ) || $links !== ( $quality_packet['packet']['links'] ?? array() ) ) {
		throw new RuntimeException( 'Quality packet did not preserve the authoritative link policy: ' . wp_json_encode( $quality_packet ) );
	}
	$checks = array_fill_keys( array( 'source_quality', 'natural_language', 'factual_accuracy', 'source_coverage', 'localized_search_intent', 'offer_and_contact', 'links_and_route', 'rendered_experience' ), true );
	$quality = $call(
		'translation_job_v2_submit_quality_decision',
		array(
			'job_id' => $job_id,
			'run_id' => $quality_run_id,
			'claim_token' => $quality_token,
			'artifact_revision' => $artifact_revision,
			'decision' => 'revise',
			'checks' => $checks,
			'evidence' => 'Runtime contract reviewed every required dimension and requests a deliberate revision.',
			'corrections' => array( 'Runtime fixture intentionally stops before publication.' ),
			'usage' => array( 'input_tokens' => 800, 'cached_input_tokens' => 0, 'output_tokens' => 200, 'attempts' => 1, 'duration_ms' => 800, 'estimated_cost_microusd' => 50 ),
		)
	);
	if ( empty( $quality['success'] ) || 'changes_requested' !== (string) ( $quality['job']['status'] ?? '' ) ) {
		throw new RuntimeException( 'Quality Decision failed: ' . wp_json_encode( $quality ) );
	}
	$option_keys[] = 'devenia_ai_translation_quality_v2_' . (string) $quality['quality_decision']['quality_revision'];
	$correction_claim = $call(
		'translation_job_v2_claim',
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
	$option_keys[] = 'devenia_ai_translation_run_v2_' . $correction_run_id;
	$correction_packet = $call(
		'translation_job_v2_fetch_packet',
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
		'translation_job_v2_submit_artifact',
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
		'translation_job_v2_claim',
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
	$option_keys[] = 'devenia_ai_translation_run_v2_' . $second_quality_run_id;
	$second_quality = $call(
		'translation_job_v2_submit_quality_decision',
		array(
			'job_id' => $job_id,
			'run_id' => $second_quality_run_id,
			'claim_token' => (string) $second_quality_claim['claim_token'],
			'artifact_revision' => (string) $second_artifact['artifact_revision'],
			'decision' => 'revise',
			'checks' => $checks,
			'evidence' => 'The second bounded quality Run found one final wording correction that must remain actionable.',
			'corrections' => array( 'Apply the final bounded wording correction.' ),
			'usage' => array( 'input_tokens' => 700, 'cached_input_tokens' => 0, 'output_tokens' => 200, 'attempts' => 1, 'duration_ms' => 700, 'estimated_cost_microusd' => 50 ),
		)
	);
	if ( empty( $second_quality['success'] ) || 'changes_requested' !== (string) ( $second_quality['job']['status'] ?? '' ) ) {
		throw new RuntimeException( 'Second Quality Decision failed: ' . wp_json_encode( $second_quality ) );
	}
	$option_keys[] = 'devenia_ai_translation_quality_v2_' . (string) $second_quality['quality_decision']['quality_revision'];
	$third_correction_claim = $call(
		'translation_job_v2_claim',
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
	$option_keys[] = 'devenia_ai_translation_run_v2_' . $third_correction_run_id;
	$third_artifact = $call(
		'translation_job_v2_submit_artifact',
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
		'translation_job_v2_claim',
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
	$option_keys[] = 'devenia_ai_translation_run_v2_' . (string) $third_quality_claim['run']['run_id'];

	echo wp_json_encode(
		array(
			'success' => true,
			'job_status' => 'quality_pending',
			'packet_fragment_count' => count( $fragments ),
			'inline_markup_kept' => true,
			'claim_token_hashed' => true,
			'unapproved_source_blocked' => true,
			'translation_saved' => $translation_id > 0,
			'correction_context_included' => true,
			'link_policy_in_packets' => true,
			'invented_localized_link_blocked' => true,
			'third_bounded_runs_available' => true,
		)
	) . PHP_EOL;
} catch ( Throwable $error ) {
	fwrite( STDERR, wp_json_encode( array( 'success' => false, 'error' => $error->getMessage() ) ) . PHP_EOL );
	exit( 1 );
} finally {
	if ( $translation_id > 0 ) {
		wp_delete_post( $translation_id, true );
	}
	if ( $source_id > 0 ) {
		wp_delete_post( $source_id, true );
	}
	if ( $linked_source_id > 0 ) {
		wp_delete_post( $linked_source_id, true );
	}
	foreach ( array_unique( $option_keys ) as $option_key ) {
		delete_option( $option_key );
	}
}
