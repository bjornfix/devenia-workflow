#!/usr/bin/env node
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const source = fs.readFileSync(path.join(root, "devenia-workflow.php"), "utf8");
const failures = [];

const urlStart = source.indexOf("private static function author_archive_url");
const urlEnd = source.indexOf("public static function maybe_start_author_archive_url_localization", urlStart);
const urlSource = source.slice(urlStart, urlEnd);
if (!urlSource.includes("'published' !== (string) ( $record['status'] ?? '' )")) {
  failures.push("localized author URLs must require a published runtime record");
}
if (!urlSource.includes("return get_author_posts_url( $author_id );")) {
  failures.push("unpublished localized author URLs must fall back to the source archive");
}
if (urlSource.includes("self::default_author_archive_path( $author_id, $language )")) {
  failures.push("URL generation must not expose an unroutable default localized path");
}

const resolverStart = source.indexOf("private static function author_archive_link_resolves");
const resolverEnd = source.indexOf("private static function link_review_scope_for_url", resolverStart);
const resolverSource = source.slice(resolverStart, resolverEnd);
if (resolverSource.includes("$prefix . '/author/'")) {
  failures.push("link validation must not accept generic unpublished localized author paths");
}

if (!source.includes("private static function author_archive_queue( array $input ): array")) {
  failures.push("author archive queue operation handler is missing");
}

if (failures.length) {
  console.error(JSON.stringify({ success: false, failures }, null, 2));
  process.exit(1);
}

console.log(JSON.stringify({ success: true }, null, 2));
