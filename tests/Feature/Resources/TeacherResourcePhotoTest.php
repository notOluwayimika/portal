<?php

use App\Http\Resources\TeacherResource;
use App\Models\FileUpload;
use App\Models\School;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * TeacherResource fed the STORED URL into Storage::disk('s3')->temporaryUrl(),
 * which expects an object KEY and signs whatever it is handed. So the whole
 * "https://bucket.s3.../teachers/photos/x.jpg" became the key and S3 answered
 * 404 NoSuchKey with the full URL echoed back inside <Key>.
 *
 * StudentResource and GuardianResource pass `photoFile->url` straight through
 * and never broke. This pins Teacher to that same shape.
 *
 * Deliberately a RESOURCE-LEVEL test, like CurriculumResourceNullChainTest: the
 * bug is one expression, there is no Teacher factory, and driving it through HTTP
 * would need a user, roles and school context to say nothing more than this does.
 */
uses(RefreshDatabase::class);

function trp_teacherWithPhoto(?FileUpload $photo): Teacher
{
    $school = School::factory()->create();

    return Teacher::create([
        'school_id' => $school->id,
        'staff_number' => 'STF-'.fake()->unique()->numerify('#####'),
        'first_name' => 'Grace',
        'last_name' => 'Teacher',
        'photo_id' => $photo?->id,
    ]);
}

it('returns the stored url verbatim, never a presigned key', function () {
    $url = 'https://brookstone-web-app.s3.eu-north-1.amazonaws.com/teachers/photos/abc123.jpg';

    $photo = FileUpload::create([
        'name' => 'abc123.jpg',
        'folder_path' => '/teachers/photos',
        'url' => $url,
    ]);

    $payload = TeacherResource::make(trp_teacherWithPhoto($photo))->toArray(request());

    expect($payload['photo'])->toBe($url);

    // The regression signature: temporaryUrl() percent-encoded the stored URL
    // into the path and appended a signature. Either marker means the object
    // key is a URL again.
    expect($payload['photo'])
        ->not->toContain('X-Amz-Signature')
        ->and($payload['photo'])->not->toContain('https%3A')
        ->and(substr_count($payload['photo'], 'https://'))->toBe(1);
});

it('returns null when the teacher has no photo', function () {
    $payload = TeacherResource::make(trp_teacherWithPhoto(null))->toArray(request());

    expect($payload['photo'])->toBeNull();
});
