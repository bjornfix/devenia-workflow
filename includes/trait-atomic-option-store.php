<?php
/**
 * Atomic create-only storage for internal WordPress option records.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Atomic_Option_Store {
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
}
