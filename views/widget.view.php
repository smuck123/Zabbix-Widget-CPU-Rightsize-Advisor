<?php
/**
 * CPU Rightsizing Advisor widget view.
 *
 * @var CView $this
 * @var array $data
 */

$rows = $data['rows'] ?? [];
$summary = $data['summary'] ?? [];
$settings = $data['settings'] ?? [];

$show_memory = (int) ($settings['show_memory'] ?? 1);
$show_total_cpu = (int) ($settings['show_total_cpu'] ?? 1);
$show_total_memory = (int) ($settings['show_total_memory'] ?? 1);
$show_costs = (int) ($settings['show_costs'] ?? 1);
$cpu_unit_cost = (float) ($settings['cpu_unit_cost'] ?? 0);
$memory_unit_cost = (float) ($settings['memory_unit_cost'] ?? 0);
$compact_mode = (int) ($settings['compact_mode'] ?? 0);

function cra_num($value): string {
    if ($value === null || $value === '') {
        return '-';
    }
    return number_format((float) $value, 1);
}

function cra_money($value): string {
    if ($value === null || $value === '') {
        return '-';
    }
    return number_format((float) $value, 2);
}

function cra_gb($value): string {
    if ($value === null || $value === '') {
        return '-';
    }
    return number_format((float) $value, 1).' GB';
}

function cra_percent_bar_class($value): string {
    $value = (float) $value;

    if ($value >= 90) {
        return 'cra-bar-fill cra-bar-high';
    }
    if ($value >= 70) {
        return 'cra-bar-fill cra-bar-warning';
    }
    if ($value >= 40) {
        return 'cra-bar-fill cra-bar-mid';
    }

    return 'cra-bar-fill cra-bar-low';
}

function cra_badge_class(string $risk): string {
    switch ($risk) {
        case 'good':
            return 'cra-badge cra-badge-good';
        case 'warning':
            return 'cra-badge cra-badge-warning';
        case 'high':
            return 'cra-badge cra-badge-high';
        default:
            return 'cra-badge cra-badge-info';
    }
}

function cra_trend_arrow_tag($last, $median): ?CTag {
    if ($last === null || $median === null) {
        return null;
    }

    $delta = (float) $last - (float) $median;

    if ($delta >= 5) {
        $tag = new CTag('span', true, '▲');
        $tag->setAttribute('class', 'cra-trend cra-trend-up');
        $tag->setAttribute('title', 'Above median');
        return $tag;
    }

    if ($delta <= -5) {
        $tag = new CTag('span', true, '▼');
        $tag->setAttribute('class', 'cra-trend cra-trend-down');
        $tag->setAttribute('title', 'Below median');
        return $tag;
    }

    $tag = new CTag('span', true, '•');
    $tag->setAttribute('class', 'cra-trend cra-trend-flat');
    $tag->setAttribute('title', 'Near median');
    return $tag;
}

function cra_make_sparkline(array $points, int $width = 320, int $height = 72): string {
    if (!$points) {
        return '';
    }

    $values = [];
    foreach ($points as $point) {
        $values[] = (float) ($point['value'] ?? 0);
    }

    $min = min($values);
    $max = max($values);
    $range = $max - $min;
    if ($range <= 0) {
        $range = 1;
    }

    if (count($values) === 1) {
        $values[] = $values[0];
    }

    $coords = [];
    foreach ($values as $i => $value) {
        $x = ($i / (count($values) - 1)) * ($width - 8) + 4;
        $y = $height - 6 - ((($value - $min) / $range) * ($height - 16));
        $coords[] = round($x, 2).','.round($y, 2);
    }

    $polyline = implode(' ', $coords);

    return '<svg class="cra-spark-svg" viewBox="0 0 '.$width.' '.$height.'" preserveAspectRatio="none">'
        .'<rect x="0" y="0" width="'.$width.'" height="'.$height.'" rx="8" ry="8" class="cra-spark-bg"></rect>'
        .'<polyline points="'.$polyline.'" class="cra-spark-line"></polyline>'
        .'</svg>';
}

