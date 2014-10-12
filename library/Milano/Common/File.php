<?php

class Milano_Common_File
{
	public static function writeFile($path, $contents, $backUp = false)
	{
		$skip = false;

		if (file_exists($path))
		{
			// existed file
			$oldContents = self::fileGetContents($path);

			if ($oldContents == $contents)
			{
				// same content
				$skip = true;
			}
			else
			{
				if ($backUp)
				{
					$newPath = self::renameFile($path, XenForo_Application::$time);
					copy($path, $newPath);
				}				
			}
		}

		if (!$skip)
		{
			return self::filePutContents($path, $contents);
		}
		else
		{
			return true;
		}
	}

	public static function fileGetContents($path)
	{
		if (is_readable($path))
		{
			$contents = file_get_contents($path);

			return $contents;
		}
		else
		{
			return false;
		}
	}

	public static function filePutContents($path, $contents)
	{
		$dir = dirname($path);
		XenForo_Helper_File::createDirectory($dir);
		if (!is_dir($dir) OR !is_writable($dir))
		{
			return false;
		}

		if (file_put_contents($path, $contents) > 0)
		{
			XenForo_Helper_File::makeWritableByFtpUser($path);
			return true;
		}

		return false;
	}
}