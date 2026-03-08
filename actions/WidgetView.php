<?php

namespace Modules\CpuRightsizingAdvisor\Actions;

use API;
use CControllerDashboardWidgetView;
use CControllerResponseData;

class WidgetView extends CControllerDashboardWidgetView {

    protected function doAction(): void {
        $groupids = $this->fields_values['groupids'] ?? [];
        $period_days = (int) ($this->fields_values['period_days'] ?? 30);
        $host_limit = (int) ($this->fields_values['host_limit'] ?? 25);

        $cpu_patterns = $this->parsePatterns($this->fields_values['cpu_patterns'] ?? '');
        $mem_patterns = $this->parsePatterns($this->fields_values['mem_patterns'] ?? '');
        $cpu_count_patterns = $this->parsePatterns($this->fields_values['cpu_count_patterns'] ?? '');
        $mem_total_patterns = $this->parsePatterns($this->fields_values['mem_total_patterns'] ?? '');

        $show_memory = (int) ($this->fields_values['show_memory'] ?? 1);
        $show_total_cpu = (int) ($this->fields_values['show_total_cpu'] ?? 1);
        $show_total_memory = (int) ($this->fields_values['show_total_memory'] ?? 1);
        $show_costs = (int) ($this->fields_values['show_costs'] ?? 1);
        $show_candidates_only = (int) ($this->fields_values['show_candidates_only'] ?? 0);
        $compact_mode = (int) ($this->fields_values['compact_mode'] ?? 0);

        $cpu_candidate_median = (float) ($this->fields_values['cpu_candidate_median'] ?? 20);
        $cpu_candidate_p95 = (float) ($this->fields_values['cpu_candidate_p95'] ?? 50);
        $mem_candidate_median = (float) ($this->fields_values['mem_candidate_median'] ?? 60);

        $cpu_unit_cost = $this->toFloat($this->fields_values['cpu_unit_cost'] ?? '15');
        $memory_unit_cost = $this->toFloat($this->fields_values['memory_unit_cost'] ?? '3');

        $time_from = time() - ($period_days * 86400);

        $host_params = [
            'output' => ['hostid', 'name'],
            'monitored_hosts' => true,
            'sortfield' => 'name',
            'preservekeys' => false
        ];

        if (!empty($groupids)) {
            $host_params['groupids'] = $groupids;
        }

        $hosts = API::Host()->get($host_params);
        $rows = [];

        foreach ($hosts as $host) {
            $items = API::Item()->get([
                'output' => ['itemid', 'hostid', 'name', 'key_', 'units', 'value_type', 'lastvalue'],
                'hostids' => [$host['hostid']],
                'filter' => [
                    'value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]
                ],
                'preservekeys' => false
            ]);

            if (!$items) {
                continue;
            }

            $cpu_item = $this->findBestItem($items, $cpu_patterns, 'cpu');
            $mem_item = $this->findBestItem($items, $mem_patterns, 'mem');
            $cpu_count_item = $this->findBestItem($items, $cpu_count_patterns, 'cpu_count');
            $mem_total_item = $this->findBestItem($items, $mem_total_patterns, 'mem_total');

            if (!$cpu_item && !$mem_item) {
                continue;
            }

            $cpu_stats = $cpu_item ? $this->getItemStats($cpu_item, $time_from, true) : null;
            $mem_stats = $mem_item ? $this->getItemStats($mem_item, $time_from, false) : null;

            if (!$cpu_stats && !$mem_stats) {
                continue;
            }

            $cpu_count = $this->getStaticNumericItemValue($cpu_count_item);
            $mem_total_bytes = $this->getStaticNumericItemValue($mem_total_item);
            $vmem_total_gb = $mem_total_bytes ? round($mem_total_bytes / 1024 / 1024 / 1024, 1) : null;

            $cpu_median = $cpu_stats['median'] ?? null;
            $cpu_p95 = $cpu_stats['p95'] ?? null;
            $cpu_max = $cpu_stats['max'] ?? null;

            $mem_median = $mem_stats['median'] ?? null;
            $mem_p95 = $mem_stats['p95'] ?? null;
            $mem_max = $mem_stats['max'] ?? null;

            $cpu_is_candidate = false;
            $mem_is_candidate = false;
            $cpu_busy = false;
            $mem_busy = false;

            if ($cpu_stats) {
                $cpu_is_candidate = ($cpu_median !== null
                    && $cpu_p95 !== null
                    && $cpu_median <= $cpu_candidate_median
                    && $cpu_p95 <= $cpu_candidate_p95);

                $cpu_busy = ($cpu_p95 !== null && $cpu_p95 >= 85)
                    || ($cpu_max !== null && $cpu_max >= 95);
            }

            if ($mem_stats) {
                $mem_is_candidate = ($mem_median !== null
                    && $mem_median <= $mem_candidate_median
                    && ($mem_p95 === null || $mem_p95 <= 80));

                $mem_busy = ($mem_median !== null && $mem_median >= 85)
                    || ($mem_p95 !== null && $mem_p95 >= 92)
                    || ($mem_max !== null && $mem_max >= 95);
            }

            $recommendation = 'Mixed profile / review';
            $risk = 'info';
            $score = 60;

            if ($cpu_is_candidate && $mem_is_candidate) {
                $recommendation = 'vCPU + vMEM candidate';
                $risk = 'good';
                $score = 10;
            }
            elseif ($cpu_is_candidate && !$mem_busy) {
                $recommendation = 'vCPU candidate';
                $risk = 'good';
                $score = 20;
            }
            elseif ($mem_is_candidate && !$cpu_busy) {
                $recommendation = 'vMEM candidate';
                $risk = 'warning';
                $score = 30;
            }
            elseif ($cpu_busy || $mem_busy) {
                $recommendation = 'Busy / consider increase';
                $risk = 'high';
                $score = 90;
            }

            if ($show_candidates_only && !$cpu_is_candidate && !$mem_is_candidate) {
                continue;
            }

            $capacity_hint = $this->buildCapacityHint(
                $cpu_is_candidate,
                $mem_is_candidate,
                $cpu_busy,
                $mem_busy,
                $cpu_count,
                $mem_total_bytes,
                $cpu_stats,
                $mem_stats
            );

            $estimated_monthly_cost = 0.0;
            if ($cpu_count !== null) {
                $estimated_monthly_cost += $cpu_count * $cpu_unit_cost;
            }
            if ($vmem_total_gb !== null) {
                $estimated_monthly_cost += $vmem_total_gb * $memory_unit_cost;
            }

            $possible_monthly_savings = $this->calculatePossibleSavings(
                $cpu_is_candidate,
                $mem_is_candidate,
                $cpu_count,
                $vmem_total_gb,
                $cpu_unit_cost,
                $memory_unit_cost
            );

            $rows[] = [
                'host' => $host['name'],
                'cpu_item' => $cpu_item ? $cpu_item['name'] : '',
                'mem_item' => $mem_item ? $mem_item['name'] : '',
                'cpu_count_item' => $cpu_count_item ? $cpu_count_item['name'] : '',
                'mem_total_item' => $mem_total_item ? $mem_total_item['name'] : '',
                'cpu' => $cpu_stats,
                'mem' => $mem_stats,
                'cpu_count' => $cpu_count,
                'mem_total_bytes' => $mem_total_bytes,
                'vmem_total_gb' => $vmem_total_gb,
                'estimated_monthly_cost' => round($estimated_monthly_cost, 2),
                'possible_monthly_savings' => round($possible_monthly_savings, 2),
                'recommendation' => $recommendation,
                'hint' => $capacity_hint,
                'risk' => $risk,
                'score' => $score,
                'cpu_is_candidate' => $cpu_is_candidate,
                'mem_is_candidate' => $mem_is_candidate,
                'cpu_busy' => $cpu_busy,
                'mem_busy' => $mem_busy
            ];
        }

        usort($rows, static function(array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $a['score'] <=> $b['score'];
            }

            $a_cpu = $a['cpu']['median'] ?? 9999;
            $b_cpu = $b['cpu']['median'] ?? 9999;

            if ($a_cpu === $b_cpu) {
                return strcmp($a['host'], $b['host']);
            }

            return $a_cpu <=> $b_cpu;
        });

