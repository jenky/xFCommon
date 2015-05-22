<?php

class Milano_Common_Install
{
	protected static $_db;
	public static $existingAddOn;
	public static $addOnData;
	public static $xml;

	protected static $_dependencies;
	protected static $_tables;
	protected static $_tablePatches;
	protected static $_userFields;
	protected static $_contentTypes;
	protected static $_contentTypeFields;
	protected static $_primaryKeys;
	protected static $_uniqueKeys;
	protected static $_keys;
	protected static $_fields;
	protected static $_enumValues;

	protected static $_noUninstall = false;

	protected static function _construct($existingAddOn = null, $addOnData  = null, $xml = null)
	{
		if (version_compare(PHP_VERSION, '5.3.0', '<'))
		{
			throw new XenForo_Exception('You need at least PHP version 5.3.0 to install this add-on. Your version: ' . PHP_VERSION, true);
		}
		
		static::$existingAddOn = $existingAddOn;
		static::$addOnData = $addOnData;
		static::$xml = $xml;

		static::$_dependencies = static::_getDependencies();
		static::$_tables = static::_getTables();
		static::$_tablePatches = static::_getTablePatches();
		static::$_userFields = static::_getUserFields();
		static::$_contentTypes = static::_getContentTypes();
		static::$_contentTypeFields = static::_getContentTypeFields();
		static::$_primaryKeys = static::_getPrimaryKeys();
		static::$_uniqueKeys = static::_getUniqueKeys();
		static::$_keys = static::_getKeys();
		static::$_enumValues = static::_getEnumValues();
	}

	protected static function _getDb()
	{
		if (!static::$_db)
		{
			static::$_db = XenForo_Application::get('db');
		}

		return static::$_db;
	}

	public static final function install($existingAddOn, $addOnData, SimpleXMLElement $xml = null)
	{
		static::_construct($existingAddOn, $addOnData, $xml);

		static::_preInstallBeforeTransaction();
		static::_getDb()->beginTransaction();
		

		if (!empty(static::$_dependencies)) 
		{
			static::checkDependencies(static::$_dependencies);
		}

		static::_preInstall();

		$fieldNameChanges = static::_getInstallFieldNameChanges();
		if (!empty($fieldNameChanges))
		{
			static::makeFieldChanges($fieldNameChanges);
		}

		$tableNameChanges = static::_getInstallTableNameChanges();
		if (!empty($tableNameChanges))
		{
			static::renameTables($tableNameChanges);
		}

		if (!empty(static::$_tables))
		{
			static::createTables(static::$_tables);
		}

		if (!empty(static::$_tablePatches))
		{
			static::alterTables(static::$_tablePatches);
		}

		if (!empty(static::$_userFields))
		{
			static::createUserFields(static::$_userFields);
		}

		if (!empty(static::$_contentTypeFields))
		{
			static::insertContentTypeFields(static::$_contentTypeFields);
		}

		if (!empty(static::$_contentTypes) || !empty(static::$_contentTypeFields))
		{
			static::insertContentTypes(static::$_contentTypes);
		}

		if (!empty(static::$_primaryKeys))
		{
			static::addPrimaryKeys(static::$_primaryKeys);
		}

		if (!empty(static::$_uniqueKeys))
		{
			static::addUniqueKeys(static::$_uniqueKeys);
		}

		if (!empty(static::$_keys))
		{
			static::addKeys(static::$_keys);
		}

		/*if (!empty(static::$_enumValues))
		{
			static::alterEnumValues(static::$_enumValues);
		}*/

		static::_postInstall();
		static::_getDb()->commit();
		static::_postInstallAfterTransaction();
	}

