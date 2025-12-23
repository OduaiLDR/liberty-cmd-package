<?php

namespace Cmd\Reports\Support;

final class CmdReportPermissions
{
    private const MAP = [
        'cmd.reports',
        'cmd.reports.cancel_report',
        'cmd.reports.nsf_report',
        'cmd.reports.mailer_data_report',
        'cmd.reports.creditor_contacts_report',
        'cmd.reports.epf_paid_report',
        'cmd.reports.epf_due_report',
        'cmd.reports.capital_report',
        'cmd.reports.jordan_expenses_report',
        'cmd.reports.llg_exec_admin_report',
        'cmd.reports.veritas_report',
        'cmd.reports.agent_roi_report',
        'cmd.reports.settlement_analysis_report',
        'cmd.reports.enrollment_model_report',
        'cmd.reports.growth_model_report',
        'cmd.reports.enrollment_frequency_report',
        'cmd.reports.ldr_past_due_report',
        'cmd.reports.legal_report',
        'cmd.reports.reconsideration_report',
        'cmd.reports.dropped_report',
        'cmd.reports.cancellation_report',
        'cmd.reports.contact_report',
        'cmd.reports.enrollment_report',
        'cmd.reports.program_completion',
        'cmd.reports.lead_report',
        'cmd.reports.marketing_report',
        'cmd.reports.marketing_admin_report',
        'cmd.reports.drop_summary_report',
        'cmd.reports.charts_report',
        'cmd.reports.tranche_summary',
        'cmd.reports.team_ranks',
        'cmd.reports.team_cohesion_report',
        'cmd.reports.debt_portfolio_summary_report',
    ];

    /** @return array<int, string> */
    public static function names(): array
    {
        return self::MAP;
    }

    /** @return array<int, array{name:string,domain:string}> */
    public static function central(): array
    {
        return self::mapWithDomain('central');
    }

    /** @return array<int, array{name:string,domain:string}> */
    public static function tenant(): array
    {
        return self::mapWithDomain('tenant');
    }

    private static function mapWithDomain(string $domain): array
    {
        return array_map(
            static fn (string $name) => ['name' => $name, 'domain' => $domain],
            self::MAP,
        );
    }
}