<?php

/*
    Form Validation class
    Simple usage:

    Fv::init($_POST);
    Fv::addRules([
        'email' => [
            'valid' => ['required', 'email'],
            'filter' => ['trim'],
        ],
    ]);

    // This will print out all errors
    if (Fv::validate() == false)
    {
        print_r(Fv::$errors_all);
    }

    // And html code, this will output first error for "email" field
    <?php if (($test = Fv::getError('email')) != false): ?>
    <div class="error"><?php echo $test[0]; ?></div>
    <?php endif; ?>

    // Another usage
    <div><input type="text" name="email"<?php echo Fv::setInputValue('email'); ?> /></div>

    // And even this one
    <div><input type="text" name="test[]"<?php echo Fv::setInputValue(['test', 0]); ?> /></div>
*/

namespace StaticPHP\Utils\Models;

use StaticPHP\Core\Models\Config;

class Fv
{
    /**
     * Errors grouped by field name.
     *
     * @var array<string, list<string>>|null
     */
    public ?array $errors = null;

    /**
     * Every error raised, in the order they were raised.
     *
     * @var list<string>|null
     */
    public ?array $errors_all = null;

    /**
     * @var array<string, mixed>
     */
    public array $post = [];

    /**
     * @var array<string, array{
     *     filter?: iterable<mixed>,
     *     valid?: iterable<mixed>,
     *     errors?: array<string, string>,
     *     title?: string
     * }>
     */
    private array $rules = [];

    /**
     * @var array<string, string>
     */
    private array $default_errors = [
        'missing' => 'Field "!name" is missing',
        'required' => 'Field "!name" is required',
        'email' => '"!value" is not a correct e-mail address',
        'date' => '"!value" is not a correct date format',
        'ipv4' => '"!value" is not a correct ipv4 address',
        'ipv6' => '"!value" is not a correct ipv6 address',
        'creditCard' => '"!value" is not a correct credit card number',

        'length' => 'Field "!name" has not correct length',
        'equal' => 'Field "!name" has wrong value',
        'format' => 'Field "!name" has not a correct format',

        'integer' => 'Field "!name" must be integer',
        'float' => 'Field "!name" must be float number',
        'string' => 'Field "!name" can contain only letters, []$/!.?()-\'" and space chars',

        'uploadRequired' => 'Field "!name" is required',
        'uploadSize' => 'Uploaded file is to large',
        'uploadExt' => 'File type is not allowed',
    ];


    public function __construct()
    {
        foreach (func_get_args() as $item) {
            if (is_array($item)) {
                $this->post = array_merge($this->post, $item);
            }
        }
    }

    /**
     * @param array<string, string> $errors
     */
    public function errors(array $errors): void
    {
        $this->default_errors = array_merge($this->default_errors, $errors);
    }


    /**
     * @param array<string, array{
     *     filter?: iterable<mixed>,
     *     valid?: iterable<mixed>,
     *     errors?: array<string, string>,
     *     title?: string
     * }> $rules
     */
    public function addRules(array $rules): void
    {
        $this->rules = array_merge($this->rules, $rules);
    }


    public function validate(): bool
    {
        foreach ($this->rules as $name => $value) {
            if (!isset($this->post[$name])) {
                $this->setError('missing', $name);
            } else {
                $this->filterField($name);
                $this->validateField($name);
            }
        }

        return empty($this->errors);
    }


    public function filterField(string $name): void
    {
        if (!empty($this->rules[$name]['filter'])) {
            foreach ($this->rules[$name]['filter'] as $item) {
                if (empty($item)) {
                    return;
                }

                $matches = $args = [];
                $call = null;

                if (is_callable($item) == false) {
                    // Get args from []
                    if (is_string($item) && preg_match('/(\w+)\[(.*)\]/', $item, $matches)) {
                        $item = $matches[1];
                        $args = explode(',', $matches[2]);
                        $args = str_replace('&#44;', ',', $args);
                    }
                }

                // Add value as first argument
                array_unshift($args, $this->post[$name]);
                array_push($args, $name);
                array_push($args, $this->post);

                // Call function
                $this->post[$name] = $this->callFunc($item, $args);
            }
        }
    }


