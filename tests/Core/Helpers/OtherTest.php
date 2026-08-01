<?php

namespace StaticPHP\Tests\Core\Helpers;

use PHPUnit\Framework\TestCase;
use StaticPHP\Core\Models\Load;

// Load helper here, because it cannot be loaded more than once.
// The functions under test live in Utils/Helpers/Helpers.php - there is no
// Core/Helpers/Other.php, so this file could never load.
Load::helper(['Helpers'], 'Utils', 'staticphp');

class OtherTest extends TestCase
{
    public function testFixFloat()
    {
        $number = fixFloat('10,30');
        $this->assertEquals(10.3, $number);

        $number = fixFloat('10,31345', 2);
        $this->assertEquals(10.31, $number);
    }


    public function testTrimChars()
    {
        $test = "\r\nAAA\t\n\r\0\x0B";
        trimChars($test);
        $this->assertEquals('AAA', $test);
    }


    public function testTrimCharsWalksArrays()
    {
        // The signature exists for this: array_walk hands the key over as the second
        // argument, and it must not be mistaken for the character mask
        $test = [' one ', "\ttwo\n", 'three '];
        array_walk($test, 'trimChars');
        $this->assertEquals(['one', 'two', 'three'], $test);

        // Numeric keys are the case that used to be trimmed off the values themselves
        $test = ['0abc0', '1abc1'];
        array_walk($test, 'trimChars');
        $this->assertEquals(['0abc0', '1abc1'], $test);
    }


    public function testIsBlank()
    {
        $this->assertTrue(isBlank(''));
        $this->assertTrue(isBlank('   '));
        $this->assertFalse(isBlank('a'));

        // Only strings can be blank
        $this->assertFalse(isBlank(null));
        $this->assertFalse(isBlank(0));
        $this->assertFalse(isBlank([]));
    }


    public function testIsBlankOrNull()
    {
        $this->assertTrue(isBlankOrNull(''));
        $this->assertTrue(isBlankOrNull("\t"));
        $this->assertTrue(isBlankOrNull(null));
        $this->assertFalse(isBlankOrNull(0));
        $this->assertFalse(isBlankOrNull('a'));
    }


    public function testValueOrNull()
    {
        $this->assertNull(valueOrNull(''));
        $this->assertNull(valueOrNull(0));
        $this->assertEquals('a', valueOrNull('a'));
    }


    public function testIsArrayKeyBlank()
    {
        $array = ['set' => 'value', 'cleared' => '', 'nulled' => null];

        $this->assertTrue(isArrayKeyBlank($array, 'cleared'));
        $this->assertFalse(isArrayKeyBlank($array, 'set'));
        $this->assertFalse(isArrayKeyBlank($array, 'nulled'));

        // A key that was never submitted is not blank
        $this->assertFalse(isArrayKeyBlank($array, 'missing'));
    }


    public function testIsArrayKeyBlankOrNull()
    {
        $array = ['set' => 'value', 'cleared' => '', 'nulled' => null];

        $this->assertTrue(isArrayKeyBlankOrNull($array, 'cleared'));
        $this->assertTrue(isArrayKeyBlankOrNull($array, 'nulled'));
        $this->assertFalse(isArrayKeyBlankOrNull($array, 'set'));
        $this->assertFalse(isArrayKeyBlankOrNull($array, 'missing'));
    }


    public function testCNumberFormat()
    {
        $this->assertEquals('1 235', cNumberFormat(1234.56));
        $this->assertEquals('1234.56', cNumberFormat(1234.56, 2, '.', ''));
        $this->assertEquals('0', cNumberFormat(null));

        // A negative precision rounds, then drops the trailing zeros
        $this->assertEquals('5.1', cNumberFormat(5.10, -2, '.', ''));
        $this->assertEquals('5.12', cNumberFormat(5.1234, -2, '.', ''));
        $this->assertEquals('5', cNumberFormat(5.0, -2, '.', ''));
    }


