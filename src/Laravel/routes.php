<?php

use Illuminate\Support\Facades\Route;
use Osos\Gaith\Sdk\Laravel\Http\GaithChatController;

/*
 * The chat box script itself. Outside the sign-in group on purpose: a <script> tag cannot
 * send the host's Authorization header, and the file holds nothing private.
 */
Route::get(
    trim(config('gaith-chatbot.widget.prefix', 'api/gaith'), '/') . '/gaith-chat.js',
    [GaithChatController::class, 'script']
)->name('gaith-chat.script');

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
    Route::get('status', [GaithChatController::class, 'status'])->name('gaith-chat.status');
    Route::post('stream', [GaithChatController::class, 'stream'])->name('gaith-chat.stream');
    Route::post('attachments', [GaithChatController::class, 'upload'])->name('gaith-chat.upload');
    Route::get('conversations/{conversation}/messages', [GaithChatController::class, 'messages'])
        ->name('gaith-chat.messages');
    Route::get('files/{artifact}', [GaithChatController::class, 'file'])->name('gaith-chat.file');
});
