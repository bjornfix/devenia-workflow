<?php
/**
 * Runtime contract: a fresh site can bootstrap its first translated language
 * root, while ordinary translated pages fail closed until that root exists.
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'Devenia_Workflow' ) ) {
	fwrite( STDERR, "Devenia Workflow must be active.\n" );
	exit( 1 );
}

$reflection = new ReflectionClass( 'Devenia_Workflow' );
$call = static function ( string $method, ...$args ) use ( $reflection ) {
	$target = $reflection->getMethod( $method );
	$target->setAccessible( true );
	return $target->invokeArgs( null, $args );
};

$missing = '__devenia_workflow_missing__';
$before_show_on_front = get_option( 'show_on_front', $missing );
$before_page_on_front = get_option( 'page_on_front', $missing );
$before_language_registry = get_option( 'devenia_workflow_language_registry', $missing );
$fixture_ids = array();
$runtime_step_authority = static function ( $decision, string $step, string $token, array $context ): array {
	unset( $decision, $token );
	$process_id = sanitize_key( (string) ( $context['process_id'] ?? 'runtime-language-root' ) );
	$execution_id = sanitize_key( (string) ( $context['execution_id'] ?? $process_id ) );
	return array(
		'success' => true,
		'step' => $step,
		'workflow_step' => $step,
		'step_token_label' => 'runtime-language-root-authority',
		'process_id' => $process_id,
		'control_scope_id' => $execution_id,
		'execution_id' => $execution_id,
		'session_origin' => 'same_session',
		'actor' => 'runtime-language-root-authority',
		'actor_id' => 'runtime_language_root',
		'authority' => 'runtime-test-adapter',
		'authority_vendor' => 'runtime-test-adapter',
		'authority_client' => 'devenia-workflow-runtime',
	);
};
add_filter( 'devenia_translation_step_token_gate', $runtime_step_authority, PHP_INT_MAX, 4 );

$create_page = static function ( string $title, string $slug, int $parent = 0, string $status = 'publish' ) use ( &$fixture_ids ): WP_Post {
	$post_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => $status,
			'post_title'   => $title,
			'post_content' => '<!-- wp:paragraph --><p>Language-root bootstrap fixture.</p><!-- /wp:paragraph -->',
			'post_name'    => $slug,
			'post_parent'  => $parent,
		),
		true
	);
	if ( is_wp_error( $post_id ) || $post_id < 1 ) {
		throw new RuntimeException( 'Could not create language-root bootstrap fixture.' );
	}
	$fixture_ids[] = (int) $post_id;
	return get_post( (int) $post_id );
};

try {
	$token = strtolower( wp_generate_password( 8, false, false ) );
	$languages = Devenia_Workflow::languages( true );
	$source_language = (string) $call( 'source_language_code' );
	$target_template = array_values(
		array_filter(
			$languages,
			static fn( array $config ): bool => empty( $config['source'] )
		)
	)[0] ?? array();
	if ( '' === $source_language || empty( $target_template ) ) {
		throw new RuntimeException( 'A configured source and target language are required.' );
	}
	$language = sanitize_key( 'zzr' . substr( $token, 0, 5 ) );
	$prefix = $language;
	$target_template['name'] = 'Runtime language root';
	$target_template['locale'] = 'en_US';
	$target_template['prefix'] = $prefix;
	$target_template['source'] = false;
	$languages[ $language ] = $target_template;
	update_option( 'devenia_workflow_language_registry', $languages, false );
	Devenia_Workflow::languages( true );

	$fresh_front = $create_page( 'Fresh source front ' . $token, 'fresh-source-front-' . $token );
	$dependent = $create_page( 'Fresh dependent ' . $token, 'fresh-dependent-' . $token );
	update_option( 'show_on_front', 'page', false );
	update_option( 'page_on_front', (int) $fresh_front->ID, false );

	$front_resolution = $call( 'translation_job_resolve_localized_parent', $fresh_front, $language, 0, '' );
	if ( empty( $front_resolution['success'] ) || 0 !== absint( $front_resolution['parent_id'] ?? -1 ) ) {
		throw new RuntimeException( 'The translated front page was not allowed to bootstrap at the root: ' . wp_json_encode( $front_resolution ) );
	}
	update_option( 'show_on_front', 'posts', false );
	if ( $call( 'is_front_page_source', $fresh_front ) ) {
		throw new RuntimeException( 'A stale page_on_front value granted language-root authority while the site displays posts.' );
	}
	$stale_front_resolution = $call( 'translation_job_resolve_localized_parent', $fresh_front, $language, 0, '' );
	if ( ! empty( $stale_front_resolution['success'] ) || 'localized_language_root_missing' !== (string) ( $stale_front_resolution['code'] ?? '' ) ) {
		throw new RuntimeException( 'A stale page_on_front value bypassed the missing-root boundary: ' . wp_json_encode( $stale_front_resolution ) );
	}
	update_option( 'show_on_front', 'page', false );

	$root_path = $call( 'expected_localized_path_for_new_page', 0, $prefix, $language, (int) $fresh_front->ID );
	if ( $prefix !== $root_path ) {
		throw new RuntimeException( 'The first translated front page did not resolve to the language root path: ' . wp_json_encode( array( 'expected' => $prefix, 'actual' => $root_path ) ) );
	}
	$root_write = $call(
		'upsert_translation',
		array(
			'source_id' => (int) $fresh_front->ID,
			'language' => $language,
			'title' => 'Runtime translated language root ' . $token,
			'content' => '<!-- wp:paragraph --><p>جذر لغة تجريبي.</p><!-- /wp:paragraph -->',
			'localized_slug' => $prefix,
			'localized_path' => $prefix,
			'status' => 'draft',
			'translation_status' => 'needs_review',
			'design_only' => true,
			'execution_id' => 'runtime-language-root-' . $token,
			'writer_process_id' => 'runtime-language-root-' . $token,
		)
	);
	$root_translation_id = absint( $root_write['translation']['id'] ?? 0 );
	if ( empty( $root_write['success'] ) || $root_translation_id < 1 ) {
		throw new RuntimeException( 'The Workflow write Interface could not create the first translated language root: ' . wp_json_encode( $root_write ) );
	}
	$fixture_ids[] = $root_translation_id;
	$root_translation = get_post( $root_translation_id );
	if (
		! ( $root_translation instanceof WP_Post )
		|| $prefix !== (string) $root_translation->post_name
		|| 0 !== (int) $root_translation->post_parent
		|| $prefix !== trim( (string) get_post_meta( $root_translation_id, '_devenia_translation_localized_path', true ), '/' )
		|| $prefix !== trim( (string) get_page_uri( $root_translation_id ), '/' )
	) {
		throw new RuntimeException( 'The WordPress Page Hierarchy Adapter did not establish the exact root path: ' . wp_json_encode( array( 'post' => $root_translation, 'localized_path' => get_post_meta( $root_translation_id, '_devenia_translation_localized_path', true ), 'page_uri' => get_page_uri( $root_translation_id ) ) ) );
	}

	$missing_root_resolution = $call( 'translation_job_resolve_localized_parent', $dependent, $language, 0, '' );
	if (
		! empty( $missing_root_resolution['success'] )
		|| 'localized_language_root_missing' !== (string) ( $missing_root_resolution['code'] ?? '' )
	) {
		throw new RuntimeException( 'A dependent translated page did not fail closed before the language root existed: ' . wp_json_encode( $missing_root_resolution ) );
	}
	$dependent_write = $call(
		'upsert_translation',
		array(
			'source_id' => (int) $dependent->ID,
			'language' => $language,
			'title' => 'Runtime translated dependent ' . $token,
			'content' => '<!-- wp:paragraph --><p>صفحة مترجمة تابعة.</p><!-- /wp:paragraph -->',
			'localized_slug' => 'translated-dependent-' . $token,
			'status' => 'draft',
			'translation_status' => 'needs_review',
			'design_only' => true,
			'execution_id' => 'runtime-missing-language-root-' . $token,
			'writer_process_id' => 'runtime-missing-language-root-' . $token,
		)
	);
	$dependent_translation_id = absint( $call( 'find_translation_id', (int) $dependent->ID, $language, array( 'publish', 'draft', 'pending', 'private' ) ) );
	if (
		! empty( $dependent_write['success'] )
		|| 'localized_language_root_missing' !== (string) ( $dependent_write['code'] ?? '' )
		|| 0 !== $dependent_translation_id
	) {
		throw new RuntimeException( 'The Workflow write Interface did not reject the dependent page before any WordPress mutation: ' . wp_json_encode( array( 'write' => $dependent_write, 'translation_id' => $dependent_translation_id ) ) );
	}
	$caller_parent = $create_page( 'Caller supplied target parent ' . $token, 'caller-parent-' . $token );
	update_post_meta( (int) $caller_parent->ID, '_devenia_translation_language', $language );
	$caller_parent_bypass = $call( 'translation_job_resolve_localized_parent', $dependent, $language, (int) $caller_parent->ID, '' );
	if ( ! empty( $caller_parent_bypass['success'] ) || 'localized_language_root_missing' !== (string) ( $caller_parent_bypass['code'] ?? '' ) ) {
		throw new RuntimeException( 'A caller-supplied parent bypassed the missing published language root: ' . wp_json_encode( $caller_parent_bypass ) );
	}
	$call(
		'with_direct_save_storage_guardrails_suspended',
		$root_translation_id,
		static function () use ( $root_translation_id ): void {
			wp_delete_post( $root_translation_id, true );
		}
	);
	$fixture_ids = array_values( array_diff( $fixture_ids, array( $root_translation_id ) ) );

	$draft_token = strtolower( wp_generate_password( 8, false, false ) );
	$draft_front = $create_page( 'Draft-root source front ' . $draft_token, 'draft-root-source-front-' . $draft_token );
	$draft_root = $create_page( 'Draft target root ' . $draft_token, 'draft-root-' . $draft_token, 0, 'draft' );
	$draft_dependent = $create_page( 'Draft-root dependent ' . $draft_token, 'draft-root-dependent-' . $draft_token );
	$call( 'apply_translation_lifecycle_meta', (int) $draft_root->ID, (int) $draft_front->ID, $language, 'needs_review', $draft_front );
	update_option( 'page_on_front', (int) $draft_front->ID, false );
	$draft_packet = $call(
		'translation_job_translation_packet',
		array(
			'job_id' => 'runtime-draft-language-root-packet-' . $draft_token,
			'source_id' => (int) $draft_front->ID,
			'target_language' => $language,
			'submission_generation' => 1,
			'publication_surface_contract_revision' => '',
		),
		array( 'run_id' => 'runtime-draft-language-root-packet-' . $draft_token, 'role' => 'translator', 'budget' => array(), 'principal' => array() ),
		$draft_front
	);
	if (
		empty( $draft_packet['route']['language_root_bootstrap'] )
		|| $prefix !== (string) ( $draft_packet['route']['required_localized_slug'] ?? '' )
		|| $prefix !== (string) ( $draft_packet['route']['required_localized_path'] ?? '' )
	) {
		throw new RuntimeException( 'A non-published front-page translation incorrectly disabled the bootstrap route contract: ' . wp_json_encode( $draft_packet['route'] ?? array() ) );
	}
	$draft_fragments = array_map(
		static fn( array $fragment ): array => array( 'key' => (string) ( $fragment['key'] ?? '' ), 'html' => 'جذر لغة تجريبي.' ),
		(array) ( $draft_packet['fragments'] ?? array() )
	);
	$draft_job = array(
		'job_id' => 'runtime-draft-language-root-stage-' . $draft_token,
		'source_id' => (int) $draft_front->ID,
		'target_language' => $language,
		'translation_id' => (int) $draft_root->ID,
		'source_revision' => 'runtime-draft-language-root-source-' . $draft_token,
		'publication_surface_contract_revision' => '',
	);
	$draft_artifact = array(
		'title' => 'جذر لغة تجريبي',
		'excerpt' => '',
		'localized_slug' => $prefix,
		'localized_path' => $prefix,
		'localized_fragments' => $draft_fragments,
		'seo' => array( 'title' => 'جذر لغة تجريبي', 'description' => 'وصف تجريبي.', 'focus_keyword' => '' ),
	);
	$draft_stage = $call( 'translation_job_stage_artifact', $draft_job, $draft_artifact );
	$draft_staged_route = (array) ( $draft_stage['manifest']['route'] ?? array() );
	if (
		empty( $draft_stage['success'] )
		|| (int) $draft_root->ID !== absint( $draft_staged_route['translation_id'] ?? 0 )
		|| $prefix !== (string) ( $draft_staged_route['localized_slug'] ?? '' )
		|| $prefix !== (string) ( $draft_staged_route['localized_path'] ?? '' )
	) {
		throw new RuntimeException( 'Staging did not adopt the required root route for an existing non-published translation: ' . wp_json_encode( $draft_stage ) );
	}
	$draft_record = array(
		'artifact' => $draft_artifact,
		'content_revision' => (string) ( $draft_stage['content_revision'] ?? '' ),
		'surface_revision' => (string) ( $draft_stage['surface_revision'] ?? '' ),
		'surface_manifest' => (array) ( $draft_stage['manifest'] ?? array() ),
		'baseline_surface_revision' => (string) $call( 'translation_job_current_surface_revision', (int) $draft_root->ID ),
		'writer_principal' => array( 'principal_id' => 'runtime-draft-language-root-writer', 'run_id' => 'runtime-draft-language-root-writer' ),
	);
	$draft_apply = $call(
		'translation_job_apply_staged_artifact_uncommitted',
		$draft_job,
		$draft_record,
		array( 'publication_attempt_id' => 'runtime-draft-language-root-apply', 'term_scope' => array(), 'identity_scope' => array() ),
		(int) $draft_root->ID
	);
	$draft_root_after = get_post( (int) $draft_root->ID );
	if (
		empty( $draft_apply['success'] )
		|| ! ( $draft_root_after instanceof WP_Post )
		|| $prefix !== (string) $draft_root_after->post_name
		|| 0 !== (int) $draft_root_after->post_parent
		|| $prefix !== trim( (string) get_post_meta( (int) $draft_root->ID, '_devenia_translation_localized_path', true ), '/' )
	) {
		throw new RuntimeException( 'Staged apply did not correct the existing draft into the required language-root route: ' . wp_json_encode( array( 'stage' => $draft_stage, 'apply' => $draft_apply, 'post' => $draft_root_after ) ) );
	}
	$draft_root_resolution = $call( 'translation_job_resolve_localized_parent', $draft_dependent, $language, 0, '' );
	if ( ! empty( $draft_root_resolution['success'] ) || 'localized_language_root_missing' !== (string) ( $draft_root_resolution['code'] ?? '' ) ) {
		throw new RuntimeException( 'A non-published translated front page was accepted as reader route authority: ' . wp_json_encode( $draft_root_resolution ) );
	}

	$second_token = strtolower( wp_generate_password( 8, false, false ) );
	$legacy_slug = 'legacy-root-' . $second_token;
	$established_front = $create_page( 'Established source front ' . $second_token, 'established-source-front-' . $second_token );
	$established_root = $create_page( 'Established target root ' . $second_token, $legacy_slug );
	$established_dependent = $create_page( 'Established dependent ' . $second_token, 'established-dependent-' . $second_token );
	$call( 'apply_translation_lifecycle_meta', (int) $established_root->ID, (int) $established_front->ID, $language, 'published', $established_front );
	$call(
		'with_direct_save_storage_guardrails_suspended',
		(int) $established_root->ID,
		static function () use ( $established_root ): void {
			wp_update_post( array( 'ID' => (int) $established_root->ID, 'post_status' => 'publish' ) );
		}
	);
	update_post_meta( (int) $established_root->ID, '_devenia_translation_localized_path', $legacy_slug );
	$call( 'sync_translation_index_row', (int) $established_root->ID );
	update_option( 'page_on_front', (int) $established_front->ID, false );

	$established_resolution = $call( 'translation_job_resolve_localized_parent', $established_dependent, $language, 0, '' );
	if (
		empty( $established_resolution['success'] )
		|| (int) $established_root->ID !== absint( $established_resolution['parent_id'] ?? 0 )
	) {
		throw new RuntimeException( 'A dependent translated page did not resolve under the established language root: ' . wp_json_encode( array( 'resolution' => $established_resolution, 'post_status' => get_post_status( (int) $established_root->ID ), 'source_meta' => get_post_meta( (int) $established_root->ID, '_devenia_translation_source_id', true ), 'language_meta' => get_post_meta( (int) $established_root->ID, '_devenia_translation_language', true ), 'status_meta' => get_post_meta( (int) $established_root->ID, '_devenia_translation_status', true ), 'find' => $call( 'find_translation_id', (int) $established_front->ID, $language, array( 'publish' ) ), 'index' => $call( 'translation_index_id_for_source_language', (int) $established_front->ID, $language, array( 'publish' ) ) ) ) );
	}

	$call( 'store_translation_canonical_route', get_post( (int) $established_root->ID ), $language );
	$legacy_route_before = get_post_meta( (int) $established_root->ID, '_devenia_translation_canonical_route_v1', true );
	$legacy_packet = $call(
		'translation_job_translation_packet',
		array(
			'job_id' => 'runtime-language-root-packet-' . $second_token,
			'source_id' => (int) $established_front->ID,
			'target_language' => $language,
			'submission_generation' => 1,
			'publication_surface_contract_revision' => '',
		),
		array( 'run_id' => 'runtime-language-root-packet-' . $second_token, 'role' => 'translator', 'budget' => array(), 'principal' => array() ),
		$established_front
	);
	if (
		empty( $legacy_packet['route']['language_root'] )
		|| ! empty( $legacy_packet['route']['language_root_bootstrap'] )
		|| '' !== (string) ( $legacy_packet['route']['required_localized_slug'] ?? '' )
		|| '' !== (string) ( $legacy_packet['route']['required_localized_path'] ?? '' )
	) {
		throw new RuntimeException( 'The translator packet contradicted the immutable established language-root route: ' . wp_json_encode( $legacy_packet['route'] ?? array() ) );
	}
	$legacy_write = $call(
		'upsert_translation',
		array(
			'source_id' => (int) $established_front->ID,
			'translation_id' => (int) $established_root->ID,
			'language' => $language,
			'title' => 'Established target root refreshed ' . $second_token,
			'content' => '<!-- wp:paragraph --><p>محتوى جذر مترجم محدث.</p><!-- /wp:paragraph -->',
			'status' => 'publish',
			'translation_status' => 'needs_review',
			'allow_update_published' => true,
			'design_only' => true,
			'execution_id' => 'runtime-legacy-language-root-' . $second_token,
			'writer_process_id' => 'runtime-legacy-language-root-' . $second_token,
		)
	);
	$legacy_route_after = get_post_meta( (int) $established_root->ID, '_devenia_translation_canonical_route_v1', true );
	$legacy_after = get_post( (int) $established_root->ID );
	if (
		empty( $legacy_write['success'] )
		|| ! ( $legacy_after instanceof WP_Post )
		|| $legacy_slug !== (string) $legacy_after->post_name
		|| 0 !== (int) $legacy_after->post_parent
		|| $legacy_slug !== trim( (string) get_post_meta( (int) $established_root->ID, '_devenia_translation_localized_path', true ), '/' )
		|| wp_json_encode( $legacy_route_before ) !== wp_json_encode( $legacy_route_after )
	) {
		throw new RuntimeException( 'Ordinary Workflow publication did not preserve the established language-root Canonical Route Contract byte-for-byte: ' . wp_json_encode( array( 'write' => $legacy_write, 'post' => $legacy_after, 'route_before' => $legacy_route_before, 'route_after' => $legacy_route_after ) ) );
	}

	echo "PASS: first translated language-root bootstrap contract\n";
} finally {
	remove_filter( 'devenia_translation_step_token_gate', $runtime_step_authority, PHP_INT_MAX );
	if ( $missing === $before_show_on_front ) {
		delete_option( 'show_on_front' );
	} else {
		update_option( 'show_on_front', $before_show_on_front, false );
	}
	if ( $missing === $before_page_on_front ) {
		delete_option( 'page_on_front' );
	} else {
		update_option( 'page_on_front', $before_page_on_front, false );
	}
	if ( $missing === $before_language_registry ) {
		delete_option( 'devenia_workflow_language_registry' );
	} else {
		update_option( 'devenia_workflow_language_registry', $before_language_registry, false );
	}
	Devenia_Workflow::languages( true );
	foreach ( array_reverse( $fixture_ids ) as $fixture_id ) {
		wp_delete_post( $fixture_id, true );
	}
}
