<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Shared persisted state and Meta-safe controls for guided tasks. */
class ChatFlow
{
    public function pending(object $conversation): ?object
    {
        return DB::table('wa_operations')->where('conversation_id', $conversation->id)->where('binding_id', $conversation->binding_id)
            ->whereNotIn('step', ['completed', 'cancelled', 'failed'])->where('expires_at', '>', now())->latest('created_at')->first();
    }

    public function create(object $conversation, string $kind, string $step, array $state): object
    {
        $id = (string) Str::uuid();
        DB::table('wa_operations')->insert(['id' => $id, 'conversation_id' => $conversation->id, 'binding_id' => $conversation->binding_id,
            'kind' => $kind, 'step' => $step, 'state' => $this->encrypt($state), 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('wa_operations')->find($id);
    }

    public function save(object $operation, string $step, array $state): void
    {
        DB::table('wa_operations')->where('id', $operation->id)->update(['step' => $step, 'state' => $this->encrypt($state), 'updated_at' => now()]);
        $operation->step = $step;
        $operation->state = $this->encrypt($state);
    }

    public function decode(?string $payload): array
    {
        return $payload ? json_decode(Crypt::decryptString($payload), true, flags: JSON_THROW_ON_ERROR) : [];
    }

    private function encrypt(array $data): string
    {
        return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function advance(object $conversation): void
    {
        DB::table('wa_conversations')->where('id', $conversation->id)->increment('version');
        $conversation->version++;
    }

    public function row(object $conversation, object $operation, string $kind, string $title, array $data = [], ?string $description = null): array
    {
        $id = (string) Str::uuid();
        DB::table('wa_actions')->insert(['id' => $id, 'conversation_id' => $conversation->id, 'version' => $conversation->version,
            'kind' => $kind, 'binding_id' => $operation->binding_id, 'payload' => $this->encrypt($data + ['operation' => $operation->id]),
            'expires_at' => $operation->expires_at, 'created_at' => now(), 'updated_at' => now()]);

        return array_filter(['id' => $id, 'title' => Str::limit($title, 24, ''), 'description' => $description ? Str::limit($description, 72, '') : null], fn ($value) => $value !== null);
    }

    public function menu(string $body, array $rows, string $button = 'Choose'): array
    {
        if (count($rows) > 10 || mb_strlen($body) > 1024) {
            throw new \LogicException('Guided menu exceeds WhatsApp limits.');
        }

        return ['type' => 'interactive', 'interactive' => ['type' => 'list', 'body' => ['text' => $body],
            'action' => ['button' => Str::limit($button, 20, ''), 'sections' => [['title' => 'Options', 'rows' => $rows]]]]];
    }

    public function text(string $body): array
    {
        return ['type' => 'text', 'text' => ['body' => Str::limit($body, 4096, '')]];
    }
}