    public function testLocaleDateFormat()
    {
        $when = mktime(14, 30, 0, 5, 9, 2017);

        $this->assertEquals('09.05.2017', localeDateFormat('dd.MM.yyyy', $when, 'en_US'));
        $this->assertEquals('2017-05-09 14:30', localeDateFormat('yyyy-MM-dd HH:mm', $when, 'en_US'));

        // The locale drives the names, not the numbers
        $this->assertEquals('May', localeDateFormat('MMMM', $when, 'en_US'));
        $this->assertEquals('maijs', localeDateFormat('MMMM', $when, 'lv_LV'));

        // Date objects keep their own instant, and the timezone shifts the wall clock
        $date = new \DateTimeImmutable('2017-05-09 14:30:00', new \DateTimeZone('UTC'));
        $this->assertEquals('14:30', localeDateFormat('HH:mm', $date, 'en_US', 'UTC'));
        $this->assertEquals('17:30', localeDateFormat('HH:mm', $date, 'en_US', 'Europe/Riga'));
    }


    public function testUploadCodeToMessage()
    {
        $this->assertStringContainsString('upload_max_filesize', uploadCodeToMessage(UPLOAD_ERR_INI_SIZE));
        $this->assertEquals('No file was uploaded', uploadCodeToMessage(UPLOAD_ERR_NO_FILE));
        $this->assertEquals('Unknown upload error', uploadCodeToMessage(99));
    }


    public function testPadEmptyArrayForDropdown()
    {
        $test = padEmptyArrayForDropdown([1 => 'One', 2 => 'Two'], '', '- select -');
        $this->assertEquals(['', 1, 2], array_keys($test));
        $this->assertEquals('- select -', $test['']);
    }


    public function testUuid4()
    {
        $test = uuid4();
        $this->assertEquals(36, strlen($test));
    }


    public function testParseQueryString()
    {
        $test = parseQueryString('aa=bb&cc=dd');
        $this->assertEquals(['aa' => 'bb', 'cc' => 'dd'], $test);

        $test = parseQueryString('aa=bb#cc=dd', '#');
        $this->assertEquals(['aa' => 'bb', 'cc' => 'dd'], $test);
    }


    public function testWeekRange()
    {
        $year = (int) date('Y');
        $weeks = getIsoWeeksInYear($year);
        for ($i = 1; $i <= $weeks; ++$i) {
            $test = weekRange($i, $year);
            $this->assertTrue(count($test) == 2 && !empty($test[0]) && !empty($test[1]));
        }
    }


    public function testMonthRangeDateTime()
    {
        $test = monthRangeDateTime();
        $this->assertTrue(count($test) == 2 && !empty($test[0]) && !empty($test[1]));
    }


    public function testExtractArrayByKeys()
    {
        $testArr = ['post' => 'data', 'is' => 'awesome'];

        $test = extractArrayByKeys($testArr, ['post', 'is']);
        $this->assertEquals($testArr, $test);

        $test = extractArrayByKeys($testArr, ['post', 'is', 'as'], false, 'get');
        $this->assertEquals($testArr + ['as' => 'get'], $test);

        $test = extractArrayByKeys($testArr, ['post', 'is', 'as'], true);
        $this->assertFalse($test);

        $test = extractArrayByKeys($testArr, ['post'], false, false, 'strtoupper');
        $this->assertEquals(['post' => 'DATA'], $test);

        // The callback only sees values that are actually there
        $test = extractArrayByKeys($testArr, ['post', 'as'], false, 'get', 'strtoupper');
        $this->assertEquals(['post' => 'DATA', 'as' => 'get'], $test);

        $this->assertFalse(extractArrayByKeys('not an array', ['post']));
    }


    public function testAnyEmpty()
    {
        $this->assertFalse(anyEmpty(['a', 'b', 'c']));
        $this->assertTrue(anyEmpty(['a', 'b', '']));
    }


    public function testAllEmpty()
    {
        $this->assertFalse(allEmpty(['', '', 'c']));
        $this->assertTrue(allEmpty(['', '', '']));
    }


    public function testTmpFilename()
    {
        $test = tmpFilename('test_', '_test');
        $this->assertStringContainsString('test_', $test);
        $this->assertStringContainsString('_test', $test);
    }


