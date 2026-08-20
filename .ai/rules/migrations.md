---
paths:
  - 'database/migrations/**'
---

# Migrations

## Name foreign keys by hand on long table names
MySQL caps identifiers at 64 characters and Laravel derives FK names as {table}_{column}_foreign. next_of_kin_repayment_arrangements blew that limit on second_approver_member_id — the CREATE succeeds and the ALTER fails, leaving a table behind with no migration row, so the retry then reports "table already exists". Pass constrained('members', indexName: 'short_name_foreign') on any table whose name is long, and drop the stray table before re-running.
