<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Controller\HealthController;
use App\Tests\WebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelEvents;

class HealthControllerTest extends WebTestCase
{
    public function testHealthyAnswersEmptyOk(): void
    {
        $this->client->request('GET', '/healthz');

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertSame('', $this->client->getResponse()->getContent());
        $this->assertResponseNotHasHeader('Set-Cookie');
    }

    public function testHeadAnswersOk(): void
    {
        $this->client->request('HEAD', '/healthz');

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertSame('', $this->client->getResponse()->getContent());
        $this->assertResponseNotHasHeader('Set-Cookie');
    }

    public function testAnswersOnPrivateInstance(): void
    {
        $this->settingsManager->set('MBIN_PRIVATE_INSTANCE', true);

        $this->client->request('GET', '/');
        $this->assertResponseRedirects();

        $this->client->request('GET', '/healthz');

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertSame('', $this->client->getResponse()->getContent());
        $this->assertResponseNotHasHeader('Set-Cookie');
    }

    public function testUnreachableDatabaseAnswersEmptyUnavailable(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willThrowException($this->createStub(ConnectionException::class));

        self::getContainer()->set(HealthController::class, new HealthController(
            $connection,
            self::getContainer()->get(CacheItemPoolInterface::class),
        ));

        $this->client->request('GET', '/healthz');

        $this->assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        $this->assertSame('', $this->client->getResponse()->getContent());
        $this->assertResponseNotHasHeader('Set-Cookie');
    }

    public function testUnreachableCacheAnswersEmptyUnavailable(): void
    {
        $cache = $this->createStub(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturnCallback(
            fn (string $key) => self::getContainer()->get(CacheItemPoolInterface::class)->getItem($key)
        );
        $cache->method('save')->willReturn(false);

        self::getContainer()->set(HealthController::class, new HealthController(
            self::getContainer()->get(Connection::class),
            $cache,
        ));

        $this->client->request('GET', '/healthz');

        $this->assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        $this->assertSame('', $this->client->getResponse()->getContent());
        $this->assertResponseNotHasHeader('Set-Cookie');
    }

    public function testFailureBeforeTheControllerAnswersEmptyUnavailable(): void
    {
        // Listeners that run before every controller read the database too.
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(KernelEvents::CONTROLLER, function (): void {
            throw $this->createStub(ConnectionException::class);
        });

        $this->client->request('GET', '/healthz');

        $this->assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        $this->assertSame('', $this->client->getResponse()->getContent());
        $this->assertResponseNotHasHeader('Set-Cookie');
    }
}
