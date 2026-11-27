<?php

namespace App\Models;

use Database\Factories\AcademyProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AcademyProfile extends Model
{
    /** @use HasFactory<AcademyProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'singleton_id',
        'name',
        'logo_path',
        'address',
        'phone',
        'email',
        'registration_number',
        'currency',
        'timezone',
        'date_format',
        'letterhead',
    ];
}
