<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** A persisted cart, never a separate inventory or accounting ledger. */
class GuidedSale
{
    public function __construct(private PosClient $pos) {}

    public function pending(object $conversation): ?object
    {
        return DB::table('wa_sale_drafts')->where('conversation_id', $conversation->id)
            ->where('binding_id', $conversation->binding_id)->whereNotIn('step', ['completed', 'cancelled', 'failed'])
            ->where('expires_at', '>', now())->latest('created_at')->first();
    }

    /** @param array<string, mixed> $message @return array<string, mixed> */
    public function handle(object $conversation, ?object $action, array $message): array
    {
        $binding = DB::table('wa_business_bindings')->where('id', $conversation->binding_id)
            ->where('conversation_id', $conversation->id)->whereNull('revoked_at')->firstOrFail();
        $this->pos->membership($binding->backend, Crypt::decryptString($binding->token), $binding->shop_id);
        $draft = $this->pending($conversation);
        $kind = $action?->kind ?? 'sale_text';
        $data = $action?->payload ? json_decode(Crypt::decryptString($action->payload), true, flags: JSON_THROW_ON_ERROR) : [];
        if ($kind === 'sale_start') {
            if (app(ChatFlow::class)->pending($conversation)) {
                return $this->text('Finish or cancel your current task before starting a sale. Open Recent tasks to resume it.');
            }
            if (! $draft) {
                $id = (string) Str::uuid();
                DB::table('wa_sale_drafts')->insert(['id' => $id, 'conversation_id' => $conversation->id, 'binding_id' => $binding->id,
                    'step' => 'products', 'state' => Crypt::encryptString(json_encode(['items' => []], JSON_THROW_ON_ERROR)),
                    'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);
                $draft = DB::table('wa_sale_drafts')->find($id);
            }
        } elseif (! $draft || ($action && ($data['draft'] ?? null) !== $draft->id)) {
            return $this->text('That sale has expired or was already completed. Send Menu to start again.');
        }
        $state = json_decode(Crypt::decryptString($draft->state), true, flags: JSON_THROW_ON_ERROR);
        $step = $draft->step;
        if ($kind === 'sale_start' && isset($data['quantity'])) {
            $state['suggested_quantity'] = $data['quantity'];
        }
        if ($kind === 'sale_cancel') {
            if ($step === 'review') {
                $recorded = $this->pos->operation($binding, 'wa-sale-'.$draft->id);
                if ($recorded !== null) {
                    $state['receipt'] = $recorded;
                    DB::table('wa_sale_drafts')->where('id', $draft->id)->update(['step' => 'completed', 'state' => Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR)), 'updated_at' => now()]);
                    app(ChatFlow::class)->advance($conversation);

                    return app(ChatFlow::class)->text('This task was already recorded in the POS. It was not cancelled. Open Recent tasks to see the result.');
                }
            }

            $this->save($draft, 'cancelled', $state);
            $this->advance($conversation);

            return $this->text('Sale cancelled. Nothing was recorded. Send Menu for your options.');
        }
        if ($kind === 'sale_products') {
            $step = 'products';
        } elseif ($kind === 'sale_product' && $step === 'products') {
            $product = collect($this->pos->stock($binding))->firstWhere('id', (int) $data['product']);
            if (! $product || (float) $product['stock'] <= 0) {
                throw ValidationException::withMessages(['stock' => 'That product is no longer in stock. Send Menu to choose again.']);
            }
            unset($state['editing']);
            $state['product'] = $product;
            $step = 'units';
        } elseif ($kind === 'sale_unit' && $step === 'units') {
            $units = $this->units($state['product']);
            $state['unit'] = $units[(int) $data['unit']] ?? throw ValidationException::withMessages(['unit' => 'Choose an available unit.']);
            $step = 'quantity';
        } elseif (($kind === 'sale_quantity' || $kind === 'sale_text') && $step === 'quantity') {
            $raw = (string) ($data['quantity'] ?? trim($message['text']['body'] ?? ''));
            if (! preg_match('/^\d{1,7}(\.\d{1,3})?$/D', $raw) || (float) $raw <= 0 || (float) $raw > 1000000) {
                return $this->text('Enter a quantity greater than 0, up to 1,000,000, with at most 3 decimal places. Or send Cancel.');
            }
            $quantity = (float) $raw;
            $product = $state['product'];
            $unit = $state['unit'];
            $needed = $quantity * (float) $unit['conversion'];
            foreach ($state['items'] as $index => $item) {
                if (($state['editing'] ?? null) === $index) {
                    continue;
                }
                if ($item['product_id'] === $product['id']) {
                    $needed += $item['quantity'] * $item['conversion'];
                }
            }
            if ($needed > (float) $product['stock'] + 0.0005) {
                return $this->text('That quantity exceeds the stock shown. Enter a smaller quantity, or send Cancel.');
            }
            if (! isset($state['editing']) && count($state['items']) >= 200) {
                return $this->text('This cart has reached the POS limit of 200 lines. Send Menu to return to your sale.');
            }
            $index = $state['editing'] ?? count($state['items']);
            $state['items'][$index] = ['product_id' => $product['id'], 'name' => $product['name'], 'quantity' => $quantity,
                'unit' => $unit['name'], 'conversion' => $unit['conversion'], 'line_total' => (int) round($quantity * (int) $unit['price'])];
            unset($state['editing'], $state['suggested_quantity']);
            $state['discount'] = min($state['discount'] ?? 0, array_sum(array_column($state['items'], 'line_total')));
            $step = 'cart';
        } elseif ($kind === 'sale_edit' && $step === 'cart') {
            $step = 'edit';
        } elseif ($kind === 'sale_line' && $step === 'edit') {
            $state['editing'] = (int) $data['line'];
            $step = 'line';
        } elseif ($kind === 'sale_remove' && $step === 'line') {
            array_splice($state['items'], $state['editing'], 1);
            unset($state['editing']);
            $state['discount'] = min($state['discount'] ?? 0, array_sum(array_column($state['items'], 'line_total')));
            $step = $state['items'] === [] ? 'products' : 'cart';
        } elseif ($kind === 'sale_change_quantity' && $step === 'line') {
            $item = $state['items'][$state['editing']];
            $product = collect($this->pos->stock($binding))->firstWhere('id', $item['product_id']);
            if (! $product) {
                throw ValidationException::withMessages(['product' => 'This product is no longer available. Remove it from the cart.']);
            }
            $unit = collect($this->units($product))->firstWhere('name', $item['unit']);
            if (! $unit) {
                throw ValidationException::withMessages(['unit' => 'This unit is no longer available. Remove the line and choose the product again.']);
            }
            $state['product'] = $product;
            $state['unit'] = $unit;
            $step = 'quantity';
        } elseif ($kind === 'sale_cart' && in_array($step, ['cart', 'edit', 'line', 'discount'], true)) {
            unset($state['editing']);
            $step = 'cart';
        } elseif ($kind === 'sale_discount' && $step === 'cart') {
            $step = 'discount';
        } elseif (($kind === 'sale_text' || $kind === 'sale_no_discount') && $step === 'discount') {
            $raw = $kind === 'sale_no_discount' ? '0' : trim($message['text']['body'] ?? '');
            if (! preg_match('/^\d{1,15}$/D', $raw) || (int) $raw > array_sum(array_column($state['items'], 'line_total'))) {
                return $this->text('Enter a whole-number discount from 0 up to the cart subtotal. Or send Cancel.');
            }
            $state['discount'] = (int) $raw;
            $step = 'cart';
        } elseif ($kind === 'sale_checkout' && $state['items'] !== []) {
            $state['payments'] = [];
            $step = 'payment';
        } elseif ($kind === 'sale_payment' && $step === 'payment') {
            $state['method'] = $data['method'];
            $state['payments'] = $data['method'] === 'CREDIT' || $this->total($state) === 0 ? [] : [['method' => $data['method'], 'amount' => $this->total($state)]];
            $step = 'customers';
        } elseif ($kind === 'sale_split' && $step === 'payment') {
            $state['method'] = 'SPLIT';
            $step = $this->total($state) === 0 ? 'customers' : 'payment_method';
        } elseif ($kind === 'sale_part_method' && $step === 'payment_method') {
            $state['part_method'] = $data['method'];
            $step = 'payment_amount';
        } elseif (($kind === 'sale_text' || $kind === 'sale_remaining') && $step === 'payment_amount') {
            $remaining = $this->total($state) - array_sum(array_column($state['payments'], 'amount'));
            $raw = $kind === 'sale_remaining' ? (string) $remaining : trim($message['text']['body'] ?? '');
            if (! preg_match('/^[1-9]\d{0,14}$/D', $raw) || (int) $raw > $remaining) {
                return $this->text('Enter a whole-number payment from 1 to '.$remaining.'. Or send Cancel.');
            }
            $state['payments'][] = ['method' => $state['part_method'], 'amount' => (int) $raw];
            $step = (int) $raw === $remaining ? 'customers' : 'payment_parts';
        } elseif ($kind === 'sale_more_payment' && $step === 'payment_parts' && count($state['payments']) < 10) {
            $step = 'payment_method';
        } elseif ($kind === 'sale_credit_balance' && $step === 'payment_parts') {
            $step = 'customers';
        } elseif ($kind === 'sale_customer' && $step === 'customers') {
            if (($data['customer'] ?? null) === null) {
                if ($this->due($state) > 0) {
                    throw ValidationException::withMessages(['customer' => 'Choose a customer for a credit sale.']);
                }
                $state['customer_id'] = null;
                $state['customer_name'] = 'Walk-in';
            } else {
                $customer = collect($this->pos->customers($binding, $data['search'] ?? ''))->firstWhere('id', (int) $data['customer']);
                if (! $customer) {
                    throw ValidationException::withMessages(['customer' => 'That customer is no longer available.']);
                }
                $state['customer_id'] = $customer['id'];
                $state['customer_name'] = $customer['name'];
            }
            $step = $this->due($state) > 0 ? 'due_date' : 'review';
            if ($step === 'review') {
                $state['payload'] = $this->payload($draft, $state);
            }
        } elseif (($kind === 'sale_text' || $kind === 'sale_no_due_date') && $step === 'due_date') {
            $raw = trim($message['text']['body'] ?? '');
            if ($kind !== 'sale_no_due_date') {
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raw) || ! checkdate((int) substr($raw, 5, 2), (int) substr($raw, 8, 2), (int) substr($raw, 0, 4))) {
                    return $this->text('Enter a real due date as YYYY-MM-DD, or choose No due date from the latest options.');
                }
                $state['due_date'] = $raw;
            } else {
                $state['due_date'] = null;
            }
            $state['payload'] = $this->payload($draft, $state);
            $step = 'review';
        } elseif ($kind === 'sale_confirm' && $step === 'review') {
            $receipt = $this->pos->sell($binding, $state['payload']);
            $state['receipt'] = $receipt;
            $this->save($draft, 'completed', $state);
            $this->advance($conversation);

            return $this->text($binding->name."\nSale recorded: ".$receipt['sale_number']."\nTotal: ".$receipt['total']."\nPaid: ".$receipt['amount_paid']."\nDue: ".$receipt['amount_due']."\n\nSend Menu for your next task.");
        }
        $this->save($draft, $step, $state);
        $this->advance($conversation);
        $rows = [];
        $body = Str::limit($binding->name, 60, '')."\n";
        $page = max(0, (int) ($data['page'] ?? 0));
        $search = $kind === 'sale_text' ? mb_substr(trim($message['text']['body'] ?? ''), 0, 100) : mb_substr($data['search'] ?? '', 0, 100);
        if ($step === 'products') {
            $available = array_values(array_filter($this->pos->stock($binding), fn ($product) => (float) $product['stock'] > 0));
            [$products, $suggested] = app(ChatFlow::class)->findProducts($available, $search);
            $body .= match (true) {
                $suggested => 'Did you mean one of these products? Confirm the product by typing its name or choosing it.',
                $products === [] => 'No in-stock products match. Try another name or SKU.',
                default => 'Choose a product, or type a product name to search.',
            };
            foreach (array_slice($products, $page * 7, 7) as $product) {
                $rows[] = $this->row($conversation, $draft, 'sale_product', $product['name'], ['product' => $product['id']], $product['stock'].' '.$product['base_unit'].' · SKU '.($product['sku'] ?? $product['id']));
            }
            $this->pages($rows, $conversation, $draft, 'sale_products', $page, count($products), $search);
        } elseif ($step === 'units') {
            $body .= $state['product']['name'].' — choose the selling unit.';
            $units = $this->units($state['product']);
            foreach (array_slice($units, $page * 7, 7, true) as $index => $unit) {
                $rows[] = $this->row($conversation, $draft, 'sale_unit', $unit['name'], ['unit' => $index], $unit['price'].' per '.$unit['name']);
            }
            $this->pages($rows, $conversation, $draft, 'sale_units', $page, count($units));
        } elseif ($step === 'quantity') {
            $body .= $state['product']['name'].' · '.$state['unit']['name']."\nChoose a quantity, or type another number.";
            $quantities = ['1', '2', '3', '5', '10'];
            if (isset($state['suggested_quantity'])) {
                array_unshift($quantities, $state['suggested_quantity']);
                $body .= "\nRequested quantity: ".$state['suggested_quantity'].'.';
            }
            foreach (array_unique($quantities) as $quantity) {
                $rows[] = $this->row($conversation, $draft, 'sale_quantity', (string) $quantity, ['quantity' => $quantity]);
            }
        } elseif ($step === 'cart') {
            $body .= $this->summary($state, $page);
            $rows[] = $this->row($conversation, $draft, 'sale_products', 'Add another product');
            $rows[] = $this->row($conversation, $draft, 'sale_checkout', 'Choose payment');
            $body .= "\nType Checkout, or choose another cart action.";
            $rows[] = $this->row($conversation, $draft, 'sale_edit', 'Edit / remove a line');
            $rows[] = $this->row($conversation, $draft, 'sale_discount', 'Order discount');
            $this->pages($rows, $conversation, $draft, 'sale_cart', $page, count($state['items']), '', 6);
        } elseif ($step === 'edit') {
            $body .= 'Choose a cart line to change or remove.';
            foreach (array_slice($state['items'], $page * 6, 6, true) as $index => $item) {
                $rows[] = $this->row($conversation, $draft, 'sale_line', 'Line '.($index + 1).': '.$item['name'], ['line' => $index], $item['quantity'].' '.$item['unit'].' · '.$item['line_total']);
            }
            $this->pages($rows, $conversation, $draft, 'sale_edit_page', $page, count($state['items']), '', 6);
            $rows[] = $this->row($conversation, $draft, 'sale_cart', 'Back to cart');
        } elseif ($step === 'line') {
            $item = $state['items'][$state['editing']];
            $body .= $item['name'].' · '.$item['quantity'].' '.$item['unit'].' · '.$item['line_total'];
            $rows[] = $this->row($conversation, $draft, 'sale_change_quantity', 'Change quantity');
            $rows[] = $this->row($conversation, $draft, 'sale_remove', 'Remove this line');
            $rows[] = $this->row($conversation, $draft, 'sale_cart', 'Back to cart');
        } elseif ($step === 'discount') {
            $body .= 'Subtotal: '.array_sum(array_column($state['items'], 'line_total'))."\nType the order discount amount, as a whole number.";
            $rows[] = $this->row($conversation, $draft, 'sale_no_discount', 'No discount');
            $rows[] = $this->row($conversation, $draft, 'sale_cart', 'Back to cart');
        } elseif ($step === 'payment') {
            $body .= 'How was this sale paid? This records payment; it does not charge a wallet or card.';
            foreach (['CASH' => 'Cash', 'MOBILE_MONEY' => 'Mobile money', 'CARD' => 'Card', 'BANK' => 'Bank', 'OTHER' => 'Other', 'CREDIT' => 'On credit'] as $method => $title) {
                $rows[] = $this->row($conversation, $draft, 'sale_payment', $title, ['method' => $method]);
            }
            $rows[] = $this->row($conversation, $draft, 'sale_split', 'Split / part payment');
        } elseif ($step === 'payment_method') {
            $body .= 'Choose the method for this part payment. No wallet or card will be charged.';
            foreach (['CASH' => 'Cash', 'MOBILE_MONEY' => 'Mobile money', 'CARD' => 'Card', 'BANK' => 'Bank', 'OTHER' => 'Other'] as $method => $title) {
                $rows[] = $this->row($conversation, $draft, 'sale_part_method', $title, ['method' => $method]);
            }
        } elseif ($step === 'payment_amount') {
            $body .= 'Remaining: '.$this->due($state)."\nType the amount paid by ".$state['part_method'].'.';
            $rows[] = $this->row($conversation, $draft, 'sale_remaining', 'Pay remaining balance');
        } elseif ($step === 'payment_parts') {
            $body .= 'Paid: '.array_sum(array_column($state['payments'], 'amount'))."\nRemaining: ".$this->due($state);
            if (count($state['payments']) < 10) {
                $rows[] = $this->row($conversation, $draft, 'sale_more_payment', 'Add another payment');
            }
            $rows[] = $this->row($conversation, $draft, 'sale_credit_balance', 'Rest on credit');
        } elseif ($step === 'customers') {
            $body .= 'Choose a customer, or type a name to search. Showing up to 500 matching customers.';
            if ($this->due($state) === 0 && $page === 0) {
                $rows[] = $this->row($conversation, $draft, 'sale_customer', 'Walk-in / no name', ['customer' => null]);
            }
            $customers = $this->pos->customers($binding, $search);
            foreach (array_slice($customers, $page * 6, 6) as $customer) {
                $rows[] = $this->row($conversation, $draft, 'sale_customer', $customer['name'], ['customer' => $customer['id'], 'search' => $search], $customer['phone'] ?? 'Customer #'.$customer['id']);
            }
            $this->pages($rows, $conversation, $draft, 'sale_customers', $page, count($customers), $search, 6);
        } elseif ($step === 'due_date') {
            $body .= 'Amount on credit: '.$this->due($state)."\nType the repayment due date as YYYY-MM-DD, or leave it unset.";
            $rows[] = $this->row($conversation, $draft, 'sale_no_due_date', 'No due date');
        } elseif ($step === 'review') {
            $details = array_merge($this->lineDetails($state), array_map(fn ($payment) => 'Payment: '.$payment['method'].' · '.$payment['amount'], $state['payments'] ?? []));
            $last = max(0, (int) ceil(count($details) / 6) - 1);
            $page = min($page, $last);
            $body .= 'Review '.($page + 1).'/'.($last + 1)."\nTotal: ".$this->total($state)."\nDiscount: ".($state['discount'] ?? 0)."\nDue: ".$this->due($state)."\nCustomer: ".Str::limit($state['customer_name'], 50, '')."\nDue date: ".($state['due_date'] ?? 'Not set')."\n".implode("\n", array_slice($details, $page * 6, 6));
            $this->pages($rows, $conversation, $draft, 'sale_review_page', $page, count($details), '', 6);
            if ($page === $last) {
                $body .= "\nConfirm to record this sale in your POS.";
                $confirm = $this->row($conversation, $draft, 'sale_confirm', 'Confirm sale');
                $rows[] = $confirm;
                $body .= "\nOr type: ".app(ChatFlow::class)->confirmationCommand($confirm['id']);
            }
        }
        $rows[] = $this->row($conversation, $draft, 'sale_cancel', 'Cancel sale');

        return ['type' => 'interactive', 'interactive' => ['type' => 'list', 'body' => ['text' => Str::limit($body, 1024, '')],
            'action' => ['button' => 'Sale options', 'sections' => [['title' => 'Sale', 'rows' => $rows]]]]];
    }

    private function units(array $product): array
    {
        return array_merge([['name' => $product['base_unit'], 'conversion' => 1, 'price' => $product['selling_price']]],
            array_map(fn ($unit) => ['name' => $unit['unit_name'], 'conversion' => $unit['conversion_to_base_unit'], 'price' => $unit['selling_price']], $product['units'] ?? []));
    }

    private function total(array $state): int
    {
        return (int) array_sum(array_column($state['items'], 'line_total')) - ($state['discount'] ?? 0);
    }

    private function due(array $state): int
    {
        return $this->total($state) - (int) array_sum(array_column($state['payments'] ?? [], 'amount'));
    }

    private function payload(object $draft, array $state): array
    {
        return ['idempotency_key' => 'wa-sale-'.$draft->id, 'customer_id' => $state['customer_id'],
            'expected_total' => $this->total($state), 'discount' => $state['discount'] ?? 0, 'due_date' => $state['due_date'] ?? null,
            'items' => array_map(fn ($item) => array_intersect_key($item, array_flip(['product_id', 'quantity', 'unit'])), $state['items']),
            'payments' => $state['payments'] ?? []];
    }

    private function lineDetails(array $state): array
    {
        return array_map(fn ($item, $index) => Str::limit(($index + 1).': '.$item['quantity'].' '.Str::limit($item['unit'], 12, '').' · '.$item['line_total'].' · '.$item['name'].' #'.$item['product_id'], 90, ''), $state['items'], array_keys($state['items']));
    }

    private function summary(array $state, int $page = 0): string
    {
        return 'Total: '.$this->total($state).' · Discount: '.($state['discount'] ?? 0)."\n".implode("\n", array_slice($this->lineDetails($state), $page * 6, 6));
    }

    private function pages(array &$rows, object $conversation, object $draft, string $kind, int $page, int $count, string $search = '', int $size = 7): void
    {
        foreach ([-1 => 'Previous', 1 => 'Next'] as $offset => $title) {
            $target = $page + $offset;
            if ($target >= 0 && $target * $size < $count) {
                $rows[] = $this->row($conversation, $draft, $kind, $title, ['page' => $target, 'search' => $search]);
            }
        }
    }

    private function row(object $conversation, object $draft, string $kind, string $title, array $data = [], ?string $description = null): array
    {
        $id = (string) Str::uuid();
        DB::table('wa_actions')->insert(['id' => $id, 'conversation_id' => $conversation->id, 'version' => $conversation->version,
            'binding_id' => $draft->binding_id, 'kind' => $kind, 'payload' => Crypt::encryptString(json_encode($data + ['draft' => $draft->id, 'typed_title' => $title], JSON_THROW_ON_ERROR)),
            'expires_at' => $draft->expires_at, 'created_at' => now(), 'updated_at' => now()]);

        return array_filter(['id' => $id, 'title' => Str::limit($title, 24, ''), 'description' => $description ? Str::limit($description, 72, '') : null], fn ($value) => $value !== null);
    }

    private function save(object $draft, string $step, array $state): void
    {
        DB::table('wa_sale_drafts')->where('id', $draft->id)->update(['step' => $step, 'state' => Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR)), 'updated_at' => now()]);
    }

    private function advance(object $conversation): void
    {
        DB::table('wa_conversations')->where('id', $conversation->id)->increment('version');
        $conversation->version++;
    }

    private function text(string $body): array
    {
        return ['type' => 'text', 'text' => ['body' => $body]];
    }
}
