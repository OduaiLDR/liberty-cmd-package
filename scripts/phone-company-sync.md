# Company-specific phone synchronization

`Sync:phone-numbers` keeps its existing LT default. The optional `--source` value accepts exactly `lt`, `ldr`, or `plaw`, mapped to the existing Snowflake configuration and `DP_LT`, `DP_LDR`, or `DP_PLAW` target label respectively. One invocation replaces only that selected label in the existing atomic transaction. It never relabels or removes generic `DP`, another company, or an unknown source.

The command retains original decimal contact IDs and the existing California conversion of `CONTACTS.CREATED` into `TblPhoneNumbers.Created_Date`. Matched prospect handling uses the deployed `Contacted` behavior. This change does not enable separate `Contacted_Date` attribution or infer original intake semantics for copied/imported contacts.

This implementation is prepared for review. Do not add source jobs, invoke a production replacement, or change the current LT automation until bounded source/target evidence and the relevant data acceptance are complete. Current generic-DP ownership and full CID/date coverage remain unproved. There is deliberately no `all` or generic-DP source option.

The command runs inside the host telemetry privacy scope and disables Snowflake debug only for that invocation. It restores the exact previous process value, including an unset key, before a long-lived worker handles another job. It never edits the environment file. Failures report the fixed phase, safe error class/SQLSTATE, source label and whether the phone snapshot committed; exception messages, objects, SQL values and connector token fragments are excluded from command output and application error-log context. A later contact-flag failure must not claim that the already committed phone snapshot rolled back.

Commit status is captured immediately when the native transaction commits, before postcommit refresh work. It resets for each invocation and replacement, so a reused command cannot carry a previous successful commit into a later failure report.

The matched-contact update uses its own 1,800-second SQL query budget and restores the connection's previous timeout on success or failure. This leaves room for staging/replacement within the observed 3,600-second normal automation limit; the general export-step allowance is not used as the contact-sync budget. It does not change the global database timeout or automation configuration. This budget is not a performance or full-run completion guarantee, and it does not authorize a manual production replay.

`--dry-run` reads a whole-source aggregate, so it is not a bounded production diagnostic. Use the separately reviewed private admission/sample tools for diagnosis. No scheduler, provider, integration gate, schema, environment, or separate DebtPlete application change is part of this patch.
