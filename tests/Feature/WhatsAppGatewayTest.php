<?php

namespace Tests\Feature;

use App\Jobs\ProcessInboundMessage;
use App\Jobs\SendWhatsAppMessage;
use App\Services\QueueWakeup;
use App\Services\WhatsAppConversation;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WhatsAppGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'whatsapp.enabled' => true,
            'whatsapp.app_secret' => 'test-secret', 'whatsapp.verify_token' => 'test-verify', 'whatsapp.phone_number_id' => '12345']);
        Http::preventStrayRequests();
    }

    /** @return array<string, mixed> */
    private function payload(string $id = 'wamid.test', string $phone = '12345'): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => $phone], 'messages' => [['id' => $id, 'from' => '256700000001', 'type' => 'text', 'timestamp' => (string) time(), 'text' => ['body' => 'Hi']]],
        ]]]]]];
    }

    private function receive(array $payload): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/whatsapp/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret')], $body);
    }

    public function test_invalid_signature_returns_403_without_persisting_or_queuing(): void
    {
        $this->configure();
        Queue::fake();

        $this->postJson('/api/whatsapp/webhook', $this->payload())->assertForbidden();

        $this->assertDatabaseCount('wa_inbound_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_duplicate_signed_webhooks_persist_one_encrypted_event(): void
    {
        $this->configure();
        Queue::fake();
        $payload = $this->payload();

        $this->receive($payload)->assertOk();
        $this->receive($payload)->assertOk();

        $this->assertDatabaseCount('wa_inbound_events', 1);
        $this->assertDatabaseCount('wa_conversations', 1);
        $event = DB::table('wa_inbound_events')->sole();
        $this->assertStringNotContainsString('Hi', $event->payload);
        $this->assertSame('Hi', json_decode(Crypt::decryptString($event->payload), true)['text']['body']);
        Queue::assertPushed(ProcessInboundMessage::class, 2);
    }

    public function test_valid_inbound_delivery_signals_the_local_worker_after_persisting_work(): void
    {
        $this->configure();
        Queue::fake();
        $wakeup = $this->mock(QueueWakeup::class);
        $wakeup->shouldReceive('notify')->once()->andReturnUsing(function (): void {
            $this->assertDatabaseCount('wa_inbound_events', 1);
        });

        $this->receive($this->payload())->assertOk();

        Queue::assertPushed(ProcessInboundMessage::class, 1);
        Http::assertNothingSent();
    }

    public function test_another_phone_account_is_ignored_even_with_valid_signature(): void
    {
        $this->configure();
        Queue::fake();

        $this->receive($this->payload(phone: '99999'))->assertOk();

        $this->assertDatabaseCount('wa_inbound_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_reprocessing_an_inbound_event_creates_one_response(): void
    {
        $this->configure();
        Queue::fake();
        $this->receive($this->payload())->assertOk();
        $id = DB::table('wa_inbound_events')->value('id');
        $worker = new ProcessInboundMessage($id);

        $worker->handle(app(WhatsAppConversation::class));
        $worker->handle(app(WhatsAppConversation::class));

        $this->assertDatabaseCount('wa_outbound_messages', 1);
        $this->assertNotNull(DB::table('wa_inbound_events')->value('processed_at'));
        Queue::assertPushed(SendWhatsAppMessage::class, 2);
    }

    public function test_out_of_order_message_does_not_change_the_current_chat_task(): void
    {
        $this->configure();
        $this->freezeTime();
        Queue::fake();
        $payload = $this->payload('wamid.delayed');
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['timestamp'] = (string) now()->subSeconds(20)->timestamp;
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] = '3';
        $this->receive($payload)->assertOk();
        $event = DB::table('wa_inbound_events')->sole();
        DB::table('wa_conversations')->where('id', $event->conversation_id)->update(['last_inbound_at' => now(), 'version' => 9]);
        $bot = $this->mock(WhatsAppConversation::class);
        $bot->shouldNotReceive('reply');

        (new ProcessInboundMessage($event->id))->handle($bot);
        (new ProcessInboundMessage($event->id))->handle($bot);

        $this->assertDatabaseCount('wa_outbound_messages', 1);
        $body = json_decode(Crypt::decryptString(DB::table('wa_outbound_messages')->value('payload')), true);
        $this->assertStringContainsString('not applied', $body['text']['body']);
        $this->assertDatabaseHas('wa_conversations', ['id' => $event->conversation_id, 'version' => 9, 'last_inbound_at' => now()->toDateTimeString()]);
        $this->assertNotNull(DB::table('wa_inbound_events')->value('processed_at'));
        Queue::assertPushed(SendWhatsAppMessage::class, 2);
        Http::assertNothingSent();
    }

    public function test_distinct_messages_with_the_same_second_timestamp_are_processed(): void
    {
        $this->configure();
        $this->freezeTime();
        Queue::fake();
        $this->receive($this->payload('wamid.same-second'))->assertOk();
        $event = DB::table('wa_inbound_events')->sole();
        DB::table('wa_conversations')->where('id', $event->conversation_id)->update(['last_inbound_at' => now()]);
        $bot = $this->mock(WhatsAppConversation::class);
        $bot->shouldReceive('reply')->once()->andReturn(['type' => 'text', 'text' => ['body' => 'Current step accepted']]);

        (new ProcessInboundMessage($event->id))->handle($bot);

        $body = json_decode(Crypt::decryptString(DB::table('wa_outbound_messages')->value('payload')), true);
        $this->assertSame('Current step accepted', $body['text']['body']);
        Queue::assertPushed(SendWhatsAppMessage::class, 1);
        Http::assertNothingSent();
    }

    public function test_unconfigured_webhook_returns_503(): void
    {
        config(['whatsapp.enabled' => false]);
        Queue::fake();

        $this->postJson('/api/whatsapp/webhook', $this->payload())->assertServiceUnavailable();

        $this->assertDatabaseCount('wa_inbound_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_verification_challenge_requires_the_configured_token(): void
    {
        $this->configure();

        $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=test-verify&hub.challenge=123456')->assertOk()->assertSeeText('123456');
        $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=123456')->assertForbidden();
    }

    public function test_sender_worker_retries_do_not_resend_a_recorded_acceptance(): void
    {
        $this->configure();
        config(['whatsapp.api_version' => 'v25.0', 'whatsapp.access_token' => 'test-token']);
        Queue::fake();
        Http::fake(['https://graph.facebook.com/v25.0/12345/messages' => Http::response(['messages' => [['id' => 'wamid.outgoing']]])]);
        $this->receive($this->payload())->assertOk();
        (new ProcessInboundMessage(DB::table('wa_inbound_events')->value('id')))->handle(app(WhatsAppConversation::class));
        $job = new SendWhatsAppMessage(DB::table('wa_outbound_messages')->value('id'));

        $job->handle();
        $job->handle();

        Http::assertSentCount(1);
        $this->assertSame('wamid.outgoing', DB::table('wa_outbound_messages')->value('provider_message_id'));
    }

    public function test_outbound_replies_wait_for_earlier_pending_replies_in_the_same_conversation(): void
    {
        $this->configure();
        config(['whatsapp.api_version' => 'v25.0', 'whatsapp.access_token' => 'test-token']);
        Queue::fake();
        Http::fake(['https://graph.facebook.com/v25.0/12345/messages' => Http::response(['messages' => [['id' => 'wamid.accepted']]])]);
        foreach (['wamid.first', 'wamid.second'] as $messageId) {
            $this->receive($this->payload($messageId))->assertOk();
            $eventId = DB::table('wa_inbound_events')->where('message_id', $messageId)->value('id');
            (new ProcessInboundMessage($eventId))->handle(app(WhatsAppConversation::class));
        }
        $ids = DB::table('wa_outbound_messages')->orderBy('id')->pluck('id');
        $queued = \Mockery::mock(Job::class);
        $queued->shouldReceive('release')->once()->with(10);
        $second = new SendWhatsAppMessage($ids[1]);
        $second->setJob($queued);

        $second->handle();

        Http::assertNothingSent();
        $this->assertDatabaseHas('wa_outbound_messages', ['id' => $ids[1], 'sent_at' => null]);
        (new SendWhatsAppMessage($ids[0]))->handle();
        (new SendWhatsAppMessage($ids[1]))->handle();
        Http::assertSentCount(2);
        $this->assertSame(2, DB::table('wa_outbound_messages')->whereNotNull('sent_at')->count());
        Queue::assertPushed(ProcessInboundMessage::class, 2);
        Queue::assertPushed(SendWhatsAppMessage::class, 2);
    }

    public function test_delayed_old_message_cannot_reopen_the_service_window(): void
    {
        $this->configure();
        config(['whatsapp.api_version' => 'v25.0', 'whatsapp.access_token' => 'test-token']);
        Queue::fake();
        $payload = $this->payload();
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['timestamp'] = (string) now()->subHours(25)->timestamp;
        $this->receive($payload)->assertOk();
        (new ProcessInboundMessage(DB::table('wa_inbound_events')->value('id')))->handle(app(WhatsAppConversation::class));

        (new SendWhatsAppMessage(DB::table('wa_outbound_messages')->value('id')))->handle();

        Http::assertNothingSent();
        $this->assertNotNull(DB::table('wa_outbound_messages')->value('abandoned_at'));
        $this->assertNull(DB::table('wa_outbound_messages')->value('sent_at'));
    }
}
