<?php

namespace StaticPHP\Core\Exceptions\ErrorMessage;

use Throwable;
use StaticPHP\Core\Exceptions\ErrorMessage;

/**
 * The request did not resolve to anything - 404.
 *
 * Throw this rather than a RouterException when the fault is the client's. Router::init()
 * renders ErrorMessage subclasses without logging or emailing, which is what stops a
 * crawler walking dead urls from generating an alert per request.
 */
class NotFound extends ErrorMessage
{
    public function __construct(
        string $message = 'Not Found',
        ?string $description = null,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            message: $message,
            description: $description,
            previous: $previous,
            httpStatusCode: 404
        );
    }
}
