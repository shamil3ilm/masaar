<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Services\MetricsCollector;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * Prometheus Metrics Controller
 *
 * Exposes application metrics in Prometheus format for monitoring.
 * Access is enforced by the `metrics` middleware — see config/metrics.php.
 * MetricsCollector gathers the figures; this renders them.
 */
class MetricsController extends Controller
{
    public function __construct(
        private readonly MetricsCollector $metrics,
    ) {}

    /**
     * Export metrics in Prometheus format
     */
    public function index(): Response
    {
        $output = $this->formatPrometheus($this->metrics->collect());

        return response($output, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }

    /**
     * Format metrics in Prometheus exposition format
     */
    private function formatPrometheus(array $metrics): string
    {
        $output = [];

        foreach ($metrics as $name => $metric) {
            // Sanitize metric name
            $name = 'masaar_'.preg_replace('/[^a-zA-Z0-9_]/', '_', $name);

            // Add HELP line
            $output[] = "# HELP {$name} {$metric['help']}";

            // Add TYPE line
            $output[] = "# TYPE {$name} {$metric['type']}";

            // Add metric value with optional labels
            if (isset($metric['labels']) && ! empty($metric['labels'])) {
                $labelPairs = [];
                foreach ($metric['labels'] as $key => $value) {
                    $labelPairs[] = "{$key}=\"{$value}\"";
                }
                $labelStr = '{'.implode(',', $labelPairs).'}';
                $output[] = "{$name}{$labelStr} {$metric['value']}";
            } else {
                $output[] = "{$name} {$metric['value']}";
            }

            $output[] = ''; // Empty line between metrics
        }

        return implode("\n", $output);
    }
}
