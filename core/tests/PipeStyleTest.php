<?php

declare(strict_types=1);

namespace NeuronBook\TripPlanner\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guards a PHP 8.5.4 engine bug, not a style rule.
 *
 * Inside a namespace, piping into a first-class callable of an internal
 * function written WITHOUT its leading backslash - `$x |> trim(...)` -
 * corrupts the heap: the process dies with "zend_mm_heap corrupted", or a
 * later, unrelated string silently comes out garbled. `\trim(...)`, closures
 * and arrow functions in a pipe are all safe.
 */
final class PipeStyleTest extends TestCase
{
    public function testPipesNeverTargetAnUnqualifiedFunction(): void
    {
        $offenders = [];

        foreach (['src', 'run', 'tests'] as $dir) {
            /** @var iterable<SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(\dirname(__DIR__) . "/{$dir}", RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($files as $file) {
                // This file quotes the pattern in its own docblock.
                if ($file->getExtension() !== 'php' || $file->getRealPath() === __FILE__) {
                    continue;
                }

                $source = (string) \file_get_contents($file->getPathname());

                if (\preg_match_all('/\|>\s*[a-z_][a-z0-9_]*\(\.\.\.\)/i', $source, $matches) > 0) {
                    $offenders[] = $file->getFilename() . ': ' . \implode(', ', $matches[0]);
                }
            }
        }

        self::assertSame([], $offenders, "Qualify internal functions in pipes (\\trim(...), not trim(...)).\n" . \implode("\n", $offenders));
    }
}
