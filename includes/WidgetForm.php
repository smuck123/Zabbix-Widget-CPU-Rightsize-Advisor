<?php

namespace Modules\CpuRightsizingAdvisor\Includes;

use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\CWidgetFieldCheckBox;
use Zabbix\Widgets\Fields\CWidgetFieldIntegerBox;
use Zabbix\Widgets\Fields\CWidgetFieldMultiSelectGroup;
use Zabbix\Widgets\Fields\CWidgetFieldTextBox;

class WidgetForm extends CWidgetForm {

    public function addFields(): self {
        return $this
            ->addField(
                (new CWidgetFieldMultiSelectGroup('groupids', _('Host groups')))
            )
            ->addField(
                (new CWidgetFieldIntegerBox('period_days', _('History period (days)'), 1, 365))
                    ->setDefault(30)
            )
            ->addField(
                (new CWidgetFieldIntegerBox('host_limit', _('Maximum rows'), 1, 200))
                    ->setDefault(25)
            )
            ->addField(
                (new CWidgetFieldTextBox('cpu_patterns', _('CPU item patterns (comma-separated)')))
                    ->setDefault('system.cpu.util,system.cpu.util[,idle],perf_counter[\\Processor(_Total)\\% Processor Time],perf_counter[\\Processor Information(_Total)\\% Processor Utility],CPU utilization')
            )
            ->addField(
                (new CWidgetFieldTextBox('mem_patterns', _('Memory item patterns (comma-separated)')))
                    ->setDefault('vm.memory.util,vm.memory.size[pused],perf_counter[\\Memory\\% Committed Bytes In Use],Memory utilization')
            )
            ->addField(
                (new CWidgetFieldTextBox('cpu_count_patterns', _('CPU count item patterns (comma-separated)')))
                    ->setDefault('system.cpu.num,wmi.get[root/cimv2,"Select NumberOfLogicalProcessors from Win32_ComputerSystem"],NumberOfLogicalProcessors')
            )
            ->addField(
                (new CWidgetFieldTextBox('mem_total_patterns', _('Total memory item patterns (comma-separated)')))
                    ->setDefault('vm.memory.size[total],vm.memory.size,total memory,Total physical memory')
            )
            ->addField(
                (new CWidgetFieldIntegerBox('cpu_candidate_median', _('CPU candidate median <= %'), 1, 100))
                    ->setDefault(20)
            )
            ->addField(
                (new CWidgetFieldIntegerBox('cpu_candidate_p95', _('CPU candidate p95 <= %'), 1, 100))
                    ->setDefault(50)
            )
            ->addField(
                (new CWidgetFieldIntegerBox('mem_candidate_median', _('Memory candidate median <= %'), 1, 100))
                    ->setDefault(60)
            )
            ->addField(
                (new CWidgetFieldTextBox('cpu_unit_cost', _('CPU cost per vCPU')))
                    ->setDefault('15')
            )
            ->addField(
                (new CWidgetFieldTextBox('memory_unit_cost', _('Memory cost per GB')))
                    ->setDefault('3')
            )
            ->addField(
                (new CWidgetFieldCheckBox('show_memory', _('Show memory columns')))
                    ->setDefault(1)
            )
            ->addField(
                (new CWidgetFieldCheckBox('show_total_cpu', _('Show total vCPU summary')))
                    ->setDefault(1)
            )
            ->addField(
                (new CWidgetFieldCheckBox('show_total_memory', _('Show total RAM summary')))
                    ->setDefault(1)
            )
            ->addField(
                (new CWidgetFieldCheckBox('show_costs', _('Show estimated cost summary')))
                    ->setDefault(1)
            )
            ->addField(
                (new CWidgetFieldCheckBox('show_candidates_only', _('Show candidates only')))
                    ->setDefault(0)
            )
            ->addField(
                (new CWidgetFieldCheckBox('compact_mode', _('Compact mode')))
                    ->setDefault(0)
            );
    }
}
