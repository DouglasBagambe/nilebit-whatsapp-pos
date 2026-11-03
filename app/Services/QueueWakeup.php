<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Local wake signal; no message data or secrets leave the container. */
class QueueWakeup
{
    public function notify(): void
    {
        if (! config('whatsapp.on_demand_worker')) {
            return;
        }
        $socket = @stream_socket_client('unix://'.config('whatsapp.worker_socket'), $code, $message, 0.2);
        if (is_resource($socket)) {
            fwrite($socket, 'wake');
            fclose($socket);
        }
    }

    public function secondsUntilNextJob(): ?int
    {
        $retryAfter = (int) config('queue.connections.database.retry_after', 120);
        $row = DB::table('jobs')->selectRaw('MIN(CASE WHEN reserved_at IS NULL THEN available_at ELSE reserved_at + ? END) AS next_at', [$retryAfter])->first();

        return $row?->next_at === null ? null : max(0, (int) $row->next_at - now()->timestamp);
    }
}
