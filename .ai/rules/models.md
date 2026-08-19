---
paths:
  - 'app/Models/**'
---

# Models

## Money is BIGINT ngwee; set model defaults in $attributes, not just the migration
Every money column is a BIGINT of ngwee (K1 = 100 ngwee), suffixed _ngwee, cast with App\Casts\MoneyCast to a Brick\Money\Money. No floats anywhere. Use App\Support\Kwacha for construction (Kwacha::of / ofNgwee) and formatting.

Trap: a column whose default lives only in the migration reads back as NULL on a freshly created model until you refresh() it, because Eloquent does not know the database default. Cycle hit this — joining_fee_ngwee came back null right after updateOrCreate. Mirror money/config defaults in the model's protected $attributes as raw ints so a new instance is readable without a round trip.
