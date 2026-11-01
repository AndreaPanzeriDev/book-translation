<?php

use App\Services\Epub\EpubParser;
use App\Services\Epub\InvalidEpubException;
use Tests\Fixtures\EpubFixture;

it('estrae metadati, indice e blocchi di testo dall’EPUB', function () {
    $path = sys_get_temp_dir().'/epub-'.uniqid().'.epub';
    EpubFixture::create($path);

    $result = (new EpubParser)->parse($path);

    expect($result['title'])->toBe('Il faro e la tempesta')
        ->and($result['author'])->toBe('Ada Lovelace')
        ->and($result['language'])->toBe('en')
        ->and($result['chapters'])->toHaveCount(2)
        ->and($result['chapters'][0]['title'])->toBe('Il faro')
        ->and($result['chapters'][0]['blocks'][0])->toMatchArray(['type' => 'h1', 'text' => 'Il faro'])
        ->and($result['chapters'][0]['blocks'][3])->toMatchArray([
            'type' => 'blockquote',
            'text' => 'Nessuna nave passa senza salutare la luce.',
        ])
        ->and($result['cover'])->not->toBeNull()
        ->and($result['cover']['extension'])->toBe('png');

    unlink($path);
});

it('rifiuta un file che non è un EPUB valido', function () {
    $path = tempnam(sys_get_temp_dir(), 'not-epub');
    file_put_contents($path, 'non sono un epub');

    expect(fn () => (new EpubParser)->parse($path))
        ->toThrow(InvalidEpubException::class);

    unlink($path);
});