        $summary_source_rows = $rows;

        if (count($rows) > $host_limit) {
            $rows = array_slice($rows, 0, $host_limit);
        }

        $cpu_medians = [];
        $mem_medians = [];
        $top_cpu_host = null;
        $top_mem_host = null;
        $cpu_candidates = 0;
        $vmem_candidates = 0;
        $both_candidates = 0;
        $total_vcpu = 0.0;
        $total_vmem_gb = 0.0;
        $total_estimated_monthly_cost = 0.0;
        $total_possible_monthly_savings = 0.0;

        foreach ($summary_source_rows as $row) {
            $cpu_median = $row['cpu']['median'] ?? null;
            $mem_median = $row['mem']['median'] ?? null;

            if ($cpu_median !== null) {
                $cpu_medians[] = (float) $cpu_median;

                if ($top_cpu_host === null || $cpu_median > ($top_cpu_host['value'] ?? -1)) {
                    $top_cpu_host = [
                        'host' => $row['host'],
                        'value' => round((float) $cpu_median, 2)
                    ];
                }
            }

            if ($mem_median !== null) {
                $mem_medians[] = (float) $mem_median;

                if ($top_mem_host === null || $mem_median > ($top_mem_host['value'] ?? -1)) {
                    $top_mem_host = [
                        'host' => $row['host'],
                        'value' => round((float) $mem_median, 2)
                    ];
                }
            }

            if ($row['recommendation'] === 'vCPU candidate') {
                $cpu_candidates++;
            }
            elseif ($row['recommendation'] === 'vMEM candidate') {
                $vmem_candidates++;
            }
            elseif ($row['recommendation'] === 'vCPU + vMEM candidate') {
                $both_candidates++;
            }

            if ($row['cpu_count'] !== null) {
                $total_vcpu += (float) $row['cpu_count'];
            }

            if ($row['vmem_total_gb'] !== null) {
                $total_vmem_gb += (float) $row['vmem_total_gb'];
            }

            $total_estimated_monthly_cost += (float) ($row['estimated_monthly_cost'] ?? 0);
            $total_possible_monthly_savings += (float) ($row['possible_monthly_savings'] ?? 0);
        }

