<?php

namespace justinholtweb\bed\helpers;

/**
 * A very small HTML tokeniser, and the only place in Bed that reads markup.
 *
 * This exists instead of `DOMDocument` because a whole-page filter has one hard requirement: the
 * bytes it did not mean to change must come out identical. libxml's HTML parser cannot promise
 * that — it re-serialises the whole document, moves nodes to satisfy an HTML4 content model, and
 * has opinions about `<template>`, inline `<svg>`, custom elements and entities. Rewriting
 * somebody's entire page as a side effect of adding `loading="lazy"` to one frame is not a trade
 * worth making.
 *
 * So this walks the string once, records offsets for the tags it finds, and the caller splices.
 * Everything it does not touch is copied through byte for byte.
 */
class Html
{
    /**
     * Elements whose content the HTML parser reads as text, not markup.
     *
     * This is the list that makes the tokeniser correct rather than approximately correct. An
     * `<iframe src=…>` written inside a `<script>` or a `<textarea>` is a string, and a scanner
     * that rewrites it has corrupted a code sample or somebody's unsaved form. `iframe` is on the
     * list itself — its own content is raw text under the spec, which is also why finding the end
     * of one is a plain string search and never needs depth counting.
     */
    public const RAW_TEXT = ['script', 'style', 'textarea', 'title', 'iframe', 'noembed', 'noframes', 'noscript', 'xmp'];

    /** Elements with no closing tag to look for. */
    public const VOID = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * Every tag in a document, in order, with byte offsets.
     *
     * Comments, doctypes, processing instructions and the contents of raw-text elements are
     * stepped over rather than reported — with one exception: the raw-text element's own start
     * and end tags are reported, because those are the tags callers came for.
     *
     * @return array<int, array{type: string, name: string, start: int, end: int, raw: string, attrs: array<string, string>, selfClosing: bool, contentStart: int|null, contentEnd: int|null}>
     */
    public static function tokenize(string $html): array
    {
        $tokens = [];
        $length = strlen($html);
        $i = 0;

        while (($lt = strpos($html, '<', $i)) !== false) {
            $next = $html[$lt + 1] ?? '';

            if ($next === '!') {
                if (substr($html, $lt, 4) === '<!--') {
                    $close = strpos($html, '-->', $lt + 4);
                    $i = $close === false ? $length : $close + 3;
                } else {
                    $gt = strpos($html, '>', $lt);
                    $i = $gt === false ? $length : $gt + 1;
                }

                continue;
            }

            if ($next === '?') {
                $gt = strpos($html, '>', $lt);
                $i = $gt === false ? $length : $gt + 1;
                continue;
            }

            if ($next === '/') {
                if (preg_match('/\A<\/([a-zA-Z][a-zA-Z0-9:._-]*)[^>]*>/', substr($html, $lt, 256), $match)) {
                    $tokens[] = [
                        'type' => 'end',
                        'name' => strtolower($match[1]),
                        'start' => $lt,
                        'end' => $lt + strlen($match[0]),
                        'raw' => $match[0],
                        'attrs' => [],
                        'selfClosing' => false,
                        'contentStart' => null,
                        'contentEnd' => null,
                    ];
                    $i = $lt + strlen($match[0]);
                } else {
                    $i = $lt + 1;
                }

                continue;
            }

            $tag = self::scanStartTag($html, $lt);

            if ($tag === null) {
                // A bare `<` in text, or `a < b`. Not a tag; keep walking.
                $i = $lt + 1;
                continue;
            }

            $i = $tag['end'];

            // A raw-text element's content is text. Report the pair, skip the middle.
            if (in_array($tag['name'], self::RAW_TEXT, true) && !$tag['selfClosing']) {
                $closeAt = self::findRawTextEnd($html, $tag['name'], $tag['end']);

                if ($closeAt !== null) {
                    $tag['contentStart'] = $tag['end'];
                    $tag['contentEnd'] = $closeAt['start'];
                    $tokens[] = $tag;
                    $tokens[] = $closeAt['token'];
                    $i = $closeAt['token']['end'];
                    continue;
                }
            }

            $tokens[] = $tag;
        }

        return $tokens;
    }

