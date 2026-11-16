<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Toolbox;

use Symfony\AI\Agent\Toolbox\ToolResult;

/**
 * A server tool that ran: its result as the tool returned it, and the agent context it
 * changed — which the model reads appended to the result.
 */
final readonly class ExecutedTool
{
    /** @param list<string> $contextChanges */
    public function __construct(
        public ToolResult $result,
        public array $contextChanges = [],
    ) {
    }
}
