<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Magazine;
use App\Entity\MagazineAccessRequest;
use App\Entity\User;
use App\Enum\MagazineAccess;
use App\Event\Magazine\MagazineAccessRequestedEvent;
use App\Message\Notification\MagazineAccessGrantedNotificationMessage;
use App\Repository\MagazineAccessRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineAccessRequestManager
{
    public function __construct(
        private readonly MagazineAccessRequestRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly MagazineMemberManager $memberManager,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Idempotent: a second request while one is pending changes nothing and notifies no one.
     *
     * @throws \InvalidArgumentException when the user may not ask for access to this magazine
     */
    public function request(Magazine $magazine, User $user): void
    {
        if (MagazineAccess::Private !== $magazine->access) {
            throw new \InvalidArgumentException('Access can only be requested to a private magazine.');
        }
        if (null !== $user->apId) {
            throw new \InvalidArgumentException('Only local users can request access to a magazine.');
        }
        if ($user->isBanned || $user->isDeleted || $user->isSoftDeleted() || $magazine->isBanned($user)) {
            throw new \InvalidArgumentException('Banned or deleted accounts cannot request access to a magazine.');
        }
        if ($magazine->userCanView($user)) {
            throw new \InvalidArgumentException('This user can already view the magazine.');
        }

        if (null !== $this->repository->findOneByMagazineAndUser($magazine, $user)) {
            return;
        }

        $request = new MagazineAccessRequest($magazine, $user);
        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $this->dispatcher->dispatch(new MagazineAccessRequestedEvent($request));
    }

    /**
     * Admits the requester and tells them. A requester who can no longer be admitted (banned, deleted,
     * remote, or the magazine is no longer restricted) has the request dropped and nobody is added.
     */
    public function approve(MagazineAccessRequest $request, User $moderator): void
    {
        $magazine = $request->magazine;
        $user = $request->user;

        $admitted = false;
        if (!$magazine->isBanned($user)) {
            try {
                $this->memberManager->add($magazine, $user, $moderator);
                $admitted = true;
            } catch (\InvalidArgumentException) {
            }
        }

        $this->entityManager->remove($request);
        $this->entityManager->flush();

        if ($admitted) {
            $this->bus->dispatch(new MagazineAccessGrantedNotificationMessage($magazine->getId(), $user->getId()));
        }
    }

    public function deny(MagazineAccessRequest $request): void
    {
        $this->entityManager->remove($request);
        $this->entityManager->flush();
    }
}
