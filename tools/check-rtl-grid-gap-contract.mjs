#!/usr/bin/env node
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const core = readFileSync(new URL("../devenia-workflow.php", import.meta.url), "utf8");
const adapter = readFileSync(new URL("../addons/generateblocks.php", import.meta.url), "utf8");

assert.match(core, /project_core_rtl_layout_from_source/, "Workflow must own an explicit typed Core RTL adapter");
assert.match(core, /project_typed_core_rtl_attrs/, "Core RTL projection must be block-type aware");
assert.doesNotMatch(core, /str_replace\( 'Left', 'Right'|strpos\( \$left_key, 'Left'/, "Workflow must never infer direction from arbitrary attribute key names");
assert.doesNotMatch(core, /devenia_workflow_mirror_rtl_block_layout/, "the retired generic RTL mirroring seam must not remain");
assert.match(core, /apply_filters\( 'devenia_workflow_project_block_layout'/, "Workflow must consume the external native layout projection Interface");
assert.doesNotMatch(core, /normalize_rtl_grid_gap_block|horizontalGap/, "theme-neutral Workflow core must not own vendor projection logic");
assert.doesNotMatch(adapter, /generateblocks_do_content|render_block_data|generateblocks_dynamic_css_posts|_generateblocks_dynamic_css_version/, "Workflow must not own canonical frontend or GenerateBlocks cache behavior");
assert.doesNotMatch(adapter, /normalize_grid_gap|responsive_grid_gap|percentage_row_end|horizontalGap/, "Workflow must not duplicate the GP-MCP projection implementation");

console.log("RTL grid-gap ownership contract: OK");
