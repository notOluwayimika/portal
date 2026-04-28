import { useMemo, useState } from 'react'
import { Plus, Pencil, Trash2, ChevronUp, ChevronDown } from 'lucide-react'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'

interface Subject {
    id: number
    name: string
    sections: ('Secondary' | 'Primary' | 'IFY Abuja' | 'IFY PH')[]
    is_optional: boolean
    order: number
}

const initialSubjects: Subject[] = [
    { id: 1, name: 'Mathematics', sections: ['Secondary', 'IFY Abuja'], is_optional: false, order: 1 },
    { id: 2, name: 'English Language', sections: ['Secondary', 'Primary'], is_optional: false, order: 2 },
    { id: 3, name: 'Physics', sections: ['Secondary'], is_optional: true, order: 3 },
    { id: 4, name: 'Chemistry', sections: ['Secondary'], is_optional: true, order: 4 },
    { id: 5, name: 'Biology', sections: ['Secondary'], is_optional: true, order: 5 },
    { id: 6, name: 'Further Mathematics', sections: ['Secondary'], is_optional: true, order: 6 },
    { id: 7, name: 'Economics', sections: ['Secondary', 'IFY Abuja'], is_optional: true, order: 7 },
    { id: 8, name: 'Geography', sections: ['Secondary'], is_optional: true, order: 8 },
    { id: 9, name: 'Civic Education', sections: ['Secondary', 'Primary'], is_optional: false, order: 9 },
    { id: 10, name: 'Basic Science', sections: ['Primary'], is_optional: false, order: 10 },
    { id: 11, name: 'Quantitative Reasoning', sections: ['Primary'], is_optional: false, order: 11 },
    { id: 12, name: 'Verbal Reasoning', sections: ['Primary'], is_optional: false, order: 12 },
]

const sectionBadgeStyles: Record<Subject['sections'][number], string> = {
    Secondary: 'bg-blue-100 text-blue-700',
    Primary: 'bg-amber-100 text-amber-700',
    'IFY Abuja': 'bg-teal-100 text-teal-700',
    'IFY PH': 'bg-teal-100 text-teal-700',
}

function SubjectRow({
    subject,
    onEdit,
    onDelete,
    onMove,
}: {
    subject: Subject
    onEdit: (subject: Subject) => void
    onDelete: (subject: Subject) => void
    onMove: (subjectId: number, direction: 'up' | 'down') => void
}) {
    return (
        <div className="flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p className="font-medium text-gray-900">{subject.name}</p>
                <div className="mt-2 flex flex-wrap gap-2">
                    {subject.sections.map((section) => (
                        <span
                            key={`${subject.id}-${section}`}
                            className={`rounded-full px-2 py-1 text-[11px] font-semibold ${sectionBadgeStyles[section]}`}
                        >
                            {section}
                        </span>
                    ))}
                    {subject.is_optional ? (
                        <span className="rounded-full bg-gray-100 px-2 py-1 text-[11px] font-semibold text-gray-700">
                            Optional
                        </span>
                    ) : null}
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    onClick={() => onMove(subject.id, 'up')}
                    className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-600 hover:bg-gray-50"
                >
                    <ChevronUp className="h-4 w-4" />
                    Up
                </button>
                <button
                    type="button"
                    onClick={() => onMove(subject.id, 'down')}
                    className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-600 hover:bg-gray-50"
                >
                    <ChevronDown className="h-4 w-4" />
                    Down
                </button>
                <button
                    type="button"
                    onClick={() => onEdit(subject)}
                    className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-600 hover:bg-gray-50"
                >
                    <Pencil className="h-4 w-4" />
                    Edit
                </button>
                <button
                    type="button"
                    onClick={() => onDelete(subject)}
                    className="inline-flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 hover:bg-red-100"
                >
                    <Trash2 className="h-4 w-4" />
                    Delete
                </button>
            </div>
        </div>
    )
}

