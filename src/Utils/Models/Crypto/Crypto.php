<?php

namespace StaticPHP\Utils\Models\Crypto;

use StaticPHP\Core\Models\Config;

/**
 * Encryption for columns that should not be readable in a database dump.
 *
 * Explicit, like the audit trail: it encrypts what you hand it, when you hand it over.
 * Nothing is wired into Db, so there is no way for a value to be encrypted twice, or to be
 * written in the clear because a hook did not fire.
 *
 *     $data['personal_code'] = Crypto::encrypt($personalCode);
 *     $data['personal_code_bi'] = Crypto::blindIndex($personalCode);
 *     Db::insert('people', $data);
 *
 *     $code = Crypto::decrypt($row['personal_code']);
 *
 * Stored values look like `sp1:k1:<base64>`, and that prefix is doing real work:
 *
 *  - the key id means old values keep decrypting after a rotation, because the ring holds
 *    the retired keys as well as the current one;
 *  - decrypt() can tell a value it wrote from one it did not, so a column can hold a mix of
 *    plaintext and ciphertext while a backfill runs;
 *  - the version lets the construction change later without guessing at what old rows are.
 *
 * XSalsa20-Poly1305 through libsodium's secretbox, which authenticates as well as encrypts:
 * a value edited in the database fails to decrypt instead of decrypting to something else.
 *
 * Encryption costs you your queries. `WHERE personal_code = ?` cannot work on ciphertext,
 * because the same input encrypts differently every time - that is the point of the nonce.
 * blindIndex() gives equality and uniqueness back through a second, deterministic column.
 * Ranges, sorting and LIKE do not come back, and no scheme here will return them.
 */
class Crypto
{
    /**
     * Prefix and format version.
     *
     * @var string
     * @access public
     */
    public const VERSION = 'sp1';

    /**
     * Raw key length. Both secretbox and the blind index hmac take 32 bytes.
     *
     * @var int
     * @access public
     */
    public const KEY_BYTES = 32;

    /**
     * @var array<string, mixed>
     * @access private
     */
    private const DEFAULTS = [
        'key' => '',
        'keys' => [],
        'index_key' => '',
        'resolver' => null,
    ];

    /**
     * Key material by key id, so a rotation over a large table resolves each key once.
     *
     * @var array<string, string>
     * @access private
     * @static
     */
    private static array $cache = [];

    /**
     * Encrypt one value.
     *
     * @access public
     * @static
     * @param  string  $plaintext
     * @param  ?string $keyId Key to encrypt with, or null for the configured current one
     * @return string  The stored form, `sp1:<keyid>:<base64>`
     * @throws CryptoError When the key is missing or unusable
     */
    public static function encrypt(string $plaintext, ?string $keyId = null): string
    {
        self::assertAvailable();

        $id = ($keyId ?? '') !== '' ? (string) $keyId : self::currentKeyId();
        $key = self::key($id);

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return self::VERSION . ':' . $id . ':' . base64_encode($nonce . $cipher);
    }

    /**
     * Decrypt one value.
     *
     * A value that is not in this package's format is handed back untouched, so a column
     * part way through a backfill reads correctly whichever kind of row comes out. Use
     * isEncrypted() where the difference matters.
     *
     * Null and the empty string come back as they went in: a column that is simply not set
     * is not an error.
     *
     * @access public
     * @static
     * @param  ?string $value
     * @return ?string
     * @throws CryptoError When the value is ours but cannot be read
     */
    public static function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $parts = self::split($value);
        if ($parts === null) {
            return $value;
        }

        self::assertAvailable();

