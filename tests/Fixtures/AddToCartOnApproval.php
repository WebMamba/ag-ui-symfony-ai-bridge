<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Tests\Fixtures;

use AgUi\Bridge\SymfonyAi\Interrupt\Interrupt;
use AgUi\Bridge\SymfonyAi\Interrupt\ResumableTool;
use AgUi\Run\ResumeEntry;
use AgUi\Run\ResumeStatus;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/** A resumable tool that changes the shared state when the human answers. */
#[AsTool('add_to_cart_on_approval', 'Adds a product to the cart once the customer approves.')]
final class AddToCartOnApproval implements ResumableTool
{
    public function __construct(private Cart $cart)
    {
    }

    public function __invoke(string $sku): Interrupt
    {
        return Interrupt::confirmation("Add $sku to the cart?");
    }

    public function resume(array $arguments, ResumeEntry $resume): mixed
    {
        if (ResumeStatus::Resolved !== $resume->status || true !== ($resume->payload['approved'] ?? false)) {
            return 'Not added.';
        }

        $this->cart->add($arguments['sku']);

        return 'Added.';
    }
}
