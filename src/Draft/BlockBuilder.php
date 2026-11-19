<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Draft;

/**
 * Builds serialised block markup. Every piece of text is escaped here,
 * and square brackets are encoded so model text can never run a
 * shortcode (security rule 7).
 */
final class BlockBuilder
{
    public static function esc(string $text): string
    {
        $s = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');

        return str_replace(['[', ']'], ['&#91;', '&#93;'], $s);
    }

    public static function escUrl(string $url): string
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            return '';
        }

        return htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @return array{name: string, attrs: array<string, mixed>, html: string} */
    public static function paragraph(string $html): array
    {
        return ['name' => 'core/paragraph', 'attrs' => [], 'html' => '<p>' . $html . '</p>'];
    }

    /** @return array{name: string, attrs: array<string, mixed>, html: string} */
    public static function heading(string $text, int $level = 2): array
    {
        $attrs = $level === 2 ? [] : ['level' => $level];

        return ['name' => 'core/heading', 'attrs' => $attrs, 'html' => '<h' . $level . ' class="wp-block-heading">' . self::esc($text) . '</h' . $level . '>'];
    }

    /** @return array{name: string, attrs: array<string, mixed>, html: string} */
    public static function audio(string $url, int $attachmentId = 0): array
    {
        $attrs = $attachmentId > 0 ? ['id' => $attachmentId] : [];

        return ['name' => 'core/audio', 'attrs' => $attrs, 'html' => '<figure class="wp-block-audio"><audio controls src="' . self::escUrl($url) . '"></audio></figure>'];
    }

    /** @return array{name: string, attrs: array<string, mixed>, html: string} */
    public static function quote(string $text, string $citeHtml): array
    {
        $inner = self::serialize([self::paragraph(self::esc($text))]);

        return ['name' => 'core/quote', 'attrs' => [], 'html' => '<blockquote class="wp-block-quote">' . $inner . '<cite>' . $citeHtml . '</cite></blockquote>'];
    }

    /**
     * @param list<string> $itemsHtml already escaped
     *
     * @return array{name: string, attrs: array<string, mixed>, html: string}
     */
    public static function list(array $itemsHtml): array
    {
        $items = [];
        foreach ($itemsHtml as $i) {
            $items[] = ['name' => 'core/list-item', 'attrs' => [], 'html' => '<li>' . $i . '</li>'];
        }

        return ['name' => 'core/list', 'attrs' => [], 'html' => '<ul class="wp-block-list">' . self::serialize($items) . '</ul>'];
    }

    /**
     * @param list<array{name: string, attrs: array<string, mixed>, html: string}> $inner
     *
     * @return array{name: string, attrs: array<string, mixed>, html: string}
     */
    public static function details(string $summary, array $inner): array
    {
        return ['name' => 'core/details', 'attrs' => [], 'html' => '<details class="wp-block-details"><summary>' . self::esc($summary) . '</summary>' . self::serialize($inner) . '</details>'];
    }

    /**
     * @param list<array{name: string, attrs: array<string, mixed>, html: string}> $blocks
     */
    public static function serialize(array $blocks): string
    {
        $out = [];
        foreach ($blocks as $b) {
            $name = str_starts_with($b['name'], 'core/') ? substr($b['name'], 5) : $b['name'];
            $attrs = $b['attrs'] === [] ? '' : ' ' . (string) json_encode($b['attrs'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $out[] = '<!-- wp:' . $name . $attrs . " -->\n" . $b['html'] . "\n<!-- /wp:" . $name . ' -->';
        }

        return implode("\n\n", $out);
    }
}
