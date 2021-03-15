<?php
namespace UrlShortner\Controllers;
use Memcached;
use Exception;

class MemcachedController
{
	private static $memCache;

    private static function getSharedMemCache()
	{
        if (is_null(self::$memCache)) {
            self::$memCache = new Memcached();
            if (!self::$memCache->addServer("memcached", 11211)){
                self::$memCache = null;
            }
        }

		return self::$memCache;
	}

    public static function closeSharedMemCache()
	{
		if (!is_null(self::$memCache)) self::$memCache->close();
	}

    public static function getMemCache($key)
    {
        if (is_null(self::$memCache)) self::getSharedMemCache();
        return (!is_null(self::$memCache)) ? self::$memCache->get($key) : false;
    }

    public static function setMemCache($key, $value, $expiration = 0)
    {
        if (is_null(self::$memCache)) self::getSharedMemCache();
        self::$memCache->set($key, $value, $expiration > 2592000 ? 2592000 : $expiration);
    }

    public static function deleteMemCache($key)
    {
        if (is_null(self::$memCache)) self::getSharedMemCache();
        self::$memCache->delete($key);
    }

}    