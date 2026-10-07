<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger;

use App\Messenger\DoctrineTransportFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

class DoctrineTransportFactoryTest extends KernelTestCase
{
    public function testAmqpOptionsAreDroppedAndTheTransportNameBecomesTheQueue(): void
    {
        $inner = $this->recordingFactory();

        (new DoctrineTransportFactory($inner))->createTransport('doctrine://default', [
            'queues' => ['inbox' => ['arguments' => ['x-queue-version' => 2]]],
            'exchange' => ['name' => 'inbox'],
            'delay' => ['queue_name_pattern' => 'delay_%delay%'],
            'transport_name' => 'inbox',
        ], $this->createStub(SerializerInterface::class));

        $this->assertSame(['transport_name' => 'inbox', 'queue_name' => 'inbox'], $inner->options);
    }

    public function testAnExplicitQueueNameWins(): void
    {
        $inner = $this->recordingFactory();

        (new DoctrineTransportFactory($inner))->createTransport('doctrine://default', [
            'queue_name' => 'failed',
            'transport_name' => 'failed',
        ], $this->createStub(SerializerInterface::class));

        $this->assertSame('failed', $inner->options['queue_name']);
    }

    public function testOtherOptionsReachTheDoctrineTransport(): void
    {
        $inner = $this->recordingFactory();

        (new DoctrineTransportFactory($inner))->createTransport('doctrine://default', [
            'redeliver_timeout' => 900,
            'not_an_option' => true,
            'transport_name' => 'deliver',
        ], $this->createStub(SerializerInterface::class));

        $this->assertSame(900, $inner->options['redeliver_timeout']);
        $this->assertTrue($inner->options['not_an_option'], 'an unknown option must still reach Doctrine, which rejects it');
    }

    public function testSupportsIsDelegated(): void
    {
        $factory = new DoctrineTransportFactory($this->recordingFactory());

        $this->assertTrue($factory->supports('doctrine://default', []));
        $this->assertFalse($factory->supports('amqp://guest:guest@rabbitmq:5672/%2f/messages', []));
    }

    public function testTheContainerDecoratesTheDoctrineFactory(): void
    {
        $this->assertInstanceOf(
            DoctrineTransportFactory::class,
            static::getContainer()->get('messenger.transport.doctrine.factory'),
        );
    }

    private function recordingFactory(): RecordingTransportFactory
    {
        return new RecordingTransportFactory();
    }
}

/**
 * @implements TransportFactoryInterface<TransportInterface>
 */
final class RecordingTransportFactory implements TransportFactoryInterface
{
    /** @var array<string, mixed> */
    public array $options = [];

    /**
     * @param array<string, mixed> $options
     */
    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $this->options = $options;

        return new InMemoryTransport();
    }

    /**
     * @param array<string, mixed> $options
     */
    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'doctrine://');
    }
}
