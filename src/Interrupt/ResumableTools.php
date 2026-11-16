<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Interrupt;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Finds resumable tools by the names their #[AsTool] attributes give them.
 */
final class ResumableTools implements ResumableToolLocator
{
    /** @var array<string, ResumableTool>|null */
    private ?array $byName = null;

    /** @param iterable<ResumableTool> $tools */
    public function __construct(
        private readonly iterable $tools = [],
    ) {
    }

    public function get(string $toolName): ?ResumableTool
    {
        if (null === $this->byName) {
            $this->byName = [];
            foreach ($this->tools as $tool) {
                foreach ((new \ReflectionClass($tool))->getAttributes(AsTool::class) as $attribute) {
                    $this->byName[$attribute->newInstance()->name] = $tool;
                }
            }
        }

        return $this->byName[$toolName] ?? null;
    }
}
