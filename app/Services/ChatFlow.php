<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Shared persisted state and Meta-safe controls for guided tasks. */
class ChatFlow
{
    public function pending(object $conversation): ?object
    {
        return DB::table('wa_operations')->where('conversation_id', $conversation->id)->where('binding_id', $conversation->binding_id)
            ->whereNotIn('step', ['completed', 'cancelled', 'failed'])->where('expires_at', '>', now())->latest('created_at')->first();
    }

    public function create(object $conversation, string $kind, string $step, array $state): object
    {
        $id = (string) Str::uuid();
        DB::table('wa_operations')->insert(['id' => $id, 'conversation_id' => $conversation->id, 'binding_id' => $conversation->binding_id,
            'kind' => $kind, 'step' => $step, 'state' => $this->encrypt($state), 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('wa_operations')->find($id);
    }

    public function save(object $operation, string $step, array $state): void
    {
        DB::table('wa_operations')->where('id', $operation->id)->update(['step' => $step, 'state' => $this->encrypt($state), 'updated_at' => now()]);
        $operation->step = $step;
        $operation->state = $this->encrypt($state);
    }

    public function decode(?string $payload): array
    {
        return $payload ? json_decode(Crypt::decryptString($payload), true, flags: JSON_THROW_ON_ERROR) : [];
    }

    private function encrypt(array $data): string
    {
        return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function advance(object $conversation): void
    {
        DB::table('wa_conversations')->where('id', $conversation->id)->increment('version');
        $conversation->version++;
    }

    public function row(object $conversation, object $operation, string $kind, string $title, array $data = [], ?string $description = null): array
    {
        $id = (string) Str::uuid();
        DB::table('wa_actions')->insert(['id' => $id, 'conversation_id' => $conversation->id, 'version' => $conversation->version,
            'kind' => $kind, 'binding_id' => $operation->binding_id, 'payload' => $this->encrypt($data + ['operation' => $operation->id, 'typed_title' => $title]),
            'expires_at' => $operation->expires_at, 'created_at' => now(), 'updated_at' => now()]);

        return array_filter(['id' => $id, 'title' => Str::limit($title, 24, ''), 'description' => $description ? Str::limit($description, 72, '') : null], fn ($value) => $value !== null);
    }

    /** Resolve a unique option on the current screen, with a one-time code for a typed write. */
    public function typedAction(object $conversation, string $text): ?string
    {
        $command = mb_strtolower(trim($text));
        if ($command === '' || mb_strlen($command) > 200) {
            return null;
        }

        $matches = [];
        $actions = DB::table('wa_actions')->where('conversation_id', $conversation->id)
            ->where(fn ($query) => $query->where('binding_id', $conversation->binding_id)->orWhere('kind', 'switch'))
            ->where('version', $conversation->version)
            ->where('expires_at', '>', now())->whereNotNull('payload')->get();
        foreach ($actions as $action) {
            if (str_ends_with($action->kind, '_confirm')) {
                if (in_array($action->kind, ['sale_confirm', 'record_confirm', 'contact_confirm'], true)
                    && $command === mb_strtolower($this->confirmationCommand($action->id))) {
                    $matches[] = $action->id;
                }

                continue;
            }
            $data = $this->decode($action->payload);
            $title = $data['typed_title'] ?? '';
            $aliases = match ($title) {
                'Choose payment' => ['checkout', 'pay'],
                'Walk-in / no name' => ['walk in', 'no customer'],
                'Done with lines' => ['done'],
                'Split / part payment' => ['split payment'],
                default => $action->kind === 'sale_unit' ? [app(ChatLanguage::class)->plural($title)] : [],
            };
            foreach ([$title, Str::limit($title, 24, ''), ...$aliases] as $label) {
                if ($command === mb_strtolower(trim($label))) {
                    $matches[] = $action->id;
                    break;
                }
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    public function confirmationCommand(string $actionId): string
    {
        return 'Confirm '.strtoupper(substr($actionId, 0, 8));
    }

    public function normalize(string $text): string
    {
        return trim(preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower($text)) ?? '');
    }

    /** @return array{0: array, 1: bool} Products and whether they are spelling suggestions. */
    public function findProducts(array $products, string $search): array
    {
        $query = $this->normalize($search);
        if ($query === '') {
            return [$search === '' ? $products : [], false];
        }
        $matches = array_values(array_filter($products, fn (array $product) => str_contains(
            $this->normalize($product['name'].' '.($product['sku'] ?? '')), $query,
        )));
        if ($matches !== [] || ! preg_match('/^[a-z0-9 ]{4,60}$/D', $query)) {
            return [$matches, false];
        }

        $words = explode(' ', $query);
        $limit = strlen($query) >= 8 ? 2 : 1;
        $ranked = [];
        foreach ($products as $index => $product) {
            $name = explode(' ', $this->normalize($product['name']));
            $best = $limit + 1;
            for ($offset = 0; $offset + count($words) <= count($name); $offset++) {
                $candidate = implode(' ', array_slice($name, $offset, count($words)));
                $best = min($best, levenshtein($query, $candidate));
            }
            if ($best <= $limit) {
                $ranked[$index] = $best;
            }
        }
        asort($ranked);

        return [array_map(fn ($index) => $products[$index], array_keys($ranked)), $ranked !== []];
    }

    public function menu(string $body, array $rows, string $button = 'Choose'): array
    {
        if (count($rows) > 10 || mb_strlen($body) > 1024) {
            throw new \LogicException('Guided menu exceeds WhatsApp limits.');
        }

        return ['type' => 'interactive', 'interactive' => ['type' => 'list', 'body' => ['text' => $body],
            'action' => ['button' => Str::limit($button, 20, ''), 'sections' => [['title' => 'Options', 'rows' => $rows]]]]];
    }

    public function text(string $body): array
    {
        return ['type' => 'text', 'text' => ['body' => Str::limit($body, 4096, '')]];
    }
}
