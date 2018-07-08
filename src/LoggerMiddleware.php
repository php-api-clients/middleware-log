<?php declare(strict_types=1);

namespace ApiClients\Middleware\Log;

use ApiClients\Foundation\Middleware\Annotation\Last;
use ApiClients\Foundation\Middleware\MiddlewareInterface;
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
     * @param RequestInterface $request
     * @param array $options
     * @return CancellablePromiseInterface
     *
     * @Last()
     */
    public function pre(
        RequestInterface $request,
        string $transactionId,
        array $options = []
    ): CancellablePromiseInterface {
        if (!isset($options[self::class][Options::LEVEL])) {
            return resolve($request);
        }

        $this->context[$transactionId][self::REQUEST]['method'] = $request->getMethod();
        $this->context[$transactionId][self::REQUEST]['uri'] = (string)$request->getUri();
        $this->context[$transactionId][self::REQUEST]['protocol_version'] = (string)$request->getProtocolVersion();
        $ignoreHeaders = $options[self::class][Options::IGNORE_HEADERS] ?? [];
        $this->iterateHeaders(self::REQUEST, $transactionId, $request->getHeaders(), $ignoreHeaders);

        return resolve($request);
    }

    /**
     * @param ResponseInterface $response
     * @param array $options
     * @return CancellablePromiseInterface
     *
     * @Last()
     */
    public function post(
        ResponseInterface $response,
        string $transactionId,
        array $options = []
    ): CancellablePromiseInterface {
        if (!isset($options[self::class][Options::LEVEL])) {
            unset($this->context[$transactionId]);
            return resolve($response);
        }

        $message = 'Request ' . $transactionId . ' completed.';

        $this->addResponseToContext($response, $transactionId, $options);

        $this->logger->log($options[self::class][Options::LEVEL], $message, $this->context[$transactionId]);
        unset($this->context[$transactionId]);

        return resolve($response);
    }

    /**
     * @param Throwable $throwable
     * @param array $options
     * @return CancellablePromiseInterface
     *
     * @Last()
     */
    public function error(
        Throwable $throwable,
        string $transactionId,
        array $options = []
    ): CancellablePromiseInterface {
        if (!isset($options[self::class][Options::ERROR_LEVEL])) {
            unset($this->context[$transactionId]);
            return reject($throwable);
        }

        $message = $throwable->getMessage();

        $response = null;
        if (method_exists($throwable, 'getResponse')) {
            $response = $throwable->getResponse();
        }
        if ($response instanceof ResponseInterface) {
            $this->addResponseToContext($response, $transactionId, $options);
        }

        $this->context[$transactionId][self::ERROR]['code']  = $throwable->getCode();
        $this->context[$transactionId][self::ERROR]['file']  = $throwable->getFile();
        $this->context[$transactionId][self::ERROR]['line']  = $throwable->getLine();
        $this->context[$transactionId][self::ERROR]['trace'] = $throwable->getTraceAsString();

        if (method_exists($throwable, 'getContext')) {
            $this->context[$transactionId][self::ERROR]['context'] = $throwable->getContext();
        }

        $this->logger->log($options[self::class][Options::ERROR_LEVEL], $message, $this->context[$transactionId]);
        unset($this->context[$transactionId]);

        return reject($throwable);
    }

    /**
     * @param string $prefix
     * @param array $headers
     * @param array $ignoreHeaders
     */
    protected function iterateHeaders(string $prefix, string $transactionId, array $headers, array $ignoreHeaders)
    {
        foreach ($headers as $header => $value) {
            if (in_array($header, $ignoreHeaders)) {
                continue;
            }

            $this->context[$transactionId][$prefix]['headers'][$header] = $value;
        }
    }

    private function addResponseToContext(ResponseInterface $response, string $transactionId, array $options)
    {
        $this->context[$transactionId][self::RESPONSE]['status_code']      = $response->getStatusCode();
        $this->context[$transactionId][self::RESPONSE]['status_reason']    = $response->getReasonPhrase();
        $this->context[$transactionId][self::RESPONSE]['protocol_version'] = $response->getProtocolVersion();
        $ignoreHeaders = $options[self::class][Options::IGNORE_HEADERS] ?? [];
        $this->iterateHeaders(self::RESPONSE, $transactionId, $response->getHeaders(), $ignoreHeaders);
    }
}
