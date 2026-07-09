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
				'work_scope'  => array(
					'type'        => 'string',
					'default'     => 'translation',
					'description' => 'Reservation scope. Use translation for source/language work or source for source-only work.',
				),
				'work_type'   => array(
					'type'        => 'string',
					'description' => 'Optional first-class work item type, such as source_design_repair.',
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
				'agent_session_id' => array(
					'type'        => 'string',
					'description' => 'Stable agent/client session identifier that owns the reservation.',
				),
				'llm_vendor' => self::agent_session_input_schema_properties()['llm_vendor'],
				'llm_client' => self::agent_session_input_schema_properties()['llm_client'],
				'authority_vendor' => self::agent_session_input_schema_properties()['authority_vendor'],
				'authority_client' => self::agent_session_input_schema_properties()['authority_client'],
				'session_binding_token' => array(
					'type'        => 'string',
					'description' => 'Agent/session secret proof for the worker session that owns this reservation.',
				),
				'actor_id'    => array(
					'type'        => 'string',
					'description' => 'Optional worker actor label for diagnostics.',
				),
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
				'work_scope'  => array(
					'type'        => 'string',
					'default'     => 'translation',
					'description' => 'Reservation scope. Use source to release source-only work reservations.',
				),
				'work_type'   => array(
					'type'        => 'string',
					'description' => 'Optional first-class work item type, such as source_design_repair.',
				),
				'language'    => array( 'type' => 'string' ),
				'languages'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'claim_token' => array(
					'type'        => 'string',
					'description' => 'Token returned by reserve-work. Required unless force is true.',
				),
				'agent_session_id' => array(
					'type'        => 'string',
					'description' => 'Stable agent/client session identifier that owns the reservation. Required to release reservations that were created with a worker session binding.',
				),
				'llm_vendor' => self::agent_session_input_schema_properties()['llm_vendor'],
				'llm_client' => self::agent_session_input_schema_properties()['llm_client'],
				'authority_vendor' => self::agent_session_input_schema_properties()['authority_vendor'],
				'authority_client' => self::agent_session_input_schema_properties()['authority_client'],
				'session_binding_token' => array(
					'type'        => 'string',
					'description' => 'Agent/session secret proof for the worker session that owns this reservation.',
				),
				'force'       => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Release reservations without matching the claim token.',
				),
				'confirm_force_release' => array(
					'type'        => 'string',
					'description' => 'Required with force=true. Must equal ai-translations/release-reservation:force.',
				),
				'force_reason' => array(
					'type'        => 'string',
					'description' => 'Required with force=true. Human-readable reason for supervisor takeover.',
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
				'work_scope'      => array(
					'type'        => 'string',
					'description' => 'Optional reservation scope filter: translation or source.',
				),
				'work_type'       => array(
					'type'        => 'string',
					'description' => 'Optional first-class work item type filter.',
				),
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

		if ( self::is_source_work_scope( (string) ( $input['work_scope'] ?? '' ) ) ) {
			return self::reserve_source_work( $source, $input );
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
		$agent_session_id = self::agent_session_id_from_input( $input );
		$session_binding_token = sanitize_text_field( (string) ( $input['session_binding_token'] ?? '' ) );
		$session_binding_hash = '' !== $session_binding_token ? self::translation_reservation_session_binding_hash( $session_binding_token ) : '';
		$actor_id   = ! empty( $input['actor_id'] ) ? sanitize_key( (string) $input['actor_id'] ) : '';
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
				'agent_session_id' => $agent_session_id,
				'llm_vendor' => sanitize_text_field( (string) ( $input['llm_vendor'] ?? '' ) ),
				'llm_client' => sanitize_text_field( (string) ( $input['llm_client'] ?? '' ) ),
				'authority_vendor' => sanitize_text_field( (string) ( $input['authority_vendor'] ?? '' ) ),
				'authority_client' => sanitize_text_field( (string) ( $input['authority_client'] ?? '' ) ),
				'session_binding_hash' => $session_binding_hash,
				'session_binding_created_at' => '' !== $session_binding_hash ? gmdate( 'c', $now ) : '',
				'actor_id'   => $actor_id,
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

		if ( self::is_source_work_scope( (string) ( $input['work_scope'] ?? '' ) ) ) {
			return self::release_source_work_reservation( $input );
		}

		$languages = self::translation_reservation_languages_from_input( $input );
		if ( ! $languages ) {
			return self::error( 'No valid target languages supplied.' );
		}

		$token     = (string) ( $input['claim_token'] ?? '' );
		$force     = ! empty( $input['force'] );
		$agent_session_id = self::agent_session_id_from_input( $input );
		$session_binding_token = sanitize_text_field( (string) ( $input['session_binding_token'] ?? '' ) );
		$released  = array();
		$conflicts = array();
		$missing   = array();

		if ( $force ) {
			$confirm_force_release = (string) ( $input['confirm_force_release'] ?? '' );
			$force_reason          = trim( sanitize_textarea_field( (string) ( $input['force_reason'] ?? '' ) ) );
			if ( 'ai-translations/release-reservation:force' !== $confirm_force_release || '' === $force_reason ) {
				return array(
					'success' => false,
					'message' => 'force release requires confirm_force_release and force_reason.',
					'code'    => 'force_release_confirmation_required',
				);
			}
		}

		foreach ( $languages as $language ) {
			$key      = self::translation_reservation_option_name( $source_id, $language );
			$existing = self::translation_reservation_for_language( $source_id, $language, true );
			if ( ! $existing ) {
				$missing[] = $language;
				continue;
			}
			$owner_session_id = self::normalize_control_scope_id( (string) ( $existing['agent_session_id'] ?? '' ) );
			if ( ! $force && '' !== $owner_session_id && ! hash_equals( $owner_session_id, $agent_session_id ) ) {
				$conflicts[] = array(
					'language'    => $language,
					'code'        => 'reservation_owner_mismatch',
					'reservation' => self::public_translation_reservation( $existing ),
				);
				continue;
			}
			$existing_session_binding_hash = sanitize_text_field( (string) ( $existing['session_binding_hash'] ?? '' ) );
			if (
				! $force
				&& '' !== $existing_session_binding_hash
				&& (
					'' === $session_binding_token
					|| ! hash_equals( $existing_session_binding_hash, self::translation_reservation_session_binding_hash( $session_binding_token ) )
				)
			) {
				$conflicts[] = array(
					'language'    => $language,
					'code'        => 'reservation_session_binding_mismatch',
					'reservation' => self::public_translation_reservation( $existing ),
				);
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
		$work_scope      = sanitize_key( (string) ( $input['work_scope'] ?? '' ) );
		$raw_work_type   = sanitize_key( (string) ( $input['work_type'] ?? '' ) );
		$work_type       = '' !== $raw_work_type ? self::sanitize_work_type( $raw_work_type ) : '';
		$include_expired = ! empty( $input['include_expired'] );
		$prefixes        = 'source' === $work_scope
			? array( self::OPTION_WORK_CLAIM_PREFIX )
			: ( 'translation' === $work_scope ? array( self::OPTION_TRANSLATION_CLAIM_PREFIX ) : array( self::OPTION_TRANSLATION_CLAIM_PREFIX, self::OPTION_WORK_CLAIM_PREFIX ) );
		$reservations    = array();

		foreach ( $prefixes as $prefix ) {
			$like            = $wpdb->esc_like( $prefix ) . '%';
		$option_names    = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 500",
				$like
			)
		);

		foreach ( $option_names as $option_name ) {
			$claim = get_option( (string) $option_name, array() );
			if ( ! is_array( $claim ) ) {
				continue;
			}
				$is_source_reservation = self::OPTION_WORK_CLAIM_PREFIX === $prefix;
				$claim = $is_source_reservation ? self::sanitize_source_work_reservation( $claim ) : self::sanitize_translation_reservation( $claim );
			if ( ! $claim ) {
				continue;
			}
			if ( $source_id && (int) $claim['source_id'] !== $source_id ) {
				continue;
			}
				if ( '' !== $language && (string) ( $claim['language'] ?? '' ) !== $language ) {
					continue;
				}
				if ( '' !== $work_type && (string) ( $claim['work_type'] ?? '' ) !== $work_type ) {
				continue;
			}
			if ( ! $include_expired && ! empty( $claim['expired'] ) ) {
				delete_option( (string) $option_name );
				continue;
			}
				$reservations[] = $is_source_reservation ? self::public_source_work_reservation( $claim ) : self::public_translation_reservation( $claim );
			}
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

	private static function source_work_reservation_option_name( int $source_id, string $work_type ): string {
		return self::OPTION_WORK_CLAIM_PREFIX . absint( $source_id ) . '_' . self::sanitize_work_type( $work_type );
	}

	private static function translation_reservation_session_binding_hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
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
			'agent_session_id' => self::normalize_control_scope_id( (string) ( $claim['agent_session_id'] ?? '' ) ),
			'llm_vendor' => sanitize_text_field( (string) ( $claim['llm_vendor'] ?? '' ) ),
			'llm_client' => sanitize_text_field( (string) ( $claim['llm_client'] ?? '' ) ),
			'authority_vendor' => sanitize_text_field( (string) ( $claim['authority_vendor'] ?? '' ) ),
			'authority_client' => sanitize_text_field( (string) ( $claim['authority_client'] ?? '' ) ),
			'session_binding_hash' => sanitize_text_field( (string) ( $claim['session_binding_hash'] ?? '' ) ),
			'session_binding_created_at' => sanitize_text_field( (string) ( $claim['session_binding_created_at'] ?? '' ) ),
			'actor_id'   => sanitize_key( (string) ( $claim['actor_id'] ?? '' ) ),
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
			'work_scope' => 'translation',
			'work_type'  => 'translation',
			'source_id'  => absint( $claim['source_id'] ?? 0 ),
			'language'   => sanitize_key( (string) ( $claim['language'] ?? '' ) ),
			'owner'      => sanitize_text_field( (string) ( $claim['owner'] ?? '' ) ),
			'note'       => sanitize_textarea_field( (string) ( $claim['note'] ?? '' ) ),
			'agent_session_id' => self::normalize_control_scope_id( (string) ( $claim['agent_session_id'] ?? '' ) ),
			'llm_vendor' => sanitize_text_field( (string) ( $claim['llm_vendor'] ?? '' ) ),
			'llm_client' => sanitize_text_field( (string) ( $claim['llm_client'] ?? '' ) ),
			'authority_vendor' => sanitize_text_field( (string) ( $claim['authority_vendor'] ?? '' ) ),
			'authority_client' => sanitize_text_field( (string) ( $claim['authority_client'] ?? '' ) ),
			'has_session_binding' => ! empty( $claim['session_binding_hash'] ),
			'session_binding_created_at' => sanitize_text_field( (string) ( $claim['session_binding_created_at'] ?? '' ) ),
			'actor_id'   => sanitize_key( (string) ( $claim['actor_id'] ?? '' ) ),
			'claimed_at' => sanitize_text_field( (string) ( $claim['claimed_at'] ?? '' ) ),
			'expires_at' => sanitize_text_field( (string) ( $claim['expires_at'] ?? '' ) ),
			'expired'    => ! empty( $claim['expired'] ),
		);
	}

	private static function is_source_work_scope( string $scope ): bool {
		return 'source' === sanitize_key( $scope );
	}

	private static function sanitize_work_type( string $work_type ): string {
		$work_type = sanitize_key( $work_type );
		return '' !== $work_type ? $work_type : 'source_design_repair';
	}

	private static function reserve_source_work( WP_Post $source, array $input ): array {
		$source_id = (int) $source->ID;
		$work_type = self::sanitize_work_type( (string) ( $input['work_type'] ?? 'source_design_repair' ) );
		$ttl_seconds = isset( $input['ttl_seconds'] )
			? max( 60, min( self::MAX_TRANSLATION_CLAIM_TTL, absint( $input['ttl_seconds'] ) ) )
			: self::DEFAULT_TRANSLATION_CLAIM_TTL;
		$force      = ! empty( $input['force'] );
		$owner      = ! empty( $input['owner'] ) ? sanitize_text_field( (string) $input['owner'] ) : 'AI Translation Workflow';
		$note       = ! empty( $input['note'] ) ? sanitize_textarea_field( (string) $input['note'] ) : '';
		$agent_session_id = self::agent_session_id_from_input( $input );
		$session_binding_token = sanitize_text_field( (string) ( $input['session_binding_token'] ?? '' ) );
		$session_binding_hash = '' !== $session_binding_token ? self::translation_reservation_session_binding_hash( $session_binding_token ) : '';
		$actor_id   = ! empty( $input['actor_id'] ) ? sanitize_key( (string) $input['actor_id'] ) : '';
		$now        = time();
		$token      = wp_generate_password( 32, false, false );
		$existing   = self::source_work_reservation_for_type( $source_id, $work_type );
		if ( $existing && ! $force ) {
			return array(
				'success'        => false,
				'message'        => 'Source work is already reserved.',
				'source'         => self::source_summary_payload( $source ),
				'claim_token'    => '',
				'ttl_seconds'    => $ttl_seconds,
				'claims'         => array(),
				'conflicts'      => array(
					array(
						'work_scope'  => 'source',
						'work_type'   => $work_type,
						'reservation' => self::public_source_work_reservation( $existing ),
					),
				),
				'conflict_count' => 1,
			);
		}

		$claim = array(
			'work_scope' => 'source',
			'work_type'  => $work_type,
			'source_id'  => $source_id,
			'token'      => $token,
			'owner'      => $owner,
			'note'       => $note,
			'agent_session_id' => $agent_session_id,
			'llm_vendor' => sanitize_text_field( (string) ( $input['llm_vendor'] ?? '' ) ),
			'llm_client' => sanitize_text_field( (string) ( $input['llm_client'] ?? '' ) ),
			'authority_vendor' => sanitize_text_field( (string) ( $input['authority_vendor'] ?? '' ) ),
			'authority_client' => sanitize_text_field( (string) ( $input['authority_client'] ?? '' ) ),
			'session_binding_hash' => $session_binding_hash,
			'session_binding_created_at' => '' !== $session_binding_hash ? gmdate( 'c', $now ) : '',
			'actor_id'   => $actor_id,
			'claimed_at' => gmdate( 'c', $now ),
			'expires_at' => gmdate( 'c', $now + $ttl_seconds ),
		);
		$key = self::source_work_reservation_option_name( $source_id, $work_type );
		$saved = $force ? update_option( $key, $claim, false ) : add_option( $key, $claim, '', 'no' );
		if ( ! $saved ) {
			$existing = self::source_work_reservation_for_type( $source_id, $work_type, true );
			if ( $existing && ! $force ) {
				return array(
					'success'        => false,
					'message'        => 'Source work is already reserved.',
					'source'         => self::source_summary_payload( $source ),
					'claim_token'    => '',
					'ttl_seconds'    => $ttl_seconds,
					'claims'         => array(),
					'conflicts'      => array(
						array(
							'work_scope'  => 'source',
							'work_type'   => $work_type,
							'reservation' => self::public_source_work_reservation( $existing ),
						),
					),
					'conflict_count' => 1,
				);
			}
			update_option( $key, $claim, false );
		}

		return array(
			'success'        => true,
			'message'        => 'Source work reserved.',
			'source'         => self::source_summary_payload( $source ),
			'claim_token'    => $token,
			'ttl_seconds'    => $ttl_seconds,
			'claims'         => array( self::public_source_work_reservation( $claim ) ),
			'conflicts'      => array(),
			'conflict_count' => 0,
		);
	}

	private static function release_source_work_reservation( array $input ): array {
		$source_id = absint( $input['source_id'] ?? 0 );
		$work_type = self::sanitize_work_type( (string) ( $input['work_type'] ?? 'source_design_repair' ) );
		$token     = (string) ( $input['claim_token'] ?? '' );
		$force     = ! empty( $input['force'] );
		$agent_session_id = self::agent_session_id_from_input( $input );
		$session_binding_token = sanitize_text_field( (string) ( $input['session_binding_token'] ?? '' ) );
		$key      = self::source_work_reservation_option_name( $source_id, $work_type );
		$existing = self::source_work_reservation_for_type( $source_id, $work_type, true );
		if ( ! $existing ) {
			return array(
				'success' => true,
				'message' => 'No active source work reservation found.',
				'released' => array(),
				'conflicts' => array(),
			);
		}

		if ( ! $force ) {
			$owner_session_id = self::normalize_control_scope_id( (string) ( $existing['agent_session_id'] ?? '' ) );
			if ( '' !== $owner_session_id && $agent_session_id !== $owner_session_id ) {
				return self::error( 'Source work reservation is owned by another worker session.', 'reservation_owner_mismatch', array( 'reservation' => self::public_source_work_reservation( $existing ) ) );
			}
			$session_binding_hash = sanitize_text_field( (string) ( $existing['session_binding_hash'] ?? '' ) );
			if ( '' !== $session_binding_hash && ( '' === $session_binding_token || ! hash_equals( $session_binding_hash, self::translation_reservation_session_binding_hash( $session_binding_token ) ) ) ) {
				return self::error( 'Source work reservation requires matching session binding proof.', 'reservation_session_binding_mismatch', array( 'reservation' => self::public_source_work_reservation( $existing ) ) );
			}
			if ( '' === $token || ! hash_equals( (string) ( $existing['token'] ?? '' ), $token ) ) {
				return self::error( 'Source work is currently reserved by another worker.', 'reservation_claim_token_mismatch', array( 'reservation' => self::public_source_work_reservation( $existing ) ) );
			}
		}

		delete_option( $key );
		return array(
			'success' => true,
			'message' => 'Source work reservation released.',
			'released' => array( $work_type ),
			'conflicts' => array(),
		);
	}

	private static function source_work_reservation_for_type( int $source_id, string $work_type, bool $include_expired = false ): array {
		$key = self::source_work_reservation_option_name( $source_id, $work_type );
		$claim = get_option( $key, array() );
		if ( ! is_array( $claim ) ) {
			return array();
		}

		$claim = self::sanitize_source_work_reservation( $claim );
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

	private static function sanitize_source_work_reservation( array $claim ): array {
		$source_id = absint( $claim['source_id'] ?? 0 );
		$work_type = self::sanitize_work_type( (string) ( $claim['work_type'] ?? '' ) );
		$token = sanitize_text_field( (string) ( $claim['token'] ?? '' ) );
		if ( ! $source_id || '' === $work_type || '' === $token ) {
			return array();
		}

		$expires_at = (string) ( $claim['expires_at'] ?? '' );
		$expires_ts = $expires_at ? strtotime( $expires_at ) : 0;
		if ( ! $expires_ts ) {
			$expires_ts = time() - 1;
		}

		return array(
			'work_scope' => 'source',
			'work_type'  => $work_type,
			'source_id'  => $source_id,
			'token'      => $token,
			'owner'      => sanitize_text_field( (string) ( $claim['owner'] ?? '' ) ),
			'note'       => sanitize_textarea_field( (string) ( $claim['note'] ?? '' ) ),
			'agent_session_id' => self::normalize_control_scope_id( (string) ( $claim['agent_session_id'] ?? '' ) ),
			'llm_vendor' => sanitize_text_field( (string) ( $claim['llm_vendor'] ?? '' ) ),
			'llm_client' => sanitize_text_field( (string) ( $claim['llm_client'] ?? '' ) ),
			'authority_vendor' => sanitize_text_field( (string) ( $claim['authority_vendor'] ?? '' ) ),
			'authority_client' => sanitize_text_field( (string) ( $claim['authority_client'] ?? '' ) ),
			'session_binding_hash' => sanitize_text_field( (string) ( $claim['session_binding_hash'] ?? '' ) ),
			'session_binding_created_at' => sanitize_text_field( (string) ( $claim['session_binding_created_at'] ?? '' ) ),
			'actor_id'   => sanitize_key( (string) ( $claim['actor_id'] ?? '' ) ),
			'claimed_at' => sanitize_text_field( (string) ( $claim['claimed_at'] ?? '' ) ),
			'expires_at' => gmdate( 'c', $expires_ts ),
			'expired'    => $expires_ts <= time(),
		);
	}

	private static function public_source_work_reservation( array $claim ): array {
		return array(
			'work_scope' => 'source',
			'work_type'  => self::sanitize_work_type( (string) ( $claim['work_type'] ?? '' ) ),
			'source_id'  => absint( $claim['source_id'] ?? 0 ),
			'language'   => '',
			'owner'      => sanitize_text_field( (string) ( $claim['owner'] ?? '' ) ),
			'note'       => sanitize_textarea_field( (string) ( $claim['note'] ?? '' ) ),
			'agent_session_id' => self::normalize_control_scope_id( (string) ( $claim['agent_session_id'] ?? '' ) ),
			'llm_vendor' => sanitize_text_field( (string) ( $claim['llm_vendor'] ?? '' ) ),
			'llm_client' => sanitize_text_field( (string) ( $claim['llm_client'] ?? '' ) ),
			'authority_vendor' => sanitize_text_field( (string) ( $claim['authority_vendor'] ?? '' ) ),
			'authority_client' => sanitize_text_field( (string) ( $claim['authority_client'] ?? '' ) ),
			'has_session_binding' => ! empty( $claim['session_binding_hash'] ),
			'session_binding_created_at' => sanitize_text_field( (string) ( $claim['session_binding_created_at'] ?? '' ) ),
			'actor_id'   => sanitize_key( (string) ( $claim['actor_id'] ?? '' ) ),
			'claimed_at' => sanitize_text_field( (string) ( $claim['claimed_at'] ?? '' ) ),
			'expires_at' => sanitize_text_field( (string) ( $claim['expires_at'] ?? '' ) ),
			'expired'    => ! empty( $claim['expired'] ),
		);
	}

	/**
	 * Block writes when another active worker has reserved the source/language.
	 */
	private static function translation_claim_write_gate( int $source_id, string $language, string $claim_token, array $input = array() ): array {
		$reservation = self::translation_reservation_for_language( $source_id, $language );
		if ( ! $reservation ) {
			return array();
		}
		if ( '' === $claim_token || ! hash_equals( (string) ( $reservation['token'] ?? '' ), $claim_token ) ) {
			return array(
				'success'     => false,
				'message'     => 'Translation work is currently reserved by another worker. Use the matching claim_token, wait for expiry, or force-release the reservation after review.',
				'code'        => 'translation_reserved',
				'source_id'   => $source_id,
				'language'    => $language,
				'reservation' => self::public_translation_reservation( $reservation ),
			);
		}

		$owner_session_id = self::normalize_control_scope_id( (string) ( $reservation['agent_session_id'] ?? '' ) );
		$agent_session_id = self::agent_session_id_from_input( $input );
		if ( '' !== $owner_session_id && ! hash_equals( $owner_session_id, $agent_session_id ) ) {
			return array(
				'success'     => false,
				'message'     => 'Translation work is reserved by a different worker session.',
				'code'        => 'translation_reserved_session_mismatch',
				'source_id'   => $source_id,
				'language'    => $language,
				'reservation' => self::public_translation_reservation( $reservation ),
			);
		}

		$session_binding_hash = sanitize_text_field( (string) ( $reservation['session_binding_hash'] ?? '' ) );
		if ( '' !== $session_binding_hash ) {
			$session_binding_token = sanitize_text_field( (string) ( $input['session_binding_token'] ?? '' ) );
			if (
				'' === $session_binding_token
				|| ! hash_equals( $session_binding_hash, self::translation_reservation_session_binding_hash( $session_binding_token ) )
			) {
				return array(
					'success'     => false,
					'message'     => 'Translation work is reserved by a session-secret-bound worker. The matching session binding token is required.',
					'code'        => 'translation_reserved_session_binding_mismatch',
					'source_id'   => $source_id,
					'language'    => $language,
					'reservation' => self::public_translation_reservation( $reservation ),
				);
			}
		}

			return array();
	}
}
