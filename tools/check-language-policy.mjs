#!/usr/bin/env node
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const base = path.dirname(path.dirname(fileURLToPath(import.meta.url)));

const issues = [];

const languagesDir = path.join(base, "languages");
if (fs.existsSync(languagesDir)) {
  const languageFiles = fs.readdirSync(languagesDir)
    .filter((name) => name.endsWith(".json"))
    .sort();
  for (const fileName of languageFiles) {
    issues.push({
      file: path.join("languages", fileName),
      code: "packaged_language_file_present",
      message: "Packaged language JSON files have been removed. Store runtime text in WordPress options and language QA policy in runtime profiles or audited rule events.",
    });
  }
}

const registryPath = "quality-rules/language-quality.json";
if (fs.existsSync(path.join(base, registryPath))) {
  issues.push({
    file: registryPath,
    code: "packaged_language_quality_registry_present",
    message: "Language-specific QA policy belongs in runtime quality profiles or audited language-rule events, not packaged JSON files.",
  });
}

if (issues.length > 0) {
  console.error(JSON.stringify({ success: false, issues }, null, 2));
  process.exit(1);
}

console.log(JSON.stringify({ success: true }));
