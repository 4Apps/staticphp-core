<?php

namespace StaticPHP\Utils\Models\Doctor;

use PDO;
use StaticPHP\Utils\Models\Db;
use StaticPHP\Utils\Models\Migrations\Discovery;
use StaticPHP\Utils\Models\Migrations\Drivers\Driver;
use StaticPHP\Utils\Models\Migrations\State;
use StaticPHP\Utils\Models\Migrations\States;
use StaticPHP\Utils\Models\Migrations\Tracker;

/**
 * The diagnostics behind `staticphp doctor`.
 *
 * Configuration is handed in rather than read from Config statically, so the whole set of
 * checks can be run against a made up configuration in a test.
 *
 * Nothing here changes anything. Doctor opens connections and reads catalogs; it does not
 * create the migrations table, apply anything or write a file. A tool people are told to
 * run when something is already wrong is the last place for side effects.
 */
class Commands
{
    /**
     * Extensions composer.json marks as required, so a build without one is broken rather
     * than merely limited.
     *
     * @var string[]
     * @access private
     */
    private const REQUIRED_EXTENSIONS = ['intl', 'mbstring', 'pdo'];

    private const MINIMUM_PHP = '8.4.0';

    /** @var array<string, mixed> */
    private array $config;

    /** @var callable */
    private $out;

    private bool $offline;

    /**
     * @access public
     * @param array<string, mixed> $config  Config::$items, or a stand in
     * @param callable             $out     Receives one line at a time, without a newline
     * @param bool                 $offline Skip everything that opens a connection
     */
    public function __construct(array $config, callable $out, bool $offline = false)
    {
        $this->config = $config;
        $this->out = $out;
        $this->offline = $offline;
    }

    /**
     * Run every check and report.
     *
     * @access public
     * @param  bool $strict Treat warnings as failures
     * @return int Process exit code
     */
    public function run(bool $strict = false): int
    {
        $results = $this->checks();

        $counts = [Status::OK->value => 0, Status::WARN->value => 0, Status::FAIL->value => 0];

        foreach ($results as $result) {
            $counts[$result->status->value]++;

            $this->line(sprintf('  %-5s %-14s %s', $result->status->value, $result->check, $result->detail));

            if ($result->fix !== '') {
                $this->line(sprintf('  %-5s %-14s %s', '', '', '-> ' . $result->fix));
            }
        }

        $this->line('');
        $this->line(sprintf(
            '%d ok, %d warning%s, %d failure%s',
            $counts[Status::OK->value],
            $counts[Status::WARN->value],
            ($counts[Status::WARN->value] === 1 ? '' : 's'),
            $counts[Status::FAIL->value],
            ($counts[Status::FAIL->value] === 1 ? '' : 's')
        ));

        if ($counts[Status::FAIL->value] > 0) {
            return 1;
        }

        return ($strict === true && $counts[Status::WARN->value] > 0 ? 1 : 0);
    }

    /**
     * Every diagnostic, in the order they are worth reading.
     *
     * @access public
     * @return list<Result>
     */
    public function checks(): array
    {
        $results = [$this->php()];

        foreach ($this->extensions() as $result) {
            $results[] = $result;
        }

        foreach ($this->connections() as $result) {
            $results[] = $result;
        }

        foreach ($this->migrations() as $result) {
            $results[] = $result;
        }

        $results[] = $this->cacheDirectory();
        $results[] = $this->debug();

        foreach ($this->auditTable() as $result) {
            $results[] = $result;
        }

        return $results;
    }

    /**
     * @access private
     * @param  string $line
     * @return void
     */
    private function line(string $line = ''): void
    {
        ($this->out)($line);
    }

    /**
     * @access private
     * @return Result
     */
    private function php(): Result
    {
        if (version_compare(PHP_VERSION, self::MINIMUM_PHP, '<') === true) {
            return Result::fail(
                'php',
                PHP_VERSION . ', below the ' . self::MINIMUM_PHP . ' this package requires'
            );
        }

        return Result::ok('php', PHP_VERSION);
    }

    /**
     * @access private
     * @return list<Result>
     */
    private function extensions(): array
    {
        $missing = [];
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (extension_loaded($extension) === false) {
                $missing[] = $extension;
            }
        }

