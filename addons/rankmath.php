<?php
/**
 * Optional Rank Math integration.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AI_Translation_Workflow_RankMath_Addon {
	/**
	 * Register Rank Math hooks.
	 */
	public static function register(): void {
		add_filter( 'rank_math/opengraph/type', array( 'Devenia_AI_Translations', 'filter_translated_posts_page_opengraph_type' ), 20 );
		add_filter( 'rank_math/frontend/canonical', array( 'Devenia_AI_Translations', 'filter_translated_posts_page_canonical' ), 20 );
		add_filter( 'rank_math/frontend/title', array( 'Devenia_AI_Translations', 'filter_translated_posts_page_seo_title' ), 20 );
		add_filter( 'rank_math/frontend/description', array( 'Devenia_AI_Translations', 'filter_translated_posts_page_seo_description' ), 20 );
		add_filter( 'rank_math/json_ld', array( 'Devenia_AI_Translations', 'filter_translated_posts_page_json_ld' ), 99, 2 );
	}
}

AI_Translation_Workflow_RankMath_Addon::register();
