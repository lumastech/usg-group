# Storage and backups

The group's entire financial record is one database on one server, and every ledger
in it is immutable by design — corrections are reversing entries, never edits. That
makes a restore the only recovery there is for a lost table.

---

## What is stored where

| What | Where | Kept |
| --- | --- | --- |
| Database (all ledgers, members, governance) | MySQL | forever |
| Nightly dumps | `notifications.backups.disk`, `backups/` | `BACKUP_RETENTION_DAYS`, default 30 |
| Monthly statement packs | `notifications.statement_pack.disk`, `statement-packs/{cycle}/{YYYY-MM}/` | forever |
| Uploaded workbooks | `storage/app/imports/` | until the import is confirmed |
| Activity log | `activity_log` table | forever — it is the audit trail |

Statement packs are rebuilt from the ledgers by `unity:statement-pack`, so losing
them is an inconvenience rather than a loss. Losing the database is a loss.

---

## The nightly dump

`unity:backup-database` is scheduled for 01:30 Africa/Lusaka. It writes
`backups/unity-YYYY-MM-DD-HHMMSS.sql` to the configured disk and deletes dumps past
the retention window. Files not named `unity-*` are left alone.

```bash
php artisan unity:backup-database              # scheduled run
php artisan unity:backup-database --keep=90    # keep three months
php artisan unity:backup-database --disk=s3    # write off the box
```

MySQL credentials are passed to `mysqldump` through a temporary defaults file, never
on the command line, so they do not appear in the server's process list. The dump is
taken with `--single-transaction`, so it is consistent without locking the group out
mid-write.

### Configuration

```dotenv
BACKUP_DISK=local
BACKUP_DIRECTORY=backups
BACKUP_RETENTION_DAYS=30
```

**A backup on the same disk as the database is not a backup.** Point `BACKUP_DISK`
at off-box storage before the cycle carries real money, or copy the directory off
the server nightly.

---

## Restoring

```bash
mysql -u <user> -p <database> < backups/unity-2026-08-21-013000.sql
php artisan unity:rebuild-summaries      # cached balances, derived from the ledgers
php artisan unity:sync-committee-roles   # portal roles, derived from committee_terms
```

Both of those rebuild derived state from the ledgers that were restored. Run them or
the dashboards will show the figures the dump was taken with rather than the ones the
ledgers hold.

Then check one member's statement against a printed voucher before telling anybody
the system is back.

---

## Verifying a backup

A dump nobody has restored is a hope, not a backup. Once a cycle:

1. Restore the latest dump into a scratch database.
2. Run `php artisan unity:reconcile-social-fund` against it.
3. Open one member's statement and check the closing balance against their last
   voucher.
