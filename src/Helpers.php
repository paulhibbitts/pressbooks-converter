<?php
namespace PB;

class Helpers
{
    public static function slugify(string $text): string
    {
        $text = str_replace("\xc2\xa0", ' ', $text); // normalise non-breaking spaces
        $text = strtolower(trim($text));
        $text = preg_replace('/[^\w\s-]/u', '', $text);
        $text = preg_replace('/[\s_]+/', '-', $text);
        $text = preg_replace('/-+/', '-', $text);
        return rtrim(substr($text, 0, 50), '-');
    }

    public static function uniqueSlug(string $text, array &$used): string
    {
        $base = self::slugify($text);
        $slug = $base;
        $n    = 2;
        while (in_array($slug, $used, true)) {
            $suffix = '-' . $n;
            $slug   = substr($base, 0, 50 - strlen($suffix)) . $suffix;
            $n++;
        }
        $used[] = $slug;
        return $slug;
    }

    public static function yamlStr(string $val): string
    {
        $val = str_replace("\r", '', $val);
        if (strpos($val, "\n") !== false
            || strpos($val, '"') !== false
            || strpos($val, '\\') !== false) {
            $escaped = str_replace(
                ['\\',   '"',   "\n"],
                ['\\\\', '\\"', '\\n'],
                $val
            );
            return '"' . $escaped . '"';
        }
        return '"' . $val . '"';
    }
}
