<?php

namespace Spatie\EventSourcing;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;
use Spatie\EventSourcing\EventSerializers\EventSerializer;
use Spatie\EventSourcing\Exceptions\CouldNotPersistAggregate;
use Spatie\EventSourcing\Exceptions\InvalidEloquentStoredEventModel;
use Spatie\EventSourcing\Models\EloquentStoredEvent;

class EloquentConcurrentEventRepository implements StoredEventRepository
{
    protected $storedEventModel;
    protected static $allowConcurrency = false;

    public function __construct()
    {
        $this->storedEventModel = config('event-sourcing.stored_event_model', EloquentStoredEvent::class);

        if (!new $this->storedEventModel instanceof EloquentStoredEvent) {
            throw new InvalidEloquentStoredEventModel("The class {$this->storedEventModel} must extend EloquentStoredEvent");
        }
    }

    /**
     * Lock in share mode outside of a transaction waits for insertion locks, next-index gaps
     * to be filled and flushed to disk.
     */
    public function retrieveAll(string $uuid = null): LazyCollection
    {
        /** @var \Illuminate\Database\Query\Builder $query */
        $query = $this->storedEventModel::query()->lockInShareMode();

        if ($uuid) {
            $query->uuid($uuid);
        }

        return $query->orderBy('aggregate_version')->cursor()->map(function (EloquentStoredEvent $storedEvent) {
            return $storedEvent->toStoredEvent();
        });
    }

    public function retrieveAllStartingFrom(int $startingFrom, string $uuid = null): LazyCollection
    {
        $query = $this->prepareEventModelQuery($startingFrom, $uuid);

        return $query->orderBy('aggregate_version')->cursor()->map(function (EloquentStoredEvent $storedEvent) {
            return $storedEvent->toStoredEvent();
        });
    }

    public function countAllStartingFrom(int $startingFrom, string $uuid = null): int
    {
        return $this->prepareEventModelQuery($startingFrom, $uuid)->count('id');
    }

    /**
     * Lock in share mode outside of a transaction waits for insertion locks, next-index gaps
     * to be filled and flushed to disk.
     */
    public function retrieveAllAfterVersion(int $version, string $uuid): LazyCollection
    {
        /** @var \Illuminate\Database\Query\Builder $query */
        $query = $this->storedEventModel::query()
            ->lockInShareMode()
            ->uuid($uuid)
            ->afterVersion($version);

        return $query->orderBy('aggregate_version')->cursor()->map(function (EloquentStoredEvent $storedEvent) {
            return $storedEvent->toStoredEvent();
        });
    }

    public function persist(ShouldBeStored $event, string $uuid = null, int $aggregateVersion = null): StoredEvent
    {
        if ($uuid !== null && $aggregateVersion === null) {
            throw new Exceptions\ConcurrencyException('aggregate version cannot be zero');
        }
        /** @var EloquentStoredEvent $eloquentStoredEvent */
        $eloquentStoredEvent = new $this->storedEventModel();

        $eloquentStoredEvent->setRawAttributes([
            'event_properties' => app(EventSerializer::class)->serialize(clone $event),
            'aggregate_uuid' => $uuid,
            'aggregate_version' => $aggregateVersion,
            'event_class' => self::getEventClass(get_class($event)),
            'meta_data' => json_encode([]),
            'created_at' => Carbon::now(),
        ]);

        $x = $eloquentStoredEvent->save();

        return $eloquentStoredEvent->toStoredEvent();
    }

    /**
     * @throw \Exception
     */
    public function persistMany(array $events, string $uuid = null, int $aggregateVersion = null): LazyCollection
    {
        if ($uuid !== null && $aggregateVersion === null) {
            throw new Exceptions\ConcurrencyException('aggregate version cannot be null');
        }
        $storedEvents = [];

        \DB::transaction(function () use ($events, $uuid, $aggregateVersion, &$storedEvents) {
            $this->ensureNoOtherEventsHaveBeenPersisted($uuid, $aggregateVersion);
            foreach ($events as $idx => $event) {
                $storedEvents[] = self::persist($event, $uuid, ($aggregateVersion + $idx + 1));
            }
        });

        return new LazyCollection($storedEvents);
    }

    public function update(StoredEvent $storedEvent): StoredEvent
    {
        /** @var EloquentStoredEvent $eloquentStoredEvent */
        $eloquentStoredEvent = $this->storedEventModel::find($storedEvent->id);

        $eloquentStoredEvent->update($storedEvent->toArray());

        return $eloquentStoredEvent->toStoredEvent();
    }

    private function getEventClass(string $class): string
    {
        $map = config('event-sourcing.event_class_map', []);

        if (!empty($map) && in_array($class, $map)) {
            return array_search($class, $map, true);
        }

        return $class;
    }


    /**
     * Lock in share mode outside of a transaction waits for insertion locks, next-index gaps
     * to be filled and flushed to disk.
     */
    private function prepareEventModelQuery(int $startingFrom, string $uuid = null): Builder
    {
        /** @var Builder $query */
        $query = $this->storedEventModel::query()->startingFrom($startingFrom)->lockInShareMode();
        //->afterVersion(0);

        if ($uuid) {
            $query->uuid($uuid);
        }

        return $query;
    }

    /**
     * @throws CouldNotPersistAggregate Exception when the max aggregateVersion does not
     * match the currently loaded aggregateVersion fencing parameter
     */
    protected function ensureNoOtherEventsHaveBeenPersisted($uuid, $aggregateVersion): void
    {
        if (static::$allowConcurrency) {
            return;
        }
        $latestPersistedVersionId = $this->getLatestAggregateVersion($uuid);

        if ($aggregateVersion !== $latestPersistedVersionId) {
            throw CouldNotPersistAggregate::unexpectedVersionAlreadyPersisted(
                null,
                $uuid,
                $aggregateVersion,
                $latestPersistedVersionId,
            );
        }
    }

    /**
     * Lock in share mode inside a transaction will obtain an index lock on
     * aggregate_uuid or aggregate_uuid + aggregate_version and not allow
     * any other thread to select from the table.
     *
     * We won't do any locks inside this transaction, let other transactions
     * write freely, if we insert a duplicate uuid + version we will
     * abort the entire transaction
     */
    public function getLatestAggregateVersion(string $aggregateUuid): int
    {
        return $this->storedEventModel::query()
            ->where('aggregate_uuid', $aggregateUuid)
            ->max('aggregate_version') ?? 0;
    }
}
