<?php

namespace Tests\Feature;

use App\Services\BusinessLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BusinessLinkTransportTest extends TestCase
{
    use RefreshDatabase;

    private function issue(): string
    {
        config(['app.url' => 'https://bot.example.test']);
        $id = DB::table('wa_conversations')->insertGetId(['sender' => '256700000044', 'phone_number_id' => 'test', 'created_at' => now(), 'updated_at' => now()]);

        return app(BusinessLink::class)->issue($id);
    }

    public function test_production_link_is_canonical_and_does_not_render_over_http_or_another_host(): void
    {
        Http::preventStrayRequests();
        $url = $this->issue();
        $this->app['env'] = 'production';
        $this->assertStringStartsWith('https://bot.example.test/link/', $url);

        $this->get(str_replace('https:', 'http:', $url))->assertBadRequest();
        $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get(str_replace('https:', 'http:', $url))->assertBadRequest();
        $this->get(str_replace('bot.example.test', 'other.example.test', $url))->assertBadRequest();
        $this->get($url)->assertOk()->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        Http::assertNothingSent();
    }

    public function test_an_http_production_url_cannot_issue_a_password_link(): void
    {
        Http::preventStrayRequests();
        $url = $this->issue();
        config(['app.url' => 'http://bot.example.test']);
        $this->app['env'] = 'production';

        $this->get($url)->assertServiceUnavailable();
        Http::assertNothingSent();
    }
}
