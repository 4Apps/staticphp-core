<?php

namespace StaticPHP\Utils\Models\Cache;

use Exception;

/**
 *  Files cache implementation
 */
class CacheFiles extends Cache
{
    /**
     * @param ?array<string, mixed> $config
     */
    public function __construct(?array $config = null)
    {
        parent::__construct($config);

        $path = $this->setting('path');

        if (is_dir($path) === false) {
            // 0777 leaves the cache world writable; the web user is the only one that
            // needs to write here
            mkdir($path, 0770, true);
        }

        if (empty($this->config['ext'])) {
            $this->config['ext'] = 'cache';
        }
        if (empty($this->config['levels'])) {
            $this->config['levels'] = 0;
        }
        if (empty($this->config['sub_path_length'])) {
            $this->config['sub_path_length'] = 2;
        }
    }

    /**
     *  Returns filename to the file.
     *
     * @access protected
     * @static
     * @param  string $key
     * @param  bool   $create Create the sub directory when it is missing
     * @return string
     */
    protected function filename($key, $create = false)
    {
        $key = md5($key);

        $subpath = $this->setting('path') . '/';
        $length = $this->settingInt('sub_path_length', 2);
        for ($i = 1; $i <= $this->settingInt('levels'); $i++) {
            $subpath .= substr($key, -($i * $length), $length);
            $subpath .= '/';
        }

        if ($create === true && is_dir($subpath) === false) {
            mkdir($subpath, 0770, true);
        }

        return $subpath . $this->prefix($key) . '.' . $this->setting('ext', 'cache');
    }

    /**
     *  Set cached value to file.
     *
     * @access public
     * @static
     * @return bool
     */
    public function setValue(string $key, mixed $value, ?int $ttl = null): bool
    {
        $filename = self::filename($key, true);

        if (is_array($value)) {
            $value = json_encode($value);
        } elseif (is_bool($value) || is_numeric($value) || is_string($value)) {
            $value = json_encode(['cacher___encoded' => $value]);
        } else {
            throw new Exception('Data type is not yet supported');
        }

        return file_put_contents($filename, $value) !== false;
    }

    /**
     *  Get cached value from file.
     *
     * @access public
     * @static
     * @return mixed
     */
    public function getValue(string $key): mixed
    {
        $filename = self::filename($key, true);

        if (is_file($filename) === false) {
            return false;
        }

        $contents = json_decode((string) file_get_contents($filename), true);
        if (is_array($contents) && isset($contents['cacher___encoded'])) {
            $contents = $contents['cacher___encoded'];
        }

        return $contents;
    }

    /**
     *  Remove cached value.
     *
     * @access public
     * @static
     * @return bool
     */
    public function removeKey(string $key): bool
    {
        $filename = self::filename($key, true);

        if (is_file($filename) === false) {
            return false;
        }

        return unlink($filename);
    }
}
