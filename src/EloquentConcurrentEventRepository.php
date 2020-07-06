<?php

namespace Spatie\EventSourcing;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;
use Spatie\EventSourcing\EventSerializers\EventSerializer;
use Spatie\EventSourcing\Exceptions\InvalidEloquentStoredEventModel;
use Spatie\EventSourcing\Models\EloquentStoredEvent;

class EloquentConcurrentEventRepository implements StoredEventRepository
{
    protected $storedEventModel;

    public function __construct()
    {
        $this->storedEventModel = config('event-sourcing.stored_event_model', EloquentStoredEvent::class);

        if (! new $this->storedEventModel instanceof EloquentStoredEvent) {
            throw new InvalidEloquentStoredEventModel("The class {$this->storedEventModel} must extend EloquentStoredEvent");
        }
    }

    public function retrieveAll(string $uuid = null): LazyCollection
    {
        /** @var \Illuminate\Database\Query\Builder $query */
        $query = $this->storedEventModel::query()->lockInShareMode();

        if ($uuid) {
            $query->uuid($uuid);
        }

        return $query->orderBy('id')->cursor()->map(function (EloquentStoredEvent $storedEvent) {
            return $storedEvent->toStoredEvent();
        });
    }

    public function retrieveAllStartingFrom(int $startingFrom, string $uuid = null): LazyCollection
    {
        $query = $this->prepareEventModelQuery($startingFrom, $uuid);

        return $query->orderBy('id')->cursor()->map(function (EloquentStoredEvent $storedEvent) {
            return $storedEvent->toStoredEvent();
        });
    }

    public function countAllStartingFrom(int $startingFrom, string $uuid = null): int
    {
        return $this->prepareEventModelQuery($startingFrom, $uuid)->count('id');
    }

    public function retrieveAllAfterVersion(int $version, string $uuid): LazyCollection
    {
        /** @var \Illuminate\Database\Query\Builder $query */
        $query = $this->storedEventModel::query()
			->lockInShareMode()
            ->uuid($uuid)
            ->afterVersion($version);

        return $query->orderBy('id')->cursor()->map(function (EloquentStoredEvent $storedEvent) {
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

		$eloquentStoredEvent->save();

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

		foreach ($events as $idx => $event) {
			\DB::transaction(function() use ($event, $uuid, $aggregateVersion, $idx, &$storedEvents){
				$storedEvents[] = self::persist($event, $uuid, ($aggregateVersion + $idx + 1));
			});
		}

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

        if (! empty($map) && in_array($class, $map)) {
            return array_search($class, $map, true);
        }

        return $class;
    }

    public function getLatestAggregateVersion(string $aggregateUuid): int
    {
        return $this->storedEventModel::query()
            ->where('aggregate_uuid', $aggregateUuid)
            ->max('aggregate_version') ?? 0;
    }

    private function prepareEventModelQuery(int $startingFrom, string $uuid = null): Builder
    {
        /** @var Builder $query */
        $query = $this->storedEventModel::query()->startingFrom($startingFrom)->lockInShareMode();

        if ($uuid) {
            $query->uuid($uuid);
        }

        return $query;
    }
}