	public static final function uninstall()
	{
		if (static::$_noUninstall)
		{
			return;
		}

		static::_construct();

		static::_preUninstallBeforeTransaction();
		static::_getDb()->beginTransaction();
		static::_preUninstall();

		$fieldNameChanges = static::_getUninstallFieldNameChanges();
		if (!empty($fieldNameChanges))
		{
			static::makeFieldChanges($fieldNameChanges);
		}

		$tableNameChanges = static::_getUninstallTableNameChanges();
		if (!empty($tableNameChanges))
		{
			static::renameTables($tableNameChanges);
		}

		if (!empty(static::$_tables))
		{
			static::dropTables(static::$_tables);
		}

		if (!empty(static::$_tablePatches))
		{
			static::dropTablePatches(static::$_tablePatches);
		}

		if (!empty(static::$_userFields))
		{
			static::dropUserFields(static::$_userFields);
		}

		if (!empty(static::$_contentTypeFields))
		{
			static::deleteContentTypeFields(static::$_contentTypeFields);
		}

		if (!empty(static::$_contentTypes) || !empty(static::$_contentTypeFields))
		{
			static::deleteContentTypes(static::$_contentTypes);
		}

		static::_postUninstall();
		static::_getDb()->commit();
		static::_postUninstallAfterTransaction();
	}

	// DEPRECATED soon
	public static function checkXfVersion($versionId, $versionString)
	{
		return static::checkXenForoVersion($versionId, $versionString);
	}

	public static function checkXenForoVersion($versionId, $versionString)
	{
		if (XenForo_Application::$versionId < $versionId)
		{
			throw new XenForo_Exception('This add-on requires XenForo ' . $versionString . ' or higher.', true);
		}
	}

	public static function dump(Exception $e, $exit = true)
	{
		if (XenForo_Application::debugMode())
		{
			echo static::_getDb()->getProfiler()
				->getLastQueryProfile()
				->getQuery();

			echo '<br>';
			echo '<font color="#cc0000">' . $e->getMessage() . '</font>';
			
			if ($exit)
			{
				die;
			}
		}
	}

	public static final function isAddOnInstalled($addOnId)
	{
		$addOnModel = XenForo_Model::create('XenForo_Model_AddOn');
		$addOn = $addOnModel->getAddOnById($addOnId);

		return $addOn;
	}

	public static final function isFieldExists($table, $field)
	{
		try
		{
			return static::_getDb()->fetchRow('SHOW COLUMNS FROM ' . $table . ' WHERE Field = ?', $field) ? true : false;
		}
		catch (Zend_Db_Exception $e) 
		{
			return static::dump($e);
		}
	}
	
	public static final function isTableExists($table)
	{
		try 
		{
			return static::_getDb()->fetchRow('SHOW TABLES LIKE \'' . $table . '\'') ? true : false; 
		}
		catch (Zend_Db_Exception $e) 
		{
			return static::dump($e);
		}
	}

	public static function checkDependencies(array $dependencies)
	{
		$notInstalled = array();
		$outOfDate = array();
		foreach ($dependencies as $addOnId => $versionId) 
		{
			$addOn = static::isAddOnInstalled($addOnId);
			if (!$addOn) 
			{
				$notInstalled[] = $addOnId;
			}
			if ($addOn['version_id'] < $versionId) 
			{
				$outOfDate[] = $addOnId;
			}
		}
		if ($notInstalled) 
		{
			throw new XenForo_Exception('The following required add-ons need to be installed: ' . implode(',', $notInstalled), true);
		}
		if ($outOfDate) 
		{
			throw new XenForo_Exception('The following required add-ons need to be updated: ' . implode(',', $outOfDate), true);
		}
	}

	public static function makeFieldChanges(array $fieldChanges)
	{
		foreach ($fieldChanges as $tableName => $tableSql) 
		{
			if (static::isTableExists($tableName)) 
			{
				$describeTable = static::_getDb()->describeTable($tableName);
				$keys = array_keys($describeTable);
				$sql = "ALTER TABLE `" . $tableName . "` ";
				$sqlAdd = array();
				foreach ($tableSql as $oldFieldName => $newField) 
				{
					if (in_array($oldFieldName, $keys)) 
					{
						$sqlAdd[] = "CHANGE `" . $oldFieldName . "` " . $newField;
					}
				}
				$sql .= implode(", ", $sqlAdd);
				try
				{
					static::_getDb()->query($sql);
				}
				catch (Zend_Db_Exception $e) 
				{
					return static::dump($e);
				}
			}
		}
	}

