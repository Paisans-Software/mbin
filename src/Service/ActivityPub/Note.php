<?php

declare(strict_types=1);

namespace App\Service\ActivityPub;

use App\DTO\EntryCommentDto;
use App\DTO\PostCommentDto;
use App\DTO\PostDto;
use App\Entity\Contracts\ActivityPubActivityInterface;
use App\Entity\Contracts\ContentInterface;
use App\Entity\Entry;
use App\Entity\EntryComment;
use App\Entity\Magazine;
use App\Entity\Post;
use App\Entity\PostComment;
use App\Entity\User;
use App\Exception\EntryLockedException;
use App\Exception\InstanceBannedException;
use App\Exception\NoMagazineFoundException;
use App\Exception\PostLockedException;
use App\Exception\TagBannedException;
use App\Exception\UserBannedException;
use App\Exception\UserDeletedException;
use App\Factory\ImageFactory;
use App\Repository\ApActivityRepository;
use App\Service\ActivityPubManager;
use App\Service\EntryCommentManager;
use App\Service\PostCommentManager;
use App\Service\PostManager;
use App\Service\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

class Note extends ActivityPubContent
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ApActivityRepository $repository,
        private readonly PostManager $postManager,
        private readonly EntryCommentManager $entryCommentManager,
        private readonly PostCommentManager $postCommentManager,
        private readonly ActivityPubManager $activityPubManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly SettingsManager $settingsManager,
        private readonly ImageFactory $imageFactory,
        private readonly ApObjectExtractor $objectExtractor,
        // FORK DIVERGENCE — do not offer this upstream: private magazines
        private readonly ShadowThreadManager $shadowThreadManager,
    ) {
    }

    /**
     * @throws TagBannedException
     * @throws UserBannedException
     * @throws UserDeletedException
     * @throws InstanceBannedException
     * @throws EntryLockedException
     * @throws PostLockedException
     * @throws NoMagazineFoundException if the object could not be routed to any magazine, including the 'random' fallback
     * @throws \Exception
     */
    public function create(array $object, ?array $root = null, bool $stickyIt = false, ?string $deliveredBy = null, ?string $activityType = null): EntryComment|PostComment|Post
    {
        // First try to find the activity object in the database
        $current = $this->repository->findByObjectId($object['id']);
        if ($current) {
            $known = $this->entityManager->getRepository($current['type'])->find((int) $current['id']);
            // FORK DIVERGENCE — do not offer this upstream: private magazines
            // a restricted magazine may have started following after the post first arrived
            if ($known instanceof Post) {
                $this->shadowThreadManager->shadow($known, $object, $deliveredBy, $activityType);
            }

            return $known;
        }
        if ($this->settingsManager->isBannedInstance($object['id'])) {
            throw new InstanceBannedException();
        }

        if (\is_string($object['to'])) {
            $object['to'] = [$object['to']];
        }

        if (!isset($object['cc'])) {
            $object['cc'] = [];
        } elseif (\is_string($object['cc'])) {
            $object['cc'] = [$object['cc']];
        }

        if (isset($object['inReplyTo']) && $replyTo = $object['inReplyTo']) {
            // Create post or entry comment
            $parentObjectId = $this->repository->findByObjectId($replyTo);
            $parent = $this->entityManager->getRepository($parentObjectId['type'])->find((int) $parentObjectId['id']);

            // FORK DIVERGENCE — do not offer this upstream: private magazines
            // a reply is filed under its parent's magazine whatever it was addressed to,
            // and local ids are easy to guess, so addressing alone does not keep it out
            if ($parent instanceof ContentInterface && $parent->getMagazine()?->isRestricted()) {
                throw new NoMagazineFoundException(\sprintf('Not accepting reply "%s" to content in a restricted magazine', $object['id']));
            }

            if ($parent instanceof Entry) {
                $root = $parent;

                return $this->createEntryComment($object, $parent, $root);
            } elseif ($parent instanceof EntryComment) {
                $root = $parent->entry;

                return $this->createEntryComment($object, $parent, $root);
            } elseif ($parent instanceof Post) {
                $root = $parent;

                return $this->createPostComment($object, $parent, $root);
            } elseif ($parent instanceof PostComment) {
                $root = $parent->post;

                return $this->createPostComment($object, $parent, $root);
            } else {
                throw new \LogicException(\get_class($parent).' is not a valid parent');
            }
        }

        return $this->createPost($object, $stickyIt, $deliveredBy, $activityType);
    }

    /**
     * @throws TagBannedException
     * @throws UserBannedException
     * @throws UserDeletedException
     * @throws EntryLockedException
     * @throws \Exception
     */
    private function createEntryComment(array $object, ActivityPubActivityInterface $parent, ?ActivityPubActivityInterface $root = null): EntryComment
    {
        $dto = new EntryCommentDto();
        if ($parent instanceof EntryComment) {
            $dto->parent = $parent;
            $dto->root = $parent->root ?? $parent;
        }

        $dto->entry = $root;
        $dto->apId = $object['id'];

        if (
            isset($object['attachment'])
            && $image = $this->activityPubManager->handleImages($object['attachment'])
        ) {
            $dto->image = $this->imageFactory->createDto($image);
        }

        $actor = $this->activityPubManager->findActorOrCreate($object['attributedTo']);
        if ($actor instanceof User) {
            if ($actor->isBanned) {
                throw new UserBannedException();
            }
            if ($actor->isDeleted || $actor->isSoftDeleted() || $actor->isTrashed()) {
                throw new UserDeletedException();
            }
            $dto->body = $this->objectExtractor->getMarkdownBody($object);
            if ($media = $this->objectExtractor->getExternalMediaBody($object)) {
                $dto->body .= $media;
            }
            $dto->mentions = $this->objectExtractor->getMentions($object);

            $dto->visibility = $this->getVisibility($object, $actor);
            $this->handleDate($dto, $object['published']);
            if (isset($object['sensitive'])) {
                $this->handleSensitiveMedia($dto, $object['sensitive']);
            }

            if (!empty($object['language'])) {
                $dto->lang = $object['language']['identifier'];
            } elseif (!empty($object['contentMap'])) {
                $dto->lang = array_keys($object['contentMap'])[0];
            } else {
                $dto->lang = $this->settingsManager->get('KBIN_DEFAULT_LANG');
            }

            $dto->apLikeCount = $this->activityPubManager->extractRemoteLikeCount($object);
            $dto->apDislikeCount = $this->activityPubManager->extractRemoteDislikeCount($object);
            $dto->apShareCount = $this->activityPubManager->extractRemoteShareCount($object);

            return $this->entryCommentManager->create($dto, $actor, false);
        } elseif ($actor instanceof Magazine) {
            throw new UnrecoverableMessageHandlingException('Actor "'.$object['attributedTo'].'" is not a user, but a magazine for post "'.$dto->apId.'".');
        } else {
            throw new UnrecoverableMessageHandlingException('Actor "'.$object['attributedTo'].'"could not be found for post "'.$dto->apId.'".');
        }
    }

    /**
     * @throws UserDeletedException
     * @throws TagBannedException
     * @throws UserBannedException
     * @throws NoMagazineFoundException if the object could not be routed to any magazine, including the 'random' fallback
     */
    private function createPost(array $object, bool $stickyIt = false, ?string $deliveredBy = null, ?string $activityType = null): Post
    {
        $dto = new PostDto();
        $dto->magazine = $this->activityPubManager->findOrCreateMagazineByToCCAndAudience($object, $deliveredBy, $activityType);
        if (null === $dto->magazine) {
            $this->logger->warning('Could not resolve a magazine for object {o} and no "random" magazine exists to fall back to, dropping it', ['o' => $object['id']]);
            throw new NoMagazineFoundException(\sprintf('No magazine could be found or created for object "%s"', $object['id']));
        }
        $dto->apId = $object['id'];

        $actor = $this->activityPubManager->findActorOrCreate($object['attributedTo']);
        if ($actor instanceof User) {
            if ($actor->isBanned) {
                throw new UserBannedException();
            }
            if ($actor->isDeleted || $actor->isSoftDeleted() || $actor->isTrashed()) {
                throw new UserDeletedException();
            }

            if (isset($object['attachment']) && $image = $this->activityPubManager->handleImages($object['attachment'])) {
                $dto->image = $this->imageFactory->createDto($image);
                $this->logger->debug("adding image to post '{title}', {image}", ['title' => $dto->slug, 'image' => $image->getId()]);
            }

            $dto->body = $this->objectExtractor->getMarkdownBody($object);
            if ($media = $this->objectExtractor->getExternalMediaBody($object)) {
                $dto->body .= $media;
            }
            $dto->mentions = $this->objectExtractor->getMentions($object);

            $dto->visibility = $this->getVisibility($object, $actor);
            $this->handleDate($dto, $object['published']);
            if (isset($object['sensitive'])) {
                $this->handleSensitiveMedia($dto, $object['sensitive']);
            }

            if (!empty($object['language'])) {
                $dto->lang = $object['language']['identifier'];
            } elseif (!empty($object['contentMap'])) {
                $dto->lang = array_keys($object['contentMap'])[0];
            } else {
                $dto->lang = $this->settingsManager->get('KBIN_DEFAULT_LANG');
            }
            $dto->apLikeCount = $this->activityPubManager->extractRemoteLikeCount($object);
            $dto->apDislikeCount = $this->activityPubManager->extractRemoteDislikeCount($object);
            $dto->apShareCount = $this->activityPubManager->extractRemoteShareCount($object);

            if (isset($object['commentsEnabled']) && \is_bool($object['commentsEnabled'])) {
                $dto->isLocked = !$object['commentsEnabled'];
            }

            // FORK DIVERGENCE — do not offer this upstream: private magazines
            // a restricted magazine following this actor gets its own private copy
            $post = $this->postManager->create($dto, $actor, false, $stickyIt);
            $this->shadowThreadManager->shadow($post, $object, $deliveredBy, $activityType);

            return $post;
        } elseif ($actor instanceof Magazine) {
            throw new UnrecoverableMessageHandlingException('Actor "'.$object['attributedTo'].'" is not a user, but a magazine for post "'.$dto->apId.'".');
        } else {
            throw new UnrecoverableMessageHandlingException('Actor "'.$object['attributedTo'].'"could not be found for post "'.$dto->apId.'".');
        }
    }

    /**
     * @throws UserDeletedException
     * @throws TagBannedException
     * @throws UserBannedException
     * @throws PostLockedException
     */
    private function createPostComment(array $object, ActivityPubActivityInterface $parent, ?ActivityPubActivityInterface $root = null): PostComment
    {
        $dto = new PostCommentDto();
        if ($parent instanceof PostComment) {
            $dto->parent = $parent;
        }

        $dto->post = $root;
        $dto->apId = $object['id'];

        if (
            isset($object['attachment'])
            && $image = $this->activityPubManager->handleImages($object['attachment'])
        ) {
            $dto->image = $this->imageFactory->createDto($image);
        }

        $actor = $this->activityPubManager->findActorOrCreate($object['attributedTo']);
        if ($actor instanceof User) {
            if ($actor->isBanned) {
                throw new UserBannedException();
            }
            if ($actor->isDeleted || $actor->isSoftDeleted() || $actor->isTrashed()) {
                throw new UserDeletedException();
            }
            $dto->body = $this->objectExtractor->getMarkdownBody($object);
            if ($media = $this->objectExtractor->getExternalMediaBody($object)) {
                $dto->body .= $media;
            }
            $dto->mentions = $this->objectExtractor->getMentions($object);

            $dto->visibility = $this->getVisibility($object, $actor);
            $this->handleDate($dto, $object['published']);
            if (isset($object['sensitive'])) {
                $this->handleSensitiveMedia($dto, $object['sensitive']);
            }

            if (!empty($object['language'])) {
                $dto->lang = $object['language']['identifier'];
            } elseif (!empty($object['contentMap'])) {
                $dto->lang = array_keys($object['contentMap'])[0];
            } else {
                $dto->lang = $this->settingsManager->get('KBIN_DEFAULT_LANG');
            }
            $dto->apLikeCount = $this->activityPubManager->extractRemoteLikeCount($object);
            $dto->apDislikeCount = $this->activityPubManager->extractRemoteDislikeCount($object);
            $dto->apShareCount = $this->activityPubManager->extractRemoteShareCount($object);

            return $this->postCommentManager->create($dto, $actor, false);
        } elseif ($actor instanceof Magazine) {
            throw new UnrecoverableMessageHandlingException('Actor "'.$object['attributedTo'].'" is not a user, but a magazine for post "'.$dto->apId.'".');
        } else {
            throw new UnrecoverableMessageHandlingException('Actor "'.$object['attributedTo'].'"could not be found for post "'.$dto->apId.'".');
        }
    }
}
