import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const main = fs.readFileSync(path.join(root, 'devenia-workflow.php'), 'utf8');
const trait = fs.readFileSync(path.join(root, 'includes/trait-translation-index-read-model.php'), 'utf8');

const wrapperStart = main.indexOf('private static function batch_translation_index_ids');
const wrapperEnd = main.indexOf('\n\t/**', wrapperStart + 1);
if (wrapperStart < 0 || wrapperEnd < 0) throw new Error('Menu translation batch wrapper was not found.');
const wrapper = main.slice(wrapperStart, wrapperEnd);

const readerStart = trait.indexOf('private static function translation_index_ids_for_sources_language');
const readerEnd = trait.indexOf('\n\t/**', readerStart + 1);
if (readerStart < 0 || readerEnd < 0) throw new Error('Bounded translation-index reader was not found.');
const reader = trait.slice(readerStart, readerEnd);

const assertions = [
  [/translation_index_ids_for_sources_language/, wrapper, 'menu adapter delegates to the translation index module'],
  [/hash\( 'sha256'.*\$statuses/s, wrapper, 'cache identity binds source IDs and accepted statuses'],
  [/array_chunk\( \$source_ids, 100 \)/, reader, 'each physical lookup is capped at 100 source IDs'],
  [/source_post_id IN \(\{\$source_placeholders\}\)/, reader, 'lookup is restricted to the requested source IDs'],
  [/language = %s/, reader, 'lookup is restricted to one language'],
  [/post_status IN \(\{\$status_placeholders\}\)/, reader, 'lookup is restricted to accepted post statuses'],
  [/translation_index_table\(\)/, reader, 'lookup uses the plugin-owned indexed registry'],
];

for (const [pattern, source, description] of assertions) {
  if (!pattern.test(source)) throw new Error(`Contract assertion failed: ${description}`);
}

for (const [pattern, description] of [
  [/\bget_posts\s*\(/, 'menu batch must not scan WordPress posts'],
  [/\bget_post_meta\s*\(/, 'menu batch must not inflate metadata per post'],
]) {
  if (pattern.test(wrapper)) throw new Error(`Forbidden pattern found: ${description}`);
}

console.log(JSON.stringify({ success: true, assertions: assertions.length, forbidden_checks: 2 }));
