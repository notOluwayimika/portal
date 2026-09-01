<?php

namespace App\Finance\Console;

use App\Finance\Exceptions\PaystackUnavailable;
use App\Finance\Services\PaystackClient;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * CAPTURE A SANDBOX TRANSACTION'S FULL VERIFY PAYLOAD — the instrument that calibrates the fee
 * arithmetic, and the thing that settles the divergence guard's premise.
 *
 * ── WHY A COMMAND AND NOT A PASTE ────────────────────────────────────────────────────────────────
 *
 * A pasted payload is a recollection: it carries the fields somebody thought to copy, once, and
 * cannot be re-run. This captures EVERYTHING, re-runs after any client change, and makes the
 * calibration repeatable rather than a transcription. `observedFee()` is written against its output.
 *
 * **What it settles that `fees` alone cannot.** The divergence guard compares what we charged against
 * what Paystack took, and that comparison is only valid if we know **what their `fees` is measured
 * against** — the gross, the net, or something else. `amount` and `requested_amount` together are
 * what answer it. Assume wrongly and the guard silently compares unlike numbers: it reports drift
 * that is not there, or misses drift that is.
 *
 * ── FOUR GUARDS, EACH BECAUSE THE ALTERNATIVE IS A REAL FAILURE ──────────────────────────────────
 *
 * **1. IT REFUSES ANY KEY THAT IS NOT `sk_test_`.** A hard check, not a comment. A capture tool that
 * CAN be pointed at live keys is one that eventually is — and pointing this at production would
 * initialise a real checkout against real money. Same shape as `bin/verify-finance-state.sh`
 * refusing to run against the production copy.
 *
 * **2. IT CAPTURES; IT DOES NOT RECORD.** No `RecordPayment`, no write to any `finance_` table, no
 * database write at all. A capture tool that can mint a payment is a second payment door outside the
 * maker/checker surface, and that is not what this is for. Read the code below: the only persistence
 * is a JSON file.
 *
 * **3. DUMPS GO TO `storage/app/`, WHICH IS GITIGNORED.** The payload carries payer email, card BIN
 * and last four — the retention ticket's own subject matter. Committing a captured payload as a test
 * fixture would put real PII in git history permanently, where no `redacted_at` can reach it. The
 * payer email is SYNTHETIC for the same reason. If a redacted fixture is ever wanted in the repo,
 * that is a separate deliberate commit, never this command's default.
 *
 * **4. IT LOGS THE RESPONSE, NEVER THE REQUEST.** The request carries `Authorization: Bearer <secret>`,
 * and the secret is also the webhook forgery credential. A debug dump of the full round trip would
 * write the key into a file on disk.
 *
 * ── USAGE ───────────────────────────────────────────────────────────────────────────────────────
 *
 *   php artisan finance:capture-paystack --amount=2000 --amount=50000 --amount=300000
 *
 * One amount per fee regime brackets both boundaries: below the ₦2,500 waiver, between it and the
 * cap, and above the cap where the fee is flat. It prints an authorization URL per amount; pay each
 * with a sandbox card, then re-run with `--verify=<reference>` to capture the settled payload.
 */
class CapturePaystackSandbox extends Command
{
    protected $signature = 'finance:capture-paystack
                            {--amount=* : Naira amounts to initialise, one per fee regime}
                            {--verify=* : References to verify and capture}
                            {--email=sandbox-capture@example.com : Synthetic payer email — never a real one}';

    protected $description = 'SANDBOX ONLY: initialise or verify a Paystack transaction and capture the full payload. Records nothing.';

    public function handle(PaystackClient $client): int
    {
        if (! $this->assertSandbox()) {
            return self::FAILURE;
        }

        $amounts = (array) $this->option('amount');
        $references = (array) $this->option('verify');

        if ($amounts === [] && $references === []) {
            $this->error('Give --amount=<naira> to initialise, or --verify=<reference> to capture a settled payload.');

            return self::INVALID;
        }

        foreach ($amounts as $naira) {
            $this->initialise($client, (int) $naira);
        }

        foreach ($references as $reference) {
            $this->verify($client, (string) $reference);
        }

        return self::SUCCESS;
    }

