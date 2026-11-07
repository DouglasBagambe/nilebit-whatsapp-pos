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
        $text = mb_strtolower(trim($message['text']['body'] ?? ''));
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
        if (! $reply && str_starts_with($text, 'debt ') && ($binding = $this->activeBinding($conversation))) {
            return app(CustomerBrowser::class)->handle($conversation, $binding, null, trim(mb_substr($message['text']['body'], 5)));
        }
        if (! $reply && ($text === 'stock' || str_starts_with($text, 'stock ')) && ($binding = $this->activeBinding($conversation))) {
            return app(StockBrowser::class)->handle($conversation, $binding, null, trim(mb_substr(trim($message['text']['body']), 6)));
        }
        $showProfiles = false;
        $warning = '';
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
                $rows[] = ['id' => $this->action($conversation, 'switch', $binding->id), 'title' => Str::limit($binding->name, 24, ''), 'description' => ucfirst($binding->backend).' · Switch business'];
            }

            if ($page > 0) {
                $rows[] = ['id' => $this->action($conversation, 'profiles', null, $page - 1), 'title' => 'Previous businesses'];
            }
            if (($page + 1) * 5 < $bindings->count()) {
                $rows[] = ['id' => $this->action($conversation, 'profiles', null, $page + 1), 'title' => 'More businesses'];
            }
        }

        return ['type' => 'interactive', 'interactive' => [
            'type' => 'list', 'body' => ['text' => $warning.($active ? 'Active: '.$active->name : 'Choose your business')."\nChoose what you would like to do."],
            'action' => ['button' => 'POS options', 'sections' => [['title' => 'Business profiles', 'rows' => $rows]]],
        ]];
    }

    private function activeBinding(object $conversation): ?object
    {
        return DB::table('wa_business_bindings')->where('id', $conversation->binding_id)->where('conversation_id', $conversation->id)->whereNull('revoked_at')->first();
    }

    private function action(object $conversation, string $kind, ?int $bindingId = null, int $page = 0): string
    {
        $id = (string) Str::uuid();
        DB::table('wa_actions')->insert(['id' => $id, 'conversation_id' => $conversation->id, 'version' => $conversation->version,
            'kind' => $kind, 'binding_id' => $bindingId, 'page' => $page, 'expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}
