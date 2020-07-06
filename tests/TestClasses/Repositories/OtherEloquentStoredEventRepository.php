<?php

namespace Spatie\EventSourcing\Tests\TestClasses\Repositories;

use Spatie\EventSourcing\EloquentConcurrentEventRepository;
use Spatie\EventSourcing\Tests\TestClasses\Models\OtherEloquentStoredEvent;

class OtherEloquentStoredEventRepository extends EloquentConcurrentEventRepository
{
    protected $storedEventModel;

    public function __construct()
    {
        $this->storedEventModel = OtherEloquentStoredEvent::class;
    }
}
