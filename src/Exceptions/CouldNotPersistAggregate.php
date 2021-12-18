<?php

namespace Spatie\EventSourcing\Exceptions;

use Exception;
use Spatie\EventSourcing\AggregateRoot;

class CouldNotPersistAggregate extends Exception
{
    public static function unexpectedVersionAlreadyPersisted(
		$aggregateRoot,
        string $uuid,
        int $expectedVersion,
        int $actualVersion,
        Exception $root=null
    ) {
        $aggregateRootClass = class_basename($aggregateRoot);

        return new static("Could not persist aggregate {$aggregateRootClass} (uuid: {$uuid}) because it seems to be changed by another process after it was retrieved in the current process. Expect to persist events after version {$expectedVersion}, but version {$actualVersion} was already persisted.", null, $root);
    }
}