    /**
     * Reads one start tag beginning at `$pos`, or null if there is not one there.
     *
     * Attribute values are scanned rather than matched, because a `>` inside a quoted value is
     * legal and extremely common — `srcdoc`, inline styles, tracking URLs. Finding the end of a
     * tag with `strpos('>')` truncates those, and the corruption only shows up on the pages that
     * have them.
     *
     * @return array{type: string, name: string, start: int, end: int, raw: string, attrs: array<string, string>, selfClosing: bool, contentStart: null, contentEnd: null}|null
     */
    public static function scanStartTag(string $html, int $pos): ?array
    {
        $length = strlen($html);

        if (($html[$pos] ?? '') !== '<') {
            return null;
        }

        $i = $pos + 1;
        $nameStart = $i;
        $i += strspn($html, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789:._-', $i);

        if ($i === $nameStart || !ctype_alpha($html[$nameStart])) {
            return null;
        }

        $name = strtolower(substr($html, $nameStart, $i - $nameStart));
        $attrs = [];
        $selfClosing = false;

        while ($i < $length) {
            while ($i < $length && ctype_space($html[$i])) {
                $i++;
            }

            if ($i >= $length) {
                return null;
            }

            $char = $html[$i];

            if ($char === '>') {
                $i++;
                break;
            }

            if ($char === '/') {
                if (($html[$i + 1] ?? '') === '>') {
                    $selfClosing = true;
                    $i += 2;
                    break;
                }

                $i++;
                continue;
            }

            // Attribute name: anything up to whitespace, `=`, `/` or `>`.
            $attrStart = $i;

            while ($i < $length && !ctype_space($html[$i]) && !in_array($html[$i], ['=', '>', '/'], true)) {
                $i++;
            }

            if ($i === $attrStart) {
                $i++;
                continue;
            }

            $attrName = strtolower(substr($html, $attrStart, $i - $attrStart));

            while ($i < $length && ctype_space($html[$i])) {
                $i++;
            }

            if (($html[$i] ?? '') !== '=') {
                // A boolean attribute — `allowfullscreen`, `async`, `data-pin-do` on its own.
                // `??=` throughout, because when an attribute is written twice the HTML parser
                // keeps the first one and so must this.
                $attrs[$attrName] ??= '';
                continue;
            }

            $i++;

            while ($i < $length && ctype_space($html[$i])) {
                $i++;
            }

            $quote = $html[$i] ?? '';

            if ($quote === '"' || $quote === "'") {
                $close = strpos($html, $quote, $i + 1);

                if ($close === false) {
                    return null;
                }

                $attrs[$attrName] ??= substr($html, $i + 1, $close - $i - 1);
                $i = $close + 1;
                continue;
            }

            $valueStart = $i;

            while ($i < $length && !ctype_space($html[$i]) && $html[$i] !== '>') {
                $i++;
            }

            $attrs[$attrName] ??= substr($html, $valueStart, $i - $valueStart);
        }

        return [
            'type' => 'start',
            'name' => $name,
            'start' => $pos,
            'end' => $i,
            'raw' => substr($html, $pos, $i - $pos),
            'attrs' => $attrs,
            // A trailing slash is meaningless on an HTML element the parser knows the shape of.
            // It matters most to get this right for the raw-text elements: `<iframe … />` does
            // *not* close an iframe, its content still runs to `</iframe>`, and treating the
            // slash as authoritative would wrap the bed around an empty tag and leave the real
            // frame outside it.
            'selfClosing' => in_array($name, self::VOID, true)
                || ($selfClosing && !in_array($name, self::RAW_TEXT, true)),
            'contentStart' => null,
            'contentEnd' => null,
        ];
    }

    /**
     * The end tag of a raw-text element — a plain search, because nothing inside it is markup.
     *
     * @return array{start: int, token: array<string, mixed>}|null
     */
    private static function findRawTextEnd(string $html, string $name, int $from): ?array
    {
        $needle = '</' . $name;
        $offset = $from;

        while (($at = stripos($html, $needle, $offset)) !== false) {
            $after = $html[$at + strlen($needle)] ?? '';

            // `</scriptish>` is not the end of a `<script>`.
            if ($after !== '' && !ctype_space($after) && $after !== '>' && $after !== '/') {
                $offset = $at + 1;
                continue;
            }

            $gt = strpos($html, '>', $at);

            if ($gt === false) {
                return null;
            }

            return [
                'start' => $at,
                'token' => [
                    'type' => 'end',
                    'name' => $name,
                    'start' => $at,
                    'end' => $gt + 1,
                    'raw' => substr($html, $at, $gt + 1 - $at),
                    'attrs' => [],
                    'selfClosing' => false,
                    'contentStart' => null,
                    'contentEnd' => null,
                ],
            ];
        }

        return null;
    }

    /**
     * The offset just past the end tag that closes the token at `$index`, by depth counting.
     *
     * @param array<int, array<string, mixed>> $tokens
     */
    public static function closingOffset(array $tokens, int $index): ?int
    {
        $open = $tokens[$index];

        if ($open['selfClosing']) {
            return $open['end'];
        }

        $name = $open['name'];
        $depth = 0;
        $count = count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            if ($tokens[$i]['name'] !== $name) {
                continue;
            }

            if ($tokens[$i]['type'] === 'start') {
                if (!$tokens[$i]['selfClosing']) {
                    $depth++;
                }

                continue;
            }

            if ($depth === 0) {
                return $tokens[$i]['end'];
            }

            $depth--;
        }

        return null;
    }

