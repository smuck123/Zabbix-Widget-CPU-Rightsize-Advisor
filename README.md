# CPU Rightsizing Advisor (Zabbix Widget)

CPU Rightsizing Advisor is a custom Zabbix dashboard widget that highlights hosts likely to be over-provisioned by analyzing historical CPU and memory utilization.

It summarizes utilization medians/peaks, estimates right-size recommendations, and can show potential vCPU/RAM/cost savings.

## Features

- Host group filtering
- Configurable lookback window (`period_days`) and max rows (`host_limit`)
- Flexible item pattern matching for:
  - CPU utilization
  - Memory utilization
  - CPU core count
  - Total memory
- Candidate detection using configurable thresholds:
  - CPU median threshold
  - CPU P95 threshold
  - Memory median threshold
- Optional cards for:
  - Total vCPU
  - Total RAM
  - Estimated cost
- Candidate-only filtering
- Compact mode for dense dashboards

## Repository structure

- `manifest.json` — module/widget manifest and action mapping
- `Widget.php` — widget entry class
- `includes/WidgetForm.php` — dashboard widget configuration form fields
- `actions/WidgetView.php` — backend data collection, calculations, and response payload
- `views/widget.edit.php` — widget edit UI
- `views/widget.view.php` — widget rendering
- `assets/css/widget.css` — widget styling

## Installation

1. Place this module directory under your Zabbix modules path (for example, `ui/modules/CPU Rightsizing_advisor` in a source-based setup).
2. Ensure ownership/permissions allow the Zabbix frontend process to read module files.
3. In Zabbix frontend, go to **Administration → General → Modules**.
4. Locate **CPU Rightsizing Advisor** and enable it.
5. Open a dashboard, click **Edit dashboard**, add widget **CPU Rightsizing Advisor**.

> Folder name must match your deployment expectations; the manifest ID is `CPU Rightsizing_advisor`.

## Widget configuration

### Scope and limits

- **Host groups**: Optional group filter for included hosts
- **History period (days)**: Range used for trend statistics (default: 30)
- **Maximum rows**: Row cap for output table (default: 25)

### Item pattern fields

Patterns are comma-separated and matched against item keys/names to find the best metrics per host.

- **CPU item patterns**
- **Memory item patterns**
- **CPU count item patterns**
- **Total memory item patterns**

If your environment uses custom item keys, add them here in priority order.

### Candidate thresholds

A host is considered a rightsizing candidate when it matches configured conditions.

- **CPU candidate median <= %** (default: 20)
- **CPU candidate p95 <= %** (default: 50)
- **Memory candidate median <= %** (default: 60)

### Cost settings

- **CPU cost per vCPU** (default: `15`)
- **Memory cost per GB** (default: `3`)

Used only for estimated savings summaries.

### Visibility toggles

- **Show memory columns**
- **Show total vCPU summary**
- **Show total RAM summary**
- **Show estimated cost summary**
- **Show candidates only**
- **Compact mode**

## How recommendations are interpreted

The widget is advisory. Use recommendations as a starting point and validate with:

- workload seasonality
- maintenance/backup windows
- burst requirements
- business-criticality and SLOs

Before reducing resources, validate with load testing and phased rollout where possible.

## Compatibility notes

- Built as a Zabbix dashboard widget module (`manifest_version: 2.0`).
- Requires items that expose CPU/memory usage and capacity metrics for each host.
- Best results are achieved when consistent item naming conventions are used across hosts.

## Troubleshooting

- **No data shown**
  - Verify selected host groups contain hosts with relevant items.
  - Expand pattern lists to include your environment's item keys.
  - Increase history period if data is sparse.
- **Unexpected candidates**
  - Revisit threshold values.
  - Compare against known busy periods.
- **Cost estimates look off**
  - Update unit cost fields to match your internal pricing model.

## Author

- Janne Kivelä
