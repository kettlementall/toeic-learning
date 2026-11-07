<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Render practice-bank passage text safely: escape first, then mark the
 * "--131--" blanks and turn retyped markdown tables (| a | b |) into HTML
 * tables. Plain lines keep their breaks.
 */
class PracticeText
{
    public static function passage(?string $body): HtmlString
    {
        if ($body === null || trim($body) === '') {
            return new HtmlString('');
        }

        $html = [];
        $table = [];
        $flush = function () use (&$table, &$html) {
            if (! $table) {
                return;
            }
            $rows = array_values(array_filter($table, fn ($r) => ! preg_match('/^\|?\s*:?-{2,}/', $r)));
            $out = '<div class="overflow-x-auto my-2"><table class="text-sm border-collapse">';
            foreach ($rows as $i => $row) {
                $cells = array_map('trim', explode('|', trim($row, " |")));
                $tag = $i === 0 ? 'th' : 'td';
                $out .= '<tr>' . implode('', array_map(
                    fn ($c) => "<{$tag} class=\"border border-slate-200 px-2 py-1 text-left align-top\">" . self::inline($c) . "</{$tag}>",
                    $cells
                )) . '</tr>';
            }
            $html[] = $out . '</table></div>';
            $table = [];
        };

        foreach (preg_split('/\R/', $body) as $line) {
            if (str_starts_with(ltrim($line), '|')) {
                $table[] = trim($line);
                continue;
            }
            $flush();
            $html[] = trim($line) === '' ? '<div class="h-2"></div>' : '<p>' . self::inline($line) . '</p>';
        }
        $flush();

        return new HtmlString(implode("\n", $html));
    }

    private static function inline(string $text): string
    {
        $text = e($text);
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);

        return preg_replace(
            '/--(\d{1,3})--/',
            '<span class="inline-block px-1.5 mx-0.5 rounded bg-indigo-100 text-indigo-700 font-semibold text-xs align-middle">$1</span>',
            $text
        );
    }
}
