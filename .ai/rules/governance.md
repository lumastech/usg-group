---
paths:
  - 'app/Domain/Governance/**'
---

# Governance

## The two 60% thresholds use different bases, and round up
VotingThreshold::needed() is an integer ceiling — intdiv($base * 6000 + 9999, 10000) — compared with `>=`. So 29 members need 18 (17 would be 58.6%) and 30 need 18 (exactly 60% carries). Never switch this to round() or to a float 0.6; both quietly lower the bar the constitution sets. Quorum uses the same function.

Which population the 60% is taken against is fixed by MotionType::thresholdBasis(), never chosen by whoever records: NoConfidence counts against the WHOLE active membership (so a thin meeting cannot unseat an officer, and absence counts against the motion), Amendment and General against the members PRESENT. The difference is deliberate.

Deciding a motion snapshots eligible_count and votes_needed onto the row and is one-way. Quorum itself is never stored — it is read live off the register so the ring tracks the room — which is exactly why the snapshot exists: a member joining next month must not turn a carried motion into a failed one. Correct minutes with a fresh motion, never by editing a decided one.

Month arithmetic here must not overflow: notice on 31 Jan runs to 28 Feb, and six months from 31 Aug is 28 Feb. Use addMonthsNoOverflow throughout. Dates are CarbonImmutable (Date::use in AppServiceProvider), so type-hint CarbonInterface, not Illuminate\Support\Carbon.
