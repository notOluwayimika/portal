import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';
import ScoreEntryPage from '@/components/score-entry-page';
import SubjectResultStatusPanel from '@/components/subject-result-status-panel';
import { ToastItem } from '@/components/toast-item';
import type { Toast } from '@/components/toast-item';
import { handleBack } from '@/helpers';
import type { Auth } from '@/types';

export default function Show() {
    const { curriculumSubject, auth } = usePage<{
        curriculumSubject: any;
        auth: Auth;
    }>().props;
    const resultStatus = curriculumSubject.data.result_status;
    const role = auth.roles[0];
    const [toasts, setToasts] = useState<Toast[]>([]);
    // const toastCounter = useState(0)[0];
    // let toastId = toastCounter;
    // function addToast(message: string, type: ToastType = 'success') {
    //     const id = ++toastId;
    //     setToasts((t) => [...t, { id, message, type }]);
    // }

    function dismissToast(id: number) {
        setToasts((t) => t.filter((x) => x.id !== id));
    }
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
                userRole={role}
                onChanged={() => {
                    setLoading(true);
                    setTimeout(() => {
                        setLoading(false);
                    }, 1000);
                }}
            />
            <ScoreEntryPage cs={curriculumSubject.data} status={status} />
            {/* Toasts */}
            <div
                style={{
                    position: 'fixed',
                    bottom: 24,
                    right: 24,
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 8,
                    zIndex: 100,
                    minWidth: 280,
                    maxWidth: 360,
                }}
            >
                {toasts.map((toast) => (
                    <ToastItem
                        key={toast.id}
                        toast={toast}
                        onDismiss={() => dismissToast(toast.id)}
                    />
                ))}
            </div>
        </div>
    );
}
