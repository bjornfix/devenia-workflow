<?php
/** Run with: wp eval-file tools/check-rtl-grid-gap-wordpress-runtime.php */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$method = new ReflectionMethod( Devenia_Workflow::class, 'project_block_layout_from_source' );
$method->setAccessible( true );

$source = '<!-- wp:generateblocks/grid {"columns":2,"horizontalGap":22} -->'
	. '<!-- wp:generateblocks/container {"sizing":{"width":"50%","widthMobile":"100%"}} --><p>One</p><!-- /wp:generateblocks/container -->'
	. '<!-- wp:generateblocks/container {"sizing":{"width":"50%","widthMobile":"100%"}} --><p>Two</p><!-- /wp:generateblocks/container -->'
	. '<!-- wp:generateblocks/container {"sizing":{"width":"50%","widthMobile":"100%"}} --><p>Three</p><!-- /wp:generateblocks/container -->'
	. '<!-- wp:generateblocks/container {"sizing":{"width":"50%","widthMobile":"100%"}} --><p>Four</p><!-- /wp:generateblocks/container -->'
	. '<!-- /wp:generateblocks/grid -->';

$failures = array();
$assert_projection = static function ( string $language, string $spacing_side ) use ( $method, $source, &$failures ): void {
	$projected = $method->invoke( null, $source, $source, $language );
	$blocks    = parse_blocks( $projected );
	$grid      = $blocks[0] ?? array();
	$items     = $grid['innerBlocks'] ?? array();

	if ( 0 !== (int) ( $grid['attrs']['horizontalGap'] ?? -1 ) ) {
		$failures[] = "{$language}: grid horizontalGap was not removed";
	}
	foreach ( array( 0, 2 ) as $index ) {
		if ( 'calc(50% - 22px)' !== (string) ( $items[ $index ]['attrs']['sizing']['width'] ?? '' ) ) {
			$failures[] = "{$language}: item {$index} width does not reserve the native gutter";
		}
		if ( '22px' !== (string) ( $items[ $index ]['attrs']['spacing'][ $spacing_side ] ?? '' ) || '0px' !== (string) ( $items[ $index ]['attrs']['spacing'][ $spacing_side . 'Mobile' ] ?? '' ) ) {
			$failures[] = "{$language}: item {$index} does not own the responsive native gutter on {$spacing_side}";
		}
	}
	foreach ( array( 1, 3 ) as $index ) {
		if ( isset( $items[ $index ]['attrs']['spacing'][ $spacing_side ] ) ) {
			$failures[] = "{$language}: row-ending item {$index} unexpectedly owns a gutter";
		}
	}
};

$assert_projection( 'nb', 'marginRight' );
$assert_projection( 'ar', 'marginLeft' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo "Directional grid-gap WordPress runtime: OK\n";
