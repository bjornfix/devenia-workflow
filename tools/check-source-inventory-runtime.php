<?php
/** WP-CLI runtime regression for complete source inventory and obligation projection. */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { fwrite( STDERR, "Run through WP-CLI.\n" ); exit( 1 ); }
if ( ! class_exists( 'Devenia_Workflow' ) ) { fwrite( STDERR, "Plugin is not active.\n" ); exit( 1 ); }

$invoke = static function ( string $method, array $args = array() ) {
	$reflection = new ReflectionMethod( Devenia_Workflow::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $args );
};
$terminal_rebuild_state = array();
$rebuild = static function () use ( $invoke, &$terminal_rebuild_state ): array {
	$result = $invoke( 'rebuild_source_inventory', array( array( 'confirm_rebuild' => true ) ) );
	for ( $attempt = 0; $attempt < 500 && ! empty( $result['success'] ) && empty( $result['completed'] ); ++$attempt ) {
		$stored = get_option( Devenia_Workflow::OPTION_SOURCE_INVENTORY_REBUILD, array() );
		if ( is_array( $stored ) && 'project' === (string) ( $stored['phase'] ?? '' ) && count( (array) ( $stored['source_rows'] ?? array() ) ) - absint( $stored['source_offset'] ?? 0 ) <= 5 ) {
			$terminal_rebuild_state = $stored;
		}
		$result = $invoke( 'rebuild_source_inventory', array( array( 'confirm_rebuild' => true, 'resume_token' => (string) ( $result['resume_token'] ?? '' ) ) ) );
	}
	return $result;
};
$created = array();
$created_options = array();
$failures = array();
delete_option( Devenia_Workflow::OPTION_SOURCE_INVENTORY_REBUILD );
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
	$old_post = $insert_post( array( 'post_type' => 'post', 'post_name' => 'inventory-earlier-post-' . strtolower( wp_generate_password( 6, false, false ) ), 'post_title' => 'Inventory earlier post source', 'post_content' => '<!-- wp:paragraph --><p>Earlier public post.</p><!-- /wp:paragraph -->' ) );
	$old_post_url = get_permalink( $old_post );
	$old_source = $insert_post( array( 'post_name' => 'inventory-page-source-' . strtolower( wp_generate_password( 6, false, false ) ), 'post_title' => 'Inventory old zero-translation source', 'post_content' => '<!-- wp:paragraph --><p>Old public source with a <a href="' . esc_url( $old_post_url ) . '">cross-type post dependency</a>.</p><!-- /wp:paragraph -->' ) );

	for ( $i = 0; $i < 501; ++$i ) {
		$id = $insert_post( array( 'post_title' => 'Inventory newer translation ' . $i, 'post_content' => '<!-- wp:paragraph --><p>Localized fixture.</p><!-- /wp:paragraph -->' ) );
		$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $id, 'meta_key' => '_devenia_translation_source_id', 'meta_value' => (string) $old_source ) );
		$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $id, 'meta_key' => '_devenia_translation_language', 'meta_value' => 'nb' ) );
	}

	$draft = $insert_post( array( 'post_status' => 'draft', 'post_title' => 'Inventory draft exclusion' ) );
	$password = $insert_post( array( 'post_title' => 'Inventory password exclusion', 'post_password' => 'fixture' ) );
	$noindex = $insert_post( array( 'post_title' => 'Inventory public noindex source', 'post_content' => '<p>Visible noindex fixture.</p>' ) );
	$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $noindex, 'meta_key' => 'rank_math_robots', 'meta_value' => serialize( array( 'noindex' ) ) ) );

	$result = $rebuild();
	if ( empty( $result['success'] ) ) { $failures[] = 'rebuild failed'; }
	$manifest = $result['inventory'] ?? array();
	$generation = (string) ( $manifest['generation'] ?? '' );
	$source_rows = $invoke( 'inventory_store_read_rows', array( $generation, 'source' ) );
	$obligation_rows = $invoke( 'inventory_store_read_rows', array( $generation, 'obligation' ) );
	$source_by_id = array();
	foreach ( $source_rows as $row ) { $source_by_id[ absint( $row['source_id'] ?? 0 ) ] = $row; }
	$old_row = $source_by_id[ $old_source ] ?? null;
	$draft_reason = $source_by_id[ $draft ]['exclusion_reason'] ?? '';
	$password_reason = $source_by_id[ $password ]['exclusion_reason'] ?? '';
	$noindex_applicable = $source_by_id[ $noindex ]['applicable'] ?? 0;
	$old_obligations = count( array_filter( $obligation_rows, static function ( $row ) use ( $old_source ) { return absint( $row['source_id'] ?? 0 ) === $old_source; } ) );
	if ( ! $old_row || 1 !== absint( $old_row['applicable'] ?? 0 ) ) { $failures[] = 'older zero-translation source was hidden by 501 newer translations'; }
	if ( 'status_draft' !== $draft_reason ) { $failures[] = 'draft exclusion reason missing'; }
	if ( 'password_protected' !== $password_reason ) { $failures[] = 'password exclusion reason missing'; }
	if ( 1 !== absint( $noindex_applicable ) ) { $failures[] = 'public noindex source was incorrectly excluded'; }
	if ( absint( $manifest['target_languages'] ?? 0 ) !== $old_obligations ) { $failures[] = 'source by target-language projection is incomplete'; }

	$terminal_owner = $invoke( 'inventory_store_acquire_projection_lease', array( 'runtime_terminal_owner' ) );
	if ( empty( $terminal_owner['success'] ) || ! $terminal_rebuild_state ) {
		$failures[] = 'terminal rebuild concurrency fixture could not establish its lease and stale in-flight state';
	} else {
		$index_name = $invoke( 'inventory_store_index_name', array( $generation ) );
		$index_before_stale_writer = get_option( $index_name, array() );
		try {
			sleep( 1 );
			$stale_terminal = $invoke( 'inventory_rebuild_continue', array( $terminal_rebuild_state ) );
		} finally {
			$invoke( 'inventory_store_release_projection_lease', array( $terminal_owner ) );
		}
		$queue_after_stale_writer = $invoke( 'translation_obligation_queue', array( array( 'cursor' => 0, 'limit' => 1 ) ) );
		if ( 'obligation_projection_lease_conflict' !== (string) ( $stale_terminal['code'] ?? '' ) || empty( $queue_after_stale_writer['success'] ) || $index_before_stale_writer !== get_option( $index_name, array() ) ) {
			$failures[] = 'a lease-conflicted terminal rebuild rewrote the active Generation before activation ownership';
		}

		update_option( Devenia_Workflow::OPTION_SOURCE_INVENTORY_REBUILD, $terminal_rebuild_state, false );
		$index_before_idempotent_resume = get_option( $index_name, array() );
		$idempotent_terminal = $invoke( 'inventory_rebuild_continue', array( $terminal_rebuild_state ) );
		$queue_after_idempotent_resume = $invoke( 'translation_obligation_queue', array( array( 'cursor' => 0, 'limit' => 1 ) ) );
		$remaining_rebuild = get_option( Devenia_Workflow::OPTION_SOURCE_INVENTORY_REBUILD, null );
		if ( empty( $idempotent_terminal['success'] ) || empty( $idempotent_terminal['completed'] ) || empty( $queue_after_idempotent_resume['success'] ) || $index_before_idempotent_resume !== get_option( $index_name, array() ) || null !== $remaining_rebuild ) {
			$failures[] = 'an already-active Generation was not resumed idempotently without materialization';
		}
	}

	$next_page = $invoke( 'translation_job_next', array( array( 'source_type' => 'page', 'observability_label' => 'runtime-pages-first' ) ) );
	if ( 'page' !== (string) ( $next_page['obligation']['source_post_type'] ?? '' ) || ! is_array( $next_page['discover'] ?? null ) ) { $failures[] = 'page-scoped next Job did not delegate the selected page to Job discovery'; }
	$next_post = $invoke( 'translation_job_next', array( array( 'source_type' => 'post', 'observability_label' => 'runtime-posts-phase' ) ) );
	if ( 'post' !== (string) ( $next_post['obligation']['source_post_type'] ?? '' ) || ! is_array( $next_post['discover'] ?? null ) ) { $failures[] = 'post-scoped next Job did not delegate the selected post to Job discovery'; }
	$page_queue = $invoke( 'translation_obligation_queue', array( array( 'cursor' => 0, 'limit' => 50, 'source_type' => 'page' ) ) );
	$page_queue_types = array_unique( array_map( static function ( $row ) { return (string) ( $row['source_post_type'] ?? '' ); }, (array) ( $page_queue['items'] ?? array() ) ) );
	if ( empty( $page_queue['success'] ) || array( 'page' ) !== array_values( $page_queue_types ) ) { $failures[] = 'page-scoped obligation queue exposed a non-page obligation'; }
	$post_queue = $invoke( 'translation_obligation_queue', array( array( 'cursor' => 0, 'limit' => 50, 'source_type' => 'post' ) ) );
	$post_queue_types = array_unique( array_map( static function ( $row ) { return (string) ( $row['source_post_type'] ?? '' ); }, (array) ( $post_queue['items'] ?? array() ) ) );
	if ( empty( $post_queue['success'] ) || array( 'post' ) !== array_values( $post_queue_types ) ) { $failures[] = 'post-scoped obligation queue exposed a non-post obligation'; }
	$page_cursor = $invoke( 'translation_obligation_queue', array( array( 'cursor' => 0, 'limit' => 1, 'source_type' => 'page' ) ) );
	$scope_mismatch = $invoke( 'translation_obligation_queue', array( array( 'cursor' => max( 1, absint( $page_cursor['next_cursor'] ?? 1 ) ), 'limit' => 1, 'source_type' => 'post', 'snapshot' => (string) ( $page_cursor['snapshot'] ?? '' ) ) ) );
	if ( 'inventory_snapshot_stale' !== (string) ( $scope_mismatch['code'] ?? '' ) ) { $failures[] = 'page cursor snapshot was reusable under post scope'; }
	$default_queue = $invoke( 'translation_obligation_queue', array( array( 'cursor' => 0, 'limit' => 10 ) ) );
	$all_queue = $invoke( 'translation_obligation_queue', array( array( 'cursor' => 0, 'limit' => 10, 'source_type' => 'all' ) ) );
	if ( (array) ( $default_queue['items'] ?? array() ) !== (array) ( $all_queue['items'] ?? array() ) || (string) ( $default_queue['snapshot'] ?? '' ) !== (string) ( $all_queue['snapshot'] ?? '' ) ) { $failures[] = 'omitted source scope no longer preserves whole-site queue behavior'; }
	$index_name = $invoke( 'inventory_store_index_name', array( $generation ) );
	$active_index = get_option( $index_name, array() );
	$active_manifest_backup = get_option( Devenia_Workflow::OPTION_SOURCE_INVENTORY_ACTIVE, array() );
	$corrupt_index = $active_index;
	$corrupt_index['unresolved_source_type_shard_counts']['page'][0] = 1 + absint( $corrupt_index['unresolved_source_type_shard_counts']['page'][0] ?? 0 );
	$corrupt_manifest = $active_manifest_backup;
	$corrupt_manifest['inventory_index_digest'] = hash( 'sha256', wp_json_encode( $corrupt_index ) ?: '' );
	try {
		update_option( $index_name, $corrupt_index, false );
		update_option( Devenia_Workflow::OPTION_SOURCE_INVENTORY_ACTIVE, $corrupt_manifest, false );
		$corrupt_type_queue = $invoke( 'translation_obligation_queue', array( array( 'cursor' => 0, 'limit' => 1, 'source_type' => 'page' ) ) );
	} finally {
		update_option( $index_name, $active_index, false );
		update_option( Devenia_Workflow::OPTION_SOURCE_INVENTORY_ACTIVE, $active_manifest_backup, false );
	}
	if ( 'inventory_store_rebuild_required' !== (string) ( $corrupt_type_queue['code'] ?? '' ) ) { $failures[] = 'corrupt per-type unresolved directory did not fail closed'; }
	$page_fixture = current( array_filter( $obligation_rows, static function ( $row ) use ( $old_source ) { return absint( $row['source_id'] ?? 0 ) === $old_source; } ) );
	if ( is_array( $page_fixture ) ) {
		$selection = $invoke( 'translation_job_dependency_ordered_selection', array( $manifest, $active_index, array( absint( $page_fixture['obligation_id'] ?? 0 ) ), 'page' ) );
		if ( empty( $selection['success'] ) || $old_source !== absint( $selection['item']['source_id'] ?? 0 ) ) { $failures[] = 'page-scoped dependency traversal crossed into a linked post'; }
	} else { $failures[] = 'cross-type dependency fixture was unavailable'; }
	$page_source_count = count( array_filter( $source_rows, static function ( $row ) { return 1 === absint( $row['applicable'] ?? 0 ) && 'page' === (string) ( $row['post_type'] ?? '' ); } ) );
	$page_excluded_count = count( array_filter( $source_rows, static function ( $row ) { return 1 !== absint( $row['applicable'] ?? 0 ) && 'page' === (string) ( $row['post_type'] ?? '' ); } ) );
	$page_proof = $invoke( 'translation_exhaustion_proof', array( array( 'source_type' => 'page', 'refresh' => false ) ) );
	$expected_page_obligations = $page_source_count * absint( $manifest['target_languages'] ?? 0 );
	if ( 'page' !== (string) ( $page_proof['source_type'] ?? '' ) || $expected_page_obligations !== absint( $page_proof['expected_obligations'] ?? -1 ) || $expected_page_obligations !== absint( $page_proof['projected_obligations'] ?? -1 ) || $page_excluded_count !== absint( $page_proof['excluded_sources'] ?? -1 ) || ! isset( $page_proof['excluded_by_reason'], $page_proof['state_counts'], $page_proof['inventory_input_signature'], $page_proof['source_inventory_epoch'], $page_proof['obligation_projection_epoch'] ) ) { $failures[] = 'page-scoped exhaustion proof omitted scoped arithmetic or authority evidence'; }

	$old_obligation = current( array_filter( $obligation_rows, static function ( $row ) use ( $old_source ) { return absint( $row['source_id'] ?? 0 ) === $old_source; } ) );
	if ( ! is_array( $old_obligation ) ) {
		$failures[] = 'runtime obligation fixture is unavailable';
	} else {
		$language = sanitize_key( (string) ( $old_obligation['target_language'] ?? '' ) );
		$job_id = $invoke( 'translation_job_id', array( $old_source, $language, (string) $old_obligation['source_revision'] ) );
		$job_key = $invoke( 'translation_job_job_key', array( $job_id ) );
		$job = array(
			'schema_version' => 4, 'job_id' => $job_id, 'source_id' => $old_source,
			'source_revision' => (string) $old_obligation['source_revision'],
			'publication_surface_contract_revision' => (string) $old_obligation['publication_surface_contract_revision'],
			'target_language' => $language, 'status' => 'queued', 'created_at' => gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ), 'run_ids' => array(), 'submission_generation' => 1,
		);
		$commit = $invoke( 'inventory_store_commit_job_projection', array( 'runtime_job_create', static function () use ( $invoke, $job_key, $job, &$created_options ): array {
			if ( ! $invoke( 'atomic_create_option', array( $job_key, $job ) ) ) { return array( 'success' => false ); }
			$created_options[] = $job_key;
			return array( 'success' => true, 'stored_job' => $job );
		} ) );
		if ( empty( $commit['success'] ) || 'queued' !== (string) ( $commit['projection']['state'] ?? '' ) ) { $failures[] = 'deep Job commit Interface did not synchronize the exact obligation'; }
		$queue_start = $invoke( 'translation_obligation_queue', array( array( 'cursor' => 0, 'limit' => 1 ) ) );
		$queue = $invoke( 'translation_obligation_queue', array( array( 'cursor' => max( 0, absint( $old_obligation['obligation_id'] ?? 1 ) - 1 ), 'limit' => 1, 'snapshot' => (string) ( $queue_start['snapshot'] ?? '' ) ) ) );
		$queued_fixture = array_filter( (array) ( $queue['items'] ?? array() ), static function ( $row ) use ( $old_source, $language ) { return absint( $row['source_id'] ?? 0 ) === $old_source && (string) ( $row['target_language'] ?? '' ) === $language && 'queued' === (string) ( $row['state'] ?? '' ); } );
		if ( empty( $queue['success'] ) || 1 !== count( $queued_fixture ) ) { $failures[] = 'bounded queue did not expose the lifecycle-synchronized row'; }

		$old_snapshot = (string) ( $queue_start['snapshot'] ?? '' );
		$job_before_refresh = get_option( $job_key, array() );
		$job_after_refresh = array_merge( $job_before_refresh, array( 'updated_at' => gmdate( 'c', time() + 1 ) ) );
		$refresh = $invoke( 'inventory_store_commit_job_projection', array( 'runtime_snapshot_refresh', static function () use ( $invoke, $job_key, $job_before_refresh, $job_after_refresh ): array {
			$stored = $invoke( 'atomic_replace_option_value', array( $job_key, $job_before_refresh, $job_after_refresh ) );
			return $stored ? array( 'success' => true, 'stored_job' => $job_after_refresh ) : array( 'success' => false );
		} ) );
		if ( empty( $refresh['success'] ) ) { $failures[] = 'snapshot refresh through deep commit Interface failed'; }
		$job = $job_after_refresh;
		$stale_page = $invoke( 'translation_obligation_queue', array( array( 'cursor' => max( 1, absint( $old_obligation['obligation_id'] ?? 1 ) - 1 ), 'limit' => 1, 'snapshot' => $old_snapshot ) ) );
		if ( 'inventory_snapshot_stale' !== (string) ( $stale_page['code'] ?? '' ) ) { $failures[] = 'cursor snapshot survived a projection epoch change'; }

		$owned_lease = $invoke( 'inventory_store_acquire_projection_lease', array( 'runtime_lease_owner' ) );
		if ( empty( $owned_lease['success'] ) ) {
			$failures[] = 'writer serialization fixture could not acquire the first lease';
		} else {
			$contender = $invoke( 'inventory_store_begin_projection_mutation', array( 'runtime_lease_contender' ) );
			$invoke( 'inventory_store_release_projection_lease', array( $owned_lease ) );
			if ( 'obligation_projection_lease_conflict' !== (string) ( $contender['code'] ?? '' ) ) { $failures[] = 'a second writer entered the projection seam'; }
		}

		$current_queue = $invoke( 'translation_obligation_queue', array( array( 'cursor' => 0, 'limit' => 1 ) ) );
		$active = get_option( Devenia_Workflow::OPTION_SOURCE_INVENTORY_ACTIVE, array() );
		$active_generation = (string) ( $active['generation'] ?? '' );
		$active_index = get_option( $invoke( 'inventory_store_index_name', array( $active_generation ) ), array() );
		$binding = $active_index['obligation_lookup'][ $old_source . ':' . $language ] ?? array();
		$shard_name = $invoke( 'inventory_store_shard_name', array( $active_generation, 'obligation', absint( $binding['shard'] ?? 0 ) ) );
		$shard_backup = get_option( $shard_name, null );
		if ( ! is_array( $shard_backup ) ) {
			$failures[] = 'corrupt-shard fixture could not identify its owned shard';
		} else {
			try {
				delete_option( $shard_name );
				$corrupt = $invoke( 'translation_obligation_queue', array( array( 'cursor' => max( 1, absint( $old_obligation['obligation_id'] ?? 1 ) - 1 ), 'limit' => 1, 'snapshot' => (string) ( $current_queue['snapshot'] ?? '' ) ) ) );
				if ( 'inventory_store_rebuild_required' !== (string) ( $corrupt['code'] ?? '' ) ) { $failures[] = 'missing obligation shard did not fail closed'; }
			} finally {
				update_option( $shard_name, $shard_backup, false );
			}
		}

		$source_start = $invoke( 'source_inventory', array( array( 'cursor' => 0, 'limit' => 1 ) ) );
		$source_ids = array_values( array_map( static function ( $row ) { return absint( $row['source_id'] ?? 0 ); }, $source_rows ) );
		if ( count( $source_ids ) > 1 ) {
			$penultimate = $source_ids[ count( $source_ids ) - 2 ];
			$first_source_shard = $invoke( 'inventory_store_shard_name', array( $active_generation, 'source', 0 ) );
			$first_source_backup = get_option( $first_source_shard, null );
			try {
				delete_option( $first_source_shard );
				$terminal_corrupt = $invoke( 'source_inventory', array( array( 'cursor' => $penultimate, 'limit' => 1, 'snapshot' => (string) ( $source_start['snapshot'] ?? '' ) ) ) );
				if ( 'inventory_store_rebuild_required' !== (string) ( $terminal_corrupt['code'] ?? '' ) ) { $failures[] = 'last non-empty source page did not prove untouched shard completeness'; }
			} finally {
				if ( is_array( $first_source_backup ) ) { update_option( $first_source_shard, $first_source_backup, false ); }
			}
		}
	}

	$proof = $invoke( 'translation_exhaustion_proof', array( array() ) );
	if ( absint( $proof['expected_obligations'] ?? 0 ) !== absint( $proof['projected_obligations'] ?? -1 ) ) { $failures[] = 'exhaustion arithmetic differs'; }

	$translation_fixture = isset( $created[1] ) ? absint( $created[1] ) : 0;
	$epoch_before_translation_save = $invoke( 'source_inventory_epoch' );
	if ( $translation_fixture > 0 ) { do_action( 'save_post', $translation_fixture, get_post( $translation_fixture ), true ); }
	if ( $invoke( 'source_inventory_epoch' ) <= $epoch_before_translation_save ) { $failures[] = 'direct translation save did not invalidate Inventory authority'; }
	$rebuild();

	$epoch_before_term_edit = $invoke( 'source_inventory_epoch' );
	do_action( 'edited_term', 0, 0, 'category' );
	if ( $invoke( 'source_inventory_epoch' ) <= $epoch_before_term_edit ) { $failures[] = 'taxonomy mutation did not invalidate Source authority'; }
	$rebuild();

	$expired_owner = $invoke( 'inventory_store_acquire_projection_lease', array( 'runtime_expired_owner' ) );
	if ( empty( $expired_owner['success'] ) ) {
		$failures[] = 'expired lease fixture could not acquire ownership';
	} else {
		$expired_value = (array) $expired_owner['lease'];
		$expired_value['expires_at'] = time() - 1;
		update_option( (string) $expired_owner['key'], $expired_value, false );
		$takeover = $invoke( 'inventory_store_acquire_projection_lease', array( 'runtime_expired_takeover' ) );
		if ( empty( $takeover['success'] ) ) { $failures[] = 'expired projection lease could not be taken over'; }
		else { $invoke( 'inventory_store_release_projection_lease', array( $takeover ) ); }
	}

	$interrupted = $invoke( 'inventory_store_begin_projection_mutation', array( 'runtime_interruption' ) );
	if ( empty( $interrupted['success'] ) ) {
		$failures[] = 'interruption fixture could not acquire projection lease';
	} else {
		$during = $invoke( 'translation_obligation_queue', array( array( 'cursor' => 0, 'limit' => 1 ) ) );
		$invoke( 'inventory_store_release_projection_lease', array( (array) $interrupted['lease'] ) );
		$after = $invoke( 'translation_obligation_queue', array( array( 'cursor' => 0, 'limit' => 1 ) ) );
		if ( 'inventory_projection_rebuild_required' !== (string) ( $during['code'] ?? '' ) || 'inventory_projection_rebuild_required' !== (string) ( $after['code'] ?? '' ) ) {
			$failures[] = 'interrupted projection did not remain fail-closed';
		}
	}
} finally {
	global $wpdb;
	if ( $created ) {
		$ids = implode( ',', array_map( 'absint', $created ) );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $created as $id ) { clean_post_cache( $id ); }
	}
	foreach ( $created_options as $option_name ) { delete_option( $option_name ); }
	$rebuild();
}

if ( $failures ) { fwrite( STDERR, wp_json_encode( array( 'success' => false, 'failures' => $failures ), JSON_PRETTY_PRINT ) . PHP_EOL ); exit( 1 ); }
echo wp_json_encode( array( 'success' => true, 'contracts' => array( '501_newer_translations_do_not_hide_old_source', 'structured_exclusions', 'public_noindex_included', 'complete_projection', 'source_type_scoped_next_job', 'source_type_scoped_queue', 'source_type_scoped_exhaustion', 'deep_lifecycle_owned_exact_row_sync', 'serialized_projection_writers', 'terminal_materialization_owned_by_activation_lease', 'active_generation_resume_is_idempotent', 'expired_lease_takeover', 'snapshot_rejected_after_epoch_change', 'missing_shard_fail_closed', 'terminal_nonempty_page_completeness', 'translation_save_authority_invalidation', 'taxonomy_authority_invalidation', 'projection_epoch_fail_closed_after_interruption', 'exhaustion_arithmetic', 'fixture_cleanup_and_rebuild' ) ), JSON_PRETTY_PRINT ) . PHP_EOL;
