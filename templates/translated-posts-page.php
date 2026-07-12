<?php
/**
 * Translated posts page template.
 *
 * Keeps translated blog URLs page-based for language routing, while rendering
 * the post list through a standard WordPress archive surface.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

do_action( 'devenia_workflow_before_translated_posts_page_main_content' );
?>

<div class="content-area" id="primary">
	<main id="main" class="site-main devenia-workflow-translated-posts-main">
			<?php
			$devenia_workflow_posts_query = Devenia_Workflow::translated_posts_page_query();
			$devenia_workflow_query_state = Devenia_Workflow::enter_translated_posts_page_loop_context();

			if ( $devenia_workflow_posts_query->have_posts() ) {
				do_action( 'devenia_workflow_before_translated_posts_page_loop' );

				while ( $devenia_workflow_posts_query->have_posts() ) {
					$devenia_workflow_posts_query->the_post();
					Devenia_Workflow::render_translated_posts_page_article();
				}

				Devenia_Workflow::render_translated_posts_page_pagination( $devenia_workflow_posts_query );
				do_action( 'devenia_workflow_after_translated_posts_page_loop' );
			} else {
				get_template_part( 'content', 'none' );
			}

			wp_reset_postdata();
			Devenia_Workflow::leave_translated_posts_page_loop_context( $devenia_workflow_query_state );
			?>
	</main>
</div>
<?php
do_action( 'devenia_workflow_after_translated_posts_page_primary_content_area' );

if ( apply_filters( 'devenia_workflow_render_translated_posts_page_default_sidebar', true ) ) {
	get_sidebar();
}
?>

<?php
do_action( 'devenia_workflow_after_translated_posts_page_main_content' );

get_footer();
