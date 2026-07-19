#!/usr/bin/env node
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const source = readFileSync(join(root, 'includes/trait-source-design-inheritance.php'), 'utf8');

const required = [
  [/core_image_alt_text\( \$attrs, \$html \)/, 'core/image alt must be read from attrs or saved markup'],
  [/source_design_fragment_key\( \$name, \$attrs, \$current_path, 'image_alt' \)/, 'inline image alt needs a stable source fragment key'],
  [/'block'\s*=>\s*'core\/image:alt'/, 'packet fragment must identify the inline image-alt role'],
  [/'format'\s*=>\s*'plain_text'/, 'inline image alt must be exposed as plain text'],
  [/localized_inline_image_alt_count/, 'projection must report localized inline image alt coverage'],
  [/replace_core_image_alt_attribute\( \(string\) \$block\['innerHTML'\], \$image_alt \)/, 'projection must replace saved img alt markup'],
  [/replace_core_image_inner_content_alt_attribute\( \$block\['innerContent'\], \$image_alt \)/, 'projection must keep Gutenberg innerContent aligned'],
];

for (const [pattern, message] of required) {
  if (!pattern.test(source)) {
    throw new Error(message);
  }
}

console.log('inline image alt contract: success');
