<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Run;

use AgUi\Bridge\SymfonyAi\Context\AgentContext;
use AgUi\Run\RunAgentInput;
use AgUi\Run\Tool;
use Symfony\Contracts\Service\ResetInterface;

/**
 * What the pieces wired into an agent need to know about the run in progress: AgUi
 * opens it, the frontend toolbox, the tool executor and the input processor read it,
 * and it is closed when the run ends.
 *
 * Shared by every agent's pieces, which only act for the agent the run is for: the
 * same agent called outside an AG-UI run, or another agent called during one (as a
 * subagent), works as if they were not there. Reset between runs, so a worker serving
 * several runs never leaks one into the next.
 *
 * @internal
 */
final class RunScope implements ResetInterface
{
    private ?RunAgentInput $input = null;

    /** The name of the agent the run is for. */
    private ?string $agent = null;

    /** @var list<Tool> */
    private array $frontendTools = [];

    /** @var list<string> */
    private array $instructions = [];

    private ?AgentContext $context = null;

    /** @var array<string, string|null> the agent context as the model knows it */
    private array $contextValues = [];

    private bool $executorUsed = false;
    private bool $instructionsApplied = false;

    /**
     * @param list<string> $instructions
     * @param array<string, string|null> $contextValues the agent context, as read for the instructions
     * @param string|null $agent the name of the agent the run is for; null for any
     */
    public function open(RunAgentInput $input, array $instructions, ?AgentContext $context = null, array $contextValues = [], ?string $agent = null): void
    {
        $this->reset();
        $this->input = $input;
        $this->agent = $agent;
        $this->frontendTools = $input->tools ?? [];
        $this->instructions = $instructions;
        $this->context = $context;
        $this->contextValues = $contextValues;
    }

    /**
     * Reads the agent context again, after a tool: one line per fact that changed since
     * the model last heard of it.
     *
     * @return list<string>
     */
    public function contextChanges(): array
    {
        if (null === $this->context) {
            return [];
        }

        [$this->contextValues, $changes] = $this->context->changes($this->contextValues);

        return $changes;
    }

    public function isOpen(): bool
    {
        return null !== $this->input;
    }

    /**
     * Whether a run is open for this agent. A piece wired without an agent name acts for
     * any run, as one built by hand for the agent it is given to.
     */
    public function isOpenFor(?string $agent): bool
    {
        return null !== $this->input && (null === $agent || null === $this->agent || $agent === $this->agent);
    }

    public function input(): ?RunAgentInput
    {
        return $this->input;
    }

    /** @return list<Tool> */
    public function frontendTools(): array
    {
        return $this->frontendTools;
    }

    public function isFrontendTool(string $name): bool
    {
        foreach ($this->frontendTools as $tool) {
            if ($name === $tool->name) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function instructions(): array
    {
        return $this->instructions;
    }

    public function markExecutorUsed(): void
    {
        $this->executorUsed = true;
    }

    public function executorUsed(): bool
    {
        return $this->executorUsed;
    }

    public function markInstructionsApplied(): void
    {
        $this->instructionsApplied = true;
    }

    public function instructionsApplied(): bool
    {
        return $this->instructionsApplied;
    }

    public function reset(): void
    {
        $this->input = null;
        $this->agent = null;
        $this->frontendTools = [];
        $this->instructions = [];
        $this->context = null;
        $this->contextValues = [];
        $this->executorUsed = false;
        $this->instructionsApplied = false;
    }
}
