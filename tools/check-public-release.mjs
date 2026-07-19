#!/usr/bin/env node
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";

const base = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const slug = "devenia-workflow";
const mainFile = "devenia-workflow.php";
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
      .filter((line) => line && fs.existsSync(path.join(base, line)));
  } catch (error) {
    issue(".", "git_ls_files_failed", error instanceof Error ? error.message : String(error));
    return [];
  }
}

function verifyGitArchiveExcludes() {
  let tempDir = "";
  try {
    tempDir = fs.mkdtempSync(path.join(os.tmpdir(), `${slug}-release-check-`));
    const zipPath = path.join(tempDir, `${slug}.zip`);
    const treeId = execFileSync("git", ["write-tree"], { cwd: base, encoding: "utf8" }).trim();
    execFileSync(
      "git",
      ["archive", "--format=zip", `--output=${zipPath}`, `--prefix=${slug}/`, treeId],
      { cwd: base, stdio: "pipe" },
    );
    const listing = execFileSync("unzip", ["-Z1", zipPath], { cwd: base, encoding: "utf8" })
      .split("\n")
      .map((line) => line.trim())
      .filter(Boolean);
    const required = [
      `${slug}/${mainFile}`,
      `${slug}/readme.txt`,
      `${slug}/uninstall.php`,
    ];
    for (const entry of required) {
      if (!listing.includes(entry)) {
        issue(".gitattributes", "archive_missing_required_public_file", "Git archive release package is missing a required public plugin file.", { entry });
      }
    }
    const outsidePrefix = listing.filter((entry) => !entry.startsWith(`${slug}/`));
    if (outsidePrefix.length > 0) {
      issue(".gitattributes", "archive_contains_unprefixed_paths", "Git archive release package must contain only paths under the plugin slug directory.", { outsidePrefix });
    }
    const forbidden = listing.filter((entry) => (
      entry.startsWith(`${slug}/tools/`)
      || entry === `${slug}/README.md`
      || entry === `${slug}/.gitattributes`
      || entry.startsWith(`${slug}/.git`)
      || entry.startsWith(`${slug}/node_modules/`)
    ));
    if (forbidden.length > 0) {
      issue(".gitattributes", "archive_contains_non_public_paths", "Git archive release package contains development-only paths.", { forbidden });
    }
  } catch (error) {
    issue(".gitattributes", "archive_export_check_failed", error instanceof Error ? error.message : String(error));
  } finally {
    if (tempDir) {
      fs.rmSync(tempDir, { recursive: true, force: true });
    }
  }
}

const headerVersion = matchOne(mainFile, /^\s*\*\s*Version:\s*(.+)$/m, "missing_plugin_version", "Plugin header Version is missing.");
const constantVersion = matchOne(mainFile, /const\s+VERSION\s*=\s*'([^']+)'/, "missing_version_constant", "VERSION constant is missing.");
const stableTag = matchOne("readme.txt", /^Stable tag:\s*(.+)$/m, "missing_stable_tag", "readme.txt Stable tag is missing.");
const textDomain = matchOne(mainFile, /^\s*\*\s*Text Domain:\s*(.+)$/m, "missing_text_domain", "Plugin header Text Domain is missing.");
const contributors = matchOne("readme.txt", /^Contributors:\s*(.+)$/m, "missing_contributors", "readme.txt Contributors is missing.");
const author = matchOne(mainFile, /^\s*\*\s*Author:\s*(.+)$/m, "missing_author", "Plugin header Author is missing.");

if (!fs.existsSync(path.join(base, "uninstall.php"))) {
  issue("uninstall.php", "missing_uninstall", "Public plugins with custom tables/options need an explicit uninstall cleanup file.");
}

if (!fs.existsSync(path.join(base, ".gitattributes"))) {
  issue(".gitattributes", "missing_export_rules", "Public release packages need explicit git archive export-ignore rules.");
} else {
  const attributes = read(".gitattributes");
  if (!/^\/?tools\/?\s+export-ignore$/m.test(attributes)) {
    issue(".gitattributes", "missing_tools_export_ignore", "Development tools must be excluded from git archive release packages.");
  }
  if (!/^\/?\.gitattributes\s+export-ignore$/m.test(attributes)) {
    issue(".gitattributes", "missing_self_export_ignore", "Git archive release packages must not include .gitattributes.");
  }
  if (!/^\/?README\.md\s+export-ignore$/m.test(attributes)) {
    issue(".gitattributes", "missing_readme_md_export_ignore", "Git archive release packages must not include GitHub/development README.md when readme.txt is the public WordPress readme.");
  }
}

verifyGitArchiveExcludes();

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
  ["hello@devenia", /hello@devenia/i],
  ["bjorn_email", /\bbjorn@/i],
  ["eman_email", /\beman@/i],
  ["old_private_name", /Devenia AI Translations/],
  ["old_ability_namespace", /devenia-translations\//],
  ["site_specific_slug_lockdown", /url_change_lockdown/i],
  ["site_exported_menu_names", /\bMain Menu [A-Z]{2}\b|main-menu-nb/i],
  ["site_legacy_language_migration", /languages\['no'\]|META_LANGUAGE,\s*true\s*\)\s*\)\s*\)\s*===?\s*'no'|migrate_market_language_codes/],
  ["private_entitlement", /\b(entitlement|coupon|free sample|pricing|abuse|misuse|private workflow)\b/i],
];

