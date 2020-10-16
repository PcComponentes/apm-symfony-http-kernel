<?php
declare(strict_types=1);

namespace PcComponentes\ElasticAPM\Symfony\Component\HttpKernel;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use ZoiloMora\ElasticAPM\ElasticApmTracer;
use ZoiloMora\ElasticAPM\Events\Common\Context;

final class EventSubscriber implements EventSubscriberInterface
{
    private RouterInterface $router;
    private ElasticApmTracer $elasticApmTracer;
    private array $skippedRoutes;

    private array $transactions;
    private array $spans;

    public function __construct(RouterInterface $router, ElasticApmTracer $elasticApmTracer, array $skippedRoutes = [])
    {
        $this->router = $router;
        $this->elasticApmTracer = $elasticApmTracer;
        $this->skippedRoutes = $skippedRoutes;

        $this->transactions = [];
        $this->spans = [];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest'],
            KernelEvents::CONTROLLER => ['onKernelController'],
            KernelEvents::RESPONSE => ['onKernelResponse'],
            KernelEvents::TERMINATE => ['onKernelTerminate'],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (false === $this->isActive($event)) {
            return;
        }

        $requestId = $this->requestId(
            $event->getRequest(),
        );

        $this->transactions[$requestId] = $this->elasticApmTracer->startTransaction(
            $this->getNameTransaction(
                $event->getRequest(),
            ),
            'request',
        );
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (false === $this->isActive($event)) {
            return;
        }

        $requestId = $this->requestId(
            $event->getRequest(),
        );

        $name = $this->getCallableName(
            $event->getController(),
        );

        $this->spans[$requestId] = $this->elasticApmTracer->startSpan(
            $name,
            'controller',
        );
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (false === $this->isActive($event)) {
            return;
        }

        $requestId = $this->requestId(
            $event->getRequest(),
        );

        if (false === \array_key_exists($requestId, $this->spans)) {
            return;
        }

        $this->spans[$requestId]->stop();
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (false === $this->isActive($event)) {
            return;
        }

        $requestId = $this->requestId(
            $event->getRequest(),
        );

        if (false === \array_key_exists($requestId, $this->transactions)) {
            return;
        }

        $transaction = $this->transactions[$requestId];

        $statusCode = $event->getResponse()->getStatusCode();

        $transaction->context()->setResponse(
            new Context\Response(true, null, null, $statusCode),
        );

        $transaction->stop(
            $this->getResult($statusCode),
        );

        $this->flush(
            $event->getRequest(),
        );
    }

    private function requestId(Request $request): int
    {
        return \spl_object_id($request);
    }

    private function getCallableName($callable): string
    {
        if ($callable instanceof \Closure) {
            return 'closure';
        }

        if (true === \is_string($callable)) {
            return \trim($callable);
        }

        if (true === \is_array($callable)) {
            $class = \is_object($callable[0])
                ? \get_class($callable[0])
                : \trim($callable[0])
            ;
            $method = \trim($callable[1]);

            return \sprintf('%s::%s', $class, $method);
        }

        if (\is_callable($callable) && \is_object($callable)) {
            $class = \get_class($callable);

            return \sprintf('%s::%s', $class, '__invoke');
        }

        return 'unknown';
    }

    private function getNameTransaction(Request $request): string
    {
        return \sprintf(
            '%s %s',
            $request->getMethod(),
            $this->getRoutePath($request),
        );
    }

    private function getRoutePath(Request $request): string
    {
        $routeName = $this->getRouteName($request);
        $route = $this->router->getRouteCollection()->get($routeName);

        return null !== $route
            ? $route->getPath()
            : 'unknown'
        ;
    }

    private function getRouteName(Request $request): string
    {
        return $request->attributes->get('_route');
    }

    private function getResult(int $statusCode): string
    {
        return \sprintf(
            'HTTP %sxx',
            \substr(
                (string) $statusCode,
                0,
                1,
            ),
        );
    }

    private function flush(Request $request): void
    {
        $routeName = $this->getRouteName($request);

        if (true === \in_array($routeName, $this->skippedRoutes, true)) {
            return;
        }

        $this->elasticApmTracer->flush();
    }

    private function isActive(KernelEvent $event): bool
    {
        return $this->elasticApmTracer->active() && $event->isMasterRequest();
    }
}
