<?php

namespace StaticPHP\Tests\Utils\Models\Translation;

use PHPUnit\Framework\TestCase;
use StaticPHP\Utils\Models\Translation\Locale;
use StaticPHP\Utils\Models\Translation\Locales;
use StaticPHP\Utils\Models\Translation\TranslationError;

class LocalesTest extends TestCase
{
    /**
     * @param  array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'url_format' => '{{country}}-{{language}}',
            'available' => [
                ['name' => 'Latvia', 'code' => 'lv', 'languages' => ['lv', 'en', 'ru']],
                ['name' => 'Estonia', 'code' => 'ee', 'languages' => ['et', 'en']],
            ],
        ], $overrides);
    }

    /**
     * Assert a lookup found something, and hand back the non-null result.
     *
     * The lookups return null for "no such locale", which is a case several tests assert
     * on purpose. Where a test means to go on and read the locale, this turns a miss into
     * a named failure rather than a null dereference further down the line.
     */
    private function found(?Locale $locale): Locale
    {
        $this->assertNotNull($locale);

        return $locale;
    }

    public function testEveryPairingIsExpanded(): void
    {
        $locales = Locales::fromConfig($this->config());

        $this->assertCount(5, $locales->all());
        $this->assertEquals('lv_lv', $locales->default()->key());
    }

    public function testTheFirstLanguageOfEachCountryIsItsDefault(): void
    {
        $locales = Locales::fromConfig($this->config());

        $this->assertTrue($this->found($locales->find('lv', 'lv'))->isDefault);
        $this->assertFalse($this->found($locales->find('lv', 'en'))->isDefault);
        $this->assertTrue($this->found($locales->find('ee', 'et'))->isDefault);
    }

    public function testLookupByPrefixAndByKey(): void
    {
        $locales = Locales::fromConfig($this->config());

        $this->assertEquals('lv_ru', $this->found($locales->byPrefix('lv-ru'))->key());
        $this->assertEquals('lv-ru', $this->found($locales->byKey('lv_ru'))->urlPrefix);
        $this->assertNull($locales->byPrefix('de-de'));
        $this->assertNull($locales->byKey('de_de'));
    }

    /**
     * The ICU locale is derived rather than configured, so english on the latvian site
     * formats numbers and dates the latvian way while reading in english.
     */
    public function testTheIcuLocaleIsDerivedFromLanguageAndCountry(): void
    {
        $locales = Locales::fromConfig($this->config());

        $this->assertEquals('en_LV', $this->found($locales->find('lv', 'en'))->icuLocale);
        $this->assertEquals('et_EE', $this->found($locales->find('ee', 'et'))->icuLocale);
    }

    public function testACountryCanOverrideItsIcuLocale(): void
    {
        $locales = Locales::fromConfig($this->config([
            'available' => [
                ['name' => 'Latvia', 'code' => 'lv', 'languages' => ['lv'], 'locale' => 'lv_LV@currency=EUR'],
            ],
        ]));

        $this->assertEquals('lv_LV@currency=EUR', $locales->default()->icuLocale);
    }

    public function testHreflangIsTheLanguageAndTheRegion(): void
    {
        $locales = Locales::fromConfig($this->config());

        $this->assertEquals('en-LV', $this->found($locales->find('lv', 'en'))->hreflang());
    }

    public function testFallbackChainPrefersTheOwnCountryBeforeTheGlobalDefault(): void
    {
        $locales = Locales::fromConfig($this->config());

        $chain = array_map(
            fn($locale): string => $locale->key(),
            $locales->fallbackChain($this->found($locales->byKey('ee_en')))
        );

        $this->assertEquals(['ee_en', 'ee_et', 'lv_lv'], $chain);
    }

    public function testFallbackChainOfTheDefaultIsJustItself(): void
    {
        $locales = Locales::fromConfig($this->config());

        $this->assertCount(1, $locales->fallbackChain($this->found($locales->byKey('lv_lv'))));
    }

    /**
     * Two countries resolving to one prefix makes the url ambiguous, and whichever came
     * second would simply never be reachable.
     */
    public function testDuplicatePrefixesAreRejected(): void
    {
        $this->expectException(TranslationError::class);

        Locales::fromConfig($this->config([
            'url_format' => '{{language}}',
            'available' => [
                ['name' => 'Latvia', 'code' => 'lv', 'languages' => ['en']],
                ['name' => 'Estonia', 'code' => 'ee', 'languages' => ['en']],
            ],
        ]));
    }

    public function testAnEmptyConfigurationIsRejected(): void
    {
        $this->expectException(TranslationError::class);

        Locales::fromConfig(['available' => []]);
    }

    public function testACountryWithoutLanguagesIsRejected(): void
    {
        $this->expectException(TranslationError::class);

        Locales::fromConfig($this->config([
            'available' => [['name' => 'Latvia', 'code' => 'lv', 'languages' => []]],
        ]));
    }
}
