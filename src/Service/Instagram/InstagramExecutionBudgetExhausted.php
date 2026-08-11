<?php

namespace App\Service\Instagram;

/** @internal Control-flow signal caught inside the handler; never reaches Messenger. */
final class InstagramExecutionBudgetExhausted extends \RuntimeException
{
    public function __construct(public readonly int $requiredSeconds)
    {
        parent::__construct('The HTTP execution budget is insufficient for the next Instagram operation.');
    }
}