    public function validateField(string $name): void
    {
        if (!empty($this->rules[$name]['valid'])) {
            foreach ($this->rules[$name]['valid'] as $item) {
                if (empty($item)) {
                    return;
                }

                $matches = $args = [];
                $call = null;

                if (is_callable($item) == false) {
                    // Get args from []
                    if (is_string($item) && preg_match('/(\w+)\[(.*)\]/', $item, $matches)) {
                        $item = $matches[1];
                        $args = explode(',', $matches[2]);
                        $args = str_replace('&#44;', ',', $args);
                    }
                }

                // Add other values
                array_unshift($args, $this->post[$name]);
                array_push($args, $name);
                array_push($args, $this->post);

                // Call function
                if ($this->callFunc($item, $args) === false) {
                    $this->setError($item, $name, $this->post[$name]);
                }
            }
        }
    }


    public function setError(mixed $type, string $name, mixed $value = ''): void
    {
        $tmp = '';
        $this->errors_all[] = &$tmp;
        $this->errors[$name][] = &$tmp;

        if (is_string($type) === false) {
            $type = 'default';
        }

        // !value is the rejected input. Error messages are routinely echoed straight into
        // a page, so escape it here rather than relying on every call site to remember.
        $template = $this->rules[$name]['errors'][$type] ?? ($this->default_errors[$type] ?? '');

        $tmp = strtr(
            $template,
            [
                '!name' => htmlspecialchars(
                    $this->rules[$name]['title'] ?? $name,
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                ),
                '!value' => htmlspecialchars(
                    (is_array($value) || is_object($value) ? '' : self::text($value)),
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                ),
            ]
        );
    }


    public function hasError(string $name): bool
    {
        return !empty($this->errors[$name]);
    }


    /**
     * @return list<string>|false
     */
    public function getError(string $name): array|false
    {
        return (empty($this->errors[$name]) ? false : $this->errors[$name]);
    }


    /**
     * @param ?array<int, mixed> $args
     */
    protected function callFunc(mixed $func, ?array $args = null): mixed
    {
        // Check for callable function
        if (is_callable($func)) {
            $call = &$func;
        } elseif (is_string($func) && method_exists(__CLASS__, $func)) {
            $call = [__CLASS__, $func];
        } elseif (is_string($func) && function_exists($func)) {
            $call = $func;
        }

        // Call method / function
        if (!empty($call) && is_callable($call)) {
            return call_user_func_array($call, $args ?? []);
        }

        return null;
    }




    /**
     * A submitted value as text.
     *
     * Form input arrives as whatever was posted - a string, an array from a multi select,
     * an upload's metadata. The filters and validators below all compare text, and
     * anything that is not scalar has no text form, so it compares as empty rather than
     * throwing on the way in.
     *
     * @access private
     * @static
     * @param  mixed $value
     * @return string
     */
    private static function text(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return (is_scalar($value) ? self::text($value) : '');
    }


    // ######################
    // ### Filter methods ###
    // ######################

    public static function setPlain(mixed $string, string $valid = ''): string
    {
        return (string) preg_replace('/[^a-z_\-0-9\ \p{L}' . $valid . ']+/iu', '', self::text($string));
    }

    public static function setClean(mixed $string): mixed
    {
        if (is_string($string) === false) {
            return $string;
        }

        $string = strip_tags($string);
        $string = stripslashes($string);
        $string = str_replace(['<', '>'], ['&lt;', '&gt;'], $string);
        $string = trim($string, " \r\n\t");

        return $string;
    }

    public static function translit(mixed $string): string
    {
        // Cache current locale, set new one as UTF8
        $current_locale = setlocale(LC_ALL, '0');
        setlocale(LC_ALL, 'en_US.UTF8');

        // Do some magick
        $string = iconv('UTF-8', 'ASCII//TRANSLIT', self::text($string));

        // Revert locale. setlocale() reports false when it cannot read the current one,
        // and handing that back would set the locale from the empty string instead.
        if ($current_locale !== false) {
            setlocale(LC_ALL, $current_locale);
        }

        // Return
        return self::text($string);
    }

