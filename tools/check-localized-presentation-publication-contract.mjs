#!/usr/bin/env node
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const plugin = readFileSync(new URL("../devenia-workflow.php", import.meta.url), "utf8");
const publication = readFileSync(new URL("../includes/trait-localized-presentation-publication.php", import.meta.url), "utf8");
const jobs = readFileSync(new URL("../includes/trait-translation-job.php", import.meta.url), "utf8");
const runtime = readFileSync(new URL("./check-translation-job-runtime.php", import.meta.url), "utf8");
const publicHeaderRuntime = readFileSync(new URL("./check-public-header-projection-wordpress-runtime.php", import.meta.url), "utf8");

const syncStart = plugin.indexOf("private static function sync_language_menu");
const syncEnd = plugin.indexOf("private static function existing_menu_label_map", syncStart);
assert.ok(syncStart > 0 && syncEnd > syncStart, "sync_language_menu must remain a bounded implementation");
const sync = plugin.slice(syncStart, syncEnd);
const identityStart = publication.indexOf("private static function localized_menu_id");
const identityEnd = publication.indexOf("private static function validate_localized_menu_projection", identityStart);
assert.ok(identityStart > 0 && identityEnd > identityStart, "localized_menu_id must remain a bounded identity reader");
const identityReader = publication.slice(identityStart, identityEnd);
const migrationStart = publication.indexOf("private static function migrate_public_header_label_authority");
const migrationEnd = publication.indexOf("private static function update_public_header_manifest", migrationStart);
assert.ok(migrationStart > 0 && migrationEnd > migrationStart, "label-authority migration must remain a bounded explicit Interface");
const migrationInterface = publication.slice(migrationStart, migrationEnd);
const enrollmentStart = publication.indexOf("private static function enroll_public_header_from_existing_menus");
assert.ok(enrollmentStart > 0 && migrationStart > enrollmentStart, "first enrollment must remain a bounded explicit Interface");
const enrollmentInterface = publication.slice(enrollmentStart, migrationStart);

