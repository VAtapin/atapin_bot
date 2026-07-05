<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

class SafeHtml
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'strong', 'b',
        'em', 'i', 'blockquote', 'a', 'img', 'figure', 'figcaption', 'hr',
    ];

    public function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><body>'.$html.'</body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $document->getElementsByTagName('body')->item(0);
        if (! $body) {
            return '';
        }

        $this->sanitizeChildren($body);

        return collect(iterator_to_array($body->childNodes))
            ->map(fn (DOMNode $node): string => $document->saveHTML($node) ?: '')
            ->implode('');
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if (! in_array(mb_strtolower($node->tagName), self::ALLOWED_TAGS, true)) {
                $text = $node->ownerDocument->createTextNode($node->textContent);
                $parent->replaceChild($text, $node);

                continue;
            }

            foreach (iterator_to_array($node->attributes) as $attribute) {
                $name = mb_strtolower($attribute->name);
                $allowed = match ($node->tagName) {
                    'a' => in_array($name, ['href', 'title', 'target', 'rel'], true),
                    'img' => in_array($name, ['src', 'alt', 'title', 'width', 'height'], true),
                    default => false,
                };
                if (! $allowed || str_starts_with($name, 'on')) {
                    $node->removeAttribute($attribute->name);
                }
            }

            if ($node->hasAttribute('href')) {
                $url = trim($node->getAttribute('href'));
                if (! preg_match('~^(https?://|/|#)~i', $url)) {
                    $node->removeAttribute('href');
                }
            }

            if ($node->hasAttribute('src')) {
                $url = trim($node->getAttribute('src'));
                if (! str_starts_with($url, '/storage/')) {
                    $node->removeAttribute('src');
                }
            }
            if ($node->tagName === 'a' && $node->getAttribute('target') === '_blank') {
                $node->setAttribute('rel', 'noopener noreferrer');
            }

            $this->sanitizeChildren($node);
        }
    }
}
