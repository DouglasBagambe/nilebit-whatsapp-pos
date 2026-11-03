<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GuidedCustomer
{
    public function __construct(private PosClient $pos, private ChatFlow $flow) {}

    public function handle(object $conversation, ?object $action, array $message): array
    {
        $binding = DB::table('wa_business_bindings')->where('id', $conversation->binding_id)->where('conversation_id', $conversation->id)->whereNull('revoked_at')->firstOrFail();
        $this->pos->membership($binding->backend, Crypt::decryptString($binding->token), $binding->shop_id);
        $data = $this->flow->decode($action?->payload);
        $kind = $action?->kind ?? 'contact_text';
        $operation = $this->flow->pending($conversation);
        if (in_array($kind, ['contact_new', 'contact_repay'], true)) {
            if ($operation || app(GuidedSale::class)->pending($conversation)) {
                return $this->flow->text('Finish or cancel your current task before starting another. Open Recent tasks to resume it.');
            }
            if ($kind === 'contact_new') {
                $operation = $this->flow->create($conversation, 'customer_create', 'name', []);
            } else {
                $customer = $this->pos->customer($binding, (int) $data['customer']);
                if ((int) $customer['balance'] <= 0) {
                    return $this->flow->text('This customer has no outstanding debt. Nothing was recorded. Send Menu to return.');
                }
                $operation = $this->flow->create($conversation, 'customer_repayment', 'amount', ['customer' => $customer]);
            }
        } elseif (! $operation || ($action && ($data['operation'] ?? null) !== $operation->id)) {
            return $this->flow->text('This task has expired or was completed. Send Menu to start again.');
        }
        $state = $this->flow->decode($operation->state);
        $step = $operation->step;
        $text = trim($message['text']['body'] ?? '');
        if ($kind === 'contact_cancel' || ($kind === 'contact_text' && mb_strtolower($text) === 'cancel')) {
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

            return $this->flow->text('Task cancelled. Nothing was recorded. Send Menu for your options.');
        }
        if ($kind === 'contact_text' && $step === 'name') {
            if (mb_strlen($text) < 2 || mb_strlen($text) > 255) {
                return $this->flow->text('Enter the customer name, between 2 and 255 characters. Or send Cancel.');
            }
            $state['name'] = $text;
            $step = 'phone';
        } elseif (($kind === 'contact_text' || $kind === 'contact_skip') && $step === 'phone') {
            $phone = $kind === 'contact_skip' ? null : preg_replace('/[\s()-]/', '', $text);
            if ($phone !== null && ! preg_match('/^\+?[0-9]{6,20}$/D', $phone)) {
                return $this->flow->text('Enter a valid phone number, or choose Skip phone. Or send Cancel.');
            }
            $state['payload'] = ['name' => $state['name'], 'phone' => $phone];
            $step = 'review';
        } elseif (($kind === 'contact_text' || $kind === 'contact_amount') && $step === 'amount') {
            $raw = (string) ($data['amount'] ?? $text);
            if (! preg_match('/^[0-9]{1,12}$/D', $raw) || (int) $raw < 1 || (int) $raw > (int) $state['customer']['balance']) {
                return $this->flow->text('Enter a whole amount greater than zero, no more than '.$state['customer']['balance'].'. Or send Cancel.');
            }
            $state['amount'] = (int) $raw;
            $step = 'method';
        } elseif ($kind === 'contact_method' && $step === 'method') {
            $state['method'] = $data['method'];
            $step = 'reference';
        } elseif (($kind === 'contact_text' || $kind === 'contact_skip') && $step === 'reference') {
            if (mb_strlen($text) > 100) {
                return $this->flow->text('Use a reference of at most 100 characters, or choose Skip reference.');
            }
            $state['payload'] = ['amount' => $state['amount'], 'method' => $state['method'], 'reference' => $kind === 'contact_skip' ? null : $text];
            $step = 'review';
        } elseif ($kind === 'contact_confirm' && $step === 'review') {
            $key = 'wa-operation-'.$operation->id;
            if ($operation->kind === 'customer_create') {
                $receipt = $this->pos->createCustomer($binding, $state['payload'], $key);
                $body = 'Customer added: '.Str::limit($receipt['name'], 100, '')."\nCustomer #".$receipt['id'];
            } else {
                $receipt = $this->pos->repayCustomer($binding, (int) $state['customer']['id'], $state['payload'], $key);
                $body = 'Payment recorded: '.$state['amount']."\nCustomer: ".Str::limit($state['customer']['name'], 100, '')."\nRemaining balance: ".$receipt['balance'];
            }
            $state['receipt'] = $receipt;
            $this->flow->save($operation, 'completed', $state);
            $this->flow->advance($conversation);

            return $this->flow->text(Str::limit($binding->name, 60, '')."\n".$body."\n\nSend Menu for your next task.");
        }
        $this->flow->save($operation, $step, $state);
        $this->flow->advance($conversation);
        $rows = [];
        $body = Str::limit($binding->name, 60, '')."\n";
        if ($step === 'name') {
            $body .= 'Type the customer’s name.';
        } elseif ($step === 'phone') {
            $body .= 'Type their phone number, or skip it.';
            $rows[] = $this->flow->row($conversation, $operation, 'contact_skip', 'Skip phone');
        } elseif ($step === 'amount') {
            $balance = (int) $state['customer']['balance'];
            $body .= Str::limit($state['customer']['name'], 100, '').' owes '.$balance."\nChoose the amount received, or type another whole amount.";
            foreach (array_unique([$balance, min($balance, 1000), min($balance, 5000)]) as $amount) {
                $rows[] = $this->flow->row($conversation, $operation, 'contact_amount', (string) $amount, ['amount' => $amount]);
            }
        } elseif ($step === 'method') {
            $body .= 'How was this payment received? This records an existing payment; it does not charge a wallet or card.';
            foreach (['CASH' => 'Cash', 'MOBILE_MONEY' => 'Mobile money', 'CARD' => 'Card', 'BANK' => 'Bank', 'OTHER' => 'Other'] as $method => $title) {
                $rows[] = $this->flow->row($conversation, $operation, 'contact_method', $title, ['method' => $method]);
            }
        } elseif ($step === 'reference') {
            $body .= 'Type a payment reference, or skip it.';
            $rows[] = $this->flow->row($conversation, $operation, 'contact_skip', 'Skip reference');
        } elseif ($step === 'review') {
            $body .= $operation->kind === 'customer_create'
                ? 'Add customer: '.Str::limit($state['payload']['name'], 255, '')."\nPhone: ".($state['payload']['phone'] ?? 'None')
                : 'Record payment for '.Str::limit($state['customer']['name'], 100, '')."\nAmount: ".$state['amount']."\nMethod: ".$state['method']."\nReference: ".($state['payload']['reference'] ?? 'None');
            $body .= "\nConfirm to save this in your POS.";
            $rows[] = $this->flow->row($conversation, $operation, 'contact_confirm', $operation->kind === 'customer_create' ? 'Confirm customer' : 'Confirm payment');
        }
        $rows[] = $this->flow->row($conversation, $operation, 'contact_cancel', 'Cancel task');

        return $this->flow->menu($body, $rows, 'Customer options');
    }
}