    // Requires iconv
    public static function setFriendly(mixed $string): string
    {
        $string = self::translit($string);
        $string = strip_tags($string);
        $string = strtolower($string);
        $string = str_replace([' ', "'", '--', '--'], '-', $string);
        $string = (string) preg_replace('/[^a-z_\-0-9]*/', '', $string);

        return trim($string, '-');
    }

    /**
     * Strip markup that could execute script, using a denylist.
     *
     * @deprecated A denylist cannot be made complete - new elements, attributes and
     *             encodings keep appearing, and each one is a bypass until it is added
     *             here. The two concrete defects this had (a broken alternation and a
     *             stripping pass that ran once instead of looping) are fixed, but the
     *             approach stays fundamentally weaker than the alternatives.
     *
     *             To render untrusted text, escape it - Twig does this by default, and
     *             Html::escape()/html_escape() do it in php. To allow a subset of markup,
     *             use a parser based sanitizer such as symfony/html-sanitizer, which
     *             works from an allowlist.
     *
     * @access public
     * @static
     * @param  mixed $string
     * @return string
     */
    public static function xss(mixed $string): string
    {
        // Decode urls
        $string = rawurldecode(self::text($string));

        // Escape non ending tags
        $string = (string) preg_replace('#(<)([a-z]+[^>]*(</[a-z]*>|</|$))#iu', '&lt;$2', $string);

        // Avoid php tags
        $string = (string) str_ireplace(["\t", '<?php', '<?', '?>'], [' ', '&lt;?php', '&lt;?', '?&gt;'], $string);

        // Clean empty tags.
        // The alternation used U+00A6 (broken bar) instead of "|", which made the negative
        // lookahead match the literal text "input¦br¦img¦hr" and never the tag names.
        $string = (string) preg_replace('#<(?!input|br|img|hr|\/)[^>]*>\s*<\/[^>]*>#iu', '', $string);

        $string = (string) str_ireplace(["&amp;", "&lt;", "&gt;"], ["&amp;amp;", "&amp;lt;", "&amp;gt;"], $string);

        // fix &entitiy\n;
        $string = (string) preg_replace('#(&\#*\w+)[\x00-\x20]+;#u', "$1;", $string);
        $string = (string) preg_replace('#(&\#x*)([0-9A-F]+);*#iu', "$1$2;", $string);

        $string = html_entity_decode($string, ENT_COMPAT, "UTF-8");

        // remove any attribute starting with "on" or xmlns
        $string = (string) preg_replace('#(<[^>]+[\x00-\x20\"\'\/])\ ?(on|xmlns)[^>]*?>#iUu', "$1>", $string);

        // remove javascript: and vbscript: protocol
        $string = (string) preg_replace(
            '#([a-z]*)[\x00-\x20\/]*=[\x00-\x20\/]*([\`\'\"]*)[\x00-\x20\/]*j[\x00-\x20]*a[\x00-\x20]*v[\x00-\x20]*a[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iUu',
            '$1=$2nojavascript...',
            $string
        );
        $string = (string) preg_replace(
            '#([a-z]*)[\x00-\x20\/]*=[\x00-\x20\/]*([\`\'\"]*)[\x00-\x20\/]*v[\x00-\x20]*b[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iUu',
            '$1=$2novbscript...',
            $string
        );
        $string = (string) preg_replace(
            '#([a-z]*)[\x00-\x20\/]*=[\x00-\x20\/]*([\`\'\"]*)[\x00-\x20\/]*-moz-binding[\x00-\x20]*:#Uu',
            '$1=$2nomozbinding...',
            $string
        );
        $string = (string) preg_replace(
            '#([a-z]*)[\x00-\x20\/]*=[\x00-\x20\/]*([\`\'\"]*)[\x00-\x20\/]*data[\x00-\x20]*:#Uu',
            '$1=$2nodata...',
            $string
        );

        //remove any style attributes, IE allows too much stupid things in them, eg.
        //<span style="width: expression(alert('Ping!'));"></span>
        // and in general you really don't want style declarations in your UGC

        $string = (string) preg_replace('#(<[^>]+[\x00-\x20\"\'\/])(class|lang|style|size|face)[^>]*>#iUu', "$1>", $string);

        //remove namespaced elements (we do not need them...)
        $string = (string) preg_replace('#</*\w+:\w[^>]*>#i', "", $string);

        // Remove really unwanted tags.
        // This has to loop: a single pass turns "<scr<script>ipt>" into "<script>", so
        // stripping once reassembles exactly the tag it was meant to remove.
        $tagPattern = '#</*(applet|meta|xml|blink|link|style|script|embed|object|iframe'
            . '|frame|frameset|ilayer|layer|bgsound|title|base|svg|math|form|video|audio'
            . '|details|marquee|template|noscript)[^>]*(>|<|$)#i';
        do {
            $oldstring = $string;
            $string = (string) preg_replace($tagPattern, "", $string);
        } while ($oldstring !== $string);

        return $string;
    }


