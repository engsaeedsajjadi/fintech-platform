<?php

declare(strict_types=1);

use App\Domain\Meeting\Http\Controllers\MeetingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/meetings')->name('api.meetings.')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/', [MeetingController::class, 'index'])->name('index');
    Route::post('/', [MeetingController::class, 'store'])->name('store');
    Route::get('/availability', [MeetingController::class, 'availability'])->name('availability');
    Route::get('/{meeting}', [MeetingController::class, 'show'])->name('show');
    Route::post('/{meeting}/cancel', [MeetingController::class, 'cancel'])->name('cancel');
    Route::post('/{meeting}/respond', [MeetingController::class, 'respond'])->name('respond');
    Route::post('/{meeting}/reschedule', [MeetingController::class, 'reschedule'])->name('reschedule');
    Route::post('/{meeting}/join', [MeetingController::class, 'join'])->name('join');
    Route::get('/{meeting}/messages', [MeetingController::class, 'messages'])->name('messages.index');
    Route::post('/{meeting}/messages', [MeetingController::class, 'storeMessage'])->name('messages.store');
});
