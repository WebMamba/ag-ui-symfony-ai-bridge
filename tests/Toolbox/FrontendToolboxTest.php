<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Tests\Toolbox;

use AgUi\Bridge\SymfonyAi\Run\RunScope;
use AgUi\Bridge\SymfonyAi\Toolbox\FrontendToolbox;
use AgUi\Run\RunAgentInput;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Toolbox;

/**
 * The client's JSON Schema reaches the model in the shape Symfony AI describes tools in.
 */
final class FrontendToolboxTest extends TestCase
{
    public function testTheClientsSchemaKeepsWhatSymfonyAiCanDescribe(): void
    {
        $parameters = $this->parametersOf([
            'type' => 'object',
            'properties' => [
                'unit' => ['type' => 'string', 'enum' => ['nm', 'km'], 'description' => 'The distance unit.'],
                'precise' => ['type' => 'boolean'],
                'legs' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1],
                'area' => ['type' => 'object', 'properties' => ['zoom' => ['type' => 'integer']], 'additionalProperties' => false],
                'speed' => ['type' => 'number', 'minimum' => 0, 'maximum' => 30, 'format' => 'float', 'enum' => 'not a list'],
                'broken' => 'not a schema',
            ],
            'required' => ['unit', 'missing'],
        ]);

        self::assertSame([
            'type' => 'object',
            'properties' => [
                'unit' => ['type' => 'string', 'description' => 'The distance unit.', 'enum' => ['nm', 'km']],
                'precise' => ['type' => 'boolean', 'description' => ''],
                'legs' => ['type' => 'array', 'description' => '', 'minItems' => 1, 'items' => ['type' => 'string']],
                'area' => ['type' => 'object', 'description' => '', 'properties' => ['zoom' => ['type' => 'integer']], 'additionalProperties' => false],
                'speed' => ['type' => 'number', 'description' => '', 'minimum' => 0, 'maximum' => 30],
            ],
            'required' => ['unit'],
            'additionalProperties' => false,
        ], $parameters, 'What the contract cannot carry, or carries in another type, is left out.');
    }

    public function testASchemaWithoutPropertiesIsLeftToThePlatform(): void
    {
        self::assertNull($this->parametersOf(['type' => 'object', 'properties' => []]));
        self::assertNull($this->parametersOf(null));
    }

    /** @return array<string, mixed>|null */
    private function parametersOf(mixed $schema): ?array
    {
        $scope = new RunScope();
        $scope->open(RunAgentInput::fromArray([
            'threadId' => 't1',
            'runId' => 'r1',
            'messages' => [],
            'tools' => [['name' => 'get_distance', 'description' => 'A distance.', 'parameters' => $schema]],
        ]), []);

        return (new FrontendToolbox(new Toolbox([]), $scope))->getTools()[0]->getParameters();
    }
}
