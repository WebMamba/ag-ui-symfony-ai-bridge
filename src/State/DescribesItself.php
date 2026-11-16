<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\State;

/**
 * A state class that writes what the model reads about it, instead of its JSON —
 * for what the model needs that the state only stores implicitly (totals, distances).
 */
interface DescribesItself
{
    public function describeForAgent(): string;
}
