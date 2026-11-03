<?php

namespace Tests\Feature;

use App\Services\BusinessLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BusinessLinkTest extends TestCase
{
    use RefreshDatabase;

    private function linkUrl(): string
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'whatsapp.backends.retail.url' => 'https://retail.example.test/api']);
        Http::preventStrayRequests();
        $conversation = DB::table('wa_conversations')->insertGetId(['sender' => '256700000001', 'phone_number_id' => '12345', 'created_at' => now(), 'updated_at' => now()]);

        return app(BusinessLink::class)->issue($conversation);
    }

    private function fakePos(): void
    {
        $user = ['id' => 7, 'status' => 'active', 'shop_roles' => [['shop_id' => 11, 'role' => 'owner', 'shop' => ['id' => 11, 'name' => 'Amina Shop', 'status' => 'active']]]];
        Http::fake([
            'https://retail.example.test/api/auth/login' => Http::response(['token_scope' => 'pos:bot', 'token' => 'test-pos-token', 'user' => $user]),
            'https://retail.example.test/api/auth/me' => Http::response($user),
        ]);
    }

    public function test_existing_pos_login_and_selected_shop_approval_create_one_private_binding(): void
    {
        $url = $this->linkUrl();
        $this->fakePos();
        $this->get($url)->assertOk()->assertSee('Connect your business');

        $this->post($url.'/login', ['backend' => 'retail', 'email' => 'amina@example.test', 'password' => 'test-password'])->assertRedirect();
        $this->get($url)->assertOk()->assertSee('Amina Shop')->assertDontSee('test-pos-token');
        $this->post($url.'/approve', ['shops' => [11]])->assertOk()->assertSee('Business connected');

        $this->assertDatabaseCount('wa_business_bindings', 1);
        $this->assertSame('test-pos-token', Crypt::decryptString(DB::table('wa_business_bindings')->value('token')));
        $this->assertNotNull(DB::table('wa_link_requests')->value('consumed_at'));
        $this->get($url)->assertGone();
    }

    public function test_approval_without_sign_in_returns_403_and_links_nothing(): void
    {
        $url = $this->linkUrl();

        $this->post($url.'/approve', ['shops' => [11]])->assertForbidden();

        $this->assertDatabaseCount('wa_business_bindings', 0);
        Http::assertNothingSent();
    }

    public function test_submitted_foreign_shop_is_rejected_after_sign_in(): void
    {
        $url = $this->linkUrl();
        $this->fakePos();
        $this->post($url.'/login', ['backend' => 'retail', 'email' => 'amina@example.test', 'password' => 'test-password'])->assertRedirect();

        $this->post($url.'/approve', ['shops' => [99]])->assertForbidden();

        $this->assertDatabaseCount('wa_business_bindings', 0);
        $this->assertNull(DB::table('wa_link_requests')->value('consumed_at'));
    }

    public function test_expired_link_returns_410_without_a_pos_request(): void
    {
        $url = $this->linkUrl();
        $this->travel(16)->minutes();

        $this->get($url)->assertGone()->assertSee('You need a fresh link.')->assertSee('Send Menu in WhatsApp');

        Http::assertNothingSent();
        $this->assertDatabaseCount('wa_business_bindings', 0);
    }

    public function test_invalid_login_creates_no_business_binding(): void
    {
        $url = $this->linkUrl();
        Http::fake(['https://retail.example.test/api/auth/login' => Http::response(['message' => 'Wrong password'], 422)]);

        $this->from($url)->post($url.'/login', ['backend' => 'retail', 'email' => 'amina@example.test', 'password' => 'wrong'])->assertRedirect()->assertSessionHasErrors('email');

        $this->assertDatabaseCount('wa_business_bindings', 0);
        $this->assertNull(DB::table('wa_link_requests')->value('consumed_at'));
    }

    public function test_unreachable_pos_preserves_the_selected_system_and_email_but_not_the_password(): void
    {
        $url = $this->linkUrl();
        config(['whatsapp.backends.production.url' => 'https://production.example.test/api']);
        Http::fake(['https://production.example.test/api/auth/login' => Http::failedConnection()]);

        $this->from($url)->post($url.'/login', ['backend' => 'production', 'email' => 'amina@example.test', 'password' => 'private-password'])
            ->assertRedirect($url)->assertSessionHasErrors('email')
            ->assertSessionHasInput('backend', 'production')->assertSessionHasInput('email', 'amina@example.test')
            ->assertSessionMissing('_old_input.password');

        $this->assertDatabaseCount('wa_business_bindings', 0);
        $this->assertNull(DB::table('wa_link_requests')->value('consumed_at'));
        Http::assertSentCount(1);
    }
}
