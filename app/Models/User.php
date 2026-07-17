<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Concerns\BelongsToSchool;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToSchool, HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable, TwoFactorAuthenticatable;

    protected $fillable = ['first_name', 'last_name', 'email', 'password', 'school_id', 'signature_id', 'disabled_at'];

    protected $appends = ['full_name', 'name'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    private ?bool $isSuperAdminMemo = null;

    /**
     * Request-scoped memo of accessibleSchoolIds(), keyed by the
     * single_source_access flag so a mid-request flag change (the parity test)
     * recomputes rather than returning a stale source. Flushed by
     * grant/revokeSchoolAccess when access actually changes.
     *
     * @var array<int, Collection<int, int>>
     */
    private array $accessibleSchoolIdsMemo = [];

    /**
     * super_admin is a GLOBAL role (no team). Check it outside whatever
     * team/school context is currently active, then restore the context.
     */
    public function isSuperAdmin(): bool
    {
        if ($this->isSuperAdminMemo !== null) {
            return $this->isSuperAdminMemo;
        }

        $previousTeam = getPermissionsTeamId();

        setPermissionsTeamId(null);
        $this->unsetRelation('roles');

        $isSuperAdmin = $this->hasRole('super_admin');

        setPermissionsTeamId($previousTeam);
        $this->unsetRelation('roles');

        return $this->isSuperAdminMemo = $isSuperAdmin;
    }

    /**
     * Schools this user has been explicitly granted login access to
     * (managed by super admins, used for multi-school admins).
     */
    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class)->withTimestamps();
    }

    public function signatureFile(): BelongsTo
    {
        return $this->belongsTo(FileUpload::class, 'signature_id');
    }

    /**
     * All school ids this user may log into:
     *  - super admins: every school
     *  - explicit grants via the school_user pivot
     *  - guardians: schools of all their guardian records (wards)
     *  - fallback: their own school_id
     */
    public function accessibleSchoolIds(): Collection
    {
        $key = config('rbac.single_source_access') ? 1 : 0;

        return $this->accessibleSchoolIdsMemo[$key]
            ??= $this->computeAccessibleSchoolIds();
    }

    private function computeAccessibleSchoolIds(): Collection
    {
        if ($this->isSuperAdmin()) {
            return School::query()->pluck('id')->map(fn ($id) => (int) $id);
        }

        // Single source of truth (§7.1): access derives solely from role
        // assignments. Behind an expand/contract flag (default off) until the
        // parity test is green and the legacy sources are backfilled + dropped.
        if (config('rbac.single_source_access')) {
            return $this->schoolIdsFromRoles();
        }

        $ids = $this->schools()->pluck('schools.id');

        $ids = $ids->merge(
            Guardian::withoutGlobalScopes()
                ->where('user_id', $this->id)
                ->whereNull('deleted_at')
                ->pluck('school_id')
        );

        if ($this->school_id) {
            $ids->push($this->school_id);
        }

        return $ids->map(fn ($id) => (int) $id)->unique()->values();
    }

    /**
     * Clear the accessibleSchoolIds memo — call after any change to this user's
     * School access (role assignment/removal, pivot grant/revoke).
     */
    public function flushSchoolAccessCache(): void
    {
        $this->accessibleSchoolIdsMemo = [];
        $this->isSuperAdminMemo = null;
    }

    /**
     * Schools where this user holds any role assignment (model_has_roles).
     * The team-less super_admin row (school_id null) is excluded — super
     * admins are handled above. This is the target single source of access.
     */
    private function schoolIdsFromRoles(): Collection
    {
        $teamKey = config('permission.column_names.team_foreign_key');

        return DB::table(config('permission.table_names.model_has_roles'))
            ->where('model_type', $this->getMorphClass())
            ->where(config('permission.column_names.model_morph_key'), $this->getKey())
            ->whereNotNull($teamKey)
            ->pluck($teamKey)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    public function accessibleSchools(): \Illuminate\Database\Eloquent\Collection
    {
        return School::whereIn('id', $this->accessibleSchoolIds())->orderBy('name')->get();
    }

    public function canAccessSchool(int|string $schoolId): bool
    {
        return $this->isSuperAdmin() || $this->accessibleSchoolIds()->contains((int) $schoolId);
    }

    /**
     * Grant login access to a school and assign the admin role within it.
     */
    public function grantSchoolAccess(School $school, string $role = 'admin'): void
    {
        $this->schools()->syncWithoutDetaching([$school->id]);

        $previousTeam = getPermissionsTeamId();

        // Ensure the role exists globally before assigning it within the team.
        setPermissionsTeamId(null);
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);

        setPermissionsTeamId($school->id);
        $this->unsetRelation('roles');
        $this->assignRole($role);

        setPermissionsTeamId($previousTeam);
        $this->unsetRelation('roles');
        $this->flushSchoolAccessCache();
    }

    /**
     * Revoke login access to a school and remove the admin role within it.
     */
    public function revokeSchoolAccess(School $school, string $role = 'admin'): void
    {
        $this->schools()->detach($school->id);

        $previousTeam = getPermissionsTeamId();

        setPermissionsTeamId($school->id);
        $this->unsetRelation('roles');

        if ($this->hasRole($role)) {
            $this->removeRole($role);
        }

        setPermissionsTeamId($previousTeam);
        $this->unsetRelation('roles');
        $this->flushSchoolAccessCache();
    }

    public function getFullNameAttribute()
    {
        return $this->first_name.' '.$this->last_name;
    }

    public function getNameAttribute()
    {
        return $this->full_name;
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function teacher()
    {
        return $this->hasOne(Teacher::class, 'user_id');
    }

    public function student()
    {
        return $this->hasOne(Student::class, 'user_id');
    }

    public function guardian()
    {
        return $this->hasOne(Guardian::class, 'user_id');
    }

    public function isDisabled(): bool
    {
        return ! is_null($this->disabled_at);
    }

    /**
     * Block login for disabled accounts via Laravel's auth contract hook.
     * Returns the cleartext password when the account is active; an
     * unguessable value when disabled so password verification fails.
     */
    public function getAuthPassword(): string
    {
        return $this->isDisabled() ? '$2y$12$disabled.account.cannot.login.'.bin2hex(random_bytes(8)) : $this->password;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['password'])
            ->logOnlyDirty();
    }
}
