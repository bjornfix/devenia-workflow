#!/usr/bin/env node
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const source = fs.readFileSync(path.join(root, "includes/trait-ability-catalogue.php"), "utf8");
const runtime = fs.readFileSync(path.join(root, "devenia-workflow.php"), "utf8");
const indexReadModel = fs.readFileSync(path.join(root, "includes/trait-translation-index-read-model.php"), "utf8");
const sourceDesignReview = fs.readFileSync(path.join(root, "includes/trait-source-design-review-policy.php"), "utf8");
const generateBlocksAdapter = fs.readFileSync(path.join(root, "addons/generateblocks.php"), "utf8");
const generatePressAdapter = fs.readFileSync(path.join(root, "addons/generatepress.php"), "utf8");
const required = [
  "devenia-workflow/frontend-integrity-status",
  "devenia-workflow/reproject-source-design",
  "devenia-workflow/mark-quality-reviewed",
];

const failures = required.filter((name) => !source.includes(`'${name}' => array(`));
if (!source.includes("trait Devenia_Workflow_Ability_Catalogue") || !source.includes("private static function ability_catalogue(): array")) {
  failures.push("ability catalogue seam");
}
if (!runtime.includes("private static function run_ability_operation")) {
  failures.push("ability operation seam");
}
if (runtime.includes("enqueue_frontend_heading_fit_assets") || runtime.includes("devenia-workflow-heading-fit")) {
  failures.push("frontend heading fit must not override GeneratePress typography");
}
const frontendPresentationSource = [runtime, generateBlocksAdapter, generatePressAdapter].join("\n");
if (/wp_(?:enqueue|add_inline)_style\s*\(/.test(frontendPresentationSource)) {
  failures.push("public Workflow must not enqueue or inject frontend presentation CSS");
}
const assetsDir = path.join(root, "assets");
if (fs.existsSync(assetsDir) && fs.readdirSync(assetsDir).some((file) => /\.css$/i.test(file))) {
  failures.push("public Workflow must not bundle frontend CSS assets");
}
for (const hook of ["generate_after_entry_title", "generate_menu_bar_items", "generate_logo_href", "generate_site_title_href", "generate_excerpt_more_output", "devenia_workflow_render_translated_posts_page_default_sidebar"]) {
  if (generatePressAdapter.includes(hook)) {
    failures.push(`GeneratePress presentation hook remains in public Workflow: ${hook}`);
  }
}
const qualityReviewStart = runtime.indexOf("private static function mark_quality_reviewed");
const qualityReviewEnd = runtime.indexOf("private static function mark_final_reviewed", qualityReviewStart);
const qualityReviewSource = runtime.slice(qualityReviewStart, qualityReviewEnd);
const qualityAuthorityCalls = qualityReviewSource.match(/translation_step_token_gate\( 'quality_review'/g) || [];
if (qualityAuthorityCalls.length !== 1 || !qualityReviewSource.includes("if ( self::is_translation_post( $page_id ) )")) {
  failures.push("whole-page quality authority must protect translations without blocking source-page review");
}
if (!qualityReviewSource.includes("$input['execution_id']") || !qualityReviewSource.includes("$input['reviewer']")) {
  failures.push("source-page quality review must preserve explicit execution and reviewer provenance");
}
for (const method of [
  "translation_index_available",
  "sync_translation_index_row",
  "translation_index_rows_for_source",
  "translation_frontend_rows_for_language",
  "frontend_rows_from_index_rows",
]) {
  if (!indexReadModel.includes(`function ${method}(`)) {
    failures.push(`translation index read model: ${method}`);
  }
  if (runtime.includes(`function ${method}(`)) {
    failures.push(`main runtime still owns: ${method}`);
  }
}

if (!sourceDesignReview.includes("! self::is_translatable_post_type( (string) $source->post_type )")) {
  failures.push("source design review must support every translatable post type");
}
if (sourceDesignReview.includes("'post' !== (string) $source->post_type")) {
  failures.push("source design review still hard-codes the post post type");
}

if (failures.length) {
  console.error(JSON.stringify({ success: false, failures }, null, 2));
  process.exit(1);
}

console.log(JSON.stringify({ success: true, contracts: required }, null, 2));
