<?php

// Based on https://www.php.net/manual/en/language.operators.bitwise.php#108679
// Thanks wbcarts!

namespace StaticPHP\Utils\Models;

use Twig\TwigFunction;
use StaticPHP\Core\Models\Config;

abstract class BitwiseFlag
{
    protected int $flags;

    /**
     * Note: these functions are protected to prevent outside code
     * from falsely setting BITS.
     *
     */
    protected function isFlagSet(int $flag)
    {
        return (($this->flags & $flag) == $flag);
    }

    protected function setFlag(int $flag, bool $value)
    {
        if ($value) {
            $this->flags |= $flag;
        } else {
            $this->flags &= ~$flag;
        }
    }

    public static function hasFlag(int $flag, int $inFlags)
    {
        return (($inFlags & $flag) == $flag);
    }

    public static function registerTwig()
    {
        // Twig is a suggestion of staticphp-core, not a requirement, so there may be no
        // engine to register against
        if (empty(Config::get('view_engine'))) {
            return;
        }

        $filter = new TwigFunction(
            'BitwiseHasFlag',
            function (int $flag, int $inFlags) {
                return \StaticPHP\Utils\Models\BitwiseFlag::hasFlag($flag, $inFlags);
            }
        );
        Config::$items['view_engine']->addFunction($filter);
    }
}
