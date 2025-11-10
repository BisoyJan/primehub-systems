<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceUpload extends Model
{
    use HasFactory;
    protected $fillable = [
        'uploaded_by',
        'original_filename',
        'stored_filename',
        'shift_date',
        'biometric_site_id',
        'total_records',
        'processed_records',
        'matched_employees',
        'unmatched_names',
        'unmatched_names_list',
        'date_warnings',
        'dates_found',
        'status',
        'notes',
        'error_message',
    ];

    protected $casts = [
        'shift_date' => 'date:Y-m-d',
        'unmatched_names_list' => 'array',
        'date_warnings' => 'array',
        'dates_found' => 'array',
        'total_records' => 'integer',
        'processed_records' => 'integer',
        'matched_employees' => 'integer',
        'unmatched_names' => 'integer',
    ];

    /**
     * Get the user who uploaded the file.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Get the biometric site where the file was from.
     */
    public function biometricSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'biometric_site_id');
    }

    /**
     * Get the file path for the stored file.
     */
    public function getFilePathAttribute(): string
    {
        return storage_path('app/attendance_uploads/' . $this->stored_filename);
    }
}
