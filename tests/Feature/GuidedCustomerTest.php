<?php

namespace Tests\Feature;

use App\Services\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GuidedCustomerTest extends TestCase
{
    use RefreshDatabase;

    private function business(): int
    {
        config(['whatsapp.backends.retail.url' => 'https://pos.example.test/api']);
        Http::preventStrayRequests();
        $id = DB::table('wa_conversations')->insertGetId(['sender' => '256700000001', 'phone_number_id' => '12345', 'created_at' => now(), 'updated_at' => now()]);
        $bindingId = DB::table('wa_business_bindings')->insertGetId(['conversation_id' => $id, 'backend' => 'retail', 'shop_id' => 11, 'user_id' => 7,
            'name' => 'Juice Shop', 'role' => 'owner', 'token' => Crypt::encryptString('secret-token'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('wa_conversations')->where('id', $id)->update(['binding_id' => $bindingId]);

        return $id;
    }

    private function membership(): array
    {
        return ['id' => 7, 'status' => 'active', 'shop_roles' => [['shop_id' => 11, 'role' => 'owner', 'shop' => ['name' => 'Juice Shop', 'status' => 'active']]]];
    }

    private function fakePos(): void
    {
        Http::fake([
            'https://pos.example.test/api/auth/me' => Http::response($this->membership()),
            'https://pos.example.test/api/bot/operations/*' => Http::response([], 404),
            'https://pos.example.test/api/bot/capabilities' => Http::response(['operation_lookup' => 1, 'operation_replay' => 1]),
            'https://pos.example.test/api/customers?*' => Http::response([['id' => 9, 'name' => 'Sarah', 'balance' => 3000]]),
            'https://pos.example.test/api/customers/9' => Http::response(['id' => 9, 'name' => 'Sarah', 'balance' => 3000]),
            'https://pos.example.test/api/customers' => Http::response(['id' => 10, 'name' => 'Peter', 'balance' => 0], 201),
            'https://pos.example.test/api/customers/9/payments' => Http::response(['id' => 9, 'name' => 'Sarah', 'balance' => 2000]),
        ]);
    }

    private function send(int $id, array $message = []): array
    {
        return app(WhatsAppConversation::class)->reply(DB::table('wa_conversations')->find($id), $message);
    }

    private function choose(int $id, array $menu, string $title): array
    {
        $rows = $menu['interactive']['action']['sections'][0]['rows'];
        $this->assertLessThanOrEqual(10, count($rows));
        $row = collect($rows)->firstWhere('title', $title);
        $this->assertNotNull($row, 'Option missing: '.$title);

        return $this->send($id, ['interactive' => ['list_reply' => ['id' => $row['id']]]]);
    }

    private function newCustomerReview(int $id): array
    {
        $menu = $this->choose($id, $this->send($id), 'Customers & debt');
        $this->choose($id, $menu, 'Add customer');
        $menu = $this->send($id, ['text' => ['body' => 'Peter']]);

        return $this->choose($id, $menu, 'Skip phone');
    }

    public function test_new_customer_is_only_created_after_explicit_confirmation(): void
    {
        $id = $this->business();
        $this->fakePos();
        $review = $this->newCustomerReview($id);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $result = $this->choose($id, $review, 'Confirm customer');

        $this->assertStringContainsString('Customer added: Peter', $result['text']['body']);
        $this->assertDatabaseHas('wa_operations', ['kind' => 'customer_create', 'step' => 'completed']);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['name'] === 'Peter'
            && $request['phone'] === null && $request->hasHeader('X-Shop-Id', '11') && $request->hasHeader('X-NileBit-Operation'));
    }

    public function test_a_saved_customer_task_blocks_starting_a_sale(): void
    {
        $id = $this->business();
        $this->fakePos();
        $this->newCustomerReview($id);
        $menu = $this->send($id, ['text' => ['body' => 'Menu']]);

        $blocked = $this->choose($id, $menu, 'Sell');

        $this->assertStringContainsString('Finish or cancel', $blocked['text']['body']);
        $this->assertDatabaseCount('wa_sale_drafts', 0);
        $this->assertDatabaseHas('wa_operations', ['kind' => 'customer_create', 'step' => 'review']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_repayment_uses_reviewed_amount_method_and_a_stable_replay_key(): void
    {
        $id = $this->business();
        $this->fakePos();
        $menu = $this->choose($id, $this->send($id), 'Customers & debt');
        $menu = $this->choose($id, $menu, 'Sarah');
        $menu = $this->choose($id, $menu, 'Record repayment');
        $menu = $this->choose($id, $menu, '1000');
        $menu = $this->choose($id, $menu, 'Mobile money');
        $review = $this->choose($id, $menu, 'Skip reference');
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $result = $this->choose($id, $review, 'Confirm payment');

        $this->assertStringContainsString('Remaining balance: 2000', $result['text']['body']);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['amount'] === 1000
            && $request['method'] === 'MOBILE_MONEY' && $request->header('X-NileBit-Operation')[0] === $request['idempotency_key']);
        $action = collect($review['interactive']['action']['sections'][0]['rows'])->firstWhere('title', 'Confirm payment')['id'];
        $this->send($id, ['interactive' => ['list_reply' => ['id' => $action]]]);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
    }

    public function test_timeout_reuses_the_same_customer_creation_request(): void
    {
        $id = $this->business();
        $this->fakePos();
        $review = $this->newCustomerReview($id);
        $action = collect($review['interactive']['action']['sections'][0]['rows'])->firstWhere('title', 'Confirm customer')['id'];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $attempts = [];
        Http::fake([
            'https://pos.example.test/api/auth/me' => Http::response($this->membership()),
            'https://pos.example.test/api/bot/operations/*' => Http::response([], 404),
            'https://pos.example.test/api/bot/capabilities' => Http::response(['operation_lookup' => 1, 'operation_replay' => 1]),
            'https://pos.example.test/api/customers' => function ($request) use (&$attempts) {
                $attempts[] = [$request->header('X-NileBit-Operation'), $request->data()];
                if (count($attempts) === 1) {
                    throw new ConnectionException('Timeout after request');
                }

                return Http::response(['id' => 10, 'name' => 'Peter', 'balance' => 0], 201);
            },
        ]);
        $message = ['interactive' => ['list_reply' => ['id' => $action]]];
        try {
            $this->send($id, $message);
            $this->fail('Timeout must remain retryable.');
        } catch (ConnectionException $e) {
            $this->assertSame('Timeout after request', $e->getMessage());
        }
        $result = $this->send($id, $message);

        $this->assertSame($attempts[0], $attempts[1]);
        $this->assertStringContainsString('Customer added', $result['text']['body']);
        Http::assertSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_older_pos_without_replay_capability_is_not_sent_a_mutation(): void
    {
        $id = $this->business();
        $this->fakePos();
        $review = $this->newCustomerReview($id);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://pos.example.test/api/auth/me' => Http::response($this->membership()),
            'https://pos.example.test/api/bot/operations/*' => Http::response([], 404),
            'https://pos.example.test/api/bot/capabilities' => Http::response([], 404),
        ]);
        try {
            $this->choose($id, $review, 'Confirm customer');
            $this->fail('An older POS must not be mutated.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('connection', $e->errors());
        }
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_cancel_does_not_create_a_customer(): void
    {
        $id = $this->business();
        $this->fakePos();
        $this->newCustomerReview($id);
        $result = $this->send($id, ['text' => ['body' => 'Cancel']]);

        $this->assertStringContainsString('Nothing was recorded', $result['text']['body']);
        $this->assertDatabaseHas('wa_operations', ['step' => 'cancelled']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }
}
