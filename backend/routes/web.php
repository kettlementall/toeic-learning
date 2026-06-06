<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\VocabularyController;
use App\Http\Controllers\WordController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// Word search (real dictionary + AI)
Route::get('/words', [WordController::class, 'index'])->name('words.index');
Route::get('/words/lookup', [WordController::class, 'lookup'])->name('words.lookup');
Route::get('/words/example', [WordController::class, 'example'])->name('words.example');

// Custom vocabulary library
Route::get('/vocabulary', [VocabularyController::class, 'index'])->name('vocabulary.index');
Route::put('/vocabulary/{vocabulary}', [VocabularyController::class, 'update'])->name('vocabulary.update');
Route::delete('/vocabulary/{vocabulary}', [VocabularyController::class, 'destroy'])->name('vocabulary.destroy');

// Spaced-repetition review
Route::get('/review', [ReviewController::class, 'session'])->name('review.session');
Route::post('/review/{userWord}/grade', [ReviewController::class, 'grade'])->name('review.grade');

// Quizzes (AI generate / take / submit / AI review)
Route::get('/quiz/create', [QuizController::class, 'create'])->name('quiz.create');
Route::post('/quiz', [QuizController::class, 'store'])->name('quiz.store');
Route::get('/quiz/{quiz}', [QuizController::class, 'show'])->name('quiz.show');
Route::post('/quiz/{quiz}/submit', [QuizController::class, 'submit'])->name('quiz.submit');
Route::get('/quiz/{quiz}/review', [QuizController::class, 'review'])->name('quiz.review');
