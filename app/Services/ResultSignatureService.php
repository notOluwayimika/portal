<?php

namespace App\Services;

use App\Models\School;
use App\Models\StudentCurriculum;
use App\Models\User;

class ResultSignatureService
{
    public function resolve(StudentCurriculum $studentCurriculum, School $school): ?array
    {
        $signature = $this->principalSignature($school)
            ?? $this->headOfSchoolSignature($studentCurriculum, $school)
            ?? $this->fallbackSignature($school);

        return $this->withApprovalDate($signature, $studentCurriculum);
    }

    public function forCurricula(iterable $studentCurricula, School $school): array
    {
        $signatures = [];
        $principalSignature = $this->principalSignature($school);
        $fallbackSignature = $this->fallbackSignature($school);

        foreach ($studentCurricula as $studentCurriculum) {
            $signature = $principalSignature
                ?? $this->headOfSchoolSignature($studentCurriculum, $school)
                ?? $fallbackSignature;
            $signatures[$studentCurriculum->uuid] = $this->withApprovalDate($signature, $studentCurriculum);
        }

        return $signatures;
    }

    private function principalSignature(School $school): ?array
    {
        $principal = User::role('principal')
            ->whereNotNull('signature_id')
            ->with('signatureFile')
            ->orderBy('id')
            ->first();

        if ($principal?->signatureFile) {
            return [
                'url' => $principal->signatureFile->url,
                'label' => $this->approvalLabel($principal->full_name, $school->result_approver_title),
                'signer_name' => null,
                'source' => 'principal',
            ];
        }

        return null;
    }

    private function headOfSchoolSignature(StudentCurriculum $studentCurriculum, School $school): ?array
    {
        $headOfSchool = $studentCurriculum->headOfSchool()?->load('user.signatureFile');

        if ($headOfSchool?->user?->signatureFile) {
            return [
                'url' => $headOfSchool->user->signatureFile->url,
                'label' => $this->approvalLabel($headOfSchool->full_name, $school->result_approver_title),
                'signer_name' => null,
                'source' => 'head_of_school',
            ];
        }

        return null;
    }

    private function fallbackSignature(School $school): ?array
    {
        $school->loadMissing('fallbackSignatureFile');

        if ($school->fallbackSignatureFile) {
            return [
                'url' => $school->fallbackSignatureFile->url,
                'label' => $this->approvalLabel($school->result_approver_name, $school->result_approver_title),
                'signer_name' => null,
                'source' => 'fallback',
            ];
        }

        return null;
    }

    private function withApprovalDate(?array $signature, StudentCurriculum $studentCurriculum): ?array
    {
        if (! $signature) {
            return null;
        }

        return [
            ...$signature,
            'approval_date' => $studentCurriculum->updated_at?->toDateString(),
        ];
    }

    /**
     * The caption printed under the signature.
     *
     * When the school has set a `result_approver_title` — primary sets
     * "Head of School" — the caption names the OFFICE rather than the person, which
     * is what that school asked for ("Approved by the Head of School" above the
     * signature). Without one, the existing name-based wording is unchanged, so
     * every school that has not been configured prints exactly what it printed
     * before.
     */
    private function approvalLabel(?string $approverName, ?string $approverTitle = null): string
    {
        if ($approverTitle) {
            return 'Approved by the '.$approverTitle;
        }

        return $approverName
            ? 'Reviewed and approved by '.$approverName
            : 'Authorized Signature';
    }
}
