<?php

namespace App\Modules\HRMS\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HolidayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'name' => $this->name,
            // The weekday is what a person checks the list against; deriving
            // it on the client means two places computing the same thing.
            'weekday' => $this->date->format('l'),
        ];
    }
}
