<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Scheduler\MbinTaskProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Scheduler\Generator\MessageGenerator;

/**
 * The schedule runs inside every messenger consumer of the scheduler transport.
 * Without a lock, each consumer generates the recurring messages on its own.
 */
class MbinTaskProviderTest extends TestCase
{
    public function testScheduleHasALock(): void
    {
        $provider = new MbinTaskProvider(new ArrayAdapter(), new LockFactory(new InMemoryStore()));

        self::assertNotNull($provider->getSchedule()->getLock());
    }

    public function testTwoProvidersContendForTheSameLock(): void
    {
        $lockFactory = new LockFactory(new InMemoryStore());
        $first = new MbinTaskProvider(new ArrayAdapter(), $lockFactory);
        $second = new MbinTaskProvider(new ArrayAdapter(), $lockFactory);

        self::assertTrue($first->getSchedule()->getLock()->acquire());
        self::assertFalse($second->getSchedule()->getLock()->acquire());
    }

    public function testOnlyOneConsumerGeneratesTheDailyMessages(): void
    {
        $clock = new MockClock();
        $lockFactory = new LockFactory(new InMemoryStore());

        // Separate caches, as with consumers on hosts that do not share one.
        $first = new MessageGenerator(new MbinTaskProvider(new ArrayAdapter(), $lockFactory), 'default', $clock);
        $second = new MessageGenerator(new MbinTaskProvider(new ArrayAdapter(), $lockFactory), 'default', $clock);

        self::assertCount(0, iterator_to_array($first->getMessages(), false));
        self::assertCount(0, iterator_to_array($second->getMessages(), false));

        $clock->modify('+1 day +1 second');

        self::assertCount(2, iterator_to_array($first->getMessages(), false));
        self::assertCount(0, iterator_to_array($second->getMessages(), false));
    }
}
