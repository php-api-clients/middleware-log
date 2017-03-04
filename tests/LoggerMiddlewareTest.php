<?php declare(strict_types=1);

namespace ApiClients\Tests\Middleware\Log;

use ApiClients\Foundation\Middleware\Priority;
use ApiClients\Middleware\Log\LoggerMiddleware;
use ApiClients\Middleware\Log\Options;
use ApiClients\Tools\TestUtilities\TestCase;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Prophecy\Argument;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use function Clue\React\Block\await;
use function React\Promise\resolve;

class LoggerMiddlewareTest extends TestCase
{
    public function testPriority()
    {
        $logger = $this->prophesize(LoggerInterface::class)->reveal();
        $middleware = new LoggerMiddleware($logger);
        $this->assertSame(Priority::LAST, $middleware->priority());
    }

    public function testLog()
    {
        $options = [
            LoggerMiddleware::class => [
                Options::LEVEL          => LogLevel::DEBUG,
                Options::IGNORE_HEADERS => [
                    'X-Ignore-Request',
                    'X-Ignore-Response',
                ],
            ],
        ];
        $request = new Request(
            'GET',
            'https://example.com/',
            [
                'X-Foo' => 'bar',
                'X-Ignore-Request' => 'nope',
            ]
        );
        $response = new Response(
            200,
            [
                'X-Bar' => 'foo',
                'X-Ignore-Response' => 'nope',
            ]
        );

        $logger = $this->prophesize(LoggerInterface::class);
        $logger->log(
            LogLevel::DEBUG,
            'Request ' . spl_object_hash($request) . ' completed.',
            [
                'request' => [
                    'method' => 'GET',
                    'uri' => 'https://example.com/',
                    'protocol_version' => '1.1',
                    'headers' => [
                        'Host' => ['example.com'],
                        'X-Foo' => ['bar'],
                    ],
                ],
                'response' => [
                    'status_code' => 200,
                    'status_reason' => 'OK',
                    'protocol_version' => '1.1',
                    'headers' => [
                        'X-Bar' => ['foo'],
                    ],
                ],
            ]
        )->shouldBeCalled();

        $middleware = new LoggerMiddleware($logger->reveal());
        $middleware->pre($request, $options);
        $middleware->post($response, $options);
    }
}
