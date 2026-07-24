#!/usr/bin/env node

import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const plugin = readFileSync(join(root, "devenia-workflow.php"), "utf8");
const authority = readFileSync(join(root, "includes/trait-source-rewrite-quality-authority.php"), "utf8");
const readerEquivalence = readFileSync(join(root, "includes/trait-reader-surface-equivalence.php"), "utf8");
const previewCapability = readFileSync(join(root, "includes/trait-staged-preview-capability.php"), "utf8");
const priming = readFileSync(join(root, "includes/trait-copy-quality-priming.php"), "utf8");
const abilities = readFileSync(join(root, "includes/trait-ability-catalogue.php"), "utf8");
const dispatch = readFileSync(join(root, "includes/trait-ability-platform.php"), "utf8");
const translation = readFileSync(join(root, "includes/trait-translation-job.php"), "utf8");
const uninstall = readFileSync(join(root, "uninstall.php"), "utf8");

const lifecycle = [
  "discover",
  "claim",
  "abandon",
  "fetch-packet",
  "submit-artifact",
  "submit-quality-decision",
  "reopen-quality",
  "publish",
  "verify-live",
  "status",
];

for (const operation of lifecycle) {
  assert.match(authority, new RegExp(`'source-rewrite-${operation}'`), `missing source rewrite ability ${operation}`);
  assert.match(authority, new RegExp(`source_rewrite_${operation.replaceAll("-", "_")}`), `missing source rewrite handler ${operation}`);
}

assert.match(abilities, /self::source_rewrite_ability_catalogue\(\)/);
assert.match(dispatch, /self::source_rewrite_dispatch_handlers\(\)/);
assert.match(plugin, /trait-copy-quality-priming\.php[\s\S]*trait-staged-preview-capability\.php[\s\S]*trait-source-rewrite-quality-authority\.php/);
assert.match(plugin, /use Devenia_Workflow_Copy_Quality_Priming;[\s\S]*use Devenia_Workflow_Staged_Preview_Capability;[\s\S]*use Devenia_Workflow_Source_Rewrite_Quality_Authority;/);
assert.match(plugin, /trait-reader-surface-equivalence\.php/);
assert.match(plugin, /use Devenia_Workflow_Reader_Surface_Equivalence;/);
assert.match(plugin, /mcp_content_write_preflight[\s\S]*validate_source_rewrite_quality_preflight/);
assert.match(plugin, /wp_insert_post_data[\s\S]*guard_unapproved_source_rewrite_before_save[\s\S]*8, 4/);
assert.doesNotMatch(plugin, /Source_Rebuild_Loss_Guard|source-rebuild-loss-guard/);

