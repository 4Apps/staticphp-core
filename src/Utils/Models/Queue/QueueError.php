<?php

namespace StaticPHP\Utils\Models\Queue;

/**
 * Thrown for a queue that is configured or called wrongly, never for a job that failed.
 */
class QueueError extends \RuntimeException
{
}
