<?php

use App\Http\Controllers\AiAccountController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\TranslationController;
use Illuminate\Support\Facades\Route;

Route::get('/', [BookController::class, 'index'])->name('library');
Route::post('/books', [BookController::class, 'store'])->name('books.store');
Route::get('/books/{book}', [BookController::class, 'show'])->name('books.show');
Route::patch('/books/{book}', [BookController::class, 'update'])->name('books.update');
Route::delete('/books/{book}', [BookController::class, 'destroy'])->name('books.destroy');
Route::get('/books/{book}/pages', [PageController::class, 'index'])->name('books.pages');
Route::post('/books/{book}/translations', [TranslationController::class, 'store'])->name('translations.store');

Route::get('/accounts', [AiAccountController::class, 'index'])->name('accounts.index');
Route::post('/accounts', [AiAccountController::class, 'store'])->name('accounts.store');
Route::put('/accounts/{account}', [AiAccountController::class, 'update'])->name('accounts.update');
Route::delete('/accounts/{account}', [AiAccountController::class, 'destroy'])->name('accounts.destroy');
Route::post('/accounts/{account}/sync-models', [AiAccountController::class, 'syncModels'])->name('accounts.sync-models');
Route::post('/accounts/{account}/test', [AiAccountController::class, 'test'])->name('accounts.test');
