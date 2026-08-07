#!/usr/bin/env php
<?php

/**
 * Dependency-integrity lint — the tree being analysed is the tree that was declared.
 *
 * WHY THIS EXISTS. bin/quality's precondition was `[ -d vendor ]`, and :22's comment
 * said the script "verifies they exist instead" of installing them. Existence is not
 * integrity: a vendor/ that exists but is behind composer.lock passes that test, and
 * every step after it then measures a tree nobody declared. That is not theoretical —
 * aws/aws-php-sns-message-validator entered composer.json AND composer.lock in the same
 * commit (65b8ccd), reached staging through the SES merge, and `composer install` was
 * never run afterwards. The class was therefore CASE (b) below. Larastan reported 8 hard
 * failures over a vendor/ that was simply incomplete. That was a false RED, which is the
 * safe direction; the identical hole produces a false GREEN whenever the missing code is
 * what a finding depends on. Under ADR 0053 this gate is the only gate, so it may not
 * report on a tree it has not verified.
 *
 * TWO DISTINCT FAILURES. Each is invisible to the other's check, so both are implemented:
 *
 *   (a) composer.json changed, composer.lock never regenerated.
 *       The package is in composer.json and in NEITHER the lock nor vendor/.
 *       Caught by the lock's content-hash, via `composer validate --strict` (network-free).
 *       vendor/ is entirely consistent with the lock here, so (b) sees nothing wrong.
 *
 *   (b) composer.lock is correct, vendor/ was never brought up to it.
 *       The package is in the lock and absent from vendor/. The content-hash is over
 *       composer.json only, so (a) is green. This is the one that bit us, and the one
 *       that produces a silent wrong-tree run.
 *
 * (b) is checked three ways, because vendor/ can be behind the lock in three shapes:
 * absent from vendor/composer/installed.json (never installed), present in installed.json
 * but its directory gone from disk (deleted or a botched install), or installed at a
 * version the lock no longer names (installed from an older lock).
 *
 * PHPSTAN RESULT CACHE. The same wrong-tree run also poisons step 13's result cache,
 * which is keyed on analysed sources and knows nothing about vendor/ moving underneath
 * it — that is why the 8 failures survived a `composer install`. The cache is not cleared
 * every run (it is the reason that step is bearable); it is cleared exactly when the input
 * it cannot see has moved, i.e. when composer.lock's sha256 differs from the fingerprint
 * recorded beside the cache. This lint already reads composer.lock, so it computes that
 * hash once and uses it twice.
 *
 * The invariant the fingerprint file carries: IT EXISTS ONLY IF THE CACHE BESIDE IT WAS
 * BUILT OVER A VENDOR TREE THAT MATCHED THAT LOCK. So a failing run drops the cache and
 * does NOT record the fingerprint. Without that, phpstan could run over the known-bad
 * tree, cache the result, and the next run would find a matching fingerprint and keep the
 * poison — which is the original bug.
 *
 * The invariant does not depend on bin/quality's control flow, and that is deliberate.
 * Step 1 now aborts the run (bin/quality's abort_check), so the poisoned cache would not
 * be reached anyway — but this lint is also runnable on its own, and a guard that only
 * holds because of its caller is not a guard. Recording the fingerprint ONLY on a passing
 * run is what makes the cache trustworthy no matter who invoked it.
 *
 * NOT IN SCOPE, deliberately: the pnpm/node_modules equivalent. pnpm-lock.yaml is YAML
 * with no parser in this repo's PHP toolchain, so it shares nothing with the JSON path
 * below and is a separate change, not a nearly-free rider. bin/quality still keeps its
 * bare `[ -d node_modules ]` existence check, with the same known limits as the old
 * vendor one.
 *
 * Usage:
 *   php bin/ci-dependency-integrity-lint.php     # check + reconcile the phpstan cache
 *
 * Exit: 0 = the installed tree matches the lock and the lock matches the manifest.
 */
