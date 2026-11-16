<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Toolbox;

use AgUi\Bridge\SymfonyAi\Context\AgentContext;
use AgUi\Bridge\SymfonyAi\Interrupt\Interrupt;
use AgUi\Bridge\SymfonyAi\Run\RunScope;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\Toolbox\SequentialToolExecutor;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolExecutorInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Agent\Toolbox\ToolResultConverter;

/**
 * Runs tools one after the other, reporting each call before it runs — as Symfony AI's
 * own executor does — and each result after: AgUi turns results into
 * TOOL_CALL_RESULT, activities and state deltas.
 *
 * After each tool, the agent context is read again: what changed is added to the
 * result the model reads.
 *
 * It also knows when the run cannot go on: the model called a frontend tool, or a tool
 * answered with an Interrupt. Every server tool of the batch still runs, then a
 * suspension tells AgUi to finish the run.
 *
 * Outside a run for its agent, it is Symfony AI's sequential executor.
 */
final readonly class AgUiToolExecutor implements ToolExecutorInterface
{
    public const string STAGE_TOOL_RESULT = 'ag_ui.tool_result';
    public const string STAGE_SUSPEND = 'ag_ui.suspend';

    /**
     * @param ToolboxInterface $toolbox the agent's toolbox as the agent sees it — with any
     *                                  fault-tolerant or traceable decorator, and the
     *                                  FrontendToolbox somewhere inside
     */
    public function __construct(
        private ToolboxInterface $toolbox,
        private RunScope $scope,
        private ToolResultConverter $converter = new ToolResultConverter(),
        /** The name of the agent it belongs to; null for any run. */
        private ?string $agent = null,
    ) {
    }

    public function execute(array $toolCalls): \Generator
    {
        if (!$this->scope->isOpenFor($this->agent)) {
            return yield from (new SequentialToolExecutor($this->toolbox))->execute($toolCalls);
        }

        $this->scope->markExecutorUsed();

        $results = [];
        $frontendToolCalls = [];
        $interrupts = [];

        foreach ($toolCalls as $toolCall) {
            yield new Progress(Progress::STAGE_TOOL_CALL, \sprintf('Executing tool "%s".', $toolCall->getName()), $toolCall);

            if ($this->scope->isFrontendTool($toolCall->getName())) {
                $frontendToolCalls[] = $toolCall;

                continue;
            }

            $result = $this->toolbox->execute($toolCall);

            if ($result->getResult() instanceof Interrupt) {
                $interrupts[] = $result;

                continue;
            }

            $changes = $this->scope->contextChanges();

            yield new Progress(self::STAGE_TOOL_RESULT, \sprintf('Tool "%s" finished.', $toolCall->getName()), new ExecutedTool($result, $changes));

            $results[] = [] === $changes
                ? $result
                : new ToolResult($toolCall, AgentContext::note($this->converter->convert($result), $changes), $result->getSources());
        }

        if ([] !== $frontendToolCalls || [] !== $interrupts) {
            yield new Progress(self::STAGE_SUSPEND, 'Waiting for the client.', new Suspension($frontendToolCalls, $interrupts));

            // AgUi stops iterating on a suspension. Going on would hand the
            // model fewer results than calls, which no model API accepts.
            throw new \LogicException('A suspended AG-UI run cannot continue in-process: it is answered in the next run.');
        }

        return $results;
    }
}
