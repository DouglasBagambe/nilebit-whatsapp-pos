<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DisconnectAccount
{
    public function __construct(private PosClient $pos, private ChatFlow $flow) {}

    public function handle(object $conversation, object $binding, object $action): array
    {
        if ($action->kind !== 'disconnect_confirm') {
            $this->flow->advance($conversation);
            $controls = (object) ['id' => '', 'binding_id' => $binding->id, 'expires_at' => now()->addMinutes(15)];

            return $this->flow->menu('Disconnect this '.ucfirst($binding->backend).' account from WhatsApp? This disconnects all its linked businesses on this phone. Your POS data and web sign-in stay intact.', [
                $this->flow->row($conversation, $controls, 'disconnect_confirm', 'Disconnect account'),
                $this->flow->row($conversation, $controls, 'menu', 'Keep connected'),
            ], 'Connection options');
        }
        $bindings = DB::table('wa_business_bindings')->where('conversation_id', $conversation->id)->where('backend', $binding->backend)->where('user_id', $binding->user_id)->whereNull('revoked_at')->get();
        $tokens = $bindings->map(fn ($entry) => Crypt::decryptString($entry->token))->unique();
        foreach ($tokens as $token) {
            $this->pos->logout($binding->backend, $token);
        }
        DB::table('wa_business_bindings')->whereIn('id', $bindings->pluck('id'))->update(['revoked_at' => now(), 'token' => Crypt::encryptString(''), 'updated_at' => now()]);
        DB::table('wa_conversations')->where('id', $conversation->id)->update(['binding_id' => null]);
        $this->flow->advance($conversation);

        return $this->flow->text(Str::ucfirst($binding->backend).' account disconnected. No POS records were deleted. Send Menu to select another business or reconnect securely.');
    }
}
