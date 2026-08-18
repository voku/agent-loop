<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use php_user_filter;

/** @internal Used only while a nested owner CLI runs behind a structured front door. */
final class StdoutDiscardFilter extends php_user_filter
{
    /**
     * @param resource $in
     * @param resource $out
     * @param int $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while (($bucket = stream_bucket_make_writeable($in)) !== false) {
            $consumed += $bucket->datalen;
            $bucket->data = '';
            $bucket->datalen = 0;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
