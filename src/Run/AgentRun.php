<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Run;

use AgUi\Bridge\SymfonyAi\Activity\Activity;
use AgUi\Bridge\SymfonyAi\AgUi;
use AgUi\Bridge\SymfonyAi\Context\AgentContext;
use AgUi\Bridge\SymfonyAi\Exception\InvalidStateException;
use AgUi\Bridge\SymfonyAi\Exception\MisconfiguredAgentException;
use AgUi\Bridge\SymfonyAi\Interrupt\Interrupt;
use AgUi\Bridge\SymfonyAi\Interrupt\InterruptSigner;
use AgUi\Bridge\SymfonyAi\Interrupt\ResumableToolLocator;
use AgUi\Bridge\SymfonyAi\Mapping\ToolResultMapping;
use AgUi\Bridge\SymfonyAi\Message\MessageBagFactory;
use AgUi\Bridge\SymfonyAi\Message\ReasoningBlock;
use AgUi\Bridge\SymfonyAi\Message\Transcript;
use AgUi\Bridge\SymfonyAi\State\SharedState;
use AgUi\Bridge\SymfonyAi\Toolbox\AgUiToolExecutor;
use AgUi\Bridge\SymfonyAi\Toolbox\ExecutedTool;
use AgUi\Bridge\SymfonyAi\Toolbox\Suspension;
use AgUi\Event\ActivitySnapshotEvent;
use AgUi\Event\Event;
use AgUi\Event\MessagesSnapshotEvent;
use AgUi\Event\ReasoningEncryptedValueEvent;
use AgUi\Event\ReasoningEncryptedValueSubtype;
use AgUi\Event\ReasoningEndEvent;
use AgUi\Event\ReasoningMessageContentEvent;
use AgUi\Event\ReasoningMessageEndEvent;
use AgUi\Event\ReasoningMessageStartEvent;
use AgUi\Event\ReasoningStartEvent;
use AgUi\Event\RunFinishedEvent;
use AgUi\Event\StateDeltaEvent;
use AgUi\Event\StateSnapshotEvent;
use AgUi\Event\TextMessageContentEvent;
use AgUi\Event\TextMessageEndEvent;
use AgUi\Event\TextMessageStartEvent;
use AgUi\Event\ToolCallArgsEvent;
use AgUi\Event\ToolCallEndEvent;
use AgUi\Event\ToolCallResultEvent;
use AgUi\Event\ToolCallStartEvent;
use AgUi\Message\TextMessageRole;
use AgUi\Patch\JsonPatch;
use AgUi\Run\Interrupt as InterruptDescription;
use AgUi\Run\RunAgentInput;
use AgUi\Run\RunFinishedInterruptOutcome;
use AgUi\Run\RunFinishedSuccessOutcome;
use AgUi\Stream\RunStream;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Agent\Toolbox\ToolResultConverter;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingSignature;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Uid\Ulid;

/**
 * One AG-UI run of one Symfony AI agent: the run agent input in, the run's event
 * stream out. AgUi builds one per run, for the agent it is given.
 *
 *  - text and reasoning stream as TEXT_MESSAGE_* and REASONING_*, and signed reasoning
 *    round-trips through encrypted values;
 *  - tool calls stream as TOOL_CALL_*, and every server result as TOOL_CALL_RESULT;
 *  - a tool result that is an Activity is also an ACTIVITY_SNAPSHOT;
 *  - changes to the state class become STATE_DELTA patches;
 *  - the agent context is stated at the start, and its changes after each tool;
 *  - frontend tools and interrupts finish the run, to be answered in the next one;
 *  - tool result mappings add whatever else the application needs.
 *
 * Everything goes through the core's RunStream, which opens and closes the run, and
 * validates and orders every event exactly as the official client will check them.
 *
 * @internal
 */
