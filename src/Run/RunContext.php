<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Run;

/**
 * What one run knows about itself, for tools that need it without touching the request:
 * the thread and run ids, and the props the client forwarded.
 */
final readonly class RunContext
{
    public function __construct(
        private RunScope $scope,
    ) {
    }

    public function threadId(): string
    {
        return $this->input()->threadId;
    }

    public function runId(): string
    {
        return $this->input()->runId;
    }

    /** Application-specific values the client passed through, untouched. Any JSON value. */
    public function forwardedProps(): mixed
    {
        return $this->input()->forwardedProps;
    }

    private function input(): \AgUi\Run\RunAgentInput
    {
        return $this->scope->input() ?? throw new \LogicException('No AG-UI run is in progress: the run context is only available while AgUi runs an agent.');
    }
}
