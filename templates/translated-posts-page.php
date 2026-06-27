<?php
/**
 * Translated posts page template.
 *
 * Keeps translated blog URLs page-based for language routing, while rendering
 * the post list through the same GeneratePress archive loop used by /blog/.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- GeneratePress template hook name.
do_action( 'generate_before_main_content' );
?>

<div class="content-area" id="primary">
	<main <?php function_exists( 'generate_do_attr' ) ? generate_do_attr( 'main' ) : post_class( 'site-main' ); ?>>
			<?php
			$devenia_ai_translations_posts_query = Devenia_AI_Translations::translated_posts_page_query();
			$devenia_ai_translations_query_state = Devenia_AI_Translations::enter_translated_posts_page_loop_context();

			if ( $devenia_ai_translations_posts_query->have_posts() ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- GeneratePress loop hook name.
				do_action( 'generate_before_loop', 'index' );

				while ( $devenia_ai_translations_posts_query->have_posts() ) {
					$devenia_ai_translations_posts_query->the_post();
					Devenia_AI_Translations::render_translated_posts_page_article();
				}

				Devenia_AI_Translations::render_translated_posts_page_pagination( $devenia_ai_translations_posts_query );
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- GeneratePress loop hook name.
				do_action( 'generate_after_loop', 'index' );
			} else {
				if ( function_exists( 'generate_do_template_part' ) ) {
					generate_do_template_part( 'none' );
				} else {
					get_template_part( 'content', 'none' );
				}
			}

			wp_reset_postdata();
			Devenia_AI_Translations::leave_translated_posts_page_loop_context( $devenia_ai_translations_query_state );
			?>
	</main>
</div>
<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- GeneratePress template hook name.
do_action( 'generate_after_primary_content_area' );

if ( function_exists( 'generate_construct_sidebars' ) ) {
	generate_construct_sidebars();
} else {
	get_sidebar();
}
?>

<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- GeneratePress template hook name.
do_action( 'generate_after_main_content' );

get_footer();
