<?php

class Milano_Common_Install
{
	protected static $_db;
	public static $existingAddOn;
	public static $addOnData;
	public static $xml;

	protected static $_prerequisites;
	protected static $_tables;
	protected static $_tablePatches;
	protected static $_userFields;
	protected static $_contentTypes;
	protected static $_contentTypeFields;
	protected static $_primaryKeys;
	protected static $_uniqueKeys;
	protected static $_keys;
	protected static $_fields;

	protected static $_noUninstall = false;

	protected static function _construct($existingAddOn = null, $addOnData  = null, $xml = null)
	{
		if (version_compare(PHP_VERSION, '5.3.0', '<'))
		{
    		throw new XenForo_Exception('You need at least PHP version 5.3.0 to install this add-on. Your version: ' . PHP_VERSION, true);
		}

		// Todo: Require Addon
		
		self::$existingAddOn = $existingAddOn;
		self::$addOnData = $addOnData;
		self::$xml = $xml;

		self::$_prerequisites = static::_getPrerequisites();
		self::$_tables = static::_getTables();
		self::$_tablePatches = static::_getTablePatches();
		self::$_userFields = static::_getUserFields();
		self::$_contentTypes = static::_getContentTypes();
		self::$_contentTypeFields = static::_getContentTypeFields();
		self::$_primaryKeys = static::_getPrimaryKeys();
		self::$_uniqueKeys = static::_getUniqueKeys();
		self::$_keys = static::_getKeys();
	}

	protected static function _getDb()
	{
		if (!self::$_db)
		{
			self::$_db = XenForo_Application::get('db');
		}

		return self::$_db;
	}

	public static final function install($existingAddOn, $addOnData, SimpleXMLElement $xml = null)
	{
		self::_construct($existingAddOn, $addOnData, $xml);

		static::_preInstallBeforeTransaction();
		self::_getDb()->beginTransaction();
		static::_preInstall();

        if (!empty(self::$_prerequisites)) 
        {
            self::checkPrerequisites(self::$_prerequisites);
        }

		$fieldNameChanges = static::_getInstallFieldNameChanges();
		if (!empty($fieldNameChanges))
		{
			self::makeFieldChanges($fieldNameChanges);
		}

		$tableNameChanges = static::_getInstallTableNameChanges();
		if (!empty($tableNameChanges))
		{
			self::renameTables($tableNameChanges);
		}

		if (!empty(self::$_tables))
		{
			self::createTables(self::$_tables);
		}

		if (!empty(self::$_tablePatches))
		{
			self::alterTables(self::$_tablePatches);
		}

		if (!empty(self::$_userFields))
		{
			self::createUserFields(self::$_userFields);
		}

		if (!empty(self::$_contentTypeFields))
		{
			self::insertContentTypeFields(self::$_contentTypeFields);
		}

		if (!empty(self::$_contentTypes) || !empty(self::$_contentTypeFields))
		{
			self::insertContentTypes(self::$_contentTypes);
		}

		if (!empty(self::$_primaryKeys))
		{
			self::addPrimaryKeys(self::$_primaryKeys);
		}

		if (!empty(self::$_uniqueKeys))
		{
			self::addUniqueKeys(self::$_uniqueKeys);
		}

		if (!empty(self::$_keys))
		{
			self::addKeys(self::$_keys);
		}

		static::_postInstall();
		self::_getDb()->commit();
		static::_postInstallAfterTransaction();
	}

	public static final function uninstall()
	{
		if (self::$_noUninstall)
		{
			return;
		}

		self::_construct();

		static::_preUninstallBeforeTransaction();
		self::_getDb()->beginTransaction();
		static::_preUninstall();

		$fieldNameChanges = static::_getUninstallFieldNameChanges();
		if (!empty($fieldNameChanges))
		{
			self::makeFieldChanges($fieldNameChanges);
		}

		$tableNameChanges = static::_getUninstallTableNameChanges();
		if (!empty($tableNameChanges))
		{
			self::renameTables($tableNameChanges);
		}

		if (!empty(self::$_tables))
		{
			self::dropTables(self::$_tables);
		}

		if (!empty(self::$_tablePatches))
		{
			self::dropTablePatches(self::$_tablePatches);
		}

		if (!empty(self::$_userFields))
		{
			self::dropUserFields(self::$_userFields);
		}

		if (!empty(self::$_contentTypeFields))
		{
			self::deleteContentTypeFields(self::$_contentTypeFields);
		}

		if (!empty(self::$_contentTypes) || !empty(self::$_contentTypeFields))
		{
			self::deleteContentTypes(self::$_contentTypes);
		}

		static::_postUninstall();
		self::_getDb()->commit();
		static::_postUninstallAfterTransaction();
	}

