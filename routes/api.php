<?php

use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify'])->name('whatsapp.verify');
Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'receive'])->name('whatsapp.receive');