function cra_metric_popup(?array $stats, string $label, string $metric_name, $display_value): CTag {
    $details = new CTag('details', true);
    $details->addClass('cra-metric-details');

    $summary = new CTag('summary', true);
    $summary->addClass('cra-metric-summary');

    $summary_wrap = new CDiv();
    $summary_wrap->addClass('cra-metric');

    if ($display_value === null || $display_value === '') {
        $summary_wrap->addItem(new CDiv('-'));
    }
    else {
        $label_div = new CDiv();
        $label_div->addClass('cra-metric-value');
        $label_div->addItem(number_format((float) $display_value, 1).'%');

        $trend = cra_trend_arrow_tag($stats['last'] ?? null, $stats['median'] ?? null);
        if ($trend !== null) {
            $label_div->addItem($trend);
        }

        $bar = new CDiv();
        $bar->addClass('cra-bar');

        $fill = new CDiv();
        $fill->setAttribute('class', cra_percent_bar_class((float) $display_value));
        $fill->setAttribute('style', 'width: '.max(0, min(100, (float) $display_value)).'%;');

        $bar->addItem($fill);

        $summary_wrap->addItem($label_div);
        $summary_wrap->addItem($bar);
    }

    $summary->addItem($summary_wrap);
    $details->addItem($summary);

    $body = new CDiv();
    $body->addClass('cra-metric-body');

    $title = new CDiv($label.' '.$metric_name);
    $title->addClass('cra-peak-meta');
    $body->addItem($title);

    $spark_html = cra_make_sparkline($stats['points'] ?? []);
    if ($spark_html !== '') {
        $spark = new CTag('div', true, $spark_html);
        $spark->addClass('cra-spark-wrap');
        $body->addItem($spark);
    }

    $stats_line = new CDiv(
        'Median: '.(isset($stats['median']) ? number_format((float) $stats['median'], 1).'%' : '-')
        .' | P95: '.(isset($stats['p95']) ? number_format((float) $stats['p95'], 1).'%' : '-')
        .' | Max: '.(isset($stats['max']) ? number_format((float) $stats['max'], 1).'%' : '-')
        .' | Last: '.(isset($stats['last']) ? number_format((float) $stats['last'], 1).'%' : '-')
    );
    $stats_line->addClass('cra-peak-meta');
    $body->addItem($stats_line);

    if (!empty($stats['peak_time'])) {
        $peak_line = new CDiv('Highest time: '.$stats['peak_time']);
        $peak_line->addClass('cra-peak-meta');
        $body->addItem($peak_line);
    }

    $details->addItem($body);

    return $details;
}

function cra_peak_details(?array $stats, string $label): CTag {
    $details = new CTag('details', true);
    $details->addClass('cra-peak-details');

    $peak_text = !empty($stats['peak_time']) ? $stats['peak_time'] : '-';
    $peak_value = isset($stats['max']) ? number_format((float) $stats['max'], 1).'%' : '-';

    $summary = new CTag('summary', true, $peak_text);
    $summary->addClass('cra-peak-summary');
    $details->addItem($summary);

    $body = new CDiv();
    $body->addClass('cra-peak-body');

    $meta = new CDiv($label.' peak: '.$peak_value);
    $meta->addClass('cra-peak-meta');
    $body->addItem($meta);

    $spark_html = cra_make_sparkline($stats['points'] ?? []);
    if ($spark_html !== '') {
        $spark = new CTag('div', true, $spark_html);
        $spark->addClass('cra-spark-wrap');
        $body->addItem($spark);
    }

    $stats_line = new CDiv(
        'Median: '.(isset($stats['median']) ? number_format((float) $stats['median'], 1).'%' : '-')
        .' | P95: '.(isset($stats['p95']) ? number_format((float) $stats['p95'], 1).'%' : '-')
        .' | Last: '.(isset($stats['last']) ? number_format((float) $stats['last'], 1).'%' : '-')
    );
    $stats_line->addClass('cra-peak-meta');
    $body->addItem($stats_line);

    $details->addItem($body);

    return $details;
}

$root = new CDiv();
$root->addClass('cra-root');

if ($compact_mode) {
    $root->addClass('cra-compact');
}

$summary_wrap = new CDiv();
$summary_wrap->addClass('cra-summary');

$cards = [
    [
        'label' => 'Hosts',
        'value' => (string) ($summary['total_rows'] ?? 0),
        'sub' => 'Displayed: '.($summary['displayed_rows'] ?? 0)
    ],
    [
        'label' => 'vCPU+vMEM candidates',
        'value' => (string) ($summary['both_candidates'] ?? 0),
        'sub' => 'vCPU: '.($summary['cpu_candidates'] ?? 0).' | vMEM: '.($summary['vmem_candidates'] ?? 0)
    ],
    [
        'label' => 'Top vCPU used',
        'value' => isset($summary['top_cpu_host']['value']) ? cra_num($summary['top_cpu_host']['value']).'%' : '-',
        'sub' => $summary['top_cpu_host']['host'] ?? '-'
    ],
    [
        'label' => 'Median vCPU',
        'value' => isset($summary['cpu_median_overall']) ? cra_num($summary['cpu_median_overall']).'%' : '-',
        'sub' => 'Across hosts'
    ]
];

