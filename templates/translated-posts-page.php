<?php
/**
 * Translated posts page template.
 *
 * Keeps translated blog URLs page-based for language routing, while rendering
 * the post list through a standard WordPress archive surface.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

do_action( 'ai_translation_workflow_before_translated_posts_page_main_content' );
?>

<div class="content-area" id="primary">
	<main id="main" class="site-main ai-translation-workflow-translated-posts-main">
			<?php
			$devenia_ai_translations_posts_query = Devenia_AI_Translations::translated_posts_page_query();
			$devenia_ai_translations_query_state = Devenia_AI_Translations::enter_translated_posts_page_loop_context();

			if ( $devenia_ai_translations_posts_query->have_posts() ) {
				do_action( 'ai_translation_workflow_before_translated_posts_page_loop' );

				while ( $devenia_ai_translations_posts_query->have_posts() ) {
					$devenia_ai_translations_posts_query->the_post();
					Devenia_AI_Translations::render_translated_posts_page_article();
				}

				Devenia_AI_Translations::render_translated_posts_page_pagination( $devenia_ai_translations_posts_query );
				do_action( 'ai_translation_workflow_after_translated_posts_page_loop' );
			} else {
				get_template_part( 'content', 'none' );
			}

			wp_reset_postdata();
			Devenia_AI_Translations::leave_translated_posts_page_loop_context( $devenia_ai_translations_query_state );
			?>
	</main>
</div>
<?php
do_action( 'ai_translation_workflow_after_translated_posts_page_primary_content_area' );

if ( apply_filters( 'ai_translation_workflow_render_translated_posts_page_default_sidebar', true ) ) {
	get_sidebar();
}
?>

<?php
do_action( 'ai_translation_workflow_after_translated_posts_page_main_content' );

get_footer();
