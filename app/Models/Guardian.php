<?php

namespace App\Models;

use App\Concerns\AddUuid;
use App\Concerns\BelongsToSchool;
use App\Support\SchoolAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Guardian extends Model
{
    use AddUuid, BelongsToSchool, HasFactory, LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'first_name',
                'middle_name',
                'last_name',
                'gender',
                'phone',
                'whatsapp_number',
                'city',
                'state',
                'country',
                'postal_code',
                'occupation',
                'employer_name',
                'marital_status',
                'emergency_contact',
                'id_type',
                'id_number',
                'id_expiry_date',
                'status',
                'photo_id',
            ])
            ->logOnlyDirty()
            ->useLogName('guardian');
    }

    protected $fillable = [
        'school_id',
        'user_id',
        'first_name',
        'middle_name',
        'last_name',
        'gender',
        'phone',
        'whatsapp_number',
        'city',
        'state',
        'country',
        'postal_code',
        'occupation',
        'employer_name',
        'marital_status',
        'emergency_contact',
        'photo_id',
        'id_type',
        'id_number',
        'id_expiry_date',
        'status',
    ];

    protected $casts = [
        'id_expiry_date' => 'date',
    ];

    public $appends = ['full_name', 'name'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * `live_identity` is a MySQL GENERATED column (2026_08_19_100000_add_guardian_live_identity_uniqueness)
     * and MySQL refuses any INSERT that names it — error 3105, "the value specified for generated
     * column ... is not allowed".
     *
     * `replicate()` copies `$this->getAttributes()` verbatim, and a hydrated Guardian carries
     * `live_identity` like any other selected column, so the clone's INSERT names it and dies. That is
     * not theoretical: GuardianService::resolveOrCreateGuardianForUserInSchool clones a Guardian into a
     * second School (§6.2, the multi-school parent) with `$template->replicate(['uuid'])`, and adding
     * the index without this override breaks that path outright. Verified by trying it.
     *
     * Excluded HERE rather than at the call site because the column belongs to this model, and there is
     * more than one caller — the parked guardian-merge work replicates too. A per-call `$except` entry
     * would have to be remembered by every future one.
     *
     * Passing a non-empty `$except` is safe: Model::replicate() merges the key and timestamp defaults
     * into whatever it is given, so nothing that would normally be dropped is retained.
     *
     * @param  array<int, string>|null  $except
     */
    public function replicate(?array $except = null): static
    {
        return parent::replicate(array_merge($except ?? [], ['live_identity']));
    }

    /**
     * A guardian profile is owned by its original school, but the linked
     * user may be granted access to additional schools through school_user.
     */
    public function applySchoolScope(Builder $builder, int $schoolId): void
    {
        $builder->where(function (Builder $query) use ($schoolId) {
            $query->where('guardians.school_id', $schoolId)
                ->orWhereIn('guardians.user_id', SchoolAccess::userIdsWithAccessTo($schoolId));
        });
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getNameAttribute(): string
    {
        return $this->full_name;
    }

    public function getPhotoAttribute(): ?string
    {
        return $this->photoFile?->url;
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<FileUpload, $this> */
    public function photoFile(): BelongsTo
    {
        return $this->belongsTo(FileUpload::class, 'photo_id');
    }

    /** @return BelongsToMany<Student, $this> */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'guardian_student')
            ->withPivot(['relationship', 'is_primary', 'can_login'])
            ->withTimestamps();
    }
}
