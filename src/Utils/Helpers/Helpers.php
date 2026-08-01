<?php

/**
 * Returns true if $input is an empty string
 *
 * Only strings can be blank - null, 0 and [] are not, which is what separates this from
 * empty(). Use it where "the user submitted nothing" has to stay distinct from "the user
 * submitted a zero".
 *
 * @param mixed $input
 * @return bool
 */
function isBlank($input)
{
    return (is_string($input) && trim($input) === '');
}


/**
 * Returns true if $input is an empty string or null
 *
 * @param mixed $input
 * @return bool
 */
function isBlankOrNull($input)
{
    return $input === null || (is_string($input) && trim($input) === '');
}


/**
 * Returns the value, or null when it is empty
 *
 * Handy when writing to a nullable column: an empty form field arrives as '' and would
 * otherwise be stored as an empty string rather than NULL.
 *
 * @param mixed $input
 * @return mixed
 */
function valueOrNull($input)
{
    return (empty($input) ? null : $input);
}


/**
 * Returns fixed floating number with precision of $precision. Replaces "," to "." and " " to "".
 *
 * @param mixed $input
 * @param int $precision
 * @return float
 */
function fixFloat($input, $precision = -1)
{
    $input = str_replace([',', ' '], ['.', ''], (is_scalar($input) ? (string) $input : ''));

    return ($precision === -1 ? (float)$input : round((float)$input, $precision));
}


/**
 * Trim characters, can be used with array_walk
 *
 * array_walk() calls the callback as ($value, $key), so the key has to occupy the second
 * parameter - without it the key lands in $character_mask and every element gets trimmed
 * of whatever its own key happens to be.
 *
 * @param mixed $value
 * @param int|string|null $key Unused, present so the signature matches array_walk
 * @param string $character_mask Characters to trim
 * @return void
 */
function trimChars(&$value, $key = null, $character_mask = " \t\n\r\0\x0B"): void
{
    /*
        Unicode variant:
            $value = preg_replace('/^['.$character_mask.']*(?U)(.*)['.$character_mask.']*$/u', '\\1', $value);
    */
    $value = trim((is_scalar($value) ? (string) $value : ''), $character_mask);
}


/**
 * Locale specific number_format
 *
 * Prefers the active i18n locale over localeconv(). localeconv() reads LC_NUMERIC, which
 * nothing in the framework sets and which does nothing at all unless the locale has been
 * generated in the container - so on a stock image this formatted every number the C way
 * while the rest of the page was in Latvian.
 *
 * @param int|float|null $number
 * @param int $decimals Precision
 * @return string
 */
function localeNumberFormat($number, $decimals = 2)
{
    if (\StaticPHP\Utils\Models\i18n::isInitialised() === true) {
        return \StaticPHP\Utils\Models\i18n::number($number ?? 0, $decimals);
    }

    $locale = localeconv();
    return number_format($number ?? 0, $decimals, $locale['decimal_point'], $locale['thousands_sep']);
}


/**
 * number_format with explicit separators and an optional "at most n decimals" mode
 *
 * A negative $decimals rounds to that many places and then drops trailing zeros, so 5.10
 * with -2 prints as "5.1" rather than "5.10". Quantities read better that way; money does
 * not, so pass a positive value for money.
 *
 * @param int|float|null $number
 * @param int $decimals Negative for "at most this many"
 * @param string $dec_point
 * @param string $thousands_sep
 * @return string
 */
function cNumberFormat($number, $decimals = 0, $dec_point = '.', $thousands_sep = ' ')
{
    $number = ($number ?? 0);

    if ($decimals < 0) {
        $number = round($number, abs($decimals));
        $decimals = strlen(substr(strrchr((string)$number, '.') ?: '', 1));
    }

    return number_format($number, abs($decimals), $dec_point, $thousands_sep);
}


/**
 * Locale specific date formatting, using an ICU pattern
 *
 * The replacement for strftime(), which is deprecated as of php 8.1. Note that $pattern is
 * an ICU pattern rather than a set of % codes - 'dd.MM.yyyy', not '%d.%m.%Y'. See
 * https://unicode-org.github.io/icu/userguide/format_parse/datetime/#datetime-format-syntax
 *
 * @param string $pattern ICU date pattern
 * @param int|\DateTimeInterface|null $when Timestamp or date object, defaults to now
 * @param string|null $locale Defaults to the i18n locale, then to setlocale()
 * @param string|null $timezone Defaults to the current default timezone
 * @return string
 */
