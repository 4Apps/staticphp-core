<?php

namespace StaticPHP\Utils\Models\Crypto;

/**
 * Something is wrong with the keys, the configuration or a stored value.
 *
 * Never caught and turned into a null by this package. A field that cannot be decrypted has
 * to reach somebody, and an empty string in a form is exactly how that stops happening.
 */
class CryptoError extends \RuntimeException
{
}
