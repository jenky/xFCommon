<?php

/**
 * Class for create XenForo virtual credit system option
 * 
 * Go to Admin CP and create an option with
 *	Edit Format: PHP Callback
 *	Format Parameters: 
 *		Milano_Common_Option_CreditSystem::renderSelect (if you want to create select option)
 *	or
 *		Milano_Common_Option_CreditSystem::renderRadio (if you want to create radio option)
 *
 */

abstract class Milano_Common_Option_CreditSystem
{
	public static function renderSelect(XenForo_View $view, $fieldPrefix, array $preparedOption, $canEdit)
	{
		return self::_render('option_list_option_select', $view, $fieldPrefix, $preparedOption, $canEdit);
	}

	public static function renderRadio(XenForo_View $view, $fieldPrefix, array $preparedOption, $canEdit)
	{
		return self::_render('option_list_option_radio', $view, $fieldPrefix, $preparedOption, $canEdit);
	}

	public static function getMoneySystemOptions($selectedOption)
	{
		$options = Milano_Common_CreditSystem::getCreditSystemOptions($selectedOption);

		return $options;
	}

	protected static function _render($templateName, XenForo_View $view, $fieldPrefix, array $preparedOption, $canEdit)
	{
		$preparedOption['formatParams'] = self::getMoneySystemOptions(
			$preparedOption['option_value']
		);

		return XenForo_ViewAdmin_Helper_Option::renderOptionTemplateInternal(
			$templateName, $view, $fieldPrefix, $preparedOption, $canEdit
		);
	}
}