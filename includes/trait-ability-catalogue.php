<?php
/**
 * Ability catalogue seam.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait Devenia_Workflow_Ability_Catalogue {
	/**
	 * MCP ability catalogue.
	 *
	 * New abilities should be added here so registration metadata, schemas, and
	 * callbacks are reviewable without scanning hook wiring.
	 */
	private static function ability_catalogue(): array {
		return self::normalize_ability_catalogue(
			array_merge(
				self::translation_job_ability_catalogue(),
				self::source_rewrite_ability_catalogue(),
				self::source_inventory_ability_catalogue(),
				array(
			'devenia-workflow/get-mode' => array(
				'label'            => 'Get AI Workflow Mode',
				'description'      => 'Returns whether this site runs multilingual translation workflow or source-only content optimization.',
				'input_schema'     => self::empty_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input = array() ) {
					return self::run_ability_operation( 'get_workflow_mode', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'devenia-workflow/update-mode' => array(
				'label'            => 'Update AI Workflow Mode',
				'description'      => 'Switches between multilingual and source-only workflow. Source-only mode leaves WordPress locale and HTML language authoritative and creates no target translation obligations.',
				'input_schema'     => array(
					'type'                 => 'object',
					'required'             => array( 'mode' ),
					'properties'           => array(
						'mode' => array( 'type' => 'string', 'enum' => array( 'multilingual', 'source_only' ) ),
					),
					'additionalProperties' => false,
				),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'update_workflow_mode', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'devenia-workflow/list-languages' => array(
				'label'            => 'List Translation Languages',
				'description'      => 'Returns the configured Devenia Workflow translation language registry. Defaults to compact registry data; pass detail_level=full only when runtime text/profile payloads are needed.',
				'input_schema'     => self::language_list_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input = array() ) {
					return self::run_ability_operation( 'list_languages', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'devenia-workflow/get-presentation-surface' => array(
				'label'            => 'Get Localized Presentation Surface',
				'description'      => 'Returns one shared localized presentation payload for singular pages, posts, author archives, term archives, blog archives, or 404 surfaces.',
				'input_schema'     => self::presentation_surface_input_schema(),
				'output_schema'    => self::presentation_surface_output_schema(),
				'execute_callback' => function ( $input = array() ) {
					return self::run_ability_operation( 'get_presentation_surface', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'devenia-workflow/translation-fitness-status' => array(
				'label'            => 'Check Translation Fitness Regressions',
				'description'      => 'Runs the packaged translation-fitness regression corpus so known bad naturalness and orthography failures cannot pass silently in future releases.',
				'input_schema'     => self::translation_fitness_status_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input = array() ) {
					return self::run_ability_operation( 'translation_fitness_status', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
				'devenia-workflow/language-packs-status' => array(
					'label'            => 'Check WordPress Core Language Packs',
					'description'      => 'Returns WordPress core language-pack status for all configured Devenia Workflow translation locales, and can install missing packs.',
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
						'devenia-workflow/translation-index-status' => array(
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
			'devenia-workflow/translation-fitness-scan' => array(
				'label'            => 'Scan Translation Fitness',
				'description'      => 'Scans stored translations through the same translation-fitness module used by QA and review gates, with optional filters for language, source, status, dimensions, and issue codes.',
				'input_schema'     => self::translation_fitness_scan_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'translation_fitness_scan', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'devenia-workflow/wrong-language-carryover-scan' => array(
				'label'            => 'Scan Wrong-Language Carryover',
				'description'      => 'Compatibility adapter that scans stored translations for visible copy matching another configured target-language profile.',
				'input_schema'     => self::wrong_language_carryover_scan_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'wrong_language_carryover_scan', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
							'devenia-workflow/gutenberg-content-safety-scan' => array(
							'label'            => 'Scan Gutenberg Content Safety',
							'description'      => 'Scans stored pages/posts with the same Gutenberg content-safety module used before saves and translation QA. Can optionally repair safe, output-preserving serialization mismatches.',
							'input_schema'     => self::gutenberg_content_safety_scan_input_schema(),
							'output_schema'    => self::generic_output_schema(),
							'execute_callback' => function ( $input ) {
								return self::run_ability_operation( 'gutenberg_content_safety_scan', $input );
							},
							'meta'             => self::ability_meta( false, false, true ),
						),
							'devenia-workflow/frontend-performance-status' => array(
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
						'devenia-workflow/frontend-integrity-status' => array(
							'label'            => 'Check Translation Frontend Integrity',
							'description'      => 'Fetches source and target public homepages and blog archives on origin-bypassing and canonical cache surfaces, then verifies their managed Public Header Projection and localized chrome.',
							'input_schema'     => self::frontend_integrity_status_input_schema(),
							'output_schema'    => self::generic_output_schema(),
							'execute_callback' => function ( $input ) {
								return self::run_ability_operation( 'frontend_integrity_status', $input );
							},
							'meta'             => self::ability_meta( true, false, false ),
						),
						'devenia-workflow/warm-cache' => array(
							'label'            => 'Warm Translation Cache',
						'description'      => 'Fetches published translated content URLs without query strings so Cloudflare/WordPress anonymous HTML cache can be warmed after purges or publication.',
							'input_schema'     => self::warm_cache_input_schema(),
							'output_schema'    => self::generic_output_schema(),
							'execute_callback' => function ( $input ) {
								return self::run_ability_operation( 'warm_cache', $input );
							},
							'meta'             => self::ability_meta( false, false, false ),
						),
				'devenia-workflow/update-runtime-text' => array(
						'label'            => 'Update Runtime Translation Text',
					'description'      => 'Updates small runtime translation text stored in WordPress options, such as shared widget, 404, or short menu labels. Use this for typo/copy fixes instead of releasing the plugin.',
					'input_schema'     => self::runtime_text_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'update_runtime_text', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'devenia-workflow/update-public-header-manifest' => array(
					'label'            => 'Stage Public Header Manifest',
					'description'      => 'Stores a complete ordered source navigation manifest as pending only. The active manifest and reader-visible projections remain unchanged until complete-set activation succeeds.',
					'input_schema'     => self::public_header_manifest_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'update_public_header_manifest', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'devenia-workflow/migrate-public-header-label-authority' => array(
					'label'            => 'Migrate Public Header Label Authority',
					'description'      => 'Builds or stages one complete schema-2 manifest from established WordPress menu labels mapped to stable source-item identities. It never derives menu labels from page titles.',
					'input_schema'     => self::public_header_label_authority_migration_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'migrate_public_header_label_authority', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'devenia-workflow/enroll-public-header-from-existing-menus' => array(
					'label'            => 'Enroll Public Header from Existing Menus',
					'description'      => 'Builds the first complete schema-2 Public Header Projection from one verified source menu and two agreeing unmanaged retained target menus per configured language, then optionally activates through atomic sync.',
					'input_schema'     => self::public_header_enrollment_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'enroll_public_header_from_existing_menus', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'devenia-workflow/update-featured-image-alt' => array(
					'label'            => 'Update Localized Featured Image Alt',
					'description'      => 'Stores localized featured-image alt text on one translated post without changing the shared attachment alt for other languages.',
					'input_schema'     => self::featured_image_alt_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'update_featured_image_alt', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'devenia-workflow/get-quality-profile' => array(
					'label'            => 'Get Translation Quality Profile',
					'description'      => 'Returns runtime, learned, and effective language quality profiles used by translation QA.',
					'input_schema'     => self::quality_profile_get_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'get_quality_profile', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
				),
				'devenia-workflow/update-quality-profile' => array(
					'label'            => 'Update Translation Quality Profile',
					'description'      => 'Updates runtime language quality profiles in WordPress options. Use this for glossary, terminology, agency-copy, review-pattern, and script-signal corrections.',
					'input_schema'     => self::quality_profile_update_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'update_quality_profile', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'devenia-workflow/record-language-rule-event' => array(
					'label'            => 'Record Language Rule Event',
					'description'      => 'Stores a language QA rule, human feedback decision, or reviewer learning event in the audited rule-event table instead of hardcoding it in PHP or packaged JSON.',
					'input_schema'     => self::language_rule_event_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'record_language_rule_event', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'devenia-workflow/list-language-rule-events' => array(
					'label'            => 'List Language Rule Events',
					'description'      => 'Lists audited language QA rule and reviewer-learning events, optionally filtered by language, status, or rule type.',
					'input_schema'     => self::language_rule_events_list_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'list_language_rule_events', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
				),
				'devenia-workflow/learning-inbox' => array(
					'label'            => 'List Translation Learning Inbox',
					'description'      => 'Lists captured human editor changes that can be kept as reviewer-style guidance, promoted to a QA rule, or ignored for future rule work.',
					'input_schema'     => self::learning_inbox_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'learning_inbox', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
				),
				'devenia-workflow/review-learning-event' => array(
					'label'            => 'Review Translation Learning Event',
					'description'      => 'Marks a captured human edit as reviewed style guidance, promotes it to a hard naturalness QA rule, or hides it from the pending learning inbox.',
					'input_schema'     => self::learning_event_review_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'review_learning_event', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'devenia-workflow/language-policy-status' => array(
					'label'            => 'Check Language Rule Policy',
					'description'      => 'Fails when language-specific QA rules are hardcoded in PHP or placed in packaged JSON instead of runtime profiles or audited rule events.',
					'input_schema'     => self::empty_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'language_policy_status', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
				),
				'devenia-workflow/agency-copy-brief' => array(
					'label'            => 'Get Agency Copy Brief',
					'description'      => 'Returns the target-reader, promise, proof, action, jargon, and review checks that should guide agency-level translation review for a source or translation.',
					'input_schema'     => self::agency_copy_brief_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'agency_copy_brief', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
				),
					'devenia-workflow/record-copy-feedback' => array(
						'label'            => 'Record Translation Copy Feedback',
						'description'      => 'Stores native or agency copy feedback on a source or translation. Open needs-work/blocking feedback keeps the page in the quality queue until resolved.',
						'input_schema'     => self::copy_feedback_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'record_copy_feedback', $input );
					},
						'meta'             => self::ability_meta( false, false, false ),
					),
					'devenia-workflow/get-reviewer-style-profile' => array(
					'label'            => 'Get Reviewer Style Profile',
					'description'      => 'Returns approved per-reviewer style learning for one language or all languages. Use this to shape future translation briefs without hardcoding language-specific copy rules.',
					'input_schema'     => self::reviewer_style_get_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'get_reviewer_style_profile', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
					),
					'devenia-workflow/record-reviewer-style-edit' => array(
					'label'            => 'Record Reviewer Style Edit',
					'description'      => 'Stores a human reviewer edit, lesson, terminology preference, or copy principle as reusable per-language and per-reviewer guidance for future translations.',
					'input_schema'     => self::reviewer_style_record_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'record_reviewer_style_edit', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
					),
				'devenia-workflow/repair-term-archive-self-redirects' => array(
					'label'            => 'Repair Term Archive Self Redirects',
					'description'      => 'Finds and optionally removes SEO-plugin self-redirects that mask localized category and tag archive URLs.',
					'input_schema'     => self::repair_term_archive_self_redirects_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input = array() ) {
						return self::run_ability_operation( 'repair_term_archive_self_redirects', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'devenia-workflow/list-taxonomy-terms' => array(
					'label'            => 'List Translation Taxonomy Terms',
					'description'      => 'Lists source categories/tags and their localized term mappings for translation work. Use this before mirroring categories or tags so contributors do not guess source term IDs, localized slugs, descriptions, or existing language variants.',
					'input_schema'     => self::taxonomy_terms_list_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input = array() ) {
						return self::run_ability_operation( 'list_taxonomy_terms', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
				),
				'devenia-workflow/update-source-qa-options' => array(
					'label'            => 'Update Source Translation QA Options',
					'description'      => 'Updates page-specific translation QA options stored on the source WordPress page. Use this for source-carryover preserve terms.',
					'input_schema'     => self::source_qa_options_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'update_source_qa_options', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'devenia-workflow/mark-source-content-integrity-reviewed' => array(
					'label'            => 'Mark Source Content Integrity Reviewed',
					'description'      => 'Marks a source content-integrity repair item complete for the current source hash when current audits show no useful content rewrite is needed.',
					'input_schema'     => self::source_content_integrity_review_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'mark_source_content_integrity_reviewed', $input );
					},
					'meta'             => self::ability_meta( false, false, false ),
				),
				'devenia-workflow/authored-original-intake-queue' => array(
					'label'            => 'List Authored Original Intake Queue',
					'description'      => 'Lists posts/pages authored in a configured non-source language that need an English technical source, source review, or downstream translation handoff.',
					'input_schema'     => self::authored_original_intake_queue_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'authored_original_intake_queue', $input );
					},
					'meta'             => self::ability_meta( true, false, true ),
				),
				'devenia-workflow/update-authored-original-intake' => array(
					'label'            => 'Update Authored Original Intake Status',
					'description'      => 'Marks an authored-original intake item ignored, pending again, or failed with an operator-visible note.',
					'input_schema'     => self::authored_original_intake_update_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'update_authored_original_intake', $input );
					},
					'meta'             => self::ability_meta( false, false, true ),
				),
				'devenia-workflow/create-source-from-authored-original' => array(
					'label'            => 'Create English Source From Authored Original',
					'description'      => 'Creates or updates an English technical source from a post/page authored in another configured language, then attaches the authored original as that language translation without rewriting it.',
					'input_schema'     => self::authored_original_source_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'create_source_from_authored_original', $input );
					},
					'meta'             => self::ability_meta( false, false, false ),
				),
				'devenia-workflow/mark-source-generation-reviewed' => array(
					'label'            => 'Mark Generated English Source Reviewed',
					'description'      => 'Marks a generated English technical source as reviewed against its authored original so downstream translations can safely use it.',
					'input_schema'     => self::source_generation_review_input_schema(),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::run_ability_operation( 'mark_source_generation_reviewed', $input );
					},
					'meta'             => self::ability_meta( false, false, false ),
				),
				'devenia-workflow/source-editor-status' => array(
					'label'            => 'Get Native Source Editor Status',
					'description'      => 'Returns the native WordPress or builder editor Adapter and safe read/write abilities for one source page or post. This does not create translation work.',
					'input_schema'     => array(
						'type'                 => 'object',
						'required'             => array( 'source_id' ),
						'properties'           => array(
							'source_id' => array( 'type' => 'integer', 'description' => 'Original WordPress page or post ID.' ),
						),
						'additionalProperties' => false,
					),
					'output_schema'    => self::generic_output_schema(),
					'execute_callback' => function ( $input ) {
						return self::source_editor_status( is_array( $input ) ? $input : array() );
					},
					'meta'             => self::ability_meta( true, false, true ),
				),
				'devenia-workflow/get-source' => array(
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
			'devenia-workflow/mark-source-taxonomy-reviewed' => array(
				'label'            => 'Mark Source Taxonomy Reviewed',
				'description'      => 'Marks a source post category/tag assignment review complete after checking topical fit, tag/category sprawl, singleton terms, and whether existing terms should be reused or consolidated.',
				'input_schema'     => self::source_taxonomy_review_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'mark_source_taxonomy_reviewed', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'devenia-workflow/mark-source-design-reviewed' => array(
				'label'            => 'Mark Source Design Reviewed',
				'description'      => 'Marks a source post design inspection complete without rewriting the source when the contributor has verified that the current rendered page already satisfies the intended publication experience.',
				'input_schema'     => self::source_design_review_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'mark_source_design_reviewed', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'devenia-workflow/repair-translation-author' => array(
				'label'            => 'Repair Translation Author',
				'description'      => 'Aligns an existing translated page or post author with its source author through the translation workflow, invalidating review evidence when the visible byline changes.',
				'input_schema'     => self::repair_translation_author_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'repair_translation_author', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'devenia-workflow/reproject-source-design' => array(
				'label'            => 'Reproject Source Design',
				'description'      => 'Rebuilds existing translations from the current source Gutenberg block tree and their stored localized fragments. Apply any source-design policy registered by the site first; translations do not get redesigned per language.',
				'input_schema'     => self::reproject_source_design_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'reproject_source_design', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'devenia-workflow/migrate-source-design-fragments' => array(
				'label'            => 'Migrate Source Design Fragments',
				'description'      => 'Extracts localized text from legacy translated Gutenberg content and stores it against the current source design fragment contract when coverage is complete. Use dry_run first, then reproject-source-design.',
				'input_schema'     => self::migrate_source_design_fragments_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'migrate_source_design_fragments', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'devenia-workflow/list-translations' => array(
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
			'devenia-workflow/verify-live-translation' => array(
				'label'            => 'Verify Live Translation',
				'description'      => 'Fetches published translated content from the frontend and checks HTTP status, language prefix, html lang, hreflang, and localized internal links.',
				'input_schema'     => self::verify_live_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'verify_live_translation', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'devenia-workflow/author-archive-queue' => array(
				'label'            => 'List Author Archive Translation Queue',
				'description'      => 'Lists author archive surfaces whose visible author info, archive URL, and CTA text need runtime localization. Localized values are stored in WordPress data, not packaged language files.',
				'input_schema'     => self::author_archive_queue_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'author_archive_queue', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'devenia-workflow/update-author-archive-translation' => array(
				'label'            => 'Update Author Archive Translation',
				'description'      => 'Stores localized author archive path, author info, and presentation copy in WordPress runtime data for one author and language.',
				'input_schema'     => self::author_archive_update_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'update_author_archive_translation', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'devenia-workflow/quality-verdict' => array(
				'label'            => 'Get Translation Quality Verdict',
				'description'      => 'Returns one consolidated publishability and copy-quality verdict for source or translated content. The default ai_operator audience gives safe issue codes; internal_debug includes raw local evidence.',
				'input_schema'     => self::quality_verdict_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'quality_verdict', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'devenia-workflow/mark-quality-reviewed' => array(
				'label'            => 'Mark Whole-Page Quality Reviewed',
				'description'      => 'Stores a complete, evidence-backed whole-page quality review for a source or translated WordPress content item.',
				'input_schema'     => self::quality_review_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'mark_quality_reviewed', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'devenia-workflow/internal-link-opportunities' => array(
				'label'            => 'Find Internal Link Opportunities',
				'description'      => 'Finds a small, moderated set of relevant internal pages/posts that the current source or translation could link to, preferring localized target URLs when available.',
				'input_schema'     => self::internal_link_opportunities_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'internal_link_opportunities', $input );
				},
				'meta'             => self::ability_meta( true, false, true ),
			),
			'devenia-workflow/sync-menu' => array(
				'label'            => 'Activate Public Header Projections',
				'description'      => 'Requires the opaque receipt returned by the exact pending-manifest staging operation, stages and validates that owned manifest for every configured source and target language, atomically activates the complete set, then requires cache invalidation plus origin and canonical verification on every homepage and blog archive.',
				'input_schema'     => self::sync_menu_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'sync_menu', $input );
				},
				'meta'             => self::ability_meta( false, true, false ),
			),
			'devenia-workflow/repair-url-hierarchy' => array(
				'label'            => 'Repair Translation URL Hierarchy',
				'description'      => 'Moves translated pages under the correct language root and translated parent pages, then refreshes localized path metadata.',
				'input_schema'     => self::repair_url_hierarchy_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'repair_url_hierarchy', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'devenia-workflow/repair-internal-links' => array(
				'label'            => 'Repair Translation Internal Links',
				'description'      => 'Rewrites translated content links that point at English source content or unpublished localized targets to the safe published frontend target.',
				'input_schema'     => self::repair_url_hierarchy_input_schema(),
				'output_schema'    => self::generic_output_schema(),
				'execute_callback' => function ( $input ) {
					return self::run_ability_operation( 'repair_internal_links', $input );
				},
				'meta'             => self::ability_meta( false, false, true ),
			),
			'devenia-workflow/repair-featured-images' => array(
				'label'            => 'Repair Translation Featured Images',
				'description'      => 'Detects source/translation featured-image drift and routes it into the bounded Translation Job and Localized Presentation Publication lifecycle; it never mutates the public image directly.',
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
