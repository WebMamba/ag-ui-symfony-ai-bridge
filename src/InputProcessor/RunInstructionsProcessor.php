<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\InputProcessor;

use AgUi\Bridge\SymfonyAi\Run\RunScope;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;

/**
 * Adds what the run tells the model — the shared state, the client's context — to the
 * agent's system prompt, after it.
 *
 * It has to be an input processor that runs after the agent's system prompt one: that
 * one skips its prompt altogether when the message bag already has a system message, so
 * putting these instructions in the bag up front would erase the agent's own.
 */
final readonly class RunInstructionsProcessor implements InputProcessorInterface
{
    public function __construct(
        private RunScope $scope,
        /** The name of the agent it belongs to; null for any run. */
        private ?string $agent = null,
    ) {
    }

    public function processInput(Input $input): void
    {
        if (!$this->scope->isOpenFor($this->agent)) {
            return;
        }

        $this->scope->markInstructionsApplied();

        if ([] === $this->scope->instructions()) {
            return;
        }

        $bag = $input->getMessageBag();
        $instructions = Message::forSystem(implode("\n\n", $this->scope->instructions()));

        // Right after the agent's own system message: the platform joins system messages.
        $messages = [];
        $placed = false;
        foreach ($bag->getMessages() as $message) {
            if (!$placed && !$message instanceof SystemMessage) {
                $messages[] = $instructions;
                $placed = true;
            }
            $messages[] = $message;
        }
        if (!$placed) {
            $messages[] = $instructions;
        }

        $input->setMessageBag(MessageBag::forId($bag->getId(), ...$messages));
    }
}
