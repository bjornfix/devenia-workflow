<?php
/**
 * Source design inheritance for AI Translation Workflow.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Source_Design_Inheritance {
	/**
	 * Source design contract used by translation workers.
	 *
	 * The source block tree is the canonical design. Translators localize the
	 * fragments returned here; they do not rebuild the tree per language.
	 *
	 * @return array<string,mixed>
	 */
	private static function source_design_contract( WP_Post $source ): array {
		$content   = self::normalize_gutenberg_content_for_storage( (string) $source->post_content );
		$blocks    = parse_blocks( $content );
		$fragments = array();
		self::collect_source_design_fragments( $blocks, $fragments );
		$editorial_validation = self::source_editorial_design_validation( $source, $content );

		return array(
			'schema_version'               => 1,
			'source_id'                    => (int) $source->ID,
			'design_hash'                  => self::source_design_signature_hash( $content ),
			'fragment_count'               => count( $fragments ),
			'fragments'                    => $fragments,
			'editorial_source_validation'  => $editorial_validation,
		);
	}

	/**
	 * Validate that a source post is good enough to be a canonical Devenia design tree.
	 *
	 * The Gutenberg/block-editor plugin owns the implementation behind this seam.
	 * AI Translations only consumes the result so source inheritance cannot quietly
	 * project a flat or unfinished source layout into every language.
	 *
	 * @return array<string,mixed>
	 */
	private static function source_editorial_design_validation( WP_Post $source, string $content = '' ): array {
		if ( 'post' !== (string) $source->post_type ) {
			return array(
				'available'      => true,
				'passed'         => true,
				'not_applicable' => true,
				'reason'         => 'editorial_post_gate_applies_to_posts_only',
			);
		}

		$content = '' !== $content ? $content : self::normalize_gutenberg_content_for_storage( (string) $source->post_content );
		$result  = apply_filters(
			'devenia_editorial_source_post_validation',
			null,
			$source,
			$content,
			array(
				'caller'    => 'devenia-ai-translations',
				'operation' => 'source_design_inheritance',
			)
		);

		if ( ! is_array( $result ) || empty( $result['available'] ) ) {
			return array(
				'available' => false,
				'passed'    => false,
				'code'      => 'source_editorial_validation_unavailable',
				'message'   => 'Devenia editorial source-post validation is unavailable. Activate the block-editor validation adapter before source design can be inherited.',
			);
		}

		return $result;
	}

	/**
	 * Return an ability-style error when a source is not a valid design source.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function source_editorial_design_gate_error( WP_Post $source, string $content = '' ): ?array {
		$validation = self::source_editorial_design_validation( $source, $content );
		if ( ! empty( $validation['passed'] ) ) {
			return null;
		}

		return array(
			'success'    => false,
			'code'       => empty( $validation['available'] ) ? 'source_editorial_validation_unavailable' : 'source_editorial_design_gate_failed',
			'message'    => empty( $validation['available'] )
				? 'Source design inheritance is blocked because Devenia editorial source-post validation is unavailable.'
				: 'Source design inheritance is blocked because the source post does not pass the Devenia editorial design gate.',
			'source_id'  => (int) $source->ID,
			'validation' => $validation,
		);
	}

	/**
	 * Project localized text into the source design tree.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function inherited_source_design_content( WP_Post $source, array $input, string $language ): array {
		$fragments = self::localized_fragment_map( $input['localized_fragments'] ?? array() );
		if ( empty( $fragments ) ) {
			return array(
				'success' => false,
				'message' => 'localized_fragments are required when inherit_source_design is enabled. Translators should supply text, not a redesigned Gutenberg tree.',
				'code'    => 'localized_fragments_required',
				'source_design' => self::source_design_contract( $source ),
			);
		}

		$source_content = self::normalize_gutenberg_content_for_storage( (string) $source->post_content );
		$source_gate_error = self::source_editorial_design_gate_error( $source, $source_content );
		if ( $source_gate_error ) {
			$source_gate_error['source_design'] = self::source_design_contract( $source );
			return $source_gate_error;
		}

		$blocks         = parse_blocks( $source_content );
		if ( empty( $blocks ) && has_blocks( $source_content ) ) {
			return self::error( 'Source Gutenberg content could not be parsed for design inheritance.' );
		}

		$contract = self::source_design_contract( $source );
		$missing  = self::missing_localized_fragment_keys( $contract['fragments'], $fragments );
		$strict   = ! array_key_exists( 'strict_source_design_fragments', $input ) || ! empty( $input['strict_source_design_fragments'] );
		if ( $strict && $missing ) {
			return array(
				'success'        => false,
				'message'        => 'Localized fragments are incomplete. Translate every source design fragment before saving.',
				'code'           => 'localized_fragments_incomplete',
				'missing_keys'   => $missing,
				'missing_count'  => count( $missing ),
				'source_design'  => $contract,
			);
		}

		$stats = array(
			'projected_count' => 0,
			'provided_count'  => count( $fragments ),
			'missing_count'   => count( $missing ),
		);
		self::project_source_design_blocks( $blocks, $fragments, $stats );

		$content = serialize_blocks( $blocks );
		$content = self::mirror_rtl_block_layout_from_source( $content, $source_content, $language );

		return array(
			'success'       => true,
			'content'       => $content,
			'source_design' => array_merge(
				$contract,
				array(
					'projection' => $stats,
				)
			),
		);
	}

	/**
	 * Persist localized fragments as the reusable content-only input for future
	 * source-design reprojection.
	 *
	 * @param mixed               $raw_fragments Raw localized fragment rows.
	 * @param array<string,mixed> $source_design Source design contract/projection.
	 */
	private static function store_localized_source_design_fragments( int $translation_id, WP_Post $source, string $language, $raw_fragments, array $source_design = array() ): void {
		$records = self::localized_fragment_records_for_storage( $raw_fragments );
		if ( empty( $records ) ) {
			return;
		}

		$design_hash = (string) ( $source_design['design_hash'] ?? '' );
		if ( '' === $design_hash ) {
			$design_hash = self::source_design_signature_hash( (string) $source->post_content );
		}

		self::update_json_post_meta(
			$translation_id,
			self::META_LOCALIZED_FRAGMENTS,
			array(
				'schema_version'     => 1,
				'source_id'          => (int) $source->ID,
				'language'           => sanitize_key( $language ),
				'source_hash'        => self::source_hash( $source ),
				'source_design_hash' => $design_hash,
				'stored_at'          => gmdate( 'c' ),
				'fragments'          => $records,
			)
		);
		update_post_meta( $translation_id, self::META_SOURCE_DESIGN_HASH, $design_hash );
	}

	/**
	 * Stored localized fragment records for one translation.
	 *
	 * @return array<string,mixed>
	 */
	private static function stored_localized_source_design_fragments( int $translation_id ): array {
		$raw = get_post_meta( $translation_id, self::META_LOCALIZED_FRAGMENTS, true );
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$records = self::localized_fragment_records_for_storage( $raw['fragments'] ?? array() );
		if ( empty( $records ) ) {
			return array();
		}

		return array(
			'schema_version'     => absint( $raw['schema_version'] ?? 1 ),
			'source_id'          => absint( $raw['source_id'] ?? 0 ),
			'language'           => sanitize_key( (string) ( $raw['language'] ?? '' ) ),
			'source_hash'        => sanitize_text_field( (string) ( $raw['source_hash'] ?? '' ) ),
			'source_design_hash' => sanitize_text_field( (string) ( $raw['source_design_hash'] ?? '' ) ),
			'stored_at'          => sanitize_text_field( (string) ( $raw['stored_at'] ?? '' ) ),
			'fragments'          => $records,
		);
	}

	/**
	 * Build sanitized fragment rows suitable for storage and later projection.
	 *
	 * @param mixed $raw Raw fragment list.
	 * @return array<int,array{key:string,html:string}>
	 */
	private static function localized_fragment_records_for_storage( $raw ): array {
		$map = self::localized_fragment_map( $raw );
		$out = array();
		foreach ( $map as $key => $html ) {
			$out[] = array(
				'key'  => $key,
				'html' => wp_kses_post( (string) $html ),
			);
		}

		return $out;
	}

	/**
	 * Extract best-effort localized fragments from an existing translated block tree.
	 *
	 * This is a migration fallback for older translations that were created before
	 * fragment persistence existed. It can only reproject safely when source keys
	 * still match.
	 *
	 * @return array<int,array{key:string,html:string}>
	 */
	private static function localized_fragment_records_from_existing_content( string $content ): array {
		$blocks    = parse_blocks( self::normalize_gutenberg_content_for_storage( $content ) );
		$out = array();
		self::collect_localized_fragment_records_from_blocks( $blocks, $out );

		return $out;
	}

	/**
	 * Collect localized projection rows from an existing translated block tree.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param array<int,array{key:string,html:string}> $records Output records.
	 */
	private static function collect_localized_fragment_records_from_blocks( array $blocks, array &$records, string $path = '' ): void {
		foreach ( $blocks as $index => $block ) {
			$current_path = '' === $path ? (string) $index : $path . '.' . $index;
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name  = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$html  = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';
			if ( in_array( $name, self::copy_quality_text_block_names(), true ) && '' !== trim( $html ) ) {
				if ( ! self::is_dynamic_presentation_surface_html( $html ) ) {
					$key = self::source_design_fragment_key( $name, $attrs, $current_path, 'text' );
					$value = self::source_design_inner_html_value( $html );
					if ( '' !== $key && '' !== trim( wp_strip_all_tags( $value ) ) ) {
						$records[] = array(
							'key'  => $key,
							'html' => wp_kses_post( $value ),
						);
					}
				}
			}

			foreach ( self::structured_text_attr_fragments( $name, $attrs ) as $attr_fragment ) {
				$key = self::structured_text_attr_fragment_key( $current_path, $name, $attr_fragment );
				$value = (string) ( $attr_fragment['text'] ?? '' );
				if ( '' !== $key && '' !== trim( wp_strip_all_tags( $value ) ) ) {
					$records[] = array(
						'key'  => $key,
						'html' => wp_kses_post( $value ),
					);
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::collect_localized_fragment_records_from_blocks( $block['innerBlocks'], $records, $current_path );
			}
		}
	}

	/**
	 * Extract the inner HTML from a saved text block wrapper.
	 */
	private static function source_design_inner_html_value( string $html ): string {
		if ( preg_match( '/^\\s*<([a-z][a-z0-9]*)\\b[^>]*>(.*)<\\/\\1>\\s*$/is', $html, $matches ) ) {
			return (string) $matches[2];
		}

		return $html;
	}

	/**
	 * Current source-design state for a translation payload.
	 *
	 * @return array<string,mixed>
	 */
	private static function translation_source_design_state( WP_Post $translation, ?WP_Post $source ): array {
		$language = sanitize_key( (string) get_post_meta( (int) $translation->ID, self::META_LANGUAGE, true ) );
		if ( ! $source || '' === $language ) {
			return array(
				'state' => 'missing_source',
				'passed' => false,
			);
		}

		$expected = self::expected_source_design_signature_hash( (string) $source->post_content, $language );
		$actual   = self::source_design_signature_hash( (string) $translation->post_content );
		$stored   = self::stored_localized_source_design_fragments( (int) $translation->ID );
		$stored_design_hash = (string) ( $stored['source_design_hash'] ?? get_post_meta( (int) $translation->ID, self::META_SOURCE_DESIGN_HASH, true ) );
		$fragment_count = isset( $stored['fragments'] ) && is_array( $stored['fragments'] ) ? count( $stored['fragments'] ) : 0;

		$state = 'in_sync';
		$next_action = '';
		if ( $expected !== $actual ) {
			$state = $fragment_count > 0 ? 'needs_reprojection' : 'manual_design_drift';
			$next_action = $fragment_count > 0 ? 'ai-translations/reproject-source-design' : 'recreate_or_reextract_localized_fragments_before_reprojection';
		} elseif ( '' !== $stored_design_hash && $stored_design_hash !== $expected ) {
			$state = $fragment_count > 0 ? 'needs_reprojection' : 'missing_localized_fragments';
			$next_action = $fragment_count > 0 ? 'ai-translations/reproject-source-design' : 'recreate_or_reextract_localized_fragments_before_reprojection';
		} elseif ( 0 === $fragment_count ) {
			$state = 'fragments_not_persisted';
			$next_action = 'next_upsert_or_reprojection_will_store_localized_fragments';
		}

		return array(
			'passed'                  => 'in_sync' === $state || 'fragments_not_persisted' === $state,
			'state'                   => $state,
			'expected_design_hash'    => $expected,
			'translation_design_hash' => $actual,
			'stored_source_design_hash' => $stored_design_hash,
			'stored_fragment_count'   => $fragment_count,
			'next_action'             => $next_action,
		);
	}

	/**
	 * Rebuild existing translations from the current source design tree.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function reproject_source_design( array $input ): array {
		$source_id = absint( $input['source_id'] ?? 0 );
		$source    = get_post( $source_id );
		if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) || self::is_translation_post( $source_id ) ) {
			return self::error( 'Source content not found.' );
		}

		$apply   = ! empty( $input['apply'] ) && empty( $input['dry_run'] );
		$dry_run = ! $apply;
		if ( $apply ) {
			$step_token_gate = self::translation_step_token_gate( 'draft_write', $input );
			if ( empty( $step_token_gate['success'] ) ) {
				return $step_token_gate;
			}
		} else {
			$step_token_gate = array();
		}

		$language_filter = array();
		if ( ! empty( $input['languages'] ) && is_array( $input['languages'] ) ) {
			foreach ( $input['languages'] as $language ) {
				$language = sanitize_key( (string) $language );
				if ( self::is_translation_language( $language ) ) {
					$language_filter[ $language ] = true;
				}
			}
		}
		$translation_filter = array();
		if ( ! empty( $input['translation_ids'] ) && is_array( $input['translation_ids'] ) ) {
			foreach ( $input['translation_ids'] as $translation_id ) {
				$translation_id = absint( $translation_id );
				if ( $translation_id ) {
					$translation_filter[ $translation_id ] = true;
				}
			}
		}

		$items = array();
		$totals = array(
			'checked' => 0,
			'ready' => 0,
			'changed' => 0,
			'applied' => 0,
			'blocked' => 0,
			'missing_fragments' => 0,
		);

		foreach ( self::translation_rows_for_source( $source_id ) as $row ) {
			$translation_id = absint( $row['id'] ?? 0 );
			$language = sanitize_key( (string) ( $row['language'] ?? '' ) );
			if ( ! $translation_id || ( $language_filter && empty( $language_filter[ $language ] ) ) || ( $translation_filter && empty( $translation_filter[ $translation_id ] ) ) ) {
				continue;
			}

			++$totals['checked'];
			$item = self::reproject_one_translation_source_design( $source, $translation_id, $language, $input, $dry_run, $step_token_gate );
			$items[] = $item;
			if ( empty( $item['success'] ) ) {
				++$totals['blocked'];
				if ( 'localized_fragments_incomplete' === (string) ( $item['code'] ?? '' ) || 'localized_fragments_missing' === (string) ( $item['code'] ?? '' ) ) {
					++$totals['missing_fragments'];
				}
				continue;
			}
			++$totals['ready'];
			if ( ! empty( $item['changed'] ) ) {
				++$totals['changed'];
			}
			if ( ! empty( $item['applied'] ) ) {
				++$totals['applied'];
			}
		}

		return array(
			'success' => 0 === $totals['blocked'],
			'message' => $dry_run ? 'Source-design reprojection checked.' : 'Source-design reprojection applied where possible.',
			'dry_run' => $dry_run,
			'source' => array(
				'id' => (int) $source->ID,
				'title' => get_the_title( $source ),
				'source_hash' => self::source_hash( $source ),
				'source_design' => self::source_design_contract( $source ),
			),
			'totals' => $totals,
			'items' => $items,
		);
	}

	/**
	 * Migrate legacy translated content into the current source design fragment contract.
	 *
	 * This is intentionally separate from reprojection. It prepares the localized
	 * text contract first, then reproject_source_design can rebuild the content.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	private static function migrate_source_design_fragments( array $input ): array {
		$source_id = absint( $input['source_id'] ?? 0 );
		$source    = get_post( $source_id );
		if ( ! $source || ! self::is_translatable_post_type( (string) $source->post_type ) || self::is_translation_post( $source_id ) ) {
			return self::error( 'Source content not found.' );
		}

		$source_content = self::normalize_gutenberg_content_for_storage( (string) $source->post_content );
		$source_gate_error = self::source_editorial_design_gate_error( $source, $source_content );
		if ( $source_gate_error ) {
			$source_gate_error['source_design'] = self::source_design_contract( $source );
			return $source_gate_error;
		}

		$apply   = ! empty( $input['apply'] ) && empty( $input['dry_run'] );
		$dry_run = ! $apply;
		if ( $apply ) {
			$step_token_gate = self::translation_step_token_gate( 'draft_write', $input );
			if ( empty( $step_token_gate['success'] ) ) {
				return $step_token_gate;
			}
		} else {
			$step_token_gate = array();
		}

		$language_filter = self::source_design_language_filter( $input['languages'] ?? array() );
		$translation_filter = self::source_design_translation_filter( $input['translation_ids'] ?? array() );
		$contract = self::source_design_contract( $source );
		$items = array();
		$totals = array(
			'checked' => 0,
			'complete' => 0,
			'applied' => 0,
			'blocked' => 0,
			'missing_fragments' => 0,
			'order_fallback' => 0,
		);

		foreach ( self::translation_rows_for_source( $source_id ) as $row ) {
			$translation_id = absint( $row['id'] ?? 0 );
			$language = sanitize_key( (string) ( $row['language'] ?? '' ) );
			if ( ! $translation_id || ( $language_filter && empty( $language_filter[ $language ] ) ) || ( $translation_filter && empty( $translation_filter[ $translation_id ] ) ) ) {
				continue;
			}

			++$totals['checked'];
			$item = self::migrate_one_translation_source_design_fragments( $source, $contract, $translation_id, $language, $input, $dry_run, $step_token_gate );
			$items[] = $item;
			if ( empty( $item['success'] ) ) {
				++$totals['blocked'];
			} else {
				++$totals['complete'];
			}
			if ( ! empty( $item['applied'] ) ) {
				++$totals['applied'];
			}
			if ( ! empty( $item['mapping']['order_fallback_used'] ) ) {
				++$totals['order_fallback'];
			}
			$totals['missing_fragments'] += absint( $item['missing_count'] ?? 0 );
		}

		return array(
			'success' => 0 === $totals['blocked'],
			'message' => $dry_run ? 'Source-design fragment migration checked.' : 'Source-design fragment migration stored where complete.',
			'dry_run' => $dry_run,
			'source' => array(
				'id' => (int) $source->ID,
				'title' => get_the_title( $source ),
				'source_hash' => self::source_hash( $source ),
				'source_design_summary' => array(
					'schema_version' => absint( $contract['schema_version'] ?? 1 ),
					'design_hash' => (string) ( $contract['design_hash'] ?? '' ),
					'fragment_count' => absint( $contract['fragment_count'] ?? 0 ),
					'editorial_source_validation' => self::source_editorial_design_validation_summary( isset( $contract['editorial_source_validation'] ) && is_array( $contract['editorial_source_validation'] ) ? $contract['editorial_source_validation'] : array() ),
				),
				'source_design' => ! empty( $input['include_source_design'] ) ? $contract : null,
			),
			'policy' => array(
				'stores_only_complete_fragment_contracts' => true,
				'next_action_after_successful_apply' => 'ai-translations/reproject-source-design',
				'order_fallback_requires_human_or_independent_review' => true,
			),
			'totals' => $totals,
			'items' => $items,
		);
	}

	/**
	 * Migrate fragment records for one translation.
	 *
	 * @param array<string,mixed> $contract Source design contract.
	 * @param array<string,mixed> $input Ability input.
	 * @param array<string,mixed> $verified_identity Step-token identity.
	 * @return array<string,mixed>
	 */
	private static function migrate_one_translation_source_design_fragments( WP_Post $source, array $contract, int $translation_id, string $language, array $input, bool $dry_run, array $verified_identity ): array {
		$translation = get_post( $translation_id );
		if ( ! $translation || ! self::is_translation_post( $translation_id ) ) {
			return array(
				'success' => false,
				'code' => 'translation_not_found',
				'translation_id' => $translation_id,
			);
		}
		if ( 'publish' === (string) $translation->post_status && ! $dry_run && empty( $input['allow_update_published'] ) ) {
			return array(
				'success' => false,
				'code' => 'published_update_requires_confirmation',
				'message' => 'Published translations require allow_update_published=true before legacy source-design fragments can be migrated.',
				'translation_id' => $translation_id,
				'language' => $language,
			);
		}
		$claim_gate = self::translation_claim_write_gate( (int) $source->ID, $language, (string) ( $input['claim_token'] ?? '' ) );
		if ( $claim_gate ) {
			$claim_gate['translation_id'] = $translation_id;
			return $claim_gate;
		}

		$migration = self::source_design_fragment_migration_records(
			$contract,
			(string) $translation->post_content,
			! array_key_exists( 'use_order_fallback', $input ) || ! empty( $input['use_order_fallback'] ),
			self::localized_fragment_records_for_storage( $input['supplemental_fragments'] ?? array() )
		);
		$records = $migration['records'];
		$missing = $migration['missing_keys'];
		if ( $missing ) {
			return array(
				'success' => false,
				'code' => 'localized_fragments_incomplete',
				'message' => 'Legacy translated content does not cover every current source-design fragment. Provide localized text for the missing keys before applying migration.',
				'translation_id' => $translation_id,
				'language' => $language,
				'post_status' => (string) $translation->post_status,
				'migrated_count' => count( $records ),
				'missing_count' => count( $missing ),
				'missing_keys' => $missing,
				'mapping' => $migration['mapping'],
				'fragment_preview' => ! empty( $input['include_fragment_preview'] ) ? array_slice( $records, 0, 12 ) : array(),
			);
		}
		if ( ! $dry_run && ! empty( $migration['mapping']['order_fallback_used'] ) && empty( $input['allow_order_fallback_apply'] ) ) {
			return array(
				'success' => false,
				'code' => 'order_fallback_apply_requires_explicit_review',
				'message' => 'Order-based legacy fragment mapping can misalign translated copy with the new source design. Review the mapping and set allow_order_fallback_apply=true before applying.',
				'translation_id' => $translation_id,
				'language' => $language,
				'post_status' => (string) $translation->post_status,
				'migrated_count' => count( $records ),
				'missing_count' => 0,
				'mapping' => $migration['mapping'],
				'fragment_preview' => ! empty( $input['include_fragment_preview'] ) ? array_slice( $records, 0, 12 ) : array(),
			);
		}

		$projection = self::inherited_source_design_content(
			$source,
			array(
				'localized_fragments' => $records,
				'strict_source_design_fragments' => true,
			),
			$language
		);
		if ( empty( $projection['success'] ) ) {
			$projection['translation_id'] = $translation_id;
			$projection['language'] = $language;
			$projection['mapping'] = $migration['mapping'];
			return $projection;
		}

		if ( ! $dry_run ) {
			self::store_localized_source_design_fragments( $translation_id, $source, $language, $records, isset( $projection['source_design'] ) && is_array( $projection['source_design'] ) ? $projection['source_design'] : array() );
			if ( ! empty( $verified_identity ) ) {
				self::record_translation_writer_provenance( $translation_id, $verified_identity );
			}
			self::sync_translation_index_row( $translation_id );
		}

		$state_post = $dry_run ? $translation : get_post( $translation_id );

		return array(
			'success' => true,
			'translation_id' => $translation_id,
			'language' => $language,
			'post_status' => (string) $translation->post_status,
			'migrated_count' => count( $records ),
			'missing_count' => 0,
			'applied' => ! $dry_run,
			'mapping' => $migration['mapping'],
			'fragment_preview' => ! empty( $input['include_fragment_preview'] ) ? array_slice( $records, 0, 12 ) : array(),
			'design_inheritance_state' => $state_post instanceof WP_Post ? self::translation_source_design_state( $state_post, $source ) : array(),
		);
	}

	/**
	 * Return a compact source-design validation summary for migration responses.
	 *
	 * @param array<string,mixed> $validation Full validation result.
	 * @return array<string,mixed>
	 */
	private static function source_editorial_design_validation_summary( array $validation ): array {
		return array(
			'available' => ! empty( $validation['available'] ),
			'passed' => ! empty( $validation['passed'] ),
			'adapter' => sanitize_text_field( (string) ( $validation['adapter'] ?? '' ) ),
			'template_id' => sanitize_text_field( (string) ( $validation['template_id'] ?? '' ) ),
			'template_version' => sanitize_text_field( (string) ( $validation['template_version'] ?? '' ) ),
			'issue_count' => absint( $validation['issue_count'] ?? 0 ),
			'warning_count' => absint( $validation['warning_count'] ?? 0 ),
			'issue_codes' => isset( $validation['issue_codes'] ) && is_array( $validation['issue_codes'] )
				? array_values( array_map( 'sanitize_key', $validation['issue_codes'] ) )
				: array(),
		);
	}

	/**
	 * Build current-contract localized records from legacy translated content.
	 *
	 * @param array<string,mixed> $contract Source design contract.
	 * @return array{records:array<int,array{key:string,html:string}>,missing_keys:array<int,string>,mapping:array<string,mixed>}
	 */
	private static function source_design_fragment_migration_records( array $contract, string $legacy_content, bool $use_order_fallback, array $supplemental_records = array() ): array {
		$source_fragments = isset( $contract['fragments'] ) && is_array( $contract['fragments'] ) ? $contract['fragments'] : array();
		$legacy_records = self::localized_fragment_records_from_existing_content( $legacy_content );
		$legacy_by_key = self::localized_fragment_map( $legacy_records );
		$supplemental_by_key = self::localized_fragment_map( $supplemental_records );
		$legacy_values = array_values(
			array_filter(
				array_map(
					static function ( array $record ): string {
						return trim( (string) ( $record['html'] ?? '' ) );
					},
					$legacy_records
				),
				'strlen'
			)
		);

		$records = array();
		$records_by_key = array();
		$order_index = 0;
		$exact_count = 0;
		$supplemental_count = 0;
		$order_count = 0;
		foreach ( $source_fragments as $fragment ) {
			$key = self::source_design_fragment_key_from_input( (string) ( $fragment['key'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}

			$html = '';
			if ( array_key_exists( $key, $supplemental_by_key ) && '' !== trim( (string) $supplemental_by_key[ $key ] ) ) {
				$html = (string) $supplemental_by_key[ $key ];
				++$supplemental_count;
			} elseif ( array_key_exists( $key, $legacy_by_key ) && '' !== trim( (string) $legacy_by_key[ $key ] ) ) {
				$html = (string) $legacy_by_key[ $key ];
				++$exact_count;
			} elseif ( $use_order_fallback && array_key_exists( $order_index, $legacy_values ) ) {
				$html = (string) $legacy_values[ $order_index ];
				++$order_count;
			}
			++$order_index;

			if ( '' === trim( $html ) ) {
				continue;
			}
			$records_by_key[ $key ] = array(
				'key' => $key,
				'html' => wp_kses_post( $html ),
			);
		}

		foreach ( $source_fragments as $fragment ) {
			$key = self::source_design_fragment_key_from_input( (string) ( $fragment['key'] ?? '' ) );
			if ( '' !== $key && isset( $records_by_key[ $key ] ) ) {
				$records[] = $records_by_key[ $key ];
			}
		}

		$missing = self::missing_localized_fragment_keys( $source_fragments, self::localized_fragment_map( $records ) );

		return array(
			'records' => $records,
			'missing_keys' => $missing,
			'mapping' => array(
				'source_fragment_count' => count( $source_fragments ),
				'legacy_fragment_count' => count( $legacy_records ),
				'supplemental_fragment_count' => count( $supplemental_by_key ),
				'migrated_fragment_count' => count( $records ),
				'exact_key_count' => $exact_count,
				'supplemental_key_count' => $supplemental_count,
				'order_fallback_count' => $order_count,
				'order_fallback_used' => $order_count > 0,
				'complete' => empty( $missing ),
			),
		);
	}

	/**
	 * @param mixed $raw_languages Raw language filter input.
	 * @return array<string,bool>
	 */
	private static function source_design_language_filter( $raw_languages ): array {
		$filter = array();
		if ( ! is_array( $raw_languages ) ) {
			return $filter;
		}
		foreach ( $raw_languages as $language ) {
			$language = sanitize_key( (string) $language );
			if ( self::is_translation_language( $language ) ) {
				$filter[ $language ] = true;
			}
		}

		return $filter;
	}

	/**
	 * @param mixed $raw_ids Raw translation ID filter input.
	 * @return array<int,bool>
	 */
	private static function source_design_translation_filter( $raw_ids ): array {
		$filter = array();
		if ( ! is_array( $raw_ids ) ) {
			return $filter;
		}
		foreach ( $raw_ids as $translation_id ) {
			$translation_id = absint( $translation_id );
			if ( $translation_id ) {
				$filter[ $translation_id ] = true;
			}
		}

		return $filter;
	}

	/**
	 * Reproject one translation from the current source design.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @param array<string,mixed> $verified_identity Step-token identity.
	 * @return array<string,mixed>
	 */
	private static function reproject_one_translation_source_design( WP_Post $source, int $translation_id, string $language, array $input, bool $dry_run, array $verified_identity ): array {
		$translation = get_post( $translation_id );
		if ( ! $translation || ! self::is_translation_post( $translation_id ) ) {
			return array(
				'success' => false,
				'code' => 'translation_not_found',
				'translation_id' => $translation_id,
			);
		}
		if ( 'publish' === (string) $translation->post_status && ! $dry_run && empty( $input['allow_update_published'] ) ) {
			return array(
				'success' => false,
				'code' => 'published_update_requires_confirmation',
				'message' => 'Published translations require allow_update_published=true before source-design reprojection can write.',
				'translation_id' => $translation_id,
				'language' => $language,
			);
		}
		$claim_gate = self::translation_claim_write_gate( (int) $source->ID, $language, (string) ( $input['claim_token'] ?? '' ) );
		if ( $claim_gate ) {
			$claim_gate['translation_id'] = $translation_id;
			return $claim_gate;
		}

		$stored = self::stored_localized_source_design_fragments( $translation_id );
		$fragments = isset( $stored['fragments'] ) && is_array( $stored['fragments'] ) ? $stored['fragments'] : array();
		$fragment_source = 'stored';
		if ( empty( $fragments ) ) {
			$fragments = self::localized_fragment_records_from_existing_content( (string) $translation->post_content );
			$fragment_source = 'existing_translation_content';
		}
		if ( empty( $fragments ) ) {
			return array(
				'success' => false,
				'code' => 'localized_fragments_missing',
				'message' => 'No localized fragments are stored for this translation. Recreate localized fragments before source-design reprojection.',
				'translation_id' => $translation_id,
				'language' => $language,
			);
		}

		$projection = self::inherited_source_design_content(
			$source,
			array(
				'localized_fragments' => $fragments,
				'strict_source_design_fragments' => true,
			),
			$language
		);
		if ( empty( $projection['success'] ) ) {
			$projection['translation_id'] = $translation_id;
			$projection['language'] = $language;
			$projection['fragment_source'] = $fragment_source;
			return $projection;
		}

		$content = self::localize_internal_links_in_content( (string) $projection['content'], $language );
		$content = self::normalize_gutenberg_content_for_storage( $content );
		$changed = $content !== self::normalize_gutenberg_content_for_storage( (string) $translation->post_content );
		$previous_review_hash = self::translation_review_content_hash( $translation );
		$review_invalidated = false;

		if ( ! $dry_run && $changed ) {
			$result = 0;
			self::with_reviewer_style_capture_suspended(
				static function () use ( &$result, $translation_id, $content ): void {
					self::with_direct_save_storage_guardrails_suspended(
						static function () use ( &$result, $translation_id, $content ): void {
							$result = wp_update_post(
								wp_slash(
									array(
										'ID' => $translation_id,
										'post_content' => $content,
									)
								),
								true
							);
						}
					);
				}
			);
			if ( is_wp_error( $result ) ) {
				return array(
					'success' => false,
					'code' => 'reprojection_save_failed',
					'message' => $result->get_error_message(),
					'translation_id' => $translation_id,
					'language' => $language,
				);
			}
			$review_invalidated = self::invalidate_translation_reviews_if_content_changed( $translation_id, 'source_design_reprojection', $previous_review_hash );
		}

		if ( ! $dry_run ) {
			$status = $changed ? 'needs_review' : self::sanitize_translation_status( (string) get_post_meta( $translation_id, self::META_STATUS, true ) );
			update_post_meta( $translation_id, self::META_SOURCE_HASH, self::source_hash( $source ) );
			update_post_meta( $translation_id, self::META_STATUS, $status );
			self::store_localized_source_design_fragments( $translation_id, $source, $language, $fragments, isset( $projection['source_design'] ) && is_array( $projection['source_design'] ) ? $projection['source_design'] : array() );
			self::sync_source_presentation_meta( $translation_id, $source );
			if ( ! empty( $verified_identity ) ) {
				self::record_translation_writer_provenance( $translation_id, $verified_identity );
			}
			self::sync_translation_index_row( $translation_id );
		}

		$state_post = $dry_run ? $translation : get_post( $translation_id );

		return array(
			'success' => true,
			'translation_id' => $translation_id,
			'language' => $language,
			'post_status' => (string) $translation->post_status,
			'changed' => $changed,
			'applied' => ! $dry_run,
			'fragment_source' => $fragment_source,
			'review_invalidated' => $review_invalidated,
			'source_design' => $projection['source_design'] ?? array(),
			'design_inheritance_state' => $state_post instanceof WP_Post ? self::translation_source_design_state( $state_post, $source ) : array(),
		);
	}

	/**
	 * Sanitize localized text fragments supplied by a translator.
	 *
	 * @param mixed $raw Raw fragment list.
	 * @return array<string,string>
	 */
	private static function localized_fragment_map( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$map = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = self::source_design_fragment_key_from_input( (string) ( $row['key'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}
			if ( array_key_exists( 'html', $row ) && '' !== trim( (string) $row['html'] ) ) {
				$map[ $key ] = wp_kses_post( (string) $row['html'] );
				continue;
			}
			if ( array_key_exists( 'text', $row ) ) {
				$map[ $key ] = esc_html( self::normalize_review_text( (string) $row['text'] ) );
			}
		}

		return $map;
	}

	/**
	 * Normalize a source design fragment key from ability input.
	 */
	private static function source_design_fragment_key_from_input( string $key ): string {
		$key = trim( $key );
		if ( '' === $key || strlen( $key ) > 180 ) {
			return '';
		}

		return preg_match( '/^[a-z0-9:_\\-.]+$/i', $key ) ? $key : '';
	}

	/**
	 * Missing source fragment keys for strict inherited-design saves.
	 *
	 * @param array<int,array<string,mixed>> $source_fragments Source contract fragments.
	 * @param array<string,string>           $localized        Localized values by key.
	 * @return array<int,string>
	 */
	private static function missing_localized_fragment_keys( array $source_fragments, array $localized ): array {
		$missing = array();
		foreach ( $source_fragments as $fragment ) {
			$key = (string) ( $fragment['key'] ?? '' );
			if ( '' !== $key && ! array_key_exists( $key, $localized ) ) {
				$missing[] = $key;
			}
		}

		return $missing;
	}

	/**
	 * Collect text fragments from a source block tree with stable projection keys.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed source blocks.
	 * @param array<int,array<string,mixed>> $fragments Output fragments.
	 */
	private static function collect_source_design_fragments( array $blocks, array &$fragments, string $path = '' ): void {
		foreach ( $blocks as $index => $block ) {
			$current_path = '' === $path ? (string) $index : $path . '.' . $index;
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name  = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$html  = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';

			if ( in_array( $name, self::copy_quality_text_block_names(), true ) && '' !== trim( $html ) ) {
				if ( ! self::is_dynamic_presentation_surface_html( $html ) ) {
					$text = self::normalize_review_text( wp_strip_all_tags( strip_shortcodes( $html ) ) );
					if ( '' !== $text ) {
						$fragments[] = array(
							'key'       => self::source_design_fragment_key( $name, $attrs, $current_path, 'text' ),
							'path'      => $current_path,
							'block'     => $name,
							'unique_id' => isset( $attrs['uniqueId'] ) ? (string) $attrs['uniqueId'] : '',
							'format'    => 'inline_html',
							'heading'   => self::is_heading_block( $name, $attrs ),
							'text'      => $text,
						);
					}
				}
			}

			foreach ( self::structured_text_attr_fragments( $name, $attrs ) as $attr_fragment ) {
				$text = self::normalize_review_text( wp_strip_all_tags( strip_shortcodes( (string) ( $attr_fragment['text'] ?? '' ) ) ) );
				if ( '' === $text ) {
					continue;
				}
				$fragments[] = array(
					'key'       => self::structured_text_attr_fragment_key( $current_path, $name, $attr_fragment ),
					'path'      => $current_path,
					'block'     => $name . ':' . (string) ( $attr_fragment['field'] ?? '' ),
					'attr_path' => (string) ( $attr_fragment['label_path'] ?? '' ),
					'row_id'    => (string) ( $attr_fragment['row_id'] ?? '' ),
					'role'      => (string) ( $attr_fragment['role'] ?? 'text' ),
					'format'    => 'inline_html',
					'heading'   => ! empty( $attr_fragment['heading'] ),
					'text'      => $text,
				);
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::collect_source_design_fragments( $block['innerBlocks'], $fragments, $current_path );
			}
		}
	}

	/**
	 * Project localized fragments into a parsed source block tree.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param array<string,string>           $fragments Localized values by key.
	 * @param array<string,int>              $stats Projection stats.
	 */
	private static function project_source_design_blocks( array &$blocks, array $fragments, array &$stats, string $path = '' ): void {
		foreach ( $blocks as $index => &$block ) {
			$current_path = '' === $path ? (string) $index : $path . '.' . $index;
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name  = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			if ( in_array( $name, self::copy_quality_text_block_names(), true ) && isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
				if ( ! self::is_dynamic_presentation_surface_html( (string) $block['innerHTML'] ) ) {
					$key = self::source_design_fragment_key( $name, $attrs, $current_path, 'text' );
					if ( array_key_exists( $key, $fragments ) ) {
						$block['innerHTML']   = self::replace_source_design_text_html( (string) $block['innerHTML'], $fragments[ $key ] );
						$block['innerContent'] = array( $block['innerHTML'] );
						$stats['projected_count']++;
					}
				}
			}

			foreach ( self::structured_text_attr_fragments( $name, $attrs ) as $attr_fragment ) {
				$key = self::structured_text_attr_fragment_key( $current_path, $name, $attr_fragment );
				if ( ! array_key_exists( $key, $fragments ) ) {
					continue;
				}

				$old_value = (string) ( $attr_fragment['text'] ?? '' );
				$new_value = $fragments[ $key ];
				self::set_nested_array_value( $block['attrs'], (array) ( $attr_fragment['attr_path'] ?? array() ), $new_value );
				if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
					$block['innerHTML'] = self::replace_source_design_structured_html_value( $block['innerHTML'], $old_value, $new_value );
					if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
						$block['innerContent'] = self::replace_source_design_inner_content_value( $block['innerContent'], $old_value, $new_value );
					} elseif ( empty( $block['innerBlocks'] ) ) {
						$block['innerContent'] = array( $block['innerHTML'] );
					}
				}
				$stats['projected_count']++;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::project_source_design_blocks( $block['innerBlocks'], $fragments, $stats, $current_path );
			}
		}
		unset( $block );
	}

	/**
	 * Stable fragment key for text-bearing blocks.
	 */
	private static function source_design_fragment_key( string $block_name, array $attrs, string $path, string $part ): string {
		$unique_id = isset( $attrs['uniqueId'] ) ? trim( (string) $attrs['uniqueId'] ) : '';
		if ( '' !== $unique_id ) {
			return 'uid:' . preg_replace( '/[^a-z0-9_\\-.]/i', '', $unique_id ) . ':' . sanitize_key( $part );
		}

		return 'path:' . preg_replace( '/[^0-9.]/', '', $path ) . ':' . sanitize_key( str_replace( '/', '_', $block_name ) ) . ':' . sanitize_key( $part );
	}

	/**
	 * Dynamic presentation shortcodes are source-owned structure, not translatable fragments.
	 */
	private static function is_dynamic_presentation_surface_html( string $html ): bool {
		return false !== strpos( $html, '[devenia_presentation ' ) || false !== strpos( $html, '[devenia_presentation]' );
	}

	/**
	 * Replace text inside a block's existing wrapper while keeping wrapper design.
	 */
	private static function replace_source_design_text_html( string $html, string $localized_html ): string {
		$localized_html = trim( $localized_html );
		if ( '' === $localized_html ) {
			return $html;
		}
		if ( preg_match( '/^(\\s*<([a-z][a-z0-9]*)\\b[^>]*>)(.*)(<\\/\\2>\\s*)$/is', $html, $matches ) ) {
			return $matches[1] . $localized_html . $matches[4];
		}

		return $localized_html;
	}

	/**
	 * Collect text fields stored in structured block attributes.
	 *
	 * This keeps design inheritance independent from the plugin that created the
	 * block. FAQ, how-to, and similar data-bearing blocks usually store repeated
	 * rows in semantic attribute collections such as questions or steps.
	 *
	 * @param array<string,mixed> $attrs Block attributes.
	 * @return array<int,array<string,mixed>>
	 */
	private static function structured_text_attr_fragments( string $block_name, array $attrs ): array {
		$fragments = array();
		foreach ( $attrs as $key => $value ) {
			if ( ! is_array( $value ) || ! self::is_structured_text_collection_key( (string) $key ) ) {
				continue;
			}
			self::collect_structured_text_attr_collection( $value, $fragments, array( $key ), array( $key ) );
		}

		$filtered = apply_filters( 'ai_translation_workflow_structured_text_attr_fragments', $fragments, $block_name, $attrs );
		if ( ! is_array( $filtered ) ) {
			return $fragments;
		}

		return array_values(
			array_filter(
				$filtered,
				static function ( $fragment ): bool {
					return is_array( $fragment ) && ! empty( $fragment['attr_path'] ) && array_key_exists( 'text', $fragment );
				}
			)
		);
	}

	/**
	 * @param array<int|string,mixed>        $collection Attribute row collection.
	 * @param array<int,array<string,mixed>> $fragments Output fragments.
	 * @param array<int,int|string>          $attr_path Current real attribute path.
	 * @param array<int,string>              $label_path Stable label path used for keys.
	 */
	private static function collect_structured_text_attr_collection( array $collection, array &$fragments, array $attr_path, array $label_path ): void {
		foreach ( $collection as $row_index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row_id = self::structured_text_row_identifier( $row, $row_index );
			foreach ( $row as $field => $value ) {
				$field_name = (string) $field;
				if ( is_string( $value ) && self::is_structured_text_field_key( $field_name ) ) {
					$role        = self::structured_text_field_role( $field_name );
					$fragments[] = array(
						'attr_path'  => array_merge( $attr_path, array( $row_index, $field_name ) ),
						'label_path' => self::structured_text_label_path( array_merge( $label_path, array( $row_id, $field_name ) ) ),
						'row_id'     => $row_id,
						'field'      => $field_name,
						'role'       => $role,
						'heading'    => in_array( $role, array( 'question', 'title', 'name' ), true ),
						'text'       => $value,
					);
					continue;
				}
				if ( is_array( $value ) && self::is_structured_text_collection_key( $field_name ) ) {
					self::collect_structured_text_attr_collection(
						$value,
						$fragments,
						array_merge( $attr_path, array( $row_index, $field_name ) ),
						array_merge( $label_path, array( $row_id, $field_name ) )
					);
				}
			}
		}
	}

	private static function is_structured_text_collection_key( string $key ): bool {
		$key = strtolower( preg_replace( '/[^a-z0-9_\\-]/i', '', $key ) ?: '' );

		return in_array( $key, array( 'question', 'questions', 'faq', 'faqs', 'step', 'steps', 'item', 'items' ), true );
	}

	private static function is_structured_text_field_key( string $key ): bool {
		$key = strtolower( preg_replace( '/[^a-z0-9_\\-]/i', '', $key ) ?: '' );

		return in_array(
			$key,
			array(
				'title',
				'content',
				'question',
				'answer',
				'jsonquestion',
				'jsonanswer',
				'name',
				'jsonname',
				'text',
				'jsontext',
				'description',
				'jsondescription',
			),
			true
		);
	}

	private static function structured_text_field_role( string $key ): string {
		$key = strtolower( preg_replace( '/[^a-z0-9_\\-]/i', '', $key ) ?: '' );
		if ( false !== strpos( $key, 'question' ) ) {
			return 'question';
		}
		if ( false !== strpos( $key, 'answer' ) || 'content' === $key || false !== strpos( $key, 'text' ) || false !== strpos( $key, 'description' ) ) {
			return 'answer';
		}
		if ( false !== strpos( $key, 'name' ) ) {
			return 'name';
		}
		if ( false !== strpos( $key, 'title' ) ) {
			return 'title';
		}

		return 'text';
	}

	/**
	 * @param array<string,mixed> $row Structured data row.
	 * @param int|string         $row_index Fallback row position.
	 */
	private static function structured_text_row_identifier( array $row, $row_index ): string {
		foreach ( array( 'id', 'uid', 'uniqueId', 'key' ) as $key ) {
			if ( isset( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
				return self::structured_text_key_part( (string) $row[ $key ] );
			}
		}

		return self::structured_text_key_part( (string) $row_index );
	}

	/**
	 * @param array<int,string> $path Human-readable stable path parts.
	 */
	private static function structured_text_label_path( array $path ): string {
		$parts = array_map( array( __CLASS__, 'structured_text_key_part' ), $path );

		return implode( '.', array_filter( $parts, 'strlen' ) );
	}

	private static function structured_text_key_part( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = str_replace( array( '/', '\\', ':', ' ' ), '_', $value );
		$value = preg_replace( '/[^a-z0-9_\\-.]/', '', $value );

		return is_string( $value ) ? trim( $value, '._-' ) : '';
	}

	/**
	 * Stable fragment key for structured block attribute fields.
	 *
	 * @param array<string,mixed> $fragment Attribute fragment descriptor.
	 */
	private static function structured_text_attr_fragment_key( string $path, string $block_name, array $fragment ): string {
		$block = self::structured_text_key_part( str_replace( '/', '_', $block_name ) );
		$label = self::structured_text_key_part( (string) ( $fragment['label_path'] ?? '' ) );

		return 'attr:' . preg_replace( '/[^0-9.]/', '', $path ) . ':' . $block . ':' . $label;
	}

	/**
	 * Set a nested array value by path when the path exists.
	 *
	 * @param array<string|int,mixed> $array Target array.
	 * @param array<int,string|int>   $path Nested keys.
	 * @param mixed                   $value New value.
	 */
	private static function set_nested_array_value( array &$array, array $path, $value ): void {
		if ( empty( $path ) ) {
			return;
		}
		$cursor =& $array;
		foreach ( $path as $index => $key ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $key, $cursor ) ) {
				return;
			}
			if ( count( $path ) - 1 === $index ) {
				$cursor[ $key ] = $value;
				return;
			}
			$cursor =& $cursor[ $key ];
		}
	}

	/**
	 * Replace a structured old value inside saved block HTML.
	 */
	private static function replace_source_design_structured_html_value( string $html, string $old_value, string $new_value ): string {
		$old_value = trim( $old_value );
		if ( '' === $old_value ) {
			return $html;
		}

		$plain = self::normalize_review_text( wp_strip_all_tags( strip_shortcodes( $old_value ) ) );
		$variants = array_unique(
			array_filter(
				array(
					wp_kses_post( $old_value ),
					esc_html( $plain ),
					esc_html( $old_value ),
					$old_value,
				),
				'strlen'
			)
		);

		foreach ( $variants as $variant ) {
			if ( false !== strpos( $html, $variant ) ) {
				return str_replace( $variant, $new_value, $html );
			}
		}

		return $html;
	}

	/**
	 * @param array<int,mixed> $inner_content Parsed block innerContent.
	 * @return array<int,mixed>
	 */
	private static function replace_source_design_inner_content_value( array $inner_content, string $old_value, string $new_value ): array {
		foreach ( $inner_content as &$part ) {
			if ( is_string( $part ) ) {
				$part = self::replace_source_design_structured_html_value( $part, $old_value, $new_value );
			}
		}
		unset( $part );

		return $inner_content;
	}

	/**
	 * Hash the non-text design signature of a Gutenberg block tree.
	 */
	private static function source_design_signature_hash( string $content ): string {
		$content = self::normalize_gutenberg_content_for_storage( $content );
		return hash( 'sha256', wp_json_encode( self::source_design_signature( parse_blocks( $content ) ) ) ?: '' );
	}

	/**
	 * Expected design signature for a target language.
	 *
	 * LTR translations must match the source design exactly. RTL translations may
	 * differ only by deterministic source-derived RTL mirroring from language
	 * direction data.
	 */
	private static function expected_source_design_signature_hash( string $source_content, string $language ): string {
		$source_content = self::normalize_gutenberg_content_for_storage( $source_content );
		if ( self::is_rtl_language( $language ) ) {
			$source_content = self::mirror_rtl_block_layout_from_source( $source_content, $source_content, $language );
		}

		return self::source_design_signature_hash( $source_content );
	}

	/**
	 * Build a text-independent design signature for guardrails.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return array<int,array<string,mixed>>
	 */
	private static function source_design_signature( array $blocks, string $path = '' ): array {
		$signature = array();
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$current_path = '' === $path ? (string) $index : $path . '.' . $index;
			$name         = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '';
			$attrs        = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$html         = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';
			$children     = ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? self::source_design_signature( $block['innerBlocks'], $current_path ) : array();
			$html_shell   = self::source_design_html_shell( $name, $attrs, $html );
			if ( '' === $name && empty( $attrs ) && '' === $html_shell && empty( $children ) ) {
				continue;
			}
			$signature[]  = array(
				'path'       => $current_path,
				'block'      => $name,
				'attrs'      => self::source_design_signature_attrs( $name, $attrs ),
				'html_shell' => $html_shell,
				'children'   => $children,
			);
		}

		return $signature;
	}

	/**
	 * Remove translatable text fields from block attrs before design comparison.
	 *
	 * @param array<string,mixed> $attrs Block attrs.
	 * @return array<string,mixed>
	 */
	private static function source_design_signature_attrs( string $block_name, array $attrs ): array {
		foreach ( self::structured_text_attr_fragments( $block_name, $attrs ) as $attr_fragment ) {
			self::set_nested_array_value( $attrs, (array) ( $attr_fragment['attr_path'] ?? array() ), '{{text}}' );
		}

		return self::recursive_ksort_array( $attrs );
	}

	/**
	 * Preserve wrapper class/tag design while ignoring text content.
	 */
	private static function source_design_html_shell( string $block_name, array $attrs, string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}
		if ( in_array( $block_name, self::copy_quality_text_block_names(), true ) && preg_match( '/^(\\s*<([a-z][a-z0-9]*)\\b[^>]*>)(.*)(<\\/\\2>\\s*)$/is', $html, $matches ) ) {
			return trim( $matches[1] . '{{text}}' . $matches[4] );
		}

		$structured_fragments = self::structured_text_attr_fragments( $block_name, $attrs );
		if ( $structured_fragments ) {
			return '';
		}

		$shell = $html;
		foreach ( $structured_fragments as $attr_fragment ) {
			$shell = self::replace_source_design_structured_html_value( $shell, (string) ( $attr_fragment['text'] ?? '' ), '{{text}}' );
		}
		if ( $shell !== $html ) {
			return trim( $shell );
		}

		return '';
	}

	/**
	 * Recursively sort associative arrays so signature hashes are stable.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed
	 */
	private static function recursive_ksort_array( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as &$child ) {
			$child = self::recursive_ksort_array( $child );
		}
		unset( $child );
		ksort( $value );

		return $value;
	}
}
