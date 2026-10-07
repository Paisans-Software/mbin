<?php

declare(strict_types=1);

namespace App\Messenger;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Lets the transports in config/packages/messenger.yaml run on the Doctrine
 * transport (MESSENGER_TRANSPORT_DSN=doctrine://...) without changing them.
 *
 * Those transports carry AMQP options (queues, exchange), which the Doctrine
 * transport rejects as unknown, and name no queue, so on Doctrine every one of
 * them would share the "default" queue and take the others' messages. This
 * drops the AMQP option keys and names each transport's queue after the
 * transport. AMQP DSNs never reach this factory, so RabbitMQ is unaffected.
 *
 * @implements TransportFactoryInterface<TransportInterface>
 */
#[AsDecorator('messenger.transport.doctrine.factory')]
final class DoctrineTransportFactory implements TransportFactoryInterface
{
    /** The AMQP transport's own option keys, which mean nothing to Doctrine. */
    private const array AMQP_OPTIONS = ['queues', 'exchange', 'delay'];

    /**
     * @param TransportFactoryInterface<TransportInterface> $inner
     */
    public function __construct(
        #[AutowireDecorated]
        private readonly TransportFactoryInterface $inner,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $options = array_diff_key($options, array_flip(self::AMQP_OPTIONS));
        // A queue_name in the DSN query still wins: Doctrine merges it over these options.
        if (isset($options['transport_name'])) {
            $options['queue_name'] ??= $options['transport_name'];
        }

        return $this->inner->createTransport($dsn, $options, $serializer);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return $this->inner->supports($dsn, $options);
    }
}
