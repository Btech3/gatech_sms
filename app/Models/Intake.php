<?php

namespace App\Models;

use Database\Factories\IntakeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Intake extends Model
{
    /** @use HasFactory<IntakeFactory> */
    use HasFactory;

    protected $fillable = [
        'academic_session_id',
        'course_id',
        'campus_id',
        'name',
        'starts_on',
        'applications_open_at',
        'applications_close_at',
        'capacity',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'applications_open_at' => 'datetime',
            'applications_close_at' => 'datetime',
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
