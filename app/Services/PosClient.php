<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class PosClient
{
    private function client(string $backend, string $token): PendingRequest
    {
        abort_unless(in_array($backend, ['retail', 'production'], true), 422);
        $url = (string) config("whatsapp.backends.{$backend}.url");
        abort_unless($url !== '', 503, 'This POS connection is not configured.');
        abort_unless(str_starts_with($url, 'https://') || (app()->environment(['local', 'testing'])
            && preg_match('#^http://(127\.0\.0\.1|localhost)(:[0-9]+)?/api/?$#D', $url)), 503);

        return Http::baseUrl(rtrim($url, '/'))->withToken($token)->acceptJson()->timeout(10)->connectTimeout(5)
            ->withOptions(['allow_redirects' => false]);
    }

    /** @return array{token: string, shops: array<int, array<string, mixed>>} */
    public function login(string $backend, string $email, string $password): array
    {
        $response = $this->client($backend, '')->post('/auth/login', ['email' => $email, 'password' => $password, 'client' => 'whatsapp']);
        if (! $response->successful() || ! is_string($response->json('token'))) {
            throw ValidationException::withMessages(['email' => 'Unable to sign in to that POS account. Check your details.']);
        }
        if ($response->json('token_scope') !== 'pos:bot') {
            $this->logout($backend, $response->json('token'));
            throw ValidationException::withMessages(['email' => 'Update this POS connection to support scoped WhatsApp access before linking it.']);
        }
        $user = $response->json('user');
        if (($user['status'] ?? '') !== 'active') {
            $this->logout($backend, $response->json('token'));
            throw ValidationException::withMessages(['email' => 'This POS account is not active.']);
        }
        $shops = collect($user['shop_roles'] ?? [])->filter(fn ($membership) => ($membership['shop']['status'] ?? '') === 'active'
            && in_array($membership['role'] ?? '', ['owner', 'manager', 'cashier'], true))
            ->map(fn ($membership) => ['id' => (int) $membership['shop_id'], 'name' => $membership['shop']['name'], 'role' => $membership['role']])->values()->all();
        if ($shops === []) {
            $this->logout($backend, $response->json('token'));
            throw ValidationException::withMessages(['email' => 'No active businesses are available on this account.']);
        }

        return ['token' => $response->json('token'), 'shops' => $shops];
    }

    public function logout(string $backend, string $token): void
    {
        $response = $this->client($backend, $token)->post('/auth/logout');
        if (! in_array($response->status(), [401, 403, 404], true)) {
            $response->throw();
        }
    }

    /** Verify credentials and membership before creating or using a binding. @return array<string, mixed> */
    public function membership(string $backend, string $token, int $shopId): array
    {
        $response = $this->client($backend, $token)->get('/auth/me');
        if (! $response->successful()) {
            throw ValidationException::withMessages(['business' => 'Please reconnect your POS account.']);
        }
        $user = $response->json('user', $response->json());
        $membership = collect($user['shop_roles'] ?? [])->firstWhere('shop_id', $shopId);
        if (($user['status'] ?? '') !== 'active' || ! $membership || ! in_array($membership['role'] ?? '', ['owner', 'manager', 'cashier'], true) || ($membership['shop']['status'] ?? '') !== 'active') {
            throw ValidationException::withMessages(['business' => 'This account no longer has access to an active business.']);
        }

        return ['user_id' => (int) $user['id'], 'shop_id' => $shopId, 'name' => $membership['shop']['name'], 'role' => $membership['role']];
    }

    /** @return array<int, array<string, mixed>> */
    public function stock(object $binding): array
    {
        $token = Crypt::decryptString($binding->token);
        $this->membership($binding->backend, $token, $binding->shop_id);
        $response = $this->client($binding->backend, $token)->withHeaders(['X-Shop-Id' => (string) $binding->shop_id])->get('/pos/products');
        if (! $response->successful()) {
            throw ValidationException::withMessages(['business' => 'Stock could not load. Please try again.']);
        }

        return $response->json();
    }

    /** @return array<int, array<string, mixed>> */
    public function customers(object $binding, string $search = ''): array
    {
        $token = Crypt::decryptString($binding->token);
        $this->membership($binding->backend, $token, $binding->shop_id);
        $response = $this->client($binding->backend, $token)->withHeaders(['X-Shop-Id' => (string) $binding->shop_id])
            ->get('/customers', ['search' => $search, 'limit' => 500]);
        if (! $response->successful()) {
            throw ValidationException::withMessages(['customer' => 'Customers could not load. Please try again.']);
        }

        return $response->json();
    }

    /** @return array<string, mixed> */
    public function customer(object $binding, int $customerId): array
    {
        $token = Crypt::decryptString($binding->token);
        $this->membership($binding->backend, $token, $binding->shop_id);
        $response = $this->client($binding->backend, $token)->withHeaders(['X-Shop-Id' => (string) $binding->shop_id])->get('/customers/'.$customerId);
        if (! $response->successful()) {
            throw ValidationException::withMessages(['customer' => 'That customer could not load. Please choose again.']);
        }

        return $response->json();
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function sell(object $binding, array $payload): array
    {
        $receipt = $this->write($binding, '/sales', $payload, $payload['idempotency_key'], ['sale_price_review']);
        if (! ($receipt['id'] ?? null) || ! ($receipt['sale_number'] ?? null)) {
            throw new \RuntimeException('The POS did not return a sale receipt. Retry the same confirmation.');
        }

        return $receipt;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createCustomer(object $binding, array $payload, string $operation): array
    {
        return $this->write($binding, '/customers', $payload, $operation);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function repayCustomer(object $binding, int $customerId, array $payload, string $operation): array
    {
        return $this->write($binding, '/customers/'.$customerId.'/payments', $payload + ['idempotency_key' => $operation], $operation);
    }

    public function operation(object $binding, string $key): ?array
    {
        if (! preg_match('/^[A-Za-z0-9_-]{8,100}$/D', $key)) {
            throw new \InvalidArgumentException('Invalid operation key.');
        }
        $token = Crypt::decryptString($binding->token);
        $this->membership($binding->backend, $token, $binding->shop_id);
        $client = $this->client($binding->backend, $token)->withHeaders(['X-Shop-Id' => (string) $binding->shop_id]);
        $caps = $client->get('/bot/capabilities');
        if (! $caps->successful() || $caps->json('operation_lookup') !== 1) {
            throw ValidationException::withMessages(['connection' => 'Update your POS connection before checking pending operations.']);
        }
        $response = $client->get('/bot/operations/'.$key);
        if ($response->status() === 404) {
            return null;
        }
        $response->throw();

        return $response->json('result');
    }

    /** Fixed resource names prevent chat input from becoming an arbitrary URL. */
    public function read(object $binding, string $resource, array $query = [], ?int $id = null): array
    {
        $path = match ($resource) {
            'products' => '/products', 'suppliers' => '/suppliers', 'sales' => '/sales',
            'purchases' => '/purchases', 'expenses' => '/expenses', 'shifts' => '/shifts',
            'current_shift' => '/shifts/current', 'recipes' => '/recipes', 'batches' => '/production/batches',
            'dashboard' => '/reports/dashboard', 'stock_report' => '/reports/stock', 'sales_report' => '/reports/sales',
            'profit_report' => '/reports/profit', 'debt_report' => '/reports/debt', 'movements' => '/inventory/movements',
            'production_report' => '/reports/production', 'capabilities' => '/bot/capabilities',
            'product' => '/products/'.(int) $id, 'supplier' => '/suppliers/'.(int) $id,
            'sale' => '/sales/'.(int) $id, 'purchase' => '/purchases/'.(int) $id,
            'recipe' => '/recipes/'.(int) $id, 'batch' => '/production/batches/'.(int) $id,
            default => throw new \InvalidArgumentException('Unknown POS resource.'),
        };
        $token = Crypt::decryptString($binding->token);
        $this->membership($binding->backend, $token, $binding->shop_id);
        $response = $this->client($binding->backend, $token)->withHeaders(['X-Shop-Id' => (string) $binding->shop_id])->get($path, $query);
        if (! $response->successful()) {
            throw ValidationException::withMessages(['resource' => 'This information could not load. Check your role and connection, then try again.']);
        }

        return $response->json();
    }

    public function record(object $binding, string $command, array $payload, string $operation, ?int $target = null): array
    {
        $path = match ($command) {
            'supplier.create' => '/suppliers', 'supplier.pay' => '/suppliers/'.(int) $target.'/payments',
            'product.create' => '/products', 'purchase.receive' => '/purchases',
            'stock.damage' => '/inventory/damage', 'stock.loss' => '/inventory/loss',
            'stock.count' => '/inventory/adjustments', 'stock.opening' => '/inventory/opening-stock',
            'expense.create' => '/expenses', 'shift.open' => '/shifts/open', 'shift.close' => '/shifts/'.(int) $target.'/close',
            'batch.plan' => '/production/batches', 'batch.complete' => '/production/batches/'.(int) $target.'/complete',
            'batch.cancel' => '/production/batches/'.(int) $target.'/cancel', 'recipe.create' => '/recipes',
            default => throw new \InvalidArgumentException('Unknown POS command.'),
        };

        return $this->write($binding, $path, $payload + ['idempotency_key' => $operation], $operation, in_array($command, ['batch.complete', 'batch.cancel'], true) ? ['batch_revision'] : []);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function write(object $binding, string $path, array $payload, string $operation, array $required = []): array
    {
        $token = Crypt::decryptString($binding->token);
        $this->membership($binding->backend, $token, $binding->shop_id);
        $client = $this->client($binding->backend, $token)->withHeaders(['X-Shop-Id' => (string) $binding->shop_id]);
        $capabilities = $client->get('/bot/capabilities');
        if (! $capabilities->successful() || $capabilities->json('operation_replay') !== 1 || $capabilities->json('operation_lookup') !== 1) {
            throw ValidationException::withMessages(['connection' => 'Your POS connection needs an update before this task can be recorded safely.']);
        }
        foreach ($required as $capability) {
            if ($capabilities->json($capability) !== 1) {
                throw ValidationException::withMessages(['connection' => 'Your POS needs an update before this operation can be recorded safely.']);
            }
        }
        $response = $client->withHeaders(['X-NileBit-Operation' => $operation])->post($path, $payload);
        if (in_array($response->status(), [400, 401, 403, 404, 409, 422], true)) {
            $message = collect($response->json('errors', []))->flatten()->first() ?? $response->json('message', 'Check the details and try again.');
            throw ValidationException::withMessages(['operation' => 'Not recorded. '.$message]);
        }
        $response->throw();
        if (! is_array($response->json())) {
            throw new \RuntimeException('Invalid POS response. Retry the same operation.');
        }

        return $response->json();
    }
}
