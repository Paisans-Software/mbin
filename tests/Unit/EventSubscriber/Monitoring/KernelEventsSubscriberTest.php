<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber\Monitoring;

use App\EventSubscriber\Monitoring\KernelEventsSubscriber;
use App\Service\Monitor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\RouterInterface;

class KernelEventsSubscriberTest extends TestCase
{
    private function makeEvent(string $path): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('https://mbin.example'.$path),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function makeSubscriber(Monitor $monitor, RouterInterface $router): KernelEventsSubscriber
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        return new KernelEventsSubscriber($monitor, $security, $router);
    }

    private function recordingMonitor(): Monitor&MockObject
    {
        $monitor = $this->createMock(Monitor::class);
        $monitor->method('shouldRecord')->willReturn(true);

        return $monitor;
    }

    public function testRouteIsMatchedByRequestWhenTheRouterSupportsIt(): void
    {
        /** @var RouterInterface&RequestMatcherInterface&Stub $router */
        $router = $this->createStubForIntersectionOfInterfaces([RouterInterface::class, RequestMatcherInterface::class]);
        $router->method('matchRequest')->willReturn(['_route' => 'ap_object']);
        $monitor = $this->recordingMonitor();
        $monitor->expects(self::once())
            ->method('startNewExecutionContext')
            ->with('request', 'activity_pub', 'ap_object', '');

        $this->makeSubscriber($monitor, $router)->onKernelRequest($this->makeEvent('/m/test'));
    }

    public function testRouteIsMatchedByPathWhenTheRouterCannotMatchRequests(): void
    {
        // RouterInterface does not declare matchRequest(): a router that only
        // implements it must still be matched, by path.
        $router = $this->createStub(RouterInterface::class);
        $router->method('match')->willReturnMap([['/m/test', ['_route' => 'ap_object']]]);
        $monitor = $this->recordingMonitor();
        $monitor->expects(self::once())
            ->method('startNewExecutionContext')
            ->with('request', 'activity_pub', 'ap_object', '');

        $this->makeSubscriber($monitor, $router)->onKernelRequest($this->makeEvent('/m/test'));
    }

    public function testIgnoredRouteStartsNoContext(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('match')->willReturn(['_route' => 'liip_imagine_filter']);
        $monitor = $this->recordingMonitor();
        $monitor->expects(self::never())->method('startNewExecutionContext');

        $this->makeSubscriber($monitor, $router)->onKernelRequest($this->makeEvent('/media/cache/x.png'));
    }
}
