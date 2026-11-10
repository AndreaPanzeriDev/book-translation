<?php

namespace App\Services\Export;

use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class EpubExporter
{
    /**
     * @param  array{
     *     title: string,
     *     author: ?string,
     *     language: string,
     *     cover_path: ?string,
     *     cover_extension: ?string,
     *     cover_mime: ?string,
     *     chapters: array<int, array{
     *         id: int,
     *         position: int,
     *         title: string,
     *         blocks: array<int, array{type: string, text: string}>
     *     }>
     * }  $book
     */
    public function export(array $book, string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the EPUB archive.');
        }

        try {
            $zip->addFromString('mimetype', 'application/epub+zip');
            $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);

            $zip->addFromString('META-INF/container.xml', $this->containerDocument());
            $zip->addFromString('OEBPS/style.css', $this->stylesheet());

            $hasCover = $book['cover_path'] !== null
                && $book['cover_extension'] !== null
                && $book['cover_mime'] !== null
                && is_file($book['cover_path']);

            if ($hasCover) {
                $zip->addFile($book['cover_path'], 'OEBPS/cover.'.$book['cover_extension']);
                $zip->addFromString('OEBPS/cover.xhtml', $this->coverDocument($book));
            }

            $chapterTitles = [];

            foreach ($book['chapters'] as $index => $chapter) {
                $chapterTitles[$index] = BlockMarkup::chapterTitle($chapter['blocks'], $chapter['title']);
                $zip->addFromString(
                    'OEBPS/chapter-'.($index + 1).'.xhtml',
                    $this->chapterDocument($chapter, $book['language']),
                );
            }

            $zip->addFromString('OEBPS/nav.xhtml', $this->navigationDocument($book, $chapterTitles));
            $zip->addFromString('OEBPS/content.opf', $this->packageDocument($book, $hasCover, count($chapterTitles)));
        } finally {
            $zip->close();
        }
    }

    private function containerDocument(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
  <rootfiles>
    <rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>
  </rootfiles>
</container>
XML;
    }

    /**
     * @param  array{title: string, author: ?string, language: string, cover_extension: ?string, cover_mime: ?string}  $book
     */
    private function coverDocument(array $book): string
    {
        $title = BlockMarkup::escape($book['title']);

        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="{$book['language']}">
  <head>
    <title>{$title}</title>
    <link rel="stylesheet" type="text/css" href="style.css"/>
  </head>
  <body class="cover-page">
    <div class="cover">
      <img src="cover.{$book['cover_extension']}" alt="{$title}"/>
    </div>
  </body>
</html>
XML;
    }

    /**
     * @param  array{title: string, language: string, blocks: array<int, array{type: string, text: string}>}  $chapter
     */
    private function chapterDocument(array $chapter, string $language): string
    {
        $title = BlockMarkup::escape(BlockMarkup::chapterTitle($chapter['blocks'], $chapter['title']));
        $body = BlockMarkup::html($chapter['blocks']);

        if (! $this->hasHeading($chapter['blocks'])) {
            $body = '<h1>'.$title.'</h1>'."\n".$body;
        }

        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="{$language}">
  <head>
    <title>{$title}</title>
    <link rel="stylesheet" type="text/css" href="style.css"/>
  </head>
  <body>
{$body}
  </body>
</html>
XML;
    }

    /**
     * @param  array{title: string, author: ?string, language: string, cover_path: ?string, cover_extension: ?string, cover_mime: ?string}  $book
     * @param  array<int, string>  $chapterTitles
     */
    private function navigationDocument(array $book, array $chapterTitles): string
    {
        $items = [];

        foreach ($chapterTitles as $index => $title) {
            $items[] = '      <li><a href="chapter-'.($index + 1).'.xhtml">'.BlockMarkup::escape($title).'</a></li>';
        }

        $itemsHtml = implode("\n", $items);
        $title = BlockMarkup::escape($book['title']);
        $tocTitle = BlockMarkup::escape(ExportText::tocTitle($book['language']));

        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="{$book['language']}">
  <head>
    <title>{$title}</title>
    <link rel="stylesheet" type="text/css" href="style.css"/>
  </head>
  <body>
    <nav epub:type="toc" id="toc">
      <h1>{$tocTitle}</h1>
      <ol>
{$itemsHtml}
      </ol>
    </nav>
  </body>
</html>
XML;
    }

    /**
     * @param  array{title: string, author: ?string, language: string, cover_extension: ?string, cover_mime: ?string}  $book
     */
    private function packageDocument(array $book, bool $hasCover, int $chapterCount): string
    {
        $identifier = 'urn:uuid:'.Str::uuid();
        $modified = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $title = BlockMarkup::escape($book['title']);
        $language = BlockMarkup::escape($book['language']);
        $author = $book['author']
            ? "\n    <dc:creator>".BlockMarkup::escape($book['author']).'</dc:creator>'
            : '';

        $manifest = [
            '    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>',
            '    <item id="style" href="style.css" media-type="text/css"/>',
        ];
        $spine = [];

        if ($hasCover) {
            $manifest[] = '    <item id="cover-page" href="cover.xhtml" media-type="application/xhtml+xml"/>';
            $manifest[] = '    <item id="cover-image" href="cover.'.$book['cover_extension'].'" media-type="'.$book['cover_mime'].'" properties="cover-image"/>';
            $spine[] = '    <itemref idref="cover-page"/>';
        }

        for ($index = 1; $index <= $chapterCount; $index++) {
            $manifest[] = '    <item id="chapter-'.$index.'" href="chapter-'.$index.'.xhtml" media-type="application/xhtml+xml"/>';
            $spine[] = '    <itemref idref="chapter-'.$index.'"/>';
        }

        $manifestHtml = implode("\n", $manifest);
        $spineHtml = implode("\n", $spine);
        $coverMeta = $hasCover
            ? "\n    <meta name=\"cover\" content=\"cover-image\"/>"
            : '';

        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="book-id" xml:lang="{$language}">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="book-id">{$identifier}</dc:identifier>
    <dc:title>{$title}</dc:title>{$author}
    <dc:language>{$language}</dc:language>
    <meta property="dcterms:modified">{$modified}</meta>{$coverMeta}
  </metadata>
  <manifest>
{$manifestHtml}
  </manifest>
  <spine>
{$spineHtml}
  </spine>
</package>
XML;
    }

    private function stylesheet(): string
    {
        return <<<'CSS'
html {
  font-size: 100%;
}

body {
  margin: 1.25em;
  font-family: Georgia, "Times New Roman", serif;
  line-height: 1.55;
  text-align: justify;
  color: #1c1917;
}

h1, h2, h3, h4, h5, h6 {
  font-family: Georgia, "Times New Roman", serif;
  line-height: 1.2;
  text-align: left;
  margin: 1.4em 0 0.6em;
}

h1 { font-size: 1.8em; }
h2 { font-size: 1.5em; }
h3 { font-size: 1.3em; }
h4, h5, h6 { font-size: 1.1em; }

p {
  margin: 0 0 0.8em;
  text-indent: 1.2em;
}

h1 + p, h2 + p, h3 + p, h4 + p, h5 + p, h6 + p,
blockquote + p, ul + p, pre + p {
  text-indent: 0;
}

blockquote {
  margin: 1em 1.5em;
  font-style: italic;
}

ul, ol {
  margin: 0 0 0.8em 1.4em;
  padding: 0;
}

li {
  margin: 0 0 0.35em;
}

pre {
  white-space: pre-wrap;
  font-family: "Courier New", monospace;
  font-size: 0.9em;
  text-align: left;
}

.cover-page {
  margin: 0;
  padding: 0;
  text-align: center;
}

.cover {
  margin: 0;
  padding: 0;
  text-align: center;
}

.cover img {
  max-width: 100%;
  max-height: 100%;
}
CSS;
    }

    /**
     * @param  array<int, array{type: string, text: string}>  $blocks
     */
    private function hasHeading(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (str_starts_with($block['type'], 'h')) {
                return true;
            }
        }

        return false;
    }
}
