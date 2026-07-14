<?php
/** Dev runtime contract for exact staged-publication recovery. */

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

global $wpdb;

$post_ids = array();
$term_ids = array();
$menu_ids = array();
$menu_option_missing = '__recovery_menu_option_missing__';
$original_menu_option = get_option( Devenia_Workflow::OPTION_LOCALIZED_MENU_IDENTITIES, $menu_option_missing );
$call = static function ( string $method, array $arguments = array() ) {
	$reflection = new ReflectionMethod( Devenia_Workflow::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $arguments );
};
$update_internal_post = static function ( array $post_data ) use ( $call ) {
	$result = null;
	$call(
		'with_direct_save_storage_guardrails_suspended',
		array(
			static function () use ( &$result, $post_data ): void {
				$result = wp_update_post( $post_data, true );
			},
		)
	);
	return $result;
};
$assert = static function ( bool $passed, string $message ): void {
	if ( ! $passed ) { throw new RuntimeException( $message ); }
};
$run_blocked_query = static function ( wpdb $connection, string $query ): array {
	$result = $connection->query( $query );
	$errno = $connection->dbh instanceof mysqli ? mysqli_errno( $connection->dbh ) : 0;
	return array( 'result' => $result, 'errno' => $errno, 'error' => (string) $connection->last_error );
};
$assert_lock_failure = static function ( array $attempt, string $message ) use ( $assert ): void {
	$assert( false === $attempt['result'] && in_array( (int) $attempt['errno'], array( 1205, 1213 ), true ) && '' !== trim( (string) $attempt['error'] ), $message . ': ' . wp_json_encode( $attempt ) );
};
$progress = static function ( string $stage ): void {
	fwrite( STDERR, wp_json_encode( array( 'runtime_stage' => $stage, 'memory_bytes' => memory_get_usage( true ) ) ) . PHP_EOL );
	fflush( STDERR );
};

