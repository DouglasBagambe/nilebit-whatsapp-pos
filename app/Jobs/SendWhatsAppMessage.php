<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 30;

    public array $backoff = [2, 10, 30, 60];

    public function __construct(public int $outboundId) {}

    public function handle(): void
    {
        $conversationId = DB::table('wa_outbound_messages')->where('id', $this->outboundId)->value('conversation_id');
        if ($conversationId === null) {
            return;
        }
        DB::transaction(function () use ($conversationId): void {
            $conversation = DB::table('wa_conversations')->where('id', $conversationId)->lockForUpdate()->firstOrFail();
            $message = DB::table('wa_outbound_messages')->where('id', $this->outboundId)->lockForUpdate()->firstOrFail();
            if ($message->sent_at || $message->abandoned_at) {
                return;
            }
            $phone = (string) config('whatsapp.phone_number_id');
            $version = (string) config('whatsapp.api_version');
            $token = (string) config('whatsapp.access_token');
            if (! config('whatsapp.enabled') || ! preg_match('/^v[0-9]+\.0$/D', $version) || $token === '' || ! ctype_digit($phone)) {
                throw new RuntimeException('WhatsApp delivery has not been configured.');
            }
            $earlierPending = DB::table('wa_outbound_messages')->where('conversation_id', $conversationId)
                ->where('id', '<', $message->id)->whereNull('sent_at')->whereNull('abandoned_at')->exists();
            if ($earlierPending) {
                $this->release(10);

                return;
            }
            if (! $conversation->last_inbound_at || now()->subHours(24)->greaterThan($conversation->last_inbound_at)) {
                DB::table('wa_outbound_messages')->where('id', $message->id)->update(['abandoned_at' => now(), 'updated_at' => now()]);

                return;
            }
            $payload = json_decode(Crypt::decryptString($message->payload), true, flags: JSON_THROW_ON_ERROR);
            if ($payload['type'] === 'document' && isset($payload['_pdf'])) {
                $bytes = base64_decode($payload['_pdf'], true);
                if ($bytes === false || ! str_starts_with($bytes, '%PDF-') || strlen($bytes) > 2097152) {
                    throw new RuntimeException('The queued PDF is invalid.');
                }
                $upload = Http::withToken($token)->timeout(20)->connectTimeout(5)->withOptions(['allow_redirects' => false])
                    ->attach('file', $bytes, $payload['document']['filename'], ['Content-Type' => 'application/pdf'])
                    ->post("https://graph.facebook.com/{$version}/{$phone}/media", ['messaging_product' => 'whatsapp', 'type' => 'application/pdf']);
                if (! $upload->successful() || ! is_string($upload->json('id')) || ! ctype_digit($upload->json('id'))) {
                    throw new RuntimeException('WhatsApp did not accept the PDF upload (HTTP '.$upload->status().').');
                }
                $payload['document']['id'] = $upload->json('id');
                unset($payload['_pdf']);
            }
            $response = Http::withToken($token)->timeout(15)->connectTimeout(5)->withOptions(['allow_redirects' => false])
                ->post("https://graph.facebook.com/{$version}/{$phone}/messages", ['messaging_product' => 'whatsapp', 'to' => $conversation->sender] + $payload);
            if (! $response->successful() || ! is_string($response->json('messages.0.id'))) {
                $code = $response->json('error.code');
                $subcode = $response->json('error.error_subcode');
                throw new RuntimeException(sprintf(
                    'WhatsApp did not confirm delivery acceptance (HTTP %d; code %s; subcode %s).',
                    $response->status(),
                    is_int($code) ? (string) $code : 'unknown',
                    is_int($subcode) ? (string) $subcode : 'unknown',
                ));
            }
            DB::table('wa_outbound_messages')->where('id', $message->id)->update([
                'provider_message_id' => $response->json('messages.0.id'), 'sent_at' => now(), 'updated_at' => now(),
            ]);
        });
    }
}