final readonly class AgentRun
{
    /**
     * @param iterable<ToolResultMapping> $mappings
     * @param AgentContext $context the facts stated to this agent
     */
    public function __construct(
        private AgentInterface $agent,
        private RunScope $scope,
        private ?SharedState $state,
        private ?ResumableToolLocator $resumableTools,
        private iterable $mappings,
        private bool $trustClientInstructions,
        private AgentContext $context,
        private RunStream $stream,
        private ToolResultConverter $converter,
        private InterruptSigner $interrupts,
    ) {
    }

    /**
     * @param array<string, mixed> $options passed to the agent's call
     *
     * @return \Generator<int, Event>
     *
     * @throws InvalidStateException
     */
    public function run(RunAgentInput $input, array $options): \Generator
    {
        $this->state?->assertValid($input->state);

        return $this->stream->events($input, $this->events($input, $options));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, Event>
     */
    private function events(RunAgentInput $input, array $options): \Generator
    {
        try {
            $this->state?->load($input->state);
            $context = $this->context->read();
            $this->scope->open($input, $this->instructions($input, $context), $this->context, $context, $this->agent->getName());

            if (null !== $this->state) {
                yield new StateSnapshotEvent($this->state->snapshot());
            }

            $transcript = new Transcript($input->messages);
            $bag = MessageBagFactory::messageBag($input->messages);

            $bag = yield from $this->resume($input, $bag, $transcript);

            $open = array_diff_key(MessageBagFactory::openToolCalls($input->messages), $this->resumedIds($input));
            if ([] !== $open) {
                throw new \RuntimeException(\sprintf('Tool call(s) %s still wait for an answer: answer or resume them before sending anything else.', implode(', ', array_keys($open))));
            }

            $suspension = yield from $this->runAgent($bag, $transcript, ['stream' => true] + $options);

            yield new MessagesSnapshotEvent($transcript->messages());

            yield new RunFinishedEvent($input->threadId, $input->runId, outcome: match (true) {
                null !== $suspension && [] !== $suspension->interrupts => new RunFinishedInterruptOutcome(array_map($this->describeInterrupt(...), $suspension->interrupts)),
                null !== $suspension => new RunFinishedSuccessOutcome(array_map(static fn (ToolCall $call): string => $call->getId(), $suspension->frontendToolCalls)),
                default => new RunFinishedSuccessOutcome(),
            });
        } finally {
            $this->scope->reset();
            $this->state?->reset();
        }
    }

    /**
     * Streams one agent call. Returns why it stopped early, if it did.
     *
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, Event, mixed, Suspension|null>
     */
    private function runAgent(MessageBag $bag, Transcript $transcript, array $options): \Generator
    {
        $assistantId = self::newId('msg');
        $textOpen = false;
        $reasoning = null;           // the reasoning message being streamed, if any
        $lastReasoningId = null;
        /** @var array<string, true> $started */
        $started = [];
        $stateBefore = null;

        try {
            foreach ($this->agent->call($bag, $options) as $update) {
                if (!$update instanceof Progress) {
                    continue;
                }

                $payload = $update->getPayload();

                switch ($update->getStage()) {
                    case Progress::STAGE_MODEL_REQUEST:
                        if (!$this->scope->instructionsApplied() && [] !== $this->scope->instructions()) {
                            throw new MisconfiguredAgentException(\sprintf('The agent "%s" ignored the run\'s instructions: declare it under "ai.agent" for the bundle to prepare it, or add %s::inputProcessor() to its input processors, after the system prompt one.', $this->agent->getName(), AgUi::class));
                        }
                        if ($textOpen) {
                            yield new TextMessageEndEvent($assistantId);
                            $textOpen = false;
                        }
                        $assistantId = self::newId('msg');   // one AG-UI assistant message per model round
                        break;

                    case Progress::STAGE_DELTA:
                        // Some providers never send ThinkingStart: the first delta opens the block.
                        if (null === $reasoning && ($payload instanceof ThinkingStart || $payload instanceof ThinkingDelta)) {
                            $reasoning = new ReasoningBlock(self::newId('reasoning'));
                            yield new ReasoningStartEvent($reasoning->id);
                            yield new ReasoningMessageStartEvent($reasoning->id);
                        }

                        if ($payload instanceof ThinkingStart) {
                            // Opened above.
                        } elseif ($payload instanceof ThinkingDelta) {
                            if ('' !== $payload->getThinking()) {
                                $reasoning->content .= $payload->getThinking();
                                yield new ReasoningMessageContentEvent($reasoning->id, $payload->getThinking());
                            }
                        } elseif ($payload instanceof ThinkingComplete && null !== $reasoning) {
                            yield from $this->closeReasoning($reasoning, $payload->getSignature(), $transcript);
                            $lastReasoningId = $reasoning->id;
                            $reasoning = null;
                        } elseif ($payload instanceof ThinkingSignature) {
                            // Some providers sign after the block, or sign a block they never streamed.
                            if (null !== $reasoning) {
                                yield from $this->closeReasoning($reasoning, $payload->getSignature(), $transcript);
                                $lastReasoningId = $reasoning->id;
                                $reasoning = null;
                            } elseif (null !== $lastReasoningId) {
                                $transcript->signReasoning($lastReasoningId, $payload->getSignature());
                                yield new ReasoningEncryptedValueEvent(ReasoningEncryptedValueSubtype::Message, $lastReasoningId, $payload->getSignature());
                            }
                        } elseif ($payload instanceof TextDelta && '' !== $payload->getText()) {
                            if (null !== $reasoning) {
                                yield from $this->closeReasoning($reasoning, null, $transcript);
                                $lastReasoningId = $reasoning->id;
                                $reasoning = null;
                            }
                            if (!$textOpen) {
                                yield new TextMessageStartEvent($assistantId, TextMessageRole::Assistant);
                                $textOpen = true;
                            }
                            $transcript->appendText($assistantId, $payload->getText());
                            yield new TextMessageContentEvent($assistantId, $payload->getText());
                        } elseif ($payload instanceof ToolCallStart) {
                            // The model stopped talking to the user and started talking to the tools.
                            if ($textOpen) {
                                yield new TextMessageEndEvent($assistantId);
                                $textOpen = false;
                            }
                            $started[$payload->getId()] = true;
                            yield new ToolCallStartEvent($payload->getId(), $payload->getName(), $assistantId);
                        } elseif ($payload instanceof ToolInputDelta && isset($started[$payload->getId()])) {
                            yield new ToolCallArgsEvent($payload->getId(), $payload->getPartialJson());
                        }
                        break;

                    case Progress::STAGE_TOOL_CALL:
                        if (!$this->scope->executorUsed()) {
                            throw new MisconfiguredAgentException(\sprintf('The agent "%s" runs its tools with another executor, so its results and pauses never reach the client: declare it under "ai.agent" for the bundle to prepare it, or give it %s::toolExecutor() (an %s).', $this->agent->getName(), AgUi::class, AgUiToolExecutor::class));
                        }
                        \assert($payload instanceof ToolCall);

                        if ($textOpen) {
                            yield new TextMessageEndEvent($assistantId);
                            $textOpen = false;
                        }
                        if (!isset($started[$payload->getId()])) {
                            // A provider that does not stream tool calls: announce it whole.
                            $started[$payload->getId()] = true;
                            yield new ToolCallStartEvent($payload->getId(), $payload->getName(), $assistantId);
                            yield new ToolCallArgsEvent($payload->getId(), json_encode((object) $payload->getArguments(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
                        }

                        $transcript->addToolCall($assistantId, $payload);
                        yield new ToolCallEndEvent($payload->getId());

                        $stateBefore = $this->state?->snapshot();
                        break;

                    case AgUiToolExecutor::STAGE_TOOL_RESULT:
                        \assert($payload instanceof ExecutedTool);
                        yield from $this->toolResult($payload->result, $transcript, $payload->contextChanges);
                        yield from $this->stateDelta($stateBefore);
                        break;

                    case AgUiToolExecutor::STAGE_SUSPEND:
                        \assert($payload instanceof Suspension);
                        foreach ($payload->interrupts as $interrupted) {
                            $interrupt = $interrupted->getResult();
                            \assert($interrupt instanceof Interrupt);

                            if (null !== $interrupt->activity) {
                                // The interrupt's id travels with its activity, so the component
                                // the human answers through knows which interrupt it resumes.
                                $activity = new Activity($interrupt->activity->forModel, $interrupt->activity->type, [
                                    ...$interrupt->activity->content,
                                    'interruptId' => $this->interruptId($interrupted->getToolCall()),
                                ]);
                                yield from $this->activity(self::activityId($interrupted->getToolCall()), $activity, $transcript);
                            }
                        }

                        return $payload;
                }
            }
        } finally {
            if (null !== $reasoning) {
                yield from $this->closeReasoning($reasoning, null, $transcript);
            }
            if ($textOpen) {
                yield new TextMessageEndEvent($assistantId);
            }
        }

        return null;
    }

    /**
     * Answers the interrupts of the previous run with what the human decided, so the
     * model finds the tool results it waits for.
     *
     * @return \Generator<int, Event, mixed, MessageBag> the conversation, with the answers
     */
    private function resume(RunAgentInput $input, MessageBag $bag, Transcript $transcript): \Generator
    {
        if ([] === ($input->resume ?? [])) {
            return $bag;
        }

        $open = MessageBagFactory::openToolCalls($input->messages);

        foreach ($input->resume ?? [] as $resume) {
            $toolCall = null;
            foreach ($open as $candidate) {
                if ($this->interrupts->answers($resume->interruptId, $input->threadId, $candidate)) {
                    $toolCall = $candidate;
                }
            }

            if (null === $toolCall) {
                throw new \RuntimeException(\sprintf('There is no open interrupt "%s".', $resume->interruptId));
            }

            $tool = $this->resumableTools?->get($toolCall->getName())
                ?? throw new \RuntimeException(\sprintf('Tool "%s" cannot be resumed: it does not implement ResumableTool.', $toolCall->getName()));

            $stateBefore = $this->state?->snapshot();
            $result = new ToolResult($toolCall, $tool->resume($toolCall->getArguments(), $resume));
            $changes = $this->scope->contextChanges();
            $bag = $bag->with(Message::ofToolCall($toolCall, (string) AgentContext::note($this->converter->convert($result), $changes)));

            // The call itself was announced in the previous run: only its result is new,
            // and what the resumed tool changed in the shared state.
            yield from $this->toolResult($result, $transcript, $changes);
            yield from $this->stateDelta($stateBefore);
        }

        return $bag;
    }

    /**
     * The client-facing side of a tool result: its content is what the model read.
     *
     * @param list<string> $contextChanges the agent context the tool changed
     *
     * @return \Generator<int, Event>
     */
    private function toolResult(ToolResult $result, Transcript $transcript, array $contextChanges = []): \Generator
    {
        $toolCall = $result->getToolCall();
        $content = (string) AgentContext::note($this->converter->convert($result), $contextChanges);
        $messageId = self::newId('tool');

        $transcript->addToolResult($messageId, $toolCall->getId(), $content);
        yield new ToolCallResultEvent($messageId, $toolCall->getId(), $content, 'tool');

        if ($result->getResult() instanceof Activity) {
            yield from $this->activity(self::activityId($toolCall), $result->getResult(), $transcript);
        }

        foreach ($this->mappings as $mapping) {
            foreach ($mapping->map($result) as $event) {
                yield $event;
            }
        }
    }

    /** @return \Generator<int, Event> */
    private function activity(string $messageId, Activity $activity, Transcript $transcript): \Generator
    {
        $transcript->addActivity($messageId, $activity->type, $activity->content);

        yield new ActivitySnapshotEvent($messageId, $activity->type, $activity->content);
    }

    /** @return \Generator<int, Event> */
    private function stateDelta(mixed $before): \Generator
    {
        if (null === $this->state) {
            return;
        }

        $delta = JsonPatch::diff($before, $this->state->snapshot());

        if ([] !== $delta) {
            yield new StateDeltaEvent($delta);
        }
    }

    /** @return \Generator<int, Event> */
    private function closeReasoning(ReasoningBlock $reasoning, ?string $signature, Transcript $transcript): \Generator
    {
        $id = $reasoning->id;
        $content = $reasoning->content;

        yield new ReasoningMessageEndEvent($id);

        $transcript->addReasoning($id, $content);
        if (null !== $signature && '' !== $signature) {
            $transcript->signReasoning($id, $signature);
            yield new ReasoningEncryptedValueEvent(ReasoningEncryptedValueSubtype::Message, $id, $signature);
        }

        yield new ReasoningEndEvent($id);
    }

    /**
     * What the server adds to the agent's instructions for this run: the server's facts
     * come before what the client says, and the shared state last.
     *
     * @param array<string, string|null> $context the agent context, as read for this run
     *
     * @return list<string>
     */
    private function instructions(RunAgentInput $input, array $context): array
    {
        $instructions = $this->trustClientInstructions ? MessageBagFactory::instructions($input->messages) : [];

        if (null !== ($facts = AgentContext::describe($context))) {
            $instructions[] = $facts;
        }

        foreach ($input->context ?? [] as $entry) {
            $instructions[] = \sprintf('%s: %s', $entry->description, $entry->value);
        }

        if (null !== $this->state) {
            $instructions[] = $this->state->describe();
        }

        return $instructions;
    }

    /** @return array<string, true> the open tool call ids this run's resumes answer */
    private function resumedIds(RunAgentInput $input): array
    {
        $ids = [];
        foreach (MessageBagFactory::openToolCalls($input->messages) as $id => $toolCall) {
            foreach ($input->resume ?? [] as $resume) {
                if ($this->interrupts->answers($resume->interruptId, $input->threadId, $toolCall)) {
                    $ids[$id] = true;
                }
            }
        }

        return $ids;
    }

    private function describeInterrupt(ToolResult $interrupted): InterruptDescription
    {
        $interrupt = $interrupted->getResult();
        \assert($interrupt instanceof Interrupt);

        return new InterruptDescription(
            id: $this->interruptId($interrupted->getToolCall()),
            reason: $interrupt->reason,
            message: $interrupt->message,
            toolCallId: $interrupted->getToolCall()->getId(),
            responseSchema: $interrupt->responseSchema,
            metadata: $interrupt->metadata,
        );
    }

    /** Signed, so the next run trusts its resume without server state. */
    private function interruptId(ToolCall $toolCall): string
    {
        $input = $this->scope->input() ?? throw new \LogicException('An interrupt is described during its run only.');

        return $this->interrupts->id($input->threadId, $toolCall);
    }

    private static function activityId(ToolCall $toolCall): string
    {
        return 'act_'.$toolCall->getId();
    }

    private static function newId(string $prefix): string
    {
        return $prefix.'_'.(new Ulid())->toBase32();
    }
}
