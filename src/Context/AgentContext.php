<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Context;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * What the server knows and the agent should too: facts the application states at the
 * start of every run, such as the interface's current theme.
 *
 * The facts are read once, before the model is called. After each tool they are read
 * again, and the ones that changed are added to that tool's result: the model learns
 * about a change even when another tool than the one it called made it.
 */
final readonly class AgentContext
{
    public const string HEADER = 'Current facts from the application:';
    public const string CHANGED = '[context changed]';

    /** @param iterable<ContextEntry> $entries */
    public function __construct(
        private iterable $entries = [],
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** The facts stated to this agent: its own, and the ones for every agent. */
    public function for(string $agent): self
    {
        $entries = [];
        foreach ($this->entries as $entry) {
            if (null === $entry->agent || $agent === $entry->agent) {
                $entries[] = $entry;
            }
        }

        return new self($entries, $this->logger);
    }

    /**
     * Every fact's current value. A fact that cannot be read fails the run: the agent
     * must not act on a fact it was not told.
     *
     * @return array<string, string|null> the values, by description
     */
    public function read(): array
    {
        $values = [];
        foreach ($this->entries as $entry) {
            $values[$entry->description] = $entry->read();
        }

        return $values;
    }

    /**
     * What the model reads at the start of the run, or null when there is nothing to say.
     *
     * @param array<string, string|null> $values
     */
    public static function describe(array $values): ?string
    {
        $lines = self::lines(array_filter($values, static fn (?string $value): bool => null !== $value));

        return [] === $lines ? null : self::HEADER."\n".implode("\n", $lines);
    }

    /**
     * Reads the facts again and tells which ones changed. A fact that cannot be read is
     * left as it was: the tool already did its job, so the run goes on.
     *
     * @param array<string, string|null> $before the values the model knows
     *
     * @return array{0: array<string, string|null>, 1: list<string>} the values now, and one line per change
     */
    public function changes(array $before): array
    {
        $now = $before;
        $changed = [];

        foreach ($this->entries as $entry) {
            try {
                $value = $entry->read();
            } catch (\Throwable $e) {
                $this->logger->warning('The agent context "{description}" could not be read again after a tool: {message}', ['description' => $entry->description, 'message' => $e->getMessage(), 'exception' => $e]);

                continue;
            }

            if (!\array_key_exists($entry->description, $before) || $before[$entry->description] !== $value) {
                $changed[$entry->description] = $value;
            }
            $now[$entry->description] = $value;
        }

        return [$now, array_map(static fn (string $line): string => self::CHANGED.' '.$line, self::lines($changed))];
    }

    /**
     * A tool's result as the model reads it, with the facts its call changed.
     *
     * @param list<string> $changes
     */
    public static function note(?string $content, array $changes): ?string
    {
        if ([] === $changes) {
            return $content;
        }

        return ('' === ($content ?? '') ? '' : $content."\n\n").implode("\n", $changes);
    }

    /**
     * @param array<string, string|null> $values
     *
     * @return list<string>
     */
    private static function lines(array $values): array
    {
        $lines = [];
        foreach ($values as $description => $value) {
            $lines[] = \sprintf('%s: %s', $description, $value ?? '(none)');
        }

        return $lines;
    }
}
