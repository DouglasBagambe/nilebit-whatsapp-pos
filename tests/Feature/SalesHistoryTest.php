<?php

namespace Tests\Feature;

use App\Services\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SalesHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_reads_authoritative_receipt_and_distinguishes_original_debt_from_current_balance(): void
    {
        config(['whatsapp.backends.retail.url' => 'https://history.example.test/api']);
        $id = DB::table('wa_conversations')->insertGetId(['sender' => '256700000033', 'phone_number_id' => 'test', 'created_at' => now(), 'updated_at' => now()]);
        $binding = DB::table('wa_business_bindings')->insertGetId(['conversation_id' => $id, 'backend' => 'retail', 'shop_id' => 11, 'user_id' => 7,
            'name' => 'History shop', 'role' => 'cashier', 'token' => Crypt::encryptString('history-token'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('wa_conversations')->where('id', $id)->update(['binding_id' => $binding]);
        Http::preventStrayRequests();
        Http::fake(['https://history.example.test/api/auth/me' => Http::response(['id' => 7, 'status' => 'active', 'shop_roles' => [['shop_id' => 11, 'role' => 'cashier', 'shop' => ['name' => 'History shop', 'status' => 'active']]]]),
            'https://history.example.test/api/sales?*' => Http::response(['data' => [['id' => 33, 'sale_number' => 'S-0033', 'status' => 'completed', 'total' => 6000]], 'next_page_url' => null]),
            'https://history.example.test/api/sales/33' => Http::response(['id' => 33, 'sale_number' => 'S-0033', 'status' => 'completed', 'total' => 6000, 'amount_paid' => 2000, 'amount_due' => 4000, 'outstanding' => 1000,
                'customer' => ['name' => 'Sarah'], 'items' => [['product_id' => 8, 'product_name' => 'Mango Juice', 'quantity' => 3, 'unit' => 'bottle', 'line_total' => 6000]], 'payments' => [['method' => 'CASH', 'amount' => 2000]]])]);
        $send = fn (array $message = []) => app(WhatsAppConversation::class)->reply(DB::table('wa_conversations')->find($id), $message);
        $choose = function (array $menu, string $title) use ($send): array {
            $rows = $menu['interactive']['action']['sections'][0]['rows'];
            $this->assertLessThanOrEqual(10, count($rows));
            $row = collect($rows)->firstWhere('title', $title);
            $this->assertNotNull($row);

            return $send(['interactive' => ['list_reply' => ['id' => $row['id']]]]);
        };
        $history = $choose($send(), 'Sales & receipts');
        $receipt = $choose($history, 'S-0033');

        $this->assertStringContainsString('Original due: 4000', $receipt['interactive']['body']['text']);
        $this->assertStringContainsString('Outstanding now: 1000', $receipt['interactive']['body']['text']);
        $this->assertStringContainsString('Mango Juice', $receipt['interactive']['body']['text']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sales?') && $request['per_page'] === 6 && $request->hasHeader('X-Shop-Id', '11'));
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }
}
