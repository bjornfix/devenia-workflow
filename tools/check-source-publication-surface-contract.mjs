#!/usr/bin/env node

import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

const root = new URL("../", import.meta.url);
const [main, surface, inventory, jobs, quality, publication, repair, runtime, context, adr] = await Promise.all([
	readFile(new URL("devenia-workflow.php", root), "utf8"),
	readFile(new URL("includes/trait-source-publication-surface.php", root), "utf8"),
	readFile(new URL("includes/trait-source-inventory.php", root), "utf8"),
	readFile(new URL("includes/trait-translation-job.php", root), "utf8"),
	readFile(new URL("includes/trait-translation-job-quality-authority.php", root), "utf8"),
	readFile(new URL("includes/trait-localized-presentation-publication.php", root), "utf8"),
	readFile(new URL("includes/trait-featured-image-repair.php", root), "utf8"),
	readFile(new URL("tools/check-translation-job-runtime.php", root), "utf8"),
	readFile(new URL("CONTEXT.md", root), "utf8"),
	readFile(new URL("docs/adr/0007-source-publication-surface-media-coherence.md", root), "utf8"),
]);

assert.match(main, /trait-source-publication-surface\.php/);
assert.match(main, /use Devenia_Workflow_Source_Publication_Surface/);
for (const hook of ["added_post_meta", "updated_post_meta", "deleted_post_meta", "edit_attachment", "delete_attachment"]) {
	assert.match(main, new RegExp(`add_action\\( '${hook}'`));
}
assert.match(surface, /function source_publication_surface_manifest/);
assert.match(surface, /function source_publication_surface_revision/);
assert.match(surface, /function publication_featured_image_identity/);
for (const member of ["attachment_id", "metadata_digest", "attachment_revision_diagnostic", "source_alt", "file_identity", "sha256", "size", "mtime", "unavailable_reason"]) {
	assert.match(surface, new RegExp(`'${member}'`));
}
assert.match(surface, /hash_file\( 'sha256'/);
assert.match(surface, /publication_file_sample_is_stable/);
assert.match(surface, /attachment_file_changed_during_hash/);
assert.match(inventory, /source_publication_surface_revision\( \$post \)/);
assert.match(inventory, /'visible_media_stale'/);
assert.match(inventory, /publication_featured_image_revision_identity\( \$source \)/);
assert.match(inventory, /publication_featured_image_revision_identity\( \$translation_id \)/);
assert.match(inventory, /translation_job_validate_published_authority/);
assert.match(inventory, /publication_authority_stale/);
assert.match(inventory, /source_inventory_refresh_dirty_state\( \$manifest \)/);
assert.match(jobs, /'featured_image_alt' => array\( 'type' => 'string' \)/);
assert.match(jobs, /source_publication_surface_revision\( \$source \)/);
assert.match(jobs, /'publication_surface' => self::source_publication_surface_manifest/);
assert.match(jobs, /evidence_matches_publication_surface/);
assert.match(quality, /source_featured_image_identity_unavailable/);
assert.match(quality, /'featured_image'\s*=> \$featured_image_identity/);
assert.match(quality, /publication_featured_image_revision_identity\( \$translation_id \)/);
assert.match(quality, /featured_image_identity/);
assert.match(quality, /featured_image_byte_identity_verification/);
assert.match(quality, /rollback_featured_image_verification_failed/);
assert.match(quality, /function translation_job_validate_published_authority/);
assert.match(publication, /function frontend_featured_image_html_issues/);
assert.match(publication, /frontend_featured_image_identity_mismatch/);
assert.match(publication, /none_without_featured_image/);
assert.match(publication, /'token_count' => count\( \$parts \)/);
assert.match(publication, /2 !== absint\( \$candidate\['token_count'\]/);
assert.match(publication, /\$parse_success && 0 === \$hero_element_count/);
assert.match(publication, /\$parse_success && 0 === \$open_graph_element_count/);
assert.match(publication, /catch \( Throwable \$error \)[\s\S]*\$loaded = false/);
assert.match(runtime, /extra_token_media_issues/);
assert.match(runtime, /empty_candidate_media_issues/);
assert.match(runtime, /no_image_empty_hero_issues/);
assert.match(runtime, /no_image_empty_og_issues/);
assert.match(runtime, /no_image_parser_unavailable_issues/);
assert.match(runtime, /no_image_parse_failure_issues/);
assert.match(publication, /verify_frontend_featured_image_for_url/);
assert.match(publication, /foreach \( array\( 'origin', 'canonical' \) as \$cache_surface \)[\s\S]*frontend_featured_image_html_issues/);
assert.doesNotMatch(repair, /translation_step_token_gate/);
const repairIntake = repair.slice(repair.indexOf("private static function repair_featured_images("), repair.indexOf("private static function sync_source_featured_image("));
assert.doesNotMatch(repairIntake, /sync_source_featured_image\(/);
assert.match(repair, /current_user_can\( 'manage_options' \)/);
assert.match(repair, /translation_index_ids\(/);
assert.match(repair, /translation_job_discover/);
assert.match(repair, /repair_visible_media_drift/);
assert.match(context, /## Source Publication Surface/);
assert.match(adr, /Source Publication Surface Module/);

console.log(JSON.stringify({ success: true, checks: 61 }, null, 2));
