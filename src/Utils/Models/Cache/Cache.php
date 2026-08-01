<?php

namespace StaticPHP\Utils\Models\Cache;

use Exception;

/**
 *  Cache library for various cache backends.
 *  You can use specific subclass for example $redisCache = new CacheRedis($config);
 *  or you can also create multiple instances and register them under Cache class:
 *      $redisCache = new CacheRedis($config);
 *      $apcuCache = new CacheApcu($config);
 *      Cache::register($redisCache, 'redis');
 *      Cache::register($apcuCache, 'apcu');
 *
 *  And then manipulate data on all backends using Cache class set/get/destroy static methods:
 *      Cache::set('Key', 'Value', $ttl); // - Sets on all backends
 *      $value = Cache::get('Key'); // - Returns from first one
 *      Cache::remove('Key'); // - Removed from all backends
 */
class Cache implements CacheInterface
{
    /**
     *  Configuration
     *
     * (default value: [])
     *
     * @var array<string, mixed>
     * @access private
     * @static
     */
    protected array $config = [];

    /**
     *  Array of cache backends.
     *
     * (default value: [])
     *
     * @var CacheInterface[]
     * @access private
     * @static
     */
    protected static array $backends = [];


    /**
     *  Get backend configuration.
     *
     * @access private
     * @static
     * @return CacheInterface
     */
    protected static function &getBackend(string $name): CacheInterface
    {
        if (isset(self::$backends[$name]) === false) {
            throw new \Exception('Backend does not exist');
        }
        return self::$backends[$name];
    }


    /**
     * @param ?array<string, mixed> $config
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? [];
    }

    public function prefix(string $key): string
    {
        return $this->setting('prefix') . $key;
    }

    /**
     *  One entry of the backend's configuration, as a string.
     *
     *  Configuration arrives from Config::$items, which is an untyped bag, so this is
     *  where a cache setting stops being a mixed.
     *
     * @access protected
     * @param  string $name
     * @param  string $default
     * @return string
     */
    protected function setting(string $name, string $default = ''): string
    {
        $value = $this->config[$name] ?? null;

        return (is_scalar($value) && (string) $value !== '' ? (string) $value : $default);
    }

    /**
     *  One entry of the backend's configuration, as an int.
     *
     * @access protected
     * @param  string $name
     * @param  int    $default
     * @return int
     */
    protected function settingInt(string $name, int $default = 0): int
    {
        $value = $this->config[$name] ?? null;

        return (is_numeric($value) ? (int) $value : $default);
    }

    public function setValue(string $key, mixed $value, ?int $ttl = null): bool
    {
        throw new \Exception('Not implemented');
    }

    public function getValue(string $key): mixed
    {
        throw new \Exception('Not implemented');
    }

    public function removeKey(string $key): bool
    {
        throw new \Exception('Not implemented');
    }

    public function doesItemExist(string $key): bool
    {
        throw new \Exception('Not implemented');
    }

    public function getTTL(string $key): int
    {
        throw new \Exception('Not implemented');
    }


    /**
     *  Register cache backend.
     *
     * @access public
     * @static
     * @return void
     */
    public static function register(CacheInterface $cacheBackend, string $name): void
    {
        if (isset(self::$backends[$name])) {
            throw new Exception("Cache backend already registerd by \"{$name}\"");
        }

        self::$backends[$name] = $cacheBackend;
    }

    /**
     *  Set key and value with ttl (time to live).
     *
     * @access public
     * @static
     * @return bool
     */
    public static function set(string $key, mixed $value, ?int $ttl = null, ?string $name = null): bool
    {
        if (!empty($name)) {
            $backend = self::getBackend($name);
            return $backend->setValue($key, $value, $ttl);
        }

        foreach (self::$backends as $backend) {
            $backend->setValue($key, $value, $ttl);
        }

        return true;
    }

    /**
     *  Get value by key.
     *
     * @access public
     * @static
     * @return mixed
     */
    public static function get(string $key, ?string $name = null): mixed
    {
        if (!empty($name)) {
            $backend = self::getBackend($name);
        } else {
            $backend = reset(self::$backends);
            if ($backend === false) {
                throw new Exception('No cache backend is registered');
            }
        }

        return $backend->getValue($key);
    }

    /**
     *  Remove value by $key from cache.
     *
     * @access public
     * @static
     * @return bool
     */
    public static function remove(string $key, ?string $name = null): bool
    {
        if (!empty($name)) {
            $backend = self::getBackend($name);
            return $backend->removeKey($key);
        }

        // True when the key was present in at least one backend - a backend that never
        // held it reports false, which is not a failure
        $status = false;
        foreach (self::$backends as $backend) {
            $status = $backend->removeKey($key) || $status;
        }

        return $status;
    }

    /**
     *  Check if item exists in cache.
     *
     * @access public
     * @static
     * @return bool
     */
    public static function exists(string $key, ?string $name = null): bool
    {
        if (!empty($name)) {
            $backend = self::getBackend($name);
            return $backend->doesItemExist($key);
        }

        foreach (self::$backends as $backend) {
            if ($backend->doesItemExist($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     *  Get TTL of cached value by key.
     *
     * @access public
     * @static
     * @return int
     */
    public static function getTimeToLive(string $key, ?string $name = null): int
    {
        if (!empty($name)) {
            $backend = self::getBackend($name);
        } else {
            $backend = reset(self::$backends);
            if ($backend === false) {
                throw new Exception('No cache backend is registered');
            }
        }

        return $backend->getTTL($key);
    }
}
