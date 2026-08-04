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
 * WHITESPACE IS NOT AN ADDRESS — and until this fix the predicate said it was.
 *
 * `'   ' !== ''` is true and `str_ends_with('   ', $sentinel)` is false, so the
 * untrimmed form returned DELIVERABLE for pure whitespace. Live at all seven sites
 * the lift routed through here: bulk mail, the export's "has login" column, and the
 * password-reset gate.
 */
it('treats a whitespace-only address as undeliverable', function () {
    $user = dep_user('parent@example.test');
    $user->email = '   ';

    expect($user->hasDeliverableEmail())->toBeFalse();
});

/**
 * THE PADDED SENTINEL — the same trim mismatch that #201 fixed in the backfill,
 * living in the predicate too.
 *
 * Fixing one copy CREATED the divergence: the migration excluded a padded sentinel
 * while this predicate called it deliverable. Two views of one value disagreeing
 * about which characters count — the inlined-copy drift the lift removed at five
 * sites, recreated at two by repairing one of them. Both now call
 * User::isSyntheticEmail(), which trims.
 */
it('rejects a synthetic address that carries whitespace', function () {
    $user = dep_user('parent@example.test');
    $user->email = '08031234567'.User::SYNTHETIC_EMAIL_DOMAIN.' ';

    expect($user->hasDeliverableEmail())->toBeFalse()
        ->and(User::isSyntheticEmail($user->email))->toBeTrue();
});

it('accepts a real address that carries whitespace', function () {
    // The trim must not make a genuine address undeliverable — the fix has to be a
    // narrowing of the sentinel test, not of deliverability.
    $user = dep_user('parent@example.test');
    $user->email = '  parent@example.test  ';

    expect($user->hasDeliverableEmail())->toBeTrue();
});

/**
 * ONE DEFINITION OF THE SENTINEL TEST, asserted structurally.
 *
 * The literal is already pinned to one occurrence; this pins the CHECK. Two inlined
 * `str_ends_with(..., SYNTHETIC_EMAIL_DOMAIN)` copies is exactly the state that let
 * a one-place trim fix produce a two-place disagreement.
 */
it('keeps the synthetic check in exactly one place', function () {
    $appFiles = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/app', FilesystemIterator::SKIP_DOTS)
    );

    $checks = 0;

    foreach ($appFiles as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^\s*(\/\/|\*|\/\*)/', $line)) {
                continue;
            }

            if (str_contains($line, 'str_ends_with') && str_contains($line, 'SYNTHETIC_EMAIL_DOMAIN')) {
                $checks++;
            }
        }
    }

    expect($checks)->toBe(1, 'the synthetic check must live only in User::isSyntheticEmail()');
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
