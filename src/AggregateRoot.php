<?php

namespace Spatie\EventSourcing;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionProperty;
use Spatie\EventSourcing\Exceptions\CouldNotPersistAggregate;
use Spatie\EventSourcing\Snapshots\Snapshot;
use Spatie\EventSourcing\Snapshots\SnapshotRepository;

abstract class AggregateRoot
{
    protected $uuid = '';
    protected $storedEventRepository = null;

    private $recordedEvents = [];

    public $aggregateVersion = 0;

    public $aggregateVersionAfterReconstitution = 0;

    protected static $allowConcurrency = false;

    public static function make(string $uuid): self
    {
        $aggregateRoot = (new static());

        $aggregateRoot->uuid = $uuid;

        return $aggregateRoot;
    }

    public static function retrieve(string $uuid): self
    {
        $aggregateRoot = (new static());

        $aggregateRoot->uuid = $uuid;

        return $aggregateRoot->reconstituteFromEvents();
    }

    public function recordThat(ShouldBeStored $domainEvent): self
    {
        $this->recordedEvents[] = $domainEvent;

        $this->apply($domainEvent);

        return $this;
    }

    public function persist(): self
    {
        try {
            $storedEvents = call_user_func(
                [$this->getStoredEventRepository(), 'persistMany'],
                $this->getAndClearRecordedEvents(),
                $this->uuid ?? null,
                $this->aggregateVersionAfterReconstitution,
            );

            $storedEvents->each(function (StoredEvent $storedEvent) {
                $storedEvent->handle();
            });

            $this->aggregateVersionAfterReconstitution += $storedEvents->count();
        } catch (\Exception $e) {
            //an exception will occur if the same aggregate uuid and version already exist
            throw CouldNotPersistAggregate::unexpectedVersionAlreadyPersisted(
                $this,
                $this->uuid,
                $this->aggregateVersionAfterReconstitution,
                $this->aggregateVersionAfterReconstitution,
                $e
            );
        }

        //check for FakeAggregateRoot
        if ($this->aggregateVersion > 0) {
            if ($this->aggregateVersionAfterReconstitution != $this->aggregateVersion) {
                throw CouldNotPersistAggregate::unexpectedVersionAlreadyPersisted(
                    $this,
                    $this->uuid,
                    $this->aggregateVersion,
                    $this->aggregateVersionAfterReconstitution,
                );
                throw new Exceptions\ConcurrencyException('aggregate version is not the same after saving');
            }
        }

        return $this;
    }

    public function snapshot(): Snapshot
    {
        return $this->getSnapshotRepository()->persist(new Snapshot(
            $this->uuid,
            $this->aggregateVersion,
            $this->getState(),
        ));
    }

    protected function getSnapshotRepository(): SnapshotRepository
    {
        return app($this->snapshotRepository ?? config('event-sourcing.snapshot_repository'));
    }

    protected function getStoredEventRepository(): StoredEventRepository
    {
        $key = 'event-sourcing.consistent_stored_event_repository';
        if (static::$allowConcurrency) {
            $key = 'event-sourcing.stored_event_repository';
        }
        return app($this->storedEventRepository ?? $key);
    }

    public function getRecordedEvents(): array
    {
        return $this->recordedEvents;
    }

    protected function getState(): array
    {
        $class = new ReflectionClass($this);

        return collect($class->getProperties())
            ->reject(function (ReflectionProperty $reflectionProperty) {
                return $reflectionProperty->isStatic();
            })
            ->mapWithKeys(function (ReflectionProperty $property) {
                return [$property->getName() => $this->{$property->getName()}];
            })->toArray();
    }

    protected function useState(array $state): void
    {
        foreach ($state as $key => $value) {
            $this->$key = $value;
        }
    }

    protected function getAndClearRecordedEvents(): array
    {
        $recordedEvents = $this->recordedEvents;

        $this->recordedEvents = [];

        return $recordedEvents;
    }

    protected function reconstituteFromEvents(): self
    {
        $storedEventRepository = $this->getStoredEventRepository();
        $snapshot = $this->getSnapshotRepository()->retrieve($this->uuid);

        if ($snapshot) {
            $this->aggregateVersion = $snapshot->aggregateVersion;
            $this->useState($snapshot->state);
        }

        $storedEventRepository->retrieveAllAfterVersion($this->aggregateVersion, $this->uuid)
            ->each(function (StoredEvent $storedEvent) {
                $this->apply($storedEvent->event);
            });

        $this->aggregateVersionAfterReconstitution = $this->aggregateVersion;

        return $this;
    }

    private function apply(ShouldBeStored $event): void
    {
        $classBaseName = class_basename($event);

        $camelCasedBaseName = ucfirst(Str::camel($classBaseName));

        $applyingMethodName = "apply{$camelCasedBaseName}";

        if (method_exists($this, $applyingMethodName)) {
            $this->$applyingMethodName($event);
        }

        $this->aggregateVersion++;
    }

    /**
     * @param \Spatie\EventSourcing\ShouldBeStored|\Spatie\EventSourcing\ShouldBeStored[] $events
     *
     * @return $this
     */
    public static function fake($events = []): FakeAggregateRoot
    {
        $events = Arr::wrap($events);

        //$uuid = \Spatie\EventSourcing\Tests\TestClasses\FakeUuid::generate();
        $uuid = substr(sha1(rand()), 0, 12);
        return (new FakeAggregateRoot(static::retrieve($uuid)))->given($events);
    }
}
