<?php

namespace App\Console\Commands;

use App\Services\QueueWakeup;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Waits on a local socket while idle, allowing Postgres to suspend. */
class WorkWhatsAppOnDemand extends Command
{
    protected $signature = 'whatsapp:work-on-demand';

    protected $description = 'Drain persisted work on arrival or retry time without idle database polling';

    public function handle(QueueWakeup $wakeup): int
    {
        if (config('queue.default') !== 'database' || ! function_exists('pcntl_async_signals')) {
            $this->error('On-demand mode requires the database queue and pcntl.');

            return self::FAILURE;
        }
        $path = (string) config('whatsapp.worker_socket');
        if (file_exists($path)) {
            unlink($path);
        }
        $server = @stream_socket_server('unix://'.$path, $code, $message);
        if (! is_resource($server)) {
            throw new RuntimeException('The local queue wake socket could not be opened.');
        }
        chmod($path, 0600);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            chown($path, 'www-data');
        }
        $running = true;
        $process = null;
        pcntl_async_signals(true);
        $stop = function () use (&$running, &$process): void {
            $running = false;
            if ($process?->isRunning()) {
                $process->signal(SIGTERM);
            }
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
        try {
            $this->call('whatsapp:recover');
            while ($running) {
                $process = new Process([PHP_BINARY, base_path('artisan'), 'queue:work', '--stop-when-empty', '--sleep=0', '--tries=30', '--timeout=60', '--max-jobs=100', '--no-interaction'], base_path());
                $process->setTimeout(null);
                $exit = $process->run(function (string $type, string $buffer): void {
                    $this->output->write($buffer);
                });
                if (! $running) {
                    break;
                }
                if ($exit !== 0) {
                    throw new RuntimeException('The on-demand queue worker stopped unexpectedly.');
                }
                $delay = $wakeup->secondsUntilNextJob();
                if ($delay === 0) {
                    continue;
                }
                $this->info('Queue idle; awaiting local wake signal.');
                $read = [$server];
                $write = $except = [];
                $ready = @stream_select($read, $write, $except, $delay);
                if ($ready > 0) {
                    $client = @stream_socket_accept($server, 0);
                    if (is_resource($client)) {
                        fclose($client);
                    }
                }
            }
        } finally {
            fclose($server);
            @unlink($path);
        }

        return self::SUCCESS;
    }
}
