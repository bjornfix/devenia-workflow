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
		global $wpdb;
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lease takeover/renewal needs compare-and-swap semantics unavailable in Options API.
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
				maybe_serialize( $replacement ),
				$key,
				maybe_serialize( $expected )
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
