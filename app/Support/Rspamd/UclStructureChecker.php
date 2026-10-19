<?php

namespace App\Support\Rspamd;

/**
 * The structural check of an Rspamd settings rule (feature 013 research R11, FR-051): one pass over
 * the content that tracks the open braces and brackets, skipping `#` comments to the end of the
 * line, C-style comments, single- and double-quoted strings with backslash escapes and UCL heredocs
 * (`<<TAG` up to a line that is `TAG`). It reports the first `unexpectedBrace` or
 * `unexpectedBracket` (a closer without its opener), an `unclosedString`, or at the end an
 * `unclosedBrace` or `unclosedBracket` at the line of the opener. Rspamd itself decides everything
 * else, for example unknown options.
 */
final class UclStructureChecker
{
    /**
     * The first structural violation, or null.
     *
     * @return array{construct: string, line: int}|null
     */
    public function check(string $content): ?array
    {
        $length = strlen($content);
        $line = 1;
        /** @var list<array{string, int}> $stack */
        $stack = [];

        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];

            if ($char === "\n") {
                $line++;

                continue;
            }

            if ($char === '#') {
                $end = strpos($content, "\n", $i);
                $i = $end === false ? $length : $end - 1;

                continue;
            }

            if ($char === '/' && ($content[$i + 1] ?? '') === '*') {
                $end = strpos($content, '*/', $i + 2);
                $comment = $end === false ? substr($content, $i) : substr($content, $i, $end + 2 - $i);
                $line += substr_count($comment, "\n");
                $i = $end === false ? $length : $end + 1;

                continue;
            }

            if ($char === '<' && preg_match('/\G<<([A-Z][A-Z0-9_]*)\n/', $content, $m, 0, $i) === 1) {
                $start = $line;
                $close = preg_match('/^'.preg_quote($m[1], '/').'$/m', $content, $c, PREG_OFFSET_CAPTURE, $i + strlen($m[0]));

                if ($close !== 1) {
                    return ['construct' => 'unclosedString', 'line' => $start];
                }

                $end = (int) $c[0][1] + strlen($m[1]);
                $line += substr_count(substr($content, $i, $end - $i), "\n");
                $i = $end - 1;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $start = $line;
                $j = $i + 1;

                while ($j < $length && $content[$j] !== $char) {
                    if ($content[$j] === '\\') {
                        $j++;
                    } elseif ($content[$j] === "\n") {
                        $line++;
                    }

                    $j++;
                }

                if ($j >= $length) {
                    return ['construct' => 'unclosedString', 'line' => $start];
                }

                $i = $j;

                continue;
            }

            if ($char === '{' || $char === '[') {
                $stack[] = [$char, $line];
            } elseif ($char === '}' || $char === ']') {
                $open = array_pop($stack);

                if ($open === null || $open[0] !== ($char === '}' ? '{' : '[')) {
                    return ['construct' => $char === '}' ? 'unexpectedBrace' : 'unexpectedBracket', 'line' => $line];
                }
            }
        }

        $open = array_pop($stack);

        return $open === null ? null : ['construct' => $open[0] === '{' ? 'unclosedBrace' : 'unclosedBracket', 'line' => $open[1]];
    }
}
