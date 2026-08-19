<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use php_user_filter;

/**
 * Captures what a nested owner CLI writes to STDERR so the front door can relay
 * the owner's own failure text instead of discarding it.
 *
 * @internal Used only while a nested owner CLI runs behind a structured front door.
 */
final class NestedStderrCaptureFilter extends php_user_filter
{
    private static string $captured = '';

    public static function reset(): void
    {
        self::$captured = '';
    }

    public static function captured(): string
    {
        return self::$captured;
    }

    /**
     * @param resource $in
     * @param resource $out
     * @param int $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while (($bucket = stream_bucket_make_writeable($in)) !== null) {
            $consumed += (int) $bucket->datalen;
            self::$captured .= (string) $bucket->data;
            $bucket->data = '';
            $bucket->datalen = 0;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
