<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Toolbox;

use AgUi\Bridge\SymfonyAi\Run\RunScope;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Contract\JsonSchema\Factory;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Adds the run's frontend tools — the ones the client declares in RunAgentInput.tools —
 * to the agent's own, so the model can call both.
 *
 * Frontend tools are offered, never executed: the AG-UI tool executor stops the run
 * when the model calls one, and the client sends the result in the next run. Outside a
 * run for its agent, it offers the agent's own tools only.
 *
 * @phpstan-import-type JsonSchema from Factory
 *
 * @phpstan-type PropertySchema value-of<JsonSchema['properties']>
 */
final readonly class FrontendToolbox implements ToolboxInterface
{
    public const string METADATA_KEY = 'ag_ui_frontend';

    public function __construct(
        private ToolboxInterface $inner,
        private RunScope $scope,
        /** The name of the agent it belongs to; null for any run. */
        private ?string $agent = null,
    ) {
    }

    public function getTools(): array
    {
        $tools = $this->inner->getTools();

        if (!$this->scope->isOpenFor($this->agent)) {
            return $tools;
        }

        foreach ($this->scope->frontendTools() as $tool) {
            $tools[] = new Tool(new ExecutionReference(self::class, 'frontend'), $tool->name, $tool->description, self::schema($tool->parameters), [self::METADATA_KEY => true]);
        }

        return $tools;
    }

    /**
     * The client's JSON Schema in the shape Symfony AI describes tools in: an object of
     * properties, each keeping the keywords Symfony AI's contract declares, in the types
     * it declares them. Anything else the client wrote is left out: the model reads only
     * what the platform's contract can carry.
     *
     * A schema without properties is left to the platform's default — JSON's `{}`
     * decodes to PHP's `[]`, which would otherwise go back out as a list.
     *
     * @return JsonSchema|null
     */
    private static function schema(mixed $parameters): ?array
    {
        if (!\is_array($parameters) || !\is_array($parameters['properties'] ?? null) || [] === $parameters['properties']) {
            return null;
        }

        $properties = [];
        foreach ($parameters['properties'] as $name => $property) {
            if (\is_array($property) && \is_string($property['type'] ?? null)) {
                $properties[(string) $name] = self::property($property, $property['type']);
            }
        }

        $required = [];
        foreach (\is_array($parameters['required'] ?? null) ? $parameters['required'] : [] as $name) {
            if (\is_string($name) && isset($properties[$name])) {
                $required[] = $name;
            }
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
    }

    /**
     * @param array<mixed> $schema
     *
     * @return PropertySchema
     */
    private static function property(array $schema, string $type): array
    {
        $property = ['type' => $type, 'description' => \is_string($schema['description'] ?? null) ? $schema['description'] : ''];

        if (self::isListOfStrings($schema['enum'] ?? null)) {
            $property['enum'] = $schema['enum'];
        }
        $const = $schema['const'] ?? null;
        if (\is_string($const) || \is_int($const) || self::isListOfStrings($const)) {
            $property['const'] = $const;
        }
        if (\is_string($schema['pattern'] ?? null)) {
            $property['pattern'] = $schema['pattern'];
        }
        foreach (['minLength', 'maxLength', 'minItems', 'maxItems', 'minContains', 'maxContains', 'minProperties', 'maxProperties'] as $keyword) {
            if (\is_int($schema[$keyword] ?? null)) {
                $property[$keyword] = $schema[$keyword];
            }
        }
        foreach (['minimum', 'maximum', 'multipleOf', 'exclusiveMinimum', 'exclusiveMaximum'] as $keyword) {
            if (\is_int($schema[$keyword] ?? null) || \is_float($schema[$keyword] ?? null)) {
                $property[$keyword] = $schema[$keyword];
            }
        }
        foreach (['uniqueItems', 'dependentRequired'] as $keyword) {
            if (\is_bool($schema[$keyword] ?? null)) {
                $property[$keyword] = $schema[$keyword];
            }
        }
        $required = $schema['required'] ?? null;
        if (\is_bool($required) || self::isListOfStrings($required)) {
            $property['required'] = $required;
        }
        if (\is_array($schema['anyOf'] ?? null) && array_is_list($schema['anyOf'])) {
            $property['anyOf'] = $schema['anyOf'];
        }
        if (self::isMap($schema['items'] ?? null)) {
            $property['items'] = $schema['items'];
        }
        $nested = $schema['properties'] ?? null;
        if (self::isMap($nested) && array_filter($nested, self::isMap(...)) === $nested) {
            $property['properties'] = $nested;
        }
        $additional = $schema['additionalProperties'] ?? null;
        if (\is_bool($additional) || self::isMap($additional)) {
            $property['additionalProperties'] = $additional;
        }

        return $property;
    }

    /** @phpstan-assert-if-true list<string> $value */
    private static function isListOfStrings(mixed $value): bool
    {
        return \is_array($value) && array_is_list($value) && array_filter($value, \is_string(...)) === $value;
    }

    /** @phpstan-assert-if-true array<string, mixed> $value */
    private static function isMap(mixed $value): bool
    {
        return \is_array($value) && array_filter(array_keys($value), \is_string(...)) === array_keys($value);
    }

    public function isFrontendTool(string $name): bool
    {
        return $this->scope->isOpenFor($this->agent) && $this->scope->isFrontendTool($name);
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        if ($this->isFrontendTool($toolCall->getName())) {
            throw new \LogicException(\sprintf('"%s" is a frontend tool: the client executes it, not the server.', $toolCall->getName()));
        }

        return $this->inner->execute($toolCall);
    }
}
