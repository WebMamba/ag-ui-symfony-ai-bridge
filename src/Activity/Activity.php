<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Activity;

/**
 * A tool result with two audiences: a sentence for the model, content for the client.
 *
 * The client receives the content as an ACTIVITY_SNAPSHOT and renders it; the model
 * reads only the sentence, so markup or data meant for the screen never costs tokens.
 */
final readonly class Activity implements \Stringable
{
    /**
     * @param string $forModel what the model reads as the tool's result
     * @param string $type the activity type the client renders it by
     * @param array<string, mixed> $content what the client renders
     */
    public function __construct(
        public string $forModel,
        public string $type,
        public array $content,
    ) {
    }

    public function __toString(): string
    {
        return $this->forModel;
    }
}
