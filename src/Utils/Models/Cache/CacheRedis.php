<?php

namespace StaticPHP\Utils\Models\Cache;

use Redis;

/**
 *  Redis cache implementation
 */
class CacheRedis extends Cache
{
    protected Redis $redis;

    /**
     *  Init Redis cache
     *
     * @access public
     * @static
     * @return void
     */
    /**
     * @param ?array<string, mixed> $config
     */
    public function __construct(?array $config = null)
    {
        parent::__construct($config);

        $this->redis = new Redis();
        $this->redis->connect(
            $this->setting('hostname', '127.0.0.1'),
            $this->settingInt('port', 6379),
            (float) $this->settingInt('timeout')
        );
        $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
        $this->redis->select($this->settingInt('database'));
    }

    /**
     *  Set cached value by key using Redis.
     *
     * @access public
     * @static
     * @return bool
     */
    public function setValue(string $key, mixed $value, ?int $ttl = null): bool
    {
        $status = $this->redis->set($this->prefix($key), $value);
        if ($status === false) {
            return false;
        }

        if ($ttl !== null) {
            return $this->redis->expire($this->prefix($key), $ttl);
        }

        return true;
    }

    /**
     *  Get cached value by key using Redis.
     *
     * @access public
     * @static
     * @return mixed
     */
    public function getValue(string $key): mixed
    {
        return $this->redis->get($this->prefix($key));
    }

    /**
     *  Remove cached value by key using Redis.
     *
     * @access public
     * @static
     * @return bool
     */
    public function removeKey(string $key): bool
    {
        return $this->redis->del($this->prefix($key));
    }

    /**
     *  Check if cached value exists by key using Redis.
     *
     * @access public
     * @static
     * @return bool
     */
    public function doesItemExist(string $key): bool
    {
        return $this->redis->exists($this->prefix($key)) > 0;
    }

    /**
     *  Get TTL of cached value by key using Redis.
     *
     * @access public
     * @static
     * @return int
     */
    public function getTTL(string $key): int
    {
        return $this->redis->ttl($this->prefix($key));
    }
}
