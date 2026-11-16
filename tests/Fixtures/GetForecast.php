<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Tests\Fixtures;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('get_forecast', 'The weather forecast.')]
final class GetForecast
{
    public function __invoke(string $place): string
    {
        return "Sunny in $place.";
    }
}
