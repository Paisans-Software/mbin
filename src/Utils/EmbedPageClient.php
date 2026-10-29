<?php

declare(strict_types=1);

namespace App\Utils;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * The HTTP client for fetching one page's embed. Use a new one for every page.
 *
 * The first request is for the page. After it, the embed library requests resources the page
 * links to, such as its oEmbed endpoint, and a failure there would otherwise discard everything
 * already read from the page itself. So only a failed first request is passed on. Any later
 * failed request is answered with an empty response, which the library treats as a resource
 * with nothing in it, and that answer is reused because the library requests an empty resource
 * again on every read.
 */
class EmbedPageClient implements ClientInterface
{
    private bool $pageRequested = false;

    /** @var array<string, true> */
    private array $failedUris = [];

    public function __construct(
        private readonly ClientInterface $client,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = (string) $request->getUri();

        if (isset($this->failedUris[$uri])) {
            return $this->responseFactory->createResponse(502);
        }

        $isPageRequest = !$this->pageRequested;
        $this->pageRequested = true;

        try {
            return $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            if ($isPageRequest) {
                throw $e;
            }

            $this->logger->info('[EmbedPageClient::sendRequest] Ignoring failed request for {uri}: {message}', [
                'uri' => $uri,
                'message' => $e->getMessage(),
            ]);
            $this->failedUris[$uri] = true;

            return $this->responseFactory->createResponse(502);
        }
    }
}
