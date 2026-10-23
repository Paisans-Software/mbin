<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ActivityPub;

use App\Exception\NoMagazineFoundException;
use App\Factory\ImageFactory;
use App\Repository\ApActivityRepository;
use App\Service\ActivityPub\ApObjectExtractor;
use App\Service\ActivityPub\Note;
use App\Service\ActivityPub\ShadowThreadManager;
use App\Service\ActivityPubManager;
use App\Service\EntryCommentManager;
use App\Service\PostCommentManager;
use App\Service\PostManager;
use App\Service\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NoteTest extends TestCase
{
    /**
     * On an instance with no 'random' magazine, an inbound Note that names no
     * routable audience/to/cc must not fatal: findOrCreateMagazineByToCCAndAudience()
     * legitimately returns null in that case, and Note::create() must turn that
     * into a typed, catchable exception instead of dereferencing null further down
     * (as PostManager::create() does with $dto->magazine).
     */
    public function testCreateThrowsNoMagazineFoundExceptionWhenObjectIsUnroutable(): void
    {
        $object = [
            'id' => 'https://remote.tld/objects/unrouted-note',
            'type' => 'Note',
            'attributedTo' => 'https://remote.tld/users/someone',
            'to' => [],
            'cc' => [],
        ];

        $repository = $this->createStub(ApActivityRepository::class);
        $repository->method('findByObjectId')->willReturn(null);

        $settingsManager = $this->createStub(SettingsManager::class);
        $settingsManager->method('isBannedInstance')->willReturn(false);

        $activityPubManager = $this->createStub(ActivityPubManager::class);
        $activityPubManager->method('findOrCreateMagazineByToCCAndAudience')->willReturn(null);

        $note = new Note(
            $this->createStub(LoggerInterface::class),
            $repository,
            $this->createStub(PostManager::class),
            $this->createStub(EntryCommentManager::class),
            $this->createStub(PostCommentManager::class),
            $activityPubManager,
            $this->createStub(EntityManagerInterface::class),
            $settingsManager,
            $this->createStub(ImageFactory::class),
            $this->createStub(ApObjectExtractor::class),
            // FORK DIVERGENCE — do not offer this upstream: private magazines
            $this->createStub(ShadowThreadManager::class),
        );

        $this->expectException(NoMagazineFoundException::class);
        $note->create($object);
    }
}
