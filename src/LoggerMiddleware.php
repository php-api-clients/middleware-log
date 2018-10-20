<?php declare(strict_types=1);

namespace ApiClients\Middleware\Log;

use ApiClients\Foundation\Middleware\Annotation\Last;
use ApiClients\Foundation\Middleware\MiddlewareInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use React\Promise\CancellablePromiseInterface;
use Throwable;
use function React\Promise\reject;
use function React\Promise\resolve;

class LoggerMiddleware implements MiddlewareInterface
{
    private const REQUEST  = 'request';
    private const RESPONSE = 'response';
    private const ERROR    = 'error';

    /**
     * @var LoggerInterface
     */
    private $logger;

    private $context = [];

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @Last()
     */
    public function pre(
        RequestInterface $request,
        string $transactionId,
        array $options = []
    ): CancellablePromiseInterface {
        if (!isset($options[self::class][Options::LEVEL]) && !isset($options[self::class][Options::ERROR_LEVEL])) {
            return resolve($request);
        }

        $this->context[$transactionId][self::REQUEST]['method'] = $request->getMethod();
        $this->context[$transactionId][self::REQUEST]['uri'] = (string)$this->stripQueryItems(
            $request->getUri(),
            $options
        );
        $this->context[$transactionId][self::REQUEST]['protocol_version'] = (string)$request->getProtocolVersion();
        $ignoreHeaders = $options[self::class][Options::IGNORE_HEADERS] ?? [];
        $this->context[$transactionId] = $this->iterateHeaders(
            $this->context[$transactionId],
            self::REQUEST,
            $request->getHeaders(),
            $ignoreHeaders
        );

        if (!isset($options[self::class][Options::URL_LEVEL])) {
            return resolve($request);
        }

        $message = 'Requesting: ' . $this->context[$transactionId][self::REQUEST]['uri'];
        $this->logger->log($options[self::class][Options::URL_LEVEL], $message, $this->context[$transactionId]);

        return resolve($request);
    }

    /**
     * @Last()
     */
    public function post(
        ResponseInterface $response,
        string $transactionId,
        array $options = []
    ): CancellablePromiseInterface {
        if (!isset($this->context[$transactionId])) {
            return resolve($response);
        }

        $context = $this->context[$transactionId];
        if (!isset($options[self::class][Options::LEVEL]) && !isset($options[self::class][Options::ERROR_LEVEL])) {
            unset($this->context[$transactionId]);
        }

        if (!isset($options[self::class][Options::LEVEL])) {
            return resolve($response);
        }

        $message = 'Request ' . $transactionId . ' completed.';

        $context = $this->addResponseToContext($context, $response, $options);

        $this->logger->log($options[self::class][Options::LEVEL], $message, $context);

        return resolve($response);
    }

    /**
     * @Last()
     */
    public function error(
        Throwable $throwable,
        string $transactionId,
        array $options = []
    ): CancellablePromiseInterface {
        if (!isset($this->context[$transactionId])) {
            return reject($throwable);
        }

        $context = $this->context[$transactionId];
        unset($this->context[$transactionId]);

        if (!isset($options[self::class][Options::ERROR_LEVEL])) {
            return reject($throwable);
        }

        $message = $throwable->getMessage();

        $response = null;
        if (method_exists($throwable, 'getResponse')) {
            $response = $throwable->getResponse();
        }
        if ($response instanceof ResponseInterface) {
            $context = $this->addResponseToContext($context, $response, $options);
        }

        $context[self::ERROR]['code']  = $throwable->getCode();
        $context[self::ERROR]['file']  = $throwable->getFile();
        $context[self::ERROR]['line']  = $throwable->getLine();
        $context[self::ERROR]['trace'] = $throwable->getTraceAsString();

        if (method_exists($throwable, 'getContext')) {
            $context[self::ERROR]['context'] = $throwable->getContext();
        }

        $this->logger->log($options[self::class][Options::ERROR_LEVEL], $message, $context);

        return reject($throwable);
    }

    protected function iterateHeaders(
        array $context,
        string $prefix,
        array $headers,
        array $ignoreHeaders
    ): array {
        foreach ($headers as $header => $value) {
            if (in_array($header, $ignoreHeaders, true)) {
                continue;
            }

            $context[$prefix]['headers'][$header] = $value;
        }

        return $context;
    }

    private function addResponseToContext(
        array $context,
        ResponseInterface $response,
        array $options
    ): array {
        $context[self::RESPONSE]['status_code']      = $response->getStatusCode();
        $context[self::RESPONSE]['status_reason']    = $response->getReasonPhrase();
        $context[self::RESPONSE]['protocol_version'] = $response->getProtocolVersion();
        $ignoreHeaders = $options[self::class][Options::IGNORE_HEADERS] ?? [];
        $context  = $this->iterateHeaders(
            $context,
            self::RESPONSE,
            $response->getHeaders(),
            $ignoreHeaders
        );

        return $context;
    }

    private function stripQueryItems(UriInterface $uri, array $options): UriInterface
    {
        parse_str($uri->getQuery(), $query);
        foreach ($options[self::class][Options::IGNORE_URI_QUERY_ITEMS] ?? [] as $item) {
            unset($query[$item], $query[$item . '[]']);
        }

        return $uri->withQuery(http_build_query($query));
    }
}
