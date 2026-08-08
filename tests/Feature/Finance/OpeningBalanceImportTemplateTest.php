<?php

/*
 * §9 step 5b-i (R13) — THE TEMPLATE THE PLATFORM ISSUES.
 *
 * Every assertion here reads the GENERATED WORKBOOK, not the export's arrays. A template is a document
 * handed to another organisation: what matters is what a reader opens, and the arrays are only how it
 * is built. So the export is rendered to real xlsx bytes, loaded back through PhpSpreadsheet, and the
 * sheets are read as cells.
 *
 * The four things these arms exist to hold, each bite-proved in the implementation report:
 *   1. the sample rows obey §1's L1 — one student's rows repeat ONE stated total and it equals the sum
 *      of their balances — so the template cannot ship a sample its own importer would reject;
 *   2. the Columns sheet is DERIVED from ImportOpeningBalances::COLUMNS, so a column added to the map
 *      reaches the workbook with no edit to the export (R13's second-source-of-truth refusal);
 *   3. the derived MAX LENGTHS reach the reader — fee_type_label's Format says 229, not the storage
 *      column's 255, because a template advertising 255 produces a file that stages green and then
 *      aborts the post at 1406 on cutover day;
 *   4. the route is behind the MAKER ability, `finance.opening-balance.submit`.
 */

use App\Finance\Console\ImportOpeningBalances;
use App\Finance\Exports\OpeningBalanceImportTemplateExport;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * The workbook as a reader receives it: rendered to xlsx bytes and loaded back. Nothing in this file
 * asserts against the export's own arrays — that would prove the arrays agree with themselves.
 */
function obtWorkbook(): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'obtpl').'.xlsx';
    file_put_contents($path, Excel::raw(new OpeningBalanceImportTemplateExport, ExcelFormat::XLSX));

    try {
        return IOFactory::load($path);
    } finally {
        @unlink($path);
    }
}

/** One sheet's cells, header row included, as read off the generated file. */
function obtSheet(string $title): array
{
    return array_map(
        fn (array $row) => array_map(fn ($cell) => $cell === null ? '' : (string) $cell, $row),
        obtWorkbook()->getSheetByName($title)->toArray(),
    );
}

