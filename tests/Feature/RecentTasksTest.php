<?php

namespace Tests\Feature;

use App\Services\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecentTasksTest extends TestCase
{
    use RefreshDatabase;

    private function business(): array
    {
        config(['whatsapp.backends.retail.url' => 'https://pos.example.test/api']);
        Http::preventStrayRequests();
        $id = DB::table('wa_conversations')->insertGetId(['sender' => '256700000001', 'phone_number_id' => '12345', 'created_at' => now(), 'updated_at' => now()]);
        $binding = DB::table('wa_business_bindings')->insertGetId(['conversation_id' => $id, 'backend' => 'retail', 'shop_id' => 11, 'user_id' => 7, 'name' => 'Shop', 'role' => 'owner',
            'token' => Crypt::encryptString('test-token'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('wa_conversations')->where('id', $id)->update(['binding_id' => $binding]);
        Http::fake([
            'https://pos.example.test/api/auth/me' => Http::response(['id' => 7, 'status' => 'active', 'shop_roles' => [['shop_id' => 11, 'role' => 'owner', 'shop' => ['name' => 'Shop', 'status' => 'active']]]]),
            'https://pos.example.test/api/bot/capabilities' => Http::response(['operation_lookup' => 1, 'operation_replay' => 1]),
        ]);

        return [$id, $binding];
    }

    private function send(int $id, ?string $action = null): array
    {
        return app(WhatsAppConversation::class)->reply(DB::table('wa_conversations')->find($id), $action ? ['interactive' => ['list_reply' => ['id' => $action]]] : []);
    }

    private function choose(int $id, array $menu, string $title): array
    {
        $rows = $menu['interactive']['action']['sections'][0]['rows'];
        $this->assertLessThanOrEqual(10, count($rows));
        $row = collect($rows)->firstWhere('title', $title);
        $this->assertNotNull($row, 'Missing '.$title);

        return $this->send($id, $row['id']);
    }

    public function test_expired_sale_review_recovers_its_recorded_pos_receipt_without_a_second_sale(): void
    {
        $this->freezeTime();
        [$id, $binding] = $this->business();
        $task = (string) Str::uuid();
        DB::table('wa_sale_drafts')->insert(['id' => $task, 'conversation_id' => $id, 'binding_id' => $binding, 'step' => 'review',
            'state' => Crypt::encryptString(json_encode(['items' => [], 'payload' => ['idempotency_key' => 'wa-sale-'.$task]])),
            'expires_at' => now()->subMinute(), 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);
        Http::fake(['https://pos.example.test/api/bot/operations/wa-sale-'.$task => Http::response(['recorded' => true, 'result' => ['id' => 50, 'sale_number' => 'S-000050', 'total' => 6000]])]);
        $menu = $this->choose($id, $this->send($id), 'Recent tasks');
        $detail = $this->choose($id, $menu, 'Sale');

        $this->assertStringContainsString('S-000050', $detail['interactive']['body']['text']);
        $this->assertDatabaseHas('wa_sale_drafts', ['id' => $task, 'step' => 'completed']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_unrecorded_expired_customer_task_resumes_the_same_payload_and_operation_id(): void
    {
        $this->freezeTime();
        [$id, $binding] = $this->business();
        $task = (string) Str::uuid();
        DB::table('wa_operations')->insert(['id' => $task, 'conversation_id' => $id, 'binding_id' => $binding, 'kind' => 'customer_create', 'step' => 'review',
            'state' => Crypt::encryptString(json_encode(['payload' => ['name' => 'Peter', 'phone' => null]])),
            'expires_at' => now()->subMinute(), 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);
        Http::fake([
            'https://pos.example.test/api/bot/operations/wa-operation-'.$task => Http::response([], 404),
            'https://pos.example.test/api/customers' => Http::response(['id' => 10, 'name' => 'Peter', 'balance' => 0], 201),
        ]);
        $menu = $this->choose($id, $this->send($id), 'Recent tasks');
        $detail = $this->choose($id, $menu, 'customer create');
        $review = $this->choose($id, $detail, 'Resume same task');
        $result = $this->choose($id, $review, 'Confirm customer');

        $this->assertStringContainsString('Customer added', $result['text']['body']);
        $this->assertDatabaseCount('wa_operations', 1);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->header('X-NileBit-Operation')[0] === 'wa-operation-'.$task && $request['name'] === 'Peter');
    }

    public function test_cancelling_a_review_checks_whether_it_already_committed_and_does_not_claim_reversal(): void
    {
        [$id, $binding] = $this->business();
        $task = (string) Str::uuid();
        DB::table('wa_operations')->insert(['id' => $task, 'conversation_id' => $id, 'binding_id' => $binding, 'kind' => 'customer_create', 'step' => 'review',
            'state' => Crypt::encryptString(json_encode(['payload' => ['name' => 'Peter', 'phone' => null]])),
            'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(['https://pos.example.test/api/bot/operations/wa-operation-'.$task => Http::response(['recorded' => true, 'result' => ['id' => 10, 'name' => 'Peter']])]);
        $result = app(WhatsAppConversation::class)->reply(DB::table('wa_conversations')->find($id), ['text' => ['body' => 'Cancel']]);

        $this->assertStringContainsString('already recorded', $result['text']['body']);
        $this->assertStringContainsString('not cancelled', $result['text']['body']);
        $this->assertDatabaseHas('wa_operations', ['id' => $task, 'step' => 'completed']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }
}
