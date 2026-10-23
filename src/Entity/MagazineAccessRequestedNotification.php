<?php

declare(strict_types=1);

namespace App\Entity;

use App\Payloads\PushNotification;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sent to a moderator when someone asks to join a private magazine.
 *
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
#[Entity]
class MagazineAccessRequestedNotification extends Notification
{
    #[ManyToOne(targetEntity: Magazine::class)]
    #[JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public ?Magazine $magazine = null;

    #[ManyToOne(targetEntity: User::class)]
    #[JoinColumn(name: 'requester_id', nullable: true, onDelete: 'CASCADE')]
    public ?User $requester = null;

    public function __construct(User $receiver, Magazine $magazine, User $requester)
    {
        parent::__construct($receiver);

        $this->magazine = $magazine;
        $this->requester = $requester;
    }

    public function getSubject(): Magazine
    {
        return $this->magazine;
    }

    public function getType(): string
    {
        return 'magazine_access_requested';
    }

    public function getMessage(TranslatorInterface $trans, string $locale, UrlGeneratorInterface $urlGenerator): PushNotification
    {
        $message = $trans->trans('notification_access_requested', ['%user%' => $this->requester->username, '%magazine%' => $this->magazine->name], locale: $locale);
        $slash = $this->magazine->icon && !str_starts_with('/', $this->magazine->icon->filePath) ? '/' : '';
        $avatarUrl = $this->magazine->icon ? '/media/cache/resolve/avatar_thumb'.$slash.$this->magazine->icon->filePath : null;

        return new PushNotification($this->getId(), $message, $trans->trans('notification_title_access_requested', locale: $locale), avatarUrl: $avatarUrl);
    }
}
