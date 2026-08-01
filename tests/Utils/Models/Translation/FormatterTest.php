<?php

namespace StaticPHP\Tests\Utils\Models\Translation;

use PHPUnit\Framework\TestCase;
use StaticPHP\Utils\Models\Translation\Formatter;
use StaticPHP\Utils\Models\Translation\TranslationError;

class FormatterTest extends TestCase
{
    /*
    | replace()
    */

    /**
     * str_replace() applies each pair to the result of the last, so ['a' => 'b', 'b' => 'c']
     * turned an "a" into a "c".
     */
    public function testReplacementsDoNotCascade(): void
    {
        $this->assertEquals('b c', Formatter::replace('a b', ['a' => 'b', 'b' => 'c']));
    }

    /**
     * With str_replace() a shorter key that is a prefix of a longer one consumed it first.
     */
    public function testTheLongestMatchingKeyWins(): void
    {
        $this->assertEquals(
            'full',
            Formatter::replace('%name_full%', ['%name%' => 'short', '%name_full%' => 'full'])
        );
    }

    public function testNullBecomesAnEmptyString(): void
    {
        $this->assertEquals('', Formatter::replace('%x%', ['%x%' => null]));
    }

    public function testNonStringValuesAreCast(): void
    {
        $this->assertEquals('42', Formatter::replace('%x%', ['%x%' => 42]));
    }

    public function testNothingToReplaceLeavesTheTextAlone(): void
    {
        $this->assertEquals('%x%', Formatter::replace('%x%', []));
    }

    /*
    | escape()
    */

    public function testHtmlEscaping(): void
    {
        $this->assertEquals(
            '&lt;b&gt; &quot;q&quot; &#039;a&#039;',
            Formatter::escape('<b> "q" \'a\'', 'html')
        );
    }

    /**
     * The escaper this replaced handled ' \r and \n and nothing else, so a backslash or a
     * closing tag walked straight out of the javascript literal it was meant to sit in.
     */
    public function testJavascriptEscapingContainsNoDelimiters(): void
    {
        $escaped = Formatter::escape("</script>\\'\"\n", 'js');

        $this->assertStringNotContainsString('</script>', $escaped);
        $this->assertStringNotContainsString("\n", $escaped);
        // Still a valid body for a quoted literal, which is the whole point of escaping it
        $this->assertNotNull(json_decode('"' . $escaped . '"'));
    }

    public function testUrlEscaping(): void
    {
        $this->assertEquals('a%20b%2Fc', Formatter::escape('a b/c', 'url'));
    }

    public function testNoModeLeavesTheTextAlone(): void
    {
        $this->assertEquals('<b>', Formatter::escape('<b>', null));
    }

    public function testAnUnknownModeLeavesTheTextAlone(): void
    {
        $this->assertEquals('<b>', Formatter::escape('<b>', 'nonsense'));
    }

    /*
    | ICU
    */

    public function testLatvianHasThreePluralCategories(): void
    {
        $formatter = new Formatter('lv_LV', true);
        $pattern = '{n, plural, zero{nulle} one{viens} other{daudz}}';

        $this->assertEquals('nulle', $formatter->message($pattern, ['n' => 0]));
        $this->assertEquals('viens', $formatter->message($pattern, ['n' => 1]));
        $this->assertEquals('daudz', $formatter->message($pattern, ['n' => 5]));
        $this->assertEquals('viens', $formatter->message($pattern, ['n' => 21]));
    }

    public function testRussianHasFour(): void
    {
        $formatter = new Formatter('ru_RU', true);
        $pattern = '{n, plural, one{one} few{few} many{many} other{other}}';

        $this->assertEquals('one', $formatter->message($pattern, ['n' => 1]));
        $this->assertEquals('few', $formatter->message($pattern, ['n' => 3]));
        $this->assertEquals('many', $formatter->message($pattern, ['n' => 5]));
    }

    public function testSelectHandlesGender(): void
    {
        $formatter = new Formatter('en_US', true);
        $pattern = '{g, select, female{She} male{He} other{They}} replied';

        $this->assertEquals('She replied', $formatter->message($pattern, ['g' => 'female']));
        $this->assertEquals('They replied', $formatter->message($pattern, ['g' => 'unknown']));
    }

    public function testAnInvalidPatternThrowsInStrictMode(): void
    {
        $this->expectException(TranslationError::class);

        (new Formatter('en_US', true))->message('{n, plural, one{x}');
    }

    public function testAnInvalidPatternDegradesToItselfOtherwise(): void
    {
        $pattern = '{n, plural, one{x}';

        $this->assertEquals($pattern, (new Formatter('en_US', false))->message($pattern));
    }

    /*
    | Numbers
    */

    public function testLatvianAndAmericanNumbersDiffer(): void
    {
        $latvian = (new Formatter('lv_LV', true))->number(1234.5, 2);
        $american = (new Formatter('en_US', true))->number(1234.5, 2);

        $this->assertStringContainsString(',50', $latvian);
        $this->assertStringContainsString('.50', $american);
        $this->assertStringContainsString(',234', $american);
    }

    public function testTheDecimalCountIsHonoured(): void
    {
        $formatter = new Formatter('en_US', true);

        $this->assertEquals('1.5000', $formatter->number(1.5, 4));
        $this->assertEquals('2', $formatter->number(2, 0));
    }

    public function testCurrencyCarriesItsSymbol(): void
    {
        $this->assertStringContainsString('€', (new Formatter('lv_LV', true))->currency(10, 'EUR'));
        $this->assertStringContainsString('$', (new Formatter('en_US', true))->currency(10, 'USD'));
    }

    public function testPercent(): void
    {
        $this->assertStringContainsString('25', (new Formatter('en_US', true))->percent(0.25));
        $this->assertStringContainsString('%', (new Formatter('en_US', true))->percent(0.25));
    }

    /*
    | Dates
    */

    public function testDatesFollowTheLocale(): void
    {
        $formatter = new Formatter('lv_LV', true);
        $value = new \DateTimeImmutable('2026-08-01 12:00:00', new \DateTimeZone('UTC'));

        $this->assertEquals('2026-08-01', $formatter->date($value, pattern: 'yyyy-MM-dd'));
        $this->assertStringContainsString('2026', $formatter->date($value));
    }

    public function testAStringDateIsAccepted(): void
    {
        $formatter = new Formatter('en_US', true);

        $this->assertEquals('2026-08-01', $formatter->date('2026-08-01', pattern: 'yyyy-MM-dd'));
    }
}
