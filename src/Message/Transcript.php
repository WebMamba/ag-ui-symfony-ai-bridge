<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Message;

use AgUi\Message\Message;
use AgUi\Message\Messages;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * The conversation in AG-UI form, as it grows during one run: it starts as what the
 * client sent and ends up in MESSAGES_SNAPSHOT, which the client adopts as its own —
 * so tool calls, results, activities and signed reasoning survive into the next run.
 *
 * @internal
 */
final class Transcript
{
    /** @var list<array<string, mixed>> plain JSON, so it can grow delta by delta */
    private array $messages;

    /** @param list<Message> $messages */
    public function __construct(array $messages)
    {
        $this->messages = array_map(self::plain(...), $messages);
    }

    public function appendText(string $assistantId, string $delta): void
    {
        $i = $this->assistant($assistantId);
        $content = $this->messages[$i]['content'] ?? '';
        $this->messages[$i]['content'] = (\is_string($content) ? $content : '').$delta;
    }

    public function addToolCall(string $assistantId, ToolCall $toolCall): void
    {
        $i = $this->assistant($assistantId);
        $toolCalls = $this->messages[$i]['toolCalls'] ?? [];
        $this->messages[$i]['toolCalls'] = [...(\is_array($toolCalls) ? $toolCalls : []), [
            'id' => $toolCall->getId(),
            'type' => 'function',
            'function' => [
                'name' => $toolCall->getName(),
                'arguments' => json_encode((object) $toolCall->getArguments(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            ],
        ]];
    }

    public function addToolResult(string $messageId, string $toolCallId, string $content): void
    {
        $this->messages[] = ['id' => $messageId, 'role' => 'tool', 'toolCallId' => $toolCallId, 'content' => $content];
    }

    public function addReasoning(string $messageId, string $content): void
    {
        $this->messages[] = ['id' => $messageId, 'role' => 'reasoning', 'content' => $content];
    }

    public function signReasoning(string $messageId, string $encryptedValue): void
    {
        foreach ($this->messages as $i => $message) {
            if ($messageId === $message['id']) {
                $this->messages[$i]['encryptedValue'] = $encryptedValue;
            }
        }
    }

    /** @param array<string, mixed> $content */
    public function addActivity(string $messageId, string $activityType, array $content): void
    {
        $this->messages[] = ['id' => $messageId, 'role' => 'activity', 'activityType' => $activityType, 'content' => $content];
    }

    /** @return list<Message> */
    public function messages(): array
    {
        return array_map(static fn (array $message): Message => Messages::fromArray($message), $this->messages);
    }

    /**
     * A message as plain JSON data: toArray() keeps empty objects as \stdClass, which
     * this log, growing by array operations, cannot use.
     *
     * @return array<string, mixed>
     */
    private static function plain(Message $message): array
    {
        $data = json_decode(json_encode($message->toArray(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($data)) {
            throw new \LogicException('A message always encodes as a JSON object.');
        }

        $plain = [];
        foreach ($data as $key => $value) {
            $plain[(string) $key] = $value;
        }

        return $plain;
    }

    private function assistant(string $messageId): int
    {
        foreach ($this->messages as $i => $message) {
            if ($messageId === $message['id']) {
                return $i;
            }
        }

        $this->messages[] = ['id' => $messageId, 'role' => 'assistant'];

        return array_key_last($this->messages);
    }
}