    public function testGroupArray()
    {
        $rows = [
            ['id' => 1, 'name' => 'Name 1'],
            ['id' => 2, 'name' => 'Name 2'],
            ['id' => 1, 'name' => 'Name 3'],
        ];

        $test = groupArray($rows, 'id');
        $this->assertEquals([1, 2], array_keys($test));
        $this->assertCount(2, $test[1]);
        $this->assertCount(1, $test[2]);
        $this->assertEquals($rows[0], $test[1][0]);

        $test = groupArray($rows, ['id', 'name']);
        $this->assertEquals([$rows[0]], $test[1]['Name 1']);
        $this->assertEquals([$rows[2]], $test[1]['Name 3']);

        // $unique replaces the list with the row itself
        $test = groupArray($rows, ['id', 'name'], true);
        $this->assertEquals($rows[0], $test[1]['Name 1']);
    }


    public function testGroupArrayWithCallbacks()
    {
        $rows = [
            ['id' => 1, 'name' => 'Name 1'],
            ['id' => 2, 'name' => 'Name 2'],
            ['id' => 1, 'name' => 'Name 3'],
        ];

        // The key callback decides what to group under, the value callback what to store
        $test = groupArray(
            $rows,
            'id',
            false,
            fn($key, $item) => 'id_' . $item[$key],
            fn($item) => $item['name']
        );

        $this->assertEquals(['id_1', 'id_2'], array_keys($test));
        $this->assertEquals(['Name 1', 'Name 3'], $test['id_1']);
        $this->assertEquals(['Name 2'], $test['id_2']);
    }


    public function testSimpleArray()
    {
        $rows = [
            ['id' => 1, 'name' => 'One'],
            ['id' => 2, 'name' => 'Two'],
            ['id' => 3],
        ];

        $this->assertEquals([1 => 'One', 2 => 'Two'], simpleArray($rows, 'id', 'name'));

        // A null key keeps the array's own keys
        $this->assertEquals([0 => 'One', 1 => 'Two'], simpleArray($rows, null, 'name'));

        $test = simpleArray($rows, 'id', 'name', null, fn($item) => $item['name'] ?? null);
        $this->assertEquals([1 => 'One', 2 => 'Two'], $test);

        $test = simpleArray($rows, null, 'name', fn($item) => 'row_' . $item['id']);
        $this->assertEquals(['row_1' => 'One', 'row_2' => 'Two'], $test);
    }


    public function testSimpleArrayThrowsOnMissingColumn()
    {
        $this->expectException(\InvalidArgumentException::class);
        simpleArray([['id' => 1]], 'id', 'name', null, null, false);
    }


    public function testWeekOfMonth()
    {
        // 1 May 2017 was a Monday, so the weeks line up exactly
        $this->assertEquals(1, weekOfMonth(mktime(0, 0, 0, 5, 1, 2017)));
        $this->assertEquals(2, weekOfMonth(mktime(0, 0, 0, 5, 8, 2017)));
        $this->assertEquals(5, weekOfMonth(mktime(0, 0, 0, 5, 31, 2017)));

        // January carries the previous year's week number over the turn
        $this->assertEquals(1, weekOfMonth(mktime(0, 0, 0, 1, 1, 2017)));
    }


    public function testYearRangeDateTime()
    {
        [$start, $end] = yearRangeDateTime(2017);

        $this->assertEquals('2017-01-01 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertEquals('2017-12-31 23:59:59', $end->format('Y-m-d H:i:s'));

        [$start, $end] = yearRangeDateTime();
        $this->assertEquals(date('Y') . '-01-01', $start->format('Y-m-d'));
        $this->assertEquals(date('Y') . '-12-31', $end->format('Y-m-d'));
    }


    public function testSqlTimestampToDatetime()
    {
        $this->assertNull(sqlTimestampToDatetime(null));
        $this->assertNull(sqlTimestampToDatetime(''));

        $test = sqlTimestampToDatetime('2017-05-09 14:30:00');
        $this->assertInstanceOf(\StaticPHP\Utils\Models\ExtendedDateTime::class, $test);
        $this->assertEquals('2017-05-09 14:30:00', $test->format('Y-m-d H:i:s'));
    }


    public function testValidISODate()
    {
        $this->assertFalse(validISODate('9.5.2017'));
        $this->assertTrue(validISODate('2017-05-09'));
    }


    public function testValidISODateTime()
    {
        $this->assertFalse(validISODateTime('9.5.2017 3:3'));
        $this->assertTrue(validISODateTime('2017-05-09T03:03:10+02:00'));
    }
}