	public static function renameTables(array $tableNameChanges)
	{
		foreach ($tableNameChanges as $oldTableName => $newTableName) 
		{
			if (static::isTableExists($oldTableName)) 
			{
				if (!static::isTableExists($newTableName)) 
				{
					$sql = "RENAME TABLE `" . $oldTableName . "` TO `" . $newTableName . "`";
				} 
				else 
				{
					$sql = "DROP TABLE `" . $oldTableName . "`";
				}
				try
				{
					static::_getDb()->query($sql);
				}
				catch (Zend_Db_Exception $e) 
				{
					return static::dump($e);
				}
			}
		}
	}

	public static function createTables(array $tables)
	{
		foreach ($tables AS $tableName => $tableSql)
		{
			if (!static::isTableExists($tableName))
			{
				$sql = "CREATE TABLE IF NOT EXISTS `" . $tableName . "` (";
				$sqlRows = array();
				foreach ($tableSql as $rowName => $rowParams) 
				{
					if ($rowName !== 'EXTRA')
					{
						$sqlRows[] = "`" . $rowName . "` " . $rowParams;
					}
				}
				if (!empty($tableSql['EXTRA']))
				{
					$sqlRows[] = $tableSql['EXTRA'];
				}
				$sql .= implode(",", $sqlRows);
				$sql .= ") ENGINE = InnoDB CHARACTER SET utf8 COLLATE utf8_general_ci";

				try
				{
					static::_getDb()->query($sql);
					//static::_getDb()->query("CREATE TABLE IF NOT EXISTS `" . $tableName . "` (" . $tableSql . ") ENGINE = InnoDB CHARACTER SET utf8 COLLATE utf8_general_ci");
				}
				catch (Zend_Db_Exception $e) 
				{
					return static::dump($e);
				}
			}
			else 
			{
				$tableChanges = array($tableName => $tableSql);
				static::alterTables($tableChanges);
			}
		}
	}

	public static function dropTables(array $tables)
	{
		foreach ($tables AS $tableName => $tableSql)
		{
			try 
			{
				static::_getDb()->query("DROP TABLE IF EXISTS `" . $tableName . "` "); 
			}
			catch (Zend_Db_Exception $e) 
			{
				return static::dump($e);
			}
		}
	}

	public static function alterTables(array $tables)
	{
		foreach ($tables AS $tableName => $tableSql)
		{
			if (static::isTableExists($tableName))
			{
				$describeTable = static::_getDb()->describeTable($tableName);
				$keys = array_keys($describeTable);
				
				$sql = "ALTER IGNORE TABLE `".$tableName."` ";
				$sqlQuery = array();
				if (isset($tableSql['EXTRA']))
				{
					unset($tableSql['EXTRA']);
				}
				foreach ($tableSql as $rowName => $rowParams)
				{
					if (strpos($rowParams, 'PRIMARY KEY') !== false)
					{
						if (static::getExistingPrimaryKeys($tableName))
						{
							$sqlQuery[] = "DROP PRIMARY KEY ";
						}
					}
					if (in_array($rowName, $keys))
					{
						$sqlQuery[] = "CHANGE `" . $rowName . "` `" . $rowName . "` " . $rowParams;
					}
					else
					{
						$sqlQuery[] = "ADD `" . $rowName . "` " . $rowParams;
					}
				}
				
				$sql .= implode(", ", $sqlQuery);
				try
				{
					static::_getDb()->query($sql);
				}
				catch (Zend_Db_Exception $e) 
				{
					return static::dump($e);
				}
			}
		}
	}


