<?php

namespace Spatie\EventSourcing\Tests\TestClasses\AggregateRoots\StorableEvents;

use Spatie\EventSourcing\ShouldBeStored;

class MoneyAdded implements ShouldBeStored
{
    public $amount;

    public function __construct(int $amount)
    {
        $this->amount = $amount;
    }
}
