<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Tests\Fixtures;

use AgUi\Bridge\SymfonyAi\Activity\Activity;
use AgUi\Bridge\SymfonyAi\Interrupt\Interrupt;
use AgUi\Bridge\SymfonyAi\Interrupt\ResumableTool;
use AgUi\Run\ResumeEntry;
use AgUi\Run\ResumeStatus;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('reserve_mooring', 'Reserves a mooring.')]
final class ReserveMooring implements ResumableTool
{
    public function __invoke(string $marina): Interrupt
    {
        return Interrupt::confirmation("Reserve $marina?")->withActivity(new Activity('Asked to confirm.', 'confirm', ['marina' => $marina]));
    }

    public function resume(array $arguments, ResumeEntry $resume): mixed
    {
        $approved = ResumeStatus::Resolved === $resume->status && \is_array($resume->payload) && true === ($resume->payload['approved'] ?? false);

        return $approved ? 'Reserved at '.$arguments['marina'].'.' : 'Declined: nothing was reserved.';
    }
}
