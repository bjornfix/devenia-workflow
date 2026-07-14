#!/usr/bin/env node

import fs from "node:fs";
import path from "node:path";

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), "..");
const core = fs.readFileSync(path.join(root, "devenia-workflow.php"), "utf8");
const moduleSource = fs.readFileSync(path.join(root, "includes/trait-canonical-seo-surface.php"), "utf8");
const quality = fs.readFileSync(path.join(root, "includes/trait-translation-job-quality-authority.php"), "utf8");
const adapter = fs.readFileSync(path.join(root, "addons/rankmath.php"), "utf8");

const checks = [
  [core.includes("trait-canonical-seo-surface.php") && core.includes("use Devenia_Workflow_Canonical_SEO_Surface;"), "Workflow Core must load the Canonical SEO Surface Module."],
  [moduleSource.includes("canonical_seo_surface_for_translation_job") && moduleSource.includes("canonical_seo_patch_operations"), "The Module must own complete artifact and patch/derive resolution."],
  [moduleSource.includes("'operation' => empty( $focus_input['present'] ) ? 'preserve'") && moduleSource.includes("'' === $focus_value ? 'delete' : 'set'"), "Absent, empty, and nonempty focus identities must resolve to preserve, delete, and set."],
  [/foreach \( \$keys as \$key \) \{[\s\S]*?if \( array_key_exists\( \$key, \$seo_input \) \) \{[\s\S]*?'present' => true,[\s\S]*?'key'\s*=> \$key,[\s\S]*?'value'\s*=> self::normalize_review_text\( \(string\) \$seo_input\[ \$key \] \),[\s\S]*?\}[\s\S]*?\}[\s\S]*?return array\( 'present' => false/.test(moduleSource), "Alias resolution must return the first present field even when its normalized value is empty."],
  [!moduleSource.includes("if ( '' !== $value )") && !moduleSource.includes("$present_key"), "A later nonempty alias must never override an explicitly empty higher-precedence alias."],
  [moduleSource.includes("'surface_mode'   => $mode") && moduleSource.includes("'complete_replace'") && moduleSource.includes("'patch_derive'"), "The Interface must expose complete-replace and patch/derive modes."],
  [quality.includes("canonical_seo_surface_for_translation_job( $artifact, $title, $excerpt, $content )"), "Staging must resolve one complete canonical SEO surface."],
  [quality.includes("'_canonical_seo_surface' => (array) ( $artifact_record['surface_manifest']['seo'] ?? array() )"), "Publication must pass the immutable manifest SEO member through complete-replace mode."],
  [adapter.includes("in_array( $operation, array( 'set', 'delete', 'preserve' ), true )"), "The Rank Math Adapter must consume explicit field operations."],
  [adapter.includes("if ( 'preserve' === $operation )") && adapter.includes("if ( 'delete' === $operation )"), "The Adapter must distinguish preservation from controlled deletion."],
  [adapter.includes("(string) get_post_meta( $post_id, 'rank_math_focus_keyword', true )"), "The sync signature must use actual final stored state after operations."],
  [!adapter.includes("array_key_exists( $field, $fields )"), "Rank Math field presence must not pretend to carry semantics erased upstream."],
];

for (const [passed, message] of checks) {
  if (!passed) {
    throw new Error(message);
  }
}

console.log("Canonical SEO Surface contract passed.");
