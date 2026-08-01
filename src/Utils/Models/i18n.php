<?php

namespace StaticPHP\Utils\Models;

use StaticPHP\Core\Models\Config;
use StaticPHP\Core\Models\Router;
use StaticPHP\Utils\Models\Translation\Catalog;
use StaticPHP\Utils\Models\Translation\Formatter;
use StaticPHP\Utils\Models\Translation\Locale;
use StaticPHP\Utils\Models\Translation\Locales;
use StaticPHP\Utils\Models\Translation\Negotiator;
use StaticPHP\Utils\Models\Translation\Store;
use StaticPHP\Utils\Models\Translation\TranslationError;

/**
 *  Internationalization (i18n).
 *
 *  Source text is the key. Templates read as english, an unseen string registers itself on
 *  first sight, and a missing translation degrades to something a human can still read
 *  rather than to "nav.header.login".
 *
 *  @example i18n::init();
 *  @example i18n::translate('Log in');
 *  @example i18n::format('{n, plural, zero{# failu} one{# fails} other{# faili}}', ['n' => 21]);
 */
class i18n
{
    /**
     *  Array holding all i18n config.
     *
     * @var ?array<string, mixed>
     * @access public
     * @static
     */
    public static ?array $config = null;

    /**
     *  Array holding info of all available countries, as configured.
     *
     * @var ?array<string, mixed>
     * @access public
     * @static
     */
    public static ?array $countries = null;

    /**
     *  Currently active country, as configured.
     *
     * @var ?array<string, mixed>
     * @access public
     * @static
     */
    public static ?array $current_country = null;

    /**
     *  Current country's abbreviation code.
     *
     * @var ?string
     * @access public
     * @static
     */
    public static $country_code = null;

    /**
     *  Current language's abbreviation code.
     *
     * @var ?string
     * @access public
     * @static
     */
    public static $language_code = null;

    /**
     *  Current url prefix.
     *
     * @var ?string
     * @access public
     * @static
     */
    public static $url_prefix = null;

    /**
     *  Language key the current strings are stored under, e.g. "lv_en".
     *
     * @var ?string
     * @access public
     * @static
     */
    public static $language_key = null;

    /**
     * @var ?Locales
     * @access private
     * @static
     */
    private static ?Locales $locales = null;

    /**
     * @var ?Locale
     * @access private
     * @static
     */
    private static ?Locale $locale = null;

    /**
     * @var ?Store
     * @access private
     * @static
     */
    private static ?Store $store = null;

    /**
     * @var ?Catalog
     * @access private
     * @static
     */
    private static ?Catalog $catalog = null;

    /**
     * One formatter per ICU locale, built on first use.
     *
     * @var Formatter[]
     * @access private
     * @static
     */
    private static array $formatters = [];

    /**
     * Keys already registered during this request, so a string that is on the page a
     * hundred times only ever costs one insert.
     *
     * @var array<string, bool>
     * @access private
     * @static
     */
    private static array $registered = [];

    /*
     * =============================================== Main Methods ====================================================
     */

    /**
     *  Init stuff.
     *
     *  With no arguments the country and language come from the url prefix, and a request
     *  that carries none is redirected to the negotiated or default language.
     *
     * @access public
     * @static
     * @param  ?string $country  Country code, e.g. "lv"
     * @param  ?string $language Language code, e.g. "en"
     * @return void
     * @throws TranslationError When the pairing is not configured
     */
    public static function init(?string $country = null, ?string $language = null): void
    {
        if (empty(Config::$items['i18n'])) {
            Config::load(['i18n'], 'Utils', 'System');
        }

        /** @var array<string, mixed> $config */
        $config = (array) (Config::$items['i18n'] ?? []);
        self::$config = $config;
        self::$countries = (is_array($config['available'] ?? null) ? $config['available'] : []);
        self::$locales = Locales::fromConfig($config);
        self::$formatters = [];
        self::$registered = [];

        $locale = self::resolve($country, $language);
        if ($locale === null) {
            $locale = self::onUnknownLocale();
        }

        self::apply($locale);
        self::load();
    }

