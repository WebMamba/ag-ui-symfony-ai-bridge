<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Tests\Fixtures;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('set_theme', 'Changes the interface theme.')]
final readonly class SetTheme
{
    public function __construct(private Theme $theme)
    {
    }

    public function __invoke(string $theme): string
    {
        $this->theme->current = $theme;

        return 'Done.';
    }
}
