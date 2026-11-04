# Proven current company migrations

The existing LT planner can identify one current enrolled backend even when Azure retains an old company link. Previously that proof was used for the LT refresh but discarded before the final matching step. The final global uniqueness guard then prevented the main identity from reaching the current backend enrollment.

The planner now completes the native LT main-row remap only when its exact fresh source selection reports `single_enrolled`, every competing SQL company candidate is stale in that same source read, the native LT main is the sole accepted target, the selected company identity has one physical owner, and its destination main identity is absent. A plan-company fallback, live competing file, occupied destination, changed identity/snapshot, or missing proof cannot authorize this migration. No generic SQL uniqueness guard is relaxed.

The normal transactional apply path checks the full main-row before-image and requires the destination to remain absent, using SQL Server update/range locks. A race returns a zero-row update and fails the transaction. The stale company and main records are preserved. Existing campaign and mailer-key attribution controls remain in force. Repeated planning after the remap uses the selected destination without another move.

`tests/Unit/ContactMigrationMatchingTest.php` exercises this with synthetic in-memory SQLite contacts and mocked Forth responses. No live sync, database repair, or source report was executed to implement this change. The correction establishes current identity routing only; it does not certify historical commission ownership or authorize a retrospective pay change.
