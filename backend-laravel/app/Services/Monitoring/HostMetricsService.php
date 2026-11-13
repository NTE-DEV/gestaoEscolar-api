<?php

namespace App\Services\Monitoring;

class HostMetricsService
{
    /**
     * Coleta CPU, Memória RAM, Load Average e Uptime do servidor Linux (ou fallback Windows dev).
     */
    public function snapshot(): array
    {
        $cpuPercent = $this->readCpuPercent();
        $memory = $this->readMemoryDetails();
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];

        return [
            'cpu' => round($cpuPercent, 2),
            'memory' => $memory['percent'],
            'memory_details' => [
                'total' => $memory['total'],
                'used' => $memory['used'],
                'free' => $memory['free'],
            ],
            'load_average' => is_array($load) ? array_map(fn ($v) => round((float) $v, 2), $load) : [0, 0, 0],
            'uptime' => $this->readUptime(),
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    private function readCpuPercent(): float
    {
        if (is_readable('/proc/stat')) {
            $first = $this->parseProcStat();
            usleep(120000);
            $second = $this->parseProcStat();

            if ($first && $second) {
                $totalDiff = $second['total'] - $first['total'];
                $idleDiff = $second['idle'] - $first['idle'];
                if ($totalDiff > 0) {
                    return min(100.0, max(0.0, (($totalDiff - $idleDiff) / $totalDiff) * 100));
                }
            }
        }

        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if (is_array($load) && isset($load[0])) {
                return min(100.0, round((float) $load[0] * 10, 2));
            }
        }

        return 12.5; // Fallback para ambiente local Windows
    }

    private function parseProcStat(): ?array
    {
        $raw = @file_get_contents('/proc/stat');
        if (! $raw) {
            return null;
        }
        $firstLine = strtok($raw, "\n");
        if (! $firstLine || ! str_starts_with($firstLine, 'cpu ')) {
            return null;
        }
        $parts = preg_split('/\s+/', trim($firstLine));
        $values = array_map('intval', array_slice($parts, 1));
        $idle = ($values[3] ?? 0) + ($values[4] ?? 0);
        $total = array_sum($values);

        return ['idle' => $idle, 'total' => $total];
    }

    private function readMemoryDetails(): array
    {
        if (is_readable('/proc/meminfo')) {
            $raw = @file_get_contents('/proc/meminfo');
            if ($raw) {
                preg_match('/MemTotal:\s+(\d+)\s+kB/i', $raw, $totalMatch);
                preg_match('/MemAvailable:\s+(\d+)\s+kB/i', $raw, $availMatch);
                $total = isset($totalMatch[1]) ? (int) $totalMatch[1] * 1024 : 0;
                $free = isset($availMatch[1]) ? (int) $availMatch[1] * 1024 : 0;
                $used = max(0, $total - $free);
                $percent = $total > 0 ? round(($used / $total) * 100, 2) : 0.0;

                return [
                    'total' => $total,
                    'used' => $used,
                    'free' => $free,
                    'percent' => $percent,
                ];
            }
        }

        // Fallback seguro para ambiente Windows local
        $total = 16 * 1024 * 1024 * 1024;
        $used = (int) round($total * 0.42);
        return [
            'total' => $total,
            'used' => $used,
            'free' => $total - $used,
            'percent' => 42.0,
        ];
    }

    private function readUptime(): ?string
    {
        if (is_readable('/proc/uptime')) {
            $raw = @file_get_contents('/proc/uptime');
            if ($raw) {
                $seconds = (int) floatval(explode(' ', trim($raw))[0]);
                $days = intdiv($seconds, 86400);
                $hours = intdiv($seconds % 86400, 3600);
                $minutes = intdiv($seconds % 3600, 60);
                return "{$days}d {$hours}h {$minutes}m";
            }
        }
        return null;
    }
}
