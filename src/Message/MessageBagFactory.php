<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Message;

use AgUi\Content\TextPart;
use AgUi\Message\AssistantMessage;
use AgUi\Message\DeveloperMessage;
use AgUi\Message\Message;
use AgUi\Message\ReasoningMessage;
use AgUi\Message\SystemMessage;
use AgUi\Message\ToolMessage;
use AgUi\Message\UserMessage;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Message\Message as PlatformMessage;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Turns the conversation the client sent into what a Symfony AI agent reads.
 *
 *  - Reasoning that carries an encrypted value goes back to the model as a signed
 *    thinking block on the assistant turn that follows it — which is what lets a
 *    provider like Anthropic think while continuing a tool call.
 *  - Activity messages are the client's: the model never reads them.
 *  - System and developer messages are instructions, kept apart (see instructions()):
 *    the agent's system prompt is the server's, and the client may only add to it.
 *
 * @internal
 */
final class MessageBagFactory
{
    /** @param list<Message> $messages */
    public static function messageBag(array $messages): MessageBag
    {
        $bag = new MessageBag();

        /** @var array<string, ToolCall> $calls */
        $calls = [];
        $thinking = null;

        foreach ($messages as $message) {
            if ($message instanceof ReasoningMessage) {
                $thinking = null !== $message->encryptedValue ? new Thinking($message->content, $message->encryptedValue) : null;
                continue;
            }

            if ($message instanceof UserMessage) {
                $text = self::text($message->content);
                if ('' !== $text) {
                    $bag = $bag->with(PlatformMessage::ofUser($text));
                }
            } elseif ($message instanceof AssistantMessage) {
                $parts = null !== $thinking ? [$thinking] : [];
                if (null !== $message->content && '' !== trim($message->content)) {
                    $parts[] = $message->content;
                }
                foreach ($message->toolCalls ?? [] as $agUiCall) {
                    $call = self::toolCall($agUiCall);
                    $calls[$call->getId()] = $call;
                    $parts[] = $call;
                }

                if ([] !== $parts) {
                    $bag = $bag->with(PlatformMessage::ofAssistant(...$parts));
                }
            } elseif ($message instanceof ToolMessage) {
                $call = $calls[$message->toolCallId] ?? null;
                if (null !== $call) {
                    $content = null !== $message->error && '' !== $message->error ? 'Error: '.$message->error : self::text($message->content);
                    $bag = $bag->with(PlatformMessage::ofToolCall($call, '' !== $content ? $content : '(no result)'));
                }
            }

            // Thinking belongs to the turn that directly follows it.
            $thinking = null;
        }

        return $bag;
    }

    /**
     * What the client's system and developer messages say, for a route that trusts
     * them.
     *
     * @param list<Message> $messages
     *
     * @return list<string>
     */
    public static function instructions(array $messages): array
    {
        $instructions = [];

        foreach ($messages as $message) {
            if (($message instanceof SystemMessage || $message instanceof DeveloperMessage) && '' !== trim($message->content)) {
                $instructions[] = $message->content;
            }
        }

        return $instructions;
    }

    /**
     * Tool calls no tool message answers yet: frontend tools waiting for the client, or
     * calls paused on an interrupt. Every one must be answered before the model can be
     * called again — a new user message does not close them.
     *
     * @param list<Message> $messages
     *
     * @return array<string, ToolCall> keyed by tool call id
     */
    public static function openToolCalls(array $messages): array
    {
        $open = [];

        foreach ($messages as $message) {
            if ($message instanceof AssistantMessage) {
                foreach ($message->toolCalls ?? [] as $agUiCall) {
                    $open[$agUiCall->id] = self::toolCall($agUiCall);
                }
            } elseif ($message instanceof ToolMessage) {
                unset($open[$message->toolCallId]);
            }
        }

        return $open;
    }

    private static function toolCall(\AgUi\Message\ToolCall $call): ToolCall
    {
        $decoded = json_decode($call->function->arguments, true);

        // Arguments are a JSON object; PHP turns its numeric keys into integers.
        $arguments = [];
        foreach (\is_array($decoded) ? $decoded : [] as $name => $value) {
            $arguments[(string) $name] = $value;
        }

        return new ToolCall($call->id, $call->function->name, $arguments);
    }

    /** @param string|list<mixed> $content a string, or content parts of which only the text ones are kept */
    private static function text(string|array $content): string
    {
        if (\is_string($content)) {
            return trim($content);
        }

        $text = '';
        foreach ($content as $part) {
            if ($part instanceof TextPart) {
                $text .= $part->text;
            }
        }

        return trim($text);
    }
}