$root = dirname(__DIR__);
$lockPath = $root.'/composer.lock';
$installedPath = $root.'/vendor/composer/installed.json';
$cacheDir = $root.'/build/phpstan';                       // phpstan.neon: parameters.tmpDir
$fingerprintPath = $root.'/build/phpstan.lock-fingerprint';

$problems = [];

$fail = static function (string $headline, array $lines, string $fix) use (&$problems): void {
    $problems[] = ['headline' => $headline, 'lines' => $lines, 'fix' => $fix];
};

// ---------------------------------------------------------------------------
// (a) composer.json vs composer.lock — the lock's content-hash.
//
// Shelled out to composer rather than recomputed here: the hash is over a fixed
// subset of composer.json's keys that composer itself owns and has changed between
// major versions. Re-implementing it would produce a check that drifts silently into
// always-green, which is the failure mode this whole lint exists to remove. If
// composer is not on PATH we cannot verify (a) at all, and saying so is the only
// honest answer — a skipped check that prints OK is the false green.
// ---------------------------------------------------------------------------
$composerBin = getenv('COMPOSER_BIN') ?: 'composer';
exec(escapeshellarg($composerBin).' --version 2>/dev/null', $probe, $probeStatus);

if ($probeStatus !== 0) {
    $fail(
        'composer is not executable, so the lock/manifest content-hash cannot be verified.',
        ['tried: '.$composerBin],
        'install composer, or set COMPOSER_BIN=/path/to/composer'
    );
} else {
    $cmd = 'cd '.escapeshellarg($root).' && '
        .escapeshellarg($composerBin).' validate --no-check-all --strict --no-interaction --no-ansi 2>&1';
    exec($cmd, $validateOut, $validateStatus);

    if ($validateStatus !== 0) {
        $fail(
            'composer.json and composer.lock disagree — the lock was never regenerated (case (a)).',
            array_values(array_filter(array_map('rtrim', $validateOut), static fn ($l) => $l !== '' && ! str_starts_with($l, 'Deprecation Notice:'))),
            'composer update --lock   (then commit composer.lock)'
        );
    }
}

// ---------------------------------------------------------------------------
// (b) composer.lock vs vendor/ — what is actually on disk.
// ---------------------------------------------------------------------------
if (! is_file($lockPath)) {
    $fail('composer.lock is missing.', [], 'restore it from git');
} elseif (! is_file($installedPath)) {
    $fail(
        'vendor/composer/installed.json is missing — nothing has been installed (case (b)).',
        [],
        'composer install'
    );
} else {
    $lock = json_decode((string) file_get_contents($lockPath), true);
    $installed = json_decode((string) file_get_contents($installedPath), true);

    if (! is_array($lock) || ! is_array($installed)) {
        $fail('composer.lock or vendor/composer/installed.json is not valid JSON.', [], 'composer install');
    } else {
        /** @var array<string,string> $wanted name => version, from the lock (runtime + dev) */
        $wanted = [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $pkg) {
            if (isset($pkg['name'])) {
                $wanted[$pkg['name']] = (string) ($pkg['version'] ?? '?');
            }
        }

        /** @var array<string,array{version:string,path:?string}> $have */
        $have = [];
        foreach ($installed['packages'] ?? [] as $pkg) {
            if (isset($pkg['name'])) {
                $have[$pkg['name']] = [
                    'version' => (string) ($pkg['version'] ?? '?'),
                    'path' => isset($pkg['install-path']) ? (string) $pkg['install-path'] : null,
                    'type' => (string) ($pkg['type'] ?? 'library'),
                ];
            }
        }

        $never = [];      // in the lock, never installed
        $gone = [];       // recorded as installed, directory not on disk
        $drifted = [];    // installed at a version the lock does not name

        foreach ($wanted as $name => $version) {
            if (! isset($have[$name])) {
                $never[] = $name.' ('.$version.')';

                continue;
            }
            if ($have[$name]['version'] !== $version) {
                $drifted[] = $name.': lock wants '.$version.', vendor/ has '.$have[$name]['version'];
            }
            // install-path is relative to vendor/composer. Metapackages have no directory.
            if ($have[$name]['type'] !== 'metapackage' && $have[$name]['path'] !== null) {
                // install-path is relative to vendor/composer, so it is normally "../<vendor>/<pkg>".
                // Collapse the ".." for a path a human can paste.
                $rel = 'vendor/composer/'.$have[$name]['path'];
                while (preg_match('#(^|/)[^/]+/\.\./#', $rel)) {
                    $rel = preg_replace('#(^|/)[^/]+/\.\./#', '$1', $rel, 1);
                }
                if (! is_dir($root.'/'.$rel)) {
                    $gone[] = $name.' — no directory at '.$rel;
                }
            }
        }

        if ($never) {
            $fail(
                count($never).' package(s) in composer.lock were never installed (case (b) — the aws/aws-php-sns-message-validator shape):',
                $never,
                'composer install'
            );
        }
        if ($gone) {
            $fail(
                count($gone).' installed package(s) have no directory in vendor/ (case (b)):',
                $gone,
                'composer install'
            );
        }
        if ($drifted) {
            $fail(
                count($drifted).' package(s) are installed at a version composer.lock does not name (case (b)):',
                $drifted,
                'composer install'
            );
        }
    }
}

