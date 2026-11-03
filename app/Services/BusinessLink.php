<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BusinessLink
{
    public function issue(int $conversationId): string
    {
        if (! app()->environment(['local', 'testing'])) {
            abort_unless(parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https', 503, 'Secure linking is not configured.');
        }
        $token = Str::random(64);
        DB::table('wa_link_requests')->insert(['conversation_id' => $conversationId, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now()]);

        return rtrim((string) config('app.url'), '/').route('business-link.show', ['token' => $token], absolute: false);
    }

    public function find(string $token, bool $lock = false): object
    {
        abort_unless(preg_match('/^[a-zA-Z0-9]{64}$/D', $token), 404);
        $query = DB::table('wa_link_requests')->where('token_hash', hash('sha256', $token));
        if ($lock) {
            $query->lockForUpdate();
        }
        $link = $query->firstOrFail();
        abort_if($link->consumed_at || now()->greaterThan($link->expires_at), 410, 'This link has expired or was already used. Send Menu in WhatsApp for a fresh link.');

        return $link;
    }
}