assert.match(publication, /publish_localized_presentation[\s\S]*apply_translation_publish_transition[\s\S]*refresh_public_header_projection_for_publication[\s\S]*devenia_workflow_frontend_cache_invalidation_result[\s\S]*verify_live_translation/);
assert.doesNotMatch(publication.slice(publication.indexOf("private static function publish_localized_presentation"), publication.indexOf("private static function rollback_localized_menu_projection")), /sync_language_menu\s*\(/, "normal publication must not activate one language directly");
assert.match(jobs, /translation_job_publish[\s\S]*publish_localized_presentation/);
assert.match(plugin, /private static function publish_translation[\s\S]*publish_localized_presentation/);
assert.match(publication, /frontend_cache_adapter_missing/);
assert.match(publication, /true !== \( \$invalidation\['success'\]/);
assert.match(publication, /devenia_workflow_localized_presentation_commit_adapter_result[\s\S]*translation_job_clean_recovery_caches[\s\S]*observed_mutation_revision/, "content publication must reconcile the commit receipt only after cache eviction and an exact public-surface read");
assert.match(publication, /before_exact && \( false === \$committed \|\| null === \$committed \)[\s\S]*publication_transaction_commit_outcome_unknown_unapplied[\s\S]*replacement_exact && \( true === \$committed \|\| null === \$committed \)[\s\S]*publication_transaction_commit_reconciliation_conflict/, "content commit false, true, and unknown outcomes must classify exact before, exact replacement, and foreign surfaces without collapsing state");
assert.match(publication, /publication_transaction_commit_reconciliation_conflict[\s\S]*'published' => null[\s\S]*'mutation_cas_revision' => ''[\s\S]*'observed_mutation_cas_revision' => \$observed_mutation_revision[\s\S]*'rollback_authorized' => false/, "foreign content state must remain critical while its observed revision stays diagnostic and never becomes rollback authority");
assert.match(publicHeaderRuntime, /array_key_exists\( 'published', \$content_attempt \)[\s\S]*null !== \$content_attempt\['published'\]/, "the WordPress runtime must distinguish an explicit published=null foreign receipt from a missing field");
assert.doesNotMatch(publicHeaderRuntime, /null !== \( \$content_attempt\['published'\] \?\? false \)/, "null coalescing must not erase the explicit published=null foreign receipt");
assert.match(publication, /'rollback_authorized' => true[\s\S]*'rollback_expected_surface_revision' => \$mutation_cas_revision/, "only an exact owned replacement receipt may authorize caller rollback");
assert.match(publication, /catch \( Throwable \$error \)[\s\S]*observed_after_exception[\s\S]*publication_transaction_exception_unapplied[\s\S]*publication_transaction_exception_applied[\s\S]*publication_transaction_exception_reconciliation_conflict/, "transaction Adapter exceptions must reconcile exact unapplied, applied, or foreign state instead of collapsing to unpublished");
assert.match(publication, /public_header_projection_publication_failed[\s\S]*commit_reconciliation[\s\S]*frontend_cache_adapter_missing[\s\S]*commit_reconciliation[\s\S]*localized_presentation_verification_failed[\s\S]*commit_reconciliation/, "every post-commit failure must preserve commit reconciliation and the exact mutation receipt");

assert.match(sync, /wp_create_nav_menu\( \$staging_name \)/);
assert.match(sync, /validate_localized_menu_projection[\s\S]*devenia_workflow_public_header_projection_receipt[\s\S]*'staged_only'\] = true[\s\S]*return \$base_result/, "single-language projection may only return a validated staged receipt");
assert.doesNotMatch(sync, /activate_localized_menu_id|retire_managed_localized_menu|retire_previous/, "single-language projection must not retain a bypass around complete-set activation and retirement");
assert.doesNotMatch(sync, /wp_delete_post\(/, "atomic projection must never delete active menu items before build");
assert.match(sync, /wp_delete_nav_menu\( \(int\) \$target_menu->term_id \)/, "failed staging projections must be cleaned up");

assert.match(publication, /OPTION_LOCALIZED_MENU_IDENTITIES/);
assert.match(publication, /OPTION_PUBLIC_HEADER_MANIFEST/);
assert.match(publication, /OPTION_PENDING_PUBLIC_HEADER_MANIFEST/);
assert.match(publication, /public_header_projection_plan/);
assert.match(sync, /public_header_projection_plan\( \$language/);
assert.match(publication, /sync_public_header_projection[\s\S]*pending_public_header_manifest[\s\S]*configured_public_header_languages[\s\S]*sync_language_menu[\s\S]*activate_public_header_projection_set/);
assert.doesNotMatch(publication, /private static function rollback_localized_menu_projection\s*\(/, "the dead standalone menu rollback transaction must not reintroduce a raw COMMIT path");
assert.doesNotMatch(publication, /private static function (?:activate_localized_menu_id|retire_managed_localized_menu)\s*\(/, "complete-set Public Header Projection must remain the only activation and retirement Interface");
assert.match(publication, /activate_public_header_projection_set[\s\S]*replace_public_header_state_transaction/);
assert.match(publication, /devenia_workflow_public_header_before_locked_state_transition[\s\S]*replace_public_header_state_transaction/, "the runtime fixture needs an exact snapshot-to-lock transaction boundary");
assert.match(publication, /replace_public_header_state_transaction[\s\S]*lock_localized_menu_projection_surface[\s\S]*public_header_staged_receipt_changed/);
assert.match(publication, /replace_public_header_state_transaction[\s\S]*OPTION_PUBLIC_HEADER_MANIFEST[\s\S]*OPTION_LOCALIZED_MENU_IDENTITIES[\s\S]*OPTION_PENDING_PUBLIC_HEADER_MANIFEST/);
assert.match(publication, /translation_job_canonicalize\( \$current \) === self::translation_job_canonicalize\( \$replacement_value \)[\s\S]*\$written = true/, "an already-equal locked option value must be an idempotent successful write");
assert.match(publication, /rollback_public_header_state_transaction[\s\S]*translation_job_rollback_recovery_transaction[\s\S]*clear_public_header_state_option_cache/);
assert.match(publication, /translation_job_canonicalize\( \$current \) !== self::translation_job_canonicalize\( \$expected_value \)[\s\S]*rollback_public_header_state_transaction\(\)[\s\S]*public_header_state_changed/, "a rejected partial option transaction must discard uncommitted cache values");
assert.match(publication, /OPTION_PUBLIC_HEADER_ENROLLMENT/);
assert.match(publication, /public_header_rollback_projection_receipts[\s\S]*previous_menu_surface_revision/);
assert.match(publication, /public_header_rollback_projection_receipts[\s\S]*public_header_navigation_snapshot_from_menu[\s\S]*expected_navigation/);
assert.match(publication, /verify_public_header_projection_set\( self::configured_public_header_languages\(\),[\s\S]*\$rollback_receipts\['expected_navigation'\]/, "rollback verification must use the receipt-bound prior navigation snapshot");
assert.match(publication, /public_header_projection_incomplete/);
assert.match(publication, /normalize_public_header_manifest_items\( \$input\['items'\] \?\? array\(\), true \)/, "manifest registration must require complete editorial label authority");
assert.match(publication, /public_header_label_authority_missing/);
assert.match(publication, /missing_editorial_label_authority/);
assert.match(migrationInterface, /current_user_can\( 'manage_options' \)/, "legacy label-authority migration must be capability-gated at its explicit Interface");
assert.match(enrollmentInterface, /current_user_can\( 'manage_options' \)/, "first enrollment must be capability-gated at its explicit Interface");
assert.match(publication, /public_header_source_manifest_from_menu/);
assert.match(publication, /get_nav_menu_locations[\s\S]*public_header_source_menu_not_primary/);
assert.match(publication, /insufficient_independent_authority_candidates/);
assert.match(publication, /public_header_enrollment_authority_changed/);
assert.match(publication, /intake_state_restore/);
assert.match(publication, /stage_first_public_header_enrollment_transaction[\s\S]*lock_localized_menu_projection_surface[\s\S]*theme_mods_[\s\S]*FOR UPDATE[\s\S]*devenia_workflow_public_header_enrollment_before_locked_stage_revalidation/);
assert.match(publication, /reconcile_first_public_header_enrollment_commit_outcome[\s\S]*! \$pre_state_proven && \$applied_state_proven[\s\S]*foreign_state_observed[\s\S]*public_header_enrollment_commit_reconciliation_conflict[\s\S]*public_header_enrollment_commit_outcome_unknown_conflict[\s\S]*public_header_enrollment_commit_reconciliation_failed/, "reconciliation may restore only this operation's exact expected-after state and must preserve foreign state");
assert.match(publication, /current_is_before[\s\S]*current_is_owned_staging[\s\S]*activation_severe[\s\S]*public_header_enrollment_severe_rollback_not_bypassed[\s\S]*public_header_enrollment_intake_restore_conflict/, "post-activation intake recovery must accept exact before, restore only the receipt-bound staging state, and preserve foreign or severe state");
assert.match(publication, /expected_state_revision[\s\S]*translation_job_canonicalize\( \$expected_state \)/, "first-enrollment stage must bind its exact owned staging state receipt");
assert.match(publication, /owned_staging_receipt_valid[\s\S]*hash_equals\( \(string\) \$stage\['expected_state_revision'\], \$owned_staging_revision \)[\s\S]*current_is_owned_staging/, "post-activation restore must validate the stage-state receipt before CAS");
assert.match(publication, /\$current_is_owned_staging && self::public_header_state_excludes_staged_menu_ids\( \$after_restore,[\s\S]*true \)/, "severe cleanup may use only the exact receipt-valid owned staging state, never arbitrary foreign state");
assert.match(publication, /__devenia_workflow_option_missing__' === \$identities[\s\S]*return \$exact_owned_state/, "an exact owned staging receipt must prove its intentionally absent identity slot excludes staged menu references");
assert.match(publication, /reconcile_public_header_state_commit_outcome[\s\S]*false === \$committed[\s\S]*true === \$committed[\s\S]*null === \$committed[\s\S]*state_outcome[\s\S]*foreign/, "activation commit must preserve three-valued applied, unapplied, and foreign outcomes");
assert.match(publication, /devenia_workflow_public_header_state_commit_adapter_result[\s\S]*clear_public_header_state_option_cache\(\)[\s\S]*return self::reconcile_public_header_state_commit_outcome/, "every activation COMMIT receipt, including success=true/committed=true, must cross cache-cleared exact reconciliation");
assert.match(publication, /reconcile_public_header_state_commit_outcome[\s\S]*\$state_class = \$expected_exact \? 'expected'[\s\S]*true === \( \$commit\['success'\][\s\S]*public_header_state_commit_applied[\s\S]*public_header_state_commit_reconciliation_conflict/, "activation success requires the exact owned replacement while expected and foreign post-COMMIT state remain classified");
assert.match(publication, /devenia_workflow_public_header_enrollment_commit_adapter_result[\s\S]*clear_first_public_header_enrollment_option_cache[\s\S]*reconcile_first_public_header_enrollment_commit_outcome/, "every first-enrollment COMMIT receipt must cross cache-cleared exact reconciliation");
assert.match(publication, /public_header_activation_cleanup_authority[\s\S]*clear_public_header_state_option_cache[\s\S]*receipt_current_exact[\s\S]*outcome_unapplied[\s\S]*public_header_state_excludes_staged_menu_ids/, "staged cleanup requires a fresh exact unapplied-state proof and no identity references");
assert.match(publication, /delete_staged_public_header_projections[\s\S]*translation_job_begin_recovery_transaction[\s\S]*lock_localized_menu_projection_surface[\s\S]*FOR UPDATE[\s\S]*public_header_state_excludes_staged_menu_ids[\s\S]*devenia_workflow_public_header_staged_cleanup_before_locked_revalidation[\s\S]*staged_projection_cleanup_identity_changed[\s\S]*wp_delete_nav_menu[\s\S]*translation_job_commit_recovery_transaction/, "identity proof, receipt revalidation, and every staged-menu deletion must share one owned transaction and fail closed on the deterministic race Seam");
assert.match(publication, /public_header_projection_activation_state_unresolved/, "applied, unknown, or foreign activation state must remain structured critical without cleanup");
assert.match(publication, /clear_first_public_header_enrollment_option_cache[\s\S]*alloptions[\s\S]*notoptions/, "transaction rollback must evict stale theme and option cache values");
assert.match(publication, /pre_enrollment_public_header_recovery_snapshot[\s\S]*array\( 'origin', 'canonical' \)[\s\S]*expected_navigation/);
assert.match(publication, /verify_pre_enrollment_public_header_navigation[\s\S]*array\( 'origin', 'canonical' \)[\s\S]*primary_navigation_from_html[\s\S]*\$actual === \$expected/, "pre-enrollment recovery must use a dedicated exact raw-navigation verifier");
assert.match(publication, /\$rollback_receipts\['pre_enrollment'\][\s\S]*verify_pre_enrollment_public_header_navigation[\s\S]*verify_public_header_projection_set/, "only pre-enrollment rollback may bypass forward managed-menu integrity rules");
assert.match(publication, /delete_staged_public_header_projections[\s\S]*staged_menu_receipt_mismatch[\s\S]*staged_menu_delete_failed/);
for (const provenance of ["explicit_authority", "retained_relation_match"]) {
  assert.match(publication, new RegExp(provenance), `first enrollment must preserve ${provenance} provenance`);
  assert.match(publicHeaderRuntime, new RegExp(provenance), `runtime must assert ${provenance} provenance behaviorally`);
}
assert.doesNotMatch(publication.slice(publication.indexOf("private static function enroll_public_header_from_existing_menus"), publication.indexOf("private static function migrate_public_header_label_authority")), /get_the_title/, "first enrollment must never infer labels from page titles");
assert.match(publication, /public_header_editorial_label_snapshot/);
assert.match(publication, /authority_candidate_conflict/);
assert.match(publication, /insufficient_independent_authority_candidates/);
assert.match(publication, /generated_label_drift/);
for (const provenance of ["known_identity", "configured_name", "retained_relation_match"]) {
  assert.match(publication, new RegExp(`resolved_from[\\s\\S]*${provenance}`), `migration evidence must preserve ${provenance} provenance`);
}
assert.doesNotMatch(publication.slice(publication.indexOf("private static function migrate_public_header_label_authority"), publication.indexOf("private static function update_public_header_manifest")), /get_the_title/, "label-authority migration must never infer editorial labels from content titles");
assert.match(publication, /\$item\['labels'\]\[ \$language \]/);
const planStart = publication.indexOf("private static function public_header_projection_plan");
const planEnd = publication.indexOf("private static function public_blog_archive_url_for_language", planStart);
const plan = publication.slice(planStart, planEnd);
assert.doesNotMatch(plan, /get_the_title|localized_menu_item_title/, "projection labels must never fall back to page titles or mutable runtime replacements");
assert.match(publication, /cancelled_pending/);
assert.doesNotMatch(sync, /wp_get_nav_menu_items\( \$source_menu/, "raw source menus must never be projection authority");
assert.doesNotMatch(publication, /resolved_from'\s*=> 'primary_theme_location'/, "raw primary theme location must never become an authoritative identity");
assert.match(identityReader, /private static function localized_menu_id\( string \$language \): int[\s\S]*OPTION_LOCALIZED_MENU_IDENTITIES[\s\S]*public_header_manifest[\s\S]*TERM_META_PUBLIC_HEADER_MANIFEST_REVISION[\s\S]*return 0;/, "ordinary identity reads must accept only exact stored active-manifest authority");
assert.doesNotMatch(identityReader, /update_option|add_option|delete_option|wp_get_nav_menus|menu_name|migrated_at|duplicate_ids/, "ordinary identity reads must be side-effect free and must never rediscover authority by configured name");
assert.doesNotMatch(plugin + publication, /localized_menu_id\([^\)]*,/, "ordinary callers must not retain a migration switch on the identity reader");
assert.match(plugin, /use_language_primary_menu[\s\S]*localized_menu_id\( \$language \)/);
assert.match(plugin, /add_filter\( 'pre_wp_nav_menu', array\( __CLASS__, 'fail_closed_primary_menu_markup' \)/);
assert.match(plugin, /fail_closed_primary_menu_markup[\s\S]*public_header_projection_is_enrolled[\s\S]*localized_menu_id\( \$language \)[\s\S]*: ''/);
assert.match(plugin, /is_language_menu_already_selected[\s\S]*localized_menu_id\( \$language \)/);
assert.match(plugin, /\$language_menu_already_selected[\s\S]*return \$items;/, "an already-selected managed projection must be final reader authority");
assert.doesNotMatch(plugin.slice(plugin.indexOf("public static function use_language_primary_menu"), plugin.indexOf("public static function localize_nav_menu_objects")), /['"]en['"]\s*===|===\s*['"]en['"]/, "source menu selection must be registry-driven");

assert.match(publication, /array\( 'origin', 'canonical' \)/);
assert.match(publication, /Callers cannot opt out[\s\S]*\$live = self::verify_live_translation/);
assert.doesNotMatch(publication, /if \( ! empty\( \$input\['verify_live'\] \) \)/, "localized publication must not permit verification opt-out");
assert.match(jobs, /'verify_live'\s*=> true/);
assert.match(publication, /'cf_cache_status'/);
assert.match(publication, /'age'/);
assert.match(publication, /frontend_primary_menu_projection_mismatch/);
assert.match(publication, /expected_localized_primary_navigation[\s\S]*public_header_projection_plan/);
assert.match(publication, /if \( \$actual === \$expected \)/);
assert.match(publication, /sync_public_header_projection[\s\S]*'homepage'[\s\S]*'blog_archive'/);
assert.match(publication, /public_header_failure_after_activation[\s\S]*public_header_projection_rollback[\s\S]*verify_public_header_projection_set[\s\S]*public_header_projection_severe_rollback_failure/);
assert.match(publicHeaderRuntime, /verification_fault_remaining > 0[\s\S]*--\$verification_fault_remaining/, "verification faults must be scoped to the failing verification and must not poison rollback verification");
assert.match(publication, /TERM_META_PUBLIC_HEADER_MANIFEST_REVISION/);
assert.match(publication, /localized_menu_items_in_render_order[\s\S]*\$append\( \$item_id \)/, "nested menus must be compared in WordPress walker depth-first order");
assert.match(plugin, /frontend_integrity_language_filter[\s\S]*source_language_code\(\)[\s\S]*target_languages/);
assert.match(plugin, /frontend_integrity_surface_filter[\s\S]*blog_archives/);

for (const evidence of [
	"real_theme_location_pre_enrollment_preserved",
	"real_theme_location_managed_source_exercised",
	"wp_nav_menu_args_managed_source_exercised",
	"editorial_labels_bound_by_source_item_identity",
	"source_short_label_not_page_title",
	"target_editorial_label_not_translated_page_title",
	"custom_child_label_and_parent_preserved",
	"missing_label_authority_preserved_old_active_set",
	"schema1_managed_label_runtime_override_bypassed",
	"schema1_label_authority_conflict_failed_closed",
	"schema1_to_schema2_migration_draft_created",
	"schema1_post_activation_rollback_verified",
	"schema1_to_schema2_repair_activated",
	"unenrolled_authority_draft_mutation_free",
	"unenrolled_raw_navigation_oracle_exact",
	"unenrolled_commit_outcomes_reconciled",
	"unenrolled_unknown_commit_outcome_structured_critical",
	"unenrolled_foreign_commit_state_preserved",
	"unenrolled_post_activation_foreign_state_preserved",
	"unenrolled_severe_rollback_reference_closure_proven",
	"activation_commit_tristate_cleanup_authority_proven",
	"successful_header_commits_reconciled_before_activation",
	"staged_cleanup_identity_reference_race_failed_closed",
	"content_publication_commit_tristate_proven",
	"content_publication_applied_commit_refreshed_verified_header",
	"malformed_first_enrollment_commit_receipt_failed_closed",
	"malformed_header_activation_commit_receipt_failed_closed",
	"malformed_content_publication_commit_receipt_failed_closed",
	"non_array_header_commit_receipt_rolled_back_failed_closed",
	"unterminated_success_header_commit_receipt_rolled_back_failed_closed",
	"pending_foreign_race_preserved_then_fixture_cleaned",
	"unenrolled_unrelated_menus_ignored",
	"unenrolled_locked_primary_source_authority_races_rejected",
	"unenrolled_all_post_activation_failure_phases_recovered",
	"unenrolled_failure_restored_exact_option_state",
	"unenrolled_schema2_atomic_activation_verified",
	"managed_identity_missing_failed_closed",
	"managed_identity_corrupt_failed_closed",
	"managed_identity_missing_verification_failed_without_mutation",
	"managed_identity_corrupt_verification_failed_without_mutation",
	"identity_migration_interface_capability_gated",
	"missing_active_manifest_durable_enrollment_failed_closed",
	"pending_manifest_preserved_old_active_set",
	"active_restage_cancelled_stale_pending",
	"missing_target_projection_failed_closed",
	"all_language_atomic_activation_exercised",
	"pre_activation_receipt_failure_rejected",
	"staged_revision_race_rejected",
	"pending_manifest_race_rejected",
	"invalidation_failure_cache_safe_rollback",
	"idempotent_enrollment_transition_exercised",
	"verification_failure_cache_safe_rollback",
	"extra_anchor_verification_failed_closed",
	"retirement_failure_cache_safe_rollback",
	"rollback_cache_failure_structured_critical",
	"changed_old_receipt_never_reactivated",
	"source_and_targets_home_blog_origin_canonical_verified",
]) {
	assert.match(publicHeaderRuntime, new RegExp(evidence));
}
assert.match(publicHeaderRuntime, /public_header_enrollment_commit_receipt_invalid[\s\S]*missing_committed[\s\S]*public_header_state_commit_receipt_invalid[\s\S]*missing_committed[\s\S]*publication_transaction_commit_receipt_invalid[\s\S]*missing_committed/, "the real WordPress runtime must reject a committed transaction whose Adapter receipt omits committed at all three Public Header publication seams");
assert.match(publicHeaderRuntime, /'non_array' === \$activation_commit_mode[\s\S]*return false;[\s\S]*'unterminated_success' === \$activation_commit_mode[\s\S]*'success' => true, 'committed' => true/, "the runtime must inject both a present non-array receipt and a structurally valid receipt that leaves the owned transaction open");
assert.match(publicHeaderRuntime, /missing_committed[\s\S]*transaction_not_terminal[\s\S]*recovery_commit_adapter_receipt_not_array[\s\S]*adapter_receipt_type[\s\S]*transaction_rolled_back/, "non-terminal Adapter receipts must be normalized, rejected, and terminalized by owned rollback before any forward Public Header work");

for (const evidence of [
	"translation_publish_preserved_seeded_menu_identity",
	"atomic_menu_failure_preserved_active_identity",
	"ordinary_identity_reader_failed_closed_without_migration",
	"frontend_cache_invalidation_adapter_consumed",
	"canonical_english_menu_cache_rejected",
	"foreign_publication_revision_never_became_rollback_authority",
	"foreign_publication_true_and_unknown_preserved_job_content_header",
]) {
	assert.match(runtime, new RegExp(evidence));
}

console.log("Localized presentation publication contract OK");
