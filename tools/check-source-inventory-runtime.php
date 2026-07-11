<?php
/** WP-CLI runtime regression for complete source inventory and obligation projection. */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { fwrite( STDERR, "Run through WP-CLI.\n" ); exit( 1 ); }
if ( ! class_exists( 'Devenia_AI_Translations' ) ) { fwrite( STDERR, "Plugin is not active.\n" ); exit( 1 ); }

$invoke = static function ( string $method, array $args = array() ) {
	$reflection = new ReflectionMethod( Devenia_AI_Translations::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $args );
};
$created = array();
$failures = array();
$insert_post = static function ( array $values ) use ( &$created ): int {
	global $wpdb;
	$defaults = array(
		'post_author' => 1, 'post_date' => current_time( 'mysql' ), 'post_date_gmt' => current_time( 'mysql', true ),
		'post_content' => '', 'post_title' => '', 'post_excerpt' => '', 'post_status' => 'publish',
		'comment_status' => 'closed', 'ping_status' => 'closed', 'post_password' => '', 'post_name' => '',
		'to_ping' => '', 'pinged' => '', 'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', true ),
		'post_content_filtered' => '', 'post_parent' => 0, 'guid' => '', 'menu_order' => 0, 'post_type' => 'page', 'post_mime_type' => '', 'comment_count' => 0,
	);
	if ( false === $wpdb->insert( $wpdb->posts, array_merge( $defaults, $values ) ) ) { throw new RuntimeException( $wpdb->last_error ); }
	$id = (int) $wpdb->insert_id; $created[] = $id; return $id;
};

try {
	global $wpdb;
	$old_source = $insert_post( array( 'post_title' => 'Inventory old zero-translation source', 'post_content' => '<!-- wp:paragraph --><p>Old public source.</p><!-- /wp:paragraph -->' ) );

	for ( $i = 0; $i < 501; ++$i ) {
		$id = $insert_post( array( 'post_title' => 'Inventory newer translation ' . $i, 'post_content' => '<!-- wp:paragraph --><p>Localized fixture.</p><!-- /wp:paragraph -->' ) );
		$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $id, 'meta_key' => '_devenia_translation_source_id', 'meta_value' => (string) $old_source ) );
		$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $id, 'meta_key' => '_devenia_translation_language', 'meta_value' => 'nb' ) );
	}

	$draft = $insert_post( array( 'post_status' => 'draft', 'post_title' => 'Inventory draft exclusion' ) );
	$password = $insert_post( array( 'post_title' => 'Inventory password exclusion', 'post_password' => 'fixture' ) );
	$noindex = $insert_post( array( 'post_title' => 'Inventory public noindex source', 'post_content' => '<p>Visible noindex fixture.</p>' ) );
	$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $noindex, 'meta_key' => 'rank_math_robots', 'meta_value' => serialize( array( 'noindex' ) ) ) );

	$result = $invoke( 'rebuild_source_inventory', array( array() ) );
	if ( empty( $result['success'] ) ) { $failures[] = 'rebuild failed'; }
	$manifest = $result['inventory'] ?? array();
	$sources = $wpdb->prefix . 'devenia_translation_sources';
	$obligations = $wpdb->prefix . 'devenia_translation_obligations';
	$generation = (string) ( $manifest['generation'] ?? '' );
	$old_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$sources} WHERE generation = %s AND source_id = %d", $generation, $old_source ), ARRAY_A );
	$draft_reason = $wpdb->get_var( $wpdb->prepare( "SELECT exclusion_reason FROM {$sources} WHERE generation = %s AND source_id = %d", $generation, $draft ) );
	$password_reason = $wpdb->get_var( $wpdb->prepare( "SELECT exclusion_reason FROM {$sources} WHERE generation = %s AND source_id = %d", $generation, $password ) );
	$noindex_applicable = $wpdb->get_var( $wpdb->prepare( "SELECT applicable FROM {$sources} WHERE generation = %s AND source_id = %d", $generation, $noindex ) );
	$old_obligations = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$obligations} WHERE generation = %s AND source_id = %d", $generation, $old_source ) ) );
	if ( ! $old_row || 1 !== absint( $old_row['applicable'] ?? 0 ) ) { $failures[] = 'older zero-translation source was hidden by 501 newer translations'; }
	if ( 'status_draft' !== $draft_reason ) { $failures[] = 'draft exclusion reason missing'; }
	if ( 'password_protected' !== $password_reason ) { $failures[] = 'password exclusion reason missing'; }
	if ( 1 !== absint( $noindex_applicable ) ) { $failures[] = 'public noindex source was incorrectly excluded'; }
	if ( absint( $manifest['target_languages'] ?? 0 ) !== $old_obligations ) { $failures[] = 'source by target-language projection is incomplete'; }
	$proof = $invoke( 'translation_exhaustion_proof', array( array() ) );
	if ( absint( $proof['expected_obligations'] ?? 0 ) !== absint( $proof['projected_obligations'] ?? -1 ) ) { $failures[] = 'exhaustion arithmetic differs'; }
} finally {
	global $wpdb;
	if ( $created ) {
		$ids = implode( ',', array_map( 'absint', $created ) );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $created as $id ) { clean_post_cache( $id ); }
	}
	$invoke( 'rebuild_source_inventory', array( array() ) );
}

if ( $failures ) { fwrite( STDERR, wp_json_encode( array( 'success' => false, 'failures' => $failures ), JSON_PRETTY_PRINT ) . PHP_EOL ); exit( 1 ); }
echo wp_json_encode( array( 'success' => true, 'contracts' => array( '501_newer_translations_do_not_hide_old_source', 'structured_exclusions', 'public_noindex_included', 'complete_projection', 'exhaustion_arithmetic', 'fixture_cleanup_and_rebuild' ) ), JSON_PRETTY_PRINT ) . PHP_EOL;