if ($show_memory) {
    $cards[] = [
        'label' => 'Top vMEM used',
        'value' => isset($summary['top_mem_host']['value']) ? cra_num($summary['top_mem_host']['value']).'%' : '-',
        'sub' => $summary['top_mem_host']['host'] ?? '-'
    ];

    $cards[] = [
        'label' => 'Median vMEM',
        'value' => isset($summary['mem_median_overall']) ? cra_num($summary['mem_median_overall']).'%' : '-',
        'sub' => 'Across hosts'
    ];
}

if ($show_total_cpu) {
    $cards[] = [
        'label' => 'Total vCPU',
        'value' => isset($summary['total_vcpu']) ? cra_num($summary['total_vcpu']) : '-',
        'sub' => 'All shown hosts'
    ];
}

if ($show_total_memory) {
    $cards[] = [
        'label' => 'Total vMEM',
        'value' => isset($summary['total_vmem_gb']) ? cra_gb($summary['total_vmem_gb']) : '-',
        'sub' => 'All shown hosts'
    ];
}

if ($show_costs) {
    $cards[] = [
        'label' => 'Estimated Monthly Cost',
        'value' => isset($summary['total_estimated_monthly_cost']) ? cra_money($summary['total_estimated_monthly_cost']) : '-',
        'sub' => 'vCPU '.$cpu_unit_cost.' / unit | vMEM '.$memory_unit_cost.' / GB'
    ];

    $cards[] = [
        'label' => 'Possible Monthly Savings',
        'value' => isset($summary['total_possible_monthly_savings']) ? cra_money($summary['total_possible_monthly_savings']) : '-',
        'sub' => 'Based on current candidates'
    ];
}

foreach ($cards as $card_data) {
    $card = new CDiv();
    $card->addClass('cra-card');

    $label_div = new CDiv($card_data['label']);
    $label_div->addClass('cra-card-label');

    $value_div = new CDiv($card_data['value']);
    $value_div->addClass('cra-card-value');

    $sub_div = new CDiv($card_data['sub']);
    $sub_div->addClass('cra-card-sub');

    $card->addItem($label_div);
    $card->addItem($value_div);
    $card->addItem($sub_div);

    $summary_wrap->addItem($card);
}

$root->addItem($summary_wrap);

