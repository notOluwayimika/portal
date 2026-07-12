<?php

use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\MarkingComponent;
use App\Models\MarkingScheme;

it('uses scheme components when a curriculum has an assigned scheme', function () {
    $local = new MarkingComponent(['name' => 'Legacy Exam', 'weight' => 0.7]);
    $shared = new MarkingComponent(['name' => 'Examination', 'weight' => 0.6]);

    $scheme = new MarkingScheme;
    $scheme->setRelation('components', collect([$shared]));

    $curriculum = new Curriculum;
    $curriculum->setRelation('markingScheme', $scheme);

    $subject = new CurriculumSubject;
    $subject->setRelation('curriculum', $curriculum);
    $subject->setRelation('markingComponents', collect([$local]));

    expect($subject->effectiveMarkingComponents()->all())->toBe([$shared]);
});

it('falls back to local components for an unmigrated curriculum', function () {
    $local = new MarkingComponent(['name' => 'Legacy Exam', 'weight' => 0.7]);

    $curriculum = new Curriculum;
    $curriculum->setRelation('markingScheme', null);

    $subject = new CurriculumSubject;
    $subject->setRelation('curriculum', $curriculum);
    $subject->setRelation('markingComponents', collect([$local]));

    expect($subject->effectiveMarkingComponents()->all())->toBe([$local]);
});
