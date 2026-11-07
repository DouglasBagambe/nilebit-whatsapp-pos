<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Live stock browsing without keeping a second inventory ledger. */
class StockBrowser
{
    public function __construct(private PosClient $pos, private ChatFlow $flow) {}

    public function handle(object $conversation, object $binding, ?object $action = null, string $search = ''): array
    {
        $data = $this->flow->decode($action?->payload);
        $products = $this->pos->stock($binding);
        $this->flow->advance($conversation);
        $controls = (object) ['id' => '', 'binding_id' => $binding->id, 'expires_at' => now()->addMinutes(15)];
        $search = mb_substr($data['search'] ?? $search, 0, 100);
        if ($action?->kind === 'stock_detail') {
            $product = collect($products)->firstWhere('id', (int) $data['product']);
            if (! $product) {
                throw ValidationException::withMessages(['product' => 'That product is no longer available. Send Menu and choose View stock again.']);
            }
            $details = ['Product #'.$product['id'].' · SKU '.($product['sku'] ?? 'Not set'), 'Stock: '.$product['stock'].' '.$product['base_unit'],
                'Selling price: '.$product['selling_price'].' per '.$product['base_unit']];
            foreach ($product['units'] ?? [] as $unit) {
                $details[] = $unit['unit_name'].' = '.$unit['conversion_to_base_unit'].' '.$product['base_unit'].' · price '.$unit['selling_price'];
            }
            $page = min(max(0, (int) ($data['page'] ?? 0)), max(0, (int) ceil(count($details) / 5) - 1));
            $body = Str::limit($binding->name, 60, '')."\n".Str::limit($product['name'], 100, '')."\n".implode("\n", array_map(fn ($line) => Str::limit($line, 140, ''), array_slice($details, $page * 5, 5)));
            $rows = [];
            foreach ([-1 => 'Previous details', 1 => 'More units'] as $offset => $title) {
                if ($page + $offset >= 0 && ($page + $offset) * 5 < count($details)) {
                    $rows[] = $this->flow->row($conversation, $controls, 'stock_detail', $title, ['product' => $product['id'], 'page' => $page + $offset, 'search' => $search]);
                }
            }
            $rows[] = $this->flow->row($conversation, $controls, 'stock_page', 'Back to stock', ['search' => $search]);
            $rows[] = $this->flow->row($conversation, $controls, 'menu', 'Back to menu');

            return $this->flow->menu($body, $rows, 'Product details');
        }
        [$filtered, $suggested] = $this->flow->findProducts($products, $search);
        $page = min(max(0, (int) ($data['page'] ?? 0)), max(0, (int) ceil(count($filtered) / 6) - 1));
        $rows = [];
        foreach (array_slice($filtered, $page * 6, 6) as $product) {
            $rows[] = $this->flow->row($conversation, $controls, 'stock_detail', $product['name'], ['product' => $product['id'], 'search' => $search],
                $product['stock'].' '.$product['base_unit'].' · SKU '.($product['sku'] ?? $product['id']));
        }
        foreach ([-1 => 'Previous products', 1 => 'Next products'] as $offset => $title) {
            if ($page + $offset >= 0 && ($page + $offset) * 6 < count($filtered)) {
                $rows[] = $this->flow->row($conversation, $controls, 'stock_page', $title, ['page' => $page + $offset, 'search' => $search]);
            }
        }
        $rows[] = $this->flow->row($conversation, $controls, 'menu', 'Back to menu');

        return $this->flow->menu(Str::limit($binding->name, 60, '')."\nStock: ".count($filtered).' matching products · page '.($page + 1).($suggested ? "\nDid you mean one of these products?" : '')."\nIncludes products with zero stock.\nChoose a product for current quantities and selling units.\nSearch by typing: stock mango", $rows, 'Stock');
    }
}
