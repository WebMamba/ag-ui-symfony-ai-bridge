<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Tests\Fixtures;

use Symfony\Component\Validator\Constraints as Assert;

final class Cart
{
    /** @var list<CartItem> */
    #[Assert\Valid]
    #[Assert\Count(max: 3)]
    public array $items = [];

    public function add(string $sku): void
    {
        $this->items[] = new CartItem($sku, 1);
    }
}