        [$id, $payload] = $parts;

        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new CryptoError("Encrypted value under key \"{$id}\" is truncated or not base64");
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, self::key($id));

        if ($plaintext === false) {
            // Either the wrong key or an edited value, and secretbox cannot say which.
            // Both mean the same thing to the caller: do not trust this row.
            throw new CryptoError(
                "Could not decrypt a value under key \"{$id}\": wrong key, or the value has been altered"
            );
        }

        return $plaintext;
    }

    /**
     * A deterministic index for a value, so it can still be looked up.
     *
     * Store it in its own column beside the ciphertext and index that column:
     *
     *     Db::select('people', ['personal_code_bi' => Crypto::blindIndex($code)]);
     *
     * Keyed with its own secret, so somebody holding a database dump cannot test guesses
     * against it the way they could against a plain sha256.
     *
     * Normalise before calling - trim, case fold an email - because "Anna@example.com" and
     * "anna@example.com" produce different indexes and this deliberately does not decide
     * that for you.
     *
     * @access public
     * @static
     * @param  string $value
     * @return string 64 hex characters
     * @throws CryptoError When no index key is configured
     */
    public static function blindIndex(string $value): string
    {
        self::assertAvailable();

        return hash_hmac('sha256', $value, self::indexKey());
    }

    /**
     * Whether this value is one this package wrote.
     *
     * @access public
     * @static
     * @param  ?string $value
     * @return bool
     */
    public static function isEncrypted(?string $value): bool
    {
        return ($value !== null && $value !== '' && self::split($value) !== null);
    }

    /**
     * Which key a stored value was encrypted with.
     *
     * What a rotation reads to decide whether a row needs rewriting.
     *
     * @access public
     * @static
     * @param  ?string $value
     * @return ?string Null when the value is not encrypted
     */
    public static function keyIdOf(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parts = self::split($value);

        return ($parts === null ? null : $parts[0]);
    }

    /**
     * The key id new values are encrypted with.
     *
     * @access public
     * @static
     * @return string
     * @throws CryptoError When none is configured
     */
    public static function currentKeyId(): string
    {
        $settings = self::settings();
        $current = $settings['key'] ?? null;

        if (is_string($current) === false || $current === '') {
            throw new CryptoError("config['crypto']['key'] does not name a key to encrypt with");
        }

        return self::assertKeyId($current);
    }

    /**
     * Generate key material, encoded the way the environment variables expect it.
     *
     * @access public
     * @static
     * @return string
     */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(self::KEY_BYTES));
    }

    /**
     * Forget resolved key material.
     *
     * For tests, and for a long running process that has just been told the keys changed.
     *
     * @access public
     * @static
     * @return void
     */
    public static function reset(): void
    {
        self::$cache = [];
    }

    /**
     * Split a stored value into its key id and payload.
     *
     * @access private
     * @static
     * @param  string $value
     * @return ?array{0: string, 1: string} Null when this is not one of ours
     */
    private static function split(string $value): ?array
    {
        if (str_starts_with($value, self::VERSION . ':') === false) {
            return null;
        }

        $parts = explode(':', $value, 3);
        if (count($parts) !== 3 || $parts[1] === '' || $parts[2] === '') {
            return null;
        }

        if (preg_match('/^[a-z0-9_]{1,16}$/', $parts[1]) !== 1) {
            return null;
        }

        return [$parts[1], $parts[2]];
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
        $configured = Config::$items['crypto'] ?? null;

        return (is_array($configured) ? $configured + self::DEFAULTS : self::DEFAULTS);
    }

    /**
     * Raw key material for a key id.
     *
     * @access private
     * @static
     * @param  string $id
     * @return string
     * @throws CryptoError
     */
    private static function key(string $id): string
    {
        self::assertKeyId($id);

        if (isset(self::$cache[$id]) === true) {
            return self::$cache[$id];
        }

        $keys = self::settings()['keys'] ?? null;
        $source = (is_array($keys) ? ($keys[$id] ?? null) : null);

        if (is_string($source) === false || $source === '') {
            throw new CryptoError(
                "No key \"{$id}\" in config['crypto']['keys']."
                . ' A key stays listed after it is retired, otherwise everything it encrypted becomes unreadable.'
            );
        }

        return self::$cache[$id] = self::material($source, "key \"{$id}\"");
    }

    /**
     * Raw key material for the blind index.
     *
     * A separate key on purpose. The index is deterministic and therefore the weaker of the
     * two, and one key doing both jobs means a leak of the index key is a leak of
     * everything.
     *
     * @access private
     * @static
     * @return string
     * @throws CryptoError
     */
    private static function indexKey(): string
    {
        if (isset(self::$cache['@index']) === true) {
            return self::$cache['@index'];
        }

        $source = self::settings()['index_key'] ?? null;

        if (is_string($source) === false || $source === '') {
            throw new CryptoError("config['crypto']['index_key'] is not set, so blindIndex() has no key");
        }

        return self::$cache['@index'] = self::material($source, 'the blind index key');
    }

    /**
     * Read and decode key material.
     *
     * $source names an environment variable rather than holding the key, because config
     * files are committed and keys must not be.
     *
     * @access private
     * @static
     * @param  string $source
     * @param  string $label  For the error message
     * @return string
     * @throws CryptoError
     */
    private static function material(string $source, string $label): string
    {
        $resolver = self::settings()['resolver'] ?? null;
        $value = (is_callable($resolver) ? $resolver($source) : getenv($source));

        if (is_string($value) === false || $value === '') {
            throw new CryptoError(
                "The environment variable {$source}, holding {$label}, is not set."
                . ' Generate one with: staticphp crypto key'
            );
        }

        $raw = base64_decode($value, true);

        if ($raw === false || strlen($raw) !== self::KEY_BYTES) {
            throw new CryptoError(
                "{$source}, holding {$label}, must be " . self::KEY_BYTES
                . ' bytes of base64. Generate one with: staticphp crypto key'
            );
        }

        return $raw;
    }

    /**
     * @access private
     * @static
     * @param  string $id
     * @return string
     * @throws CryptoError
     */
    private static function assertKeyId(string $id): string
    {
        // Constrained rather than escaped: the id is written into the stored value next to
        // a colon separator, so anything containing one would make the value unsplittable
        if (preg_match('/^[a-z0-9_]{1,16}$/', $id) !== 1) {
            throw new CryptoError(
                "\"{$id}\" is not a usable key id. Use up to 16 of a-z, 0-9 and underscore."
            );
        }

        return $id;
    }

    /**
     * @access private
     * @static
     * @return void
     * @throws CryptoError
     */
    private static function assertAvailable(): void
    {
        if (extension_loaded('sodium') === false) {
            throw new CryptoError('ext-sodium is not loaded, so nothing here can run');
        }
    }
}
