<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Trip
 */
final class TripResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ask' => $this->ask,
            'status' => $this->status,
            'phase' => $this->phase,
            'pending' => $this->pending,
            'summary' => $this->summary,
            'outcome' => $this->outcome,
            'note' => $this->note,
            'error' => $this->error,
            'created_at' => $this->created_at->toAtomString(),
            'updated_at' => $this->updated_at->toAtomString(),
        ];
    }
}
