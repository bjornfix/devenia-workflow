import assert from "node:assert/strict";
import fs from "node:fs";

const plugin = fs.readFileSync(new URL("../devenia-workflow.php", import.meta.url), "utf8");
const runtime = fs.readFileSync(new URL("./check-translated-post-link-wordpress-runtime.php", import.meta.url), "utf8");
const start = plugin.indexOf("private static function localized_path_for_post");
const end = plugin.indexOf("private static function localized_blog_base_path", start);

assert.ok(start >= 0 && end > start, "Localized post-path resolver is missing.");
const resolver = plugin.slice(start, end);
const postBranchEnd = resolver.indexOf("if ( $post && 'page' === $post->post_type )");
assert.ok(postBranchEnd > 0, "Translated-post terminal branch is missing.");
const postBranch = resolver.slice(0, postBranchEnd);

assert.match(postBranch, /META_CANONICAL_ROUTE/);
assert.match(postBranch, /META_LOCALIZED_PATH/);
assert.match(postBranch, /localized_blog_base_path\( \$language \)/);
assert.match(postBranch, /language_prefix\( \$language \)/);
assert.match(postBranch, /return \$slug;/);
assert.doesNotMatch(postBranch, /get_permalink\s*\(/);
assert.match(plugin, /function normalize_stored_localized_route_path/);
assert.match(plugin, /isset\( \$parts\['scheme'\] \)/);
assert.match(plugin, /isset\( \$parts\['query'\] \)/);
const blogBaseStart = plugin.indexOf("private static function detect_localized_blog_base_path");
const blogBaseEnd = plugin.indexOf("private static function localized_taxonomy_base_path", blogBaseStart);
assert.ok(blogBaseStart >= 0 && blogBaseEnd > blogBaseStart, "Localized blog-base resolver is missing.");
const blogBaseResolver = plugin.slice(blogBaseStart, blogBaseEnd);
assert.match(blogBaseResolver, /self::source_language_code\(\) === sanitize_key\( \$language \)/);
assert.doesNotMatch(blogBaseResolver, /'en' === sanitize_key\( \$language \)/);

assert.match(runtime, /no_posts_page_prefix_slug_bounded/);
assert.match(runtime, /stored_localized_path_authoritative/);
assert.match(runtime, /canonical_route_authoritative/);
assert.match(runtime, /localized_blog_base_slug/);
assert.match(runtime, /non_english_source_english_target_blog_base/);
assert.match(runtime, /\$languages\['fr'\]\['source'\] = '1'/);
assert.match(runtime, /\$languages\['en'\]\['source'\] = '0'/);
assert.match(runtime, /META_LANGUAGE, 'en'/);
assert.match(runtime, /post_link_filter_calls/);
assert.match(runtime, /remove_filter\( 'post_link', \$recursion_guard, 1 \)/);
assert.match(runtime, /wp_delete_post/);
assert.match(runtime, /page_for_posts/);

console.log("Translated post-link recursion contract passed.");
