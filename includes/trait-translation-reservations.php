<?php
/**
 * Translation reservation and claim-gate logic for AI Translation Workflow.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Translation_Reservations {
	/**
	 * Input schema for translation work reservations.
	 */
	private static function translation_reservation_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'source_id' ),
			'properties'           => array(
				'source_id'   => array(
					'type'        => 'integer',
					'description' => 'Original WordPress page or post ID to reserve translation work for.',
				),
				'language'    => array(
					'type'        => 'string',
					'description' => 'Single target language to reserve. Use languages for several languages.',
				),
				'languages'   => array(
					'type'        => 'array',
					'description' => 'Target languages to reserve. Defaults to all configured target languages when omitted.',
					'items'       => array( 'type' => 'string' ),
				),
				'owner'       => array(
					'type'        => 'string',
					'description' => 'Human-readable owner, such as the agent/session name.',
				),
				'note'        => array( 'type' => 'string' ),
				'ttl_seconds' => array(
					'type'        => 'integer',
					'default'     => self::DEFAULT_TRANSLATION_CLAIM_TTL,
					'minimum'     => 60,
					'maximum'     => self::MAX_TRANSLATION_CLAIM_TTL,
					'description' => 'How long the reservation remains active before another worker may claim it.',
				),
				'force'       => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Replace an existing active reservation.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for releasing translation work reservations.
	 */
	private static function translation_reservation_release_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'source_id' ),
			'properties'           => array(
				'source_id'   => array( 'type' => 'integer' ),
				'language'    => array( 'type' => 'string' ),
				'languages'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'claim_token' => array(
					'type'        => 'string',
					'description' => 'Token returned by reserve-work. Required unless force is true.',
				),
				'force'       => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Release reservations without matching the claim token.',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Input schema for listing translation work reservations.
	 */
	private static function translation_reservation_list_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'source_id'       => array( 'type' => 'integer' ),
				'language'        => array( 'type' => 'string' ),
				'include_expired' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Reserve translation work for one source across one or more target languages.
	 */
	private static function reserve_translation_work( array $input ): array {
		$source_id = absint( $input['source_id'] ?? 0 );
		$source    = get_post( $source_id );
		if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) || self::is_translation_post( $source_id ) ) {
			return self::error( 'Source content not found.' );
		}

		$languages = self::translation_reservation_languages_from_input( $input );
		if ( ! $languages ) {
			return self::error( 'No valid target languages supplied.' );
		}

		$ttl_seconds = isset( $input['ttl_seconds'] )
			? max( 60, min( self::MAX_TRANSLATION_CLAIM_TTL, absint( $input['ttl_seconds'] ) ) )
			: self::DEFAULT_TRANSLATION_CLAIM_TTL;
		$force      = ! empty( $input['force'] );
		$owner      = ! empty( $input['owner'] ) ? sanitize_text_field( (string) $input['owner'] ) : 'AI Translation Workflow';
		$note       = ! empty( $input['note'] ) ? sanitize_textarea_field( (string) $input['note'] ) : '';
		$now        = time();
		$token      = wp_generate_password( 32, false, false );
		$claims     = array();
		$conflicts  = array();

		foreach ( $languages as $language ) {
			$existing = self::translation_reservation_for_language( $source_id, $language );
			if ( $existing && ! $force ) {
				$conflicts[] = array(
					'language'    => $language,
					'reservation' => self::public_translation_reservation( $existing ),
				);
				continue;
			}

			$claim = array(
				'source_id'  => $source_id,
				'language'   => $language,
				'token'      => $token,
				'owner'      => $owner,
				'note'       => $note,
				'claimed_at' => gmdate( 'c', $now ),
				'expires_at' => gmdate( 'c', $now + $ttl_seconds ),
			);
			$key   = self::translation_reservation_option_name( $source_id, $language );
			$saved = $force ? update_option( $key, $claim, false ) : add_option( $key, $claim, '', 'no' );
			if ( ! $saved ) {
				$existing = self::translation_reservation_for_language( $source_id, $language );
				if ( $existing && ! $force ) {
					$conflicts[] = array(
						'language'    => $language,
						'reservation' => self::public_translation_reservation( $existing ),
					);
					continue;
				}
				update_option( $key, $claim, false );
			}

			$claims[] = self::public_translation_reservation( $claim );
		}

		return array(
			'success'        => empty( $conflicts ),
			'message'        => empty( $conflicts ) ? 'Translation work reserved.' : 'Some languages are already reserved.',
			'source'         => self::source_summary_payload( $source ),
			'claim_token'    => $token,
			'ttl_seconds'    => $ttl_seconds,
			'claims'         => $claims,
			'conflicts'      => $conflicts,
			'conflict_count' => count( $conflicts ),
		);
	}

	/**
	 * Release translation work reservations.
	 */
	private static function release_translation_reservation( array $input ): array {
		$source_id = absint( $input['source_id'] ?? 0 );
		if ( ! $source_id || ! get_post( $source_id ) ) {
			return self::error( 'Source content not found.' );
		}

		$languages = self::translation_reservation_languages_from_input( $input );
		if ( ! $languages ) {
			return self::error( 'No valid target languages supplied.' );
		}

		$token     = (string) ( $input['claim_token'] ?? '' );
		$force     = ! empty( $input['force'] );
		$released  = array();
		$conflicts = array();
		$missing   = array();

		foreach ( $languages as $language ) {
			$key      = self::translation_reservation_option_name( $source_id, $language );
			$existing = self::translation_reservation_for_language( $source_id, $language, true );
			if ( ! $existing ) {
				$missing[] = $language;
				continue;
			}
			if ( ! $force && ( '' === $token || ! hash_equals( (string) ( $existing['token'] ?? '' ), $token ) ) ) {
				$conflicts[] = array(
					'language'    => $language,
					'reservation' => self::public_translation_reservation( $existing ),
				);
				continue;
			}

			delete_option( $key );
			$released[] = $language;
		}

		return array(
			'success'        => empty( $conflicts ),
			'message'        => empty( $conflicts ) ? 'Translation reservations released.' : 'Some reservations were not released because the claim token did not match.',
			'released'       => $released,
			'missing'        => $missing,
			'conflicts'      => $conflicts,
			'conflict_count' => count( $conflicts ),
		);
	}

	/**
	 * List active translation work reservations.
	 */
	private static function list_translation_reservations( array $input ): array {
		global $wpdb;

		$source_id       = absint( $input['source_id'] ?? 0 );
		$language        = sanitize_key( (string) ( $input['language'] ?? '' ) );
		$include_expired = ! empty( $input['include_expired'] );
		$prefix          = self::OPTION_TRANSLATION_CLAIM_PREFIX;
		$like            = $wpdb->esc_like( $prefix ) . '%';
		$option_names    = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 500",
				$like
			)
		);
		$reservations = array();

		foreach ( $option_names as $option_name ) {
			$claim = get_option( (string) $option_name, array() );
			if ( ! is_array( $claim ) ) {
				continue;
			}
			$claim = self::sanitize_translation_reservation( $claim );
			if ( ! $claim ) {
				continue;
			}
			if ( $source_id && (int) $claim['source_id'] !== $source_id ) {
				continue;
			}
			if ( '' !== $language && (string) $claim['language'] !== $language ) {
				continue;
			}
			if ( ! $include_expired && ! empty( $claim['expired'] ) ) {
				delete_option( (string) $option_name );
				continue;
			}
			$reservations[] = self::public_translation_reservation( $claim );
		}

		return array(
			'success'      => true,
			'reservations' => $reservations,
			'total'        => count( $reservations ),
		);
	}

	/**
	 * Return valid target languages from reservation input.
	 */
	private static function translation_reservation_languages_from_input( array $input ): array {
		$languages = array();
		if ( ! empty( $input['language'] ) ) {
			$languages[] = sanitize_key( (string) $input['language'] );
		}
		if ( ! empty( $input['languages'] ) && is_array( $input['languages'] ) ) {
			foreach ( $input['languages'] as $language ) {
				$languages[] = sanitize_key( (string) $language );
			}
		}
		if ( ! $languages ) {
			$languages = array_keys( self::target_languages() );
		}

		return array_values(
			array_unique(
				array_filter(
					$languages,
					static function ( string $language ): bool {
						return self::is_translation_language( $language );
					}
				)
			)
		);
	}

	/**
	 * Option key for one source/language reservation.
	 */
	private static function translation_reservation_option_name( int $source_id, string $language ): string {
		return self::OPTION_TRANSLATION_CLAIM_PREFIX . absint( $source_id ) . '_' . sanitize_key( $language );
	}

	/**
	 * Return an active reservation for a source/language pair.
	 */
	private static function translation_reservation_for_language( int $source_id, string $language, bool $include_expired = false ): array {
		$key   = self::translation_reservation_option_name( $source_id, $language );
		$claim = get_option( $key, array() );
		if ( ! is_array( $claim ) ) {
			return array();
		}

		$claim = self::sanitize_translation_reservation( $claim );
		if ( ! $claim ) {
			delete_option( $key );
			return array();
		}
		if ( ! $include_expired && ! empty( $claim['expired'] ) ) {
			delete_option( $key );
			return array();
		}

		return $claim;
	}

	/**
	 * Sanitize stored reservation data.
	 */
	private static function sanitize_translation_reservation( array $claim ): array {
		$source_id = absint( $claim['source_id'] ?? 0 );
		$language  = sanitize_key( (string) ( $claim['language'] ?? '' ) );
		$token     = sanitize_text_field( (string) ( $claim['token'] ?? '' ) );
		if ( ! $source_id || ! self::is_translation_language( $language ) || '' === $token ) {
			return array();
		}

		$expires_at = (string) ( $claim['expires_at'] ?? '' );
		$expires_ts = $expires_at ? strtotime( $expires_at ) : 0;
		if ( ! $expires_ts ) {
			$expires_ts = time() - 1;
		}

		return array(
			'source_id'  => $source_id,
			'language'   => $language,
			'token'      => $token,
			'owner'      => sanitize_text_field( (string) ( $claim['owner'] ?? '' ) ),
			'note'       => sanitize_textarea_field( (string) ( $claim['note'] ?? '' ) ),
			'claimed_at' => sanitize_text_field( (string) ( $claim['claimed_at'] ?? '' ) ),
			'expires_at' => gmdate( 'c', $expires_ts ),
			'expired'    => $expires_ts <= time(),
		);
	}

	/**
	 * Return reservation data safe to expose in queue/status output.
	 */
	private static function public_translation_reservation( array $claim ): array {
		return array(
			'source_id'  => absint( $claim['source_id'] ?? 0 ),
			'language'   => sanitize_key( (string) ( $claim['language'] ?? '' ) ),
			'owner'      => sanitize_text_field( (string) ( $claim['owner'] ?? '' ) ),
			'note'       => sanitize_textarea_field( (string) ( $claim['note'] ?? '' ) ),
			'claimed_at' => sanitize_text_field( (string) ( $claim['claimed_at'] ?? '' ) ),
			'expires_at' => sanitize_text_field( (string) ( $claim['expires_at'] ?? '' ) ),
			'expired'    => ! empty( $claim['expired'] ),
		);
	}

	/**
	 * Block writes when another active worker has reserved the source/language.
	 */
	private static function translation_claim_write_gate( int $source_id, string $language, string $claim_token ): array {
		$reservation = self::translation_reservation_for_language( $source_id, $language );
		if ( ! $reservation ) {
			return array();
		}
		if ( '' !== $claim_token && hash_equals( (string) ( $reservation['token'] ?? '' ), $claim_token ) ) {
			return array();
		}

		return array(
			'success'     => false,
			'message'     => 'Translation work is currently reserved by another worker. Use the matching claim_token, wait for expiry, or force-release the reservation after review.',
			'code'        => 'translation_reserved',
			'source_id'   => $source_id,
			'language'    => $language,
			'reservation' => self::public_translation_reservation( $reservation ),
		);
	}
}
