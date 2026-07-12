#!/usr/bin/env node
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const source = fs.readFileSync(path.join(root, "includes/trait-ability-catalogue.php"), "utf8");
const runtime = fs.readFileSync(path.join(root, "devenia-ai-translations.php"), "utf8");
const indexReadModel = fs.readFileSync(path.join(root, "includes/trait-translation-index-read-model.php"), "utf8");
const sourceDesignReview = fs.readFileSync(path.join(root, "includes/trait-source-design-review-policy.php"), "utf8");
const required = [
  "ai-translations/frontend-integrity-status",
  "ai-translations/reserve-work",
  "ai-translations/release-reservation",
  "ai-translations/upsert-page",
  "ai-translations/reproject-source-design",
  "ai-translations/next-heartbeat-action",
	"ai-translations/accept-assignment",
	"ai-translations/current-assignment",
	"ai-translations/renew-assignment",
	"ai-translations/complete-assignment",
	"ai-translations/resolve-assignment-block",
];

const failures = required.filter((name) => !source.includes(`'${name}' => array(`));
if (!source.includes("trait Devenia_AI_Translations_Ability_Catalogue") || !source.includes("private static function ability_catalogue(): array")) {
  failures.push("ability catalogue seam");
}
if (!runtime.includes("private static function run_ability_operation")) {
  failures.push("ability operation seam");
}
for (const [file, contract] of [
	["includes/trait-work-item-planner.php", "trait Devenia_AI_Translations_Work_Item_Planner"],
	["includes/trait-assignment-lifecycle.php", "trait Devenia_AI_Translations_Assignment_Lifecycle"],
]) {
	const moduleSource = fs.readFileSync(path.join(root, file), "utf8");
	if (!moduleSource.includes(contract)) {
		failures.push(`deep module missing: ${contract}`);
	}
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