    /** @param array<string, string> $attrs */
    public static function renderStartTag(string $name, array $attrs, bool $selfClosing = false): string
    {
        $out = '<' . $name;

        foreach ($attrs as $attrName => $value) {
            if ($value === '') {
                $out .= ' ' . $attrName;
                continue;
            }

            $out .= ' ' . $attrName . '="' . self::escapeAttr($value) . '"';
        }

        return $out . ($selfClosing ? ' />' : '>');
    }

    /**
     * Escapes a value for a double-quoted attribute.
     *
     * `htmlspecialchars` with `ENT_QUOTES | ENT_SUBSTITUTE` and no double encoding: values here
     * come out of markup that was already escaped, so re-encoding an existing `&amp;` would turn
     * every URL with two query parameters into a broken one.
     */
    public static function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }

    /**
     * The class list of a parsed tag.
     *
     * @param array<string, string> $attrs
     * @return string[]
     */
    public static function classes(array $attrs): array
    {
        $class = trim($attrs['class'] ?? '');

        if ($class === '') {
            return [];
        }

        return preg_split('/\s+/', $class) ?: [];
    }

    /**
     * Applies a set of `[start, end, replacement]` edits to a string.
     *
     * Back to front, so every offset an edit was computed against is still valid when its turn
     * comes. This is the whole reason the scanner reports offsets instead of nodes.
     *
     * @param array<int, array{0: int, 1: int, 2: string}> $edits
     */
    public static function splice(string $html, array $edits): string
    {
        // Back to front by start, and for edits that begin at the same offset the longer one
        // first. That ordering matters: wrapping a frame is a zero-width insert at the frame's
        // own start offset, and rewriting the frame's attributes begins there too. Rewrite the
        // tag, then insert in front of it — the other order drops the rewrite as an overlap.
        usort($edits, fn(array $a, array $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);

        $floor = strlen($html);

        foreach ($edits as [$start, $end, $replacement]) {
            if ($start < 0 || $end > strlen($html) || $end < $start) {
                continue;
            }

            // Overlapping edits are dropped rather than applied, because the second one would be
            // computed against offsets the first one has already moved. Bed does not generate
            // them — an embed nested inside another embed's wrapper would — and silently doing
            // nothing to the inner one is the only outcome here that cannot corrupt a page.
            if ($end > $floor) {
                continue;
            }

            $html = substr($html, 0, $start) . $replacement . substr($html, $end);
            $floor = $start;
        }

        return $html;
    }
}
