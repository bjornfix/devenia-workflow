#!/usr/bin/env node

import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const plugin = readFileSync(join(root, "devenia-workflow.php"), "utf8");
const authority = readFileSync(join(root, "includes/trait-source-rewrite-quality-authority.php"), "utf8");
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
assert.match(plugin, /trait-copy-quality-priming\.php[\s\S]*trait-source-rewrite-quality-authority\.php/);
assert.match(plugin, /use Devenia_Workflow_Copy_Quality_Priming;[\s\S]*use Devenia_Workflow_Source_Rewrite_Quality_Authority;/);
assert.match(plugin, /mcp_content_write_preflight[\s\S]*validate_source_rewrite_quality_preflight/);
assert.match(plugin, /wp_insert_post_data[\s\S]*guard_unapproved_source_rewrite_before_save[\s\S]*8, 4/);
assert.doesNotMatch(plugin, /Source_Rebuild_Loss_Guard|source-rebuild-loss-guard/);

assert.match(authority, /source_rewrite_publish_authority[\s\S]*source_rewrite_request_authorizes/);
assert.match(authority, /source_rewrite_publish[\s\S]*mcp_expose_validate_content_write_policy\([\s\S]*wp_update_post/);
assert.doesNotMatch(authority, /apply_filters\(\s*['"]mcp_content_write_preflight['"]/);
assert.match(authority, /source_rewrite_verify_live[\s\S]*origin[\s\S]*canonical[\s\S]*source_rewrite_quality_passed/);
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
assert.match(priming, /devenia_workflow_copy_quality_site_policy/);
assert.match(priming, /site_policy/);
assert.doesNotMatch(priming, /Devenia Workflow binds|devenia_contrast|devenia_transfer/);

assert.match(translation, /translation_job_translation_packet[\s\S]*role_priming'\s*=>\s*self::translation_job_role_priming\( 'translator'/);
assert.match(translation, /translation_job_quality_packet[\s\S]*role_priming'\s*=>\s*self::translation_job_role_priming\( 'quality'/);
assert.match(translation, /translation_job_artifact_schema[\s\S]*required'\s*=>\s*array\([^)]*'priming_revision'/);
assert.match(translation, /translation_job_quality_schema[\s\S]*required'\s*=>\s*array\([^)]*'priming_revision'/);
assert.match(translation, /translation_job_submit_artifact[\s\S]*translation_job_validate_priming_acknowledgement/);
assert.match(translation, /translation_job_submit_quality_decision[\s\S]*translation_job_validate_priming_acknowledgement/);
assert.match(translation, /translation_job_source_approval[\s\S]*source_rewrite_pending_for_source[\s\S]*source_rewrite_quality_passed/);

process.stdout.write("Workflow Source Rewrite Quality Authority contract passed.\n");
