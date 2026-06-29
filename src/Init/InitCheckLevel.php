<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

final class InitCheckLevel
{
    public const string OK = 'OK';
    public const string WARN = 'WARN';
    public const string INFO = 'INFO';
    public const string FAIL = 'FAIL';

    private function __construct()
    {
    }
}