export default function SubjectManager() {
    const [subjects, setSubjects] = useState<Subject[]>(initialSubjects)
    const [isModalOpen, setIsModalOpen] = useState(false)
    const [editingSubject, setEditingSubject] = useState<Subject | null>(null)
    const [name, setName] = useState('')
    const [sections, setSections] = useState<Subject['sections']>(['Secondary'])
    const [isOptional, setIsOptional] = useState(false)
    const [isConfirmOpen, setIsConfirmOpen] = useState(false)
    const [deleteTarget, setDeleteTarget] = useState<Subject | null>(null)

    const orderedSubjects = useMemo(
        () => [...subjects].sort((a, b) => a.order - b.order),
        [subjects]
    )

    const openAddModal = () => {
        setEditingSubject(null)
        setName('')
        setSections(['Secondary'])
        setIsOptional(false)
        setIsModalOpen(true)
    }

    const openEditModal = (subject: Subject) => {
        setEditingSubject(subject)
        setName(subject.name)
        setSections(subject.sections)
        setIsOptional(subject.is_optional)
        setIsModalOpen(true)
    }

    const handleSaveSubject = () => {
        if (!name.trim() || sections.length === 0) return

        if (editingSubject) {
            setSubjects((current) =>
                current.map((subject) =>
                    subject.id === editingSubject.id
                        ? { ...subject, name: name.trim(), sections, is_optional: isOptional }
                        : subject
                )
            )
        } else {
            const maxOrder = subjects.reduce((max, subject) => Math.max(max, subject.order), 0)
            setSubjects((current) => [
                ...current,
                {
                    id: Date.now(),
                    name: name.trim(),
                    sections,
                    is_optional: isOptional,
                    order: maxOrder + 1,
                },
            ])
        }

        setIsModalOpen(false)
    }

    const handleDeleteSubject = () => {
        if (!deleteTarget) return
        setSubjects((current) => current.filter((subject) => subject.id !== deleteTarget.id))
        setDeleteTarget(null)
        setIsConfirmOpen(false)
    }

    const moveSubject = (subjectId: number, direction: 'up' | 'down') => {
        setSubjects((current) => {
            const ordered = [...current].sort((a, b) => a.order - b.order)
            const index = ordered.findIndex((subject) => subject.id === subjectId)
            if (index === -1) return current

            const targetIndex = direction === 'up' ? index - 1 : index + 1
            if (targetIndex < 0 || targetIndex >= ordered.length) return current

            const reordered = [...ordered]
            const [moved] = reordered.splice(index, 1)
            reordered.splice(targetIndex, 0, moved)

            return reordered.map((subject, position) => ({ ...subject, order: position + 1 }))
        })
    }

    const toggleSection = (section: Subject['sections'][number]) => {
        setSections((current) =>
            current.includes(section)
                ? current.filter((item) => item !== section)
                : [...current, section]
        )
    }

    return (
        <div className="space-y-6">
            <div className="flex flex-col gap-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-xl font-semibold text-gray-900">Subject manager</h1>
                    <p className="text-sm text-gray-600">Subject order here defines the order on all result templates. Use the buttons to move subjects up or down.</p>
                </div>
                <button
                    type="button"
                    onClick={openAddModal}
                    className="inline-flex items-center gap-2 rounded-lg bg-[#185FA5] px-4 py-2 text-sm font-medium text-white hover:bg-[#0f4a82]"
                >
                    <Plus className="h-4 w-4" />
                    Add subject
                </button>
            </div>

            {orderedSubjects.length === 0 ? (
                <EmptyState
                    icon={<Plus className="h-8 w-8" />}
                    title="No subjects yet"
                    description="Create a subject to define curriculum ordering and sections."
                    actionLabel="Add subject"
                    onAction={openAddModal}
                />
            ) : (
                <div className="space-y-3">
                    {orderedSubjects.map((subject) => (
                        <SubjectRow
                            key={subject.id}
                            subject={subject}
                            onEdit={openEditModal}
                            onDelete={(subjectToDelete) => {
                                setDeleteTarget(subjectToDelete)
                                setIsConfirmOpen(true)
                            }}
                            onMove={moveSubject}
                        />
                    ))}
                </div>
            )}

            <Modal
                isOpen={isModalOpen}
                onClose={() => setIsModalOpen(false)}
                title={editingSubject ? 'Edit subject' : 'Add subject'}
                footer={
                    <div className="flex justify-end gap-3">
                        <button
                            type="button"
                            className="border border-gray-300 bg-white text-gray-600 rounded-lg px-4 py-2 text-sm hover:bg-gray-50"
                            onClick={() => setIsModalOpen(false)}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="rounded-lg bg-[#185FA5] px-4 py-2 text-sm font-medium text-white hover:bg-[#0f4a82]"
                            onClick={handleSaveSubject}
                        >
                            Save subject
                        </button>
                    </div>
                }
            >
                <div className="space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Subject name</label>
                        <input
                            type="text"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            className="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#185FA5] focus:border-transparent"
                            placeholder="Mathematics"
                        />
                    </div>
                    <div>
                        <p className="text-sm font-medium text-gray-700">Sections</p>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {(['Secondary', 'Primary', 'IFY Abuja', 'IFY PH'] as Subject['sections'][number][]).map((section) => (
                                <button
                                    key={section}
                                    type="button"
                                    onClick={() => toggleSection(section)}
                                    className={`rounded-full border px-3 py-1 text-sm font-medium ${sections.includes(section) ? 'border-[#185FA5] bg-[#def1ff] text-[#185FA5]' : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50'}`}
                                >
                                    {section}
                                </button>
                            ))}
                        </div>
                    </div>
                    <div className="flex items-center gap-3">
                        <label className="flex cursor-pointer items-center gap-3 rounded-full border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            <input
                                type="checkbox"
                                checked={isOptional}
                                onChange={() => setIsOptional((current) => !current)}
                                className="h-4 w-4 rounded border-gray-300 text-[#185FA5] focus:ring-[#185FA5]"
                            />
                            Optional subject
                        </label>
                    </div>
                </div>
            </Modal>

            <ConfirmDialog
                isOpen={isConfirmOpen}
                onClose={() => setIsConfirmOpen(false)}
                onConfirm={handleDeleteSubject}
                title="Delete subject"
                message="This subject will be removed from the subject manager. Are you sure you want to continue?"
                confirmLabel="Delete"
                dangerous
            />
        </div>
    )
}
