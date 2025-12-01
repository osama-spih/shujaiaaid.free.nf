<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FamilyMemberResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'identity_id' => $this->identity_id,
            'member_name' => $this->member_name,
            'relation' => $this->relation,
            'national_id' => $this->national_id,
            'phone' => $this->phone,
            'birth_date' => optional($this->birth_date)->toDateString(),
            'is_guardian' => (bool) $this->is_guardian,
            'needs_care' => (bool) $this->needs_care,
            'health_status' => $this->health_status,
            'education_status' => $this->education_status,
            'notes' => $this->notes,
        ];
    }
}
