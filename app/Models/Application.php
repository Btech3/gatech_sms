<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Application extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_INFO_REQUESTED = 'info_requested';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WAITLISTED = 'waitlisted';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_ADMITTED = 'admitted';

    protected $fillable = [
        'application_number',
        'applicant_id',
        'intake_id',
        'campus_id',
        'course_id',
        'reviewer_id',
        'status',
        'decision_reason',
        'submitted_at',
        'review_started_at',
        'decision_at',
        'withdrawn_at',
        'waitlist_expires_at',
        'waitlist_position',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'review_started_at' => 'datetime',
            'decision_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'waitlist_expires_at' => 'datetime',
            'waitlist_position' => 'integer',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(Intake::class);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ApplicationStatusHistory::class);
    }

    public function admissionLetter(): HasOne
    {
        return $this->hasOne(AdmissionLetter::class);
    }
}