assert.match(authority, /source_rewrite_publish_authority[\s\S]*source_rewrite_request_authorizes/);
assert.match(authority, /source_rewrite_reopen_quality[\s\S]*published_artifact_drifted/);
assert.match(authority, /quality_recheck_history[\s\S]*prior_quality_revision/);
assert.match(authority, /published_artifact_requality/);
assert.match(authority, /review_cycle[\s\S]*SOURCE_REWRITE_MAX_RUNS_PER_ROLE/);
assert.match(authority, /requality_job_not_latest[\s\S]*requality_latest_race_rollback_failed/);
assert.match(authority, /requality_reopening[\s\S]*requality_activation_conflict/);
assert.match(authority, /source_rewrite_discover[\s\S]*source_rewrite_acquire_source_transition_lease/);
assert.match(authority, /source_rewrite_reopen_quality[\s\S]*source_rewrite_acquire_source_transition_lease/);
assert.match(authority, /source_rewrite_publish[\s\S]*mcp_expose_validate_content_write_policy\([\s\S]*wp_update_post/);
assert.doesNotMatch(authority, /apply_filters\(\s*['"]mcp_content_write_preflight['"]/);
assert.match(authority, /source_rewrite_verify_live[\s\S]*origin[\s\S]*canonical[\s\S]*source_rewrite_quality_passed/);
assert.match(authority, /reader_surface_action_values\( \$decoded_body \)[\s\S]*reader_surface_action_identity[\s\S]*in_array\( \$text,[\s\S]*true \)/);
assert.doesNotMatch(authority, /source_rewrite_reader_action_document/);
assert.match(readerEquivalence, /reader_surface_action_values[\s\S]*reader_surface_action_identity[\s\S]*reader_surface_decode_cloudflare_email_protection/);
assert.match(readerEquivalence, /new WP_HTML_Tag_Processor\( \$html \)[\s\S]*next_tag\(\)[\s\S]*get_attribute\( \$attribute \)/);
assert.match(readerEquivalence, /normalized_comparable_url[\s\S]*normalized_url_path/);
assert.match(authority, /source_rewrite_pending_for_source/);
assert.match(authority, /SOURCE_REWRITE_MAX_RUNS_PER_ROLE/);
assert.match(authority, /SOURCE_REWRITE_MAX_SUBMISSION_GENERATIONS/);
assert.match(authority, /source_rewrite_discover[\s\S]*'priming_context'\s*=>\s*self::source_rewrite_priming_context\( \$source \)/);
assert.match(authority, /source_rewrite_role_priming[\s\S]*\$job\['priming_context'\][\s\S]*source_rewrite_priming_context\( \$source \)/);
assert.doesNotMatch(authority, /\b41811\b|devenia\.com\/plugins\/devenia-workflow/);
assert.match(uninstall, /devenia_workflow_source_rewrite_/);

assert.match(priming, /Rolls-Royce, 1958[\s\S]*Hathaway shirts, 1951[\s\S]*Guinness Guide to Oysters, 1950[\s\S]*Dove cleansing bar, 1950s/);
assert.match(priming, /Ogilvy on Advertising[\s\S]*Confessions of an Advertising Man[\s\S]*The Unpublished David Ogilvy[\s\S]*David Ogilvy Papers/);
assert.match(priming, /If the legal source is inaccessible or the doubt remains unresolved, fail closed/);
assert.match(priming, /Do not translate mechanically/);
assert.match(priming, /Reject the page if it is fluent but interchangeable/);
assert.match(priming, /Design is not decoration[\s\S]*helps the reader understand[\s\S]*without nagging/);
assert.match(priming, /text wall[\s\S]*decorative card/);
assert.match(priming, /information_architecture_examples[\s\S]*dense sequential process[\s\S]*proof hierarchy[\s\S]*secondary technical detail[\s\S]*short content/);
assert.match(priming, /devenia_workflow_copy_quality_site_policy/);
assert.match(priming, /site_policy/);
assert.doesNotMatch(priming, /Devenia Workflow binds|devenia_contrast|devenia_transfer/);
assert.match(authority, /rendered_information_architecture_assessment/);
assert.match(authority, /proposed_design_validation/);
assert.match(authority, /quality_proposed_design_gate_failed/);
assert.match(authority, /source_rewrite_transition_after_publish_preflight_failure/);
assert.match(authority, /publication_preflight_rejected[\s\S]*changes_requested/);
assert.match(plugin, /the_posts[\s\S]*filter_source_rewrite_preview_posts/);
assert.match(plugin, /add_action\(\s*'template_redirect',\s*array\(\s*__CLASS__,\s*'apply_source_rewrite_preview_response_policy'\s*\),\s*0\s*\)/);
assert.match(authority, /source_rewrite_preview_descriptor[\s\S]*staged_preview_capability_token/);
assert.match(previewCapability, /staged_preview_capability_token[\s\S]*hash_hmac[\s\S]*wp_salt/);
assert.match(authority, /source_rewrite_preview_authority[\s\S]*quality_claimed[\s\S]*expires_at/);
assert.match(authority, /source_rewrite_validate_browser_receipts[\s\S]*desktop:light[\s\S]*mobile:dark/);
assert.match(authority, /source_rewrite_validate_browser_receipts[\s\S]*response_digest[\s\S]*document_language[\s\S]*document_direction/);
assert.match(authority, /source_rewrite_preview_request_matches[\s\S]*staged_preview_request_matches_id/);
assert.match(previewCapability, /staged_preview_request_matches_id[\s\S]*\$GLOBALS\['wp'\][\s\S]*query_vars[\s\S]*page_id[\s\S]*post_id[\s\S]*expected_id/);
assert.match(previewCapability, /staged_preview_query_token[\s\S]*\$query->get\( \$query_var \)/);
assert.match(authority, /filter_source_rewrite_preview_posts[\s\S]*staged_preview_query_token\( \$query, 'devenia_source_rewrite_preview' \)/);
assert.match(authority, /browser_receipts[\s\S]*preview_identity/);
assert.match(previewCapability, /staged_preview_prevent_page_cache[\s\S]*DONOTCACHEPAGE[\s\S]*WordPress\.NamingConventions\.PrefixAllGlobals\.NonPrefixedConstantFound/);
assert.match(previewCapability, /staged_preview_apply_response_policy[\s\S]*staged_preview_prevent_page_cache[\s\S]*remove_action\(\s*'template_redirect',\s*'redirect_canonical',\s*10\s*\)[\s\S]*! \$authorized[\s\S]*status_header\( 404 \)/);
assert.match(authority, /staged_preview_prevent_page_cache[\s\S]*X-Robots-Tag: noindex, nofollow, noarchive[\s\S]*Referrer-Policy: no-referrer/);
assert.match(authority, /apply_source_rewrite_preview_response_policy[\s\S]*staged_preview_apply_response_policy\( ! empty\( \$authority\['success'\] \) && self::source_rewrite_preview_request_matches\( \$authority \) \)/);
assert.doesNotMatch(authority, /define\(\s*'DONOTCACHEPAGE'/);
assert.doesNotMatch(authority, /add_query_arg\([^;]*claim_token/);

assert.match(translation, /translation_job_translation_packet[\s\S]*role_priming'\s*=>\s*self::translation_job_role_priming\( 'translator'/);
assert.match(translation, /translation_job_quality_packet[\s\S]*role_priming'\s*=>\s*self::translation_job_role_priming\( 'quality'/);
assert.match(translation, /translation_job_artifact_schema[\s\S]*required'\s*=>\s*array\([^)]*'priming_revision'/);
assert.match(translation, /translation_job_quality_schema[\s\S]*required'\s*=>\s*array\([^)]*'priming_revision'/);
assert.match(translation, /translation_job_quality_schema[\s\S]*rendered_information_architecture/);
assert.match(translation, /translation_job_required_reviewer_attestation_kinds/);
assert.match(translation, /reviewer_attestations'[\s\S]*translation_job_required_reviewer_attestation_kinds/);
assert.match(translation, /payload_example'[\s\S]*rendered_information_architecture/);
assert.match(translation, /publication-surface-contract-v3-rendered-information-architecture-quality/);
assert.match(translation, /publication-surface-contract-v5-rtl-grid-gap-rendered-information-architecture-quality/);
assert.match(translation, /translation_job_submit_artifact[\s\S]*translation_job_validate_priming_acknowledgement/);
assert.match(translation, /translation_job_submit_quality_decision[\s\S]*translation_job_validate_priming_acknowledgement/);
assert.match(translation, /translation_job_source_approval[\s\S]*source_rewrite_pending_for_source[\s\S]*source_rewrite_quality_passed/);

process.stdout.write("Workflow Source Rewrite Quality Authority contract passed.\n");
