<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi;

use AgUi\Bridge\SymfonyAi\Context\AgentContext;
use AgUi\Bridge\SymfonyAi\InputProcessor\RunInstructionsProcessor;
use AgUi\Bridge\SymfonyAi\Interrupt\InterruptSigner;
use AgUi\Bridge\SymfonyAi\Interrupt\ResumableToolLocator;
use AgUi\Bridge\SymfonyAi\Mapping\ToolResultMapping;
use AgUi\Bridge\SymfonyAi\Run\AgentRun;
use AgUi\Bridge\SymfonyAi\Run\RunScope;
use AgUi\Bridge\SymfonyAi\State\SharedState;
use AgUi\Bridge\SymfonyAi\Toolbox\AgUiToolExecutor;
use AgUi\Bridge\SymfonyAi\Toolbox\FrontendToolbox;
use AgUi\Event\Event;
use AgUi\Run\RunAgentInput;
use AgUi\Stream\RunStream;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResultConverter;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Runs a Symfony AI agent over AG-UI: one run agent input in, the run's event stream
 * out. The agent is the caller's choice, run by run — the route picks it, with its own
 * platform and model:
 *
 *     public function __invoke(RunAgentInput $input, AgentInterface $navigator, AgUi $agUi): AgUiResponse
 *     {
 *         return new AgUiResponse($agUi->run($input, $navigator));
 *     }
 *
 * The agent is not changed: it only needs a frontend toolbox, a tool executor and an
 * input processor that share this service's run scope. They stay out of the way until
 * a run is opened for that agent, which is when the protocol's tools join its own.
 * The bundle wires them into every agent declared under "ai.agent"; an agent built by
 * hand takes them from toolbox(), toolExecutor() and inputProcessor().
 */
final readonly class AgUi
{
    private InterruptSigner $interrupts;

    /**
     * @param string $secret signs the interrupts' ids, so a resume is trusted without
     *                       server state: the same on every server that may run a thread
     * @param iterable<ToolResultMapping> $mappings
     * @param AgentContext $context the facts stated to the agents, each told its own
     */
    public function __construct(
        #[\SensitiveParameter] string $secret,
        private RunScope $scope = new RunScope(),
        private ?ResumableToolLocator $resumableTools = null,
        private iterable $mappings = [],
        private AgentContext $context = new AgentContext(),
        private RunStream $stream = new RunStream(),
        private ToolResultConverter $converter = new ToolResultConverter(),
        private (NormalizerInterface&DenormalizerInterface)|null $serializer = null,
        private ?ValidatorInterface $validator = null,
    ) {
        $this->interrupts = new InterruptSigner($secret);
    }

    /**
     * The whole event stream of one run of this agent, from RUN_STARTED to RUN_FINISHED
     * or RUN_ERROR.
     *
     * The input is checked before anything streams: a shared state that does not fit the
     * state class throws here, so it can be answered as a bad request.
     *
     * @param object|null $state the shared state: the very instance the agent's tools
     *                           inject, filled from the input for the run and reset after
     * @param array<string, mixed> $options passed to the agent's call (e.g. "max_tokens", or a "tools" allow-list)
     * @param bool $trustClientInstructions whether the client's system and developer
     *                                      messages may add to the agent's instructions
     *
     * @return \Generator<int, Event>
     *
     * @throws Exception\InvalidStateException
     */
    public function run(RunAgentInput $input, AgentInterface $agent, ?object $state = null, array $options = [], bool $trustClientInstructions = false): \Generator
    {
        $run = new AgentRun(
            $agent,
            $this->scope,
            null !== $state ? new SharedState($state, $this->serializer, $this->validator) : null,
            $this->resumableTools,
            $this->mappings,
            $trustClientInstructions,
            $this->context->for($agent->getName()),
            $this->stream,
            $this->converter,
            $this->interrupts,
        );

        return $run->run($input, $options);
    }

    /**
     * For an agent built by hand: its own tools, plus the run's frontend tools. Give the
     * same instance to the agent and to toolExecutor().
     */
    public function toolbox(ToolboxInterface $tools): FrontendToolbox
    {
        return new FrontendToolbox($tools, $this->scope);
    }

    /** For an agent built by hand: runs its tools and reports their results to the client. */
    public function toolExecutor(FrontendToolbox $toolbox): AgUiToolExecutor
    {
        return new AgUiToolExecutor($toolbox, $this->scope, $this->converter);
    }

    /** For an agent built by hand: to add after its system prompt processor. */
    public function inputProcessor(): RunInstructionsProcessor
    {
        return new RunInstructionsProcessor($this->scope);
    }
}
