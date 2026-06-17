import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';
import ScoreEntryPage from '@/components/score-entry-page';
import SubjectResultStatusPanel from '@/components/subject-result-status-panel';
import { handleBack } from '@/helpers';
import type { Auth } from '@/types';

export default function Show() {
    const { curriculumSubject, auth } = usePage<{
        curriculumSubject: any;
        auth: Auth;
    }>().props;
    const resultStatus = curriculumSubject.data.result_status;
    const roles = auth.roles;

    const [status, setStatus] = useState(resultStatus);
    const [loading, setLoading] = useState(false);
    useEffect(() => {
        async function getResultStatus() {
            const response = await axios.get(
                `/api/curriculum-subjects/${curriculumSubject.data.id}/result-status`,
            );

            if (response.status == 200) {
                setStatus(response.data);
            }
        }
        getResultStatus();
    }, [curriculumSubject.data.id, loading]);

    return (
        <div className="space-y-6 p-10">
            <div></div>
            <button
                className="btn btn-ghost btn-sm btn-icon"
                onClick={handleBack}
                title="Back to curricula"
                style={{ fontSize: 14 }}
            >
                ← Go back
            </button>
            <SubjectResultStatusPanel
                curriculumSubjectId={curriculumSubject.data.id}
                status={status}
                userRoles={roles}
                onChanged={() => {
                    setLoading(true);
                    setTimeout(() => {
                        setLoading(false);
                    }, 1000);
                }}
            />
            <ScoreEntryPage cs={curriculumSubject.data} status={status} />

        </div>
    );
}