        if ($missing !== []) {
            return [Result::fail(
                'extensions',
                'missing ' . implode(', ', $missing),
                'install ext-' . implode(', ext-', $missing)
            )];
        }

        return [Result::ok('extensions', implode(', ', self::REQUIRED_EXTENSIONS))];
    }

    /**
     * Every configured connection, and whether it answers.
     *
     * The pdo driver is checked separately from the connection because the two failures
     * need different people: a missing pdo_pgsql is a build problem, a refused connection
     * is a credentials or firewall problem.
     *
     * @access private
     * @return list<Result>
     */
    private function connections(): array
    {
        $connections = $this->pdoConfigs();

        if ($connections === []) {
            return [Result::warn(
                'database',
                'no connections in config[\'db\'][\'pdo\']',
                'add one, or ignore this if the application does not use a database'
            )];
        }

        $available = PDO::getAvailableDrivers();
        $results = [];

        foreach ($connections as $name => $settings) {
            $dsn = $this->stringAt($settings, 'string');
            $driver = (str_contains($dsn, ':') === true ? explode(':', $dsn, 2)[0] : '');

            if ($driver === '') {
                $results[] = Result::fail("db:{$name}", 'no dsn in the connection config');

                continue;
            }

            if (in_array($driver, $available, true) === false) {
                $results[] = Result::fail(
                    "db:{$name}",
                    "needs the {$driver} pdo driver, which is not loaded",
                    "install ext-pdo_{$driver}"
                );

                continue;
            }

            if ($this->offline === true) {
                $results[] = Result::ok("db:{$name}", "{$driver}, not contacted (--offline)");

                continue;
            }

            $results[] = $this->connect($name, $driver, $settings);
        }

        return $results;
    }

    /**
     * @access private
     * @param  string $name
     * @param  string $driver
     * @param  mixed  $settings
     * @return Result
     */
    private function connect(string $name, string $driver, mixed $settings): Result
    {
        try {
            /** @var array<string, mixed> $config */
            $config = (is_array($settings) ? $settings : []);
            $pdo = Db::init($name, $config);
            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (\Throwable $exception) {
            return Result::fail("db:{$name}", "cannot connect: {$exception->getMessage()}");
        }

        return Result::ok("db:{$name}", $driver . ' ' . (is_scalar($version) ? (string) $version : 'connected'));
    }

    /**
     * The migrations directory, and what the tracking table makes of it.
     *
     * @access private
     * @return list<Result>
     */
    private function migrations(): array
    {
        $settings = $this->arrayAt($this->config, 'migrations');
        if ($settings === []) {
            return [];
        }

        $dir = $this->stringAt($settings, 'dir');
        if ($dir === '' || is_dir($dir) === false) {
            return [Result::fail(
                'migrations',
                ($dir === '' ? 'no directory configured' : "no directory at {$dir}"),
                'staticphp migrate new "first migration"'
            )];
        }

        $connectionName = $this->stringAt($settings, 'connection');
        $connectionName = ($connectionName === '' ? 'default' : $connectionName);

        if ($this->offline === true) {
            return [Result::ok('migrations', "{$dir}, state not checked (--offline)")];
        }

        $connection = $this->pdoConfigs()[$connectionName] ?? null;
        if (is_array($connection) === false) {
            return [Result::fail('migrations', "connection \"{$connectionName}\" is not configured")];
        }

        try {
            /** @var array<string, mixed> $connection */
            $pdo = Db::init($connectionName, $connection);
            $tracker = new Tracker(
                $pdo,
                Driver::forPdo($pdo, $this->stringAt($connection, 'string')),
                ($this->stringAt($settings, 'table') === '' ? 'migrations' : $this->stringAt($settings, 'table'))
            );

            $states = States::compute(Discovery::discover($dir), $tracker->appliedRows());
        } catch (\Throwable $exception) {
            // Most often the tracking table simply is not there yet, which is the normal
            // state of a database nobody has migrated
            return [Result::warn('migrations', "cannot read the state: {$exception->getMessage()}")];
        }

        $results = [];

        $blocking = States::blocking($states);
        if ($blocking !== []) {
            $names = [];
            foreach ($blocking as $state) {
                $names[] = "{$state->name} ({$state->state->value})";
            }

            $results[] = Result::fail(
                'migrations',
                implode(', ', $names),
                'staticphp migrate status'
            );
        }

        $pending = States::pending($states);
        if ($pending !== []) {
            $results[] = Result::warn(
                'migrations',
                count($pending) . ' pending',
                'staticphp migrate apply'
            );
        }

        if ($results === []) {
            $applied = array_filter($states, fn($state) => $state->state === State::APPLIED);
            $results[] = Result::ok('migrations', count($applied) . ' applied, none pending');
        }

        return $results;
    }

    /**
     * The file cache directory, which is the one path the framework writes to by default.
     *
     * @access private
     * @return Result
     */
    private function cacheDirectory(): Result
    {
        $path = $this->stringAt($this->arrayAt($this->arrayAt($this->config, 'cache'), 'files'), 'path');

        if ($path === '') {
            return Result::ok('cache', 'no file cache configured');
        }

        if (is_dir($path) === false) {
            return Result::warn('cache', "no directory at {$path}", 'it is created on first use');
        }

        if (is_writable($path) === false) {
            return Result::fail('cache', "{$path} is not writable", 'check the owner and mode');
        }

        // The framework creates this 0770; anything wider lets any local account read
        // whatever the application decided to cache
        $mode = fileperms($path);
        if ($mode !== false && ($mode & 0007) !== 0) {
            return Result::warn(
                'cache',
                sprintf('%s is world accessible (%04o)', $path, $mode & 0777),
                'chmod 0770'
            );
        }

        return Result::ok('cache', $path);
    }

    /**
     * @access private
     * @return Result
     */
    private function debug(): Result
    {
        $debug = $this->config['debug'] ?? null;

        if (is_callable($debug) === true) {
            return Result::ok('debug', 'decided per request by the application callable');
        }

        if ($debug === true) {
            return Result::warn(
                'debug',
                'on for everybody',
                'turn it off in production, or make it a callable that decides per request'
            );
        }

        return Result::ok('debug', 'off');
    }

    /**
     * Whether the audit table the application is configured to write to exists.
     *
     * Only a table named by a plain string can be checked. A resolver answers per event and
     * cannot be asked in the abstract.
     *
     * @access private
     * @return list<Result>
     */
    private function auditTable(): array
    {
        $settings = $this->arrayAt($this->config, 'audit');
        if ($settings === [] || $this->offline === true) {
            return [];
        }

        $table = $settings['table'] ?? null;
        if (is_string($table) === false || $table === '') {
            return [];
        }

        $connectionName = $this->stringAt($settings, 'connection');
        $connectionName = ($connectionName === '' ? 'default' : $connectionName);

        $connection = $this->pdoConfigs()[$connectionName] ?? null;
        if (is_array($connection) === false) {
            return [Result::fail('audit', "connection \"{$connectionName}\" is not configured")];
        }

        try {
            /** @var array<string, mixed> $connection */
            $pdo = Db::init($connectionName, $connection);
            $statement = $pdo->query("SELECT COUNT(*) FROM {$table} WHERE 1 = 0");

            if ($statement === false) {
                throw new \RuntimeException('the query was refused');
            }
        } catch (\Throwable $exception) {
            return [Result::fail(
                'audit',
                "cannot read {$table}: {$exception->getMessage()}",
                'staticphp audit install, then staticphp migrate apply'
            )];
        }

        return [Result::ok('audit', "{$table} on \"{$connectionName}\"")];
    }

    /**
     * @access private
     * @return array<string, mixed>
     */
    private function pdoConfigs(): array
    {
        return $this->arrayAt($this->arrayAt($this->config, 'db'), 'pdo');
    }

    /**
     * One entry of an untyped configuration bag, as an array.
     *
     * @access private
     * @param  array<string, mixed> $bag
     * @param  string               $key
     * @return array<string, mixed>
     */
    private function arrayAt(array $bag, string $key): array
    {
        $value = $bag[$key] ?? null;

        /** @var array<string, mixed> */
        return (is_array($value) ? $value : []);
    }

    /**
     * One entry of an untyped configuration bag, as a string.
     *
     * @access private
     * @param  mixed  $bag
     * @param  string $key
     * @return string
     */
    private function stringAt(mixed $bag, string $key): string
    {
        $value = (is_array($bag) ? ($bag[$key] ?? null) : null);

        return (is_string($value) ? $value : '');
    }
}
