<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Interrupt;

use Symfony\AI\Platform\Result\ToolCall;

/**
 * Signs the id of an interrupt, so the server can trust a resume without keeping the
 * interrupt: the client owns the conversation (ADR 0004), and could otherwise resume a
 * tool call the model never made, or with arguments the human was never asked about.
 *
 * The id names its tool call and carries an HMAC of the thread, the call's id, the
 * tool's name and its arguments: changing any of them, or the secret, breaks it.
 */
final readonly class InterruptSigner
{
    public function __construct(
        #[\SensitiveParameter] private string $secret,
    ) {
        if ('' === $secret) {
            throw new \InvalidArgumentException('Interrupts are signed with a secret: it cannot be empty.');
        }
    }

    public function id(string $threadId, ToolCall $toolCall): string
    {
        $signed = json_encode([$threadId, $toolCall->getId(), $toolCall->getName(), $toolCall->getArguments()], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $signed, $this->secret, true)), '+/', '-_'), '=');

        return 'int_'.$toolCall->getId().'.'.$signature;
    }

    /** Whether this interrupt id is the one the server gave this tool call, in this thread. */
    public function answers(string $interruptId, string $threadId, ToolCall $toolCall): bool
    {
        return hash_equals($this->id($threadId, $toolCall), $interruptId);
    }
}
