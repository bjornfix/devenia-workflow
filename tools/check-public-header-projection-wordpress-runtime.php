<?php
/** Real WordPress regression for atomic all-language Public Header Projection. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$call = static function ( string $method, ...$arguments ) { $r = new ReflectionMethod( Devenia_Workflow::class, $method ); $r->setAccessible( true ); return $r->invokeArgs( null, $arguments ); };
$option_keys = array( 'devenia_workflow_language_registry', 'devenia_workflow_localized_menu_identities', 'devenia_workflow_public_header_manifest', 'devenia_workflow_pending_public_header_manifest', 'devenia_workflow_public_header_enrollment', 'show_on_front', 'page_on_front', 'page_for_posts' );
$before = array(); foreach ( $option_keys as $key ) { $before[ $key ] = get_option( $key, '__workflow_missing__' ); }
$locations_before = get_theme_mod( 'nav_menu_locations', array() );
$user_before = get_current_user_id();
$posts = array(); $menus = array(); $filters = array(); $error = null; $result = null;
$failure_mode = '';
$failure_injected = false;
$verification_fault_remaining = 0;
$verification_fault_revision = '';
$verification_rollback_observations = array();
$staged_race_menu_id = 0;
$enrollment_race_mode = '';
$enrollment_race_source_menu_id = 0;
$enrollment_race_authority_menu_id = 0;
$enrollment_commit_mode = '';
$enrollment_foreign_state = array();
$enrollment_post_activation_foreign_mode = '';
$enrollment_post_activation_foreign_state = array();
$activation_commit_mode = '';
$activation_commit_injected = false;
$activation_commit_foreign_state = array();
$cleanup_race_mode = '';
$cleanup_race_injected = false;
$cleanup_race_menu_id = 0;
$content_commit_mode = '';
$content_commit_foreign_revision = '';
$migration_authority_race_mode = '';
$migration_authority_race_menu_id = 0;
$authority_relation_race_mode = '';
$authority_relation_race_translation_id = 0;

try {
	$admins = get_users( array( 'role__in' => array( 'administrator' ), 'number' => 1, 'fields' => 'ids' ) );
	if ( empty( $admins ) ) { throw new RuntimeException( 'Administrator fixture missing.' ); }
	wp_set_current_user( (int) $admins[0] );
	$frontend = static function (): bool { return true; };
	add_filter( 'devenia_workflow_is_frontend_runtime_request', $frontend, 10, 0 ); $filters[] = array( 'devenia_workflow_is_frontend_runtime_request', $frontend, 10 );

	$languages = Devenia_Workflow::languages( true );
	$source_language = (string) $call( 'source_language_code' );
	$targets = array_values( array_diff( array_keys( $languages ), array( $source_language ) ) );
	if ( count( $targets ) < 2 ) { throw new RuntimeException( 'At least two target-language fixtures are required.' ); }
	$token = strtolower( wp_generate_password( 8, false, false ) );
	$create_page = static function ( string $title, string $slug, int $parent_id = 0 ) use ( &$posts ): int { $id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug, 'post_parent' => $parent_id, 'post_content' => '<!-- wp:paragraph --><p>Fixture</p><!-- /wp:paragraph -->' ), true ); if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); } $posts[] = (int) $id; return (int) $id; };
	$source_home = $create_page( 'Source home ' . $token, 'source-home-' . $token );
	$source_blog = $create_page( 'Source blog ' . $token, 'source-blog-' . $token );
	update_option( 'show_on_front', 'page' ); update_option( 'page_on_front', $source_home ); update_option( 'page_for_posts', $source_blog );
	$translated = array();
	foreach ( $targets as $language ) {
		$language_prefix = sanitize_key( (string) ( $languages[ $language ]['prefix'] ?? $language ) );
		$existing_root = get_page_by_path( $language_prefix, OBJECT, 'page' );
		$root = $existing_root instanceof WP_Post ? (int) $existing_root->ID : $create_page( 'Target root ' . $language . ' ' . $token, $language_prefix );
		$home = $create_page( 'Target home ' . $language . ' ' . $token, 'target-home-' . $language . '-' . $token, $root );
		$blog = $create_page( 'Target blog ' . $language . ' ' . $token, 'target-blog-' . $language . '-' . $token, $root );
		foreach ( array( $home => $source_home, $blog => $source_blog ) as $target_id => $source_id ) { update_post_meta( $target_id, '_devenia_translation_source_id', $source_id ); update_post_meta( $target_id, '_devenia_translation_language', $language ); update_post_meta( $target_id, '_devenia_translation_status', 'published' ); $call( 'sync_translation_index_row', $target_id ); }
		$translated[ $language ] = array( 'root' => $root, 'home' => $home, 'blog' => $blog );
	}

	// The same page may intentionally appear more than once in one menu at
	// different hierarchy positions. Exact source-item identity must win over
	// page-object fallback so those editorial labels remain independently owned.
	$duplicate_source_menu = wp_create_nav_menu( 'Duplicate source identity ' . $token ); if ( is_wp_error( $duplicate_source_menu ) ) { throw new RuntimeException( $duplicate_source_menu->get_error_message() ); } $menus[] = (int) $duplicate_source_menu;
	$duplicate_source_top = wp_update_nav_menu_item( (int) $duplicate_source_menu, 0, array( 'menu-item-title' => 'Duplicate source top', 'menu-item-object' => 'page', 'menu-item-object-id' => $source_home, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 1 ) );
	$duplicate_source_child = wp_update_nav_menu_item( (int) $duplicate_source_menu, 0, array( 'menu-item-title' => 'Duplicate source child', 'menu-item-object' => 'page', 'menu-item-object-id' => $source_home, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-parent-id' => (int) $duplicate_source_top, 'menu-item-position' => 2 ) );
	if ( is_wp_error( $duplicate_source_top ) || is_wp_error( $duplicate_source_child ) ) { throw new RuntimeException( 'Could not build duplicate source-item identity fixture.' ); }
	$duplicate_manifest = array(
		array( 'source_item_id' => (int) $duplicate_source_top, 'type' => 'page', 'object_id' => $source_home, 'parent_source_item_id' => 0, 'position' => 1 ),
		array( 'source_item_id' => (int) $duplicate_source_child, 'type' => 'page', 'object_id' => $source_home, 'parent_source_item_id' => (int) $duplicate_source_top, 'position' => 2 ),
	);
	$duplicate_source_snapshot = $call( 'public_header_editorial_label_snapshot', $source_language, (int) $duplicate_source_menu, $duplicate_manifest );
	if ( empty( $duplicate_source_snapshot['success'] ) || 'Duplicate source top' !== (string) ( $duplicate_source_snapshot['labels'][ (int) $duplicate_source_top ] ?? '' ) || 'Duplicate source child' !== (string) ( $duplicate_source_snapshot['labels'][ (int) $duplicate_source_child ] ?? '' ) ) { throw new RuntimeException( 'Exact source menu-item identity did not disambiguate duplicate page references: ' . wp_json_encode( $duplicate_source_snapshot ) ); }

	$identity_target_language = (string) $targets[0];
	$duplicate_target_menu = wp_create_nav_menu( 'Duplicate target identity ' . $token ); if ( is_wp_error( $duplicate_target_menu ) ) { throw new RuntimeException( $duplicate_target_menu->get_error_message() ); } $menus[] = (int) $duplicate_target_menu;
	$duplicate_target_top = wp_update_nav_menu_item( (int) $duplicate_target_menu, 0, array( 'menu-item-title' => 'Duplicate target top', 'menu-item-object' => 'page', 'menu-item-object-id' => (int) $translated[ $identity_target_language ]['home'], 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 1 ) );
	$duplicate_target_child = wp_update_nav_menu_item( (int) $duplicate_target_menu, 0, array( 'menu-item-title' => 'Duplicate target child', 'menu-item-object' => 'page', 'menu-item-object-id' => (int) $translated[ $identity_target_language ]['home'], 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-parent-id' => (int) $duplicate_target_top, 'menu-item-position' => 2 ) );
	if ( is_wp_error( $duplicate_target_top ) || is_wp_error( $duplicate_target_child ) ) { throw new RuntimeException( 'Could not build duplicate target-item identity fixture.' ); }
	update_post_meta( (int) $duplicate_target_top, '_devenia_translation_source_menu_item_id', (int) $duplicate_source_top );
	update_post_meta( (int) $duplicate_target_child, '_devenia_translation_source_menu_item_id', (int) $duplicate_source_child );
	$duplicate_target_snapshot = $call( 'public_header_editorial_label_snapshot', $identity_target_language, (int) $duplicate_target_menu, $duplicate_manifest );
	if ( empty( $duplicate_target_snapshot['success'] ) || 'Duplicate target top' !== (string) ( $duplicate_target_snapshot['labels'][ (int) $duplicate_source_top ] ?? '' ) || 'Duplicate target child' !== (string) ( $duplicate_target_snapshot['labels'][ (int) $duplicate_source_child ] ?? '' ) ) { throw new RuntimeException( 'Stable target source-item identity did not disambiguate duplicate page references: ' . wp_json_encode( $duplicate_target_snapshot ) ); }
	$wrong_identity_language = (string) ( $targets[1] ?? '' );
	if ( '' !== $wrong_identity_language ) {
		$wrong_language_identity_snapshot = $call( 'public_header_editorial_label_snapshot', $wrong_identity_language, (int) $duplicate_target_menu, $duplicate_manifest );
		if ( ! empty( $wrong_language_identity_snapshot['success'] ) || false === strpos( wp_json_encode( $wrong_language_identity_snapshot['missing'] ?? array() ), 'stable_identity_relation_mismatch' ) ) { throw new RuntimeException( 'A stable source-item identity was accepted against the wrong target-language relation: ' . wp_json_encode( $wrong_language_identity_snapshot ) ); }
	}
	$duplicate_target_extra = wp_update_nav_menu_item( (int) $duplicate_target_menu, 0, array( 'menu-item-title' => 'Duplicate target competing identity', 'menu-item-object' => 'page', 'menu-item-object-id' => (int) $translated[ $identity_target_language ]['home'], 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 3 ) );
	if ( is_wp_error( $duplicate_target_extra ) ) { throw new RuntimeException( $duplicate_target_extra->get_error_message() ); }
	update_post_meta( (int) $duplicate_target_extra, '_devenia_translation_source_menu_item_id', (int) $duplicate_source_top );
	$ambiguous_stable_snapshot = $call( 'public_header_editorial_label_snapshot', $identity_target_language, (int) $duplicate_target_menu, $duplicate_manifest );
	if ( ! empty( $ambiguous_stable_snapshot['success'] ) || false === strpos( wp_json_encode( $ambiguous_stable_snapshot['missing'] ?? array() ), 'stable_item_ambiguous' ) ) { throw new RuntimeException( 'Two exact stable source-item candidates did not fail as ambiguous authority: ' . wp_json_encode( $ambiguous_stable_snapshot ) ); }
	wp_delete_post( (int) $duplicate_target_extra, true );
	add_post_meta( (int) $duplicate_target_top, '_devenia_translation_source_menu_item_id', (int) $duplicate_source_top, false );
	$duplicate_meta_snapshot = $call( 'public_header_editorial_label_snapshot', $identity_target_language, (int) $duplicate_target_menu, $duplicate_manifest );
	if ( ! empty( $duplicate_meta_snapshot['success'] ) || false === strpos( wp_json_encode( $duplicate_meta_snapshot['missing'] ?? array() ), 'stable_identity_row_count_invalid' ) ) { throw new RuntimeException( 'Duplicate stable-identity metadata rows did not fail closed: ' . wp_json_encode( $duplicate_meta_snapshot ) ); }

	$foreign_identity_menu = wp_create_nav_menu( 'Foreign target identity ' . $token ); if ( is_wp_error( $foreign_identity_menu ) ) { throw new RuntimeException( $foreign_identity_menu->get_error_message() ); } $menus[] = (int) $foreign_identity_menu;
	$foreign_identity_top = wp_update_nav_menu_item( (int) $foreign_identity_menu, 0, array( 'menu-item-title' => 'Foreign identity top', 'menu-item-object' => 'page', 'menu-item-object-id' => (int) $translated[ $identity_target_language ]['home'], 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 1 ) );
	$foreign_identity_child = wp_update_nav_menu_item( (int) $foreign_identity_menu, 0, array( 'menu-item-title' => 'Foreign identity child', 'menu-item-object' => 'page', 'menu-item-object-id' => (int) $translated[ $identity_target_language ]['home'], 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-parent-id' => (int) $foreign_identity_top, 'menu-item-position' => 2 ) );
	if ( is_wp_error( $foreign_identity_top ) || is_wp_error( $foreign_identity_child ) ) { throw new RuntimeException( 'Could not build foreign stable-identity fixture.' ); }
	update_post_meta( (int) $foreign_identity_top, '_devenia_translation_source_menu_item_id', (int) $duplicate_source_top + 1000000 );
	update_post_meta( (int) $foreign_identity_child, '_devenia_translation_source_menu_item_id', (int) $duplicate_source_child + 1000000 );
	$foreign_identity_snapshot = $call( 'public_header_editorial_label_snapshot', $identity_target_language, (int) $foreign_identity_menu, $duplicate_manifest );
	if ( ! empty( $foreign_identity_snapshot['success'] ) || false === strpos( wp_json_encode( $foreign_identity_snapshot['missing'] ?? array() ), 'foreign_stable_identity' ) ) { throw new RuntimeException( 'Foreign persisted stable identities incorrectly fell back to the derived page relation: ' . wp_json_encode( $foreign_identity_snapshot ) ); }
	$mixed_foreign_menu = wp_create_nav_menu( 'Mixed foreign target identity ' . $token ); if ( is_wp_error( $mixed_foreign_menu ) ) { throw new RuntimeException( $mixed_foreign_menu->get_error_message() ); } $menus[] = (int) $mixed_foreign_menu;
	$mixed_foreign_legacy = wp_update_nav_menu_item( (int) $mixed_foreign_menu, 0, array( 'menu-item-title' => 'Legitimate absent-meta fallback', 'menu-item-object' => 'page', 'menu-item-object-id' => (int) $translated[ $identity_target_language ]['home'], 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 1 ) );
	$mixed_foreign_extra = wp_update_nav_menu_item( (int) $mixed_foreign_menu, 0, array( 'menu-item-title' => 'Foreign persisted extra', 'menu-item-object' => 'page', 'menu-item-object-id' => (int) $translated[ $identity_target_language ]['home'], 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 2 ) );
	if ( is_wp_error( $mixed_foreign_legacy ) || is_wp_error( $mixed_foreign_extra ) ) { throw new RuntimeException( 'Could not build mixed foreign stable-identity fixture.' ); }
	update_post_meta( (int) $mixed_foreign_extra, '_devenia_translation_source_menu_item_id', (int) $duplicate_source_top + 2000000 );
	$mixed_foreign_manifest = array( array( 'source_item_id' => (int) $duplicate_source_top, 'type' => 'page', 'object_id' => $source_home, 'parent_source_item_id' => 0, 'position' => 1 ) );
	$mixed_foreign_snapshot = $call( 'public_header_editorial_label_snapshot', $identity_target_language, (int) $mixed_foreign_menu, $mixed_foreign_manifest );
	if ( ! empty( $mixed_foreign_snapshot['success'] ) || false === strpos( wp_json_encode( $mixed_foreign_snapshot['missing'] ?? array() ), 'foreign_stable_identity' ) ) { throw new RuntimeException( 'A legitimate absent-meta fallback incorrectly hid an extra foreign stable identity: ' . wp_json_encode( $mixed_foreign_snapshot ) ); }

	$raw_menu = wp_create_nav_menu( 'Raw drift ' . $token ); if ( is_wp_error( $raw_menu ) ) { throw new RuntimeException( $raw_menu->get_error_message() ); } $menus[] = (int) $raw_menu;
	wp_update_nav_menu_item( (int) $raw_menu, 0, array( 'menu-item-title' => 'Raw drift', 'menu-item-url' => home_url( '/raw-' . $token . '/' ), 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) );
	$locations = is_array( $locations_before ) ? $locations_before : array(); $locations['primary'] = (int) $raw_menu; set_theme_mod( 'nav_menu_locations', $locations );
	$registry = get_option( 'devenia_workflow_language_registry', array() ); $registry = is_array( $registry ) ? $registry : array();
	foreach ( array_merge( array( $source_language ), $targets ) as $language ) { $registry[ $language ]['menu_name'] = 'Managed ' . $language . ' ' . $token; }
	update_option( 'devenia_workflow_language_registry', $registry, false ); Devenia_Workflow::languages( true );

	delete_option( 'devenia_workflow_public_header_manifest' ); delete_option( 'devenia_workflow_pending_public_header_manifest' ); delete_option( 'devenia_workflow_localized_menu_identities' ); delete_option( 'devenia_workflow_public_header_enrollment' );
	$_SERVER['REQUEST_URI'] = '/';

	$urls = array( untrailingslashit( home_url( '/' ) ) => $source_language, untrailingslashit( (string) get_permalink( $source_blog ) ) => $source_language );
	foreach ( $translated as $language => $ids ) { $urls[ untrailingslashit( (string) get_permalink( $ids['home'] ) ) ] = $language; $urls[ untrailingslashit( (string) get_permalink( $ids['blog'] ) ) ] = $language; }
	$http = static function ( $preempt, array $args, string $url ) use ( &$failure_mode, &$verification_fault_remaining, &$verification_fault_revision, &$verification_rollback_observations, $urls, $call, $source_language ) {
		$canonical = untrailingslashit( (string) strtok( $url, '?' ) ); $language = $urls[ $canonical ] ?? ''; if ( '' === $language ) { return $preempt; }
		$active = get_option( 'devenia_workflow_public_header_manifest', array() ); $revision = (string) ( $active['revision'] ?? '' );
		$languages = Devenia_Workflow::languages( true );
		$prefix = sanitize_key( (string) ( $languages[ $language ]['prefix'] ?? '' ) );
		$request_before = $_SERVER['REQUEST_URI'] ?? '/';
		$_SERVER['REQUEST_URI'] = $source_language === $language || '' === $prefix ? '/' : '/' . $prefix . '/fixture/';
		if ( 'verification_fail' === $failure_mode && $verification_fault_remaining > 0 && '' !== $verification_fault_revision && hash_equals( $verification_fault_revision, $revision ) ) { --$verification_fault_remaining; $navigation = '<a href="' . esc_url( home_url( '/wrong/' ) ) . '">Wrong</a>'; }
		else {
			$identities = get_option( 'devenia_workflow_localized_menu_identities', array() );
			$menu_id = absint( is_array( $identities ) ? ( $identities[ $language ]['menu_id'] ?? 0 ) : 0 );
			if ( 'verification_fail' === $failure_mode && 0 === $verification_fault_remaining && '' !== $verification_fault_revision && ! hash_equals( $verification_fault_revision, $revision ) ) {
				$verification_rollback_observations[] = array( 'language' => $language, 'schema_version' => (int) ( $active['schema_version'] ?? 0 ), 'manifest_revision' => $revision, 'identity_revision' => (string) ( $identities[ $language ]['manifest_revision'] ?? '' ) );
			}
		$menu_args = $source_language === $language || $menu_id < 1
				? array( 'theme_location' => 'primary', 'container' => false, 'echo' => false, 'fallback_cb' => false, 'items_wrap' => '%3$s' )
				: array( 'menu' => $menu_id, 'container' => false, 'echo' => false, 'fallback_cb' => false, 'items_wrap' => '%3$s' );
			$navigation = (string) wp_nav_menu( $menu_args );
			if ( 'verification_extra_anchor' === $failure_mode && $verification_fault_remaining > 0 ) { --$verification_fault_remaining; $navigation .= '<a href="' . esc_url( home_url( '/unexpected/' ) ) . '">Unexpected</a>'; }
		}
		$_SERVER['REQUEST_URI'] = $request_before;
		$lang = (string) $call( 'html_lang_for_language', $language ); $href = (string) $call( 'hreflang_for_language', $language );
		$body = '<!doctype html><html lang="' . esc_attr( $lang ) . '"><head><link rel="alternate" hreflang="' . esc_attr( $href ) . '" href="' . esc_url( $canonical ) . '"></head><body><nav id="site-navigation">' . $navigation . '</nav><main><p>Fixture</p></main></body></html>';
		return array( 'headers' => array( 'cf-cache-status' => false === strpos( $url, 'devenia_frontend_integrity=' ) ? 'HIT' : 'DYNAMIC', 'age' => '0' ), 'body' => $body, 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}; add_filter( 'pre_http_request', $http, 10, 3 ); $filters[] = array( 'pre_http_request', $http, 10 );
	$cache = static function ( $default, array $purge_urls, array $context ) use ( &$failure_mode ): array { $event = (string) ( $context['event'] ?? '' ); if ( 'public_header_projection' === $event && in_array( $failure_mode, array( 'invalidation_fail', 'rollback_cache_fail' ), true ) ) { return array( 'success' => false, 'code' => 'injected_invalidation_failure' ); } if ( 'public_header_projection_rollback' === $event && 'rollback_cache_fail' === $failure_mode ) { return array( 'success' => false, 'code' => 'injected_rollback_invalidation_failure' ); } return array( 'success' => true, 'purged_urls' => $purge_urls ); };
	add_filter( 'devenia_workflow_frontend_cache_invalidation_result', $cache, 10, 3 ); $filters[] = array( 'devenia_workflow_frontend_cache_invalidation_result', $cache, 10 );
	$receipt = static function ( $value, $target_menu ) use ( &$failure_mode, &$failure_injected, &$staged_race_menu_id ) {
		if ( 'receipt_fail' === $failure_mode ) { return ''; }
		if ( 'staged_revision_change' === $failure_mode && ! $failure_injected ) {
			$staged_race_menu_id = absint( $target_menu->term_id ?? 0 );
		}
		return $value;
	};
	add_filter( 'devenia_workflow_public_header_projection_receipt', $receipt, 10, 2 ); $filters[] = array( 'devenia_workflow_public_header_projection_receipt', $receipt, 10 );
	$pending_boundary = static function () use ( &$failure_mode, &$failure_injected, &$staged_race_menu_id ): void {
		if ( $failure_injected ) { return; }
		if ( 'staged_revision_change' === $failure_mode ) {
			$items = $staged_race_menu_id > 0 ? ( wp_get_nav_menu_items( $staged_race_menu_id ) ?: array() ) : array();
			if ( empty( $items ) || is_wp_error( wp_update_post( array( 'ID' => (int) $items[0]->ID, 'post_title' => 'Changed after receipt' ), true ) ) ) { throw new RuntimeException( 'Staged term race injection failed at the transaction boundary.' ); }
			$failure_injected = true;
			return;
		}
		if ( 'pending_race' === $failure_mode ) {
			$race = array( 'race' => wp_generate_uuid4() );
			update_option( 'devenia_workflow_pending_public_header_manifest', $race, false );
			if ( $race !== get_option( 'devenia_workflow_pending_public_header_manifest', array() ) ) { throw new RuntimeException( 'Pending manifest race injection was not stored at the transaction boundary.' ); }
			$failure_injected = true;
		}
	};
	add_action( 'devenia_workflow_public_header_before_locked_state_transition', $pending_boundary, 10, 0 ); $filters[] = array( 'devenia_workflow_public_header_before_locked_state_transition', $pending_boundary, 10 );
	$enrollment_boundary = static function () use ( &$enrollment_race_mode, &$enrollment_race_source_menu_id, &$enrollment_race_authority_menu_id, $raw_menu ): void {
		if ( 'primary' === $enrollment_race_mode ) { $locations = get_theme_mod( 'nav_menu_locations', array() ); $locations = is_array( $locations ) ? $locations : array(); $locations['primary'] = (int) $raw_menu; set_theme_mod( 'nav_menu_locations', $locations ); }
		if ( 'source' === $enrollment_race_mode || 'authority' === $enrollment_race_mode ) { $menu_id = 'source' === $enrollment_race_mode ? $enrollment_race_source_menu_id : $enrollment_race_authority_menu_id; $items = wp_get_nav_menu_items( $menu_id, array( 'orderby' => 'menu_order' ) ) ?: array(); if ( ! empty( $items ) ) { wp_update_post( array( 'ID' => (int) $items[0]->ID, 'post_title' => 'Locked race mutation' ) ); } }
	};
	add_action( 'devenia_workflow_public_header_enrollment_before_locked_stage_revalidation', $enrollment_boundary, 10, 0 ); $filters[] = array( 'devenia_workflow_public_header_enrollment_before_locked_stage_revalidation', $enrollment_boundary, 10 );
	$enrollment_commit_adapter = static function ( $default ) use ( &$enrollment_commit_mode, &$enrollment_foreign_state, $call ) {
		if ( '' === $enrollment_commit_mode ) { return $default; }
		if ( 'rollback_confirmed' === $enrollment_commit_mode ) {
			$rollback = $call( 'translation_job_rollback_recovery_transaction' );
			return array( 'success' => false, 'committed' => false, 'code' => 'fixture_commit_rolled_back', 'rollback' => $rollback );
		}
		$actual = $call( 'translation_job_commit_recovery_transaction' );
		if ( empty( $actual['success'] ) || true !== ( $actual['committed'] ?? null ) ) { throw new RuntimeException( 'Enrollment commit-outcome fixture could not establish a real committed transaction.' ); }
		if ( in_array( $enrollment_commit_mode, array( 'applied_foreign', 'unknown_foreign', 'success_foreign' ), true ) ) {
			$enrollment_foreign_state = array( 'manifest' => array( 'foreign' => wp_generate_uuid4() ), 'identities' => array( 'foreign' => wp_generate_uuid4() ), 'pending' => array( 'foreign' => wp_generate_uuid4() ), 'enrollment' => 'foreign-' . wp_generate_uuid4() );
			update_option( 'devenia_workflow_public_header_manifest', $enrollment_foreign_state['manifest'], false ); update_option( 'devenia_workflow_localized_menu_identities', $enrollment_foreign_state['identities'], false ); update_option( 'devenia_workflow_pending_public_header_manifest', $enrollment_foreign_state['pending'], false ); update_option( 'devenia_workflow_public_header_enrollment', $enrollment_foreign_state['enrollment'], false );
		}
		if ( 'success_foreign' === $enrollment_commit_mode ) { return $actual; }
		if ( 'invalid_applied' === $enrollment_commit_mode ) { return array( 'success' => false, 'code' => 'fixture_enrollment_commit_receipt_missing_field', 'actual' => $actual ); }
		return in_array( $enrollment_commit_mode, array( 'applied_then_error', 'applied_foreign' ), true )
			? array( 'success' => false, 'committed' => true, 'code' => 'fixture_adapter_error_after_commit', 'actual' => $actual )
			: array( 'success' => false, 'committed' => null, 'code' => 'fixture_commit_outcome_unknown', 'actual' => $actual );
	};
	add_filter( 'devenia_workflow_public_header_enrollment_commit_adapter_result', $enrollment_commit_adapter, 10, 1 ); $filters[] = array( 'devenia_workflow_public_header_enrollment_commit_adapter_result', $enrollment_commit_adapter, 10 );
	$enrollment_post_activation_foreign = static function () use ( &$enrollment_post_activation_foreign_mode, &$enrollment_post_activation_foreign_state ): void {
		if ( '' === $enrollment_post_activation_foreign_mode ) { return; }
		$enrollment_post_activation_foreign_state = array( 'manifest' => array( 'foreign_post_activation' => wp_generate_uuid4() ), 'identities' => array( 'foreign_post_activation' => wp_generate_uuid4() ), 'pending' => array( 'foreign_post_activation' => wp_generate_uuid4() ), 'enrollment' => 'foreign-post-activation-' . wp_generate_uuid4() );
		update_option( 'devenia_workflow_public_header_manifest', $enrollment_post_activation_foreign_state['manifest'], false ); update_option( 'devenia_workflow_localized_menu_identities', $enrollment_post_activation_foreign_state['identities'], false ); update_option( 'devenia_workflow_pending_public_header_manifest', $enrollment_post_activation_foreign_state['pending'], false ); update_option( 'devenia_workflow_public_header_enrollment', $enrollment_post_activation_foreign_state['enrollment'], false );
	};
	$migration_authority_boundary = static function () use ( &$migration_authority_race_mode, &$migration_authority_race_menu_id ): void {
		if ( '' === $migration_authority_race_mode || $migration_authority_race_menu_id < 1 ) { return; }
		$migration_authority_race_mode = '';
		$items = wp_get_nav_menu_items( $migration_authority_race_menu_id, array( 'orderby' => 'menu_order' ) ) ?: array();
		if ( ! empty( $items ) ) { wp_update_post( array( 'ID' => (int) $items[0]->ID, 'post_title' => 'Migration authority race mutation' ) ); }
	};
	add_action( 'devenia_workflow_public_header_migration_before_final_authority_revalidation', $migration_authority_boundary, 10, 0 ); $filters[] = array( 'devenia_workflow_public_header_migration_before_final_authority_revalidation', $migration_authority_boundary, 10 );
	$authority_relation_boundary = static function () use ( &$authority_relation_race_mode, &$authority_relation_race_translation_id, $call ): void {
		if ( '' === $authority_relation_race_mode || $authority_relation_race_translation_id < 1 ) { return; }
		$authority_relation_race_mode = '';
		wp_update_post( array( 'ID' => $authority_relation_race_translation_id, 'post_status' => 'draft' ) );
		$call( 'sync_translation_index_row', $authority_relation_race_translation_id );
	};
	add_action( 'devenia_workflow_public_header_authority_before_final_revalidation', $authority_relation_boundary, 10, 0 ); $filters[] = array( 'devenia_workflow_public_header_authority_before_final_revalidation', $authority_relation_boundary, 10 );
	add_action( 'devenia_workflow_public_header_enrollment_before_intake_restore', $enrollment_post_activation_foreign, 10, 0 ); $filters[] = array( 'devenia_workflow_public_header_enrollment_before_intake_restore', $enrollment_post_activation_foreign, 10 );
	$activation_commit_adapter = static function ( $default ) use ( &$activation_commit_mode, &$activation_commit_injected, &$activation_commit_foreign_state, $call ) {
		if ( '' === $activation_commit_mode || $activation_commit_injected ) { return $default; }
		$activation_commit_injected = true;
		if ( 'non_array' === $activation_commit_mode ) { return false; }
		if ( 'unterminated_success' === $activation_commit_mode ) { return array( 'success' => true, 'committed' => true, 'code' => 'fixture_unterminated_success_receipt' ); }
		if ( 'rollback_confirmed' === $activation_commit_mode ) { $rollback = $call( 'translation_job_rollback_recovery_transaction' ); return array( 'success' => false, 'committed' => false, 'code' => 'fixture_activation_commit_rolled_back', 'rollback' => $rollback ); }
		$actual = $call( 'translation_job_commit_recovery_transaction' );
		if ( empty( $actual['success'] ) || true !== ( $actual['committed'] ?? null ) ) { throw new RuntimeException( 'Activation commit fixture could not establish a real COMMIT.' ); }
		if ( in_array( $activation_commit_mode, array( 'unknown_foreign', 'success_foreign' ), true ) ) { $activation_commit_foreign_state = array( 'manifest' => array( 'foreign_activation' => wp_generate_uuid4() ), 'identities' => array( 'foreign_activation' => wp_generate_uuid4() ), 'pending' => array( 'foreign_activation' => wp_generate_uuid4() ), 'enrollment' => 'foreign-activation-' . wp_generate_uuid4() ); update_option( 'devenia_workflow_public_header_manifest', $activation_commit_foreign_state['manifest'], false ); update_option( 'devenia_workflow_localized_menu_identities', $activation_commit_foreign_state['identities'], false ); update_option( 'devenia_workflow_pending_public_header_manifest', $activation_commit_foreign_state['pending'], false ); update_option( 'devenia_workflow_public_header_enrollment', $activation_commit_foreign_state['enrollment'], false ); }
		if ( 'success_foreign' === $activation_commit_mode ) { return $actual; }
		if ( 'invalid_applied' === $activation_commit_mode ) { return array( 'success' => false, 'code' => 'fixture_activation_commit_receipt_missing_field', 'actual' => $actual ); }
		return 'applied_error' === $activation_commit_mode ? array( 'success' => false, 'committed' => true, 'code' => 'fixture_activation_adapter_error_after_commit', 'actual' => $actual ) : array( 'success' => false, 'committed' => null, 'code' => 'fixture_activation_commit_unknown', 'actual' => $actual );
	};
	add_filter( 'devenia_workflow_public_header_state_commit_adapter_result', $activation_commit_adapter, 10, 1 ); $filters[] = array( 'devenia_workflow_public_header_state_commit_adapter_result', $activation_commit_adapter, 10 );
	$cleanup_race = static function ( array $staged ) use ( &$cleanup_race_mode, &$cleanup_race_injected, &$cleanup_race_menu_id ): void {
		if ( 'identity_reference' !== $cleanup_race_mode || $cleanup_race_injected ) { return; }
		$language = (string) array_key_first( $staged );
		$projection = is_array( $staged[ $language ] ?? null ) ? $staged[ $language ] : array();
		$cleanup_race_menu_id = absint( $projection['target_menu']['id'] ?? 0 );
		if ( '' === $language || $cleanup_race_menu_id < 1 ) { throw new RuntimeException( 'Cleanup identity-reference fixture could not select a staged menu.' ); }
		$identities = get_option( 'devenia_workflow_localized_menu_identities', array() );
		$identities = is_array( $identities ) ? $identities : array();
		$identities[ $language ] = array( 'menu_id' => $cleanup_race_menu_id, 'manifest_revision' => 'fixture-cleanup-race-' . wp_generate_uuid4() );
		update_option( 'devenia_workflow_localized_menu_identities', $identities, false );
		$cleanup_race_injected = true;
	};
	add_action( 'devenia_workflow_public_header_staged_cleanup_before_locked_revalidation', $cleanup_race, 10, 1 ); $filters[] = array( 'devenia_workflow_public_header_staged_cleanup_before_locked_revalidation', $cleanup_race, 10 );
	$content_commit_adapter = static function ( $default, int $translation_id ) use ( &$content_commit_mode, &$content_commit_foreign_revision, $call ) {
		if ( '' === $content_commit_mode ) { return $default; }
		if ( 'rollback_confirmed' === $content_commit_mode ) { $rollback = $call( 'translation_job_rollback_recovery_transaction' ); return array( 'success' => false, 'committed' => false, 'code' => 'fixture_content_commit_rolled_back', 'rollback' => $rollback ); }
		$actual = $call( 'translation_job_commit_recovery_transaction' );
		if ( empty( $actual['success'] ) || true !== ( $actual['committed'] ?? null ) ) { throw new RuntimeException( 'Content commit fixture could not establish a real COMMIT.' ); }
		if ( in_array( $content_commit_mode, array( 'applied_foreign', 'unknown_foreign' ), true ) ) { wp_update_post( array( 'ID' => $translation_id, 'post_excerpt' => 'Foreign committed surface ' . wp_generate_uuid4() ) ); clean_post_cache( $translation_id ); $content_commit_foreign_revision = (string) $call( 'translation_job_rollback_cas_revision', $translation_id, array(), array() ); }
		if ( 'invalid_applied' === $content_commit_mode ) { return array( 'success' => false, 'code' => 'fixture_content_commit_receipt_missing_field', 'actual' => $actual ); }
		return in_array( $content_commit_mode, array( 'applied_error', 'applied_foreign' ), true ) ? array( 'success' => false, 'committed' => true, 'code' => 'fixture_content_adapter_error_after_commit', 'actual' => $actual ) : array( 'success' => false, 'committed' => null, 'code' => 'fixture_content_commit_unknown', 'actual' => $actual );
	};
	add_filter( 'devenia_workflow_localized_presentation_commit_adapter_result', $content_commit_adapter, 10, 2 ); $filters[] = array( 'devenia_workflow_localized_presentation_commit_adapter_result', $content_commit_adapter, 10 );
	$retirement = static function ( $allowed, array $staged ) use ( &$failure_mode, &$failure_injected ) {
		if ( 'old_receipt_changed' === $failure_mode && ! $failure_injected ) {
			$projection = reset( $staged ); $old_id = absint( is_array( $projection ) ? ( $projection['previous_menu_id'] ?? 0 ) : 0 );
			$items = $old_id ? ( wp_get_nav_menu_items( $old_id ) ?: array() ) : array();
			if ( ! empty( $items ) ) { wp_update_post( array( 'ID' => (int) $items[0]->ID, 'post_title' => 'Changed old receipt' ) ); $failure_injected = true; }
			return false;
		}
		return 'retirement_fail' === $failure_mode ? false : $allowed;
	};
	add_filter( 'devenia_workflow_public_header_projection_retirement_result', $retirement, 10, 2 ); $filters[] = array( 'devenia_workflow_public_header_projection_retirement_result', $retirement, 10 );

	$all_languages = array_merge( array( $source_language ), $targets );
	$editorial_labels = static function ( string $prefix, string $item ) use ( $all_languages, $source_language ): array {
		$labels = array();
		foreach ( $all_languages as $language ) {
			$labels[ $language ] = $source_language === $language
				? $prefix . ' short ' . $item
				: $prefix . ' ' . $language . ' editorial ' . $item;
		}
		return $labels;
	};
	$manifest_items = static function ( string $prefix ) use ( $source_home, $source_blog, $editorial_labels ): array { return array(
		array( 'source_item_id' => 800001, 'type' => 'page', 'title' => $prefix . ' short home', 'labels' => $editorial_labels( $prefix, 'home' ), 'object_id' => $source_home, 'parent_source_item_id' => 0, 'position' => 1 ),
		array( 'source_item_id' => 800003, 'type' => 'custom', 'title' => $prefix . ' short help', 'labels' => $editorial_labels( $prefix, 'help' ), 'url' => 'https://example.org/help/', 'parent_source_item_id' => 800001, 'position' => 2 ),
		array( 'source_item_id' => 800002, 'type' => 'page', 'title' => $prefix . ' short blog', 'labels' => $editorial_labels( $prefix, 'blog' ), 'object_id' => $source_blog, 'parent_source_item_id' => 0, 'position' => 3 ),
	); };

	// Begin with the production-like 0.1.612 state: an enrolled schema-1
	// manifest points at managed menus which already store short editorial
	// labels, while mutable runtime text contains long page-title replacements.
	$legacy_items = $manifest_items( 'Active' );
	foreach ( $legacy_items as &$legacy_item ) { unset( $legacy_item['labels'] ); }
	unset( $legacy_item );
	$legacy_revision = (string) $call( 'public_header_manifest_revision_for_items', $legacy_items );
	$legacy_manifest = array( 'schema_version' => 1, 'source_language' => $source_language, 'revision' => $legacy_revision, 'items' => $legacy_items, 'updated_at' => gmdate( 'c' ) );
	$legacy_identities = array();
	$conflicting_authority_menu_id = 0;
	$first_enrollment_source_menu_id = 0;
	$retained_authority_menu_ids = array();
	foreach ( $all_languages as $language ) {
		$labels_home = $editorial_labels( 'Active', 'home' );
		$labels_help = $editorial_labels( 'Active', 'help' );
		$labels_blog = $editorial_labels( 'Active', 'blog' );
		$object_home = $source_language === $language ? $source_home : (int) $translated[ $language ]['home'];
		$object_blog = $source_language === $language ? $source_blog : (int) $translated[ $language ]['blog'];
		$create_legacy_menu = static function ( string $name, bool $managed ) use ( &$menus, $language, $labels_home, $labels_help, $labels_blog, $object_home, $object_blog, $legacy_revision ): int {
			$menu_id = wp_create_nav_menu( $name ); if ( is_wp_error( $menu_id ) ) { throw new RuntimeException( $menu_id->get_error_message() ); } $menus[] = (int) $menu_id;
			$home_item = wp_update_nav_menu_item( (int) $menu_id, 0, array( 'menu-item-title' => $labels_home[ $language ], 'menu-item-object' => 'page', 'menu-item-object-id' => $object_home, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 1 ) );
			$help_item = wp_update_nav_menu_item( (int) $menu_id, 0, array( 'menu-item-title' => $labels_help[ $language ], 'menu-item-url' => 'https://example.org/help/', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-parent-id' => (int) $home_item, 'menu-item-position' => 2 ) );
			$blog_item = wp_update_nav_menu_item( (int) $menu_id, 0, array( 'menu-item-title' => $labels_blog[ $language ], 'menu-item-object' => 'page', 'menu-item-object-id' => $object_blog, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => 3 ) );
			if ( is_wp_error( $home_item ) || is_wp_error( $help_item ) || is_wp_error( $blog_item ) ) { throw new RuntimeException( 'Could not build legacy editorial menu.' ); }
			if ( $managed ) {
				update_term_meta( (int) $menu_id, '_devenia_workflow_localized_menu_managed', '1' );
				update_term_meta( (int) $menu_id, '_devenia_workflow_localized_menu_language', $language );
				update_term_meta( (int) $menu_id, '_devenia_workflow_public_header_manifest_revision', $legacy_revision );
				foreach ( array( (int) $home_item => 800001, (int) $help_item => 800003, (int) $blog_item => 800002 ) as $item_id => $source_item_id ) { update_post_meta( $item_id, '_devenia_translation_source_menu_item_id', $source_item_id ); }
			}
			return (int) $menu_id;
		};
		$authority_menu_id = $create_legacy_menu( (string) $registry[ $language ]['menu_name'], false );
		if ( $source_language === $language ) { $first_enrollment_source_menu_id = $authority_menu_id; }
		$second_authority_menu_id = $create_legacy_menu( 'Retained editorial ' . $language . ' ' . $token, false );
		$retained_authority_menu_ids[ $language ] = array( $authority_menu_id, $second_authority_menu_id );
		if ( $language === $targets[0] ) {
			$conflicting_authority_menu_id = $create_legacy_menu( 'Conflicting editorial ' . $language . ' ' . $token, false );
			$conflicting_items = wp_get_nav_menu_items( $conflicting_authority_menu_id, array( 'orderby' => 'menu_order' ) ) ?: array();
			if ( empty( $conflicting_items ) || is_wp_error( wp_update_post( array( 'ID' => (int) $conflicting_items[0]->ID, 'post_title' => 'Conflicting retained label' ), true ) ) ) { throw new RuntimeException( 'Could not create conflicting retained-label fixture.' ); }
		}
		$managed_menu_id = $create_legacy_menu( 'Legacy managed ' . $language . ' ' . $token, true );
		$legacy_identities[ $language ] = array( 'menu_id' => $managed_menu_id, 'configured_name' => (string) $registry[ $language ]['menu_name'], 'manifest_revision' => $legacy_revision );
	}
	$enrollment_race_source_menu_id = $first_enrollment_source_menu_id;
	$enrollment_race_authority_menu_id = (int) $retained_authority_menu_ids[ $targets[0] ][0];
	$managed_fixture_menu_ids = static function () use ( $token ): array {
		$ids = array();
		foreach ( wp_get_nav_menus() as $menu ) { if ( is_object( $menu ) && false !== strpos( (string) $menu->name, $token ) && '1' === (string) get_term_meta( (int) $menu->term_id, '_devenia_workflow_localized_menu_managed', true ) ) { $ids[] = (int) $menu->term_id; } }
		sort( $ids ); return $ids;
	};
	$legacy_pending = array( 'status' => 'activated', 'revision' => $legacy_revision, 'activated_at' => 'legacy-fixture' );
	update_option( 'devenia_workflow_public_header_manifest', $legacy_manifest, false );
	update_option( 'devenia_workflow_localized_menu_identities', $legacy_identities, false );
	update_option( 'devenia_workflow_pending_public_header_manifest', $legacy_pending, false );
	update_option( 'devenia_workflow_public_header_enrollment', '1', false );
	$runtime_registry = get_option( 'devenia_workflow_language_registry', array() );
	$runtime_registry[ $targets[0] ]['menu_items'][ (string) $source_home ] = 'Long translated page title that must never render';
	$runtime_registry[ $targets[0] ]['custom_menu_items']['Active short help'] = 'Long mutable custom replacement that must never render';
	update_option( 'devenia_workflow_language_registry', $runtime_registry, false ); Devenia_Workflow::languages( true );
	$request_before_legacy = $_SERVER['REQUEST_URI'] ?? '/'; $_SERVER['REQUEST_URI'] = '/' . sanitize_key( (string) ( $languages[ $targets[0] ]['prefix'] ?? $targets[0] ) ) . '/fixture/';
	$legacy_render = (string) wp_nav_menu( array( 'menu' => (int) $legacy_identities[ $targets[0] ]['menu_id'], 'container' => false, 'echo' => false, 'fallback_cb' => false, 'items_wrap' => '%3$s' ) );
	$_SERVER['REQUEST_URI'] = $request_before_legacy;
	if ( false === strpos( $legacy_render, 'Active ' . $targets[0] . ' editorial home' ) || false === strpos( $legacy_render, 'Active ' . $targets[0] . ' editorial help' ) || false !== strpos( $legacy_render, 'Long translated page title' ) || false !== strpos( $legacy_render, 'Long mutable custom replacement' ) ) { throw new RuntimeException( 'Selected managed schema-1 menu was relocalized after its signed stored labels became reader authority.' ); }
	$migration_capability_state_before = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
	wp_set_current_user( 0 );
	$unauthorized_migration = $call( 'migrate_public_header_label_authority', array( 'stage' => true ) );
	wp_set_current_user( (int) $admins[0] );
	$migration_capability_state_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
	if ( ! empty( $unauthorized_migration['success'] ) || $migration_capability_state_before !== $migration_capability_state_after ) { throw new RuntimeException( 'Legacy identity migration was not capability-gated and mutation-free for an unauthorized caller.' ); }
	$migration_conflict = $call( 'migrate_public_header_label_authority', array() );
	if ( 'public_header_label_authority_incomplete' !== (string) ( $migration_conflict['code'] ?? '' ) || false === strpos( wp_json_encode( $migration_conflict['missing'] ?? array() ), 'authority_candidate_conflict' ) || $legacy_pending !== get_option( 'devenia_workflow_pending_public_header_manifest', array() ) ) { throw new RuntimeException( 'Conflicting retained menu labels did not remain unresolved.' ); }
	$missing_explicit_menu_id = 2000000000;
	$explicit_migration_state_before = get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' );
	$explicit_migration_invalid = $call( 'migrate_public_header_label_authority', array( 'stage' => true, 'authority_menus' => array( array( 'language' => $targets[0], 'menu_id' => (int) $retained_authority_menu_ids[ $targets[0] ][0] ), array( 'language' => $targets[0], 'menu_id' => (int) $retained_authority_menu_ids[ $targets[0] ][1] ), array( 'language' => $targets[0], 'menu_id' => $missing_explicit_menu_id ) ) ) );
	if ( ! empty( $explicit_migration_invalid['success'] ) || false === strpos( wp_json_encode( $explicit_migration_invalid['missing'] ?? array() ), 'authority_menu_missing' ) || $explicit_migration_state_before !== get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ) ) { throw new RuntimeException( 'Schema-1 migration silently omitted one invalid member of an explicit authority set: ' . wp_json_encode( $explicit_migration_invalid ) ); }
	wp_delete_nav_menu( $conflicting_authority_menu_id );
	$migration_authority_race_menu_id = (int) $retained_authority_menu_ids[ $targets[0] ][0];
	$migration_authority_race_items = wp_get_nav_menu_items( $migration_authority_race_menu_id, array( 'orderby' => 'menu_order' ) ) ?: array();
	$migration_authority_race_item_id = absint( $migration_authority_race_items[0]->ID ?? 0 );
	$migration_authority_race_original_title = (string) ( $migration_authority_race_items[0]->title ?? '' );
	$migration_authority_race_pending_before = get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' );
	$migration_authority_race_mode = 'menu';
	$migration_authority_race = $call( 'migrate_public_header_label_authority', array( 'stage' => true ) );
	if ( $migration_authority_race_item_id > 0 ) { wp_update_post( array( 'ID' => $migration_authority_race_item_id, 'post_title' => $migration_authority_race_original_title ) ); }
	if ( ! empty( $migration_authority_race['success'] ) || ! in_array( (string) ( $migration_authority_race['code'] ?? '' ), array( 'public_header_authority_menu_changed', 'public_header_authority_snapshot_changed' ), true ) || $migration_authority_race_pending_before !== get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ) ) { throw new RuntimeException( 'Schema-1 migration authority changed after snapshot but still mutated pending state: ' . wp_json_encode( $migration_authority_race ) ); }
	$migration_draft = $call( 'migrate_public_header_label_authority', array() );
	if ( empty( $migration_draft['success'] ) || 2 !== (int) ( $migration_draft['draft']['schema_version'] ?? 0 ) || $legacy_pending !== get_option( 'devenia_workflow_pending_public_header_manifest', array() ) ) { throw new RuntimeException( 'Schema-1 label-authority migration draft failed or mutated pending state.' ); }
	$migration_stage_state_before = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__devenia_workflow_option_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__devenia_workflow_option_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__devenia_workflow_option_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__devenia_workflow_option_missing__' ) );
	$migration_stage = $call( 'migrate_public_header_label_authority', array( 'stage' => true ) );
	$migration_stage_state_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__devenia_workflow_option_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__devenia_workflow_option_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__devenia_workflow_option_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__devenia_workflow_option_missing__' ) );
	if ( empty( $migration_stage['staged'] ) ) { throw new RuntimeException( 'Schema-2 migration draft could not be staged: ' . wp_json_encode( array( 'migration_stage' => $migration_stage, 'migration_draft' => $migration_draft, 'state_before' => $migration_stage_state_before, 'state_after' => $migration_stage_state_after, 'retained_authority_menu_ids' => $retained_authority_menu_ids, 'conflicting_authority_menu_id' => $conflicting_authority_menu_id, 'conflicting_menu_exists_after_delete' => (bool) wp_get_nav_menu_object( $conflicting_authority_menu_id ) ) ) ); }
	$migration_pending = get_option( 'devenia_workflow_pending_public_header_manifest', array() );
	$managed_before_relation_race = $managed_fixture_menu_ids();
	$authority_relation_race_translation_id = (int) $translated[ $targets[0] ]['home'];
	$authority_relation_race_mode = 'translation_status';
	$authority_relation_race = $call( 'sync_public_header_projection', array( 'timeout' => 5 ) );
	wp_update_post( array( 'ID' => $authority_relation_race_translation_id, 'post_status' => 'publish' ) ); $call( 'sync_translation_index_row', $authority_relation_race_translation_id );
	$bound_relation_consumed = count( (array) ( $authority_relation_race['projections'] ?? array() ) ) === count( $all_languages );
	foreach ( (array) ( $authority_relation_race['projections'] ?? array() ) as $relation_language => $relation_projection ) { $bound_relation_consumed = $bound_relation_consumed && ! empty( $relation_projection['relation_authority_consumed'] ) && hash_equals( (string) ( $migration_pending['authority_receipts'][ $relation_language ]['relation_revision'] ?? '' ), (string) ( $relation_projection['relation_authority_revision'] ?? '' ) ); }
	if ( 'public_header_authority_changed_before_activation' !== (string) ( $authority_relation_race['code'] ?? '' ) || empty( $authority_relation_race['cleanup']['success'] ) || ! $bound_relation_consumed || 1 !== (int) ( get_option( 'devenia_workflow_public_header_manifest', array() )['schema_version'] ?? 0 ) || $migration_pending !== get_option( 'devenia_workflow_pending_public_header_manifest', array() ) || $managed_before_relation_race !== $managed_fixture_menu_ids() ) { throw new RuntimeException( 'A translation relation changed after receipt-bound staging but was not rejected and cleaned before activation: ' . wp_json_encode( $authority_relation_race ) ); }
	$failure_mode = 'verification_fail'; $verification_fault_remaining = 1; $verification_fault_revision = (string) ( $migration_pending['revision'] ?? '' ); $verification_rollback_observations = array();
	$migration_rollback = $call( 'sync_public_header_projection', array( 'timeout' => 5 ) );
	$rollback_observation_count = count( $all_languages ) * 4;
	$rollback_observations_exact = $rollback_observation_count === count( $verification_rollback_observations ) && empty( array_filter( $verification_rollback_observations, static function ( array $observation ) use ( $legacy_revision ): bool { return 1 !== (int) $observation['schema_version'] || ! hash_equals( $legacy_revision, (string) $observation['manifest_revision'] ) || ! hash_equals( $legacy_revision, (string) $observation['identity_revision'] ); } ) );
	$schema1_rollback_assertions = array(
		'expected_failure_code' => 'public_header_projection_verification_failed' === (string) ( $migration_rollback['code'] ?? '' ),
		'forward_fault_consumed_once' => 0 === $verification_fault_remaining,
		'schema1_manifest_restored' => 1 === (int) ( get_option( 'devenia_workflow_public_header_manifest', array() )['schema_version'] ?? 0 ),
		'identities_restored_exactly' => $legacy_identities === get_option( 'devenia_workflow_localized_menu_identities', array() ),
		'pre_activation_pending_restored_exactly' => $migration_pending === get_option( 'devenia_workflow_pending_public_header_manifest', array() ),
		'rollback_verification_passed' => ! empty( $migration_rollback['rollback_verification']['passed'] ),
		'all_rollback_fetches_observed_legacy_receipts' => $rollback_observations_exact,
	);
	if ( in_array( false, $schema1_rollback_assertions, true ) ) { throw new RuntimeException( 'Schema-2 failure did not restore and verify the exact schema-1 reader surface: ' . wp_json_encode( $schema1_rollback_assertions ) ); }
	$failure_mode = '';
	$migration_stage = $call( 'migrate_public_header_label_authority', array( 'stage' => true ) );
	$migration_success = $call( 'sync_public_header_projection', array( 'timeout' => 5 ) );
	$migration_active_manifest = get_option( 'devenia_workflow_public_header_manifest', array() );
	if ( empty( $migration_stage['staged'] ) || empty( $migration_success['success'] ) || 2 !== (int) ( $migration_active_manifest['schema_version'] ?? 0 ) || array_key_exists( 'authority_receipts', $migration_active_manifest ) ) { throw new RuntimeException( 'Schema-1 editorial labels were not repaired through successful schema-2 activation without leaking intake authority into active reader state.' ); }

	// Reset the fixture and retain the existing exhaustive clean-install tests.
	delete_option( 'devenia_workflow_public_header_manifest' ); delete_option( 'devenia_workflow_pending_public_header_manifest' ); delete_option( 'devenia_workflow_localized_menu_identities' ); delete_option( 'devenia_workflow_public_header_enrollment' );
	$enrollment_locations = get_theme_mod( 'nav_menu_locations', array() ); $enrollment_locations = is_array( $enrollment_locations ) ? $enrollment_locations : array(); $enrollment_locations['primary'] = $first_enrollment_source_menu_id; set_theme_mod( 'nav_menu_locations', $enrollment_locations );
	$unenrolled_before = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
	$conflict_menu = wp_create_nav_menu( 'Enrollment conflict ' . $targets[0] . ' ' . $token ); if ( is_wp_error( $conflict_menu ) ) { throw new RuntimeException( $conflict_menu->get_error_message() ); } $menus[] = (int) $conflict_menu;
	$conflict_parent_map = array();
	foreach ( wp_get_nav_menu_items( (int) $retained_authority_menu_ids[ $targets[0] ][0], array( 'orderby' => 'menu_order' ) ) ?: array() as $candidate_item ) {
		$parent = absint( $candidate_item->menu_item_parent ?? 0 );
		$new_item = wp_update_nav_menu_item( (int) $conflict_menu, 0, array( 'menu-item-title' => (string) $candidate_item->title, 'menu-item-url' => (string) $candidate_item->url, 'menu-item-object' => (string) $candidate_item->object, 'menu-item-object-id' => absint( $candidate_item->object_id ?? 0 ), 'menu-item-type' => (string) $candidate_item->type, 'menu-item-status' => 'publish', 'menu-item-parent-id' => absint( $conflict_parent_map[ $parent ] ?? 0 ), 'menu-item-position' => absint( $candidate_item->menu_order ?? 0 ) ) );
		if ( is_wp_error( $new_item ) ) { throw new RuntimeException( $new_item->get_error_message() ); } $conflict_parent_map[ (int) $candidate_item->ID ] = (int) $new_item;
	}
	$conflict_items = wp_get_nav_menu_items( (int) $conflict_menu, array( 'orderby' => 'menu_order' ) ) ?: array(); wp_update_post( array( 'ID' => (int) $conflict_items[0]->ID, 'post_title' => 'Enrollment conflicting label' ) );
	$enrollment_conflict = $call( 'enroll_public_header_from_existing_menus', array( 'source_menu_id' => $first_enrollment_source_menu_id ) );
	$unenrolled_after_conflict = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
	if ( 'public_header_enrollment_authority_incomplete' !== (string) ( $enrollment_conflict['code'] ?? '' ) || false === strpos( wp_json_encode( $enrollment_conflict['missing'] ?? array() ), 'authority_candidate_conflict' ) || $unenrolled_before !== $unenrolled_after_conflict ) { throw new RuntimeException( 'First-enrollment consensus conflict did not fail before mutation.' ); }
	foreach ( (array) $retained_authority_menu_ids[ $targets[0] ] as $temporarily_managed_id ) { update_term_meta( $temporarily_managed_id, '_devenia_workflow_localized_menu_managed', '1' ); }
	$missing_enrollment_authority = $call( 'enroll_public_header_from_existing_menus', array( 'source_menu_id' => $first_enrollment_source_menu_id ) );
	foreach ( (array) $retained_authority_menu_ids[ $targets[0] ] as $temporarily_managed_id ) { delete_term_meta( $temporarily_managed_id, '_devenia_workflow_localized_menu_managed' ); }
	$unenrolled_after_missing = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
	if ( 'public_header_enrollment_authority_incomplete' !== (string) ( $missing_enrollment_authority['code'] ?? '' ) || $unenrolled_before !== $unenrolled_after_missing ) { throw new RuntimeException( 'Missing target authority did not fail before first-enrollment mutation.' ); }
	$single_explicit_authority = $call( 'enroll_public_header_from_existing_menus', array( 'source_menu_id' => $first_enrollment_source_menu_id, 'authority_menus' => array( array( 'language' => $targets[0], 'menu_id' => (int) $retained_authority_menu_ids[ $targets[0] ][0] ) ) ) );
	$single_explicit_missing = array_values( array_filter( (array) ( $single_explicit_authority['missing'] ?? array() ), static function ( array $row ) use ( $targets ): bool { return $targets[0] === (string) ( $row['language'] ?? '' ) && 'insufficient_independent_authority_candidates' === (string) ( $row['reason'] ?? '' ); } ) );
	if ( ! empty( $single_explicit_authority['success'] ) || 1 !== count( $single_explicit_missing ) || array( (int) $retained_authority_menu_ids[ $targets[0] ][0] ) !== array_values( array_map( 'intval', (array) ( $single_explicit_missing[0]['candidate_menu_ids'] ?? array() ) ) ) || $unenrolled_before !== array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) ) ) { throw new RuntimeException( 'One explicit authority candidate was incorrectly supplemented by retained discovery: ' . wp_json_encode( $single_explicit_authority ) ); }
	update_term_meta( (int) $conflict_menu, '_devenia_workflow_localized_menu_managed', '1' );
	$explicit_managed_authority = $call( 'enroll_public_header_from_existing_menus', array( 'source_menu_id' => $first_enrollment_source_menu_id, 'authority_menus' => array( array( 'language' => $targets[0], 'menu_id' => (int) $retained_authority_menu_ids[ $targets[0] ][0] ), array( 'language' => $targets[0], 'menu_id' => (int) $retained_authority_menu_ids[ $targets[0] ][1] ), array( 'language' => $targets[0], 'menu_id' => (int) $conflict_menu ) ) ) );
	delete_term_meta( (int) $conflict_menu, '_devenia_workflow_localized_menu_managed' );
	if ( ! empty( $explicit_managed_authority['success'] ) || false === strpos( wp_json_encode( $explicit_managed_authority['missing'] ?? array() ), 'authority_menu_managed_not_retained' ) || $unenrolled_before !== array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) ) ) { throw new RuntimeException( 'First enrollment silently omitted one managed member of an explicit authority set: ' . wp_json_encode( $explicit_managed_authority ) ); }
	$enrollment_draft = $call( 'enroll_public_header_from_existing_menus', array( 'source_menu_id' => $first_enrollment_source_menu_id, 'authority_menus' => array( array( 'language' => $targets[0], 'menu_id' => (int) $retained_authority_menu_ids[ $targets[0] ][0] ), array( 'language' => $targets[0], 'menu_id' => (int) $retained_authority_menu_ids[ $targets[0] ][1] ) ) ) );
	$unenrolled_after_draft = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
	$target_provenance = array_column( (array) ( $enrollment_draft['authority'][ $targets[0] ] ?? array() ), 'provenance', 'menu_id' );
	$retained_relation_match_seen = false;
	foreach ( (array) ( $enrollment_draft['authority'] ?? array() ) as $authority_language => $authority_rows ) { if ( $targets[0] === $authority_language ) { continue; } foreach ( (array) $authority_rows as $authority_row ) { if ( 'retained_relation_match' === (string) ( $authority_row['provenance'] ?? '' ) ) { $retained_relation_match_seen = true; break 2; } } }
	if ( empty( $enrollment_draft['success'] ) || 2 !== (int) ( $enrollment_draft['draft']['schema_version'] ?? 0 ) || 2 !== count( $target_provenance ) || 'explicit_authority' !== (string) ( $target_provenance[ (int) $retained_authority_menu_ids[ $targets[0] ][0] ] ?? '' ) || 'explicit_authority' !== (string) ( $target_provenance[ (int) $retained_authority_menu_ids[ $targets[0] ][1] ] ?? '' ) || ! $retained_relation_match_seen || $unenrolled_before !== $unenrolled_after_draft ) { throw new RuntimeException( 'Complete explicit authority was not isolated from conflicting retained menus in a mutation-free schema-2 draft: ' . wp_json_encode( $enrollment_draft ) ); }
	wp_delete_nav_menu( (int) $conflict_menu );
	$expected_raw_navigation = array(
		array( 'title' => 'Active short home', 'url' => $call( 'normalize_primary_navigation_url', (string) get_permalink( $source_home ) ) ),
		array( 'title' => 'Active short help', 'url' => $call( 'normalize_primary_navigation_url', 'https://example.org/help/' ) ),
		array( 'title' => 'Active short blog', 'url' => $call( 'normalize_primary_navigation_url', (string) get_permalink( $source_blog ) ) ),
	);
	foreach ( $all_languages as $oracle_language ) {
		$oracle_navigation = (array) ( $enrollment_draft['recovery']['expected_navigation'][ $oracle_language ] ?? array() );
		$oracle_evidence = (array) ( $enrollment_draft['recovery']['evidence'][ $oracle_language ] ?? array() );
		if ( $expected_raw_navigation !== $oracle_navigation || 2 !== count( (array) ( $oracle_evidence['homepage'] ?? array() ) ) || 2 !== count( (array) ( $oracle_evidence['blog_archive'] ?? array() ) ) ) { throw new RuntimeException( 'Pre-enrollment recovery oracle did not bind the exact observed labels and URLs on all four public surfaces for ' . $oracle_language . ': ' . wp_json_encode( $enrollment_draft['recovery'] ?? array() ) ); }
	}
	$managed_before_invalid_enrollment_receipt = $managed_fixture_menu_ids();
	$enrollment_commit_mode = 'invalid_applied';
	$invalid_enrollment_receipt_attempt = $call( 'enroll_public_header_from_existing_menus', array( 'source_menu_id' => $first_enrollment_source_menu_id, 'activate' => true, 'timeout' => 5 ) );
	$enrollment_commit_mode = '';
	$invalid_enrollment_receipt_stage = (array) ( $invalid_enrollment_receipt_attempt['stage_result'] ?? array() );
	$invalid_enrollment_receipt_validation = (array) ( $invalid_enrollment_receipt_stage['receipt_validation'] ?? array() );
	$invalid_enrollment_receipt_reconciliation = (array) ( $invalid_enrollment_receipt_stage['reconciliation'] ?? array() );
	$invalid_enrollment_missing = '__devenia_workflow_option_missing__';
	$invalid_enrollment_receipt_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', $invalid_enrollment_missing ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', $invalid_enrollment_missing ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', $invalid_enrollment_missing ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', $invalid_enrollment_missing ) );
	$invalid_enrollment_expected_after = (array) ( $invalid_enrollment_receipt_reconciliation['expected_after'] ?? array() );
	$invalid_enrollment_terminalization = (array) ( $invalid_enrollment_receipt_validation['terminalization'] ?? array() );
	if (
		! empty( $invalid_enrollment_receipt_attempt['success'] )
		|| ! empty( $invalid_enrollment_receipt_attempt['activated'] )
		|| array_key_exists( 'activation', $invalid_enrollment_receipt_attempt )
		|| array_key_exists( 'intake_state_restore', $invalid_enrollment_receipt_attempt )
		|| 'public_header_enrollment_commit_receipt_invalid' !== (string) ( $invalid_enrollment_receipt_stage['code'] ?? '' )
		|| 'critical' !== (string) ( $invalid_enrollment_receipt_stage['severity'] ?? '' )
		|| 'invalid_receipt' !== (string) ( $invalid_enrollment_receipt_stage['state_outcome'] ?? '' )
		|| ! array_key_exists( 'committed', $invalid_enrollment_receipt_stage )
		|| null !== $invalid_enrollment_receipt_stage['committed']
		|| array_key_exists( 'committed', (array) ( $invalid_enrollment_receipt_stage['commit'] ?? array() ) )
		|| ! in_array( 'missing_committed', (array) ( $invalid_enrollment_receipt_validation['violations'] ?? array() ), true )
		|| 'transaction_not_owned' !== (string) ( $invalid_enrollment_terminalization['code'] ?? '' )
		|| empty( $invalid_enrollment_receipt_reconciliation['applied_state_observed'] )
		|| ! empty( $invalid_enrollment_receipt_reconciliation['pre_state_proven'] )
		|| ! empty( $invalid_enrollment_receipt_reconciliation['restore'] )
		|| empty( $invalid_enrollment_expected_after )
		|| $call( 'translation_job_canonicalize', $invalid_enrollment_expected_after ) !== $call( 'translation_job_canonicalize', $invalid_enrollment_receipt_after )
		|| $managed_before_invalid_enrollment_receipt !== $managed_fixture_menu_ids()
	) { throw new RuntimeException( 'Malformed first-enrollment COMMIT receipt did not fail closed on its exact applied pending state without activation, restore, or cleanup: ' . wp_json_encode( array( 'attempt_success' => ! empty( $invalid_enrollment_receipt_attempt['success'] ), 'attempt_activated' => ! empty( $invalid_enrollment_receipt_attempt['activated'] ), 'activation_present' => array_key_exists( 'activation', $invalid_enrollment_receipt_attempt ), 'intake_restore_present' => array_key_exists( 'intake_state_restore', $invalid_enrollment_receipt_attempt ), 'code' => (string) ( $invalid_enrollment_receipt_stage['code'] ?? '' ), 'severity' => (string) ( $invalid_enrollment_receipt_stage['severity'] ?? '' ), 'state_outcome' => (string) ( $invalid_enrollment_receipt_stage['state_outcome'] ?? '' ), 'committed_present' => array_key_exists( 'committed', $invalid_enrollment_receipt_stage ), 'committed' => $invalid_enrollment_receipt_stage['committed'] ?? '__missing__', 'raw_commit_has_committed' => array_key_exists( 'committed', (array) ( $invalid_enrollment_receipt_stage['commit'] ?? array() ) ), 'violations' => (array) ( $invalid_enrollment_receipt_validation['violations'] ?? array() ), 'terminalization' => $invalid_enrollment_terminalization, 'applied_state_observed' => ! empty( $invalid_enrollment_receipt_reconciliation['applied_state_observed'] ), 'pre_state_proven' => ! empty( $invalid_enrollment_receipt_reconciliation['pre_state_proven'] ), 'restore' => $invalid_enrollment_receipt_reconciliation['restore'] ?? '__missing__', 'expected_after_present' => ! empty( $invalid_enrollment_expected_after ), 'state_matches' => $call( 'translation_job_canonicalize', $invalid_enrollment_expected_after ) === $call( 'translation_job_canonicalize', $invalid_enrollment_receipt_after ), 'managed_matches' => $managed_before_invalid_enrollment_receipt === $managed_fixture_menu_ids() ) ) ); }
	foreach ( array( 'manifest' => 'devenia_workflow_public_header_manifest', 'identities' => 'devenia_workflow_localized_menu_identities', 'pending' => 'devenia_workflow_pending_public_header_manifest', 'enrollment' => 'devenia_workflow_public_header_enrollment' ) as $slot => $option_key ) { '__workflow_missing__' === $unenrolled_before[ $slot ] ? delete_option( $option_key ) : update_option( $option_key, $unenrolled_before[ $slot ], false ); }
	$invalid_enrollment_reset = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
	if ( $unenrolled_before !== $invalid_enrollment_reset ) { throw new RuntimeException( 'First-enrollment malformed-receipt fixture reset did not restore its exact pre-state.' ); }
	foreach ( array( 'rollback_confirmed' => array( 'public_header_enrollment_commit_rolled_back', false, false, false ), 'applied_then_error' => array( 'public_header_enrollment_commit_applied_then_restored', true, false, true ), 'unknown' => array( 'public_header_enrollment_commit_outcome_unknown_reconciled', null, true, true ) ) as $commit_mode => $expectation ) {
		$managed_before_commit_outcome = $managed_fixture_menu_ids();
		$enrollment_commit_mode = $commit_mode;
		$commit_attempt = $call( 'enroll_public_header_from_existing_menus', array( 'source_menu_id' => $first_enrollment_source_menu_id, 'activate' => true, 'timeout' => 5 ) );
		$enrollment_commit_mode = '';
		$commit_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
		$stage_result = (array) ( $commit_attempt['stage_result'] ?? array() );
		if ( ! empty( $commit_attempt['success'] ) || $expectation[0] !== (string) ( $stage_result['code'] ?? '' ) || ! array_key_exists( 'committed', $stage_result ) || $expectation[1] !== $stage_result['committed'] || $expectation[2] !== ( 'critical' === (string) ( $stage_result['severity'] ?? '' ) ) || $expectation[3] !== ! empty( $stage_result['reconciliation']['applied_state_observed'] ) || empty( $stage_result['reconciliation']['pre_state_proven'] ) || $unenrolled_before !== $commit_after || $managed_before_commit_outcome !== $managed_fixture_menu_ids() ) { throw new RuntimeException( 'First-enrollment commit outcome was not reconciled exactly for ' . $commit_mode . ': ' . wp_json_encode( $commit_attempt ) ); }
	}
	foreach ( array( 'success_foreign' => array( true, 'public_header_enrollment_commit_reconciliation_conflict' ), 'applied_foreign' => array( true, 'public_header_enrollment_commit_reconciliation_conflict' ), 'unknown_foreign' => array( null, 'public_header_enrollment_commit_outcome_unknown_conflict' ) ) as $foreign_mode => $foreign_expectation ) {
		$managed_before_foreign = $managed_fixture_menu_ids(); $enrollment_foreign_state = array(); $enrollment_commit_mode = $foreign_mode;
		$foreign_attempt = $call( 'enroll_public_header_from_existing_menus', array( 'source_menu_id' => $first_enrollment_source_menu_id, 'activate' => true, 'timeout' => 5 ) );
		$enrollment_commit_mode = '';
		$foreign_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
		$foreign_stage = (array) ( $foreign_attempt['stage_result'] ?? array() );
		if ( $call( 'translation_job_canonicalize', $enrollment_foreign_state ) !== $call( 'translation_job_canonicalize', $foreign_after ) || ! array_key_exists( 'committed', $foreign_stage ) || $foreign_expectation[0] !== $foreign_stage['committed'] || $foreign_expectation[1] !== (string) ( $foreign_stage['code'] ?? '' ) || 'critical' !== (string) ( $foreign_stage['severity'] ?? '' ) || empty( $foreign_stage['reconciliation']['foreign_state_observed'] ) || ! empty( $foreign_stage['reconciliation']['restore'] ) || ! empty( $foreign_attempt['activation'] ) || $managed_before_foreign !== $managed_fixture_menu_ids() ) { throw new RuntimeException( 'Foreign four-option state was not preserved byte-exact or escaped into activation for ' . $foreign_mode . ': ' . wp_json_encode( $foreign_attempt ) ); }
		foreach ( array( 'manifest' => 'devenia_workflow_public_header_manifest', 'identities' => 'devenia_workflow_localized_menu_identities', 'pending' => 'devenia_workflow_pending_public_header_manifest', 'enrollment' => 'devenia_workflow_public_header_enrollment' ) as $slot => $option_key ) { '__workflow_missing__' === $unenrolled_before[ $slot ] ? delete_option( $option_key ) : update_option( $option_key, $unenrolled_before[ $slot ], false ); }
	}
	foreach ( array( 'rollback_confirmed', 'applied_error', 'unknown_applied', 'invalid_applied', 'unknown_foreign', 'success_foreign' ) as $activation_mode ) {
		$managed_before_activation_commit = $managed_fixture_menu_ids(); $activation_commit_mode = $activation_mode; $activation_commit_injected = false; $activation_commit_foreign_state = array();
		$activation_commit_attempt = $call( 'enroll_public_header_from_existing_menus', array( 'source_menu_id' => $first_enrollment_source_menu_id, 'activate' => true, 'timeout' => 5 ) );
		$activation_commit_mode = '';
		$activation_sync = (array) ( $activation_commit_attempt['activation'] ?? array() ); $activation_transaction = (array) ( $activation_sync['activation'] ?? array() ); $activation_projections = (array) ( $activation_sync['projections'] ?? array() );
		$activation_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
		if ( 'rollback_confirmed' === $activation_mode ) {
			if ( ! $activation_commit_injected || 'unapplied' !== (string) ( $activation_transaction['state_outcome'] ?? '' ) || empty( $activation_commit_attempt['intake_state_restore']['success'] ) || $unenrolled_before !== $activation_after || $managed_before_activation_commit !== $managed_fixture_menu_ids() ) { throw new RuntimeException( 'Proven-unapplied activation COMMIT did not clean only unapplied projections: ' . wp_json_encode( $activation_commit_attempt ) ); }
			continue;
		}
		$projection_ids = array(); foreach ( $activation_projections as $projection ) { $menu_id = absint( $projection['target_menu']['id'] ?? 0 ); if ( $menu_id > 0 ) { $projection_ids[] = $menu_id; } } sort( $projection_ids );
		$referenced_ids = array(); foreach ( (array) $activation_after['identities'] as $identity ) { $menu_id = absint( is_array( $identity ) ? ( $identity['menu_id'] ?? 0 ) : 0 ); if ( $menu_id > 0 ) { $referenced_ids[] = $menu_id; } } sort( $referenced_ids );
		$foreign_mode = in_array( $activation_mode, array( 'unknown_foreign', 'success_foreign' ), true );
		$foreign_exact = ! $foreign_mode || $call( 'translation_job_canonicalize', $activation_commit_foreign_state ) === $call( 'translation_job_canonicalize', $activation_after );
		$applied_reference_closed = $foreign_mode || ( ! empty( $projection_ids ) && $projection_ids === $referenced_ids );
		$projection_menus_preserved = ! empty( $projection_ids ) && empty( array_filter( $projection_ids, static function ( int $menu_id ): bool { return ! wp_get_nav_menu_object( $menu_id ) || '1' !== (string) get_term_meta( $menu_id, '_devenia_workflow_localized_menu_managed', true ); } ) );
		if ( ! $activation_commit_injected || 'critical' !== (string) ( $activation_commit_attempt['severity'] ?? '' ) || 'public_header_projection_activation_state_unresolved' !== (string) ( $activation_sync['code'] ?? '' ) || ! empty( $activation_sync['cleanup_authority']['allowed'] ) || ! $foreign_exact || ! $applied_reference_closed || ! $projection_menus_preserved ) { throw new RuntimeException( 'Applied or unknown activation COMMIT lost state or deleted referenced menus for ' . $activation_mode . ': ' . wp_json_encode( $activation_commit_attempt ) ); }
		if ( 'invalid_applied' === $activation_mode ) {
			$invalid_activation_validation = (array) ( $activation_transaction['receipt_validation'] ?? array() );
			$invalid_activation_terminalization = (array) ( $invalid_activation_validation['terminalization'] ?? array() );
			$invalid_activation_intake_restore = (array) ( $activation_commit_attempt['intake_state_restore'] ?? array() );
			if (
				! empty( $activation_commit_attempt['success'] )
				|| ! empty( $activation_commit_attempt['activated'] )
				|| 'public_header_enrollment_restore_failed' !== (string) ( $activation_commit_attempt['code'] ?? '' )
				|| 'public_header_state_commit_receipt_invalid' !== (string) ( $activation_transaction['code'] ?? '' )
				|| 'invalid_receipt' !== (string) ( $activation_transaction['state_outcome'] ?? '' )
				|| ! array_key_exists( 'committed', $activation_transaction )
				|| null !== $activation_transaction['committed']
				|| array_key_exists( 'committed', (array) ( $activation_transaction['commit'] ?? array() ) )
				|| ! in_array( 'missing_committed', (array) ( $invalid_activation_validation['violations'] ?? array() ), true )
				|| 'transaction_not_owned' !== (string) ( $invalid_activation_terminalization['code'] ?? '' )
				|| empty( $activation_transaction['replacement_state_exact'] )
				|| 'replacement' !== (string) ( $activation_transaction['state_class'] ?? '' )
				|| $call( 'translation_job_canonicalize', $activation_after ) !== $call( 'translation_job_canonicalize', (array) ( $activation_transaction['current_state'] ?? array() ) )
				|| 'staged_projection_cleanup_not_authorized' !== (string) ( $activation_sync['cleanup']['code'] ?? '' )
				|| array_key_exists( 'cache_invalidation', $activation_sync )
				|| array_key_exists( 'verification', $activation_sync )
				|| array_key_exists( 'retirement', $activation_sync )
				|| ! empty( $invalid_activation_intake_restore['success'] )
				|| empty( $invalid_activation_intake_restore['activation_severe'] )
				|| 'public_header_enrollment_severe_rollback_not_bypassed' !== (string) ( $invalid_activation_intake_restore['transaction']['code'] ?? '' )
				|| 'cleanup_blocked_by_foreign_staged_menu_reference' !== (string) ( $invalid_activation_intake_restore['cleanup']['code'] ?? '' )
			) { throw new RuntimeException( 'Malformed activation COMMIT receipt did not preserve the exact applied header while denying continuation, restore, and staged cleanup: ' . wp_json_encode( $activation_commit_attempt ) ); }
		}
		foreach ( array( 'manifest' => 'devenia_workflow_public_header_manifest', 'identities' => 'devenia_workflow_localized_menu_identities', 'pending' => 'devenia_workflow_pending_public_header_manifest', 'enrollment' => 'devenia_workflow_public_header_enrollment' ) as $slot => $option_key ) { '__workflow_missing__' === $unenrolled_before[ $slot ] ? delete_option( $option_key ) : update_option( $option_key, $unenrolled_before[ $slot ], false ); }
		$production_missing = '__devenia_workflow_option_missing__';
		$activation_cleanup_state = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', $production_missing ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', $production_missing ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', $production_missing ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', $production_missing ) );
		$activation_cleanup_authority = $call( 'public_header_staged_cleanup_state_authority', $activation_cleanup_state, $production_missing === ( $activation_cleanup_state['identities'] ?? null ) );
		$activation_fixture_cleanup = $call( 'delete_staged_public_header_projections', $activation_projections, $activation_cleanup_authority );
		if ( empty( $activation_fixture_cleanup['success'] ) || ! empty( array_filter( $projection_ids, 'wp_get_nav_menu_object' ) ) ) { throw new RuntimeException( 'Activation commit fixture cleanup failed after explicit state reset for ' . $activation_mode . ': ' . wp_json_encode( array( 'cleanup' => $activation_fixture_cleanup, 'cleanup_state' => $activation_cleanup_state, 'projection_ids' => $projection_ids ) ) ); }
	}
	foreach ( array( 'ordinary' => array( 'invalidation_fail', 'public_header_enrollment_intake_restore_conflict' ), 'severe' => array( 'rollback_cache_fail', 'public_header_enrollment_severe_rollback_not_bypassed' ) ) as $post_activation_mode => $post_activation_expectation ) {
		$post_activation_failure_mode = $post_activation_expectation[0];
		$managed_before_post_activation_foreign = $managed_fixture_menu_ids(); $enrollment_post_activation_foreign_state = array(); $enrollment_post_activation_foreign_mode = $post_activation_mode; $failure_mode = $post_activation_failure_mode;
		$post_activation_foreign_attempt = $call( 'enroll_public_header_from_existing_menus', array( 'source_menu_id' => $first_enrollment_source_menu_id, 'activate' => true, 'timeout' => 5 ) );
		$failure_mode = ''; $enrollment_post_activation_foreign_mode = '';
		$post_activation_foreign_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
		$intake_restore = (array) ( $post_activation_foreign_attempt['intake_state_restore'] ?? array() );
		$foreign_cleanup_expected = 'ordinary' === $post_activation_mode;
		if ( $call( 'translation_job_canonicalize', $enrollment_post_activation_foreign_state ) !== $call( 'translation_job_canonicalize', $post_activation_foreign_after ) || 'critical' !== (string) ( $post_activation_foreign_attempt['severity'] ?? '' ) || 'public_header_enrollment_restore_failed' !== (string) ( $post_activation_foreign_attempt['code'] ?? '' ) || ! empty( $intake_restore['success'] ) || $post_activation_expectation[1] !== (string) ( $intake_restore['transaction']['code'] ?? '' ) || $foreign_cleanup_expected !== ! empty( $intake_restore['cleanup']['success'] ) || ( ! $foreign_cleanup_expected && 'cleanup_blocked_by_foreign_staged_menu_reference' !== (string) ( $intake_restore['cleanup']['code'] ?? '' ) ) || ( $foreign_cleanup_expected && $managed_before_post_activation_foreign !== $managed_fixture_menu_ids() ) ) { throw new RuntimeException( 'Post-activation foreign state or cleanup authority was incorrect for ' . $post_activation_mode . ': ' . wp_json_encode( $post_activation_foreign_attempt ) ); }
		foreach ( array( 'manifest' => 'devenia_workflow_public_header_manifest', 'identities' => 'devenia_workflow_localized_menu_identities', 'pending' => 'devenia_workflow_pending_public_header_manifest', 'enrollment' => 'devenia_workflow_public_header_enrollment' ) as $slot => $option_key ) { '__workflow_missing__' === $unenrolled_before[ $slot ] ? delete_option( $option_key ) : update_option( $option_key, $unenrolled_before[ $slot ], false ); }
		if ( ! $foreign_cleanup_expected ) {
			$production_missing = '__devenia_workflow_option_missing__';
			$foreign_cleanup_state = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', $production_missing ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', $production_missing ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', $production_missing ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', $production_missing ) );
			$foreign_cleanup_authority = $call( 'public_header_staged_cleanup_state_authority', $foreign_cleanup_state, $production_missing === ( $foreign_cleanup_state['identities'] ?? null ) );
			$foreign_fixture_cleanup = $call( 'delete_staged_public_header_projections', (array) ( $post_activation_foreign_attempt['activation']['projections'] ?? array() ), $foreign_cleanup_authority );
			if ( empty( $foreign_fixture_cleanup['success'] ) || $managed_before_post_activation_foreign !== $managed_fixture_menu_ids() ) { throw new RuntimeException( 'Foreign severe fixture could not remove receipt-bound menus after explicit state reset: ' . wp_json_encode( array( 'cleanup' => $foreign_fixture_cleanup, 'cleanup_state' => $foreign_cleanup_state ) ) ); }
		}
	}
	foreach ( array( 'primary' => 'public_header_enrollment_locked_state_changed', 'source' => 'public_header_enrollment_authority_changed_at_locked_boundary', 'authority' => 'public_header_enrollment_authority_changed_at_locked_boundary' ) as $race_mode => $expected_code ) {
		$managed_before_race = $managed_fixture_menu_ids();
		$enrollment_race_mode = $race_mode;
		$race_attempt = $call( 'enroll_public_header_from_existing_menus', array( 'source_menu_id' => $first_enrollment_source_menu_id, 'activate' => true, 'timeout' => 5 ) );
		$enrollment_race_mode = '';
		wp_cache_delete( 'theme_mods_' . (string) get_option( 'stylesheet' ), 'options' );
		foreach ( array( $enrollment_race_source_menu_id, $enrollment_race_authority_menu_id ) as $race_menu_id ) { foreach ( wp_get_nav_menu_items( $race_menu_id, array( 'orderby' => 'menu_order' ) ) ?: array() as $race_item ) { clean_post_cache( (int) $race_item->ID ); } }
		$race_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
		$current_locations = get_nav_menu_locations();
		if ( ! empty( $race_attempt['success'] ) || $expected_code !== (string) ( $race_attempt['stage_result']['code'] ?? '' ) || $unenrolled_before !== $race_after || $first_enrollment_source_menu_id !== absint( $current_locations['primary'] ?? 0 ) || $managed_before_race !== $managed_fixture_menu_ids() ) { throw new RuntimeException( 'Locked first-enrollment race did not fail without state or staged-menu mutation for ' . $race_mode . ': ' . wp_json_encode( $race_attempt ) ); }
	}
	$failure_mode = 'receipt_fail';
	$managed_before_receipt_failure = $managed_fixture_menu_ids();
	$failed_first_enrollment = $call( 'enroll_public_header_from_existing_menus', array( 'source_menu_id' => $first_enrollment_source_menu_id, 'activate' => true, 'timeout' => 5 ) );
	$unenrolled_after_failure = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
	if ( ! empty( $failed_first_enrollment['success'] ) || empty( $failed_first_enrollment['intake_state_restore']['success'] ) || $unenrolled_before !== $unenrolled_after_failure || $managed_before_receipt_failure !== $managed_fixture_menu_ids() ) { throw new RuntimeException( 'Failed first enrollment left staged menu or enrolled option state behind: ' . wp_json_encode( $failed_first_enrollment ) ); }
	foreach ( array( 'invalidation_fail' => 'public_header_cache_invalidation_failed', 'verification_fail' => 'public_header_projection_verification_failed', 'retirement_fail' => 'public_header_projection_retirement_failed', 'rollback_cache_fail' => 'public_header_projection_severe_rollback_failure' ) as $first_failure_mode => $expected_activation_code ) {
		$managed_before_phase = $managed_fixture_menu_ids();
		$failure_mode = $first_failure_mode;
		if ( 'verification_fail' === $failure_mode ) { $verification_fault_remaining = 1; $verification_fault_revision = (string) ( $enrollment_draft['draft']['revision'] ?? '' ); }
		$failed_phase_enrollment = $call( 'enroll_public_header_from_existing_menus', array( 'source_menu_id' => $first_enrollment_source_menu_id, 'activate' => true, 'timeout' => 5 ) );
		$phase_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
		if ( 'rollback_cache_fail' === $first_failure_mode ) {
			$referenced_ids = array(); foreach ( (array) $phase_after['identities'] as $identity ) { $menu_id = absint( is_array( $identity ) ? ( $identity['menu_id'] ?? 0 ) : 0 ); if ( $menu_id > 0 ) { $referenced_ids[] = $menu_id; } } $referenced_ids = array_values( array_unique( $referenced_ids ) ); sort( $referenced_ids );
			$projection_ids = array(); foreach ( (array) ( $failed_phase_enrollment['activation']['projections'] ?? array() ) as $projection ) { $menu_id = absint( $projection['target_menu']['id'] ?? 0 ); if ( $menu_id > 0 ) { $projection_ids[] = $menu_id; } } $projection_ids = array_values( array_unique( $projection_ids ) ); sort( $projection_ids );
			$intake_restore = (array) ( $failed_phase_enrollment['intake_state_restore'] ?? array() );
			$severe_assertions = array(
				'activation_severe' => 'public_header_projection_severe_rollback_failure' === (string) ( $failed_phase_enrollment['activation']['code'] ?? '' ),
				'outer_critical' => 'critical' === (string) ( $failed_phase_enrollment['severity'] ?? '' ),
				'outer_restore_failed' => 'public_header_enrollment_restore_failed' === (string) ( $failed_phase_enrollment['code'] ?? '' ),
				'severe_not_bypassed' => 'public_header_enrollment_severe_rollback_not_bypassed' === (string) ( $intake_restore['transaction']['code'] ?? '' ),
				'owned_staging_receipt_valid' => ! empty( $intake_restore['owned_staging_receipt_valid'] ),
				'current_is_owned_staging' => ! empty( $intake_restore['current_is_owned_staging'] ),
				'cleanup_complete' => ! empty( $intake_restore['cleanup']['success'] ),
				'projection_set_nonempty' => ! empty( $projection_ids ),
				'owned_staging_has_no_identity_references' => empty( $referenced_ids ),
				'zero_new_managed_after_cleanup' => $managed_before_phase === $managed_fixture_menu_ids(),
			);
			if ( in_array( false, $severe_assertions, true ) ) { throw new RuntimeException( 'Severe owned-staging cleanup assertion failed: ' . wp_json_encode( array( 'assertions' => $severe_assertions, 'referenced_ids' => $referenced_ids, 'projection_ids' => $projection_ids, 'activation_code' => (string) ( $failed_phase_enrollment['activation']['code'] ?? '' ), 'outer_code' => (string) ( $failed_phase_enrollment['code'] ?? '' ), 'outer_severity' => (string) ( $failed_phase_enrollment['severity'] ?? '' ), 'restore_code' => (string) ( $intake_restore['transaction']['code'] ?? '' ), 'cleanup_code' => (string) ( $intake_restore['cleanup']['code'] ?? '' ) ) ) ); }
			foreach ( array( 'manifest' => 'devenia_workflow_public_header_manifest', 'identities' => 'devenia_workflow_localized_menu_identities', 'pending' => 'devenia_workflow_pending_public_header_manifest', 'enrollment' => 'devenia_workflow_public_header_enrollment' ) as $slot => $option_key ) { '__workflow_missing__' === $unenrolled_before[ $slot ] ? delete_option( $option_key ) : update_option( $option_key, $unenrolled_before[ $slot ], false ); }
			continue;
		}
		if ( ! empty( $failed_phase_enrollment['success'] ) || $expected_activation_code !== (string) ( $failed_phase_enrollment['activation']['code'] ?? '' ) || empty( $failed_phase_enrollment['intake_state_restore']['success'] ) || $unenrolled_before !== $phase_after || $managed_before_phase !== $managed_fixture_menu_ids() ) { throw new RuntimeException( 'First enrollment did not recover the ' . $first_failure_mode . ' phase without an orphan staged menu: ' . wp_json_encode( $failed_phase_enrollment ) ); }
	}
	$failure_mode = '';
	$first_enrollment = $call( 'enroll_public_header_from_existing_menus', array( 'source_menu_id' => $first_enrollment_source_menu_id, 'activate' => true, 'timeout' => 5 ) );
	if ( empty( $first_enrollment['success'] ) || empty( $first_enrollment['activated'] ) || empty( $first_enrollment['activation']['verification']['passed'] ) || 2 !== (int) ( get_option( 'devenia_workflow_public_header_manifest', array() )['schema_version'] ?? 0 ) ) { throw new RuntimeException( 'First enrollment did not activate the exact complete schema-2 projection: ' . wp_json_encode( $first_enrollment ) ); }
	foreach ( (array) ( $first_enrollment['activation']['projections'] ?? array() ) as $projection ) { $menus[] = absint( $projection['target_menu']['id'] ?? 0 ); }
	$content_translation_id = (int) $translated[ $targets[0] ]['home']; $content_original = get_post( $content_translation_id ); $content_original_status_meta = get_post_meta( $content_translation_id, '_devenia_translation_status', true );
	foreach ( array( 'rollback_confirmed', 'invalid_applied', 'applied_error', 'unknown_applied', 'applied_foreign', 'unknown_foreign' ) as $content_mode ) {
		wp_update_post( array( 'ID' => $content_translation_id, 'post_status' => 'draft', 'post_excerpt' => 'Content commit baseline ' . $content_mode ) ); update_post_meta( $content_translation_id, '_devenia_translation_status', 'draft' ); clean_post_cache( $content_translation_id );
		$content_before_revision = (string) $call( 'translation_job_rollback_cas_revision', $content_translation_id, array(), array() ); $content_commit_mode = $content_mode; $content_commit_foreign_revision = '';
		$content_header_before = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
		$content_sync_menu = in_array( $content_mode, array( 'applied_error', 'unknown_applied' ), true );
		$content_attempt = $call( 'publish_localized_presentation', array( 'translation_id' => $content_translation_id, 'language' => $targets[0], 'source_id' => $source_home, 'job_id' => 'content_commit_' . $content_mode . '_' . $token, 'rollback_term_scope' => array(), 'rollback_identity_scope' => array(), 'expected_mutation_cas_revision' => $content_before_revision, 'recover_staged_mutation' => false, 'sync_menu' => $content_sync_menu, 'live_verification_timeout' => 5 ) );
		$content_commit_mode = ''; clean_post_cache( $content_translation_id ); $content_observed_revision = (string) $call( 'translation_job_rollback_cas_revision', $content_translation_id, array(), array() );
		$content_header_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
			if ( 'rollback_confirmed' === $content_mode ) {
				if ( false !== ( $content_attempt['published'] ?? null ) || 'publication_transaction_commit_rolled_back' !== (string) ( $content_attempt['code'] ?? '' ) || $content_before_revision !== $content_observed_revision || $content_before_revision !== (string) ( $content_attempt['mutation_cas_revision'] ?? '' ) || $content_header_before !== $content_header_after ) { throw new RuntimeException( 'Content commit rollback was not proven unapplied without header mutation: ' . wp_json_encode( $content_attempt ) ); }
			} elseif ( 'invalid_applied' === $content_mode ) {
				$invalid_content_reconciliation = (array) ( $content_attempt['commit_reconciliation'] ?? array() );
				$invalid_content_validation = (array) ( $invalid_content_reconciliation['receipt_validation'] ?? array() );
				$invalid_content_terminalization = (array) ( $invalid_content_validation['terminalization'] ?? array() );
				if (
					! empty( $content_attempt['success'] )
					|| ! array_key_exists( 'published', $content_attempt )
					|| null !== $content_attempt['published']
					|| 'publication_transaction_commit_receipt_invalid' !== (string) ( $content_attempt['code'] ?? '' )
					|| 'critical' !== (string) ( $content_attempt['severity'] ?? '' )
					|| 'invalid_receipt' !== (string) ( $invalid_content_reconciliation['state_outcome'] ?? '' )
					|| array_key_exists( 'committed', (array) ( $content_attempt['transaction_commit'] ?? array() ) )
					|| ! in_array( 'missing_committed', (array) ( $invalid_content_validation['violations'] ?? array() ), true )
					|| 'transaction_not_owned' !== (string) ( $invalid_content_terminalization['code'] ?? '' )
					|| empty( $invalid_content_reconciliation['replacement_exact'] )
					|| $content_observed_revision !== (string) ( $invalid_content_reconciliation['replacement_revision'] ?? '' )
					|| $content_observed_revision !== (string) ( $content_attempt['observed_mutation_cas_revision'] ?? '' )
					|| '' !== (string) ( $content_attempt['mutation_cas_revision'] ?? '' )
					|| false !== ( $content_attempt['rollback_authorized'] ?? null )
					|| 'publish' !== (string) get_post_status( $content_translation_id )
					|| 'published' !== (string) get_post_meta( $content_translation_id, '_devenia_translation_status', true )
					|| array_key_exists( 'menu', $content_attempt )
					|| array_key_exists( 'verification', $content_attempt )
					|| $content_header_before !== $content_header_after
				) { throw new RuntimeException( 'Malformed localized-content COMMIT receipt did not preserve the exact applied surface while denying success, rollback authority, and header continuation: ' . wp_json_encode( $content_attempt ) ); }
			} elseif ( in_array( $content_mode, array( 'applied_foreign', 'unknown_foreign' ), true ) ) {
				if ( ! array_key_exists( 'published', $content_attempt ) || null !== $content_attempt['published'] || 'critical' !== (string) ( $content_attempt['severity'] ?? '' ) || 'publication_transaction_commit_reconciliation_conflict' !== (string) ( $content_attempt['code'] ?? '' ) || $content_commit_foreign_revision !== $content_observed_revision || $content_observed_revision !== (string) ( $content_attempt['observed_mutation_cas_revision'] ?? '' ) || '' !== (string) ( $content_attempt['mutation_cas_revision'] ?? '' ) || false !== ( $content_attempt['rollback_authorized'] ?? null ) || 'foreign' !== (string) ( $content_attempt['commit_reconciliation']['state_outcome'] ?? '' ) || $content_header_before !== $content_header_after ) { throw new RuntimeException( 'Foreign content commit state was overwritten, promoted to rollback authority, misreported, or allowed to mutate the header: ' . wp_json_encode( $content_attempt ) ); }
		} elseif ( true !== ( $content_attempt['published'] ?? false ) || $content_observed_revision !== (string) ( $content_attempt['mutation_cas_revision'] ?? '' ) || true !== ( $content_attempt['rollback_authorized'] ?? null ) || $content_observed_revision !== (string) ( $content_attempt['rollback_expected_surface_revision'] ?? '' ) || 'applied' !== (string) ( $content_attempt['commit_reconciliation']['state_outcome'] ?? '' ) || empty( $content_attempt['menu']['success'] ) || empty( $content_attempt['menu']['manifest_staging']['success'] ) || empty( $content_attempt['menu']['verification']['passed'] ) || (string) ( $content_header_before['manifest']['revision'] ?? '' ) !== (string) ( $content_header_after['manifest']['revision'] ?? '' ) || (string) ( $content_header_after['manifest']['revision'] ?? '' ) !== (string) ( $content_header_after['pending']['revision'] ?? '' ) ) { throw new RuntimeException( 'Applied content commit did not refresh and verify the stable header set with its owned mutation receipt for ' . $content_mode . ': ' . wp_json_encode( array( 'code' => $content_attempt['code'] ?? '', 'published' => $content_attempt['published'] ?? null, 'menu_success' => $content_attempt['menu']['success'] ?? null, 'manifest_staging_success' => $content_attempt['menu']['manifest_staging']['success'] ?? null, 'verification_passed' => $content_attempt['menu']['verification']['passed'] ?? null, 'observed_receipt' => $content_observed_revision, 'rollback_authorized' => $content_attempt['rollback_authorized'] ?? null, 'rollback_receipt' => $content_attempt['rollback_expected_surface_revision'] ?? '', 'before_manifest_revision' => $content_header_before['manifest']['revision'] ?? '', 'after_manifest_revision' => $content_header_after['manifest']['revision'] ?? '', 'after_pending_revision' => $content_header_after['pending']['revision'] ?? '' ) ) ); }
		if ( $content_sync_menu ) { foreach ( (array) ( $content_attempt['menu']['projections'] ?? array() ) as $projection ) { $menus[] = absint( $projection['target_menu']['id'] ?? 0 ); } }
	}
	if ( $content_original instanceof WP_Post ) { wp_update_post( array( 'ID' => $content_translation_id, 'post_status' => $content_original->post_status, 'post_excerpt' => $content_original->post_excerpt ) ); } update_post_meta( $content_translation_id, '_devenia_translation_status', $content_original_status_meta ); clean_post_cache( $content_translation_id ); $call( 'sync_translation_index_row', $content_translation_id );
	delete_option( 'devenia_workflow_public_header_manifest' ); delete_option( 'devenia_workflow_pending_public_header_manifest' ); delete_option( 'devenia_workflow_localized_menu_identities' ); delete_option( 'devenia_workflow_public_header_enrollment' );
	$enrollment_locations['primary'] = (int) $raw_menu; set_theme_mod( 'nav_menu_locations', $enrollment_locations );
	$pre_enrollment_markup = (string) wp_nav_menu( array( 'theme_location' => 'primary', 'container' => false, 'echo' => false, 'fallback_cb' => false, 'items_wrap' => '%3$s' ) );
	if ( false === strpos( $pre_enrollment_markup, 'Raw drift' ) ) { throw new RuntimeException( 'One-time pre-enrollment rendering did not preserve the existing primary menu.' ); }
	$pending_before_missing_label = get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' );
	$missing_label_items = $manifest_items( 'Missing' ); unset( $missing_label_items[0]['labels'][ $targets[0] ] );
	$missing_label_authority = $call( 'update_public_header_manifest', array( 'items' => $missing_label_items ) );
	if ( 'public_header_label_authority_missing' !== (string) ( $missing_label_authority['code'] ?? '' ) || $pending_before_missing_label !== get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ) ) { throw new RuntimeException( 'Missing editorial label authority did not preserve pending state byte-exact.' ); }
	$stage_a = $call( 'update_public_header_manifest', array( 'items' => $manifest_items( 'Active' ) ) );
	$activate_a = $call( 'sync_public_header_projection', array( 'timeout' => 5 ) );
	if ( empty( $stage_a['pending'] ) || empty( $activate_a['success'] ) ) { throw new RuntimeException( 'Initial complete-set activation failed: ' . wp_json_encode( $activate_a ) ); }
	foreach ( (array) $activate_a['projections'] as $projection ) { $menus[] = absint( $projection['target_menu']['id'] ?? 0 ); }
	$active_a = get_option( 'devenia_workflow_public_header_manifest', array() ); $active_a_revision = (string) ( $active_a['revision'] ?? '' );
	$source_args = apply_filters( 'wp_nav_menu_args', array( 'theme_location' => 'primary' ) );
	if ( absint( $source_args['menu'] ?? 0 ) !== absint( $activate_a['projections'][ $source_language ]['target_menu']['id'] ?? 0 ) ) { throw new RuntimeException( 'wp_nav_menu_args did not select the managed source projection.' ); }
	$managed_source_markup = (string) wp_nav_menu( array( 'theme_location' => 'primary', 'container' => false, 'echo' => false, 'fallback_cb' => false, 'items_wrap' => '%3$s' ) );
	if ( false === strpos( $managed_source_markup, 'Active short home' ) || false === strpos( $managed_source_markup, 'Active short help' ) || false !== strpos( $managed_source_markup, 'Source home ' . $token ) || false !== strpos( $managed_source_markup, 'Raw drift' ) ) { throw new RuntimeException( 'Real theme_location rendering did not preserve exact source editorial labels.' ); }
	foreach ( $all_languages as $language ) {
		$projection_id = absint( $activate_a['projections'][ $language ]['target_menu']['id'] ?? 0 );
		$projection_items = wp_get_nav_menu_items( $projection_id, array( 'orderby' => 'menu_order' ) ) ?: array();
		$projection_by_source = array();
		foreach ( $projection_items as $projection_item ) { $projection_by_source[ absint( get_post_meta( (int) $projection_item->ID, '_devenia_translation_source_menu_item_id', true ) ) ] = $projection_item; }
		$expected_home_labels = $editorial_labels( 'Active', 'home' );
		$expected_help_labels = $editorial_labels( 'Active', 'help' );
		$expected_blog_labels = $editorial_labels( 'Active', 'blog' );
		if ( (string) ( $projection_by_source[800001]->title ?? '' ) !== (string) $expected_home_labels[ $language ] || (string) ( $projection_by_source[800003]->title ?? '' ) !== (string) $expected_help_labels[ $language ] || (string) ( $projection_by_source[800002]->title ?? '' ) !== (string) $expected_blog_labels[ $language ] || absint( $projection_by_source[800003]->menu_item_parent ?? 0 ) !== absint( $projection_by_source[800001]->ID ?? 0 ) ) { throw new RuntimeException( 'Stable source-item label authority or custom parent hierarchy was not preserved for ' . $language . '.' ); }
	}
	$valid_identities = get_option( 'devenia_workflow_localized_menu_identities', array() );
	$pending_before_active_label_rejection = get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' );
	$missing_active_label_items = $manifest_items( 'Rejected' ); unset( $missing_active_label_items[1]['labels'][ $targets[0] ] );
	$missing_active_label_authority = $call( 'update_public_header_manifest', array( 'items' => $missing_active_label_items ) );
	if ( 'public_header_label_authority_missing' !== (string) ( $missing_active_label_authority['code'] ?? '' ) || $active_a_revision !== (string) ( get_option( 'devenia_workflow_public_header_manifest', array() )['revision'] ?? '' ) || $valid_identities !== get_option( 'devenia_workflow_localized_menu_identities', array() ) || $pending_before_active_label_rejection !== get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ) ) { throw new RuntimeException( 'Missing target label authority changed the prior active projection set: ' . wp_json_encode( array( 'result' => $missing_active_label_authority, 'expected_revision' => $active_a_revision, 'actual_revision' => (string) ( get_option( 'devenia_workflow_public_header_manifest', array() )['revision'] ?? '' ), 'identities_equal' => $valid_identities === get_option( 'devenia_workflow_localized_menu_identities', array() ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__missing__' ) ) ) ); }
	delete_option( 'devenia_workflow_localized_menu_identities' );
	$missing_identity_before_verification = get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' );
	$missing_identity_verification = $call( 'localized_primary_navigation_html_issues', '<html><body><nav id="site-navigation">' . $managed_source_markup . '</nav></body></html>', $source_language, home_url( '/' ), 'origin' );
	$missing_identity_after_verification = get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' );
	$missing_args = apply_filters( 'wp_nav_menu_args', array( 'theme_location' => 'primary' ) );
	$missing_closed = apply_filters( 'pre_wp_nav_menu', null, (object) $missing_args );
	$missing_markup = (string) wp_nav_menu( array( 'theme_location' => 'primary', 'container' => false, 'echo' => false, 'fallback_cb' => false, 'items_wrap' => '%3$s' ) );
	if ( '' !== $missing_closed || '' !== $missing_markup || isset( $missing_args['menu'] ) || '__workflow_missing__' !== $missing_identity_before_verification || $missing_identity_before_verification !== $missing_identity_after_verification || 'frontend_primary_menu_identity_missing' !== (string) ( $missing_identity_verification[0]['code'] ?? '' ) ) { throw new RuntimeException( 'Missing managed identity changed during verification or exposed the raw theme menu after enrollment.' ); }
	update_option( 'devenia_workflow_localized_menu_identities', $valid_identities, false );
	$corrupt_identities = $valid_identities; $corrupt_identities[ $source_language ]['menu_id'] = (int) $raw_menu;
	update_option( 'devenia_workflow_localized_menu_identities', $corrupt_identities, false );
	$corrupt_identity_before_verification = get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' );
	$corrupt_identity_verification = $call( 'localized_primary_navigation_html_issues', '<html><body><nav id="site-navigation">' . $managed_source_markup . '</nav></body></html>', $source_language, home_url( '/' ), 'origin' );
	$corrupt_identity_after_verification = get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' );
	$corrupt_args = apply_filters( 'wp_nav_menu_args', array( 'theme_location' => 'primary' ) );
	$corrupt_closed = apply_filters( 'pre_wp_nav_menu', null, (object) $corrupt_args );
	$corrupt_markup = (string) wp_nav_menu( array( 'theme_location' => 'primary', 'container' => false, 'echo' => false, 'fallback_cb' => false, 'items_wrap' => '%3$s' ) );
	if ( '' !== $corrupt_closed || '' !== $corrupt_markup || isset( $corrupt_args['menu'] ) || $corrupt_identities !== $corrupt_identity_before_verification || $corrupt_identity_before_verification !== $corrupt_identity_after_verification || 'frontend_primary_menu_identity_missing' !== (string) ( $corrupt_identity_verification[0]['code'] ?? '' ) ) { throw new RuntimeException( 'Corrupt managed identity changed during verification or exposed the raw theme menu.' ); }
	update_option( 'devenia_workflow_localized_menu_identities', $valid_identities, false );
	$active_manifest_saved = get_option( 'devenia_workflow_public_header_manifest', array() );
	delete_option( 'devenia_workflow_public_header_manifest' );
	$missing_manifest_markup = (string) wp_nav_menu( array( 'theme_location' => 'primary', 'container' => false, 'echo' => false, 'fallback_cb' => false, 'items_wrap' => '%3$s' ) );
	if ( '' !== $missing_manifest_markup ) { throw new RuntimeException( 'Missing active manifest reopened the raw theme menu after durable enrollment.' ); }
	update_option( 'devenia_workflow_public_header_manifest', $active_manifest_saved, false );

	$stage_b = $call( 'update_public_header_manifest', array( 'items' => $manifest_items( 'Pending' ) ) );
	if ( empty( $stage_b['pending'] ) || $active_a_revision !== (string) ( get_option( 'devenia_workflow_public_header_manifest', array() )['revision'] ?? '' ) ) { throw new RuntimeException( 'Pending manifest changed active state before complete-set activation.' ); }
	$cancel_b = $call( 'update_public_header_manifest', array( 'items' => $manifest_items( 'Active' ) ) );
	if ( empty( $cancel_b['cancelled_pending'] ) || false !== get_option( 'devenia_workflow_pending_public_header_manifest', false ) ) { throw new RuntimeException( 'Restaging active revision did not cancel the different pending revision.' ); }
	$stage_b = $call( 'update_public_header_manifest', array( 'items' => $manifest_items( 'Pending' ) ) );
	if ( empty( $stage_b['pending'] ) ) { throw new RuntimeException( 'Could not restage the pending manifest after cancellation proof.' ); }
	$pending_source_args = apply_filters( 'wp_nav_menu_args', array( 'theme_location' => 'primary' ) );
	if ( absint( $pending_source_args['menu'] ?? 0 ) !== absint( $source_args['menu'] ?? 0 ) ) { throw new RuntimeException( 'Pending manifest displaced the old active source projection.' ); }
	$missing_source_page = $create_page( 'Untranslated manifest page ' . $token, 'untranslated-manifest-' . $token );
	$incomplete_items = $manifest_items( 'Incomplete' );
	$incomplete_items[] = array( 'source_item_id' => 800004, 'type' => 'page', 'title' => 'Missing short target', 'labels' => $editorial_labels( 'Missing', 'target' ), 'object_id' => $missing_source_page, 'parent_source_item_id' => 0, 'position' => 4 );
	$call( 'update_public_header_manifest', array( 'items' => $incomplete_items ) );
	$incomplete = $call( 'sync_public_header_projection', array( 'timeout' => 5 ) );
	if ( 'public_header_projection_staging_failed' !== (string) ( $incomplete['code'] ?? '' ) || 'public_header_projection_incomplete' !== (string) ( $incomplete['projection']['code'] ?? '' ) || $active_a_revision !== (string) ( get_option( 'devenia_workflow_public_header_manifest', array() )['revision'] ?? '' ) ) { throw new RuntimeException( 'Missing target translation did not fail the complete projection closed.' ); }
	$stage_b = $call( 'update_public_header_manifest', array( 'items' => $manifest_items( 'Pending' ) ) );
	foreach ( array( 'non_array', 'unterminated_success' ) as $unterminated_mode ) {
		$unterminated_state_before = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
		$managed_before_unterminated_receipt = $managed_fixture_menu_ids();
		$activation_commit_mode = $unterminated_mode;
		$activation_commit_injected = false;
		$unterminated_attempt = $call( 'sync_public_header_projection', array( 'timeout' => 5 ) );
		$activation_commit_mode = '';
		$unterminated_activation = (array) ( $unterminated_attempt['activation'] ?? array() );
		$unterminated_validation = (array) ( $unterminated_activation['receipt_validation'] ?? array() );
		$unterminated_terminalization = (array) ( $unterminated_validation['terminalization'] ?? array() );
		$unterminated_state_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
		$unterminated_projection_ids = array();
		foreach ( (array) ( $unterminated_attempt['projections'] ?? array() ) as $projection ) { $menu_id = absint( $projection['target_menu']['id'] ?? 0 ); if ( $menu_id > 0 ) { $unterminated_projection_ids[] = $menu_id; } }
		$unterminated_projection_ids = array_values( array_unique( $unterminated_projection_ids ) );
		$unterminated_projections_preserved = ! empty( $unterminated_projection_ids ) && empty( array_filter( $unterminated_projection_ids, static function ( int $menu_id ): bool { return ! wp_get_nav_menu_object( $menu_id ); } ) );
		$expected_unterminated_violations = 'non_array' === $unterminated_mode ? array( 'missing_committed', 'transaction_not_terminal' ) : array( 'transaction_not_terminal' );
		$receipt_shape_exact = 'non_array' === $unterminated_mode
			? ( 'recovery_commit_adapter_receipt_not_array' === (string) ( $unterminated_activation['commit']['code'] ?? '' ) && 'boolean' === (string) ( $unterminated_activation['commit']['adapter_receipt_type'] ?? '' ) && ! array_key_exists( 'committed', (array) ( $unterminated_activation['commit'] ?? array() ) ) )
			: ( true === ( $unterminated_activation['commit']['success'] ?? null ) && true === ( $unterminated_activation['commit']['committed'] ?? null ) && 'fixture_unterminated_success_receipt' === (string) ( $unterminated_activation['commit']['code'] ?? '' ) );
		if (
			! $activation_commit_injected
			|| ! empty( $unterminated_attempt['success'] )
			|| 'public_header_projection_activation_state_unresolved' !== (string) ( $unterminated_attempt['code'] ?? '' )
			|| 'critical' !== (string) ( $unterminated_attempt['severity'] ?? '' )
			|| 'public_header_state_commit_receipt_invalid' !== (string) ( $unterminated_activation['code'] ?? '' )
			|| 'invalid_receipt' !== (string) ( $unterminated_activation['state_outcome'] ?? '' )
			|| ! $receipt_shape_exact
			|| true !== ( $unterminated_validation['transaction_still_owned_at_boundary'] ?? null )
			|| $expected_unterminated_violations !== (array) ( $unterminated_validation['violations'] ?? array() )
			|| empty( $unterminated_terminalization['success'] )
			|| empty( $unterminated_terminalization['rolled_back'] )
			|| 'transaction_rolled_back' !== (string) ( $unterminated_terminalization['code'] ?? '' )
			|| empty( $unterminated_activation['expected_state_exact'] )
			|| ! empty( $unterminated_activation['replacement_state_exact'] )
			|| 'expected' !== (string) ( $unterminated_activation['state_class'] ?? '' )
			|| $call( 'translation_job_canonicalize', $unterminated_state_before ) !== $call( 'translation_job_canonicalize', $unterminated_state_after )
			|| $call( 'translation_job_canonicalize', $unterminated_state_after ) !== $call( 'translation_job_canonicalize', (array) ( $unterminated_activation['current_state'] ?? array() ) )
			|| ! empty( $unterminated_attempt['cleanup_authority']['allowed'] )
			|| 'staged_projection_cleanup_not_authorized' !== (string) ( $unterminated_attempt['cleanup']['code'] ?? '' )
			|| array_key_exists( 'cache_invalidation', $unterminated_attempt )
			|| array_key_exists( 'verification', $unterminated_attempt )
			|| array_key_exists( 'retirement', $unterminated_attempt )
			|| ! $unterminated_projections_preserved
		) { throw new RuntimeException( 'Non-terminal Public Header Adapter receipt escaped strict rollback or forward-progress guards for ' . $unterminated_mode . ': ' . wp_json_encode( $unterminated_attempt ) ); }
		$unterminated_cleanup_authority = $call( 'public_header_staged_cleanup_state_authority', $unterminated_state_after, false );
		$unterminated_fixture_cleanup = $call( 'delete_staged_public_header_projections', (array) ( $unterminated_attempt['projections'] ?? array() ), $unterminated_cleanup_authority );
		if ( empty( $unterminated_fixture_cleanup['success'] ) || $managed_before_unterminated_receipt !== $managed_fixture_menu_ids() ) { throw new RuntimeException( 'Explicit fixture cleanup failed after strict non-terminal receipt proof for ' . $unterminated_mode . ': ' . wp_json_encode( $unterminated_fixture_cleanup ) ); }
	}

	$assert_rolled_back = static function ( array $attempt, string $expected_code ) use ( $active_a_revision ): void { if ( $expected_code !== (string) ( $attempt['code'] ?? '' ) || $active_a_revision !== (string) ( get_option( 'devenia_workflow_public_header_manifest', array() )['revision'] ?? '' ) || empty( $attempt['rollback_cache_invalidation']['success'] ) || empty( $attempt['rollback_verification']['passed'] ) ) { throw new RuntimeException( 'Cache-safe rollback assertion failed: ' . wp_json_encode( $attempt ) ); } };
	$failure_mode = 'receipt_fail'; $receipt_fail = $call( 'sync_public_header_projection', array( 'timeout' => 5 ) );
	if ( 'public_header_projection_staging_failed' !== (string) ( $receipt_fail['code'] ?? '' ) || $active_a_revision !== (string) ( get_option( 'devenia_workflow_public_header_manifest', array() )['revision'] ?? '' ) ) { throw new RuntimeException( 'Receipt failure activated state.' ); }
	$failure_mode = 'staged_revision_change'; $failure_injected = false; $staged_race_menu_id = 0; $staged_race = $call( 'sync_public_header_projection', array( 'timeout' => 5 ) );
	if ( ! $failure_injected || 'public_header_projection_activation_cleanup_failed' !== (string) ( $staged_race['code'] ?? '' ) || 'critical' !== (string) ( $staged_race['severity'] ?? '' ) || 'public_header_staged_receipt_changed' !== (string) ( $staged_race['activation']['code'] ?? '' ) || 'staged_projection_cleanup_incomplete' !== (string) ( $staged_race['cleanup']['code'] ?? '' ) || false === strpos( wp_json_encode( $staged_race['cleanup']['results'] ?? array() ), 'staged_menu_receipt_mismatch' ) || $active_a_revision !== (string) ( get_option( 'devenia_workflow_public_header_manifest', array() )['revision'] ?? '' ) ) { throw new RuntimeException( 'Changed staged term revision did not become a structured critical cleanup failure.' ); }
	$pending_race_before = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) ); $managed_before_pending_race = $managed_fixture_menu_ids();
	$failure_mode = 'pending_race'; $failure_injected = false; $pending_race = $call( 'sync_public_header_projection', array( 'timeout' => 5 ) );
	$pending_race_after = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) ); $pending_projection_ids = array(); $pending_projection_set_preserved = true; foreach ( (array) ( $pending_race['projections'] ?? array() ) as $projection ) { $menu_id = absint( $projection['target_menu']['id'] ?? 0 ); if ( $menu_id < 1 || ! wp_get_nav_menu_object( $menu_id ) ) { $pending_projection_set_preserved = false; } else { $pending_projection_ids[] = $menu_id; } }
	if ( ! $failure_injected || 'public_header_projection_activation_state_unresolved' !== (string) ( $pending_race['code'] ?? '' ) || 'critical' !== (string) ( $pending_race['severity'] ?? '' ) || 'public_header_state_changed' !== (string) ( $pending_race['activation']['code'] ?? '' ) || 'pending' !== (string) ( $pending_race['activation']['slot'] ?? '' ) || ! empty( $pending_race['cleanup_authority']['allowed'] ) || $pending_race_before === $pending_race_after || empty( $pending_projection_ids ) || ! $pending_projection_set_preserved || $active_a_revision !== (string) ( $pending_race_after['manifest']['revision'] ?? '' ) ) { throw new RuntimeException( 'Pending manifest race was not preserved as a foreign critical state with its staged projections intact: ' . wp_json_encode( $pending_race ) ); }
	foreach ( array( 'manifest' => 'devenia_workflow_public_header_manifest', 'pending' => 'devenia_workflow_pending_public_header_manifest', 'identities' => 'devenia_workflow_localized_menu_identities', 'enrollment' => 'devenia_workflow_public_header_enrollment' ) as $slot => $option_key ) { '__workflow_missing__' === $pending_race_before[ $slot ] ? delete_option( $option_key ) : update_option( $option_key, $pending_race_before[ $slot ], false ); }
	$pending_cleanup_authority = $call( 'public_header_staged_cleanup_state_authority', $pending_race_before, '__workflow_missing__' === ( $pending_race_before['identities'] ?? null ) );
	$cleanup_race_mode = 'identity_reference'; $cleanup_race_injected = false; $cleanup_race_menu_id = 0;
	$identity_race_cleanup = $call( 'delete_staged_public_header_projections', (array) ( $pending_race['projections'] ?? array() ), $pending_cleanup_authority );
	$cleanup_race_mode = '';
	$state_after_identity_race = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__workflow_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__workflow_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__workflow_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__workflow_missing__' ) );
	$identity_race_menus_preserved = ! empty( $pending_projection_ids ) && empty( array_filter( $pending_projection_ids, static function ( int $menu_id ): bool { return ! wp_get_nav_menu_object( $menu_id ); } ) );
	if ( ! $cleanup_race_injected || $cleanup_race_menu_id < 1 || 'staged_projection_cleanup_identity_changed' !== (string) ( $identity_race_cleanup['code'] ?? '' ) || 'critical' !== (string) ( $identity_race_cleanup['severity'] ?? '' ) || ! $identity_race_menus_preserved || $call( 'translation_job_canonicalize', $pending_race_before ) !== $call( 'translation_job_canonicalize', $state_after_identity_race ) || empty( $identity_race_cleanup['transaction_rollback']['success'] ) ) { throw new RuntimeException( 'Concurrent identity-reference acquisition did not roll back before any staged-menu deletion: ' . wp_json_encode( array( 'cleanup' => $identity_race_cleanup, 'state_after' => $state_after_identity_race, 'menu_ids' => $pending_projection_ids ) ) ); }
	$pending_race_fixture_cleanup = $call( 'delete_staged_public_header_projections', (array) ( $pending_race['projections'] ?? array() ), $pending_cleanup_authority );
	if ( empty( $pending_race_fixture_cleanup['success'] ) || $managed_before_pending_race !== $managed_fixture_menu_ids() ) { throw new RuntimeException( 'Pending race fixture could not restore its expected state and receipt-delete its now-unreferenced projections: ' . wp_json_encode( $pending_race_fixture_cleanup ) ); }
	$failure_mode = ''; $failure_injected = false; $call( 'update_public_header_manifest', array( 'items' => $manifest_items( 'Pending' ) ) );
	$failure_mode = 'invalidation_fail'; $invalidation_fail = $call( 'sync_public_header_projection', array( 'timeout' => 5 ) ); $assert_rolled_back( $invalidation_fail, 'public_header_cache_invalidation_failed' );
	$failure_mode = 'verification_fail'; $verification_fault_remaining = 1; $verification_fault_revision = (string) ( get_option( 'devenia_workflow_pending_public_header_manifest', array() )['revision'] ?? '' ); $verification_rollback_observations = array(); $verification_fail = $call( 'sync_public_header_projection', array( 'timeout' => 5 ) ); $assert_rolled_back( $verification_fail, 'public_header_projection_verification_failed' );
	$failure_mode = 'verification_extra_anchor'; $verification_fault_remaining = 1; $verification_fault_revision = (string) ( get_option( 'devenia_workflow_pending_public_header_manifest', array() )['revision'] ?? '' ); $extra_anchor_fail = $call( 'sync_public_header_projection', array( 'timeout' => 5 ) ); $assert_rolled_back( $extra_anchor_fail, 'public_header_projection_verification_failed' );
	$failure_mode = 'retirement_fail'; $retirement_fail = $call( 'sync_public_header_projection', array( 'timeout' => 5 ) ); $assert_rolled_back( $retirement_fail, 'public_header_projection_retirement_failed' );
	$failure_mode = 'rollback_cache_fail'; $rollback_cache_fail = $call( 'sync_public_header_projection', array( 'timeout' => 5 ) );
	if ( 'public_header_projection_severe_rollback_failure' !== (string) ( $rollback_cache_fail['code'] ?? '' ) || 'critical' !== (string) ( $rollback_cache_fail['severity'] ?? '' ) ) { throw new RuntimeException( 'Rollback cache failure was not a structured critical state.' ); }
	// Restore the authoritative pending manifest after the deliberately unproven rollback path.
	$failure_mode = ''; $success_b = $call( 'sync_public_header_projection', array( 'timeout' => 5 ) );
	if ( empty( $success_b['success'] ) ) { throw new RuntimeException( 'Final complete-set activation failed: ' . wp_json_encode( $success_b ) ); }
	foreach ( (array) $success_b['projections'] as $projection ) { $menus[] = absint( $projection['target_menu']['id'] ?? 0 ); }
	foreach ( (array) $success_b['verification']['items'] as $surface_set ) { foreach ( array( 'homepage', 'blog_archive' ) as $surface ) { if ( empty( $surface_set[ $surface ]['passed'] ) || ! isset( $surface_set[ $surface ]['cache_responses']['origin'], $surface_set[ $surface ]['cache_responses']['canonical'] ) ) { throw new RuntimeException( 'Origin/canonical surface evidence missing.' ); } } }
	$active_b_revision = (string) ( get_option( 'devenia_workflow_public_header_manifest', array() )['revision'] ?? '' );
	$call( 'update_public_header_manifest', array( 'items' => $manifest_items( 'Receipt guard' ) ) );
	$failure_mode = 'old_receipt_changed'; $failure_injected = false; $old_receipt_fail = $call( 'sync_public_header_projection', array( 'timeout' => 5 ) );
	$current_after_old_receipt = (string) ( get_option( 'devenia_workflow_public_header_manifest', array() )['revision'] ?? '' );
	if ( 'public_header_projection_severe_rollback_failure' !== (string) ( $old_receipt_fail['code'] ?? '' ) || 'public_header_staged_receipt_changed' !== (string) ( $old_receipt_fail['rollback']['code'] ?? '' ) || $active_b_revision === $current_after_old_receipt ) { throw new RuntimeException( 'Changed prior receipt was reactivated instead of leaving the verified new set active in a critical state: ' . wp_json_encode( array( 'attempt' => $old_receipt_fail, 'active_b_revision' => $active_b_revision, 'current_revision' => $current_after_old_receipt, 'failure_injected' => $failure_injected ) ) ); }

	$result = array( 'success' => true, 'duplicate_source_page_references_disambiguated_by_item_identity' => true, 'duplicate_target_page_references_disambiguated_by_stable_identity' => true, 'wrong_language_stable_identity_rejected' => true, 'duplicate_exact_stable_identity_ambiguous' => true, 'duplicate_stable_identity_rows_rejected' => true, 'foreign_stable_identity_cannot_fallback' => true, 'mixed_foreign_identity_cannot_hide_behind_legacy_fallback' => true, 'absent_stable_identity_legacy_relation_fallback_verified' => true, 'single_explicit_authority_not_auto_supplemented' => true, 'explicit_authority_sets_all_or_nothing' => true, 'complete_explicit_authority_isolated_from_retained_conflicts' => true, 'unenrolled_commit_outcomes_reconciled' => true, 'unenrolled_unknown_commit_outcome_structured_critical' => true, 'unenrolled_authority_draft_mutation_free' => true, 'unenrolled_unrelated_menus_ignored' => true, 'unenrolled_locked_primary_source_authority_races_rejected' => true, 'unenrolled_all_post_activation_failure_phases_recovered' => true, 'unenrolled_failure_restored_exact_option_state' => true, 'unenrolled_schema2_atomic_activation_verified' => true, 'schema1_managed_label_runtime_override_bypassed' => true, 'schema1_label_authority_conflict_failed_closed' => true, 'schema1_explicit_authority_sets_all_or_nothing' => true, 'schema1_authority_revision_race_rejected' => true, 'schema1_to_schema2_migration_draft_created' => true, 'schema1_relation_receipt_race_rejected_before_activation' => true, 'relation_authority_consumed_by_staging' => true, 'authority_receipts_not_persisted_in_active_manifest' => true, 'schema1_post_activation_rollback_verified' => true, 'schema1_to_schema2_repair_activated' => true, 'identity_migration_interface_capability_gated' => true, 'real_theme_location_pre_enrollment_preserved' => true, 'real_theme_location_managed_source_exercised' => true, 'wp_nav_menu_args_managed_source_exercised' => true, 'editorial_labels_bound_by_source_item_identity' => true, 'source_short_label_not_page_title' => true, 'target_editorial_label_not_translated_page_title' => true, 'custom_child_label_and_parent_preserved' => true, 'missing_label_authority_preserved_old_active_set' => true, 'managed_identity_missing_failed_closed' => true, 'managed_identity_corrupt_failed_closed' => true, 'managed_identity_missing_verification_failed_without_mutation' => true, 'managed_identity_corrupt_verification_failed_without_mutation' => true, 'missing_active_manifest_durable_enrollment_failed_closed' => true, 'pending_manifest_preserved_old_active_set' => true, 'active_restage_cancelled_stale_pending' => true, 'missing_target_projection_failed_closed' => true, 'all_language_atomic_activation_exercised' => true, 'pre_activation_receipt_failure_rejected' => true, 'staged_revision_race_rejected' => true, 'pending_manifest_race_rejected' => true, 'invalidation_failure_cache_safe_rollback' => true, 'idempotent_enrollment_transition_exercised' => true, 'verification_failure_cache_safe_rollback' => true, 'extra_anchor_verification_failed_closed' => true, 'retirement_failure_cache_safe_rollback' => true, 'rollback_cache_failure_structured_critical' => true, 'changed_old_receipt_never_reactivated' => true, 'source_and_targets_home_blog_origin_canonical_verified' => true );
	$result['unenrolled_raw_navigation_oracle_exact'] = true;
	$result['unenrolled_foreign_commit_state_preserved'] = true;
	$result['unenrolled_post_activation_foreign_state_preserved'] = true;
	$result['unenrolled_severe_rollback_reference_closure_proven'] = true;
	$result['activation_commit_tristate_cleanup_authority_proven'] = true;
	$result['successful_header_commits_reconciled_before_activation'] = true;
	$result['staged_cleanup_identity_reference_race_failed_closed'] = true;
	$result['content_publication_commit_tristate_proven'] = true;
	$result['content_publication_applied_commit_refreshed_verified_header'] = true;
	$result['pending_foreign_race_preserved_then_fixture_cleaned'] = true;
	$result['malformed_first_enrollment_commit_receipt_failed_closed'] = true;
	$result['malformed_header_activation_commit_receipt_failed_closed'] = true;
	$result['malformed_content_publication_commit_receipt_failed_closed'] = true;
	$result['non_array_header_commit_receipt_rolled_back_failed_closed'] = true;
	$result['unterminated_success_header_commit_receipt_rolled_back_failed_closed'] = true;
} catch ( Throwable $caught ) { $error = $caught; }
finally {
	foreach ( array_reverse( $filters ) as $filter ) { remove_filter( $filter[0], $filter[1], $filter[2] ); }
	foreach ( wp_get_nav_menus() as $menu ) { if ( is_object( $menu ) && false !== strpos( (string) $menu->name, $token ?? '__never__' ) ) { $menus[] = (int) $menu->term_id; } }
	foreach ( array_unique( $menus ) as $menu_id ) { if ( $menu_id ) { wp_delete_nav_menu( $menu_id ); } }
	foreach ( array_reverse( $posts ) as $post_id ) { $call( 'delete_translation_index_for_post', $post_id, get_post( $post_id ) ); wp_delete_post( $post_id, true ); }
	foreach ( $before as $key => $value ) { if ( '__workflow_missing__' === $value ) { delete_option( $key ); } else { update_option( $key, $value, false ); } }
	set_theme_mod( 'nav_menu_locations', is_array( $locations_before ) ? $locations_before : array() ); Devenia_Workflow::languages( true ); wp_set_current_user( $user_before );
}
if ( $error ) { fwrite( STDERR, $error->getMessage() . "\n" ); exit( 1 ); }
fwrite( STDOUT, wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
