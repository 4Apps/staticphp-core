<?php

/*
|--------------------------------------------------------------------------
| Redis session handler
|--------------------------------------------------------------------------
*/

namespace StaticPHP\Utils\Models\Sessions;

use Redis;

class SessionsRedis extends Sessions
{
    protected Redis $redis;

    /**
     * @param array<string, mixed> $dbConfig
     */
    public function __construct(array $dbConfig, string $sessionName = 'SMDB', ?Sessions $backupHandler = null)
    {
        $this->redis = new Redis();
        $host = $dbConfig['hostname'] ?? null;
        $port = $dbConfig['port'] ?? null;
        $database = $dbConfig['database'] ?? null;

        $this->redis->connect(
            (is_string($host) ? $host : '127.0.0.1'),
            (is_numeric($port) ? (int) $port : 6379)
        );
        $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
        $this->redis->select(is_numeric($database) ? (int) $database : 1);

        parent::__construct($sessionName, $backupHandler);
    }

    public function read(string $id): string|false
    {
        $data = $this->redis->get($this->id($id));
        if (is_string($data) && $data !== '') {
            return $data;
        }

        return parent::read($id);
    }

    public function write(string $id, string $data): bool
    {
        $this->redis->set($this->id($id), $data, $this->expire);

        return parent::write($id, $data);
    }

    public function destroy(string $id): bool
    {
        $this->redis->del($this->id($id));

        return parent::destroy($id);
    }
}
