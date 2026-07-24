<?php

use App\Finance\Enums\InvoiceStatus;
use App\Finance\Http\Resources\InvoiceResource;
use App\Finance\Models\Invoice;
use App\Finance\Models\SchoolFinanceSettings;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Support\ActiveSchool;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-School invoice-number prefix + the 6-digit minimum pad — PRESENTATION-DERIVED,
 * never stored.
 *
 * Rendered form is `<prefix>-<number padded to a minimum of 6>` → `BSS-000042`.
 * `finance_invoices.number` stays the integer that `UNIQUE(school_id, number)` and the
 * Sequences kernel depend on. These tests pin both halves: that the rendered form is
 * right, AND that nothing was stored.
 *
 * Prefixes are the real ones and are stored SEPARATOR-LESS: BSS, BSP, BSPH, BSA.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => SchoolFinanceSettings::flushPrefixMemo());

function prefixInvoice(School $school, int $number): Invoice
{
    $student = Student::factory()->create(['school_id' => $school->id]);
    $curriculum = Curriculum::factory()->create(['school_id' => $school->id]);
    $enrollment = ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => $curriculum->id,
        'status' => 'active',
    ]));

    return Invoice::create([
        'school_id' => $school->id,
        'student_id' => $student->id,
        'student_curriculum_id' => $enrollment->id,
        'number' => $number,
        'status' => InvoiceStatus::Issued,
        'billed_to_name' => 'Ada Obi',
        'academic_context' => '2026/2027 First Term',
        'total' => Money::fromKobo(330239),
    ]);
}

it('renders the configured prefix, and the bare number when none is configured', function () {
    $configured = School::factory()->create();
    $unconfigured = School::factory()->create();

    // Stored separator-less; the `-` is added at render.
    SchoolFinanceSettings::create([
        'school_id' => $configured->id,
        'invoice_number_prefix' => 'BSS',
    ]);

    expect(prefixInvoice($configured, 42)->displayNumber())->toBe('BSS-000042')
        // No settings row at all — absence of configuration is a valid state, so the
        // invoice renders the bare PADDED number, with no leading separator.
        ->and(prefixInvoice($unconfigured, 42)->displayNumber())->toBe('000042');
});

it('treats a blank prefix as unset — no invisible difference from the bare number', function () {
    $school = School::factory()->create();
    SchoolFinanceSettings::create(['school_id' => $school->id, 'invoice_number_prefix' => '']);

    expect(prefixInvoice($school, 7)->displayNumber())->toBe('000007');
});

it('OVERFLOW — past 6 digits the number renders IN FULL, never truncated or wrapped', function () {
    // THE decided-rule proof. 6 is a MINIMUM width, not a maximum: a fixed-width
    // formatter would silently change format the day a School's numbering outgrew six
    // digits, which is exactly the trap the policy clause exists to close. Without this
    // case, "padding works" is only proven in the happy path.
    $school = School::factory()->create();
    SchoolFinanceSettings::create(['school_id' => $school->id, 'invoice_number_prefix' => 'BSA']);

    expect(prefixInvoice($school, 999999)->displayNumber())->toBe('BSA-999999')   // exactly at the floor
        ->and(prefixInvoice($school, 1000000)->displayNumber())->toBe('BSA-1000000')  // one past it
        ->and(prefixInvoice($school, 123456789)->displayNumber())->toBe('BSA-123456789');
});

it('DEFENSIVE — a stored prefix still carrying a trailing dash does not double it', function () {
    // The earlier mixed model stored `BSS-`. Rendering must normalise to one separator.
    $school = School::factory()->create();
    SchoolFinanceSettings::create(['school_id' => $school->id, 'invoice_number_prefix' => 'BSS-']);

    expect(prefixInvoice($school, 42)->displayNumber())->toBe('BSS-000042');
});

it('the PREFIX is never padded — only the numeric portion is', function () {
    // BSPH is 4 characters and stays 4; the pad applies to 42 -> 000042 alone.
    $school = School::factory()->create();
    SchoolFinanceSettings::create(['school_id' => $school->id, 'invoice_number_prefix' => 'BSPH']);

    expect(prefixInvoice($school, 42)->displayNumber())->toBe('BSPH-000042');
});

it('PER-SCHOOL ISOLATION — each School renders its own prefix, never another\'s', function () {
    $a = School::factory()->create();
    $b = School::factory()->create();
    SchoolFinanceSettings::create(['school_id' => $a->id, 'invoice_number_prefix' => 'BSS']);
    SchoolFinanceSettings::create(['school_id' => $b->id, 'invoice_number_prefix' => 'BSP']);

    // Same stored integer in both Schools — legal, since the unique index is
    // (school_id, number). The rendered forms must still differ.
    $invoiceA = prefixInvoice($a, 100);
    $invoiceB = prefixInvoice($b, 100);

    expect($invoiceA->displayNumber())->toBe('BSS-000100')
        ->and($invoiceB->displayNumber())->toBe('BSP-000100')
        // …and the memo did not leak one School's prefix into the other, which is the
        // failure mode a naive single-value cache would introduce.
        ->and($invoiceA->displayNumber())->toBe('BSS-000100');
});

it('STORED SHAPE UNCHANGED — number is still the integer under its unique index', function () {
    // The whole point of presentation-derivation: no migration touched
    // finance_invoices, so this slice cannot have altered live invoice data.
    $school = School::factory()->create();
    SchoolFinanceSettings::create(['school_id' => $school->id, 'invoice_number_prefix' => 'BSS']);
    $invoice = prefixInvoice($school, 42);

    $stored = DB::table('finance_invoices')->where('id', $invoice->id)->value('number');
    expect($stored)->toEqual(42)
        ->and(is_numeric($stored))->toBeTrue();

    // The column type and the unique index are both still what the kernel relies on.
    expect(Schema::getColumnType('finance_invoices', 'number'))->toContain('int');

    $indexes = collect(DB::select('SHOW INDEX FROM finance_invoices'))
        ->groupBy('Key_name')
        ->map(fn ($rows) => $rows->pluck('Column_name')->all());

    expect($indexes->contains(fn ($cols) => $cols === ['school_id', 'number']))->toBeTrue();
});

it('the API exposes both forms — `number` unchanged, `display_number` added', function () {
    // Additive on the wire: an existing consumer reading `number` keeps working.
    $school = School::factory()->create();
    SchoolFinanceSettings::create(['school_id' => $school->id, 'invoice_number_prefix' => 'BSS']);
    $invoice = prefixInvoice($school, 42);

    $payload = (new InvoiceResource($invoice))->toArray(request());

    expect($payload['number'])->toEqual(42)
        ->and($payload['display_number'])->toBe('BSS-000042');
});
