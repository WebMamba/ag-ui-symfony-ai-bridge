<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Tests\Fixtures;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('add_to_cart', 'Adds a product to the cart.')]
final class AddToCart
{
    public function __construct(private Cart $cart)
    {
    }

    public function __invoke(string $sku): string
    {
        $this->cart->add($sku);

        return 'Added.';
    }
}
