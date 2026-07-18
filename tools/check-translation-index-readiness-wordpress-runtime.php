<?php
/**
 * Real MariaDB proof for the Translation Index Readiness Module.
 *
 * The live dev Index is atomically parked under a request-unique identity. All
 * destructive fixtures run against a disposable canonical table, and finally
 * restores the exact table identity plus raw option rows even after a failure.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Translation Index Readiness runtime requires WP-CLI with WordPress loaded.' );
}
if ( ! class_exists( 'Devenia_Workflow' ) ) {
	throw new RuntimeException( 'Devenia Workflow was not loaded.' );
}

$call = static function ( string $method, ...$arguments ) {
	$reflection = new ReflectionMethod( Devenia_Workflow::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $arguments );
};

global $wpdb;
$token = strtolower( wp_generate_password( 10, false, false ) );
$canonical = $wpdb->prefix . 'devenia_translation_index';
$saved = $wpdb->prefix . 'devenia_ti_runtime_saved_' . $token;
$physical_valid = $wpdb->prefix . 'devenia_ti_runtime_valid_' . $token;
$physical_engine = $wpdb->prefix . 'devenia_ti_runtime_engine_' . $token;
$physical_schema = $wpdb->prefix . 'devenia_ti_runtime_schema_' . $token;
$physical_type = $wpdb->prefix . 'devenia_ti_runtime_type_' . $token;
$physical_null = $wpdb->prefix . 'devenia_ti_runtime_null_' . $token;
$physical_collation = $wpdb->prefix . 'devenia_ti_runtime_collation_' . $token;
$physical_prefix = $wpdb->prefix . 'devenia_ti_runtime_prefix_' . $token;
$runtime_tables = array(
	$physical_valid,
	$physical_engine,
	$physical_schema,
	$physical_type,
	$physical_null,
	$physical_collation,
	$physical_prefix,
);
$external_failed = $wpdb->prefix . 'devenia_ti_runtime_failed_' . $token;
$option_keys = array(
	'devenia_workflow_translation_index_schema',
	'devenia_workflow_translation_index_readiness',
	'devenia_workflow_translation_index_upgrade_lease',
	'devenia_workflow_translation_index_last_failure',
	'devenia_workflow_translation_index_canonical_revision',
	'devenia_workflow_version',
);
$fixture_slugs = array(
	'translation-index-source-' . $token,
	'translation-index-target-' . $token,
	'translation-index-alternate-source-' . $token,
	'translation-index-target-new-' . $token,
	'translation-index-author-source-' . $token,
	'translation-index-author-target-' . $token,
);
$journal_path = (string) getenv( 'DEVENIA_TRANSLATION_INDEX_RUNTIME_JOURNAL' );
$journal_directory = '' !== $journal_path ? realpath( dirname( $journal_path ) ) : false;
$journal_stat = '' !== $journal_path && is_file( $journal_path ) && ! is_link( $journal_path ) ? stat( $journal_path ) : false;
if (
	'/tmp' !== $journal_directory
	|| 1 !== preg_match( '#^devenia-ti-recovery-[A-Za-z0-9]+\.json$#D', basename( $journal_path ) )
	|| ! is_array( $journal_stat )
	|| 0600 !== ( (int) $journal_stat['mode'] & 0777 )
) {
	throw new RuntimeException( 'Translation Index runtime requires one precreated 0600 recovery journal under /tmp.' );
}
$journal = array();
$write_recovery_journal = static function ( array $state ) use ( $journal_path ): void {
	unset( $state['checksum'] );
	$payload = wp_json_encode( $state, JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $payload ) ) {
		throw new RuntimeException( 'Translation Index recovery journal could not be encoded.' );
	}
	$state['checksum'] = hash( 'sha256', $payload );
	$encoded = wp_json_encode( $state, JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $encoded ) ) {
		throw new RuntimeException( 'Translation Index recovery journal checksum could not be encoded.' );
	}
	$next = $journal_path . '.next';
	if ( file_exists( $next ) || is_link( $next ) ) {
		throw new RuntimeException( 'Translation Index recovery journal staging path is occupied.' );
	}
	$handle = fopen( $next, 'xb' );
	if ( false === $handle ) {
		throw new RuntimeException( 'Translation Index recovery journal staging file could not be created.' );
	}
	$written = false;
	try {
		if ( ! chmod( $next, 0600 ) ) {
			throw new RuntimeException( 'Translation Index recovery journal staging mode could not be restricted.' );
		}
		$bytes = $encoded . PHP_EOL;
		$offset = 0;
		while ( $offset < strlen( $bytes ) ) {
			$count = fwrite( $handle, substr( $bytes, $offset ) );
			if ( false === $count || 0 === $count ) {
				throw new RuntimeException( 'Translation Index recovery journal staging write was incomplete.' );
			}
			$offset += $count;
		}
		if ( ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! fsync( $handle ) ) ) {
			throw new RuntimeException( 'Translation Index recovery journal was not durably flushed.' );
		}
		$written = true;
	} finally {
		fclose( $handle );
		if ( ! $written && is_file( $next ) ) {
			unlink( $next );
		}
	}
	if ( ! rename( $next, $journal_path ) ) {
		throw new RuntimeException( 'Translation Index recovery journal atomic publication failed.' );
	}
	clearstatcache( true, $journal_path );
	$published = stat( $journal_path );
	$roundtrip = file_get_contents( $journal_path );
	if ( ! is_array( $published ) || 0600 !== ( (int) $published['mode'] & 0777 ) || $encoded . PHP_EOL !== $roundtrip ) {
		throw new RuntimeException( 'Translation Index recovery journal publication was not exact.' );
	}
};
$fixture_posts = array();
$worker_handles = array();
$worker_markers = array();
$filters = array();
$recovery_active = false;
$original_parked = false;
$original_existed = false;
$runtime_isolated = false;
$observer = null;
$error = null;
$result = null;
$evidence = array_fill_keys(
	array(
		'separate_observer_connection',
		'physical_contract_negative',
		'empty_semantic_read_distinct',
		'source_sql_error_failed_closed',
		'semantic_sql_error_failed_closed',
		'pre_swap_faults_restored',
		'post_swap_faults_restored',
		'throwable_faults_restored',
		'partial_row_failed_closed',
		'non_owner_preserved_readiness',
		'expired_lease_takeover',
		'old_owner_swap_rejected',
		'rebuild_rebuild_serialized',
		'reader_saw_last_good_canonical',
		'writer_before_rebuild_included',
		'canonical_writer_serialized',
		'writer_after_rebuild_twice',
		'recovery_rebuild_serialized',
		'version_faults_restored',
		'activation_failed_closed',
		'activation_succeeded_ready',
		'translation_posts_same_request_coherent',
		'find_translation_id_same_request_coherent',
		'localized_link_maps_same_request_coherent',
		'frontend_surface_same_request_coherent',
		'localized_author_archive_same_request_coherent',
		'quality_snapshot_same_request_coherent',
		'callback_throwable_released_frames',
		'fixture_residue_zero_before_finally',
	),
	false
);

/** Read exact raw option rows, including ID, serialized bytes and autoload. */
$raw_option_rows = static function ( wpdb $connection, array $keys ) use ( $wpdb ): array {
	if ( empty( $keys ) ) {
		return array();
	}
	$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
	$query = $connection->prepare(
		"SELECT option_id, option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name IN ({$placeholders}) ORDER BY option_name ASC, option_id ASC",
		$keys
	);
	$rows = is_string( $query ) ? $connection->get_results( $query, ARRAY_A ) : null;
	if ( ! is_array( $rows ) ) {
		throw new RuntimeException( 'Exact option snapshot failed.' );
	}
	return array_map(
		static function ( array $row ): array {
			return array(
				'option_id'    => absint( $row['option_id'] ?? 0 ),
				'option_name'  => (string) ( $row['option_name'] ?? '' ),
				'option_value' => (string) ( $row['option_value'] ?? '' ),
				'autoload'     => (string) ( $row['autoload'] ?? '' ),
			);
		},
		$rows
	);
};

/** Restore the exact raw option rows captured before the runtime. */
$restore_raw_option_rows = static function ( array $keys, array $snapshot ) use ( $wpdb ): bool {
	foreach ( $keys as $key ) {
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Runtime restores exact pre-state bytes in finally.
		if ( false === $deleted ) {
			return false;
		}
		wp_cache_delete( $key, 'options' );
	}
	foreach ( $snapshot as $row ) {
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_id, option_name, option_value, autoload) VALUES (%d, %s, %s, %s)",
				absint( $row['option_id'] ?? 0 ),
				(string) ( $row['option_name'] ?? '' ),
				(string) ( $row['option_value'] ?? '' ),
				(string) ( $row['autoload'] ?? '' )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Runtime restores exact option identity, bytes and autoload.
		if ( 1 !== $inserted ) {
			return false;
		}
		wp_cache_delete( (string) $row['option_name'], 'options' );
	}
	return true;
};

/** List the exact owned Index table identities without a wildcard oracle. */
$owned_tables = static function ( wpdb $connection ) use ( $wpdb ): array {
	$prefix = $wpdb->prefix . 'devenia_ti_';
	$canonical_name = $wpdb->prefix . 'devenia_translation_index';
	$rows = $connection->get_col(
		$connection->prepare(
			'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME = %s OR LEFT(TABLE_NAME, CHAR_LENGTH(%s)) = %s) ORDER BY TABLE_NAME ASC',
			$canonical_name,
			$prefix,
			$prefix
		)
	);
	if ( ! is_array( $rows ) ) {
		throw new RuntimeException( 'Owned table inventory failed.' );
	}
	return array_values( array_map( 'strval', $rows ) );
};