function localeDateFormat($pattern, $when = null, $locale = null, $timezone = null)
{
    if ($locale === null) {
        $locale = \StaticPHP\Utils\Models\ExtendedDateTime::$defaultLocale
            ?: (setlocale(LC_TIME, '0') ?: \Locale::getDefault());

        // setlocale() reports things like lv_LV.UTF-8, ICU wants lv_LV
        $locale = explode('.', (string)$locale)[0];
    }

    if ($when instanceof \DateTimeInterface) {
        $date = $when;
    } elseif ($when === null) {
        $date = new \DateTimeImmutable('now');
    } else {
        // An '@' timestamp is always UTC and carries no zone of its own, which is why the
        // formatter below is handed the target zone rather than the date being shifted
        $date = new \DateTimeImmutable('@' . $when);
    }

    if ($timezone === null) {
        $timezone = date_default_timezone_get();
    }

    $formatter = new \IntlDateFormatter(
        $locale,
        \IntlDateFormatter::FULL,
        \IntlDateFormatter::FULL,
        new \DateTimeZone($timezone),
        \IntlDateFormatter::GREGORIAN,
        $pattern
    );

    $formatted = $formatter->format($date);

    return ($formatted === false ? '' : $formatted);
}


/**
 * Returns unique uuid4 value
 *
 * @return string
 */
function uuid4()
{
    // random_bytes rather than openssl_random_pseudo_bytes: it is core rather than an
    // extension, and it throws when the system has no usable entropy instead of handing
    // back bytes that only might be strong
    $data = random_bytes(16);

    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}


/**
 * Parse query string using $delimiter
 *
 * @param string $str Query string
 * @param string $delimiter Delimiter
 * @return array<string, string>
 */
function parseQueryString($str, $delimiter = '&')
{
    $op = [];
    $pairs = explode(($delimiter === '' ? '&' : $delimiter), $str);
    foreach ($pairs as $pair) {
        $ex = explode("=", $pair);
        if (count($ex) < 2) {
            continue;
        }
        list($k, $v) = array_map("urldecode", $ex);
        $op[$k] = $v;
    }

    return $op;
}


/**
 * Returns array containing week's start and week's end
 *
 * @param int $week A Week
 * @param int $year of a Year
 * @return array{0: int|false, 1: int|false}
 */
function weekRange($week, $year = null): array
{
    if (empty($year)) {
        $year = date('Y');
    }

    $week -= 1;
    $ts = strtotime("{$year}0104 +{$week} weeks"); // By http://en.wikipedia.org/wiki/ISO_8601#Week_dates
    if ($ts === false) {
        return [false, false];
    }

    // "last monday" and "next sunday" against a valid base always resolve, so only the
    // constructed date above can fail to parse
    $start = (date('w', $ts) == 1 ? $ts : strtotime('last monday', $ts));

    return [$start, strtotime('next sunday', $start)];
}


/**
 * Returns which week of its own month a date falls in, counting ISO weeks
 *
 * @param int|null $when A timestamp, defaults to now
 * @return int
 */
function weekOfMonth($when = null)
{
    if ($when === null) {
        $when = time();
    }

    $week = (int)date('W', $when); // note that ISO weeks start on Monday
    $firstWeekOfMonth = (int)date('W', (int)strtotime(date('Y-m-01', $when)));

    // The first days of January can still carry the previous year's week 52 or 53, which
    // would otherwise give a negative offset
    return 1 + ($week < $firstWeekOfMonth ? $week : $week - $firstWeekOfMonth);
}


/**
 * Returns array containing month's start and end timestamps
 *
 * @param int $timestamp A timestamp for which date to calculate first and last day
 * @return array{0: \DateTime, 1: \DateTime}
 */
function monthRangeDateTime($timestamp = null): array
{
    if (empty($timestamp)) {
        $timestamp = new \DateTime("now", new \DateTimeZone('UTC'));
    }

    if (is_int($timestamp)) {
        $timestamp = new \DateTime("@{$timestamp}");
    }

    $start = clone $timestamp;
    $start->modify('first day of this month');
    $start->setTime(00, 00, 00);

    $end = clone $timestamp;
    $end->modify('last day of this month');
    $end->setTime(23, 59, 59);

    return [$start, $end];
}


/**
 * Returns array containing a year's start and end
 *
 * @param int|null $year A year, defaults to the current one
 * @return \StaticPHP\Utils\Models\ExtendedDateTime[]
 */
function yearRangeDateTime($year = null): array
{
    if (empty($year)) {
        $year = (int)date('Y');
    }

    $start = new \StaticPHP\Utils\Models\ExtendedDateTime("{$year}-01-01 00:00:00");

    $end = clone $start;
    $end->modify('last day of december this year');
    $end->setTime(23, 59, 59);

    return [$start, $end];
}


