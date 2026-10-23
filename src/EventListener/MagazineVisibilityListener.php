<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Contracts\ContentInterface;
use App\Entity\Contracts\VisibilityInterface;
use App\Entity\Magazine;
use App\Enum\MagazineAccess;
use App\Security\Voter\MagazineVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineVisibilityListener
{
    public const STUB_ROUTES = ['front_magazine', 'front_magazine_short', 'api_magazine_retrieve', 'api_magazine_retrieve_by_name'];
    public const AP_ACTOR_ROUTE = 'ap_magazine';
    public const STUB_ATTRIBUTE = '_magazine_stub';

    public function __construct(private readonly Security $security)
    {
    }

    public function onKernelControllerArguments(ControllerArgumentsEvent $event): void
    {
        // Content resolved by id carries its own magazine, which may differ from any magazine in the URL.
        foreach ($event->getArguments() as $argument) {
            if ($argument instanceof ContentInterface
                && $argument->getMagazine()
                && !$this->security->isGranted(MagazineVoter::VIEW, $argument->getMagazine())) {
                throw new NotFoundHttpException();
            }
        }

        $magazine = array_filter($event->getArguments(), fn ($argument) => $argument instanceof Magazine);

        if (!$magazine) {
            return;
        }

        $magazine = array_values($magazine)[0];

        if (VisibilityInterface::VISIBILITY_VISIBLE !== $magazine->visibility) {
            if (null === $this->security->getUser()
                || (false === $magazine->userIsOwner($this->security->getUser())
                && false === $this->security->isGranted('ROLE_ADMIN')
                && false === $this->security->isGranted('ROLE_MODERATOR'))) {
                throw new NotFoundHttpException();
            }
        }

        // FORK DIVERGENCE — do not offer this upstream: private magazines
        if (!$this->security->isGranted(MagazineVoter::VIEW, $magazine)) {
            $request = $event->getRequest();
            $route = $request->attributes->get('_route');

            if (self::AP_ACTOR_ROUTE === $route) {
                return;
            }

            if (MagazineAccess::Private === $magazine->access && $this->isStubRoute($request, $route)) {
                $request->attributes->set(self::STUB_ATTRIBUTE, true);

                return;
            }

            throw new NotFoundHttpException();
        }
    }

    /**
     * The front routes also serve /m/{name}/microblog and other content listings;
     * only the bare magazine page itself shows the stub.
     */
    private function isStubRoute(Request $request, mixed $route): bool
    {
        if (!\in_array($route, self::STUB_ROUTES, true)) {
            return false;
        }

        if (\in_array($route, ['front_magazine', 'front_magazine_short'], true)) {
            return 1 === preg_match('#^/m/[^/]+/?$#', $request->getPathInfo());
        }

        return true;
    }
}
