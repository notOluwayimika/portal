<?php
 
namespace App\Models;
 
use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Concerns\HasStaffNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
 
class Teacher extends Model
{
    use AddUuid, SoftDeletes, HasStaffNumber, BelongsToSchool;
 
    protected $fillable = [
        'school_id',
        'user_id',
        'staff_number',
        'first_name',
        'last_name',
        'gender',
        'date_of_birth',
        'phone',
        'address',
        'qualification',
        'hire_date',
        'status',
        'photo_id',
    ];
 
    public $appends = ['full_name', 'name'];
 
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
 
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
 
    public function getNameAttribute(): string
    {
        return $this->full_name;
    }
 
    public function getPhotoAttribute(): ?string
    {
        return $this->photoFile?->url;
    }
 
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
 
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
 
    public function photoFile(): BelongsTo
    {
        return $this->belongsTo(FileUpload::class, 'photo_id');
    }
 
    public function assignedCurriculumSubjects(): HasMany
    {
        return $this->hasMany(TeacherCurriculumSubject::class, 'teacher_id');
    }
 
    public function assignments(): HasMany
    {
        return $this->hasMany(TeacherCurriculumSubject::class, 'teacher_id');
    }
}