const privateScanExtensions = new Set([".php", ".js", ".css", ".json", ".md", ".txt"]);
const privateScanIgnored = new Set([
  "tools/check-public-release.mjs",
  "qa-corpus/translation-fitness-regressions.json",
]);

for (const file of gitFiles()) {
  if (file.startsWith("tools/")) {
    continue;
  }
  if (privateScanIgnored.has(file)) {
    continue;
  }

  const basename = path.basename(file);
  if (basename.startsWith(".") && !new Set([".gitattributes", ".phpcs.xml.dist"]).has(basename)) {
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

for (const file of gitFiles().filter((name) => name.startsWith("languages/") && name.endsWith(".json"))) {
  issue(file, "packaged_language_file_present", "Packaged language JSON files are no longer part of the public release contract.");
}
for (const file of gitFiles().filter((name) => name === "quality-rules/language-quality.json")) {
  issue(file, "packaged_language_quality_registry_present", "Language-specific QA policy must not be shipped as packaged JSON.");
}

const vendorHookPattern = /\b(?:add_action|add_filter|do_action|apply_filters)\(\s*['"](?:generate_|rank_math\/|mcp_abilities_elementor_)/;
const rankMathMetaWritePattern = /\b(?:update_post_meta|delete_post_meta)\(\s*\$?[A-Za-z0-9_>()[\]'"\s,-]+,\s*['"]rank_math_/;
const coreVendorReferencePattern = /\b(?:rank_math|Rank Math|generateblocks|GenerateBlocks|generatepress|GeneratePress|elementor|Elementor|mcp_abilities_elementor_)\b/;
const translatedArchiveLabelPattern = /\b(?:Last updated|Sist oppdatert|Zuletzt aktualisiert|Mis à jour|Última actualización|Senast uppdaterad|Senest opdateret|Päivitetty viimeksi|آخر تحديث|Ultimo aggiornamento|Laatst bijgewerkt|Última atualização|最后更新|最終更新日|Cập nhật lần cuối)\b/u;
const languageDateFormatMapPattern = /['"](?:en|nb|de|fr|es|sv|da|fi|ar|it|nl|pt|zh|ja|vi)['"]\s*=>\s*['"]([^'"]+)['"]/gu;
function hasHardcodedLanguageDateFormat(content) {
  for (const match of content.matchAll(languageDateFormatMapPattern)) {
    const value = String(match[1] ?? "");
    if (
      /^[djDlFmMnYySzgGhHisuaAcBeIOPTUWeNrStTLoXxZFcr:.,/ \-年月日]+$/u.test(value)
      && ((/[YymndjDFMl]/.test(value) && /[./\-\s]/.test(value)) || /[年月日]/u.test(value))
    ) {
      return true;
    }
  }
  return false;
}
for (const file of gitFiles().filter((name) => name.endsWith(".php"))) {
  if (file.startsWith("addons/") || file.startsWith("tools/")) {
    continue;
  }
  const content = read(file);
  const contentWithoutAddonRequires = file === mainFile
    ? content.replace(/require_once\s+__DIR__\s*\.\s*['"]\/addons\/[^'"]+['"]\s*;/g, "")
    : content;
  if (vendorHookPattern.test(content)) {
    issue(file, "vendor_hook_outside_addon", "Vendor integration hooks belong in addons/, not in the theme-neutral core.");
  }
  if (rankMathMetaWritePattern.test(content)) {
    issue(file, "rank_math_meta_write_outside_addon", "Rank Math metadata writes belong in the optional Rank Math addon, not in the theme-neutral core.");
  }
  if (coreVendorReferencePattern.test(contentWithoutAddonRequires)) {
    issue(file, "vendor_reference_outside_addon", "Vendor-specific code and identifiers belong in optional addon files, not in the theme-neutral core.");
  }
  if (translatedArchiveLabelPattern.test(content)) {
    issue(file, "translated_archive_label_hardcoded", "Translated blog archive labels belong in runtime language configuration, not PHP.");
  }
  if (hasHardcodedLanguageDateFormat(content)) {
    issue(file, "language_date_format_hardcoded", "Language-specific date formats belong in runtime language configuration, not PHP maps.");
  }
}

const privatePresentationPatterns = [
  ["private_presentation_ability", /devenia-site-presentation\//i],
  ["private_editorial_validation_hook", /devenia_editorial_source_post_validation/i],
  ["private_presentation_shortcode", /\[devenia_presentation\b/i],
  ["private_design_class", /\bdv-(?:section|blog|container|link)-/i],
];
for (const file of gitFiles().filter((name) => name.endsWith(".php") || name.endsWith(".css") || name.endsWith(".js"))) {
  if (file.startsWith("tools/")) {
    continue;
  }
  const content = read(file);
  for (const [code, pattern] of privatePresentationPatterns) {
    if (pattern.test(content)) {
      issue(file, code, "Private Site Presentation policy must stay outside the public workflow package.");
    }
  }
}

if (issues.length > 0) {
  console.error(JSON.stringify({ success: false, issue_count: issues.length, issues }, null, 2));
  process.exit(1);
}

console.log(JSON.stringify({ success: true }));
