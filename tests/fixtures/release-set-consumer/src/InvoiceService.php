<?php

declare(strict_types=1);

namespace Fixture;

final readonly class InvoiceService
{
    /** @param list<int> $lineAmounts */
    public function total(array $lineAmounts): int
    {
        return array_sum($lineAmounts);
    }
}
