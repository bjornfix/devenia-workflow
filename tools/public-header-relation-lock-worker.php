<?php
/**
 * Independent writer used by the Public Header Relation Authority lock oracle.
 *
 * Usage:
 * php public-header-relation-lock-worker.php <wp-load.php> <before|under|after> <identity_meta|post_slug> <post-id> <fixture-slug> <field> <expected-state> <replacement-value>
 */

ini_set( 'display_errors', '0' );
ini_set( 'log_errors', '0' );
error_reporting( 0 );
ob_start(
	static function (): string {
		return '';
	}
);

/**
 * Finish with the worker's complete, deliberately narrow public Interface.
 *
 * Registering this after WordPress has loaded keeps bootstrap and shutdown-hook
 * output inside the discard buffer. Database and SQL diagnostics never cross
 * this Interface.
 *
 * @param array<string,mixed> $result Worker result.
 * @return void
 */
function devenia_workflow_relation_lock_worker_finish( array $result ): void {
	if ( function_exists( 'remove_action' ) ) {
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );
	}

	$phase = (string) ( $result['phase'] ?? 'invalid' );
	if ( ! in_array( $phase, array( 'before', 'under', 'after' ), true ) ) {
		$phase = 'invalid';
	}

	$engine = strtoupper( trim( (string) ( $result['engine'] ?? '' ) ) );
	if ( 'INNODB' !== $engine ) {
		$engine = '';
	}

	$payload = array(
		'success'       => true === ( $result['success'] ?? false ),
		'phase'         => $phase,
		'errno'         => max( 0, (int) ( $result['errno'] ?? 0 ) ),
		'connection_id' => max( 0, (int) ( $result['connection_id'] ?? 0 ) ),
		'engine'        => $engine,
	);
	$exit_code = $payload['success'] ? 0 : 1;

	register_shutdown_function(
		static function () use ( $payload ): void {
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}

			$encoded = json_encode( $payload, JSON_UNESCAPED_SLASHES );
			if ( is_string( $encoded ) ) {
				fwrite( STDOUT, $encoded . PHP_EOL );
			}
		}
	);

	exit( $exit_code );
}

/**
 * Return the current mysqli error number without exposing its message.
 *
 * @param wpdb $connection Dedicated worker connection.
 * @return int
 */
function devenia_workflow_relation_lock_worker_errno( wpdb $connection ): int {
	return $connection->dbh instanceof mysqli ? max( 0, mysqli_errno( $connection->dbh ) ) : 0;
}

/**
 * Disable automatic reconnects for the disposable writer connection.
 *
 * A timed-out or disconnected writer must never reissue its write after the
 * parent connection releases the lock.
 *
 * @param wpdb $connection Dedicated worker connection.
 * @return bool
 */
