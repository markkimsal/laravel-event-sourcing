<?php

namespace Spatie\EventSourcing\Tests\TestClasses\Events;

use Spatie\EventSourcing\ShouldBeStored;

class EventWithDatetime implements ShouldBeStored
{
    public $value;

    public function __construct(\DateTimeImmutable $value)
    {
        $this->value = $value;
    }
}
