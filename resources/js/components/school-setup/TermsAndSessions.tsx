import { useMemo, useState } from 'react'
import { CalendarDays, Pencil } from 'lucide-react'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'

interface Term {
    id: number
    name: string
    start_date: string
    end_date: string
    status: 'completed' | 'active' | 'upcoming'
}

interface AcademicSession {
    id: number
    name: string
    is_current: boolean
    terms: Term[]
}

const initialSessions: AcademicSession[] = [
    {
        id: 1,
        name: '2025/2026',
        is_current: true,
        terms: [
            { id: 1, name: 'First Term', start_date: '2025-09-09', end_date: '2025-12-13', status: 'completed' },
            { id: 2, name: 'Second Term', start_date: '2026-01-13', end_date: '2026-04-04', status: 'active' },
            { id: 3, name: 'Third Term', start_date: '2026-04-27', end_date: '2026-07-18', status: 'upcoming' },
        ],
    },
    {
        id: 2,
        name: '2024/2025',
        is_current: false,
        terms: [
            { id: 4, name: 'First Term', start_date: '2024-09-10', end_date: '2024-12-14', status: 'completed' },
            { id: 5, name: 'Second Term', start_date: '2025-01-14', end_date: '2025-04-05', status: 'completed' },
            { id: 6, name: 'Third Term', start_date: '2025-04-28', end_date: '2025-07-19', status: 'completed' },
        ],
    },
]

const statusClasses: Record<Term['status'], string> = {
    completed: 'bg-gray-100 text-gray-700',
    active: 'bg-emerald-100 text-emerald-700',
    upcoming: 'bg-sky-100 text-sky-700',
}

