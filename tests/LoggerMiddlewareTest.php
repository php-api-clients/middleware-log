<?php declare(strict_types=1);

namespace ApiClients\Tests\Middleware\Log;

use ApiClients\Foundation\Middleware\Priority;
use ApiClients\Middleware\Log\LoggerMiddleware;
use ApiClients\Middleware\Log\Options;
use ApiClients\Tools\TestUtilities\TestCase;
use Exception;
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
        $middleware->pre($request, $options);
        $middleware->post($response, $options);
        $middleware->error($exception, $options);
    }


    public function testLog()
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
        $logger->log(
            LogLevel::ERROR,
            $exception->getMessage(),
            [
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
                    'code'  => $exception->getCode(),
                    'file'  => $exception->getFile(),
                    'line'  => $exception->getLine(),
                    'trace' => $exception->getTraceAsString(),
                ],
            ]
        )->shouldBeCalled();

        $middleware = new LoggerMiddleware($logger->reveal());
        $middleware->pre($request, $options);
        $middleware->post($response, $options);
        $middleware->error($exception, $options);
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
        $exception = new class(
            'New Exception'
        ) extends Exception {
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
            $exception->getMessage(),
            [
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
                    'code'  => $exception->getCode(),
                    'file'  => $exception->getFile(),
                    'line'  => $exception->getLine(),
                    'trace' => $exception->getTraceAsString(),
                ],
            ]
        )->shouldBeCalled();

        $middleware = new LoggerMiddleware($logger->reveal());
        $middleware->pre($request, $options);
        $middleware->error($exception, $options);
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
            $exception->getMessage(),
            [
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
                    'status_code' => null,
                    'status_reason' => null,
                    'protocol_version' => null,
                    'headers' => [],
                ],
                'error' => [
                    'code'  => $exception->getCode(),
                    'file'  => $exception->getFile(),
                    'line'  => $exception->getLine(),
                    'trace' => $exception->getTraceAsString(),
                ],
            ]
        )->shouldBeCalled();

        $middleware = new LoggerMiddleware($logger->reveal());
        $middleware->pre($request, $options);
        $middleware->error($exception, $options);
    }
}
