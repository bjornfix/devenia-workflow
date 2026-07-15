<?php
/**
 * Lightweight behavioral contract for the public primary-navigation parser.
 *
 * @package Devenia_Workflow
 */

define( 'ABSPATH', __DIR__ . '/' );

function apply_filters( $hook, $value ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.valueFound -- Minimal WordPress test stub.
	return $value;
}

function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

function wp_parse_url( $url ) {
	return parse_url( $url );
}

function untrailingslashit( $value ) {
	return rtrim( $value, '/' );
}

function esc_url_raw( $url ) {
	return $url;
}

require_once dirname( __DIR__ ) . '/includes/trait-localized-presentation-publication.php';

final class Devenia_Workflow_Primary_Navigation_Parser_Harness {
	use Devenia_Workflow_Localized_Presentation_Publication;

	public static function parse( string $html ): array {
		return self::primary_navigation_from_html( $html, 'en' );
	}
}

$html = <<<'HTML'
<!doctype html><html><body>
<nav id="site-navigation" class="main-navigation">
	<a class="site-logo" href="/">Brand</a>
	<ul id="menu-primary" class="menu sf-menu">
		<li><a href="/">Home</a></li>
		<li><a href="/services/">Services</a><ul class="sub-menu"><li><a href="/services/consulting/">Consulting</a></li></ul></li>
		<li class="devenia-language-menu-dropdown menu-item-has-children"><a class="devenia-language-trigger" href="/">Choose language</a><ul class="sub-menu devenia-language-submenu"><li><div><a class="devenia-language-menu-item" href="/en/">English</a><a class="devenia-language-menu-item" href="/nb/">Norsk</a></div></li></ul></li>
		<li><a class="devenia-language-trigger" href="/surface-specific-language-trigger/">Reparented language trigger</a></li>
		<li><a class="devenia-language-menu-item" href="/surface-specific-language-route/">Reparented language item</a></li>
	</ul>
	<div class="menu-bar-items"><a href="#">Search</a></div>
	<ul class="secondary-menu"><li><a href="/secondary/">Secondary</a></li></ul>
</nav>
</body></html>
HTML;

$expected = array(
	array( 'title' => 'Home', 'url' => 'https://example.test' ),
	array( 'title' => 'Services', 'url' => 'https://example.test/services' ),
	array( 'title' => 'Consulting', 'url' => 'https://example.test/services/consulting' ),
);
$actual = Devenia_Workflow_Primary_Navigation_Parser_Harness::parse( $html );
if ( $expected !== $actual ) {
	fwrite( STDERR, 'Primary navigation parser mismatch: ' . json_encode( $actual, JSON_UNESCAPED_SLASHES ) . PHP_EOL );
	exit( 1 );
}

$fallback = str_replace( ' id="site-navigation"', '', $html );
if ( $expected !== Devenia_Workflow_Primary_Navigation_Parser_Harness::parse( $fallback ) ) {
	fwrite( STDERR, "Primary navigation fallback selector mismatch.\n" );
	exit( 1 );
}

echo "Primary navigation parser contract passed.\n";
