<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Answers 200 with an empty body while the database and the cache are reachable.
 * Any failure becomes an empty 503 in HealthExceptionSubscriber, so the answer
 * carries nothing but the status.
 */
class HealthController extends AbstractController
{
    public const string ROUTE = 'health';

    public function __construct(
        private readonly Connection $connection,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function __invoke(): Response
    {
        $this->connection->executeQuery('SELECT 1');

        // A failed write is the only signal the cache pool gives: it logs and swallows connection errors.
        $item = $this->cache->getItem('healthz')->set(true)->expiresAfter(60);
        if (!$this->cache->save($item)) {
            throw new ServiceUnavailableHttpException();
        }

        return new Response();
    }
}