if (!$rows) {
    $empty = new CDiv('No matching hosts or items found. Adjust host groups or item patterns in widget settings.');
    $empty->addClass('cra-empty');
    $root->addItem($empty);
}
else {
    $table_wrap = new CDiv();
    $table_wrap->addClass('cra-table-wrap');

    $table = new CTag('table', true);
    $table->addClass('cra-table');

    $thead = new CTag('thead', true);
    $thead_row = new CTag('tr', true);

    $thead_row->addItem(new CTag('th', true, 'Host / capacity'));
    $thead_row->addItem(new CTag('th', true, 'vCPU median'));
    $thead_row->addItem(new CTag('th', true, 'vCPU p95'));
    $thead_row->addItem(new CTag('th', true, 'vCPU max'));
    $thead_row->addItem(new CTag('th', true, 'Highest vCPU time'));

    if ($show_memory) {
        $thead_row->addItem(new CTag('th', true, 'vMEM median'));
        $thead_row->addItem(new CTag('th', true, 'vMEM p95'));
        $thead_row->addItem(new CTag('th', true, 'Highest vMEM time'));
    }

    $thead_row->addItem(new CTag('th', true, 'Recommendation'));
    $thead->addItem($thead_row);

    $tbody = new CTag('tbody', true);

    foreach ($rows as $row) {
        $cpu = $row['cpu'] ?? null;
        $mem = $row['mem'] ?? null;

        $tr = new CTag('tr', true);

        $host_td = new CTag('td', true);
        $host_td->addClass('cra-host');

        $host_name = new CDiv($row['host'] ?? '');
        $host_name->addClass('cra-host-name');
        $host_td->addItem($host_name);

        $capacity = [];
        if (!empty($row['cpu_count'])) {
            $capacity[] = 'vCPU: '.(int) $row['cpu_count'];
        }
        if (!empty($row['vmem_total_gb'])) {
            $capacity[] = 'vMEM: '.cra_gb($row['vmem_total_gb']);
        }
        if (!empty($row['estimated_monthly_cost'])) {
            $capacity[] = 'Monthly: '.cra_money($row['estimated_monthly_cost']);
        }
        if (!empty($row['possible_monthly_savings'])) {
            $capacity[] = 'Save: '.cra_money($row['possible_monthly_savings']);
        }

        if ($capacity) {
            $cap_sub = new CDiv(implode(' | ', $capacity));
            $cap_sub->addClass('cra-sub cra-capacity');
            $host_td->addItem($cap_sub);
        }

        if (!empty($row['cpu_item'])) {
            $cpu_sub = new CDiv('vCPU item: '.$row['cpu_item']);
            $cpu_sub->addClass('cra-sub');
            $host_td->addItem($cpu_sub);
        }

        if ($show_memory && !empty($row['mem_item'])) {
            $mem_sub = new CDiv('vMEM item: '.$row['mem_item']);
            $mem_sub->addClass('cra-sub');
            $host_td->addItem($mem_sub);
        }

        $tr->addItem($host_td);

        $cpu_median_td = new CTag('td', true);
        $cpu_median_td->addClass('cra-metric-cell');
        $cpu_median_td->addItem(cra_metric_popup($cpu, 'vCPU', 'median', $cpu['median'] ?? null));
        $tr->addItem($cpu_median_td);

        $cpu_p95_td = new CTag('td', true);
        $cpu_p95_td->addClass('cra-metric-cell');
        $cpu_p95_td->addItem(cra_metric_popup($cpu, 'vCPU', 'p95', $cpu['p95'] ?? null));
        $tr->addItem($cpu_p95_td);

        $cpu_max_td = new CTag('td', true);
        $cpu_max_td->addClass('cra-metric-cell');
        $cpu_max_td->addItem(cra_metric_popup($cpu, 'vCPU', 'max', $cpu['max'] ?? null));
        $tr->addItem($cpu_max_td);

        $cpu_peak_td = new CTag('td', true);
        $cpu_peak_td->addClass('cra-peak-cell');
        $cpu_peak_td->addItem(cra_peak_details($cpu, 'vCPU'));
        $tr->addItem($cpu_peak_td);

        if ($show_memory) {
            $mem_median_td = new CTag('td', true);
            $mem_median_td->addClass('cra-metric-cell');
            $mem_median_td->addItem(cra_metric_popup($mem, 'vMEM', 'median', $mem['median'] ?? null));
            $tr->addItem($mem_median_td);

            $mem_p95_td = new CTag('td', true);
            $mem_p95_td->addClass('cra-metric-cell');
            $mem_p95_td->addItem(cra_metric_popup($mem, 'vMEM', 'p95', $mem['p95'] ?? null));
            $tr->addItem($mem_p95_td);

            $mem_peak_td = new CTag('td', true);
            $mem_peak_td->addClass('cra-peak-cell');
            $mem_peak_td->addItem(cra_peak_details($mem, 'vMEM'));
            $tr->addItem($mem_peak_td);
        }

        $rec_wrap = new CDiv();

        $badge = new CTag('span', true, $row['recommendation'] ?? 'Review');
        $badge->setAttribute('class', cra_badge_class($row['risk'] ?? 'info'));
        $rec_wrap->addItem($badge);

        $detail_bits = [];

        if (!empty($row['cpu_is_candidate'])) {
            $detail_bits[] = 'Low vCPU';
        }
        if (!empty($row['mem_is_candidate'])) {
            $detail_bits[] = 'Low vMEM';
        }
        if (!empty($row['cpu_busy'])) {
            $detail_bits[] = 'vCPU busy';
        }
        if (!empty($row['mem_busy'])) {
            $detail_bits[] = 'vMEM busy';
        }

        if ($detail_bits) {
            $detail = new CDiv(implode(' | ', $detail_bits));
            $detail->addClass('cra-sub cra-rec-sub');
            $rec_wrap->addItem($detail);
        }

        if (!empty($row['hint'])) {
            $hint = new CDiv($row['hint']);
            $hint->addClass('cra-hint');
            $rec_wrap->addItem($hint);
        }

        $rec_td = new CTag('td', true);
        $rec_td->addItem($rec_wrap);

        $tr->addItem($rec_td);
        $tbody->addItem($tr);
    }

    $table->addItem($thead);
    $table->addItem($tbody);
    $table_wrap->addItem($table);
    $root->addItem($table_wrap);
}

(new CWidgetView($data))
    ->addItem($root)
    ->show();
