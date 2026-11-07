<?php

namespace Tests\Feature;

use App\Services\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RecordWizardTest extends TestCase
{
    use RefreshDatabase;

    private function business(string $role = 'owner'): int
    {
        config(['whatsapp.backends.retail.url' => 'https://pos.example.test/api']);
        Http::preventStrayRequests();
        $id = DB::table('wa_conversations')->insertGetId(['sender' => '256700000001', 'phone_number_id' => '12345', 'created_at' => now(), 'updated_at' => now()]);
        $binding = DB::table('wa_business_bindings')->insertGetId(['conversation_id' => $id, 'backend' => 'retail', 'shop_id' => 11, 'user_id' => 7, 'name' => 'Shop', 'role' => $role,
            'token' => Crypt::encryptString('test-token'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('wa_conversations')->where('id', $id)->update(['binding_id' => $binding]);
        Http::fake([
            'https://pos.example.test/api/auth/me' => Http::response(['id' => 7, 'status' => 'active', 'shop_roles' => [['shop_id' => 11, 'role' => $role, 'shop' => ['name' => 'Shop', 'status' => 'active']]]]),
            'https://pos.example.test/api/bot/capabilities' => Http::response(['operation_lookup' => 1, 'operation_replay' => 1, 'today' => '2026-11-03']),
            'https://pos.example.test/api/products?*' => Http::response(['data' => [['id' => 8, 'name' => 'Juice', 'base_unit' => 'bottle', 'sku' => 'J8', 'units' => [['unit_name' => 'box', 'conversion_to_base_unit' => 10]]]], 'next_page_url' => null, 'prev_page_url' => null]),
            'https://pos.example.test/api/expenses' => Http::response(['id' => 5], 201),
            'https://pos.example.test/api/inventory/damage' => Http::response(['id' => 6]),
            'https://pos.example.test/api/inventory/adjustments' => Http::response(['id' => 7]),
            'https://pos.example.test/api/shifts/open' => Http::response(['id' => 8], 201),
        ]);

        return $id;
    }

    private function send(int $id, ?string $text = null, ?string $action = null): array
    {
        $message = $action ? ['interactive' => ['list_reply' => ['id' => $action]]] : ($text === null ? [] : ['text' => ['body' => $text]]);

        return app(WhatsAppConversation::class)->reply(DB::table('wa_conversations')->find($id), $message);
    }

    private function choose(int $id, array $menu, string $title): array
    {
        $rows = $menu['interactive']['action']['sections'][0]['rows'];
        $this->assertLessThanOrEqual(10, count($rows));
        $row = collect($rows)->firstWhere('title', $title);
        $this->assertNotNull($row, 'Missing option '.$title);

        return $this->send($id, action: $row['id']);
    }

    public function test_expense_is_reviewed_and_only_then_recorded_once(): void
    {
        $id = $this->business();
        $menu = $this->choose($id, $this->send($id), 'Business tasks');
        $this->choose($id, $menu, 'Record expense');
        $this->send($id, 'Transport');
        $menu = $this->send($id, '5000');
        $menu = $this->choose($id, $menu, 'Today');
        $review = $this->choose($id, $menu, 'Skip');
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $result = $this->choose($id, $review, 'Confirm');

        $this->assertStringContainsString('Record expense recorded', $result['text']['body']);
        Http::assertSent(fn ($request) => $request->url() === 'https://pos.example.test/api/expenses'
            && $request['amount'] === '5000' && $request['expense_date'] === '2026-11-03'
            && $request->hasHeader('X-NileBit-Operation') && $request->hasHeader('X-Shop-Id', '11'));
        $this->assertDatabaseHas('wa_operations', ['kind' => 'record:expense', 'step' => 'completed']);
    }

    public function test_damaged_stock_uses_base_quantity_and_reason(): void
    {
        $id = $this->business();
        $menu = $this->choose($id, $this->send($id), 'Business tasks');
        $menu = $this->choose($id, $menu, 'Damaged stock');
        $menu = $this->choose($id, $menu, 'Juice');
        $this->assertStringContainsString('BASE unit', $menu['interactive']['body']['text']);
        $this->send($id, '2.5');
        $review = $this->send($id, 'Broken bottles');
        $this->choose($id, $review, 'Confirm');

        Http::assertSent(fn ($request) => $request->url() === 'https://pos.example.test/api/inventory/damage'
            && $request['product_id'] === 8 && $request['quantity'] === '2.5' && $request['reason'] === 'Broken bottles');
    }

    public function test_stock_count_zero_is_a_count_and_not_an_addition(): void
    {
        $id = $this->business();
        $menu = $this->send($id, 'Stock count');
        $this->choose($id, $menu, 'Juice');
        $this->send($id, '0');
        $review = $this->send($id, 'Shelf counted');
        $this->choose($id, $review, 'Confirm');

        Http::assertSent(fn ($request) => $request->url() === 'https://pos.example.test/api/inventory/adjustments'
            && $request['counted_quantity'] === '0' && ! isset($request['quantity_delta']));
    }

    public function test_cashier_only_sees_shift_tasks_and_can_open_their_own_shift(): void
    {
        $id = $this->business('cashier');
        $menu = $this->choose($id, $this->send($id), 'Business tasks');
        $titles = array_column($menu['interactive']['action']['sections'][0]['rows'], 'title');
        $this->assertNotContains('Damaged stock', $titles);
        $this->assertNotContains('Record expense', $titles);
        $this->choose($id, $menu, 'Open shift');
        $review = $this->send($id, '0');
        $this->choose($id, $review, 'Confirm');

        Http::assertSent(fn ($request) => $request->url() === 'https://pos.example.test/api/shifts/open' && $request['opening_cash'] === '0');
    }

    public function test_product_search_stays_in_the_product_step_and_passes_the_name(): void
    {
        $id = $this->business();
        $menu = $this->choose($id, $this->send($id), 'Business tasks');
        $this->choose($id, $menu, 'Damaged stock');
        $result = $this->send($id, 'Jui');

        $this->assertContains('Juice', array_column($result['interactive']['action']['sections'][0]['rows'], 'title'));
        $this->assertDatabaseHas('wa_operations', ['kind' => 'record:damage', 'step' => '0']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/products?') && $request['search'] === 'Jui' && $request['per_page'] === 6);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_purchase_keeps_received_pack_units_and_records_supplier_credit_once(): void
    {
        $id = $this->business();
        Http::fake([
            'https://pos.example.test/api/suppliers?*' => Http::response(['suppliers' => ['data' => [['id' => 9, 'name' => 'Fresh Supplies', 'balance' => 0]], 'next_page_url' => null]]),
            'https://pos.example.test/api/purchases' => Http::response(['id' => 12, 'purchase_number' => 'P-000012'], 201),
        ]);
        $menu = $this->choose($id, $this->send($id), 'Business tasks');
        $menu = $this->choose($id, $menu, 'More tasks');
        $menu = $this->choose($id, $menu, 'Receive stock');
        $menu = $this->choose($id, $menu, 'Fresh Supplies');
        $menu = $this->choose($id, $menu, 'Juice');
        $this->choose($id, $menu, 'box (10 base)');
        $this->send($id, '2');
        $menu = $this->send($id, '18000');
        $menu = $this->choose($id, $menu, 'Done with lines');
        $menu = $this->send($id, '0');
        $menu = $this->choose($id, $menu, 'Cash');
        $menu = $this->choose($id, $menu, 'Skip');
        $review = $this->choose($id, $menu, 'Skip');
        while (collect($review['interactive']['action']['sections'][0]['rows'])->contains('title', 'More details')) {
            $review = $this->choose($id, $review, 'More details');
        }
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $receipt = $this->choose($id, $review, 'Confirm');

        $this->assertStringContainsString('P-000012', $receipt['text']['body']);
        Http::assertSent(fn ($request) => $request->url() === 'https://pos.example.test/api/purchases'
            && $request['supplier_id'] === 9 && $request['amount_paid'] === '0'
            && $request['items'] === [['product_id' => 8, 'unit_name' => 'box', 'quantity' => '2', 'unit_cost' => '18000']]);
    }

    public function test_multi_item_purchase_retains_both_lines_before_confirmation(): void
    {
        $id = $this->business();
        Http::fake(['https://pos.example.test/api/suppliers?*' => Http::response(['suppliers' => ['data' => [['id' => 9, 'name' => 'Fresh Supplies', 'balance' => 0]]]])]);
        $menu = $this->choose($id, $this->send($id), 'Business tasks');
        $menu = $this->choose($id, $menu, 'More tasks');
        $menu = $this->choose($id, $menu, 'Receive stock');
        $menu = $this->choose($id, $menu, 'Fresh Supplies');
        foreach (range(1, 6) as $line) {
            $menu = $this->choose($id, $menu, 'Juice');
            $this->choose($id, $menu, 'bottle (base unit)');
            $this->send($id, (string) $line);
            $menu = $this->send($id, '2000');
            if ($line < 6) {
                $menu = $this->choose($id, $menu, 'Add another line');
            }
        }
        $state = json_decode(Crypt::decryptString(DB::table('wa_operations')->sole()->state), true);

        $this->assertCount(6, $state['values']['items']);
        $this->assertSame('1', $state['values']['items'][0]['quantity']);
        $this->assertSame('2', $state['values']['items'][1]['quantity']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }
}
