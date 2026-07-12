<?php
/**
 * Localized frontend read-model composition seam.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Translation_Frontend_Read_Model {
	use Devenia_Workflow_Translation_Index_Read_Model;
	use Devenia_Workflow_Translation_Read_Models;
	use Devenia_Workflow_Presentation_Adapter;
}
