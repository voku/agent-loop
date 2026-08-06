<?php

declare(strict_types=1);

namespace Fixture;

final readonly class RequestService
{
    public function __construct(private RetryPolicy $retryPolicy)
    {
    }

    public function retryDelayForTimeout(int $attempt): int
    {
        return $this->retryPolicy->delayMilliseconds($attempt);
    }
}
