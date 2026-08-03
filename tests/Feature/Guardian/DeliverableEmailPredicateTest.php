<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * `User::hasDeliverableEmail()` — the predicate seven call sites used to inline.
 *
 * The review question this file exists to answer is narrow and specific: does
 * routing all seven through one function PRESERVE their behaviour? Each site paired
 * a null-guard with a synthetic-check, and one of them (GuardiansExport) also ANDed
 * `disabled_at` — so "consolidate" had seven boundaries to keep, not one.
 */
function dep_user(string $email, ?string $disabledAt = null): User
{
    $user = User::forceCreate([
        'uuid' => (string) Str::uuid(),
        'first_name' => 'Test',
        'last_name' => 'User '.Str::random(5),
        'email' => $email,
        'password' => bcrypt('password'),
        'school_id' => al_makeSchool()->id,
        'email_verified_at' => now(),
    ]);

    if ($disabledAt !== null) {
        $user->forceFill(['disabled_at' => now()])->save();
    }

    return $user;
}

it('accepts a real address', function () {
    expect(dep_user('parent@example.test')->hasDeliverableEmail())->toBeTrue();
});

it('rejects both shapes of synthetic address', function () {
    // `{phone}@no-email.local` — minted when the guardian HAS a phone, which is why
    // the backfill can reroute the embedded number to a phone contact point.
    expect(dep_user('08031234567'.User::SYNTHETIC_EMAIL_DOMAIN)->hasDeliverableEmail())->toBeFalse()
        // `guardian+{random}@…` — minted when there is no phone, randomised purely to
        // clear the UNIQUE index on a NOT NULL column.
        ->and(dep_user('guardian+'.Str::random(12).User::SYNTHETIC_EMAIL_DOMAIN)->hasDeliverableEmail())->toBeFalse()
        // The school-scoped third variant.
        ->and(dep_user('guardian+'.Str::random(12).'+1'.User::SYNTHETIC_EMAIL_DOMAIN)->hasDeliverableEmail())->toBeFalse();
});

/**
 * DELIBERATELY NARROW. A disabled account with a real address IS deliverable — the
 * address works. Whether we should write to it is a different question, owned by the
 * caller.
 *
 * If `disabled_at` were ever folded in here, this test is what fails. Without it the
 * change would be invisible until a disabled guardian received a bulk message or a
 * password-reset mail they should not have — a silent behaviour change in exactly
 * the direction that matters.
 */
it('ignores disabled_at, which is a different question', function () {
    expect(dep_user('parent@example.test', disabledAt: 'now')->hasDeliverableEmail())->toBeTrue();
});

/**
 * THE ONE INTENTIONAL BEHAVIOUR CHANGE, and it is unreachable today.
 *
 * `users.email` is NOT NULL, so no user can currently have a null address. But
 * GuardiansExport cast through `(string) $user->email`, and `str_ends_with('', …)`
 * is false — so a null address would have read as DELIVERABLE, and the export would
 * have reported "has login" for an account that cannot receive anything.
 *
 * That becomes reachable the moment the synthetic mint is retired and the column
 * goes nullable, which is the next PR. Folding the null guard into the predicate
 * fixes it BEFORE the change that would expose it, rather than after.
 */
it('treats an absent address as undeliverable, closing the export cast', function () {
    $user = dep_user('parent@example.test');

    // Bypasses the NOT NULL column: the predicate must be correct on the object,
    // because that is the state the nullable-column change will make persistable.
    $user->email = null;

    expect($user->hasDeliverableEmail())->toBeFalse();

    $user->email = '';

    expect($user->hasDeliverableEmail())->toBeFalse();
});

it('leaves exactly one definition of the sentinel domain in the application', function () {
    $appFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/app', FilesystemIterator::SKIP_DOTS)
    );

    $literals = 0;

    foreach ($appFiles as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) as $line) {
            // Comments explain the sentinel in several places; only CODE counts.
            if (preg_match('/^\s*(\/\/|\*|\/\*)/', $line)) {
                continue;
            }

            if (str_contains($line, "'@no-email.local'") || str_contains($line, '"@no-email.local"')) {
                $literals++;
            }
        }
    }

    // The whole point of the lift: the string had EIGHT occurrences across five
    // files. Recognising it is now one function; creating it names one constant.
    expect($literals)->toBe(1);
});
