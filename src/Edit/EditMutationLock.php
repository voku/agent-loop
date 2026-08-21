<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use Closure;
use RuntimeException;

/** Serializes agent-loop edit runners that may mutate one project working tree. */
final readonly class EditMutationLock
{
    /** @var (Closure(string, Closure): mixed)|null */
    private ?Closure $synchronizeOperation;

    /** @param (Closure(string, Closure): mixed)|null $synchronizeOperation */
    public function __construct(?Closure $synchronizeOperation = null)
    {
        $this->synchronizeOperation = $synchronizeOperation;
    }

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    public function synchronized(string $projectRoot, Closure $operation): mixed
    {
        if ($this->synchronizeOperation !== null) {
            /** @var T $result */
            $result = ($this->synchronizeOperation)($projectRoot, $operation);

            return $result;
        }

        $root = realpath($projectRoot);
        if (!is_string($root)) {
            throw new RuntimeException('Unable to resolve project root for edit mutation lock: ' . $projectRoot);
        }

        $path = sys_get_temp_dir() . '/agent-loop-edit-mutation-' . hash('sha256', $root) . '.lock';
        $handle = fopen($path, 'c');
        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to open edit mutation lock: ' . $path);
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('Unable to acquire edit mutation lock: ' . $path);
        }

        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
