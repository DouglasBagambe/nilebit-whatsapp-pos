<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WhatsAppConversation
{
    /** Internal verified linking service; no public token-ingestion endpoint. */
    public function link(int $conversationId, string $backend, string $token, int $shopId): int
    {
        $member = app(PosClient::class)->membership($backend, $token, $shopId);

        return DB::transaction(function () use ($conversationId, $backend, $token, $member): int {
            DB::table('wa_conversations')->where('id', $conversationId)->lockForUpdate()->firstOrFail();
            $identity = ['conversation_id' => $conversationId, 'backend' => $backend, 'shop_id' => $member['shop_id'], 'user_id' => $member['user_id']];
            DB::table('wa_business_bindings')->updateOrInsert($identity, [
                'name' => $member['name'], 'role' => $member['role'], 'token' => Crypt::encryptString($token),
                'revoked_at' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('wa_conversations')->where('id', $conversationId)->increment('version');

            return DB::table('wa_business_bindings')->where($identity)->value('id');
        });
    }

    /** @param array<string, mixed> $message @return array<string, mixed> */
    public function reply(object $conversation, array $message): array
    {
        $reply = $message['interactive']['list_reply']['id'] ?? $message['interactive']['button_reply']['id'] ?? null;
        $rawText = trim($message['text']['body'] ?? '');
        $text = mb_strtolower($rawText);
        $flow = app(ChatFlow::class);
        if (! $reply && $text !== 'menu') {
            $reply = $flow->typedAction($conversation, $text);
        }
        if (! $reply && $text !== 'menu' && app(ChatFlow::class)->pending($conversation)) {
            $pending = app(ChatFlow::class)->pending($conversation);

            return str_starts_with($pending->kind, 'record:')
                ? app(RecordWizard::class)->handle($conversation, null, $message)
                : app(GuidedCustomer::class)->handle($conversation, null, $message);
        }
        if (! $reply && $text !== 'menu' && app(GuidedSale::class)->pending($conversation)) {
            if ($text === 'cancel') {
                $draft = app(GuidedSale::class)->pending($conversation);
                $action = (object) ['kind' => 'sale_cancel', 'payload' => Crypt::encryptString(json_encode(['draft' => $draft->id]))];

                return app(GuidedSale::class)->handle($conversation, $action, $message);
            }

            return app(GuidedSale::class)->handle($conversation, null, $message);
        }
        if (! $reply && ($binding = $this->activeBinding($conversation))) {
            $command = $flow->normalize($text);
            if (preg_match('/^(?:check |show |view )?(?:stock|inventory)(?: (?:for |of )?(.*))?$/iu', $rawText, $matches)) {
                return app(StockBrowser::class)->handle($conversation, $binding, null, $matches[1] ?? '');
            }
            if (preg_match('/^(?:sell|record (?:a )?sale)(?:\s+(.*))?$/iu', $rawText, $matches)) {
                $search = trim($matches[1] ?? '');
                $quantity = null;
                if (preg_match('/^(\d{1,7}(?:\.\d{1,3})?)\s+(.+)$/u', $search, $line)
                    && (float) $line[1] > 0 && (float) $line[1] <= 1000000) {
                    [, $quantity, $search] = $line;
                }

                return app(GuidedSale::class)->handle($conversation, $this->typedCommand('sale_start', ['search' => $search, 'quantity' => $quantity]), $message);
            }
            if (preg_match('/^(?:show |view )?(dashboard|today at a glance|(?:sales|profit) (?:report|summary)|debt report|customer debt)(?: (today|last 7 days|this week|this month))?$/', $command, $matches)) {
                $resource = match ($matches[1]) {
                    'dashboard', 'today at a glance' => 'dashboard',
                    'debt report', 'customer debt' => 'debt_report',
                    'sales report', 'sales summary' => 'sales_report',
                    default => 'profit_report',
                };
                $period = match ($matches[2] ?? '') {
                    'today' => 'today', 'last 7 days' => 'week', 'this week' => 'calendar_week', 'this month' => 'month', default => null,
                };

                return app(ReportBrowser::class)->handle($conversation, $binding, $this->typedCommand('report_open', ['resource' => $resource, 'period' => $period]));
            }
            if (preg_match('/^(?:debt(?: for)?|customers?)(?: (.*))?$/iu', $rawText, $matches)) {
                return app(CustomerBrowser::class)->handle($conversation, $binding, null, $matches[1] ?? '');
            }
            foreach (app(RecordForms::class)->definitions($binding->backend) as $key => $definition) {
                if ($command === $flow->normalize($definition['title'])) {
                    return app(RecordWizard::class)->handle($conversation, $this->typedCommand('record_start', ['form' => $key]), $message);
                }
            }
            $route = match ($command) {
                'reports', 'show reports' => [ReportBrowser::class, 'report_menu'],
                'sales history', 'sales receipts', 'receipts' => [SalesHistory::class, 'history_list'],
                'recent tasks', 'activity' => [RecentTasks::class, 'activity_list'],
                default => null,
            };
            if ($route !== null) {
                return app($route[0])->handle($conversation, $binding, $this->typedCommand($route[1]));
            }
            if (in_array($command, ['business tasks', 'tasks'], true)) {
                return app(RecordWizard::class)->handle($conversation, $this->typedCommand('record_menu'), $message);
            }
        }
        $showProfiles = ! $reply && in_array($flow->normalize($text), ['switch business', 'switch shop', 'businesses'], true);
        $warning = ! $reply && $text !== '' && ! $showProfiles && ! in_array($flow->normalize($text), ['menu', 'hi', 'hello', 'hey', 'help'], true)
            ? "I couldn't match that request. Try Stock mango, Sell, Reports, Sales history, or Business tasks.\n\n"
            : '';
        $page = 0;
        if ($reply) {
            $action = DB::table('wa_actions')->where('id', $reply)->where('conversation_id', $conversation->id)->first();
            if (! $action || $action->version !== $conversation->version || now()->greaterThan($action->expires_at)) {
                $warning = "That option has expired. Check Recent tasks if a previous confirmation timed out.\n\n";
            } elseif (str_starts_with($action->kind, 'disconnect_')) {
                if ($binding = $this->activeBinding($conversation)) {
                    return app(DisconnectAccount::class)->handle($conversation, $binding, $action);
                }
            } elseif (str_starts_with($action->kind, 'history_')) {
                if ($binding = $this->activeBinding($conversation)) {
                    return app(SalesHistory::class)->handle($conversation, $binding, $action);
                }
            } elseif (str_starts_with($action->kind, 'report_')) {
                if ($binding = $this->activeBinding($conversation)) {
                    return app(ReportBrowser::class)->handle($conversation, $binding, $action);
                }
            } elseif (str_starts_with($action->kind, 'activity_')) {
                if ($binding = $this->activeBinding($conversation)) {
                    return app(RecentTasks::class)->handle($conversation, $binding, $action);
                }
            } elseif (str_starts_with($action->kind, 'record_')) {
                return app(RecordWizard::class)->handle($conversation, $action, $message);
            } elseif (str_starts_with($action->kind, 'contact_')) {
                return app(GuidedCustomer::class)->handle($conversation, $action, $message);
            } elseif (str_starts_with($action->kind, 'customer_')) {
                if ($binding = $this->activeBinding($conversation)) {
                    return app(CustomerBrowser::class)->handle($conversation, $binding, $action);
                }
            } elseif ($action->kind === 'connect') {
                return ['type' => 'text', 'text' => ['body' => 'Connect another Retail or Production account securely:'."\n".app(BusinessLink::class)->issue($conversation->id)."\nOnly open this link yourself."]];
            } elseif (str_starts_with($action->kind, 'sale_')) {
                return app(GuidedSale::class)->handle($conversation, $action, $message);
            } elseif ($action->kind === 'switch') {
                $binding = DB::table('wa_business_bindings')->where('id', $action->binding_id)->where('conversation_id', $conversation->id)->whereNull('revoked_at')->firstOrFail();
                app(PosClient::class)->membership($binding->backend, Crypt::decryptString($binding->token), $binding->shop_id);
                DB::table('wa_conversations')->where('id', $conversation->id)->update(['binding_id' => $binding->id]);
                $conversation->binding_id = $binding->id;
            } elseif ($action->kind === 'profiles') {
                $showProfiles = true;
                $page = (int) $action->page;
            } elseif ($action->kind === 'stock' || str_starts_with($action->kind, 'stock_')) {
                if ($binding = $this->activeBinding($conversation)) {
                    return app(StockBrowser::class)->handle($conversation, $binding, $action);
                }
            }
        }
        DB::table('wa_conversations')->where('id', $conversation->id)->increment('version');
        $conversation->version++;
        $bindings = DB::table('wa_business_bindings')->where('conversation_id', $conversation->id)->whereNull('revoked_at')->orderBy('id')->get();
        if ($bindings->isEmpty()) {
            return ['type' => 'text', 'text' => ['body' => "Welcome to NileBit POS. Connect your existing Retail or Production account securely:\n".app(BusinessLink::class)->issue($conversation->id)."\n\nOnly open this link yourself. Never send your password in chat."]];
        }
        $active = $this->activeBinding($conversation);
        $rows = [];
        if ($active && ! $showProfiles) {
            $rows[] = ['id' => $this->action($conversation, 'sale_start', $active->id), 'title' => 'Sell', 'description' => 'Choose products, units and payment'];
            $rows[] = ['id' => $this->action($conversation, 'stock', $active->id), 'title' => 'View stock', 'description' => 'Current stock from '.$active->backend.' POS'];
            $rows[] = ['id' => $this->action($conversation, 'customer_start', $active->id), 'title' => 'Customers & debt', 'description' => 'View customers and current balances'];
            $rows[] = ['id' => $this->action($conversation, 'record_menu', $active->id), 'title' => 'Business tasks', 'description' => 'Stock, suppliers, expenses and shifts'];
            $rows[] = ['id' => $this->action($conversation, 'history_list', $active->id), 'title' => 'Sales & receipts'];
            $rows[] = ['id' => $this->action($conversation, 'report_menu', $active->id), 'title' => 'Reports', 'description' => 'Sales, debt and permitted profit figures'];
            $rows[] = ['id' => $this->action($conversation, 'activity_list', $active->id), 'title' => 'Recent tasks', 'description' => 'Results, saved drafts and timeout recovery'];
            $rows[] = ['id' => $this->action($conversation, 'disconnect_start', $active->id), 'title' => 'Disconnect account'];
            $rows[] = ['id' => $this->action($conversation, 'profiles'), 'title' => 'Switch business'];
        }
        $rows[] = ['id' => $this->action($conversation, 'connect'), 'title' => 'Connect a business', 'description' => 'Add a Retail or Production account'];
        if (! $active || $showProfiles) {
            $page = min($page, max(0, (int) ceil($bindings->count() / 5) - 1));
            foreach ($bindings->slice($page * 5, 5) as $binding) {
                $rows[] = ['id' => $this->action($conversation, 'switch', $binding->id, typedTitle: $binding->name), 'title' => Str::limit($binding->name, 24, ''), 'description' => ucfirst($binding->backend).' · Switch business'];
            }

            if ($page > 0) {
                $rows[] = ['id' => $this->action($conversation, 'profiles', null, $page - 1), 'title' => 'Previous businesses'];
            }
            if (($page + 1) * 5 < $bindings->count()) {
                $rows[] = ['id' => $this->action($conversation, 'profiles', null, $page + 1), 'title' => 'More businesses'];
            }
        }

        return ['type' => 'interactive', 'interactive' => [
            'type' => 'list', 'body' => ['text' => $warning.($active ? 'Active: '.$active->name : 'Choose your business')."\nChoose an option or type a command, such as Stock, Sell, Reports or Business tasks."],
            'action' => ['button' => 'POS options', 'sections' => [['title' => 'Business profiles', 'rows' => $rows]]],
        ]];
    }

    private function typedCommand(string $kind, array $payload = []): object
    {
        return (object) ['kind' => $kind, 'payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR))];
    }

    private function activeBinding(object $conversation): ?object
    {
        return DB::table('wa_business_bindings')->where('id', $conversation->binding_id)->where('conversation_id', $conversation->id)->whereNull('revoked_at')->first();
    }

    private function action(object $conversation, string $kind, ?int $bindingId = null, int $page = 0, ?string $typedTitle = null): string
    {
        $id = (string) Str::uuid();
        DB::table('wa_actions')->insert(['id' => $id, 'conversation_id' => $conversation->id, 'version' => $conversation->version,
            'kind' => $kind, 'binding_id' => $bindingId, 'page' => $page, 'payload' => $typedTitle === null ? null : Crypt::encryptString(json_encode(['typed_title' => $typedTitle], JSON_THROW_ON_ERROR)), 'expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
