<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Exception;

use AgUi\Exception\ExceptionInterface;

/**
 * The shared state a client sent does not fit the route's state class: bad input,
 * rejected before the run starts.
 */
final class InvalidStateException extends \InvalidArgumentException implements ExceptionInterface
{
}
