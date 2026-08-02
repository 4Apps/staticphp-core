<?php

namespace StaticPHP\Tests\Utils\Models\Crypto;

use PHPUnit\Framework\TestCase;
use StaticPHP\Core\Models\Config;
use StaticPHP\Utils\Models\Crypto\Crypto;
use StaticPHP\Utils\Models\Crypto\CryptoError;

class CryptoTest extends TestCase
{
    /** @var array<string, string> */
    private array $vault = [];

    protected function setUp(): void
    {
        if (extension_loaded('sodium') === false) {
            $this->markTestSkipped('ext-sodium is not available');
        }

        $this->vault = [
            'K1' => base64_encode(str_repeat("\x11", 32)),
            'K0' => base64_encode(str_repeat("\x00", 32)),
            'IDX' => base64_encode(str_repeat("\x22", 32)),
        ];

        $this->configure('k1');
    }

    protected function tearDown(): void
    {
        Crypto::reset();
        unset(Config::$items['crypto']);
    }

    private function configure(string $current): void
    {
        Crypto::reset();

        Config::$items['crypto'] = [
            'key' => $current,
            'keys' => ['k1' => 'K1', 'k0' => 'K0'],
            'index_key' => 'IDX',
            'resolver' => fn(string $name): ?string => $this->vault[$name] ?? null,
        ];
    }

    public function testAValueSurvivesTheRoundTrip(): void
    {
        $encrypted = Crypto::encrypt('12345678901');

        $this->assertNotSame('12345678901', $encrypted);
        $this->assertSame('12345678901', Crypto::decrypt($encrypted));
    }

    public function testUnicodeAndEmptyStringsSurviveToo(): void
    {
        $this->assertSame('Ķīpsalas iela 6', Crypto::decrypt(Crypto::encrypt('Ķīpsalas iela 6')));
        $this->assertSame('', Crypto::decrypt(Crypto::encrypt('')));
    }

    /**
     * A deterministic ciphertext would let anyone holding a dump see which rows share a
     * value, which is most of what the encryption was meant to hide.
     */
    public function testTheSameInputEncryptsDifferentlyEveryTime(): void
    {
        $first = Crypto::encrypt('same');
        $second = Crypto::encrypt('same');

        $this->assertNotSame($first, $second);
        $this->assertSame('same', Crypto::decrypt($first));
        $this->assertSame('same', Crypto::decrypt($second));
    }

    public function testTheStoredValueCarriesTheVersionAndKeyId(): void
    {
        $this->assertStringStartsWith('sp1:k1:', Crypto::encrypt('x'));
        $this->assertSame('k1', Crypto::keyIdOf(Crypto::encrypt('x')));
    }

    public function testNullAndEmptyPassStraightThrough(): void
    {
        $this->assertNull(Crypto::decrypt(null));
        $this->assertSame('', Crypto::decrypt(''));
    }

    /**
     * The reason a column can hold both kinds of row while a backfill runs.
     */
    public function testAPlainValueIsHandedBackUntouched(): void
    {
        $this->assertSame('not encrypted', Crypto::decrypt('not encrypted'));
        $this->assertSame('sp1', Crypto::decrypt('sp1'));
        $this->assertSame('sp1:', Crypto::decrypt('sp1:'));
        $this->assertSame('sp1:BADKEY:zzz', Crypto::decrypt('sp1:BADKEY:zzz'));
    }

    public function testIsEncryptedTellsTheTwoApart(): void
    {
        $this->assertTrue(Crypto::isEncrypted(Crypto::encrypt('x')));
        $this->assertFalse(Crypto::isEncrypted('x'));
        $this->assertFalse(Crypto::isEncrypted(null));
        $this->assertFalse(Crypto::isEncrypted(''));
        $this->assertNull(Crypto::keyIdOf('x'));
    }

    /**
     * secretbox authenticates, so an edit in the database is caught rather than decrypting
     * to something else.
     */
    public function testAnAlteredValueIsRejected(): void
    {
        $encrypted = Crypto::encrypt('12345678901');
        $payload = base64_decode(substr($encrypted, strlen('sp1:k1:')), true);
        $this->assertIsString($payload);

        $payload[30] = ($payload[30] === "\x00" ? "\x01" : "\x00");
        $tampered = 'sp1:k1:' . base64_encode($payload);

        $this->expectException(CryptoError::class);
        $this->expectExceptionMessage('has been altered');

        Crypto::decrypt($tampered);
    }