        $summary = [
            'total_rows' => count($summary_source_rows),
            'displayed_rows' => count($rows),
            'cpu_candidates' => $cpu_candidates,
            'vmem_candidates' => $vmem_candidates,
            'both_candidates' => $both_candidates,
            'cpu_median_overall' => $cpu_medians ? $this->percentileFromUnsorted($cpu_medians, 50) : null,
            'mem_median_overall' => $mem_medians ? $this->percentileFromUnsorted($mem_medians, 50) : null,
            'top_cpu_host' => $top_cpu_host,
            'top_mem_host' => $top_mem_host,
            'total_vcpu' => round($total_vcpu, 1),
            'total_vmem_gb' => round($total_vmem_gb, 1),
            'total_estimated_monthly_cost' => round($total_estimated_monthly_cost, 2),
            'total_possible_monthly_savings' => round($total_possible_monthly_savings, 2)
        ];

        $this->setResponse(new CControllerResponseData([
            'name' => $this->getInput('name', $this->widget->getName()),
            'rows' => $rows,
            'summary' => $summary,
            'settings' => [
                'period_days' => $period_days,
                'show_memory' => $show_memory,
                'show_total_cpu' => $show_total_cpu,
                'show_total_memory' => $show_total_memory,
                'show_costs' => $show_costs,
                'cpu_unit_cost' => $cpu_unit_cost,
                'memory_unit_cost' => $memory_unit_cost,
                'compact_mode' => $compact_mode,
                'show_candidates_only' => $show_candidates_only
            ],
            'user' => [
                'debug_mode' => $this->getDebugMode()
            ]
        ]));
    }

    private function toFloat($value): float {
        $value = str_replace(',', '.', trim((string) $value));
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function parsePatterns(string $input): array {
        $parts = preg_split('/\s*,\s*/', trim($input));
        $patterns = [];

        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $patterns[] = mb_strtolower($part);
            }
        }

        return $patterns;
    }

    private function findBestItem(array $items, array $patterns, string $mode = ''): ?array {
        if (!$patterns) {
            return null;
        }

        $best_item = null;
        $best_score = -1;

        foreach ($items as $item) {
            $haystack = mb_strtolower(($item['key_'] ?? '') . ' ' . ($item['name'] ?? ''));
            $score = 0;

            foreach ($patterns as $pattern) {
                if ($pattern !== '' && mb_strpos($haystack, $pattern) !== false) {
                    $score += 10;
                }
            }

            if ($mode === 'cpu') {
                if (mb_strpos($haystack, 'cpu') !== false) {
                    $score += 3;
                }
                if (mb_strpos($haystack, 'idle') !== false) {
                    $score += 2;
                }
                if (mb_strpos($haystack, 'util') !== false) {
                    $score += 1;
                }
                if (mb_strpos($haystack, 'memory') !== false) {
                    $score -= 5;
                }
            }

            if ($mode === 'mem') {
                if (mb_strpos($haystack, 'memory') !== false || mb_strpos($haystack, 'mem') !== false) {
                    $score += 3;
                }
                if (mb_strpos($haystack, 'pused') !== false || mb_strpos($haystack, 'committed bytes') !== false) {
                    $score += 2;
                }
                if (mb_strpos($haystack, 'cpu') !== false) {
                    $score -= 5;
                }
            }

            if ($mode === 'cpu_count') {
                if (mb_strpos($haystack, 'system.cpu.num') !== false) {
                    $score += 5;
                }
                if (mb_strpos($haystack, 'logicalprocessor') !== false || mb_strpos($haystack, 'processor') !== false) {
                    $score += 3;
                }
                if (mb_strpos($haystack, 'util') !== false) {
                    $score -= 4;
                }
            }

            if ($mode === 'mem_total') {
                if (mb_strpos($haystack, 'total') !== false) {
                    $score += 5;
                }
                if (mb_strpos($haystack, 'vm.memory.size[total]') !== false) {
                    $score += 5;
                }
                if (mb_strpos($haystack, 'pused') !== false || mb_strpos($haystack, '%') !== false) {
                    $score -= 4;
                }
            }

            if ($score > $best_score) {
                $best_score = $score;
                $best_item = $item;
            }
        }

        return $best_score > 0 ? $best_item : null;
    }

    private function getStaticNumericItemValue(?array $item): ?float {
        if (!$item || !isset($item['lastvalue']) || !is_numeric($item['lastvalue'])) {
            return null;
        }

        return (float) $item['lastvalue'];
    }

    private function getItemStats(array $item, int $time_from, bool $cpu_mode = false): ?array {
        $history = API::History()->get([
            'output' => ['clock', 'value'],
            'itemids' => [$item['itemid']],
            'history' => $item['value_type'],
            'time_from' => $time_from,
            'sortfield' => 'clock',
            'sortorder' => 'ASC',
            'limit' => 5000
        ]);

        $values = [];
        $history_points = [];
        $last_value = null;
        $peak_value = null;
        $peak_clock = null;

        foreach ($history as $point) {
            if (!isset($point['value']) || !is_numeric($point['value'])) {
                continue;
            }

            $value = (float) $point['value'];

            if ($cpu_mode && isset($item['key_']) && stripos($item['key_'], 'idle') !== false) {
                $value = 100 - $value;
            }

            if ($value < 0) {
                $value = 0;
            }

            if ($value > 1000000) {
                continue;
            }

            $values[] = $value;
            $history_points[] = [
                'clock' => isset($point['clock']) ? (int) $point['clock'] : 0,
                'value' => round($value, 2)
            ];
            $last_value = $value;

            if ($peak_value === null || $value > $peak_value) {
                $peak_value = $value;
                $peak_clock = isset($point['clock']) ? (int) $point['clock'] : null;
            }
        }

        if (!$values && isset($item['lastvalue']) && is_numeric($item['lastvalue'])) {
            $last = (float) $item['lastvalue'];

            if ($cpu_mode && isset($item['key_']) && stripos($item['key_'], 'idle') !== false) {
                $last = 100 - $last;
            }

            $values[] = $last;
            $history_points[] = [
                'clock' => time(),
                'value' => round($last, 2)
            ];
            $last_value = $last;
            $peak_value = $last;
            $peak_clock = time();
        }

        if (!$values) {
            return null;
        }

        $sampled_points = $this->sampleHistoryPoints($history_points, 48);

        $sorted_values = $values;
        sort($sorted_values, SORT_NUMERIC);

        return [
            'median' => $this->percentile($sorted_values, 50),
            'p95' => $this->percentile($sorted_values, 95),
            'max' => round((float) max($sorted_values), 2),
            'last' => round((float) $last_value, 2),
            'count' => count($sorted_values),
            'peak_clock' => $peak_clock,
            'peak_time' => $peak_clock ? date('Y-m-d H:i', $peak_clock) : '',
            'points' => $sampled_points,
            'units' => $item['units'] ?? '%'
        ];
    }

    private function sampleHistoryPoints(array $points, int $max_points): array {
        $count = count($points);

        if ($count <= $max_points) {
            return $points;
        }

        $result = [];
        $step = ($count - 1) / ($max_points - 1);

        for ($i = 0; $i < $max_points; $i++) {
            $index = (int) round($i * $step);
            if (isset($points[$index])) {
                $result[] = $points[$index];
            }
        }

        return $result;
    }

    private function calculatePossibleSavings(
        bool $cpu_is_candidate,
        bool $mem_is_candidate,
        ?float $cpu_count,
        ?float $vmem_total_gb,
        float $cpu_unit_cost,
        float $memory_unit_cost
    ): float {
        $savings = 0.0;

        if ($cpu_is_candidate && $cpu_count !== null && $cpu_count >= 2) {
            $new_cpu = $this->recommendedEvenCpuForDecrease((int) floor($cpu_count / 2));
            $saved_cpu = max(0, $cpu_count - $new_cpu);
            $savings += $saved_cpu * $cpu_unit_cost;
        }

        if ($mem_is_candidate && $vmem_total_gb !== null && $vmem_total_gb > 0) {
            $new_vmem = $this->recommendedEvenMemory((int) round($vmem_total_gb * 0.75));
            $saved_vmem = max(0, $vmem_total_gb - $new_vmem);
            $savings += $saved_vmem * $memory_unit_cost;
        }

        return $savings;
    }

    private function buildCapacityHint(
        bool $cpu_is_candidate,
        bool $mem_is_candidate,
        bool $cpu_busy,
        bool $mem_busy,
        ?float $cpu_count,
        ?float $mem_total_bytes,
        ?array $cpu_stats,
        ?array $mem_stats
    ): string {
        $hints = [];

        if ($cpu_is_candidate && $cpu_count !== null && $cpu_count >= 2) {
            $new_cpu = $this->recommendedEvenCpuForDecrease((int) floor($cpu_count / 2));
            if ($new_cpu < (int) $cpu_count) {
                $hints[] = 'vCPU: decrease from '.(int) $cpu_count.' to '.$new_cpu;
            }
        }
        elseif ($cpu_is_candidate) {
            $hints[] = 'vCPU: looks oversized, review lower count';
        }

        if ($mem_is_candidate && $mem_total_bytes !== null && $mem_total_bytes > 0) {
            $new_mem_bytes = $mem_total_bytes * 0.75;
            $new_mem_gb = $this->recommendedEvenMemory((int) round($new_mem_bytes / 1024 / 1024 / 1024));
            $current_mem_gb = round($mem_total_bytes / 1024 / 1024 / 1024, 1);

            if ($new_mem_gb < $current_mem_gb) {
                $hints[] = 'vMEM: decrease from '.$current_mem_gb.' GB to about '.$new_mem_gb.' GB';
            }
        }
        elseif ($mem_is_candidate) {
            $hints[] = 'vMEM: looks oversized, review lower size';
        }

        if ($cpu_busy && $cpu_count !== null && $cpu_count >= 1) {
            $new_cpu = $this->recommendedEvenCpuForIncrease((int) ceil($cpu_count * 1.5));
            if ($new_cpu <= (int) $cpu_count) {
                $new_cpu = $this->recommendedEvenCpuForIncrease((int) $cpu_count + 1);
            }
            $hints[] = 'vCPU: consider increase from '.(int) $cpu_count.' to '.$new_cpu;
        }
        elseif ($cpu_busy) {
            $hints[] = 'vCPU: consider higher count';
        }

        if ($mem_busy && $mem_total_bytes !== null && $mem_total_bytes > 0) {
            $current_mem_gb = round($mem_total_bytes / 1024 / 1024 / 1024, 1);
            $new_mem_gb = max((int) ceil($current_mem_gb + 1), (int) round($current_mem_gb * 1.25));
            $new_mem_gb = $this->recommendedEvenMemory($new_mem_gb);
            $hints[] = 'vMEM: consider increase from '.$current_mem_gb.' GB to about '.$new_mem_gb.' GB';
        }
        elseif ($mem_busy) {
            $hints[] = 'vMEM: consider more memory';
        }

        if (!$hints && $cpu_stats && $mem_stats) {
            $hints[] = 'vCPU and vMEM are mixed, review trend before resizing';
        }
        elseif (!$hints && $cpu_stats) {
            $hints[] = 'vCPU trend available, review before resizing';
        }
        elseif (!$hints && $mem_stats) {
            $hints[] = 'vMEM trend available, review before resizing';
        }

        return implode(' | ', $hints);
    }

    private function percentileFromUnsorted(array $values, float $percentile): float {
        sort($values, SORT_NUMERIC);
        return $this->percentile($values, $percentile);
    }

    private function percentile(array $values, float $percentile): float {
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        if ($count === 1) {
            return round((float) $values[0], 2);
        }

        $index = ($percentile / 100) * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        if ($lower === $upper) {
            return round((float) $values[$lower], 2);
        }

        $weight = $index - $lower;
        $value = ((1 - $weight) * $values[$lower]) + ($weight * $values[$upper]);

        return round((float) $value, 2);
    }

    private function recommendedEvenCpuForDecrease(int $cpu): int {
        return $this->toEven($cpu, true, 2);
    }

    private function recommendedEvenCpuForIncrease(int $cpu): int {
        return $this->toEven($cpu, false, 2);
    }

    private function recommendedEvenMemory(int $memory_gb): int {
        return $this->toEven($memory_gb, false, 2);
    }

    private function toEven(int $value, bool $round_down, int $minimum_even): int {
        if ($value % 2 !== 0) {
            $value += $round_down ? -1 : 1;
        }

        if ($value < $minimum_even) {
            return $minimum_even;
        }

        return $value;
    }
}
