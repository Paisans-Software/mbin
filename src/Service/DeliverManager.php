<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Traits\ActivityPubActorTrait;
use App\Message\ActivityPub\Outbox\DeliverMessage;
use App\Service\ActivityPub\RestrictedMagazineFederationGuard;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

readonly class DeliverManager
{
    public function __construct(
        private SettingsManager $settingsManager,
        private OutboundFederationPolicy $policy,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
        // FORK DIVERGENCE — do not offer this upstream: private magazines
        private RestrictedMagazineFederationGuard $restrictedGuard,
    ) {
    }

    /**
     * @param string[]|ActivityPubActorTrait[] $inboxes
     */
    public function deliver(array $inboxes, array $activity, bool $useOldPrivateKey = false): void
    {
        // FORK DIVERGENCE — do not offer this upstream: private magazines
        // decided once for the whole fan out: the payload is the same for every inbox
        if ($this->restrictedGuard->touchesRestrictedMagazine($activity) && !$this->restrictedGuard->isAllowedHandshake($activity)) {
            $this->logger->debug('not delivering a {type}, it is about a restricted magazine', [
                'type' => \is_string($activity['type'] ?? null) ? $activity['type'] : 'activity without a type',
            ]);

            return;
        }

        foreach ($inboxes as $inbox) {
            if (!$inbox) {
                continue;
            }

            $inboxUrl = \is_string($inbox) ? $inbox : $inbox->apInboxUrl;

            if ($this->settingsManager->isBannedInstance($inboxUrl)) {
                continue;
            }

            if ($this->settingsManager->isLocalUrl($inboxUrl)) {
                $this->logger->warning('tried delivering to a local url, {payload}', ['payload' => $activity]);
                continue;
            }

            // dropped here rather than in the handler so a fan out that includes a read only
            // instance does not queue a message only to throw it away. DeliverHandler checks
            // again, for the call sites that dispatch a DeliverMessage without coming through
            // this method.
            if (!$this->policy->mayDeliver($inboxUrl, $activity)) {
                continue;
            }

            $this->bus->dispatch(new DeliverMessage($inboxUrl, $activity, $useOldPrivateKey));
        }
    }
}
