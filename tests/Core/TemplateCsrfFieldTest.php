<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * Contract test: every hidden input in a Twig template that carries a CSRF
 * token must use a field name the global CSRF middleware accepts.
 *
 * The middleware in app/src/Core/Application.php reads the token from
 * `_csrf_token`, `_csrf`, or the X-CSRF-Token header. A template that names
 * the field anything else silently fails with 403 at runtime (regression
 * seen on /context/mode, 2026-04-24).
 */
class TemplateCsrfFieldTest extends TestCase
{
    private const ALLOWED_FIELD_NAMES = ['_csrf_token', '_csrf'];

    public function testEveryCsrfTokenFieldUsesAnAllowedName(): void
    {
        $templatesDir = dirname(__DIR__, 2) . '/app';
        $this->assertDirectoryExists($templatesDir);

        $violations = [];

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($templatesDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $file) {
            if (!$file->isFile() || !str_ends_with($file->getPathname(), '.twig')) {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());

            // Match <input ... name="X" ... value="{{ csrf_token ... }}">
            // regardless of attribute order or quoting style.
            if (preg_match_all(
                '/<input\b[^>]*\bvalue\s*=\s*"\{\{\s*csrf_token[^"]*"[^>]*>/i',
                $contents,
                $matches
            )) {
                foreach ($matches[0] as $tag) {
                    if (!preg_match('/\bname\s*=\s*"([^"]+)"/', $tag, $nameMatch)) {
                        continue;
                    }
                    $name = $nameMatch[1];
                    if (!in_array($name, self::ALLOWED_FIELD_NAMES, true)) {
                        $violations[] = sprintf(
                            '%s uses name="%s" (allowed: %s)',
                            str_replace($templatesDir . DIRECTORY_SEPARATOR, '', $file->getPathname()),
                            $name,
                            implode(', ', self::ALLOWED_FIELD_NAMES)
                        );
                    }
                }
            }

            // Also catch the reversed attribute order: value first, then name.
            if (preg_match_all(
                '/<input\b[^>]*\bname\s*=\s*"([^"]+)"[^>]*\bvalue\s*=\s*"\{\{\s*csrf_token[^"]*"/i',
                $contents,
                $matches2
            )) {
                foreach ($matches2[1] as $name) {
                    if (!in_array($name, self::ALLOWED_FIELD_NAMES, true)) {
                        $violations[] = sprintf(
                            '%s uses name="%s" (allowed: %s)',
                            str_replace($templatesDir . DIRECTORY_SEPARATOR, '', $file->getPathname()),
                            $name,
                            implode(', ', self::ALLOWED_FIELD_NAMES)
                        );
                    }
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($violations)),
            "Templates contain CSRF hidden inputs with field names the global middleware will reject:\n  "
                . implode("\n  ", array_unique($violations))
        );
    }
}
