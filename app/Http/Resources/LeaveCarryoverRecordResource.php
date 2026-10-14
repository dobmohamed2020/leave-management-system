<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LeaveCarryoverRecord */
class LeaveCarryoverRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'leave_type'         => $this->whenLoaded('leaveType', fn () => [
                'id'   => $this->leaveType->id,
                'code' => $this->leaveType->code,
                'name' => $this->leaveType->name,
            ]),
            'from_fiscal_year'   => $this->from_fiscal_year,
            'to_fiscal_year'     => $this->to_fiscal_year,
            'days_carried'       => $this->days_carried,
            'entitlement_grade'  => $this->entitlement_grade,
            'source'             => $this->source,
            'created_at'         => $this->created_at?->toIso8601String(),
        ];
    }
}