    /**
     * GUARD 1 — refuse anything that is not a sandbox secret.
     *
     * Checked against the CONFIGURED key rather than an env name or a flag, because the question is
     * "which credential will actually be sent", and only the key itself answers that.
     */
    private function assertSandbox(): bool
    {
        $key = (string) config('services.paystack.secret_key');

        if ($key === '') {
            $this->error('services.paystack.secret_key is not configured.');

            return false;
        }

        if (! str_starts_with($key, 'sk_test_')) {
            $this->error('REFUSING TO RUN. The configured Paystack secret is not a sandbox key '
                .'(expected a "sk_test_" prefix, got "'.substr($key, 0, 8).'"). This command '
                .'initialises real checkouts; pointed at a live key it moves real money.');

            return false;
        }

        return true;
    }

    private function initialise(PaystackClient $client, int $naira): void
    {
        $amount = Money::fromKobo($naira * 100);

        // NOT GatewayReference::mint(), deliberately. That format routes a webhook back to a school,
        // and this command creates NO finance_gateway_transactions row — it calls Paystack directly
        // to measure fee arithmetic and records nothing. A reference that cannot route is correct
        // here precisely because nothing will ever look this up; minting a routable one would
        // advertise a transaction that does not exist. (The model guard would refuse this string on
        // a real row, which is the guard working, not a conflict.)
        $reference = 'CAPTURE-'.$naira.'-'.now()->format('YmdHis');

        $this->line('');
        // Money::format(), not a hand-written ₦ — one formatter per side (money-lint). The gate
        // caught the hand-written one here, which is the lint doing exactly its job on a file whose
        // whole purpose is measuring money.
        $this->info("initialise  {$amount->format()}  ({$amount->toKobo()} kobo)  reference={$reference}");

        try {
            $checkout = $client->initialize($amount, $reference, (string) $this->option('email'));
        } catch (Throwable $e) {
            $this->error('  '.get_class($e).': '.$e->getMessage());

            return;
        }

        $this->line('  pay here: '.$checkout->authorizationUrl);
        $this->line('  then:     php artisan finance:capture-paystack --verify='.$checkout->reference);
    }

    /**
     * GUARD 4 lives here: we serialise the RESPONSE only. The request — which carries the bearer
     * token — is never written, printed or dumped.
     */
    private function verify(PaystackClient $client, string $reference): void
    {
        $this->line('');
        $this->info("verify  {$reference}");

        try {
            $raw = $client->verifyRaw($reference);
        } catch (PaystackUnavailable $e) {
            $this->error('  UNAVAILABLE (not "failed" — we could not find out): '.$e->getMessage());

            return;
        } catch (Throwable $e) {
            $this->error('  '.get_class($e).': '.$e->getMessage());

            return;
        }

        // GUARD 3 — storage/app is gitignored. A captured payload must never reach git history.
        $path = 'paystack-capture/'.$reference.'.json';
        Storage::disk('local')->put($path, json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // The path is derived from the disk, never assembled by hand: the `local` disk's root is
        // `storage/app/private`, NOT `storage/app`, so a hand-written message named a location the
        // file was not at. Small, and the same class as every other description asserting a property
        // its artifact does not have — so it is read back from the disk instead of composed.
        $this->line('  saved: '.Storage::disk('local')->path($path).'  (gitignored — carries payer PII)');

        $data = $raw['data'] ?? [];
        foreach (['status', 'amount', 'requested_amount', 'currency', 'fees', 'channel', 'paid_at', 'id'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = is_scalar($data[$field]) ? var_export($data[$field], true) : json_encode($data[$field]);
                $this->line(sprintf('    %-18s %s', $field, $value));
            }
        }

        // The pair that settles the divergence guard's premise, called out because it is the whole
        // reason the full payload is captured rather than `fees` alone.
        if (isset($data['amount'], $data['fees'])) {
            $this->line('');
            $this->line('  amount − fees = '.((int) $data['amount'] - (int) $data['fees']).' kobo'
                .'   (compare against requested_amount to see what `fees` is measured against)');
        }
    }
}
