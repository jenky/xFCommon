<?php

/**
 * Helper for XenForo virtual credit systems
 * 
 * Currently support:
 * - XenForo trophy points system. (@var string trophy_points)
 * - [bd] Banking by xfrocks @http://xenforo.com/community/resources/bd-banking.536/ (@var string (addon_id): bdbank)
 * - Credits by Brivium @http://xenforo.com/community/resources/credits.1391/ (@var string (addon_id): Brivium_Credits)
 * 
 * For developers:
 * @see Milano_Common_Option_CreditSystem for how to create an option.
 *
 */

class Milano_Common_CreditSystem
{
	/**
	 * XenForo_Model_AddOn
	 * 
	 * @var object|null
	 */ 
	protected static $_addOnModel = null;

	/**
	 * List of credit system callbacks to get user balance. Maps the add-on id (key)
	 * to a callback (value).
	 *
	 * Data received by this callback is not escaped!
	 *
	 * @var array
	 */
	public static $creditCallbacks = array(
		'trophy_points'		=> array('self', 'getTrophyPoints'),
		'bdbank'            => array('self', 'bdbankBalance'),
		'Brivium_Credits'	=> array('self', 'creditsBalance')
	);

	/**
	 * Calls a general helper as listed in the helper callbacks.
	 *
	 * @param string $helper Name of helper
	 * @param array $args All arguments passed to the helper.
	 *
	 * @return string
	 */
	public static function getUserBalance($creditSystem, array $args)
	{
		$creditSystem = strval($creditSystem);
		if (!isset(static::$creditCallbacks[$creditSystem]))
		{
			return 0;
		}

		return call_user_func_array(static::$creditCallbacks[$creditSystem], $args);
	}

	/**
	 * Get user trophy points
	 * 
	 * @param array $user
	 * 
	 * @return int
	 */ 
	public static function getTrophyPoints(array $user = null)
	{
		static::standardizeViewingUserReference($user);

		return isset($user['trophy_points']) ? $user['trophy_points'] : 0;
	}

	/**
	 * Get user [bd]Bank balance
	 * 
	 * @param array $user
	 * 
	 * @return mixed
	 */ 
	public static function bdbankBalance(array $user = null)
	{
		static::standardizeViewingUserReference($user);

		$field = XenForo_Application::get('options')->bdbank_field;

		return isset($user[$field]) ? $user[$field] : 0;
	}

	/**
	 * Get user Brivium Credits (free) balance
	 * 
	 * @param array $user
	 * 
	 * @return mixed
	 */ 
	public static function creditsBalance(array $user = null)
	{
		static::standardizeViewingUserReference($user);

		$field = XenForo_Application::get('options')->BRC_field;

		return $field ? $user[$field] : $user['credits'];
	}

	/**
	 * Check if credit system addon is enabled or disabled
	 * Only supports addons defines in $creditCallbacks
	 *
	 * @param string $creditSystem
	 *
	 * @return boolean
	 */
	public static function verifyCreditSystem($creditSystem, $includeDisabled = false)
	{
		if (!in_array($creditSystem, array_keys(static::$creditCallbacks)))
		{
			return false;
		}

		return static::_checkAddOn($creditSystem, $includeDisabled);
	}

	/**
	 * Gets a list of money systems as options.
	 *
	 * @param string $selectedOption
	 * @param boolean $includeDisabled If true, will include disabled addon
	 *
	 * @return array
	 */
	public static function getCreditSystemOptions($selectedOption, $includeDisabled = false)
	{
		$options = array(
			array(
				'label' => 'Trophy Points',
				'value' => 'trophy_points',
				'selected' => ($selectedOption == 'trophy_points')
			)
		);

		foreach (array_keys(static::$creditCallbacks) as $addOnId) 
		{
			$addOn = static::_checkAddOn($addOnId, $includeDisabled);

			if (!empty($addOn))
			{
				$options[] = array(
					'label' => $addOn['title'],
					'value' => $addOn['addon_id'],
					'selected' => ($selectedOption == $addOn['addon_id'])
				);
			}
		}

		return $options;
	}

	/**
	 * Check if addon is installed and enabled
	 *
	 * @param string $addOnId
	 * @param boolean $includeDisabled If true, will include disabled addon
	 *
	 * @return array|false
	 */
	protected static function _checkAddOn($addOnId, $includeDisabled = false)
	{
		if (!$includeDisabled)
		{
			$addOns = XenForo_Application::get('addOns');

			return in_array($addOnId, array_keys($addOns));
		}

		return static::_getAddOnModel()->getAddOnById($addOnId);
	}

	/**
	 * Determines if the value is float or interger
	 * 
	 * @param mixed $value Value to format
	 * @param string $delimeter If found in $value, this value is float 
	 * @param int $precision Number of places to show after decimal point or word "size" for file size
	 * 
	 * @return mixed
	 */ 
	public static function determineFloatOrInt($value, $delimeter = '.', $precision = 2)
	{
		if (strpos($value, $delimeter) === false)
		{
			return intval($value);
		}
		else
		{
			return XenForo_Locale::numberFormat($value, $precision);
		}
	}

	/**
	 * Standardizes a viewing user reference array. This array must contain all basic user info
	 * (preferably all user info) and include global permissions in a "permissions" key.
	 * If not an array or missing a user_id, the visitor's values will be used.
	 *
	 * @param array|null $viewingUser
	 */
	public static function standardizeViewingUserReference(array &$viewingUser = null)
	{
		if (!is_array($viewingUser) || !isset($viewingUser['user_id']))
		{
			$viewingUser = XenForo_Visitor::getInstance()->toArray();
		}

		return $viewingUser;
	}

	protected static function _getAddOnModel()
	{
		if (!static::$_addOnModel)
		{
			static::$_addOnModel = XenForo_Model::create('XenForo_Model_AddOn');
		}

		return static::$_addOnModel;
	}
}