<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Fresh POS receipts, including cashiers' server-enforced own-sale scope. */
class SalesHistory
{
    public function __construct(private PosClient $pos, private ChatFlow $flow) {}

    public function handle(object $conversation, object $binding, object $action): array
    {
        $this->pos->membership($binding->backend, Crypt::decryptString($binding->token), $binding->shop_id);
        $data = $this->flow->decode($action->payload);
        $this->flow->advance($conversation);
        $controls = (object) ['id' => '', 'binding_id' => $binding->id, 'expires_at' => now()->addMinutes(15)];
        $rows = [];
        $body = Str::limit($binding->name, 60, '')."\n";
        if (in_array($action->kind, ['history_receipt', 'history_pdf'], true)) {
            if (isset($data['number'])) {
                $sales = $this->pos->read($binding, 'sales', ['search' => $data['number'], 'per_page' => 100, 'page' => 1]);
                $match = collect($sales['data'] ?? [])->first(fn ($sale) => strcasecmp($sale['sale_number'], $data['number']) === 0);
                if ($match === null) {
                    throw ValidationException::withMessages(['receipt' => 'That receipt was not found in the accessible sales. Open Sales history and choose it from the list.']);
                }
                $data['sale'] = $match['id'];
            }
            $receipt = $this->pos->read($binding, 'sale', [], (int) $data['sale']);
            $lines = [];
            foreach ($receipt['items'] ?? [] as $item) {
                $lines[] = ($item['quantity'] ?? '').' '.($item['unit'] ?? $item['unit_name'] ?? '').' · '.($item['product_name'] ?? $item['name'] ?? 'Product #'.$item['product_id']).' · '.($item['line_total'] ?? $item['total'] ?? '');
            }
            foreach ($receipt['payments'] ?? [] as $payment) {
                $lines[] = 'Payment: '.$payment['method'].' · '.$payment['amount'];
            }
            if ($action->kind === 'history_pdf') {
                $summary = ['Status: '.$receipt['status'], 'Total: '.$receipt['total'],
                    'Paid at sale: '.$receipt['amount_paid'], 'Original due: '.$receipt['amount_due'],
                    'Customer: '.($receipt['customer']['name'] ?? 'Walk-in')];
                if (isset($receipt['outstanding'])) {
                    $summary[] = 'Outstanding now: '.$receipt['outstanding'];
                }

                return app(ChatDocument::class)->make($binding->name, 'Receipt '.$receipt['sale_number'], [...$summary, ...$lines], 'receipt-'.$receipt['sale_number']);
            }
            $rows[] = $this->flow->row($conversation, $controls, 'history_pdf', 'Receipt PDF', ['sale' => $receipt['id']]);
            $page = min(max(0, (int) ($data['page'] ?? 0)), max(0, (int) ceil(count($lines) / 5) - 1));
            $body .= Str::limit($receipt['sale_number'], 60, '').' · '.$receipt['status']."\nTotal: ".$receipt['total']."\nPaid at sale: ".$receipt['amount_paid']."\nOriginal due: ".$receipt['amount_due'];
            if (isset($receipt['outstanding'])) {
                $body .= "\nOutstanding now: ".$receipt['outstanding'];
            }
            $body .= "\nCustomer: ".Str::limit($receipt['customer']['name'] ?? 'Walk-in', 80, '')."\n".implode("\n", array_map(fn ($line) => Str::limit($line, 100, ''), array_slice($lines, $page * 5, 5)));
            foreach ([-1 => 'Previous receipt lines', 1 => 'More receipt lines'] as $offset => $title) {
                if ($page + $offset >= 0 && ($page + $offset) * 5 < count($lines)) {
                    $rows[] = $this->flow->row($conversation, $controls, 'history_receipt', $title, ['sale' => $receipt['id'], 'page' => $page + $offset]);
                }
            }
            $rows[] = $this->flow->row($conversation, $controls, 'history_list', 'Back to sales');
        } else {
            $page = max(1, (int) ($data['page'] ?? 1));
            $result = $this->pos->read($binding, 'sales', ['per_page' => 6, 'page' => $page]);
            $body .= 'Sales history · page '.$page."\nChoose a receipt. Your POS controls which sales you can view.";
            foreach ($result['data'] ?? [] as $sale) {
                $rows[] = $this->flow->row($conversation, $controls, 'history_receipt', $sale['sale_number'], ['sale' => $sale['id']],
                    $sale['status'].' · '.$sale['total'].' · '.($sale['customer']['name'] ?? 'Walk-in'));
            }
            if (empty($result['data'])) {
                $body .= "\nNo sales on this page.";
            }
            foreach (['prev_page_url' => $page - 1, 'next_page_url' => $page + 1] as $key => $target) {
                if (! empty($result[$key])) {
                    $rows[] = $this->flow->row($conversation, $controls, 'history_list', $key === 'prev_page_url' ? 'Previous sales' : 'Next sales', ['page' => $target]);
                }
            }
        }
        $rows[] = $this->flow->row($conversation, $controls, 'menu', 'Back to menu');

        return $this->flow->menu($body, $rows, 'Sales history');
    }
}
