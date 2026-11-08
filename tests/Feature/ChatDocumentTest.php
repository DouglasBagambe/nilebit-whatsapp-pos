<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Services\ChatDocument;
use App\Services\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ChatDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function business(string $role = 'owner'): int
    {
        config(['whatsapp.backends.retail.url' => 'https://pdf.example.test/api']);
        $id = DB::table('wa_conversations')->insertGetId(['sender' => '256700000055', 'phone_number_id' => '12345',
            'last_inbound_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $binding = DB::table('wa_business_bindings')->insertGetId(['conversation_id' => $id, 'backend' => 'retail', 'shop_id' => 11, 'user_id' => 7,
            'name' => 'QA Shop', 'role' => $role, 'token' => Crypt::encryptString('pdf-token'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('wa_conversations')->where('id', $id)->update(['binding_id' => $binding]);
        Http::preventStrayRequests();
        Http::fake([
            'https://pdf.example.test/api/auth/me' => Http::response(['id' => 7, 'status' => 'active', 'shop_roles' => [['shop_id' => 11, 'role' => $role, 'shop' => ['name' => 'QA Shop', 'status' => 'active']]]]),
            'https://pdf.example.test/api/bot/capabilities' => Http::response(['today' => '2026-11-08']),
            'https://pdf.example.test/api/reports/sales*' => Http::response(['summary' => ['net_sales' => 12000, 'sales_count' => 4]]),
            'https://pdf.example.test/api/sales?*' => Http::response(['data' => [['id' => 5, 'sale_number' => 'S-000005']]]),
            'https://pdf.example.test/api/sales/5' => Http::response(['sale_number' => 'S-000005', 'status' => 'completed', 'total' => 2000,
                'amount_paid' => 2000, 'amount_due' => 0, 'items' => [['product_id' => 8, 'product_name' => 'Mango Juice', 'quantity' => 1, 'unit' => 'bottle', 'line_total' => 2000]]]),
        ]);

        return $id;
    }

    private function send(int $id, string $text): array
    {
        return app(WhatsAppConversation::class)->reply(DB::table('wa_conversations')->find($id), ['text' => ['body' => $text]]);
    }

    public function test_natural_report_request_produces_a_pdf_for_the_shop_date(): void
    {
        $id = $this->business();
        $reply = $this->send($id, 'Please send me today’s sales report as a PDF');

        $this->assertSame('document', $reply['type']);
        $this->assertSame('sales-report-2026-11-08.pdf', $reply['document']['filename']);
        $this->assertStringStartsWith('%PDF-', base64_decode($reply['_pdf'], true));
        $this->assertStringContainsString('%%EOF', base64_decode($reply['_pdf'], true));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/reports/sales')
            && $request['from'] === '2026-11-08' && $request['to'] === '2026-11-08');
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_pdf_request_keeps_its_format_when_the_user_chooses_a_period(): void
    {
        $id = $this->business();
        $menu = $this->send($id, 'Send me a sales report as a PDF');
        $row = collect($menu['interactive']['action']['sections'][0]['rows'])->firstWhere('title', 'Today');
        $this->assertNotNull($row);
        $reply = app(WhatsAppConversation::class)->reply(DB::table('wa_conversations')->find($id), ['interactive' => ['list_reply' => ['id' => $row['id']]]]);
        $this->assertSame('document', $reply['type']);
    }

    public function test_stock_pdf_uses_the_report_endpoint_instead_of_a_product_search(): void
    {
        $id = $this->business();
        Http::fake(['https://pdf.example.test/api/reports/stock' => Http::response(['summary' => ['stock_value' => 25000, 'products' => 2]])]);
        $reply = $this->send($id, 'Could you show me a stock report as a PDF, please?');
        $this->assertSame('document', $reply['type']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/reports/stock'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/pos/products'));
    }

    public function test_retail_binding_cannot_open_a_production_report(): void
    {
        $id = $this->business();
        $this->expectException(ValidationException::class);
        try {
            $this->send($id, 'production report today');
        } finally {
            Http::assertNotSent(fn ($request) => str_contains($request->url(), '/reports/production'));
        }
    }

    public function test_last_month_uses_the_complete_previous_calendar_month(): void
    {
        $id = $this->business();
        $this->send($id, 'Can you show me the sales report for last month?');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/reports/sales')
            && $request['from'] === '2026-10-01' && $request['to'] === '2026-10-31');
    }

    public function test_pdf_does_not_bypass_the_current_report_role(): void
    {
        $id = $this->business('cashier');
        $this->expectException(ValidationException::class);
        try {
            $this->send($id, 'sales report today pdf');
        } finally {
            Http::assertNotSent(fn ($request) => str_contains($request->url(), '/reports/sales'));
        }
    }

    public function test_typed_receipt_pdf_uses_an_exact_accessible_receipt_match(): void
    {
        $id = $this->business('cashier');
        $reply = $this->send($id, 'Send me receipt S-000005 as PDF');
        $this->assertSame('document', $reply['type']);
        $this->assertSame('receipt-s-000005.pdf', $reply['document']['filename']);
        $this->assertStringStartsWith('%PDF-', base64_decode($reply['_pdf'], true));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sales/5') && $request->hasHeader('X-Shop-Id', '11'));
    }

    public function test_report_template_escapes_customer_content(): void
    {
        $html = view('chat-document', ['shop' => '<script>bad()</script>', 'title' => 'Report',
            'lines' => ['<img src="https://private.example.test/" />']])->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img ', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_transient_media_failure_preserves_the_pdf_and_retries_without_sending_an_empty_document(): void
    {
        $id = $this->business();
        config(['whatsapp.enabled' => true, 'whatsapp.api_version' => 'v25.0', 'whatsapp.phone_number_id' => '12345', 'whatsapp.access_token' => 'meta-token']);
        $payload = app(ChatDocument::class)->make('QA Shop', 'Sales summary', ['Net sales 12000'], 'sales-summary');
        $event = DB::table('wa_inbound_events')->insertGetId(['conversation_id' => $id, 'phone_number_id' => '12345',
            'message_id' => 'wamid.pdf-retry', 'payload' => Crypt::encryptString('{}'), 'created_at' => now(), 'updated_at' => now()]);
        $outbound = DB::table('wa_outbound_messages')->insertGetId(['conversation_id' => $id, 'inbound_event_id' => $event,
            'payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'created_at' => now(), 'updated_at' => now()]);
        Http::fake([
            'https://graph.facebook.com/v25.0/12345/media' => Http::sequence()->push([], 503)->push(['id' => '987654321']),
            'https://graph.facebook.com/v25.0/12345/messages' => Http::response(['messages' => [['id' => 'wamid.pdf-retried']]]),
        ]);
        $job = new SendWhatsAppMessage($outbound);
        try {
            $job->handle();
            $this->fail('The upload failure must leave delivery pending.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('PDF upload', $exception->getMessage());
        }
        $this->assertNull(DB::table('wa_outbound_messages')->where('id', $outbound)->value('sent_at'));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/messages'));
        $job->handle();
        $this->assertDatabaseHas('wa_outbound_messages', ['id' => $outbound, 'provider_message_id' => 'wamid.pdf-retried']);
        Http::assertSentCount(3);
    }

    public function test_sender_uploads_pdf_bytes_then_sends_only_the_media_id_and_does_not_replay_acceptance(): void
    {
        $id = $this->business();
        config(['whatsapp.enabled' => true, 'whatsapp.api_version' => 'v25.0', 'whatsapp.phone_number_id' => '12345', 'whatsapp.access_token' => 'meta-token']);
        $payload = app(ChatDocument::class)->make('QA Shop', 'Sales summary', ['Net sales 12000'], 'sales-summary');
        $event = DB::table('wa_inbound_events')->insertGetId(['conversation_id' => $id, 'phone_number_id' => '12345',
            'message_id' => 'wamid.pdf-test', 'payload' => Crypt::encryptString('{}'), 'created_at' => now(), 'updated_at' => now()]);
        $outbound = DB::table('wa_outbound_messages')->insertGetId(['conversation_id' => $id, 'inbound_event_id' => $event,
            'payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'created_at' => now(), 'updated_at' => now()]);
        Http::fake([
            'https://graph.facebook.com/v25.0/12345/media' => Http::response(['id' => '987654321']),
            'https://graph.facebook.com/v25.0/12345/messages' => Http::response(['messages' => [['id' => 'wamid.pdf-accepted']]]),
        ]);
        $job = new SendWhatsAppMessage($outbound);
        $job->handle();
        $job->handle();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/media') && str_contains($request->body(), '%PDF-') && $request->hasHeader('Authorization', 'Bearer meta-token'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/messages') && $request['type'] === 'document'
            && $request['document']['id'] === '987654321' && ! isset($request['_pdf']));
        Http::assertSentCount(2);
        $this->assertDatabaseHas('wa_outbound_messages', ['id' => $outbound, 'provider_message_id' => 'wamid.pdf-accepted']);
    }
}
