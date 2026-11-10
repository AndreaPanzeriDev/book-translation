<?php

namespace App\Services\Epub;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ZipArchive;

class EpubParser
{
    private const BLOCK_TAGS = [
        'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'blockquote', 'li', 'pre', 'dt', 'dd', 'figcaption',
        'td', 'th',
    ];

    /**
     * @return array{
     *     title: string,
     *     author: ?string,
     *     language: ?string,
     *     chapters: array<int, array{title: string, href: ?string, blocks: array<int, array{type: string, text: string}>}>,
     *     cover: ?array{contents: string, extension: string}
     * }
     */
    public function parse(string $path, ?string $fallbackTitle = null): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new InvalidEpubException(__('messages.epub.open_failed'));
        }

        try {
            $container = $this->readXml($zip, 'META-INF/container.xml');

            if (! $container) {
                throw new InvalidEpubException(__('messages.epub.missing_container'));
            }

            $xpath = new DOMXPath($container);
            $rootfile = $xpath->query("//*[local-name()='rootfile']")->item(0);

            if (! $rootfile instanceof DOMElement) {
                throw new InvalidEpubException(__('messages.epub.missing_opf'));
            }

            $opfPath = $this->normalizePath($rootfile->getAttribute('full-path'));

            if ($opfPath === '') {
                throw new InvalidEpubException(__('messages.epub.invalid_opf_path'));
            }

            $opf = $this->readXml($zip, $opfPath);

            if (! $opf) {
                throw new InvalidEpubException(__('messages.epub.opf_unreadable'));
            }

            $opfDir = dirname($opfPath) === '.' ? '' : dirname($opfPath);
            $opfXpath = new DOMXPath($opf);

            $title = $this->firstText($opfXpath, "//*[local-name()='metadata']/*[local-name()='title']");
            $author = $this->firstText($opfXpath, "//*[local-name()='metadata']/*[local-name()='creator']");
            $language = $this->firstText($opfXpath, "//*[local-name()='metadata']/*[local-name()='language']");

            $manifest = $this->readManifest($opfXpath);
            $spine = $this->readSpine($opfXpath);
            $toc = $this->readToc($zip, $manifest, $opfDir, $opfXpath);
            $cover = $this->readCover($zip, $opfXpath, $manifest, $opfDir);

            $navPaths = [];

            foreach ($manifest as $item) {
                if (str_contains($item['properties'], 'nav')) {
                    $navPaths[$this->resolvePath($opfDir, $item['href'])] = true;
                }
            }

            $chapters = [];

            foreach ($spine as $href) {
                $chapterPath = $this->resolvePath($opfDir, $href);

                if (isset($navPaths[$chapterPath])) {
                    continue;
                }

                $contents = $this->readEntry($zip, $chapterPath);

                if ($contents === null) {
                    continue;
                }

                $document = $this->loadDocument($contents);

                if (! $document) {
                    continue;
                }

                $body = $document->getElementsByTagName('body')->item(0) ?? $document->documentElement;

                if (! $body instanceof DOMNode) {
                    continue;
                }

                $blocks = [];
                $this->collectBlocks($body, $blocks);

                if ($blocks === []) {
                    continue;
                }

                $chapterTitle = $toc[$chapterPath] ?? null;

                if (! $chapterTitle) {
                    foreach ($blocks as $block) {
                        if (str_starts_with($block['type'], 'h')) {
                            $chapterTitle = $block['text'];
                            break;
                        }
                    }
                }

                $chapters[] = [
                    'title' => $chapterTitle ?: __('messages.epub.untitled_section', ['number' => count($chapters) + 1]),
                    'href' => $chapterPath,
                    'blocks' => $blocks,
                ];
            }

            if ($chapters === []) {
                throw new InvalidEpubException(__('messages.epub.no_text'));
            }

            $normalizedLanguage = $language
                ? strtolower(explode('-', str_replace('_', '-', $language))[0])
                : null;

            return [
                'title' => $title ?: ($fallbackTitle ?: pathinfo($path, PATHINFO_FILENAME)),
                'author' => $author,
                'language' => $normalizedLanguage ? substr($normalizedLanguage, 0, 16) : null,
                'chapters' => $chapters,
                'cover' => $cover,
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int, array{id: string, href: string, media_type: string, properties: string}>
     */
    private function readManifest(DOMXPath $xpath): array
    {
        $items = [];

        foreach ($xpath->query("//*[local-name()='manifest']/*[local-name()='item']") as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $items[$node->getAttribute('id')] = [
                'id' => $node->getAttribute('id'),
                'href' => $node->getAttribute('href'),
                'media_type' => $node->getAttribute('media-type'),
                'properties' => $node->getAttribute('properties'),
            ];
        }

        return $items;
    }

    /**
     * @return array<int, string>
     */
    private function readSpine(DOMXPath $xpath): array
    {
        $manifest = [];
        $hrefById = [];

        foreach ($xpath->query("//*[local-name()='manifest']/*[local-name()='item']") as $node) {
            if ($node instanceof DOMElement) {
                $hrefById[$node->getAttribute('id')] = $node->getAttribute('href');
            }
        }

        foreach ($xpath->query("//*[local-name()='spine']/*[local-name()='itemref']") as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $idref = $node->getAttribute('idref');

            if (isset($hrefById[$idref])) {
                $manifest[] = $hrefById[$idref];
            }
        }

        return $manifest;
    }

    /**
     * @param  array<string, array{id: string, href: string, media_type: string, properties: string}>  $manifest
     * @return array<string, string>
     */
    private function readToc(ZipArchive $zip, array $manifest, string $opfDir, DOMXPath $opfXpath): array
    {
        foreach ($manifest as $item) {
            if (! str_contains($item['properties'], 'nav')) {
                continue;
            }

            $navPath = $this->resolvePath($opfDir, $item['href']);
            $contents = $this->readEntry($zip, $navPath);

            if ($contents === null) {
                continue;
            }

            $document = $this->loadDocument($contents);

            if (! $document) {
                continue;
            }

            $toc = $this->anchorsToToc($document, dirname($navPath) === '.' ? '' : dirname($navPath));

            if ($toc !== []) {
                return $toc;
            }
        }

        $ncxId = $opfXpath->evaluate('string(//*[local-name()="spine"]/@toc)');

        foreach ($manifest as $item) {
            if ($ncxId && $item['id'] !== $ncxId) {
                continue;
            }

            if (! $ncxId && $item['media_type'] !== 'application/x-dtbncx+xml') {
                continue;
            }

            $ncxPath = $this->resolvePath($opfDir, $item['href']);
            $contents = $this->readEntry($zip, $ncxPath);

            if ($contents === null) {
                continue;
            }

            $document = $this->loadDocument($contents);

            if (! $document) {
                continue;
            }

            $toc = [];
            $baseDir = dirname($ncxPath) === '.' ? '' : dirname($ncxPath);

            foreach ($document->getElementsByTagName('navPoint') as $navPoint) {
                if (! $navPoint instanceof DOMElement) {
                    continue;
                }

                $labelNode = $navPoint->getElementsByTagName('text')->item(0);
                $contentNode = $navPoint->getElementsByTagName('content')->item(0);

                if (! $labelNode || ! $contentNode instanceof DOMElement) {
                    continue;
                }

                $label = $this->normalizeText($labelNode->textContent);
                $target = $this->resolvePath($baseDir, $contentNode->getAttribute('src'));

                if ($label !== '' && $target !== '') {
                    $toc[$target] ??= $label;
                }
            }

            if ($toc !== []) {
                return $toc;
            }
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function anchorsToToc(DOMDocument $document, string $baseDir): array
    {
        $toc = [];
        $xpath = new DOMXPath($document);
        $navs = $xpath->query("//*[local-name()='nav']");

        foreach ($navs as $nav) {
            if (! $nav instanceof DOMElement) {
                continue;
            }

            $type = $nav->getAttributeNS('http://www.idpf.org/2007/ops', 'type')
                ?: $nav->getAttribute('epub:type');

            if ($type !== '' && $type !== 'toc') {
                continue;
            }

            foreach ($nav->getElementsByTagName('a') as $anchor) {
                if (! $anchor instanceof DOMElement) {
                    continue;
                }

                $href = $anchor->getAttribute('href');
                $label = $this->normalizeText($anchor->textContent);

                if ($href === '' || $label === '' || str_starts_with($href, '#')) {
                    continue;
                }

                $target = $this->resolvePath($baseDir, $href);

                if ($target !== '') {
                    $toc[$target] ??= $label;
                }
            }

            if ($toc !== []) {
                break;
            }
        }

        return $toc;
    }

    /**
     * @param  array<string, array{id: string, href: string, media_type: string, properties: string}>  $manifest
     * @return array{contents: string, extension: string}|null
     */
    private function readCover(ZipArchive $zip, DOMXPath $xpath, array $manifest, string $opfDir): ?array
    {
        $coverId = '';

        foreach ($xpath->query("//*[local-name()='metadata']/*[local-name()='meta']") as $meta) {
            if ($meta instanceof DOMElement && $meta->getAttribute('name') === 'cover') {
                $coverId = $meta->getAttribute('content');
                break;
            }
        }

        foreach ($manifest as $item) {
            $isCover = str_contains($item['properties'], 'cover-image')
                || ($coverId !== '' && $item['id'] === $coverId)
                || (str_contains(strtolower($item['id']), 'cover') && str_starts_with($item['media_type'], 'image/'));

            if (! $isCover) {
                continue;
            }

            $path = $this->resolvePath($opfDir, $item['href']);
            $contents = $this->readEntry($zip, $path);

            if ($contents === null) {
                continue;
            }

            $extension = match ($item['media_type']) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'image/svg+xml' => 'svg',
                default => pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg',
            };

            return ['contents' => $contents, 'extension' => $extension];
        }

        return null;
    }

    /**
     * @param  array<int, array{type: string, text: string}>  $blocks
     */
    private function collectBlocks(DOMNode $node, array &$blocks): void
    {
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, ['script', 'style', 'svg', 'head', 'nav'], true)) {
                continue;
            }

            if (in_array($tag, self::BLOCK_TAGS, true) && ! $this->containsBlock($child)) {
                $text = $this->normalizeText($child->textContent);

                if ($text !== '') {
                    $blocks[] = ['type' => $this->blockType($tag), 'text' => $text];
                }

                continue;
            }

            if ($this->containsBlock($child)) {
                $this->collectBlocks($child, $blocks);

                continue;
            }

            $text = $this->normalizeText($child->textContent);

            if ($text !== '') {
                $blocks[] = ['type' => 'p', 'text' => $text];
            }
        }
    }

    private function containsBlock(DOMNode $node): bool
    {
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if (in_array(strtolower($child->nodeName), self::BLOCK_TAGS, true)) {
                return true;
            }

            if ($this->containsBlock($child)) {
                return true;
            }
        }

        return false;
    }

    private function blockType(string $tag): string
    {
        return match ($tag) {
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => $tag,
            'blockquote' => 'blockquote',
            'li' => 'li',
            'pre' => 'pre',
            default => 'p',
        };
    }

    private function firstText(DOMXPath $xpath, string $query): ?string
    {
        $node = $xpath->query($query)->item(0);

        if (! $node) {
            return null;
        }

        $text = $this->normalizeText($node->textContent);

        return $text !== '' ? $text : null;
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\u{00a0}", "\u{200b}", "\u{feff}"], ' ', $text);
        $text = preg_replace('/[ \t\r\n\f\v]+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function readXml(ZipArchive $zip, string $path): ?DOMDocument
    {
        $contents = $this->readEntry($zip, $path);

        return $contents === null ? null : $this->loadDocument($contents);
    }

    private function readEntry(ZipArchive $zip, string $path): ?string
    {
        $path = $this->normalizePath($path);
        $contents = $zip->getFromName($path);

        if ($contents === false) {
            $contents = $zip->getFromName(rawurldecode($path));
        }

        return $contents === false ? null : $contents;
    }

    private function loadDocument(string $contents): ?DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($contents, LIBXML_NONET | LIBXML_NOENT);

        if (! $loaded) {
            $document = new DOMDocument;
            $loaded = $document->loadHTML(
                '<?xml encoding="utf-8" ?>'.$contents,
                LIBXML_NONET | LIBXML_NOENT
            );
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }

    private function normalizePath(string $path): string
    {
        $path = rawurldecode(trim($path));
        $path = preg_replace('/[?#].*$/', '', $path) ?? $path;
        $path = ltrim($path, '/');
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private function resolvePath(string $baseDir, string $href): string
    {
        $href = preg_replace('/[?#].*$/', '', rawurldecode(trim($href))) ?? '';

        if ($href === '') {
            return '';
        }

        if (str_starts_with($href, '/')) {
            return $this->normalizePath($href);
        }

        return $this->normalizePath(($baseDir !== '' ? $baseDir.'/' : '').$href);
    }
}
