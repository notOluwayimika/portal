<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Concerns\BelongsToSchool;
use App\Exceptions\NullTeamRoleAssignmentException;
use App\Notifications\Enums\ChannelKey;
use App\Support\ContactPointAuthority;
use App\Support\DutySeparation;
use App\Support\SchoolAccessParity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
    use BelongsToSchool, HasApiTokens, HasFactory, LogsActivity, Notifiable, TwoFactorAuthenticatable;

    use HasRoles {
        assignRole as protected spatieAssignRole;
    }

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

    /** S7 parity soak: ensures the dual-compare runs at most once per instance. */
    private bool $paritySoakDone = false;

    /**
     * The domain of the SYNTHETIC placeholder address.
     *
     * `users.email` is NOT NULL and UNIQUE, so a guardian created without a real
     * address still needs one. GuardianService mints `{phone}@no-email.local`, or a
     * randomised `guardian+{random}@…` when there is no phone — randomised precisely
     * to clear the unique index. Nothing at that domain can receive mail.
     *
     * Defined HERE rather than in the minting service because seven call sites need
     * to recognise it and only two need to create it.
     */
    public const SYNTHETIC_EMAIL_DOMAIN = '@no-email.local';

    /**
     * Can this account actually be SENT an email?
     *
     * DELIBERATELY NARROW: present AND not synthetic. It does NOT consider
     * `disabled_at`, and that omission is the whole point — "can this address
     * receive mail" and "is this login active" are different questions, and
     * GuardiansExport is the only caller that wants both. Folding `disabled_at` in
     * here would silently change whether disabled guardians receive bulk messages
     * and password-reset mail, and nothing would surface it until one of them got
     * something they should not have. Callers that want "active login" keep their
     * own `disabled_at` check beside this one.
     *
     * IT ALSO OWNS THE NULL GUARD. The predicate this replaces took `string $email`
     * and answered synthetic-ness alone, so every one of its seven call sites paired
     * it with a separate `! $user->email ||`. Two checks, seven copies, one of which
     * (GuardiansExport) cast through `(string)` and would therefore read a NULL
     * address as deliverable. That is unreachable today — the column is NOT NULL —
     * and becomes reachable the moment the synthetic mint is retired and the column
     * goes nullable, which is the next PR. Folding the guard in fixes it ahead of
     * the change that would expose it.
     */
    /**
     * THE ONE RESOLUTION. Gate, mail router and export all read this.
     *
     * Returning the ADDRESS rather than a boolean is what makes the deliverability
     * GATE and the send ACTION the same source by construction rather than by
     * coincidence. They used to be two: `hasDeliverableEmail()` answered from
     * `users.email` while `Password::sendResetLink → ResetPassword` routed through
     * `routeNotificationForMail()`, which Laravel defaults to `$this->email`. Those
     * agree today only because contact points were backfilled FROM that column — an
     * accident of provenance. The moment an email edit lands in `contact_points`
     * instead, a gate that says yes and a router that mails the old address is a
     * password reset that silently goes nowhere.
     *
     * GATED ON THE BACKFILL MARKER, failing safe to the legacy string. Between
     * code-live and backfill-complete every flipped reader would otherwise mis-answer
     * for the WHOLE populated database: bulk messaging no-ops school-wide, password
     * reset refuses everyone. The gate is not co-deploy timing; it is the data's own
     * completion marker.
     */
    public function deliverableEmailAddress(): ?string
    {
        if (! app(ContactPointAuthority::class)->isAuthoritative()) {
            // LEGACY, unchanged: trimmed, non-empty, not the minted sentinel.
            $email = trim((string) $this->email);

            return ($email !== '' && ! self::isSyntheticEmail($email)) ? $email : null;
        }

        return $this->emailContactPoint()?->normalized_address;
    }

    public function hasDeliverableEmail(): bool
    {
        return $this->deliverableEmailAddress() !== null;
    }

    /**
     * Where Laravel actually sends mail — INCLUDING the password-reset broker.
     *
     * ⚠️ THIS MUST LAND WITH OR BEFORE `users.email` GOES NULLABLE. Laravel's default
     * routes mail to `$this->email`; once that column can be null, an unoverridden
     * router turns every `notify()` and every reset into a send-to-null by accident.
     * The override is what converts "email is nullable" from a latent null-route into
     * a defined skip.
     *
     * NULL IS A DELIBERATE SKIP, not an error: MailChannel declines to send when the
     * route is empty, which is the correct behaviour for a guardian on record with no
     * address — the population the synthetic sentinel used to serve.
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->deliverableEmailAddress();
    }

    /**
     * @return HasMany<ContactPoint, $this>
     *
     * NOT scoped by school, and that is not an omission — `contact_points` has no
     * `school_id` column BY DESIGN (#199): an address belongs to a human, not a
     * tenancy, because one person is a parent at one school and staff at another with
     * one phone. The tenancy boundary for this read is the USER, enforced wherever
     * that user was fetched; a contact-point query is already person-scoped and
     * cannot cross tenants on its own.
     */
    public function contactPoints(): HasMany
    {
        return $this->hasMany(ContactPoint::class);
    }

    /**
     * This person's email contact point.
     *
     * READS THE LOADED RELATION WHEN PRESENT, which is the entire reason eager
     * loading works at the loop call sites. A `->contactPoints()->where(...)->first()`
     * that always queries would make `with('user.contactPoints')` decorative and leave
     * the N+1 exactly where it was — the flip turns an O(1) string test into a
     * per-row query, and the export asks twice per row.
     */
    public function emailContactPoint(): ?ContactPoint
    {
        if ($this->relationLoaded('contactPoints')) {
            return $this->contactPoints
                ->firstWhere('channel', ChannelKey::EMAIL);
        }

        return $this->contactPoints()
            ->where('channel', ChannelKey::EMAIL->value)
            ->orderByDesc('is_primary')
            ->first();
    }

    /**
     * Is this a MINTED PLACEHOLDER rather than a real address?
     *
     * ONE DEFINITION, because two inlined copies drift — and this one already had.
     * The backfill and this predicate each carried their own
     * `str_ends_with($email, SYNTHETIC_EMAIL_DOMAIN)`, and a fix that added a trim to
     * the backfill's copy alone left them DISAGREEING about a padded sentinel: the
     * migration excluded it while the predicate called it deliverable. That is the
     * same inlined-copy drift the lift removed at five sites, recreated at two by
     * repairing one of them.
     *
     * IT TRIMS, because every caller is reading a stored column that may carry
     * whitespace from an import or an edit, and the two views of one value must not
     * disagree about which characters count.
     */
    public static function isSyntheticEmail(string $email): bool
    {
        return str_ends_with(trim($email), self::SYNTHETIC_EMAIL_DOMAIN);
    }

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
    /** @return BelongsToMany<School, $this> */
    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class)->withTimestamps();
    }

    /** @return BelongsTo<FileUpload, $this> */
    public function signatureFile(): BelongsTo
    {
        return $this->belongsTo(FileUpload::class, 'signature_id');
    }

    /**
     * All school ids this user may log into. Super admins always get every school.
     * Otherwise the resolution is EITHER/OR on rbac.single_source_access, not a
     * union of both:
     *  - flag ON  (single source, §7.1): model_has_roles ONLY (schoolIdsFromRoles).
     *    A user with a role in a School's team has access; nothing else is read.
     *  - flag OFF (legacy, current default): the legacy UNION only —
     *    school_user pivot ∪ guardian records ∪ users.school_id. Roles are NOT
     *    read here, which is why a role-only user resolves to [] under the flag
     *    (the S7 finding). The legacy sources are removed once the flag is on
     *    everywhere and the columns are dropped.
     */
    public function accessibleSchoolIds(): Collection
    {
        $key = config('rbac.single_source_access') ? 1 : 0;

        $result = $this->accessibleSchoolIdsMemo[$key]
            ??= $this->computeAccessibleSchoolIds();

        // S7 parity soak: when enabled, compute BOTH resolution paths for this
        // decision and log any divergence (once per user instance per request).
        // This runs regardless of which path is returned above — dual-compute,
        // not flag-flip — so per-user divergence is caught on live traffic
        // before the legacy columns are dropped.
        if (config('rbac.parity_soak') && ! $this->paritySoakDone && ! $this->isSuperAdmin()) {
            $this->paritySoakDone = true;
            SchoolAccessParity::compare(
                $this,
                $this->legacyAccessibleSchoolIds(),
                $this->schoolIdsFromRoles(),
            );
        }

        return $result;
    }

    private function computeAccessibleSchoolIds(): Collection
    {
        if ($this->isSuperAdmin()) {
            return School::query()->pluck('id')->map(fn ($id) => (int) $id);
        }

        // Single source of truth (§7.1): access derives solely from role
        // assignments. Behind an expand/contract flag (default off) until the
        // parity soak is green and the legacy sources are backfilled + dropped.
        if (config('rbac.single_source_access')) {
            return $this->schoolIdsFromRoles();
        }

        return $this->legacyAccessibleSchoolIds();
    }

    /**
     * Legacy School-access union: school_user pivot + guardian records +
     * users.school_id fallback. Retained (behind single_source_access=off and
     * for the parity soak) until S7 drops the legacy columns.
     */
    private function legacyAccessibleSchoolIds(): Collection
    {
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
        $this->paritySoakDone = false;
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
     * Enforce the S7 invariant: a school-scoped role may never be assigned with a
     * null permissions-team, because that grants access to no School (divergence).
     * `super_admin` is the sole team-less role and is exempt. This overrides the
     * spatie HasRoles method so the guard cannot be bypassed at any call site.
     *
     * @param  array<int, Collection|\Spatie\Permission\Contracts\Role|string>|Collection|\Spatie\Permission\Contracts\Role|string  ...$roles
     */
    public function assignRole(...$roles): static
    {
        $teamId = getPermissionsTeamId();

        if ($teamId === null) {
            $schoolScoped = collect($roles)->flatten()
                ->map(fn ($r) => is_string($r) ? $r : ($r->name ?? null))
                ->filter()
                ->reject(fn ($n) => $n === 'super_admin');

            if ($schoolScoped->isNotEmpty()) {
                throw new NullTeamRoleAssignmentException($schoolScoped->values()->all());
            }
        }

        // User-level segregation-of-duties enforcement (Finance pairs only — Decision 0). This
        // override is the one place EVERY role assignment crosses, so guarding here covers HTTP,
        // programmatic and seeder paths and cannot be bypassed at a call site — the same reason the
        // null-team guard above lives here. It refuses BEFORE spatieAssignRole, so a violating grant
        // lands nothing (wholesale). Finance is per-school, so a team-less assignment (super_admin,
        // exempt above) is not enforced.
        if ($teamId !== null) {
            DutySeparation::assertAssignmentAllowed($this, (int) $teamId, $roles);
        }

        return $this->spatieAssignRole(...$roles);
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

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return HasOne<Teacher, $this> */
    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class, 'user_id');
    }

    /** @return HasOne<Student, $this> */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class, 'user_id');
    }

    /** @return HasOne<Guardian, $this> */
    public function guardian(): HasOne
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

    /**
     * Account-security fields. The audit trail records that each of these CHANGED,
     * on which account and by whom — the notification layer's Tier-1 signal reads
     * the same rows rather than keeping a second field list of its own.
     *
     * ⚠️ THE VALUES OF THESE ARE CREDENTIAL MATERIAL, and are stripped before the
     * row is written by the `creating` hook on App\Models\Activity, using
     * config/activity_log_sensitive.php `fields`. `password` alone used to be
     * logged here and its bcrypt hash WAS reaching the column; adding
     * `two_factor_secret` and `two_factor_recovery_codes` without that redaction in
     * place would re-open the same hole for a live TOTP seed. Keeping the columns
     * here and the redaction there is deliberate: the fact of the change is
     * auditable, the secret is not retained.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'password',
                'email',
                'two_factor_secret',
                'two_factor_confirmed_at',
                // Not a credential, but the security-relevant state change that
                // takes an account in or out of service.
                'disabled_at',
            ])
            ->logOnlyDirty();
    }
}
