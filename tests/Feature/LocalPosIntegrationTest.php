<?php

namespace Tests\Feature;

use App\Services\PosClient;
use App\Services\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Opt-in checks against the disposable audit POS servers, never deployed accounts. */
class LocalPosIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_retail_chat_sale_updates_the_same_pos_stock_and_receipt(): void
    {
        $this->verifySale('retail', 8251, 'audit.retail@example.test', 'audit-local-only', 'Ballpoint Pen');
    }

    public function test_production_chat_sale_updates_the_same_pos_stock_and_receipt(): void
    {
        $this->verifySale('production', 8250, 'amina.production@example.test', 'demo-password', 'Mango Juice 300ml');
    }

    public function test_retail_discount_and_split_payment_match_the_pos_receipt(): void
    {
        $this->verifySale('retail', 8251, 'audit.retail@example.test', 'audit-local-only', 'Ballpoint Pen', true);
    }

    private function verifySale(string $backend, int $port, string $email, string $password, string $productName, bool $split = false): void
    {
        if (getenv('NILEBIT_LOCAL_INTEGRATION') !== '1') {
            $this->markTestSkipped('Requires disposable audit POS servers on 8250/8251; set NILEBIT_LOCAL_INTEGRATION=1 explicitly.');
        }
        $url = 'http://127.0.0.1:'.$port.'/api';
        config(["whatsapp.backends.$backend.url" => $url]);
        $login = app(PosClient::class)->login($backend, $email, $password);
        $shopId = $login['shops'][0]['id'];
        $client = Http::baseUrl($url)->withToken($login['token'])->withHeaders(['X-Shop-Id' => (string) $shopId])->acceptJson()->timeout(10);
        $products = $client->get('/pos/products')->throw()->json();
        $product = collect($products)->firstWhere('name', $productName);
        $this->assertNotNull($product);
        $this->assertGreaterThanOrEqual(1, (float) $product['stock']);
        $id = DB::table('wa_conversations')->insertGetId(['sender' => '256700000090', 'phone_number_id' => 'local-integration', 'created_at' => now(), 'updated_at' => now()]);
        $bot = app(WhatsAppConversation::class);
        $bindingId = $bot->link($id, $backend, $login['token'], $shopId);
        DB::table('wa_conversations')->where('id', $id)->update(['binding_id' => $bindingId]);
        $send = fn (array $message = []) => $bot->reply(DB::table('wa_conversations')->find($id), $message);
        $choose = function (array $menu, string $title) use ($send): array {
            $row = collect($menu['interactive']['action']['sections'][0]['rows'])->firstWhere('title', $title);
            $this->assertNotNull($row, 'Option missing: '.$title);

            return $send(['interactive' => ['list_reply' => ['id' => $row['id']]]]);
        };
        $menu = $choose($send(), 'Sell');
        $menu = $send(['text' => ['body' => $productName]]);
        $menu = $choose($menu, $productName);
        $menu = $choose($menu, $product['base_unit']);
        $menu = $choose($menu, '1');
        if ($split) {
            $choose($menu, 'Order discount');
            $menu = $send(['text' => ['body' => '100']]);
        }
        $menu = $choose($menu, 'Choose payment');
        if ($split) {
            $menu = $choose($menu, 'Split / part payment');
            $choose($menu, 'Cash');
            $menu = $send(['text' => ['body' => '1']]);
            $menu = $choose($menu, 'Add another payment');
            $menu = $choose($menu, 'Mobile money');
            $menu = $choose($menu, 'Pay remaining balance');
        } else {
            $menu = $choose($menu, 'Cash');
        }
        $review = $choose($menu, 'Walk-in / no name');
        $receipt = $choose($review, 'Confirm sale');
        $state = json_decode(Crypt::decryptString(DB::table('wa_sale_drafts')->sole()->state), true);
        $sale = $client->get('/sales/'.$state['receipt']['id'])->throw()->json();
        $after = collect($client->get('/pos/products')->throw()->json())->firstWhere('id', $product['id']);

        $this->assertStringContainsString($sale['sale_number'], $receipt['text']['body']);
        $this->assertSame((int) $product['selling_price'] - ($split ? 100 : 0), $sale['total']);
        if ($split) {
            $this->assertSame(100, $sale['discount']);
            $this->assertSame($sale['total'], $sale['amount_paid']);
            $this->assertSame(0, $sale['amount_due']);
            $this->assertCount(2, $sale['payments']);
        }
        $this->assertSame((float) $product['stock'] - 1, (float) $after['stock']);
        $this->assertDatabaseHas('wa_sale_drafts', ['step' => 'completed']);
        $client->post('/auth/logout')->throw();
    }
}
