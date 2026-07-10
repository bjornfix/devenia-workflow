<?php
/**
 * Localized frontend read-model composition seam.
 *
 * @package Devenia_AI_Translations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_AI_Translations_Frontend_Read_Model {
	use Devenia_AI_Translations_Translation_Index_Read_Model;
	use Devenia_AI_Translations_Translation_Read_Models;
	use Devenia_AI_Translations_Read_Model_Snapshots;
	use Devenia_AI_Translations_Presentation_Adapter;
}
