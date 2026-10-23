<?php

declare(strict_types=1);

namespace App\EventSubscriber\ActivityPub;

use App\ActivityPub\JsonRdLink;
use App\Entity\Magazine;
use App\Event\ActivityPub\WebfingerResponseEvent;
use App\Repository\MagazineFollowRepository;
use App\Repository\MagazineRepository;
use App\Service\ActivityPub\Webfinger\WebFingerParameters;
use JetBrains\PhpStorm\ArrayShape;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class GroupWebFingerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly MagazineRepository $magazineRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        // FORK DIVERGENCE — do not offer this upstream: private magazines
        private readonly MagazineFollowRepository $magazineFollowRepository,
    ) {
    }

    #[ArrayShape([WebfingerResponseEvent::class => 'string'])]
    public static function getSubscribedEvents(): array
    {
        return [
            WebfingerResponseEvent::class => ['buildResponse', 195],
        ];
    }

    public function buildResponse(WebfingerResponseEvent $event): void
    {
        $params = $event->params;
        $jsonRd = $event->jsonRd;

        $subject = $event->subject;
        if (!empty($subject)) {
            $jsonRd->setSubject($subject);
        }

        if (
            isset($params[WebFingerParameters::ACCOUNT_KEY_NAME])
            && $actor = $this->getActor($params[WebFingerParameters::ACCOUNT_KEY_NAME])
        ) {
            $accountHref = $this->urlGenerator->generate(
                'ap_magazine',
                ['name' => $actor->name],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            $jsonRd->addAlias($accountHref);
            $link = new JsonRdLink();
            $link->setRel('https://webfinger.net/rel/profile-page')
                ->setType('text/html')
                ->setHref($accountHref);
            $jsonRd->addLink($link);
        }
    }

    protected function getActor($name): ?Magazine
    {
        if ('random' === $name) {
            return null;
        }

        // FORK DIVERGENCE — do not offer this upstream: private magazines
        // WebFinger lookups are anonymous, so a restricted magazine resolves only under
        // the rule its actor document follows: while it follows somebody, because the
        // followed server confirms the actor through WebFinger
        $magazine = $this->magazineRepository->findOneByName($name);
        if (null === $magazine) {
            return null;
        }

        return $magazine->userCanView(null) || $this->magazineFollowRepository->magazineHasFollows($magazine) ? $magazine : null;
    }
}
