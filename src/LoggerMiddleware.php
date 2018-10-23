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
use function WyriHaximus\getIn;

class LoggerMiddleware implements MiddlewareInterface
{
    private const REQUEST            = 'request';
    private const RESPONSE           = 'response';
    private const ERROR              = 'error';

    private const MESSAGE_URL        = '[{{transaction_id}}] Requesting: {{request.uri}}';
    private const MESSAGE_SUCCESSFUL = '[{{transaction_id}}] Request completed with {{response.status_code}}';
    private const MESSAGE_ERROR      = '[{{transaction_id}}] {{error.message}}';

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

        $this->context[$transactionId] = [
            'transaction_id' => $transactionId,
            self::REQUEST => [],
        ];
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

        $message = $this->renderTemplate(
            $options[self::class][Options::MESSAGE_PRE] ?? self::MESSAGE_URL,
            $this->context[$transactionId]
        );
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

        $context = $this->addResponseToContext($context, $response, $options);
        $message = $this->renderTemplate(
            $options[self::class][Options::MESSAGE_POST] ?? self::MESSAGE_SUCCESSFUL,
            $context
        );
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

        $response = null;
        if (method_exists($throwable, 'getResponse')) {
            $response = $throwable->getResponse();
        }
        if ($response instanceof ResponseInterface) {
            $context = $this->addResponseToContext($context, $response, $options);
        }

        $context[self::ERROR]['message']  = $throwable->getMessage();
        $context[self::ERROR]['code']  = $throwable->getCode();
        $context[self::ERROR]['file']  = $throwable->getFile();
        $context[self::ERROR]['line']  = $throwable->getLine();
        $context[self::ERROR]['trace'] = $throwable->getTraceAsString();

        if (method_exists($throwable, 'getContext')) {
            $context[self::ERROR]['context'] = $throwable->getContext();
        }

        $message = $this->renderTemplate(
            $options[self::class][Options::MESSAGE_ERROR] ?? self::MESSAGE_ERROR,
            $context
        );
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

    private function renderTemplate(string $template, array $context): string
    {
        $keyValues = [];
        preg_match_all("|\{\{(.*)\}\}|U", $template, $out, PREG_PATTERN_ORDER);
        foreach (array_unique(array_values($out[1])) as $placeHolder) {
            $keyValues['{{' . $placeHolder . '}}'] = getIn($context, $placeHolder, '');
        }
        $template = str_replace(array_keys($keyValues), array_values($keyValues), $template);

        return $template;
    }
}
