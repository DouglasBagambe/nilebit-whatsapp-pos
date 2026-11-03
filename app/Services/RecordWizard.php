<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordWizard
{
    public function __construct(private PosClient $pos, private ChatFlow $flow, private RecordForms $forms) {}

    public function handle(object $conversation, ?object $action, array $message): array
    {
        $binding = DB::table('wa_business_bindings')->where('id', $conversation->binding_id)->where('conversation_id', $conversation->id)->whereNull('revoked_at')->firstOrFail();
        $membership = $this->pos->membership($binding->backend, Crypt::decryptString($binding->token), $binding->shop_id);
        $definitions = $this->forms->definitions($binding->backend);
        $data = $this->flow->decode($action?->payload);
        $kind = $action?->kind ?? 'record_text';
        if ($kind === 'record_menu') {
            $this->flow->advance($conversation);
            $rows = [];
            $page = max(0, (int) ($data['page'] ?? 0));
            $available = array_filter($definitions, fn ($form) => $membership['role'] !== 'cashier' || ($form['cashier'] ?? false));
            foreach (array_slice($available, $page * 7, 7, true) as $key => $definition) {
                $rows[] = $this->flow->row($conversation, (object) ['id' => '', 'binding_id' => $binding->id, 'expires_at' => now()->addMinutes(15)], 'record_start', $definition['title'], ['form' => $key]);
            }
            foreach ([-1 => 'Previous tasks', 1 => 'More tasks'] as $offset => $label) {
                if ($page + $offset >= 0 && ($page + $offset) * 7 < count($available)) {
                    $rows[] = $this->flow->row($conversation, (object) ['id' => '', 'binding_id' => $binding->id, 'expires_at' => now()->addMinutes(15)], 'record_menu', $label, ['page' => $page + $offset]);
                }
            }
            $rows[] = $this->flow->row($conversation, (object) ['id' => '', 'binding_id' => $binding->id, 'expires_at' => now()->addMinutes(15)], 'menu', 'Back to menu');

            return $this->flow->menu(Str::limit($binding->name, 60, '')."\nBusiness tasks", $rows, 'Business tasks');
        }
        $operation = $this->flow->pending($conversation);
        if ($kind === 'record_start') {
            if ($operation || app(GuidedSale::class)->pending($conversation)) {
                return $this->flow->text('Finish or cancel your current task before starting another. Open Recent tasks to resume it.');
            }
            $formKey = $data['form'];
            $definition = $definitions[$formKey] ?? throw ValidationException::withMessages(['task' => 'Choose an available task.']);
            $this->authorize($membership, $definition);
            $state = ['values' => [], 'labels' => [], 'selected' => []];
            if ($definition['current_shift'] ?? false) {
                $shift = $this->pos->read($binding, 'current_shift')['shift'] ?? null;
                if (! $shift) {
                    return $this->flow->text('You have no open shift in this business. Nothing was changed. Send Menu to return.');
                }
                $state['target'] = $shift['id'];
            }
            $operation = $this->flow->create($conversation, 'record:'.$formKey, '0', $state);
        } elseif (! $operation || ! str_starts_with($operation->kind, 'record:') || ($action && ($data['operation'] ?? null) !== $operation->id)) {
            return $this->flow->text('That task has expired or was completed. Send Menu to start again.');
        }
        $definition = $definitions[substr($operation->kind, 7)];
        $this->authorize($membership, $definition);
        $state = $this->flow->decode($operation->state);
        $text = trim($message['text']['body'] ?? '');
        $step = $operation->step;
        if ($kind === 'record_cancel' || ($kind === 'record_text' && mb_strtolower($text) === 'cancel')) {
            if ($step === 'review') {
                $recorded = $this->pos->operation($binding, 'wa-operation-'.$operation->id);
                if ($recorded !== null) {
                    $state['receipt'] = $recorded;
                    DB::table('wa_operations')->where('id', $operation->id)->update(['step' => 'completed', 'state' => Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR)), 'updated_at' => now()]);
                    $this->flow->advance($conversation);

                    return $this->flow->text('This task was already recorded in the POS. It was not cancelled. Open Recent tasks to see the result.');
                }
            }

            $this->flow->save($operation, 'cancelled', $state);
            $this->flow->advance($conversation);

            return $this->flow->text('Task cancelled. Nothing was recorded. Send Menu to return.');
        }
        if ($kind === 'record_confirm' && $step === 'review') {
            $receipt = $this->pos->record($binding, $definition['command'], $state['payload'], 'wa-operation-'.$operation->id, $state['target'] ?? null);
            $state['receipt'] = $receipt;
            $this->flow->save($operation, 'completed', $state);
            $this->flow->advance($conversation);

            return $this->flow->text($definition['title'].' recorded in '.Str::limit($binding->name, 60, '').".\nRecord: ".($receipt['purchase_number'] ?? $receipt['batch_number'] ?? $receipt['id'] ?? 'saved')."\nSend Menu for your next task.");
        }
        if (str_starts_with($step, 'lines:') && in_array($kind, ['record_line_more', 'record_line_done'], true)) {
            $parentIndex = (int) substr($step, 6);
            $parent = $definition['fields'][$parentIndex];
            $limit = in_array($parent['key'], ['outputs', 'direct_expenses'], true) ? 50 : 100;
            if ($kind === 'record_line_more' && count($state['values'][$parent['key']] ?? []) >= $limit) {
                throw ValidationException::withMessages(['lines' => 'This collection has reached its POS limit. Choose Done with lines.']);
            }
            $step = $kind === 'record_line_more' ? (string) $parentIndex : (string) ($parentIndex + 1);
        } elseif ($step !== 'review' && ! str_starts_with($step, 'lines:')) {
            $parent = $definition['fields'][(int) $step];
            $repeating = isset($parent['fields']);
            $field = $repeating ? $parent['fields'][$state['line_step'] ?? 0] : $parent;
            if ($kind === 'record_skip_collection') {
                if (! ($parent['optional'] ?? false) || ! empty($state['values'][$parent['key']])) {
                    throw ValidationException::withMessages(['field' => 'This collection cannot be skipped.']);
                }
                $step = (string) ((int) $step + 1);
            } else {
                if ($kind === 'record_text' && isset($field['resource'])) {
                    $kind = 'record_search';
                }
                if (in_array($kind, ['record_text', 'record_value', 'record_skip'], true)) {
                    if ($kind === 'record_skip' && ! ($field['optional'] ?? false)) {
                        throw ValidationException::withMessages(['field' => 'This value is required.']);
                    }
                    if ($kind !== 'record_skip') {
                        $value = $data['value'] ?? $text;
                        Validator::make(['value' => $value], ['value' => $field['rules']])->validate();
                        $choices = $this->choices($field, $state, $repeating);
                        if ($choices !== null && ! array_key_exists($value, $choices)) {
                            throw ValidationException::withMessages(['field' => 'Choose one of the available options.']);
                        }
                        $valuesKey = $repeating ? 'line_values' : 'values';
                        $labelsKey = $repeating ? 'line_labels' : 'labels';
                        $selectedKey = $repeating ? 'line_selected' : 'selected';
                        $state[$valuesKey][$field['key']] = $value;
                        $state[$labelsKey][$field['key']] = Str::limit((string) ($data['label'] ?? $choices[$value] ?? $value), 100, '');
                        if (isset($data['selected'])) {
                            $state[$selectedKey][$field['key']] = $data['selected'];
                        }
                    }
                    if ($repeating) {
                        $state['line_step'] = ($state['line_step'] ?? 0) + 1;
                        if ($state['line_step'] >= count($parent['fields'])) {
                            $state['values'][$parent['key']][] = $state['line_values'] ?? [];
                            $summary = [];
                            foreach ($state['line_labels'] ?? [] as $key => $label) {
                                $summary[] = $key.': '.$label;
                            }
                            $state['labels'][$parent['key']][] = implode(' · ', $summary);
                            unset($state['line_step'], $state['line_values'], $state['line_labels'], $state['line_selected']);
                            $step = 'lines:'.$step;
                        }
                    } else {
                        $step = (string) ((int) $step + 1);
                    }
                }
            }
        }
        if ($step !== 'review' && ! str_starts_with($step, 'lines:') && (int) $step >= count($definition['fields'])) {
            $state['payload'] = $state['values'] + ($definition['defaults'] ?? []);
            if (isset($definition['target'])) {
                $targetKey = $definition['target'];
                $state['target'] = (int) $state['payload'][$targetKey];
                unset($state['payload'][$targetKey]);
                if ($definition['guard_revision'] ?? false) {
                    $revision = $state['selected'][$targetKey]['revision'] ?? null;
                    if (! is_int($revision) || $revision < 1) {
                        throw ValidationException::withMessages(['connection' => 'Update the Production POS before completing or cancelling batches from chat.']);
                    }
                    $state['payload']['expected_revision'] = $revision;
                }
            }
            $step = 'review';
        }
        $this->flow->save($operation, $step, $state);
        $this->flow->advance($conversation);
        $body = Str::limit($binding->name, 60, '')."\n".$definition['title']."\n";
        $rows = [];
        if ($step === 'review') {
            $reviewLines = [];
            foreach ($definition['fields'] as $field) {
                foreach ((array) ($state['labels'][$field['key']] ?? ['Skipped']) as $label) {
                    foreach (mb_str_split($field['key'].': '.$label, 110) as $part) {
                        $reviewLines[] = $part;
                    }
                }
            }
            $pages = max(1, (int) ceil(count($reviewLines) / 6));
            $page = min(max(0, (int) ($data['page'] ?? 0)), $pages - 1);
            $body .= 'Review '.($page + 1).'/'.$pages."\n".implode("\n", array_slice($reviewLines, $page * 6, 6));
            if ($page > 0) {
                $rows[] = $this->flow->row($conversation, $operation, 'record_review_page', 'Previous details', ['page' => $page - 1]);
            }
            if ($page < $pages - 1) {
                $rows[] = $this->flow->row($conversation, $operation, 'record_review_page', 'More details', ['page' => $page + 1]);
            } else {
                $body .= "\nConfirm to save this in your POS.";
                $rows[] = $this->flow->row($conversation, $operation, 'record_confirm', 'Confirm');
            }
        } elseif (str_starts_with($step, 'lines:')) {
            $parentIndex = (int) substr($step, 6);
            $parent = $definition['fields'][$parentIndex];
            $count = count($state['values'][$parent['key']] ?? []);
            $body .= $parent['label']."\n".$count.' line(s) added.';
            $limit = in_array($parent['key'], ['outputs', 'direct_expenses'], true) ? 50 : 100;
            if ($count < $limit) {
                $rows[] = $this->flow->row($conversation, $operation, 'record_line_more', 'Add another line');
            }
            $rows[] = $this->flow->row($conversation, $operation, 'record_line_done', 'Done with lines');
        } else {
            $parent = $definition['fields'][(int) $step];
            $repeating = isset($parent['fields']);
            $field = $repeating ? $parent['fields'][$state['line_step'] ?? 0] : $parent;
            $body .= ($repeating ? $parent['label']."\n" : '').$field['label'];
            if (isset($field['resource'])) {
                $page = max(1, (int) ($data['page'] ?? 1));
                $search = $kind === 'record_search' ? $text : ($data['search'] ?? '');
                $response = $this->pos->read($binding, $field['resource'], ['page' => $page, 'per_page' => 6, 'search' => $search] + ($field['query'] ?? []));
                $pageData = $response['suppliers'] ?? $response;
                $records = $pageData['data'] ?? [];
                foreach ($records as $record) {
                    $label = $record['name'] ?? $record['batch_number'] ?? $record['sale_number'] ?? '#'.$record['id'];
                    $description = '#'.$record['id'].' · '.($record['base_unit'] ?? '').' · '.($record['sku'] ?? '').(isset($record['balance']) ? ' · We owe '.$record['balance'] : '');
                    $rows[] = $this->flow->row($conversation, $operation, 'record_value', $label, ['value' => $record['id'], 'label' => $label.' #'.$record['id'], 'selected' => $record], $description);
                }
                foreach (['prev_page_url' => $page - 1, 'next_page_url' => $page + 1] as $link => $target) {
                    if (! empty($pageData[$link])) {
                        $rows[] = $this->flow->row($conversation, $operation, 'record_page', $link === 'prev_page_url' ? 'Previous' : 'Next', ['page' => $target, 'search' => $search]);
                    }
                }
                $body .= "\nChoose an item. Names include IDs to distinguish duplicates.";
                if ($records === []) {
                    $body .= "\nNo records found. Add one first, then return.";
                }
            } elseif (($choices = $this->choices($field, $state, $repeating)) !== null) {
                $page = max(0, (int) ($data['choice_page'] ?? 0));
                foreach (array_slice($choices, $page * 6, 6, true) as $value => $label) {
                    $rows[] = $this->flow->row($conversation, $operation, 'record_value', $label, ['value' => $value], $label);
                }
                foreach ([-1 => 'Previous units', 1 => 'More units'] as $offset => $label) {
                    if ($page + $offset >= 0 && ($page + $offset) * 6 < count($choices)) {
                        $rows[] = $this->flow->row($conversation, $operation, 'record_page', $label, ['choice_page' => $page + $offset]);
                    }
                }
            } else {
                $body .= "\nType your answer in chat.";
                if (str_contains($field['rules'], 'date_format:Y-m-d')) {
                    $today = $this->pos->read($binding, 'capabilities')['today'] ?? null;
                    if ($today) {
                        $rows[] = $this->flow->row($conversation, $operation, 'record_value', 'Today', ['value' => $today]);
                    }
                }
            }
            if ($repeating && ($parent['optional'] ?? false) && empty($state['values'][$parent['key']]) && empty($state['line_step'])) {
                $rows[] = $this->flow->row($conversation, $operation, 'record_skip_collection', 'Skip these lines');
            }
            if ($field['optional'] ?? false) {
                $rows[] = $this->flow->row($conversation, $operation, 'record_skip', 'Skip');
            }
        }
        $rows[] = $this->flow->row($conversation, $operation, 'record_cancel', 'Cancel task');

        return $this->flow->menu($body, $rows, 'Task options');
    }

    private function choices(array $field, array $state, bool $repeating): ?array
    {
        if (isset($field['units_of'])) {
            $product = $state[$repeating ? 'line_selected' : 'selected'][$field['units_of']] ?? null;
            if (! $product) {
                throw ValidationException::withMessages(['unit' => 'Select a product first.']);
            }
            $choices = [$product['base_unit'] => $product['base_unit'].' (base unit)'];
            foreach ($product['units'] ?? [] as $unit) {
                $choices[$unit['unit_name']] = $unit['unit_name'].' ('.$unit['conversion_to_base_unit'].' base)';
            }

            return $choices;
        }

        return $field['choices'] ?? null;
    }

    private function authorize(array $member, array $definition): void
    {
        if ($member['role'] === 'cashier' && ! ($definition['cashier'] ?? false)) {
            throw ValidationException::withMessages(['role' => 'This task requires an owner or manager.']);
        }
    }
}