/**
 * Turns a timestamp coming out of the database into a date object, passing null through
 *
 * @param string|null $timestamp
 * @return \StaticPHP\Utils\Models\ExtendedDateTime|null
 */
function sqlTimestampToDatetime(?string $timestamp = null): ?\StaticPHP\Utils\Models\ExtendedDateTime
{
    if (empty($timestamp)) {
        return null;
    }

    return new \StaticPHP\Utils\Models\ExtendedDateTime($timestamp);
}


/**
 * Returns how many weeks there will be or was in a specific year.
 *
 * @param int $year A year
 */
function getIsoWeeksInYear($year): int
{
    $date = new DateTime();
    $date->setISODate($year, 53);
    return ($date->format("W") === "53" ? 53 : 52);
}


/**
 * Returns array containing only values with their keys whose keys are in $keys parameter.
 * Also can return false if $required is specified and any of $keys are missing.
 * Also can fill missing keys with $fill_missing, if its other than false
 *
 * @param mixed $array Original array - anything else returns false
 * @param array $keys Array of keys
 * @param bool $required Whether return false if there are missing keys
 * @param bool|mixed $fill_missing Fill missing keys with this value
 * @param callable|null $callback Applied to each extracted value
 * @param mixed $array Anything; a non-array is reported as false rather than throwing
 * @param iterable<int, string|int> $keys
 * @return array<int|string, mixed>|bool
 */
function extractArrayByKeys($array, $keys, $required = false, $fill_missing = false, $callback = null)
{
    if (is_array($array) === false) {
        return false;
    }

    // Build new array
    $new_array = [];
    foreach ($keys as $key) {
        if (isset($array[$key])) {
            $new_array[$key] = (is_callable($callback) ? $callback($array[$key]) : $array[$key]);
        } elseif ($required === true) {
            return false;
        } elseif ($fill_missing !== false) {
            $new_array[$key] = $fill_missing;
        }
    }

    // Return new array
    return $new_array;
}


/**
 * Returns a boolean value representing whether any of the passed array elements are empty
 *
 * @param array<int|string, mixed> $array
 * @return boolean
 */
function anyEmpty($array)
{
    return (count($array) !== count(array_filter($array)));
}


/**
 * Returns a boolean value representing whether all of the passed array elements are empty
 *
 * @param array<int|string, mixed> $array
 * @return boolean
 */
function allEmpty($array)
{
    return (count(array_filter($array)) === 0);
}


/**
 * Returns true when $key is present in $array and holds a blank string
 *
 * A missing key is not blank - that is the point of the distinction. It tells "the field
 * was cleared" apart from "the field was not submitted" in a partial update.
 *
 * @param array<int|string, mixed> $array
 * @param string|int $key
 * @return bool
 */
function isArrayKeyBlank($array, $key)
{
    return (array_key_exists($key, $array) && isBlank($array[$key]));
}


/**
 * Returns true when $key is present in $array and holds a blank string or null
 *
 * @param array<int|string, mixed> $array
 * @param string|int $key
 * @return bool
 */
function isArrayKeyBlankOrNull($array, $key)
{
    return (array_key_exists($key, $array) && isBlankOrNull($array[$key]));
}


/**
 * Prepends an empty option to a key => value list, giving a dropdown its placeholder row
 *
 * @param array<int|string, mixed> $array
 * @param string|int $key
 * @param string $value
 * @return array<int|string, mixed>
 */
function padEmptyArrayForDropdown($array, $key = '', $value = '')
{
    // Union rather than array_merge: merge renumbers integer keys, which would turn an
    // id => label list into a 0..n list and quietly post the wrong id back
    return [$key => $value] + $array;
}


/**
 * Returns string pointing to a (somehow) random temporary filename
 *
 * @param string $prefix
 * @param string $postfix
 * @return string
 */
function tmpFilename($prefix = 'tmp_', $postfix = '')
{
    // uniqid(rand(), true) is predictable, and returning a name without creating the file
    // leaves a window for another process to place a symlink there first. tempnam creates
    // the file atomically with 0600 permissions.
    $filename = tempnam(sys_get_temp_dir(), $prefix);
    if ($filename === false) {
        throw new \RuntimeException('Could not create a temporary file');
    }

    if (empty($postfix)) {
        return $filename;
    }

    // A suffix means a second, still exclusive, create - O_EXCL fails if the name is taken
    $target = $filename . $postfix;
    $handle = @fopen($target, 'x');
    if ($handle === false) {
        unlink($filename);
        throw new \RuntimeException("Could not create a temporary file: {$target}");
    }

    fclose($handle);
    chmod($target, 0600);
    unlink($filename);

    return $target;
}


