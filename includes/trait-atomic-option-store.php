<?php
/**
 * Atomic create-only storage for internal WordPress option records.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Atomic_Option_Store {
	/**
	 * Create one option only when its unique option_name is absent.
	 *
	 * WordPress 6.9 add_option() uses ON DUPLICATE KEY UPDATE, so it no longer
	 * provides create-only semantics for exclusion records. This Interface
	 * deliberately bypasses that compatibility behavior and returns true only
	 * when this call inserted the row.
	 *
	 * @param mixed $value Option value.
	 */
	private static function atomic_create_option( string $key, $value ): bool {
		$key = trim( $key );
		if ( '' === $key ) {
			return false;
		}

		global $wpdb;
		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exclusion records require create-only SQL semantics that WordPress 6.9 add_option() no longer provides.
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$key,
				maybe_serialize( $value ),
				'off'
			)
		);

		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return 1 === $inserted;
	}

	/** Replace one option only when its complete serialized value still matches. */
	private static function atomic_replace_option_value( string $key, $expected, $replacement ): bool {
		$key = trim( $key );
		if ( '' === $key ) { return false; }
		$expected_bytes = maybe_serialize( $expected );
		$replacement_bytes = maybe_serialize( $replacement );
		global $wpdb;
		if ( $expected_bytes === $replacement_bytes ) {
			$matched_key = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Idempotent CAS must distinguish an exact owned no-op from a changed current owner when MariaDB reports zero affected rows.
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = BINARY %s LIMIT 1",
					$key,
					$expected_bytes
				)
			);
			wp_cache_delete( $key, 'options' );
			return $key === (string) $matched_key;
		}
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lease takeover/renewal needs compare-and-swap semantics unavailable in Options API.
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
				$replacement_bytes,
				$key,
				$expected_bytes
			)
		);
		wp_cache_delete( $key, 'options' );
		return 1 === $updated;
	}

	/** Delete one option only when its complete serialized value still matches. */
	private static function atomic_delete_option_value( string $key, $expected ): bool {
		$key = trim( $key );
		if ( '' === $key ) { return false; }
		global $wpdb;
		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lease release must not delete a successor lease after an ownership race.
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = BINARY %s",
				$key,
				maybe_serialize( $expected )
			)
		);
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return 1 === $deleted;
	}
}
