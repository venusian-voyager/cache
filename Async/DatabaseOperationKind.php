<?php

namespace Voyager\Cache\Async;

enum DatabaseOperationKind: string
{
    case GET = 'get';
    case PUT = 'put';
    case ADD = 'add';
    case INCREMENT = 'increment';
    case FORGET = 'forget';

    /** Everything but a get writes, and waits for the connection's calls before it. */
    public function writes(): bool
    {
        return $this !== self::GET;
    }
}
