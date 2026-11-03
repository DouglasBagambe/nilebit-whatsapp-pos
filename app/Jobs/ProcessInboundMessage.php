<?php

namespace App\Jobs;

use App\Services\WhatsAppConversation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcessInboundMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $eventId) {}

    public function handle(WhatsAppConversation $bot): void
    {
        $outboundId = DB::transaction(function () use ($bot): int {
            $event = DB::table('wa_inbound_events')->where('id', $this->eventId)->lockForUpdate()->firstOrFail();
            $existing = DB::table('wa_outbound_messages')->where('inbound_event_id', $event->id)->first();
            if ($existing) {
                return $existing->id;
            }
            $conversation = DB::table('wa_conversations')->where('id', $event->conversation_id)->lockForUpdate()->firstOrFail();
            $message = json_decode(Crypt::decryptString($event->payload), true, flags: JSON_THROW_ON_ERROR);
            $occurredAt = CarbonImmutable::createFromTimestampUTC((int) $message['timestamp']);
            try {
                $reply = $conversation->last_inbound_at && $occurredAt->lessThan($conversation->last_inbound_at)
                    ? ['type' => 'text', 'text' => ['body' => 'A delayed message arrived after your more recent reply, so it was not applied. Please use the latest options, or send Menu.']]
                    : $bot->reply($conversation, $message);
            } catch (ValidationException $e) {
                $reply = ['type' => 'text', 'text' => ['body' => (string) collect($e->errors())->flatten()->first()]];
            }
            $id = DB::table('wa_outbound_messages')->insertGetId([
                'inbound_event_id' => $event->id, 'conversation_id' => $conversation->id,
                'payload' => Crypt::encryptString(json_encode($reply, JSON_THROW_ON_ERROR)), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('wa_inbound_events')->where('id', $event->id)->update(['processed_at' => now()]);
            if (! $conversation->last_inbound_at || $occurredAt->greaterThan($conversation->last_inbound_at)) {
                DB::table('wa_conversations')->where('id', $conversation->id)->update(['last_inbound_at' => $occurredAt, 'updated_at' => now()]);
            }

            return $id;
        });
        SendWhatsAppMessage::dispatch($outboundId);
    }
}
