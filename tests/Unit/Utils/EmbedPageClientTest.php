<?php

declare(strict_types=1);

namespace App\Tests\Unit\Utils;

use App\Utils\EmbedPageClient;
use Embed\Embed as BaseEmbed;
use Embed\Http\Crawler;
use Embed\Http\FactoryDiscovery;
use Embed\Http\NetworkException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class EmbedPageClientTest extends TestCase
{
    private const string PAGE = '<html><head><title>Flooded sinkhole</title>'
        .'<meta property="og:image" content="https://example.com/cover.jpg">'
        .'<link rel="alternate" type="application/json+oembed" href="https://example.com/oembed?url=article">'
        .'</head></html>';

    public function testFailedOEmbedRequestKeepsPageMetadata(): void
    {
        $oEmbedRequestCount = 0;
        $client = $this->createClient(function (RequestInterface $request) use (&$oEmbedRequestCount): ResponseInterface {
            if (str_starts_with((string) $request->getUri(), 'https://example.com/oembed')) {
                ++$oEmbedRequestCount;

                throw new NetworkException('Operation timed out', CURLE_OPERATION_TIMEDOUT, $request);
            }

            return $this->createResponse(self::PAGE);
        });

        $embed = (new BaseEmbed(new Crawler($client)))->get('https://example.com/article');

        self::assertSame('Flooded sinkhole', $embed->title);
        self::assertSame('https://example.com/cover.jpg', (string) $embed->image);
        self::assertNull($embed->getOEmbed()->html('html'));
        self::assertSame(1, $oEmbedRequestCount);
    }

    public function testFailedPageRequestIsPassedOn(): void
    {
        $client = $this->createClient(function (RequestInterface $request): ResponseInterface {
            throw new NetworkException('Operation timed out', CURLE_OPERATION_TIMEDOUT, $request);
        });

        $this->expectException(NetworkException::class);

        (new BaseEmbed(new Crawler($client)))->get('https://example.com/article');
    }

    private function createClient(\Closure $handler): EmbedPageClient
    {
        $inner = new class($handler) implements ClientInterface {
            public function __construct(private readonly \Closure $handler)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return ($this->handler)($request);
            }
        };

        return new EmbedPageClient($inner, FactoryDiscovery::getResponseFactory(), $this->createStub(LoggerInterface::class));
    }

    private function createResponse(string $body): ResponseInterface
    {
        return FactoryDiscovery::getResponseFactory()->createResponse(200)
            ->withHeader('Content-Type', 'text/html')
            ->withBody(FactoryDiscovery::getStreamFactory()->createStream($body));
    }
}
