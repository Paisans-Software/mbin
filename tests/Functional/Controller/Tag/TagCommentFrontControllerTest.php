<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Tag;

use App\Tests\WebTestCase;

class TagCommentFrontControllerTest extends WebTestCase
{
    public function testTagCommentsPageListsCommentsWithTag(): void
    {
        $entry = $this->getEntryByTitle('an entry', body: 'no tag here');
        $this->createEntryComment('comment about #test', $entry);
        $this->createEntryComment('unrelated comment', $entry);

        $crawler = $this->client->request('GET', '/tag/test/comments');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.entry-comments .comment');
        self::assertStringContainsString('comment about', $crawler->filter('.entry-comments')->text());
    }
}
