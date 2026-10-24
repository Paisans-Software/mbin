<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Entry;
use App\Entity\EntryComment;
use App\Entity\Magazine;
use App\Entity\MagazineAccessGrantedNotification;
use App\Entity\Post;
use App\Entity\PostComment;
use App\Entity\User;
use App\Enum\MagazineAccess;
use App\Service\MagazineMemberManager;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 *
 * A private magazine "circle" with one of each content type, a member (alice) and a
 * non-member (bob), plus a public magazine "open" for cross-magazine URLs.
 */
trait RestrictedContentFixtureTrait
{
    private Magazine $circle;
    private Magazine $open;
    private Entry $entry;
    private EntryComment $entryComment;
    private Post $post;
    private PostComment $postComment;
    private User $alice;
    private User $bob;

    private function createRestrictedFixture(): void
    {
        $admin = $this->getUserByUsername('admin', isAdmin: true);
        $this->circle = $this->getMagazineByName('circle', $admin);
        $this->circle->access = MagazineAccess::Private;
        $this->open = $this->getMagazineByName('open', $admin);
        $this->entityManager->flush();

        $this->entry = $this->createEntry('Secret entry title', $this->circle, $admin, 'https://secret.example/link');
        $this->entryComment = $this->createEntryComment('Secret entry comment', $this->entry, $admin);
        $this->post = $this->createPost('Secret post body', $this->circle, $admin);
        $this->postComment = $this->createPostComment('Secret post comment', $this->post, $admin);

        $this->bob = $this->getUserByUsername('bob');
        $this->alice = $this->getUserByUsername('alice');
        static::getContainer()->get(MagazineMemberManager::class)->add($this->circle, $this->alice, $admin);
        // Tests count the notifications restricted content causes, so alice starts with none.
        $this->entityManager->createQueryBuilder()
            ->delete(MagazineAccessGrantedNotification::class, 'n')
            ->where('n.user = :user')
            ->setParameter('user', $this->alice)
            ->getQuery()
            ->execute();
    }
}
