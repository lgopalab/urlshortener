<?php
namespace UrlShortner\Controllers;
use Exception;
use mysqli;

class DatabaseController
{

	private static $initialized = false;

	private static $databaseName = null;
	private static $databaseUser = null;
	private static $databasePassword = null;
	private static $databaseHost = null;

	public static function init($databaseConfigurationName = 'DATABASE_CONF')
	{
		$databaseConfigurationFilename = self::getEnvironmentVariable($databaseConfigurationName, '/var/www/html/config/mysql_config.ini');

		$databaseConfiguration = file_exists($databaseConfigurationFilename) ? parse_ini_file($databaseConfigurationFilename, true) : array ();

		if (isset($databaseConfiguration['credential'])) {
			$databaseCredentialConfiguration = $databaseConfiguration['credential'];
			if (isset($databaseCredentialConfiguration['username']) && isset($databaseCredentialConfiguration['password'])) {
				self::$databaseUser = $databaseCredentialConfiguration['username'];
				self::$databasePassword = $_ENV['MYSQL_ROOT_PASSWORD'];
			}
		}

		if (isset($databaseConfiguration['connection_info'])) {
			$databaseConnectionConfiguration = $databaseConfiguration['connection_info'];
			if (isset($databaseConnectionConfiguration['host']) && isset($databaseConnectionConfiguration['database'])) {
				self::$databaseHost = $databaseConnectionConfiguration['host'];
				self::$databaseName = $_ENV['APP_DB_SCHEMA'];
			}
		}

		self::$initialized = true;

		return self::getDbConnection();
	}

	public static function initialize()
	{
		if (!self::$initialized)
		{
			return self::init();
		}else{
		    return self::getDbConnection();
        }
	}

    private static final function getEnvironmentVariable($variableName, $default = null)
	{
		$value = $default;
		if (isset($_SERVER[$variableName])) $value = $_SERVER[$variableName];
		if (isset($_ENV[$variableName])) $value = $_ENV[$variableName];
		return $value;
    }

    public static function getDbConnection()
	{
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
		$connection = new mysqli(self::$databaseHost, self::$databaseUser , self::$databasePassword, self::$databaseName);
		if (!$connection) throw new Exception('Error connecting to mysql', 1001);
		return $connection;
	}

}