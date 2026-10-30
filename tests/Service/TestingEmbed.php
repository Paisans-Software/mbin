<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Factory\WwwHttpClientFactory;
use App\Service\SettingsManager;
use App\Utils\Embed;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Never fetches a remote page. Remote URLs return the canned data registered with respond(),
 * or an empty result (the same one a failed fetch returns) when nothing is registered.
 */
class TestingEmbed extends Embed
{
    /**
     * Static, so registrations survive the container being rebuilt between requests.
     *
     * @var array<string, array{title: ?string, description: ?string, image: ?string, html: ?string}>
     */
    private static array $responses = [];

    public function __construct(
        CacheInterface $cache,
        private readonly SettingsManager $settingsManager,
        LoggerInterface $logger,
        EventDispatcherInterface $dispatcher,
        WwwHttpClientFactory $httpClientFactory,
    ) {
        parent::__construct($cache, $settingsManager, $logger, $dispatcher, $httpClientFactory);
    }

    public function respond(string $url, ?string $image = null, ?string $html = null, ?string $title = null, ?string $description = null): void
    {
        self::$responses[$url] = [
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'html' => $html,
        ];
    }

    public function clearResponses(): void
    {
        self::$responses = [];
    }

    public function fetch(string $url): Embed
    {
        if ($this->settingsManager->isLocalUrl($url)) {
            return parent::fetch($url);
        }

        $c = clone $this;

        if (\array_key_exists($url, self::$responses)) {
            $c->url = $url;
            $c->title = self::$responses[$url]['title'];
            $c->description = self::$responses[$url]['description'];
            $c->image = self::$responses[$url]['image'];
            $c->html = self::$responses[$url]['html'];
        }

        return $c;
    }
}
