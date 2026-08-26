<?php

use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\VocabularyController;
use App\Http\Controllers\WordController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Guest (unauthenticated) — registration & login
// ---------------------------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

// ---------------------------------------------------------------------------
// Authenticated — the whole app is per-user
// ---------------------------------------------------------------------------
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Word search (real dictionary + AI)
    Route::get('/words', [WordController::class, 'index'])->name('words.index');
    Route::get('/words/lookup', [WordController::class, 'lookup'])->name('words.lookup');
    Route::get('/words/example', [WordController::class, 'example'])->name('words.example');
    Route::get('/words/mnemonic', [WordController::class, 'mnemonic'])->name('words.mnemonic');

    // Custom vocabulary library
    Route::get('/vocabulary', [VocabularyController::class, 'index'])->name('vocabulary.index');
    Route::put('/vocabulary/{vocabulary}', [VocabularyController::class, 'update'])->name('vocabulary.update');
    Route::delete('/vocabulary/{vocabulary}', [VocabularyController::class, 'destroy'])->name('vocabulary.destroy');

    // Spaced-repetition review
    Route::get('/review', [ReviewController::class, 'session'])->name('review.session');
    Route::post('/review/{userWord}/grade', [ReviewController::class, 'grade'])->name('review.grade');
    Route::post('/review/{userWord}/resume', [ReviewController::class, 'resume'])->name('review.resume');

    // Quizzes (AI generate / take / submit / AI review)
    Route::get('/quiz/create', [QuizController::class, 'create'])->name('quiz.create');
    Route::post('/quiz', [QuizController::class, 'store'])->name('quiz.store');
    Route::get('/quiz/{quiz}', [QuizController::class, 'show'])->name('quiz.show');
    Route::post('/quiz/{quiz}/submit', [QuizController::class, 'submit'])->name('quiz.submit');
    Route::get('/quiz/{quiz}/review', [QuizController::class, 'review'])->name('quiz.review');

    // ---------------------------------------------------------------------
    // Admin user management (admin-only)
    // ---------------------------------------------------------------------
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');
    });
});