	public static function checkXfVersion($versionId, $versionString)
	{
		if (XenForo_Application::$versionId < $versionId)
		{
			throw new XenForo_Exception('This add-on requires XenForo ' . $versionString . ' or higher.', true);
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
			return self::_getDb()->fetchRow('SHOW COLUMNS FROM ' . $table . ' WHERE Field = ?', $field) ? true : false;
		}
		catch (Zend_Db_Exception $e) {}
	}
	
	public static final function isTableExists($table)
	{
		try 
		{
			return self::_getDb()->fetchRow('SHOW TABLES LIKE \'' . $table . '\'') ? true : false; 
		}
		catch (Zend_Db_Exception $e) {}
	}

	public static function checkPrerequisites(array $prerequisites)
    {
        $notInstalled = array();
        $outOfDate = array();
        foreach ($prerequisites as $addOnId => $versionId) 
        {
            $addOn = self::isAddOnInstalled($addOnId);
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
            if (self::isTableExists($tableName)) 
            {
                $describeTable = self::_getDb()->describeTable($tableName);
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
					self::_getDb()->query($sql);
				}
				catch (Zend_Db_Exception $e) {}
            }
        }
    }

    public static function renameTables(array $tableNameChanges)
    {
        foreach ($tableNameChanges as $oldTableName => $newTableName) 
        {
            if (self::isTableExists($oldTableName)) 
            {
                if (!self::isTableExists($newTableName)) 
                {
                    $sql = "RENAME TABLE `" . $oldTableName . "` TO `" . $newTableName . "`";
                } 
                else 
                {
                    $sql = "DROP TABLE `" . $oldTableName . "`";
                }
                try
				{
					self::_getDb()->query($sql);
				}
				catch (Zend_Db_Exception $e) {}
            }
        }
    }

	public static function createTables(array $tables)
	{
		foreach ($tables AS $tableName => $tableSql)
		{
			if (!self::isTableExists($tableName))
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
					self::_getDb()->query($sql);
					//self::_getDb()->query("CREATE TABLE IF NOT EXISTS `" . $tableName . "` (" . $tableSql . ") ENGINE = InnoDB CHARACTER SET utf8 COLLATE utf8_general_ci");
				}
				catch (Zend_Db_Exception $e) {}
			}
			else 
			{
                $tableChanges = array($tableName => $tableSql);
                self::alterTables($tableChanges);
            }
		}
	}

	public static function dropTables(array $tables)
	{
		foreach ($tables AS $tableName => $tableSql)
		{
			try 
			{
				self::_getDb()->query("DROP TABLE IF EXISTS `" . $tableName . "` "); 
			}
			catch (Zend_Db_Exception $e) {}
		}
	}

	public static function alterTables(array $tables)
	{
		foreach ($tables AS $tableName => $tableSql)
		{
			if (self::isTableExists($tableName))
			{
				$describeTable = self::_getDb()->describeTable($tableName);
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
						if (self::getExistingPrimaryKeys($tableName))
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
					self::_getDb()->query($sql);
				}
				catch (Zend_Db_Exception $e) {}
			}
		}
	}


	public static function alterTable($table, $field, $action = 'drop', $attr = NULL, $after = NULL)
	{
		$exists = self::isFieldExists($table, $field);
		$action = strtolower($action);

		if ($action == 'drop') 
		{
			if ($exists)
			{
				try
				{
					self::_getDb()->query("ALTER TABLE " . $table . " DROP " . $field);  
				}
				catch (Zend_Db_Exception $e) {}
			}
		}
		elseif ($action == 'add')
		{
			if (!$exists)
			{
				try
				{
					$afterColumn = !empty($after) ? " AFTER " . $after : '';
					self::_getDb()->query("ALTER TABLE " . $table . " ADD " . $field . " " . $attr . $afterColumn);
				}
				catch (Zend_Db_Exception $e) {}
			}            
		}
		elseif ($action == 'change')
		{
			if ($exists)
			{
				try
				{
					self::_getDb()->query("ALTER TABLE " . $table . " CHANGE " . $field . "  " . $field . " " . $attr);
				}
				catch (Zend_Db_Exception $e) {}
			}            
		}
	}

	public static function dropTablePatches(array $tables)
	{
		foreach ($tables as $tableName => $tableSql)
		{		
			$keys = array_keys(self::_getDb()->describeTable($tableName));
				
			foreach ($tableSql as $rowName => $rowParams)
			{
				if (in_array($rowName, $keys))
				{
					try
					{
						self::_getDb()->query("ALTER TABLE " . $tableName . " DROP " . $rowName); 
					}
					catch (Zend_Db_Exception $e) {}
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
		$columns = self::_getDb()->describeTable($tableName);
		
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
			$oldKey = self::getExistingPrimaryKeys($tableName);
			$keyDiff = array_diff($primaryKey, $oldKey);
			if (!empty($keyDiff))
			{
				try
				{
					self::_getDb()->query("ALTER TABLE `" . $tableName . "`
						". (empty($oldKey) ? "": "DROP PRIMARY KEY, ") ."
						ADD PRIMARY KEY(".implode(",", $primaryKey).")");
				}
				catch (Zend_Db_Exception $e) {}
			}
		}
	}

	public static function getExistingKeys($tableName)
	{
		$keys = array();

		if (self::isTableExists($tableName))
		{
			$columns = self::_getDb()->describeTable($tableName);	
			$indexes = self::_getDb()->fetchAll('SHOW INDEXES FROM  `'.$tableName.'`');

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
			$oldKeys = self::_getExistingKeys($tableName);
			foreach ($uniqueKey as $keyName => $keyColumns)
			{
				try
				{
					self::_getDb()->query("ALTER TABLE `" . $tableName . "`
						". (!isset($oldKeys[$keyName]) ? "": "DROP INDEX `" . $keyName . "`, ") ."
						ADD UNIQUE `" . $keyName . "` (" . implode(",", $keyColumns) . ")");
				}
				catch (Zend_Db_Exception $e) {}
			}
		}
	}

	public static function addKeys(array $keys)
	{
		foreach ($keys as $tableName => $key)
		{
			$oldKeys = self::_getExistingKeys($tableName);
			foreach ($key as $keyName => $keyColumns)
			{
				try
				{
					self::_getDb()->query("ALTER TABLE `".$tableName."`
						". (!isset($oldKeys[$keyName]) ? "": "DROP INDEX `" . $keyName . "`, ") ."
						ADD INDEX `" . $keyName . "` (" . implode(",", $keyColumns) . ")");
				}
				catch (Zend_Db_Exception $e) {}
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
					self::_getDb()->query("INSERT INTO xf_content_type (
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
				catch (Zend_Db_Exception $e) {}
				self::insertContentTypeFields(array($contentType => $contentTypeParams['fields']));
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
					self::_getDb()->query("INSERT INTO xf_content_type_field (
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
				catch (Zend_Db_Exception $e) {}
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
					self::_getDb()->query("DELETE FROM xf_content_type WHERE content_type = '" . $contentType . "' AND addon_id = '" . $addOnId . "'");
					self::_getDb()->query("DELETE FROM xf_content_type_field WHERE content_type = '" . $contentType . "'");
				}
				catch (Zend_Db_Exception $e) {}
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
					self::_getDb()->query("DELETE FROM xf_content_type_field WHERE content_type = '" . $contentType . "' 
						AND field_name = '" . $fieldName . "' AND field_value = '" . $fieldValue . "'");
				}
				catch (Zend_Db_Exception $e) {}
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

	protected static function _getPrerequisites()
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