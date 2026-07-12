<?php
/**
 * Site-level workflow mode.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Workflow_Mode {
	private static function workflow_mode(): string {
		$mode = sanitize_key( (string) get_option( self::OPTION_WORKFLOW_MODE, 'multilingual' ) );
		return in_array( $mode, array( 'multilingual', 'source_only' ), true ) ? $mode : 'multilingual';
	}

	private static function is_multilingual_workflow(): bool {
		return 'multilingual' === self::workflow_mode();
	}

	/** @return array<string,mixed> */
	private static function workflow_mode_status(): array {
		return array(
			'success'                        => true,
			'mode'                           => self::workflow_mode(),
			'multilingual_runtime_enabled'   => self::is_multilingual_workflow(),
			'target_translation_obligations' => self::is_multilingual_workflow(),
			'wordpress_locale_authoritative' => ! self::is_multilingual_workflow(),
			'wordpress_locale'               => get_locale(),
		);
	}

	/** @param array<string,mixed> $input Ability input. @return array<string,mixed> */
	private static function update_workflow_mode( array $input ): array {
		$mode = sanitize_key( (string) ( $input['mode'] ?? '' ) );
		if ( ! in_array( $mode, array( 'multilingual', 'source_only' ), true ) ) {
			return self::error( 'Workflow mode must be multilingual or source_only.' );
		}

		$previous = self::workflow_mode();
		update_option( self::OPTION_WORKFLOW_MODE, $mode, false );
		$status             = self::workflow_mode_status();
		$status['previous'] = $previous;
		$status['updated']  = $previous !== $mode;
		return $status;
	}
}
