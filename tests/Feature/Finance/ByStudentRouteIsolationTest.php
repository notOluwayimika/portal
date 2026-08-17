<?php

/*
 * The two by-student billing routes refuse a FOREIGN student.
 *
 *   GET  /api/v1/finance/students/{student:uuid}/billable-enrollment   InvoiceController::billableEnrollment
 *   POST /api/v1/finance/students/{student:uuid}/invoices             InvoiceController::generateForStudent
 *   (routes/endpoints/finance.php:228-229)
 *
 * Both resolve their Student through route-model binding on `uuid`, and both then ask the ACL port
 * for that student's current billable episode. U6 commit 1 gave the port two School-argument reads
 * that deliberately strip the ambient SchoolScope; currentForStudent() deliberately KEEPS it. That
 * asymmetry is documented on the port — and until this file, the isolation of the two routes that
 * actually reach currentForStudent() was tested nowhere in the tree.
 *
 * WHAT ACTUALLY REFUSES, measured rather than assumed: route-model binding, before the controller
 * body runs at all. Student is School-scoped (BelongsToSchool), so School B's uuid does not resolve
 * under School A's context and Laravel answers 404. currentForStudent() is never reached. The plant
 * below confirms that attribution rather than leaving it as a story — with the binding made
 * permissive, the status moves off 404, and what catches the request instead is the SECOND layer:
 * currentForStudent()'s own ambient scope, which finds no episode and answers 422. Two independent
 * refusals, and this file pins the outer one.
 *
 * THE STATUS IS ASSERTED, THE MESSAGE IS NOT. The refusal is Laravel's route-model binding, so its
 * wording is framework copy — pinning it would tie this test to a string the project does not own.
 * What the project DOES own is that nothing was written, so the invoice tables are counted too: a
 * refusal that returns the right status and still leaves a row behind is the failure worth catching,
 * and a status assertion alone cannot see it.
 *
 * EACH REFUSAL IS PAIRED WITH THE SAME REQUEST SUCCEEDING for the owning School. Without that
 * control a 404 proves nothing — a misspelled path, a missing permission or an unregistered route
 * all produce one, and the test would pass while testing the URL rather than the isolation.
 */

use App\Models\Curriculum;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentCurriculum;
use App\Models\User;
use App\Support\ActiveSchool;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(fn () => (new RbacSeeder)->run());

/**
 * A School with a bursar who may generate invoices, and a student holding an ACTIVE enrollment.
 *
 * The enrollment matters on BOTH sides of every assertion below. For the owning School it is what
 * makes the request succeed, so the control is a real 200/201 rather than a different refusal. For
 * the foreign School it is what makes the 404 mean something: this student IS billable, to somebody
 * — the request is refused because of who is asking, not because there was nothing to bill.
 *
 * @return array{school: School, bursar: User, student: Student}
 */
function byStudentSchool(string $roleName): array
{
    $school = School::factory()->create();
    $bursar = User::factory()->create(['school_id' => $school->id]);

    setPermissionsTeamId($school->id);
    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    foreach (['finance.access', 'finance.invoice.generate'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $role->syncPermissions(['finance.access', 'finance.invoice.generate']);
    $bursar->assignRole($roleName);
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $student = Student::factory()->create(['school_id' => $school->id]);
    ActiveSchool::runFor($school->id, fn () => StudentCurriculum::create([
        'student_id' => $student->id,
        'curriculum_id' => Curriculum::factory()->create(['school_id' => $school->id])->id,
        'status' => 'active',
    ]));

    return ['school' => $school, 'bursar' => $bursar, 'student' => $student];
}

/** @return array<int, array<string, mixed>> one ordinary charge line — the payload is not what is under test */
function byStudentLines(): array
{
    return [['description' => 'Tuition', 'amount_minor' => 100000, 'kind' => 'charge']];
}

test('GET billable-enrollment refuses a foreign student, and serves its own', function () {
    $a = byStudentSchool('bsri_bursar_a');
    $b = byStudentSchool('bsri_bursar_b');

    // Control first: School B's bursar CAN read School B's student. If this ever stops being a 200,
    // the refusal below is not evidence of isolation and this file is measuring the wrong thing.
    $this->actingAs($b['bursar'])->withSession(['school_id' => $b['school']->id])
        ->getJson("/api/v1/finance/students/{$b['student']->uuid}/billable-enrollment")
        ->assertOk()
        ->assertJsonStructure(['academic_context', 'already_invoiced']);

    // The same uuid, same route, School A's token.
    $this->actingAs($a['bursar'])->withSession(['school_id' => $a['school']->id])
        ->getJson("/api/v1/finance/students/{$b['student']->uuid}/billable-enrollment")
        ->assertNotFound();
});

test('POST invoices refuses a foreign student and writes nothing, and bills its own', function () {
    $a = byStudentSchool('bsri_bursar_c');
    $b = byStudentSchool('bsri_bursar_d');

    $this->actingAs($a['bursar'])->withSession(['school_id' => $a['school']->id])
        ->postJson("/api/v1/finance/students/{$b['student']->uuid}/invoices", ['lines' => byStudentLines()])
        ->assertNotFound();

    // The refusal is only worth as much as the absence of a row behind it. Both tables, because the
    // invoice and its lines are written in one transaction and a half-written pair is exactly what a
    // broken refusal leaves.
    expect(DB::table('finance_invoices')->count())->toBe(0)
        ->and(DB::table('finance_invoice_lines')->count())->toBe(0);

    // Control: the identical request, from the School that owns the student, DOES bill.
    $this->actingAs($b['bursar'])->withSession(['school_id' => $b['school']->id])
        ->postJson("/api/v1/finance/students/{$b['student']->uuid}/invoices", ['lines' => byStudentLines()])
        ->assertCreated();

    expect(DB::table('finance_invoices')->count())->toBe(1)
        ->and(DB::table('finance_invoices')->where('school_id', $b['school']->id)->count())->toBe(1);
});
