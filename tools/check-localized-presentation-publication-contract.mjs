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

assert.match(publication, /publish_localized_presentation[\s\S]*apply_translation_publish_transition[\s\S]*refresh_public_header_projection_for_publication[\s\S]*devenia_workflow_frontend_cache_invalidation_result[\s\S]*verify_live_translation/);
assert.doesNotMatch(publication.slice(publication.indexOf("private static function publish_localized_presentation"), publication.indexOf("private static function rollback_localized_menu_projection")), /sync_language_menu\s*\(/, "normal publication must not activate one language directly");
assert.match(jobs, /translation_job_publish[\s\S]*publish_localized_presentation/);
assert.match(plugin, /private static function publish_translation[\s\S]*publish_localized_presentation/);
assert.match(publication, /frontend_cache_adapter_missing/);
assert.match(publication, /true !== \( \$invalidation\['success'\]/);

assert.match(sync, /wp_create_nav_menu\( \$staging_name \)/);
assert.match(sync, /validate_localized_menu_projection[\s\S]*activate_localized_menu_id[\s\S]*retire_managed_localized_menu/);
assert.ok(sync.indexOf("devenia_workflow_public_header_projection_receipt") < sync.indexOf("activate_localized_menu_id"), "the recovery receipt must be computed before activation");
assert.doesNotMatch(sync, /wp_delete_post\(/, "atomic projection must never delete active menu items before build");
assert.match(sync, /wp_delete_nav_menu\( \(int\) \$target_menu->term_id \)/, "failed staging projections must be cleaned up");

assert.match(publication, /OPTION_LOCALIZED_MENU_IDENTITIES/);
assert.match(publication, /OPTION_PUBLIC_HEADER_MANIFEST/);
assert.match(publication, /OPTION_PENDING_PUBLIC_HEADER_MANIFEST/);
assert.match(publication, /public_header_projection_plan/);
assert.match(sync, /public_header_projection_plan\( \$language/);
assert.match(publication, /sync_public_header_projection[\s\S]*pending_public_header_manifest[\s\S]*configured_public_header_languages[\s\S]*'stage_only'[\s\S]*activate_public_header_projection_set/);
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
assert.match(publication, /migrate_public_header_label_authority[\s\S]*current_user_can\( 'manage_options' \)/);
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
assert.match(publication, /duplicate_ids/);
assert.match(plugin, /use_language_primary_menu[\s\S]*localized_menu_id\( \$language, false \)/);
assert.match(plugin, /add_filter\( 'pre_wp_nav_menu', array\( __CLASS__, 'fail_closed_primary_menu_markup' \)/);
assert.match(plugin, /fail_closed_primary_menu_markup[\s\S]*public_header_projection_is_enrolled[\s\S]*localized_menu_id\( \$language, false \)[\s\S]*: ''/);
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
	"managed_identity_missing_failed_closed",
	"managed_identity_corrupt_failed_closed",
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

for (const evidence of [
	"translation_publish_preserved_seeded_menu_identity",
	"atomic_menu_failure_preserved_active_identity",
	"stable_menu_identity_migrated",
	"frontend_cache_invalidation_adapter_consumed",
	"canonical_english_menu_cache_rejected",
]) {
	assert.match(runtime, new RegExp(evidence));
}

console.log("Localized presentation publication contract OK");