function devenia_workflow_relation_lock_worker_disable_reconnects( wpdb $connection ): bool {
	try {
		$reflection = new ReflectionObject( $connection );
		if ( ! $reflection->hasProperty( 'reconnect_retries' ) ) {
			return false;
		}

		$property = $reflection->getProperty( 'reconnect_retries' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( $connection, 0 );
		return 0 === (int) $property->getValue( $connection );
	} catch ( Throwable $exception ) {
		unset( $exception );
		return false;
	}
}

/**
 * Validate one canonical value for an allowlisted translation identity key.
 *
 * @param string $meta_key  Translation identity key.
 * @param string $value     Candidate value.
 * @return bool
 */
function devenia_workflow_relation_lock_worker_valid_value( string $meta_key, string $value ): bool {
	if ( '_devenia_translation_source_id' === $meta_key ) {
		if ( 1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return false;
		}

		$integer = (int) $value;
		return $integer > 0 && (string) $integer === $value;
	}

	if ( '_devenia_translation_language' === $meta_key ) {
		return 1 === preg_match( '/^[a-z][a-z0-9_-]{0,19}$/D', $value );
	}

	return false;
}

/**
 * Read every exact fixture identity row, including a valid empty set.
 *
 * @param wpdb   $connection Dedicated worker connection.
 * @param int    $post_id    Fixture post ID.
 * @param string $meta_key  Allowlisted identity key.
 * @return array<int,array{meta_id:int,meta_value:string}>|null
 */
function devenia_workflow_relation_lock_worker_read_meta_rows( wpdb $connection, int $post_id, string $meta_key ): ?array {
	$query = $connection->prepare(
		'SELECT meta_id, meta_value FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC',
		$connection->postmeta,
		$post_id,
		$meta_key
	);
	if ( ! is_string( $query ) || '' === $query ) {
		return null;
	}

	$rows = $connection->get_results( $query, ARRAY_A );
	if ( ! is_array( $rows ) ) {
		return null;
	}

	$normalized = array();
	foreach ( $rows as $row ) {
		$meta_id = is_array( $row ) ? (int) ( $row['meta_id'] ?? 0 ) : 0;
		if ( $meta_id <= 0 ) {
			return null;
		}
		$normalized[] = array( 'meta_id' => $meta_id, 'meta_value' => (string) ( $row['meta_value'] ?? '' ) );
	}
	return $normalized;
}

/**
 * Insert one exact identity row only while its canonical predicate is absent.
 *
 * @param wpdb   $connection Dedicated worker connection.
 * @param int    $post_id    Fixture post ID.
 * @param string $meta_key   Allowlisted identity key.
 * @param string $replacement Worker-owned fixture value.
 * @return array{result:int|false,errno:int,meta_id:int}
 */
function devenia_workflow_relation_lock_worker_insert_meta( wpdb $connection, int $post_id, string $meta_key, string $replacement ): array {
	$query = $connection->prepare(
		'INSERT INTO %i (post_id, meta_key, meta_value) SELECT %d, %s, %s FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM %i WHERE post_id = %d AND meta_key = %s)',
		$connection->postmeta,
		$post_id,
		$meta_key,
		$replacement,
		$connection->postmeta,
		$post_id,
		$meta_key
	);
	if ( ! is_string( $query ) || '' === $query ) {
		return array( 'result' => false, 'errno' => 9009, 'meta_id' => 0 );
	}

	$result = $connection->query( $query );
	$errno  = false === $result ? devenia_workflow_relation_lock_worker_errno( $connection ) : 0;
	if ( false === $result && 0 === $errno ) {
		$errno = 9009;
	}

	$meta_id = 1 === $result ? (int) $connection->insert_id : 0;
	return array( 'result' => $result, 'errno' => $errno, 'meta_id' => $meta_id );
}

/**
 * Delete only one exact worker-owned inserted row.
 *
 * @param wpdb   $connection Dedicated worker connection.
 * @param int    $post_id    Fixture post ID.
 * @param int    $meta_id    Exact fixture metadata row ID.
 * @param string $meta_key   Allowlisted identity key.
 * @param string $replacement Worker-owned inserted value.
 * @return bool
 */
function devenia_workflow_relation_lock_worker_delete_meta( wpdb $connection, int $post_id, int $meta_id, string $meta_key, string $replacement ): bool {
	$query = $connection->prepare(
		'DELETE FROM %i WHERE meta_id = %d AND post_id = %d AND meta_key = %s AND meta_value = %s',
		$connection->postmeta,
		$meta_id,
		$post_id,
		$meta_key,
		$replacement
	);
	if ( ! is_string( $query ) || '' === $query || 1 !== $connection->query( $query ) ) {
		return false;
	}

	$current = devenia_workflow_relation_lock_worker_read_meta_rows( $connection, $post_id, $meta_key );
	return is_array( $current ) && empty( $current );
}

/**
 * Restore the exact absent identity predicate, including a prior worker leak.
 *
 * The fixture post and replacement value are process-unique authority supplied
 * by the parent harness; any other row is foreign and is never deleted.
 *
 * @param wpdb   $connection  Dedicated worker connection.
 * @param int    $post_id     Fixture post ID.
 * @param int    $meta_id     Known inserted metadata row ID, or zero for discovery.
 * @param string $meta_key    Allowlisted identity key.
 * @param string $replacement Worker-owned inserted value.
 * @return bool
 */
function devenia_workflow_relation_lock_worker_restore_meta_absence( wpdb $connection, int $post_id, int $meta_id, string $meta_key, string $replacement ): bool {
	$rows = devenia_workflow_relation_lock_worker_read_meta_rows( $connection, $post_id, $meta_key );
	if ( ! is_array( $rows ) ) {
		return false;
	}
	if ( empty( $rows ) ) {
		return true;
	}
	if ( 1 !== count( $rows ) || ! hash_equals( $replacement, (string) $rows[0]['meta_value'] ) ) {
		return false;
	}
	$owned_meta_id = (int) $rows[0]['meta_id'];
	if ( $meta_id > 0 && $meta_id !== $owned_meta_id ) {
		return false;
	}
	return devenia_workflow_relation_lock_worker_delete_meta( $connection, $post_id, $owned_meta_id, $meta_key, $replacement );
}

/**
 * Read the exact fixture post slug.
 *
 * @param wpdb $connection Dedicated worker connection.
 * @param int  $post_id    Fixture post ID.
 * @return string|null
 */
function devenia_workflow_relation_lock_worker_read_post_slug( wpdb $connection, int $post_id ): ?string {
	$query = $connection->prepare(
		'SELECT post_name FROM %i WHERE ID = %d AND post_type = %s AND post_status = %s',
		$connection->posts,
		$post_id,
		'page',
		'publish'
	);
	if ( ! is_string( $query ) || '' === $query ) {
		return null;
	}

	$rows = $connection->get_results( $query, ARRAY_A );
	if ( ! is_array( $rows ) || 1 !== count( $rows ) || ! is_array( $rows[0] ) ) {
		return null;
	}

	return (string) ( $rows[0]['post_name'] ?? '' );
}

/**
 * Execute one exact, fixture-scoped post-slug compare-and-swap.
 *
 * @param wpdb   $connection  Dedicated worker connection.
 * @param int    $post_id     Fixture post ID.
 * @param string $expected    Required current slug.
 * @param string $replacement Replacement slug.
 * @return array{result:int|false,errno:int}
 */
function devenia_workflow_relation_lock_worker_update_post_slug( wpdb $connection, int $post_id, string $expected, string $replacement ): array {
	$query = $connection->prepare(
		'UPDATE %i SET post_name = %s WHERE ID = %d AND post_type = %s AND post_status = %s AND post_name = %s',
		$connection->posts,
		$replacement,
		$post_id,
		'page',
		'publish',
		$expected
	);
	if ( ! is_string( $query ) || '' === $query ) {
		return array( 'result' => false, 'errno' => 9009 );
	}

	$result = $connection->query( $query );
	$errno  = false === $result ? devenia_workflow_relation_lock_worker_errno( $connection ) : 0;
	if ( false === $result && 0 === $errno ) {
		$errno = 9009;
	}

	return array( 'result' => $result, 'errno' => $errno );
}

/**
 * Restore only the worker-owned slug replacement and verify the exact pre-state.
 *
 * @param wpdb   $connection  Dedicated worker connection.
 * @param int    $post_id     Fixture post ID.
 * @param string $expected    Required restored slug.
 * @param string $replacement Worker-owned replacement slug.
 * @return bool
 */
function devenia_workflow_relation_lock_worker_restore_post_slug( wpdb $connection, int $post_id, string $expected, string $replacement ): bool {
	$current = devenia_workflow_relation_lock_worker_read_post_slug( $connection, $post_id );
	if ( ! is_string( $current ) ) {
		return false;
	}

	if ( hash_equals( $expected, $current ) ) {
		return true;
	}

	if ( ! hash_equals( $replacement, $current ) ) {
		return false;
	}

	$restored = devenia_workflow_relation_lock_worker_update_post_slug( $connection, $post_id, $replacement, $expected );
	if ( 1 !== $restored['result'] ) {
		return false;
	}

	$current = devenia_workflow_relation_lock_worker_read_post_slug( $connection, $post_id );
	return is_string( $current ) && hash_equals( $expected, $current );
}

$arguments = isset( $argv ) && is_array( $argv ) ? $argv : array();
$phase     = isset( $arguments[2] ) && is_string( $arguments[2] ) ? $arguments[2] : 'invalid';
if ( ! in_array( $phase, array( 'before', 'under', 'after' ), true ) ) {
	$phase = 'invalid';
}

if ( 'cli' !== PHP_SAPI || 9 !== count( $arguments ) || 'invalid' === $phase ) {
	devenia_workflow_relation_lock_worker_finish(
		array( 'success' => false, 'phase' => $phase, 'errno' => 9001, 'connection_id' => 0, 'engine' => '' )
	);
}

$wp_load      = is_string( $arguments[1] ) ? $arguments[1] : '';
$surface_mode = is_string( $arguments[3] ) ? $arguments[3] : '';
$post_id_raw  = is_string( $arguments[4] ) ? $arguments[4] : '';
$fixture_slug = is_string( $arguments[5] ) ? $arguments[5] : '';
$field        = is_string( $arguments[6] ) ? $arguments[6] : '';
$expected     = is_string( $arguments[7] ) ? $arguments[7] : '';
$replacement  = is_string( $arguments[8] ) ? $arguments[8] : '';
$wp_load_real = realpath( $wp_load );

if (
	'' === $wp_load
	|| '/' !== substr( $wp_load, 0, 1 )
	|| false === $wp_load_real
	|| ! is_file( $wp_load_real )
	|| ! is_readable( $wp_load_real )
	|| 'wp-load.php' !== basename( $wp_load_real )
	|| defined( 'ABSPATH' )
	|| class_exists( 'wpdb', false )
) {
	devenia_workflow_relation_lock_worker_finish(
		array( 'success' => false, 'phase' => $phase, 'errno' => 9002, 'connection_id' => 0, 'engine' => '' )
	);
}

if (
	1 !== preg_match( '/^[1-9][0-9]*$/D', $post_id_raw )
	|| (int) $post_id_raw <= 0
	|| (string) (int) $post_id_raw !== $post_id_raw
	|| 1 !== preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,198}[a-z0-9])?$/D', $fixture_slug )
	|| ! in_array( $surface_mode, array( 'identity_meta', 'post_slug' ), true )
	|| (
		'identity_meta' === $surface_mode
		&& (
			! in_array( $field, array( '_devenia_translation_source_id', '_devenia_translation_language' ), true )
			|| 'absent' !== $expected
			|| ! devenia_workflow_relation_lock_worker_valid_value( $field, $replacement )
		)
	)
	|| (
		'post_slug' === $surface_mode
		&& (
			'post_name' !== $field
			|| ! hash_equals( $fixture_slug, $expected )
			|| 1 !== preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,198}[a-z0-9])?$/D', $replacement )
		)
	)
	|| hash_equals( $expected, $replacement )
) {
	devenia_workflow_relation_lock_worker_finish(
		array( 'success' => false, 'phase' => $phase, 'errno' => 9001, 'connection_id' => 0, 'engine' => '' )
	);
}

