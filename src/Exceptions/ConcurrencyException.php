<?php
namespace Spatie\EventSourcing\Exceptions;

use Exception;
use Spatie\EventSourcing\StoredEvent;

class ConcurrencyException extends Exception
{
}
