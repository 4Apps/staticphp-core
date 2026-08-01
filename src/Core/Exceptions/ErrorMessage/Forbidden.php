<?php

namespace StaticPHP\Core\Exceptions\ErrorMessage;

use Throwable;
use StaticPHP\Core\Exceptions\ErrorMessage;

/**
 * The request was understood but is not allowed - 403.
 */
class Forbidden extends ErrorMessage
{
    public function __construct(
        string $message = 'Forbidden',
        ?string $description = null,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            message: $message,
            description: $description,
            previous: $previous,
            httpStatusCode: 403
        );
    }
}
