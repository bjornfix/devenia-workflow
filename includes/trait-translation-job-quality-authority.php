<?php
/**
 * Quality Authority and staged publication for Translation Jobs.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Translation_Job_Quality_Authority {
	/** @var array<string,mixed> Request-local ownership receipt for the current recovery transaction. */
	private static $translation_job_recovery_transaction = array();

	/** @var array<string,mixed> Request-local wpdb reconnect-retry guard. */
	private static $translation_job_reconnect_guard = array();

	/** Whether the request-end reconnect guard cleanup hook is registered. */
	private static $translation_job_reconnect_guard_shutdown_registered = false;

	/** @var array<string,mixed> Last safe recovery-transaction diagnostic. */
	private static $translation_job_recovery_transaction_diagnostic = array(
		'phase' => 'idle',
		'code'  => 'not_started',
	);

	/** Save one credential-free, SQL-free transaction diagnostic. */
	private static function translation_job_set_recovery_transaction_diagnostic( string $phase, string $code, array $context = array() ): void {
		$diagnostic = array(
			'phase' => sanitize_key( $phase ),
			'code'  => sanitize_key( $code ),
		);
		foreach ( array( 'metadata_source', 'missing_table_count', 'table_count' ) as $key ) {
			if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ) {
				$diagnostic[ $key ] = is_numeric( $context[ $key ] ) ? (int) $context[ $key ] : sanitize_key( (string) $context[ $key ] );
			}
		}
		self::$translation_job_recovery_transaction_diagnostic = $diagnostic;
	}

	/** Return the stable structured diagnostic exposed by publication errors. */
	private static function translation_job_recovery_transaction_diagnostic(): array {
		return self::$translation_job_recovery_transaction_diagnostic;
	}

	/** Add safe transaction context without changing existing response fields. */
	private static function translation_job_recovery_transaction_error_fields(): array {
		return array( 'transaction' => self::translation_job_recovery_transaction_diagnostic() );
	}

	/** Normalize a database identifier for exact core-table comparison. */
	private static function translation_job_normalize_recovery_table_name( string $table ): string {
		$table = trim( $table );
		return preg_match( '/^[A-Za-z0-9_$]+$/D', $table ) ? strtolower( $table ) : '';
	}

	/** Read portable isolation and connection metadata without exposing SQL errors. */
	private static function translation_job_recovery_session_metadata(): array {
		global $wpdb;
		$state = $wpdb->get_row( 'SELECT CONNECTION_ID() AS connection_id', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- CONNECTION_ID() is portable across supported MySQL and MariaDB versions.
		if ( ! is_array( $state ) || ! isset( $state['connection_id'] ) || absint( $state['connection_id'] ) < 1 ) {
			return array( 'success' => false, 'code' => 'transaction_metadata_unavailable' );
		}
		$isolation_rows = $wpdb->get_results( "SHOW SESSION VARIABLES WHERE Variable_name IN ('transaction_isolation', 'tx_isolation')", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- One portable metadata read avoids probing a missing MySQL/MariaDB variable and logging a database error.
		$isolation = '';
		foreach ( is_array( $isolation_rows ) ? $isolation_rows : array() as $row ) {
			$name = strtolower( (string) ( $row['Variable_name'] ?? '' ) );
			if ( in_array( $name, array( 'transaction_isolation', 'tx_isolation' ), true ) && is_scalar( $row['Value'] ?? null ) ) {
				$isolation = (string) $row['Value'];
				if ( 'transaction_isolation' === $name ) { break; }
			}
		}
		if ( ! is_string( $isolation ) || '' === trim( $isolation ) ) {
			return array( 'success' => false, 'code' => 'transaction_isolation_unavailable' );
		}
		return array(
			'success'       => true,
			'connection_id' => absint( $state['connection_id'] ),
			'isolation'     => strtoupper( str_replace( ' ', '-', trim( $isolation ) ) ),
		);
	}

	/** Resolve one observed isolation level to a fixed SQL-token allowlist. */
	private static function translation_job_recovery_isolation_sql( string $isolation ): string {
		$levels = array(
			'READ-UNCOMMITTED' => 'READ UNCOMMITTED',
			'READ-COMMITTED'   => 'READ COMMITTED',
			'REPEATABLE-READ'  => 'REPEATABLE READ',
			'SERIALIZABLE'     => 'SERIALIZABLE',
		);
		return (string) ( $levels[ $isolation ] ?? '' );
	}

	/** Clear an unconsumed next-transaction override from a fixed allowlist. */
	private static function translation_job_clear_recovery_next_isolation( string $isolation ): bool {
		global $wpdb;
		switch ( $isolation ) {
			case 'READ-UNCOMMITTED':
				return false !== $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Fixed literal replaces an unconsumed next-transaction override after START failure; SESSION state is never mutated.
			case 'READ-COMMITTED':
				return false !== $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Fixed literal replaces an unconsumed next-transaction override after START failure; SESSION state is never mutated.
			case 'REPEATABLE-READ':
				return false !== $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Fixed literal replaces an unconsumed next-transaction override after START failure; SESSION state is never mutated.
			case 'SERIALIZABLE':
				return false !== $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Fixed literal replaces an unconsumed next-transaction override after START failure; SESSION state is never mutated.
			default:
				return false;
		}
	}

	/** Derive one unforgeable request-local SQL-safe savepoint identifier. */
	private static function translation_job_recovery_savepoint_name( string $owner_id ): string {
		$name = 'devenia_workflow_recovery_' . substr( hash( 'sha256', $owner_id ), 0, 24 );
		return preg_match( '/^[a-z0-9_]{1,64}$/D', $name ) ? $name : '';
	}

	/** Read/write wpdb's protected reconnect retry counter fail-closed. */
	private static function translation_job_wpdb_reconnect_retries( ?int $new_value = null ): array {
		global $wpdb;
		try {
			$reflection = new ReflectionObject( $wpdb );
			if ( ! $reflection->hasProperty( 'reconnect_retries' ) ) { return array( 'success' => false, 'code' => 'reconnect_retries_unavailable' ); }
			$property = $reflection->getProperty( 'reconnect_retries' );
			if ( PHP_VERSION_ID < 80100 ) { $property->setAccessible( true ); }
			$before = $property->getValue( $wpdb );
			if ( ! is_int( $before ) || $before < 0 ) { return array( 'success' => false, 'code' => 'reconnect_retries_invalid' ); }
			if ( null !== $new_value ) {
				if ( $new_value < 0 || $new_value > 100 ) { return array( 'success' => false, 'code' => 'reconnect_retries_restore_invalid' ); }
				$property->setValue( $wpdb, $new_value );
				if ( $new_value !== $property->getValue( $wpdb ) ) { return array( 'success' => false, 'code' => 'reconnect_retries_write_failed' ); }
			}
			return array( 'success' => true, 'value' => $before );
		} catch ( Throwable $error ) {
			return array( 'success' => false, 'code' => 'reconnect_retries_reflection_failed' );
		}
	}

	/** Disable wpdb reconnect/retry before any owned transaction SQL can run. */
	private static function translation_job_disable_reconnect_retries(): array {
		global $wpdb;
		if ( ! empty( self::$translation_job_reconnect_guard['active'] ) ) { return array( 'success' => false, 'code' => 'reconnect_guard_already_active' ); }
		$current = self::translation_job_wpdb_reconnect_retries();
		if ( empty( $current['success'] ) ) { return $current; }
		$disabled = self::translation_job_wpdb_reconnect_retries( 0 );
		if ( empty( $disabled['success'] ) ) { return $disabled; }
		$token = 'trg_' . substr( hash( 'sha256', wp_generate_uuid4() . '|' . microtime( true ) ), 0, 32 );
		self::$translation_job_reconnect_guard = array( 'active' => true, 'token' => $token, 'wpdb_id' => spl_object_id( $wpdb ), 'original_retries' => (int) $current['value'] );
		if ( ! self::$translation_job_reconnect_guard_shutdown_registered ) {
			self::$translation_job_reconnect_guard_shutdown_registered = true;
			add_action( 'shutdown', static function (): void {
				if ( empty( self::$translation_job_recovery_transaction['owned'] ) ) { self::translation_job_restore_reconnect_retries(); }
			}, PHP_INT_MAX );
		}
		return array( 'success' => true, 'token' => $token );
	}

	/** Prove reconnect/retry is still disabled on the exact guarded wpdb object. */
	private static function translation_job_reconnect_guard_active( string $token ): bool {
		global $wpdb;
		if ( empty( self::$translation_job_reconnect_guard['active'] ) || ! hash_equals( (string) ( self::$translation_job_reconnect_guard['token'] ?? '' ), $token ) || spl_object_id( $wpdb ) !== (int) ( self::$translation_job_reconnect_guard['wpdb_id'] ?? 0 ) ) { return false; }
		$current = self::translation_job_wpdb_reconnect_retries();
		return ! empty( $current['success'] ) && 0 === (int) ( $current['value'] ?? -1 );
	}

	/** Restore wpdb reconnect/retry only after a proven terminal boundary. */
	private static function translation_job_restore_reconnect_retries(): bool {
		if ( empty( self::$translation_job_reconnect_guard['active'] ) ) { return true; }
		$original = (int) ( self::$translation_job_reconnect_guard['original_retries'] ?? -1 );
		$restored = self::translation_job_wpdb_reconnect_retries( $original );
		if ( empty( $restored['success'] ) ) { return false; }
		self::$translation_job_reconnect_guard = array();
		return true;
	}

	/** Roll back only the transaction that this begin operation has just started. */
	private static function translation_job_abort_started_recovery_transaction(): array {
		global $wpdb;
		// START succeeded only after this same connection proved that no outer
		// transaction existed. A failed post-START state query must therefore not
		// leak the transaction. No callback or caller runs between START and this
		// branch, so the just-started boundary is the only possible active one.
		$rolled_back = false !== $wpdb->query( 'ROLLBACK AND NO CHAIN NO RELEASE' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Successful START after the no-outer guard proves this immediate private cleanup boundary; explicit NO CHAIN/NO RELEASE overrides completion_type.
		$guard_restored = $rolled_back && self::translation_job_restore_reconnect_retries();
		return array(
			'success'     => $rolled_back && $guard_restored,
			'rolled_back' => $rolled_back,
			'reconnect_guard_restored' => $guard_restored,
			'code'        => ! $rolled_back ? 'abort_rollback_failed' : ( $guard_restored ? 'abort_complete' : 'abort_reconnect_guard_restore_failed' ),
		);
	}

	/** Begin one InnoDB recovery boundary for a bounded publication mutation. */
	private static function translation_job_begin_recovery_transaction(): bool {
		global $wpdb;
		if ( ! empty( self::$translation_job_recovery_transaction['owned'] ) ) {
			self::translation_job_set_recovery_transaction_diagnostic( 'preexisting_check', 'owned_transaction_already_active' );
			return false;
		}
		self::$translation_job_recovery_transaction = array();
		$tables = array_values( array_unique( array( $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->termmeta, $wpdb->options ) ) );
		$normalized_tables = array();
		foreach ( $tables as $table ) {
			$normalized = self::translation_job_normalize_recovery_table_name( (string) $table );
			if ( '' === $normalized || isset( $normalized_tables[ $normalized ] ) ) {
				self::translation_job_set_recovery_transaction_diagnostic( 'metadata_primary', 'core_table_identity_invalid' );
				return false;
			}
			$normalized_tables[ $normalized ] = (string) $table;
		}
		if ( 7 !== count( $normalized_tables ) ) {
			self::translation_job_set_recovery_transaction_diagnostic( 'metadata_primary', 'core_table_set_incomplete', array( 'table_count' => count( $normalized_tables ) ) );
			return false;
		}
		$engine_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s, %s, %s, %s, %s, %s)', $tables[0], $tables[1], $tables[2], $tables[3], $tables[4], $tables[5], $tables[6] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Primary exact engine proof for the seven fixed owned core tables.
		$engines = array();
		foreach ( is_array( $engine_rows ) ? $engine_rows : array() as $row ) {
			$name = self::translation_job_normalize_recovery_table_name( (string) ( $row['TABLE_NAME'] ?? '' ) );
			if ( isset( $normalized_tables[ $name ] ) ) { $engines[ $name ] = strtoupper( trim( (string) ( $row['ENGINE'] ?? '' ) ) ); }
		}
		foreach ( $engines as $normalized => $engine ) {
			if ( '' === $engine ) {
				unset( $engines[ $normalized ] );
			} elseif ( 'INNODB' !== $engine ) {
				self::translation_job_set_recovery_transaction_diagnostic( 'metadata_primary', 'core_table_non_transactional', array( 'metadata_source' => 'information_schema' ) );
				return false;
			}
		}
		$missing = array_diff_key( $normalized_tables, $engines );
		if ( $missing ) {
			foreach ( $missing as $normalized => $table ) {
				$fallback_rows = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Safe exact-name fallback only for primary metadata gaps.
				if ( ! is_array( $fallback_rows ) || 1 !== count( $fallback_rows ) ) {
					self::translation_job_set_recovery_transaction_diagnostic( 'metadata_fallback', 'core_table_metadata_unavailable', array( 'metadata_source' => 'show_table_status', 'missing_table_count' => count( $missing ) ) );
					return false;
				}
				$row = $fallback_rows[0];
				if ( $normalized !== self::translation_job_normalize_recovery_table_name( (string) ( $row['Name'] ?? '' ) ) ) {
					self::translation_job_set_recovery_transaction_diagnostic( 'metadata_fallback', 'core_table_identity_mismatch', array( 'metadata_source' => 'show_table_status' ) );
					return false;
				}
				$engine = strtoupper( trim( (string) ( $row['Engine'] ?? '' ) ) );
				if ( 'INNODB' !== $engine ) {
					self::translation_job_set_recovery_transaction_diagnostic( 'metadata_fallback', '' === $engine ? 'core_table_engine_unknown' : 'core_table_non_transactional', array( 'metadata_source' => 'show_table_status' ) );
					return false;
				}
				$engines[ $normalized ] = $engine;
			}
		}
		if ( 7 !== count( $engines ) ) {
			self::translation_job_set_recovery_transaction_diagnostic( 'metadata_complete', 'core_table_set_unproven', array( 'table_count' => count( $engines ) ) );
			return false;
		}
		$before = self::translation_job_recovery_session_metadata();
		if ( empty( $before['success'] ) ) {
			self::translation_job_set_recovery_transaction_diagnostic( 'preexisting_check', (string) ( $before['code'] ?? 'transaction_metadata_unavailable' ) );
			return false;
		}
		$original_isolation = (string) ( $before['isolation'] ?? '' );
		if ( '' === self::translation_job_recovery_isolation_sql( $original_isolation ) ) {
			self::translation_job_set_recovery_transaction_diagnostic( 'preexisting_check', 'transaction_isolation_unsupported' );
			return false;
		}
		$reconnect_guard = self::translation_job_disable_reconnect_retries();
		if ( empty( $reconnect_guard['success'] ) ) {
			self::translation_job_set_recovery_transaction_diagnostic( 'reconnect_guard', (string) ( $reconnect_guard['code'] ?? 'reconnect_guard_unavailable' ) );
			return false;
		}
		// Portable outer-transaction guard: MySQL and MariaDB reject the
		// transaction-scoped form while a transaction is already active. A
		// successful statement affects only the next transaction and is consumed
		// by START below or explicitly restored on every pre-START failure path.
		if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Portable active/unknown transaction guard; deliberately not SESSION scope.
			$restored = self::translation_job_restore_reconnect_retries();
			self::translation_job_set_recovery_transaction_diagnostic( 'preexisting_check', $restored ? 'preexisting_or_unknown_transaction_refused' : 'preexisting_or_unknown_transaction_refused_reconnect_guard_restore_failed' );
			return false;
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- WordPress has no transaction API; publication recovery requires one atomic database boundary.
			$cleared = self::translation_job_clear_recovery_next_isolation( $original_isolation );
			$restored = self::translation_job_restore_reconnect_retries();
			self::translation_job_set_recovery_transaction_diagnostic( 'start', ! $cleared ? 'start_transaction_failed_next_isolation_clear_failed' : ( $restored ? 'start_transaction_failed' : 'start_transaction_failed_reconnect_guard_restore_failed' ) );
			return false;
		}
		$connection_id = absint( $before['connection_id'] ?? 0 );
		$active = self::translation_job_recovery_session_metadata();
		if ( empty( $active['success'] ) || $connection_id !== absint( $active['connection_id'] ?? 0 ) || $original_isolation !== (string) ( $active['isolation'] ?? '' ) ) {
			$aborted = self::translation_job_abort_started_recovery_transaction();
			$code = empty( $active['success'] ) ? (string) ( $active['code'] ?? 'transaction_metadata_unavailable' ) : ( $connection_id !== absint( $active['connection_id'] ?? 0 ) ? 'connection_identity_changed' : 'session_isolation_changed' );
			self::translation_job_set_recovery_transaction_diagnostic( 'verify_start', ! empty( $aborted['success'] ) ? $code : $code . '_abort_failed' );
			return false;
		}
		$owner_id = wp_generate_uuid4();
		$savepoint = self::translation_job_recovery_savepoint_name( $owner_id );
		if ( '' === $savepoint || false === $wpdb->query( $wpdb->prepare( 'SAVEPOINT %i', $savepoint ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress-native identifier preparation protects the private, strictly validated savepoint name.
			$aborted = self::translation_job_abort_started_recovery_transaction();
			self::translation_job_set_recovery_transaction_diagnostic( 'ownership', ! empty( $aborted['success'] ) ? 'savepoint_create_failed' : 'savepoint_create_failed_abort_failed' );
			return false;
		}
		self::$translation_job_recovery_transaction = array( 'owned' => true, 'owner_id' => $owner_id, 'savepoint' => $savepoint, 'reconnect_guard_token' => (string) $reconnect_guard['token'], 'connection_id' => $connection_id, 'session_isolation' => $original_isolation, 'transaction_isolation' => 'SERIALIZABLE' );
		self::translation_job_set_recovery_transaction_diagnostic( 'ready', 'transaction_owned', array( 'metadata_source' => $missing ? 'information_schema_and_show' : 'information_schema', 'table_count' => 7 ) );
		return true;
	}

	/** Commit one bounded publication/recovery transaction with a terminal outcome. */
	private static function translation_job_commit_recovery_transaction(): array {
		global $wpdb;
		$receipt = self::$translation_job_recovery_transaction;
		$savepoint = self::translation_job_recovery_savepoint_name( (string) ( $receipt['owner_id'] ?? '' ) );
		if ( empty( $receipt['owned'] ) || empty( $receipt['owner_id'] ) || '' === $savepoint || ! hash_equals( $savepoint, (string) ( $receipt['savepoint'] ?? '' ) ) ) {
			self::translation_job_set_recovery_transaction_diagnostic( 'commit_preflight', 'transaction_not_owned' );
			return array( 'success' => false, 'committed' => null, 'code' => 'transaction_not_owned' );
		}
		if ( ! self::translation_job_reconnect_guard_active( (string) ( $receipt['reconnect_guard_token'] ?? '' ) ) ) {
			self::translation_job_set_recovery_transaction_diagnostic( 'commit_preflight', 'reconnect_guard_lost' );
			return array( 'success' => false, 'committed' => null, 'code' => 'commit_outcome_unknown' );
		}
		$state = self::translation_job_recovery_session_metadata();
		if ( empty( $state['success'] ) ) {
			$rollback = self::translation_job_rollback_recovery_transaction();
			self::translation_job_set_recovery_transaction_diagnostic( 'commit_preflight', ! empty( $rollback['success'] ) ? 'transaction_metadata_unavailable_owned_rollback_complete' : 'transaction_metadata_unavailable_owned_rollback_failed' );
			return array( 'success' => false, 'committed' => ! empty( $rollback['success'] ) ? false : null, 'code' => ! empty( $rollback['success'] ) ? 'transaction_metadata_unavailable_rolled_back' : 'commit_outcome_unknown', 'rollback' => $rollback );
		}
		if ( absint( $receipt['connection_id'] ?? 0 ) !== absint( $state['connection_id'] ?? 0 ) ) {
			self::translation_job_set_recovery_transaction_diagnostic( 'commit_preflight', 'transaction_ownership_lost' );
			return array( 'success' => false, 'committed' => null, 'code' => 'commit_outcome_unknown' );
		}
		if ( (string) ( $receipt['session_isolation'] ?? '' ) !== (string) ( $state['isolation'] ?? '' ) || 'SERIALIZABLE' !== (string) ( $receipt['transaction_isolation'] ?? '' ) ) {
			$rollback = self::translation_job_rollback_recovery_transaction();
			self::translation_job_set_recovery_transaction_diagnostic( 'commit_preflight', ! empty( $rollback['success'] ) ? 'serializable_ownership_lost_rollback_complete' : 'serializable_ownership_lost_rollback_failed' );
			return array( 'success' => false, 'committed' => ! empty( $rollback['success'] ) ? false : null, 'code' => ! empty( $rollback['success'] ) ? 'serializable_ownership_lost_rolled_back' : 'commit_outcome_unknown', 'rollback' => $rollback );
		}
		if ( false === $wpdb->query( $wpdb->prepare( 'RELEASE SAVEPOINT %i', $savepoint ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress-native identifier preparation protects the private savepoint receipt that must still exist.
			$rollback = self::translation_job_rollback_recovery_transaction();
			self::translation_job_set_recovery_transaction_diagnostic( 'commit_preflight', 'transaction_ownership_lost' );
			return array( 'success' => false, 'committed' => ! empty( $rollback['success'] ) ? false : null, 'code' => ! empty( $rollback['success'] ) ? 'transaction_ownership_lost_rolled_back' : 'commit_outcome_unknown', 'rollback' => $rollback );
		}
		if ( false === $wpdb->query( $wpdb->prepare( 'SAVEPOINT %i', $savepoint ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Re-establish the same WordPress-prepared private receipt immediately after the ownership check.
			$rolled_back = false !== $wpdb->query( 'ROLLBACK AND NO CHAIN NO RELEASE' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Ownership was proven by the immediately preceding release; explicit NO CHAIN/NO RELEASE overrides completion_type.
			$guard_restored = $rolled_back && self::translation_job_restore_reconnect_retries();
			if ( $rolled_back ) { self::$translation_job_recovery_transaction = array(); }
			self::translation_job_set_recovery_transaction_diagnostic( 'commit_preflight', $rolled_back && $guard_restored ? 'ownership_receipt_refresh_failed_rollback_complete' : 'ownership_receipt_refresh_failed_rollback_incomplete' );
			return array(
				'success' => false,
				'committed' => false,
				'code' => 'ownership_receipt_refresh_failed',
				'rollback' => array( 'success' => $rolled_back && $guard_restored, 'rolled_back' => $rolled_back, 'reconnect_guard_restored' => $guard_restored, 'code' => ! $rolled_back ? 'rollback_failed' : ( $guard_restored ? 'transaction_rolled_back' : 'rollback_reconnect_guard_restore_failed' ) ),
			);
		}
		$committed = false !== $wpdb->query( 'COMMIT AND NO CHAIN NO RELEASE' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Explicit NO CHAIN/NO RELEASE overrides completion_type.
		if ( ! $committed ) {
			$rollback = self::translation_job_rollback_recovery_transaction();
			self::translation_job_set_recovery_transaction_diagnostic( 'commit', ! empty( $rollback['success'] ) ? 'commit_failed_owned_rollback_complete' : 'commit_outcome_unknown' );
			return array( 'success' => false, 'committed' => ! empty( $rollback['success'] ) ? false : null, 'code' => ! empty( $rollback['success'] ) ? 'commit_failed_rolled_back' : 'commit_outcome_unknown', 'rollback' => $rollback );
		}
		// wpdb::query() may reconnect after a server-gone-away error and retry the
		// same COMMIT on a fresh connection. A truthy SQL result is therefore not
		// sufficient: prove that the post-COMMIT metadata still belongs to the
		// exact connection and unchanged session captured by this receipt.
		$after_commit = self::translation_job_recovery_session_metadata();
		if ( empty( $after_commit['success'] ) || absint( $receipt['connection_id'] ?? 0 ) !== absint( $after_commit['connection_id'] ?? 0 ) || (string) ( $receipt['session_isolation'] ?? '' ) !== (string) ( $after_commit['isolation'] ?? '' ) ) {
			self::translation_job_set_recovery_transaction_diagnostic( 'commit_verify', 'commit_outcome_unknown' );
			return array( 'success' => false, 'committed' => null, 'code' => 'commit_outcome_unknown' );
		}
		$guard_restored = self::translation_job_restore_reconnect_retries();
		self::$translation_job_recovery_transaction = array();
		self::translation_job_set_recovery_transaction_diagnostic( 'committed', $guard_restored ? 'transaction_committed' : 'transaction_committed_reconnect_guard_restore_failed' );
		return array( 'success' => $guard_restored, 'committed' => true, 'reconnect_guard_restored' => $guard_restored, 'code' => $guard_restored ? 'transaction_committed' : 'transaction_committed_reconnect_guard_restore_failed' );
	}

	/** Roll back one bounded publication/recovery transaction. */
	private static function translation_job_rollback_recovery_transaction(): array {
		global $wpdb;
		$receipt = self::$translation_job_recovery_transaction;
		$savepoint = self::translation_job_recovery_savepoint_name( (string) ( $receipt['owner_id'] ?? '' ) );
		if ( empty( $receipt['owned'] ) || empty( $receipt['owner_id'] ) || '' === $savepoint || ! hash_equals( $savepoint, (string) ( $receipt['savepoint'] ?? '' ) ) ) {
			return array( 'success' => false, 'rolled_back' => false, 'code' => 'transaction_not_owned' );
		}
		if ( ! self::translation_job_reconnect_guard_active( (string) ( $receipt['reconnect_guard_token'] ?? '' ) ) ) {
			self::translation_job_set_recovery_transaction_diagnostic( 'rollback_preflight', 'reconnect_guard_lost' );
			return array( 'success' => false, 'rolled_back' => false, 'reconnect_guard_restored' => false, 'code' => 'transaction_ownership_lost' );
		}
		$state = self::translation_job_recovery_session_metadata();
		if ( empty( $state['success'] ) || absint( $receipt['connection_id'] ?? 0 ) !== absint( $state['connection_id'] ?? 0 ) || (string) ( $receipt['session_isolation'] ?? '' ) !== (string) ( $state['isolation'] ?? '' ) || 'SERIALIZABLE' !== (string) ( $receipt['transaction_isolation'] ?? '' ) ) {
			self::translation_job_set_recovery_transaction_diagnostic( 'rollback_preflight', 'transaction_ownership_lost' );
			return array( 'success' => false, 'rolled_back' => false, 'code' => 'transaction_ownership_lost' );
		}
		if ( false === $wpdb->query( $wpdb->prepare( 'ROLLBACK TO SAVEPOINT %i', $savepoint ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress-native identifier preparation preserves the private savepoint as independent activity proof.
			self::translation_job_set_recovery_transaction_diagnostic( 'rollback_preflight', 'transaction_ownership_lost' );
			return array( 'success' => false, 'rolled_back' => false, 'code' => 'transaction_ownership_lost' );
		}
		$rolled_back = false !== $wpdb->query( 'ROLLBACK AND NO CHAIN NO RELEASE' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Savepoint proof binds this rollback to the owned transaction; explicit NO CHAIN/NO RELEASE overrides completion_type.
		if ( ! $rolled_back ) {
			self::translation_job_set_recovery_transaction_diagnostic( 'rolled_back', 'rollback_failed' );
			return array( 'success' => false, 'rolled_back' => false, 'code' => 'rollback_failed' );
		}
		$guard_restored = self::translation_job_restore_reconnect_retries();
		self::$translation_job_recovery_transaction = array();
		self::translation_job_set_recovery_transaction_diagnostic( 'rolled_back', $guard_restored ? 'transaction_rolled_back' : 'rollback_reconnect_guard_restore_failed' );
		return array( 'success' => $guard_restored, 'rolled_back' => true, 'reconnect_guard_restored' => $guard_restored, 'code' => $guard_restored ? 'transaction_rolled_back' : 'rollback_reconnect_guard_restore_failed' );
	}

	/** Expose a terminal rollback outcome without overstating incomplete cleanup. */
	private static function translation_job_rollback_response_fields( array $outcome ): array {
		return array(
			'transaction_rolled_back' => ! empty( $outcome['rolled_back'] ),
			'transaction_rollback'    => array(
				'success'            => ! empty( $outcome['success'] ),
				'rolled_back'        => ! empty( $outcome['rolled_back'] ),
				'reconnect_guard_restored' => ! empty( $outcome['reconnect_guard_restored'] ),
				'code'               => sanitize_key( (string) ( $outcome['code'] ?? 'rollback_outcome_unavailable' ) ),
			),
		);
	}

	/** Roll back and attach the exact terminal result to an array failure. */
	private static function translation_job_failure_after_recovery_rollback( array $failure ): array {
		$rollback = self::translation_job_rollback_recovery_transaction();
		return array_merge( $failure, self::translation_job_rollback_response_fields( $rollback ) );
	}

	/** Lock every database row and indexed relationship range owned by recovery. */
	private static function translation_job_lock_recovery_surface( int $translation_id, array $term_scope = array(), array $identity_scope = array() ): array {
		global $wpdb;
		$queries = array();
		$identity_translation_id = 0;
		if ( ! empty( $identity_scope['source_id'] ) && ! empty( $identity_scope['language'] ) && ! empty( $identity_scope['post_type'] ) ) {
			$identity_queries = array(
				$wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s FOR UPDATE", self::META_SOURCE_ID, (string) absint( $identity_scope['source_id'] ) ),
				$wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s FOR UPDATE", self::META_LANGUAGE, sanitize_key( (string) $identity_scope['language'] ) ),
			);
			foreach ( $identity_queries as $identity_query ) {
				if ( false === $wpdb->query( $identity_query ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared next-key locks protect the canonical translation identity, including absence.
					return array( 'success' => false, 'code' => 'recovery_translation_identity_lock_failed', 'message' => 'The canonical translation identity range could not be locked safely.' );
				}
			}
			$candidate_ids = self::translation_job_find_translation_identity_candidate_ids_for_update( $identity_scope );
			if ( is_wp_error( $candidate_ids ) ) { return array( 'success' => false, 'code' => 'recovery_translation_candidate_lock_failed', 'message' => $candidate_ids->get_error_message() ); }
			$identity_ids = self::translation_job_find_translation_identity_ids( $identity_scope, true );
			if ( is_wp_error( $identity_ids ) ) { return array( 'success' => false, 'code' => 'recovery_translation_identity_lookup_failed', 'message' => $identity_ids->get_error_message() ); }
			$identity_translation_id = empty( $identity_ids ) ? 0 : absint( $identity_ids[0] );
		}
		$post_ids = array_values( array_unique( array_filter( array_merge( array( $translation_id, $identity_translation_id ), isset( $candidate_ids ) ? $candidate_ids : array() ) ) ) );
		foreach ( $post_ids as $post_id ) {
			$queries[] = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $post_id );
			$queries[] = $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d FOR UPDATE", $post_id );
			$queries[] = $wpdb->prepare( "SELECT object_id, term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id = %d FOR UPDATE", $post_id );
		}
		$term_ids = array();
		foreach ( $term_scope as $entry ) {
			$entry = (array) $entry;
			$source_term_id = absint( $entry['source_term_id'] ?? 0 );
			$language = sanitize_key( (string) ( $entry['language'] ?? '' ) );
			$taxonomy = sanitize_key( (string) ( $entry['taxonomy'] ?? '' ) );
			// Lock both identity-meta key ranges before resolving an existing or
			// absent term. This prevents a cached miss from leaving a phantom
			// insertion window for the same source/language identity.
			$identity_queries = array(
				$wpdb->prepare( "SELECT meta_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s FOR UPDATE", self::TERM_META_SOURCE_ID, (string) $source_term_id ),
				$wpdb->prepare( "SELECT meta_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s FOR UPDATE", self::TERM_META_LANGUAGE, $language ),
			);
			foreach ( $identity_queries as $identity_query ) {
				if ( false === $wpdb->query( $identity_query ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared next-key locks protect absent identity ranges.
					return array( 'success' => false, 'code' => 'recovery_term_identity_lock_failed', 'message' => 'The translated-term identity range could not be locked safely.' );
				}
			}
			$term_id = self::translation_job_find_scoped_term_id_for_update( $source_term_id, $language, $taxonomy );
			if ( is_wp_error( $term_id ) ) { return array( 'success' => false, 'code' => 'recovery_term_lock_lookup_failed', 'message' => $term_id->get_error_message() ); }
			if ( $term_id ) { $term_ids[] = absint( $term_id ); }
		}
		$term_ids = array_values( array_unique( array_filter( $term_ids ) ) );
		foreach ( $term_ids as $term_id ) {
			$queries[] = $wpdb->prepare( "SELECT term_id FROM {$wpdb->terms} WHERE term_id = %d FOR UPDATE", $term_id );
			$queries[] = $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d FOR UPDATE", $term_id );
			$queries[] = $wpdb->prepare( "SELECT meta_id FROM {$wpdb->termmeta} WHERE term_id = %d FOR UPDATE", $term_id );
			$queries[] = $wpdb->prepare( "SELECT tr.object_id, tr.term_taxonomy_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.term_id = %d FOR UPDATE", $term_id );
		}
		foreach ( $queries as $query ) {
			if ( false === $wpdb->query( $query ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared row locks form the atomic recovery boundary.
				return array( 'success' => false, 'code' => 'recovery_surface_lock_failed', 'message' => 'The publication recovery surface could not be locked safely.' );
			}
		}
		foreach ( $post_ids as $post_id ) { clean_post_cache( $post_id ); }
		if ( $term_ids ) { clean_term_cache( $term_ids ); }
		return array( 'success' => true, 'term_ids' => $term_ids, 'identity_translation_id' => $identity_translation_id );
	}

	/** Lock every source/language candidate before post_type can enter or leave the exact identity. */
	private static function translation_job_find_translation_identity_candidate_ids_for_update( array $identity_scope ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} sm ON sm.post_id = p.ID AND sm.meta_key = %s AND sm.meta_value = %s INNER JOIN {$wpdb->postmeta} lm ON lm.post_id = p.ID AND lm.meta_key = %s AND lm.meta_value = %s ORDER BY p.ID ASC FOR UPDATE",
			self::META_SOURCE_ID,
			(string) absint( $identity_scope['source_id'] ?? 0 ),
			self::META_LANGUAGE,
			sanitize_key( (string) ( $identity_scope['language'] ?? '' ) )
		);
		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Locks all candidates before post_type changes can alter the exact canonical identity.
		if ( '' !== (string) $wpdb->last_error ) { return new WP_Error( 'translation_identity_candidate_lock_failed', $wpdb->last_error ); }
		return array_values( array_map( 'absint', (array) $ids ) );
	}

	/** Read the exact canonical translation identity, optionally retaining row locks. */
	private static function translation_job_find_translation_identity_ids( array $identity_scope, bool $for_update = false ) {
		global $wpdb;
		$sql = $for_update
			? $wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} sm ON sm.post_id = p.ID AND sm.meta_key = %s AND sm.meta_value = %s INNER JOIN {$wpdb->postmeta} lm ON lm.post_id = p.ID AND lm.meta_key = %s AND lm.meta_value = %s WHERE p.post_type = %s ORDER BY p.ID ASC LIMIT 2 FOR UPDATE",
				self::META_SOURCE_ID,
				(string) absint( $identity_scope['source_id'] ?? 0 ),
				self::META_LANGUAGE,
				sanitize_key( (string) ( $identity_scope['language'] ?? '' ) ),
				sanitize_key( (string) ( $identity_scope['post_type'] ?? '' ) )
			)
			: $wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} sm ON sm.post_id = p.ID AND sm.meta_key = %s AND sm.meta_value = %s INNER JOIN {$wpdb->postmeta} lm ON lm.post_id = p.ID AND lm.meta_key = %s AND lm.meta_value = %s WHERE p.post_type = %s ORDER BY p.ID ASC LIMIT 2",
				self::META_SOURCE_ID,
				(string) absint( $identity_scope['source_id'] ?? 0 ),
				self::META_LANGUAGE,
				sanitize_key( (string) ( $identity_scope['language'] ?? '' ) ),
				sanitize_key( (string) ( $identity_scope['post_type'] ?? '' ) )
			);
		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Exact prepared identity read is required for publication ownership.
		if ( '' !== (string) $wpdb->last_error ) { return new WP_Error( 'translation_identity_lookup_failed', $wpdb->last_error ); }
		if ( count( (array) $ids ) > 1 ) { return new WP_Error( 'duplicate_translation_identity', 'More than one translation has the same source, language, and post type.' ); }
		return array_values( array_map( 'absint', (array) $ids ) );
	}

	/** Resolve one scoped identity with an uncached locking read. */
	private static function translation_job_find_scoped_term_id_for_update( int $source_term_id, string $language, string $taxonomy ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			"SELECT DISTINCT t.term_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = %s INNER JOIN {$wpdb->termmeta} sm ON sm.term_id = t.term_id AND sm.meta_key = %s AND sm.meta_value = %s INNER JOIN {$wpdb->termmeta} lm ON lm.term_id = t.term_id AND lm.meta_key = %s AND lm.meta_value = %s ORDER BY t.term_id ASC LIMIT 2 FOR UPDATE",
			$taxonomy,
			self::TERM_META_SOURCE_ID,
			(string) $source_term_id,
			self::TERM_META_LANGUAGE,
			sanitize_key( $language )
		);
		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Exact prepared locking read is required for publication ownership.
		if ( '' !== (string) $wpdb->last_error ) { return new WP_Error( 'localized_term_lock_lookup_failed', $wpdb->last_error ); }
		if ( count( (array) $ids ) > 1 ) { return new WP_Error( 'duplicate_localized_term_identity', 'More than one translated term has the same source/language identity.' ); }
		return empty( $ids ) ? 0 : absint( $ids[0] );
	}

	/** Drop object-cache views after a committed or rolled-back recovery boundary. */
	private static function translation_job_clean_recovery_caches( int $translation_id, array $term_scope = array() ): void {
		if ( $translation_id > 0 ) {
			clean_post_cache( $translation_id );
			wp_cache_delete( $translation_id, 'post_meta' );
		}
		$term_ids = array();
		foreach ( $term_scope as $entry ) {
			$entry = (array) $entry;
			$term_id = self::translation_job_find_scoped_term_id( absint( $entry['source_term_id'] ?? 0 ), (string) ( $entry['language'] ?? '' ), sanitize_key( (string) ( $entry['taxonomy'] ?? '' ) ) );
			if ( ! is_wp_error( $term_id ) && $term_id ) { $term_ids[] = absint( $term_id ); }
		}
		self::translation_job_clean_term_caches( $term_ids );
	}

	/** Drop both term objects and metadata written inside a transaction boundary. */
	private static function translation_job_clean_term_caches( array $term_ids ): void {
		$term_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );
		if ( empty( $term_ids ) ) { return; }
		clean_term_cache( $term_ids );
		foreach ( $term_ids as $term_id ) { wp_cache_delete( $term_id, 'term_meta' ); }
	}

	/** Serialize every Job/publication lifecycle mutation for one source/language pair. */
	private static function translation_job_acquire_lifecycle_lease( array $job, string $operation ): array {
		$source_id = absint( $job['source_id'] ?? 0 );
		$language = sanitize_key( (string) ( $job['target_language'] ?? '' ) );
		if ( $source_id < 1 || '' === $language ) {
			return array( 'success' => false, 'code' => 'translation_job_lifecycle_binding_invalid', 'message' => 'The Translation Job source/language lifecycle binding is unavailable.' );
		}
		$key = 'devenia_workflow_lifecycle_lease_' . substr( hash( 'sha256', $source_id . '|' . $language ), 0, 32 );
		$lease = array( 'token' => 'tjl_' . substr( hash( 'sha256', wp_generate_uuid4() . '|' . microtime( true ) ), 0, 32 ), 'job_id' => (string) ( $job['job_id'] ?? '' ), 'source_id' => $source_id, 'target_language' => $language, 'operation' => sanitize_key( $operation ), 'expires_at' => time() + 600 );
		if ( self::atomic_create_option( $key, $lease ) ) { return array( 'success' => true, 'key' => $key, 'lease' => $lease ); }
		$existing = get_option( $key );
		if ( is_array( $existing ) && absint( $existing['expires_at'] ?? 0 ) < time() ) {
			if ( self::atomic_replace_option_value( $key, $existing, $lease ) ) { return array( 'success' => true, 'key' => $key, 'lease' => $lease ); }
		}
		return array( 'success' => false, 'code' => 'translation_job_lifecycle_lease_conflict', 'message' => 'Another Workflow lifecycle operation already owns this source/language lease.' );
	}

	/** Renew the owned lifecycle lease through compare-and-swap before slow public checks. */
	private static function translation_job_renew_lifecycle_lease( array $lease_result ): array {
		$key = (string) ( $lease_result['key'] ?? '' );
		$owned = (array) ( $lease_result['lease'] ?? array() );
		$renewed = $owned;
		// A renewal may run in the same second as acquisition. Always advance the
		// serialized value so MySQL reports a successful owned CAS instead of a
		// no-op update with zero affected rows.
		$renewed['expires_at'] = max( time() + 600, absint( $owned['expires_at'] ?? 0 ) + 1 );
		if ( ! self::atomic_replace_option_value( $key, $owned, $renewed ) ) {
			return array( 'success' => false, 'code' => 'translation_job_lifecycle_lease_lost', 'message' => 'The Workflow lifecycle lease could not be renewed safely.' );
		}
		return array( 'success' => true, 'key' => $key, 'lease' => $renewed );
	}

	/** Release only the lifecycle lease token acquired by this request. */
	private static function translation_job_release_lifecycle_lease( array $lease_result ): void {
		$key = sanitize_key( (string) ( $lease_result['key'] ?? '' ) );
		$owned = (array) ( $lease_result['lease'] ?? array() );
		if ( '' !== $key ) { self::atomic_delete_option_value( $key, $owned ); }
	}
	/**
	 * Return the server-issued principal represented by one active Job claim.
	 *
	 * The principal authenticates the bounded Run, not a person's motivation.
	 * Its identity is derived from the server-generated claim secret and cannot
	 * be selected by a caller through coordinator labels.
	 *
	 * @return array<string,mixed>
	 */
	private static function translation_job_authenticated_principal( array $job, array $run, array $claim ): array {
		$role       = sanitize_key( (string) ( $run['role'] ?? $claim['role'] ?? '' ) );
		$run_id     = self::translation_job_clean_id( (string) ( $run['run_id'] ?? $claim['run_id'] ?? '' ) );
		$token_hash = sanitize_text_field( (string) ( $claim['token_hash'] ?? '' ) );
		$user_id    = get_current_user_id();
		$submission_generation = max( 1, absint( $run['submission_generation'] ?? $claim['submission_generation'] ?? $job['submission_generation'] ?? 1 ) );
		$material   = implode( '|', array( (string) ( $job['job_id'] ?? '' ), (string) $submission_generation, $run_id, $role, (string) $user_id, $token_hash ) );

		return array(
			'principal_id'     => 'tjp_' . substr( hash( 'sha256', $material ), 0, 32 ),
			'job_id'           => (string) ( $job['job_id'] ?? '' ),
			'run_id'           => $run_id,
			'role'             => $role,
			'wordpress_user_id'=> $user_id,
			'authority'        => 'server_issued_translation_job_claim',
			'coordinator_label'=> sanitize_text_field( (string) ( $run['coordinator_id'] ?? '' ) ),
			'claim_digest'     => $token_hash,
			'issued_at'        => sanitize_text_field( (string) ( $claim['claimed_at'] ?? gmdate( 'c' ) ) ),
			'expires_at'       => sanitize_text_field( (string) ( $claim['expires_at'] ?? '' ) ),
			'submission_generation' => $submission_generation,
		);
	}

	/**
	 * Normalize presentation fragments to their exact logical storage identity.
	 *
	 * Translator artifacts may express plain-text fragments with a `text` field,
	 * while the durable WordPress representation always uses `html`. Sorting the
	 * normalized key/value records also makes verification independent of storage
	 * merge order without weakening source-design coverage or its exact hash.
	 *
	 * @param mixed $fragments Raw manifest or stored fragment rows.
	 * @return array<int,array{key:string,html:string}>
	 */
	private static function translation_job_normalized_presentation_fragments( $fragments ): array {
		$records = self::localized_fragment_records_for_storage( $fragments );
		usort(
			$records,
			static function ( array $left, array $right ): int {
				return strcmp( (string) ( $left['key'] ?? '' ), (string) ( $right['key'] ?? '' ) );
			}
		);

		return $records;
	}

	/**
	 * Build and validate the complete staged reader surface without writing it.
	 *
	 * @return array<string,mixed>
	 */
	private static function translation_job_stage_artifact( array $job, array $artifact ): array {
		$source = get_post( absint( $job['source_id'] ?? 0 ) );
		if ( ! $source instanceof WP_Post ) {
			return array( 'success' => false, 'code' => 'job_source_missing', 'message' => 'Translation Job source is unavailable.', 'mutation_started' => false );
		}
		$title   = sanitize_text_field( (string) ( $artifact['title'] ?? '' ) );
		$excerpt = sanitize_textarea_field( (string) ( $artifact['excerpt'] ?? '' ) );
		$language = sanitize_key( (string) ( $job['target_language'] ?? '' ) );
		if ( '' === $title ) {
			return array( 'success' => false, 'code' => 'staged_title_required', 'message' => 'A staged translation title is required.' );
		}

		$projection_input = array_merge(
			$artifact,
			array(
				'source_id'              => (int) $source->ID,
				'language'               => $language,
				'inherit_source_design'  => true,
				'strict_source_design_fragments' => true,
			)
		);
		$projection = self::inherited_source_design_content( $source, $projection_input, $language );
		if ( empty( $projection['success'] ) ) {
			return array_merge( $projection, array( 'code' => (string) ( $projection['code'] ?? 'staged_projection_failed' ) ) );
		}
		$content = self::normalize_gutenberg_content_for_storage(
			self::localize_internal_links_in_content( (string) ( $projection['content'] ?? '' ), $language )
		);
		$guardrails = self::translation_guardrails(
			$content,
			(string) $source->post_content,
			$title,
			$excerpt,
			self::translation_fitness_context( $language, (int) $source->ID )
		);
		if ( ! empty( $guardrails['issues'] ) ) {
			return array( 'success' => false, 'code' => 'staged_guardrails_failed', 'message' => 'The staged artifact failed translation guardrails.', 'guardrails' => $guardrails );
		}
		$taxonomy = self::validate_translated_post_terms_before_save( $source, $language, $artifact['taxonomies'] ?? array() );
		if ( empty( $taxonomy['success'] ) ) {
			return $taxonomy;
		}

		$translation_id = absint( $job['translation_id'] ?? 0 );
		if ( ! $translation_id ) {
			$translation_id = self::find_translation_id( (int) $source->ID, $language, self::translation_workflow_post_statuses( false ) );
		}
		$existing = $translation_id ? get_post( $translation_id ) : null;
		$canonical_route_resolution = $existing instanceof WP_Post
			? self::effective_translation_canonical_route( $existing, $language )
			: array( 'success' => true, 'route' => array() );
		if ( empty( $canonical_route_resolution['success'] ) ) {
			return $canonical_route_resolution;
		}
		$route = $existing instanceof WP_Post
			? array(
				'translation_id' => (int) $existing->ID,
				'post_name'      => (string) $existing->post_name,
				'post_parent'    => (int) $existing->post_parent,
				'localized_path' => trim( (string) get_post_meta( (int) $existing->ID, self::META_LOCALIZED_PATH, true ), '/' ),
				'canonical_route'=> (array) $canonical_route_resolution['route'],
			)
			: array(
				'translation_id'       => 0,
				'localized_slug'       => sanitize_title( (string) ( $artifact['localized_slug'] ?? '' ) ),
				'localized_path'       => trim( sanitize_text_field( (string) ( $artifact['localized_path'] ?? '' ) ), '/' ),
				'localized_parent_id'  => 0,
				'localized_parent_path'=> trim( sanitize_text_field( (string) ( $artifact['localized_parent_path'] ?? '' ) ), '/' ),
			);
		if ( ! $existing instanceof WP_Post ) {
			$raw_slug = (string) ( $artifact['localized_slug'] ?? '' );
			$slug = sanitize_title( $raw_slug );
			if ( '' === $slug ) { return array( 'success' => false, 'code' => 'staged_localized_slug_required', 'message' => 'A new staged translation requires a localized slug.' ); }
			$slug_issue = self::validate_localized_slug( $raw_slug, $slug, $language, $source, ! empty( $artifact['allow_source_slug_in_url'] ), (string) ( $artifact['source_slug_reason'] ?? '' ) );
			if ( $slug_issue ) { return $slug_issue; }
			if ( self::has_wordpress_duplicate_slug_suffix( $slug ) ) { return array( 'success' => false, 'code' => 'staged_duplicate_slug_suffix', 'message' => 'A staged localized slug cannot use a WordPress duplicate suffix.' ); }
			$parent_id = absint( $artifact['localized_parent_id'] ?? 0 );
			if ( 'page' === (string) $source->post_type ) {
				$parent_path = self::normalize_localized_parent_path( (string) ( $artifact['localized_parent_path'] ?? '' ), $language );
				$parent_result = self::translation_job_resolve_localized_parent( $source, $language, $parent_id, $parent_path, ! empty( $artifact['allow_source_slug_in_url'] ), (string) ( $artifact['source_slug_reason'] ?? '' ) );
				if ( empty( $parent_result['success'] ) ) { return $parent_result; }
				$parent_id = absint( $parent_result['parent_id'] ?? 0 );
				$parent_path = (string) ( $parent_result['parent_path'] ?? $parent_path );
				$route['localized_parent_id'] = $parent_id;
				$route['localized_parent_path'] = $parent_path;
			}
			if ( self::translation_slug_conflicts( $slug, (string) $source->post_type, $parent_id, 0 ) ) { return array( 'success' => false, 'code' => 'staged_localized_slug_collision', 'message' => 'The staged localized route collides with existing content.' ); }
		}

		$seo_surface = self::canonical_seo_surface_for_translation_job( $artifact, $title, $excerpt, $content );
		$expected_taxonomies = self::translation_job_expected_taxonomy_surface( $source, $language, is_array( $artifact['taxonomies'] ?? null ) ? $artifact['taxonomies'] : array() );
		if ( is_wp_error( $expected_taxonomies ) ) {
			return array( 'success' => false, 'code' => 'staged_taxonomy_read_failed', 'message' => $expected_taxonomies->get_error_message(), 'mutation_started' => false );
		}
		$featured_image_identity = self::publication_featured_image_revision_identity( $source );
		if (
			absint( $featured_image_identity['attachment_id'] ?? 0 ) > 0
			&& empty( $featured_image_identity['file_identity']['available'] )
		) {
			return array( 'success' => false, 'code' => 'source_featured_image_identity_unavailable', 'message' => 'The source featured-image bytes could not be identified; staging fails closed.', 'media_identity' => $featured_image_identity );
		}
		$manifest = array(
			'schema_version'  => 2,
			'job_id'          => (string) $job['job_id'],
			'source_revision' => (string) $job['source_revision'],
			'language'        => $language,
			'content'         => array( 'title' => $title, 'excerpt' => $excerpt, 'gutenberg' => $content ),
			'seo'             => $seo_surface,
			'taxonomies'      => self::translation_job_canonicalize( $expected_taxonomies ),
			'route'           => $route,
			'media'           => array(
				'featured_image'     => $featured_image_identity,
				'featured_image_alt' => sanitize_text_field( (string) ( $artifact['featured_image_alt'] ?? '' ) ),
			),
			'presentation'    => array(
				'source_design_hash' => (string) ( self::source_design_contract( $source )['design_hash'] ?? '' ),
				'localized_fragments' => self::translation_job_normalized_presentation_fragments( $artifact['localized_fragments'] ?? array() ),
			),
		);
		$surface_revision = self::translation_job_surface_revision( $manifest );

		return array(
			'success'          => true,
			'content_revision' => hash( 'sha256', $title . "\n" . $excerpt . "\n" . $content ),
			'surface_revision' => $surface_revision,
			'manifest'         => $manifest,
			'projected_content'=> $content,
			'guardrails'       => $guardrails,
			'taxonomy'         => $taxonomy,
			'translation_id'   => $translation_id,
		);
	}

	/**
	 * Content-address one complete public surface contract.
	 */
	private static function translation_job_surface_revision( array $manifest ): string {
		return 'sr_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $manifest ) ) ?: '' ), 0, 40 );
	}

	/**
	 * Hash the current stored WordPress surface so drift invalidates approval.
	 */
	private static function translation_job_current_surface_revision( int $translation_id ): string {
		$post = get_post( $translation_id );
		if ( ! $post instanceof WP_Post ) {
			return '';
		}
		$manifest = array(
			'post' => array(
				'id' => (int) $post->ID,
				'title' => (string) $post->post_title,
				'excerpt' => (string) $post->post_excerpt,
				'content' => (string) $post->post_content,
				'status' => (string) $post->post_status,
				'slug' => (string) $post->post_name,
				'parent' => (int) $post->post_parent,
			),
			'seo' => array(
				'title' => (string) get_post_meta( $translation_id, 'rank_math_title', true ),
				'description' => (string) get_post_meta( $translation_id, 'rank_math_description', true ),
				'focus_keyword' => (string) get_post_meta( $translation_id, 'rank_math_focus_keyword', true ),
			),
			'taxonomies' => self::post_taxonomy_payload( $post ),
			'route' => array(
				'localized_path' => (string) get_post_meta( $translation_id, self::META_LOCALIZED_PATH, true ),
				'canonical_route' => self::json_post_meta_value( $translation_id, self::META_CANONICAL_ROUTE ),
			),
			'media' => array(
				'featured_image' => self::publication_featured_image_revision_identity( $translation_id ),
				'visible_media_provenance' => self::json_post_meta_value( $translation_id, self::META_VISIBLE_MEDIA_PROVENANCE ),
			),
			'presentation' => array(
				'source_design_hash' => (string) get_post_meta( $translation_id, self::META_SOURCE_DESIGN_HASH, true ),
				'localized_fragments' => self::json_post_meta_value( $translation_id, self::META_LOCALIZED_FRAGMENTS ),
			),
		);
		return self::translation_job_surface_revision( $manifest );
	}

	/**
	 * Validate and issue immutable Quality evidence receipts.
	 *
	 * @return array<string,mixed>
	 */
	private static function translation_job_quality_evidence_receipts( array $job, array $artifact_record, array $input, array $reviewer_principal ): array {
		$required_kinds = array( 'deterministic_structure', 'source_coverage', 'localized_route_links', 'seo_taxonomy', 'offer_contact', 'http_live_dom' );
		$receipt_ids = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) ( $input['evidence_receipt_ids'] ?? array() ) ) ) ) );
		$resolved = array();
		$resolved_kinds = array();
		foreach ( $receipt_ids as $receipt_id ) {
			$receipt = get_option( self::translation_job_quality_receipt_key( $receipt_id ) );
			if ( ! is_array( $receipt ) ) {
				return array( 'success' => false, 'code' => 'quality_receipt_missing', 'message' => 'A submitted server Quality receipt ID does not exist.', 'receipt_id' => $receipt_id );
			}
			if (
				(string) ( $receipt['artifact_revision'] ?? '' ) !== (string) $artifact_record['artifact_revision']
				|| (string) ( $receipt['surface_revision'] ?? '' ) !== (string) $artifact_record['surface_revision']
				|| (string) ( $receipt['principal_id'] ?? '' ) !== (string) ( $reviewer_principal['principal_id'] ?? '' )
				|| 'workflow' !== (string) ( $receipt['issuer'] ?? '' )
				|| empty( $receipt['passed'] )
				|| 'quality-authority-v1' !== (string) ( $receipt['policy_revision'] ?? '' )
			) {
				return array( 'success' => false, 'code' => 'quality_receipt_binding_mismatch', 'message' => 'A server Quality receipt belongs to another artifact, surface, or Quality principal.', 'receipt_id' => $receipt_id );
			}
			$kind = sanitize_key( (string) ( $receipt['kind'] ?? '' ) );
			$resolved_kinds[ $kind ] = true;
			$resolved[] = $receipt;
		}
		$missing_kinds = array_values( array_diff( $required_kinds, array_keys( $resolved_kinds ) ) );
		if ( $missing_kinds || 6 !== count( $receipt_ids ) || 6 !== count( $resolved_kinds ) ) {
			return array( 'success' => false, 'code' => 'quality_receipt_set_incomplete', 'message' => 'The mandatory server Quality receipt set is incomplete.', 'missing_kinds' => $missing_kinds );
		}

		$attestations = array();
		foreach ( (array) ( $input['reviewer_attestations'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$kind = sanitize_key( (string) ( $row['kind'] ?? '' ) );
			$observation = trim( sanitize_textarea_field( (string) ( $row['observation'] ?? '' ) ) );
			if ( ! in_array( $kind, array( 'natural_language', 'factual_accuracy' ), true ) || strlen( $observation ) < 40 || self::is_generic_review_evidence( $observation ) ) { continue; }
			$attestations[ $kind ] = array(
				'kind' => $kind,
				'passed' => ! empty( $row['passed'] ),
				'observation' => $observation,
				'fragment_keys' => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $row['fragment_keys'] ?? array() ) ) ) ),
				'principal_id' => (string) ( $reviewer_principal['principal_id'] ?? '' ),
				'artifact_revision' => (string) $artifact_record['artifact_revision'],
				'surface_revision' => (string) $artifact_record['surface_revision'],
				'trust' => 'reviewer_attested',
			);
		}
		if ( array_diff( array( 'natural_language', 'factual_accuracy' ), array_keys( $attestations ) ) ) {
			return array( 'success' => false, 'code' => 'reviewer_attestations_incomplete', 'message' => 'Quality requires concrete natural-language and factual-accuracy attestations.' );
		}
		$browser = self::translation_job_browser_receipt( $job, $artifact_record, $input['browser_receipts'] ?? array(), $reviewer_principal, $input['browser_adapter_receipt_ids'] ?? array() );
		if ( empty( $browser['success'] ) ) { return $browser; }
		$record = array(
			'job_id'           => (string) $job['job_id'],
			'artifact_revision' => (string) $artifact_record['artifact_revision'],
			'surface_revision'  => (string) $artifact_record['surface_revision'],
			'reviewer_principal'=> $reviewer_principal,
			'server_receipt_ids'=> $receipt_ids,
			'server_receipts'   => $resolved,
			'reviewer_attestations' => $attestations,
			'browser_attestations'  => $browser['receipts'],
			'issued_at'         => gmdate( 'c' ),
			'issuer'            => 'devenia-workflow-quality-authority',
		);
		$record['evidence_revision'] = 'qe_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $record ) ) ?: '' ), 0, 40 );
		if ( ! self::atomic_create_option( self::translation_job_quality_evidence_key( $record['evidence_revision'] ), $record ) ) {
			$stored = get_option( self::translation_job_quality_evidence_key( $record['evidence_revision'] ) );
			if ( ! is_array( $stored ) || self::translation_job_canonicalize( $stored ) !== self::translation_job_canonicalize( $record ) ) {
				return array( 'success' => false, 'code' => 'quality_evidence_store_failed', 'message' => 'The immutable Quality evidence receipt could not be stored.' );
			}
			$record = $stored;
		}
		return array( 'success' => true, 'record' => $record );
	}

	/**
	 * Issue receipts only for checks Workflow can actually observe itself.
	 * Semantic language judgment remains an explicitly separate reviewer
	 * attestation and is never mislabeled as server-computed truth.
	 *
	 * @return array<string,mixed>
	 */
	private static function translation_job_server_quality_receipts( array $job, array $artifact_record, array $reviewer_principal ): array {
		$source = get_post( absint( $job['source_id'] ?? 0 ) );
		$artifact = isset( $artifact_record['artifact'] ) && is_array( $artifact_record['artifact'] ) ? $artifact_record['artifact'] : array();
		if ( ! $source instanceof WP_Post ) {
			return array( 'success' => false, 'code' => 'server_quality_source_missing', 'message' => 'Server Quality receipts require the current source.' );
		}
		$language = sanitize_key( (string) $job['target_language'] );
		$source_quality = self::translation_job_source_approval( $source );
		$coverage = self::translation_job_fragment_coverage( $job, $artifact['localized_fragments'] ?? array() );
		$links = self::translation_job_artifact_link_policy( $source, $language, $artifact['localized_fragments'] ?? array() );
		$contact = self::translation_job_artifact_contact_policy( $source, $artifact['localized_fragments'] ?? array() );
		$staged = ! empty( $artifact_record['staged_validation']['passed'] );
		$seo = isset( $artifact_record['surface_manifest']['seo'] ) && is_array( $artifact_record['surface_manifest']['seo'] ) ? $artifact_record['surface_manifest']['seo'] : array();
		$translation_id = absint( $artifact_record['translation_id'] ?? 0 );
		$surface_post = $translation_id ? get_post( $translation_id ) : $source;
		$surface_language = $translation_id ? $language : self::source_language_code();
		$surface_scope = $translation_id ? 'existing_translation_presentation_shell' : 'source_presentation_shell';
		$frontend = $surface_post instanceof WP_Post && 'publish' === (string) $surface_post->post_status
			? self::translation_job_http_live_dom_evidence( (string) get_permalink( $surface_post ), $surface_language )
			: array( 'success' => false, 'passed' => false, 'status_code' => 0, 'scope' => 'no_http_surface' );
		$projected_content = (string) ( $artifact_record['surface_manifest']['content']['gutenberg'] ?? '' );
		$staged_dom = do_blocks( $projected_content );
		$staged_dom_passed = '' !== trim( wp_strip_all_tags( $staged_dom ) ) && false === stripos( $staged_dom, '<!-- wp:' );
		$staged_dom_digest = hash( 'sha256', $staged_dom );
		$states = array(
			'deterministic_structure' => ! empty( $source_quality['passed'] ) && $staged,
			'source_coverage' => ! empty( $coverage['success'] ) && $staged,
			'localized_route_links' => ! empty( $links['success'] ),
			'seo_taxonomy' => '' !== trim( (string) ( $seo['title'] ?? '' ) ) && '' !== trim( (string) ( $seo['description'] ?? '' ) ) && isset( $artifact_record['surface_manifest']['taxonomies'] ),
			'offer_contact' => ! empty( $contact['success'] ),
			'http_live_dom' => ! empty( $frontend['success'] ) && ! empty( $frontend['passed'] ) && $staged_dom_passed,
		);
		$failed = array_keys( array_filter( $states, static function ( $passed ): bool { return ! $passed; } ) );
		if ( $failed ) {
			return array( 'success' => false, 'code' => 'server_quality_receipts_failed', 'message' => 'Workflow server checks failed and cannot issue Quality receipts.', 'failed_receipts' => $failed, 'frontend' => $frontend );
		}
		$receipts = array();
		$receipt_ids = array();
		foreach ( $states as $name => $passed ) {
			$body = array(
				'passed' => (bool) $passed,
				'issuer' => 'workflow',
				'trust' => 'server_computed',
				'kind' => $name,
				'artifact_revision' => (string) $artifact_record['artifact_revision'],
				'surface_revision' => (string) $artifact_record['surface_revision'],
				'principal_id' => (string) ( $reviewer_principal['principal_id'] ?? '' ),
				'adapter_revision' => defined( 'DEVENIA_WORKFLOW_VERSION' ) ? DEVENIA_WORKFLOW_VERSION : 'development',
				'policy_revision' => 'quality-authority-v1',
				'evidence_digest' => hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( array( $name, $passed, $frontend, $staged_dom_digest, $artifact_record['staged_validation'] ?? array() ) ) ) ?: '' ),
			);
			$receipt_id = 'qer_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $body ) ) ?: '' ), 0, 40 );
			$body['receipt_id'] = $receipt_id;
			$body['issued_at'] = gmdate( 'c' );
			if ( ! self::atomic_create_option( self::translation_job_quality_receipt_key( $receipt_id ), $body ) ) {
				$stored = get_option( self::translation_job_quality_receipt_key( $receipt_id ) );
				if ( ! is_array( $stored ) ) { return array( 'success' => false, 'code' => 'server_quality_receipt_store_failed', 'receipt_id' => $receipt_id ); }
				$body = $stored;
			}
			$receipt_ids[] = $receipt_id;
			$receipts[] = $body;
		}
		return array( 'success' => true, 'receipt_ids' => $receipt_ids, 'receipts' => $receipts, 'http_live_dom_scope' => $surface_scope );
	}

	/**
	 * Observe the reachable WordPress/theme shell without importing unrelated
	 * localized-menu or copy-policy checks into the staged DOM receipt.
	 */
	private static function translation_job_http_live_dom_evidence( string $url, string $language ): array {
		$request_url = add_query_arg( 'devenia_quality_receipt', wp_generate_uuid4(), $url );
		$response = wp_safe_remote_get( $request_url, array( 'timeout' => 15, 'redirection' => 3, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'passed' => false, 'status_code' => 0, 'url' => $url, 'language' => $language, 'error' => $response->get_error_message() );
		}
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$has_dom = false !== stripos( $body, '<html' ) && false !== stripos( $body, '<body' ) && '' !== trim( wp_strip_all_tags( $body ) );
		return array(
			'success' => 200 === $status_code && $has_dom,
			'passed' => 200 === $status_code && $has_dom,
			'status_code' => $status_code,
			'url' => $url,
			'language' => sanitize_key( $language ),
			'response_digest' => hash( 'sha256', $body ),
			'dom_bytes' => strlen( $body ),
			'scope' => 'http_wordpress_theme_shell',
		);
	}

	/**
	 * Validate four reviewer-attested browser receipts bound to the staged surface.
	 *
	 * @param mixed $raw_receipts Browser receipt payload.
	 * @return array<string,mixed>
	 */
	private static function translation_job_browser_receipt( array $job, array $artifact_record, $raw_receipts, array $reviewer_principal, $browser_adapter_receipt_ids = array() ): array {
		$raw_receipts = is_array( $raw_receipts ) ? $raw_receipts : array();
		$required = array( 'desktop:light', 'desktop:dark', 'mobile:light', 'mobile:dark' );
		$seen = array();
		$receipts = array();
		foreach ( $raw_receipts as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$viewport = sanitize_key( (string) ( $row['viewport_scheme'] ?? '' ) );
			$scheme = sanitize_key( (string) ( $row['color_scheme'] ?? '' ) );
			$key = $viewport . ':' . $scheme;
			$url = esc_url_raw( (string) ( $row['url'] ?? '' ) );
			$screenshot = strtolower( sanitize_text_field( (string) ( $row['screenshot_digest'] ?? '' ) ) );
			$dom = strtolower( sanitize_text_field( (string) ( $row['response_digest'] ?? '' ) ) );
			$layout = strtolower( sanitize_text_field( (string) ( $row['layout_digest'] ?? '' ) ) );
			$dimensions = is_array( $row['viewport'] ?? null ) ? $row['viewport'] : array();
			$policy_dimensions = 'desktop' === $viewport ? array( 1440, 1100, 1 ) : array( 390, 844, 1 );
			if (
				! in_array( $key, $required, true )
				|| (string) $artifact_record['artifact_revision'] !== (string) ( $row['artifact_revision'] ?? '' )
				|| (string) $artifact_record['surface_revision'] !== (string) ( $row['surface_revision'] ?? '' )
				|| '' === $url
				|| ! preg_match( '/^[a-f0-9]{64}$/', $screenshot )
				|| ! preg_match( '/^[a-f0-9]{64}$/', $dom )
				|| ! preg_match( '/^[a-f0-9]{64}$/', $layout )
				|| array( absint( $dimensions['width'] ?? 0 ), absint( $dimensions['height'] ?? 0 ), absint( $dimensions['device_scale_factor'] ?? 0 ) ) !== $policy_dimensions
				|| '' === sanitize_text_field( (string) ( $row['document_language'] ?? '' ) )
				|| ! in_array( sanitize_key( (string) ( $row['document_direction'] ?? '' ) ), array( 'ltr', 'rtl' ), true )
			) {
				continue;
			}
			$seen[ $key ] = true;
			$receipts[] = array(
				'artifact_revision' => (string) $artifact_record['artifact_revision'],
				'surface_revision'  => (string) $artifact_record['surface_revision'],
				'principal_id'      => (string) ( $reviewer_principal['principal_id'] ?? '' ),
				'viewport_scheme'   => $viewport,
				'viewport'          => array( 'width' => $policy_dimensions[0], 'height' => $policy_dimensions[1], 'device_scale_factor' => $policy_dimensions[2] ),
				'color_scheme'      => $scheme,
				'url'               => $url,
				'response_digest'   => $dom,
				'document_language' => sanitize_text_field( (string) $row['document_language'] ),
				'document_direction'=> sanitize_key( (string) $row['document_direction'] ),
				'layout_digest'     => $layout,
				'screenshot_digest' => $screenshot,
				'checked_at'        => sanitize_text_field( (string) ( $row['checked_at'] ?? gmdate( 'c' ) ) ),
				'adapter'           => sanitize_key( (string) ( $row['adapter'] ?? 'fresh_quality_browser' ) ),
				'trust'             => 'reviewer_attested',
			);
		}
		$missing = array_values( array_diff( $required, array_keys( $seen ) ) );
		$adapter_ids = array_values( array_filter( array_map( 'sanitize_text_field', (array) $browser_adapter_receipt_ids ) ) );
		$adapter_ids = apply_filters( 'devenia_workflow_translation_job_browser_adapter_receipt_ids', $adapter_ids, $job, $artifact_record, $reviewer_principal );
		return $missing
			? array( 'success' => false, 'code' => 'browser_receipts_incomplete', 'missing' => $missing )
			: array( 'success' => true, 'receipts' => $receipts, 'receipt_count' => count( $receipts ), 'browser_adapter_receipt_ids' => $adapter_ids );
	}

	/**
	 * Apply one approved staged artifact to WordPress at publication time only.
	 *
	 * @return array<string,mixed>
	 */
	private static function translation_job_resolve_publication_translation_id( array $job, array $artifact_record = array() ): int {
		$source_id = absint( $job['source_id'] ?? 0 );
		$language = sanitize_key( (string) ( $job['target_language'] ?? '' ) );
		$source = get_post( $source_id );
		if ( ! $source instanceof WP_Post || ! self::is_translatable_post_type( (string) $source->post_type ) ) { return 0; }
		$is_owned_translation = static function ( int $translation_id ) use ( $source, $source_id, $language ): bool {
			$post = get_post( $translation_id );
			return $post instanceof WP_Post
				&& (string) $source->post_type === (string) $post->post_type
				&& $source_id === absint( get_post_meta( $translation_id, self::META_SOURCE_ID, true ) )
				&& $language === sanitize_key( (string) get_post_meta( $translation_id, self::META_LANGUAGE, true ) );
		};
		foreach ( array_values( array_unique( array_filter( array( absint( $artifact_record['translation_id'] ?? 0 ), absint( $job['translation_id'] ?? 0 ) ) ) ) ) as $translation_id ) {
			if ( $is_owned_translation( $translation_id ) ) { return $translation_id; }
		}
		$indexed_id = self::find_translation_id( $source_id, $language, self::translation_workflow_post_statuses( false ) );
		if ( $indexed_id && $is_owned_translation( $indexed_id ) ) { return $indexed_id; }
		$query = self::translation_page_query(
			array(
				'post_status' => self::translation_workflow_post_statuses( false ),
				'posts_per_page' => 1000,
				'fields' => 'ids',
			)
		);
		foreach ( (array) $query->posts as $translation_id ) {
			$translation_id = absint( $translation_id );
			if ( $is_owned_translation( $translation_id ) ) { return $translation_id; }
		}
		return 0;
	}

	/** Require the staged-write identity to be exactly the identity captured by the snapshot. */
	private static function translation_job_snapshot_translation_identity_matches( array $surface_snapshot, int $locked_translation_id ): bool {
		$captured_id = absint( $surface_snapshot['translation_id'] ?? 0 );
		$captured_existed = ! empty( $surface_snapshot['existed'] );
		return $captured_existed
			? $captured_id > 0 && $captured_id === $locked_translation_id
			: 0 === $captured_id && 0 === $locked_translation_id;
	}

	/** Build the canonical translation identity independently of mutable candidate IDs. */
	private static function translation_job_publication_identity_scope( array $job ): array {
		$source = get_post( absint( $job['source_id'] ?? 0 ) );
		if ( ! $source instanceof WP_Post ) { return array(); }
		return array(
			'source_id' => (int) $source->ID,
			'language' => sanitize_key( (string) ( $job['target_language'] ?? '' ) ),
			'post_type' => sanitize_key( (string) $source->post_type ),
		);
	}

	private static function translation_job_apply_staged_artifact( array $job, array $artifact_record, array $surface_snapshot = array() ): array {
		$translation_id = 0;
		$term_scope = (array) ( $surface_snapshot['term_scope'] ?? array() );
		$identity_scope = self::translation_job_publication_identity_scope( $job );
		if ( ! self::translation_job_begin_recovery_transaction() ) {
			return array_merge( array( 'success' => false, 'code' => 'publication_transaction_unavailable', 'message' => 'The staged publication transaction could not be started.', 'mutation_started' => false ), self::translation_job_recovery_transaction_error_fields() );
		}
		try {
			$translation_id = self::translation_job_resolve_publication_translation_id( $job, $artifact_record );
			$locked = self::translation_job_lock_recovery_surface( $translation_id, $term_scope, $identity_scope );
			if ( empty( $locked['success'] ) ) {
				return self::translation_job_failure_after_recovery_rollback( array_merge( $locked, array( 'mutation_started' => false ) ) );
			}
			$translation_id = absint( $locked['identity_translation_id'] ?? $translation_id );
			if ( ! self::translation_job_snapshot_translation_identity_matches( $surface_snapshot, $translation_id ) ) {
				$rollback = self::translation_job_rollback_recovery_transaction();
				self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
				return array_merge( array( 'success' => false, 'code' => 'staged_translation_identity_changed_before_locked_write', 'message' => 'The canonical translation identity changed after snapshot capture; publication must retry from a fresh locked snapshot.', 'translation_id' => $translation_id, 'mutation_started' => false ), self::translation_job_rollback_response_fields( $rollback ) );
			}
			$captured_revision = (string) ( $surface_snapshot['captured_cas_revision'] ?? '' );
			if ( '' === $captured_revision || ! hash_equals( $captured_revision, self::translation_job_rollback_cas_revision( $translation_id, $term_scope, $identity_scope ) ) ) {
				return self::translation_job_failure_after_recovery_rollback( array( 'success' => false, 'code' => 'staged_surface_drifted_before_locked_write', 'message' => 'The translation surface changed before the staged publication transaction acquired ownership.', 'translation_id' => $translation_id, 'mutation_started' => false ) );
			}
			$result = self::translation_job_apply_staged_artifact_uncommitted( $job, $artifact_record, $surface_snapshot, $translation_id );
			$resolved_id = absint( $result['translation_id'] ?? $translation_id );
			if ( empty( $result['success'] ) ) {
				$rollback = self::translation_job_rollback_recovery_transaction();
				self::translation_job_clean_recovery_caches( $resolved_id, $term_scope );
				$result['mutation_started'] = false;
				$result = array_merge( $result, self::translation_job_rollback_response_fields( $rollback ) );
				unset( $result['mutation_surface_revision'] );
				return $result;
			}
			$result['mutation_cas_revision'] = self::translation_job_rollback_cas_revision( $resolved_id, $term_scope, $identity_scope );
			if ( '' === (string) $result['mutation_cas_revision'] ) {
				$rollback = self::translation_job_rollback_recovery_transaction();
				self::translation_job_clean_recovery_caches( $resolved_id, $term_scope );
				return array_merge( array( 'success' => false, 'code' => 'staged_mutation_receipt_failed', 'message' => 'The exact staged mutation receipt could not be captured under lock.', 'translation_id' => $resolved_id, 'mutation_started' => false ), self::translation_job_rollback_response_fields( $rollback ) );
			}
			$commit = self::translation_job_commit_recovery_transaction();
			if ( empty( $commit['success'] ) ) {
				self::translation_job_clean_recovery_caches( $resolved_id, $term_scope );
				return array_merge( array( 'success' => false, 'code' => 'publication_transaction_commit_failed', 'message' => 'The staged publication transaction could not be committed safely.', 'translation_id' => $resolved_id, 'mutation_started' => false, 'transaction_commit' => $commit ), self::translation_job_recovery_transaction_error_fields() );
			}
			self::translation_job_clean_recovery_caches( $resolved_id, $term_scope );
			$result['transaction_commit'] = $commit;
			return $result;
		} catch ( Throwable $error ) {
			$rollback = self::translation_job_rollback_recovery_transaction();
			self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
			return array_merge( array( 'success' => false, 'code' => 'publication_transaction_exception', 'message' => 'The staged publication transaction stopped unexpectedly.', 'translation_id' => $translation_id, 'mutation_started' => false ), self::translation_job_rollback_response_fields( $rollback ), self::translation_job_recovery_transaction_error_fields() );
		}
	}

	/** Execute staged writes only inside the row-locked publication transaction. */
	private static function translation_job_apply_staged_artifact_uncommitted( array $job, array $artifact_record, array $surface_snapshot, int $translation_id ): array {
		$term_scope = (array) ( $surface_snapshot['term_scope'] ?? array() );
		$identity_scope = (array) ( $surface_snapshot['identity_scope'] ?? self::translation_job_publication_identity_scope( $job ) );
		$artifact = isset( $artifact_record['artifact'] ) && is_array( $artifact_record['artifact'] ) ? $artifact_record['artifact'] : array();
		$source = get_post( absint( $job['source_id'] ?? 0 ) );
		if ( ! $source instanceof WP_Post ) {
			return array( 'success' => false, 'code' => 'job_source_missing', 'message' => 'Translation Job source is unavailable.' );
		}
		$current_surface_revision = $translation_id ? self::translation_job_current_surface_revision( $translation_id ) : '';
		$already_applied_verification = $translation_id
			? self::translation_job_verify_applied_surface( $source, $translation_id, (array) $artifact_record['surface_manifest'] )
			: array( 'success' => false );
		$already_applied = $translation_id
			&& ! empty( $already_applied_verification['success'] )
			&& hash_equals( (string) ( $artifact_record['content_revision'] ?? '' ), self::translation_job_translation_revision( $translation_id ) );
		if ( $translation_id && ! $already_applied && (string) ( $artifact_record['baseline_surface_revision'] ?? '' ) !== $current_surface_revision ) {
			return array( 'success' => false, 'code' => 'staged_surface_drifted', 'message' => 'The public translation surface changed after the staged artifact was submitted.', 'mutation_started' => false );
		}
		if ( $already_applied ) {
			$thumbnail_id = self::featured_image_id_for_post( $translation_id );
			return array(
				'success' => true,
				'translation_id' => $translation_id,
				'translation' => self::translation_payload( get_post( $translation_id ) ),
				'featured_image_sync' => array( 'changed' => false, 'before_thumbnail_id' => $thumbnail_id, 'after_thumbnail_id' => $thumbnail_id, 'verified_thumbnail_id' => $thumbnail_id, 'write_verified' => true ),
				'surface_verification' => $already_applied_verification,
				'current_surface_revision' => $current_surface_revision,
				'already_applied' => true,
				'mutation_started' => false,
			);
		}
		if ( $translation_id ) {
			$locked_post = get_post( $translation_id );
			$locked_route_resolution = $locked_post instanceof WP_Post
				? self::effective_translation_canonical_route( $locked_post, (string) $job['target_language'] )
				: array( 'success' => false, 'code' => 'translation_missing', 'message' => 'The locked translation is unavailable.', 'mutation_started' => false );
			$staged_route = (array) ( $artifact_record['surface_manifest']['route']['canonical_route'] ?? array() );
			if ( empty( $locked_route_resolution['success'] ) ) {
				return $locked_route_resolution;
			}
			if ( self::translation_job_canonicalize( (array) $locked_route_resolution['route'] ) !== self::translation_job_canonicalize( $staged_route ) ) {
				return array( 'success' => false, 'code' => 'staged_canonical_route_drifted', 'message' => 'The observed Canonical Route Contract changed after staging.', 'mutation_started' => false );
			}
		}
		$status = $translation_id && 'publish' === get_post_status( $translation_id ) ? 'publish' : 'draft';
		$writer = isset( $artifact_record['writer_principal'] ) && is_array( $artifact_record['writer_principal'] ) ? $artifact_record['writer_principal'] : array();
		$upsert = array_merge(
			$artifact,
			array(
				'_canonical_seo_surface' => (array) ( $artifact_record['surface_manifest']['seo'] ?? array() ),
				'source_id' => (int) $job['source_id'],
				'language' => (string) $job['target_language'],
				'translation_id' => $translation_id,
				'inherit_source_design' => true,
				'strict_source_design_fragments' => true,
				'status' => $status,
				'translation_status' => 'needs_review',
				'allow_update_published' => true,
				'execution_id' => (string) ( $writer['principal_id'] ?? 'translation-job-writer' ),
				'writer_process_id' => (string) ( $writer['run_id'] ?? '' ),
				'writer_actor' => (string) ( $writer['principal_id'] ?? '' ),
				'publication_attempt_id' => sanitize_text_field( (string) ( $surface_snapshot['publication_attempt_id'] ?? '' ) ),
			)
		);
		self::$translation_job_internal_identity = array(
			'success' => true,
			'step' => 'draft_write',
			'workflow_step' => 'draft_write',
			'process_id' => (string) ( $writer['run_id'] ?? '' ),
			'control_scope_id' => (string) ( $writer['principal_id'] ?? '' ),
			'execution_id' => (string) ( $writer['principal_id'] ?? '' ),
			'session_origin' => 'spawned_subagent',
			'actor' => 'translation-job:' . (string) ( $writer['principal_id'] ?? '' ),
			'actor_id' => 'translation_job_writer',
			'authority' => 'server_issued_translation_job_claim',
			'job_id' => (string) $job['job_id'],
			'run_id' => (string) ( $writer['run_id'] ?? '' ),
		);
		try {
			$result = self::upsert_translation( $upsert );
		} finally {
			self::$translation_job_internal_identity = array();
		}
		if ( empty( $result['success'] ) ) {
			$result['mutation_started'] = true;
			$result['translation_id'] = $translation_id;
			$result['mutation_surface_revision'] = self::translation_job_rollback_cas_revision( $translation_id, $term_scope, $identity_scope );
			return $result;
		}
		$translation_id = absint( $result['translation']['id'] ?? 0 );
		$featured_image_sync = self::sync_source_featured_image( $translation_id, $source );
		if ( empty( $featured_image_sync['write_verified'] ) ) {
			return array( 'success' => false, 'code' => 'featured_image_sync_failed', 'message' => 'The approved source featured image could not be synchronized.', 'featured_image_sync' => $featured_image_sync, 'translation_id' => $translation_id, 'mutation_started' => true, 'mutation_surface_revision' => self::translation_job_rollback_cas_revision( $translation_id, $term_scope, $identity_scope ) );
		}
		if ( ! empty( $featured_image_sync['changed'] ) ) {
			$media_identity = self::translation_job_identity( $job, array( 'coordinator_id' => 'publication-module', 'run_id' => 'publish-media-reconcile' ), 'publish' );
			self::record_translation_visible_media_provenance( $translation_id, $media_identity, 'translation_job_publish_reconcile' );
			self::sync_translation_index_row( $translation_id );
		}
		$featured_alt = trim( wp_strip_all_tags( (string) ( $artifact['featured_image_alt'] ?? '' ) ) );
		if ( '' !== $featured_alt ) {
			update_post_meta( $translation_id, self::META_FEATURED_IMAGE_ALT, $featured_alt );
		} else {
			delete_post_meta( $translation_id, self::META_FEATURED_IMAGE_ALT );
		}
		$actual_content_revision = self::translation_job_translation_revision( $translation_id );
		if ( ! hash_equals( (string) $artifact_record['content_revision'], $actual_content_revision ) ) {
			return array( 'success' => false, 'code' => 'applied_content_revision_mismatch', 'message' => 'The applied WordPress content does not match the approved staged artifact.', 'translation_id' => $translation_id, 'mutation_started' => true, 'mutation_surface_revision' => self::translation_job_rollback_cas_revision( $translation_id, $term_scope, $identity_scope ) );
		}
		$surface_verification = self::translation_job_verify_applied_surface( $source, $translation_id, (array) $artifact_record['surface_manifest'] );
		if ( empty( $surface_verification['success'] ) ) {
			return array( 'success' => false, 'code' => 'applied_surface_revision_mismatch', 'message' => 'The applied WordPress surface does not match the complete approved staged surface.', 'translation_id' => $translation_id, 'surface_verification' => $surface_verification, 'mutation_started' => true, 'mutation_surface_revision' => self::translation_job_rollback_cas_revision( $translation_id, $term_scope, $identity_scope ) );
		}
		return array( 'success' => true, 'translation_id' => $translation_id, 'translation' => $result['translation'], 'featured_image_sync' => $featured_image_sync, 'surface_verification' => $surface_verification, 'current_surface_revision' => self::translation_job_current_surface_revision( $translation_id ), 'mutation_cas_revision' => self::translation_job_rollback_cas_revision( $translation_id, $term_scope, $identity_scope ), 'mutation_started' => true );
	}

	/** Verify every public surface family represented by the approved manifest. */
	private static function translation_job_verify_applied_surface( WP_Post $source, int $translation_id, array $manifest ): array {
		$post = get_post( $translation_id );
		if ( ! $post instanceof WP_Post ) { return array( 'success' => false, 'failed' => array( 'post_missing' ) ); }
		$failed = array();
		$content = (array) ( $manifest['content'] ?? array() );
		if ( (string) ( $content['title'] ?? '' ) !== (string) $post->post_title || (string) ( $content['excerpt'] ?? '' ) !== (string) $post->post_excerpt || (string) ( $content['gutenberg'] ?? '' ) !== (string) $post->post_content ) { $failed[] = 'content'; }
		$seo = (array) ( $manifest['seo'] ?? array() );
		if ( (string) ( $seo['title'] ?? '' ) !== (string) get_post_meta( $translation_id, 'rank_math_title', true ) || (string) ( $seo['description'] ?? '' ) !== (string) get_post_meta( $translation_id, 'rank_math_description', true ) || (string) ( $seo['focus_keyword'] ?? '' ) !== (string) get_post_meta( $translation_id, 'rank_math_focus_keyword', true ) ) { $failed[] = 'seo'; }
		$route = (array) ( $manifest['route'] ?? array() );
		$expected_slug = (string) ( $route['post_name'] ?? $route['localized_slug'] ?? '' );
		if ( '' !== $expected_slug && $expected_slug !== (string) $post->post_name ) { $failed[] = 'route_slug'; }
		if ( isset( $route['post_parent'] ) && (int) $route['post_parent'] !== (int) $post->post_parent ) { $failed[] = 'route_parent'; }
		if ( isset( $route['localized_parent_id'] ) && (int) $route['localized_parent_id'] !== (int) $post->post_parent ) { $failed[] = 'route_parent'; }
		$expected_path = trim( (string) ( $route['localized_path'] ?? '' ), '/' );
		if ( array_key_exists( 'localized_path', $route ) && $expected_path !== trim( (string) get_post_meta( $translation_id, self::META_LOCALIZED_PATH, true ), '/' ) ) { $failed[] = 'route_path'; }
		if ( array_key_exists( 'canonical_route', $route ) ) {
			$expected_canonical_route = self::translation_job_canonicalize( (array) $route['canonical_route'] );
			$stored_canonical_route = self::json_post_meta_value( $translation_id, self::META_CANONICAL_ROUTE );
			$effective_canonical_route = self::effective_translation_canonical_route( $post, (string) ( $manifest['language'] ?? '' ) );
			if (
				! metadata_exists( 'post', $translation_id, self::META_CANONICAL_ROUTE )
				|| self::translation_job_canonicalize( $stored_canonical_route ) !== $expected_canonical_route
				|| empty( $effective_canonical_route['success'] )
				|| self::translation_job_canonicalize( (array) ( $effective_canonical_route['route'] ?? array() ) ) !== $expected_canonical_route
			) {
				$failed[] = 'route_canonical';
			}
		}
		$media = (array) ( $manifest['media'] ?? array() );
		$expected_featured_image = (array) ( $media['featured_image'] ?? array() );
		$actual_featured_image = self::publication_featured_image_revision_identity( $translation_id );
		if ( self::translation_job_canonicalize( $expected_featured_image ) !== self::translation_job_canonicalize( $actual_featured_image ) ) { $failed[] = 'media_image'; }
		if ( array_key_exists( 'featured_image_alt', $media ) && (string) $media['featured_image_alt'] !== (string) get_post_meta( $translation_id, self::META_FEATURED_IMAGE_ALT, true ) ) { $failed[] = 'media_alt'; }
		$presentation = (array) ( $manifest['presentation'] ?? array() );
		$stored_presentation = self::stored_localized_source_design_fragments( $translation_id );
		$expected_presentation_fragments = self::translation_job_normalized_presentation_fragments( $presentation['localized_fragments'] ?? array() );
		$actual_presentation_fragments = self::translation_job_normalized_presentation_fragments( $stored_presentation['fragments'] ?? array() );
		if ( (string) ( $presentation['source_design_hash'] ?? '' ) !== (string) get_post_meta( $translation_id, self::META_SOURCE_DESIGN_HASH, true ) || self::translation_job_canonicalize( $expected_presentation_fragments ) !== self::translation_job_canonicalize( $actual_presentation_fragments ) ) { $failed[] = 'presentation'; }
		if ( 'post' === $source->post_type ) {
			$expected_taxonomies = absint( $manifest['schema_version'] ?? 1 ) >= 2
				? (array) ( $manifest['taxonomies'] ?? array() )
				: self::translation_job_expected_taxonomy_surface( $source, (string) ( $manifest['language'] ?? '' ), (array) ( $manifest['taxonomies'] ?? array() ) );
			$actual_taxonomies = self::translation_job_actual_taxonomy_surface( $post );
			if ( is_wp_error( $expected_taxonomies ) || is_wp_error( $actual_taxonomies ) ) {
				$failed[] = 'taxonomy_read';
			} elseif ( self::translation_job_canonicalize( $expected_taxonomies ) !== self::translation_job_canonicalize( $actual_taxonomies ) ) {
				$failed[] = 'taxonomies';
			}
		}
		$evidence = array( 'translation_id' => $translation_id, 'approved_surface_revision' => self::translation_job_surface_revision( $manifest ), 'failed' => $failed );
		$evidence['applied_surface_evidence_revision'] = 'asr_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $evidence ) ) ?: '' ), 0, 40 );
		return array_merge( $evidence, array( 'success' => empty( $failed ) ) );
	}

	/** Resolve the exact logical taxonomy surface represented by staged input. */
	private static function translation_job_expected_taxonomy_surface( WP_Post $source, string $language, array $taxonomy_input ) {
		$out = array();
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$terms = wp_get_post_terms( (int) $source->ID, $taxonomy, array( 'hide_empty' => false ) );
			if ( is_wp_error( $terms ) ) { return $terms; }
			$input_terms = self::taxonomy_input_by_source_term( $taxonomy_input[ $taxonomy ] ?? array() );
			$out[ $taxonomy ] = array();
			foreach ( is_array( $terms ) ? $terms : array() as $source_term ) {
				if ( ! $source_term instanceof WP_Term ) { continue; }
				$term_data = (array) ( $input_terms[ (int) $source_term->term_id ] ?? array() );
				$existing_id = self::translation_job_find_scoped_term_id( (int) $source_term->term_id, $language, $taxonomy );
				if ( is_wp_error( $existing_id ) ) { return $existing_id; }
				$existing = $existing_id ? get_term( $existing_id, $taxonomy ) : null;
				$out[ $taxonomy ][] = array(
					'source_term_id' => (int) $source_term->term_id,
					'taxonomy' => $taxonomy,
					'language' => sanitize_key( $language ),
					'name' => isset( $term_data['name'] ) && '' !== trim( (string) $term_data['name'] ) ? trim( (string) $term_data['name'] ) : ( $existing instanceof WP_Term ? (string) $existing->name : (string) $source_term->name ),
					'slug' => isset( $term_data['slug'] ) && '' !== trim( (string) $term_data['slug'] ) ? sanitize_title( (string) $term_data['slug'] ) : sanitize_title( $language . '-' . (string) $source_term->slug ),
					'description' => array_key_exists( 'description', $term_data ) ? (string) $term_data['description'] : ( $existing instanceof WP_Term ? (string) $existing->description : '' ),
					'parent_source_term_id' => (int) $source_term->parent,
				);
			}
			usort( $out[ $taxonomy ], static function ( array $a, array $b ): int { return (int) $a['source_term_id'] <=> (int) $b['source_term_id']; } );
		}
		return $out;
	}

	/** Read the exact logical taxonomy surface currently assigned to a translation. */
	private static function translation_job_actual_taxonomy_surface( WP_Post $post ) {
		$out = array();
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$terms = wp_get_post_terms( (int) $post->ID, $taxonomy, array( 'hide_empty' => false ) );
			if ( is_wp_error( $terms ) ) { return $terms; }
			$out[ $taxonomy ] = array();
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( ! $term instanceof WP_Term ) { continue; }
				$parent_source_id = $term->parent ? absint( get_term_meta( (int) $term->parent, self::TERM_META_SOURCE_ID, true ) ) : 0;
				$out[ $taxonomy ][] = array(
					'source_term_id' => absint( get_term_meta( (int) $term->term_id, self::TERM_META_SOURCE_ID, true ) ),
					'taxonomy' => $taxonomy,
					'language' => sanitize_key( (string) get_term_meta( (int) $term->term_id, self::TERM_META_LANGUAGE, true ) ),
					'name' => (string) $term->name,
					'slug' => (string) $term->slug,
					'description' => (string) $term->description,
					'parent_source_term_id' => $parent_source_id,
				);
			}
			usort( $out[ $taxonomy ], static function ( array $a, array $b ): int { return (int) $a['source_term_id'] <=> (int) $b['source_term_id']; } );
		}
		return $out;
	}

	/** Resolve exactly one translated taxonomy term without hiding storage errors. */
	private static function translation_job_find_scoped_term_id( int $source_term_id, string $language, string $taxonomy ) {
		$terms = get_terms(
			array(
				'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 2, 'fields' => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded rollback ownership lookup.
					'relation' => 'AND',
					array( 'key' => self::TERM_META_SOURCE_ID, 'value' => (string) $source_term_id ),
					array( 'key' => self::TERM_META_LANGUAGE, 'value' => sanitize_key( $language ) ),
				),
			)
		);
		if ( is_wp_error( $terms ) ) { return $terms; }
		if ( count( (array) $terms ) > 1 ) { return new WP_Error( 'duplicate_localized_term_identity', 'More than one translated term has the same source/language identity.' ); }
		return empty( $terms ) ? 0 : absint( $terms[0] );
	}

	/** Read every mutable field and relationship owned by a translated term. */
	private static function translation_job_taxonomy_term_state( int $term_id, string $taxonomy ) {
		$term = get_term( $term_id, $taxonomy );
		if ( is_wp_error( $term ) ) { return $term; }
		if ( ! $term instanceof WP_Term ) { return new WP_Error( 'localized_term_missing', 'The scoped translated term is missing.' ); }
		$objects = get_objects_in_term( $term_id, $taxonomy );
		if ( is_wp_error( $objects ) ) { return $objects; }
		$objects = array_values( array_map( 'absint', (array) $objects ) );
		sort( $objects );
		return array(
			'term_id' => (int) $term->term_id, 'term_taxonomy_id' => (int) $term->term_taxonomy_id, 'term_group' => (int) $term->term_group,
			'taxonomy' => (string) $term->taxonomy, 'name' => (string) $term->name, 'slug' => (string) $term->slug,
			'description' => (string) $term->description, 'parent' => (int) $term->parent, 'count' => (int) $term->count,
			'meta' => get_term_meta( (int) $term->term_id ), 'objects' => $objects,
		);
	}

	/** Capture the global term records which staged post publication may mutate. */
	private static function translation_job_capture_term_snapshot( array $manifest, string $publication_attempt_id ): array {
		$snapshot = array();
		foreach ( (array) ( $manifest['taxonomies'] ?? array() ) as $taxonomy => $items ) {
			$taxonomy = sanitize_key( (string) $taxonomy );
			foreach ( (array) $items as $item ) {
				$item = (array) $item;
				$source_term_id = absint( $item['source_term_id'] ?? 0 );
				$language = sanitize_key( (string) ( $item['language'] ?? $manifest['language'] ?? '' ) );
				if ( ! $source_term_id || '' === $taxonomy || '' === $language ) { continue; }
				$key = $taxonomy . ':' . $source_term_id . ':' . $language;
				$term_id = self::translation_job_find_scoped_term_id( $source_term_id, $language, $taxonomy );
				if ( is_wp_error( $term_id ) ) { return array( 'success' => false, 'error' => $term_id->get_error_message() ); }
				$entry = array( 'taxonomy' => $taxonomy, 'source_term_id' => $source_term_id, 'language' => $language, 'existed' => (bool) $term_id, 'term_id' => absint( $term_id ) );
				if ( $term_id ) {
					$state = self::translation_job_taxonomy_term_state( absint( $term_id ), $taxonomy );
					if ( is_wp_error( $state ) ) { return array( 'success' => false, 'error' => $state->get_error_message() ); }
					$entry['state'] = $state;
				} else {
					$entry['publication_attempt_id'] = sanitize_text_field( $publication_attempt_id );
				}
				$snapshot[ $key ] = $entry;
			}
		}
		ksort( $snapshot );
		return array( 'success' => true, 'terms' => $snapshot );
	}

	/** Read the current term states for one immutable rollback scope. */
	private static function translation_job_current_term_scope( array $term_scope ) {
		$current = array();
		foreach ( $term_scope as $key => $entry ) {
			$entry = (array) $entry;
			$taxonomy = sanitize_key( (string) ( $entry['taxonomy'] ?? '' ) );
			$term_id = self::translation_job_find_scoped_term_id( absint( $entry['source_term_id'] ?? 0 ), (string) ( $entry['language'] ?? '' ), $taxonomy );
			if ( is_wp_error( $term_id ) ) { return $term_id; }
			if ( ! $term_id ) { $current[ (string) $key ] = array( 'exists' => false ); continue; }
			$state = self::translation_job_taxonomy_term_state( absint( $term_id ), $taxonomy );
			if ( is_wp_error( $state ) ) { return $state; }
			$current[ (string) $key ] = array( 'exists' => true, 'state' => $state );
		}
		ksort( $current );
		return $current;
	}

	/** Fingerprint every mutable field rollback owns for compare-and-swap safety. */
	private static function translation_job_rollback_cas_revision( int $translation_id, array $term_scope = array(), array $identity_scope = array() ): string {
		$post = get_post( $translation_id );
		if ( ! $post instanceof WP_Post && empty( $term_scope ) && empty( $identity_scope ) ) { return ''; }
		$taxonomies = array();
		foreach ( $post instanceof WP_Post ? get_object_taxonomies( $post->post_type ) : array() as $taxonomy ) {
			$ids = wp_get_object_terms( $translation_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $ids ) ) { return ''; }
			$ids = array_values( array_map( 'absint', (array) $ids ) );
			sort( $ids );
			$taxonomies[ $taxonomy ] = $ids;
		}
		$term_states = self::translation_job_current_term_scope( $term_scope );
		if ( is_wp_error( $term_states ) ) { return ''; }
		$identity_ids = empty( $identity_scope ) ? array() : self::translation_job_find_translation_identity_ids( $identity_scope );
		if ( is_wp_error( $identity_ids ) ) { return ''; }
		$surface = array(
			'translation_identity' => array( 'scope' => $identity_scope, 'ids' => $identity_ids ),
			'post' => $post instanceof WP_Post ? array(
				'ID' => (int) $post->ID, 'post_author' => (int) $post->post_author, 'post_title' => (string) $post->post_title,
				'post_excerpt' => (string) $post->post_excerpt, 'post_content' => (string) $post->post_content, 'post_status' => (string) $post->post_status,
				'post_name' => (string) $post->post_name, 'post_parent' => (int) $post->post_parent, 'menu_order' => (int) $post->menu_order,
				'post_date' => (string) $post->post_date, 'post_date_gmt' => (string) $post->post_date_gmt,
				'post_modified' => (string) $post->post_modified, 'post_modified_gmt' => (string) $post->post_modified_gmt,
			) : null,
			'meta' => $post instanceof WP_Post ? get_post_meta( $translation_id ) : array(),
			'featured_image_identity' => $post instanceof WP_Post ? self::publication_featured_image_revision_identity( $translation_id ) : array(),
			'taxonomies' => $taxonomies,
			'term_scope' => $term_states,
		);
		return 'rcas_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $surface ) ) ?: '' ), 0, 40 );
	}

	/** Build the immutable translated-term lock scope without reading mutable state. */
	private static function translation_job_term_lock_scope_from_manifest( array $manifest, string $publication_attempt_id ): array {
		$scope = array();
		foreach ( (array) ( $manifest['taxonomies'] ?? array() ) as $taxonomy => $items ) {
			$taxonomy = sanitize_key( (string) $taxonomy );
			foreach ( (array) $items as $item ) {
				$item = (array) $item;
				$source_term_id = absint( $item['source_term_id'] ?? 0 );
				$language = sanitize_key( (string) ( $item['language'] ?? $manifest['language'] ?? '' ) );
				if ( ! $source_term_id || '' === $taxonomy || '' === $language ) { continue; }
				$scope[ $taxonomy . ':' . $source_term_id . ':' . $language ] = array(
					'taxonomy' => $taxonomy,
					'source_term_id' => $source_term_id,
					'language' => $language,
					'publication_attempt_id' => sanitize_text_field( $publication_attempt_id ),
				);
			}
		}
		ksort( $scope );
		return $scope;
	}

	/** Capture the complete mutable WordPress surface inside one locked snapshot. */
	private static function translation_job_capture_surface_snapshot( int $translation_id, array $manifest = array(), array $identity_scope = array() ): array {
		$publication_attempt_id = 'tpa_' . substr( hash( 'sha256', wp_generate_uuid4() . '|' . microtime( true ) ), 0, 32 );
		$term_scope = self::translation_job_term_lock_scope_from_manifest( $manifest, $publication_attempt_id );
		if ( ! self::translation_job_begin_recovery_transaction() ) {
			return array_merge( array( 'snapshot_valid' => false, 'existed' => false, 'translation_id' => $translation_id, 'message' => 'publication_snapshot_transaction_unavailable', 'mutation_started' => false ), self::translation_job_recovery_transaction_error_fields() );
		}
		try {
			$locked = self::translation_job_lock_recovery_surface( $translation_id, $term_scope, $identity_scope );
			if ( empty( $locked['success'] ) ) {
				return self::translation_job_failure_after_recovery_rollback( array( 'snapshot_valid' => false, 'existed' => false, 'translation_id' => $translation_id, 'message' => (string) ( $locked['code'] ?? 'publication_snapshot_lock_failed' ), 'mutation_started' => false ) );
			}
			$locked_identity_id = absint( $locked['identity_translation_id'] ?? $translation_id );
			if ( $locked_identity_id !== $translation_id ) {
				$rollback = self::translation_job_rollback_recovery_transaction();
				self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
				return array_merge( array( 'snapshot_valid' => false, 'existed' => false, 'translation_id' => $locked_identity_id, 'message' => 'publication_snapshot_identity_changed', 'mutation_started' => false ), self::translation_job_rollback_response_fields( $rollback ) );
			}
			$snapshot = self::translation_job_capture_surface_snapshot_uncommitted( $translation_id, $manifest, $publication_attempt_id, $identity_scope );
			if ( empty( $snapshot['snapshot_valid'] ) ) {
				$rollback = self::translation_job_rollback_recovery_transaction();
				self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
				return array_merge( $snapshot, self::translation_job_rollback_response_fields( $rollback ) );
			}
			$commit = self::translation_job_commit_recovery_transaction();
			if ( empty( $commit['success'] ) ) {
				self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
				return array_merge( array( 'snapshot_valid' => false, 'existed' => false, 'translation_id' => $translation_id, 'message' => 'publication_snapshot_commit_failed', 'mutation_started' => false, 'transaction_commit' => $commit ), self::translation_job_recovery_transaction_error_fields() );
			}
			self::translation_job_clean_recovery_caches( $translation_id, (array) ( $snapshot['term_scope'] ?? $term_scope ) );
			$snapshot['transaction_commit'] = $commit;
			return $snapshot;
		} catch ( Throwable $error ) {
			$rollback = self::translation_job_rollback_recovery_transaction();
			self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
			return array_merge( array( 'snapshot_valid' => false, 'existed' => false, 'translation_id' => $translation_id, 'message' => 'publication_snapshot_transaction_exception', 'mutation_started' => false ), self::translation_job_rollback_response_fields( $rollback ), self::translation_job_recovery_transaction_error_fields() );
		}
	}

	/** Read the snapshot only after its complete post, meta, relationship and term scope is locked. */
	private static function translation_job_capture_surface_snapshot_uncommitted( int $translation_id, array $manifest, string $publication_attempt_id, array $identity_scope ): array {
		$term_snapshot = self::translation_job_capture_term_snapshot( $manifest, $publication_attempt_id );
		if ( empty( $term_snapshot['success'] ) ) {
			return array( 'snapshot_valid' => false, 'existed' => false, 'translation_id' => $translation_id, 'message' => (string) ( $term_snapshot['error'] ?? 'taxonomy_term_snapshot_failed' ), 'mutation_started' => false );
		}
		$post = $translation_id ? get_post( $translation_id ) : null;
		if ( ! $post instanceof WP_Post ) {
			return array( 'snapshot_valid' => true, 'existed' => false, 'translation_id' => 0, 'mutation_started' => false, 'term_scope' => $term_snapshot['terms'], 'identity_scope' => $identity_scope, 'publication_attempt_id' => $publication_attempt_id, 'captured_cas_revision' => self::translation_job_rollback_cas_revision( 0, $term_snapshot['terms'], $identity_scope ) );
		}
		$taxonomies = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$term_ids = wp_get_object_terms( $translation_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $term_ids ) ) {
				return array( 'snapshot_valid' => false, 'existed' => true, 'translation_id' => $translation_id, 'message' => $term_ids->get_error_message(), 'mutation_started' => false );
			}
			$taxonomies[ $taxonomy ] = $term_ids;
		}
		return array(
			'snapshot_valid' => true,
			'existed' => true,
			'translation_id' => $translation_id,
			'mutation_started' => false,
			'publication_attempt_id' => $publication_attempt_id,
			'identity_scope' => $identity_scope,
			'captured_surface_revision' => self::translation_job_current_surface_revision( $translation_id ),
			'featured_image_identity' => self::publication_featured_image_revision_identity( $translation_id ),
			'rollback_public_url' => esc_url_raw( (string) get_permalink( $translation_id ) ),
			'language' => sanitize_key( (string) ( $manifest['language'] ?? '' ) ),
			'term_scope' => $term_snapshot['terms'],
			'captured_cas_revision' => self::translation_job_rollback_cas_revision( $translation_id, $term_snapshot['terms'], $identity_scope ),
			'post' => array(
				'ID' => $translation_id,
				'post_author' => (int) $post->post_author,
				'post_title' => (string) $post->post_title,
				'post_excerpt' => (string) $post->post_excerpt,
				'post_content' => (string) $post->post_content,
				'post_status' => (string) $post->post_status,
				'post_name' => (string) $post->post_name,
				'post_parent' => (int) $post->post_parent,
				'menu_order' => (int) $post->menu_order,
				'post_date' => (string) $post->post_date,
				'post_date_gmt' => (string) $post->post_date_gmt,
				'post_modified' => (string) $post->post_modified,
				'post_modified_gmt' => (string) $post->post_modified_gmt,
				'edit_date' => true,
			),
			'meta' => get_post_meta( $translation_id ),
			'taxonomies' => $taxonomies,
		);
	}

	/** Restore or remove every global translated term changed inside the publication attempt. */
	private static function translation_job_restore_term_snapshot( array $term_scope, int $translation_id ): array {
		$errors = array();
		foreach ( $term_scope as $key => $entry ) {
			$entry = (array) $entry;
			if ( empty( $entry['existed'] ) ) { continue; }
			$taxonomy = sanitize_key( (string) ( $entry['taxonomy'] ?? '' ) );
			$expected_id = absint( $entry['term_id'] ?? 0 );
			$current_id = self::translation_job_find_scoped_term_id( absint( $entry['source_term_id'] ?? 0 ), (string) ( $entry['language'] ?? '' ), $taxonomy );
			if ( is_wp_error( $current_id ) || $expected_id !== absint( $current_id ) ) { $errors[] = 'term_identity_' . $key; continue; }
			$state = (array) ( $entry['state'] ?? array() );
			$updated = wp_update_term( $expected_id, $taxonomy, array( 'name' => (string) ( $state['name'] ?? '' ), 'slug' => (string) ( $state['slug'] ?? '' ), 'description' => (string) ( $state['description'] ?? '' ), 'parent' => absint( $state['parent'] ?? 0 ) ) );
			if ( is_wp_error( $updated ) ) { $errors[] = 'term_restore_' . $key; continue; }
			$existing_meta = get_term_meta( $expected_id );
			foreach ( array_keys( $existing_meta ) as $meta_key ) {
				if ( ! delete_term_meta( $expected_id, (string) $meta_key ) && metadata_exists( 'term', $expected_id, (string) $meta_key ) ) { $errors[] = 'term_meta_delete_' . $key; }
			}
			foreach ( (array) ( $state['meta'] ?? array() ) as $meta_key => $values ) {
				foreach ( (array) $values as $value ) {
					if ( false === add_term_meta( $expected_id, (string) $meta_key, wp_slash( maybe_unserialize( $value ) ) ) ) { $errors[] = 'term_meta_add_' . $key; }
				}
			}
			$actual = self::translation_job_taxonomy_term_state( $expected_id, $taxonomy );
			if ( is_wp_error( $actual ) ) { $errors[] = 'term_verify_' . $key; continue; }
			foreach ( array( 'term_id', 'taxonomy', 'name', 'slug', 'description', 'parent', 'count' ) as $field ) {
				if ( (string) ( $state[ $field ] ?? '' ) !== (string) ( $actual[ $field ] ?? '' ) ) { $errors[] = 'term_field_' . $key . '_' . $field; }
			}
			if ( self::translation_job_canonicalize( (array) ( $state['meta'] ?? array() ) ) !== self::translation_job_canonicalize( (array) ( $actual['meta'] ?? array() ) ) ) { $errors[] = 'term_meta_verify_' . $key; }
			if ( self::translation_job_canonicalize( (array) ( $state['objects'] ?? array() ) ) !== self::translation_job_canonicalize( (array) ( $actual['objects'] ?? array() ) ) ) { $errors[] = 'term_objects_verify_' . $key; }
		}
		foreach ( $term_scope as $key => $entry ) {
			$entry = (array) $entry;
			if ( ! empty( $entry['existed'] ) ) { continue; }
			$taxonomy = sanitize_key( (string) ( $entry['taxonomy'] ?? '' ) );
			$current_id = self::translation_job_find_scoped_term_id( absint( $entry['source_term_id'] ?? 0 ), (string) ( $entry['language'] ?? '' ), $taxonomy );
			if ( is_wp_error( $current_id ) ) { $errors[] = 'new_term_identity_' . $key; continue; }
			if ( ! $current_id ) { continue; }
			$attempt_id = sanitize_text_field( (string) ( $entry['publication_attempt_id'] ?? '' ) );
			$current_attempt_id = sanitize_text_field( (string) get_term_meta( absint( $current_id ), self::TERM_META_PUBLICATION_ATTEMPT, true ) );
			if ( '' === $attempt_id || ! hash_equals( $attempt_id, $current_attempt_id ) ) { $errors[] = 'new_term_publication_attempt_' . $key; continue; }
			$objects = get_objects_in_term( absint( $current_id ), $taxonomy );
			if ( is_wp_error( $objects ) ) { $errors[] = 'new_term_objects_' . $key; continue; }
			$outside = array_values( array_diff( array_map( 'absint', (array) $objects ), array_filter( array( $translation_id ) ) ) );
			if ( $outside ) { $errors[] = 'new_term_shared_' . $key; continue; }
			$deleted = wp_delete_term( absint( $current_id ), $taxonomy );
			if ( is_wp_error( $deleted ) || false === $deleted || 0 === $deleted ) { $errors[] = 'new_term_delete_' . $key; }
		}
		return empty( $errors ) ? array( 'success' => true ) : array( 'success' => false, 'errors' => array_values( array_unique( $errors ) ) );
	}

	/** Restore the pre-publication surface, or remove a newly-created candidate. */
	private static function translation_job_restore_surface_snapshot( array $snapshot, int $translation_id ): array {
		$term_scope = (array) ( $snapshot['term_scope'] ?? array() );
		$identity_scope = (array) ( $snapshot['identity_scope'] ?? array() );
		if ( ! self::translation_job_begin_recovery_transaction() ) {
			return array_merge( array( 'success' => false, 'action' => 'rollback_conflict', 'error' => 'recovery_transaction_unavailable', 'translation_id' => $translation_id ), self::translation_job_recovery_transaction_error_fields() );
		}
		try {
			$locked = self::translation_job_lock_recovery_surface( $translation_id, $term_scope, $identity_scope );
			if ( empty( $locked['success'] ) ) {
				return self::translation_job_failure_after_recovery_rollback( array( 'success' => false, 'action' => 'rollback_conflict', 'error' => (string) ( $locked['code'] ?? 'recovery_surface_lock_failed' ), 'translation_id' => $translation_id ) );
			}
			$result = self::translation_job_restore_surface_snapshot_uncommitted( $snapshot, $translation_id );
			if ( empty( $result['success'] ) ) {
				$rollback = self::translation_job_rollback_recovery_transaction();
				self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
				return array_merge( $result, self::translation_job_rollback_response_fields( $rollback ) );
			}
			$commit = self::translation_job_commit_recovery_transaction();
			if ( empty( $commit['success'] ) ) {
				self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
				return array_merge( array( 'success' => false, 'action' => 'rollback_conflict', 'error' => 'recovery_transaction_commit_failed', 'translation_id' => $translation_id, 'transaction_commit' => $commit ), self::translation_job_recovery_transaction_error_fields() );
			}
			self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
			$result['transaction_committed'] = true;
			$result['transaction_commit'] = $commit;
			return $result;
		} catch ( Throwable $error ) {
			$rollback = self::translation_job_rollback_recovery_transaction();
			self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
			return array_merge( array( 'success' => false, 'action' => 'rollback_conflict', 'error' => 'recovery_transaction_exception', 'message' => 'The recovery transaction stopped unexpectedly.', 'translation_id' => $translation_id ), self::translation_job_rollback_response_fields( $rollback ), self::translation_job_recovery_transaction_error_fields() );
		}
	}

	/** Perform recovery writes only after every owned row/range is locked. */
	private static function translation_job_restore_surface_snapshot_uncommitted( array $snapshot, int $translation_id ): array {
		$term_scope = (array) ( $snapshot['term_scope'] ?? array() );
		$identity_scope = (array) ( $snapshot['identity_scope'] ?? array() );
		$expected_current_revision = (string) ( $snapshot['rollback_expected_surface_revision'] ?? '' );
		if ( '' === $expected_current_revision ) {
			return array( 'success' => false, 'action' => 'rollback_conflict', 'error' => 'missing_expected_mutation_revision', 'translation_id' => $translation_id );
		}
		if ( ! hash_equals( $expected_current_revision, self::translation_job_rollback_cas_revision( $translation_id, $term_scope, $identity_scope ) ) ) {
			return array( 'success' => false, 'action' => 'rollback_conflict', 'error' => 'surface_changed_after_publication_failure', 'translation_id' => $translation_id );
		}
		if ( empty( $snapshot['existed'] ) ) {
			if ( $translation_id && get_post( $translation_id ) ) {
				$attempt_id = sanitize_text_field( (string) ( $snapshot['publication_attempt_id'] ?? '' ) );
				if ( '' === $attempt_id || ! hash_equals( $attempt_id, (string) get_post_meta( $translation_id, '_devenia_workflow_publication_attempt_id', true ) ) ) {
					return array( 'success' => false, 'action' => 'rollback_conflict', 'error' => 'new_candidate_publication_attempt_mismatch', 'translation_id' => $translation_id );
				}
				$result = wp_delete_post( $translation_id, true );
				if ( ! $result instanceof WP_Post ) { return array( 'success' => false, 'action' => 'delete_new_candidate', 'translation_id' => $translation_id, 'error' => 'candidate_delete_failed' ); }
			}
			$term_restore = self::translation_job_restore_term_snapshot( $term_scope, $translation_id );
			return empty( $term_restore['success'] )
				? array( 'success' => false, 'action' => 'delete_new_candidate', 'translation_id' => $translation_id, 'error' => 'term_restore_incomplete', 'term_restore' => $term_restore )
				: array( 'success' => true, 'action' => $translation_id ? 'delete_new_candidate' : 'no_candidate_created', 'translation_id' => $translation_id );
		}
		$original_id = absint( $snapshot['translation_id'] ?? 0 );
		$post_snapshot = (array) ( $snapshot['post'] ?? array() );
		$modified = (string) ( $post_snapshot['post_modified'] ?? '' );
		$modified_gmt = (string) ( $post_snapshot['post_modified_gmt'] ?? '' );
		$preserve_modified = static function ( array $data, array $postarr ) use ( $original_id, $modified, $modified_gmt ): array {
			if ( $original_id === absint( $postarr['ID'] ?? 0 ) ) {
				$data['post_modified'] = $modified;
				$data['post_modified_gmt'] = $modified_gmt;
			}
			return $data;
		};
		add_filter( 'wp_insert_post_data', $preserve_modified, 10, 2 );
		$updated = null;
		try {
			self::with_direct_save_storage_guardrails_suspended(
				static function () use ( &$updated, $post_snapshot ): void {
					self::with_reviewer_style_capture_suspended(
						static function () use ( &$updated, $post_snapshot ): void {
							$updated = wp_update_post( wp_slash( $post_snapshot ), true );
						}
					);
				}
			);
		} finally {
			remove_filter( 'wp_insert_post_data', $preserve_modified, 10 );
		}
		if ( is_wp_error( $updated ) || $original_id !== absint( $updated ) ) {
			return array( 'success' => false, 'action' => 'restore_existing', 'error' => is_wp_error( $updated ) ? $updated->get_error_message() : 'post_restore_failed' );
		}
		$errors = array();
		$restored_post = get_post( $original_id );
		foreach ( array( 'post_author', 'post_title', 'post_excerpt', 'post_content', 'post_status', 'post_name', 'post_parent', 'menu_order', 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt' ) as $field ) {
			if ( ! $restored_post instanceof WP_Post || (string) ( $post_snapshot[ $field ] ?? '' ) !== (string) $restored_post->{$field} ) {
				$errors[] = 'post_field_' . $field;
			}
		}
		$existing_meta = get_post_meta( $original_id );
		foreach ( array_keys( $existing_meta ) as $key ) {
			if ( ! delete_post_meta( $original_id, $key ) && metadata_exists( 'post', $original_id, $key ) ) { $errors[] = 'meta_delete_' . $key; }
		}
		foreach ( (array) ( $snapshot['meta'] ?? array() ) as $key => $values ) {
			foreach ( (array) $values as $value ) {
				if ( false === add_post_meta( $original_id, (string) $key, wp_slash( maybe_unserialize( $value ) ) ) ) { $errors[] = 'meta_add_' . $key; }
			}
		}
		$expected_meta = self::translation_job_canonicalize( (array) ( $snapshot['meta'] ?? array() ) );
		$restored_meta = self::translation_job_canonicalize( get_post_meta( $original_id ) );
		$meta_verification = array();
		if ( $expected_meta !== $restored_meta ) {
			$errors[] = 'meta_verification';
			foreach ( array_values( array_unique( array_merge( array_keys( $expected_meta ), array_keys( $restored_meta ) ) ) ) as $meta_key ) {
				if ( ( $expected_meta[ $meta_key ] ?? null ) !== ( $restored_meta[ $meta_key ] ?? null ) ) {
					$meta_verification[] = (string) $meta_key;
				}
			}
		}
		if ( self::translation_job_canonicalize( (array) ( $snapshot['featured_image_identity'] ?? array() ) ) !== self::translation_job_canonicalize( self::publication_featured_image_revision_identity( $original_id ) ) ) { $errors[] = 'featured_image_byte_identity_verification'; }
		foreach ( (array) ( $snapshot['taxonomies'] ?? array() ) as $taxonomy => $term_ids ) {
			$result = wp_set_object_terms( $original_id, array_map( 'absint', (array) $term_ids ), (string) $taxonomy, false );
			if ( is_wp_error( $result ) ) {
				$errors[] = 'taxonomy_restore_' . $taxonomy;
				continue;
			}
			$expected_ids = array_values( array_map( 'absint', (array) $term_ids ) );
			$actual_ids = wp_get_object_terms( $original_id, (string) $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $actual_ids ) ) { $errors[] = 'taxonomy_verify_' . $taxonomy; continue; }
			$actual_ids = array_values( array_map( 'absint', (array) $actual_ids ) );
			sort( $expected_ids ); sort( $actual_ids );
			if ( $expected_ids !== $actual_ids ) { $errors[] = 'taxonomy_verify_' . $taxonomy; }
		}
		$term_restore = self::translation_job_restore_term_snapshot( $term_scope, $original_id );
		if ( empty( $term_restore['success'] ) ) { $errors[] = 'term_restore'; }
		clean_post_cache( $original_id );
		return empty( $errors )
			? array( 'success' => true, 'action' => 'restore_existing', 'translation_id' => $original_id )
			: array( 'success' => false, 'action' => 'restore_existing', 'translation_id' => $original_id, 'error' => 'surface_restore_incomplete', 'restore_errors' => array_values( array_unique( $errors ) ), 'meta_verification_keys' => $meta_verification, 'term_restore' => $term_restore );
	}

	/** Restore content/terms and menu projection in one all-or-nothing transaction. */
	private static function translation_job_restore_publication_snapshot( array $snapshot, int $translation_id, string $language, array $menu ): array {
		$term_scope = (array) ( $snapshot['term_scope'] ?? array() );
		$identity_scope = (array) ( $snapshot['identity_scope'] ?? array() );
		$target_menu_id = absint( $menu['target_menu']['id'] ?? 0 );
		if ( ! self::translation_job_begin_recovery_transaction() ) { return array_merge( array( 'success' => false, 'action' => 'rollback_conflict', 'error' => 'publication_recovery_transaction_unavailable' ), self::translation_job_recovery_transaction_error_fields() ); }
		try {
			$content_lock = self::translation_job_lock_recovery_surface( $translation_id, $term_scope, $identity_scope );
			$menu_lock = $target_menu_id ? self::lock_localized_menu_projection_surface( $target_menu_id ) : array( 'success' => true );
			$previous_menu_id = absint( $menu['previous_menu_id'] ?? 0 );
			$previous_menu_lock = $previous_menu_id ? self::lock_localized_menu_projection_surface( $previous_menu_id ) : array( 'success' => true );
			if ( empty( $content_lock['success'] ) || empty( $menu_lock['success'] ) || empty( $previous_menu_lock['success'] ) ) {
				return self::translation_job_failure_after_recovery_rollback( array( 'success' => false, 'action' => 'rollback_conflict', 'error' => 'publication_recovery_surface_lock_failed', 'content_lock' => $content_lock, 'menu_lock' => $menu_lock, 'previous_menu_lock' => $previous_menu_lock ) );
			}
			$expected_revision = (string) ( $snapshot['rollback_expected_surface_revision'] ?? '' );
			$current_revision = self::translation_job_rollback_cas_revision( $translation_id, $term_scope, $identity_scope );
			$menu_preflight = self::localized_menu_projection_rollback_preflight( $language, $menu );
			if ( '' === $expected_revision || '' === $current_revision || ! hash_equals( $expected_revision, $current_revision ) || empty( $menu_preflight['success'] ) ) {
				return self::translation_job_failure_after_recovery_rollback( array( 'success' => false, 'action' => 'rollback_conflict', 'error' => 'publication_recovery_preflight_failed', 'menu_rollback' => $menu_preflight ) );
			}
			$content_rollback = self::translation_job_restore_surface_snapshot_uncommitted( $snapshot, $translation_id );
			$menu_rollback = ! empty( $content_rollback['success'] ) ? self::rollback_localized_menu_projection_uncommitted( $language, $menu ) : array( 'success' => false, 'action' => 'not_attempted_after_content_failure' );
			if ( empty( $content_rollback['success'] ) || empty( $menu_rollback['success'] ) ) {
				$rollback = self::translation_job_rollback_recovery_transaction();
				self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
				if ( $target_menu_id ) { self::translation_job_clean_term_caches( array( $target_menu_id ) ); }
				return array_merge( array( 'success' => false, 'action' => 'publication_restore_rolled_back', 'error' => 'publication_restore_incomplete', 'content_rollback' => $content_rollback, 'menu_rollback' => $menu_rollback ), self::translation_job_rollback_response_fields( $rollback ) );
			}
			$commit = self::translation_job_commit_recovery_transaction();
			if ( empty( $commit['success'] ) ) {
				self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
				if ( $target_menu_id ) { self::translation_job_clean_term_caches( array( $target_menu_id ) ); }
				return array_merge( array( 'success' => false, 'action' => 'rollback_conflict', 'error' => 'publication_recovery_transaction_commit_failed', 'transaction_commit' => $commit ), self::translation_job_recovery_transaction_error_fields() );
			}
			self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
			if ( $target_menu_id ) { self::translation_job_clean_term_caches( array( $target_menu_id ) ); }
			return array( 'success' => true, 'action' => 'restore_publication', 'transaction_committed' => true, 'transaction_commit' => $commit, 'content_rollback' => $content_rollback, 'menu_rollback' => $menu_rollback );
		} catch ( Throwable $error ) {
			$rollback = self::translation_job_rollback_recovery_transaction();
			self::translation_job_clean_recovery_caches( $translation_id, $term_scope );
			if ( $target_menu_id ) { self::translation_job_clean_term_caches( array( $target_menu_id ) ); }
			return array_merge( array( 'success' => false, 'action' => 'rollback_conflict', 'error' => 'publication_recovery_transaction_exception', 'message' => 'The publication recovery transaction stopped unexpectedly.' ), self::translation_job_rollback_response_fields( $rollback ), self::translation_job_recovery_transaction_error_fields() );
		}
	}

	/** Attach rollback evidence to any failure after the first staged mutation. */
	private static function translation_job_publish_failure_with_rollback( array $failure, $snapshot, int $translation_id ): array {
		if ( ! is_array( $snapshot ) ) { return $failure; }
		if ( empty( $snapshot['mutation_started'] ) ) {
			$failure['rollback'] = array( 'success' => true, 'action' => 'not_required_before_mutation', 'translation_id' => $translation_id );
			return $failure;
		}
		if ( empty( $snapshot['rollback_expected_surface_revision'] ) ) {
			$snapshot['rollback_expected_surface_revision'] = (string) ( $failure['mutation_surface_revision'] ?? '' );
		}
		if ( empty( $snapshot['rollback_expected_surface_revision'] ) ) {
			$failure['rollback'] = array( 'success' => false, 'action' => 'rollback_conflict', 'error' => 'missing_expected_mutation_revision', 'translation_id' => $translation_id );
			$failure['code'] = 'publication_rollback_failed';
			$failure['message'] = 'Publication failed and rollback could not prove exclusive ownership of the current surface.';
			return $failure;
		}
		$menu_recovery_plan = isset( $failure['menu_recovery_plan'] ) && is_array( $failure['menu_recovery_plan'] ) ? $failure['menu_recovery_plan'] : null;
		$failure['rollback'] = is_array( $menu_recovery_plan )
			? self::translation_job_restore_publication_snapshot( $snapshot, $translation_id, sanitize_key( (string) ( $menu_recovery_plan['language'] ?? '' ) ), $menu_recovery_plan )
			: self::translation_job_restore_surface_snapshot( $snapshot, $translation_id );
		if ( ! empty( $failure['rollback']['success'] ) ) {
			$rollback_urls = array_values( array_unique( array_filter( array_merge( (array) ( $failure['purge_urls'] ?? array() ), array( (string) ( $snapshot['rollback_public_url'] ?? '' ) ) ) ) ) );
			$rollback_invalidation = apply_filters(
				'devenia_workflow_frontend_cache_invalidation_result',
				null,
				array_values( array_filter( array_map( 'esc_url_raw', $rollback_urls ) ) ),
				array( 'event' => 'localized_presentation_rollback', 'translation_id' => $translation_id, 'reason' => sanitize_key( (string) ( $failure['code'] ?? 'publication_failed' ) ) )
			);
			$failure['rollback']['cache_invalidation'] = $rollback_invalidation;
			if ( ! is_array( $rollback_invalidation ) || true !== ( $rollback_invalidation['success'] ?? null ) ) {
				$failure['rollback']['success'] = false;
				$failure['rollback']['error'] = 'rollback_cache_invalidation_failed';
			}
			if ( ! empty( $failure['rollback']['success'] ) && ! empty( $snapshot['existed'] ) ) {
				$rollback_verification = self::verify_frontend_featured_image_for_url( (string) ( $snapshot['rollback_public_url'] ?? '' ), (array) ( $snapshot['featured_image_identity'] ?? array() ), 15 );
				$failure['rollback']['media_verification'] = $rollback_verification;
				if ( empty( $rollback_verification['success'] ) ) {
					$failure['rollback']['success'] = false;
					$failure['rollback']['error'] = 'rollback_featured_image_verification_failed';
				}
			} elseif ( ! empty( $failure['rollback']['success'] ) ) {
				$failure['rollback']['media_verification'] = array( 'success' => true, 'policy' => 'new_candidate_removed_no_prior_media_surface' );
			}
		}
		if ( empty( $failure['rollback']['success'] ) ) {
			$failure['code'] = 'publication_rollback_failed';
			$failure['message'] = 'Publication failed and the original WordPress surface could not be restored automatically.';
		}
		return $failure;
	}

	/** Revalidate the immutable Quality Authority record immediately before mutation. */
	private static function translation_job_validate_quality_evidence_record( array $quality, array $artifact_record ): array {
		$record = get_option( self::translation_job_quality_evidence_key( (string) ( $quality['evidence_revision'] ?? '' ) ) );
		if ( ! is_array( $record ) ) { return array( 'success' => false, 'code' => 'quality_evidence_missing' ); }
		$hash_input = $record;
		unset( $hash_input['evidence_revision'] );
		$expected_revision = 'qe_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( $hash_input ) ) ?: '' ), 0, 40 );
		if ( ! hash_equals( $expected_revision, (string) ( $record['evidence_revision'] ?? '' ) ) ) { return array( 'success' => false, 'code' => 'quality_evidence_tampered' ); }
		if (
			(string) ( $record['job_id'] ?? '' ) !== (string) ( $artifact_record['job_id'] ?? '' )
			|| (string) ( $record['job_id'] ?? '' ) !== (string) ( $quality['job_id'] ?? '' )
			|| (string) ( $record['artifact_revision'] ?? '' ) !== (string) ( $artifact_record['artifact_revision'] ?? '' )
			|| (string) ( $quality['artifact_revision'] ?? '' ) !== (string) ( $artifact_record['artifact_revision'] ?? '' )
			|| (string) ( $record['surface_revision'] ?? '' ) !== (string) ( $artifact_record['surface_revision'] ?? '' )
			|| (string) ( $quality['surface_revision'] ?? '' ) !== (string) ( $artifact_record['surface_revision'] ?? '' )
			|| self::translation_job_canonicalize( (array) ( $record['reviewer_principal'] ?? array() ) ) !== self::translation_job_canonicalize( (array) ( $quality['reviewer_principal'] ?? array() ) )
		) { return array( 'success' => false, 'code' => 'quality_evidence_binding_mismatch' ); }
		$required = array( 'deterministic_structure', 'source_coverage', 'localized_route_links', 'seo_taxonomy', 'offer_contact', 'http_live_dom' );
		$kinds = array();
		foreach ( (array) ( $record['server_receipts'] ?? array() ) as $receipt ) {
			if ( ! is_array( $receipt ) || empty( $receipt['passed'] ) || 'workflow' !== (string) ( $receipt['issuer'] ?? '' ) || (string) ( $receipt['principal_id'] ?? '' ) !== (string) ( $record['reviewer_principal']['principal_id'] ?? '' ) ) { return array( 'success' => false, 'code' => 'quality_server_receipt_invalid' ); }
			$stored = get_option( self::translation_job_quality_receipt_key( (string) ( $receipt['receipt_id'] ?? '' ) ) );
			if ( ! is_array( $stored ) || self::translation_job_canonicalize( $stored ) !== self::translation_job_canonicalize( $receipt ) ) { return array( 'success' => false, 'code' => 'quality_server_receipt_missing' ); }
			$kinds[] = sanitize_key( (string) ( $receipt['kind'] ?? '' ) );
		}
		if ( array_diff( $required, array_unique( $kinds ) ) ) { return array( 'success' => false, 'code' => 'quality_server_receipt_set_incomplete' ); }
		if ( count( (array) ( $record['browser_attestations'] ?? array() ) ) < 4 || count( (array) ( $record['reviewer_attestations'] ?? array() ) ) < 2 ) { return array( 'success' => false, 'code' => 'quality_reviewer_evidence_incomplete' ); }
		if ( empty( $quality['usage']['usage_receipt_id'] ) || 'server_payload_estimate' !== (string) ( $quality['usage']['measurement_source'] ?? '' ) ) { return array( 'success' => false, 'code' => 'quality_usage_state_missing' ); }
		return array( 'success' => true, 'record' => $record );
	}

	/** Validate one complete immutable Job, Artifact, Quality and Evidence authority chain. */
	private static function translation_job_validate_published_authority( array $job, int $translation_id, bool $require_applied_surface = false ): array {
		$artifact_revision = (string) ( $job['artifact_revision'] ?? '' );
		$quality_revision = (string) ( $job['quality_revision'] ?? '' );
		$artifact_record = self::translation_job_unpack_artifact_record( get_option( self::translation_job_artifact_key( $artifact_revision ) ) );
		if ( ! is_array( $artifact_record ) || empty( $artifact_record['staged'] ) ) { return array( 'success' => false, 'code' => 'artifact_record_missing' ); }
		$quality = get_option( self::translation_job_quality_key( $quality_revision ) );
		if ( ! is_array( $quality ) ) { return array( 'success' => false, 'code' => 'quality_record_missing' ); }
		$generation = self::translation_job_submission_generation( $job );
		$manifest = (array) ( $artifact_record['surface_manifest'] ?? array() );
		$source = get_post( absint( $job['source_id'] ?? 0 ) );
		$artifact_reconstructed = self::translation_job_revision(
			array(
				'job_id' => (string) ( $job['job_id'] ?? '' ),
				'source_revision' => (string) ( $job['source_revision'] ?? '' ),
				'target_language' => (string) ( $job['target_language'] ?? '' ),
				'submission_generation' => $generation,
				'baseline_surface_revision' => (string) ( $artifact_record['baseline_surface_revision'] ?? '' ),
				'writer_principal_id' => (string) ( $artifact_record['writer_principal']['principal_id'] ?? '' ),
				'artifact' => (array) ( $artifact_record['artifact'] ?? array() ),
			)
		);
		$evidence = self::translation_job_validate_quality_evidence_record( $quality, $artifact_record );
		$quality_reconstructed = self::translation_job_revision( array( $artifact_revision, (string) ( $artifact_record['surface_revision'] ?? '' ), (string) ( $quality['decision'] ?? '' ), (string) ( $quality['evidence_revision'] ?? '' ), (string) ( $quality['reviewer_observations'] ?? '' ), (array) ( $quality['corrections'] ?? array() ) ) );
		$checks = array(
			'job_source_missing' => $source instanceof WP_Post,
			'job_identity_mismatch' => (string) ( $job['job_id'] ?? '' ) === self::translation_job_id( absint( $job['source_id'] ?? 0 ), (string) ( $job['target_language'] ?? '' ), (string) ( $job['source_revision'] ?? '' ) ),
			'job_source_revision_stale' => $source instanceof WP_Post && (string) ( $job['source_revision'] ?? '' ) === self::source_publication_surface_revision( $source ),
			'artifact_revision_mismatch' => $artifact_revision === (string) ( $artifact_record['artifact_revision'] ?? '' ) && $artifact_revision === $artifact_reconstructed,
			'artifact_job_binding_mismatch' => (string) ( $job['job_id'] ?? '' ) === (string) ( $artifact_record['job_id'] ?? '' ) && (string) ( $job['source_revision'] ?? '' ) === (string) ( $artifact_record['source_revision'] ?? '' ),
			'artifact_manifest_binding_mismatch' => (string) ( $job['job_id'] ?? '' ) === (string) ( $manifest['job_id'] ?? '' ) && (string) ( $job['source_revision'] ?? '' ) === (string) ( $manifest['source_revision'] ?? '' ) && (string) ( $job['target_language'] ?? '' ) === (string) ( $manifest['language'] ?? '' ),
			'artifact_surface_revision_mismatch' => (string) ( $artifact_record['surface_revision'] ?? '' ) === self::translation_job_surface_revision( $manifest ),
			'quality_revision_mismatch' => $quality_revision === (string) ( $quality['quality_revision'] ?? '' ) && $quality_revision === $quality_reconstructed,
			'quality_job_binding_mismatch' => (string) ( $job['job_id'] ?? '' ) === (string) ( $quality['job_id'] ?? '' ) && $artifact_revision === (string) ( $quality['artifact_revision'] ?? '' ),
			'quality_surface_binding_mismatch' => (string) ( $artifact_record['surface_revision'] ?? '' ) === (string) ( $quality['surface_revision'] ?? '' ) && (string) ( $job['content_revision'] ?? '' ) === (string) ( $quality['content_revision'] ?? '' ),
			'translation_identity_mismatch' => $translation_id === absint( $job['translation_id'] ?? 0 ) && $translation_id === absint( $artifact_record['translation_id'] ?? 0 ) && $translation_id === absint( $quality['translation_id'] ?? 0 ),
			'quality_decision_not_pass' => 'pass' === (string) ( $quality['decision'] ?? '' ),
			'submission_generation_mismatch' => $generation === absint( $artifact_record['submission_generation'] ?? 0 ) && $generation === absint( $quality['submission_generation'] ?? 0 ),
			'quality_principal_mismatch' => ! empty( $artifact_record['writer_principal']['principal_id'] ) && ! empty( $quality['reviewer_principal']['principal_id'] ) && (string) ( $artifact_record['writer_principal']['principal_id'] ?? '' ) !== (string) ( $quality['reviewer_principal']['principal_id'] ?? '' ),
			(string) ( $evidence['code'] ?? 'quality_evidence_invalid' ) => ! empty( $evidence['success'] ),
		);
		if ( $require_applied_surface ) {
			$checks['published_content_revision_stale'] = $translation_id > 0 && (string) ( $job['content_revision'] ?? '' ) === self::translation_job_translation_revision( $translation_id );
			$checks['published_surface_revision_stale'] = $translation_id > 0 && (string) ( $job['applied_surface_revision'] ?? '' ) === self::translation_job_current_surface_revision( $translation_id );
		}
		foreach ( $checks as $code => $passed ) {
			if ( ! $passed ) { return array( 'success' => false, 'code' => sanitize_key( (string) $code ), 'artifact_record' => $artifact_record, 'quality' => $quality, 'evidence' => $evidence ); }
		}
		return array( 'success' => true, 'artifact_record' => $artifact_record, 'quality' => $quality, 'evidence' => $evidence );
	}

	private static function translation_job_quality_evidence_key( string $revision ): string {
		return 'devenia_tj_quality_evidence_' . self::translation_job_clean_id( $revision );
	}

	private static function translation_job_quality_receipt_key( string $receipt_id ): string {
		return 'devenia_tj_quality_receipt_' . self::translation_job_clean_id( $receipt_id );
	}
}
