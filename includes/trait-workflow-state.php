<?php
/**
 * Workflow-state composition seam.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Workflow_State {
	use Devenia_AI_Translations_Assignment_Authority;
	use Devenia_AI_Translations_Translation_Reservations;
	use Devenia_AI_Translations_Agent_Session_Identity;
	use Devenia_AI_Translations_Translation_Provenance;
	use Devenia_AI_Translations_Heartbeat_Workflow;

	/**
	 * Return per-language workflow status for a source page.
	 */
	private static function workflow_status_from_input( array $input ): array {
		return self::workflow_status( absint( $input['source_id'] ?? 0 ), self::queue_detail_level( $input ) );
	}

	/**
	 * Return per-language workflow status for a source page.
	 */
	private static function workflow_status( int $source_id, string $detail_level = 'compact' ): array {
		$snapshot = self::workflow_source_snapshot( $source_id, $detail_level );
		$source   = $snapshot['source'];
		if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) || self::is_translation_post( $source_id ) ) {
			return self::error( 'Source content not found.' );
		}

		$translations = $snapshot['translations'];
		$languages    = $snapshot['languages'];
		$rows      = array();
		foreach ( $languages as $language => $config ) {
			if ( ! empty( $config['source'] ) ) {
				continue;
			}
			$row         = $translations[ $language ] ?? null;
			$state       = $row ? self::queue_state_for_translation( $row ) : 'missing';
			$reservation = self::translation_reservation_for_language( $source_id, $language );
			if ( $reservation && 'complete' !== $state ) {
				$state = 'reserved';
			}
			$rows[] = array(
				'language'    => $language,
				'name'        => $config['name'] ?? strtoupper( $language ),
				'flag'        => $config['flag'] ?? strtoupper( $language ),
				'prefix'      => $config['prefix'] ?? '',
				'state'       => $state,
				'translation' => $row,
				'reservation' => $reservation ? self::public_translation_reservation( $reservation ) : null,
			);
		}

		return array(
			'success'     => true,
			'source'      => 'full' === $detail_level ? self::post_payload( $source ) : self::source_summary_payload( $source ),
			'source_hash' => $snapshot['source_hash'],
			'languages'   => $rows,
			'detail_level'=> $detail_level,
			'read_model'  => 'full' === $detail_level ? 'translation_payload' : 'work_item_catalog',
		);
	}

	private static function workflow_obligations( array $input ): array {
		$source_id = absint( $input['source_id'] ?? 0 );
		$limit = isset( $input['limit'] ) ? max( 1, min( 500, absint( $input['limit'] ) ) ) : 100;
		$include_items = array_key_exists( 'include_items', $input ) ? (bool) $input['include_items'] : true;
		$sources = self::workflow_source_candidates( $source_id, $limit );
		if ( $source_id && empty( $sources ) ) {
			return self::error( 'Source content not found.' );
		}
		$catalog = self::workflow_work_items_for_sources( $sources, $include_items );
		return array( 'success' => true, 'flow_policy' => array( 'default_workflow_step' => 'draft_write', 'open_reviews_must_be_visible' => true, 'open_reviews_block_new_draft_work' => false, 'publish_requires_current_reviews' => true, 'source_updates_with_existing_translations_require_reprojection' => true, 'real_reader_decision_safety_required' => true, 'currentness_and_historical_context_required' => true, 'purpose' => 'produce_source_content_translate_review_publish_when_quality_is_high_enough' ), 'totals' => $catalog['totals'], 'items' => $include_items ? $catalog['items'] : array(), 'read_model' => 'work_item_catalog' );
	}


	/**
	 * Classify one language row for the translation queue.
	 */
	private static function queue_state_for_translation( array $translation ): string {
		return Devenia_AI_Translations_Workflow_State_Model::classify_translation( $translation );
	}

	/**
	 * Suggested next action for a queue state.
	 */
	private static function queue_action_for_state( string $state ): string {
		$source_work_definition = self::source_work_queue_definition( $state );
		if ( ! empty( $source_work_definition['action'] ) ) {
			return sanitize_key( (string) $source_work_definition['action'] );
		}

		return Devenia_AI_Translations_Workflow_State_Model::next_action( $state );
	}

}
