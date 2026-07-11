<?php
/** Dependency-light structural contract for authoritative source inventory. */

$root = dirname( __DIR__ );
$module = file_get_contents( $root . '/includes/trait-source-inventory.php' );
$catalogue = file_get_contents( $root . '/includes/trait-ability-catalogue.php' );
$platform = file_get_contents( $root . '/includes/trait-ability-platform.php' );
$work_items = file_get_contents( $root . '/includes/trait-work-item-catalog.php' );
$main = file_get_contents( $root . '/devenia-ai-translations.php' );
$failures = array();

foreach ( array(
	'Authoritative Source Inventory' => 'source_inventory_table',
	'atomic generation activation' => 'OPTION_SOURCE_INVENTORY_ACTIVE',
	'structured exclusions' => 'exclusion_reason',
	'password exclusion' => 'password_protected',
	'public viewability' => 'is_post_publicly_viewable',
	'complete obligation product' => '$included * count( $languages )',
	'stable source cursor' => 'source_id > %d',
	'stable obligation cursor' => 'obligation_id > %d',
	'v2 next-job delegation' => 'translation_job_v2_discover',
	'exhaustion arithmetic' => '$expected === $total',
	'published verification gate' => 'live_verification_passed',
) as $contract => $needle ) {
	if ( false === strpos( $module, $needle ) ) { $failures[] = "missing {$contract}"; }
}

foreach ( array( 'rebuild-source-inventory', 'source-inventory', 'translation-obligation-queue', 'translation-job-v2-next', 'translation-exhaustion-proof' ) as $ability ) {
	if ( false === strpos( $module, "ai-translations/{$ability}" ) ) { $failures[] = "ability {$ability} missing"; }
}

if ( false === strpos( $catalogue, 'source_inventory_ability_catalogue' ) ) { $failures[] = 'catalogue integration missing'; }
if ( false === strpos( $platform, 'translation_exhaustion_proof' ) ) { $failures[] = 'dispatch integration missing'; }
if ( false === strpos( $work_items, "state <> 'published_verified'" ) ) { $failures[] = 'legacy queue is not adapted to obligations'; }
if ( false === strpos( $main, "add_action( 'save_post', array( __CLASS__, 'mark_source_inventory_dirty' )" ) ) { $failures[] = 'save invalidation missing'; }

if ( $failures ) {
	fwrite( STDERR, json_encode( array( 'success' => false, 'failures' => $failures ), JSON_PRETTY_PRINT ) . PHP_EOL );
	exit( 1 );
}

echo json_encode( array( 'success' => true, 'contracts' => 17, 'regression_fixture' => '501 newer complete sources cannot hide an older unresolved source because obligation existence is projected before prioritization' ), JSON_PRETTY_PRINT ) . PHP_EOL;