    public static function setIntOrNull(mixed $value): ?int
    {
        $value = (int) self::text($value);
        return (empty($value) ? null : $value);
    }


    // ##############################
    // ### Record / Array methods ###
    // ##############################

    /**
     * @param  array<string, mixed> $record
     * @param  list<string>         $keys
     * @return array<string, mixed>
     */
    public static function setStringOrNullForRecord(array $record, array $keys): array
    {
        foreach ($keys as $key) {
            $record[$key] = ($record[$key] == '' ? null : $record[$key]);
        }

        return $record;
    }

    /**
     * @param  array<string, mixed> $record
     * @param  list<string>         $keys
     * @return array<string, mixed>
     */
    public static function setIntOrNullForRecord(array $record, array $keys): array
    {
        foreach ($keys as $key) {
            $record[$key] = (empty($record[$key]) && $record[$key] != 0 ? null : (int) self::text($record[$key]));
        }

        return $record;
    }

    /**
     * @param  array<string, mixed> $record
     * @param  list<string>         $keys
     * @return array<string, mixed>
     */
    public static function setIntForRecord(array $record, array $keys): array
    {
        foreach ($keys as $key) {
            $record[$key] = (int) self::text($record[$key]);
        }

        return $record;
    }

    /**
     * @param  array<string, mixed> $record
     * @param  list<string>         $keys
     * @return array<string, mixed>
     */
    public static function setDecOrNullForRecord(array $record, array $keys): array
    {
        foreach ($keys as $key) {
            $record[$key] = (empty($record[$key]) && $record[$key] != 0 ? null : fixFloat($record[$key]));
        }

        return $record;
    }

    /**
     * @param  array<string, mixed> $record
     * @param  list<string>         $keys
     * @return array<string, mixed>
     */
    public static function setDecForRecord(array $record, array $keys): array
    {
        foreach ($keys as $key) {
            $record[$key] = fixFloat($record[$key]);
        }

        return $record;
    }

    /**
     * @param  array<string, mixed> $record
     * @param  list<string>         $keys
     * @return array<string, mixed>
     */
    public static function setCleanForRecord(array $record, array $keys): array
    {
        foreach ($keys as $key) {
            $record[$key] = self::setClean($record[$key]);
        }

        return $record;
    }

    /**
     * @param  array<string, mixed> $record
     * @param  list<string>         $keys
     * @return array<string, mixed>
     */
    public static function setPlainForRecord(array $record, array $keys): array
    {
        foreach ($keys as $key) {
            $record[$key] = self::setPlain($record[$key]);
        }

        return $record;
    }

    /**
     * @param  array<string, mixed> $record
     * @param  list<string>         $keys
     * @return array<string, mixed>
     */
    public static function setValueToBinaryForRecord(array $record, array $keys): array
    {
        foreach ($keys as $key) {
            $record[$key] = (empty($record[$key]) ? 0 : 1);
        }

        return $record;
    }


    // ##########################
    // ### Validation methods ###
    // ##########################

    public static function required(mixed $value): bool
    {
        $value = trim(self::text($value));
        return !empty($value);
    }

    public static function email(mixed $email): bool
    {
        // The previous pattern capped the tld at 4 characters and rejected valid
        // addresses such as anything on .agency or .technology
        return (filter_var(self::text($email), FILTER_VALIDATE_EMAIL) !== false);
    }

