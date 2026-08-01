<?php

namespace StaticPHP\Tests\Utils\Models\Translation;

use StaticPHP\Core\Models\Config;
use StaticPHP\Core\Models\Router;
use StaticPHP\Utils\Models\Db;
use StaticPHP\Utils\Models\i18n;
use StaticPHP\Utils\Models\Translation\Catalog;
use StaticPHP\Utils\Models\Translation\Store;
use StaticPHP\Utils\Models\Translation\TranslationError;

class I18nTest extends SqliteCase
{
    private ?string $baseUrl = null;
    private ?string $segmentsUrl = null;
    /** @var array<string, string> */
    private array $prefixes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseUrl = Router::$base_url;
        $this->segmentsUrl = Router::$segments_url;
        $this->prefixes = Router::$prefixes;

        $this->boot('lv_lv');
    }

    protected function tearDown(): void
    {
        i18n::reset();

        Router::$base_url = $this->baseUrl;
        Router::$segments_url = $this->segmentsUrl;
        Router::$prefixes = $this->prefixes;

        parent::tearDown();
    }

    /**
     * Point the facade at the test database, as one language.
     */
    /**
     * @param array<string, mixed> $overrides
     */
    private function boot(string $languageKey, array $overrides = []): void
    {
        $config = array_merge($this->config(), $overrides);
        $store = new Store($this->connection, '', [], true);

        $locale = $this->locales->byKey($languageKey);
        $this->assertNotNull($locale, "no locale configured for \"{$languageKey}\"");

        i18n::inject($config, $locale, $store, new Catalog($store, 'none'));
    }

    /*
    | Lookup
    */

    public function testTranslateReturnsTheStoredTranslation(): void
    {
        $this->store->setTranslation('Log in', 'lv_lv', 'Pieslēgties');

        $this->assertEquals('Pieslēgties', i18n::translate('Log in'));
    }

    public function testUnknownStringRegistersItselfAndComesBackMarked(): void
    {
        $this->assertEquals('Log in*', i18n::translate('Log in'));
        $this->assertEquals('Log in*', $this->value('Log in', 'lv_lv'));
    }

    public function testRepeatedUseOfAnUnknownStringRegistersItOnce(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            i18n::translate('Log in');
        }

        $this->assertEquals(1, $this->rowCount('i18n_keys'));
        $this->assertEquals(1, $this->rowCount('i18n_translations'));
    }

    /**
     * The old schema had no unique constraint, so a key registered twice left two rows and
     * the join that loads a language returned it twice.
     */
    public function testRegisteringTheSameKeyTwiceLeavesOneRow(): void
    {
        $id = $this->store->ensureKey('Log in');
        $this->assertNotNull($id);
        $this->store->putTranslation($id, 'lv_lv', 'Log in*', false);
        $this->store->putTranslation($id, 'lv_lv', 'Log in*', false);

        $this->assertEquals(1, $this->rowCount('i18n_translations'));
    }

    public function testAutoRegisterCanBeTurnedOff(): void
    {
        $this->boot('lv_lv', ['auto_register' => false]);

        $this->assertEquals('Log in*', i18n::translate('Log in'));
        $this->assertEquals(0, $this->rowCount('i18n_keys'));
    }

    /**
     * An empty translation is a deliberate "render nothing here", not a missing one. The
     * class this replaced tested it with empty(), so it re-inserted a duplicate row and
     * invalidated the cache on every single request for as long as the row stayed empty.
     */
    public function testAnEmptyTranslationIsHonoured(): void
    {
        $this->store->setTranslation('Optional note', 'lv_lv', '');

        $this->assertEquals('', i18n::translate('Optional note'));
        $this->assertEquals(1, $this->rowCount('i18n_translations'));
    }

    /*
    | Fallback
    */

    public function testMissingTranslationFallsBackToTheCountryDefault(): void
    {
        $this->store->setTranslation('Log in', 'lv_lv', 'Pieslēgties');
        $this->boot('lv_ru');

        $this->assertEquals('Pieslēgties', i18n::translate('Log in'));
    }

    public function testFallbackCanBeTurnedOff(): void
    {
        $this->store->setTranslation('Log in', 'lv_lv', 'Pieslēgties');
        $this->boot('lv_ru', ['fallback' => false]);

        $this->assertEquals('Log in*', i18n::translate('Log in'));
    }

    public function testTheRequestedLanguageWinsOverTheFallback(): void
    {
        $this->store->setTranslation('Log in', 'lv_lv', 'Pieslēgties');
        $this->store->setTranslation('Log in', 'lv_ru', 'Войти');
        $this->boot('lv_ru');

        $this->assertEquals('Войти', i18n::translate('Log in'));
    }

    /*
    | Writing
    */

    /**
     * The update this replaced had no language in its WHERE clause, so editing the latvian
     * text overwrote english and russian with it.
     */
    public function testUpdateOnlyTouchesTheNamedLanguage(): void
    {
        $this->store->setTranslation('Log in', 'lv_lv', 'Pieslēgties');
        $this->store->setTranslation('Log in', 'lv_en', 'Log in');
        $this->store->setTranslation('Log in', 'lv_ru', 'Войти');

        i18n::update('Log in', 'Ienākt', 'lv_lv');

        $this->assertEquals('Ienākt', $this->value('Log in', 'lv_lv'));
        $this->assertEquals('Log in', $this->value('Log in', 'lv_en'));
        $this->assertEquals('Войти', $this->value('Log in', 'lv_ru'));
    }

    public function testUpdateRefusesAnUnregisteredKey(): void
    {
        $this->expectException(TranslationError::class);

        i18n::update('Never seen', 'Nekad', 'lv_lv');
    }

    public function testSetRegistersTheKeyItIsGiven(): void
    {
        $this->assertTrue(i18n::set('Log in', 'Pieslēgties', 'lv_lv'));
        $this->assertEquals('Pieslēgties', $this->value('Log in', 'lv_lv'));
    }

    /*
    | Substitution and escaping
    */

    /**
     * str_replace() walked the array applying each pair to the result of the last, so a
     * value that contained another key got replaced a second time.
     */
    public function testPlaceholdersAreSubstitutedInOnePass(): void
    {
        $this->store->setTranslation('%a% and %b%', 'lv_lv', '%a% un %b%');

        $this->assertEquals(
            '%b% un done',
            i18n::translate('%a% and %b%', ['%a%' => '%b%', '%b%' => 'done'])
        );
    }

    public function testHtmlEscapingIsAvailable(): void
    {
        $this->store->setTranslation('Hello %name%', 'lv_lv', 'Sveiki %name%');

        $this->assertEquals(
            'Sveiki &lt;script&gt;alert(1)&lt;/script&gt;',
            i18n::translate('Hello %name%', ['%name%' => '<script>alert(1)</script>'], 'html')
        );
    }

    /**
     * The js escaper this replaced was a str_replace of ' \r and \n, so a closing tag or a
     * backslash walked straight out of the literal it was supposed to sit in.
     */
    public function testJavascriptEscapingClosesNothing(): void
    {
        $escaped = i18n::translate('</script><b>\\', [], 'js');

        $this->assertStringNotContainsString('</script>', $escaped);
        $this->assertStringNotContainsString('<b>', $escaped);
    }

    /*
    | ICU
    */

    public function testFormatPluralisesForLatvian(): void
    {
        $pattern = '{n, plural, zero{# failu} one{# fails} other{# faili}}';
        $this->store->setTranslation($pattern, 'lv_lv', $pattern);

        $this->assertEquals('0 failu', i18n::format($pattern, ['n' => 0]));
        $this->assertEquals('1 fails', i18n::format($pattern, ['n' => 1]));
        $this->assertEquals('5 faili', i18n::format($pattern, ['n' => 5]));
        // Where a two-form ngettext gets it wrong
        $this->assertEquals('21 fails', i18n::format($pattern, ['n' => 21]));
        $this->assertEquals('11 failu', i18n::format($pattern, ['n' => 11]));
    }

    public function testFormatPluralisesForRussian(): void
    {
        $pattern = '{n, plural, one{# файл} few{# файла} many{# файлов} other{# файла}}';
        $this->store->setTranslation($pattern, 'lv_ru', $pattern);
        $this->boot('lv_ru');

        $this->assertEquals('1 файл', i18n::format($pattern, ['n' => 1]));
        $this->assertEquals('2 файла', i18n::format($pattern, ['n' => 2]));
        $this->assertEquals('5 файлов', i18n::format($pattern, ['n' => 5]));
        $this->assertEquals('21 файл', i18n::format($pattern, ['n' => 21]));
    }

    public function testAnInvalidPatternThrowsInStrictMode(): void
    {
        $this->expectException(TranslationError::class);

        i18n::format('{n, plural, one{# fails}', ['n' => 1]);
    }

    /**
     * The formats block in the old config was read by nothing, and the two helpers that
     * did format numbers read localeconv() - which is the C locale unless something
     * generated and set another one.
     */
    public function testNumbersFollowTheCountryConventions(): void
    {
        $latvian = i18n::number(1234.5, 2);

        $this->boot('ee_en');
        $estonian = i18n::number(1234.5, 2);

        // Both use a comma for the decimal mark, unlike the C locale this used to fall to
        $this->assertStringContainsString(',', $latvian);
        $this->assertStringContainsString(',', $estonian);
        $this->assertStringNotContainsString('.', $latvian);
    }

    public function testCurrencyIsFormattedForTheLocale(): void
    {
        $formatted = i18n::currency(1234.5, 'EUR');

        $this->assertStringContainsString('€', $formatted);
        $this->assertStringContainsString(',', $formatted);
    }

    public function testTheIcuLocaleCombinesLanguageAndCountry(): void
    {
        $this->boot('lv_en');

        $this->assertEquals('en_LV', i18n::locale()?->icuLocale);
    }

    /*
    | Degrading
    */

    /**
     * A translation layer that takes the page down when the database blinks is worse than
     * one that renders english.
     */
    public function testADeadDatabaseRendersSourceStrings(): void
    {
        $store = new Store($this->connection, '', [], false);
        $locale = $this->locales->byKey('lv_lv');
        $this->assertNotNull($locale);
        i18n::inject($this->config(), $locale, $store, new Catalog($store, 'none'));

        Db::query('DROP TABLE i18n_translations', [], $this->connection);
        Db::query('DROP TABLE i18n_keys', [], $this->connection);

        $this->assertEquals('Log in*', i18n::translate('Log in'));
        $this->assertTrue(i18n::isDegraded());
    }

    /*
    | Urls
    */

    public function testUrlRewritesThePrefix(): void
    {
        Router::$base_url = 'http://test';
        Router::$segments_url = 'some/page';

        $this->assertEquals('http://test/lv-ru/some/page', i18n::url('lv_ru'));
        $this->assertEquals('http://test/ee-et/other', i18n::url('ee_et', 'other'));
    }

    public function testAlternatesCoverEveryConfiguredLanguage(): void
    {
        Router::$base_url = 'http://test';
        Router::$segments_url = 'some/page';

        $alternates = i18n::alternates();

        $this->assertCount(5, $alternates);
        $this->assertEquals('lv-LV', $alternates[0]['hreflang']);
        $this->assertEquals('http://test/lv-lv/some/page', $alternates[0]['url']);

        $this->assertCount(3, i18n::alternates(true));
    }

    public function testUrlRefusesAnUnconfiguredLanguage(): void
    {
        $this->expectException(TranslationError::class);

        i18n::url('xx_yy');
    }

    /*
    | init()
    */

    public function testInitPicksTheLocaleOutOfTheUrlPrefix(): void
    {
        Config::$items['i18n'] = $this->config();
        Config::$items['db'] = ['pdo' => [$this->connection => ['string' => "sqlite:{$this->dbFile}"]]];
        Router::$prefixes = ['lv-ru' => 'lv-ru'];

        i18n::init();

        $this->assertEquals('lv_ru', i18n::$language_key);
        $this->assertEquals('lv', i18n::$country_code);
        $this->assertEquals('ru', i18n::$language_code);
        $this->assertEquals('Latvia', i18n::$current_country['name'] ?? null);
    }

    /**
     * The check this replaced tested the country code against the language list, which only
     * ever passed because every shipped country happens to list its own code as a language.
     */
    public function testInitRefusesAnUnconfiguredPairing(): void
    {
        Config::$items['i18n'] = $this->config();
        Router::$prefixes = [];

        $this->expectException(TranslationError::class);

        i18n::init('lv', 'de');
    }

    public function testInitAcceptsAnExplicitPairing(): void
    {
        Config::$items['i18n'] = $this->config();
        Config::$items['db'] = ['pdo' => [$this->connection => ['string' => "sqlite:{$this->dbFile}"]]];
        Router::$prefixes = [];

        i18n::init('ee', 'en');

        $this->assertEquals('ee_en', i18n::$language_key);
        $this->assertEquals('ee-en', i18n::$url_prefix);
    }

    public function testInitWithoutAPrefixNegotiatesFromTheHeader(): void
    {
        Config::$items['i18n'] = $this->config();
        Config::$items['db'] = ['pdo' => [$this->connection => ['string' => "sqlite:{$this->dbFile}"]]];
        Router::$prefixes = [];
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'ru-RU,ru;q=0.9,en;q=0.5';

        i18n::init();
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);

        $this->assertEquals('lv_ru', i18n::$language_key);
    }
}
