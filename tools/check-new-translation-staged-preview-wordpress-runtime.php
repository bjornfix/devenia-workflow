<?php
/**
 * Prove that a first-translation preview uses target-language runtime context
 * while leaving its canonical source post unchanged.
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'Devenia_Workflow' ) ) {
	throw new RuntimeException( 'This staged-preview proof requires WP-CLI with Devenia Workflow active.' );
}

$reflection = new ReflectionClass( Devenia_Workflow::class );
$call_private = static function ( string $method, array $arguments = array() ) use ( $reflection ) {
	$callable = $reflection->getMethod( $method );
	$callable->setAccessible( true );
	return $callable->invokeArgs( null, $arguments );
};

$source_id = 0;
$existing_translation_id = 0;
$option_keys = array();
$query = $GLOBALS['wp_query'] ?? null;
$previous_preview = $query instanceof WP_Query ? $query->get( 'devenia_translation_artifact_preview' ) : null;
$request = $GLOBALS['wp'] ?? null;
$previous_request_query_vars = is_object( $request ) && is_array( $request->query_vars ?? null ) ? $request->query_vars : null;
$preview_query_entries = 0;
$count_preview_query_entries = static function ( array $posts ) use ( &$preview_query_entries ): array {
	++$preview_query_entries;
	return $posts;
};
add_filter( 'the_posts', $count_preview_query_entries, 1, 2 );
$previous_error_handler = set_error_handler(
	static function ( int $severity, string $message, string $file, int $line ): bool {
		if ( 0 !== ( $severity & ( E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE ) ) ) {
			throw new RuntimeException( $message . ' at ' . $file . ':' . $line );
		}
		return false;
	}
);

try {
	$source_content = '<!-- wp:paragraph --><p>Canonical source copy must remain unchanged.</p><!-- /wp:paragraph -->';
	$source_id = wp_insert_post(
		array(
			'post_type' => 'post',
			'post_status' => 'draft',
			'post_title' => 'Staged preview source fixture',
			'post_content' => $source_content,
		),
		true
	);
	if ( is_wp_error( $source_id ) || $source_id < 1 ) {
		throw new RuntimeException( 'Could not create the canonical source fixture.' );
	}

	$suffix = strtolower( wp_generate_password( 8, false, false ) );
	$job_id = 'tj_preview_' . $suffix;
	$run_id = 'tjr_preview_' . $suffix;
	$artifact_revision = 'ta_preview_' . $suffix;
	$surface_revision = 'sr_preview_' . $suffix;
	$claimed_at = gmdate( 'c', time() - 5 );
	$expires = time() + 600;
	$claim_hash = hash( 'sha256', 'runtime-preview-claim-' . $suffix );
	$target_content = '<!-- wp:paragraph --><p>Dette er den eksakte, iscenesatte oversettelsen.</p><!-- /wp:paragraph -->';

	$job = array(
		'job_id' => $job_id,
		'source_id' => (int) $source_id,
		'target_language' => 'nb',
		'source_revision' => 'source-preview-runtime',
		'publication_surface_contract_revision' => 'runtime-preview-contract',
		'artifact_revision' => $artifact_revision,
		'active_run_id' => $run_id,
		'submission_generation' => 1,
		'status' => 'quality_claimed',
	);
	$run = array( 'job_id' => $job_id, 'run_id' => $run_id, 'role' => 'quality', 'status' => 'running' );
	$claim = array( 'job_id' => $job_id, 'run_id' => $run_id, 'role' => 'quality', 'token_hash' => $claim_hash, 'claimed_at' => $claimed_at, 'expires_at' => gmdate( 'c', $expires ) );
	$artifact = array(
		'artifact_revision' => $artifact_revision,
		'surface_revision' => $surface_revision,
		'job_id' => $job_id,
		'translation_id' => 0,
		'publication_surface_contract_revision' => 'runtime-preview-contract',
		'surface_manifest' => array(
			'language' => 'nb',
			'content' => array( 'title' => 'Iscenesatt tittel', 'excerpt' => 'Iscenesatt ingress', 'gutenberg' => $target_content ),
			'seo' => array( 'title' => 'Iscenesatt SEO-tittel', 'description' => 'Iscenesatt SEO-beskrivelse', 'focus_keyword' => 'iscenesatt' ),
			'taxonomies' => array( 'category' => array(), 'post_tag' => array() ),
			'route' => array( 'localized_slug' => 'iscenesatt-side', 'localized_path' => 'nb/iscenesatt-side' ),
			'media' => array( 'featured_image' => array( 'attachment_id' => 0 ), 'featured_image_alt' => 'Iscenesatt bildebeskrivelse' ),
			'presentation' => array( 'source_design_hash' => 'preview-design-hash', 'localized_fragments' => array() ),
		),
		'artifact' => array( 'title' => 'Iscenesatt tittel' ),
	);
	$artifact = $call_private( 'translation_job_pack_artifact_record', array( $artifact ) );

	$option_keys = array(
		'devenia_workflow_translation_job_' . $job_id,
		'devenia_workflow_translation_run_' . $run_id,
		'devenia_workflow_translation_job_claim_' . $job_id,
		'devenia_workflow_translation_artifact_' . $artifact_revision,
	);
	update_option( $option_keys[0], $job, false );
	update_option( $option_keys[1], $run, false );
	update_option( $option_keys[2], $claim, false );
	update_option( $option_keys[3], $artifact, false );

	$token = (string) $call_private( 'staged_preview_capability_token', array( 'translation', $job_id, $run_id, $artifact_revision, $expires, $claim_hash, 'canonical_source_theme_shell:' . (int) $source_id ) );
	if ( ! $query instanceof WP_Query ) {
		$query = new WP_Query();
		$GLOBALS['wp_query'] = $query;
	}
	$query->set( 'devenia_translation_artifact_preview', $token );
	$query->set( 'p', (int) $source_id );

	$language = Devenia_Workflow::frontend_language();
	$entries_before_preview_projection = $preview_query_entries;
	$preview_posts = Devenia_Workflow::filter_translation_job_preview_posts( array( get_post( $source_id ) ), $query );
	$normalized_query = new WP_Query();
	$normalized_query->set( 'devenia_translation_artifact_preview', $token );
	if ( is_object( $request ) ) { $request->query_vars = array( 'p' => (int) $source_id, 'devenia_translation_artifact_preview' => $token ); }
	$normalized_empty_preview_posts = Devenia_Workflow::filter_translation_job_preview_posts( array(), $normalized_query );
	$preview_projection_query_entries = $preview_query_entries - $entries_before_preview_projection;
	$foreign_query = new WP_Query(); $foreign_query->set( 'p', (int) $source_id + 999 );
	$foreign_posts = Devenia_Workflow::filter_translation_job_preview_posts( array( get_post( $source_id ) ), $foreign_query );
	$presentation = Devenia_Workflow::filter_site_presentation_single_post_context( array(), get_post( $source_id ) );
	$preview_seo_title = Devenia_Workflow::filter_translation_job_preview_seo_title( 'Old SEO title' );
	$preview_canonical = Devenia_Workflow::filter_translation_job_preview_canonical( home_url( '/source/' ) );
	$rank_math_preview = ! defined( 'RANK_MATH_VERSION' ) || (
		'Iscenesatt SEO-tittel' === apply_filters( 'rank_math/opengraph/facebook/title', 'Old social title' )
		&& 'Iscenesatt SEO-tittel' === apply_filters( 'rank_math/opengraph/twitter/title', 'Old social title' )
		&& 'Iscenesatt SEO-beskrivelse' === apply_filters( 'rank_math/opengraph/facebook/description', 'Old social description' )
		&& 'Iscenesatt SEO-beskrivelse' === apply_filters( 'rank_math/opengraph/twitter/description', 'Old social description' )
	);
	$stored_source = get_post( $source_id );
	if (
		'nb' !== $language
		|| 1 !== count( $preview_posts )
		|| $preview_projection_query_entries > 1
		|| $target_content !== (string) ( $preview_posts[0]->post_content ?? '' )
		|| $target_content !== (string) ( $normalized_empty_preview_posts[0]->post_content ?? '' )
		|| $source_content !== (string) ( $foreign_posts[0]->post_content ?? '' )
		|| 'nb' !== (string) ( $presentation['language'] ?? '' )
		|| 'Iscenesatt SEO-tittel' !== $preview_seo_title
		|| home_url( '/nb/iscenesatt-side/' ) !== $preview_canonical
		|| ! $rank_math_preview
		|| ! $stored_source instanceof WP_Post
		|| $source_content !== (string) $stored_source->post_content
		|| 'draft' !== (string) $stored_source->post_status
	) {
		throw new RuntimeException( 'The first-translation preview did not preserve target runtime context and zero source mutation.' );
	}
	$query->set( 'p', (int) $source_id + 999 );
	if (
		'en' !== Devenia_Workflow::frontend_language()
		|| 'Old SEO title' !== Devenia_Workflow::filter_translation_job_preview_seo_title( 'Old SEO title' )
		|| home_url( '/source/' ) !== Devenia_Workflow::filter_translation_job_preview_canonical( home_url( '/source/' ) )
		|| null !== Devenia_Workflow::filter_translation_job_preview_post_metadata( null, (int) $source_id, '_devenia_translation_language', true )
	) {
		throw new RuntimeException( 'A valid preview capability leaked target context onto the wrong request host.' );
	}
	$query->set( 'p', (int) $source_id );

	$existing_translation_id = wp_insert_post(
		array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Old translation', 'post_content' => 'Old stored translation content.' ),
		true
	);
	if ( is_wp_error( $existing_translation_id ) || $existing_translation_id < 1 ) { throw new RuntimeException( 'Could not create the existing translation fixture.' ); }
	update_post_meta( $existing_translation_id, '_devenia_translation_source_id', (int) $source_id );
	update_post_meta( $existing_translation_id, '_devenia_translation_language', 'nb' );
	$relation_job = array_merge( $job, array( 'translation_id' => (int) $existing_translation_id ) );
	$relation_artifact = $call_private( 'translation_job_unpack_artifact_record', array( $artifact ) );
	$relation_artifact['translation_id'] = (int) $existing_translation_id;
	$relation_artifact = $call_private( 'translation_job_pack_artifact_record', array( $relation_artifact ) );
	update_option( $option_keys[0], $relation_job, false );
	update_option( $option_keys[3], $relation_artifact, false );
	$query->set( 'p', (int) $existing_translation_id );
	$stale_source_host_posts = Devenia_Workflow::filter_translation_job_preview_posts( array( get_post( $existing_translation_id ) ), $query );
	if ( 'Old stored translation content.' !== (string) ( $stale_source_host_posts[0]->post_content ?? '' ) ) {
		throw new RuntimeException( 'A source-shell capability remained valid after relation discovery changed the resolved preview host.' );
	}
	update_option( $option_keys[0], $job, false );
	update_option( $option_keys[3], $artifact, false );
	$query->set( 'p', (int) $source_id );
	$existing_job_id = $job_id . '_existing'; $existing_run_id = $run_id . '_existing'; $existing_artifact_revision = $artifact_revision . '_existing';
	$existing_job = array_merge( $job, array( 'job_id' => $existing_job_id, 'artifact_revision' => $existing_artifact_revision, 'active_run_id' => $existing_run_id, 'translation_id' => (int) $existing_translation_id ) );
	$existing_run = array_merge( $run, array( 'job_id' => $existing_job_id, 'run_id' => $existing_run_id ) );
	$existing_claim = array_merge( $claim, array( 'job_id' => $existing_job_id, 'run_id' => $existing_run_id ) );
	$existing_artifact = $call_private( 'translation_job_unpack_artifact_record', array( $artifact ) );
	$existing_artifact['job_id'] = $existing_job_id; $existing_artifact['artifact_revision'] = $existing_artifact_revision; $existing_artifact['translation_id'] = (int) $existing_translation_id;
	$existing_artifact = $call_private( 'translation_job_pack_artifact_record', array( $existing_artifact ) );
	$existing_keys = array( 'devenia_workflow_translation_job_' . $existing_job_id, 'devenia_workflow_translation_run_' . $existing_run_id, 'devenia_workflow_translation_job_claim_' . $existing_job_id, 'devenia_workflow_translation_artifact_' . $existing_artifact_revision );
	$option_keys = array_merge( $option_keys, $existing_keys );
	update_option( $existing_keys[0], $existing_job, false ); update_option( $existing_keys[1], $existing_run, false ); update_option( $existing_keys[2], $existing_claim, false ); update_option( $existing_keys[3], $existing_artifact, false );
	$existing_token = (string) $call_private( 'staged_preview_capability_token', array( 'translation', $existing_job_id, $existing_run_id, $existing_artifact_revision, $expires, $claim_hash, 'existing_translation:' . (int) $existing_translation_id ) );
	$query->set( 'devenia_translation_artifact_preview', $existing_token ); $query->set( 'p', (int) $existing_translation_id );
	$existing_preview_posts = Devenia_Workflow::filter_translation_job_preview_posts( array( get_post( $existing_translation_id ) ), $query );
	$stored_translation = get_post( $existing_translation_id );
	if ( 'nb' !== Devenia_Workflow::frontend_language() || $target_content !== (string) ( $existing_preview_posts[0]->post_content ?? '' ) || 'Old stored translation content.' !== (string) $stored_translation->post_content ) {
		throw new RuntimeException( 'The existing-translation preview did not project the exact staged target without storage mutation.' );
	}

	echo wp_json_encode(
		array(
			'success' => true,
			'target_runtime_language' => true,
			'localized_presentation_context' => true,
			'exact_staged_content' => true,
			'zero_source_mutation' => true,
			'existing_translation_preview' => true,
			'host_relation_change_denied' => true,
		)
	);
} finally {
	restore_error_handler();
	remove_filter( 'the_posts', $count_preview_query_entries, 1 );
	foreach ( $option_keys as $option_key ) { delete_option( $option_key ); }
	if ( $source_id > 0 ) { wp_delete_post( $source_id, true ); }
	if ( $existing_translation_id > 0 ) { wp_delete_post( $existing_translation_id, true ); }
	if ( $query instanceof WP_Query ) { $query->set( 'devenia_translation_artifact_preview', $previous_preview ); }
	if ( is_object( $request ) && is_array( $previous_request_query_vars ) ) { $request->query_vars = $previous_request_query_vars; }
}
