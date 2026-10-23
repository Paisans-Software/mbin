<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ActivityPub;

use App\Entity\User;
use App\Exception\NoMagazineFoundException;
use App\Factory\ImageFactory;
use App\Repository\ApActivityRepository;
use App\Repository\InstanceRepository;
use App\Service\ActivityPub\ApObjectExtractor;
use App\Service\ActivityPub\Page;
use App\Service\ActivityPub\ShadowThreadManager;
use App\Service\ActivityPubManager;
use App\Service\EntryManager;
use App\Service\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PageTest extends TestCase
{
    /**
     * On an instance with no 'random' magazine, an inbound Page/Article/Video that
     * names no routable audience/to/cc must not fatal. findOrCreateMagazineByToCCAndAudience()
     * legitimately returns null in that case (its last resort, findOneByName('random'),
     * finds nothing), and without the fix Page::create() dereferences that null directly
     * with $magazine->isActorPostingRestricted($actor), producing a fatal \Error instead
     * of the typed, catchable exception this test expects.
     */
    public function testCreateThrowsNoMagazineFoundExceptionWhenObjectIsUnroutable(): void
    {
        $object = [
            'id' => 'https://remote.tld/objects/unrouted-page',
            'type' => 'Page',
            'attributedTo' => 'https://remote.tld/users/someone',
            'to' => [],
            'cc' => [],
            'name' => 'An unrouted page',
        ];

        $repository = $this->createStub(ApActivityRepository::class);
        $repository->method('findByObjectId')->willReturn(null);

        $settingsManager = $this->createStub(SettingsManager::class);
        $settingsManager->method('isBannedInstance')->willReturn(false);

        $actor = $this->createStub(User::class);

        $activityPubManager = $this->createStub(ActivityPubManager::class);
        $activityPubManager->method('getSingleActorFromAttributedTo')->willReturn($object['attributedTo']);
        $activityPubManager->method('findActorOrCreate')->willReturn($actor);
        $activityPubManager->method('findOrCreateMagazineByToCCAndAudience')->willReturn(null);

        $page = new Page(
            $repository,
            $this->createStub(EntryManager::class),
            $activityPubManager,
            $this->createStub(EntityManagerInterface::class),
            $settingsManager,
            $this->createStub(ImageFactory::class),
            $this->createStub(ApObjectExtractor::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(InstanceRepository::class),
            // FORK DIVERGENCE — do not offer this upstream: private magazines
            $this->createStub(ShadowThreadManager::class),
        );

        $this->expectException(NoMagazineFoundException::class);
        $page->create($object);
    }
}
