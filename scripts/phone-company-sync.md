# Company-specific phone synchronization

`Sync:phone-numbers` keeps its existing LT default. The optional `--source` value accepts exactly `lt`, `ldr`, or `plaw`, mapped to the existing Snowflake configuration and `DP_LT`, `DP_LDR`, or `DP_PLAW` target label respectively. One invocation replaces only that selected label in the existing atomic transaction. It never relabels or removes generic `DP`, another company, or an unknown source.

The command retains original decimal contact IDs and the existing California conversion of `CONTACTS.CREATED` into `TblPhoneNumbers.Created_Date`. Matched prospect handling uses the deployed `Contacted` behavior. This change does not enable separate `Contacted_Date` attribution or infer original intake semantics for copied/imported contacts.

This implementation is prepared for review. Do not add source jobs, invoke a production replacement, or change the current LT automation until bounded source/target evidence and the relevant data acceptance are complete. Current generic-DP ownership and full CID/date coverage remain unproved. There is deliberately no `all` or generic-DP source option.

`--dry-run` reads a whole-source aggregate, so it is not a bounded production diagnostic. Use the separately reviewed private admission/sample tools for diagnosis. No scheduler, provider, integration gate, schema, environment, or separate DebtPlete application change is part of this patch.