    public function testATruncatedValueIsRejected(): void
    {
        $this->expectException(CryptoError::class);
        $this->expectExceptionMessage('truncated');

        Crypto::decrypt('sp1:k1:' . base64_encode('short'));
    }

    public function testAKeyThatIsNoLongerListedIsAClearError(): void
    {
        $encrypted = Crypto::encrypt('x', 'k0');

        Config::$items['crypto'] = [
            'key' => 'k1',
            'keys' => ['k1' => 'K1'],
            'resolver' => fn(string $name): ?string => $this->vault[$name] ?? null,
        ];
        Crypto::reset();

        $this->expectException(CryptoError::class);
        $this->expectExceptionMessage('No key "k0"');

        Crypto::decrypt($encrypted);
    }

    /**
     * The point of the key ring: rotating the current key does not make yesterday's rows
     * unreadable.
     */
    public function testValuesUnderARetiredKeyStillDecryptAfterRotation(): void
    {
        $this->configure('k0');
        $old = Crypto::encrypt('written last year');
        $this->assertSame('k0', Crypto::keyIdOf($old));

        $this->configure('k1');

        $this->assertSame('written last year', Crypto::decrypt($old));
        $this->assertSame('k1', Crypto::keyIdOf(Crypto::encrypt('written today')));
    }

    public function testAMissingEnvironmentVariableSaysHowToMakeOne(): void
    {
        $this->vault = [];
        Crypto::reset();

        $this->expectException(CryptoError::class);
        $this->expectExceptionMessage('staticphp crypto key');

        Crypto::encrypt('x');
    }

    public function testKeyMaterialOfTheWrongLengthIsRejected(): void
    {
        $this->vault['K1'] = base64_encode('too short');
        Crypto::reset();

        $this->expectException(CryptoError::class);
        $this->expectExceptionMessage('32 bytes of base64');

        Crypto::encrypt('x');
    }

    public function testAKeyIdThatWouldNotSurviveTheFormatIsRejected(): void
    {
        $this->expectException(CryptoError::class);
        $this->expectExceptionMessage('not a usable key id');

        Crypto::encrypt('x', 'has:colon');
    }

    public function testNoConfiguredCurrentKeyIsAClearError(): void
    {
        Config::$items['crypto'] = ['keys' => []];
        Crypto::reset();

        $this->expectException(CryptoError::class);
        $this->expectExceptionMessage("config['crypto']['key']");

        Crypto::encrypt('x');
    }

    public function testTheBlindIndexIsStableAndLookupable(): void
    {
        $first = Crypto::blindIndex('12345678901');

        $this->assertSame($first, Crypto::blindIndex('12345678901'));
        $this->assertNotSame($first, Crypto::blindIndex('12345678902'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
    }

    /**
     * Keyed, so a stolen dump cannot be attacked by hashing a list of candidate values.
     */
    public function testTheBlindIndexDependsOnItsOwnKey(): void
    {
        $first = Crypto::blindIndex('12345678901');

        $this->vault['IDX'] = base64_encode(str_repeat("\x33", 32));
        Crypto::reset();

        $this->assertNotSame($first, Crypto::blindIndex('12345678901'));
        $this->assertNotSame(hash('sha256', '12345678901'), $first);
    }

    public function testTheBlindIndexNeedsItsKeyConfigured(): void
    {
        Config::$items['crypto'] = [
            'key' => 'k1',
            'keys' => ['k1' => 'K1'],
            'resolver' => fn(string $name): ?string => $this->vault[$name] ?? null,
        ];
        Crypto::reset();

        $this->expectException(CryptoError::class);
        $this->expectExceptionMessage('index_key');

        Crypto::blindIndex('x');
    }

    public function testGeneratedKeysAreUsableKeyMaterial(): void
    {
        $generated = Crypto::generateKey();
        $raw = base64_decode($generated, true);

        $this->assertIsString($raw);
        $this->assertSame(32, strlen($raw));
        $this->assertNotSame($generated, Crypto::generateKey());

        $this->vault['K1'] = $generated;
        Crypto::reset();

        $this->assertSame('x', Crypto::decrypt(Crypto::encrypt('x')));
    }
}