/**
 * Translates a $_FILES error code into readable text
 *
 * @param int $code One of the UPLOAD_ERR_* constants
 * @return string
 */
function uploadCodeToMessage($code)
{
    return match ($code) {
        UPLOAD_ERR_OK => 'The file was uploaded successfully',
        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive specified in the HTML form',
        UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
        default => 'Unknown upload error',
    };
}


/**
 * Group $array by $keys.
 * When $keys == ['id', 'name'], Turns [['id' => 1, 'name' => 'Name 1'], ['id' => 2, 'name' => 'Name 2'] into
 * [1 => ['Name 1' => ['id' => 1, 'name' => 'Name 1']], 2 => ['Name 2' => ['id' => 2, 'name' => 'Name 2']]]
 *
 * @param  iterable<int|string, mixed> $array
 * @param  mixed    $keys
 * @param  bool     $unique Describes whether last key of input array is unique
 * @param  callable|null $keys_callback Called as ($key, $item), returns the value to group under
 * @param  callable|null $values_callback Called as ($item), returns what to store
 * @return array<int|string, mixed>  Returns array grouped by keys
 */
function groupArray($array, $keys = [], $unique = false, $keys_callback = null, $values_callback = null)
{
    $keys = (array)$keys;
    $result = [];

    foreach ($array as $item) {
        $x = &$result;
        foreach ($keys as $key) {
            $itemKey = (is_int($key) || is_string($key) ? $key : '');
            $group = (
                is_callable($keys_callback)
                ? $keys_callback($key, $item)
                : (is_array($item) ? ($item[$itemKey] ?? null) : null)
            );
            $groupKey = (is_int($group) || is_string($group) ? $group : '');
            $x = &$x[$groupKey];
        }

        if (is_callable($values_callback)) {
            $item = $values_callback($item);
        }

        if ($unique === true) {
            $x = $item;
        } else {
            $x[] = $item;
        }
    }

    return $result;
}


/**
 * Flattens a list of rows into a key => value array, the shape a dropdown wants.
 * Turns [['id' => 1, 'name' => 'One'], ['id' => 2, 'name' => 'Two']] into [1 => 'One', 2 => 'Two']
 *
 * @param  iterable<int|string, mixed> $array
 * @param  string|null $keys Column to take the key from, null to keep the existing key
 * @param  string $values Column to take the value from
 * @param  callable|null $keys_callback Called as ($item), returns the key
 * @param  callable|null $values_callback Called as ($item), returns the value - null skips the row
 * @param  bool $skip_missing Whether a row missing the $values column is skipped or throws
 * @return array<int|string, mixed>
 */
function simpleArray(
    $array,
    $keys,
    $values,
    $keys_callback = null,
    $values_callback = null,
    $skip_missing = true
): array {
    $result = [];

    foreach ($array as $key => $item) {
        if (is_callable($keys_callback)) {
            $key = $keys_callback($item);
        } elseif ($keys !== null) {
            $key = (is_array($item) ? ($item[$keys] ?? null) : null);
        }

        if (is_int($key) === false && is_string($key) === false) {
            $key = '';
        }

        if (is_callable($values_callback)) {
            $value = $values_callback($item);
            if ($value === null) {
                continue;
            }
            $result[$key] = $value;
        } elseif (is_array($item) && isset($item[$values])) {
            $result[$key] = $item[$values];
        } elseif ($skip_missing === false) {
            throw new \InvalidArgumentException("Missing column: {$values}");
        }
    }

    return $result;
}


/**
 * Returns wheather date has a valid ISO8601 format.
 *
 * @param  string $date string
 * @return bool   Returns true or false
 */
function validISODate($date)
{
    return preg_match('/([0-9]{4})-([0-9]{2})-([0-9]{2})/', $date) == 1;
}


/**
 * Returns wheather datetime has a valid ISO8601 format.
 *
 * @param  string $datetime string
 * @return bool   Returns true or false
 */
function validISODateTime($datetime)
{
    return preg_match(
        '/^'
            . '(\d{4})-(\d{2})-(\d{2})T' // YYYY-MM-DDT ex: 2014-01-01T
            . '(\d{2}):(\d{2}):(\d{2})'  // HH-MM-SS  ex: 17:00:00
            . '(Z|((-|\+)\d{2}:\d{2}))'  // Z or +01:00 or -01:00
            . '$/',
        $datetime
    ) == 1;
}