/** A web-session user in $school holding EXACTLY $permissions (mirrors PaymentRecordGateTest). */
function obtGateUser(School $school, array $permissions): User
{
    $roleName = 'obtpl_'.substr(md5(implode(',', $permissions)), 0, 10);
    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $role->syncPermissions($permissions);

    $user = User::factory()->create(['school_id' => $school->id]);
    $user->grantSchoolAccess($school, $roleName);
    $user->flushSchoolAccessCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

// ── The workbook a reader opens ──────────────────────────────────────────────────────────────────

it('issues THREE sheets, in order — the third is the departure from the guardian template', function () {
    expect(obtWorkbook()->getSheetNames())->toBe(['Import', 'Columns', 'Notes']);
});

it('heads the Import sheet with the COLUMNS map keys, in the map order', function () {
    // Not "the expected six names" — the MAP's keys. A hardcoded list here would be the same second
    // source of truth the export refuses, one layer up.
    expect(obtSheet('Import')[0])->toBe(array_keys(ImportOpeningBalances::COLUMNS));
});

it('samples MORE THAN ONE ROW FOR ONE STUDENT, which is the only way to show the repeated total', function () {
    $sheet = obtSheet('Import');
    $columns = array_flip($sheet[0]);
    $rows = array_slice($sheet, 1);

    $byStudent = [];
    foreach ($rows as $row) {
        $byStudent[$row[$columns['admission_number']]][] = $row;
    }

    // The guardian template's one sample row teaches that one row per student is the shape. This is
    // the assertion that refuses that reading of THIS format.
    expect(count($byStudent))->toBeGreaterThanOrEqual(2, 'the sample must show more than one student');
    expect(max(array_map('count', $byStudent)))
        ->toBeGreaterThanOrEqual(2, 'at least one student must carry SEVERAL rows — one row per fee type is the format');
});

it('samples rows §1 L1 WOULD ACCEPT — one stated total per student, equal to the sum of that student\'s balances', function () {
    // The check is re-derived with the same Money parsing the validator uses, so a sample edited to a
    // figure the importer would REJECT fails here rather than in a data team's first upload.
    $sheet = obtSheet('Import');
    $columns = array_flip($sheet[0]);
    $rows = array_slice($sheet, 1);

    $byStudent = [];
    foreach ($rows as $row) {
        $byStudent[$row[$columns['admission_number']]][] = $row;
    }

    foreach ($byStudent as $admission => $studentRows) {
        $stated = array_unique(array_map(fn (array $r) => $r[$columns['student_total_balance']], $studentRows));

        expect($stated)->toHaveCount(1,
            "student [{$admission}] states more than one total across their rows — §2 requires the SAME figure on every row");

        $sum = Money::fromKobo(0);
        foreach ($studentRows as $row) {
            $sum = $sum->plus(Money::fromNaira($row[$columns['balance']]));
        }

        expect($sum->toKobo())->toBe(
            Money::fromNaira(reset($stated))->toKobo(),
            "student [{$admission}]: Σ of the sampled balances does not equal the sampled student_total_balance — the template ships a sample its own importer rejects",
        );
    }
});

it('samples a student in CREDIT, because balance is signed and a positives-only sample invites dropped minus signs', function () {
    $sheet = obtSheet('Import');
    $columns = array_flip($sheet[0]);

    $balances = array_map(
        fn (array $row) => Money::fromNaira($row[$columns['balance']])->toKobo(),
        array_slice($sheet, 1),
    );

    expect(min($balances))->toBeLessThan(0, 'no sampled balance is negative — the sample never shows a credit');
});

it('writes the money samples AS TEXT, so the two decimals the format requires are what a reader sees', function () {
    // The default value binder casts '120000.00' to the number 120000 and '-5000.00' to -5000, which
    // would delete the decimals from the one place the format is demonstrated.
    $sheet = obtSheet('Import');
    $columns = array_flip($sheet[0]);

    foreach (array_slice($sheet, 1) as $row) {
        expect($row[$columns['balance']])->toMatch('/^-?\d+\.\d{2}$/')
            ->and($row[$columns['student_total_balance']])->toMatch('/^-?\d+\.\d{2}$/');
    }
});

// ── The Columns sheet is RENDERED from the map, never restated ───────────────────────────────────

it('renders the Columns sheet FROM the COLUMNS map — one row per column, every cell the map\'s own', function () {
    $sheet = obtSheet('Columns');

    expect($sheet[0])->toBe(['Column', 'Group', 'Required', 'Format', 'Example', 'Notes']);

    // Built from the map, so a column ADDED to the map gains a row here with no edit to the export or
    // to this test — and an export that listed the columns itself goes red on the very next map edit.
    $expected = [];
    foreach (ImportOpeningBalances::COLUMNS as $column => $meta) {
        $expected[] = [
            $column,
            $meta['group'],
            $meta['required'] ? 'Yes' : 'No',
            $meta['format'],
            $meta['example'],
            $meta['notes'],
        ];
    }

    expect(array_slice($sheet, 1))->toBe($expected)
        ->and(count($sheet) - 1)->toBe(count(ImportOpeningBalances::COLUMNS));
});

it('carries the DERIVED max length to the reader — fee_type_label says 229, never the column\'s 255', function () {
    $sheet = obtSheet('Columns');
    $formats = [];
    foreach (array_slice($sheet, 1) as $row) {
        $formats[$row[0]] = $row[3];
    }

    // 229 is not this column's own width (that is 255): posting appends a 26-character suffix to the
    // label verbatim into a varchar(255) narration. A template advertising 255 hands over a file that
    // stages perfectly and aborts the post at 1406.
    expect($formats['fee_type_label'])->toContain('229')
        ->and($formats['fee_type_label'])->not->toContain('255')
        ->and(ImportOpeningBalances::COLUMNS['fee_type_label']['max'])->toBe(229);

    // And the general rule the specific case is an instance of: every column's stated Format carries
    // the number its `max` really holds, so the sheet cannot drift from the limit that is enforced.
    foreach (ImportOpeningBalances::COLUMNS as $column => $meta) {
        expect(str_contains($formats[$column], (string) $meta['max']))->toBeTrue(
            "the Format cell for [{$column}] does not state its max of {$meta['max']}");
    }
});

it('shows the OPTIONAL column as optional, and leaves it blank in a sample row', function () {
    $columnsSheet = obtSheet('Columns');
    $required = [];
    foreach (array_slice($columnsSheet, 1) as $row) {
        $required[$row[0]] = $row[2];
    }

    expect($required['wcbs_bill_reference'])->toBe('No');

    $importSheet = obtSheet('Import');
    $index = array_flip($importSheet[0])['wcbs_bill_reference'];
    $values = array_map(fn (array $row) => $row[$index], array_slice($importSheet, 1));

    expect(in_array('', $values, true))->toBeTrue(
        'no sample row leaves the optional reference blank, so the template never shows that a blank is accepted');
});

// ── The third sheet: the rules with nowhere else to live ─────────────────────────────────────────

it('gives the NON-per-column rules a home — the ones behind the expensive failures', function () {
    $sheet = obtSheet('Notes');

    expect($sheet[0])->toBe(['Rule', 'What it means']);

    $body = strtolower(implode(' ', array_map(fn (array $row) => implode(' ', $row), array_slice($sheet, 1))));

    // §11's pure-arrears assumption, §2's blank-is-not-zero, §1 L2's control total, G1's one batch.
    expect($body)->toContain('arrears')
        ->and($body)->toContain('0.00')
        ->and($body)->toContain('control total')
        ->and($body)->toContain('one file per school');

    // The control-total rule is the one an operator gets wrong by adding a column, so the sheet must
    // say WHERE it is entered, not merely that it exists.
    expect($body)->toContain('typed in at upload');
});

// ── The route ────────────────────────────────────────────────────────────────────────────────────

it('serves the template to a holder of the MAKER ability', function () {
    $this->seed(DatabaseSeeder::class);
    $school = School::factory()->create();
    $user = obtGateUser($school, ['finance.access', 'finance.opening-balance.submit']);

    $response = $this->actingAs($user)->withSession(['school_id' => $school->id])
        ->get('/api/v1/finance/opening-balance-batches/import/template');

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('opening-balance-import-template.xlsx');
});

it('refuses a user without finance.opening-balance.submit', function () {
    $this->seed(DatabaseSeeder::class);
    $school = School::factory()->create();

    // finance.access alone reaches the module — this arm is what makes the template's own ability
    // load-bearing rather than decorative. The checker's ability is included deliberately: the person
    // who APPROVES a batch is not thereby the person who uploads one, and neither of them is admitted
    // by the group gate alone.
    $user = obtGateUser($school, ['finance.access', 'finance.opening-balance.approve']);

    $this->actingAs($user)->withSession(['school_id' => $school->id])
        ->get('/api/v1/finance/opening-balance-batches/import/template')
        ->assertForbidden();
});
