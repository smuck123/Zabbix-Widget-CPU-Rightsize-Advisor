<?php
/**
 * CPU Rightsizing Advisor widget edit view.
 *
 * @var CView $this
 * @var array $data
 */

(new CWidgetFormView($data))
    ->addField(new CWidgetFieldMultiSelectGroupView($data['fields']['groupids']))
    ->addField(new CWidgetFieldIntegerBoxView($data['fields']['period_days']))
    ->addField(new CWidgetFieldIntegerBoxView($data['fields']['host_limit']))
    ->addField(new CWidgetFieldTextBoxView($data['fields']['cpu_patterns']))
    ->addField(new CWidgetFieldTextBoxView($data['fields']['mem_patterns']))
    ->addField(new CWidgetFieldTextBoxView($data['fields']['cpu_count_patterns']))
    ->addField(new CWidgetFieldTextBoxView($data['fields']['mem_total_patterns']))
    ->addField(new CWidgetFieldIntegerBoxView($data['fields']['cpu_candidate_median']))
    ->addField(new CWidgetFieldIntegerBoxView($data['fields']['cpu_candidate_p95']))
    ->addField(new CWidgetFieldIntegerBoxView($data['fields']['mem_candidate_median']))
    ->addField(new CWidgetFieldTextBoxView($data['fields']['cpu_unit_cost']))
    ->addField(new CWidgetFieldTextBoxView($data['fields']['memory_unit_cost']))
    ->addField(new CWidgetFieldCheckBoxView($data['fields']['show_memory']))
    ->addField(new CWidgetFieldCheckBoxView($data['fields']['show_total_cpu']))
    ->addField(new CWidgetFieldCheckBoxView($data['fields']['show_total_memory']))
    ->addField(new CWidgetFieldCheckBoxView($data['fields']['show_costs']))
    ->addField(new CWidgetFieldCheckBoxView($data['fields']['show_candidates_only']))
    ->addField(new CWidgetFieldCheckBoxView($data['fields']['compact_mode']))
    ->show();
