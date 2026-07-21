<?php
/** Dependency-light structural contract for authoritative source inventory. */

$root = dirname( __DIR__ );
$module = file_get_contents( $root . '/includes/trait-source-inventory.php' );
$catalogue = file_get_contents( $root . '/includes/trait-ability-catalogue.php' );
$platform = file_get_contents( $root . '/includes/trait-ability-platform.php' );
$main = file_get_contents( $root . '/devenia-workflow.php' );
$jobs = file_get_contents( $root . '/includes/trait-translation-job.php' );
$mode = file_get_contents( $root . '/includes/trait-workflow-mode.php' );
$uninstall = file_get_contents( $root . '/uninstall.php' );
$failures = array();

foreach ( array(
	'Inventory Generation Store' => 'inventory_store_write_generation',
	'bounded option shards' => 'INVENTORY_STORE_SHARD_SIZE',
	'atomic generation activation' => 'OPTION_SOURCE_INVENTORY_ACTIVE',
	'structured exclusions' => 'exclusion_reason',
	'password exclusion' => 'password_protected',
	'public viewability' => 'is_post_publicly_viewable',
	'complete obligation product' => "absint( \$state['included'] ) * count( (array) \$state['languages'] )",
	'bounded resumable rebuild' => 'inventory_rebuild_continue',
	'server-owned rebuild cursor' => "['source_offset']",
	'stable source cursor' => "['source_lookup'][ (string) \$cursor ]",
	'stable obligation cursor' => 'inventory_store_seek_unresolved',
	'Translation Job delegation' => 'translation_job_discover',
	'internal-link dependency ordering' => 'translation_job_dependency_ordered_selection',
	'dependency cycle protection' => 'cycles_skipped',
	'exhaustion arithmetic' => '$expected === $total',
	'published verification gate' => 'live_verification_passed',
	'bounded lifecycle-owned obligation synchronization' => 'inventory_store_sync_job_obligation',
	'bounded active generation read' => 'active_inventory_manifest',
	'dirty generation fail-closed queue gate' => 'inventory_rebuild_required',
	'shared projection writer lease' => 'inventory_store_acquire_projection_lease',
	'durable projection epoch' => 'OPTION_OBLIGATION_PROJECTION_EPOCH',
	'fail-closed interrupted projection' => 'inventory_projection_rebuild_required',
	'reader epoch revalidation' => 'inventory_store_projection_snapshot_is_current',
	'rebuild lifecycle race rejection' => 'inventory_changed_during_rebuild',
	'rebuild source race rejection' => 'source_changed_during_rebuild',
	'incremental state counts' => "['state_counts']",
	'monotonic source input epoch' => 'OPTION_SOURCE_INVENTORY_EPOCH',
	'input-policy signature' => 'source_inventory_input_signature',
	'structurally strict shard read' => 'inventory_store_read_rows_strict',
	'generation-bound shard digests' => 'obligation_shard_digests',
	'direct obligation binding index' => 'obligation_lookup',
	'seekable unresolved shard directory' => 'unresolved_shard_counts',
	'snapshot-bound pagination' => 'inventory_store_snapshot_token',
	'bounded dependency traversal' => 'INVENTORY_DEPENDENCY_TRAVERSAL_LIMIT',
	'source-type scoped queue index' => 'unresolved_source_type_shard_counts',
	'source-type scoped next Job' => "['source_type']",
	'scope-bound obligation snapshot' => "'obligation_' . \$source_type",
	'source-type scoped exhaustion arithmetic' => "['included_sources_by_post_type']",
	'deep Job projection commit Interface' => 'inventory_store_commit_job_projection',
) as $contract => $needle ) {
	if ( false === strpos( $module, $needle ) ) { $failures[] = "missing {$contract}"; }
}

foreach ( array( 'rebuild-source-inventory', 'source-inventory', 'translation-obligation-queue', 'translation-job-next', 'translation-exhaustion-proof' ) as $ability ) {
	if ( false === strpos( $module, "devenia-workflow/{$ability}" ) ) { $failures[] = "ability {$ability} missing"; }
}

