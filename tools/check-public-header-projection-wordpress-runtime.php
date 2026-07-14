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

try {
	$admins = get_users( array( 'role__in' => array( 'administrator' ), 'number' => 1, 'fields' => 'ids' ) );
	if ( empty( $admins ) ) { throw new RuntimeException( 'Administrator fixture missing.' ); }
	wp_set_current_user( (int) $admins[0] );
	$frontend = static function (): bool { return true; };
	add_filter( 'devenia_workflow_is_frontend_runtime_request', $frontend, 10, 0 ); $filters[] = array( 'devenia_workflow_is_frontend_runtime_request', $frontend, 10 );

	$languages = Devenia_Workflow::languages( true );
	$source_language = (string) $call( 'source_language_code' );
	$targets = array_values( array_diff( array_keys( $languages ), array( $source_language ) ) );
	if ( empty( $targets ) ) { throw new RuntimeException( 'Target-language fixture missing.' ); }
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
			$menu_args = $source_language === $language
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
	$legacy_items = $manifest_items( 'Legacy' );
	foreach ( $legacy_items as &$legacy_item ) { unset( $legacy_item['labels'] ); }
	unset( $legacy_item );
	$legacy_revision = (string) $call( 'public_header_manifest_revision_for_items', $legacy_items );
	$legacy_manifest = array( 'schema_version' => 1, 'source_language' => $source_language, 'revision' => $legacy_revision, 'items' => $legacy_items, 'updated_at' => gmdate( 'c' ) );
	$legacy_identities = array();
	$conflicting_authority_menu_id = 0;
	foreach ( $all_languages as $language ) {
		$labels_home = $editorial_labels( 'Legacy', 'home' );
		$labels_help = $editorial_labels( 'Legacy', 'help' );
		$labels_blog = $editorial_labels( 'Legacy', 'blog' );
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
		$create_legacy_menu( 'Retained editorial ' . $language . ' ' . $token, false );
		if ( $language === $targets[0] ) {
			$conflicting_authority_menu_id = $create_legacy_menu( 'Conflicting editorial ' . $language . ' ' . $token, false );
			$conflicting_items = wp_get_nav_menu_items( $conflicting_authority_menu_id, array( 'orderby' => 'menu_order' ) ) ?: array();
			if ( empty( $conflicting_items ) || is_wp_error( wp_update_post( array( 'ID' => (int) $conflicting_items[0]->ID, 'post_title' => 'Conflicting retained label' ), true ) ) ) { throw new RuntimeException( 'Could not create conflicting retained-label fixture.' ); }
		}
		$managed_menu_id = $create_legacy_menu( 'Legacy managed ' . $language . ' ' . $token, true );
		$legacy_identities[ $language ] = array( 'menu_id' => $managed_menu_id, 'configured_name' => (string) $registry[ $language ]['menu_name'], 'manifest_revision' => $legacy_revision );
	}
	$legacy_pending = array( 'status' => 'activated', 'revision' => $legacy_revision, 'activated_at' => 'legacy-fixture' );
	update_option( 'devenia_workflow_public_header_manifest', $legacy_manifest, false );
	update_option( 'devenia_workflow_localized_menu_identities', $legacy_identities, false );
	update_option( 'devenia_workflow_pending_public_header_manifest', $legacy_pending, false );
	update_option( 'devenia_workflow_public_header_enrollment', '1', false );
	$runtime_registry = get_option( 'devenia_workflow_language_registry', array() );
	$runtime_registry[ $targets[0] ]['menu_items'][ (string) $source_home ] = 'Long translated page title that must never render';
	$runtime_registry[ $targets[0] ]['custom_menu_items']['Legacy short help'] = 'Long mutable custom replacement that must never render';
	update_option( 'devenia_workflow_language_registry', $runtime_registry, false ); Devenia_Workflow::languages( true );
	$request_before_legacy = $_SERVER['REQUEST_URI'] ?? '/'; $_SERVER['REQUEST_URI'] = '/' . sanitize_key( (string) ( $languages[ $targets[0] ]['prefix'] ?? $targets[0] ) ) . '/fixture/';
	$legacy_render = (string) wp_nav_menu( array( 'menu' => (int) $legacy_identities[ $targets[0] ]['menu_id'], 'container' => false, 'echo' => false, 'fallback_cb' => false, 'items_wrap' => '%3$s' ) );
	$_SERVER['REQUEST_URI'] = $request_before_legacy;
	if ( false === strpos( $legacy_render, 'Legacy ' . $targets[0] . ' editorial home' ) || false === strpos( $legacy_render, 'Legacy ' . $targets[0] . ' editorial help' ) || false !== strpos( $legacy_render, 'Long translated page title' ) || false !== strpos( $legacy_render, 'Long mutable custom replacement' ) ) { throw new RuntimeException( 'Selected managed schema-1 menu was relocalized after its signed stored labels became reader authority.' ); }
	$migration_conflict = $call( 'migrate_public_header_label_authority', array() );
	if ( 'public_header_label_authority_incomplete' !== (string) ( $migration_conflict['code'] ?? '' ) || false === strpos( wp_json_encode( $migration_conflict['missing'] ?? array() ), 'authority_candidate_conflict' ) || $legacy_pending !== get_option( 'devenia_workflow_pending_public_header_manifest', array() ) ) { throw new RuntimeException( 'Conflicting retained menu labels did not remain unresolved.' ); }
	wp_delete_nav_menu( $conflicting_authority_menu_id );
	$migration_draft = $call( 'migrate_public_header_label_authority', array() );
	if ( empty( $migration_draft['success'] ) || 2 !== (int) ( $migration_draft['draft']['schema_version'] ?? 0 ) || $legacy_pending !== get_option( 'devenia_workflow_pending_public_header_manifest', array() ) ) { throw new RuntimeException( 'Schema-1 label-authority migration draft failed or mutated pending state.' ); }
	$migration_stage = $call( 'migrate_public_header_label_authority', array( 'stage' => true ) );
	if ( empty( $migration_stage['staged'] ) ) { throw new RuntimeException( 'Schema-2 migration draft could not be staged.' ); }
	$migration_pending = get_option( 'devenia_workflow_pending_public_header_manifest', array() );
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
	if ( empty( $migration_stage['staged'] ) || empty( $migration_success['success'] ) || 2 !== (int) ( get_option( 'devenia_workflow_public_header_manifest', array() )['schema_version'] ?? 0 ) ) { throw new RuntimeException( 'Schema-1 editorial labels were not repaired through successful schema-2 activation.' ); }

	// Reset the fixture and retain the existing exhaustive clean-install tests.
	delete_option( 'devenia_workflow_public_header_manifest' ); delete_option( 'devenia_workflow_pending_public_header_manifest' ); delete_option( 'devenia_workflow_localized_menu_identities' ); delete_option( 'devenia_workflow_public_header_enrollment' );
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
	$missing_args = apply_filters( 'wp_nav_menu_args', array( 'theme_location' => 'primary' ) );
	$missing_closed = apply_filters( 'pre_wp_nav_menu', null, (object) $missing_args );
	$missing_markup = (string) wp_nav_menu( array( 'theme_location' => 'primary', 'container' => false, 'echo' => false, 'fallback_cb' => false, 'items_wrap' => '%3$s' ) );
	if ( '' !== $missing_closed || '' !== $missing_markup || isset( $missing_args['menu'] ) ) { throw new RuntimeException( 'Missing managed identity exposed the raw theme menu after enrollment.' ); }
	update_option( 'devenia_workflow_localized_menu_identities', $valid_identities, false );
	$corrupt_identities = $valid_identities; $corrupt_identities[ $source_language ]['menu_id'] = (int) $raw_menu;
	update_option( 'devenia_workflow_localized_menu_identities', $corrupt_identities, false );
	$corrupt_args = apply_filters( 'wp_nav_menu_args', array( 'theme_location' => 'primary' ) );
	$corrupt_closed = apply_filters( 'pre_wp_nav_menu', null, (object) $corrupt_args );
	$corrupt_markup = (string) wp_nav_menu( array( 'theme_location' => 'primary', 'container' => false, 'echo' => false, 'fallback_cb' => false, 'items_wrap' => '%3$s' ) );
	if ( '' !== $corrupt_closed || '' !== $corrupt_markup || isset( $corrupt_args['menu'] ) ) { throw new RuntimeException( 'Corrupt managed identity exposed the raw theme menu.' ); }
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

	$assert_rolled_back = static function ( array $attempt, string $expected_code ) use ( $active_a_revision ): void { if ( $expected_code !== (string) ( $attempt['code'] ?? '' ) || $active_a_revision !== (string) ( get_option( 'devenia_workflow_public_header_manifest', array() )['revision'] ?? '' ) || empty( $attempt['rollback_cache_invalidation']['success'] ) || empty( $attempt['rollback_verification']['passed'] ) ) { throw new RuntimeException( 'Cache-safe rollback assertion failed: ' . wp_json_encode( $attempt ) ); } };
	$failure_mode = 'receipt_fail'; $receipt_fail = $call( 'sync_public_header_projection', array( 'timeout' => 5 ) );
	if ( 'public_header_projection_staging_failed' !== (string) ( $receipt_fail['code'] ?? '' ) || $active_a_revision !== (string) ( get_option( 'devenia_workflow_public_header_manifest', array() )['revision'] ?? '' ) ) { throw new RuntimeException( 'Receipt failure activated state.' ); }
	$failure_mode = 'staged_revision_change'; $failure_injected = false; $staged_race_menu_id = 0; $staged_race = $call( 'sync_public_header_projection', array( 'timeout' => 5 ) );
	if ( ! $failure_injected || 'public_header_projection_activation_failed' !== (string) ( $staged_race['code'] ?? '' ) || 'public_header_staged_receipt_changed' !== (string) ( $staged_race['activation']['code'] ?? '' ) || $active_a_revision !== (string) ( get_option( 'devenia_workflow_public_header_manifest', array() )['revision'] ?? '' ) ) { throw new RuntimeException( 'Changed staged term revision did not block atomic activation.' ); }
	$failure_mode = 'pending_race'; $failure_injected = false; $pending_race = $call( 'sync_public_header_projection', array( 'timeout' => 5 ) );
	if ( ! $failure_injected || 'public_header_projection_activation_failed' !== (string) ( $pending_race['code'] ?? '' ) || 'public_header_state_changed' !== (string) ( $pending_race['activation']['code'] ?? '' ) || 'pending' !== (string) ( $pending_race['activation']['slot'] ?? '' ) || $active_a_revision !== (string) ( get_option( 'devenia_workflow_public_header_manifest', array() )['revision'] ?? '' ) ) { throw new RuntimeException( 'Pending manifest race did not block the locked atomic activation: ' . wp_json_encode( $pending_race ) ); }
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

	$result = array( 'success' => true, 'schema1_managed_label_runtime_override_bypassed' => true, 'schema1_label_authority_conflict_failed_closed' => true, 'schema1_to_schema2_migration_draft_created' => true, 'schema1_post_activation_rollback_verified' => true, 'schema1_to_schema2_repair_activated' => true, 'real_theme_location_pre_enrollment_preserved' => true, 'real_theme_location_managed_source_exercised' => true, 'wp_nav_menu_args_managed_source_exercised' => true, 'editorial_labels_bound_by_source_item_identity' => true, 'source_short_label_not_page_title' => true, 'target_editorial_label_not_translated_page_title' => true, 'custom_child_label_and_parent_preserved' => true, 'missing_label_authority_preserved_old_active_set' => true, 'managed_identity_missing_failed_closed' => true, 'managed_identity_corrupt_failed_closed' => true, 'missing_active_manifest_durable_enrollment_failed_closed' => true, 'pending_manifest_preserved_old_active_set' => true, 'active_restage_cancelled_stale_pending' => true, 'missing_target_projection_failed_closed' => true, 'all_language_atomic_activation_exercised' => true, 'pre_activation_receipt_failure_rejected' => true, 'staged_revision_race_rejected' => true, 'pending_manifest_race_rejected' => true, 'invalidation_failure_cache_safe_rollback' => true, 'idempotent_enrollment_transition_exercised' => true, 'verification_failure_cache_safe_rollback' => true, 'extra_anchor_verification_failed_closed' => true, 'retirement_failure_cache_safe_rollback' => true, 'rollback_cache_failure_structured_critical' => true, 'changed_old_receipt_never_reactivated' => true, 'source_and_targets_home_blog_origin_canonical_verified' => true );
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