try {
	$progress( 'start' );
	$languages = Devenia_Workflow::languages( true );
	$targets = array_values( array_filter( array_keys( $languages ), static function ( string $language ) use ( $languages ): bool { return empty( $languages[ $language ]['source'] ); } ) );
	$language = sanitize_key( (string) ( $targets[0] ?? '' ) );
	$assert( '' !== $language, 'No target language is configured for the recovery runtime.' );

	$source_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Recovery source', 'post_content' => '<!-- wp:paragraph --><p>Recovery source.</p><!-- /wp:paragraph -->' ), true );
	$assert( ! is_wp_error( $source_id ), 'Could not create recovery source.' );
	$post_ids[] = (int) $source_id;
	$identity_scope = array( 'source_id' => (int) $source_id, 'language' => $language, 'post_type' => 'post' );
	$translation_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Recovery before', 'post_content' => '<!-- wp:paragraph --><p>Before.</p><!-- /wp:paragraph -->' ), true );
	$assert( ! is_wp_error( $translation_id ), 'Could not create recovery translation.' );
	$post_ids[] = (int) $translation_id;
	update_post_meta( $translation_id, Devenia_Workflow::META_SOURCE_ID, (int) $source_id );
	update_post_meta( $translation_id, Devenia_Workflow::META_LANGUAGE, $language );
	update_post_meta( $translation_id, '_recovery_fixture_meta', 'before' );
	$retry_resolved_id = $call( 'translation_job_resolve_publication_translation_id', array( array( 'source_id' => (int) $source_id, 'target_language' => $language, 'translation_id' => 0 ), array( 'translation_id' => 0 ) ) );
	$assert( (int) $translation_id === (int) $retry_resolved_id, 'A retry with stale translation_id=0 did not resolve the existing translation identity.' );
	$wrong_type_translation_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Wrong recovery post type' ), true );
	$assert( ! is_wp_error( $wrong_type_translation_id ), 'Could not create wrong-post-type fixture.' );
	$post_ids[] = (int) $wrong_type_translation_id;
	update_post_meta( $wrong_type_translation_id, Devenia_Workflow::META_SOURCE_ID, (int) $source_id );
	update_post_meta( $wrong_type_translation_id, Devenia_Workflow::META_LANGUAGE, $language );
	$wrong_type_resolved_id = $call( 'translation_job_resolve_publication_translation_id', array( array( 'source_id' => (int) $source_id, 'target_language' => $language, 'translation_id' => (int) $wrong_type_translation_id ), array( 'translation_id' => (int) $wrong_type_translation_id ) ) );
	$assert( (int) $translation_id === (int) $wrong_type_resolved_id, 'A stale translation ID with a mismatched source post type was trusted.' );
	$wrong_language_translation_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Wrong recovery language' ), true );
	$assert( ! is_wp_error( $wrong_language_translation_id ), 'Could not create wrong-language fixture.' );
	$post_ids[] = (int) $wrong_language_translation_id;
	update_post_meta( $wrong_language_translation_id, Devenia_Workflow::META_SOURCE_ID, (int) $source_id );
	update_post_meta( $wrong_language_translation_id, Devenia_Workflow::META_LANGUAGE, 'source-language-mismatch' );
	$wrong_language_resolved_id = $call( 'translation_job_resolve_publication_translation_id', array( array( 'source_id' => (int) $source_id, 'target_language' => $language, 'translation_id' => (int) $wrong_language_translation_id ), array( 'translation_id' => (int) $wrong_language_translation_id ) ) );
	$assert( (int) $translation_id === (int) $wrong_language_resolved_id, 'A stale translation ID with a mismatched language was trusted.' );
	$wrong_translation_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Wrong recovery identity' ), true );
	$assert( ! is_wp_error( $wrong_translation_id ), 'Could not create wrong-identity fixture.' );
	$post_ids[] = (int) $wrong_translation_id;
	update_post_meta( $wrong_translation_id, Devenia_Workflow::META_SOURCE_ID, (int) $source_id + 1 );
	update_post_meta( $wrong_translation_id, Devenia_Workflow::META_LANGUAGE, $language );
	$ownership_resolved_id = $call( 'translation_job_resolve_publication_translation_id', array( array( 'source_id' => (int) $source_id, 'target_language' => $language, 'translation_id' => (int) $wrong_translation_id ), array( 'translation_id' => (int) $wrong_translation_id ) ) );
	$assert( (int) $translation_id === (int) $ownership_resolved_id, 'A stale nonzero translation ID with wrong source ownership was trusted.' );
	$deleted_stale_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Deleted recovery identity' ), true );
	$assert( ! is_wp_error( $deleted_stale_id ), 'Could not create deleted-ID fixture.' );
	wp_delete_post( (int) $deleted_stale_id, true );
	$deleted_resolved_id = $call( 'translation_job_resolve_publication_translation_id', array( array( 'source_id' => (int) $source_id, 'target_language' => $language, 'translation_id' => (int) $deleted_stale_id ), array( 'translation_id' => (int) $deleted_stale_id ) ) );
	$assert( (int) $translation_id === (int) $deleted_resolved_id, 'A deleted stale nonzero translation ID did not fall back to the canonical translation.' );
	$assert( $call( 'translation_job_snapshot_translation_identity_matches', array( array( 'existed' => true, 'translation_id' => (int) $translation_id ), (int) $translation_id ) ), 'An unchanged existing snapshot identity was rejected.' );
	$assert( ! $call( 'translation_job_snapshot_translation_identity_matches', array( array( 'existed' => false, 'translation_id' => 0 ), (int) $translation_id ) ), 'A 0-to-existing translation identity race was accepted.' );
	$assert( ! $call( 'translation_job_snapshot_translation_identity_matches', array( array( 'existed' => true, 'translation_id' => (int) $translation_id ), 0 ) ), 'An existing-to-missing translation identity race was accepted.' );
	$progress( 'resolver_fixtures_complete' );

	$absent_source_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Absent recovery identity source' ), true );
	$assert( ! is_wp_error( $absent_source_id ), 'Could not create absent-identity source.' );
	$post_ids[] = (int) $absent_source_id;
	$absent_candidate_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Absent recovery identity candidate' ), true );
	$assert( ! is_wp_error( $absent_candidate_id ), 'Could not create absent-identity candidate.' );
	$post_ids[] = (int) $absent_candidate_id;
	$absent_wrong_type_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Absent recovery identity wrong type' ), true );
	$assert( ! is_wp_error( $absent_wrong_type_id ), 'Could not create absent-identity wrong-type candidate.' );
	$post_ids[] = (int) $absent_wrong_type_id;
	update_post_meta( $absent_wrong_type_id, Devenia_Workflow::META_SOURCE_ID, (int) $absent_source_id );
	update_post_meta( $absent_wrong_type_id, Devenia_Workflow::META_LANGUAGE, $language );
	$absent_identity_scope = array( 'source_id' => (int) $absent_source_id, 'language' => $language, 'post_type' => 'page' );
	$assert( '' !== (string) $call( 'translation_job_rollback_cas_revision', array( 0, array(), $absent_identity_scope ) ), 'An absent page translation identity produced an empty receipt.' );
	$assert( $call( 'translation_job_begin_recovery_transaction' ), 'Could not start the absent translation-identity fixture.' );
	$absent_identity_lock = $call( 'translation_job_lock_recovery_surface', array( 0, array(), $absent_identity_scope ) );
	$assert( ! empty( $absent_identity_lock['success'] ) && 0 === (int) ( $absent_identity_lock['identity_translation_id'] ?? -1 ), 'Could not lock the absent canonical translation identity.' );
	$identity_secondary = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$identity_secondary->set_prefix( $wpdb->prefix );
	$identity_secondary->suppress_errors( true );
	$assert( '1' === (string) $identity_secondary->get_var( 'SELECT 1' ) && false !== $identity_secondary->query( 'SET SESSION innodb_lock_wait_timeout = 1' ), 'The secondary translation-identity connection is not healthy.' );
	$blocked_translation_identity_insert = $run_blocked_query( $identity_secondary, $identity_secondary->prepare( "INSERT INTO {$identity_secondary->postmeta} (post_id, meta_key, meta_value) VALUES (%d, %s, %s)", (int) $absent_candidate_id, Devenia_Workflow::META_SOURCE_ID, (string) $absent_source_id ) );
	$blocked_translation_type_update = $run_blocked_query( $identity_secondary, $identity_secondary->prepare( "UPDATE {$identity_secondary->posts} SET post_type = %s WHERE ID = %d", 'page', (int) $absent_wrong_type_id ) );
	$call( 'translation_job_rollback_recovery_transaction' );
	$assert_lock_failure( $blocked_translation_identity_insert, 'Absent canonical identity metadata insert was not blocked by a real database lock' );
	$assert_lock_failure( $blocked_translation_type_update, 'Wrong-type to target-type identity update was not blocked by a real database lock' );
	$assert( ! metadata_exists( 'post', $absent_candidate_id, Devenia_Workflow::META_SOURCE_ID ) && 'post' === (string) get_post_type( $absent_wrong_type_id ), 'A second connection changed canonical translation identity state despite lock failures.' );
	$progress( 'absent_translation_identity_lock_complete' );

	$source_term = wp_insert_term( 'Recovery Source Category', 'category', array( 'slug' => 'recovery-source-' . wp_generate_password( 6, false, false ) ) );
	$assert( ! is_wp_error( $source_term ), 'Could not create source term.' );
	$source_term_id = absint( $source_term['term_id'] ?? 0 );
	$term_ids[] = array( $source_term_id, 'category' );
	wp_set_post_terms( (int) $source_id, array( $source_term_id ), 'category', false );
	$localized_term = wp_insert_term( 'Recovery Before Category', 'category', array( 'slug' => $language . '-recovery-before-' . wp_generate_password( 6, false, false ), 'description' => 'Before description.' ) );
	$assert( ! is_wp_error( $localized_term ), 'Could not create localized term.' );
	$localized_term_id = absint( $localized_term['term_id'] ?? 0 );
	$term_ids[] = array( $localized_term_id, 'category' );
	update_term_meta( $localized_term_id, Devenia_Workflow::TERM_META_SOURCE_ID, $source_term_id );
	update_term_meta( $localized_term_id, Devenia_Workflow::TERM_META_LANGUAGE, $language );
	update_term_meta( $localized_term_id, '_recovery_term_meta', 'before' );
	wp_set_post_terms( (int) $translation_id, array( $localized_term_id ), 'category', false );

	$assert( $call( 'translation_job_begin_recovery_transaction' ), 'Could not start the row-lock contention fixture.' );
	$lock_result = $call( 'translation_job_lock_recovery_surface', array( (int) $translation_id, array() ) );
	$assert( ! empty( $lock_result['success'] ), 'Could not lock the recovery surface for the contention fixture.' );
	$secondary = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$secondary->set_prefix( $wpdb->prefix );
	$secondary->suppress_errors( true );
	$assert( '1' === (string) $secondary->get_var( 'SELECT 1' ) && false !== $secondary->query( 'SET SESSION innodb_lock_wait_timeout = 1' ), 'The secondary recovery-surface connection is not healthy.' );
	$blocked_write = $run_blocked_query( $secondary, $secondary->prepare( "UPDATE {$secondary->postmeta} SET meta_value = %s WHERE post_id = %d AND meta_key = %s", 'must-not-commit', (int) $translation_id, '_recovery_fixture_meta' ) );
	$blocked_insert = $run_blocked_query( $secondary, $secondary->prepare( "INSERT INTO {$secondary->postmeta} (post_id, meta_key, meta_value) VALUES (%d, %s, %s)", (int) $translation_id, '_recovery_concurrent_insert', 'must-not-commit' ) );
	$call( 'translation_job_rollback_recovery_transaction' );
	$assert_lock_failure( $blocked_write, 'Existing recovery metadata update was not blocked by a real database lock' );
	$assert_lock_failure( $blocked_insert, 'Existing recovery metadata insert was not blocked by a real database lock' );
	$assert( 'before' === (string) get_post_meta( $translation_id, '_recovery_fixture_meta', true ) && ! metadata_exists( 'post', $translation_id, '_recovery_concurrent_insert' ), 'A second connection wrote through the recovery row/range lock.' );
	$progress( 'post_surface_lock_complete' );

	$identity_source = wp_insert_term( 'Recovery Identity Lock Source', 'category', array( 'slug' => 'recovery-identity-source-' . wp_generate_password( 6, false, false ) ) );
	$assert( ! is_wp_error( $identity_source ), 'Could not create identity-lock source term.' );
	$identity_source_id = absint( $identity_source['term_id'] ?? 0 );
	$term_ids[] = array( $identity_source_id, 'category' );
	$identity_candidate = wp_insert_term( 'Recovery Identity Lock Candidate', 'category', array( 'slug' => $language . '-recovery-identity-candidate-' . wp_generate_password( 6, false, false ) ) );
	$assert( ! is_wp_error( $identity_candidate ), 'Could not create identity-lock candidate term.' );
	$identity_candidate_id = absint( $identity_candidate['term_id'] ?? 0 );
	$term_ids[] = array( $identity_candidate_id, 'category' );
	$term_identity_scope = array( 'category:' . $identity_source_id . ':' . $language => array( 'taxonomy' => 'category', 'source_term_id' => $identity_source_id, 'language' => $language, 'existed' => false ) );
	$assert( $call( 'translation_job_begin_recovery_transaction' ), 'Could not start the absent-identity lock fixture.' );
	$identity_lock = $call( 'translation_job_lock_recovery_surface', array( 0, $term_identity_scope ) );
	$assert( ! empty( $identity_lock['success'] ), 'Could not lock the absent translated-term identity range.' );
	$assert( '1' === (string) $secondary->get_var( 'SELECT 1' ) && false !== $secondary->query( 'SET SESSION innodb_lock_wait_timeout = 1' ), 'The secondary term-identity connection is not healthy.' );
	$blocked_identity_insert = $run_blocked_query( $secondary, $secondary->prepare( "INSERT INTO {$secondary->termmeta} (term_id, meta_key, meta_value) VALUES (%d, %s, %s)", $identity_candidate_id, Devenia_Workflow::TERM_META_SOURCE_ID, (string) $identity_source_id ) );
	$call( 'translation_job_rollback_recovery_transaction' );
	$assert_lock_failure( $blocked_identity_insert, 'Absent translated-term identity insert was not blocked by a real database lock' );
	$assert( ! metadata_exists( 'term', $identity_candidate_id, Devenia_Workflow::TERM_META_SOURCE_ID ), 'A second connection created an initially absent translated-term identity through the range lock.' );
	$progress( 'absent_term_identity_lock_complete' );

	$manifest = array( 'language' => $language, 'taxonomies' => array( 'category' => array( array( 'source_term_id' => $source_term_id, 'taxonomy' => 'category', 'language' => $language ) ), 'post_tag' => array() ) );
	$snapshot = $call( 'translation_job_capture_surface_snapshot', array( (int) $translation_id, $manifest, $identity_scope ) );
	$assert( ! empty( $snapshot['snapshot_valid'] ) && ! empty( $snapshot['captured_cas_revision'] ), 'Existing recovery snapshot was not complete: ' . wp_json_encode( $snapshot ) );
	$assert( ! empty( $snapshot['existed'] ), 'Retry snapshot misclassified the resolved published translation as a new candidate.' );
	$progress( 'existing_snapshot_complete' );
	$fixture_post = get_post( (int) $translation_id );
	$fixture_update_data = array(
		'post_type'   => (string) $fixture_post->post_type,
		'post_name'   => (string) $fixture_post->post_name,
		'post_parent' => (int) $fixture_post->post_parent,
	);
	$progress( 'direct_save_duplicate_suffix_probe_begin' );
	$call( 'has_wordpress_duplicate_slug_suffix', array( (string) $fixture_post->post_name ) );
	$progress( 'direct_save_duplicate_suffix_probe_complete' );
	$progress( 'direct_save_slug_language_probe_begin' );
	$call( 'translated_post_slug_language_issue', array( (int) $translation_id, (string) $fixture_post->post_name, $language ) );
	$progress( 'direct_save_slug_language_probe_complete' );
	$progress( 'direct_save_slug_conflicts_probe_begin' );
	$call( 'translation_slug_conflicts', array( (string) $fixture_post->post_name, (string) $fixture_post->post_type, (int) $fixture_post->post_parent, (int) $translation_id ) );
	$progress( 'direct_save_slug_conflicts_probe_complete' );
	$progress( 'direct_save_route_probe_begin' );
	$call( 'translation_direct_save_route_issues', array( (int) $translation_id, $fixture_update_data ) );
	$progress( 'direct_save_route_probe_complete' );
	$progress( 'direct_save_link_probe_begin' );
	$call( 'hard_invalid_link_issues_for_content', array( (string) $fixture_post->post_content, $language ) );
	$progress( 'direct_save_link_probe_complete' );
	$progress( 'direct_save_design_probe_begin' );
	$call( 'translation_direct_save_source_design_issues', array( (int) $translation_id, (string) $fixture_post->post_content, $language ) );
	$progress( 'direct_save_design_probe_complete' );
	$progress( 'direct_save_gutenberg_probe_begin' );
	$call( 'gutenberg_saved_markup_integrity', array( (string) $fixture_post->post_content ) );
	$progress( 'direct_save_gutenberg_probe_complete' );
	$progress( 'existing_mutation_begin' );
	$assert( ! is_wp_error( $update_internal_post( array( 'ID' => (int) $translation_id, 'post_title' => 'Recovery mutated' ) ) ), 'Could not mutate the existing recovery fixture.' );
	$progress( 'existing_post_mutated' );
	update_post_meta( $translation_id, '_recovery_fixture_meta', 'mutated' );
	$progress( 'existing_postmeta_mutated' );
	wp_update_term( $localized_term_id, 'category', array( 'name' => 'Recovery Mutated Category', 'description' => 'Mutated description.' ) );
	$progress( 'existing_term_mutated' );
	update_term_meta( $localized_term_id, '_recovery_term_meta', 'mutated' );
	$progress( 'existing_termmeta_mutated' );
	$snapshot['mutation_started'] = true;
	$snapshot['rollback_expected_surface_revision'] = $call( 'translation_job_rollback_cas_revision', array( (int) $translation_id, (array) $snapshot['term_scope'], $identity_scope ) );
	$progress( 'existing_mutation_receipt_complete' );
	$restored = $call( 'translation_job_restore_surface_snapshot', array( $snapshot, (int) $translation_id ) );
	$assert( ! empty( $restored['success'] ), 'Existing post/term recovery failed: ' . wp_json_encode( $restored ) );
	$assert( hash_equals( (string) $snapshot['captured_cas_revision'], (string) $call( 'translation_job_rollback_cas_revision', array( (int) $translation_id, (array) $snapshot['term_scope'], $identity_scope ) ) ), 'Existing post/term surface was not restored byte-for-byte.' );
	$progress( 'existing_restore_complete' );

	$assert( ! is_wp_error( $update_internal_post( array( 'ID' => (int) $translation_id, 'post_title' => 'Atomic failure mutated' ) ) ), 'Could not mutate the atomic-failure fixture.' );
	update_post_meta( $translation_id, '_recovery_fixture_meta', 'atomic-failure' );
	wp_update_term( $localized_term_id, 'category', array( 'name' => 'Atomic Failure Mutated Category' ) );
	update_term_meta( $localized_term_id, '_recovery_term_meta', 'atomic-failure' );
	$snapshot['mutation_started'] = true;
	$snapshot['rollback_expected_surface_revision'] = $call( 'translation_job_rollback_cas_revision', array( (int) $translation_id, (array) $snapshot['term_scope'], $identity_scope ) );
	$fail_term_meta_restore = static function ( $check, $object_id, $meta_key ) use ( $localized_term_id ) {
		return (int) $object_id === $localized_term_id && '_recovery_term_meta' === (string) $meta_key ? false : $check;
	};
	add_filter( 'add_term_metadata', $fail_term_meta_restore, 10, 3 );
	$atomic_failure = $call( 'translation_job_restore_surface_snapshot', array( $snapshot, (int) $translation_id ) );
	remove_filter( 'add_term_metadata', $fail_term_meta_restore, 10 );
	$assert( empty( $atomic_failure['success'] ) && ! empty( $atomic_failure['transaction_rolled_back'] ), 'Injected recovery failure did not roll back the complete restore transaction.' );
	$assert( 'Atomic failure mutated' === (string) get_the_title( $translation_id ) && 'atomic-failure' === (string) get_post_meta( $translation_id, '_recovery_fixture_meta', true ), 'Failed recovery partially changed the post surface.' );
	$atomic_term = get_term( $localized_term_id, 'category' );
	$assert( $atomic_term instanceof WP_Term && 'Atomic Failure Mutated Category' === (string) $atomic_term->name && 'atomic-failure' === (string) get_term_meta( $localized_term_id, '_recovery_term_meta', true ), 'Failed recovery partially changed the global term surface.' );
	$snapshot['rollback_expected_surface_revision'] = $call( 'translation_job_rollback_cas_revision', array( (int) $translation_id, (array) $snapshot['term_scope'], $identity_scope ) );
	$assert( ! empty( $call( 'translation_job_restore_surface_snapshot', array( $snapshot, (int) $translation_id ) )['success'] ), 'Could not restore the fixture after the atomic-failure test.' );
	$progress( 'atomic_failure_fixture_complete' );

	$previous_menu_id = wp_create_nav_menu( 'Recovery Previous ' . wp_generate_password( 6, false, false ) );
	$assert( ! is_wp_error( $previous_menu_id ), 'Could not create previous-menu fixture.' );
	$menu_ids[] = (int) $previous_menu_id;
	add_term_meta( (int) $previous_menu_id, Devenia_Workflow::TERM_META_MENU_MANAGED, '1', true );
	add_term_meta( (int) $previous_menu_id, Devenia_Workflow::TERM_META_MENU_LANGUAGE, $language, true );
	wp_update_nav_menu_item( (int) $previous_menu_id, 0, array( 'menu-item-title' => 'Previous', 'menu-item-url' => home_url( '/' ), 'menu-item-status' => 'publish', 'menu-item-type' => 'custom' ) );
	$target_menu_id = wp_create_nav_menu( 'Recovery Target ' . wp_generate_password( 6, false, false ) );
	$assert( ! is_wp_error( $target_menu_id ), 'Could not create target-menu fixture.' );
	$menu_ids[] = (int) $target_menu_id;
	add_term_meta( (int) $target_menu_id, Devenia_Workflow::TERM_META_MENU_MANAGED, '1', true );
	add_term_meta( (int) $target_menu_id, Devenia_Workflow::TERM_META_MENU_LANGUAGE, $language, true );
	wp_update_nav_menu_item( (int) $target_menu_id, 0, array( 'menu-item-title' => 'Target', 'menu-item-url' => home_url( '/' ), 'menu-item-status' => 'publish', 'menu-item-type' => 'custom' ) );
	$fixture_before_identities = is_array( $original_menu_option ) ? $original_menu_option : array();
	$fixture_before_identities[ $language ] = array( 'menu_id' => (int) $previous_menu_id, 'configured_name' => 'Recovery Previous' );
	$fixture_after_identities = $fixture_before_identities;
	$fixture_after_identities[ $language ] = array( 'menu_id' => (int) $target_menu_id, 'configured_name' => 'Recovery Target', 'previous_menu_id' => (int) $previous_menu_id );
	update_option( Devenia_Workflow::OPTION_LOCALIZED_MENU_IDENTITIES, $fixture_after_identities, false );
	$menu_plan = array(
		'language' => $language,
		'previous_menu_id' => (int) $previous_menu_id,
		'target_menu' => array( 'id' => (int) $target_menu_id ),
		'menu_identity_activation' => array( 'success' => true, 'before_exists' => true, 'before' => $fixture_before_identities, 'after' => $fixture_after_identities ),
		'menu_surface_revision' => $call( 'localized_menu_projection_revision', array( (int) $target_menu_id ) ),
		'previous_menu_surface_revision' => $call( 'localized_menu_projection_revision', array( (int) $previous_menu_id ) ),
	);
	$assert( '' !== (string) $menu_plan['menu_surface_revision'], 'Could not capture target-menu recovery receipt.' );
	$assert( ! is_wp_error( $update_internal_post( array( 'ID' => (int) $translation_id, 'post_title' => 'Combined failure mutated' ) ) ), 'Could not mutate the combined-failure fixture.' );
	update_post_meta( $translation_id, '_recovery_fixture_meta', 'combined-failure' );
	wp_update_term( $localized_term_id, 'category', array( 'name' => 'Combined Failure Mutated Category' ) );
	update_term_meta( $localized_term_id, '_recovery_term_meta', 'combined-failure' );
	$snapshot['mutation_started'] = true;
	$snapshot['rollback_expected_surface_revision'] = $call( 'translation_job_rollback_cas_revision', array( (int) $translation_id, (array) $snapshot['term_scope'], $identity_scope ) );
	add_filter( 'add_term_metadata', $fail_term_meta_restore, 10, 3 );
	$combined_failure = $call( 'translation_job_restore_publication_snapshot', array( $snapshot, (int) $translation_id, $language, $menu_plan ) );
	remove_filter( 'add_term_metadata', $fail_term_meta_restore, 10 );
	$assert( empty( $combined_failure['success'] ) && ! empty( $combined_failure['transaction_rolled_back'] ), 'Combined content-menu recovery failure was not rolled back atomically.' );
	$combined_identities = get_option( Devenia_Workflow::OPTION_LOCALIZED_MENU_IDENTITIES, array() );
	$assert( 'Combined failure mutated' === (string) get_the_title( $translation_id ) && is_array( $combined_identities ) && (int) $target_menu_id === absint( $combined_identities[ $language ]['menu_id'] ?? 0 ) && wp_get_nav_menu_object( (int) $target_menu_id ), 'Combined recovery partially changed content or menu state after failure.' );
	$snapshot['rollback_expected_surface_revision'] = $call( 'translation_job_rollback_cas_revision', array( (int) $translation_id, (array) $snapshot['term_scope'], $identity_scope ) );
	$previous_items = wp_get_nav_menu_items( (int) $previous_menu_id );
	$assert( ! empty( $previous_items[0]->ID ), 'Previous-menu item fixture is missing.' );
	wp_update_nav_menu_item( (int) $previous_menu_id, (int) $previous_items[0]->ID, array( 'menu-item-title' => 'Previous externally changed', 'menu-item-url' => home_url( '/' ), 'menu-item-status' => 'publish', 'menu-item-type' => 'custom' ) );
	$previous_drift = $call( 'translation_job_restore_publication_snapshot', array( $snapshot, (int) $translation_id, $language, $menu_plan ) );
	$assert( empty( $previous_drift['success'] ) && 'Combined failure mutated' === (string) get_the_title( $translation_id ) && wp_get_nav_menu_object( (int) $target_menu_id ), 'Changed previous-menu receipt was reactivated or caused a partial content rollback.' );
	$menu_plan['previous_menu_surface_revision'] = $call( 'localized_menu_projection_revision', array( (int) $previous_menu_id ) );
	$combined_success = $call( 'translation_job_restore_publication_snapshot', array( $snapshot, (int) $translation_id, $language, $menu_plan ) );
	$assert( ! empty( $combined_success['success'] ) && ! wp_get_nav_menu_object( (int) $target_menu_id ), 'Combined content-menu recovery did not commit atomically.' );
	$menu_ids = array_values( array_diff( $menu_ids, array( (int) $target_menu_id ) ) );
	$progress( 'composite_menu_restore_complete' );

	$new_source_term = wp_insert_term( 'Recovery New Source Category', 'category', array( 'slug' => 'recovery-new-source-' . wp_generate_password( 6, false, false ) ) );
	$assert( ! is_wp_error( $new_source_term ), 'Could not create new-term source fixture.' );
	$new_source_term_id = absint( $new_source_term['term_id'] ?? 0 );
	$term_ids[] = array( $new_source_term_id, 'category' );
	$new_manifest = array( 'language' => $language, 'taxonomies' => array( 'category' => array( array( 'source_term_id' => $new_source_term_id, 'taxonomy' => 'category', 'language' => $language ) ), 'post_tag' => array() ) );
	$new_snapshot = $call( 'translation_job_capture_surface_snapshot', array( (int) $translation_id, $new_manifest, $identity_scope ) );
	$new_term = wp_insert_term( 'Recovery New Localized', 'category', array( 'slug' => $language . '-recovery-new-' . wp_generate_password( 6, false, false ) ) );
	$assert( ! is_wp_error( $new_term ), 'Could not create new localized term fixture.' );
	$new_term_id = absint( $new_term['term_id'] ?? 0 );
	$term_ids[] = array( $new_term_id, 'category' );
	update_term_meta( $new_term_id, Devenia_Workflow::TERM_META_SOURCE_ID, $new_source_term_id );
	update_term_meta( $new_term_id, Devenia_Workflow::TERM_META_LANGUAGE, $language );
	update_term_meta( $new_term_id, Devenia_Workflow::TERM_META_PUBLICATION_ATTEMPT, (string) $new_snapshot['publication_attempt_id'] );
	wp_set_post_terms( (int) $translation_id, array( $new_term_id ), 'category', false );
	$new_snapshot['mutation_started'] = true;
	$new_snapshot['rollback_expected_surface_revision'] = $call( 'translation_job_rollback_cas_revision', array( (int) $translation_id, (array) $new_snapshot['term_scope'], $identity_scope ) );
	$new_restored = $call( 'translation_job_restore_surface_snapshot', array( $new_snapshot, (int) $translation_id ) );
	$assert( ! empty( $new_restored['success'] ) && ! term_exists( $new_term_id, 'category' ), 'New translated term was not removed by exact rollback.' );
	$progress( 'new_term_restore_complete' );

	$candidate_snapshot = $call( 'translation_job_capture_surface_snapshot', array( 0, $new_manifest, $absent_identity_scope ) );
	$candidate_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Recovery candidate' ), true );
	$assert( ! is_wp_error( $candidate_id ), 'Could not create new candidate fixture.' );
	$post_ids[] = (int) $candidate_id;
	update_post_meta( $candidate_id, Devenia_Workflow::META_SOURCE_ID, (int) $absent_source_id );
	update_post_meta( $candidate_id, Devenia_Workflow::META_LANGUAGE, $language );
	update_post_meta( $candidate_id, '_devenia_workflow_publication_attempt_id', (string) $candidate_snapshot['publication_attempt_id'] );
	$candidate_term = wp_insert_term( 'Recovery Candidate Localized', 'category', array( 'slug' => $language . '-recovery-candidate-' . wp_generate_password( 6, false, false ) ) );
	$assert( ! is_wp_error( $candidate_term ), 'Could not create candidate term fixture.' );
	$candidate_term_id = absint( $candidate_term['term_id'] ?? 0 );
	$term_ids[] = array( $candidate_term_id, 'category' );
	update_term_meta( $candidate_term_id, Devenia_Workflow::TERM_META_SOURCE_ID, $new_source_term_id );
	update_term_meta( $candidate_term_id, Devenia_Workflow::TERM_META_LANGUAGE, $language );
	update_term_meta( $candidate_term_id, Devenia_Workflow::TERM_META_PUBLICATION_ATTEMPT, (string) $candidate_snapshot['publication_attempt_id'] );
	wp_set_post_terms( (int) $candidate_id, array( $candidate_term_id ), 'category', false );
	$candidate_snapshot['mutation_started'] = true;
	$candidate_snapshot['rollback_expected_surface_revision'] = $call( 'translation_job_rollback_cas_revision', array( (int) $candidate_id, (array) $candidate_snapshot['term_scope'], $absent_identity_scope ) );
	$candidate_restored = $call( 'translation_job_restore_surface_snapshot', array( $candidate_snapshot, (int) $candidate_id ) );
	$assert( ! empty( $candidate_restored['success'] ) && ! get_post( $candidate_id ) && ! term_exists( $candidate_term_id, 'category' ), 'Owned new candidate and term were not removed together.' );
	$progress( 'new_candidate_restore_complete' );

	$conflict_snapshot = $call( 'translation_job_capture_surface_snapshot', array( (int) $translation_id, $manifest, $identity_scope ) );
	update_post_meta( $translation_id, '_recovery_owned_mutation', 'owned' );
	$receipt = $call( 'translation_job_rollback_cas_revision', array( (int) $translation_id, (array) $conflict_snapshot['term_scope'], $identity_scope ) );
	update_post_meta( $translation_id, '_recovery_concurrent_mutation', 'external' );
	$conflict_snapshot['mutation_started'] = true;
	$conflict_snapshot['rollback_expected_surface_revision'] = $receipt;
	$conflict = $call( 'translation_job_restore_surface_snapshot', array( $conflict_snapshot, (int) $translation_id ) );
	$assert( empty( $conflict['success'] ) && 'rollback_conflict' === (string) ( $conflict['action'] ?? '' ) && 'external' === (string) get_post_meta( $translation_id, '_recovery_concurrent_mutation', true ), 'Concurrent mutation was overwritten instead of producing a rollback conflict.' );
	$progress( 'concurrent_conflict_complete' );

	$success_output = wp_json_encode( array( 'success' => true, 'retry_identity_resolved_existing' => true, 'stale_nonzero_identity_rejected' => true, 'row_lock_blocked_second_connection' => true, 'absent_translation_identity_range_locked' => true, 'wrong_type_identity_update_blocked' => true, 'absent_term_identity_range_locked' => true, 'failed_restore_was_atomic' => true, 'combined_content_menu_restore_atomic' => true, 'previous_menu_drift_preserved' => true, 'existing_surface_restored' => true, 'new_term_removed' => true, 'new_candidate_removed' => true, 'concurrent_change_preserved' => true ) );
	fwrite( STDERR, $success_output . PHP_EOL );
} catch ( Throwable $error ) {
	fwrite( STDERR, wp_json_encode( array( 'success' => false, 'error' => $error->getMessage() ) ) . PHP_EOL );
	exit( 1 );
} finally {
	if ( $menu_option_missing === $original_menu_option ) { delete_option( Devenia_Workflow::OPTION_LOCALIZED_MENU_IDENTITIES ); } else { update_option( Devenia_Workflow::OPTION_LOCALIZED_MENU_IDENTITIES, $original_menu_option, false ); }
	foreach ( array_reverse( $menu_ids ) as $menu_id ) { if ( wp_get_nav_menu_object( $menu_id ) ) { wp_delete_nav_menu( $menu_id ); } }
	foreach ( array_reverse( $post_ids ) as $post_id ) { if ( get_post( $post_id ) ) { wp_delete_post( $post_id, true ); } }
	foreach ( array_reverse( $term_ids ) as $term ) { if ( term_exists( (int) $term[0], (string) $term[1] ) ) { wp_delete_term( (int) $term[0], (string) $term[1] ); } }
}
