<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IdentityResource extends JsonResource
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
            'national_id' => $this->national_id,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'backup_phone' => $this->backup_phone,
            'marital_status' => $this->marital_status,
            'family_members_count' => $this->family_members_count,
            'spouse_name' => $this->spouse_name,
            'spouse_phone' => $this->spouse_phone,
            'spouse_national_id' => $this->spouse_national_id,
            'primary_address' => $this->primary_address,
            'previous_address' => $this->previous_address,
            'region' => $this->region,
            'locality' => $this->locality,
            'branch' => $this->branch,
            'mosque' => $this->mosque,
            'housing_type' => $this->housing_type,
            'job_title' => $this->job_title,
            'health_status' => $this->health_status,
            'notes' => $this->notes,
            'needs_review' => (bool) $this->needs_review,
            'entered_at' => optional($this->entered_at)->toDateTimeString(),
            'last_verified_at' => optional($this->last_verified_at)->toDateTimeString(),
            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
            'family_members' => $this->relationLoaded('familyMembers')
                ? FamilyMemberResource::collection($this->familyMembers)
                : [],
        ];
    }
}
