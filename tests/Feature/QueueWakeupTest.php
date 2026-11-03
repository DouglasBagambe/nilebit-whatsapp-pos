<?php

namespace Tests\Feature;

use App\Services\QueueWakeup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class QueueWakeupTest extends TestCase
{
    use RefreshDatabase;

    public function test_idle_queue_has_no_polling_deadline_and_reserved_jobs_wait_until_retry_time(): void
    {
        $this->freezeTime();
        config(['queue.connections.database.retry_after' => 120]);
        $wakeup = app(QueueWakeup::class);
        $this->assertNull($wakeup->secondsUntilNextJob());
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => now()->timestamp - 20, 'available_at' => now()->timestamp - 60, 'created_at' => now()->timestamp]);
        $this->assertSame(100, $wakeup->secondsUntilNextJob());
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp + 30, 'created_at' => now()->timestamp]);
        $this->assertSame(30, $wakeup->secondsUntilNextJob());
        $this->travel(31)->seconds();
        $this->assertSame(0, $wakeup->secondsUntilNextJob());
    }

    public function test_local_socket_receives_a_wake_signal_without_message_contents(): void
    {
        $path = sys_get_temp_dir().'/nilebit-'.Str::uuid().'.sock';
        config(['whatsapp.on_demand_worker' => true, 'whatsapp.worker_socket' => $path]);
        $server = stream_socket_server('unix://'.$path);
        try {
            app(QueueWakeup::class)->notify();
            $client = stream_socket_accept($server, 1);
            $this->assertIsResource($client);
            $this->assertSame('wake', fread($client, 4));
            fclose($client);
        } finally {
            fclose($server);
            unlink($path);
        }
    }
}
