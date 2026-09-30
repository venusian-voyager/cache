<?php

namespace Voyager\Cache\Async;

enum FileOperationKind: string
{
    case GET = 'get';
    case PUT = 'put';
    case ADD = 'add';
    case INCREMENT = 'increment';
    case FORGET = 'forget';
}
