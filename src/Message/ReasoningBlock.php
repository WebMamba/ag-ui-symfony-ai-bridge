<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Message;

/**
 * A reasoning message being streamed: its id, and the thinking received so far.
 *
 * @internal
 */
final class ReasoningBlock
{
    public string $content = '';

    public function __construct(
        public readonly string $id,
    ) {
    }
}
