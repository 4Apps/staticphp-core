<?php

namespace StaticPHP\Utils\Models\Throttle;

use StaticPHP\Core\Models\Config;
use StaticPHP\Utils\Models\Cache\Cache;

/**
 * Rate limiting on top of the cache backends.
 *
 * Nothing here happens on its own. A controller that wants a limit asks for one:
 *
 *     $attempt = Throttle::hit("login:{$email}", 5, 900);
 *     if ($attempt->allowed === false) {
 *         Router::error(429, 'Too many attempts', "Try again in {$attempt->retryAfter} seconds.");
 *     }
 *
 * and clears it once the thing being protected succeeds:
 *
 *     Throttle::clear("login:{$email}");
 *
 * Fixed window rather than sliding: the window opens on the first hit and closes $window
 * seconds later, whatever happens in between. The known cost is the boundary - a client can
 * spend a full allowance at the end of one window and another at the start of the next, so
 * for a limit of 5 per 15 minutes the true worst case is 10 in a little over a moment. That
 * is the right trade for login and form throttling, where the point is to make a password
 * list take weeks rather than to meter an api precisely. It is the wrong trade for billing
 * quotas, and those should be counted in the database where they can be transactional.
 *
 * Counting is read-modify-write and the cache backends offer no compare-and-set, so
 * simultaneous requests can read the same count and let one extra attempt through. That is
 * a rounding error against a limit measured in attempts per quarter hour, and the
 * alternative is a lock held across a network round trip on the hot path of every request.
 */
class Throttle
{
    /**
     * @var array<string, mixed>
     * @access private
     */
    private const DEFAULTS = [
        'cache' => null,
        'prefix' => 'sp_throttle_',
        'fail_open' => true,
    ];

    /**
     * Record an attempt against $key and report where it leaves the limit.
     *
     * The attempt is counted whether or not it is allowed, so a client that keeps hammering
     * does not reset anything by doing so. It does not extend the window either - the reset
     * time is fixed when the window opens.
     *
     * @access public
     * @static
     * @param  string  $key    Whatever is being limited: an ip, an account, an ip and route
     * @param  int     $max    Attempts allowed per window
     * @param  int     $window Window length in seconds
     * @param  ?string $cache  Cache backend name, or null for the configured one
     * @return Attempt
     */
    public static function hit(string $key, int $max, int $window, ?string $cache = null): Attempt
    {
        self::assertLimit($max, $window);

        $now = time();

        try {
            $state = self::read($key, $cache);

            if ($state === null || $state[1] <= $now) {
                $state = [0, $now + $window];
            }

            $hits = $state[0] + 1;
            $resetAt = $state[1];

            Cache::set(
                self::cacheKey($key),
                ['hits' => $hits, 'reset' => $resetAt],
                max(1, $resetAt - $now),
                self::backend($cache)
            );
        } catch (\Throwable $exception) {
            return self::unavailable($exception, $max, $now, $window);
        }

        return self::attempt($max, $hits, $resetAt, $now);
    }

    /**
     * Where $key stands, without counting an attempt.
     *
     * For a page that wants to show "3 tries left" before anything is submitted, and for a
     * gate that should not punish a client for asking.
     *
     * @access public
     * @static
     * @param  string  $key
     * @param  int     $max
     * @param  ?string $cache
     * @return Attempt
     */
    public static function check(string $key, int $max, ?string $cache = null): Attempt
    {
        self::assertLimit($max, 1);

        $now = time();

        try {
            $state = self::read($key, $cache);
        } catch (\Throwable $exception) {
            return self::unavailable($exception, $max, $now, 0);
        }

        if ($state === null || $state[1] <= $now) {
            return new Attempt(true, $max, 0, $max, 0, $now);
        }

        return self::attempt($max, $state[0], $state[1], $now);
    }

    /**
     * Forget everything recorded against $key.
     *
     * Call this the moment the protected thing succeeds, so that a user who mistyped a
     * password four times and then got it right is not one attempt away from a lockout for
     * the rest of the window.
     *
     * @access public
     * @static
     * @param  string  $key
     * @param  ?string $cache
     * @return void
     */
    public static function clear(string $key, ?string $cache = null): void
    {
        try {
            Cache::remove(self::cacheKey($key), self::backend($cache));
        } catch (\Throwable $exception) {
            if (self::failOpen() === false) {
                throw $exception;
            }

            error_log('Throttle: ' . $exception->getMessage());
        }
    }

