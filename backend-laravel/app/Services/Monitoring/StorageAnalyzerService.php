<?php

namespace App\Services\Monitoring;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class StorageAnalyzerService
{
    public function analyze(): array
    {
        $path = storage_path('app');
        $totalDisk = @disk_total_space($path) ?: 0;
        $freeDisk = @disk_free_space($path) ?: 0;
        $usedDisk = max(0, $totalDisk - $freeDisk);

        $counts = [
            'images' => 0,
            'audio' => 0,
            'pdf' => 0,
            'doc' => 0,
            'excel' => 0,
            'others' => 0,
        ];

        if (is_dir($path)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                match (true) {
                    in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp']) => $counts['images']++,
                    in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'aac']) => $counts['audio']++,
                    $ext === 'pdf' => $counts['pdf']++,
                    in_array($ext, ['doc', 'docx', 'odt', 'rtf', 'txt']) => $counts['doc']++,
                    in_array($ext, ['xls', 'xlsx', 'ods', 'csv']) => $counts['excel']++,
                    default => $counts['others']++,
                };
            }
        }

        return [
            'capacity_bytes' => (int) $totalDisk,
            'used_bytes' => (int) $usedDisk,
            'free_bytes' => (int) $freeDisk,
            'capacity_readable' => $this->formatBytes((int) $totalDisk),
            'used_readable' => $this->formatBytes((int) $usedDisk),
            'usage_percent' => $totalDisk > 0 ? round(($usedDisk / $totalDisk) * 100, 2) : 0.0,
            'images' => $counts['images'],
            'audio' => $counts['audio'],
            'pdf' => $counts['pdf'],
            'doc' => $counts['doc'],
            'excel' => $counts['excel'],
            'others' => $counts['others'],
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = (int) floor(log($bytes, 1024));
        $pow = min($pow, count($units) - 1);
        return round($bytes / (1024 ** $pow), 2).' '.$units[$pow];
    }
}
