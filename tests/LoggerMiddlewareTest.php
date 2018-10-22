<?php declare(strict_types=1);

namespace ApiClients\Tests\Middleware\Log;

use ApiClients\Middleware\Log\LoggerMiddleware;
use ApiClients\Middleware\Log\Options;
use ApiClients\Tools\TestUtilities\TestCase;
use Exception;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Prophecy\Argument;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class LoggerMiddlewareTest extends TestCase
{
    public function testNoConfig()
    {
        $options = [];
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
        $exception = new Exception(
            'New Exception'
        );

        $logger = $this->prophesize(LoggerInterface::class);
        $logger->log(Argument::any(), Argument::any(), Argument::any())->shouldNotBeCalled();
        $middleware = new LoggerMiddleware($logger->reveal());
        $middleware->pre($request, 'abc', $options);
        $middleware->post($response, 'abc', $options);
        $middleware->error($exception, 'abc', $options);
    }

    public function testLog()
    {
        $options = [
            LoggerMiddleware::class => [
                Options::LEVEL          => LogLevel::DEBUG,
                Options::ERROR_LEVEL    => LogLevel::ERROR,
                Options::URL_LEVEL      => LogLevel::DEBUG,
                Options::IGNORE_HEADERS => [
                    'X-Ignore-Request',
                    'X-Ignore-Response',
                ],
                Options::IGNORE_URI_QUERY_ITEMS => [
                    'strip_this_item',
                ],
            ],
        ];
        $request = new Request(
            'GET',
            'https://example.com/?strip_this_item=0&dont_strip_this_item=1',
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
            '[abc] Requesting: https://example.com/?dont_strip_this_item=1',
            [
                'transaction_id' => 'abc',
                'request' => [
                    'method' => 'GET',
                    'uri' => 'https://example.com/?dont_strip_this_item=1',
                    'protocol_version' => '1.1',
                    'headers' => [
                        'Host' => ['example.com'],
                        'X-Foo' => ['bar'],
                    ],
                ],
            ]
        )->shouldBeCalled();
        $logger->log(
            LogLevel::DEBUG,
            '[abc] Request completed with 200',
            [
                'transaction_id' => 'abc',
                'request' => [
                    'method' => 'GET',
                    'uri' => 'https://example.com/?dont_strip_this_item=1',
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
        $middleware->pre($request, 'abc', $options);
        $middleware->post($response, 'abc', $options);
    }

    public function testLogError()
    {
        $options = [
            LoggerMiddleware::class => [
                Options::LEVEL          => LogLevel::DEBUG,
                Options::ERROR_LEVEL    => LogLevel::ERROR,
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
        $exception = new class('New Exception') extends Exception {
            public function getResponse()
            {
                return new Response(
                    200,
                    [
                        'X-Bar' => 'foo',
                        'X-Ignore-Response' => 'nope',
                    ]
                );
            }
        };

        $logger = $this->prophesize(LoggerInterface::class);
        $logger->log(
            LogLevel::ERROR,
            '[abc] ' . $exception->getMessage(),
            [
                'transaction_id' => 'abc',
                'request' => [
                    'method'           => 'GET',
                    'uri'              => 'https://example.com/',
                    'protocol_version' => '1.1',
                    'headers' => [
                        'Host'  => ['example.com'],
                        'X-Foo' => ['bar'],
                    ],
                ],
                'response' => [
                    'status_code'      => 200,
                    'status_reason'    => 'OK',
                    'protocol_version' => '1.1',
                    'headers' => [
                        'X-Bar' => ['foo'],
                    ],
                ],
                'error' => [
                    'message' => $exception->getMessage(),
                    'code'    => $exception->getCode(),
                    'file'    => $exception->getFile(),
                    'line'    => $exception->getLine(),
                    'trace'   => $exception->getTraceAsString(),
                ],
            ]
        )->shouldBeCalled();

        $middleware = new LoggerMiddleware($logger->reveal());
        $middleware->pre($request, 'abc', $options);
        $middleware->error($exception, 'abc', $options);
    }

    public function testLogErrorNoResponse()
    {
        $options = [
            LoggerMiddleware::class => [
                Options::LEVEL          => LogLevel::DEBUG,
                Options::ERROR_LEVEL    => LogLevel::ERROR,
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
        $exception = new Exception('New Exception');

        $logger = $this->prophesize(LoggerInterface::class);
        $logger->log(
            LogLevel::ERROR,
            '[abc] ' . $exception->getMessage(),
            [
                'transaction_id' => 'abc',
                'request' => [
                    'method'           => 'GET',
                    'uri'              => 'https://example.com/',
                    'protocol_version' => '1.1',
                    'headers' => [
                        'Host'  => ['example.com'],
                        'X-Foo' => ['bar'],
                    ],
                ],
                'error' => [
                    'message' => $exception->getMessage(),
                    'code'    => $exception->getCode(),
                    'file'    => $exception->getFile(),
                    'line'    => $exception->getLine(),
                    'trace'   => $exception->getTraceAsString(),
                ],
            ]
        )->shouldBeCalled();

        $middleware = new LoggerMiddleware($logger->reveal());
        $middleware->pre($request, 'abc', $options);
        $middleware->error($exception, 'abc', $options);
    }
}
