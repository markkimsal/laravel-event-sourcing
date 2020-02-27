<?php

namespace Spatie\EventSourcing\Snapshots;

class Snapshot
{
    public $aggregateUuid;

    public $aggregateVersion;

    public $state;

    public function __construct(
        string $aggregateUuid,
        int $aggregateVersion,
        $state
    ) {
        $this->aggregateUuid = $aggregateUuid;
        $this->aggregateVersion = $aggregateVersion;
        $this->state = $state;
    }
}
