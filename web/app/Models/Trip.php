<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $ask
 * @property string $status
 * @property string|null $phase
 * @property array<string, mixed>|null $pending
 * @property array<string, mixed>|null $summary
 * @property string|null $outcome
 * @property string|null $note
 * @property string|null $error
 * @property string|null $run_id
 * @property int|null $execution_attempt
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Trip extends Model
{
    use HasUlids;

    public const string WORKING = 'working';
    public const string WAITING = 'waiting';
    public const string FINISHED = 'finished';
    public const string FAILED = 'failed';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pending' => 'array',
            'summary' => 'array',
            'execution_attempt' => 'integer',
        ];
    }

    public function isWaitingFor(string $type): bool
    {
        return $this->status === self::WAITING && ($this->pending['type'] ?? null) === $type;
    }
}