    /**
     * Point every static at one locale and build the machinery around it.
     *
     * @access private
     * @static
     * @param  Locale $locale
     * @return void
     */
    private static function apply(Locale $locale): void
    {
        self::$locale = $locale;
        self::$country_code = $locale->countryCode;
        self::$language_code = $locale->language;
        self::$url_prefix = $locale->urlPrefix;
        self::$language_key = $locale->key();

        self::$current_country = null;
        foreach ((array) self::$countries as $country) {
            if (is_array($country) && ($country['code'] ?? null) === $locale->countryCode) {
                self::$current_country = $country;
                break;
            }
        }

        $strict = self::$config['strict'] ?? null;
        if ($strict === null) {
            $strict = (bool) Config::get('debug', false);
        }

        $tables = self::$config['tables'] ?? null;
        $ttl = self::$config['cache_ttl'] ?? null;

        self::$store = new Store(
            self::setting('db_config', 'default'),
            self::setting('db_scheme'),
            (is_array($tables) ? array_filter($tables, is_string(...)) : []),
            (bool) $strict,
        );

        $mode = self::setting('cache', 'external');
        self::$catalog = new Catalog(
            self::$store,
            $mode,
            self::setting('cache_prefix', 'language_'),
            $mode === 'internal' && defined('APP_PATH') === true
                ? APP_PATH . '/Cache/' . self::setting('cache_subdir', 'i18n')
                : null,
            (is_numeric($ttl) ? (int) $ttl : null),
        );

        // ICU carries its own locale data, so nothing this class formats needs the system
        // locale. Third-party code reading localeconv() or strftime() does, which is the
        // only reason this is offered at all
        if (!empty(self::$config['set_locale'])) {
            setlocale(LC_TIME, $locale->icuLocale . '.UTF-8', $locale->icuLocale);
            setlocale(LC_CTYPE, $locale->icuLocale . '.UTF-8', $locale->icuLocale);
        }

        // ExtendedDateTime otherwise reads setlocale(LC_TIME, 0), which is "C" unless
        // something set it - and the container is not required to have the locale generated
        ExtendedDateTime::$defaultLocale = $locale->icuLocale;
    }

