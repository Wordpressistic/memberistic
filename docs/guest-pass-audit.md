# Guest Pass Audit (`wp memberistic guest-pass-audit`)

## Background

An earlier version of the plugin auto-created a "Guest Pass" membership for
anyone who made a booking. That hook has been removed (see
`includes/corporate/class-corporate-module.php` — the
`[memberistic_guest_pass]` registration form is now the only way to issue a
Guest Pass), but the memberships it created are still in the database and
inflate member counts, waiver chases and marketing lists.

`wp memberistic guest-pass-audit` finds those leftover rows, classifies every
membership on the `guest-pass` plan, and — only when explicitly asked — expires
the auto-created ones. Nothing is ever deleted: the WP user, bookings,
payments, waiver records and QR/verification data are left untouched, and every
change is journaled so it can be rolled back.

## Usage

```
wp memberistic guest-pass-audit [--apply] [--rollback] [--format=<csv|json>]
                                [--batch-size=<n>] [--resume-from=<membership_id>]
```

| Flag | Default | Meaning |
| --- | --- | --- |
| `--apply` | off | **Dry-run is the default.** Without `--apply` the command classifies and reports but writes nothing to the database. |
| `--rollback` | off | Restore memberships previously expired by this command (see [Rollback](#rollback)). Also defaults to dry-run; add `--apply` to execute. |
| `--format=<csv\|json>` | `csv` | Format of the per-record report written to `wp-content/uploads/memberistic-audits/` (path echoed at the end of the run). |
| `--batch-size=<n>` | `100` | Rows processed per batch. In apply mode each batch runs inside one DB transaction (`START TRANSACTION` / `COMMIT`), so a batch either fully lands or fully rolls back. |
| `--resume-from=<membership_id>` | `0` | Resume at this membership id (inclusive). Processing is always ordered by ascending membership id, and a failed batch reports the exact `--resume-from` value to re-run with. |

### Examples

```
# 1. Preview — classify everything, write a CSV report, change nothing.
wp memberistic guest-pass-audit

# 2. Same preview but as JSON.
wp memberistic guest-pass-audit --format=json

# 3. Apply — expire the auto_created bucket.
wp memberistic guest-pass-audit --apply

# 4. Resume an apply run that stopped at membership 1234.
wp memberistic guest-pass-audit --apply --resume-from=1234

# 5. Undo: preview the rollback, then execute it.
wp memberistic guest-pass-audit --rollback
wp memberistic guest-pass-audit --rollback --apply
```

## Buckets and evidence rules

Every membership on the plan with slug `guest-pass` is classified into exactly
one bucket. Each record in the report carries a confidence score (0–1) and a
list of the concrete evidence that produced the classification.

### `legitimate` — never touched

A record is legitimate when **any** of these signals is present (checked
first, so an intentionally issued pass can never be auto-expired):

- it has at least one successful row in `wp_memberistic_payments`
  (status `completed` / `paid` / `succeeded`), or
- the `guest-pass` plan has a non-zero monthly or annual price, or
- `payment_source` is anything other than `guest_pass`, or
- its activity history (`wp_memberistic_activity`) contains rows whose
  `created_by` is a real staff user (a user holding `manage_options`,
  `manage_memberistic`, `manage_memberistic_members` or
  `edit_memberistic_members`) — i.e. staff manually issued it.

### `auto_created` — high confidence, the only bucket `--apply` modifies

All of the following must hold:

- `payment_source = 'guest_pass'`, **and**
- no successful row in `wp_memberistic_payments`, **and**
- the plan price is 0, **and**
- either the membership was created within ±10 minutes of a
  bookings row for the same customer email (in whichever booking table the
  mapped booking adapter resolves to), **or** `created_by`
  is 0/NULL (system-created).

### `ambiguous` — never auto-modified

Everything that matches neither rule set. Listed in the report for manual
review only; the command will not change these rows in any mode.

### `already_processed`

Memberships whose notes already carry the `memberistic_gpa_processed` audit
flag from a previous `--apply` run. Re-running the command skips them and
reports them in this bucket — the command is idempotent.

## What `--apply` does (auto_created records only)

1. Sets the membership `status` to `expired`. The row is **not** deleted.
2. Appends a note to the membership:
   `Expired by guest-pass-audit <date>: auto-created from booking [memberistic_gpa_processed]`.
   The bracketed flag is the idempotency marker.
3. Logs a `membership_expired` activity row
   (`related_object_type = guest_pass_audit`).
4. Writes an audit-log row to `wp_memberistic_logs` with
   `source = 'guest_pass_audit'` whose JSON context records the **previous
   status** (this journal powers the rollback).
5. Adds user meta `_memberistic_customer_segment = auto_created_guest` to the primary user.
6. Leaves the WP user, booking rows, payments, waiver records and
   QR/verification data completely untouched.

Each batch is wrapped in a transaction; if any write fails the batch is rolled
back and the command exits with the `--resume-from` value to continue from.

Note: statuses are changed with direct table writes, deliberately bypassing
`Memberships_Repository::change_status()` so the
`memberistic_membership_status_changed` integrations hook (Stripe cancel
propagation, POS/coreSTORE sync) does not fire mid-transaction for these
zero-value rows. If your site mirrors status into an external POS, trigger a
re-sync after the run.

## Rollback

Every expire is journaled to `wp_memberistic_logs`
(`source = 'guest_pass_audit'`, context `action = 'expire'`, including the
previous status per record). The rollback mode replays that journal:

```
wp memberistic guest-pass-audit --rollback           # preview
wp memberistic guest-pass-audit --rollback --apply   # execute
```

For every journaled expire that has not already been rolled back it:

- restores the membership to its recorded previous status,
- removes the `[memberistic_gpa_processed]` flag from the notes and appends a
  `Status restored … [memberistic_gpa_rolled_back]` note,
- logs a `membership_status_changed` activity row and a
  `action = 'rollback'` audit-log row (so a second rollback run is a no-op),
- deletes the `_memberistic_customer_segment` user meta, but only when the original
  run set it and it still equals `auto_created_guest` (a later manual change wins).

Safety: a membership is skipped (and reported) if its row no longer exists, if
its status is no longer `expired`, or if the audit flag has been removed from
its notes — i.e. anything modified since the audit is left alone.

After a rollback, a subsequent `--apply` run will classify those rows again
and, if they still meet the `auto_created` rules, re-expire them. Rollback is
an undo, not an exclusion list.

## Reports

Every run (including dry-runs and rollbacks) writes a per-record report to:

```
wp-content/uploads/memberistic-audits/guest-pass-audit-<mode>-<timestamp>.<csv|json>
```

Columns/fields: `membership_id`, `membership_uuid`, `primary_user_id`,
`email`, `status_before`, `status_after`, `bucket`, `confidence`, `action`,
`evidence`. The terminal output additionally shows a bucket summary table and
a before/after count of guest-pass memberships by status.

## Recommended runbook

1. **Backup the database** (at minimum `wp_memberistic_memberships`,
   `wp_memberistic_activity`, `wp_memberistic_logs`, `wp_usermeta`):
   `wp db export pre-guest-pass-audit.sql`
2. **Dry-run**: `wp memberistic guest-pass-audit`
3. **Review the CSV** in `uploads/memberistic-audits/` — especially the
   `ambiguous` bucket (manual review only) and spot-check a few
   `auto_created` rows against their evidence.
4. **Apply**: `wp memberistic guest-pass-audit --apply`
5. **Verify counts**: compare the before/after status table printed by the
   command with the dry-run's expectations; re-run
   `wp memberistic guest-pass-audit` once more — every previously expired row
   should now report as `already_processed` and the auto_created count should
   be 0.
6. If anything looks wrong:
   `wp memberistic guest-pass-audit --rollback --apply` (or restore the DB
   backup).
