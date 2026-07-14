<?php
/** Real wpdb fault/runtime proof for the recovery transaction boundary. */

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

final class Devenia_Workflow_Recovery_Intercept_WPDB extends wpdb {
	/** @var wpdb */
	private $delegate;
	/** @var array<string,mixed> */
	public $faults = array();
	/** @var array<int,string> */
	public $trace = array();

	public function __construct( wpdb $delegate, array $faults = array() ) {
		$this->delegate = $delegate;
		$this->faults = $faults;
		foreach ( array( 'prefix', 'base_prefix', 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'options' ) as $property ) {
			$this->$property = $delegate->$property;
		}
	}

	public function prepare( $query, ...$args ) { return $this->delegate->prepare( $query, ...$args ); }
	public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
		$this->trace[] = (string) $query;
		$row = $this->delegate->get_row( $query, $output, $y );
		if ( false !== stripos( (string) $query, 'CONNECTION_ID()' ) && isset( $this->faults['connection_id_override'] ) ) {
			if ( ARRAY_A === $output && is_array( $row ) ) { $row['connection_id'] = (int) $this->faults['connection_id_override']; }
			elseif ( is_object( $row ) ) { $row->connection_id = (int) $this->faults['connection_id_override']; }
		}
		return $row;
	}
	public function get_results( $query = null, $output = OBJECT ) {
		$this->trace[] = (string) $query;
		if ( false !== stripos( (string) $query, 'information_schema.TABLES' ) ) {
			if ( ! empty( $this->faults['primary_empty'] ) ) { return array(); }
			$rows = $this->delegate->get_results( $query, $output );
			if ( ! empty( $this->faults['primary_missing_one'] ) && is_array( $rows ) ) { array_pop( $rows ); }
			if ( ! empty( $this->faults['primary_non_innodb'] ) && is_array( $rows ) && isset( $rows[0] ) ) {
				if ( ARRAY_A === $output ) { $rows[0]['ENGINE'] = 'MyISAM'; } else { $rows[0]->ENGINE = 'MyISAM'; }
			}
			return $rows;
		}
		if ( false !== stripos( (string) $query, 'SHOW TABLE STATUS' ) && ! empty( $this->faults['fallback_missing'] ) ) { return array(); }
		return $this->delegate->get_results( $query, $output );
	}
	public function query( $query ) {
		$query = (string) $query;
		$this->trace[] = $query;
		if ( ! empty( $this->faults['mid_write_disconnect'] ) && false !== strpos( $query, (string) $this->faults['mid_write_disconnect'] ) ) {
			if ( 0 === $this->reconnect_retries ) {
				$this->trace[] = 'MID_WRITE_RECONNECT_BLOCKED';
				return false;
			}
			$this->trace[] = 'MID_WRITE_REISSUED_ON_DELEGATE';
		}
		foreach ( (array) ( $this->faults['fail_queries'] ?? array() ) as $pattern ) {
			if ( preg_match( $pattern, $query ) ) { return false; }
		}
		$result = $this->delegate->query( $query );
		if ( false !== $result && 'COMMIT AND NO CHAIN NO RELEASE' === $query && ! empty( $this->faults['reconnect_after_commit'] ) ) {
			$this->faults['connection_id_override'] = (int) $this->delegate->get_var( 'SELECT CONNECTION_ID()' ) + 1;
		}
		return $result;
	}
}

