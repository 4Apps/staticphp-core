<?php

namespace StaticPHP\Utils\Models\Cache;

use Memcached;
use StaticPHP\Core\Models\Config;

/**
 *  Memcached cache implementation
 */
class CacheMemcached extends Cache
{
    protected Memcached $memcached;

    /**
     *  Init Memcached cache
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

        $timeout = $this->settingInt('timeout');
        if ($timeout > 0) {
            ini_set('memcached.default_connect_timeout', $timeout * 1000);
        }

        $persistentId = $this->setting('persistent_id');
        $this->memcached = ($persistentId === '' ? new Memcached() : new Memcached($persistentId));
        $this->memcached->setOption(Memcached::OPT_LIBKETAMA_COMPATIBLE, true);
        if (!count($this->memcached->getServerList())) {
            if ($timeout > 0) {
                $this->memcached->setOption(Memcached::OPT_RECV_TIMEOUT, $timeout * 1000);
                $this->memcached->setOption(Memcached::OPT_SEND_TIMEOUT, $timeout * 1000);
            }

            $servers = $this->config['servers'] ?? null;
            $this->memcached->addServers(is_array($servers) ? $servers : []);
        }
    }

    /**
     *  Set cached value by key using Memcached.
     *
     * @access public
     * @static
     * @return bool
     */
    public function setValue(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->memcached->set($this->prefix($key), $value, $ttl ?? 0);
    }

    /**
     *  Get cached value by key using Memcached.
     *
     * @access public
     * @static
     * @return mixed
     */
    public function getValue(string $key): mixed
    {
        return $this->memcached->get($this->prefix($key));
    }

    /**
     *  Remove cached value by key using Memcached.
     *
     * @access public
     * @static
     * @return bool
     */
    public function removeKey(string $key): bool
    {
        return $this->memcached->delete($this->prefix($key));
    }
}
