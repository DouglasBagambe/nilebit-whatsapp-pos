<?php

namespace Tests\Feature;

use App\Services\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReportBrowserTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_uses_shop_date_and_rechecks_role_before_returning_profit(): void
    {
        config(['whatsapp.backends.retail.url' => 'https://report.example.test/api']);
        $id = DB::table('wa_conversations')->insertGetId(['sender' => '256700000022', 'phone_number_id' => 'test', 'created_at' => now(), 'updated_at' => now()]);
        $binding = DB::table('wa_business_bindings')->insertGetId(['conversation_id' => $id, 'backend' => 'retail', 'shop_id' => 11, 'user_id' => 7,
            'name' => 'Report shop', 'role' => 'owner', 'token' => Crypt::encryptString('report-token'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('wa_conversations')->where('id', $id)->update(['binding_id' => $binding]);
        $role = 'owner';
        Http::preventStrayRequests();
        Http::fake(['https://report.example.test/api/auth/me' => function () use (&$role) {
            return Http::response(['id' => 7, 'status' => 'active', 'shop_roles' => [['shop_id' => 11, 'role' => $role, 'shop' => ['name' => 'Report shop', 'status' => 'active']]]]);
        },
            'https://report.example.test/api/bot/capabilities' => Http::response(['today' => '2026-11-03']),
            'https://report.example.test/api/reports/sales*' => Http::response(['summary' => ['net_sales' => 9000, 'sales_count' => 3]])]);
        $send = fn (array $message = []) => app(WhatsAppConversation::class)->reply(DB::table('wa_conversations')->find($id), $message);
        $choose = function (array $menu, string $title) use ($send): array {
            $rows = $menu['interactive']['action']['sections'][0]['rows'];
            $this->assertLessThanOrEqual(10, count($rows));
            $row = collect($rows)->firstWhere('title', $title);
            $this->assertNotNull($row);

            return $send(['interactive' => ['list_reply' => ['id' => $row['id']]]]);
        };
        $typed = $send(['text' => ['body' => 'Show sales report last 7 days']]);
        $this->assertStringContainsString('Net sales: 9000', $typed['interactive']['body']['text']);
        $reports = $send(['text' => ['body' => 'Reports']]);
        $periods = $choose($reports, 'Sales summary');
        $figures = $choose($periods, 'Last 7 days');
        $this->assertStringContainsString('2026-10-28 to 2026-11-03', $figures['interactive']['body']['text']);
        $this->assertStringContainsString('Net sales: 9000', $figures['interactive']['body']['text']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/reports/sales') && $request['from'] === '2026-10-28' && $request['to'] === '2026-11-03');
        $reports = $choose($figures, 'All reports');
        $profit = collect($reports['interactive']['action']['sections'][0]['rows'])->firstWhere('title', 'Profit summary')['id'];
        $role = 'cashier';
        try {
            $send(['interactive' => ['list_reply' => ['id' => $profit]]]);
            $this->fail('Role change must revoke profit access.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('report', $e->errors());
        }
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/reports/profit') || $request->method() !== 'GET');
    }
}
