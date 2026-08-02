<?php

namespace StaticPHP\Utils\Models\Doctor;

/**
 * One diagnostic and what it found.
 */
readonly class Result
{
    /**
     * @access public
     * @param Status $status
     * @param string $check  Short name, so a script can grep for one
     * @param string $detail What was found, in a sentence
     * @param string $fix    What to do about it, empty when there is nothing to do
     */
    public function __construct(
        public Status $status,
        public string $check,
        public string $detail,
        public string $fix = '',
    ) {
    }

    /**
     * @access public
     * @static
     * @param  string $check
     * @param  string $detail
     * @return self
     */
    public static function ok(string $check, string $detail): self
    {
        return new self(Status::OK, $check, $detail);
    }

    /**
     * @access public
     * @static
     * @param  string $check
     * @param  string $detail
     * @param  string $fix
     * @return self
     */
    public static function warn(string $check, string $detail, string $fix = ''): self
    {
        return new self(Status::WARN, $check, $detail, $fix);
    }

    /**
     * @access public
     * @static
     * @param  string $check
     * @param  string $detail
     * @param  string $fix
     * @return self
     */
    public static function fail(string $check, string $detail, string $fix = ''): self
    {
        return new self(Status::FAIL, $check, $detail, $fix);
    }
}