$post_id       = (int) $post_id_raw;
$connection_id = 0;
$engine        = '';
$connection    = null;
$meta_id       = 0;
$write_applied = false;
$result        = array( 'success' => false, 'phase' => $phase, 'errno' => 9003, 'connection_id' => 0, 'engine' => '' );

try {
	if ( ! defined( 'WP_USE_THEMES' ) ) {
		define( 'WP_USE_THEMES', false );
	}
	require_once $wp_load_real;

	$expected_root = realpath( dirname( $wp_load_real ) );
	$actual_root   = defined( 'ABSPATH' ) ? realpath( ABSPATH ) : false;
	if ( false === $expected_root || false === $actual_root || $expected_root !== $actual_root || ! class_exists( 'wpdb', false ) ) {
		throw new RuntimeException( 'bootstrap' );
	}

	$bootstrap_connection = $GLOBALS['wpdb'] ?? null;
	$prefix               = $bootstrap_connection instanceof wpdb ? (string) $bootstrap_connection->prefix : '';
	if ( '' === $prefix || 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $prefix ) ) {
		$result['errno'] = 9004;
		throw new RuntimeException( 'prefix' );
	}

	$connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$connection->set_prefix( $prefix );
	$connection->suppress_errors( true );
	if ( ! devenia_workflow_relation_lock_worker_disable_reconnects( $connection ) ) {
		$result['errno'] = 9006;
		throw new RuntimeException( 'reconnect' );
	}

	$connection_id = (int) $connection->get_var( 'SELECT CONNECTION_ID()' );
	if ( $connection_id <= 0 || '1' !== (string) $connection->get_var( 'SELECT 1' ) ) {
		$result['errno'] = 9004;
		throw new RuntimeException( 'connection' );
	}
	$result['connection_id'] = $connection_id;

	$surface_table = 'post_slug' === $surface_mode ? $connection->posts : $connection->postmeta;
	$engine_query  = $connection->prepare(
		'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
		$surface_table
	);
	$engine_rows = is_string( $engine_query ) && '' !== $engine_query ? $connection->get_results( $engine_query, ARRAY_A ) : null;
	if ( ! is_array( $engine_rows ) || 1 !== count( $engine_rows ) || ! is_array( $engine_rows[0] ) ) {
		$result['errno'] = 9005;
		throw new RuntimeException( 'engine' );
	}

	$engine = strtoupper( trim( (string) ( $engine_rows[0]['ENGINE'] ?? '' ) ) );
	if ( 'INNODB' !== $engine ) {
		$result['errno'] = 9005;
		throw new RuntimeException( 'engine' );
	}
	$result['engine'] = $engine;

	if (
		false === $connection->query( 'SET SESSION innodb_lock_wait_timeout = 1' )
		|| '1' !== (string) $connection->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' )
	) {
		$errno           = devenia_workflow_relation_lock_worker_errno( $connection );
		$result['errno'] = $errno > 0 ? $errno : 9006;
		throw new RuntimeException( 'timeout' );
	}

	$post_query = $connection->prepare(
		'SELECT ID, post_type, post_status, post_name FROM %i WHERE ID = %d',
		$connection->posts,
		$post_id
	);
	$post_rows = is_string( $post_query ) ? $connection->get_results( $post_query, ARRAY_A ) : null;
	if (
		! is_array( $post_rows )
		|| 1 !== count( $post_rows )
		|| ! is_array( $post_rows[0] )
		|| $post_id !== (int) ( $post_rows[0]['ID'] ?? 0 )
		|| 'page' !== (string) ( $post_rows[0]['post_type'] ?? '' )
		|| 'publish' !== (string) ( $post_rows[0]['post_status'] ?? '' )
		|| ! hash_equals( $fixture_slug, (string) ( $post_rows[0]['post_name'] ?? '' ) )
	) {
		$result['errno'] = 9007;
		throw new RuntimeException( 'post' );
	}

	if ( 'identity_meta' === $surface_mode ) {
		if ( 'after' === $phase ) {
			if ( ! devenia_workflow_relation_lock_worker_restore_meta_absence( $connection, $post_id, 0, $field, $replacement ) ) {
				$result['errno'] = 9010;
				throw new RuntimeException( 'cleanup' );
			}
		}
		$current = devenia_workflow_relation_lock_worker_read_meta_rows( $connection, $post_id, $field );
		if ( ! is_array( $current ) || ! empty( $current ) ) {
			$result['errno'] = 9008;
			throw new RuntimeException( 'meta-state' );
		}

		$write = devenia_workflow_relation_lock_worker_insert_meta( $connection, $post_id, $field, $replacement );
		$meta_id = (int) ( $write['meta_id'] ?? 0 );
	} else {
		$current_slug = devenia_workflow_relation_lock_worker_read_post_slug( $connection, $post_id );
		if ( 'after' === $phase && is_string( $current_slug ) && hash_equals( $replacement, $current_slug ) ) {
			if ( ! devenia_workflow_relation_lock_worker_restore_post_slug( $connection, $post_id, $expected, $replacement ) ) {
				$result['errno'] = 9010;
				throw new RuntimeException( 'cleanup' );
			}
			$current_slug = devenia_workflow_relation_lock_worker_read_post_slug( $connection, $post_id );
		}

		if ( ! is_string( $current_slug ) || ! hash_equals( $expected, $current_slug ) ) {
			$result['errno'] = 9008;
			throw new RuntimeException( 'post-state' );
		}

		$write = devenia_workflow_relation_lock_worker_update_post_slug( $connection, $post_id, $expected, $replacement );
	}
	if ( false === $write['result'] ) {
		$result['errno'] = max( 1, (int) $write['errno'] );
	} elseif ( 1 !== $write['result'] ) {
		$result['errno'] = 9009;
	} else {
		$write_applied = true;
		$restored = 'identity_meta' === $surface_mode
			? devenia_workflow_relation_lock_worker_restore_meta_absence( $connection, $post_id, $meta_id, $field, $replacement )
			: devenia_workflow_relation_lock_worker_restore_post_slug( $connection, $post_id, $expected, $replacement );
		if ( ! $restored || ( 'identity_meta' === $surface_mode && $meta_id < 1 ) ) {
			$result['errno'] = 9010;
			throw new RuntimeException( 'restore' );
		}

		$write_applied = false;
		$result         = array(
			'success'       => true,
			'phase'         => $phase,
			'errno'         => 0,
			'connection_id' => $connection_id,
			'engine'        => $engine,
		);
	}
} catch ( Throwable $exception ) {
	unset( $exception );
} finally {
	if ( $write_applied && $connection instanceof wpdb ) {
		$restored = 'identity_meta' === $surface_mode
			? devenia_workflow_relation_lock_worker_restore_meta_absence( $connection, $post_id, $meta_id, $field, $replacement )
			: ( 'post_slug' === $surface_mode ? devenia_workflow_relation_lock_worker_restore_post_slug( $connection, $post_id, $expected, $replacement ) : false );
		if ( $restored ) {
			$write_applied = false;
		} else {
			$result['success'] = false;
			$result['errno']   = 9010;
		}
	}

	$result['phase']         = $phase;
	$result['connection_id'] = $connection_id;
	$result['engine']        = $engine;
	if ( $connection instanceof wpdb ) {
		$connection->close();
	}
}

devenia_workflow_relation_lock_worker_finish( $result );
