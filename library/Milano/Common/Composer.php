<?php

class Milano_Common_Composer
{
	protected static $_loader = false;

	final static public function autoload()
	{
		if (self::$_loader)
		{
			return true;
		}

		$composerDir = self::getComposerPath();
		chdir($composerDir);

		if (file_exists($composerDir . '/vendor/autoload.php'))
		{
			require($composerDir . '/vendor/autoload.php');
			self::$_loader = true;
		}

		return self::$_loader;
	}

	public static function getComposerPath()
	{
		$rootDir = XenForo_Autoloader::getInstance()->getRootDir();
		return realpath($rootDir . '/..');
	}

	public static function setRequire(array $dependencies)
	{
		foreach ($dependencies as $name => $version)
		{
			# code...
		}
	}

	public static function make($composer)
	{
		$composerDir = self::getComposerPath();
		chdir($composerDir);
		Milano_Common_File::writeFile($composerDir . '/composer.json', $composer);
	}

	public static function __callStatic($method, $args)
    {
        $instance = App::get(static::getName());

        if ( ! method_exists($instance, $method)) 
        {
            throw new XenForo_Exception(get_called_class() . ' does not implement ' . $method . ' method.');
        }

        return call_user_func_array(array($instance, $method), $args);
    }
}