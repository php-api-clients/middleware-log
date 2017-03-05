<?php declare(strict_types=1);

namespace ApiClients\Middleware\Log;

use ApiClients\Tools\Psr7\Oauth1\Definition\AccessToken;
use ApiClients\Tools\Psr7\Oauth1\Definition\ConsumerKey;
use ApiClients\Tools\Psr7\Oauth1\Definition\ConsumerSecret;
use ApiClients\Tools\Psr7\Oauth1\Definition\TokenSecret;

final class Options
{
    const IGNORE_HEADERS = 'ignore_headers';
    const LEVEL          = 'level';
    const ERROR_LEVEL    = 'error_level';
}
