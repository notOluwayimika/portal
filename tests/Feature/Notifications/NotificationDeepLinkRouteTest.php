<?php

use App\Notifications\Enums\NotificationType;
use Illuminate\Support\Facades\Route;

/**
 * Every deep-link the frontend can build must resolve to a route that EXISTS.
 *
 * ⚠️ A CROSS-LANGUAGE PIN, and the only kind that can hold this invariant. The map
 * lives in TypeScript and the routes live in PHP, so nothing in either language sees
 * both — "an entry here is a promise the target page exists" was a convention enforced
 * by whoever remembered it. Two entries is exactly when a convention stops being
 * reliable, which is why this lands now rather than later.
 *
 * Modelled on the sentinel-literal count test: read the other language's source, assert
 * a property of it. Ugly, and the alternative is a dead link discovered in production by
 * a parent tapping it.
 *
 * IT ALSO PINS THE KEYS. A map keyed on a notification type that no longer exists is
 * the same class of dead promise — the entry can never fire, and nothing else would say
 * so.
 */
function ndl_mapSource(): string
{
    return (string) file_get_contents(
        dirname(__DIR__, 3).'/resources/js/hooks/use-notifications.ts'
    );
}

/**
 * The URL templates the map can produce, normalised for comparison.
 *
 * `/students/${row.student_uuid}/results/${row.subject_uuid}` becomes
 * `students/{}/results/{}` — the same shape Laravel's `students/{student:uuid}/…`
 * reduces to. Comparing SHAPES rather than literals is what lets a uuid-parameterised
 * link be checked at all.
 *
 * @return list<string>
 */
function ndl_normalisedTemplates(string $source): array
{
    // Template literals inside the DEEP_LINKS block, plus plain quoted paths.
    $block = substr(
        $source,
        strpos($source, 'const DEEP_LINKS'),
        strpos($source, 'export function notificationDeepLink') - strpos($source, 'const DEEP_LINKS')
    );

    preg_match_all('/`(\/[^`]*)`|\'(\/[a-z0-9\-\/]+)\'/i', $block, $matches, PREG_SET_ORDER);

    $templates = [];

    foreach ($matches as $match) {
        $raw = $match[1] !== '' ? $match[1] : ($match[2] ?? '');

        if ($raw === '') {
            continue;
        }

        // `${anything}` → a single parameter slot.
        $normalised = preg_replace('/\$\{[^}]+\}/', '{}', $raw);
        $templates[] = trim((string) $normalised, '/');
    }

    return array_values(array_unique(array_filter($templates)));
}

/** Laravel's registered URIs, reduced to the same shape. */
function ndl_registeredShapes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => preg_replace('/\{[^}]+\}/', '{}', $route->uri()))
        ->unique()
        ->values()
        ->all();
}

it('resolves every deep-link template to a registered route', function () {
    $templates = ndl_normalisedTemplates(ndl_mapSource());

    // NOT VACUOUS: an extraction that found nothing would satisfy the misses assertion
    // below while checking no promise at all.
    //
    // NON-EMPTY, NOT AN EXACT COUNT — found by bite-proving. `toHaveCount(2)` fired
    // FIRST when a bad entry was added, so the test failed on "3 is not 2" and the
    // route check never ran. It would have gone green for a third entry pointing at a
    // real route and red for one pointing nowhere, with the same message either way:
    // the guard was masking the property.
    expect($templates)->not->toBeEmpty();

    $registered = ndl_registeredShapes();

    // Collected rather than asserted one-by-one: `toContain` takes further NEEDLES, not
    // a failure message, so passing an explanation there silently searches for it. An
    // empty-misses assertion also names every offender at once instead of the first.
    $misses = array_values(array_diff($templates, $registered));

    expect($misses)->toBe([]);
});

/**
 * A key that names no live notification type is a dead entry — it can never fire, and
 * nothing else in either language would say so.
 */
it('keys the map only on notification types that exist', function () {
    $source = ndl_mapSource();

    $block = substr(
        $source,
        strpos($source, 'const DEEP_LINKS'),
        strpos($source, 'export function notificationDeepLink') - strpos($source, 'const DEEP_LINKS')
    );

    preg_match_all("/^\s{4}'([a-z0-9_.]+)':/mi", $block, $matches);

    $keys = $matches[1];
    $known = array_map(fn (NotificationType $t) => $t->value, NotificationType::cases());

    expect($keys)->not->toBeEmpty();

    $unknown = array_values(array_diff($keys, $known));

    expect($unknown)->toBe([]);
});

/**
 * The approvals queue specifically — the entry this slice adds.
 *
 * Named rather than left to the generic sweep, because it is the one whose target is a
 * QUEUE: all four approval families land here, and no subject uuid appears in the URL.
 */
it('points approval notifications at a real approvals queue route', function () {
    expect(Route::has('admin.finance.approvals'))->toBeTrue();

    expect(ndl_normalisedTemplates(ndl_mapSource()))->toContain('finance/approvals');
});