export default function TermsAndSessions() {
    const [sessions, setSessions] = useState<AcademicSession[]>(initialSessions)
    const [selectedSessionId, setSelectedSessionId] = useState<number>(1)
    const [isSessionModalOpen, setIsSessionModalOpen] = useState(false)
    const [isTermModalOpen, setIsTermModalOpen] = useState(false)
    const [editTerm, setEditTerm] = useState<Term | null>(null)
    const [newSessionName, setNewSessionName] = useState('')
    const [termName, setTermName] = useState('')
    const [termStartDate, setTermStartDate] = useState('')
    const [termEndDate, setTermEndDate] = useState('')
    const [termStatus, setTermStatus] = useState<Term['status']>('upcoming')
    const [isConfirmOpen, setIsConfirmOpen] = useState(false)
    const [deleteTermId, setDeleteTermId] = useState<number | null>(null)

    const selectedSession = useMemo(
        () => sessions.find((session) => session.id === selectedSessionId) ?? sessions[0],
        [sessions, selectedSessionId]
    )

    const handleSetActiveStatus = (termId: number) => {
        setSessions((current) =>
            current.map((session) => {
                if (session.id !== selectedSessionId) return session
                return {
                    ...session,
                    terms: session.terms.map((term) => {
                        if (term.id === termId) {
                            return { ...term, status: 'active' }
                        }
                        if (term.status === 'active') {
                            return { ...term, status: 'completed' }
                        }
                        return term
                    }),
                }
            })
        )
    }

    const openNewSessionModal = () => {
        setNewSessionName('')
        setIsSessionModalOpen(true)
    }

    const handleCreateSession = () => {
        if (!newSessionName.trim()) return
        const newSession: AcademicSession = {
            id: Date.now(),
            name: newSessionName.trim(),
            is_current: false,
            terms: [
                { id: Date.now() + 1, name: 'First Term', start_date: '', end_date: '', status: 'upcoming' },
                { id: Date.now() + 2, name: 'Second Term', start_date: '', end_date: '', status: 'upcoming' },
                { id: Date.now() + 3, name: 'Third Term', start_date: '', end_date: '', status: 'upcoming' },
            ],
        }
        setSessions((current) => [...current, newSession])
        setSelectedSessionId(newSession.id)
        setIsSessionModalOpen(false)
    }

    const openEditTermModal = (term: Term) => {
        setEditTerm(term)
        setTermName(term.name)
        setTermStartDate(term.start_date)
        setTermEndDate(term.end_date)
        setTermStatus(term.status)
        setIsTermModalOpen(true)
    }

    const handleSaveTerm = () => {
        if (!editTerm) return
        setSessions((current) =>
            current.map((session) => {
                if (session.id !== selectedSessionId) return session
                return {
                    ...session,
                    terms: session.terms.map((term) =>
                        term.id === editTerm.id
                            ? { ...term, name: termName, start_date: termStartDate, end_date: termEndDate, status: termStatus }
                            : term
                    ),
                }
            })
        )
        setEditTerm(null)
        setIsTermModalOpen(false)
    }

    const handleDeleteTerm = () => {
        if (deleteTermId === null) return
        setSessions((current) =>
            current.map((session) =>
                session.id !== selectedSessionId
                    ? session
                    : {
                          ...session,
                          terms: session.terms.filter((term) => term.id !== deleteTermId),
                      }
            )
        )
        setDeleteTermId(null)
        setIsConfirmOpen(false)
    }

    return (
        <div className="space-y-6">
            <div className="flex flex-col gap-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-xl font-semibold text-gray-900">Terms &amp; sessions</h1>
                    <p className="text-sm text-gray-600">Manage academic sessions, term dates, and current term status.</p>
                </div>
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <div className="flex items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
                        <CalendarDays className="h-5 w-5 text-gray-500" />
                        <select
                            value={selectedSessionId}
                            onChange={(event) => setSelectedSessionId(Number(event.target.value))}
                            className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#185FA5] focus:border-transparent"
                        >
                            {sessions.map((session) => (
                                <option key={session.id} value={session.id}>
                                    {session.name} {session.is_current ? '(Current)' : ''}
                                </option>
                            ))}
                        </select>
                    </div>
                    <button
                        type="button"
                        className="inline-flex items-center gap-2 rounded-lg bg-[#185FA5] px-4 py-2 text-sm font-medium text-white hover:bg-[#0f4a82]"
                        onClick={openNewSessionModal}
                    >
                        <span>+ New session</span>
                    </button>
                </div>
            </div>

            {selectedSession.terms.length === 0 ? (
                <EmptyState
                    icon={<CalendarDays className="h-8 w-8" />}
                    title="No terms yet"
                    description="Create a session and then add term details to begin scheduling." 
                    actionLabel="New session"
                    onAction={openNewSessionModal}
                />
            ) : (
                <div className="space-y-4">
                    {selectedSession.terms.map((term) => (
                        <div key={term.id} className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p className="text-lg font-semibold text-gray-900">{term.name}</p>
                                    <p className="mt-1 text-sm text-gray-500">{term.start_date || 'Start date unset'} — {term.end_date || 'End date unset'}</p>
                                </div>
                                <div className="flex items-center gap-3">
                                    <span className={`rounded-full px-3 py-1 text-xs font-semibold ${statusClasses[term.status]}`}>
                                        {term.status}
                                    </span>
                                    <button
                                        type="button"
                                        className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-600 hover:bg-gray-50"
                                        onClick={() => openEditTermModal(term)}
                                    >
                                        <Pencil className="h-4 w-4" />
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-600 hover:bg-gray-50"
                                        onClick={() => {
                                            setDeleteTermId(term.id)
                                            setIsConfirmOpen(true)
                                        }}
                                    >
                                        Delete
                                    </button>
                                </div>
                            </div>
                            <div className="mt-4 flex flex-wrap gap-3">
                                <button
                                    type="button"
                                    className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-600 hover:bg-gray-50"
                                    onClick={() => handleSetActiveStatus(term.id)}
                                >
                                    Set active
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <ConfirmDialog
                isOpen={isConfirmOpen}
                onClose={() => setIsConfirmOpen(false)}
                onConfirm={handleDeleteTerm}
                title="Delete term"
                message="This term will be removed from the selected session. Are you sure?"
                confirmLabel="Delete"
                dangerous
            />

            <Modal
                isOpen={isSessionModalOpen}
                onClose={() => setIsSessionModalOpen(false)}
                title="Create new session"
                footer={
                    <div className="flex justify-end gap-3">
                        <button
                            type="button"
                            className="border border-gray-300 bg-white text-gray-600 rounded-lg px-4 py-2 text-sm hover:bg-gray-50"
                            onClick={() => setIsSessionModalOpen(false)}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="rounded-lg bg-[#185FA5] px-4 py-2 text-sm font-medium text-white hover:bg-[#0f4a82]"
                            onClick={handleCreateSession}
                        >
                            Create session
                        </button>
                    </div>
                }
            >
                <div className="space-y-4">
                    <label className="block text-sm font-medium text-gray-700">Session name</label>
                    <input
                        type="text"
                        value={newSessionName}
                        onChange={(event) => setNewSessionName(event.target.value)}
                        className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#185FA5] focus:border-transparent"
                        placeholder="2026/2027"
                    />
                </div>
            </Modal>

            <Modal
                isOpen={isTermModalOpen}
                onClose={() => {
                    setIsTermModalOpen(false)
                    setEditTerm(null)
                }}
                title="Edit term"
                footer={
                    <div className="flex justify-end gap-3">
                        <button
                            type="button"
                            className="border border-gray-300 bg-white text-gray-600 rounded-lg px-4 py-2 text-sm hover:bg-gray-50"
                            onClick={() => {
                                setIsTermModalOpen(false)
                                setEditTerm(null)
                            }}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="rounded-lg bg-[#185FA5] px-4 py-2 text-sm font-medium text-white hover:bg-[#0f4a82]"
                            onClick={handleSaveTerm}
                        >
                            Save term
                        </button>
                    </div>
                }
            >
                <div className="space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Term name</label>
                        <input
                            type="text"
                            value={termName}
                            onChange={(event) => setTermName(event.target.value)}
                            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#185FA5] focus:border-transparent"
                        />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label className="block text-sm font-medium text-gray-700">Start date</label>
                            <input
                                type="date"
                                value={termStartDate}
                                onChange={(event) => setTermStartDate(event.target.value)}
                                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#185FA5] focus:border-transparent"
                            />
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700">End date</label>
                            <input
                                type="date"
                                value={termEndDate}
                                onChange={(event) => setTermEndDate(event.target.value)}
                                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#185FA5] focus:border-transparent"
                            />
                        </div>
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Status</label>
                        <select
                            value={termStatus}
                            onChange={(event) => setTermStatus(event.target.value as Term['status'])}
                            className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#185FA5] focus:border-transparent"
                        >
                            <option value="completed">Completed</option>
                            <option value="active">Active</option>
                            <option value="upcoming">Upcoming</option>
                        </select>
                    </div>
                </div>
            </Modal>
        </div>
    )
}
