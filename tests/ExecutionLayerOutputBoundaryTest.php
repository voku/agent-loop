<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

/** The Execution layer is the typed API Runner and UI hosts consume; process output belongs to the CLI commands. */
final class ExecutionLayerOutputBoundaryTest extends TestCase
{
    private const array OUTPUT_FUNCTIONS = ['printf', 'vprintf', 'fprintf', 'print_r', 'var_dump', 'fputs', 'fwrite'];

    public function testExecutionLayerNeverWritesToTheProcessOutput(): void
    {
        $violations = [];
        foreach (glob(dirname(__DIR__) . '/src/Execution/*.php') ?: [] as $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (!is_array($token)) {
                    continue;
                }
                [$id, $text, $line] = $token;
                $isOutput = in_array($id, [T_ECHO, T_PRINT, T_INLINE_HTML], true)
                    || ($id === T_STRING && (in_array($text, ['STDERR', 'STDOUT'], true) || in_array(strtolower($text), self::OUTPUT_FUNCTIONS, true)))
                    || ($id === T_CONSTANT_ENCAPSED_STRING && preg_match('~php://(stdout|stderr|output)~i', $text) === 1);
                if ($isOutput) {
                    $violations[] = basename($file) . ':' . $line . ' ' . trim($text);
                }
            }
        }

        self::assertSame([], $violations, 'Return typed values and let the CLI command print them.');
    }
}
