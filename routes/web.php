<?php

use App\Http\Controllers\BusinessLinkController;
use App\Http\Middleware\ProtectBusinessLink;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'NileBit POS WhatsApp gateway', 'status' => 'development']));

Route::middleware([ProtectBusinessLink::class, 'throttle:10,1'])->group(function () {
    Route::get('/link/{token}', [BusinessLinkController::class, 'show'])->name('business-link.show');
    Route::post('/link/{token}/login', [BusinessLinkController::class, 'authenticate'])->name('business-link.authenticate');
    Route::post('/link/{token}/approve', [BusinessLinkController::class, 'approve'])->name('business-link.approve');
});
