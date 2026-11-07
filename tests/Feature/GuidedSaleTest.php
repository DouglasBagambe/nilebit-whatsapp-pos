<?php

namespace Tests\Feature;

use App\Services\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GuidedSaleTest extends TestCase
{
    use RefreshDatabase;

    private function setupBusiness(string $backend = 'retail'): int
    {
        config(["whatsapp.backends.$backend.url" => 'https://pos.example.test/api']);
        $id = DB::table('wa_conversations')->insertGetId(['sender' => '256700000001', 'phone_number_id' => '12345', 'created_at' => now(), 'updated_at' => now()]);
        $bindingId = DB::table('wa_business_bindings')->insertGetId(['conversation_id' => $id, 'backend' => $backend, 'shop_id' => 11, 'user_id' => 7,
            'name' => 'Juice Shop', 'role' => 'owner', 'token' => Crypt::encryptString('secret-token'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('wa_conversations')->where('id', $id)->update(['binding_id' => $bindingId]);

        return $id;
    }

    private function fakePos(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://pos.example.test/api/bot/operations/*' => Http::response([], 404),
            'https://pos.example.test/api/bot/capabilities' => Http::response(['operation_lookup' => 1, 'operation_replay' => 1, 'sale_price_review' => 1]),
            'https://pos.example.test/api/auth/me' => Http::response(['id' => 7, 'status' => 'active', 'shop_roles' => [['shop_id' => 11, 'role' => 'owner', 'shop' => ['name' => 'Juice Shop', 'status' => 'active']]]]),
            'https://pos.example.test/api/pos/products' => Http::response([['id' => 8, 'name' => 'Mango Juice', 'sku' => 'MJ', 'base_unit' => 'bottle', 'selling_price' => 2000, 'stock' => 30,
                'units' => [['unit_name' => 'box', 'selling_price' => 20000, 'conversion_to_base_unit' => 12]]]]),
            'https://pos.example.test/api/customers*' => Http::response([['id' => 9, 'name' => 'Sarah', 'phone' => '0700000000', 'balance' => 0]]),
            'https://pos.example.test/api/sales' => Http::response(['id' => 50, 'sale_number' => 'S-000050', 'total' => 6000, 'amount_paid' => 6000, 'amount_due' => 0], 201),
        ]);
    }

    private function send(int $id, array $message = []): array
    {
        return app(WhatsAppConversation::class)->reply(DB::table('wa_conversations')->find($id), $message);
    }

    private function choose(int $id, array $menu, string $title): array
    {
        $rows = $menu['interactive']['action']['sections'][0]['rows'];
        $row = collect($rows)->firstWhere('title', $title);
        $this->assertNotNull($row, 'Option missing: '.$title);
        $this->assertLessThanOrEqual(10, count($rows));

        return $this->send($id, ['interactive' => ['list_reply' => ['id' => $row['id']]]]);
    }

    private function cart(int $id): array
    {
        $menu = $this->choose($id, $this->send($id), 'Sell');
        $menu = $this->choose($id, $menu, 'Mango Juice');
        $menu = $this->choose($id, $menu, 'bottle');

        return $this->choose($id, $menu, '3');
    }

    public function test_typed_sale_suggests_misspelled_product_and_requires_current_confirmation_code(): void
    {
        $id = $this->setupBusiness();
        $this->fakePos();
        $type = fn (string $text) => $this->send($id, ['text' => ['body' => $text]]);

        $suggestion = $type('Sell 2.5 Mangoe Juice');
        $this->assertStringContainsString('Did you mean', $suggestion['interactive']['body']['text']);
        $this->assertDatabaseHas('wa_sale_drafts', ['step' => 'products']);
        $type('Mango Juice');
        $quantity = $type('bottle');
        $this->assertStringContainsString('Requested quantity: 2.5', $quantity['interactive']['body']['text']);
        $this->assertStringContainsString('exceeds the stock', $type('31')['text']['body']);
        $type('2.5');
        $type('checkout');
        $type('cash');
        $review = $type('walk in');
        $this->assertStringContainsString('Total: 5000', $review['interactive']['body']['text']);
        preg_match('/Confirm [0-9A-F]{8}/', $review['interactive']['body']['text'], $firstCode);
        $type('confirm sale');
        $review = $type($firstCode[0]);
        preg_match('/Confirm [0-9A-F]{8}/', $review['interactive']['body']['text'], $currentCode);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $type($currentCode[0]);
        $type($currentCode[0]);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['items'][0]['quantity'] === 2.5);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
    }

    public function test_walk_in_cash_sale_requires_review_and_uses_the_existing_pos_api(): void
    {
        $id = $this->setupBusiness();
        $this->fakePos();
        $menu = $this->choose($id, $this->cart($id), 'Choose payment');
        $menu = $this->choose($id, $menu, 'Cash');
        $review = $this->choose($id, $menu, 'Walk-in / no name');
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');

        $receipt = $this->choose($id, $review, 'Confirm sale');

        $this->assertStringContainsString('S-000050', $receipt['text']['body']);
        $this->assertDatabaseHas('wa_sale_drafts', ['step' => 'completed']);
        Http::assertSent(fn ($request) => $request->url() === 'https://pos.example.test/api/sales'
            && $request->hasHeader('X-Shop-Id', '11') && $request['expected_total'] === 6000
            && $request['items'] === [['product_id' => 8, 'quantity' => 3, 'unit' => 'bottle']]
            && $request['payments'] === [['method' => 'CASH', 'amount' => 6000]]);
        $old = collect($review['interactive']['action']['sections'][0]['rows'])->firstWhere('title', 'Confirm sale')['id'];
        $this->send($id, ['interactive' => ['list_reply' => ['id' => $old]]]);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
    }

    public function test_production_credit_sale_requires_named_customer_and_sends_no_payment(): void
    {
        $id = $this->setupBusiness('production');
        $this->fakePos();
        $menu = $this->choose($id, $this->cart($id), 'Choose payment');
        $menu = $this->choose($id, $menu, 'On credit');
        $this->assertNotContains('Walk-in / no name', array_column($menu['interactive']['action']['sections'][0]['rows'], 'title'));
        $dueDate = $this->choose($id, $menu, 'Sarah');
        $review = $this->choose($id, $dueDate, 'No due date');
        $this->choose($id, $review, 'Confirm sale');

        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['customer_id'] === 9 && $request['payments'] === []);
    }

    public function test_discount_and_part_payment_leave_named_customer_debt_with_a_due_date(): void
    {
        $id = $this->setupBusiness();
        $this->fakePos();
        $this->choose($id, $this->cart($id), 'Order discount');
        $invalid = $this->send($id, ['text' => ['body' => '6001']]);
        $this->assertStringContainsString('subtotal', $invalid['text']['body']);
        $cart = $this->send($id, ['text' => ['body' => '1000']]);
        $menu = $this->choose($id, $cart, 'Choose payment');
        $menu = $this->choose($id, $menu, 'Split / part payment');
        $this->choose($id, $menu, 'Mobile money');
        $invalid = $this->send($id, ['text' => ['body' => '5001']]);
        $this->assertStringContainsString('5000', $invalid['text']['body']);
        $menu = $this->send($id, ['text' => ['body' => '2000']]);
        $customers = $this->choose($id, $menu, 'Rest on credit');
        $this->assertNotContains('Walk-in / no name', array_column($customers['interactive']['action']['sections'][0]['rows'], 'title'));
        $this->choose($id, $customers, 'Sarah');
        $invalid = $this->send($id, ['text' => ['body' => '2026-02-31']]);
        $this->assertStringContainsString('real due date', $invalid['text']['body']);
        $review = $this->send($id, ['text' => ['body' => '2026-11-10']]);
        $this->assertStringContainsString('Total: 5000', $review['interactive']['body']['text']);
        $this->assertStringContainsString('Due: 3000', $review['interactive']['body']['text']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');

        $this->choose($id, $review, 'Confirm sale');

        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['discount'] === 1000 && $request['expected_total'] === 5000
            && $request['due_date'] === '2026-11-10' && $request['customer_id'] === 9 && $request['payments'] === [['method' => 'MOBILE_MONEY', 'amount' => 2000]]);
    }

    public function test_two_payment_methods_can_settle_a_walk_in_sale(): void
    {
        $id = $this->setupBusiness();
        $this->fakePos();
        $menu = $this->choose($id, $this->cart($id), 'Choose payment');
        $menu = $this->choose($id, $menu, 'Split / part payment');
        $this->choose($id, $menu, 'Cash');
        $menu = $this->send($id, ['text' => ['body' => '1500']]);
        $menu = $this->choose($id, $menu, 'Add another payment');
        $menu = $this->choose($id, $menu, 'Mobile money');
        $menu = $this->choose($id, $menu, 'Pay remaining balance');
        $review = $this->choose($id, $menu, 'Walk-in / no name');
        $this->assertStringContainsString('CASH · 1500', $review['interactive']['body']['text']);
        $this->assertStringContainsString('MOBILE_MONEY · 4500', $review['interactive']['body']['text']);

        $this->choose($id, $review, 'Confirm sale');

        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['customer_id'] === null
            && $request['payments'] === [['method' => 'CASH', 'amount' => 1500], ['method' => 'MOBILE_MONEY', 'amount' => 4500]]);
    }

    public function test_cart_quantity_can_be_changed_without_double_counting_existing_stock(): void
    {
        $id = $this->setupBusiness();
        $this->fakePos();
        $menu = $this->choose($id, $this->cart($id), 'Edit / remove a line');
        $menu = $this->choose($id, $menu, 'Line 1: Mango Juice');
        $this->choose($id, $menu, 'Change quantity');
        $cart = $this->send($id, ['text' => ['body' => '30']]);
        $this->assertStringContainsString('Total: 60000', $cart['interactive']['body']['text']);
        $menu = $this->choose($id, $cart, 'Edit / remove a line');
        $menu = $this->choose($id, $menu, 'Line 1: Mango Juice');

        $products = $this->choose($id, $menu, 'Remove this line');

        $this->assertContains('Mango Juice', array_column($products['interactive']['action']['sections'][0]['rows'], 'title'));
        $state = json_decode(Crypt::decryptString(DB::table('wa_sale_drafts')->value('state')), true);
        $this->assertSame([], $state['items']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_every_cart_line_is_reviewed_before_confirmation_on_large_sales(): void
    {
        $id = $this->setupBusiness();
        $this->fakePos();
        $cart = $this->cart($id);
        for ($i = 0; $i < 10; $i++) {
            $menu = $this->choose($id, $cart, 'Add another product');
            $menu = $this->choose($id, $menu, 'Mango Juice');
            $menu = $this->choose($id, $menu, 'bottle');
            $cart = $this->choose($id, $menu, '1');
        }
        $menu = $this->choose($id, $cart, 'Choose payment');
        $menu = $this->choose($id, $menu, 'Cash');
        $first = $this->choose($id, $menu, 'Walk-in / no name');
        $this->assertNotContains('Confirm sale', array_column($first['interactive']['action']['sections'][0]['rows'], 'title'));
        $last = $this->choose($id, $first, 'Next');
        $this->assertStringContainsString('11: 1 bottle', $last['interactive']['body']['text']);
        $this->assertLessThanOrEqual(1024, mb_strlen($last['interactive']['body']['text']));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');

        $this->choose($id, $last, 'Confirm sale');

        Http::assertSent(fn ($request) => $request->method() === 'POST' && count($request['items']) === 11 && $request['expected_total'] === 26000);
    }

    public function test_a_saved_sale_blocks_starting_an_unrelated_customer_task(): void
    {
        $id = $this->setupBusiness();
        $this->fakePos();
        $this->cart($id);
        $menu = $this->send($id, ['text' => ['body' => 'Menu']]);
        $customers = $this->choose($id, $menu, 'Customers & debt');

        $blocked = $this->choose($id, $customers, 'Add customer');

        $this->assertStringContainsString('Finish or cancel', $blocked['text']['body']);
        $this->assertDatabaseCount('wa_operations', 0);
        $this->assertDatabaseHas('wa_sale_drafts', ['step' => 'cart']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_quantity_in_selected_unit_cannot_exceed_available_base_stock(): void
    {
        $id = $this->setupBusiness();
        $this->fakePos();
        $menu = $this->choose($id, $this->send($id), 'Sell');
        $menu = $this->choose($id, $menu, 'Mango Juice');
        $menu = $this->choose($id, $menu, 'box');
        $reply = $this->choose($id, $menu, '3');

        $this->assertStringContainsString('exceeds', $reply['text']['body']);
        $this->assertDatabaseHas('wa_sale_drafts', ['step' => 'quantity']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_cancelled_sale_does_not_post_any_financial_transaction(): void
    {
        $id = $this->setupBusiness();
        $this->fakePos();
        $this->cart($id);
        $reply = $this->send($id, ['text' => ['body' => 'Cancel']]);

        $this->assertStringContainsString('Nothing was recorded', $reply['text']['body']);
        $this->assertDatabaseHas('wa_sale_drafts', ['step' => 'cancelled']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_timeout_retries_use_the_identical_sale_key_and_payload(): void
    {
        $id = $this->setupBusiness();
        $this->fakePos();
        $menu = $this->choose($id, $this->cart($id), 'Choose payment');
        $menu = $this->choose($id, $menu, 'Cash');
        $review = $this->choose($id, $menu, 'Walk-in / no name');
        $action = collect($review['interactive']['action']['sections'][0]['rows'])->firstWhere('title', 'Confirm sale')['id'];
        $payloads = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://pos.example.test/api/bot/operations/*' => Http::response([], 404),
            'https://pos.example.test/api/bot/capabilities' => Http::response(['operation_lookup' => 1, 'operation_replay' => 1, 'sale_price_review' => 1]),
            'https://pos.example.test/api/auth/me' => Http::response(['id' => 7, 'status' => 'active', 'shop_roles' => [['shop_id' => 11, 'role' => 'owner', 'shop' => ['name' => 'Juice Shop', 'status' => 'active']]]]),
            'https://pos.example.test/api/sales' => function ($request) use (&$payloads) {
                $payloads[] = $request->data();
                if (count($payloads) === 1) {
                    throw new ConnectionException('Ambiguous timeout');
                }

                return Http::response(['id' => 50, 'sale_number' => 'S-000050', 'total' => 6000, 'amount_paid' => 6000, 'amount_due' => 0]);
            },
        ]);
        $message = ['interactive' => ['list_reply' => ['id' => $action]]];
        try {
            $this->send($id, $message);
            $this->fail('Timeout must remain retryable.');
        } catch (ConnectionException $e) {
            $this->assertSame('Ambiguous timeout', $e->getMessage());
        }
        $this->assertDatabaseHas('wa_sale_drafts', ['step' => 'review']);

        $receipt = $this->send($id, $message);

        $this->assertSame($payloads[0], $payloads[1]);
        $this->assertStringStartsWith('wa-sale-', $payloads[1]['idempotency_key']);
        $this->assertStringContainsString('S-000050', $receipt['text']['body']);
        Http::assertSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_another_conversation_cannot_submit_the_sale_confirmation(): void
    {
        $id = $this->setupBusiness();
        $this->fakePos();
        $menu = $this->choose($id, $this->cart($id), 'Choose payment');
        $menu = $this->choose($id, $menu, 'Cash');
        $review = $this->choose($id, $menu, 'Walk-in / no name');
        $action = collect($review['interactive']['action']['sections'][0]['rows'])->firstWhere('title', 'Confirm sale')['id'];
        $other = DB::table('wa_conversations')->insertGetId(['sender' => '256700000002', 'phone_number_id' => '12345', 'created_at' => now(), 'updated_at' => now()]);

        $this->send($other, ['interactive' => ['list_reply' => ['id' => $action]]]);

        $this->assertDatabaseHas('wa_sale_drafts', ['step' => 'review']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_larger_catalogues_page_without_exceeding_whatsapp_limits(): void
    {
        $id = $this->setupBusiness();
        Http::preventStrayRequests();
        $products = [];
        foreach (range(1, 20) as $number) {
            $products[] = ['id' => $number, 'name' => 'Product '.$number, 'sku' => 'SKU'.$number, 'base_unit' => 'piece', 'selling_price' => 1000, 'stock' => 10, 'units' => []];
        }
        Http::fake([
            'https://pos.example.test/api/bot/operations/*' => Http::response([], 404),
            'https://pos.example.test/api/bot/capabilities' => Http::response(['operation_lookup' => 1, 'operation_replay' => 1, 'sale_price_review' => 1]),
            'https://pos.example.test/api/auth/me' => Http::response(['id' => 7, 'status' => 'active', 'shop_roles' => [['shop_id' => 11, 'role' => 'owner', 'shop' => ['name' => 'Juice Shop', 'status' => 'active']]]]),
            'https://pos.example.test/api/pos/products' => Http::response($products),
        ]);
        $first = $this->choose($id, $this->send($id), 'Sell');
        $middle = $this->choose($id, $first, 'Next');
        $last = $this->choose($id, $middle, 'Next');
        $this->assertContains('Product 20', array_column($last['interactive']['action']['sections'][0]['rows'], 'title'));
        $this->assertLessThanOrEqual(10, count($middle['interactive']['action']['sections'][0]['rows']));
        $this->assertLessThanOrEqual(10, count($last['interactive']['action']['sections'][0]['rows']));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_decimal_quantity_can_be_typed_but_negative_quantity_is_refused(): void
    {
        $id = $this->setupBusiness();
        $this->fakePos();
        $menu = $this->choose($id, $this->send($id), 'Sell');
        $menu = $this->choose($id, $menu, 'Mango Juice');
        $this->choose($id, $menu, 'bottle');
        $invalid = $this->send($id, ['text' => ['body' => '-1']]);
        $this->assertStringContainsString('greater than 0', $invalid['text']['body']);

        $cart = $this->send($id, ['text' => ['body' => '1.5']]);

        $this->assertStringContainsString('Total: 3000', $cart['interactive']['body']['text']);
        $this->assertDatabaseHas('wa_sale_drafts', ['step' => 'cart']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }
}
