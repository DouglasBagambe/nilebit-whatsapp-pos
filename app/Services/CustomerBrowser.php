<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Customer/debt view with explicit entry points to confirmed write flows. */
class CustomerBrowser
{
    public function __construct(private PosClient $pos) {}

    public function handle(object $conversation, object $binding, ?object $action = null, string $search = ''): array
    {
        $this->pos->membership($binding->backend, Crypt::decryptString($binding->token), $binding->shop_id);
        $data = $action?->payload ? json_decode(Crypt::decryptString($action->payload), true, flags: JSON_THROW_ON_ERROR) : [];
        $search = mb_substr($data['search'] ?? $search, 0, 100);
        DB::table('wa_conversations')->where('id', $conversation->id)->increment('version');
        $conversation->version++;
        if ($action?->kind === 'customer_detail') {
            $customer = $this->pos->customer($binding, (int) $data['customer']);
            $balance = (int) $customer['balance'];
            $amount = $balance >= 0 ? 'Owes: '.$balance : 'Credit balance: '.abs($balance);
            $body = Str::limit($binding->name, 60, '')."\n".Str::limit($customer['name'], 100, '')."\n".$amount;
            if (! empty($customer['phone'])) {
                $body .= "\nPhone: ".$customer['phone'];
            }
            if ($customer['overdue'] ?? false) {
                $body .= "\nPayment overdue";
            }
            $body .= "\n\nCurrent balance from your POS. Send Menu to return.";

            $rows = [];
            if ($balance > 0) {
                $rows[] = $this->row($conversation, $binding, 'contact_repay', 'Record repayment', ['customer' => $customer['id']]);
            }
            $rows[] = $this->row($conversation, $binding, 'customer_start', 'Back to customers');
            $rows[] = $this->row($conversation, $binding, 'menu', 'Back to menu');

            return app(ChatFlow::class)->menu($body, $rows, 'Customer options');
        }
        $customers = $this->pos->customers($binding, $search);
        $owing = (bool) ($data['owing'] ?? false);
        if ($owing) {
            $customers = array_values(array_filter($customers, fn ($customer) => (int) $customer['balance'] > 0));
        }
        $page = min(max(0, (int) ($data['page'] ?? 0)), max(0, (int) ceil(count($customers) / 5) - 1));
        $rows = [];
        foreach (array_slice($customers, $page * 5, 5) as $customer) {
            $rows[] = $this->row($conversation, $binding, 'customer_detail', $customer['name'], ['customer' => $customer['id']],
                'Balance: '.$customer['balance'].' · '.($customer['phone'] ?? 'Customer #'.$customer['id']));
        }
        foreach ([-1 => 'Previous customers', 1 => 'Next customers'] as $offset => $title) {
            $target = $page + $offset;
            if ($target >= 0 && $target * 5 < count($customers)) {
                $rows[] = $this->row($conversation, $binding, 'customer_page', $title, ['page' => $target, 'search' => $search, 'owing' => $owing]);
            }
        }
        $rows[] = $this->row($conversation, $binding, 'customer_page', $owing ? 'All customers' : 'Only debtors', ['search' => $search, 'owing' => ! $owing]);
        $rows[] = $this->row($conversation, $binding, 'contact_new', 'Add customer');
        $rows[] = $this->row($conversation, $binding, 'menu', 'Back to menu');
        $body = Str::limit($binding->name, 60, '')."\n".($owing ? 'Customers who owe you' : 'Customers and balances');
        $body .= $customers === [] ? "\nNo matching customers." : "\nChoose a customer to see their current balance.";
        $body .= "\nSearch by typing: debt Sarah\nUp to 500 matching customers are loaded.";

        return ['type' => 'interactive', 'interactive' => ['type' => 'list', 'body' => ['text' => $body],
            'action' => ['button' => 'Customers', 'sections' => [['title' => 'Customers', 'rows' => $rows]]]]];
    }

    private function row(object $conversation, object $binding, string $kind, string $title, array $data = [], ?string $description = null): array
    {
        $id = (string) Str::uuid();
        DB::table('wa_actions')->insert(['id' => $id, 'conversation_id' => $conversation->id, 'version' => $conversation->version,
            'kind' => $kind, 'binding_id' => $binding->id, 'payload' => Crypt::encryptString(json_encode($data + ['typed_title' => $title], JSON_THROW_ON_ERROR)),
            'expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now()]);

        return array_filter(['id' => $id, 'title' => Str::limit($title, 24, ''), 'description' => $description ? Str::limit($description, 72, '') : null], fn ($value) => $value !== null);
    }
}
