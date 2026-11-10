<?php

namespace App\Services\Export;

class BlockMarkup
{
    private const TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'blockquote', 'pre'];

    /**
     * @param  array<int, array{type: string, text: string}>  $blocks
     */
    public static function html(array $blocks): string
    {
        $html = [];
        $listOpen = false;

        foreach ($blocks as $block) {
            $type = $block['type'];
            $text = self::escape($block['text']);

            if ($type === 'li') {
                if (! $listOpen) {
                    $html[] = '<ul>';
                    $listOpen = true;
                }

                $html[] = '<li>'.$text.'</li>';

                continue;
            }

            if ($listOpen) {
                $html[] = '</ul>';
                $listOpen = false;
            }

            if (in_array($type, self::TAGS, true)) {
                $html[] = '<'.$type.'>'.$text.'</'.$type.'>';

                continue;
            }

            $class = preg_replace('/[^a-z0-9_-]/i', '', $type);

            $html[] = '<p class="block-'.$class.'">'.$text.'</p>';
        }

        if ($listOpen) {
            $html[] = '</ul>';
        }

        return implode("\n", $html);
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param  array<int, array{type: string, text: string}>  $blocks
     */
    public static function chapterTitle(array $blocks, string $fallback): string
    {
        foreach ($blocks as $block) {
            if (str_starts_with($block['type'], 'h')) {
                return $block['text'];
            }
        }

        return $fallback;
    }
}
