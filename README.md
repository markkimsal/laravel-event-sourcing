# Event sourcing with no concurrency bugs

I haven't found a PHP library yet that does event sourcing properly wrt concurrency, so I fixed one.

Fixes from spatie fork:
* don't allow concurrent updates to event stream for same aggregate root UUID
* Fix concurrent reading - (locking reads (fixes pkey "gaps"))
* Fix concurrent writing - (make aggregateVersion per event rather than per persist)


Other changes:
* Removed short fn () syntax (php 7.3)
* Removed member variable types (php 7.3)
* AggregateRoot uuid is protected instead of private
* static make() like retrieve but w/o db lookup


# Locking and Transactions

Mysql's `SELECT ... LOCK IN SHARE MODE` behaves 2 different ways depending on if it is done inside a transaction or outside (with autocommit=1).

Using a locking read will try to obtain a a gap lock or next-key lock because we are scanning a non-unique index of 'aggregate\_uuid' (but I believe this also happens even with a compound unique key of 'aggregate\_uuid' and 'aggregate\_version').  Trying to obtain the lock with autocommit=1 and outside of a `BEGIN` or `START TRANSACTION` statement will have the effect of waiting for other inserts which obtained a next-key lock to complete and flush the output.  This means that selecting ranges of pkeys will not result in "gaps".

Using a locking read inside a transaction will try to obtain the same locks on any rows scanned plus obtain a lock on the next item to be inserted into any index scanned.  So, using a locking read inside a transaction will block all inserts into `stored_events` table because the lock is on the next highest value of 'aggregate\_uuid', effectively blocking all inserts.

We are not using a locking read inside a transaction when finding the highest aggregate\_version right before inserting new events, but this idea was attempted so some function comments might be referring to this old method.

All this assumes the ISOLATION LEVEL READ COMMITTED (which is the mysql default).

For all `retrieve()` calls whose purpose is to reconstitute an Aggregate, we use locking reads to ensure we see a consistent view of any in-flight write transactions.

For any pre-insert reads which try to ensure the fencing parameter 'aggregate\_version' is up-to-date with the in-memory aggregate, we do not use locking reads and rely on the unique index of 'aggregate\_uuid' + 'aggregate\_version' to prevent stale writes.o

Any call to `persist()` could throw a `CouldNotPersistAggregate` exception.


## Static Make
When you subclass AggregateRoot, you always must do a `retrieve($uuid)` call to set the UUID.  It's not possible to set the UUID and skip any event loading.

```
//spatie way
$order = OrderAggregate::retrieve($uuid);

//my way
$order = OrderAggregate::make($uuid);
```

## Original README follows

This package aims to be the entry point to get started with event sourcing in Laravel. It can help you with setting up aggregates, projectors, and reactors. 

If you've never worked with event sourcing, or are uncertain about what aggregates, projectors and reactors are head over to the getting familiar with event sourcing section [in our docs](https://docs.spatie.be/laravel-event-sourcing/v1/getting-familiar-with-event-sourcing/introduction).

Event sourcing might be a good choice for your project if:

- your app needs to make decisions based on the past
- your app has auditing requirements: the reason why your app is in a certain state is equally as important as the state itself
- you foresee that there will be a reporting need in the future, but you don't know yet which data you need to collect for those reports

If you want to skip to reading code immediately, here are some example apps. In each of them, you can create accounts and deposit or withdraw money. 

- [Larabank built traditionally without event sourcing](https://github.com/spatie/larabank-traditional)
- [Larabank built with projectors](https://github.com/spatie/larabank-event-projector)
- [Larabank built with aggregates and projectors](https://github.com/spatie/larabank-event-projector-aggregates)

## Support us

We invest a lot of resources into creating [best in class open source packages](https://spatie.be/open-source). You can support us by [buying one of our paid products](https://spatie.be/open-source/support-us). 

We highly appreciate you sending us a postcard from your hometown, mentioning which of our package(s) you are using. You'll find our address on [our contact page](https://spatie.be/about-us). We publish all received postcards on [our virtual postcard wall](https://spatie.be/open-source/postcards).

## Documentation

You can find installation instructions and detailed instructions on how to use this package at [the dedicated documentation site](https://docs.spatie.be/laravel-event-sourcing/v1/introduction/).

## Upgrading from laravel-event-projector

This package supercedes [laravel-event-projector](https://github.com/spatie/laravel-event-projector). It has the same API. Upgrading from laravel-event-projector to laravel-event-sourcing is easy. Take a look at [our upgrade guide](UPGRADING.md).

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security related issues, please email freek@spatie.be instead of using the issue tracker.

## Postcardware

You're free to use this package, but if it makes it to your production environment we highly appreciate you sending us a postcard from your hometown, mentioning which of our package(s) you are using.

Our address is: Spatie, Samberstraat 69D, 2060 Antwerp, Belgium.

We publish all received postcards [on our company website](https://spatie.be/en/opensource/postcards).

## Credits

- [Freek Van der Herten](https://github.com/freekmurze)
- [All Contributors](../../contributors)

The aggregate root functionality is heavily inspired by [Frank De Jonge](https://twitter.com/frankdejonge)'s excellent [EventSauce](https://eventsauce.io/) package. A big thank you to [Dries Vints](https://github.com/driesvints) for giving lots of valuable feedback while we were developing the package. 

## Footnotes

<a name="footnote1"><sup>1</sup></a> Quote taken from [Event Sourcing made Simple](https://kickstarter.engineering/event-sourcing-made-simple-4a2625113224)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
