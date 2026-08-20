---
paths:
  - 'app/Models/**'
  - app/Models/CommitteeTerm.php
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
