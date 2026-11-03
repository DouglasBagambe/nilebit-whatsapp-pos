<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessInboundMessage;
use App\Services\QueueWakeup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $token = (string) config('whatsapp.verify_token');
        abort_unless(config('whatsapp.enabled') && $token !== '', 503);
        abort_unless($request->query('hub_mode') === 'subscribe'
            && hash_equals($token, (string) $request->query('hub_verify_token')), 403);
        $challenge = (string) $request->query('hub_challenge');
        abort_unless(preg_match('/^[0-9]{1,100}$/D', $challenge), 400);

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request): JsonResponse
    {
        $secret = (string) config('whatsapp.app_secret');
        $phone = (string) config('whatsapp.phone_number_id');
        abort_unless(config('whatsapp.enabled') && $secret !== '' && $phone !== '', 503);
        abort_if(strlen($request->getContent()) > 1048576, 413);
        $signature = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        abort_unless(hash_equals($signature, (string) $request->header('X-Hub-Signature-256')), 403);
        $data = $request->validate(['object' => ['required', 'in:whatsapp_business_account'], 'entry' => ['required', 'array', 'max:100']]);
        $eventIds = [];
        foreach ($data['entry'] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                if (($change['field'] ?? null) !== 'messages' || ($value['metadata']['phone_number_id'] ?? null) !== $phone) {
                    continue;
                }
                foreach ($value['messages'] ?? [] as $message) {
                    if (! is_array($message) || ! is_string($message['id'] ?? null) || strlen($message['id']) > 255
                        || ! preg_match('/^[0-9]{6,20}$/D', (string) ($message['from'] ?? ''))
                        || ! ctype_digit((string) ($message['timestamp'] ?? ''))
                        || (int) $message['timestamp'] > now()->addMinutes(5)->timestamp
                        || ! in_array($message['type'] ?? null, ['text', 'interactive'], true)) {
                        continue;
                    }
                    $id = DB::transaction(function () use ($message, $phone): int {
                        DB::table('wa_conversations')->insertOrIgnore([
                            'sender' => $message['from'], 'phone_number_id' => $phone,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $conversation = DB::table('wa_conversations')->where('sender', $message['from'])->where('phone_number_id', $phone)->firstOrFail();
                        DB::table('wa_inbound_events')->insertOrIgnore([
                            'conversation_id' => $conversation->id, 'message_id' => $message['id'], 'phone_number_id' => $phone,
                            'payload' => Crypt::encryptString(json_encode($message, JSON_THROW_ON_ERROR)), 'created_at' => now(), 'updated_at' => now(),
                        ]);

                        return DB::table('wa_inbound_events')->where('phone_number_id', $phone)->where('message_id', $message['id'])->value('id');
                    });
                    $eventIds[] = $id;
                }
            }
        }
        foreach (array_unique($eventIds) as $id) {
            ProcessInboundMessage::dispatch($id);
        }

        if ($eventIds !== []) {
            app(QueueWakeup::class)->notify();
        }

        return response()->json(['received' => true]);
    }
}