    /**
     * The settings, framework defaults filled in.
     *
     * @access private
     * @static
     * @return array<string, mixed>
     */
    private static function settings(): array
    {
        $configured = Config::$items['throttle'] ?? null;

        return (is_array($configured) ? $configured + self::DEFAULTS : self::DEFAULTS);
    }

    /**
     * Which cache backend to work through.
     *
     * Null means Cache's own default, which writes to every registered backend and reads
     * from the first. That is consistent - it always reads back what it wrote - and it is
     * what an application with one backend registered wants without configuring anything.
     *
     * @access private
     * @static
     * @param  ?string $override
     * @return ?string
     */
    private static function backend(?string $override): ?string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }

        $configured = self::settings()['cache'] ?? null;

        return (is_string($configured) && $configured !== '' ? $configured : null);
    }

    /**
     * @access private
     * @static
     * @return bool
     */
    private static function failOpen(): bool
    {
        return (self::settings()['fail_open'] ?? true) !== false;
    }

    /**
     * The cache key for a caller's key.
     *
     * Hashed because callers key on the thing being limited, which is routinely an email
     * address or an ip, and those should not end up readable in a shared redis keyspace or
     * in whatever monitoring is pointed at it.
     *
     * @access private
     * @static
     * @param  string $key
     * @return string
     */
    private static function cacheKey(string $key): string
    {
        $prefix = self::settings()['prefix'] ?? null;

        return (is_string($prefix) ? $prefix : '') . hash('sha256', $key);
    }

    /**
     * The stored counter for $key.
     *
     * @access private
     * @static
     * @param  string  $key
     * @param  ?string $cache
     * @return ?array{0: int, 1: int} Hits and reset timestamp, null when nothing is stored
     */
    private static function read(string $key, ?string $cache): ?array
    {
        $stored = Cache::get(self::cacheKey($key), self::backend($cache));

        if (is_array($stored) === false) {
            return null;
        }

        $hits = $stored['hits'] ?? null;
        $reset = $stored['reset'] ?? null;

        // A backend that lost the value, or handed back something written by an older
        // version of this class, starts a fresh window rather than throwing
        if (is_numeric($hits) === false || is_numeric($reset) === false) {
            return null;
        }

        return [(int) $hits, (int) $reset];
    }

    /**
     * @access private
     * @static
     * @param  int $max
     * @param  int $hits
     * @param  int $resetAt
     * @param  int $now
     * @return Attempt
     */
    private static function attempt(int $max, int $hits, int $resetAt, int $now): Attempt
    {
        $allowed = ($hits <= $max);

        return new Attempt(
            $allowed,
            $max,
            $hits,
            max(0, $max - $hits),
            ($allowed === true ? 0 : max(0, $resetAt - $now)),
            $resetAt
        );
    }

    /**
     * What to answer when the cache cannot be reached.
     *
     * fail_open defaults to true because the backend being down is an outage of the cache,
     * not of the application, and refusing every login until redis comes back turns a
     * degraded dependency into a full one. Set it false where the limit is the control
     * itself and letting traffic through unmetered is worse than turning it away.
     *
     * @access private
     * @static
     * @param  \Throwable $exception
     * @param  int        $max
     * @param  int        $now
     * @param  int        $window
     * @return Attempt
     */
    private static function unavailable(\Throwable $exception, int $max, int $now, int $window): Attempt
    {
        if (self::failOpen() === false) {
            throw $exception;
        }

        error_log('Throttle: ' . $exception->getMessage());

        return new Attempt(true, $max, 0, $max, 0, $now + $window);
    }

    /**
     * @access private
     * @static
     * @param  int $max
     * @param  int $window
     * @return void
     */
    private static function assertLimit(int $max, int $window): void
    {
        if ($max < 1) {
            throw new \InvalidArgumentException("Throttle limit must be at least 1, got {$max}");
        }

        if ($window < 1) {
            throw new \InvalidArgumentException("Throttle window must be at least 1 second, got {$window}");
        }
    }
}
