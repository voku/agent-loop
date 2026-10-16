<?php

declare(strict_types=1);

// Frozen E1.4 runtime invocation. Byte-identical in both arms. It only calls the project's public
// parser entry point on a source string; it prints no expected failure text.

require \getcwd() . '/vendor/autoload.php';

use voku\SimplePhpParser\Parsers\Helper\ParserOptions;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

$source = <<<'PHP'
<?php
namespace Cycle;
interface Marker {}
class First extends Second implements Marker {}
class Second extends First {}
PHP;

\fwrite(\STDOUT, "BEGIN parse source-bytes=" . \strlen($source) . "\n");

$container = PhpCodeParser::getPhpFiles($source, [], [], [], ParserOptions::astOnly());

$classes = $container->getClasses();
\ksort($classes);
foreach ($classes as $name => $class) {
    \fwrite(\STDOUT, 'class ' . $name . ' interfaces=' . \implode(',', $class->interfaces) . "\n");
}
\fwrite(\STDOUT, "END parse classes=" . \count($classes) . "\n");
