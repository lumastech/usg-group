<?php

namespace App\Http\Resources;

use App\Models\NextOfKin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NextOfKin */
class NextOfKinResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'relationship' => $this->relationship,
            'relationship_label' => $this->relationship_label,
            'relationship_display' => $this->relationshipLabel(),
        ];
    }
}
