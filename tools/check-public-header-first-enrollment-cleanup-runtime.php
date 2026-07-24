<?php
/** Focused WordPress regression for receipt-owned first-enrollment cleanup. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$option_keys = array(
	'devenia_workflow_language_registry',
	'devenia_workflow_localized_menu_identities',
	'devenia_workflow_public_header_manifest',
	'devenia_workflow_pending_public_header_manifest',
	'devenia_workflow_public_header_enrollment',
	'devenia_workflow_public_header_transition',
);
$before = array();
foreach ( $option_keys as $key ) { $before[ $key ] = get_option( $key, '__workflow_missing__' ); }
$locations_before = get_theme_mod( 'nav_menu_locations', array() );
$user_before = get_current_user_id();
$request_uri_before = $_SERVER['REQUEST_URI'] ?? null;
$menus = array();
$projection_ids = array();
$filters = array();
$result = null;
$error = null;

try {
	$admins = get_users( array( 'role__in' => array( 'administrator' ), 'number' => 1, 'fields' => 'ids' ) );
	if ( empty( $admins ) ) { throw new RuntimeException( 'Administrator fixture missing.' ); }
	$admin_id = (int) $admins[0];
	wp_set_current_user( $admin_id );
	$frontend = static function (): bool { return true; };
	add_filter( 'devenia_workflow_is_frontend_runtime_request', $frontend, 10, 0 );
	$filters[] = array( 'devenia_workflow_is_frontend_runtime_request', $frontend, 10 );

	$languages = Devenia_Workflow::languages( true );
	$source_languages = array_keys( array_filter( $languages, static function ( array $config ): bool { return ! empty( $config['source'] ); } ) );
	$source_language = 1 === count( $source_languages ) ? sanitize_key( (string) $source_languages[0] ) : '';
	$targets = array_values( array_diff( array_keys( $languages ), array( $source_language ) ) );
	if ( '' === $source_language || empty( $targets ) ) { throw new RuntimeException( 'Configured source and target languages are required.' ); }
	$token = strtolower( wp_generate_password( 8, false, false ) );
	$source_blog_id = absint( get_option( 'page_for_posts' ) );
	if ( $source_blog_id < 1 ) { throw new RuntimeException( 'The source posts page fixture is required.' ); }
	foreach ( $targets as $language ) {
		$existing_blog = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_devenia_translation_source_id', 'value' => $source_blog_id ), array( 'key' => '_devenia_translation_language', 'value' => $language ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded dev-only fixture prerequisite.
		if ( empty( $existing_blog ) || '' === trim( (string) wp_parse_url( (string) get_permalink( (int) $existing_blog[0] ), PHP_URL_PATH ), '/' ) ) { throw new RuntimeException( 'Focused runtime requires one pre-bootstrap published blog-route fixture for ' . $language . '.' ); }
	}
	$create_menu = static function ( string $name ) use ( &$menus ): int {
		$id = wp_create_nav_menu( $name );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
		$menus[] = (int) $id;
		return (int) $id;
	};
	$fixture_url = 'https://example.org/focused-' . $token . '/';
	$source_menu = $create_menu( 'Focused source ' . $token );
	$source_item = wp_update_nav_menu_item( $source_menu, 0, array( 'menu-item-title' => 'Focused source', 'menu-item-url' => $fixture_url, 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) );
	if ( is_wp_error( $source_item ) ) { throw new RuntimeException( $source_item->get_error_message() ); }
	$authority_menus = array();
	foreach ( $targets as $language ) {
		foreach ( array( 'a', 'b' ) as $candidate ) {
			$menu_id = $create_menu( 'Focused ' . $language . ' ' . $candidate . ' ' . $token );
			$item_id = wp_update_nav_menu_item( $menu_id, 0, array( 'menu-item-title' => 'Focused ' . $language, 'menu-item-url' => $fixture_url, 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) );
			if ( is_wp_error( $item_id ) ) { throw new RuntimeException( $item_id->get_error_message() ); }
			update_post_meta( (int) $item_id, '_devenia_translation_source_menu_item_id', (int) $source_item );
			$authority_menus[] = array( 'language' => $language, 'menu_id' => $menu_id );
		}
	}
	$registry = is_array( $before['devenia_workflow_language_registry'] ) ? $before['devenia_workflow_language_registry'] : array();
	foreach ( array_merge( array( $source_language ), $targets ) as $language ) { $registry[ $language ]['menu_name'] = 'Focused managed ' . $language . ' ' . $token; }
	update_option( 'devenia_workflow_language_registry', $registry, false );
	Devenia_Workflow::languages( true );
	$locations = is_array( $locations_before ) ? $locations_before : array();
	$locations['primary'] = $source_menu;
	set_theme_mod( 'nav_menu_locations', $locations );
	delete_option( 'devenia_workflow_public_header_manifest' );
	update_option( 'devenia_workflow_localized_menu_identities', array(), false );
	delete_option( 'devenia_workflow_pending_public_header_manifest' );
	delete_option( 'devenia_workflow_public_header_enrollment' );
	update_option( 'devenia_workflow_public_header_transition', array( 'schema_version' => 1, 'phase' => 'idle' ), false );
	$expected_pre_state = array( 'manifest' => '__devenia_workflow_option_missing__', 'identities' => array(), 'pending' => '__devenia_workflow_option_missing__', 'enrollment' => '__devenia_workflow_option_missing__' );
	$_SERVER['REQUEST_URI'] = '/';

	$batch_calls = 0;
	$verification_language = '';
	$force_mismatch = false;
	$pre_enrollment_navigation = array( array( 'title' => 'Focused source', 'url' => $fixture_url ) );
	$batch = static function ( $default, array $requests ) use ( &$batch_calls, &$verification_language, &$force_mismatch, $pre_enrollment_navigation ): array {
		unset( $default );
		++$batch_calls;
		$transition = get_option( 'devenia_workflow_public_header_transition', array() );
		$phase = (string) ( is_array( $transition ) ? ( $transition['phase'] ?? '' ) : '' );
		$direction = 0 === strpos( $phase, 'rollback_' ) ? 'rollback' : 'forward';
		$expected = '' === $verification_language
			? $pre_enrollment_navigation
			: (array) ( $transition['authority']['expected_navigation'][ $direction ][ $verification_language ] ?? array() );
		if ( empty( $expected ) ) { throw new RuntimeException( 'Focused external HTTP Adapter has no navigation oracle for the current public operation.' ); }
		$items = '';
		foreach ( $expected as $row ) { $items .= '<li class="menu-item"><a href="' . esc_url( (string) ( $row['url'] ?? '' ) ) . '">' . esc_html( (string) ( $row['title'] ?? '' ) ) . '</a></li>'; }
		if ( $force_mismatch ) { $items .= '<li class="menu-item"><a href="https://example.org/mismatch/">Mismatch</a></li>'; }
		$menu = '<nav id="site-navigation"><ul id="primary-menu" class="menu">' . $items . '</ul></nav>';
		$responses = array();
		foreach ( array_keys( $requests ) as $key ) { $responses[ $key ] = array( 'headers' => array(), 'body' => '<!doctype html><html><body>' . $menu . '</body></html>', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null ); }
		return $responses;
	};
	add_filter( 'devenia_workflow_frontend_cache_batch_adapter_result', $batch, 10, 2 );
	$filters[] = array( 'devenia_workflow_frontend_cache_batch_adapter_result', $batch, 10 );
	$forward_cache_fails = true;
	$rollback_cache_fails = false;
	$cache_failure = static function ( $default, array $urls, array $context ) use ( &$forward_cache_fails, &$rollback_cache_fails ): array {
		unset( $default, $urls );
		$event = (string) ( $context['event'] ?? '' );
		return ( ( $forward_cache_fails && 'public_header_projection' === $event ) || ( $rollback_cache_fails && 'public_header_projection_rollback' === $event ) )
			? array( 'success' => false, 'code' => 'focused_' . $event . '_failure' )
			: array( 'success' => true );
	};
	add_filter( 'devenia_workflow_frontend_cache_invalidation_result', $cache_failure, 10, 3 );
	$filters[] = array( 'devenia_workflow_frontend_cache_invalidation_result', $cache_failure, 10 );
	$managed_ids = static function (): array {
		$ids = array();
		foreach ( wp_get_nav_menus() as $menu ) { $id = absint( $menu->term_id ?? 0 ); if ( $id > 0 && '1' === (string) get_term_meta( $id, '_devenia_workflow_localized_menu_managed', true ) ) { $ids[] = $id; } }
		sort( $ids );
		return $ids;
	};
	$enrollment_ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'devenia-workflow/enroll-public-header-from-existing-menus' ) : null;
	$activation_ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'devenia-workflow/activate-public-header-projection' ) : null;
	$verification_ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'devenia-workflow/verify-public-header-projection' ) : null;
	if ( ! is_object( $enrollment_ability ) || ! is_object( $activation_ability ) || ! is_object( $verification_ability ) ) { throw new RuntimeException( 'Registered Public Header abilities are unavailable.' ); }
	$enrollment_input = array( 'source_menu_id' => $source_menu, 'authority_menus' => $authority_menus, 'stage' => true, 'timeout' => 3 );
	if ( true !== $enrollment_ability->check_permissions( $enrollment_input ) ) { throw new RuntimeException( 'Administrator permission callback rejected enrollment.' ); }
	wp_set_current_user( 0 );
	$denied = $activation_ability->check_permissions( array( 'activation_receipt' => 'fixture', 'timeout' => 3 ) );
	$verify_denied = $verification_ability->check_permissions( array( 'activation_receipt' => 'phact_' . str_repeat( 'a', 48 ), 'language' => $source_language, 'timeout' => 3 ) );
	wp_set_current_user( $admin_id );
	if ( false !== $denied || false !== $verify_denied ) { throw new RuntimeException( 'Public Header mutation permission callback did not reject an anonymous caller.' ); }
	$invalid_timeout = $activation_ability->execute( array( 'activation_receipt' => 'fixture', 'timeout' => 2 ) );
	$invalid_verify_timeout = $verification_ability->execute( array( 'activation_receipt' => 'phact_' . str_repeat( 'a', 48 ), 'language' => $source_language, 'timeout' => 2 ) );
	if ( ! is_wp_error( $invalid_timeout ) || ! is_wp_error( $invalid_verify_timeout ) ) { throw new RuntimeException( 'Public Header schema accepted timeout below its declared minimum.' ); }
	$managed_before = $managed_ids();
	$enrollment = $enrollment_ability->execute( $enrollment_input );
	if ( is_wp_error( $enrollment ) ) { throw new RuntimeException( $enrollment->get_error_code() . ': ' . $enrollment->get_error_message() ); }
	if ( empty( $enrollment['success'] ) || empty( $enrollment['staged'] ) || empty( $enrollment['activation_receipt'] ) ) { throw new RuntimeException( 'Focused enrollment could not stage: ' . wp_json_encode( $enrollment ) ); }
	$activation_input = array( 'activation_receipt' => (string) $enrollment['activation_receipt'], 'timeout' => 3 );
	if ( true !== $activation_ability->check_permissions( $activation_input ) ) { throw new RuntimeException( 'Administrator permission callback rejected activation.' ); }
	$activation = $activation_ability->execute( $activation_input );
	if ( is_wp_error( $activation ) ) { throw new RuntimeException( $activation->get_error_code() . ': ' . $activation->get_error_message() ); }
	foreach ( (array) ( $activation['projections'] ?? array() ) as $projection ) { $id = absint( $projection['target_menu']['id'] ?? 0 ); if ( $id > 0 ) { $projection_ids[] = $id; } }
	$projection_ids = array_values( array_unique( $projection_ids ) );
	$transition = get_option( 'devenia_workflow_public_header_transition', array() );
	if ( 'public_header_cache_invalidation_failed' !== (string) ( $activation['code'] ?? '' ) || empty( $activation['activation_applied'] ) || 'forward_invalidation_pending' !== (string) ( $transition['phase'] ?? '' ) || empty( $projection_ids ) ) {
		throw new RuntimeException( 'Activation did not preserve a resumable transition after cache invalidation failed: ' . wp_json_encode( $activation ) );
	}
	$forward_cache_fails = false;
	$force_mismatch = true;
	$verification_language = $source_language;
	$forward_failure = $verification_ability->execute( array( 'activation_receipt' => (string) $enrollment['activation_receipt'], 'language' => $source_language, 'timeout' => 3 ) );
	if ( is_wp_error( $forward_failure ) ) { throw new RuntimeException( $forward_failure->get_error_code() . ': ' . $forward_failure->get_error_message() ); }
	$rolled_back_state = array( 'manifest' => get_option( 'devenia_workflow_public_header_manifest', '__devenia_workflow_option_missing__' ), 'identities' => get_option( 'devenia_workflow_localized_menu_identities', '__devenia_workflow_option_missing__' ), 'pending' => get_option( 'devenia_workflow_pending_public_header_manifest', '__devenia_workflow_option_missing__' ), 'enrollment' => get_option( 'devenia_workflow_public_header_enrollment', '__devenia_workflow_option_missing__' ) );
	$transition = get_option( 'devenia_workflow_public_header_transition', array() );
	if ( 'public_header_rollback_verification_pending' !== (string) ( $forward_failure['code'] ?? '' ) || $expected_pre_state !== $rolled_back_state || 'rollback_invalidation_pending' !== (string) ( $transition['phase'] ?? '' ) ) {
		throw new RuntimeException( 'Conclusive forward mismatch did not atomically restore exact pre-intake state: ' . wp_json_encode( array( 'failure' => $forward_failure, 'state' => $rolled_back_state ) ) );
	}
	$force_mismatch = false;
	$rollback_cache_fails = true;
	$rollback_cache_failure = $verification_ability->execute( array( 'activation_receipt' => (string) $enrollment['activation_receipt'], 'language' => $source_language, 'timeout' => 3 ) );
	if ( is_wp_error( $rollback_cache_failure ) || 'public_header_rollback_cache_invalidation_failed' !== (string) ( $rollback_cache_failure['code'] ?? '' ) ) { throw new RuntimeException( 'Rollback cache failure was not retained as retryable transition state.' ); }
	$rollback_cache_fails = false;
	$last_verification = array();
	foreach ( array_merge( array( $source_language ), $targets ) as $language ) {
		$verification_language = $language;
		$last_verification = $verification_ability->execute( array( 'activation_receipt' => (string) $enrollment['activation_receipt'], 'language' => $language, 'timeout' => 3 ) );
		if ( is_wp_error( $last_verification ) ) { throw new RuntimeException( $last_verification->get_error_code() . ': ' . $last_verification->get_error_message() ); }
	}
	$remaining_projection_ids = array_values( array_filter( $projection_ids, 'wp_get_nav_menu_object' ) );
	$terminal = get_option( 'devenia_workflow_public_header_transition', array() );
	if ( 'rolled_back_verified' !== (string) ( $terminal['phase'] ?? '' ) || empty( $last_verification['rolled_back'] ) || ! empty( $remaining_projection_ids ) || $managed_before !== $managed_ids() || $expected_pre_state !== $rolled_back_state ) {
		throw new RuntimeException( 'Bounded rollback verification did not terminally clean every owned candidate: ' . wp_json_encode( array( 'last' => $last_verification, 'remaining_projection_ids' => $remaining_projection_ids, 'terminal' => $terminal ) ) );
	}
	$result = array( 'success' => true, 'public_interfaces' => array( 'devenia-workflow/activate-public-header-projection', 'devenia-workflow/verify-public-header-projection' ), 'schema_enforced' => true, 'permission_enforced' => true, 'existing_identities_pre_state_restored' => true, 'rollback_cache_retryable' => true, 'owned_staging_cleaned' => true, 'projection_count' => count( $projection_ids ), 'batch_calls' => $batch_calls );
} catch ( Throwable $caught ) {
	$error = $caught;
} finally {
	foreach ( array_reverse( $filters ) as $filter ) { remove_filter( $filter[0], $filter[1], $filter[2] ); }
	foreach ( $option_keys as $key ) { '__workflow_missing__' === $before[ $key ] ? delete_option( $key ) : update_option( $key, $before[ $key ], false ); }
	set_theme_mod( 'nav_menu_locations', is_array( $locations_before ) ? $locations_before : array() );
	if ( null === $request_uri_before ) { unset( $_SERVER['REQUEST_URI'] ); } else { $_SERVER['REQUEST_URI'] = $request_uri_before; }
	foreach ( array_reverse( array_values( array_unique( array_map( 'intval', $menus ) ) ) ) as $menu_id ) { if ( wp_get_nav_menu_object( $menu_id ) ) { wp_delete_nav_menu( $menu_id ); } }
	foreach ( array_reverse( array_values( array_unique( array_map( 'intval', $projection_ids ) ) ) ) as $projection_id ) { if ( $projection_id > 0 && '1' === (string) get_term_meta( $projection_id, '_devenia_workflow_localized_menu_managed', true ) && wp_get_nav_menu_object( $projection_id ) ) { wp_delete_nav_menu( $projection_id ); } }
	Devenia_Workflow::languages( true );
	wp_set_current_user( $user_before );
}

if ( $error instanceof Throwable ) { fwrite( STDERR, $error->getMessage() . PHP_EOL ); exit( 1 ); }
echo wp_json_encode( $result, JSON_UNESCAPED_SLASHES ) . PHP_EOL;