    public static function date(
        mixed $value,
        string $format = '^(19|20)[0-9]{2}[- /.](0[1-9]|1[012])[- /.](0[1-9]|[12][0-9]|3[01])$'
    ): bool {
        return self::format($value, $format);
    }

    public static function ipv4(mixed $value): bool
    {
        return (bool) preg_match(
            '/^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/',
            self::text($value)
        );
    }

    public static function ipv6(mixed $value): bool
    {
        return (bool) preg_match(
            '/^(^(([0-9A-F]{1,4}(((:[0-9A-F]{1,4}){5}::[0-9A-F]{1,4})|((:[0-9A-F]{1,4}){4}::[0-9A-F]{1,4}(:[0-9A-F]{1,4}){0,1})|((:[0-9A-F]{1,4}){3}::[0-9A-F]{1,4}(:[0-9A-F]{1,4}){0,2})|((:[0-9A-F]{1,4}){2}::[0-9A-F]{1,4}(:[0-9A-F]{1,4}){0,3})|(:[0-9A-F]{1,4}::[0-9A-F]{1,4}(:[0-9A-F]{1,4}){0,4})|(::[0-9A-F]{1,4}(:[0-9A-F]{1,4}){0,5})|(:[0-9A-F]{1,4}){7}))$|^(::[0-9A-F]{1,4}(:[0-9A-F]{1,4}){0,6})$)|^::$)|^((([0-9A-F]{1,4}(((:[0-9A-F]{1,4}){3}::([0-9A-F]{1,4}){1})|((:[0-9A-F]{1,4}){2}::[0-9A-F]{1,4}(:[0-9A-F]{1,4}){0,1})|((:[0-9A-F]{1,4}){1}::[0-9A-F]{1,4}(:[0-9A-F]{1,4}){0,2})|(::[0-9A-F]{1,4}(:[0-9A-F]{1,4}){0,3})|((:[0-9A-F]{1,4}){0,5})))|([:]{2}[0-9A-F]{1,4}(:[0-9A-F]{1,4}){0,4})):|::)((25[0-5]|2[0-4][0-9]|[0-1]?[0-9]{0,2})\.){3}(25[0-5]|2[0-4][0-9]|[0-1]?[0-9]{0,2})$$/',
            self::text($value)
        );
    }

    public static function creditCard(mixed $value): bool
    {
        $value = (string) preg_replace('/[^0-9]+/', '', self::text($value));

        return (bool) preg_match(
            '/^(?:4[0-9]{12}(?:[0-9]{3})?|5[1-5][0-9]{14}|6011[0-9]{12}|3(?:0[0-5]|[68][0-9])[0-9]{11}|3[47][0-9]{13})$/',
            $value
        );
    }

    public static function length(mixed $value, int $from, int|string|null $to = null): bool
    {
        $len = strlen(self::text($value));
        switch (true) {
            case ($to == '>'):
                return ($len >= $from);

            // This tested '>' a second time, so the "at most" form was unreachable
            case ($to == '<'):
                return ($len <= $from);

            // Cast before testing: ctype_digit() interprets an int between -128 and 255 as
            // an ascii codepoint, so a numeric bound like 10 tested chr(10) and fell
            // through to the "exactly $from" default. Passing null was also deprecated
            // in PHP 8.4.
            case ($to !== null && ctype_digit((string) $to)):
                return ($len >= $from && $len <= $to);

            case ($to == '='):
            default:
                return ($len == $from);
        }
    }

    public static function equal(mixed $value, mixed $equal, bool $cast = false): bool
    {
        return ($cast == false ? $value === $equal : $value == $equal);
    }

    public static function format(mixed $value, string $format = ''): bool
    {
        $format = str_replace('/', '\\/', $format);
        return (bool) preg_match("/$format/", self::text($value));
    }

    public static function integer(mixed $value): bool
    {
        return (bool) preg_match('/^\d+$/x', self::text($value));
    }

    public static function float(mixed $value, string $delimiter = '.'): bool
    {
        return (bool) preg_match('/^\d+' . preg_quote($delimiter, '/') . '?\d+$/', self::text($value));
    }

