import axios from 'axios';
import { Save } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'react-toastify';
import { SchoolChecklist } from '@/components/school-access/school-checklist';
import { Button } from '@/components/ui/button';
import Modal from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/spinner';
import type { SchoolOption } from '@/types';
import type { Teacher } from '@/types/models';

/**
 * Lets an admin grant/revoke a teacher's access to schools the admin
 * themself can access (school_user pivot). The teacher's home school is
 * locked — it can't be revoked here.
 */
export function ManageTeacherSchoolsDialog({
    teacher,
    adminSchools,
    isOpen,
    onClose,
    onSuccess,
}: {
    teacher: Teacher;
    adminSchools: SchoolOption[];
    isOpen: boolean;
    onClose: () => void;
    onSuccess: () => void;
}) {
    const homeUuid = teacher.schools?.find((s) => s.is_home)?.uuid;
    const [selected, setSelected] = useState<string[]>(
        (teacher.schools ?? [])
            .filter((s) => !s.is_home)
            .map((s) => s.uuid),
    );
    const [processing, setProcessing] = useState(false);

    const toggle = (uuid: string) => {
        setSelected((prev) =>
            prev.includes(uuid)
                ? prev.filter((s) => s !== uuid)
                : [...prev, uuid],
        );
    };

    const submit = async () => {
        try {
            setProcessing(true);
            await axios.put(`/api/teachers/${teacher.id}/schools`, {
                schools: selected,
            });
            toast.success('Teacher school access updated');
            onSuccess();
        } catch (error) {
            const message = axios.isAxiosError(error)
                ? error.response?.data?.message
                : null;
            toast.error(message || 'Failed to update school access');
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Modal
            isOpen={isOpen}
            onClose={onClose}
            title={`School access — ${teacher.full_name}`}
            size="lg"
            footer={
                <div className="flex justify-end gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onClose}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button type="button" onClick={submit} disabled={processing}>
                        {processing ? (
                            <Spinner className="mr-2 h-4 w-4 animate-spin" />
                        ) : (
                            <Save className="mr-2 h-4 w-4" />
                        )}
                        Save access
                    </Button>
                </div>
            }
        >
            <div className="flex flex-col gap-2">
                <p className="text-sm text-muted-foreground">
                    Choose which of your schools this teacher can log into. Their
                    home school cannot be removed.
                </p>
                <SchoolChecklist
                    schools={adminSchools}
                    selected={selected}
                    toggle={toggle}
                    lockedUuids={homeUuid ? [homeUuid] : []}
                />
            </div>
        </Modal>
    );
}
