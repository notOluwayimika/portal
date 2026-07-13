<?php

use App\Models\Curriculum;

it('uses numerical grading by default', function () {
    $curriculum = new Curriculum;

    expect($curriculum->usesCategoricalGrading())->toBeFalse();
});

it('uses categorical grading when a scheme is assigned', function () {
    $curriculum = new Curriculum(['grading_scheme_id' => 42]);

    expect($curriculum->usesCategoricalGrading())->toBeTrue();
});
