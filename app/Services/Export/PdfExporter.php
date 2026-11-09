<?php

namespace App\Services\Export;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;

class PdfExporter
{
    private const RTL_LANGUAGES = ['ar', 'he', 'fa', 'ur'];

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
        $mpdf = $this->makeMpdf();
        $mpdf->SetTitle($book['title']);
        $mpdf->SetAuthor($book['author'] ?? '');
        $mpdf->SetCreator('Book Translation Studio');
        $mpdf->SetHTMLFooter(
            '<div style="text-align:center;font-size:8pt;color:#777;">{PAGENO}</div>',
        );

        if (in_array(strtolower($book['language']), self::RTL_LANGUAGES, true)) {
            $mpdf->SetDirectionality('rtl');
        }

        $mpdf->h2toc = ['H1' => 0, 'H2' => 1, 'H3' => 2];
        $mpdf->h2bookmarks = ['H1' => 0, 'H2' => 1, 'H3' => 2];

        $mpdf->WriteHTML($this->stylesheet());
        $mpdf->WriteHTML($this->titlePage($book));

        $tocTitle = BlockMarkup::escape(ExportText::tocTitle($book['language']));

        $mpdf->TOCpagebreakByArray([
            'TOCusePaging' => true,
            'TOCuseLinking' => true,
            'toc_preHTML' => '<div class="toc-title">'.$tocTitle.'</div>',
        ]);

        foreach ($book['chapters'] as $index => $chapter) {
            if ($index > 0) {
                $mpdf->AddPage();
            }

            $mpdf->WriteHTML($this->chapterHtml($chapter));
        }

        $mpdf->Output($path, Destination::FILE);
    }

    private function makeMpdf(): Mpdf
    {
        $tempDir = storage_path('app/mpdf');

        if (! is_dir($tempDir) && ! mkdir($tempDir, 0775, true) && ! is_dir($tempDir)) {
            throw new RuntimeException('Unable to create the PDF temporary directory.');
        }

        return new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A5',
            'margin_left' => 18,
            'margin_right' => 18,
            'margin_top' => 16,
            'margin_bottom' => 18,
            'margin_header' => 8,
            'margin_footer' => 8,
            'tempDir' => $tempDir,
            'default_font' => 'dejavuserif',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'useSubstitutions' => true,
        ]);
    }

    /**
     * @param  array{title: string, author: ?string, language: string, cover_path: ?string}  $book
     */
    private function titlePage(array $book): string
    {
        $title = BlockMarkup::escape($book['title']);
        $author = $book['author'] ? BlockMarkup::escape($book['author']) : '';
        $language = ExportText::displayLanguage($book['language']);
        $translationInto = BlockMarkup::escape(
            ExportText::translationInto($book['language'], $language),
        );

        $cover = '';

        if ($book['cover_path'] && is_file($book['cover_path'])) {
            $coverPath = BlockMarkup::escape($book['cover_path']);
            $cover = <<<HTML
<div class="cover"><img src="{$coverPath}" alt="{$title}"/></div>
HTML;
        }

        $authorHtml = $author !== ''
            ? '<div class="book-author">'.$author.'</div>'
            : '';
        $titleSize = $this->titleFontSize($book['title']);

        return <<<HTML
<div class="title-page">
  {$cover}
  <div class="book-title" style="font-size: {$titleSize};">{$title}</div>
  {$authorHtml}
  <div class="book-translation">{$translationInto}</div>
</div>
HTML;
    }

    /**
     * @param  array{title: string, blocks: array<int, array{type: string, text: string}>}  $chapter
     */
    private function chapterHtml(array $chapter): string
    {
        $title = BlockMarkup::escape($chapter['title']);
        $body = BlockMarkup::html($chapter['blocks']);

        if (! $this->hasHeading($chapter['blocks'])) {
            $body = '<h1>'.$title.'</h1>'."\n".$body;
        }

        return '<section class="chapter">'."\n".$body."\n".'</section>';
    }

    private function stylesheet(): string
    {
        return <<<'HTML'
<style>
  body {
    font-family: dejavuserif, serif;
    font-size: 10.5pt;
    line-height: 1.55;
    color: #1c1917;
  }

  .title-page {
    text-align: center;
    page-break-after: always;
  }

  .title-page .cover {
    margin: 0 0 8mm;
    padding: 0;
    text-align: center;
  }

  .title-page .cover img {
    max-width: 100%;
    max-height: 90mm;
  }

  .book-title {
    margin-top: 10mm;
    font-size: 24pt;
    font-weight: bold;
    line-height: 1.2;
  }

  .book-author {
    margin-top: 4mm;
    font-size: 12pt;
    color: #444444;
  }

  .book-translation {
    margin-top: 3mm;
    font-size: 10pt;
    color: #777777;
  }

  .toc-title {
    margin-bottom: 8mm;
    font-size: 18pt;
    font-weight: bold;
  }

  h1 {
    margin: 0 0 6mm;
    font-size: 18pt;
    line-height: 1.2;
    page-break-after: avoid;
  }

  h2 {
    margin: 6mm 0 3mm;
    font-size: 14pt;
    line-height: 1.25;
    page-break-after: avoid;
  }

  h3 {
    margin: 5mm 0 3mm;
    font-size: 12pt;
    line-height: 1.3;
    page-break-after: avoid;
  }

  h4, h5, h6 {
    margin: 4mm 0 2mm;
    font-size: 10.5pt;
    line-height: 1.35;
    page-break-after: avoid;
  }

  p {
    margin: 0 0 2.4mm;
    text-align: justify;
    text-indent: 5mm;
  }

  h1 + p, h2 + p, h3 + p, h4 + p, h5 + p, h6 + p,
  blockquote + p, ul + p, pre + p {
    text-indent: 0;
  }

  blockquote {
    margin: 0 8mm 3mm;
    font-style: italic;
  }

  ul, ol {
    margin: 0 0 3mm 6mm;
    padding: 0;
  }

  li {
    margin-bottom: 1mm;
  }

  pre {
    white-space: pre-wrap;
    font-family: dejavusansmono, monospace;
    font-size: 9pt;
    text-align: left;
  }
</style>
HTML;
    }

    private function titleFontSize(string $title): string
    {
        return match (true) {
            mb_strlen($title) <= 60 => '24pt',
            mb_strlen($title) <= 120 => '18pt',
            default => '14pt',
        };
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
