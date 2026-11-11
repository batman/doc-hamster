<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Formatted text documents (point 21): a strict allowlist sanitizer of our own (DOMDocument, no extra
 * package). It rebuilds the HTML from scratch: only a few block and inline tags survive, every attribute
 * goes except a class from our own list. Size, font and alignment become classes (rt-size-3,
 * rt-font-serif, rt-align-center), because the CSP forbids inline styles when the text is shown.
 */
final class RichText
{
    /** Tags kept as they are (b and i become strong and em, font becomes span). */
    private const TAGS = ['p' => 'p', 'div' => 'div', 'br' => 'br', 'ul' => 'ul', 'ol' => 'ol', 'li' => 'li',
        'strong' => 'strong', 'b' => 'strong', 'em' => 'em', 'i' => 'em', 'u' => 'u', 'span' => 'span', 'font' => 'span'];

    /** Dropped with everything inside; any other unknown tag is unwrapped (its text stays). */
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'head', 'title', 'noscript', 'svg', 'math',
        'textarea', 'select', 'input', 'button', 'form', 'img', 'video', 'audio', 'canvas', 'link', 'meta', 'base', 'frame', 'frameset'];

    private const BLOCKS = ['p', 'div', 'li', 'ul', 'ol'];

    public const FONTS = ['sans', 'serif', 'mono'];

    public const SIZES = [1, 2, 3, 4, 5, 6, 7];

    private const ALIGN = ['left', 'right', 'center', 'justify'];

    private const MAX_DEPTH = 40;

    public static function sanitize(string $html): string
    {
        $body = self::parse($html);

        return $body === null ? '' : trim(self::children($body, 0));
    }

    /** The text without tags, one line per block – for the search (ocr_text). */
    public static function toPlainText(string $html): string
    {
        $body = self::parse($html);
        if ($body === null) {
            return '';
        }
        $text = self::text($body);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    private static function parse(string $html): ?DOMNode
    {
        if (trim($html) === '') {
            return null;
        }
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom->getElementsByTagName('body')->item(0);
    }

    private static function children(DOMNode $node, int $depth): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= self::node($child, $depth);
        }

        return $out;
    }

    private static function node(DOMNode $node, int $depth): string
    {
        if ($node instanceof DOMText) {
            return htmlspecialchars($node->data, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }
        if (! $node instanceof DOMElement) {
            return '';
        }
        $name = strtolower($node->tagName);
        if (in_array($name, self::DROP, true)) {
            return '';
        }
        $tag = self::TAGS[$name] ?? null;
        if ($tag === null || $depth > self::MAX_DEPTH) {
            return self::children($node, $depth);
        }
        if ($tag === 'br') {
            return '<br>';
        }
        $classes = self::classes($node, $tag);
        $inner = self::children($node, $depth + 1);
        // An empty span says nothing.
        if ($tag === 'span' && $classes === []) {
            return $inner;
        }

        return '<'.$tag.($classes === [] ? '' : ' class="'.implode(' ', $classes).'"').'>'.$inner.'</'.$tag.'>';
    }

    /**
     * Our own classes, plus what the browser's editing commands wrote as attributes or styles.
     *
     * @return list<string>
     */
    private static function classes(DOMElement $node, string $tag): array
    {
        $found = [];
        foreach (preg_split('/\s+/', $node->getAttribute('class')) ?: [] as $class) {
            if (preg_match('/^rt-(size-[1-7]|font-(sans|serif|mono)|align-(left|right|center|justify)|indent-[1-8])$/', $class) === 1) {
                $found[] = $class;
            }
        }
        $style = strtolower($node->getAttribute('style'));
        $align = strtolower($node->getAttribute('align'));
        if (preg_match('/text-align\s*:\s*(left|right|center|justify)/', $style, $m) === 1) {
            $align = $m[1];
        }
        if (in_array($align, self::ALIGN, true)) {
            $found[] = 'rt-align-'.$align;
        }
        if (strtolower($node->tagName) === 'font') {
            $size = (int) $node->getAttribute('size');
            if (in_array($size, self::SIZES, true)) {
                $found[] = 'rt-size-'.$size;
            }
            $font = self::font($node->getAttribute('face'));
            if ($font !== null) {
                $found[] = 'rt-font-'.$font;
            }
        }

        // One of each kind; block classes only on blocks, size and font only inline.
        $kept = [];
        foreach ($found as $class) {
            $kind = (string) preg_replace('/-[^-]+$/', '', $class);
            $block = $kind === 'rt-align' || $kind === 'rt-indent';
            if ($block !== in_array($tag, self::BLOCKS, true) || ($kind === 'rt-indent' && $tag === 'li')) {
                continue;
            }
            $kept[$kind] = $class;
        }

        return array_values($kept);
    }

    private static function font(string $face): ?string
    {
        $face = strtolower($face);

        return match (true) {
            str_contains($face, 'mono') || str_contains($face, 'courier') => 'mono',
            str_contains($face, 'sans') || str_contains($face, 'arial') || str_contains($face, 'helvetica') => 'sans',
            str_contains($face, 'serif') || str_contains($face, 'times') || str_contains($face, 'georgia') => 'serif',
            default => null,
        };
    }

    private static function text(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $out .= $child->data;
            } elseif ($child instanceof DOMElement) {
                $name = strtolower($child->tagName);
                if (in_array($name, self::DROP, true)) {
                    continue;
                }
                if ($name === 'br') {
                    $out .= "\n";

                    continue;
                }
                $out .= self::text($child);
                if (in_array($name, self::BLOCKS, true)) {
                    $out .= "\n";
                }
            }
        }

        return $out;
    }
}
