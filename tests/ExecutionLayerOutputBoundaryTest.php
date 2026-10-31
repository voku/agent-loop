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
        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src/Execution', \FilesystemIterator::SKIP_DOTS)),
            '/\.php$/',
        );
        foreach ($files as $file) {
            $file = (string) $file;
            foreach (self::findProcessOutputTokens((string) file_get_contents($file)) as $token) {
                $violations[] = basename($file) . ':' . $token;
            }
        }

        self::assertSame([], $violations, 'Return typed values and let the CLI command print them.');
    }

    public function testFullyQualifiedOutputCallsCannotBypassGuard(): void
    {
        self::assertSame(
            ['1 \\fwrite', '1 \\STDERR'],
            self::findProcessOutputTokens('<?php \\fwrite(\\STDERR, "notice");'),
        );
        self::assertSame(
            ['1 fwrite', '1 STDERR'],
            self::findProcessOutputTokens('<?php fwrite(STDERR, "notice");'),
        );
        self::assertSame([], self::findProcessOutputTokens('<?php return new Result("safe");'));
    }

    /**
     * @return list<string>
     */
    private static function findProcessOutputTokens(string $php): array
    {
        $violations = [];
        foreach (token_get_all($php) as $token) {
            if (!is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;
            $normalized = ltrim(strtolower($text), '\\');
            $isOutput = in_array($id, [T_ECHO, T_PRINT, T_INLINE_HTML], true)
                || (in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                    && (in_array($normalized, ['stderr', 'stdout'], true) || in_array($normalized, self::OUTPUT_FUNCTIONS, true)))
                || ($id === T_CONSTANT_ENCAPSED_STRING && preg_match('~php://(stdout|stderr|output)~i', $text) === 1);
            if ($isOutput) {
                $violations[] = $line . ' ' . trim($text);
            }
        }

        return $violations;
    }
}
