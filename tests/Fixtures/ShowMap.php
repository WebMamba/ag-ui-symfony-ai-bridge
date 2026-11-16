<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Tests\Fixtures;

use AgUi\Bridge\SymfonyAi\Activity\Activity;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('show_map', 'Shows a map.')]
final class ShowMap
{
    public function __invoke(string $place): Activity
    {
        return new Activity('Displayed a map.', 'map', ['place' => $place]);
    }
}
