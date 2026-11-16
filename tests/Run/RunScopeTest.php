<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Tests\Run;

use AgUi\Bridge\SymfonyAi\Context\AgentContext;
use AgUi\Bridge\SymfonyAi\Context\ContextEntry;
use AgUi\Bridge\SymfonyAi\InputProcessor\RunInstructionsProcessor;
use AgUi\Bridge\SymfonyAi\Run\RunScope;
use AgUi\Bridge\SymfonyAi\Tests\Fixtures\GetForecast;
use AgUi\Bridge\SymfonyAi\Toolbox\AgUiToolExecutor;
use AgUi\Bridge\SymfonyAi\Toolbox\FrontendToolbox;
use AgUi\Run\RunAgentInput;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;

/**
 * The pieces wired into an agent act for a run of that agent only: every agent can be
 * wired, and called outside AG-UI — or as another agent's subagent — as if it were not.
 */
final class RunScopeTest extends TestCase
{
    private RunScope $scope;
    private FrontendToolbox $toolbox;
    private AgUiToolExecutor $executor;
    private RunInstructionsProcessor $processor;

    protected function setUp(): void
    {
        $this->scope = new RunScope();
        $this->toolbox = new FrontendToolbox(new Toolbox([new GetForecast()]), $this->scope, 'navigator');
        $this->executor = new AgUiToolExecutor($this->toolbox, $this->scope, agent: 'navigator');
        $this->processor = new RunInstructionsProcessor($this->scope, 'navigator');
    }

    public function testDuringARunForItsAgentThePiecesAct(): void
    {
        $this->open('navigator');

        self::assertSame(['get_forecast', 'get_position'], $this->toolNames());
        self::assertTrue($this->toolbox->isFrontendTool('get_position'));
        self::assertStringContainsString('Be brief.', $this->instructedPrompt());

        iterator_to_array($this->executor->execute([new ToolCall('c1', 'get_forecast', ['place' => 'Papeete'])]), false);
        self::assertTrue($this->scope->executorUsed());
    }

    public function testOutsideARunThePiecesAreNotThere(): void
    {
        self::assertSame(['get_forecast'], $this->toolNames());
        self::assertSame('You are a sailor.', $this->instructedPrompt());
        self::assertSame(['Sunny in Papeete.'], $this->execute());
    }

    public function testDuringAnotherAgentsRunThePiecesAreNotThere(): void
    {
        $this->open('weather');

        self::assertSame(['get_forecast'], $this->toolNames(), 'A subagent is not offered the client\'s tools.');
        self::assertFalse($this->toolbox->isFrontendTool('get_position'));
        self::assertSame('You are a sailor.', $this->instructedPrompt());
        self::assertSame(['Sunny in Papeete.'], $this->execute());
        self::assertFalse($this->scope->executorUsed(), 'Only the run\'s agent counts as wired.');
    }

    public function testPiecesWiredForNoAgentActForAnyRun(): void
    {
        $toolbox = new FrontendToolbox(new Toolbox([]), $this->scope);
        $this->open('weather');

        self::assertSame(['get_position'], array_map(static fn (Tool $tool): string => $tool->getName(), $toolbox->getTools()));
    }

    public function testEachAgentIsToldItsOwnFactsAndTheSharedOnes(): void
    {
        $context = new AgentContext([
            new ContextEntry('Shop', static fn (): string => 'open'),
            new ContextEntry('Customer', static fn (): string => 'Moana', 'navigator'),
            new ContextEntry('Warehouse', static fn (): string => 'Papeete', 'weather'),
        ]);

        self::assertSame(['Shop' => 'open', 'Customer' => 'Moana'], $context->for('navigator')->read());
    }

    private function open(string $agent): void
    {
        $input = RunAgentInput::fromArray([
            'threadId' => 't1',
            'runId' => 'r1',
            'messages' => [],
            'tools' => [['name' => 'get_position', 'description' => 'Where the boat is.', 'parameters' => ['type' => 'object', 'properties' => []]]],
        ]);

        $this->scope->open($input, ['Be brief.'], agent: $agent);
    }

    /** @return list<string> */
    private function toolNames(): array
    {
        return array_map(static fn (Tool $tool): string => $tool->getName(), $this->toolbox->getTools());
    }

    private function instructedPrompt(): string
    {
        $input = new Input('mock-model', new MessageBag(Message::forSystem('You are a sailor.'), Message::ofUser('Hi')));
        $this->processor->processInput($input);

        $content = $input->getMessageBag()->getSystemMessage()?->getContent();

        return \is_string($content) ? $content : '';
    }

    /** @return list<mixed> */
    private function execute(): array
    {
        $execution = $this->executor->execute([new ToolCall('c1', 'get_forecast', ['place' => 'Papeete'])]);
        iterator_to_array($execution, false);

        return array_map(static fn ($result): mixed => $result->getResult(), $execution->getReturn());
    }
}
