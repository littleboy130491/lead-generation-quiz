<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubmissionEvent extends Model
{
    use HasFactory;

    protected $fillable = ['event', 'context_snapshot', 'details'];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('Submission events are append-only and cannot be updated.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        if ($this->exists) {
            throw new \LogicException('Submission events are append-only and cannot be deleted.');
        }

        return parent::delete();
    }

    protected function casts(): array
    {
        return [
            'context_snapshot' => 'array',
            'details' => 'array',
        ];
    }

    public function submission()
    {
        return $this->belongsTo(Submission::class);
    }
}
