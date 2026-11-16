<?php

declare(strict_types=1);

namespace AgUi\Bridge\SymfonyAi\Interrupt;

use AgUi\Run\ResumeEntry;

/**
 * A tool that may answer with an Interrupt, and turns the human's answer into its
 * result once it arrives, in the next run.
 */
interface ResumableTool
{
    /**
     * @param array<string, mixed> $arguments the arguments of the interrupted call
     *
     * @return mixed what the model reads as the tool's result: a string, a \Stringable,
     *               an Activity, or anything Symfony AI's ToolResultConverter accepts
     */
    public function resume(array $arguments, ResumeEntry $resume): mixed;
}
