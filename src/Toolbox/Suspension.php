<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Toolbox;

use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Why a run stops before the model can go on: calls only the client can answer, and
 * calls waiting for a human decision.
 */
final readonly class Suspension
{
    /**
     * @param list<ToolCall> $frontendToolCalls
     * @param list<ToolResult> $interrupts results whose value is an Interrupt
     */
    public function __construct(
        public array $frontendToolCalls = [],
        public array $interrupts = [],
    ) {
    }
}