// ---------------------------------------------------------------------------
// PHPStan result cache reconciliation. See the invariant in the header.
// ---------------------------------------------------------------------------
$dropCache = static function () use ($cacheDir): bool {
    if (! is_dir($cacheDir)) {
        return false;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($cacheDir);

    return true;
};

$cacheNote = '';
$lockHash = is_file($lockPath) ? (string) hash_file('sha256', $lockPath) : null;
$recorded = is_file($fingerprintPath) ? trim((string) file_get_contents($fingerprintPath)) : null;

if ($problems) {
    // Known-bad tree: drop whatever is cached and REFUSE to record the fingerprint, so the
    // next run cannot mistake a cache built over this tree for a valid one.
    $dropped = $dropCache();
    @unlink($fingerprintPath);
    $cacheNote = 'phpstan cache '.($dropped ? 'dropped' : 'absent').'; fingerprint cleared (tree is not trusted).';
} elseif ($lockHash !== null && $lockHash !== $recorded) {
    $dropped = $dropCache();
    @mkdir(dirname($fingerprintPath), 0o775, true);
    file_put_contents($fingerprintPath, $lockHash."\n");
    $cacheNote = 'composer.lock moved ('.($recorded === null ? 'no fingerprint' : substr($recorded, 0, 12)).' → '
        .substr($lockHash, 0, 12).') — phpstan cache '.($dropped ? 'dropped' : 'was absent').', fingerprint recorded.';
} else {
    $cacheNote = 'composer.lock unchanged ('.substr((string) $lockHash, 0, 12).') — phpstan cache kept.';
}

// ---------------------------------------------------------------------------
if ($problems) {
    fwrite(STDERR, "\ndependency-integrity-lint: the tree does not match what was declared.\n");
    foreach ($problems as $p) {
        fwrite(STDERR, "\n  \u{2717} ".$p['headline']."\n");
        foreach ($p['lines'] as $line) {
            fwrite(STDERR, '      '.$line."\n");
        }
        fwrite(STDERR, '      fix: '.$p['fix']."\n");
    }
    fwrite(STDERR, "\n  ".$cacheNote."\n");
    exit(1);
}

fwrite(STDERR, 'dependency-integrity-lint: OK — composer.lock matches composer.json, and all '
    .count($wanted ?? [])." locked packages are installed at the locked version.\n");
fwrite(STDERR, '  '.$cacheNote."\n");
exit(0);
