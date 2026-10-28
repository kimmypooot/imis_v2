<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The boot screens (resources/css/boot.css) are copied into every system under
 * the IMIS hub, and a copy that drifts shows as a screen that looks different
 * from one page to the next. This compares each copy with IMIS's, rule by
 * rule, whatever the order, formatting or variable names.
 *
 * The systems sit beside this repository; one that isn't checked out is skipped.
 *
 * Intended differences:
 * - `html` (the page background) is IMIS/JPortal only, because a module's body
 *   has its own background.
 * - The modules define their colours in one `.imis-preloader, .imis-tr` rule
 *   of --imis-* variables, which must hold the same values as IMIS's token
 *   fallbacks.
 */
class ModuleBootStylesTest extends TestCase
{
    private const VARIABLES = '.imis-preloader, .imis-tr';

    /** @return array<string, array{string, string, bool}> */
    public static function systems(): array
    {
        return [
            'JPortal' => ['jportal', 'resources/css/boot.css', true],
            'PROCTAD' => ['proctad', 'resources/css/imis-loading.css', false],
            'TIMS' => ['csc-tims', 'resources/css/imis-loading.css', false],
            'Recruitment Portal' => ['recruitment-system', 'resources/css/imis-loading.css', false],
        ];
    }

    #[DataProvider('systems')]
    public function test_a_systems_boot_styles_match_imis(string $directory, string $file, bool $sharesIMISTokens): void
    {
        $path = dirname(__DIR__, 3)."/{$directory}/{$file}";

        if (! is_file($path)) {
            $this->markTestSkipped("{$directory} is not checked out beside IMIS.");
        }

        $imis = $this->rules(file_get_contents(dirname(__DIR__, 2).'/resources/css/boot.css'));
        $copy = $this->rules(file_get_contents($path));

        if (! $sharesIMISTokens) {
            unset($imis['html']);
            $variables = $copy[self::VARIABLES] ?? [];
            unset($copy[self::VARIABLES]);
            $this->assertSame($this->tokenValues(), array_intersect_key($this->variableValues($variables), $this->tokenValues()), 'The colour variables differ from the IMIS token fallbacks.');
        }

        $this->assertSame([], array_keys(array_diff_key($imis, $copy)), "{$directory} lacks rules IMIS has.");
        $this->assertSame([], array_keys(array_diff_key($copy, $imis)), "{$directory} has rules IMIS doesn't.");

        foreach ($imis as $selector => $declarations) {
            $this->assertSame($declarations, $copy[$selector], "{$directory}: the rule for {$selector} differs from IMIS.");
        }
    }

    /** The colour and radius fallbacks IMIS's boot.css carries, by token. */
    private function tokenValues(): array
    {
        preg_match_all('/var\(--(?:color-|radius-)([a-z-]+),\s*(#[0-9a-f]{3,8}|[0-9.]+rem)\)/i', file_get_contents(dirname(__DIR__, 2).'/resources/css/boot.css'), $found, PREG_SET_ORDER);

        $values = [];
        foreach ($found as [, $token, $value]) {
            $values[match ($token) {
                'csc-blue' => 'blue',
                'csc-red' => 'red',
                'lg' => 'radius',
                default => $token,
            }] = strtolower($value);
        }
        ksort($values);

        return $values;
    }

    /** The module's --imis-* variables, by the same token names. */
    private function variableValues(array $declarations): array
    {
        $values = [];
        foreach ($declarations as $property => $value) {
            if (str_starts_with($property, '--imis-')) {
                $values[substr($property, 7)] = strtolower($value);
            }
        }
        ksort($values);

        return $values;
    }

    /** @return array<string, array<string, string>> selector (with its media query) => declarations */
    private function rules(string $css): array
    {
        $css = preg_replace(['!/\*.*?\*/!s', '/@import[^;]*;/'], '', $css);
        $rules = [];
        $this->collect($css, '', $rules);
        ksort($rules);

        return $rules;
    }

    private function collect(string $css, string $context, array &$rules): void
    {
        $length = strlen($css);
        $position = 0;

        while (($open = strpos($css, '{', $position)) !== false) {
            $prelude = trim(substr($css, $position, $open - $position));
            $depth = 1;
            $end = $open + 1;

            while ($end < $length && $depth > 0) {
                $depth += match ($css[$end]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };
                $end++;
            }

            $body = substr($css, $open + 1, $end - $open - 2);
            $position = $end;

            if (str_starts_with($prelude, '@layer')) {
                $this->collect($body, $context, $rules);
            } elseif (str_starts_with($prelude, '@media')) {
                $this->collect($body, $context.$this->squash($prelude).' | ', $rules);
            } elseif (str_starts_with($prelude, '@keyframes')) {
                $rules[$context.$prelude] = ['keyframes' => $this->value($body)];
            } else {
                $selectors = array_map($this->squash(...), explode(',', $prelude));
                sort($selectors);
                $rules[$context.implode(', ', $selectors)] = array_merge($rules[$context.implode(', ', $selectors)] ?? [], $this->declarations($body));
            }
        }
    }

    /** @return array<string, string> */
    private function declarations(string $body): array
    {
        $found = [];
        $depth = 0;
        $current = '';

        foreach (str_split($body.';') as $character) {
            $depth += match ($character) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            if ($character === ';' && $depth === 0) {
                if (str_contains($current, ':')) {
                    [$property, $value] = explode(':', $current, 2);
                    $found[strtolower(trim($property))] = $this->value($value);
                }
                $current = '';

                continue;
            }

            $current .= $character;
        }

        return $found;
    }

    private function squash(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /** A value with each design token under one name, whichever file spelled it. */
    private function value(string $value): string
    {
        $value = preg_replace_callback('/var\(--([a-z-]+)(?:,\s*[^()]*(?:\([^()]*\))?[^()]*)?\)/i', function (array $match) {
            $name = preg_replace('/^(?:color-|radius-|font-|imis-)/', '', $match[1]);

            return 'token:'.match ($name) {
                'csc-blue' => 'blue',
                'csc-red' => 'red',
                'lg', 'sans' => $name === 'lg' ? 'radius' : 'font',
                default => $name,
            };
        }, $value);

        return strtolower($this->squash($value));
    }
}