	public static function alterTable($table, $field, $action = 'drop', $attr = NULL, $after = NULL)
	{
		$exists = static::isFieldExists($table, $field);
		$action = strtolower($action);

		if ($action == 'drop') 
		{
			if ($exists)
			{
				try
				{
					static::_getDb()->query("ALTER TABLE " . $table . " DROP " . $field);  
				}
				catch (Zend_Db_Exception $e) 
				{
					return static::dump($e);
				}
			}
		}
		elseif ($action == 'add')
		{
			if (!$exists)
			{
				try
				{
					$afterColumn = !empty($after) ? " AFTER " . $after : '';
					static::_getDb()->query("ALTER TABLE " . $table . " ADD " . $field . " " . $attr . $afterColumn);
				}
				catch (Zend_Db_Exception $e) 
				{
					return static::dump($e);
				}
			}            
		}
		elseif ($action == 'change')
		{
			if ($exists)
			{
				try
				{
					static::_getDb()->query("ALTER TABLE " . $table . " CHANGE " . $field . "  " . $field . " " . $attr);
				}
				catch (Zend_Db_Exception $e) 
				{
					return static::dump($e);
				}
			}            
		}
	}

	public static function alterEnumValues(array $enumValues, $reverse = false)
	{
		/*foreach ($enumValues as $tableName => $fields) 
		{
			if (static::isTableExists($tableName)) 
			{
				$table = static::_getDb()->describeTable($tableName);
				$enums = array();
				foreach ($fields as $fieldName => $fieldEnums) 
				{
					if (!isset($table[$fieldName])) 
					{
						continue;
					}
					preg_match('/^enum\((.*)\)$/', $table[$fieldName]['DATA_TYPE'], $matches);
					foreach (explode(',', $matches[1]) as $value) 
					{
						$enums[] = trim($value, "'");
					}
					$newEnums = $enums;
					if (isset($fieldEnums['add'])) 
					{
						if (!$reverse) {
							foreach ($fieldEnums['add'] as $fieldEnum) 
							{
								$newEnums[] = $fieldEnum;
							}
						} 
						else 
						{
							foreach ($fieldEnums['add'] as $fieldEnum) 
							{
								static::_getDb()->delete($tableName, $fieldName . ' = \'' . $fieldEnum . '\'');
							}
							$newEnums = array_diff($newEnums, $fieldEnums['add']);
						}
						$newEnums = array_unique($newEnums);
					}
					if (isset($fieldEnums['remove'])) 
					{
						if (!$reverse) 
						{
							foreach ($fieldEnums['remove'] as $fieldEnum) 
							{
								static::_getDb()->delete($tableName, $fieldName . ' = \'' . $fieldEnum . '\'');
							}
							$newEnums = array_diff($newEnums, $fieldEnums['remove']);
						} 
						else 
						{
							foreach ($fieldEnums['remove'] as $fieldEnum) 
							{
								$newEnums[] = $fieldEnum;
							}
						}
						$newEnums = array_unique($newEnums);
					}
					sort($enums);
					sort($newEnums);
					if ($enums != $newEnums) 
					{
						foreach ($newEnums as &$value) 
						{
							$value = '\'' . $value . '\'';
						}
						$table[$fieldName]['DATA_TYPE'] = 'enum(' . implode(',', $newEnums) . ')';
						static::alterTable($table[$fieldName]);
					}
				}
			}
		}*/
	}

	public static function dropTablePatches(array $tables)
	{
		foreach ($tables as $tableName => $tableSql)
		{		
			$keys = array_keys(static::_getDb()->describeTable($tableName));
				
			foreach ($tableSql as $rowName => $rowParams)
			{
				if (in_array($rowName, $keys))
				{
					try
					{
						static::_getDb()->query("ALTER TABLE " . $tableName . " DROP " . $rowName); 
					}
					catch (Zend_Db_Exception $e) 
					{
						return static::dump($e);
					}
				}
			}
		}
	}

