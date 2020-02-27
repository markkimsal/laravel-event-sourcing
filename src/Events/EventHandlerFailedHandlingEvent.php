<?php

namespace Spatie\EventSourcing\Events;

use Exception;
use Spatie\EventSourcing\EventHandlers\EventHandler;
use Spatie\EventSourcing\StoredEvent;

class EventHandlerFailedHandlingEvent
{
    public $eventHandler;

    public $storedEvent;

    public $exception;

    public function __construct(EventHandler $eventHandler, StoredEvent $storedEvent, Exception $exception)
    {
        $this->eventHandler = $eventHandler;

        $this->storedEvent = $storedEvent;

        $this->exception = $exception;
    }
}
