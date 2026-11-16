<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Mapping;

use AgUi\Event\Event;
use Symfony\AI\Agent\Toolbox\ToolResult;

/**
 * An application rule that turns a tool result into extra events, on top of the ones
 * the protocol requires — the escape hatch for what activities, interrupts and the
 * shared state do not cover (a CUSTOM event, say).
 */
interface ToolResultMapping
{
    /** @return iterable<Event> */
    public function map(ToolResult $result): iterable;
}
