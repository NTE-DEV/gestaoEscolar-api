<?php

namespace App\Console\Commands;

use App\Models\SystemMetricHistory;
use App\Services\Monitoring\HostMetricsService;
use Illuminate\Console\Command;

class CollectSystemMetricsCommand extends Command
{
    protected $signature = 'metrics:collect-system';

    protected $description = 'Coleta amostra atual de CPU e Memória RAM e consolida o pico diário (20 dias)';

    public function handle(HostMetricsService $hostMetrics): int
    {
        $snapshot = $hostMetrics->snapshot();
        $today = now()->format('Y-m-d');
        $nowTime = now()->format('H:i:s');

        $cpu = (float) $snapshot['cpu'];
        $mem = (float) $snapshot['memory'];
        $memUsed = (int) ($snapshot['memory_details']['used'] ?? 0);
        $memTotal = (int) ($snapshot['memory_details']['total'] ?? 0);

        $record = SystemMetricHistory::firstOrNew(['metric_date' => $today]);
        $prevCount = (int) ($record->samples_count ?? 0);
        $newCount = $prevCount + 1;

        $record->cpu_avg_percent = $prevCount > 0
            ? round((($record->cpu_avg_percent * $prevCount) + $cpu) / $newCount, 2)
            : $cpu;

        $record->memory_avg_percent = $prevCount > 0
            ? round((($record->memory_avg_percent * $prevCount) + $mem) / $newCount, 2)
            : $mem;

        if (! $record->exists || $cpu >= $record->cpu_peak_percent) {
            $record->cpu_peak_percent = $cpu;
            $record->cpu_peak_time = $nowTime;
        }

        if (! $record->exists || $mem >= $record->memory_peak_percent) {
            $record->memory_peak_percent = $mem;
            $record->memory_peak_used_bytes = $memUsed;
        }

        $record->memory_total_bytes = $memTotal > 0 ? $memTotal : $record->memory_total_bytes;
        $record->samples_count = $newCount;
        $record->save();

        $this->info("Amostra gravada para {$today}: CPU={$cpu}% | RAM={$mem}% (samples={$newCount})");

        return self::SUCCESS;
    }
}
