#!/usr/bin/env node
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const core = readFileSync(new URL("../devenia-workflow.php", import.meta.url), "utf8");
const source = readFileSync(new URL("../addons/generateblocks.php", import.meta.url), "utf8");
const start = source.indexOf("private static function normalize_grid_gap_block(");
assert.ok(start >= 0, "missing native directional GenerateBlocks grid-gap projection");
const end = source.indexOf("\n\tprivate static function ", start + 1);
const body = source.slice(start, end > start ? end : source.length);

assert.match(body, /'generateblocks\/grid'/);
assert.match(body, /\['horizontalGap'\] = 0/);
assert.match(body, /\['sizing'\]\['width'\].*'calc\('/s);
assert.match(body, /\$is_rtl \? 'marginLeft' : 'marginRight'/, "gutter side must derive from document direction data");
assert.match(body, /\$spacing_side/);
assert.match(body, /\$mobile_side/);
assert.match(body, /spacing_value_is_zero/, "intentional source margins must make the transform fail closed");
assert.doesNotMatch(body, /style|className|customCss|additionalCss/i, "projection must use native block attributes, not CSS escape hatches");
assert.match(core, /apply_filters\( 'devenia_workflow_mirror_rtl_block_layout'/, "theme-neutral RTL core must expose the adapter seam");
assert.match(core, /apply_filters\( 'devenia_workflow_project_block_layout'/, "theme-neutral core must expose a direction-aware vendor layout projection seam");
assert.doesNotMatch(core, /normalize_rtl_grid_gap_block|horizontalGap/, "theme-neutral core must not own vendor-specific projection logic");
assert.match(source, /add_filter\( 'render_block_data', array\( __CLASS__, 'project_frontend_grid_layout' \), 10, 3 \)/, "canonical source rendering must use the same global Adapter");
const frontendStart = source.indexOf("public static function project_frontend_grid_layout(");
assert.ok(frontendStart >= 0, "missing canonical-source frontend projection");
const frontendEnd = source.indexOf("\n\tprivate static function ", frontendStart);
const frontendBody = source.slice(frontendStart, frontendEnd > frontendStart ? frontendEnd : source.length);
assert.match(frontendBody, /is_admin\(\)/, "editor-owned source content must not be mutated in admin rendering");
assert.match(frontendBody, /is_rtl\(\)/, "frontend gutter side must derive from document direction");
assert.match(frontendBody, /normalize_grid_gap_block/, "frontend and publication must share one native projection implementation");
assert.doesNotMatch(frontendBody, /post_id|page_id|language|locale|text|style|className|customCss/i, "frontend projection must not infer presentation from page, locale, text, or CSS");

console.log("RTL grid-gap contract: OK");
