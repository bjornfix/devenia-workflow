#!/usr/bin/env node
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const root = new URL("../", import.meta.url);
const plugin = readFileSync(new URL("devenia-workflow.php", root), "utf8");
const workflow = readFileSync(new URL(".github/workflows/recovery-transaction-mysql84.yml", root), "utf8");
const runtime = readFileSync(new URL("tools/check-wp-cli-upgrade-bootstrap-runtime.php", root), "utf8");

const loaderStart = plugin.indexOf("private static function load_wordpress_language_pack_dependencies");
const loaderEnd = plugin.indexOf("\n\tprivate static function ", loaderStart + 1);
assert.ok(loaderStart >= 0, "language-pack dependency loader must exist");
const loader = plugin.slice(loaderStart, loaderEnd > loaderStart ? loaderEnd : plugin.length);

assert.match(plugin, /private static function wordpress_language_pack_status[\s\S]*?self::load_wordpress_language_pack_dependencies\(\);/);
assert.match(loader, /function_exists\( 'request_filesystem_credentials' \)[\s\S]*?wp-admin\/includes\/file\.php/);
assert.match(loader, /wp-admin\/includes\/translation-install\.php/);
assert.doesNotMatch(loader, /WP_CLI|is_admin\(|request_context/);
assert.ok(
	loader.indexOf("wp-admin/includes/file.php") < loader.indexOf("wp-admin/includes/translation-install.php"),
	"the File API must be available before the language-pack upgrader API"
);

assert.match(workflow, /wp option delete devenia_workflow_version[\s\S]*?check-wp-cli-upgrade-bootstrap-runtime\.php/);
assert.match(workflow, /\{"success":true,"context":"wp-cli","fresh_upgrade":true\}/);
assert.ok(
	workflow.indexOf("check-wp-cli-upgrade-bootstrap-runtime.php", workflow.indexOf("wp option delete devenia_workflow_version")) < workflow.lastIndexOf("check-recovery-transaction-portability-runtime.php"),
	"fresh bootstrap proof must run before the recovery runtime"
);

for (const functionName of [
	"request_filesystem_credentials",
	"wp_get_available_translations",
	"wp_download_language_pack",
	"wp_can_install_language_pack",
]) {
	assert.match(runtime, new RegExp(`['\"]${functionName}['\"]`));
}
assert.match(runtime, /Devenia_Workflow::VERSION[\s\S]*?get_option\( 'devenia_workflow_version'/);
assert.match(runtime, /devenia_workflow_translation_language_pack_status/);

console.log("WP-CLI fresh upgrade bootstrap contract passed.");
