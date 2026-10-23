<?php

declare(strict_types=1);

namespace App\MessageHandler\ActivityPub\Outbox;

use App\Entity\User;
use App\Exception\InstanceBannedException;
use App\Exception\InvalidApPostException;
use App\Exception\InvalidWebfingerException;
use App\Message\ActivityPub\Outbox\DeliverMessage;
use App\Message\Contracts\MessageInterface;
use App\MessageHandler\MbinMessageHandler;
use App\Repository\InstanceRepository;
use App\Service\ActivityPub\ApHttpClientInterface;
use App\Service\ActivityPub\RestrictedMagazineFederationGuard;
use App\Service\ActivityPubManager;
use App\Service\OutboundFederationPolicy;
use App\Service\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

#[AsMessageHandler]
class DeliverHandler extends MbinMessageHandler
{
    public const HTTP_RESPONSE_CODE_RATE_LIMITED = 429;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly KernelInterface $kernel,
        private readonly ApHttpClientInterface $client,
        private readonly ActivityPubManager $activityPubManager,
        private readonly SettingsManager $settingsManager,
        private readonly OutboundFederationPolicy $policy,
        private readonly LoggerInterface $logger,
        private readonly InstanceRepository $instanceRepository,
        // FORK DIVERGENCE — do not offer this upstream: private magazines
        private readonly RestrictedMagazineFederationGuard $restrictedGuard,
    ) {
        parent::__construct($this->entityManager, $this->kernel);
    }

    /**
     * @throws InvalidApPostException
     */
    public function __invoke(DeliverMessage $message): void
    {
        if (!$this->settingsManager->get('KBIN_FEDERATION_ENABLED')) {
            return;
        }
        $this->workWrapper($message);
    }

    public function workWrapper(MessageInterface $message): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->getNativeConnection(); // calls connect() internally
        $conn->beginTransaction();
        try {
            $this->doWork($message);
            $conn->commit();
        } catch (InvalidApPostException $e) {
            if (400 <= $e->responseCode && 500 > $e->responseCode && self::HTTP_RESPONSE_CODE_RATE_LIMITED !== $e->responseCode) {
                $conn->rollBack();
                $this->logger->debug('{domain} responded with {code} for our request, rolling back the changes and not trying again, request: {body}', [
                    'domain' => $e->url,
                    'code' => $e->responseCode,
                    'body' => $e->payload,
                ]);
                throw new UnrecoverableMessageHandlingException('There is a problem with the request which will stay the same, so discarding', previous: $e);
            } elseif (self::HTTP_RESPONSE_CODE_RATE_LIMITED === $e->responseCode) {
                $conn->rollBack();
                // a rate limit is always recoverable
                throw new RecoverableMessageHandlingException(previous: $e);
            } else {
                // we don't roll back on an InvalidApPostException, so the failed delivery attempt gets written to the DB
                $conn->commit();
                throw $e;
            }
        } catch (TransportExceptionInterface $e) {
            // we don't roll back on an TransportExceptionInterface, so the failed delivery attempt gets written to the DB
            $conn->commit();
            throw $e;
        } catch (\Exception $e) {
            $conn->rollBack();
            throw $e;
        }

        $conn->close();
    }

    /**
     * @throws InvalidApPostException
     * @throws TransportExceptionInterface
     * @throws InvalidArgumentException
     * @throws InstanceBannedException
     * @throws InvalidWebfingerException
     */
    public function doWork(MessageInterface $message): void
    {
        if (!($message instanceof DeliverMessage)) {
            throw new \LogicException();
        }

        if (!$this->policy->mayDeliver($message->apInboxUrl, $message->payload)) {
            // the backstop for the call sites that dispatch a DeliverMessage without going
            // through DeliverManager, and for messages already queued when the instance was
            // marked read only. Checked before the instance row is touched at all.
            return;
        }

        // FORK DIVERGENCE — do not offer this upstream: private magazines
        // the backstop for the call sites that dispatch a DeliverMessage without going
        // through DeliverManager
        if ($this->restrictedGuard->touchesRestrictedMagazine($message->payload) && !$this->restrictedGuard->isAllowedHandshake($message->payload)) {
            $this->logger->debug('not delivering a {type} to {url}, it is about a restricted magazine', [
                'type' => \is_string($message->payload['type'] ?? null) ? $message->payload['type'] : 'activity without a type',
                'url' => $message->apInboxUrl,
            ]);

            return;
        }

        $instance = $this->instanceRepository->getOrCreateInstance(parse_url($message->apInboxUrl, PHP_URL_HOST));
        if ($instance->isDead()) {
            $this->logger->debug('instance {n} is considered dead. Last successful delivery date: {dd}, failed attempts since then: {fa}', [
                'n' => $instance->domain,
                'dd' => $instance->getLastSuccessfulDeliver(),
                'fa' => $instance->getLastFailedDeliver(),
            ]);

            return;
        }

        if ('Announce' !== $message->payload['type']) {
            $url = $message->payload['object']['attributedTo'] ?? $message->payload['actor'];
        } else {
            $url = $message->payload['actor'];
        }
        $this->logger->debug("Getting Actor for url: $url");
        $actor = $this->activityPubManager->findActorOrCreate($url);

        if (!$actor) {
            $this->logger->debug('got no actor :(');

            return;
        }

        if ($actor instanceof User && $actor->isBanned) {
            $this->logger->debug('got an actor, but he is banned :(');

            return;
        }

        try {
            $this->client->post($message->apInboxUrl, $actor, $message->payload, $message->useOldPrivateKey);
            if ($instance->getLastSuccessfulDeliver() < new \DateTimeImmutable('now - 5 minutes')) {
                $instance->setLastSuccessfulDeliver();
                $this->entityManager->persist($instance);
                $this->entityManager->flush();
            }
        } catch (InvalidApPostException|TransportExceptionInterface $e) {
            $instance->setLastFailedDeliver();
            $this->entityManager->persist($instance);
            $this->entityManager->flush();

            throw $e;
        }
    }
}
