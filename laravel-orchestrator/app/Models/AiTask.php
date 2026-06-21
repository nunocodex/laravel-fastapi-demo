<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiTask extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $table = 'ai_tasks';

    protected $fillable = [
        'task_uuid',
        'status',
        'document_id',
        'prompt_template',
        'result',
        'metadata',
        'error',
        'completed_at',
    ];

    protected $casts = [
        'result' => 'array',
        'metadata' => 'array',
        'completed_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['task_uuid'];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }
}
