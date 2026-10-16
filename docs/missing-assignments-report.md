# Missing Assignments Report (ticket #149)

CMD Runner command: `Generate:missing-assignments-report`.

Configure the weekly Monday delivery time in CMD Runner. This package does not
create or enable a schedule.

Configure `TblReports` with report name `MissingAssignmentsReport` (or
`Missing Assignments Report`). Set Send_To to Adam Moore and Jose Balanos, and
Send_CC to Jacob Hilderbrand and Bryan, using their verified email addresses.
The combined report reads those recipients without a company filter, hardcoded
addresses, or extra environment recipients. A missing recipient configuration or
delivery failure returns a failed command and keeps the generated workbook.

The report selects `TblContacts` using the ticket's filters: `Agent = ''`,
`Stage = 'Client'`, and `Status <> 'Funded'`. It starts on the first of the prior
Friday's month and ends on that Friday, inclusive, using calendar dates in
America/Los_Angeles. Saturday is the exclusive SQL upper boundary. For example,
Monday November 2, 2026 reports October 1–30, because the prior Friday is October
30. The same rule applies across year boundaries. Empty reports are still emailed.
No NULL-agent or additional status rules are introduced.

The workbook contains Enrollment ID (`LLG_ID`), Lending Tower ID, Client, Status,
and Created Date, ordered by Created Date and then Client, both ascending.
Lending Tower IDs come only from `TblContactsLDR.External_ID` or
`TblContactsPLAW.External_ID`, matched by `LLG_ID`. The main contacts' External_ID
is never used. The existing contact identity guard must corroborate a unique
physical source owner and a valid numeric ID; missing, duplicate, invalid, or
conflicting mappings leave the Lending Tower ID blank and highlighted. The client
remains in the report, and the email notes the unresolved count.

For a SELECT-only preview with no workbook, email, or database writes:

```sh
php artisan Generate:missing-assignments-report --dry-run
```

To build and retain the workbook without sending email or writing database records:

```sh
php artisan Generate:missing-assignments-report --no-email
```

Normal delivery uses the existing email service and its usual delivery logging.
