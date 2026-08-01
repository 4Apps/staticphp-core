<?php

namespace StaticPHP\Core\Models;

use StaticPHP\Core\Models\Config;
use StaticPHP\Core\Models\Router;

/**
 * Core class for loading resources.
 */
class Load
{
    /**
     * Generate UUID v4.
     *
     * Uses random_bytes rather than mt_rand: the Mersenne Twister's state can be
     * recovered from a modest amount of observed output, which would make every
     * subsequent value predictable - including the filenames derived from it below.
     *
     * @access public
     * @static
     * @return string
     */
    public static function uuid4(): string
    {
        $data = random_bytes(16);

        // Set version to 0100 and bits 6-7 to 10, per RFC 4122
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Generate sha1 hash from random v4 uuid.
     *
     * @see Load::uuid4()
     * @access public
     * @static
     * @return string
     */
    public static function randomHash(): string
    {
        return bin2hex(random_bytes(20));
    }

    /**
     * Generate hashed path.
     *
     * Generate hashed path to avoid reaching files per directory limit ({@link http://stackoverflow.com/a/466596}).
     * By default it will create directories 2 levels deep and 2 symbols long, for example,
     * for a filename /www/upload/files/image.jpg, it will generate filename /www/upload/files/ge/ma/image.jpg and
     * optionally create all directories. It is also suggested for cases where image name is not important to set
     * $randomize to true. This way generated filename becomes a sha1 hash and will provide better file distribution
     * between directories.
     *
     * @see Load::randomHash()
     * @access public
     * @static
     * @param  string   $filename
     * @param  bool     $randomize             (default: false)
     * @param  bool     $createDirectories    (default: false)
     * @param  int      $levelsDeep           (default: 2)
     * @param  int      $directoryNameLength (default: 2)
     * @return string[] An array of string objects:
     *                  <ul>
     *                  <li>'hash_dir' Contains only hashed directory (e.g. ge/ma);</li>
     *                  <li>'hash_file' hash_dir + filename (ge/ma/image.jpg);</li>
     *                  <li>'filename' Filename without extension;</li>
     *                  <li>'ext' File extension;</li>
     *                  <li>'dir' Absolute path to file's containing directory, including hashed directories
     *                        (/www/upload/files/ge/ma/);</li>
     *                  <li>'file' Full path to a file.</li>
     *                  </ul>
     */
    public static function hashedPath(
        string $filename,
        bool $randomize = false,
        bool $createDirectories = false,
        int $levelsDeep = 2,
        int $directoryNameLength = 2
    ): array {
        // Explode path to get filename
        $parts = explode(DIRECTORY_SEPARATOR, $filename);

        // Predefine array elements
        $data['hash_dir'] = '';
        $data['hash_file'] = '';

        // Get filename and extension
        $data['filename'] = explode('.', array_pop($parts));
        $data['ext'] = (count($data['filename']) > 1 ? array_pop($data['filename']) : '');
        $data['filename'] = (empty($randomize) ? implode('.', $data['filename']) : self::randomHash());

        if (strlen($data['filename']) < $levelsDeep * $directoryNameLength) {
            throw new \Exception(
                '
                    Filename length too small to satisfy
                    how much sub-directories and how long
                    each directory name should be made.
                '
            );
        }

        // Put directory together
        $dir = (empty($parts) ? '' : implode('/', $parts) . '/');

        // Create hashed directory
        for ($i = 1; $i <= $levelsDeep; ++$i) {
            $data['hash_dir'] .= substr($data['filename'], -1 * $directoryNameLength * $i, $directoryNameLength);
            $data['hash_dir'] .= '/';
        }

        // Put other stuff together
        $data['dir'] = str_replace($data['hash_dir'], '', $dir) . $data['hash_dir'];
        $data['file'] = $data['dir'] . $data['filename'] . (empty($data['ext']) ? '' : '.' . $data['ext']);
        $data['hash_file'] = $data['hash_dir'] . $data['filename'] . (empty($data['ext']) ? '' : '.' . $data['ext']);

        // Create directories
        if (!empty($createDirectories) && !is_dir($data['dir'])) {
            mkdir($data['dir'], 0770, true);
        }

        return $data;
    }

    /**
     * Delete file and directories created by Load::hashedPath.
     *
     * @see Load::hashedPath
     * @access public
     * @static
     * @param  string $filename
     * @return void
     */
    public static function deleteHashedFile(string $filename): void
    {
        $path = self::hashedPath($filename);

        // Trim off / from end
        $path['hash_dir'] = rtrim($path['hash_dir'], '/');
        $path['dir'] = rtrim($path['dir'], '/');

        // Explode hash directories to get the count of them
        $expl = explode('/', $path['hash_dir']);

        // Unlink the file
        if (is_file($path['file'])) {
            unlink($path['file']);
        }

        // Remove directories
        foreach ($expl as $null) {
            if (!@rmdir($path['dir'])) {
                break;
            }

            $path['dir'] = dirname($path['dir']);
        }
    }

    /*
    |-------------------------------------------------------------------------------------------------------------------
    | File Loading
    |-------------------------------------------------------------------------------------------------------------------
    */

    /**
     * The reserved $project name for the framework's own modules.
     *
     * Backed by SP_PATH rather than by a $config['module_paths'] entry, so it resolves
     * whatever an application's config does and in whatever order things are loaded. It
     * was a registered entry first, which meant an application assigning module_paths
     * wiped it and every framework config load then failed - exactly the kind of ordering
     * bug a reserved name cannot have.
     *
     * @var string
     */
    public const FRAMEWORK_PATH = 'staticphp';

    /**
     * Resolve a loadable file to an absolute path.
     *
     * $project names either the reserved "staticphp" or an entry in
     * $config['module_paths'], which maps a name to a directory holding modules. It used
     * to be a directory name appended to BASE_PATH, which assumed every loadable tree was
     * a sibling of the application - true only while the framework was vendored into the
     * same repository, and false the moment it moved to vendor.
     *
     * Entries are module roots, so a $project only means something together with a
     * $module. Without one the file is looked up at the application root, which is decided
     * per request and is not something another project can usefully name.
     *
     * @access private
     * @static
     * @param  string|null $project
     * @param  string|null $module
     * @param  string      $type
     * @param  string      $name
     * @return string
     */
    private static function resolve(?string $project, ?string $module, string $type, string $name): string
    {
        if (empty($module)) {
            if (empty($project) === false) {
                throw new \InvalidArgumentException(
                    "Loading \"{$name}\" from \"{$project}\" needs a module name -"
                        . " module paths point at a directory of modules."
                );
            }

            return APP_PATH . "/{$type}/{$name}.php";
        }

        $root = APP_MODULES_PATH;
        if ($project === self::FRAMEWORK_PATH) {
            $root = SP_PATH;
        } elseif (empty($project) === false) {
            $paths = (array) Config::get('module_paths', []);
            if (isset($paths[$project]) === false) {
                throw new \InvalidArgumentException(
                    "Unknown module path \"{$project}\". Add it to \$config['module_paths']."
                );
            }

            $root = (is_string($paths[$project]) ? $paths[$project] : '');
        }

        return "{$root}/{$module}/{$type}/{$name}.php";
    }

    /**
     * Load configuration files.
     *
     * Load configuration files from current application's config directory (APP_PATH/Config) or
     * from a module of another registered project by naming it in $project.
     *
     * @access public
     * @static
     * @param  array<int|string, string> $files Bare names, or project => name pairs
     * @param  string|null  $project (default: null)
     * @param  ?array<string, mixed> &$config Receives the loaded values; defaults to
     *                                        Config::$items
     * @param-out array<string, mixed> $config
     * @return void
     */
    public static function config(
        array $files,
        ?string $module = null,
        ?string $project = null,
        ?array &$config = null
    ): void {
        if ($config === null) {
            $config = &Config::$items;
        } else {
            Config::$items = &$config;
        }

        foreach ((array) $files as $key => $name) {
            $project1 = $project;
            if (is_numeric($key) === false) {
                $project1 = $name;
                $name = $key;
            }

            require(self::resolve($project1, $module, 'Config', $name));
        }
    }

    /**
     * Load controller files.
     *
     * Load controller files from current application's $module/controllers directory or
     * from other $project/$module/controllers by providing $project name.
     *
     * @access public
     * @static
     * @param  array<int|string, string> $files Bare names, or project => name pairs
     * @param  string|null  $project (default: null)
     * @return void
     */
    public static function controller(array $files, ?string $module = null, ?string $project = null): void
    {
        foreach ((array) $files as $key => $name) {
            $project1 = $project;
            if (is_numeric($key) === false) {
                $project1 = $name;
                $name = $key;
            }

            require(self::resolve($project1, $module, 'Controllers', $name));
        }
    }

    /**
     * Load model files.
     *
     * Load model files from current application's $module/models directory or
     * from other $project/$module/models by providing $project name.
     *
     * @access public
     * @static
     * @param  array<int|string, string> $files Bare names, or project => name pairs
     * @param  string|null  $project (default: null)
     * @return void
     */
    public static function model(array $files, ?string $module = null, ?string $project = null): void
    {
        foreach ((array) $files as $key => $name) {
            $project1 = $project;
            if (is_numeric($key) === false) {
                $project1 = $name;
                $name = $key;
            }

            require(self::resolve($project1, $module, 'Models', $name));
        }
    }

    /**
     * Load helper files.
     *
     * Load helper files from current application's $module/helpers directory or
     * from other $project/$module/helpers by providing $project name.
     *
     * @access public
     * @static
     * @param  array<int|string, string> $files Bare names, or project => name pairs
     * @param  string|null  $project (default: null)
     * @return void
     */
    public static function helper(array $files, ?string $module = null, ?string $project = null): void
    {
        foreach ((array) $files as $key => $name) {
            $project1 = $project;
            if (is_numeric($key) === false) {
                $project1 = $name;
                $name = $key;
            }

            require(self::resolve($project1, $module, 'Helpers', $name));
        }
    }

    /**
     * Keys whose values must never be handed to a template.
     *
     * @var string
     * @access private
     */
    private const SENSITIVE_KEY_PATTERN = '/(pass|passwd|pwd|secret|token|api_?key|credential|salt|dsn|private)/i';

    /**
     * Environment values exposed to templates.
     *
     * The whole of $_ENV used to be a template global, so with symfony/dotenv loading a
     * .env file every template could read the database password. Only the keys named in
     * $config['view_env_keys'] are exposed now.
     *
     * @access private
     * @static
     * @return array<string, mixed>
     */
    private static function safeEnvForViews(): array
    {
        $allowed = Config::getArray('view_env_keys');

        $env = [];
        foreach ($allowed as $key) {
            if ((is_int($key) || is_string($key)) && array_key_exists($key, $_ENV)) {
                $env[$key] = $_ENV[$key];
            }
        }

        return $env;
    }

    /**
     * Configuration exposed to templates, with credentials removed.
     *
     * Config::$items holds the database configuration among other things, so handing it
     * over whole let any template read connection passwords.
     *
     * @access private
     * @static
     * @param  ?array $config (default: null)
     * @param  ?array<string, mixed> $config
     * @param  int    $depth  (default: 0)
     * @return array<string, mixed>
     */
    private static function safeConfigForViews(?array $config = null, int $depth = 0): array
    {
        $config = ($config === null ? Config::$items : $config);

        $safe = [];
        foreach ($config as $key => $value) {
            if (preg_match(self::SENSITIVE_KEY_PATTERN, (string) $key)) {
                continue;
            }

            // The view engine and loader are objects that reach back into everything else
            if ($key === 'view_engine' || $key === 'view_loader' || $key === 'db') {
                continue;
            }

            if (is_array($value)) {
                // Guard against a config array that references itself
                /** @var array<string, mixed> $value */
                $safe[$key] = ($depth >= 16 ? [] : self::safeConfigForViews($value, $depth + 1));
                continue;
            }

            $safe[$key] = $value;
        }

        return $safe;
    }

    /**
     * Render plain php views, for when there is no template engine.
     *
     * The view is included from inside a closure so that $data lands in a scope of its
     * own: extracting into this method would let a key called "files" or "path" overwrite
     * the loop's own variables. "config" is provided the same way twig provides it.
     *
     * @access private
     * @static
     * @param  array<int|string, string> $files
     * @param  array<string, mixed>       $data
     * @return string
     */
    private static function renderPlain(array $files, array $data): string
    {
        $render = static function (string $sp_view_path, array $sp_view_data): void {
            extract($sp_view_data, EXTR_SKIP);

            require $sp_view_path;
        };

        $data['config'] = self::safeConfigForViews();
        $data['env'] = self::safeEnvForViews();

        ob_start();

        try {
            foreach ((array) $files as $file) {
                $path = APP_MODULES_PATH . "/{$file}";
                if (Router::pathIsWithin($path, APP_MODULES_PATH) === false) {
                    throw new \RuntimeException("View outside of the modules directory: \"{$file}\"");
                }

                $render($path, $data);
            }
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * Render a view or multiple views.
     *
     * Render views from current application's view directory (APP_PATH/views).
     * Setting $return to true, instead of outputing, rendered view's html will be returned.
     *
     * @access public
     * @static
     * @param  array $files
     * @param  array<int|string, string> $files
     * @param  array<mixed, mixed> $data  (default: [])
     * @param  bool         $return (default: false)
     * @return string|bool
     */
    public static function view(array $files, array &$data = [], bool $return = false): string|bool
    {
        static $globalsAdded = false;

        // Check for global views variables, can be set, for example, by controller's constructor
        if (!empty(Config::$items['view_data'])) {
            $data = (array) $data + (array) Config::$items['view_data'];
        }

        // No template engine: plain php views. twig/twig is a suggestion of
        // staticphp-core rather than a requirement, so this is the whole view layer for an
        // application that leaves it out, not just an error path.
        if (Config::viewEngine() === null) {
            $rendered = self::renderPlain($files, $data);

            if (!empty($return)) {
                return $rendered;
            }

            echo $rendered;

            return true;
        }

        // Add default view data
        if (empty($globalsAdded)) {
            Config::viewEngine()->addGlobal('env', self::safeEnvForViews());
            Config::viewEngine()->addGlobal('now', Config::$items['now']);
            Config::viewEngine()->addGlobal('date_time', Config::$items['date_time']);
            Config::viewEngine()->addGlobal('config', self::safeConfigForViews());
            Config::viewEngine()->addGlobal('session', $_SESSION ?? []);
            Config::viewEngine()->addGlobal('cookie', $_COOKIE);
            Config::viewEngine()->addGlobal('base_url', Router::$base_url);
            Config::viewEngine()->addGlobal('namespace', Router::$namespace);
            Config::viewEngine()->addGlobal('class', Router::$class);
            Config::viewEngine()->addGlobal('method', Router::$method);
            Config::viewEngine()->addGlobal('segments', Router::$segments);
            $globalsAdded = true;
        }

        // Load view data
        $contents = '';
        foreach ((array) $files as $key => $file) {
            $contents .= Config::viewEngine()->render($file, (array) $data);
        }

        // Output or return view data
        if (empty($return)) {
            echo $contents;

            return true;
        }

        return $contents;
    }
}
