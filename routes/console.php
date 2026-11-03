<?php

use App\Jobs\ProcessInboundMessage;
use App\Jobs\SendWhatsAppMessage;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('whatsapp:recover', function () {
    DB::table('wa_inbound_events')->whereNull('processed_at')->orderBy('id')->chunkById(100, function ($events) {
        foreach ($events as $event) {
            ProcessInboundMessage::dispatch($event->id);
        }
    });
    DB::table('wa_outbound_messages')->whereNull('sent_at')->whereNull('abandoned_at')->orderBy('id')->chunkById(100, function ($messages) {
        foreach ($messages as $message) {
            SendWhatsAppMessage::dispatch($message->id);
        }
    });
    $this->info('Pending WhatsApp work queued.');
})->purpose('Recover persisted messages after a queue interruption');
