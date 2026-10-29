<?php

use Illuminate\Support\Facades\Route;
use Osos\Gaith\Sdk\Laravel\Http\GaithChatController;

/*
 * Routes for the <gaith-chat> widget. Loaded only when gaith-chatbot.widget.enabled
 * is true; prefix and middleware come from config so each host app puts them
 * behind its own authentication.
 */
Route::group([
    'prefix' => config('gaith-chatbot.widget.prefix', 'api/gaith'),
    'middleware' => config('gaith-chatbot.widget.middleware', ['api', 'auth']),
], function () {
    Route::get('config', [GaithChatController::class, 'config'])->name('gaith-chat.config');
    Route::post('stream', [GaithChatController::class, 'stream'])->name('gaith-chat.stream');
    Route::post('attachments', [GaithChatController::class, 'upload'])->name('gaith-chat.upload');
    Route::get('conversations/{conversation}/messages', [GaithChatController::class, 'messages'])
        ->name('gaith-chat.messages');
    Route::get('files/{artifact}', [GaithChatController::class, 'file'])->name('gaith-chat.file');
});
