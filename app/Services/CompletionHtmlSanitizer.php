<?php

declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Renders administrator-authored completion HTML as a deliberately small,
 * static subset. This never evaluates Blade, PHP, JavaScript, or respondent
 * fields. It is defense in depth for the administrator-only setting.
 */
class CompletionHtmlSanitizer
{
    private const ALLOWED_TAGS = ['a', 'br', 'div', 'em', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'img', 'li', 'ol', 'p', 'span', 'strong', 'ul'];

    private const ALLOWED_ATTRIBUTES = ['alt', 'class', 'height', 'href', 'id', 'rel', 'src', 'target', 'title', 'width'];

    public function sanitize(?string $html): string
    {
        if (blank($html)) {
            return '';
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<div id="completion-html-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('completion-html-root');
        if (! $root instanceof DOMElement) {
            return '';
        }

        $this->sanitizeNode($root);

        $rendered = '';
        foreach ($root->childNodes as $child) {
            $rendered .= $document->saveHTML($child);
        }

        return trim($rendered);
    }

    private function sanitizeNode(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'form', 'object', 'embed', 'svg', 'math'], true)) {
                    $node->removeChild($child);

                    continue;
                }

                if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);

                    continue;
                }

                $this->sanitizeAttributes($child);
            }

            $this->sanitizeNode($child);
        }
    }

    private function sanitizeAttributes(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            if (! in_array($name, self::ALLOWED_ATTRIBUTES, true)) {
                $element->removeAttributeNode($attribute);
            }
        }

        foreach (['href', 'src'] as $attribute) {
            if ($element->hasAttribute($attribute) && ! $this->isSafeUrl($element->getAttribute($attribute))) {
                $element->removeAttribute($attribute);
            }
        }

        if ($element->tagName === 'a' && $element->getAttribute('target') === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function isSafeUrl(string $url): bool
    {
        $url = trim($url);

        return $url === '' || str_starts_with($url, '#') || str_starts_with($url, '/') || filter_var($url, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https', 'mailto'], true);
    }
}