    /**
     * Find the locale this request is for.
     *
     * @access private
     * @static
     * @param  ?string $country
     * @param  ?string $language
     * @return ?Locale
     * @throws TranslationError When an explicit pairing is not configured
     */
    private static function resolve(?string $country, ?string $language): ?Locale
    {
        if ($country !== null && $language !== null) {
            // The check this replaced tested in_array($country, ...) against the language
            // list, which only ever passed because every shipped country happens to list
            // its own code as one of its languages
            $locale = self::requireLocales()->find($country, $language);
            if ($locale === null) {
                throw new TranslationError("i18n has no \"{$country}\" country serving \"{$language}\"");
            }

            return $locale;
        }

        foreach ((array) Router::$prefixes as $prefix) {
            $locale = self::requireLocales()->byPrefix((string) $prefix);
            if ($locale !== null) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * What to do about a request that named no language.
     *
     * @access private
     * @static
     * @return Locale
     */
    private static function onUnknownLocale(): Locale
    {
        $target = self::requireLocales()->default();

        if (!empty(self::$config['negotiate'])) {
            $negotiated = Negotiator::best(self::acceptLanguage(), self::requireLocales());
            if ($negotiated !== null) {
                $target = $negotiated;
            }
        }

        if (!empty(self::$config['redirect']) && PHP_SAPI !== 'cli') {
            Router::redirect($target->urlPrefix . Router::$requested_url);
        }

        return $target;
    }

    /**
     *  Load strings for a language, warming the cache if it is cold.
     *
     * @access public
     * @static
     * @param  ?string $language_key
     * @return void
     */
    public static function load(?string $language_key = null): void
    {
        self::assertInitialised();
        self::requireCatalog()->strings($language_key ?? self::requireLanguageKey());
    }

    /**
     *  Returns all strings held for a language.
     *
     * @access public
     * @static
     * @param  ?string $language_key
     * @return array<string, ?string>
     */
    public static function cache(?string $language_key = null): array
    {
        self::assertInitialised();

        return self::requireCatalog()->strings($language_key ?? self::requireLanguageKey());
    }

    /**
     * Gets translated text.
     *
     * @access public
     * @static
     * @param  string  $text         Source text, which is also the key it is stored under
     * @param  array<string, mixed> $replace Placeholders mapped to their values
     * @param  ?string $escape       One of: html, attr, input, js, url
     * @param  ?string $language_key Language to translate into, defaulting to the current one
     * @return string
     */
    public static function translate(
        string $text,
        array $replace = [],
        ?string $escape = null,
        ?string $language_key = null
    ): string {
        $translated = self::lookup($text, $language_key);

        return Formatter::escape(Formatter::replace($translated, $replace), $escape);
    }

    /**
     * Gets translated text and formats it as an ICU message.
     *
     * This is what handles plurals. The categories are a property of the target language -
     * latvian has three and russian four - so the pattern lives in the translation, not in
     * the calling code.
     *
     * @example i18n::format('{n, plural, zero{# faili} one{# fails} other{# faili}}', ['n' => $count]);
     * @access public
     * @static
     * @param  string  $text         Source pattern, which is also the key
     * @param  array<string, mixed> $arguments Named arguments referenced by the pattern
     * @param  ?string $escape       One of: html, attr, input, js, url
     * @param  ?string $language_key
     * @return string
     */
    public static function format(
        string $text,
        array $arguments = [],
        ?string $escape = null,
        ?string $language_key = null
    ): string {
        $translated = self::lookup($text, $language_key);
        $locale = self::localeFor($language_key);

        return Formatter::escape(self::formatter($locale->icuLocale)->message($translated, $arguments), $escape);
    }

    /**
     * Find a string, walking the fallback chain and registering it when it is unknown.
     *
     * @access private
     * @static
     * @param  string  $text
     * @param  ?string $language_key
     * @return string
     */
    private static function lookup(string $text, ?string $language_key): string
    {
        self::assertInitialised();

        $locale = self::localeFor($language_key);
        $chain = !empty(self::$config['fallback'])
            ? self::requireLocales()->fallbackChain($locale)
            : [$locale];

        // An unconfigured key can still be asked for - the cli translates into whatever the
        // caller names - and there is nothing to fall back to in that case
        $requestedKey = $language_key ?? $locale->key();
        if (self::requireLocales()->byKey($requestedKey) === null) {
            $chain = [];
        }

        $keys = array_map(fn(Locale $item): string => $item->key(), $chain);
        if ($keys === [] || $keys[0] !== $requestedKey) {
            array_unshift($keys, $requestedKey);
        }

        foreach ($keys as $key) {
            $strings = self::requireCatalog()->strings($key);
            if (array_key_exists($text, $strings) === true && $strings[$text] !== null) {
                return $strings[$text];
            }
        }

        return self::register($text, $requestedKey);
    }

    /**
     * Store a string nobody has asked for before.
     *
     * The suffix is the point: an untranslated string shows up on the page as "Log in*",
     * which is visible to whoever is reviewing it and searchable in the backend, without
     * anyone having to run an extraction step first.
     *
     * @access private
     * @static
     * @param  string $text
     * @param  string $languageKey
     * @return string
     */
    private static function register(string $text, string $languageKey): string
    {
        $suffixed = $text . self::setting('missing_suffix', '*');

        if (isset(self::$registered[$languageKey . "\0" . $text]) === true) {
            return $suffixed;
        }
        self::$registered[$languageKey . "\0" . $text] = true;

        // In-memory either way, so a page using the same unknown string a hundred times
        // does not go looking for it a hundred times
        self::requireCatalog()->remember($languageKey, $text, $suffixed);

        if (empty(self::$config['auto_register']) || self::requireStore()->isDegraded() === true) {
            return $suffixed;
        }

        $id = self::requireStore()->ensureKey($text);
        if ($id === null) {
            return $suffixed;
        }

        // overwrite: false, because another request may have registered it a moment ago and
        // a translator may already have replaced the placeholder with the real thing
        self::requireStore()->putTranslation($id, $languageKey, $suffixed, false);

        // Only now, and only for this language: the warmed table no longer has every key
        self::requireStore()->markStale($languageKey);

        return $suffixed;
    }

    /**
     * Update an existing translation for one language.
     *
     * @access public
     * @static
     * @param  string  $key          Source text
     * @param  string  $text         Its translation
     * @param  ?string $language_key
     * @return void
     * @throws TranslationError When the key is not registered
     */
    public static function update(string $key, string $text, ?string $language_key = null): void
    {
        self::assertInitialised();

        $languageKey = $language_key ?? self::$language_key;

        if (self::requireStore()->keyId($key) === null) {
            throw new TranslationError("Key \"{$key}\" doesn't exist");
        }

        self::set($key, $text, $languageKey);
    }

    /**
     * Write a translation, registering the key if it is new.
     *
     * @access public
     * @static
     * @param  string  $key          Source text
     * @param  string  $text         Its translation
     * @param  ?string $language_key
     * @return bool
     */
    public static function set(string $key, string $text, ?string $language_key = null): bool
    {
        self::assertInitialised();

        $languageKey = $language_key ?? self::requireLanguageKey();
        $written = self::requireStore()->setTranslation($key, $languageKey, $text);

        if ($written === true) {
            self::requireCatalog()->invalidate($languageKey);
        }

        return $written;
    }

    /**
     * Mark a language's warmed copy stale everywhere.
     *
     * @access public
     * @static
     * @param  ?string $language_key Null for every language
     * @return void
     */
    public static function cacheInvalidate(?string $language_key = null): void
    {
        self::assertInitialised();
        self::requireCatalog()->invalidate($language_key);
    }

    /*
     * =============================================== Formatting ======================================================
     */

    /**
     * @access public
     * @static
     * @param  int|float $number
     * @param  ?int      $decimals Null for the locale's own default
     * @return string
     */
    public static function number(int|float $number, ?int $decimals = null): string
    {
        return self::formatter(self::icuLocale())->number($number, $decimals);
    }

    /**
     * @access public
     * @static
     * @param  int|float $number
     * @param  string    $currency ISO 4217 code
     * @return string
     */
    public static function currency(int|float $number, string $currency = 'EUR'): string
    {
        return self::formatter(self::icuLocale())->currency($number, $currency);
    }

    /**
     * @access public
     * @static
     * @param  int|float $number   1.0 being one hundred percent
     * @param  int       $decimals
     * @return string
     */
    public static function percent(int|float $number, int $decimals = 0): string
    {
        return self::formatter(self::icuLocale())->percent($number, $decimals);
    }

    /**
     * @access public
     * @static
     * @param  \DateTimeInterface|int|string $value
     * @param  ?string                       $pattern ICU pattern, overriding the default style
     * @return string
     */
    public static function date(\DateTimeInterface|int|string $value, ?string $pattern = null): string
    {
        return self::formatter(self::icuLocale())
            ->date($value, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, $pattern);
    }

    /**
     * @access public
     * @static
     * @param  \DateTimeInterface|int|string $value
     * @param  ?string                       $pattern ICU pattern, overriding the default style
     * @return string
     */
    public static function dateTime(\DateTimeInterface|int|string $value, ?string $pattern = null): string
    {
        return self::formatter(self::icuLocale())
            ->date($value, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT, $pattern);
    }

    /**
     * @access public
     * @static
     * @param  \DateTimeInterface|int|string $value
     * @param  ?string                       $pattern ICU pattern, overriding the default style
     * @return string
     */
    public static function time(\DateTimeInterface|int|string $value, ?string $pattern = null): string
    {
        return self::formatter(self::icuLocale())
            ->date($value, \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT, $pattern);
    }

    /*
     * =============================================== Urls ============================================================
     */

    /**
     *  Make a country and language prefix.
     *
     * @access public
     * @static
     * @param  array<string, mixed> $country Entry of config['i18n']['available']
     * @param  string $language
     * @return string
     */
    public static function urlPrefix(array $country, string $language): string
    {
        $format = self::setting('url_format', '{{country}}-{{language}}');
        $code = $country['code'] ?? null;

        return Locales::prefix($format, (is_scalar($code) ? (string) $code : ''), $language);
    }

    /**
     * The current url, served in another language.
     *
     * @access public
     * @static
     * @param  string  $language_key
     * @param  ?string $path         Path to use instead of the current one
     * @return string
     */
    public static function url(string $language_key, ?string $path = null): string
    {
        self::assertInitialised();

        $locale = self::requireLocales()->byKey($language_key);
        if ($locale === null) {
            throw new TranslationError("i18n has no \"{$language_key}\" language");
        }

        $path = ltrim($path ?? (string) Router::$segments_url, '/');

        return Router::baseUrl($locale->urlPrefix . ($path === '' ? '' : '/' . $path));
    }

    /**
     * Every language this page is also served in, ready for rel="alternate" tags.
     *
     * @access public
     * @static
     * @param  bool $sameCountryOnly Only the current country's languages
     * @return array<int, array{key: string, hreflang: string, language: string, country: string, url: string}>
     */
    public static function alternates(bool $sameCountryOnly = false): array
    {
        self::assertInitialised();

        $locales = $sameCountryOnly === true
            ? self::requireLocales()->forCountry(self::requireLocale()->countryCode)
            : self::requireLocales()->all();

        return array_map(
            fn(Locale $locale): array => [
                'key' => $locale->key(),
                'hreflang' => $locale->hreflang(),
                'language' => $locale->language,
                'country' => $locale->countryCode,
                'url' => self::url($locale->key()),
            ],
            $locales
        );
    }

    /**
     * Best configured language for an Accept-Language header.
     *
     * @access public
     * @static
     * @param  ?string $header Defaults to the current request's header
     * @return ?Locale
     */
    public static function negotiate(?string $header = null): ?Locale
    {
        self::assertInitialised();

        return Negotiator::best($header ?? self::acceptLanguage(), self::requireLocales());
    }

    /*
     * =============================================== Accessors =======================================================
     */

    /*
     * The four collaborators below are null until init() or inject() runs. Every method
     * that reaches for one assumes it is there, and reaching through a null gave
     * "call to a member function on null" from somewhere deep in the facade. These say
     * what actually went wrong instead.
     */

    /**
     * The Accept-Language header, if the sapi put a usable one in $_SERVER.
     *
     * @access private
     * @static
     * @return ?string
     */
    private static function acceptLanguage(): ?string
    {
        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;

        return (is_string($header) ? $header : null);
    }

    /**
     * One entry of config['i18n'], as a string.
     *
     * The configuration is an untyped bag, so this is where a setting stops being a mixed.
     *
     * @access private
     * @static
     * @param  string $name
     * @param  string $default
     * @return string
     */
    private static function setting(string $name, string $default = ''): string
    {
        $value = self::$config[$name] ?? null;

        return (is_scalar($value) && (string) $value !== '' ? (string) $value : $default);
    }

    /**
     * @access private
     * @static
     * @return string
     * @throws TranslationError When i18n has not been initialised
     */
    private static function requireLanguageKey(): string
    {
        return self::$language_key ?? throw new TranslationError('i18n::init() has not been called');
    }

    /**
     * @access private
     * @static
     * @return Locales
     * @throws TranslationError When i18n has not been initialised
     */
    private static function requireLocales(): Locales
    {
        return self::$locales ?? throw new TranslationError('i18n::init() has not been called');
    }

    /**
     * @access private
     * @static
     * @return Locale
     * @throws TranslationError When i18n has not been initialised
     */
    private static function requireLocale(): Locale
    {
        return self::$locale ?? throw new TranslationError('i18n::init() has not been called');
    }

    /**
     * @access private
     * @static
     * @return Store
     * @throws TranslationError When i18n has not been initialised
     */
    private static function requireStore(): Store
    {
        return self::$store ?? throw new TranslationError('i18n::init() has not been called');
    }

    /**
     * @access private
     * @static
     * @return Catalog
     * @throws TranslationError When i18n has not been initialised
     */
    private static function requireCatalog(): Catalog
    {
        return self::$catalog ?? throw new TranslationError('i18n::init() has not been called');
    }


    /**
     * @access public
     * @static
     * @return bool
     */
    public static function isInitialised(): bool
    {
        return self::$locale !== null;
    }

    /**
     * Whether a database call has failed and strings are coming back untranslated.
     *
     * @access public
     * @static
     * @return bool
     */
    public static function isDegraded(): bool
    {
        return self::$store !== null && self::requireStore()->isDegraded() === true;
    }

    /**
     * @access public
     * @static
     * @return ?Locale
     */
    public static function locale(): ?Locale
    {
        return self::$locale;
    }

    /**
     * @access public
     * @static
     * @return ?Locales
     */
    public static function locales(): ?Locales
    {
        return self::$locales;
    }

    /**
     * @access public
     * @static
     * @return ?Store
     */
    public static function store(): ?Store
    {
        return self::$store;
    }

    /**
     *  Country and language hash value.
     *
     * @access public
     * @static
     * @return string
     */
    public static function hash(): string
    {
        return sha1((string) self::$country_code . (string) self::$language_code);
    }

    /**
     * Wire an already built store and catalog in, for tests and for the cli.
     *
     * @access public
     * @static
     * @param  array<string, mixed> $config
     * @param  Locale  $locale
     * @param  Store   $store
     * @param  Catalog $catalog
     * @return void
     */
    public static function inject(array $config, Locale $locale, Store $store, Catalog $catalog): void
    {
        self::$config = $config;
        self::$countries = (is_array($config['available'] ?? null) ? $config['available'] : []);
        self::$locales = Locales::fromConfig($config);
        self::$locale = $locale;
        self::$country_code = $locale->countryCode;
        self::$language_code = $locale->language;
        self::$url_prefix = $locale->urlPrefix;
        self::$language_key = $locale->key();
        self::$store = $store;
        self::$catalog = $catalog;
        self::$formatters = [];
        self::$registered = [];
    }

    /**
     * Forget everything, so one process can serve two languages in turn.
     *
     * @access public
     * @static
     * @return void
     */
    public static function reset(): void
    {
        self::$config = null;
        self::$countries = null;
        self::$current_country = null;
        self::$country_code = null;
        self::$language_code = null;
        self::$url_prefix = null;
        self::$language_key = null;
        self::$locales = null;
        self::$locale = null;
        self::$store = null;
        self::$catalog = null;
        self::$formatters = [];
        self::$registered = [];

        ExtendedDateTime::$defaultLocale = null;
    }

    /**
     * Prints debug information.
     *
     * @access public
     * @static
     * @return void
     */
    public static function debug(): void
    {
        echo 'i18n::$url_prefix: ' . var_export(self::$url_prefix, true) . "\n";
        echo 'i18n::$country_code: ' . var_export(self::$country_code, true) . "\n";
        echo 'i18n::$language_code: ' . var_export(self::$language_code, true) . "\n";
        echo 'i18n::$language_key: ' . var_export(self::$language_key, true) . "\n";
        echo 'i18n icu locale: ' . var_export(self::$locale?->icuLocale, true) . "\n";
        echo 'i18n degraded: ' . var_export(self::isDegraded(), true) . "\n";
        echo 'i18n::$current_country: ' . print_r(self::$current_country, true) . "\n";
        echo 'i18n strings: ' . print_r(self::isInitialised() === true ? self::cache() : null, true) . "\n";
    }

    /*
     * =============================================== Twig ============================================================
     */

    /**
     * Register twig methods.
     *
     * Nothing here is marked html safe. The filter this replaced was, which meant both the
     * translation and every value substituted into it went to the page unescaped - so a
     * user supplied name in {{ 'Hello %name%'|translate({'%name%': user.name}) }} was
     * stored xss. Translations that really do carry markup need an explicit |raw.
     *
     * @access public
     * @static
     * @return void
     */
    public static function twigRegister(): void
    {
        if (is_array(Config::$items['view_data'] ?? null) === false) {
            Config::$items['view_data'] = [];
        }

        Config::$items['view_data']['i18n'] = [
            'country_code' => self::$country_code,
            'language_code' => self::$language_code,
            'language_key' => self::$language_key,
            'url_prefix' => self::$url_prefix,
            'countries' => self::$countries,
            'alternates' => self::isInitialised() === true ? self::alternates() : [],
        ];

        // Twig is a suggestion of staticphp-core, not a requirement. The view_data above is
        // still set, so a plain php view can read it.
        $engine = Config::viewEngine();
        if (empty($engine)) {
            return;
        }

        $engine->addFilter(new \Twig\TwigFilter(
            'translate',
            fn(string $text, array $replace = [], ?string $escape = null, ?string $languageKey = null): string
                => self::translate($text, $replace, $escape, $languageKey)
        ));

        $engine->addFilter(new \Twig\TwigFilter(
            'format',
            fn(string $text, array $arguments = [], ?string $escape = null, ?string $languageKey = null): string
                => self::format($text, $arguments, $escape, $languageKey)
        ));

        $engine->addFunction(new \Twig\TwigFunction(
            '_',
            fn(string $text, array $replace = [], ?string $escape = null, ?string $languageKey = null): string
                => self::translate($text, $replace, $escape, $languageKey)
        ));

        $engine->addFunction(new \Twig\TwigFunction(
            '_f',
            fn(string $text, array $arguments = [], ?string $escape = null, ?string $languageKey = null): string
                => self::format($text, $arguments, $escape, $languageKey)
        ));

        $engine->addFunction(new \Twig\TwigFunction(
            'i18n_url',
            fn(string $languageKey, ?string $path = null): string => self::url($languageKey, $path)
        ));

        $engine->addFunction(new \Twig\TwigFunction(
            'i18n_number',
            fn(int|float $number, ?int $decimals = null): string => self::number($number, $decimals)
        ));

        $engine->addFunction(new \Twig\TwigFunction(
            'i18n_currency',
            fn(int|float $number, string $currency = 'EUR'): string => self::currency($number, $currency)
        ));

        $engine->addFunction(new \Twig\TwigFunction(
            'i18n_date',
            fn(\DateTimeInterface|int|string $value, ?string $pattern = null): string => self::date($value, $pattern)
        ));
    }

    /*
     * =============================================== Internals =======================================================
     */

    /**
     * @access private
     * @static
     * @param  ?string $languageKey
     * @return Locale
     */
    private static function localeFor(?string $languageKey): Locale
    {
        if ($languageKey === null || $languageKey === self::$language_key) {
            return self::requireLocale();
        }

        return self::requireLocales()->byKey($languageKey) ?? self::requireLocale();
    }

    /**
     * @access private
     * @static
     * @return string
     */
    private static function icuLocale(): string
    {
        return self::$locale->icuLocale ?? 'en_US';
    }

    /**
     * @access private
     * @static
     * @param  string $icuLocale
     * @return Formatter
     */
    private static function formatter(string $icuLocale): Formatter
    {
        if (isset(self::$formatters[$icuLocale]) === true) {
            return self::$formatters[$icuLocale];
        }

        $strict = self::$config['strict'] ?? null;
        if ($strict === null) {
            $strict = (bool) Config::get('debug', false);
        }

        return self::$formatters[$icuLocale] = new Formatter($icuLocale, (bool) $strict);
    }

    /**
     * @access private
     * @static
     * @return void
     * @throws TranslationError
     */
    private static function assertInitialised(): void
    {
        if (self::$locale === null) {
            throw new TranslationError('i18n::init() has not been called yet');
        }
    }
}
