#!/usr/bin/env node
import fs from "node:fs";
import path from "node:path";
import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";

const base = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const slug = "devenia-ai-translations";
const mainFile = "devenia-ai-translations.php";
const issues = [];

function read(relativePath) {
  return fs.readFileSync(path.join(base, relativePath), "utf8");
}

function issue(file, code, message, details = {}) {
  issues.push({ file, code, message, ...details });
}

function matchOne(file, pattern, code, message) {
  const content = read(file);
  const match = content.match(pattern);
  if (!match) {
    issue(file, code, message);
    return "";
  }
  return String(match[1] ?? "").trim();
}

function gitFiles() {
  try {
    return execFileSync("git", ["ls-files"], { cwd: base, encoding: "utf8" })
      .split("\n")
      .map((line) => line.trim())
      .filter(Boolean);
  } catch (error) {
    issue(".", "git_ls_files_failed", error instanceof Error ? error.message : String(error));
    return [];
  }
}

const headerVersion = matchOne(mainFile, /^\s*\*\s*Version:\s*(.+)$/m, "missing_plugin_version", "Plugin header Version is missing.");
const constantVersion = matchOne(mainFile, /const\s+VERSION\s*=\s*'([^']+)'/, "missing_version_constant", "VERSION constant is missing.");
const stableTag = matchOne("readme.txt", /^Stable tag:\s*(.+)$/m, "missing_stable_tag", "readme.txt Stable tag is missing.");
const textDomain = matchOne(mainFile, /^\s*\*\s*Text Domain:\s*(.+)$/m, "missing_text_domain", "Plugin header Text Domain is missing.");
const contributors = matchOne("readme.txt", /^Contributors:\s*(.+)$/m, "missing_contributors", "readme.txt Contributors is missing.");
const author = matchOne(mainFile, /^\s*\*\s*Author:\s*(.+)$/m, "missing_author", "Plugin header Author is missing.");

if (headerVersion && constantVersion && headerVersion !== constantVersion) {
  issue(mainFile, "version_mismatch", "Plugin header Version and VERSION constant differ.", { headerVersion, constantVersion });
}

if (headerVersion && stableTag && headerVersion !== stableTag) {
  issue("readme.txt", "stable_tag_mismatch", "readme.txt Stable tag must match the plugin header Version.", { headerVersion, stableTag });
}

if (textDomain && textDomain !== slug) {
  issue(mainFile, "text_domain_slug_mismatch", "WordPress.org expects Text Domain to match the plugin slug.", { expected: slug, actual: textDomain });
}

if (contributors && contributors !== "basicus") {
  issue("readme.txt", "contributors_not_public_identity", "Public readme contributors must use the public WordPress.org identity.", { expected: "basicus", actual: contributors });
}

if (author && author !== "basicus") {
  issue(mainFile, "author_not_public_identity", "Public plugin header Author must use the public WordPress.org identity.", { expected: "basicus", actual: author });
}

const privatePatterns = [
  ["devenia.com", /devenia\.com/i],
  ["hello@devenia", /hello@devenia/i],
  ["bjorn_email", /\bbjorn@/i],
  ["eman_email", /\beman@/i],
  ["old_private_name", /Devenia AI Translations/],
  ["old_ability_namespace", /devenia-translations\//],
  ["private_entitlement", /\b(entitlement|coupon|free sample|pricing|abuse|misuse|private workflow)\b/i],
];

const privateScanExtensions = new Set([".php", ".js", ".css", ".json", ".md", ".txt"]);
const privateScanIgnored = new Set([
  "tools/check-public-release.mjs",
  "qa-corpus/translation-fitness-regressions.json",
]);

for (const file of gitFiles()) {
  if (privateScanIgnored.has(file)) {
    continue;
  }

  const basename = path.basename(file);
  if (basename.startsWith(".")) {
    issue(file, "hidden_file_tracked", "Hidden files must not be part of the distributable plugin ZIP.");
  }

  const ext = path.extname(file);
  if (!privateScanExtensions.has(ext)) {
    continue;
  }

  const content = read(file);
  for (const [code, pattern] of privatePatterns) {
    if (pattern.test(content)) {
      issue(file, `private_reference_${code}`, "Private or site-specific release text is present in a public package file.");
    }
  }
}

const vendorHookPattern = /\b(?:add_action|add_filter|do_action|apply_filters)\(\s*['"]generate_/;
for (const file of gitFiles().filter((name) => name.endsWith(".php"))) {
  if (file.startsWith("addons/")) {
    continue;
  }
  if (vendorHookPattern.test(read(file))) {
    issue(file, "vendor_hook_outside_addon", "GeneratePress hooks belong in addons/, not in the theme-neutral core.");
  }
}

if (issues.length > 0) {
  console.error(JSON.stringify({ success: false, issue_count: issues.length, issues }, null, 2));
  process.exit(1);
}

console.log(JSON.stringify({ success: true }));
