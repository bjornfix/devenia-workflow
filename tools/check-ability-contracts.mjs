#!/usr/bin/env node
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const source = fs.readFileSync(path.join(root, "includes/trait-ability-catalogue.php"), "utf8");
const runtime = fs.readFileSync(path.join(root, "devenia-ai-translations.php"), "utf8");
const indexReadModel = fs.readFileSync(path.join(root, "includes/trait-translation-index-read-model.php"), "utf8");
const required = [
  "ai-translations/frontend-integrity-status",
  "ai-translations/reserve-work",
  "ai-translations/release-reservation",
  "ai-translations/upsert-page",
  "ai-translations/reproject-source-design",
  "ai-translations/next-heartbeat-action",
];

const failures = required.filter((name) => !source.includes(`'${name}' => array(`));
if (!source.includes("trait Devenia_AI_Translations_Ability_Catalogue") || !source.includes("private static function ability_catalogue(): array")) {
  failures.push("ability catalogue seam");
}
if (!runtime.includes("private static function run_ability_operation")) {
  failures.push("ability operation seam");
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

if (failures.length) {
  console.error(JSON.stringify({ success: false, failures }, null, 2));
  process.exit(1);
}

console.log(JSON.stringify({ success: true, contracts: required }, null, 2));
