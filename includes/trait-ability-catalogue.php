<?php
/**
 * Ability catalogue seam.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Devenia_AI_Translations_Ability_Catalogue {
	/**
	 * MCP ability catalogue.
	 *
	 * New abilities should be added here so registration metadata, schemas, and
	 * callbacks are reviewable without scanning hook wiring.
	 */
	private static function ability_catalogue(): array {
		return self::normalize_ability_catalogue(
			array_merge(
				self::translation_job_v2_ability_catalogue(),
				self::source_inventory_ability_catalogue(),
				array(
			'ai-translations/list-languages' => array(
				'label'            => 'List Translation Languages',
				'description'      => 'Returns the configured Devenia AI Workflow translation language registry. Defaults to compact registry data; pass detail_level=full only when runtime text/profile payloads are needed.',
				'input_schema'     => self::language_list_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input = array() ) {
					return self::run_ability_operation( 'list_languages', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/get-presentation-surface' => array(
				'label'            => 'Get Localized Presentation Surface',
				'description'      => 'Returns one shared localized presentation payload for singular pages, posts, author archives, term archives, blog archives, or 404 surfaces.',
				'input_schema'     => self::presentation_surface_input_schema(),
				'output_schema'    => self::presentation_surface_output_schema(),
				'execute_callback' => function ( $input = array() ) {
					return self::run_ability_operation( 'get_presentation_surface', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/translation-fitness-status' => array(
				'label'            => 'Check Translation Fitness Regressions',
				'description'      => 'Runs the packaged translation-fitness regression corpus so known bad naturalness and orthography failures cannot pass silently in future releases.',
				'input_schema'     => self::translation_fitness_status_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input = array() ) {
					return self::run_ability_operation( 'translation_fitness_status', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/lifecycle-regression-status' => array(
				'label'            => 'Check Translation Lifecycle Regression',
				'description'      => 'Reports translation workflow lifecycle readiness and can explicitly run a temporary post translation regression for QA, review evidence, publish gate, and review invalidation.',
				'input_schema'     => self::translation_lifecycle_regression_status_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input = array() ) {
					return self::run_ability_operation( 'lifecycle_regression_status', $input );
				},
				'meta'             => self::ability_meta( false, false, false ),
			),
				'ai-translations/language-packs-status' => array(
					'label'            => 'Check WordPress Core Language Packs',
					'description'      => 'Returns WordPress core language-pack status for all configured Devenia AI Workflow translation locales, and can install missing packs.',
				'input_schema'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'install_missing' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Install missing WordPress core language packs when possible.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'language_packs_status', $input );
					},
						'meta'             => self::ability_meta( true, false, true ),
					),
						'ai-translations/translation-index-status' => array(
					'label'            => 'Check Translation Index',
					'description'      => 'Reports the MariaDB translation registry table status and can rebuild it from WordPress page metadata.',
					'input_schema'     => array(
						'type'                 => 'object',
						'properties'           => array(
							'rebuild' => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => 'Rebuild the translation registry from WordPress page metadata before reporting status.',
							),
						),
						'additionalProperties' => false,
					),
						'output_schema'    => self::generic_output_schema(),
						'execute_callback' => function ( $input ) {
							return self::run_ability_operation( 'translation_index_status', $input );
						},
							'meta'             => self::ability_meta( false, false, true ),
						),
			'ai-translations/translation-fitness-scan' => array(
				'label'            => 'Scan Translation Fitness',
				'description'      => 'Scans stored translations through the same translation-fitness module used by QA and review gates, with optional filters for language, source, status, dimensions, and issue codes.',
				'input_schema'     => self::translation_fitness_scan_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'translation_fitness_scan', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/wrong-language-carryover-scan' => array(
				'label'            => 'Scan Wrong-Language Carryover',
				'description'      => 'Compatibility adapter that scans stored translations for visible copy matching another configured target-language profile.',
				'input_schema'     => self::wrong_language_carryover_scan_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'wrong_language_carryover_scan', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
							'ai-translations/gutenberg-content-safety-scan' => array(
							'label'            => 'Scan Gutenberg Content Safety',
							'description'      => 'Scans stored pages/posts with the same Gutenberg content-safety module used before saves and translation QA. Can optionally repair safe, output-preserving serialization mismatches.',
							'input_schema'     => self::gutenberg_content_safety_scan_input_schema(),
							'output_schema'    => self::generic_output_schema(),
							'execute_callback' => function ( $input ) {
								return self::run_ability_operation( 'gutenberg_content_safety_scan', $input );
							},
							'meta'             => self::ability_meta( false, false, true ),
						),
							'ai-translations/frontend-performance-status' => array(
							'label'            => 'Check Translation Frontend Performance',
							'description'      => 'Returns recent slow translated frontend requests recorded by the plugin. Can clear the slow log after review.',
							'input_schema'     => array(
							'type'                 => 'object',
							'properties'           => array(
								'clear' => array(
									'type'        => 'boolean',
									'default'     => false,
									'description' => 'Clear the recorded slow frontend request log after reading it.',
								),
							),
							'additionalProperties' => false,
							),
							'output_schema'    => self::generic_output_schema(),
							'execute_callback' => function ( $input ) {
								return self::run_ability_operation( 'frontend_performance_status', $input );
							},
							'meta'             => self::ability_meta( false, false, true ),
						),
						'ai-translations/frontend-integrity-status' => array(
							'label'            => 'Check Translation Frontend Integrity',
							'description'      => 'Fetches localized public frontend surfaces, especially language homepages, and blocks visible English/source remnants that only appear after runtime widgets, menus, and shared chrome render.',
							'input_schema'     => self::frontend_integrity_status_input_schema(),
							'output_schema'    => self::generic_output_schema(),
							'execute_callback' => function ( $input ) {
								return self::run_ability_operation( 'frontend_integrity_status', $input );
							},
							'meta'             => self::ability_meta( true, false, false ),
						),
						'ai-translations/warm-cache' => array(
							'label'            => 'Warm Translation Cache',
						'description'      => 'Fetches published translated content URLs without query strings so Cloudflare/WordPress anonymous HTML cache can be warmed after purges or publication.',
							'input_schema'     => self::warm_cache_input_schema(),
							'output_schema'    => self::generic_output_schema(),
							'execute_callback' => function ( $input ) {
								return self::run_ability_operation( 'warm_cache', $input );
							},
							'meta'             => self::ability_meta( false, false, false ),
						),
				'ai-translations/update-runtime-text' => array(
						'label'            => 'Update Runtime Translation Text',
					'description'      => 'Updates small runtime translation text stored in WordPress options, such as shared widget, 404, or short menu labels. Use this for typo/copy fixes instead of releasing the plugin.',
					'input_schema'     => self::runtime_text_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'update_runtime_text', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'ai-translations/update-featured-image-alt' => array(
					'label'            => 'Update Localized Featured Image Alt',
					'description'      => 'Stores localized featured-image alt text on one translated post without changing the shared attachment alt for other languages.',
					'input_schema'     => self::featured_image_alt_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'update_featured_image_alt', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'ai-translations/get-quality-profile' => array(
					'label'            => 'Get Translation Quality Profile',
					'description'      => 'Returns runtime, learned, and effective language quality profiles used by translation QA.',
					'input_schema'     => self::quality_profile_get_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'get_quality_profile', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
				),
				'ai-translations/update-quality-profile' => array(
					'label'            => 'Update Translation Quality Profile',
					'description'      => 'Updates runtime language quality profiles in WordPress options. Use this for glossary, terminology, agency-copy, review-pattern, and script-signal corrections.',
					'input_schema'     => self::quality_profile_update_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'update_quality_profile', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'ai-translations/record-language-rule-event' => array(
					'label'            => 'Record Language Rule Event',
					'description'      => 'Stores a language QA rule, human feedback decision, or reviewer learning event in the audited rule-event table instead of hardcoding it in PHP or packaged JSON.',
					'input_schema'     => self::language_rule_event_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'record_language_rule_event', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'ai-translations/list-language-rule-events' => array(
					'label'            => 'List Language Rule Events',
					'description'      => 'Lists audited language QA rule and reviewer-learning events, optionally filtered by language, status, or rule type.',
					'input_schema'     => self::language_rule_events_list_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'list_language_rule_events', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
				),
				'ai-translations/learning-inbox' => array(
					'label'            => 'List Translation Learning Inbox',
					'description'      => 'Lists captured human editor changes that can be kept as reviewer-style guidance, promoted to a QA rule, or ignored for future rule work.',
					'input_schema'     => self::learning_inbox_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'learning_inbox', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
				),
				'ai-translations/review-learning-event' => array(
					'label'            => 'Review Translation Learning Event',
					'description'      => 'Marks a captured human edit as reviewed style guidance, promotes it to a hard naturalness QA rule, or hides it from the pending learning inbox.',
					'input_schema'     => self::learning_event_review_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'review_learning_event', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'ai-translations/language-policy-status' => array(
					'label'            => 'Check Language Rule Policy',
					'description'      => 'Fails when language-specific QA rules are hardcoded in PHP or placed in packaged JSON instead of runtime profiles or audited rule events.',
					'input_schema'     => self::empty_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'language_policy_status', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
				),
				'ai-translations/agency-copy-brief' => array(
					'label'            => 'Get Agency Copy Brief',
					'description'      => 'Returns the target-reader, promise, proof, action, jargon, and review checks that should guide agency-level translation review for a source or translation.',
					'input_schema'     => self::agency_copy_brief_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'agency_copy_brief', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
				),
					'ai-translations/record-copy-feedback' => array(
						'label'            => 'Record Translation Copy Feedback',
						'description'      => 'Stores native or agency copy feedback on a source or translation. Open needs-work/blocking feedback keeps the page in the quality queue until resolved.',
						'input_schema'     => self::copy_feedback_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'record_copy_feedback', $input );
					},
						'meta'             => self::ability_meta( false, false, false ),
					),
					'ai-translations/get-reviewer-style-profile' => array(
					'label'            => 'Get Reviewer Style Profile',
					'description'      => 'Returns approved per-reviewer style learning for one language or all languages. Use this to shape future translation briefs without hardcoding language-specific copy rules.',
					'input_schema'     => self::reviewer_style_get_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'get_reviewer_style_profile', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
					),
					'ai-translations/record-reviewer-style-edit' => array(
					'label'            => 'Record Reviewer Style Edit',
					'description'      => 'Stores a human reviewer edit, lesson, terminology preference, or copy principle as reusable per-language and per-reviewer guidance for future translations.',
					'input_schema'     => self::reviewer_style_record_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'record_reviewer_style_edit', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
					),
				'ai-translations/repair-term-archive-self-redirects' => array(
					'label'            => 'Repair Term Archive Self Redirects',
					'description'      => 'Finds and optionally removes SEO-plugin self-redirects that mask localized category and tag archive URLs.',
					'input_schema'     => self::repair_term_archive_self_redirects_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input = array() ) {
						return self::run_ability_operation( 'repair_term_archive_self_redirects', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'ai-translations/list-taxonomy-terms' => array(
					'label'            => 'List Translation Taxonomy Terms',
					'description'      => 'Lists source categories/tags and their localized term mappings for translation work. Use this before mirroring categories or tags so contributors do not guess source term IDs, localized slugs, descriptions, or existing language variants.',
					'input_schema'     => self::taxonomy_terms_list_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input = array() ) {
						return self::run_ability_operation( 'list_taxonomy_terms', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
				),
				'ai-translations/update-source-qa-options' => array(
					'label'            => 'Update Source Translation QA Options',
					'description'      => 'Updates page-specific translation QA options stored on the source WordPress page. Use this for source-carryover preserve terms.',
					'input_schema'     => self::source_qa_options_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'update_source_qa_options', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'ai-translations/mark-source-content-integrity-reviewed' => array(
					'label'            => 'Mark Source Content Integrity Reviewed',
					'description'      => 'Marks a source content-integrity repair item complete for the current source hash when current audits show no useful content rewrite is needed.',
					'input_schema'     => self::source_content_integrity_review_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'mark_source_content_integrity_reviewed', $input );
					},
					'meta'             => self::ability_meta( false, false, false ),
				),
				'ai-translations/authored-original-intake-queue' => array(
					'label'            => 'List Authored Original Intake Queue',
					'description'      => 'Lists posts/pages authored in a configured non-source language that need an English technical source, source review, or downstream translation handoff.',
					'input_schema'     => self::authored_original_intake_queue_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'authored_original_intake_queue', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
				),
				'ai-translations/update-authored-original-intake' => array(
					'label'            => 'Update Authored Original Intake Status',
					'description'      => 'Marks an authored-original intake item ignored, pending again, or failed with an operator-visible note.',
					'input_schema'     => self::authored_original_intake_update_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'update_authored_original_intake', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'ai-translations/create-source-from-authored-original' => array(
					'label'            => 'Create English Source From Authored Original',
					'description'      => 'Creates or updates an English technical source from a post/page authored in another configured language, then attaches the authored original as that language translation without rewriting it.',
					'input_schema'     => self::authored_original_source_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'create_source_from_authored_original', $input );
					},
					'meta'             => self::ability_meta( false, false, false ),
				),
				'ai-translations/mark-source-generation-reviewed' => array(
					'label'            => 'Mark Generated English Source Reviewed',
					'description'      => 'Marks a generated English technical source as reviewed against its authored original so downstream translations can safely use it.',
					'input_schema'     => self::source_generation_review_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'mark_source_generation_reviewed', $input );
					},
					'meta'             => self::ability_meta( false, false, false ),
				),
				'ai-translations/get-source' => array(
					'label'            => 'Get Translation Source Content',
					'description'      => 'Returns source page/post content, metadata, source hash, taxonomies, and existing translations.',
				'input_schema'     => array(
					'type'                 => 'object',
					'required'             => array( 'source_id' ),
					'properties'           => array(
						'source_id' => array(
							'type'        => 'integer',
							'description' => 'Original WordPress page or post ID.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'get_source', $input );
				},
			'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/reserve-work' => array(
				'label'            => 'Reserve Translation Work',
				'description'      => 'Claims one source/language translation row for a limited time so parallel AI agents do not work the same item at once.',
				'input_schema'     => self::translation_reservation_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'reserve_translation_work', $input );
				},
				'meta'             => self::ability_meta( false, false, false ),
			),
			'ai-translations/release-reservation' => array(
				'label'            => 'Release Translation Reservation',
				'description'      => 'Releases an active source/language translation reservation after work is complete or abandoned.',
				'input_schema'     => self::translation_reservation_release_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'release_translation_reservation', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'ai-translations/list-reservations' => array(
				'label'            => 'List Translation Reservations',
				'description'      => 'Lists active translation work reservations, optionally filtered by source content and language.',
				'input_schema'     => self::translation_reservation_list_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'list_translation_reservations', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/upsert-page' => array(
				'label'            => 'Create or Update Translated Content',
				'description'      => 'Creates or updates a translated WordPress page or post with localized slug/path, taxonomies, and translation metadata. Source design is inherited by default; the AI/client supplies localized text fragments, not a redesigned Gutenberg tree.',
				'input_schema'     => self::upsert_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'upsert_page', $input );
				},
				'meta'             => self::ability_meta( false, false, false ),
			),
			'ai-translations/mark-source-taxonomy-reviewed' => array(
				'label'            => 'Mark Source Taxonomy Reviewed',
				'description'      => 'Marks a source post category/tag assignment review complete after checking topical fit, tag/category sprawl, singleton terms, and whether existing terms should be reused or consolidated.',
				'input_schema'     => self::source_taxonomy_review_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'mark_source_taxonomy_reviewed', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'ai-translations/mark-source-design-reviewed' => array(
				'label'            => 'Mark Source Design Reviewed',
				'description'      => 'Marks a source post design inspection complete without rewriting the source when the contributor has verified that the current rendered page already satisfies the intended publication experience.',
				'input_schema'     => self::source_design_review_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'mark_source_design_reviewed', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'ai-translations/repair-translation-author' => array(
				'label'            => 'Repair Translation Author',
				'description'      => 'Aligns an existing translated page or post author with its source author through the translation workflow, invalidating review evidence when the visible byline changes.',
				'input_schema'     => self::repair_translation_author_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'repair_translation_author', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'ai-translations/reproject-source-design' => array(
				'label'            => 'Reproject Source Design',
				'description'      => 'Rebuilds existing translations from the current source Gutenberg block tree and their stored localized fragments. Use this only after the source design has passed the Site Presentation article contract; translations do not get redesigned per language.',
				'input_schema'     => self::reproject_source_design_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'reproject_source_design', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'ai-translations/migrate-source-design-fragments' => array(
				'label'            => 'Migrate Source Design Fragments',
				'description'      => 'Extracts localized text from legacy translated Gutenberg content and stores it against the current source design fragment contract when coverage is complete. Use dry_run first, then reproject-source-design.',
				'input_schema'     => self::migrate_source_design_fragments_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'migrate_source_design_fragments', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'ai-translations/list-translations' => array(
				'label'            => 'List Content Translations',
				'description'      => 'Lists translation mappings, optionally filtered by source content, language, and status.',
				'input_schema'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'source_id' => array( 'type' => 'integer' ),
						'language'  => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string' ),
						'limit'     => array( 'type' => 'integer', 'default' => 100 ),
					),
					'additionalProperties' => false,
				),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'list_translations', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/mark-reviewed' => array(
				'label'            => 'Mark Translation Reviewed',
				'description'      => 'Legacy status marker for demotion/cleanup only. It cannot promote translations to reviewed or published.',
				'input_schema'     => array(
					'type'                 => 'object',
					'required'             => array( 'translation_id', 'translation_status' ),
					'properties'           => array(
						'translation_id'     => array( 'type' => 'integer' ),
						'translation_status' => array(
							'type' => 'string',
							'enum' => array( 'draft', 'needs_review', 'stale' ),
						),
						'claim_token'        => array(
							'type'        => 'string',
							'description' => 'Optional reservation token from ai-translations/reserve-work when this source/language is claimed.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'mark_reviewed', $input );
				},
				'meta'             => self::ability_meta( false, false, false ),
			),
			'ai-translations/qa-translation' => array(
				'label'            => 'QA Translation',
				'description'      => 'Runs lightweight workflow QA for translated content before review or publishing.',
				'input_schema'     => self::qa_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'qa_translation', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/mark-linguistic-reviewed' => array(
				'label'            => 'Mark Translation Linguistically Reviewed',
				'description'      => 'Marks a translation as linguistically reviewed after a human or agent language review. Publishing requires this marker.',
				'input_schema'     => self::linguistic_review_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'mark_linguistic_reviewed', $input );
				},
				'meta'             => self::ability_meta( false, false, false ),
			),
			'ai-translations/publish-translation' => array(
				'label'            => 'Publish Translation',
				'description'      => 'Runs QA, publishes translated content, updates translation metadata, cleans WordPress post caches, and optionally syncs the language menu for pages.',
				'input_schema'     => self::publish_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'publish_translation', $input );
				},
				'meta'             => self::ability_meta( false, false, false ),
			),
			'ai-translations/verify-live-translation' => array(
				'label'            => 'Verify Live Translation',
				'description'      => 'Fetches published translated content from the frontend and checks HTTP status, language prefix, html lang, hreflang, and localized internal links.',
				'input_schema'     => self::verify_live_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'verify_live_translation', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/workflow-status' => array(
				'label'            => 'Get Translation Workflow Status',
				'description'      => 'Returns per-language translation status for source content. Defaults to compact indexed rows; pass detail_level=full for legacy full translation payloads.',
				'input_schema'     => self::workflow_status_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'workflow_status', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/workflow-obligations' => array(
				'label'            => 'Get Translation Workflow Obligations',
				'description'      => 'Reports open translation review and publish obligations without blocking new draft/write production work. Publishing remains blocked until each specific translation has current review evidence.',
				'input_schema'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'source_id' => array(
							'type'        => 'integer',
							'description' => 'Optional source post/page ID. When omitted, newest source content is scanned up to limit.',
						),
						'limit' => array(
							'type'        => 'integer',
							'default'     => 100,
							'minimum'     => 1,
							'maximum'     => 500,
							'description' => 'Maximum source posts/pages to scan when source_id is omitted.',
						),
						'include_items' => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => 'Include compact per-translation obligation items in addition to totals.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'workflow_obligations', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/production-flow' => array(
				'label'            => 'Get Translation Production Flow',
				'description'      => 'Returns one read-only production workflow view: what can keep moving, which reviews remain visible, and which translations are ready or blocked for publish.',
				'input_schema'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'source_id' => array(
							'type'        => 'integer',
							'description' => 'Optional source post/page ID. When omitted, newest source content is scanned up to limit.',
						),
						'limit' => array(
							'type'        => 'integer',
							'default'     => 100,
							'minimum'     => 1,
							'maximum'     => 500,
							'description' => 'Maximum source posts/pages to scan when source_id is omitted.',
						),
						'include_items' => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => 'Include compact per-translation items.',
						),
						'proposed_source_title' => array(
							'type'        => 'string',
							'description' => 'Optional proposed source title for a not-yet-applied source update. Used only for read-only impact analysis.',
						),
						'proposed_source_excerpt' => array(
							'type'        => 'string',
							'description' => 'Optional proposed source excerpt for a not-yet-applied source update. Defaults to the current source excerpt.',
						),
						'proposed_source_content' => array(
							'type'        => 'string',
							'description' => 'Optional proposed source Gutenberg content for a not-yet-applied source update. The dashboard reports which translations would need reprojection before the source update is safe.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'production_flow', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/next-heartbeat-action' => array(
				'label'            => 'Get Next Heartbeat Action',
				'description'      => 'Compatibility Adapter that observes the Work Item Planner by default and accepts a server-owned Assignment when claim=true.',
				'input_schema'     => self::heartbeat_action_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'next_heartbeat_action', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'ai-translations/accept-assignment' => array(
				'label'            => 'Accept Contributor Assignment',
				'description'      => 'Idempotently accepts or resumes one server-owned Assignment for an independent contributor session.',
				'input_schema'     => self::heartbeat_action_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'accept_assignment', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'ai-translations/current-assignment' => array(
				'label'            => 'Get Current Contributor Assignment',
				'description'      => 'Returns or recovers the server-owned Assignment for the verified contributor session.',
				'input_schema'     => self::current_assignment_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'current_assignment', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'ai-translations/renew-assignment' => array(
				'label'            => 'Renew Contributor Assignment',
				'description'      => 'Renews the current server Assignment and its internal Reservation for the verified contributor session.',
				'input_schema'     => self::renew_assignment_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'renew_assignment', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'ai-translations/complete-assignment' => array(
				'label'            => 'Record Contributor Assignment Outcome',
				'description'      => 'Records completed, blocked, or abandoned outcome and releases the server-owned Assignment. Completion requires the assigned Work Item revision to be resolved.',
				'input_schema'     => self::complete_assignment_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'complete_assignment', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'ai-translations/resolve-assignment-block' => array(
				'label'            => 'Resolve Contributor Assignment Block',
				'description'      => 'Clears a structured blocker for one exact Work Item revision after coordinator verification.',
				'input_schema'     => self::resolve_assignment_block_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'resolve_assignment_block', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'ai-translations/heartbeat-assignment-coverage' => array(
				'label'            => 'Audit Heartbeat Assignment Coverage',
				'description'      => 'Read-only audit that compares workflow obligations with heartbeat assignment coverage so visible queue work cannot silently fall out of the assignment path.',
				'input_schema'     => self::heartbeat_assignment_coverage_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'heartbeat_assignment_coverage', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/heartbeat-status' => array(
				'label'            => 'Get Heartbeat Status',
				'description'      => 'Returns read-only server-side heartbeat health for independent agent sessions that have called next-heartbeat-action.',
				'input_schema'     => self::heartbeat_status_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'heartbeat_status', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/queue' => array(
				'label'            => 'List Translation Queue',
				'description'      => 'Lists source pages/posts with missing, stale, review-needed, or publish-ready translations.',
				'input_schema'     => self::queue_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'queue', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/review-queue' => array(
				'label'            => 'List Translation Review Queue',
				'description'      => 'Lists translated content that needs review, linguistic review, or publishing.',
				'input_schema'     => self::review_queue_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'review_queue', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/author-archive-queue' => array(
				'label'            => 'List Author Archive Translation Queue',
				'description'      => 'Lists author archive surfaces whose visible author info, archive URL, and CTA text need runtime localization. Localized values are stored in WordPress data, not packaged language files.',
				'input_schema'     => self::author_archive_queue_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'author_archive_queue', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/update-author-archive-translation' => array(
				'label'            => 'Update Author Archive Translation',
				'description'      => 'Stores localized author archive path, author info, and presentation copy in WordPress runtime data for one author and language.',
				'input_schema'     => self::author_archive_update_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'update_author_archive_translation', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'ai-translations/quality-review-queue' => array(
				'label'            => 'List Translation Quality Review Queue',
				'description'      => 'Lists published translated content whose language/copy quality review is missing or older than the WordPress modified timestamp.',
				'input_schema'     => self::quality_review_queue_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'quality_review_queue', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/quality-verdict' => array(
				'label'            => 'Get Translation Quality Verdict',
				'description'      => 'Returns one consolidated publishability and copy-quality verdict for source or translated content. The default ai_operator audience gives safe issue codes; internal_debug includes raw local evidence.',
				'input_schema'     => self::quality_verdict_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'quality_verdict', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/internal-link-opportunities' => array(
				'label'            => 'Find Internal Link Opportunities',
				'description'      => 'Finds a small, moderated set of relevant internal pages/posts that the current source or translation could link to, preferring localized target URLs when available.',
				'input_schema'     => self::internal_link_opportunities_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'internal_link_opportunities', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'ai-translations/mark-quality-reviewed' => array(
				'label'            => 'Mark Translation Quality Reviewed',
				'description'      => 'Marks a published page or translation as quality-reviewed after a full visible-page review. The review becomes stale when the WordPress page is modified later.',
				'input_schema'     => self::quality_review_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'mark_quality_reviewed', $input );
				},
				'meta'             => self::ability_meta( false, false, false ),
			),
			'ai-translations/mark-final-reviewed' => array(
				'label'            => 'Mark Translation Final Reviewed',
				'description'      => 'Marks a translation as final-reviewed after an independent final approval. Publishing requires this third review marker.',
				'input_schema'     => self::final_review_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'mark_final_reviewed', $input );
				},
				'meta'             => self::ability_meta( false, false, false ),
			),
			'ai-translations/sync-menu' => array(
				'label'            => 'Sync Language Menu',
				'description'      => 'Creates or rebuilds a language-specific menu from the source menu, using translated page mappings where available.',
				'input_schema'     => self::sync_menu_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'sync_menu', $input );
				},
				'meta'             => self::ability_meta( false, true, false ),
			),
			'ai-translations/repair-url-hierarchy' => array(
				'label'            => 'Repair Translation URL Hierarchy',
				'description'      => 'Moves translated pages under the correct language root and translated parent pages, then refreshes localized path metadata.',
				'input_schema'     => self::repair_url_hierarchy_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'repair_url_hierarchy', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'ai-translations/repair-internal-links' => array(
				'label'            => 'Repair Translation Internal Links',
				'description'      => 'Rewrites translated content links that point at English source content or unpublished localized targets to the safe published frontend target.',
				'input_schema'     => self::repair_url_hierarchy_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'repair_internal_links', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'ai-translations/repair-featured-images' => array(
				'label'            => 'Repair Translation Featured Images',
				'description'      => 'Mirrors source featured image state to existing translated pages and posts, records writer provenance, and invalidates stale review evidence.',
				'input_schema'     => self::repair_featured_images_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'repair_featured_images', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
				)
			)
		);
	}

}
