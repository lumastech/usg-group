---
paths:
  - 'app/Models/**'
  - app/Models/CommitteeTerm.php
  - app/Models/PayoutDestination.php
---

# Models

## Money is BIGINT ngwee; set model defaults in $attributes, not just the migration
Every money column is a BIGINT of ngwee (K1 = 100 ngwee), suffixed _ngwee, cast with App\Casts\MoneyCast to a Brick\Money\Money. No floats anywhere. Use App\Support\Kwacha for construction (Kwacha::of / ofNgwee) and formatting.

Trap: a column whose default lives only in the migration reads back as NULL on a freshly created model until you refresh() it, because Eloquent does not know the database default. Cycle hit this — joining_fee_ngwee came back null right after updateOrCreate. Mirror money/config defaults in the model's protected $attributes as raw ints so a new instance is readable without a round trip.

## Committee portal roles are derived from terms, never granted by hand
CommitteeRoleSync is the only thing that touches the four committee spatie roles. Recording a term grants the matching role in the same transaction; ending it revokes. It leaves `member` and `admin` alone.

CommitteeRole (offices) is deliberately separate from MemberRole (permission bundles): CommitteeRole::Signatory maps to portalRole() === null — a signatory's authority is at the bank, so the term is recorded and shown but grants nothing in the portal. Signatory is also the one office that may have several holders and may be held alongside an executive office.

TermEndReason::Removed is never accepted from a request. A removal is written by MotionRecorder when a no-confidence motion carries; EndCommitteeTermRequest rejects it.

`unity:sync-committee-roles` reconciles roles from committee_terms after an import or restore — nothing in normal use needs it.

## Changing where money is sent is the highest-value attack; four controls guard it
Redirecting a payout needs no ledger tampering at all, so `PayoutDestinationService` is the only thing that may write this table, and it enforces:

1. The provider is asked whose account it is (`/resolve/*`) BEFORE anything is saved. An unverifiable destination sitting in the list looking like the others is worse than none.
2. The resolved name is scored against `members.full_name` by `AccountNameMatcher` (initials match the name they stand for; titles are stripped). A low score does not block — maiden names and spouses' wallets are ordinary here — but it is stored, shown in red at the point of signing, and a member may never clear their own.
3. A destination added or changed inside `payments.transfers.destination_cooling_off_hours` (48h) needs a second committee signature. This is what defeats "take over the login, change the number, wait for share-out".
4. Every change dispatches `PayoutDestinationChanged`, which notifies the member on the contacts they had BEFORE the change.

Uniqueness is on a sha1 `fingerprint`, not the columns: MySQL treats NULLs as distinct, so a composite index across the bank and wallet columns would let the same wallet be added twice on the strength of its empty bank half. One default per member is an application rule — MySQL has no partial unique index for it.
