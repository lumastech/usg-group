<?php

namespace Database\Factories;

use App\Enums\ApportionmentItemStatus;
use App\Models\DiasporaApportionment;
use App\Models\DiasporaApportionmentItem;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DiasporaApportionmentItem> */
class DiasporaApportionmentItemFactory extends Factory
{
    protected $model = DiasporaApportionmentItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'diaspora_apportionment_id' => DiasporaApportionment::factory(),
            'member_id' => Member::factory(),
            'amount_ngwee' => 50_000,
            'status' => ApportionmentItemStatus::Pending,
        ];
    }
}
