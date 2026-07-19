#!/usr/bin/env node
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const read = (file) => readFileSync(join(root, file), "utf8");
const plugin = read("devenia-workflow.php");
const abilityPlatform = read("includes/trait-ability-platform.php");
const publication = read("includes/trait-localized-presentation-publication.php");
const quality = read("includes/trait-translation-job-quality-authority.php");
const sourceDesign = read("includes/trait-source-design-inheritance.php");

assert.doesNotMatch(plugin, /\$suspend_direct_save_storage_guardrails/, "storage protection must not use a process-wide boolean bypass");
assert.match(plugin, /with_direct_save_storage_guardrails_suspended\( int \$post_id, callable \$callback \)/, "trusted mutation context must require an exact post ID");
assert.match(plugin, /direct_save_storage_guardrails_suspended_for\( int \$post_id \)/, "guard checks must resolve one post-bound context");
assert.match(plugin, /direct_save_storage_guardrails_suspended_for\( \$post_id \)/, "storage hooks must check the post-bound context");

assert.match(abilityPlatform, /ability_operation_permission\( string \$operation \)/, "ability authorization must have one operation policy seam");
assert.match(abilityPlatform, /isset\( self::ability_operation_handlers\(\)\[ \$operation \] \) && current_user_can\( 'manage_options' \)/, "unknown operations must fail closed and registered operations must require administrator authority");
assert.match(abilityPlatform, /\$annotations\['destructive'\] = ! \$readonly;/, "every state-changing ability must advertise destructive operator semantics");
assert.match(abilityPlatform, /\$args\['category'\] = 'devenia-workflow'/, "every ability must bind the registered Workflow category");
assert.doesNotMatch(abilityPlatform, /\$args\['operation'\]/, "internal dispatch identity must not leak into WordPress ability constructor properties");
assert.match(plugin, /wp_abilities_api_categories_init[\s\S]*register_ability_categories[\s\S]*wp_register_ability_category/, "the Workflow category must be registered on the WordPress category lifecycle");

assert.match(publication, /same_site_frontend_evidence_url[\s\S]*wp_http_validate_url[\s\S]*hash_equals\( \$site_host, \$target_host \)[\s\S]*hash_equals\( \$site_scheme, \$target_scheme \)[\s\S]*\$site_port !== \$target_port/, "frontend evidence must validate HTTP safety and exact site origin ownership");
assert.match(publication, /'redirects' => 0[\s\S]*'max_bytes' => self::FRONTEND_EVIDENCE_MAX_BYTES/, "concurrent evidence fetches must not follow redirects and must cap retained bytes");
assert.match(publication, /wp_safe_remote_get\( \$fetch_url, \$args \)/, "single evidence fetches must use WordPress safe HTTP transport");
assert.match(quality, /'adapter_revision' => self::VERSION/, "Quality receipts must bind the exact plugin release identity");
assert.match(quality, /limit_response_size' => self::FRONTEND_EVIDENCE_MAX_BYTES/, "Quality HTML evidence must cap retained bytes");

assert.doesNotMatch(sourceDesign, /GenerateBlocks|GENERATEBLOCKS_VERSION|generateblocks/, "theme-neutral source design must not own GenerateBlocks cache implementation");

console.log(JSON.stringify({
	success: true,
	contracts: 16,
	boundaries: ["release_identity", "post_bound_mutation_context", "ability_policy", "same_site_evidence_transport", "vendor_adapter_locality"],
}));
