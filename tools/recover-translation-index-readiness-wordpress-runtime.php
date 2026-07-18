<?php
/**
 * Idempotent external recovery for the Translation Index Readiness runtime.
 *
 * This file runs in a separate WP-CLI process from the destructive runtime. It
 * consumes the durable pre-mutation journal and restores exact table and option
 * state even when exit/die/fatal bypasses the runtime's PHP finally block.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Translation Index recovery requires WP-CLI with WordPress loaded.' );
}
if ( ! class_exists( 'Devenia_Workflow' ) ) {
	throw new RuntimeException( 'Devenia Workflow was not loaded for Translation Index recovery.' );
}

global $wpdb;
$journal_path = (string) getenv( 'DEVENIA_TRANSLATION_INDEX_RUNTIME_JOURNAL' );
$journal_directory = '' !== $journal_path ? realpath( dirname( $journal_path ) ) : false;
$journal_lstat = '' !== $journal_path ? lstat( $journal_path ) : false;
if (
	'/tmp' !== $journal_directory
	|| 1 !== preg_match( '#^devenia-ti-recovery-[A-Za-z0-9]+\.json$#D', basename( $journal_path ) )
	|| ! is_array( $journal_lstat )
	|| 0100000 !== ( (int) $journal_lstat['mode'] & 0170000 )
	|| 0600 !== ( (int) $journal_lstat['mode'] & 0777 )
) {
	throw new RuntimeException( 'Translation Index recovery journal path or mode is invalid.' );
}

$journal_handle = fopen( $journal_path, 'rb' );
if ( false === $journal_handle ) {
	throw new RuntimeException( 'Translation Index recovery journal could not be opened.' );
}
$journal_fstat = fstat( $journal_handle );
$journal_bytes = stream_get_contents( $journal_handle );
fclose( $journal_handle );
$journal_lstat_after = lstat( $journal_path );
if (
	! is_array( $journal_fstat )
	|| ! is_array( $journal_lstat_after )
	|| (int) $journal_lstat['dev'] !== (int) $journal_fstat['dev']
	|| (int) $journal_lstat['ino'] !== (int) $journal_fstat['ino']
	|| (int) $journal_lstat_after['dev'] !== (int) $journal_fstat['dev']
	|| (int) $journal_lstat_after['ino'] !== (int) $journal_fstat['ino']
	|| 0100000 !== ( (int) $journal_fstat['mode'] & 0170000 )
	|| 0600 !== ( (int) $journal_fstat['mode'] & 0777 )
) {
	throw new RuntimeException( 'Translation Index recovery journal identity changed while it was read.' );
}
$journal = is_string( $journal_bytes ) ? json_decode( trim( $journal_bytes ), true ) : null;
if ( ! is_array( $journal ) ) {
	throw new RuntimeException( 'Translation Index recovery journal is not valid JSON.' );
}
$checksum = (string) ( $journal['checksum'] ?? '' );
unset( $journal['checksum'] );
$checksum_payload = wp_json_encode( $journal, JSON_UNESCAPED_SLASHES );
if ( ! is_string( $checksum_payload ) || 64 !== strlen( $checksum ) || ! hash_equals( hash( 'sha256', $checksum_payload ), $checksum ) ) {
	throw new RuntimeException( 'Translation Index recovery journal checksum is invalid.' );
}

$expected_keys = array(
	'journal_version',
	'token',
	'phase',
	'canonical',
	'saved',
	'original_existed',
	'option_keys',
	'original_options',
	'original_tables',
	'original_index_rows',
	'runtime_tables',
	'fixture_slugs',
	'fixture_posts',
	'worker_markers',
);
if ( $expected_keys !== array_keys( $journal ) || 1 !== (int) $journal['journal_version'] ) {
	throw new RuntimeException( 'Translation Index recovery journal schema is invalid.' );
}

$token = (string) $journal['token'];
$canonical = (string) $journal['canonical'];
$saved = (string) $journal['saved'];
$original_existed = true === $journal['original_existed'];
$option_keys = (array) $journal['option_keys'];
$original_options = (array) $journal['original_options'];
$original_tables = (array) $journal['original_tables'];
$original_index_rows = (array) $journal['original_index_rows'];
$runtime_tables = (array) $journal['runtime_tables'];
$fixture_slugs = (array) $journal['fixture_slugs'];
$fixture_posts = array_values( array_unique( array_filter( array_map( 'absint', (array) $journal['fixture_posts'] ) ) ) );
$worker_markers = (array) $journal['worker_markers'];
$expected_option_keys = array(
	'devenia_workflow_translation_index_schema',
	'devenia_workflow_translation_index_readiness',
	'devenia_workflow_translation_index_upgrade_lease',
	'devenia_workflow_translation_index_last_failure',
	'devenia_workflow_translation_index_canonical_revision',
	'devenia_workflow_version',
);
$expected_fixture_slugs = array(
	'translation-index-source-' . $token,
	'translation-index-target-' . $token,
	'translation-index-alternate-source-' . $token,
	'translation-index-target-new-' . $token,
	'translation-index-author-source-' . $token,
	'translation-index-author-target-' . $token,
);
$expected_runtime_tables = array(
	$wpdb->prefix . 'devenia_ti_runtime_valid_' . $token,
	$wpdb->prefix . 'devenia_ti_runtime_engine_' . $token,
	$wpdb->prefix . 'devenia_ti_runtime_schema_' . $token,
	$wpdb->prefix . 'devenia_ti_runtime_type_' . $token,
	$wpdb->prefix . 'devenia_ti_runtime_null_' . $token,
	$wpdb->prefix . 'devenia_ti_runtime_collation_' . $token,
	$wpdb->prefix . 'devenia_ti_runtime_prefix_' . $token,
);
$expected_phases = array( 'snapshotted', 'canonical_parked', 'fixture_recorded', 'worker_declared', 'runtime_restored' );
if (
	1 !== preg_match( '/^[a-z0-9]{10}$/D', $token )
	|| ! in_array( (string) $journal['phase'], $expected_phases, true )
	|| $wpdb->prefix . 'devenia_translation_index' !== $canonical
	|| $wpdb->prefix . 'devenia_ti_runtime_saved_' . $token !== $saved
	|| $expected_option_keys !== $option_keys
	|| $expected_runtime_tables !== $runtime_tables
	|| $expected_fixture_slugs !== $fixture_slugs
) {
	throw new RuntimeException( 'Translation Index recovery journal identity scope is invalid.' );
}

$option_names = array();
$option_ids = array();
foreach ( $original_options as $row ) {
	if (
		array( 'option_id', 'option_name', 'option_value', 'autoload' ) !== array_keys( (array) $row )
		|| ! is_int( $row['option_id'] ?? null )
		|| (int) $row['option_id'] < 1
		|| ! is_string( $row['option_name'] ?? null )
		|| ! is_string( $row['option_value'] ?? null )
		|| ! is_string( $row['autoload'] ?? null )
		|| '' === (string) $row['autoload']
		|| strlen( (string) $row['autoload'] ) > 20
		|| ! in_array( (string) ( $row['option_name'] ?? '' ), $option_keys, true )
		|| in_array( (string) ( $row['option_name'] ?? '' ), $option_names, true )
		|| in_array( (int) $row['option_id'], $option_ids, true )
	) {
		throw new RuntimeException( 'Translation Index recovery journal option snapshot is invalid.' );
	}
	$option_names[] = (string) $row['option_name'];
	$option_ids[] = (int) $row['option_id'];
}
$normalized_original_tables = array_values( array_unique( array_map( 'strval', $original_tables ) ) );
sort( $normalized_original_tables, SORT_STRING );
if ( $normalized_original_tables !== $original_tables || $original_existed !== in_array( $canonical, $original_tables, true ) ) {
	throw new RuntimeException( 'Translation Index recovery journal table inventory is invalid.' );
}
foreach ( $original_tables as $table ) {
	if ( $canonical !== $table && 0 !== strpos( $table, $wpdb->prefix . 'devenia_ti_' ) ) {
		throw new RuntimeException( 'Translation Index recovery journal contains a foreign table identity.' );
	}
}

$raw_row_columns = array( 'id', 'source_post_id', 'translation_post_id', 'language', 'localized_path', 'source_path', 'target_path', 'target_url', 'translation_status', 'post_status', 'source_hash', 'reviewed_at', 'linguistic_reviewed_at', 'quality_reviewed_at', 'updated_at' );
foreach ( $original_index_rows as $row ) {
	if (
		$raw_row_columns !== array_keys( (array) $row )
		|| ! is_int( $row['id'] ?? null )
		|| ! is_int( $row['source_post_id'] ?? null )
		|| ! is_int( $row['translation_post_id'] ?? null )
		|| (int) $row['id'] < 1
		|| (int) $row['source_post_id'] < 1
		|| (int) $row['translation_post_id'] < 1
	) {
		throw new RuntimeException( 'Translation Index recovery journal canonical row snapshot is invalid.' );
	}
	foreach ( array_diff( $raw_row_columns, array( 'id', 'source_post_id', 'translation_post_id' ) ) as $column ) {
		if ( ! is_string( $row[ $column ] ?? null ) ) {
			throw new RuntimeException( 'Translation Index recovery journal canonical row types are invalid.' );
		}
	}
}
if ( ! $original_existed && array() !== $original_index_rows ) {
	throw new RuntimeException( 'Translation Index recovery journal claims rows for an absent canonical table.' );
}
foreach ( $worker_markers as $marker ) {
	if ( ! is_string( $marker ) || 1 !== preg_match( '#^/tmp/devenia-ti-readiness-' . preg_quote( $token, '#' ) . '-(?:canonical_writer|ensure_ready)-[0-9]+\.ready$#D', $marker ) ) {
		throw new RuntimeException( 'Translation Index recovery journal marker scope is invalid.' );
	}
}

$call = static function ( string $method, ...$arguments ) {
	$reflection = new ReflectionMethod( Devenia_Workflow::class, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $arguments );
};
$table_exists = static function ( string $table ) use ( $wpdb ): bool {
	return 1 === (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$table
		)
	);
};
$owned_tables = static function () use ( $wpdb, $canonical ): array {
	$prefix = $wpdb->prefix . 'devenia_ti_';
	$rows = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME = %s OR LEFT(TABLE_NAME, CHAR_LENGTH(%s)) = %s) ORDER BY TABLE_NAME ASC',
			$canonical,
			$prefix,
			$prefix
		)
	);
	if ( ! is_array( $rows ) ) {
		throw new RuntimeException( 'Translation Index recovery table inventory failed.' );
	}
	return array_values( array_map( 'strval', $rows ) );
};
$raw_index_rows = static function ( string $table ) use ( $wpdb ): array {
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', $table ), ARRAY_A );
	if ( ! is_array( $rows ) ) {
		throw new RuntimeException( 'Translation Index recovery canonical row read failed.' );
	}
	return $rows;
};
$raw_option_rows = static function () use ( $wpdb, $option_keys ): array {
	$placeholders = implode( ', ', array_fill( 0, count( $option_keys ), '%s' ) );
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_id, option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name IN ({$placeholders}) ORDER BY option_name ASC, option_id ASC",
			$option_keys
		),
		ARRAY_A
	);
	if ( ! is_array( $rows ) ) {
		throw new RuntimeException( 'Translation Index recovery option read failed.' );
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
$restore_option_rows = static function () use ( $wpdb, $option_keys, $original_options ): void {
	foreach ( $option_keys as $key ) {
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact external recovery replays the durable pre-mutation snapshot.
		if ( false === $deleted ) {
			throw new RuntimeException( 'Translation Index recovery could not clear one owned option row.' );
		}
		wp_cache_delete( $key, 'options' );
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	foreach ( $original_options as $row ) {
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_id, option_name, option_value, autoload) VALUES (%d, %s, %s, %s)",
				absint( $row['option_id'] ),
				(string) $row['option_name'],
				(string) $row['option_value'],
				(string) $row['autoload']
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact external recovery preserves option identity, bytes and autoload.
		if ( 1 !== $inserted ) {
			throw new RuntimeException( 'Translation Index recovery could not restore one owned option row.' );
		}
		wp_cache_delete( (string) $row['option_name'], 'options' );
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
};

$fixture_slug_placeholders = implode( ', ', array_fill( 0, count( $fixture_slugs ), '%s' ) );
$slug_rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT ID, post_name, post_title, post_type FROM {$wpdb->posts} WHERE post_name IN ({$fixture_slug_placeholders}) ORDER BY ID DESC",
		$fixture_slugs
	),
	ARRAY_A
); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Predeclared token slugs recover an exit inside wp_insert_post before its ID could be journaled.
if ( ! is_array( $slug_rows ) ) {
	throw new RuntimeException( 'Translation Index recovery fixture discovery failed.' );
}
foreach ( $slug_rows as $row ) {
	if (
		! in_array( (string) ( $row['post_name'] ?? '' ), $fixture_slugs, true )
		|| 0 !== strpos( (string) ( $row['post_title'] ?? '' ), 'Translation Index ' )
		|| ! in_array( (string) ( $row['post_type'] ?? '' ), array( 'page', 'post' ), true )
	) {
		throw new RuntimeException( 'Translation Index recovery refused a fixture identity outside its exact token scope.' );
	}
	$fixture_posts[] = absint( $row['ID'] ?? 0 );
}
$fixture_posts = array_values( array_unique( array_filter( array_map( 'absint', $fixture_posts ) ) ) );
rsort( $fixture_posts, SORT_NUMERIC );
foreach ( $fixture_posts as $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		continue;
	}
	if ( ! in_array( (string) $post->post_name, $fixture_slugs, true ) || 0 !== strpos( (string) $post->post_title, 'Translation Index ' ) ) {
		throw new RuntimeException( 'Translation Index recovery post identity changed before deletion.' );
	}
	$deleted_post = wp_delete_post( $post_id, true );
	if ( ! $deleted_post instanceof WP_Post ) {
		throw new RuntimeException( 'Translation Index recovery could not delete one exact fixture through WordPress.' );
	}
}
if ( ! $call( 'complete_pending_translation_index_mutations' ) ) {
	throw new RuntimeException( 'Translation Index recovery could not terminalize fixture deletion boundaries.' );
}

$canonical_already_exact = $original_existed
	? $table_exists( $canonical ) && $original_index_rows === $raw_index_rows( $canonical )
	: ! $table_exists( $canonical );
$state_already_exact = ! $table_exists( $saved )
	&& $canonical_already_exact
	&& $original_options === $raw_option_rows()
	&& $original_tables === $owned_tables();
if ( ! $state_already_exact ) {
	$failed = $wpdb->prefix . 'devenia_ti_runtime_failed_' . $token;
	if ( $original_existed ) {
		if ( $table_exists( $saved ) ) {
			if ( $table_exists( $failed ) ) {
				throw new RuntimeException( 'Translation Index recovery failed-table identity is already occupied.' );
			}
			if ( $table_exists( $canonical ) ) {
				$renamed = $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i, %i TO %i', $canonical, $failed, $saved, $canonical ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Atomic external recovery restores the parked original before disposing the failed runtime table.
			} else {
				$renamed = $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $saved, $canonical ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Canonical was absent; restore the exact parked identity.
			}
			if ( false === $renamed ) {
				throw new RuntimeException( 'Translation Index recovery could not restore the parked canonical table.' );
			}
		} elseif ( ! $table_exists( $canonical ) || $original_index_rows !== $raw_index_rows( $canonical ) ) {
			throw new RuntimeException( 'Translation Index recovery cannot prove the original canonical table without its parked backup.' );
		}
	} else {
		if ( $table_exists( $saved ) ) {
			throw new RuntimeException( 'Translation Index recovery found a parked table for an originally absent canonical.' );
		}
		if ( $table_exists( $canonical ) && ! $call( 'translation_index_drop_owned_table', $canonical ) ) {
			throw new RuntimeException( 'Translation Index recovery could not remove the runtime-created canonical table.' );
		}
	}

	$restore_option_rows();
}
$post_count = 0;
$meta_count = 0;
if ( ! empty( $fixture_posts ) ) {
	$fixture_placeholders = implode( ', ', array_fill( 0, count( $fixture_posts ), '%d' ) );
	$post_count = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN ({$fixture_placeholders})", $fixture_posts ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact recovery residue proof.
	$meta_count = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN ({$fixture_placeholders})", $fixture_posts ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact recovery residue proof.
}
$slug_count = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_name IN ({$fixture_slug_placeholders})", $fixture_slugs ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Covers an exit between insert and ID journal update.
$canonical_exact = $original_existed ? $table_exists( $canonical ) && $original_index_rows === $raw_index_rows( $canonical ) : ! $table_exists( $canonical );
$options_exact = $original_options === $raw_option_rows();
if ( ! $canonical_exact || ! $options_exact || 0 !== $post_count || 0 !== $meta_count || 0 !== $slug_count ) {
	throw new RuntimeException( 'Translation Index recovery refused to dispose runtime tables before exact canonical, option and fixture proof.' );
}

$current_tables = $owned_tables();
$extra_tables = array_values( array_diff( $current_tables, $original_tables ) );
$failed = $wpdb->prefix . 'devenia_ti_runtime_failed_' . $token;
$disposable_tables = array_merge( $runtime_tables, array( $failed ) );
$unproven_tables = array_values( array_diff( $extra_tables, $disposable_tables ) );
if ( array() !== $unproven_tables ) {
	throw new RuntimeException( 'Translation Index recovery refused to remove an undeclared table identity.' );
}
foreach ( $extra_tables as $table ) {
	if ( ! $call( 'translation_index_drop_owned_table', $table ) ) {
		throw new RuntimeException( 'Translation Index recovery could not remove one exact runtime-declared table.' );
	}
}
$discovered_markers = glob( '/tmp/devenia-ti-readiness-' . $token . '-*.ready' );
if ( false === $discovered_markers ) {
	throw new RuntimeException( 'Translation Index recovery could not enumerate its exact worker-marker scope.' );
}
foreach ( $discovered_markers as $marker ) {
	if ( 1 !== preg_match( '#^/tmp/devenia-ti-readiness-' . preg_quote( $token, '#' ) . '-(?:canonical_writer|ensure_ready)-[0-9]+\.ready$#D', $marker ) ) {
		throw new RuntimeException( 'Translation Index recovery discovered an invalid token marker identity.' );
	}
}
$worker_markers = array_values( array_unique( array_merge( $worker_markers, $discovered_markers ) ) );
sort( $worker_markers, SORT_STRING );
foreach ( $worker_markers as $marker ) {
	if ( is_file( $marker ) && ! unlink( $marker ) ) {
		throw new RuntimeException( 'Translation Index recovery could not remove one exact worker marker.' );
	}
}

$marker_count = 0;
foreach ( $worker_markers as $marker ) {
	$marker_count += is_file( $marker ) ? 1 : 0;
}
$lease_snapshot = array_values(
	array_filter(
		$original_options,
		static function ( array $row ): bool {
			return 'devenia_workflow_translation_index_upgrade_lease' === (string) ( $row['option_name'] ?? '' );
		}
	)
);
$lease_absent = false === get_option( 'devenia_workflow_translation_index_upgrade_lease', false );
$lease_matches_snapshot = empty( $lease_snapshot ) ? $lease_absent : $original_options === $raw_option_rows();
$final_tables = $owned_tables();
$final_canonical_exact = $original_existed ? $table_exists( $canonical ) && $original_index_rows === $raw_index_rows( $canonical ) : ! $table_exists( $canonical );
$final_options_exact = $original_options === $raw_option_rows();
if ( ! $final_canonical_exact || ! $final_options_exact || $original_tables !== $final_tables || 0 !== $marker_count || ! $lease_matches_snapshot || $table_exists( $saved ) ) {
	throw new RuntimeException( 'Translation Index recovery final exact-state proof failed.' );
}

echo wp_json_encode(
	array(
		'success'                => true,
		'journal_version'        => 1,
		'canonical_exact'        => true,
		'options_exact'          => true,
		'fixtures_zero'          => 0 === $post_count && 0 === $meta_count && 0 === $slug_count,
		'markers_zero'           => 0 === $marker_count,
		'lease_matches_snapshot' => $lease_matches_snapshot,
		'tables_exact'           => true,
	),
	JSON_UNESCAPED_SLASHES
);
