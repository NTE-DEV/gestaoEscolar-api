<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemMetricHistory extends Model
{
    protected $fillable = [
        'metric_date',
        'cpu_peak_percent',
        'cpu_avg_percent',
        'cpu_peak_time',
        'memory_peak_percent',
        'memory_avg_percent',
        'memory_peak_used_bytes',
        'memory_total_bytes',
        'samples_count',
    ];

    protected $casts = [
        'metric_date' => 'date:Y-m-d',
        'cpu_peak_percent' => 'float',
        'cpu_avg_percent' => 'float',
        'memory_peak_percent' => 'float',
        'memory_avg_percent' => 'float',
        'memory_peak_used_bytes' => 'integer',
        'memory_total_bytes' => 'integer',
        'samples_count' => 'integer',
    ];
}
