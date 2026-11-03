<?php

namespace Tests\Feature;

use App\Services\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DisconnectAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_disconnect_requires_confirmation_revokes_only_the_selected_account_and_erases_its_tokens(): void
    {
        config(['whatsapp.backends.retail.url' => 'https://retail.example.test/api']);
        Http::preventStrayRequests();
        Http::fake(['https://retail.example.test/api/auth/logout' => Http::response(['message' => 'Logged out'])]);
        $id = DB::table('wa_conversations')->insertGetId(['sender' => '256700000001', 'phone_number_id' => '12345', 'created_at' => now(), 'updated_at' => now()]);
        $bindingIds = [];
        foreach ([11, 12] as $shop) {
            $bindingIds[] = DB::table('wa_business_bindings')->insertGetId(['conversation_id' => $id, 'backend' => 'retail', 'shop_id' => $shop, 'user_id' => 7, 'name' => 'Shop '.$shop, 'role' => 'owner',
                'token' => Crypt::encryptString('retail-token'), 'created_at' => now(), 'updated_at' => now()]);
        }
        $other = DB::table('wa_business_bindings')->insertGetId(['conversation_id' => $id, 'backend' => 'production', 'shop_id' => 1, 'user_id' => 7, 'name' => 'Factory', 'role' => 'owner',
            'token' => Crypt::encryptString('production-token'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('wa_conversations')->where('id', $id)->update(['binding_id' => $bindingIds[0]]);
        $bot = app(WhatsAppConversation::class);
        $send = fn (array $message = []) => $bot->reply(DB::table('wa_conversations')->find($id), $message);
        $menu = $send();
        $start = collect($menu['interactive']['action']['sections'][0]['rows'])->firstWhere('title', 'Disconnect account')['id'];
        $confirmation = $send(['interactive' => ['list_reply' => ['id' => $start]]]);
        Http::assertNothingSent();
        $this->assertSame(3, DB::table('wa_business_bindings')->whereNull('revoked_at')->count());
        $confirm = collect($confirmation['interactive']['action']['sections'][0]['rows'])->firstWhere('title', 'Disconnect account')['id'];
        $result = $send(['interactive' => ['list_reply' => ['id' => $confirm]]]);

        $this->assertStringContainsString('disconnected', $result['text']['body']);
        $this->assertSame(2, DB::table('wa_business_bindings')->whereNotNull('revoked_at')->count());
        $this->assertNull(DB::table('wa_business_bindings')->find($other)->revoked_at);
        $this->assertSame('', Crypt::decryptString(DB::table('wa_business_bindings')->find($bindingIds[0])->token));
        $this->assertNull(DB::table('wa_conversations')->find($id)->binding_id);
        Http::assertSentCount(1);
    }
}
