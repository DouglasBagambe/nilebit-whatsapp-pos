<?php

namespace Tests\Feature;

use App\Services\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StockBrowserTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_pages_searches_by_sku_and_reloads_current_units_without_writes(): void
    {
        config(['whatsapp.backends.retail.url' => 'https://stock.example.test/api']);
        $id = DB::table('wa_conversations')->insertGetId(['sender' => '256700000011', 'phone_number_id' => 'test', 'created_at' => now(), 'updated_at' => now()]);
        $binding = DB::table('wa_business_bindings')->insertGetId(['conversation_id' => $id, 'backend' => 'retail', 'shop_id' => 11, 'user_id' => 7,
            'name' => 'Stock shop', 'role' => 'owner', 'token' => Crypt::encryptString('stock-token'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('wa_conversations')->where('id', $id)->update(['binding_id' => $binding]);
        $products = array_map(fn ($i) => ['id' => $i, 'name' => 'Item '.$i, 'sku' => 'SKU-'.$i, 'base_unit' => 'bottle', 'selling_price' => 2000, 'stock' => 0,
            'units' => [['unit_name' => 'crate', 'selling_price' => 20000, 'conversion_to_base_unit' => 12]]], range(1, 15));
        Http::preventStrayRequests();
        Http::fake(['https://stock.example.test/api/auth/me' => Http::response(['id' => 7, 'status' => 'active', 'shop_roles' => [['shop_id' => 11, 'role' => 'owner', 'shop' => ['name' => 'Stock shop', 'status' => 'active']]]]),
            'https://stock.example.test/api/pos/products' => function () use (&$products) {
                return Http::response($products);
            }]);
        $send = fn (array $message = []) => app(WhatsAppConversation::class)->reply(DB::table('wa_conversations')->find($id), $message);
        $choose = function (array $menu, string $title) use ($send): array {
            $rows = $menu['interactive']['action']['sections'][0]['rows'];
            $this->assertLessThanOrEqual(10, count($rows));
            $row = collect($rows)->firstWhere('title', $title);
            $this->assertNotNull($row);

            return $send(['interactive' => ['list_reply' => ['id' => $row['id']]]]);
        };
        $menu = $choose($send(), 'View stock');
        $stockCommand = $send(['text' => ['body' => '  STOCK  ']]);
        $this->assertStringContainsString('15 matching products', $stockCommand['interactive']['body']['text']);
        $menu = $stockCommand;
        $menu = $choose($menu, 'Next products');
        $menu = $choose($menu, 'Next products');
        $this->assertContains('Item 15', array_column($menu['interactive']['action']['sections'][0]['rows'], 'title'));
        $search = $send(['text' => ['body' => '  stock SKU-15  ']]);
        $this->assertStringContainsString('1 matching products', $search['interactive']['body']['text']);
        $products[14]['stock'] = 24;

        $detail = $choose($search, 'Item 15');

        $this->assertStringContainsString('Stock: 24 bottle', $detail['interactive']['body']['text']);
        $this->assertStringContainsString('crate = 12 bottle · price 20000', $detail['interactive']['body']['text']);
        $this->assertLessThanOrEqual(1024, mb_strlen($detail['interactive']['body']['text']));
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }
}
