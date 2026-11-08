<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Reports always use the POS calculations and the shop's calendar. */
class ReportBrowser
{
    public function __construct(private PosClient $pos, private ChatFlow $flow) {}

    public function handle(object $conversation, object $binding, object $action): array
    {
        $member = $this->pos->membership($binding->backend, Crypt::decryptString($binding->token), $binding->shop_id);
        $data = $this->flow->decode($action->payload);
        $pdf = $action->kind === 'report_pdf' || ($data['format'] ?? null) === 'pdf';
        $options = ['dashboard' => 'Today at a glance'];
        if (in_array($member['role'], ['owner', 'manager'], true)) {
            $options += ['sales_report' => 'Sales summary', 'debt_report' => 'Customer debt', 'stock_report' => 'Stock summary'];
            if ($binding->backend === 'production') {
                $options['production_report'] = 'Production summary';
            }
        }
        if ($member['role'] === 'owner') {
            $options['profit_report'] = 'Profit summary';
        }
        $resource = $data['resource'] ?? null;
        if ($resource !== null && ! isset($options[$resource])) {
            throw ValidationException::withMessages(['report' => 'Your current role cannot open that report.']);
        }
        $this->flow->advance($conversation);
        $controls = (object) ['id' => '', 'binding_id' => $binding->id, 'expires_at' => now()->addMinutes(15)];
        $rows = [];
        $body = Str::limit($binding->name, 60, '')."\nReports from your POS";
        if ($resource === null) {
            foreach ($options as $key => $title) {
                $rows[] = $this->flow->row($conversation, $controls, 'report_open', $title, ['resource' => $key]);
            }
        } elseif (in_array($resource, ['sales_report', 'profit_report', 'production_report'], true) && ! isset($data['period'])) {
            $body .= "\n".$options[$resource].' — choose the period.';
            foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'Last 7 days', 'month' => 'This month', 'last_month' => 'Last month'] as $period => $title) {
                $rows[] = $this->flow->row($conversation, $controls, 'report_open', $title, ['resource' => $resource, 'period' => $period, 'format' => $pdf ? 'pdf' : 'text']);
            }
        } else {
            $query = [];
            if (in_array($resource, ['sales_report', 'profit_report', 'production_report'], true)) {
                $caps = $this->pos->read($binding, 'capabilities');
                $today = CarbonImmutable::createFromFormat('!Y-m-d', $caps['today']);
                $from = match ($data['period']) {
                    'today' => $today, 'yesterday' => $today->subDay(), 'week' => $today->subDays(6),
                    'calendar_week' => $today->startOfWeek(CarbonImmutable::MONDAY),
                    'last_week' => $today->subWeek()->startOfWeek(CarbonImmutable::MONDAY),
                    'month' => $today->startOfMonth(), 'last_month' => $today->startOfMonth()->subMonth(),
                    default => throw ValidationException::withMessages(['period' => 'Choose a supported reporting period.']),
                };
                $to = match ($data['period']) {
                    'yesterday' => $today->subDay(),
                    'last_week' => $today->startOfWeek(CarbonImmutable::MONDAY)->subDay(),
                    'last_month' => $today->startOfMonth()->subDay(),
                    default => $today,
                };
                $query = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
            }
            $result = $this->pos->read($binding, $resource, $query);
            $summary = $resource === 'dashboard' ? ($result['today'] ?? []) : ($result['summary'] ?? []);
            $date = $result['date'] ?? $result['as_of'] ?? null;
            $body .= "\n".$options[$resource].($date ? ' · '.$date : '');
            if ($query !== []) {
                $body .= "\n".$query['from'].' to '.$query['to'];
            }
            $lines = [];
            foreach ($summary as $key => $value) {
                if (is_scalar($value) && ! is_bool($value)) {
                    $lines[] = Str::limit(ucfirst(str_replace('_', ' ', $key)).': '.$value, 120, '');
                }
            }
            if ($lines === []) {
                $lines[] = 'The POS returned no summary figures for this report.';
            }
            if ($pdf) {
                $heading = $options[$resource];
                $documentLines = $query !== [] ? [$query['from'].' to '.$query['to']] : ($date ? ['As of '.$date] : []);

                return app(ChatDocument::class)->make($binding->name, $heading, [...$documentLines, ...$lines], $resource.'-'.($date ?? $query['to'] ?? 'current'));
            }
            $rows[] = $this->flow->row($conversation, $controls, 'report_pdf', 'Send PDF', ['resource' => $resource, 'period' => $data['period'] ?? null]);
            $page = min(max(0, (int) ($data['page'] ?? 0)), max(0, (int) ceil(count($lines) / 5) - 1));
            $body .= "\n".implode("\n", array_slice($lines, $page * 5, 5))."\nAmounts use your POS currency. Refreshed on opening.";
            foreach ([-1 => 'Previous figures', 1 => 'More figures'] as $offset => $title) {
                if ($page + $offset >= 0 && ($page + $offset) * 5 < count($lines)) {
                    $rows[] = $this->flow->row($conversation, $controls, 'report_open', $title, ['resource' => $resource, 'period' => $data['period'] ?? null, 'page' => $page + $offset]);
                }
            }
            $rows[] = $this->flow->row($conversation, $controls, 'report_open', 'Refresh figures', ['resource' => $resource, 'period' => $data['period'] ?? null, 'page' => $page]);
        }
        if ($resource !== null) {
            $rows[] = $this->flow->row($conversation, $controls, 'report_menu', 'All reports');
        }
        $rows[] = $this->flow->row($conversation, $controls, 'menu', 'Back to menu');

        return $this->flow->menu($body, $rows, 'Reports');
    }
}
