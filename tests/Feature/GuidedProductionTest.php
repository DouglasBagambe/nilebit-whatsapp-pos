<?php

namespace Tests\Feature;

use App\Services\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GuidedProductionTest extends TestCase
{
    use RefreshDatabase;

    private function business(): int
    {
        config(['whatsapp.backends.production.url' => 'https://production.example.test/api']);
        Http::preventStrayRequests();
        $id = DB::table('wa_conversations')->insertGetId(['sender' => '256700000001', 'phone_number_id' => '12345', 'created_at' => now(), 'updated_at' => now()]);
        $binding = DB::table('wa_business_bindings')->insertGetId(['conversation_id' => $id, 'backend' => 'production', 'shop_id' => 11, 'user_id' => 7, 'name' => 'Juice Factory', 'role' => 'owner',
            'token' => Crypt::encryptString('test-token'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('wa_conversations')->where('id', $id)->update(['binding_id' => $binding]);
        Http::fake([
            'https://production.example.test/api/auth/me' => Http::response(['id' => 7, 'status' => 'active', 'shop_roles' => [['shop_id' => 11, 'role' => 'owner', 'shop' => ['name' => 'Juice Factory', 'status' => 'active']]]]),
            'https://production.example.test/api/bot/capabilities' => Http::response(['operation_lookup' => 1, 'operation_replay' => 1, 'batch_revision' => 1, 'today' => '2026-11-03']),
            'https://production.example.test/api/products?*' => Http::response(['data' => [
                ['id' => 8, 'name' => 'Mango Juice 300ml', 'base_unit' => 'bottle', 'sku' => 'J300', 'units' => []],
                ['id' => 9, 'name' => 'Mango pulp', 'base_unit' => 'L', 'sku' => 'PULP', 'units' => []],
            ]]),
            'https://production.example.test/api/recipes?*' => Http::response(['data' => [['id' => 4, 'name' => 'Mango recipe', 'yield_quantity' => 10, 'yield_unit' => 'L']]]),
            'https://production.example.test/api/production/batches?*' => Http::response(['data' => [['id' => 12, 'batch_number' => 'B-000012', 'name' => 'Mango run', 'revision' => 7, 'status' => 'draft']]]),
            'https://production.example.test/api/production/batches' => Http::response(['id' => 12, 'batch_number' => 'B-000012', 'status' => 'draft'], 201),
            'https://production.example.test/api/production/batches/12/complete' => Http::response(['id' => 12, 'batch_number' => 'B-000012', 'status' => 'completed']),
            'https://production.example.test/api/recipes' => Http::response(['id' => 4, 'name' => 'Mango recipe'], 201),
        ]);

        return $id;
    }

    private function send(int $id, ?string $text = null, ?string $action = null): array
    {
        return app(WhatsAppConversation::class)->reply(DB::table('wa_conversations')->find($id), $action ? ['interactive' => ['list_reply' => ['id' => $action]]] : ($text === null ? [] : ['text' => ['body' => $text]]));
    }

    private function choose(int $id, array $menu, string $title): array
    {
        $rows = $menu['interactive']['action']['sections'][0]['rows'];
        $this->assertLessThanOrEqual(10, count($rows));
        $row = collect($rows)->firstWhere('title', $title);
        $this->assertNotNull($row, 'Missing option '.$title);

        return $this->send($id, action: $row['id']);
    }

    private function start(int $id, string $title): array
    {
        $menu = $this->choose($id, $this->send($id), 'Business tasks');
        while (! collect($menu['interactive']['action']['sections'][0]['rows'])->contains('title', $title)) {
            $menu = $this->choose($id, $menu, 'More tasks');
        }

        return $this->choose($id, $menu, $title);
    }

    private function outputs(int $id, array $menu): array
    {
        $this->choose($id, $menu, 'Mango Juice 300ml');
        $this->send($id, '30');
        $menu = $this->send($id, '0.3');
        $menu = $this->choose($id, $menu, 'Skip');

        return $this->choose($id, $menu, 'Done with lines');
    }

    private function confirm(int $id, array $review): array
    {
        while (collect($review['interactive']['action']['sections'][0]['rows'])->contains('title', 'More details')) {
            $review = $this->choose($id, $review, 'More details');
        }
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');

        return $this->choose($id, $review, 'Confirm');
    }

    public function test_planning_a_batch_records_explicit_variant_equivalence_but_only_as_a_draft(): void
    {
        $id = $this->business();
        $menu = $this->start($id, 'Plan production batch');
        $menu = $this->choose($id, $menu, 'Mango recipe');
        $menu = $this->choose($id, $menu, 'Skip');
        $menu = $this->outputs($id, $menu);
        $menu = $this->choose($id, $menu, 'Skip');
        $review = $this->choose($id, $menu, 'Skip');
        $result = $this->confirm($id, $review);

        $this->assertStringContainsString('B-000012', $result['text']['body']);
        Http::assertSent(fn ($request) => $request->url() === 'https://production.example.test/api/production/batches'
            && $request['recipe_id'] === 4 && $request['outputs'] === [['product_id' => 8, 'quantity' => '30', 'output_equivalent' => '0.3']]);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/complete'));
    }

    public function test_completion_sends_actual_inputs_outputs_and_reviewed_revision_without_invented_costs(): void
    {
        $id = $this->business();
        $menu = $this->start($id, 'Complete batch');
        $menu = $this->choose($id, $menu, 'Mango run');
        $this->choose($id, $menu, 'Mango pulp');
        $menu = $this->send($id, '10');
        $menu = $this->choose($id, $menu, 'Done with lines');
        $menu = $this->outputs($id, $menu);
        $menu = $this->choose($id, $menu, 'Skip these lines');
        $menu = $this->choose($id, $menu, 'Skip these lines');
        $review = $this->choose($id, $menu, 'Skip');
        $result = $this->confirm($id, $review);

        $this->assertStringContainsString('B-000012', $result['text']['body']);
        Http::assertSent(fn ($request) => $request->url() === 'https://production.example.test/api/production/batches/12/complete'
            && $request['expected_revision'] === 7
            && $request['inputs'] === [['product_id' => 9, 'actual_quantity' => '10']]
            && $request['outputs'] === [['product_id' => 8, 'quantity' => '30', 'output_equivalent' => '0.3']]
            && ! isset($request['direct_expenses']) && ! isset($request['wastage']));
    }

    public function test_recipe_creation_keeps_yield_unit_and_input_base_quantity(): void
    {
        $id = $this->business();
        $this->start($id, 'Create recipe');
        $menu = $this->send($id, 'Mango recipe');
        $this->choose($id, $menu, 'Skip');
        $this->send($id, '10');
        $menu = $this->send($id, 'L');
        $this->choose($id, $menu, 'Mango pulp');
        $menu = $this->send($id, '8');
        $menu = $this->choose($id, $menu, 'Done with lines');
        $review = $this->choose($id, $menu, 'Skip');
        $this->confirm($id, $review);

        Http::assertSent(fn ($request) => $request->url() === 'https://production.example.test/api/recipes'
            && $request['yield_quantity'] === '10' && $request['yield_unit'] === 'L' && $request['items'] === [['product_id' => 9, 'quantity' => '8']]);
    }
}
