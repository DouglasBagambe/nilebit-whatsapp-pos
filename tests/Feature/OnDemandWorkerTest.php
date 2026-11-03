<?php

namespace Tests\Feature;

use App\Jobs\ProcessInboundMessage;
use App\Services\QueueWakeup;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OnDemandWorkerTest extends TestCase
{
    public function test_idle_worker_wakes_and_processes_persisted_messages_without_a_meta_connection(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('Requires Unix pcntl worker support.');
        }
        $database = tempnam(sys_get_temp_dir(), 'nilebit-worker-');
        $socket = sys_get_temp_dir().'/nilebit-worker-'.Str::uuid().'.sock';
        $environment = ['APP_ENV' => 'testing', 'APP_URL' => 'http://localhost', 'APP_KEY' => config('app.key'),
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database', 'WHATSAPP_ENABLED' => 'false', 'WHATSAPP_WORKER_SOCKET' => $socket];
        config(['database.connections.worker_test' => array_merge(config('database.connections.sqlite'), ['database' => $database, 'url' => null]),
            'queue.connections.worker_test' => ['driver' => 'database', 'connection' => 'worker_test', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 120],
            'whatsapp.on_demand_worker' => true, 'whatsapp.worker_socket' => $socket]);
        $worker = null;
        try {
            $migrate = new Process([PHP_BINARY, base_path('artisan'), 'migrate', '--no-interaction'], base_path(), $environment);
            $migrate->mustRun();
            $db = DB::connection('worker_test');
            $conversationId = $db->table('wa_conversations')->insertGetId(['sender' => '256700000099', 'phone_number_id' => 'worker-test', 'created_at' => now(), 'updated_at' => now()]);
            $worker = new Process([PHP_BINARY, base_path('artisan'), 'whatsapp:work-on-demand', '--no-interaction'], base_path(), $environment);
            $worker->setTimeout(null)->start();
            $this->waitUntil(fn () => str_contains($worker->getOutput(), 'Queue idle; awaiting local wake signal.'), $worker);
            foreach ([1, 2] as $number) {
                $eventId = $db->table('wa_inbound_events')->insertGetId(['conversation_id' => $conversationId, 'message_id' => 'wamid.worker-'.$number, 'phone_number_id' => 'worker-test',
                    'payload' => Crypt::encryptString(json_encode(['type' => 'text', 'timestamp' => (string) now()->timestamp, 'text' => ['body' => 'Menu']], JSON_THROW_ON_ERROR)), 'created_at' => now(), 'updated_at' => now()]);
                Queue::connection('worker_test')->push(new ProcessInboundMessage($eventId));

                app(QueueWakeup::class)->notify();

                $this->waitUntil(fn () => $db->table('wa_inbound_events')->where('id', $eventId)->value('processed_at') !== null, $worker);
                $this->assertSame(1, $db->table('wa_outbound_messages')->where('inbound_event_id', $eventId)->count());
                $this->assertTrue($worker->isRunning());
            }
            $this->assertSame(0, $db->table('wa_outbound_messages')->whereNotNull('sent_at')->count());
        } finally {
            $worker?->stop(5, SIGTERM);
            DB::purge('worker_test');
            @unlink($database);
            @unlink($socket);
        }
    }

    private function waitUntil(callable $ready, Process $worker): void
    {
        $deadline = microtime(true) + 8;
        do {
            if ($ready()) {
                return;
            }
            if (! $worker->isRunning()) {
                $this->fail('Worker exited: '.$worker->getErrorOutput().' '.$worker->getOutput());
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        $this->fail('Worker did not reach the expected state: '.$worker->getErrorOutput().' '.$worker->getOutput());
    }
}
