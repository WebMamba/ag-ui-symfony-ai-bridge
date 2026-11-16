<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Interrupt;

use AgUi\Bridge\SymfonyAi\Activity\Activity;

/**
 * What a resumable tool returns when it needs a human before it can go on.
 *
 * The run stops right there and finishes with an "interrupt" outcome; the tool's
 * resume() is called in the next run, with the human's answer.
 */
final readonly class Interrupt implements \Stringable
{
    public const string CONFIRMATION = 'confirmation';
    public const string INPUT_REQUIRED = 'input_required';

    /**
     * @param string $reason why the run pauses, e.g. "confirmation"
     * @param array<string, mixed>|null $responseSchema the JSON Schema the answer's payload must follow
     * @param array<string, mixed>|null $metadata
     * @param Activity|null $activity UI asking the question, sent as an activity
     */
    public function __construct(
        public string $reason,
        public ?string $message = null,
        public ?array $responseSchema = null,
        public ?array $metadata = null,
        public ?Activity $activity = null,
    ) {
    }

    public static function confirmation(string $message): self
    {
        return new self(self::CONFIRMATION, $message);
    }

    /** @param array<string, mixed>|null $responseSchema */
    public static function inputRequired(string $message, ?array $responseSchema = null): self
    {
        return new self(self::INPUT_REQUIRED, $message, $responseSchema);
    }

    /** The same interrupt, with UI for the client to ask the question. */
    public function withActivity(Activity $activity): self
    {
        return new self($this->reason, $this->message, $this->responseSchema, $this->metadata, $activity);
    }

    public function __toString(): string
    {
        return $this->message ?? $this->reason;
    }
}