	public static function createUserFields(array $userFields)
	{
		foreach ($userFields as $fieldId => $fields)
		{		
			$dw = XenForo_DataWriter::create('XenForo_DataWriter_UserField');
			if (!$dw->setExistingData($fieldId))
			{
				$dw->set('field_id', $fieldId);
			}
			$dw->bulkSet($fields);
			$dw->save();
		}
	}
	
	public static function dropUserFields(array $userFields)
	{
		foreach ($userFields as $fieldId => $fields)
		{
			$dw = XenForo_DataWriter::create('XenForo_DataWriter_UserField');			
			$dw->setExistingData($fieldId);
			$dw->delete();
		}
	}

	public static function getExistingPrimaryKeys($tableName)
	{
		$columns = static::_getDb()->describeTable($tableName);
		
		$primaryKeys = array();
		foreach ($columns as $columnName => $column)
		{
			if ($column['PRIMARY'])
			{
				$primaryKeys[] = $columnName;
			}
		}
		return $primaryKeys;
	}
	
	public static function addPrimaryKeys(array $primaryKeys)
	{
		foreach ($primaryKeys as $tableName => $primaryKey)
		{
			$oldKey = static::getExistingPrimaryKeys($tableName);
			$keyDiff = array_diff($primaryKey, $oldKey);
			if (!empty($keyDiff))
			{
				try
				{
					static::_getDb()->query("ALTER TABLE `" . $tableName . "`
						". (empty($oldKey) ? "": "DROP PRIMARY KEY, ") ."
						ADD PRIMARY KEY(".implode(",", $primaryKey).")");
				}
				catch (Zend_Db_Exception $e) 
				{
					return static::dump($e);
				}
			}
		}
	}

	public static function getExistingKeys($tableName)
	{
		$keys = array();

		if (static::isTableExists($tableName))
		{
			$columns = static::_getDb()->describeTable($tableName);	
			$indexes = static::_getDb()->fetchAll('SHOW INDEXES FROM  `'.$tableName.'`');

			foreach ($indexes as $index) 
			{
				if (!isset($keys[$index['Key_name']])) 
				{
						$keys[$index['Key_name']] = $index;
				}
				
				$keys[$index['Key_name']]['Column_names'][] = $index['Column_name'];
			}
		}

		return $keys;
	}
	
	public static function addUniqueKeys(array $uniqueKeys)
	{
		foreach ($uniqueKeys as $tableName => $uniqueKey)
		{
			$oldKeys = static::_getExistingKeys($tableName);
			foreach ($uniqueKey as $keyName => $keyColumns)
			{
				try
				{
					static::_getDb()->query("ALTER TABLE `" . $tableName . "`
						". (!isset($oldKeys[$keyName]) ? "": "DROP INDEX `" . $keyName . "`, ") ."
						ADD UNIQUE `" . $keyName . "` (" . implode(",", $keyColumns) . ")");
				}
				catch (Zend_Db_Exception $e) 
				{
					return static::dump($e);
				}
			}
		}
	}

	public static function addKeys(array $keys)
	{
		foreach ($keys as $tableName => $key)
		{
			$oldKeys = static::_getExistingKeys($tableName);
			foreach ($key as $keyName => $keyColumns)
			{
				try
				{
					static::_getDb()->query("ALTER TABLE `".$tableName."`
						". (!isset($oldKeys[$keyName]) ? "": "DROP INDEX `" . $keyName . "`, ") ."
						ADD INDEX `" . $keyName . "` (" . implode(",", $keyColumns) . ")");
				}
				catch (Zend_Db_Exception $e) 
				{
					return static::dump($e);
				}
			}
		}
	}

	public static function insertContentTypes(array $contentTypes)
	{
		foreach ($contentTypes as $contentType => $contentTypeParams)
		{
			if (isset($contentTypeParams['addon_id']))
			{
				$addOnId = $contentTypeParams['addon_id'];
				try
				{
					static::_getDb()->query("INSERT INTO xf_content_type (
							content_type,
							addon_id,
							fields
						) VALUES (
							'" . $contentType . "',
							'" . $addOnId . "',
							''
						) ON DUPLICATE KEY UPDATE
							addon_id = '" . $addOnId . "'");
				}
				catch (Zend_Db_Exception $e) 
				{
					return static::dump($e);
				}
				static::insertContentTypeFields(array($contentType => $contentTypeParams['fields']));
			}
		}
		XenForo_Model::create('XenForo_Model_ContentType')->rebuildContentTypeCache();
	}
	
	public static function insertContentTypeFields(array $contentTypes)
	{
		foreach ($contentTypes as $contentType => $contentTypeFields)
		{
			foreach ($contentTypeFields as $fieldName => $fieldValue)
			{
				try
				{
					static::_getDb()->query("INSERT INTO xf_content_type_field (
						content_type,
						field_name,
						field_value
					) VALUES (
						'" . $contentType . "',
						'" . $fieldName . "',
						'" . $fieldValue . "'
					) ON DUPLICATE KEY UPDATE
						field_value = '" . $fieldValue . "'");
				}
				catch (Zend_Db_Exception $e) 
				{
					return static::dump($e);
				}
			}
		}
	}
	
	public static function deleteContentTypes(array $contentTypes)
	{
		foreach ($contentTypes as $contentType => $contentTypeParams)
		{
			if (isset($contentTypeParams['addon_id']))
			{
				$addOnId = $contentTypeParams['addon_id'];
				try
				{
					static::_getDb()->query("DELETE FROM xf_content_type WHERE content_type = '" . $contentType . "' AND addon_id = '" . $addOnId . "'");
					static::_getDb()->query("DELETE FROM xf_content_type_field WHERE content_type = '" . $contentType . "'");
				}
				catch (Zend_Db_Exception $e) 
				{
					return static::dump($e);
				}
			}
		}
		XenForo_Model::create('XenForo_Model_ContentType')->rebuildContentTypeCache();
	}

	public static function deleteContentTypeFields(array $contentTypes)
	{
		foreach ($contentTypes as $contentType => $contentTypeFields)
		{
			foreach ($contentTypeFields as $fieldName => $fieldValue)
			{
				try
				{
					static::_getDb()->query("DELETE FROM xf_content_type_field WHERE content_type = '" . $contentType . "' 
						AND field_name = '" . $fieldName . "' AND field_value = '" . $fieldValue . "'");
				}
				catch (Zend_Db_Exception $e) 
				{
					return static::dump($e);
				}
			}
		}
	}
	
	protected static function _getInstallFieldNameChanges()
	{
		return array();
	}

	protected static function _getUninstallFieldNameChanges()
	{
		return array();
	}

	protected static function _getInstallTableNameChanges()
	{
		return array();
	}

	protected static function _getUninstallTableNameChanges()
	{
		return array();
	}

	protected static function _getDependencies()
	{
		return array();
	} 

	protected static function _getTables()
	{
		return array();
	}
	
	protected static function _getTablePatches()
	{
		return array();
	}
	
	protected static function _getContentTypes()
	{
		return array();
	}
	
	protected static function _getContentTypeFields()
	{
		return array();
	}
	
	protected static function _getUserFields()
	{
		return array();
	}
	
	protected static function _getPrimaryKeys()
	{
		return array();
	}

	protected static function _getUniqueKeys()
	{
		return array();
	}
	
	protected static function _getKeys()
	{
		return array();
	}
	
	protected static function _getEnumValues()
	{
		return array();
	}

	protected static function _preInstall()
	{
	}
	
	protected static function _preInstallBeforeTransaction()
	{
	}
	
	protected static function _preUninstall()
	{
	}

	protected static function _preUninstallBeforeTransaction()
	{
	}	
	
	protected static function _postInstall()
	{
	}
	
	protected static function _postInstallAfterTransaction()
	{
	}
	
	protected static function _postUninstall()
	{
	}

	protected static function _postUninstallAfterTransaction()
	{
	}
}