$original_wpdb = $GLOBALS['wpdb'];
$connections = array();
$option_names = array();
$reflection = new ReflectionClass( Devenia_Workflow::class );
$call = static function ( string $method, array $arguments = array() ) use ( $reflection ) {
	$target = $reflection->getMethod( $method );
	if ( PHP_VERSION_ID < 80100 ) { $target->setAccessible( true ); }
	return $target->invokeArgs( null, $arguments );
};
$receipt_property = $reflection->getProperty( 'translation_job_recovery_transaction' );
if ( PHP_VERSION_ID < 80100 ) { $receipt_property->setAccessible( true ); }
$diagnostic_property = $reflection->getProperty( 'translation_job_recovery_transaction_diagnostic' );
if ( PHP_VERSION_ID < 80100 ) { $diagnostic_property->setAccessible( true ); }
$reconnect_guard_property = $reflection->getProperty( 'translation_job_reconnect_guard' );
if ( PHP_VERSION_ID < 80100 ) { $reconnect_guard_property->setAccessible( true ); }
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$connection = static function () use ( $original_wpdb, &$connections ): wpdb {
	$db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$db->set_prefix( $original_wpdb->prefix );
	$db->suppress_errors( true );
	if ( '1' !== (string) $db->get_var( 'SELECT 1' ) ) { throw new RuntimeException( 'Disposable wpdb connection is unavailable.' ); }
	$connections[] = $db;
	return $db;
};
$install = static function ( wpdb $base, array $faults = array() ) use ( $receipt_property ): Devenia_Workflow_Recovery_Intercept_WPDB {
	$receipt_property->setValue( null, array() );
	$wrapped = new Devenia_Workflow_Recovery_Intercept_WPDB( $base, $faults );
	$GLOBALS['wpdb'] = $wrapped;
	return $wrapped;
};
$receipt = static function () use ( $receipt_property ): array { return (array) $receipt_property->getValue(); };
$reconnect_retries = static function ( wpdb $db ): int {
	$property = ( new ReflectionObject( $db ) )->getProperty( 'reconnect_retries' );
	if ( PHP_VERSION_ID < 80100 ) { $property->setAccessible( true ); }
	return (int) $property->getValue( $db );
};

