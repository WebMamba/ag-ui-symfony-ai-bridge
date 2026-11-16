<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Context;

/**
 * One fact the application states to the agent: what it is, and how to read its
 * current value.
 */
final readonly class ContextEntry
{
    /**
     * @param string $description what the value is, as the model reads it (e.g. "Interface theme")
     * @param \Closure(): mixed $read the current value, a string or null — null leaves the fact out
     * @param string|null $agent the name of the agent told about it; every agent when null
     */
    public function __construct(
        public string $description,
        private \Closure $read,
        public ?string $agent = null,
    ) {
    }

    public function read(): ?string
    {
        $value = ($this->read)();

        if (null !== $value && !\is_string($value)) {
            throw new \UnexpectedValueException(\sprintf('The agent context "%s" must be a string or null, got %s.', $this->description, get_debug_type($value)));
        }

        return $value;
    }
}
