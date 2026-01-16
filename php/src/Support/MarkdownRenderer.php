<?php

declare(strict_types=1);

namespace Attendly\Support;

use DOMDocument;
use DOMElement;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension;
use League\CommonMark\MarkdownConverter;

final class MarkdownRenderer
{
    private MarkdownConverter $converter;

    public function __construct(?MarkdownConverter $converter = null)
    {
        if ($converter !== null) {
            $this->converter = $converter;
            return;
        }

        $config = [
            'disallowed_raw_html' => [
                'disallowed_tags' => [
                    'title',
                    'textarea',
                    'style',
                    'xmp',
                    'iframe',
                    'noembed',
                    'noframes',
                    'script',
                    'plaintext',
                ],
            ],
        ];
        $environment = new Environment($config);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new DisallowedRawHtmlExtension());
        $this->converter = new MarkdownConverter($environment);
    }

    public function render(string $markdown): string
    {
        $rendered = $this->converter->convert($markdown);
        $html = method_exists($rendered, 'getContent') ? $rendered->getContent() : (string)$rendered;
        return $this->sanitizeHtml($html);
    }

    private function sanitizeHtml(string $html): string
    {
        $allowedTags = [
            'p' => [],
            'strong' => [],
            'a' => ['href', 'rel'],
            'ul' => [],
            'ol' => [],
            'li' => [],
        ];

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div>' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $wrapper = $doc->documentElement;
        if (!$wrapper instanceof DOMElement) {
            return '';
        }
        $this->sanitizeNode($wrapper, $allowedTags);

        $result = '';
        foreach ($wrapper->childNodes as $child) {
            $result .= $doc->saveHTML($child);
        }
        return $result;
    }

    /**
     * @param array<string,string[]> $allowedTags
     */
    private function sanitizeNode(DOMElement $node, array $allowedTags): void
    {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $child = $node->childNodes->item($i);
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (!array_key_exists($tag, $allowedTags)) {
                $this->unwrapNode($child);
                continue;
            }

            if ($tag === 'a') {
                $href = trim((string)$child->getAttribute('href'));
                if ($href === '' || !$this->isAllowedUrl($href)) {
                    $child->removeAttribute('href');
                } else {
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
            }

            $allowedAttributes = $allowedTags[$tag];
            if ($allowedAttributes === []) {
                while ($child->attributes->length > 0) {
                    $child->removeAttributeNode($child->attributes->item(0));
                }
            } else {
                for ($j = $child->attributes->length - 1; $j >= 0; $j--) {
                    $attr = $child->attributes->item($j);
                    if ($attr === null) {
                        continue;
                    }
                    if (!in_array($attr->nodeName, $allowedAttributes, true)) {
                        $child->removeAttributeNode($attr);
                    }
                }
            }

            $this->sanitizeNode($child, $allowedTags);
        }
    }

    private function unwrapNode(DOMElement $node): void
    {
        $parent = $node->parentNode;
        if ($parent === null) {
            $node->parentNode?->removeChild($node);
            return;
        }
        while ($node->firstChild !== null) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }

    private function isAllowedUrl(string $href): bool
    {
        if (str_starts_with($href, '/')) {
            return true;
        }
        $parts = parse_url($href);
        if ($parts === false || empty($parts['scheme'])) {
            return false;
        }
        $scheme = strtolower((string)$parts['scheme']);
        return in_array($scheme, ['http', 'https'], true);
    }
}
