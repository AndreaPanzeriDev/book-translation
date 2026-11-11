<?php

use App\Http\Controllers\AiAccountController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\BookExportController;
use App\Http\Controllers\BookReaderController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\TranslationBatchController;
use App\Http\Controllers\TranslationController;
use App\Http\Controllers\TranslationCoverageController;
use Illuminate\Support\Facades\Route;

Route::patch('/locale', [LocaleController::class, 'update'])->name('locale.update');

Route::get('/', [BookController::class, 'index'])->name('library');
Route::post('/books', [BookController::class, 'store'])->name('books.store');
Route::get('/books/{book}', [BookController::class, 'show'])->name('books.show');
Route::patch('/books/{book}', [BookController::class, 'update'])->name('books.update');
Route::delete('/books/{book}', [BookController::class, 'destroy'])->name('books.destroy');
Route::get('/books/{book}/pages', [PageController::class, 'index'])->name('books.pages');
Route::get('/books/{book}/reader', [BookReaderController::class, 'show'])->name('books.reader');
Route::get('/books/{book}/reader/index', [BookReaderController::class, 'index'])->name('books.reader.index');
Route::get('/books/{book}/reader/pages', [BookReaderController::class, 'pages'])->name('books.reader.pages');
Route::get('/books/{book}/translation-coverage', TranslationCoverageController::class)->name('books.translation-coverage');
Route::post('/books/{book}/export', BookExportController::class)->name('books.export');
Route::post('/books/{book}/translations', [TranslationController::class, 'store'])->name('translations.store');
Route::post('/books/{book}/translation-batches', [TranslationBatchController::class, 'store'])->name('translation-batches.store');

Route::get('/translation-batches', [TranslationBatchController::class, 'index'])->name('translation-batches.index');
Route::get('/translation-batches/{batch}', [TranslationBatchController::class, 'show'])->name('translation-batches.show');
Route::post('/translation-batches/{batch}/cancel', [TranslationBatchController::class, 'cancel'])->name('translation-batches.cancel');
Route::post('/translation-batches/{batch}/retry', [TranslationBatchController::class, 'retry'])->name('translation-batches.retry');

Route::get('/accounts', [AiAccountController::class, 'index'])->name('accounts.index');
Route::post('/accounts', [AiAccountController::class, 'store'])->name('accounts.store');
Route::put('/accounts/{account}', [AiAccountController::class, 'update'])->name('accounts.update');
Route::delete('/accounts/{account}', [AiAccountController::class, 'destroy'])->name('accounts.destroy');
Route::post('/accounts/{account}/sync-models', [AiAccountController::class, 'syncModels'])->name('accounts.sync-models');
Route::post('/accounts/{account}/test', [AiAccountController::class, 'test'])->name('accounts.test');
