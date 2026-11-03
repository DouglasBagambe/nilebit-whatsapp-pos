<?php

namespace Tests\Feature;

use App\Services\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GatewaySecurityTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(string $sender = '256700000001'): int
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'whatsapp.backends.retail.url' => 'https://retail.example.test/api']);
        Http::preventStrayRequests();

        return DB::table('wa_conversations')->insertGetId(['sender' => $sender, 'phone_number_id' => '12345', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function membership(): array
    {
        return ['id' => 7, 'status' => 'active', 'shop_roles' => [
            ['shop_id' => 11, 'role' => 'owner', 'shop' => ['id' => 11, 'name' => 'Retail Shop', 'status' => 'active']],
        ]];
    }

    public function test_link_verifies_membership_and_encrypts_credentials(): void
    {
        $id = $this->conversation();
        Http::fake(['https://retail.example.test/api/auth/me' => Http::response($this->membership())]);

        app(WhatsAppConversation::class)->link($id, 'retail', 'private-pos-token', 11);

        $binding = DB::table('wa_business_bindings')->sole();
        $this->assertSame('private-pos-token', Crypt::decryptString($binding->token));
        $this->assertNotSame('private-pos-token', $binding->token);
        $this->assertSame(11, $binding->shop_id);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer private-pos-token'));
    }

    public function test_link_rejects_a_shop_outside_verified_membership(): void
    {
        $id = $this->conversation();
        Http::fake(['https://retail.example.test/api/auth/me' => Http::response($this->membership())]);

        try {
            app(WhatsAppConversation::class)->link($id, 'retail', 'private-pos-token', 999);
            $this->fail('Foreign shop must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('business', $e->errors());
        }

        $this->assertDatabaseCount('wa_business_bindings', 0);
    }

    public function test_old_switch_button_is_rejected_after_state_changes(): void
    {
        $id = $this->conversation();
        Http::fake(['https://retail.example.test/api/auth/me' => Http::response($this->membership())]);
        $bot = app(WhatsAppConversation::class);
        $bindingId = $bot->link($id, 'retail', 'private-pos-token', 11);
        $menu = $bot->reply(DB::table('wa_conversations')->find($id), ['type' => 'text']);
        $action = collect($menu['interactive']['action']['sections'][0]['rows'])->firstWhere('title', 'Retail Shop')['id'];
        DB::table('wa_conversations')->where('id', $id)->increment('version');

        $reply = $bot->reply(DB::table('wa_conversations')->find($id), ['interactive' => ['list_reply' => ['id' => $action]]]);

        $this->assertStringContainsString('expired', $reply['interactive']['body']['text']);
        $this->assertNull(DB::table('wa_conversations')->find($id)->binding_id);
    }

    public function test_valid_profile_switch_rechecks_pos_access(): void
    {
        $id = $this->conversation();
        Http::fake(['https://retail.example.test/api/auth/me' => Http::response($this->membership())]);
        $bot = app(WhatsAppConversation::class);
        $bindingId = $bot->link($id, 'retail', 'private-pos-token', 11);
        $menu = $bot->reply(DB::table('wa_conversations')->find($id), []);
        $action = collect($menu['interactive']['action']['sections'][0]['rows'])->firstWhere('title', 'Retail Shop')['id'];

        $reply = $bot->reply(DB::table('wa_conversations')->find($id), ['interactive' => ['list_reply' => ['id' => $action]]]);

        $this->assertSame($bindingId, DB::table('wa_conversations')->find($id)->binding_id);
        $this->assertStringContainsString('Active: Retail Shop', $reply['interactive']['body']['text']);
        Http::assertSentCount(2);
    }

    public function test_another_sender_cannot_use_a_switch_button(): void
    {
        $id = $this->conversation();
        $otherId = $this->conversation('256700000002');
        Http::fake(['https://retail.example.test/api/auth/me' => Http::response($this->membership())]);
        $bot = app(WhatsAppConversation::class);
        $bot->link($id, 'retail', 'private-pos-token', 11);
        $bot->link($otherId, 'retail', 'other-token', 11);
        $menu = $bot->reply(DB::table('wa_conversations')->find($id), []);
        $action = collect($menu['interactive']['action']['sections'][0]['rows'])->firstWhere('title', 'Retail Shop')['id'];

        $reply = $bot->reply(DB::table('wa_conversations')->find($otherId), ['interactive' => ['list_reply' => ['id' => $action]]]);

        $this->assertStringContainsString('expired', $reply['interactive']['body']['text']);
        $this->assertNull(DB::table('wa_conversations')->find($otherId)->binding_id);
    }

    public function test_business_picker_pages_beyond_seven_profiles_without_exceeding_ten_rows(): void
    {
        $id = $this->conversation();
        foreach (range(1, 10) as $number) {
            DB::table('wa_business_bindings')->insert(['conversation_id' => $id, 'backend' => 'retail', 'shop_id' => $number,
                'user_id' => 7, 'name' => 'Shop '.$number, 'role' => 'owner', 'token' => Crypt::encryptString('test-token'),
                'created_at' => now(), 'updated_at' => now()]);
        }
        $bot = app(WhatsAppConversation::class);
        $menu = $bot->reply(DB::table('wa_conversations')->find($id), []);
        $rows = $menu['interactive']['action']['sections'][0]['rows'];
        $next = collect($rows)->firstWhere('title', 'More businesses')['id'];

        $menu = $bot->reply(DB::table('wa_conversations')->find($id), ['interactive' => ['list_reply' => ['id' => $next]]]);
        $rows = $menu['interactive']['action']['sections'][0]['rows'];

        $this->assertContains('Shop 10', array_column($rows, 'title'));
        $this->assertLessThanOrEqual(10, count($rows));
    }

    public function test_revoked_pos_account_cannot_read_stock_with_stored_credentials(): void
    {
        $id = $this->conversation();
        $user = $this->membership();
        Http::fake(['https://retail.example.test/api/auth/me' => function () use (&$user) {
            return Http::response($user);
        }]);
        $bot = app(WhatsAppConversation::class);
        $bindingId = $bot->link($id, 'retail', 'private-pos-token', 11);
        DB::table('wa_conversations')->where('id', $id)->update(['binding_id' => $bindingId]);
        $menu = $bot->reply(DB::table('wa_conversations')->find($id), []);
        $action = collect($menu['interactive']['action']['sections'][0]['rows'])->firstWhere('title', 'View stock')['id'];
        $user['status'] = 'inactive';

        try {
            $bot->reply(DB::table('wa_conversations')->find($id), ['interactive' => ['list_reply' => ['id' => $action]]]);
            $this->fail('Revoked account must not read stock.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('business', $e->errors());
        }

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/pos/products'));
    }
}