/** Read the complete canonical table as exact raw rows. */
$raw_index_rows = static function ( wpdb $connection, string $table ): array {
	$rows = $connection->get_results( $connection->prepare( 'SELECT * FROM %i ORDER BY id ASC', $table ), ARRAY_A );
	if ( ! is_array( $rows ) ) {
		throw new RuntimeException( 'Exact canonical Index snapshot failed.' );
	}
	return $rows;
};

/** Restore one exact raw Index row removed by a corruption fixture. */
$restore_raw_index_row = static function ( string $table, array $row ) use ( $wpdb ): bool {
	$columns = array_keys( $row );
	if ( empty( $columns ) || array_diff( $columns, array( 'id', 'source_post_id', 'translation_post_id', 'language', 'localized_path', 'source_path', 'target_path', 'target_url', 'translation_status', 'post_status', 'source_hash', 'reviewed_at', 'linguistic_reviewed_at', 'quality_reviewed_at', 'updated_at' ) ) ) {
		return false;
	}
	$formats = array();
	foreach ( $columns as $column ) {
		$formats[] = in_array( $column, array( 'id', 'source_post_id', 'translation_post_id' ), true ) ? '%d' : '%s';
	}
	return false !== $wpdb->insert( $table, $row, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact runtime fixture restoration.
};

/** Build a second real wpdb connection used only as an uncached observer. */
$new_observer = static function () use ( $wpdb ): wpdb {
	$connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$connection->show_errors( false );
	$connection->suppress_errors( true );
	$connection->set_prefix( $wpdb->prefix );
	$connection->query( 'SET SESSION innodb_lock_wait_timeout = 2' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded, disposable observer session.
	return $connection;
};

/** Start one exact worker process and wait until it reaches its write seam. */
$start_worker = static function ( string $mode, array $payload ) use ( $token, &$worker_handles, &$worker_markers, &$journal, $write_recovery_journal ): array {
	$worker = realpath( __DIR__ . '/translation-index-readiness-worker.php' );
	$wp_load = realpath( ABSPATH . 'wp-load.php' );
	if ( false === $worker || false === $wp_load || ! function_exists( 'proc_open' ) ) {
		throw new RuntimeException( 'Translation Index separate-process prerequisites are unavailable.' );
	}
	$marker = '/tmp/devenia-ti-readiness-' . $token . '-' . sanitize_key( $mode ) . '-' . count( $worker_markers ) . '.ready';
	$payload['marker'] = $marker;
	$worker_markers[] = $marker;
	$journal['worker_markers'] = $worker_markers;
	$journal['phase'] = 'worker_declared';
	$write_recovery_journal( $journal );
	$encoded = base64_encode( (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) );
	$command = array( PHP_BINARY, $worker, $wp_load, $mode, $encoded );
	$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
	$pipes = array();
	$process = proc_open( $command, $descriptors, $pipes, dirname( __DIR__ ), null, array( 'bypass_shell' => true, 'suppress_errors' => true ) );
	if ( ! is_resource( $process ) || 3 !== count( $pipes ) ) {
		throw new RuntimeException( 'Translation Index worker could not start.' );
	}
	fclose( $pipes[0] );
	$handle = array( 'process' => $process, 'pipes' => $pipes, 'mode' => $mode, 'marker' => $marker );
	$worker_handles[] = $handle;
	$deadline = microtime( true ) + 5;
	while ( ! is_file( $marker ) && microtime( true ) < $deadline ) {
		usleep( 20000 );
	}
	if ( ! is_file( $marker ) ) {
		throw new RuntimeException( 'Translation Index worker did not reach its write seam.' );
	}
	return $handle;
};

/** Finish one worker and enforce its deliberately narrow JSON Interface. */
$finish_worker = static function ( array $handle ): array {
	$stdout = stream_get_contents( $handle['pipes'][1] );
	$stderr = stream_get_contents( $handle['pipes'][2] );
	fclose( $handle['pipes'][1] );
	fclose( $handle['pipes'][2] );
	$exit = proc_close( $handle['process'] );
	$decoded = is_string( $stdout ) ? json_decode( $stdout, true ) : null;
	$keys = array( 'success', 'mode', 'code', 'connection_id', 'engine', 'wait_ms', 'restored', 'ready' );
	$exact = is_array( $decoded ) ? wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES ) . PHP_EOL : '';
	$violations = array();
	if ( '' !== (string) $stderr ) {
		$violations[] = 'stderr_nonempty';
	}
	if ( ! is_array( $decoded ) ) {
		$violations[] = 'stdout_not_json';
	} elseif ( $keys !== array_keys( $decoded ) ) {
		$violations[] = 'json_shape';
	}
	if ( $exact !== $stdout ) {
		$violations[] = 'stdout_not_canonical';
	}
	if ( is_array( $decoded ) && ( ! empty( $decoded['success'] ) ? 0 : 1 ) !== $exit ) {
		$violations[] = 'exit_mismatch_' . (int) $exit;
	}
	if ( is_array( $decoded ) && (string) $handle['mode'] !== (string) ( $decoded['mode'] ?? '' ) ) {
		$violations[] = 'mode_mismatch';
	}
	if ( array() !== $violations ) {
		throw new RuntimeException( 'Translation Index worker violated its exact process/JSON Interface: ' . implode( ',', $violations ) . '.' );
	}
	return $decoded;
};

/** Compare exact canonical bytes and receipt options after one failed build. */
$assert_failed_build_restored = static function ( array $baseline_rows, array $baseline_options, array $baseline_tables ) use ( &$observer, $raw_index_rows, $raw_option_rows, $owned_tables, $canonical, $call ): void {
	$current_rows = $raw_index_rows( $observer, $canonical );
	$current_options = $raw_option_rows( $observer, array( 'devenia_workflow_translation_index_schema', 'devenia_workflow_translation_index_readiness', 'devenia_workflow_translation_index_upgrade_lease', 'devenia_workflow_version' ) );
	$current_tables = $owned_tables( $observer );
	$ready = $call( 'translation_index_readiness', true );
	if ( $baseline_rows !== $current_rows || $baseline_options !== $current_options || $baseline_tables !== $current_tables || empty( $ready['success'] ) ) {
		throw new RuntimeException( 'Failed readiness build did not restore exact canonical bytes, receipt options and table portfolio.' );
	}
};

$lease_row = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'devenia_workflow_translation_index_upgrade_lease' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Refuse an active owner before the recovery journal can authorize any mutation.
$fixture_slug_placeholders = implode( ', ', array_fill( 0, count( $fixture_slugs ), '%s' ) );
$preexisting_fixture_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name IN ({$fixture_slug_placeholders}) ORDER BY ID ASC", $fixture_slugs ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact token scope must be empty before the recovery journal can authorize deletion.
$preexisting_tables = $owned_tables( $wpdb );
$reserved_tables = array_merge( array( $saved, $external_failed ), $runtime_tables );
$preexisting_markers = glob( '/tmp/devenia-ti-readiness-' . $token . '-*.ready' );
if (
	null !== $lease_row
	|| ! is_array( $preexisting_fixture_ids )
	|| array() !== $preexisting_fixture_ids
	|| array() !== array_values( array_intersect( $reserved_tables, $preexisting_tables ) )
	|| false === $preexisting_markers
	|| array() !== $preexisting_markers
) {
	throw new RuntimeException( 'Translation Index runtime refused an occupied writer or fixture scope before journaling.' );
}

$original_options = $raw_option_rows( $wpdb, $option_keys );
$original_tables = $owned_tables( $wpdb );
$original_existed = in_array( $canonical, $original_tables, true );
$original_index_rows = $original_existed ? $raw_index_rows( $wpdb, $canonical ) : array();
$journal = array(
	'journal_version'    => 1,
	'token'              => $token,
	'phase'              => 'snapshotted',
	'canonical'          => $canonical,
	'saved'              => $saved,
	'original_existed'   => $original_existed,
	'option_keys'        => $option_keys,
	'original_options'   => $original_options,
	'original_tables'    => $original_tables,
	'original_index_rows' => $original_index_rows,
	'runtime_tables'      => $runtime_tables,
	'fixture_slugs'      => $fixture_slugs,
	'fixture_posts'      => array(),
	'worker_markers'     => array(),
);
$write_recovery_journal( $journal );
$record_fixture_post = static function ( int $post_id ) use ( &$fixture_posts, &$journal, $write_recovery_journal ): void {
	if ( $post_id < 1 ) {
		throw new RuntimeException( 'Translation Index recovery journal refused an invalid fixture identity.' );
	}
	$fixture_posts[] = $post_id;
	$fixture_posts = array_values( array_unique( array_map( 'absint', $fixture_posts ) ) );
	$journal['fixture_posts'] = $fixture_posts;
	$journal['phase'] = 'fixture_recorded';
	$write_recovery_journal( $journal );
};
$main_connection_id = absint( $wpdb->get_var( 'SELECT CONNECTION_ID()' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Main/observer identity separation is asserted below.

$active_fault = '';
$throw_fault = false;
$fault_filter = static function ( bool $inject, string $phase ) use ( &$active_fault, &$throw_fault ): bool {
	unset( $inject );
	if ( $phase !== $active_fault ) {
		return false;
	}
	if ( $throw_fault ) {
		throw new RuntimeException( 'translation_index_runtime_throwable_' . $phase );
	}
	return true;
};
add_filter( 'devenia_workflow_translation_index_readiness_fault', $fault_filter, 10, 2 );
$filters[] = array( 'devenia_workflow_translation_index_readiness_fault', $fault_filter, 10 );

try {
	if ( strlen( $saved ) > 64 || in_array( $saved, $original_tables, true ) ) {
		throw new RuntimeException( 'Runtime saved table identity is unsafe.' );
	}
	if ( $original_existed ) {
		if ( false === $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $canonical, $saved ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Atomically park live dev read-model identity before destructive fixtures.
			throw new RuntimeException( 'Could not atomically park the original canonical Index.' );
		}
		$original_parked = true;
	}
	$journal['phase'] = 'canonical_parked';
	$write_recovery_journal( $journal );
	$runtime_isolated = true;
	if ( ! $restore_raw_option_rows( $option_keys, array() ) ) {
		throw new RuntimeException( 'Could not isolate Translation Index option state.' );
	}

	$bootstrap = $call( 'translation_index_ensure_ready', 'runtime_bootstrap', true );
	if ( empty( $bootstrap['success'] ) ) {
		throw new RuntimeException( 'Disposable Translation Index bootstrap failed: ' . sanitize_key( (string) ( $bootstrap['code'] ?? '' ) ) );
	}
	$pre_fixture_rows = $raw_index_rows( $wpdb, $canonical );
	$pre_fixture_tables = $owned_tables( $wpdb );
	if ( 'after_fixture_insert' === (string) getenv( 'DEVENIA_TRANSLATION_INDEX_RUNTIME_EXIT_PROBE' ) ) {
		$controlled_exit_probe = static function ( int $post_id, WP_Post $post, bool $update ) use ( $token ): void {
			unset( $post_id );
			if ( ! $update && 'translation-index-source-' . $token === (string) $post->post_name ) {
				WP_CLI::error( 'Translation Index controlled exit recovery probe.' );
			}
		};
		add_action( 'wp_after_insert_post', $controlled_exit_probe, 999, 3 );
		$filters[] = array( 'wp_after_insert_post', $controlled_exit_probe, 999 );
	}

	$source_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Translation Index source ' . $token,
			'post_name'    => 'translation-index-source-' . $token,
			'post_content' => '<!-- wp:paragraph --><p>Runtime source.</p><!-- /wp:paragraph -->',
		),
		true
	);
	if ( is_wp_error( $source_id ) ) {
		throw new RuntimeException( 'Could not create Translation Index source fixture.' );
	}
	$record_fixture_post( (int) $source_id );
	$target_languages = $call( 'target_languages' );
	$language = sanitize_key( (string) array_key_first( is_array( $target_languages ) ? $target_languages : array() ) );
	$source_language = sanitize_key( (string) $call( 'source_language_code' ) );
	if ( '' === $language || '' === $source_language || $source_language === $language ) {
		throw new RuntimeException( 'No configured target language exists for the runtime fixture.' );
	}
	$translation_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Translation Index target ' . $token,
			'post_name'    => 'translation-index-target-' . $token,
			'post_content' => '<!-- wp:paragraph --><p>Runtime target.</p><!-- /wp:paragraph -->',
		),
		true
	);
	if ( is_wp_error( $translation_id ) ) {
		throw new RuntimeException( 'Could not create Translation Index target fixture.' );
	}
	$record_fixture_post( (int) $translation_id );
	$source_hash = hash( 'sha256', 'translation-index-readiness-' . $token );
	update_post_meta( (int) $translation_id, '_devenia_translation_source_id', (int) $source_id );
	update_post_meta( (int) $translation_id, '_devenia_translation_language', $language );
	update_post_meta( (int) $translation_id, '_devenia_translation_status', 'published' );
	update_post_meta( (int) $translation_id, '_devenia_translation_source_hash', $source_hash );
	$fixture_ready = $call( 'translation_index_available', true );
	$fixture_row = $call( 'translation_index_row_for_translation', (int) $translation_id );
	$fixture_row_exact = absint( $fixture_row['translation_post_id'] ?? 0 ) === (int) $translation_id;
	$fixture_hash_exact = hash_equals( $source_hash, (string) ( $fixture_row['source_hash'] ?? '' ) );
	$fixture_lease_absent = false === get_option( 'devenia_workflow_translation_index_upgrade_lease', false );
	if ( ! $fixture_ready || ! $fixture_row_exact || ! $fixture_hash_exact || ! $fixture_lease_absent ) {
		throw new RuntimeException( sprintf( 'Translation Index fixture read-your-writes failed: ready=%d row=%d hash=%d lease_absent=%d.', $fixture_ready ? 1 : 0, $fixture_row_exact ? 1 : 0, $fixture_hash_exact ? 1 : 0, $fixture_lease_absent ? 1 : 0 ) );
	}

	$observer = $new_observer();
	$observer_connection_id = absint( $observer->get_var( 'SELECT CONNECTION_ID()' ) );
	if ( $observer_connection_id < 1 || $observer_connection_id === $main_connection_id ) {
		throw new RuntimeException( 'Separate observer connection identity was not established.' );
	}
	$version = (string) $observer->get_var( 'SELECT VERSION()' );
	if ( false === stripos( $version, '10.11.' ) || false === stripos( $version, 'MariaDB' ) ) {
		throw new RuntimeException( 'Translation Index runtime requires the MariaDB 10.11 release baseline.' );
	}
	$evidence['separate_observer_connection'] = true;

	$schema_sql = $call( 'translation_index_schema_sql', $physical_valid );
	if ( false === $wpdb->query( $schema_sql ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Runtime-owned physical fixture.
		throw new RuntimeException( 'Exact physical-contract fixture could not be created.' );
	}
	$valid_contract = $call( 'translation_index_storage_contract', $physical_valid );
	$empty_semantic_rows = $call( 'translation_index_semantic_rows', $physical_valid );
	if ( empty( $valid_contract['success'] ) || array() !== $empty_semantic_rows ) {
		throw new RuntimeException( 'Owned physical contract rejected its exact schema.' );
	}
	$evidence['empty_semantic_read_distinct'] = true;
	$engine_sql = $wpdb->prepare( 'CREATE TABLE %i (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=MyISAM', $physical_engine );
	if ( false === $wpdb->query( $engine_sql ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Minimal negative engine fixture; the physical Interface rejects engine before evaluating columns.
		throw new RuntimeException( 'MyISAM negative fixture could not be created.' );
	}
	$engine_contract = $call( 'translation_index_storage_contract', $physical_engine );
	if ( ! empty( $engine_contract['success'] ) || 'translation_index_engine_invalid' !== (string) ( $engine_contract['code'] ?? '' ) ) {
		throw new RuntimeException( 'Physical contract accepted a non-InnoDB Adapter.' );
	}
	$malformed_sql = str_replace( $physical_valid, $physical_schema, $schema_sql );
	if ( false === $wpdb->query( $malformed_sql ) || false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX language_path', $physical_schema ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Negative runtime fixture deliberately mutates only its disposable table.
		throw new RuntimeException( 'Malformed-schema negative fixture could not be created.' );
	}
	$schema_contract = $call( 'translation_index_storage_contract', $physical_schema );
	if ( ! empty( $schema_contract['success'] ) ) {
		throw new RuntimeException( 'Physical contract accepted a missing required index.' );
	}
	$negative_physical_mutations = array(
		$physical_type => 'ALTER TABLE %i MODIFY language varchar(21) NOT NULL DEFAULT \'\'',
		$physical_null => 'ALTER TABLE %i MODIFY post_status varchar(20) NULL DEFAULT NULL',
		$physical_collation => 'ALTER TABLE %i MODIFY language varchar(20) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL DEFAULT \'\'',
		$physical_prefix => 'ALTER TABLE %i DROP INDEX source_language, ADD UNIQUE KEY source_language (source_post_id, language(10))',
	);
	foreach ( $negative_physical_mutations as $fixture_table => $mutation_sql ) {
		$fixture_sql = str_replace( $physical_valid, $fixture_table, $schema_sql );
		if ( false === $wpdb->query( $fixture_sql ) || false === $wpdb->query( $wpdb->prepare( $mutation_sql, $fixture_table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Negative physical fixtures mutate only request-owned tables with fixed SQL.
			throw new RuntimeException( 'A negative physical-contract fixture could not be created.' );
		}
		$negative_contract = $call( 'translation_index_storage_contract', $fixture_table );
		if ( ! empty( $negative_contract['success'] ) ) {
			throw new RuntimeException( 'Physical contract accepted a wrong type, null/default, collation, or prefix-UNIQUE fixture.' );
		}
	}
	$evidence['physical_contract_negative'] = true;
	$prior_suppress_errors = $wpdb->suppress_errors( true );
	$posts_table_before = $wpdb->posts;
	try {
		$semantic_sql_error = $call( 'translation_index_semantic_rows', $wpdb->prefix . 'devenia_ti_runtime_absent_' . $token );
		$wpdb->posts = $wpdb->prefix . 'devenia_runtime_absent_posts_' . $token;
		$source_sql_error = $call( 'translation_index_canonical_translation_ids' );
	} finally {
		$wpdb->posts = $posts_table_before;
		$wpdb->suppress_errors( $prior_suppress_errors );
		$wpdb->last_error = '';
	}
	if ( ! is_wp_error( $semantic_sql_error ) || 'translation_index_semantic_read_failed' !== $semantic_sql_error->get_error_code() || ! is_wp_error( $source_sql_error ) || 'translation_index_source_read_failed' !== $source_sql_error->get_error_code() ) {
		throw new RuntimeException( 'A real semantic SQL error was interpreted as an empty successful read.' );
	}
	$evidence['semantic_sql_error_failed_closed'] = true;
	$evidence['source_sql_error_failed_closed'] = true;
	foreach ( array( $physical_valid, $physical_engine, $physical_schema, $physical_type, $physical_null, $physical_collation, $physical_prefix ) as $fixture_table ) {
		if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $fixture_table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Exact disposable fixture cleanup.
			throw new RuntimeException( 'Physical fixture cleanup failed.' );
		}
	}

	$baseline_rows = $raw_index_rows( $observer, $canonical );
	$baseline_options = $raw_option_rows( $observer, array( 'devenia_workflow_translation_index_schema', 'devenia_workflow_translation_index_readiness', 'devenia_workflow_translation_index_upgrade_lease', 'devenia_workflow_version' ) );
	$baseline_tables = $owned_tables( $observer );
	if ( empty( $baseline_rows ) ) {
		throw new RuntimeException( 'Fault matrix requires at least one canonical row.' );
	}
	$fixture_baseline_rows = array_values(
		array_filter(
			$baseline_rows,
			static function ( array $row ) use ( $translation_id, $source_hash ): bool {
				return absint( $row['translation_post_id'] ?? 0 ) === (int) $translation_id && hash_equals( $source_hash, (string) ( $row['source_hash'] ?? '' ) );
			}
		)
	);
	if ( 1 !== count( $fixture_baseline_rows ) ) {
		throw new RuntimeException( 'Canonical writer-before-rebuild fixture was not included exactly once.' );
	}
	$evidence['writer_before_rebuild_included'] = true;

	$boolean_faults = array( 'before_shadow_create', 'after_shadow_create', 'source_read', 'shadow_insert', 'shadow_parity', 'before_swap', 'after_swap_parity', 'schema_option', 'readiness_option', 'final_readiness' );
	foreach ( $boolean_faults as $phase ) {
		$active_fault = $phase;
		$throw_fault = false;
		$failed = $call( 'translation_index_ensure_ready', 'runtime_fault_' . $phase, true );
		$active_fault = '';
		if ( ! is_array( $failed ) || ! empty( $failed['success'] ) ) {
			throw new RuntimeException( 'Boolean readiness fault unexpectedly succeeded: ' . $phase );
		}
		$assert_failed_build_restored( $baseline_rows, $baseline_options, $baseline_tables );
	}
	$evidence['pre_swap_faults_restored'] = true;
	$evidence['post_swap_faults_restored'] = true;

	foreach ( array( 'before_swap', 'after_swap_parity', 'final_readiness' ) as $phase ) {
		$active_fault = $phase;
		$throw_fault = true;
		$failed = $call( 'translation_index_ensure_ready', 'runtime_throwable_' . $phase, true );
		$active_fault = '';
		$throw_fault = false;
		if ( ! is_array( $failed ) || ! empty( $failed['success'] ) ) {
			throw new RuntimeException( 'Throwable readiness fault unexpectedly succeeded: ' . $phase );
		}
		$assert_failed_build_restored( $baseline_rows, $baseline_options, $baseline_tables );
	}
	$evidence['throwable_faults_restored'] = true;

	$deleted_row = $baseline_rows[0];
	if ( 1 !== $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', $canonical, absint( $deleted_row['id'] ?? 0 ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deliberate partial-row corruption fixture.
		throw new RuntimeException( 'Partial-row corruption fixture could not be applied.' );
	}
	$partial = $call( 'translation_index_readiness', true );
	if ( ! empty( $partial['success'] ) || 'translation_index_parity_mismatch' !== (string) ( $partial['code'] ?? '' ) || ! $restore_raw_index_row( $canonical, $deleted_row ) || $baseline_rows !== $raw_index_rows( $observer, $canonical ) || empty( $call( 'translation_index_readiness', true )['success'] ) ) {
		throw new RuntimeException( 'Partial-row corruption did not fail closed and restore exactly.' );
	}
	$evidence['partial_row_failed_closed'] = true;

	$readiness_before_foreign = $raw_option_rows( $observer, array( 'devenia_workflow_translation_index_readiness' ) );
	$foreign_lease = array( 'token' => 'til_foreign_' . $token, 'trigger' => 'runtime_foreign', 'expires_at' => time() + 600 );
	update_option( 'devenia_workflow_translation_index_upgrade_lease', $foreign_lease, false );
	wp_cache_delete( 'devenia_workflow_translation_index_upgrade_lease', 'options' );
	$non_owner = $call( 'translation_index_ensure_ready', 'runtime_non_owner', true );
	if ( ! is_array( $non_owner ) || ! empty( $non_owner['success'] ) || 'translation_index_upgrade_in_progress' !== (string) ( $non_owner['code'] ?? '' ) || $readiness_before_foreign !== $raw_option_rows( $observer, array( 'devenia_workflow_translation_index_readiness' ) ) || $foreign_lease !== get_option( 'devenia_workflow_translation_index_upgrade_lease', array() ) ) {
		throw new RuntimeException( 'Non-owner readiness attempt mutated foreign lease or readiness authority.' );
	}
	$evidence['non_owner_preserved_readiness'] = true;
	delete_option( 'devenia_workflow_translation_index_upgrade_lease' );

	$expired_lease = array( 'token' => 'til_expired_' . $token, 'trigger' => 'runtime_expired', 'expires_at' => time() - 1 );
	update_option( 'devenia_workflow_translation_index_upgrade_lease', $expired_lease, false );
	wp_cache_delete( 'devenia_workflow_translation_index_upgrade_lease', 'options' );
	$takeover = $call( 'translation_index_acquire_upgrade_lease', 'runtime_takeover' );
	$taken_lease = is_array( $takeover ) ? (array) ( $takeover['lease'] ?? array() ) : array();
	$taken = ! empty( $takeover['success'] ) && ! hash_equals( (string) $expired_lease['token'], (string) ( $taken_lease['token'] ?? '' ) );
	$released = $taken && $call( 'translation_index_release_upgrade_lease', $taken_lease );
	if ( ! $taken || ! $released || false !== get_option( 'devenia_workflow_translation_index_upgrade_lease', false ) || $readiness_before_foreign !== $raw_option_rows( $observer, array( 'devenia_workflow_translation_index_readiness' ) ) ) {
		throw new RuntimeException( 'Expired lease takeover did not preserve readiness and release exactly.' );
	}
	$evidence['expired_lease_takeover'] = true;

	$old_owner_rows = $raw_index_rows( $observer, $canonical );
	$old_owner_options = $raw_option_rows( $observer, array( 'devenia_workflow_translation_index_schema', 'devenia_workflow_translation_index_readiness' ) );
	$old_owner_tables = $owned_tables( $observer );
	$old_owner = $call( 'translation_index_acquire_upgrade_lease', 'runtime_old_owner' );
	$old_lease = is_array( $old_owner ) ? (array) ( $old_owner['lease'] ?? array() ) : array();
	if ( empty( $old_owner['success'] ) || empty( $old_lease['token'] ) ) {
		throw new RuntimeException( 'Could not establish the old-owner lease fixture.' );
	}
	$successor_lease = array( 'token' => 'til_successor_' . $token, 'trigger' => 'runtime_successor', 'expires_at' => time() + 600 );
	update_option( 'devenia_workflow_translation_index_upgrade_lease', $successor_lease, false );
	wp_cache_delete( 'devenia_workflow_translation_index_upgrade_lease', 'options' );
	$old_owner_build = $call( 'translation_index_build_ready', 'runtime_old_owner_after_takeover' );
	$old_owner_release = $call( 'translation_index_release_upgrade_lease', $old_lease );
	if ( ! is_array( $old_owner_build ) || ! empty( $old_owner_build['success'] ) || $old_owner_release || $successor_lease !== get_option( 'devenia_workflow_translation_index_upgrade_lease', array() ) || $old_owner_rows !== $raw_index_rows( $observer, $canonical ) || $old_owner_options !== $raw_option_rows( $observer, array( 'devenia_workflow_translation_index_schema', 'devenia_workflow_translation_index_readiness' ) ) || $old_owner_tables !== $owned_tables( $observer ) ) {
		throw new RuntimeException( 'A displaced old lease owner reached swap or damaged successor authority.' );
	}
	$evidence['old_owner_swap_rejected'] = true;
	delete_option( 'devenia_workflow_translation_index_upgrade_lease' );

	$rebuild_worker_result = array();
	$rebuild_worker_started = false;
	$rebuild_worker_filter = static function ( bool $inject, string $phase ) use ( &$rebuild_worker_result, &$rebuild_worker_started, $start_worker, $finish_worker ): bool {
		unset( $inject );
		if ( 'after_shadow_create' !== $phase || $rebuild_worker_started ) {
			return false;
		}
		$rebuild_worker_started = true;
		$handle = $start_worker( 'ensure_ready', array() );
		$rebuild_worker_result = $finish_worker( $handle );
		return false;
	};
	add_filter( 'devenia_workflow_translation_index_readiness_fault', $rebuild_worker_filter, 20, 2 );
	$filters[] = array( 'devenia_workflow_translation_index_readiness_fault', $rebuild_worker_filter, 20 );
	$rebuild_with_competitor = $call( 'translation_index_ensure_ready', 'runtime_rebuild_competitor', true );
	remove_filter( 'devenia_workflow_translation_index_readiness_fault', $rebuild_worker_filter, 20 );
	if ( empty( $rebuild_with_competitor['success'] ) || empty( $rebuild_worker_result['success'] ) || empty( $rebuild_worker_result['ready'] ) || absint( $rebuild_worker_result['connection_id'] ?? 0 ) === $main_connection_id ) {
		throw new RuntimeException( 'Two separate rebuild processes were not serialized by the shared lease: main=' . sanitize_key( (string) ( $rebuild_with_competitor['code'] ?? 'missing' ) ) . '; worker=' . sanitize_key( (string) ( $rebuild_worker_result['code'] ?? 'missing' ) ) . '; worker_success=' . ( ! empty( $rebuild_worker_result['success'] ) ? '1' : '0' ) . '; worker_ready=' . ( ! empty( $rebuild_worker_result['ready'] ) ? '1' : '0' ) . '.' );
	}
	$evidence['rebuild_rebuild_serialized'] = true;

	$concurrent_handle = null;
	$concurrent_started = false;
	$reader_saw_last_good = false;
	$reader_rows_before_shadow = $raw_index_rows( $observer, $canonical );
	$reader_options_before_shadow = $raw_option_rows( $observer, array( 'devenia_workflow_translation_index_schema', 'devenia_workflow_translation_index_readiness' ) );
	$concurrent_filter = static function ( bool $inject, string $phase ) use ( &$concurrent_handle, &$concurrent_started, &$reader_saw_last_good, $reader_rows_before_shadow, $reader_options_before_shadow, $raw_index_rows, $raw_option_rows, &$observer, $canonical, $start_worker, $translation_id, $source_hash, $token ): bool {
		unset( $inject );
		if ( 'after_shadow_create' !== $phase || $concurrent_started ) {
			return false;
		}
		$concurrent_started = true;
		$reader_saw_last_good = $reader_rows_before_shadow === $raw_index_rows( $observer, $canonical )
			&& $reader_options_before_shadow === $raw_option_rows( $observer, array( 'devenia_workflow_translation_index_schema', 'devenia_workflow_translation_index_readiness' ) );
		$concurrent_handle = $start_worker(
			'canonical_writer',
			array(
				'post_id'     => (int) $translation_id,
				'meta_key'    => '_devenia_translation_source_hash',
				'expected'    => $source_hash,
				'replacement' => hash( 'sha256', 'concurrent-' . $token ),
			)
		);
		usleep( 250000 );
		$status = proc_get_status( $concurrent_handle['process'] );
		if ( ! is_array( $status ) || empty( $status['running'] ) ) {
			throw new RuntimeException( 'Canonical writer did not block behind the rebuild lease.' );
		}
		return false;
	};
	add_filter( 'devenia_workflow_translation_index_readiness_fault', $concurrent_filter, 20, 2 );
	$filters[] = array( 'devenia_workflow_translation_index_readiness_fault', $concurrent_filter, 20 );
	$concurrent_rebuild = $call( 'translation_index_ensure_ready', 'runtime_canonical_concurrency', true );
	remove_filter( 'devenia_workflow_translation_index_readiness_fault', $concurrent_filter, 20 );
	$writer = is_array( $concurrent_handle ) ? $finish_worker( $concurrent_handle ) : array();
	if ( empty( $concurrent_rebuild['success'] ) || ! $reader_saw_last_good || empty( $writer['success'] ) || empty( $writer['restored'] ) || empty( $writer['ready'] ) || absint( $writer['wait_ms'] ?? 0 ) < 200 || absint( $writer['connection_id'] ?? 0 ) === $main_connection_id || ! hash_equals( $source_hash, (string) get_post_meta( (int) $translation_id, '_devenia_translation_source_hash', true ) ) ) {
		throw new RuntimeException( 'Rebuild/canonical-writer serialization was not proven.' );
	}
	$evidence['reader_saw_last_good_canonical'] = true;
	$evidence['canonical_writer_serialized'] = true;
	$evidence['writer_after_rebuild_twice'] = true;

	$recovery_before = $raw_index_rows( $observer, $canonical );
	$recovery_options_before = $raw_option_rows( $observer, array_slice( $option_keys, 0, 3 ) );
	$recovery_readiness_before = (array) $call( 'translation_index_readiness', true );
	$recovery_active = (bool) $call( 'translation_job_begin_recovery_transaction' );
	if ( ! $recovery_active ) {
		$recovery_diagnostic = (array) $call( 'translation_job_recovery_transaction_diagnostic' );
		throw new RuntimeException( 'Owned recovery transaction could not start for concurrency proof: phase=' . sanitize_key( (string) ( $recovery_diagnostic['phase'] ?? 'missing' ) ) . '; code=' . sanitize_key( (string) ( $recovery_diagnostic['code'] ?? 'missing' ) ) . '; readiness=' . sanitize_key( (string) ( $recovery_readiness_before['code'] ?? 'missing' ) ) . '.' );
	}
	$recovery_worker_handle = $start_worker( 'ensure_ready', array() );
	$recovery_worker = $finish_worker( $recovery_worker_handle );
	$observer_rows_under_recovery = $raw_index_rows( $observer, $canonical );
	$observer_options_under_recovery = $raw_option_rows( $observer, array_slice( $option_keys, 0, 3 ) );
	$rollback = $call( 'translation_job_rollback_recovery_transaction' );
	$recovery_active = false;
	if ( empty( $recovery_worker['success'] ) || empty( $recovery_worker['ready'] ) || absint( $recovery_worker['wait_ms'] ?? 0 ) < 800 || absint( $recovery_worker['connection_id'] ?? 0 ) === $main_connection_id || $recovery_before !== $observer_rows_under_recovery || $recovery_options_before !== $observer_options_under_recovery || empty( $rollback['success'] ) || empty( $rollback['rolled_back'] ) || false !== get_option( 'devenia_workflow_translation_index_upgrade_lease', false ) || empty( $call( 'translation_index_readiness', true )['success'] ) ) {
		throw new RuntimeException( 'Recovery/rebuild predicate-lock serialization was not proven.' );
	}
	$evidence['recovery_rebuild_serialized'] = true;

	$version_before = get_option( 'devenia_workflow_version', '__missing__' );
	$runtime_version = '0.1.618';
	update_option( 'devenia_workflow_version', $runtime_version, false );
	$version_fault_phase = 'before_write';
	$version_fault = static function ( bool $inject, string $phase ) use ( &$version_fault_phase ): bool {
		unset( $inject );
		return $phase === $version_fault_phase;
	};
	add_filter( 'devenia_workflow_version_upgrade_fault', $version_fault, 10, 2 );
	$filters[] = array( 'devenia_workflow_version_upgrade_fault', $version_fault, 10 );
	$version_before_failed = ! $call( 'finalize_workflow_version_upgrade', $runtime_version ) && $runtime_version === get_option( 'devenia_workflow_version', '' );
	$version_fault_phase = 'after_write';
	$version_after_failed = ! $call( 'finalize_workflow_version_upgrade', $runtime_version ) && $runtime_version === get_option( 'devenia_workflow_version', '' );
	$version_fault_phase = '';
	$version_succeeded = $call( 'finalize_workflow_version_upgrade', $runtime_version ) && Devenia_Workflow::VERSION === get_option( 'devenia_workflow_version', '' );
	remove_filter( 'devenia_workflow_version_upgrade_fault', $version_fault, 10 );
	if ( ! $version_before_failed || ! $version_after_failed || ! $version_succeeded ) {
		throw new RuntimeException( 'Version finalization fault/restore matrix failed.' );
	}
	$evidence['version_faults_restored'] = true;
	if ( '__missing__' === $version_before ) {
		delete_option( 'devenia_workflow_version' );
	} else {
		update_option( 'devenia_workflow_version', $version_before, false );
	}

	$activation_rows_before = $raw_index_rows( $observer, $canonical );
	$activation_readiness_before = get_option( 'devenia_workflow_translation_index_readiness', array() );
	update_option( 'devenia_workflow_translation_index_readiness', array( 'runtime' => 'invalid' ), false );
	$active_fault = 'before_shadow_create';
	$activation_threw = false;
	try {
		Devenia_Workflow::activate();
	} catch ( Throwable $activation_error ) {
		$activation_threw = true;
		unset( $activation_error );
	}
	$active_fault = '';
	$activation_failed_state = get_option( 'devenia_workflow_translation_index_readiness', array() );
	update_option( 'devenia_workflow_translation_index_readiness', $activation_readiness_before, false );
	wp_cache_delete( 'devenia_workflow_translation_index_readiness', 'options' );
	if ( ! $activation_threw || array( 'runtime' => 'invalid' ) !== $activation_failed_state || $activation_rows_before !== $raw_index_rows( $observer, $canonical ) || empty( $call( 'translation_index_readiness', true )['success'] ) ) {
		throw new RuntimeException( 'Activation did not fail closed before unrelated storage mutation.' );
	}
	$evidence['activation_failed_closed'] = true;
	Devenia_Workflow::activate();
	if ( empty( $call( 'translation_index_readiness', true )['success'] ) ) {
		throw new RuntimeException( 'Successful activation did not establish fresh Translation Index readiness.' );
	}
	$evidence['activation_succeeded_ready'] = true;

	$frames_property = new ReflectionProperty( Devenia_Workflow::class, 'translation_index_canonical_mutation_boundaries' );
	$frames_property->setAccessible( true );
	$lease_depth_property = new ReflectionProperty( Devenia_Workflow::class, 'translation_index_lease_depth' );
	$lease_depth_property->setAccessible( true );
	$lease_token_property = new ReflectionProperty( Devenia_Workflow::class, 'translation_index_upgrade_lease_token' );
	$lease_token_property->setAccessible( true );
	$callback_intermediate_hash = hash( 'sha256', 'callback-intermediate-' . $token );
	$callback_hash = hash( 'sha256', 'callback-throwable-' . $token );
	$callback_first_write = update_post_meta( (int) $translation_id, '_devenia_translation_source_hash', $callback_intermediate_hash, $source_hash );
	$callback_second_write = update_post_meta( (int) $translation_id, '_devenia_translation_source_hash', $callback_hash, $callback_intermediate_hash );
	$callback_frames_before = $frames_property->getValue();
	$callback_lease_depth_before = (int) $lease_depth_property->getValue();
	if ( false === $callback_first_write || false === $callback_second_write || ! is_array( $callback_frames_before ) || count( $callback_frames_before ) < 2 || $callback_lease_depth_before < 2 ) {
		throw new RuntimeException( 'Callback-Throwable fixture did not establish multiple canonical frame leases.' );
	}
	$active_fault = 'canonical_scope_sync_exception';
	$throw_fault = true;
	try {
		$callback_completed = Devenia_Workflow::complete_pending_translation_index_mutations();
	} finally {
		$active_fault = '';
		$throw_fault = false;
	}
	$callback_failure = get_option( 'devenia_workflow_translation_index_last_failure', array() );
	$callback_frames = $frames_property->getValue();
	$callback_lease_depth = (int) $lease_depth_property->getValue();
	$callback_lease_token = (string) $lease_token_property->getValue();
	if (
		$callback_completed
		|| ! is_array( $callback_failure )
		|| 'translation_index_canonical_scope_sync_exception' !== (string) ( $callback_failure['code'] ?? '' )
		|| 'canonical_request_batch' !== (string) ( $callback_failure['trigger'] ?? '' )
		|| ! is_array( $callback_frames )
		|| array() !== $callback_frames
		|| 0 !== $callback_lease_depth
		|| '' !== $callback_lease_token
		|| false !== get_option( 'devenia_workflow_translation_index_upgrade_lease', false )
	) {
		throw new RuntimeException( 'Callback Throwable did not release every frame lease and persist exact failure authority.' );
	}
	$callback_recovery = $call( 'translation_index_ensure_ready', 'runtime_callback_throwable_recovery', true );
	$callback_row = $call( 'translation_index_row_for_translation', (int) $translation_id );
	if ( empty( $callback_recovery['success'] ) || ! hash_equals( $callback_hash, (string) ( $callback_row['source_hash'] ?? '' ) ) ) {
		throw new RuntimeException( 'Callback-Throwable recovery did not rebuild exact canonical state.' );
	}
	if ( false === update_post_meta( (int) $translation_id, '_devenia_translation_source_hash', $source_hash, $callback_hash ) ) {
		throw new RuntimeException( 'Callback-Throwable fixture source hash could not be restored.' );
	}
	$callback_restored_ready = (bool) $call( 'translation_index_available', true );
	$callback_restored_row = $call( 'translation_index_row_for_translation', (int) $translation_id );
	if ( ! $callback_restored_ready || ! hash_equals( $source_hash, (string) ( $callback_restored_row['source_hash'] ?? '' ) ) || false !== get_option( 'devenia_workflow_translation_index_upgrade_lease', false ) || false !== get_option( 'devenia_workflow_translation_index_last_failure', false ) ) {
		throw new RuntimeException( 'Callback-Throwable fixture did not restore exact ready state.' );
	}
	$evidence['callback_throwable_released_frames'] = true;

	$alternate_source_content = '<!-- wp:paragraph --><p>Runtime alternate source ' . $token . '.</p><!-- /wp:paragraph -->';
	$alternate_source_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Translation Index alternate source ' . $token,
			'post_name'    => 'translation-index-alternate-source-' . $token,
			'post_content' => $alternate_source_content,
		),
		true
	);
	if ( is_wp_error( $alternate_source_id ) ) {
		throw new RuntimeException( 'Could not create the alternate-source cache fixture.' );
	}
	$alternate_source_id = (int) $alternate_source_id;
	$record_fixture_post( $alternate_source_id );
	$source_url = (string) get_permalink( (int) $source_id );
	$alternate_source_url = (string) get_permalink( $alternate_source_id );
	$target_url_before = (string) get_permalink( (int) $translation_id );
	if ( '' === $source_url || '' === $alternate_source_url || '' === $target_url_before ) {
		throw new RuntimeException( 'Same-request cache fixtures require exact WordPress permalinks.' );
	}

	$translation_ids = static function ( array $posts ): array {
		$ids = array_values( array_filter( array_map( static fn( $post ): int => $post instanceof WP_Post ? (int) $post->ID : 0, $posts ) ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	};
	$link_id = static function ( array $links, string $code ): int {
		return absint( $links[ $code ]['id'] ?? 0 );
	};
	$link_url = static function ( array $links, string $code ): string {
		return (string) ( $links[ $code ]['url'] ?? '' );
	};
	$mapped_target = static function ( array $map, string $url ) use ( $call ): ?string {
		$target = $call( 'localized_internal_link_target', $url, $map );
		return is_string( $target ) ? $target : null;
	};
	$urls_match = static function ( ?string $left, ?string $right ) use ( $call ): bool {
		return is_string( $left ) && is_string( $right ) && '' !== $left && '' !== $right
			&& $call( 'normalized_comparable_url', $left ) === $call( 'normalized_comparable_url', $right );
	};

	$primary_posts_before = $translation_ids( $call( 'translation_posts_for_source', (int) $source_id, array( 'publish' ) ) );
	$alternate_posts_before = $translation_ids( $call( 'translation_posts_for_source', $alternate_source_id, array( 'publish' ) ) );
	$primary_find_before = (int) $call( 'find_translation_id', (int) $source_id, $language, array( 'publish' ) );
	$alternate_find_before = (int) $call( 'find_translation_id', $alternate_source_id, $language, array( 'publish' ) );
	$internal_map_before = (array) $call( 'localized_internal_link_map', $language );
	$expected_map_before = (array) $call( 'localized_link_expected_target_map', $language );
	$internal_target_before = $mapped_target( $internal_map_before, $source_url );
	$expected_target_before = $mapped_target( $expected_map_before, $source_url );
	$surface_before = (array) $call( 'frontend_surface', (int) $translation_id );
	$surface_links_before = (array) $call( 'frontend_surface_with_links', (int) $translation_id );
	$quality_before = (array) $call( 'quality_engine_content_snapshot', get_post( (int) $translation_id ), array() );
	if (
		array( (int) $translation_id ) !== $primary_posts_before
		|| array() !== $alternate_posts_before
		|| (int) $translation_id !== $primary_find_before
		|| 0 !== $alternate_find_before
		|| ! $urls_match( $internal_target_before, $target_url_before )
		|| ! $urls_match( $expected_target_before, $target_url_before )
		|| (int) $source_id !== absint( $surface_before['source_id'] ?? 0 )
		|| (int) $source_id !== $link_id( (array) ( $surface_links_before['links'] ?? array() ), $source_language )
		|| (int) $translation_id !== $link_id( (array) ( $surface_links_before['links'] ?? array() ), $language )
		|| (int) $source_id !== absint( $quality_before['source_id'] ?? 0 )
		|| (string) get_post_field( 'post_content', (int) $source_id ) !== (string) ( $quality_before['source_content'] ?? '' )
	) {
		throw new RuntimeException( 'Initial same-request cache fixtures were not exact.' );
	}

	$reattached = update_post_meta( (int) $translation_id, '_devenia_translation_source_id', $alternate_source_id, (int) $source_id );
	if ( false === $reattached ) {
		throw new RuntimeException( 'Could not reattach the translation cache fixture.' );
	}
	$primary_posts_after_reattach = $translation_ids( $call( 'translation_posts_for_source', (int) $source_id, array( 'publish' ) ) );
	$alternate_posts_after_reattach = $translation_ids( $call( 'translation_posts_for_source', $alternate_source_id, array( 'publish' ) ) );
	$primary_find_after_reattach = (int) $call( 'find_translation_id', (int) $source_id, $language, array( 'publish' ) );
	$alternate_find_after_reattach = (int) $call( 'find_translation_id', $alternate_source_id, $language, array( 'publish' ) );
	$internal_map_after_reattach = (array) $call( 'localized_internal_link_map', $language );
	$expected_map_after_reattach = (array) $call( 'localized_link_expected_target_map', $language );
	$surface_after_reattach = (array) $call( 'frontend_surface', (int) $translation_id );
	$surface_links_after_reattach = (array) $call( 'frontend_surface_with_links', (int) $translation_id );
	$quality_after_reattach = (array) $call( 'quality_engine_content_snapshot', get_post( (int) $translation_id ), array() );
	if (
		array() !== $primary_posts_after_reattach
		|| array( (int) $translation_id ) !== $alternate_posts_after_reattach
		|| 0 !== $primary_find_after_reattach
		|| (int) $translation_id !== $alternate_find_after_reattach
		|| null !== $mapped_target( $internal_map_after_reattach, $source_url )
		|| null !== $mapped_target( $expected_map_after_reattach, $source_url )
		|| ! $urls_match( $mapped_target( $internal_map_after_reattach, $alternate_source_url ), $target_url_before )
		|| ! $urls_match( $mapped_target( $expected_map_after_reattach, $alternate_source_url ), $target_url_before )
		|| $alternate_source_id !== absint( $surface_after_reattach['source_id'] ?? 0 )
		|| $alternate_source_id !== $link_id( (array) ( $surface_links_after_reattach['links'] ?? array() ), $source_language )
		|| (int) $translation_id !== $link_id( (array) ( $surface_links_after_reattach['links'] ?? array() ), $language )
		|| in_array( (int) $source_id, array_map( 'absint', array_column( (array) ( $surface_links_after_reattach['links'] ?? array() ), 'id' ) ), true )
		|| $alternate_source_id !== absint( $quality_after_reattach['source_id'] ?? 0 )
		|| $alternate_source_content !== (string) ( $quality_after_reattach['source_content'] ?? '' )
	) {
		throw new RuntimeException( 'Reattach remained stale in a same-request Translation Index consumer.' );
	}

	$alternate_source_content_changed = '<!-- wp:paragraph --><p>Runtime alternate source changed ' . $token . '.</p><!-- /wp:paragraph -->';
	$source_changed = wp_update_post(
		array(
			'ID'           => $alternate_source_id,
			'post_content' => $alternate_source_content_changed,
		),
		true
	);
	if ( is_wp_error( $source_changed ) ) {
		throw new RuntimeException( 'Could not mutate the Quality source-content fixture.' );
	}
	$quality_after_source_change = (array) $call( 'quality_engine_content_snapshot', get_post( (int) $translation_id ), array() );
	if ( $alternate_source_id !== absint( $quality_after_source_change['source_id'] ?? 0 ) || $alternate_source_content_changed !== (string) ( $quality_after_source_change['source_content'] ?? '' ) ) {
		throw new RuntimeException( 'Quality snapshot reused stale source content in the same request.' );
	}
	$evidence['quality_snapshot_same_request_coherent'] = true;

	if ( ! wp_delete_post( (int) $translation_id, true ) ) {
		throw new RuntimeException( 'Could not delete the old localized-link target fixture.' );
	}
	$internal_map_between_targets = (array) $call( 'localized_internal_link_map', $language );
	$expected_map_between_targets = (array) $call( 'localized_link_expected_target_map', $language );
	$posts_between_targets = $translation_ids( $call( 'translation_posts_for_source', $alternate_source_id, array( 'publish' ) ) );
	$find_between_targets = (int) $call( 'find_translation_id', $alternate_source_id, $language, array( 'publish' ) );
	if ( null !== $mapped_target( $internal_map_between_targets, $alternate_source_url ) || null !== $mapped_target( $expected_map_between_targets, $alternate_source_url ) || array() !== $posts_between_targets || 0 !== $find_between_targets ) {
		throw new RuntimeException( 'Consumers retained the old target after its same-request deletion.' );
	}

	$replacement_translation_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Translation Index replacement target ' . $token,
			'post_name'    => 'translation-index-target-new-' . $token,
			'post_content' => '<!-- wp:paragraph --><p>Runtime replacement target.</p><!-- /wp:paragraph -->',
		),
		true
	);
	if ( is_wp_error( $replacement_translation_id ) ) {
		throw new RuntimeException( 'Could not create the replacement localized-link target fixture.' );
	}
	$replacement_translation_id = (int) $replacement_translation_id;
	$record_fixture_post( $replacement_translation_id );
	update_post_meta( $replacement_translation_id, '_devenia_translation_source_id', $alternate_source_id );
	update_post_meta( $replacement_translation_id, '_devenia_translation_language', $language );
	update_post_meta( $replacement_translation_id, '_devenia_translation_status', 'published' );
	update_post_meta( $replacement_translation_id, '_devenia_translation_source_hash', hash( 'sha256', 'replacement-' . $token ) );
	$replacement_published = wp_update_post( array( 'ID' => $replacement_translation_id, 'post_status' => 'publish' ), true );
	if ( is_wp_error( $replacement_published ) || 'publish' !== get_post_status( $replacement_translation_id ) ) {
		throw new RuntimeException( 'Could not publish the bound replacement localized-link target fixture.' );
	}
	$translation_id = $replacement_translation_id;
	$target_url_after = (string) get_permalink( $replacement_translation_id );
	$internal_map_after_target = (array) $call( 'localized_internal_link_map', $language );
	$expected_map_after_target = (array) $call( 'localized_link_expected_target_map', $language );
	$surface_links_after_target = (array) $call( 'frontend_surface_with_links', $replacement_translation_id );
	$posts_after_target = $translation_ids( $call( 'translation_posts_for_source', $alternate_source_id, array( 'publish' ) ) );
	$find_after_target = (int) $call( 'find_translation_id', $alternate_source_id, $language, array( 'publish' ) );
	$target_failures = array();
	if ( '' === $target_url_after ) {
		$target_failures[] = 'target_url_empty';
	}
	if ( $urls_match( $target_url_before, $target_url_after ) ) {
		$target_failures[] = 'permalink_unchanged';
	}
	if ( ! $urls_match( $mapped_target( $internal_map_after_target, $alternate_source_url ), $target_url_after ) ) {
		$target_failures[] = 'internal_map_stale';
	}
	if ( ! $urls_match( $mapped_target( $expected_map_after_target, $alternate_source_url ), $target_url_after ) ) {
		$target_failures[] = 'expected_map_stale';
	}
	if ( ! $urls_match( $link_url( (array) ( $surface_links_after_target['links'] ?? array() ), $language ), $target_url_after ) ) {
		$target_failures[] = 'hreflang_stale';
	}
	if ( array( $replacement_translation_id ) !== $posts_after_target ) {
		$target_failures[] = 'translation_posts_stale';
	}
	if ( $replacement_translation_id !== $find_after_target ) {
		$target_failures[] = 'find_translation_stale';
	}
	if ( array() !== $target_failures ) {
		throw new RuntimeException( 'Localized link or hreflang consumer reused the old target in the same request: ' . implode( ',', $target_failures ) . '.' );
	}
	$evidence['frontend_surface_same_request_coherent'] = true;

	$author_ids = get_users( array( 'role__in' => array( 'administrator' ), 'number' => 1, 'fields' => 'ids' ) );
	$author_id = absint( is_array( $author_ids ) ? ( $author_ids[0] ?? 0 ) : 0 );
	if ( $author_id < 1 ) {
		throw new RuntimeException( 'Author-archive cache fixture requires an existing administrator author.' );
	}
	$author_source_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_author'  => $author_id,
			'post_title'   => 'Translation Index author source ' . $token,
			'post_name'    => 'translation-index-author-source-' . $token,
			'post_content' => '<!-- wp:paragraph --><p>Runtime author source.</p><!-- /wp:paragraph -->',
		),
		true
	);
	if ( is_wp_error( $author_source_id ) ) {
		throw new RuntimeException( 'Could not create the author source fixture.' );
	}
	$author_source_id = (int) $author_source_id;
	$record_fixture_post( $author_source_id );
	$author_before = array_map( 'absint', $call( 'localized_author_archive_post_ids', $author_id, $language ) );
	$author_translation_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_author'  => $author_id,
			'post_title'   => 'Translation Index author target ' . $token,
			'post_name'    => 'translation-index-author-target-' . $token,
			'post_content' => '<!-- wp:paragraph --><p>Runtime author target.</p><!-- /wp:paragraph -->',
		),
		true
	);
	if ( is_wp_error( $author_translation_id ) ) {
		throw new RuntimeException( 'Could not create the author translation fixture.' );
	}
	$author_translation_id = (int) $author_translation_id;
	$record_fixture_post( $author_translation_id );
	update_post_meta( $author_translation_id, '_devenia_translation_source_id', $author_source_id );
	update_post_meta( $author_translation_id, '_devenia_translation_language', $language );
	update_post_meta( $author_translation_id, '_devenia_translation_status', 'published' );
	update_post_meta( $author_translation_id, '_devenia_translation_source_hash', hash( 'sha256', 'author-' . $token ) );
	$author_after_publish = array_map( 'absint', $call( 'localized_author_archive_post_ids', $author_id, $language ) );
	if ( ! wp_delete_post( $author_translation_id, true ) ) {
		throw new RuntimeException( 'Could not delete the author translation fixture.' );
	}
	$author_after_delete = array_map( 'absint', $call( 'localized_author_archive_post_ids', $author_id, $language ) );
	if ( ! in_array( $author_source_id, $author_before, true ) || in_array( $author_translation_id, $author_before, true ) || ! in_array( $author_translation_id, $author_after_publish, true ) || in_array( $author_translation_id, $author_after_delete, true ) || ! in_array( $author_source_id, $author_after_delete, true ) ) {
		throw new RuntimeException( 'Localized author archive reused a stale positive or empty same-request result.' );
	}
	$evidence['localized_author_archive_same_request_coherent'] = true;

	if ( ! wp_delete_post( (int) $translation_id, true ) ) {
		throw new RuntimeException( 'Could not delete the primary translation cache fixture.' );
	}
	$internal_map_after_delete = (array) $call( 'localized_internal_link_map', $language );
	$expected_map_after_delete = (array) $call( 'localized_link_expected_target_map', $language );
	$primary_posts_after_delete = $translation_ids( $call( 'translation_posts_for_source', $alternate_source_id, array( 'publish' ) ) );
	$primary_find_after_delete = (int) $call( 'find_translation_id', $alternate_source_id, $language, array( 'publish' ) );
	if ( null !== $mapped_target( $internal_map_after_delete, $alternate_source_url ) || null !== $mapped_target( $expected_map_after_delete, $alternate_source_url ) ) {
		throw new RuntimeException( 'Localized link map retained a deleted target in the same request.' );
	}
	if ( array() !== $primary_posts_after_delete ) {
		throw new RuntimeException( 'Translation posts retained a deleted target in the same request.' );
	}
	if ( 0 !== $primary_find_after_delete ) {
		throw new RuntimeException( 'Translation identity retained a deleted target in the same request.' );
	}
	$evidence['translation_posts_same_request_coherent'] = true;
	$evidence['find_translation_id_same_request_coherent'] = true;
	$evidence['localized_link_maps_same_request_coherent'] = true;

	$final_tables = $owned_tables( $observer );
	if ( $baseline_tables !== $final_tables || empty( $call( 'translation_index_readiness', true )['success'] ) ) {
		throw new RuntimeException( 'Translation Index runtime left a fixture table or unready canonical state.' );
	}
	foreach ( array_reverse( array_unique( array_map( 'absint', $fixture_posts ) ) ) as $post_id ) {
		if ( $post_id > 0 && get_post( $post_id ) instanceof WP_Post && ! wp_delete_post( $post_id, true ) ) {
			throw new RuntimeException( 'Translation Index fixture post could not be deleted before the residue oracle.' );
		}
	}
	$fixture_boundaries_complete = Devenia_Workflow::complete_pending_translation_index_mutations();
	$fixture_reader_clean = (bool) $call( 'translation_index_available', true );
	$fixture_post_count = 0;
	$fixture_meta_count = 0;
	if ( ! empty( $fixture_posts ) ) {
		$placeholders = implode( ', ', array_fill( 0, count( $fixture_posts ), '%d' ) );
		$fixture_post_count = absint( $observer->get_var( $observer->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN ({$placeholders})", $fixture_posts ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact pre-finally fixture residue proof.
		$fixture_meta_count = absint( $observer->get_var( $observer->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN ({$placeholders})", $fixture_posts ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact pre-finally fixture residue proof.
	}
	if ( ! $fixture_boundaries_complete || ! $fixture_reader_clean || false !== get_option( 'devenia_workflow_translation_index_upgrade_lease', false ) || 0 !== $fixture_post_count || 0 !== $fixture_meta_count || $pre_fixture_rows !== $raw_index_rows( $observer, $canonical ) || $pre_fixture_tables !== $owned_tables( $observer ) ) {
		throw new RuntimeException( 'Translation Index fixture residue remained before the final safety cleanup.' );
	}
	$evidence['fixture_residue_zero_before_finally'] = true;

	$missing_evidence = array_keys( array_filter( $evidence, static fn( bool $passed ): bool => ! $passed ) );
	if ( ! empty( $missing_evidence ) ) {
		throw new RuntimeException( 'Translation Index runtime omitted evidence: ' . implode( ',', $missing_evidence ) );
	}
	$result = array_merge(
		array(
			'success'   => ! in_array( false, $evidence, true ),
			'database'  => 'mariadb-10.11',
			'scenarios' => count( $evidence ),
		),
		$evidence
	);
} catch ( Throwable $runtime_error ) {
	$error = $runtime_error;
} finally {
	$active_fault = '';
	$throw_fault = false;
	foreach ( array_reverse( $filters ) as $filter ) {
		remove_filter( $filter[0], $filter[1], $filter[2] );
	}
	if ( $recovery_active ) {
		try {
			$call( 'translation_job_rollback_recovery_transaction' );
		} catch ( Throwable $rollback_error ) {
			unset( $rollback_error );
		}
	}
	foreach ( $worker_handles as $handle ) {
		if ( ! isset( $handle['process'] ) || ! is_resource( $handle['process'] ) ) {
			continue;
		}
		$status = proc_get_status( $handle['process'] );
		if ( is_array( $status ) && ! empty( $status['running'] ) ) {
			proc_terminate( $handle['process'] );
		}
	}
	foreach ( $worker_markers as $marker ) {
		if ( is_file( $marker ) ) {
			unlink( $marker );
		}
	}
	$fixture_cleanup_error = null;
	try {
		foreach ( array_reverse( array_unique( array_map( 'absint', $fixture_posts ) ) ) as $post_id ) {
			if ( $post_id > 0 ) {
				wp_delete_post( $post_id, true );
			}
		}
	} catch ( Throwable $delete_error ) {
		$fixture_cleanup_error = $delete_error;
	} finally {
		try {
			$fixture_boundaries_complete = Devenia_Workflow::complete_pending_translation_index_mutations();
			$fixture_reader_clean = $runtime_isolated ? (bool) $call( 'translation_index_available', true ) : true;
			$fixture_lease_released = ! $runtime_isolated || false === get_option( 'devenia_workflow_translation_index_upgrade_lease', false );
			if ( ! $fixture_boundaries_complete || ! $fixture_reader_clean || ! $fixture_lease_released ) {
				throw new RuntimeException( 'Fixture cleanup did not terminalize its canonical mutation batch before table restoration.' );
			}
		} catch ( Throwable $boundary_error ) {
			if ( null === $fixture_cleanup_error ) {
				$fixture_cleanup_error = $boundary_error;
			}
		}
	}
	foreach ( array_reverse( array_unique( array_map( 'absint', $fixture_posts ) ) ) as $post_id ) {
		if ( $post_id > 0 ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Last-resort exact fixture cleanup after a hook failure.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE ID = %d", $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Last-resort exact fixture cleanup after a hook failure.
			clean_post_cache( $post_id );
		}
	}
	if ( $fixture_cleanup_error instanceof Throwable ) {
		$error = new RuntimeException( 'Translation Index fixture cleanup failed terminally.', 0, $fixture_cleanup_error );
	}
	try {
		if ( ! $runtime_isolated ) {
			$restored_options = $raw_option_rows( $wpdb, $option_keys );
			$restored_tables = $owned_tables( $wpdb );
			if ( $original_options !== $restored_options || $original_tables !== $restored_tables ) {
				throw new RuntimeException( 'Translation Index runtime changed state before isolation was established.' );
			}
		} else {
			$current_tables = $owned_tables( $wpdb );
			$declared_disposable_tables = array_merge( array( $canonical ), $runtime_tables );
			foreach ( $declared_disposable_tables as $table ) {
				if ( in_array( $table, $current_tables, true ) && false === $wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Only exact identities declared durably before the runtime may be disposed.
					throw new RuntimeException( 'Could not remove one exact runtime-declared table.' );
				}
			}
			if ( $original_parked ) {
				if ( false === $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $saved, $canonical ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Restore exact pre-runtime canonical table identity.
					throw new RuntimeException( 'Could not restore original canonical Index identity.' );
				}
				$original_parked = false;
			}
			if ( ! $restore_raw_option_rows( $option_keys, $original_options ) ) {
				throw new RuntimeException( 'Could not restore exact pre-runtime option rows.' );
			}
			$restored_options = $raw_option_rows( $wpdb, $option_keys );
			$restored_tables = $owned_tables( $wpdb );
			$fixture_count = 0;
			if ( ! empty( $fixture_posts ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $fixture_posts ), '%d' ) );
				$fixture_count = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN ({$placeholders})", $fixture_posts ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact no-residue proof.
			}
			if ( $original_options !== $restored_options || $original_tables !== $restored_tables || 0 !== $fixture_count ) {
				throw new RuntimeException( 'Translation Index runtime final restoration was not byte/table/fixture exact.' );
			}
		}
		$restored_index_exact = $original_existed
			? in_array( $canonical, $owned_tables( $wpdb ), true ) && $original_index_rows === $raw_index_rows( $wpdb, $canonical )
			: ! in_array( $canonical, $owned_tables( $wpdb ), true );
		$fixture_slug_placeholders = implode( ', ', array_fill( 0, count( $fixture_slugs ), '%s' ) );
		$restored_fixture_slug_count = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_name IN ({$fixture_slug_placeholders})", $fixture_slugs ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- External journal scope must also be empty after ordinary finally restoration.
		if ( ! $restored_index_exact || 0 !== $restored_fixture_slug_count ) {
			throw new RuntimeException( 'Translation Index runtime did not restore its exact raw canonical rows and token fixture scope.' );
		}
		$journal['phase'] = 'runtime_restored';
		$write_recovery_journal( $journal );
	} catch ( Throwable $cleanup_error ) {
		$error = new RuntimeException( 'Translation Index canonical/options restoration failed terminally.', 0, $cleanup_error );
	}
	if ( $observer instanceof wpdb ) {
		$observer->close();
	}
}

if ( $error instanceof Throwable ) {
	throw $error;
}

echo wp_json_encode( $result, JSON_UNESCAPED_SLASHES );
