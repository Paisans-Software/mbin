<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Entry;
use App\Entity\Magazine;
use App\Entity\User;
use App\Message\EntryEmbedMessage;
use App\Message\Notification\EntryEditedNotificationMessage;
use App\MessageHandler\AttachEntryEmbedHandler;
use App\Repository\EntryRepository;
use App\Repository\ImageRepository;
use App\Service\ImageManagerInterface;
use App\Utils\Embed;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

class AttachEntryEmbedHandlerTest extends TestCase
{
    public function testAttachingAnEmbedAnnouncesTheEditAfterTheHandlerFinishes(): void
    {
        $entry = $this->createEntry();
        $dispatched = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps = []) use (&$dispatched): Envelope {
            $dispatched[] = [$message, $stamps];

            return new Envelope($message, $stamps);
        });

        $this->createHandler($entry, $this->createFetchedEmbed('<iframe src="https://www.youtube.com/embed/abc123"></iframe>'), $bus)
            ->doWork(new EntryEmbedMessage(7));

        self::assertCount(1, $dispatched);
        [$message, $stamps] = $dispatched[0];
        self::assertInstanceOf(EntryEditedNotificationMessage::class, $message);
        self::assertSame(7, $message->entryId);
        self::assertContainsOnlyInstancesOf(DispatchAfterCurrentBusStamp::class, $stamps);
        self::assertCount(1, $stamps);
    }

    public function testNothingIsAnnouncedWhenNothingIsAttached(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $this->createHandler($this->createEntry(), $this->createFetchedEmbed(null), $bus)
            ->doWork(new EntryEmbedMessage(7));
    }

    private function createEntry(): Entry
    {
        $entry = new Entry('Title', 'https://example.com/article', null, $this->createStub(Magazine::class), $this->createStub(User::class), false, false, 'en');
        (new \ReflectionProperty(Entry::class, 'id'))->setValue($entry, 7);

        return $entry;
    }

    private function createFetchedEmbed(?string $html): Embed
    {
        $embed = $this->createStub(Embed::class);
        $embed->html = $html;
        $embed->method('fetch')->willReturn($embed);
        $embed->method('getType')->willReturn(Entry::ENTRY_TYPE_VIDEO);

        return $embed;
    }

    private function createHandler(Entry $entry, Embed $embed, MessageBusInterface $bus): AttachEntryEmbedHandler
    {
        $entryRepository = $this->createStub(EntryRepository::class);
        $entryRepository->method('find')->willReturn($entry);

        return new AttachEntryEmbedHandler(
            $entryRepository,
            $this->createStub(KernelInterface::class),
            $embed,
            $this->createStub(ImageManagerInterface::class),
            $this->createStub(ImageRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(LoggerInterface::class),
            $bus,
        );
    }
}
