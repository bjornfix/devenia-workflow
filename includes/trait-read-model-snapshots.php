<?php
/**
 * Request-local read-model snapshots.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Read_Model_Snapshots {
	/**
	 * Snapshot source, hash, languages, and translation rows for workflow views.
	 *
	 * @return array<string,mixed>
	 */
	private static function workflow_source_snapshot( int $source_id, string $detail_level ): array {
		$cache_key = array(
			'source_id'    => $source_id,
			'detail_level' => $detail_level,
			'source_hash'  => $source_id ? (string) get_post_modified_time( 'U', true, $source_id ) : '',
		);
		$cached = self::request_analysis_cache_get( 'workflow_source_snapshot', $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$source = get_post( $source_id );
		if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) || self::is_translation_post( $source_id ) ) {
			return self::request_analysis_cache_set(
				'workflow_source_snapshot',
				$cache_key,
				array(
					'source' => null,
					'translations' => array(),
					'languages' => array(),
					'source_hash' => '',
				)
			);
		}

		$translations = array();
		if ( 'full' === $detail_level ) {
			foreach ( self::translation_rows_for_source( $source_id ) as $row ) {
				$translations[ $row['language'] ] = $row;
			}
		} else {
			foreach ( self::heartbeat_translation_rows_for_source( $source_id ) as $workflow_row ) {
				$row = self::compact_translation_queue_payload_from_workflow_row( $workflow_row, $source );
				if ( ! empty( $row['language'] ) ) {
					$translations[ $row['language'] ] = $row;
				}
			}
		}

		return self::request_analysis_cache_set(
			'workflow_source_snapshot',
			$cache_key,
			array(
				'source'       => $source,
				'translations' => $translations,
				'languages'    => self::languages(),
				'source_hash'  => self::source_hash( $source ),
			)
		);
	}
}