if ( false === strpos( $catalogue, 'source_inventory_ability_catalogue' ) ) { $failures[] = 'catalogue integration missing'; }
if ( false === strpos( $platform, 'translation_exhaustion_proof' ) ) { $failures[] = 'dispatch integration missing'; }
if ( false !== strpos( $module, '$wpdb' ) || false !== strpos( $module, 'phpcs:ignore' ) ) { $failures[] = 'Inventory Generation Store leaks raw database access or suppression'; }
if ( false === strpos( $main, "add_action( 'save_post', array( __CLASS__, 'mark_source_inventory_dirty' )" ) ) { $failures[] = 'save invalidation missing'; }
if ( false === strpos( $main, "add_action( 'set_object_terms', array( __CLASS__, 'mark_source_inventory_dirty_on_object_terms' )" ) || false === strpos( $main, "add_action( 'edited_term', array( __CLASS__, 'mark_source_inventory_dirty_on_term_change' )" ) ) { $failures[] = 'taxonomy authority invalidation missing'; }
if ( false !== strpos( $module, "['source_ids']" ) || false !== strpos( $module, "['unresolved_obligation_ids']" ) ) { $failures[] = 'monolithic cursor indexes returned'; }
if ( false === strpos( $module, "if ( ! empty( \$store['terminal'] ) )" ) || false === strpos( $module, "if ( empty( \$seek['has_more'] ) )" ) ) { $failures[] = 'terminal non-empty pages do not prove store completeness'; }
if ( false !== strpos( $module, 'refresh_active_obligations' ) ) { $failures[] = 'queue readers still perform whole-generation obligation reprojection'; }
if ( substr_count( $jobs, 'self::inventory_store_commit_job_projection(' ) < 2 ) { $failures[] = 'Job creation and transition do not cross the deep projection commit Interface'; }
if ( false !== strpos( $jobs, 'inventory_store_begin_projection_mutation' ) || false !== strpos( $jobs, 'inventory_store_sync_job_obligation' ) || false !== strpos( $jobs, 'inventory_store_release_projection_lease' ) ) { $failures[] = 'Job caller leaks projection implementation ordering'; }
if ( false === strpos( $mode, 'self::mark_source_inventory_dirty();' ) ) { $failures[] = 'Workflow mode mutation does not advance Source Inventory authority'; }
if ( substr_count( $main, 'update_option( self::OPTION_LANGUAGES, $languages, false );' ) !== substr_count( $main, "update_option( self::OPTION_LANGUAGES, \$languages, false );\n\t\tself::mark_source_inventory_dirty();" ) + substr_count( $main, "update_option( self::OPTION_LANGUAGES, \$languages, false );\n\t\t\t\tself::mark_source_inventory_dirty();" ) ) { $failures[] = 'A language-registry mutation bypasses Source Inventory authority'; }
if ( false === strpos( $main, "SOURCE_INVENTORY_SCHEMA_VERSION = '5'" ) ) { $failures[] = 'Inventory schema was not advanced for source-type scoped queue indexes'; }
foreach ( array( 'devenia_workflow_source_inventory_epoch', 'devenia_workflow_source_inventory_rebuild', 'devenia_workflow_obligation_projection_epoch', 'devenia_workflow_obligation_projection_lease' ) as $owned_option ) {
	if ( false === strpos( $uninstall, "'{$owned_option}'" ) ) { $failures[] = "uninstall omits {$owned_option}"; }
}

if ( $failures ) {
	fwrite( STDERR, json_encode( array( 'success' => false, 'failures' => $failures ), JSON_PRETTY_PRINT ) . PHP_EOL );
	exit( 1 );
}

echo json_encode( array( 'success' => true, 'contracts' => 49, 'regression_fixture' => 'One deep commit Interface owns Job CAS/create plus exact projection; Generation receipts bind input/projection epochs, source-type shard indexes and digests; pagination binds scope plus one snapshot and incomplete stores fail closed.' ), JSON_PRETTY_PRINT ) . PHP_EOL;
