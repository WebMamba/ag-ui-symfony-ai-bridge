<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Exception;

use AgUi\Exception\ExceptionInterface;

/**
 * The agent AgUi runs is missing a piece it needs: its frontend toolbox,
 * its tool executor or its input processor.
 */
final class MisconfiguredAgentException extends \LogicException implements ExceptionInterface
{
}
