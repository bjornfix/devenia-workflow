#!/usr/bin/env node
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const base = path.dirname(path.dirname(fileURLToPath(import.meta.url)));

const languageProfileRuleFields = [
  "review_patterns",
  "naturalness_patterns",
  "script_signals",
  "source_carryover_homographs",
  "shadow_exclusions",
  "shadow_context_exclusions",
];

const runtimeScriptSignalOptions = [
  "infer_text_shadow_terms",
  "shadow_context_exclusions",
];

const issues = [];

function readJson(relativePath) {
  const absolutePath = path.join(base, relativePath);
  try {
    return JSON.parse(fs.readFileSync(absolutePath, "utf8"));
  } catch (error) {
    issues.push({
      file: relativePath,
      code: "invalid_json",
      message: error instanceof Error ? error.message : String(error),
    });
    return null;
  }
}

for (const fileName of fs.readdirSync(path.join(base, "languages")).filter((name) => name.endsWith(".json")).sort()) {
  const relativePath = path.join("languages", fileName);
  const decoded = readJson(relativePath);
  if (!decoded || typeof decoded !== "object") {
    continue;
  }

  const profile = decoded.language_profile && typeof decoded.language_profile === "object"
    ? decoded.language_profile
    : {};
  for (const field of languageProfileRuleFields) {
    if (Object.prototype.hasOwnProperty.call(profile, field)) {
      issues.push({
        file: relativePath,
        code: "language_quality_rule_in_packaged_language_file",
        field: `language_profile.${field}`,
      });
    }
  }
}

const registryPath = "quality-rules/language-quality.json";
const registry = readJson(registryPath);
if (registry && typeof registry === "object") {
  const registryLanguages = registry.languages && typeof registry.languages === "object"
    ? registry.languages
    : {};
  for (const [language, rules] of Object.entries(registryLanguages)) {
    const signals = rules && typeof rules === "object" && rules.script_signals && typeof rules.script_signals === "object"
      ? rules.script_signals
      : {};
    for (const optionKey of runtimeScriptSignalOptions) {
      if (Object.prototype.hasOwnProperty.call(signals, optionKey)) {
        issues.push({
          file: registryPath,
          code: "runtime_script_signal_option_in_packaged_registry",
          language,
          field: `script_signals.${optionKey}`,
        });
      }
    }
  }
}

if (issues.length > 0) {
  console.error(JSON.stringify({ success: false, issues }, null, 2));
  process.exit(1);
}

console.log(JSON.stringify({ success: true }));