try {
	// A real standard wpdb object must restore retry state between boundaries.
	$base = $connection();
	$receipt_property->setValue( null, array() );
	$GLOBALS['wpdb'] = $base;
	$original_retries = $reconnect_retries( $base );
	$assert( $original_retries > 0, 'Standard wpdb reconnect retry fixture is not enabled.' );
	$assert( true === $call( 'translation_job_begin_recovery_transaction' ) && 0 === $reconnect_retries( $base ), 'First standard-wpdb boundary did not disable reconnect retries.' );
	$rollback = $call( 'translation_job_rollback_recovery_transaction' );
	$assert( ! empty( $rollback['success'] ) && $original_retries === $reconnect_retries( $base ), 'Rollback did not restore standard-wpdb reconnect retries.' );
	$assert( true === $call( 'translation_job_begin_recovery_transaction' ) && 0 === $reconnect_retries( $base ), 'Second sequential standard-wpdb boundary could not acquire the restored guard.' );
	$commit = $call( 'translation_job_commit_recovery_transaction' );
	$assert( ! empty( $commit['success'] ) && $original_retries === $reconnect_retries( $base ), 'Commit did not restore standard-wpdb reconnect retries.' );

	// Terminal database truth remains explicit when reconnect-guard restore fails.
	$base = $connection();
	$wrapped = $install( $base );
	$initial_retries = $reconnect_retries( $wrapped );
	$assert( true === $call( 'translation_job_begin_recovery_transaction' ), 'Commit guard-restore-failure fixture begin failed.' );
	$guard = (array) $reconnect_guard_property->getValue();
	$guard['original_retries'] = -1;
	$reconnect_guard_property->setValue( null, $guard );
	$commit = $call( 'translation_job_commit_recovery_transaction' );
	$assert( false === $commit['success'] && true === $commit['committed'] && empty( $commit['reconnect_guard_restored'] ) && empty( $receipt()['owned'] ), 'Committed boundary overstated guard cleanup or retained false transaction ownership.' );
	$guard['original_retries'] = $initial_retries;
	$reconnect_guard_property->setValue( null, $guard );
	$assert( $call( 'translation_job_restore_reconnect_retries' ), 'Commit fixture reconnect guard could not be repaired.' );

	$base = $connection();
	$wrapped = $install( $base );
	$initial_retries = $reconnect_retries( $wrapped );
	$assert( true === $call( 'translation_job_begin_recovery_transaction' ), 'Rollback guard-restore-failure fixture begin failed.' );
	$guard = (array) $reconnect_guard_property->getValue();
	$guard['original_retries'] = -1;
	$reconnect_guard_property->setValue( null, $guard );
	$rollback = $call( 'translation_job_rollback_recovery_transaction' );
	$fields = $call( 'translation_job_rollback_response_fields', array( $rollback ) );
	$assert( false === $rollback['success'] && true === $rollback['rolled_back'] && true === $fields['transaction_rolled_back'] && empty( $receipt()['owned'] ), 'Rolled-back boundary lost database truth or retained false transaction ownership after guard restore failure.' );
	$guard['original_retries'] = $initial_retries;
	$reconnect_guard_property->setValue( null, $guard );
	$assert( $call( 'translation_job_restore_reconnect_retries' ), 'Rollback fixture reconnect guard could not be repaired.' );

	// Primary metadata, unique receipt and structured rollback success.
	$base = $connection();
	$wrapped = $install( $base );
	$assert( true === $call( 'translation_job_begin_recovery_transaction' ), 'Primary InnoDB transaction begin failed.' );
	$first_receipt = $receipt();
	$assert( preg_match( '/^devenia_workflow_recovery_[a-f0-9]{24}$/D', (string) ( $first_receipt['savepoint'] ?? '' ) ) === 1, 'Savepoint receipt is not unique and SQL-safe.' );
	$prepared_savepoint = $wrapped->prepare( 'SAVEPOINT %i', (string) $first_receipt['savepoint'] );
	$assert( in_array( $prepared_savepoint, $wrapped->trace, true ), 'Savepoint creation did not use the WordPress-native prepared identifier form.' );
	$rolled_back = $call( 'translation_job_rollback_recovery_transaction' );
	$assert( ! empty( $rolled_back['success'] ) && ! empty( $rolled_back['rolled_back'] ), 'Structured rollback success was not returned.' );
	$assert( in_array( $wrapped->prepare( 'ROLLBACK TO SAVEPOINT %i', (string) $first_receipt['savepoint'] ), $wrapped->trace, true ), 'Rollback ownership proof did not use the WordPress-native prepared identifier form.' );
	$assert( in_array( 'ROLLBACK AND NO CHAIN NO RELEASE', $wrapped->trace, true ), 'Rollback did not override completion_type CHAIN and RELEASE.' );

	// Missing-only fallback and fail-closed metadata cases.
	$base = $connection();
	$wrapped = $install( $base, array( 'primary_empty' => true ) );
	$assert( true === $call( 'translation_job_begin_recovery_transaction' ), 'SHOW TABLE STATUS fallback did not prove all seven tables.' );
	$call( 'translation_job_rollback_recovery_transaction' );
	$base = $connection();
	$install( $base, array( 'primary_empty' => true, 'fallback_missing' => true ) );
	$assert( false === $call( 'translation_job_begin_recovery_transaction' ), 'Missing engine metadata did not fail closed.' );
	$diagnostic = (array) $diagnostic_property->getValue();
	$assert( 'core_table_metadata_unavailable' === (string) ( $diagnostic['code'] ?? '' ), 'Missing-metadata diagnostic is not stable.' );
	$base = $connection();
	$wrapped = $install( $base, array( 'primary_missing_one' => true ) );
	$assert( true === $call( 'translation_job_begin_recovery_transaction' ), 'One missing primary engine row was not recovered through fallback.' );
	$show_queries = array_values( array_filter( $wrapped->trace, static function ( string $query ): bool { return false !== stripos( $query, 'SHOW TABLE STATUS' ); } ) );
	$assert( 1 === count( $show_queries ), 'Missing-only fallback did not issue exactly one SHOW TABLE STATUS query.' );
	$call( 'translation_job_rollback_recovery_transaction' );
	$base = $connection();
	$wrapped = $install( $base, array( 'primary_non_innodb' => true ) );
	$assert( false === $call( 'translation_job_begin_recovery_transaction' ), 'Non-InnoDB primary proof was accepted.' );
	$show_queries = array_values( array_filter( $wrapped->trace, static function ( string $query ): bool { return false !== stripos( $query, 'SHOW TABLE STATUS' ); } ) );
	$assert( 0 === count( $show_queries ), 'Proven non-InnoDB metadata was incorrectly re-probed through fallback.' );

	// Real outer transaction: a write must remain invisible and then disappear on outer rollback.
	$base = $connection();
	$observer = $connection();
	$outer_option = '_devenia_workflow_outer_' . strtolower( wp_generate_password( 12, false, false ) );
	$option_names[] = $outer_option;
	$assert( false !== $base->query( 'START TRANSACTION' ), 'Could not create real outer transaction.' );
	$assert( 1 === $base->query( $base->prepare( "INSERT INTO {$base->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $outer_option, 'uncommitted', 'no' ) ), 'Could not write inside the outer transaction.' );
	$install( $base );
	$assert( false === $call( 'translation_job_begin_recovery_transaction' ), 'A real pre-existing outer transaction was accepted.' );
	$assert( 'uncommitted' === (string) $base->get_var( $base->prepare( "SELECT option_value FROM {$base->options} WHERE option_name = %s", $outer_option ) ), 'Outer write disappeared before caller rollback.' );
	$assert( null === $observer->get_var( $observer->prepare( "SELECT option_value FROM {$observer->options} WHERE option_name = %s", $outer_option ) ), 'Failed guard committed the caller-owned outer write.' );
	$base->query( 'ROLLBACK AND NO CHAIN NO RELEASE' );
	$assert( null === $observer->get_var( $observer->prepare( "SELECT option_value FROM {$observer->options} WHERE option_name = %s", $outer_option ) ), 'Caller rollback did not remove the preserved outer write.' );

	// Injected guard and START failures, including pending override cleanup.
	$base = $connection();
	$wrapped = $install( $base, array( 'fail_queries' => array( '/^SET TRANSACTION ISOLATION LEVEL SERIALIZABLE$/' ) ) );
	$assert( false === $call( 'translation_job_begin_recovery_transaction' ), 'Injected SET TRANSACTION failure was accepted.' );
	$base = $connection();
	$wrapped = $install( $base, array( 'fail_queries' => array( '/^START TRANSACTION$/' ) ) );
	$assert( false === $call( 'translation_job_begin_recovery_transaction' ), 'Injected START failure was accepted.' );
	$assert( count( preg_grep( '/^SET TRANSACTION ISOLATION LEVEL (?!SERIALIZABLE)/', $wrapped->trace ) ) === 1, 'START failure did not clear the pending next-transaction override.' );

	// A mid-write disconnect must never delegate/reissue on an autocommit connection.
	$base = $connection();
	$observer = $connection();
	$midwrite_option = '_devenia_workflow_midwrite_' . strtolower( wp_generate_password( 12, false, false ) );
	$option_names[] = $midwrite_option;
	$wrapped = $install( $base, array( 'mid_write_disconnect' => $midwrite_option ) );
	$assert( true === $call( 'translation_job_begin_recovery_transaction' ), 'Mid-write disconnect fixture begin failed.' );
	$write = $wrapped->query( $wrapped->prepare( "INSERT INTO {$wrapped->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $midwrite_option, 'must-not-reissue', 'no' ) );
	$assert( false === $write && in_array( 'MID_WRITE_RECONNECT_BLOCKED', $wrapped->trace, true ) && ! in_array( 'MID_WRITE_REISSUED_ON_DELEGATE', $wrapped->trace, true ), 'Mid-write disconnect was delegated/reissued despite the owned reconnect guard.' );
	$assert( null === $observer->get_var( $observer->prepare( "SELECT option_value FROM {$observer->options} WHERE option_name = %s", $midwrite_option ) ), 'Mid-write reconnect produced an autocommitted partial write.' );
	$rollback = $call( 'translation_job_rollback_recovery_transaction' );
	$assert( ! empty( $rollback['success'] ), 'Mid-write disconnect fixture did not terminate cleanly.' );

	// completion_type CHAIN and RELEASE cannot chain or disconnect commit/rollback.
	foreach ( array( 1, 2 ) as $completion_type ) {
		$base = $connection();
		$assert( false !== $base->query( 'SET SESSION completion_type = ' . $completion_type ), 'Could not set completion_type fixture.' );
		$wrapped = $install( $base );
		$assert( true === $call( 'translation_job_begin_recovery_transaction' ), 'Completion-mode commit begin failed.' );
		$commit_receipt = $receipt();
		$commit = $call( 'translation_job_commit_recovery_transaction' );
		$assert( ! empty( $commit['success'] ) && ! empty( $commit['committed'] ), 'Completion-mode commit failed.' );
		$prepared_commit_savepoint = $wrapped->prepare( 'SAVEPOINT %i', (string) $commit_receipt['savepoint'] );
		$assert( 2 === count( array_keys( $wrapped->trace, $prepared_commit_savepoint, true ) ), 'Commit did not create and refresh the same WordPress-prepared savepoint identifier.' );
		$assert( in_array( $wrapped->prepare( 'RELEASE SAVEPOINT %i', (string) $commit_receipt['savepoint'] ), $wrapped->trace, true ), 'Commit ownership proof did not release the WordPress-prepared savepoint identifier.' );
		$assert( '1' === (string) $base->get_var( 'SELECT 1' ), 'NO RELEASE did not preserve the connection after commit.' );
		$assert( false !== $base->query( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' ), 'NO CHAIN did not end the transaction after commit.' );
		$base->query( 'START TRANSACTION' );
		$base->query( 'ROLLBACK AND NO CHAIN NO RELEASE' );

		$wrapped = $install( $base );
		$assert( true === $call( 'translation_job_begin_recovery_transaction' ), 'Completion-mode rollback begin failed.' );
		$rollback = $call( 'translation_job_rollback_recovery_transaction' );
		$assert( ! empty( $rollback['success'] ), 'Completion-mode rollback failed.' );
		$assert( '1' === (string) $base->get_var( 'SELECT 1' ), 'NO RELEASE did not preserve the connection after rollback.' );
		$assert( false !== $base->query( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' ), 'NO CHAIN did not end the transaction after rollback.' );
		$base->query( 'START TRANSACTION' );
		$base->query( 'ROLLBACK AND NO CHAIN NO RELEASE' );
	}

	// A truthy COMMIT retried by wpdb on a new connection remains unknown.
	$base = $connection();
	$wrapped = $install( $base, array( 'reconnect_after_commit' => true ) );
	$assert( true === $call( 'translation_job_begin_recovery_transaction' ), 'Post-COMMIT reconnect fixture begin failed.' );
	$commit = $call( 'translation_job_commit_recovery_transaction' );
	$assert( false === $commit['success'] && null === $commit['committed'] && 'commit_outcome_unknown' === $commit['code'] && ! empty( $receipt()['owned'] ), 'Truthy COMMIT followed by changed connection identity overstated publication or discarded ownership.' );
	$call( 'translation_job_restore_reconnect_retries' );
	$receipt_property->setValue( null, array() );

	// COMMIT failure propagates proven rollback; terminal rollback failure stays false and owned.
	$base = $connection();
	$wrapped = $install( $base, array( 'fail_queries' => array( '/^COMMIT AND NO CHAIN NO RELEASE$/' ) ) );
	$assert( true === $call( 'translation_job_begin_recovery_transaction' ), 'Commit-failure fixture begin failed.' );
	$commit = $call( 'translation_job_commit_recovery_transaction' );
	$assert( false === $commit['success'] && false === $commit['committed'] && ! empty( $commit['rollback']['success'] ), 'COMMIT failure did not propagate a proven rollback.' );
	$base = $connection();
	$wrapped = $install( $base, array( 'fail_queries' => array( '/^COMMIT AND NO CHAIN NO RELEASE$/', '/^ROLLBACK AND NO CHAIN NO RELEASE$/' ) ) );
	$assert( true === $call( 'translation_job_begin_recovery_transaction' ), 'Ambiguous commit fixture begin failed.' );
	$commit = $call( 'translation_job_commit_recovery_transaction' );
	$assert( false === $commit['success'] && null === $commit['committed'] && empty( $commit['rollback']['success'] ) && ! empty( $receipt()['owned'] ), 'Ambiguous COMMIT/ROLLBACK failure overstated the outcome or discarded ownership.' );
	$base->query( 'ROLLBACK AND NO CHAIN NO RELEASE' );
	$call( 'translation_job_restore_reconnect_retries' );
	$receipt_property->setValue( null, array() );
	$base = $connection();
	$wrapped = $install( $base, array( 'fail_queries' => array( '/^ROLLBACK AND NO CHAIN NO RELEASE$/' ) ) );
	$assert( true === $call( 'translation_job_begin_recovery_transaction' ), 'Rollback-failure fixture begin failed.' );
	$rollback = $call( 'translation_job_rollback_recovery_transaction' );
	$assert( empty( $rollback['success'] ) && empty( $rollback['rolled_back'] ) && ! empty( $receipt()['owned'] ), 'Failed rollback overstated cleanup or discarded ownership.' );
	$fields = $call( 'translation_job_rollback_response_fields', array( $rollback ) );
	$assert( false === $fields['transaction_rolled_back'], 'Caller rollback truth overstated a failed terminal rollback.' );
	$base->query( 'ROLLBACK AND NO CHAIN NO RELEASE' );
	$call( 'translation_job_restore_reconnect_retries' );
	$receipt_property->setValue( null, array() );

	// A changed connection identity must lose ownership without clearing the private receipt.
	$base = $connection();
	$wrapped = $install( $base );
	$assert( true === $call( 'translation_job_begin_recovery_transaction' ), 'Connection-loss fixture begin failed.' );
	$actual_connection_id = (int) $base->get_var( 'SELECT CONNECTION_ID()' );
	$wrapped->faults['connection_id_override'] = $actual_connection_id + 1;
	$rollback = $call( 'translation_job_rollback_recovery_transaction' );
	$assert( empty( $rollback['success'] ) && 'transaction_ownership_lost' === $rollback['code'] && ! empty( $receipt()['owned'] ), 'Connection change did not fail closed while preserving the ownership receipt.' );
	$base->query( 'ROLLBACK AND NO CHAIN NO RELEASE' );
	$call( 'translation_job_restore_reconnect_retries' );
	$receipt_property->setValue( null, array() );

	// Destroying the private savepoint must be detected; a fixed-name replacement cannot forge it.
	$base = $connection();
	$install( $base );
	$assert( true === $call( 'translation_job_begin_recovery_transaction' ), 'Savepoint-loss fixture begin failed.' );
	$current = $receipt();
	$base->query( $base->prepare( 'RELEASE SAVEPOINT %i', (string) $current['savepoint'] ) );
	$base->query( 'SAVEPOINT devenia_workflow_recovery_owned' );
	$rollback = $call( 'translation_job_rollback_recovery_transaction' );
	$assert( empty( $rollback['success'] ) && 'transaction_ownership_lost' === $rollback['code'], 'A forged fixed savepoint replaced the private ownership receipt.' );
	$base->query( 'ROLLBACK AND NO CHAIN NO RELEASE' );
	$call( 'translation_job_restore_reconnect_retries' );
	$receipt_property->setValue( null, array() );

	echo wp_json_encode( array( 'success' => true, 'scenarios' => 22 ) ) . PHP_EOL;
} finally {
	$call( 'translation_job_restore_reconnect_retries' );
	$GLOBALS['wpdb'] = $original_wpdb;
	$receipt_property->setValue( null, array() );
	foreach ( $connections as $db ) {
		$db->query( 'ROLLBACK AND NO CHAIN NO RELEASE' );
		foreach ( $option_names as $option_name ) { $db->query( $db->prepare( "DELETE FROM {$db->options} WHERE option_name = %s", $option_name ) ); }
		$db->close();
	}
}
