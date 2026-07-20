#!/usr/bin/env node
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const core = readFileSync(new URL("../devenia-workflow.php", import.meta.url), "utf8");
const source = readFileSync(new URL("../addons/generateblocks.php", import.meta.url), "utf8");
const start = source.indexOf("private static function normalize_rtl_grid_gap_block(");
assert.ok(start >= 0, "missing native RTL GenerateBlocks grid-gap projection");
const end = source.indexOf("\n\tprivate static function ", start + 1);
const body = source.slice(start, end > start ? end : source.length);

assert.match(body, /'generateblocks\/grid'/);
assert.match(body, /\['horizontalGap'\] = 0/);
assert.match(body, /\['sizing'\]\['width'\].*'calc\('/s);
assert.match(body, /\['spacing'\]\['marginLeft'\]/);
assert.match(body, /\['spacing'\]\['marginLeftMobile'\].*= '0px'/);
assert.match(body, /spacing_value_is_zero/, "intentional source margins must make the transform fail closed");
assert.doesNotMatch(body, /style|className|customCss|additionalCss/i, "projection must use native block attributes, not CSS escape hatches");
assert.match(core, /apply_filters\( 'devenia_workflow_mirror_rtl_block_layout'/, "theme-neutral RTL core must expose the adapter seam");
assert.doesNotMatch(core, /normalize_rtl_grid_gap_block|horizontalGap/, "theme-neutral core must not own vendor-specific projection logic");

console.log("RTL grid-gap contract: OK");
