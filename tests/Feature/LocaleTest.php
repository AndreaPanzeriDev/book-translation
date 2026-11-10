<?php

use App\Models\Book;
use Inertia\Testing\AssertableInertia as Assert;

function localeTestBook(): Book
{
    return Book::create([
        'title' => 'Libro esistente',
        'author' => 'Ada Lovelace',
        'language' => 'it',
        'original_filename' => 'libro.epub',
        'file_path' => 'books/libro.epub',
        'page_char_limit' => 1500,
        'target_language' => 'en',
    ]);
}

it('usa l’italiano come lingua predefinita', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'it')
            ->where('availableLocales', ['it', 'en']));
});

it('rileva la lingua inglese dal browser', function () {
    $this->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

it('preferisce il cookie alla lingua del browser', function () {
    $this->withCookie('locale', 'it')
        ->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'it'));
});

it('ignora una lingua non supportata e usa il browser', function () {
    $this->withCookie('locale', 'fr')
        ->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

it('salva la lingua scelta in un cookie di un anno', function () {
    $response = $this->patchJson(route('locale.update'), ['locale' => 'en']);

    $response->assertOk()->assertJsonPath('locale', 'en');
    $response->assertCookie('locale', 'en');

    $rawCookie = $response->getCookie('locale', false);

    expect($rawCookie)->not->toBeNull()
        ->and($rawCookie->getExpiresTime())->toBeGreaterThan(now()->addDays(364)->getTimestamp());
});

it('rifiuta una lingua non supportata', function () {
    $response = $this->withCredentials()
        ->withCookie('locale', 'en')
        ->patchJson(route('locale.update'), ['locale' => 'fr']);

    $response->assertUnprocessable()
        ->assertJsonPath('errors.locale.0', 'The selected language is invalid.')
        ->assertCookieMissing('locale');
});

it('localizza i messaggi flash in inglese', function () {
    $book = localeTestBook();

    $this->withCookie('locale', 'en')
        ->delete(route('books.destroy', $book))
        ->assertRedirect()
        ->assertSessionHas('success', 'Book deleted: Libro esistente.');
});

it('localizza i messaggi di validazione in inglese', function () {
    $book = localeTestBook();

    $this->withCredentials()
        ->withCookie('locale', 'en')
        ->patchJson(route('books.update', $book), [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.target_language.0', 'The target language field is required.');
});
