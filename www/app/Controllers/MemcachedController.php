<?php
namespace UrlShortner\Controllers;
use Memcached;
use Exception;

class MemcachedController
{
	private static $memCache;

    /**
     * This function is used to initialize Memcache connection, if initialized, return memcache connection.
     * @return Memcached|null
     */
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

    /**
     *This function is used to close the memcache connection.
     */
    public static function closeSharedMemCache()
	{
		if (!is_null(self::$memCache)) self::$memCache->close();
	}

    /**
     * This function is used to fetch the values stored in memcached
     * @param $key
     * @return bool
     */
    public static function getMemCache($key)
    {
        if (is_null(self::$memCache)) self::getSharedMemCache();
        return (!is_null(self::$memCache)) ? self::$memCache->get($key) : false;
    }

    /**
     * This function is used to save values to memcache.
     * @param $key
     * @param $value
     * @param int $expiration
     */
    public static function setMemCache($key, $value, $expiration = 0)
    {
        if (is_null(self::$memCache)) self::getSharedMemCache();
        self::$memCache->set($key, $value, $expiration > 2592000 ? 2592000 : $expiration);
    }

    /**
     * This function is used to remove a specific value from memcached based on the key passed.
     * @param $key
     */
    public static function deleteMemCache($key)
    {
        if (is_null(self::$memCache)) self::getSharedMemCache();
        self::$memCache->delete($key);
    }

}    