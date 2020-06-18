<?php
declare(strict_types=1);

namespace PcComponentes\ElasticAPM\Symfony\Component\HttpKernel;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\PostResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use ZoiloMora\ElasticAPM\ElasticApmTracer;
use ZoiloMora\ElasticAPM\Events\Common\Context;
use ZoiloMora\ElasticAPM\Events\Span\Span;
use ZoiloMora\ElasticAPM\Events\Transaction\Transaction;

final class EventSubscriber implements EventSubscriberInterface
{
    private RouterInterface $router;
    private ElasticApmTracer $elasticApmTracer;

    private Transaction $transaction;
    private Span $span;

    public function __construct(RouterInterface $router, ElasticApmTracer $elasticApmTracer)
    {
        $this->router = $router;
        $this->elasticApmTracer = $elasticApmTracer;
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

    public function onKernelRequest(GetResponseEvent $event): void
    {
        $this->transaction = $this->elasticApmTracer->startTransaction(
            $this->getNameTransaction(
                $event->getRequest(),
            ),
            'request',
        );
    }

    public function onKernelController(FilterControllerEvent $event): void
    {
        $name = $this->getCallableName(
            $event->getController(),
        );

        $this->span = $this->elasticApmTracer->startSpan(
            $name,
            'controller',
        );
    }

    public function onKernelResponse(FilterResponseEvent $event): void
    {
        if (isset($this->span)) {
            $this->span->stop();
        }
    }

    public function onKernelTerminate(PostResponseEvent $event): void
    {
        $statusCode = $event->getResponse()->getStatusCode();

        $this->transaction->context()->setResponse(
            new Context\Response(true, null, null, $statusCode),
        );

        $this->transaction->stop(
            $this->getResult($statusCode),
        );

        $this->elasticApmTracer->flush();
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
        $routeCollection = $this->router->getRouteCollection();
        $routeName = $request->attributes->get('_route');
        $route = $routeCollection->get($routeName);

        return \sprintf(
            '%s %s',
            $request->getMethod(),
            $route->getPath(),
        );
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
}
