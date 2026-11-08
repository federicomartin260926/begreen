<?php

declare(strict_types=1);

namespace App\Service\FinalReport;

/** Allowed editorial HTML, shared by persistence and PDF rendering. */
final class FinalReportRichText
{
    private const TAGS = ['p' => true, 'br' => true, 'strong' => true, 'em' => true,
        'ul' => true, 'ol' => true, 'li' => true];
    private const REMOVE_WITH_CONTENT = ['script' => true, 'style' => true,
        'iframe' => true, 'object' => true, 'embed' => true, 'svg' => true,
        'math' => true, 'template' => true];

    public static function sanitize(?string $value, int $maxPlainLength): ?string
    {
        $text = trim($value ?? '');
        if ($text === '') {
            return null;
        }
        if (strlen($text) > $maxPlainLength * 8 + 4096) {
            throw new \InvalidArgumentException('Editorial HTML is too long.');
        }
        if (!self::hasHtml($text)) {
            if (mb_strlen($text) > $maxPlainLength) {
                throw new \InvalidArgumentException('Editorial text is too long.');
            }
            return $text;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $source = new \DOMDocument('1.0', 'UTF-8');
            $source->loadHTML('<?xml encoding="UTF-8"><div id="begreen-editor-root">'.$text.'</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
            $root = $source->getElementById('begreen-editor-root');
            if (!$root instanceof \DOMElement) {
                throw new \InvalidArgumentException('Invalid editorial HTML.');
            }
            $target = new \DOMDocument('1.0', 'UTF-8');
            $container = $target->createElement('div');
            $target->appendChild($container);
            foreach (iterator_to_array($root->childNodes) as $child) {
                self::copyAllowed($child, $target, $container);
            }
            $result = '';
            foreach (iterator_to_array($container->childNodes) as $child) {
                $result .= $target->saveHTML($child);
            }
            $result = trim($result);
            if (mb_strlen(trim($container->textContent ?? '')) > $maxPlainLength) {
                throw new \InvalidArgumentException('Editorial text is too long.');
            }
            return $result === '' ? null : $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function render(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }
        if (!self::hasHtml($value)) {
            return nl2br(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        }
        return self::sanitize($value, 10000) ?? '';
    }

    private static function hasHtml(string $value): bool
    {
        return preg_match('~<\s*/?\s*[a-z][a-z0-9]*\b~i', $value) === 1;
    }

    private static function copyAllowed(\DOMNode $node, \DOMDocument $target, \DOMNode $parent): void
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            $parent->appendChild($target->createTextNode($node->nodeValue ?? ''));
            return;
        }
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return;
        }
        $tag = strtolower($node->nodeName);
        if (isset(self::REMOVE_WITH_CONTENT[$tag])) {
            return;
        }
        $tag = match ($tag) { 'b' => 'strong', 'i' => 'em', 'div' => 'p', default => $tag };
        if (!isset(self::TAGS[$tag])) {
            foreach (iterator_to_array($node->childNodes) as $child) {
                self::copyAllowed($child, $target, $parent);
            }
            return;
        }
        $element = $target->createElement($tag);
        $parent->appendChild($element);
        foreach (iterator_to_array($node->childNodes) as $child) {
            self::copyAllowed($child, $target, $element);
        }
    }
}
