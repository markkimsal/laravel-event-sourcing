<?php

namespace Spatie\EventSourcing\Tests;

use Spatie\EventSourcing\Exceptions\CouldNotPersistAggregate;
use Spatie\EventSourcing\Models\EloquentStoredEvent;
use Spatie\EventSourcing\Tests\TestClasses\AggregateRoots\AccountAggregateRoot;
use Spatie\EventSourcing\Tests\TestClasses\FakeUuid;

class ConcurrencyTest extends TestCase
{
    private $aggregateUuid;
    private $aggregateUuid2;

    public function setUp(): void
    {
        parent::setUp();

        $this->setConfig('database.connections.mysql2', [
            'driver' => 'mysql',
            'url' => env('DATABASE_URL'),
            'host' => env('DB2_HOST', '127.0.0.1'),
            'port' => env('DB2_PORT', '3306'),
            'database' => env('DB2_DATABASE', 'forge'),
            'username' => env('DB2_USERNAME', 'forge'),
            'password' => env('DB2_PASSWORD', ''),
            'unix_socket' => env('DB2_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                \PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ]);

        $this->aggregateUuid  = FakeUuid::generate();
        $this->aggregateUuid2 = FakeUuid::generate();
    }

    /**
     * @test
     */
    public function it_cannot_persist_aggregate_when_another_connection_has_a_lock()
    {
        AccountAggregateRoot::retrieve($this->aggregateUuid)
            ->addMoney(100)
            ->persist();

        \DB::connection('mysql')->statement('SET innodb_lock_wait_timeout=1');

        $this->expectException(CouldNotPersistAggregate::class);
        //this should produce a deadlock which is killed after 2 seconds.
        //we can't exactly do multi-threaded stuff so this dangling
        //SELECT ... FOR UPDATE simulates the ensureNoOtherEventsHaveBeenPersisted()
        //and getLatestAggregateVersion() calls in the concurrent event repository
        //
        //
        //if any other process/connection has written to the db
        //between retrieval and persist, then an exception will be thrown.
        \DB::connection('mysql2')->transaction(function ($db) {
            //$db->select('select * from stored_events where aggregate_uuid = \''.$this->aggregateUuid2.'\' LOCK IN SHARE MODE', []);
            //$db->select('select max(aggregate_version) from stored_events where aggregate_uuid = \''.$this->aggregateUuid.'\'', []);

            $ag  = AccountAggregateRoot::retrieve($this->aggregateUuid);
            $ag2 = AccountAggregateRoot::retrieve($this->aggregateUuid);

            $ag
                ->addMoney(100)
                ->persist();

            $ag2->connection = 'mysql2';
            $ag2
                ->addMoney(100)
                ->persist();

            /*
            AccountAggregateRoot::retrieve($this->aggregateUuid)
                ->addMoney(100)
                ->persist();
             */

            //above insert/persist hangs until lock wait timeout
        });

        //timeout did not allow concurrent inserts into the same aggregateUuid
        $storedEvents = EloquentStoredEvent::get();
        $this->assertCount(2, $storedEvents);
    }


    /**
     * Lock in share mode with a non-unique index query locks
     * the index supremum to achieve repeatable reads.
     *
     * Any aggregate UUID insert that locks the event_store to check
     * the latest aggregate_version will block the table for all writes.
     * @test
     */
    public function it_wont_block_inserts_when_another_uuid_is_locked()
    {
        AccountAggregateRoot::retrieve($this->aggregateUuid)
            ->addMoney(100)
            ->persist();

        //$this->expectException(CouldNotPersistAggregate::class);

        \DB::connection('mysql')->statement('SET innodb_lock_wait_timeout=1');

        //we can't exactly do multi-threaded stuff so this dangling
        //SELECT ... FOR UPDATE simulates the ensureNoOtherEventsHaveBeenPersisted()
        //and getLatestAggregateVersion() calls in the concurrent event repository
        //
        //Even locking for another aggregate_uuid will lock the entire table essentially
        //because it locks the index for writing
        \DB::connection('mysql2')->transaction(function ($db) {
            //$db->select('select * from stored_events where aggregate_uuid = \''.$this->aggregateUuid2.'\' LOCK IN SHARE MODE', []);
            $db->select('select max(aggregate_version) from stored_events where aggregate_uuid = \'' . $this->aggregateUuid2 . '\'', []);

            AccountAggregateRoot::retrieve($this->aggregateUuid)
                ->addMoney(100)
                ->persist();
            //above persist runs fine with no blocks
        });

        $storedEvents = EloquentStoredEvent::get();
        $this->assertCount(2, $storedEvents);
    }
}
