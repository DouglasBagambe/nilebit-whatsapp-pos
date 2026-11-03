<?php

namespace Tests\Feature;

use App\Services\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerBrowserTest extends TestCase
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

    private function send(int $id, array $message = []): array
    {
        return app(WhatsAppConversation::class)->reply(DB::table('wa_conversations')->find($id), $message);
    }

    private function choose(int $id, array $menu, string $title): array
    {
        $rows = $menu['interactive']['action']['sections'][0]['rows'];
        $this->assertLessThanOrEqual(10, count($rows));
        $row = collect($rows)->firstWhere('title', $title);
        $this->assertNotNull($row);

        return $this->send($id, ['interactive' => ['list_reply' => ['id' => $row['id']]]]);
    }

    public function test_customer_detail_reads_the_current_pos_balance_instead_of_the_menu_snapshot(): void
    {
        $id = $this->business();
        Http::fake([
            'https://pos.example.test/api/auth/me' => Http::response($this->membership()),
            'https://pos.example.test/api/customers?*' => Http::response([['id' => 9, 'name' => 'Sarah', 'balance' => 8000]]),
            'https://pos.example.test/api/customers/9' => Http::response(['id' => 9, 'name' => 'Sarah', 'balance' => 3000, 'overdue' => true]),
        ]);
        $menu = $this->choose($id, $this->send($id), 'Customers & debt');
        $detail = $this->choose($id, $menu, 'Sarah');

        $this->assertStringContainsString('Owes: 3000', $detail['interactive']['body']['text']);
        $this->assertStringContainsString('Payment overdue', $detail['interactive']['body']['text']);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        Http::assertSent(fn ($request) => $request->url() === 'https://pos.example.test/api/customers/9' && $request->hasHeader('X-Shop-Id', '11'));
    }

    public function test_debt_search_forwards_the_typed_name_to_the_selected_pos(): void
    {
        $id = $this->business();
        Http::fake([
            'https://pos.example.test/api/auth/me' => Http::response($this->membership()),
            'https://pos.example.test/api/customers?*' => Http::response([]),
        ]);

        $menu = $this->send($id, ['text' => ['body' => 'debt Sarah']]);

        $this->assertStringContainsString('No matching customers', $menu['interactive']['body']['text']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/customers?') && $request['search'] === 'Sarah' && $request->hasHeader('X-Shop-Id', '11'));
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_debtor_filter_excludes_zero_and_credit_balances(): void
    {
        $id = $this->business();
        Http::fake([
            'https://pos.example.test/api/auth/me' => Http::response($this->membership()),
            'https://pos.example.test/api/customers?*' => Http::response([
                ['id' => 9, 'name' => 'Sarah', 'balance' => 3000], ['id' => 10, 'name' => 'Peter', 'balance' => 0], ['id' => 11, 'name' => 'Jane', 'balance' => -500],
            ]),
        ]);
        $menu = $this->choose($id, $this->send($id), 'Customers & debt');
        $menu = $this->choose($id, $menu, 'Only debtors');
        $titles = array_column($menu['interactive']['action']['sections'][0]['rows'], 'title');

        $this->assertContains('Sarah', $titles);
        $this->assertNotContains('Peter', $titles);
        $this->assertNotContains('Jane', $titles);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_customer_paging_reaches_later_people_without_exceeding_ten_rows(): void
    {
        $id = $this->business();
        $customers = array_map(fn ($number) => ['id' => $number, 'name' => 'Customer '.$number, 'balance' => 1000], range(1, 20));
        Http::fake([
            'https://pos.example.test/api/auth/me' => Http::response($this->membership()),
            'https://pos.example.test/api/customers?*' => Http::response($customers),
        ]);
        $menu = $this->choose($id, $this->send($id), 'Customers & debt');
        $menu = $this->choose($id, $menu, 'Next customers');
        $menu = $this->choose($id, $menu, 'Next customers');
        $this->assertLessThanOrEqual(10, count($menu['interactive']['action']['sections'][0]['rows']));
        $menu = $this->choose($id, $menu, 'Next customers');

        $this->assertContains('Customer 20', array_column($menu['interactive']['action']['sections'][0]['rows'], 'title'));
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }
}
