<?php declare(strict_types=1);

namespace ApiClients\Middleware\Log;

final class Options
{
    public const IGNORE_HEADERS         = 'ignore_headers';
    public const IGNORE_URI_QUERY_ITEMS = 'ignoreuri_query_items';
    public const LEVEL                  = 'level';
    public const ERROR_LEVEL            = 'error_level';
    public const URL_LEVEL              = 'url_level';
    public const MESSAGE_PRE            = 'message_pre';
    public const MESSAGE_POST           = 'message_post';
    public const MESSAGE_ERROR          = 'message_error';
}
