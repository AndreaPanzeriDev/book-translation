<?php

namespace Tests\Fixtures;

use ZipArchive;

class EpubFixture
{
    public static function create(string $path): string
    {
        $longParagraph = str_repeat('Il mare restava immobile sotto un cielo di piombo. ', 55);

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->addFromString('META-INF/container.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
  <rootfiles>
    <rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>
  </rootfiles>
</container>
XML);

        $zip->addFromString('OEBPS/content.opf', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="book-id">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:title>Il faro e la tempesta</dc:title>
    <dc:creator>Ada Lovelace</dc:creator>
    <dc:language>en-US</dc:language>
    <meta name="cover" content="cover-image"/>
  </metadata>
  <manifest>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
    <item id="cover-image" href="cover.png" media-type="image/png" properties="cover-image"/>
    <item id="chapter-1" href="chapter1.xhtml" media-type="application/xhtml+xml"/>
    <item id="chapter-2" href="chapter2.xhtml" media-type="application/xhtml+xml"/>
  </manifest>
  <spine>
    <itemref idref="chapter-1"/>
    <itemref idref="chapter-2"/>
  </spine>
</package>
XML);

        $zip->addFromString('OEBPS/nav.xhtml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">
  <head><title>Indice</title></head>
  <body>
    <nav epub:type="toc">
      <ol>
        <li><a href="chapter1.xhtml">Il faro</a></li>
        <li><a href="chapter2.xhtml">La tempesta</a></li>
      </ol>
    </nav>
  </body>
</html>
XML);

        $zip->addFromString('OEBPS/chapter1.xhtml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<html xmlns="http://www.w3.org/1999/xhtml">
  <head><title>Il faro</title></head>
  <body>
    <section>
      <h1>Il faro</h1>
      <p>La lanterna girava lenta nella notte.</p>
      <p>Ogni lampo apriva un cerchio d’acqua.</p>
      <blockquote>Nessuna nave passa senza salutare la luce.</blockquote>
      <p>Il guardiano contava le onde.</p>
    </section>
  </body>
</html>
XML);

        $zip->addFromString('OEBPS/chapter2.xhtml', '<?xml version="1.0" encoding="UTF-8"?>
<html xmlns="http://www.w3.org/1999/xhtml">
  <head><title>La tempesta</title></head>
  <body>
    <h2>La tempesta</h2>
    <p>'.htmlspecialchars($longParagraph, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</p>
  </body>
</html>');

        $zip->addFromString('OEBPS/cover.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nH0AAAAASUVORK5CYII='
        ));

        $zip->close();

        return $path;
    }
}