    public static function string(mixed $value): bool
    {
        return (bool) preg_match('/^[a-z\p{L}]+$/iu', self::text($value));
    }




    public static function uploadRequired(mixed $upload): bool
    {
        return (is_array($upload) && !empty($upload['name']) && !empty($upload['tmp_name']) && !empty($upload['size']));
    }

    public static function uploadSize(mixed $upload, int $size): ?bool
    {
        if (is_array($upload) && self::uploadRequired($upload)) {
            return ($upload['size'] <= $size);
        }

        // Null rather than false: there is no upload to judge, which is not the same as
        // an upload that is too big
        return null;
    }

    public static function uploadExt(mixed $upload, string $extensions): ?bool
    {
        if (is_array($upload) && self::uploadRequired($upload)) {
            $ext = explode(' ', $extensions);
            $tmp = explode('.', self::text($upload['name']));

            return in_array(end($tmp), $ext);
        }

        // See uploadSize(): no upload is not a rejected extension
        return null;
    }




    // ####################
    // ### Form helpers ###
    // ####################

    public static function isGet(): bool
    {
        return (strtolower(self::text($_SERVER['REQUEST_METHOD'] ?? '')) === 'get');
    }

    // $isset checks against $_POST not local self::$post
    /**
     * @param list<string>|string|null $isset
     */
    public static function isPost(array|string|null $isset = null): bool
    {
        // Check if post
        if (strtolower(self::text($_SERVER['REQUEST_METHOD'] ?? '')) !== 'post') {
            return false;
        }

        // Check if isset keys in POST data
        if ($isset !== null) {
            foreach ((array)$isset as $key) {
                if (!isset($_POST[$key])) {
                    return false;
                }
            }
        }

        return true;
    }



    /**
     * @param list<string>|string $name Key, or a path of keys into the posted data
     */
    public function setInputValue(array|string $name): string|false
    {
        if (($field = $this->getField($name)) == false) {
            return false;
        }

        return ' value="' . htmlspecialchars(self::text($field)) . '"';
    }

    /**
     * @param list<string>|string $name Key, or a path of keys into the posted data
     */
    public function setSelected(array|string $name, mixed $test = ''): string|false
    {
        if (($field = $this->getField($name)) == false) {
            return false;
        }

        return ((is_array($field) && in_array($test, $field)) || $field == $test ? ' selected="selected"' : '');
    }


    /**
     * @param list<string>|string $name Key, or a path of keys into the posted data
     */
    public function setChecked(array|string $name): string|false
    {
        if (($field = $this->getField($name)) == false) {
            return false;
        }

        return ' checked="checked"';
    }


    /**
     * @param list<string>|string $name Key, or a path of keys into the posted data
     */
    public function setValue(array|string $name): mixed
    {
        if (($field = $this->getField($name)) == false) {
            return false;
        }

        return $field;
    }


    /**
     * @param list<string>|string $name Key, or a path of keys into the posted data
     */
    private function getField(array|string $name): mixed
    {
        $field = $this->post;
        foreach ((array) $name as $item) {
            if (is_array($field) && isset($field[$item])) {
                $field = &$field[$item];
            } else {
                return false;
            }
        }

        return $field;
    }


    // #############################
    // ### Register Twig filters ###
    // #############################

    public static function registerTwig(): void
    {
        // Twig is a suggestion of staticphp-core, not a requirement, so there may be no
        // engine to register against
        if (Config::viewEngine() === null) {
            return;
        }

        // Register filters
        $filter = new \Twig\TwigFilter('fvPlain', function ($value, $valid = '') {
            return \StaticPHP\Utils\Models\Fv::setPlain($value);
        });
        Config::viewEngine()->addFilter($filter);

        $filter = new \Twig\TwigFilter('fvFriendly', function ($value) {
            return \StaticPHP\Utils\Models\Fv::setFriendly($value);
        });
        Config::viewEngine()->addFilter($filter);

        $filter = new \Twig\TwigFilter('fvXSS', function ($value, $valid = '') {
            return \StaticPHP\Utils\Models\Fv::xss($value);
        });
        Config::viewEngine()->addFilter($filter);
    }
}
