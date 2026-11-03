<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Reconciles local drafts with the authoritative POS after an unknown outcome. */
class RecentTasks
{
    public function __construct(private PosClient $pos, private ChatFlow $flow) {}

    public function handle(object $conversation, object $binding, object $action): array
    {
        $this->pos->membership($binding->backend, Crypt::decryptString($binding->token), $binding->shop_id);
        $data = $this->flow->decode($action->payload);
        $this->flow->advance($conversation);
        $controls = (object) ['id' => '', 'binding_id' => $binding->id, 'expires_at' => now()->addMinutes(15)];
        if ($action->kind === 'activity_detail') {
            $table = ($data['type'] ?? '') === 'sale' ? 'wa_sale_drafts' : 'wa_operations';
            $task = DB::table($table)->where('id', $data['task'])->where('conversation_id', $conversation->id)->where('binding_id', $binding->id)->firstOrFail();
            $state = $this->flow->decode($task->state);
            if ($task->step === 'review') {
                $key = ($table === 'wa_sale_drafts' ? 'wa-sale-' : 'wa-operation-').$task->id;
                $receipt = $this->pos->operation($binding, $key);
                if ($receipt !== null) {
                    $state['receipt'] = $receipt;
                    DB::table($table)->where('id', $task->id)->update(['step' => 'completed', 'state' => Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR)), 'updated_at' => now()]);
                    $task->step = 'completed';
                }
            }
            $body = Str::limit($binding->name, 60, '')."\nTask: ".($task->kind ?? 'sale')."\nStatus: ".$task->step;
            $rows = [];
            if ($task->step === 'completed') {
                $receipt = $state['receipt'] ?? [];
                $body .= "\nRecorded result (at the time saved):";
                foreach (['sale_number', 'purchase_number', 'batch_number', 'id', 'name', 'total', 'amount_paid', 'amount_due', 'balance'] as $field) {
                    if (isset($receipt[$field]) && is_scalar($receipt[$field])) {
                        $body .= "\n".$field.': '.Str::limit((string) $receipt[$field], 100, '');
                    }
                }
            } elseif (! in_array($task->step, ['cancelled', 'failed'], true)) {
                $other = $this->flow->pending($conversation);
                $sale = app(GuidedSale::class)->pending($conversation);
                if (($other && ($table !== 'wa_operations' || $other->id !== $task->id)) || ($sale && ($table !== 'wa_sale_drafts' || $sale->id !== $task->id))) {
                    $body .= "\nFinish or cancel the active task before resuming this one.";
                } else {
                    DB::table($table)->where('id', $task->id)->update(['expires_at' => now()->addMinutes(30)]);
                    $task->expires_at = now()->addMinutes(30);
                    $prefix = $table === 'wa_sale_drafts' ? 'sale' : (str_starts_with($task->kind, 'record:') ? 'record' : 'contact');
                    $payload = $table === 'wa_sale_drafts' ? ['draft' => $task->id] : ['operation' => $task->id];
                    $rows[] = $this->flow->row($conversation, $task, $prefix.'_resume', 'Resume same task', $payload);
                    $body .= "\nResume keeps the same operation ID. It does not start a duplicate task.";
                }
            }
            $rows[] = $this->flow->row($conversation, $controls, 'activity_list', 'Back to recent tasks');
            $rows[] = $this->flow->row($conversation, $controls, 'menu', 'Back to menu');

            return $this->flow->menu($body, $rows, 'Task options');
        }
        $base = fn (string $table) => DB::table($table)->where('conversation_id', $conversation->id)->where('binding_id', $binding->id);
        $sales = $base('wa_sale_drafts')->select('id', 'step', 'updated_at', DB::raw("'sale' as kind"), DB::raw("'sale' as type"));
        $tasks = $base('wa_operations')->select('id', 'step', 'updated_at', 'kind', DB::raw("'operation' as type"))->unionAll($sales);
        $page = max(1, (int) ($data['page'] ?? 1));
        $result = DB::query()->fromSub($tasks, 'tasks')->orderByDesc('updated_at')->orderBy('id')->paginate(6, ['*'], 'page', $page);
        $rows = [];
        foreach ($result->items() as $task) {
            $title = $task->kind === 'sale' ? 'Sale' : str_replace(['record:', '_'], ['', ' '], $task->kind);
            $rows[] = $this->flow->row($conversation, $controls, 'activity_detail', $title, ['type' => $task->type, 'task' => $task->id], $task->step.' · '.$task->updated_at);
        }
        foreach ([-1 => 'Previous tasks', 1 => 'Next tasks'] as $offset => $label) {
            if ($page + $offset > 0 && $page + $offset <= $result->lastPage()) {
                $rows[] = $this->flow->row($conversation, $controls, 'activity_list', $label, ['page' => $page + $offset]);
            }
        }
        $rows[] = $this->flow->row($conversation, $controls, 'menu', 'Back to menu');

        return $this->flow->menu(Str::limit($binding->name, 60, '')."\nRecent tasks and saved drafts. Choose one to check its result or resume it.", $rows, 'Recent tasks');
    }
}
