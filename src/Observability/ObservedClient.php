<?php

namespace Cmd\Reports\Observability;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class ObservedClient extends Client
{
    public function __construct(array $config = [])
    {
        $stack = $config['handler'] ?? HandlerStack::create();
        $stack = $stack instanceof HandlerStack ? clone $stack : HandlerStack::create($stack);
        $stack->push(static function (callable $handler) {
            return static function (RequestInterface $request, array $options) use ($handler) {
                $start = microtime(true);
                $service = $request->getUri()->getHost();
                $operation = $request->getMethod();
                try {
                    return $handler($request, $options)->then(
                        static function (ResponseInterface $response) use ($start, $service, $operation) {
                            Telemetry::record('http', $operation, $service, $start, $response->getStatusCode() < 400, null, $response->getStatusCode());
                            return $response;
                        },
                        static function ($reason) use ($start, $service, $operation) {
                            Telemetry::record('http', $operation, $service, $start, false, $reason instanceof Throwable ? $reason : null);
                            return \GuzzleHttp\Promise\Create::rejectionFor($reason);
                        }
                    );
                } catch (Throwable $e) {
                    Telemetry::record('http', $operation, $service, $start, false, $e);
                    throw $e;
                }
            };
        }, 'cmd-telemetry');
        $config['handler'] = $stack;
        parent::__construct($config);
    }
}
