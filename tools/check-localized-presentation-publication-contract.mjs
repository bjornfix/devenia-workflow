#!/usr/bin/env node
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const plugin = readFileSync(new URL("../devenia-workflow.php", import.meta.url), "utf8");
const publication = readFileSync(new URL("../includes/trait-localized-presentation-publication.php", import.meta.url), "utf8");
const jobs = readFileSync(new URL("../includes/trait-translation-job.php", import.meta.url), "utf8");
const runtime = readFileSync(new URL("./check-translation-job-runtime.php", import.meta.url), "utf8");

const syncStart = plugin.indexOf("private static function sync_language_menu");
const syncEnd = plugin.indexOf("private static function existing_menu_label_map", syncStart);
assert.ok(syncStart > 0 && syncEnd > syncStart, "sync_language_menu must remain a bounded implementation");
const sync = plugin.slice(syncStart, syncEnd);

assert.match(publication, /publish_localized_presentation[\s\S]*apply_translation_publish_transition[\s\S]*sync_language_menu[\s\S]*devenia_workflow_frontend_cache_invalidation_result[\s\S]*verify_live_translation/);
assert.match(jobs, /translation_job_publish[\s\S]*publish_localized_presentation/);
assert.match(plugin, /private static function publish_translation[\s\S]*publish_localized_presentation/);
assert.match(publication, /frontend_cache_adapter_missing/);
assert.match(publication, /true !== \( \$invalidation\['success'\]/);

assert.match(sync, /wp_create_nav_menu\( \$staging_name \)/);
assert.match(sync, /validate_localized_menu_projection[\s\S]*activate_localized_menu_id[\s\S]*retire_managed_localized_menu/);
assert.doesNotMatch(sync, /wp_delete_post\(/, "atomic projection must never delete active menu items before build");
assert.match(sync, /wp_delete_nav_menu\( \(int\) \$target_menu->term_id \)/, "failed staging projections must be cleaned up");

assert.match(publication, /OPTION_LOCALIZED_MENU_IDENTITIES/);
assert.match(publication, /duplicate_ids/);
assert.match(plugin, /use_language_primary_menu[\s\S]*localized_menu_id\( \$language \)/);
assert.match(plugin, /is_language_menu_already_selected[\s\S]*localized_menu_id\( \$language \)/);

assert.match(publication, /array\( 'origin', 'canonical' \)/);
assert.match(publication, /Callers cannot opt out[\s\S]*\$live = self::verify_live_translation/);
assert.doesNotMatch(publication, /if \( ! empty\( \$input\['verify_live'\] \) \)/, "localized publication must not permit verification opt-out");
assert.match(jobs, /'verify_live'\s*=> true/);
assert.match(publication, /'cf_cache_status'/);
assert.match(publication, /'age'/);
assert.match(publication, /frontend_primary_menu_projection_mismatch/);
assert.match(publication, /expected_localized_primary_navigation[\s\S]*localized_menu_items_in_render_order/);
assert.match(publication, /expected_localized_primary_navigation[\s\S]*effective_localized_menu_item_title/);
assert.match(publication, /effective_localized_menu_item_title[\s\S]*localized_menu_item_title/);
assert.match(publication, /localized_menu_items_in_render_order[\s\S]*\$append\( \$item_id \)/, "nested menus must be compared in WordPress walker depth-first order");

for (const evidence of [
	"real_sync_menu_publication_exercised",
	"atomic_menu_failure_preserved_active_identity",
	"stable_menu_identity_migrated",
	"frontend_cache_invalidation_adapter_consumed",
	"canonical_english_menu_cache_rejected",
]) {
	assert.match(runtime, new RegExp(evidence));
}

console.log("Localized presentation publication contract OK");
