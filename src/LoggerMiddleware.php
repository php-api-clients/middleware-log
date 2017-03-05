<?php declare(strict_types=1);

namespace ApiClients\Middleware\Log;

use ApiClients\Foundation\Middleware\MiddlewareInterface;
use ApiClients\Foundation\Middleware\Priority;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use React\Promise\CancellablePromiseInterface;
use Throwable;
use function React\Promise\reject;
use function React\Promise\resolve;

class LoggerMiddleware implements MiddlewareInterface
{
    const REQUEST  = 'request';
    const RESPONSE = 'response';
    const ERROR    = 'error';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var string
     */
    private $requestId;

    /**
     * @var array
     */
    private $context = [
        self::REQUEST => [
            'method' => null,
            'uri' => null,
            'protocol_version' => null,
            'headers' => [],
        ],
        self::RESPONSE => [
            'status_code' => null,
            'status_reason' => null,
            'protocol_version' => null,
            'headers' => [],
        ],
    ];

    /**
     * LogMiddleware constructor.
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @return int
     */
    public function priority(): int
    {
        return Priority::LAST;
    }

    /**
     * @param RequestInterface $request
     * @param array $options
     * @return CancellablePromiseInterface
     */
    public function pre(RequestInterface $request, array $options = []): CancellablePromiseInterface
    {
        if (!isset($options[self::class][Options::LEVEL])) {
            return resolve($request);
        }

        $this->requestId = spl_object_hash($request);

        $this->context[self::REQUEST]['method'] = $request->getMethod();
        $this->context[self::REQUEST]['uri'] = (string)$request->getUri();
        $this->context[self::REQUEST]['protocol_version'] = (string)$request->getProtocolVersion();
        $ignoreHeaders = $options[self::class][Options::IGNORE_HEADERS] ?? [];
        $this->iterateHeaders(self::REQUEST, $request->getHeaders(), $ignoreHeaders);

        return resolve($request);
    }

    /**
     * @param ResponseInterface $response
     * @param array $options
     * @return CancellablePromiseInterface
     */
    public function post(ResponseInterface $response, array $options = []): CancellablePromiseInterface
    {
        if (!isset($options[self::class][Options::LEVEL])) {
            return resolve($response);
        }

        $message = 'Request ' . $this->requestId . ' completed.';

        $this->addResponseToContext($response, $options);

        $this->logger->log($options[self::class][Options::LEVEL], $message, $this->context);

        return resolve($response);
    }

    /**
     * @param Throwable $throwable
     * @param array $options
     * @return CancellablePromiseInterface
     */
    public function error(Throwable $throwable, array $options = []): CancellablePromiseInterface
    {
        if (!isset($options[self::class][Options::ERROR_LEVEL])) {
            return reject($throwable);
        }

        $message = $throwable->getMessage();

        $response = null;
        if (method_exists($throwable, 'getResponse')) {
            $response = $throwable->getResponse();
        }
        if ($response instanceof ResponseInterface) {
            $this->addResponseToContext($response, $options);
        }

        $this->context[self::ERROR]['code']  = $throwable->getCode();
        $this->context[self::ERROR]['file']  = $throwable->getFile();
        $this->context[self::ERROR]['line']  = $throwable->getLine();
        $this->context[self::ERROR]['trace'] = $throwable->getTraceAsString();

        $this->logger->log($options[self::class][Options::ERROR_LEVEL], $message, $this->context);

        return reject($throwable);
    }

    /**
     * @param string $prefix
     * @param array $headers
     * @param array $ignoreHeaders
     */
    protected function iterateHeaders(string $prefix, array $headers, array $ignoreHeaders)
    {
        foreach ($headers as $header => $value) {
            if (in_array($header, $ignoreHeaders)) {
                continue;
            }

            $this->context[$prefix]['headers'][$header] = $value;
        }
    }

    private function addResponseToContext(ResponseInterface $response, array $options)
    {
        $this->context[self::RESPONSE]['status_code']      = $response->getStatusCode();
        $this->context[self::RESPONSE]['status_reason']    = $response->getReasonPhrase();
        $this->context[self::RESPONSE]['protocol_version'] = $response->getProtocolVersion();
        $ignoreHeaders = $options[self::class][Options::IGNORE_HEADERS] ?? [];
        $this->iterateHeaders(self::RESPONSE, $response->getHeaders(), $ignoreHeaders);
    }
}
