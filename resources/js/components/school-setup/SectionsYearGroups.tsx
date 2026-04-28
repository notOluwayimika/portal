import { useMemo, useState, type ChangeEvent } from 'react'
import { Plus, Pencil, X } from 'lucide-react'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'

interface ClassArm {
    id: number
    name: string
    type: 'IGCSE' | 'WAEC' | 'IFY' | 'Standard'
}

interface YearGroup {
    id: number
    name: string
    classArms: ClassArm[]
}

interface Section {
    id: number
    name: string
    slug: string
    is_active: boolean
    yearGroups: YearGroup[]
}

const initialSections: Section[] = [
    {
        id: 1,
        name: 'Secondary School',
        slug: 'secondary',
        is_active: true,
        yearGroups: [
            { id: 1, name: 'Year 7', classArms: [{ id: 1, name: '7A', type: 'IGCSE' }, { id: 2, name: '7B', type: 'IGCSE' }] },
            { id: 2, name: 'Year 8', classArms: [{ id: 3, name: '8A', type: 'IGCSE' }, { id: 4, name: '8B', type: 'IGCSE' }] },
            { id: 3, name: 'Year 9', classArms: [{ id: 5, name: '9A', type: 'IGCSE' }, { id: 6, name: '9B', type: 'IGCSE' }] },
            { id: 4, name: 'Year 10', classArms: [{ id: 7, name: '10A', type: 'IGCSE' }, { id: 8, name: '10B', type: 'IGCSE' }, { id: 9, name: '10C', type: 'WAEC' }] },
            { id: 5, name: 'Year 11', classArms: [{ id: 10, name: '11A', type: 'IGCSE' }, { id: 11, name: '11B', type: 'WAEC' }] },
            { id: 6, name: 'Year 12', classArms: [{ id: 12, name: '12A', type: 'WAEC' }] },
        ],
    },
    {
        id: 2,
        name: 'Primary School',
        slug: 'primary',
        is_active: true,
        yearGroups: [
            { id: 7, name: 'Pre-Kinderfun', classArms: [{ id: 13, name: 'PKF A', type: 'Standard' }] },
            { id: 8, name: 'Kinderfun', classArms: [{ id: 14, name: 'KF A', type: 'Standard' }] },
            { id: 9, name: 'Nursery', classArms: [{ id: 15, name: 'Nur A', type: 'Standard' }, { id: 16, name: 'Nur B', type: 'Standard' }] },
            { id: 10, name: 'Reception', classArms: [{ id: 17, name: 'Rec A', type: 'Standard' }] },
            { id: 11, name: 'Primary 1', classArms: [{ id: 18, name: 'P1A', type: 'Standard' }, { id: 19, name: 'P1B', type: 'Standard' }] },
            { id: 12, name: 'Primary 2', classArms: [{ id: 20, name: 'P2A', type: 'Standard' }] },
            { id: 13, name: 'Primary 3', classArms: [{ id: 21, name: 'P3A', type: 'Standard' }] },
            { id: 14, name: 'Primary 4', classArms: [{ id: 22, name: 'P4A', type: 'Standard' }] },
            { id: 15, name: 'Primary 5', classArms: [{ id: 23, name: 'P5A', type: 'Standard' }, { id: 24, name: 'P5B', type: 'Standard' }] },
            { id: 16, name: 'Primary 6', classArms: [{ id: 25, name: 'P6A', type: 'Standard' }] },
        ],
    },
    {
        id: 3,
        name: 'IFY Abuja',
        slug: 'ify-abuja',
        is_active: true,
        yearGroups: [{ id: 17, name: 'IFY Year 1', classArms: [{ id: 26, name: 'IFY-ABJ-A', type: 'IFY' }, { id: 27, name: 'IFY-ABJ-Hybrid', type: 'IFY' }] }],
    },
    {
        id: 4,
        name: 'IFY PH',
        slug: 'ify-ph',
        is_active: true,
        yearGroups: [{ id: 18, name: 'IFY Year 1', classArms: [{ id: 28, name: 'IFY-PH-A', type: 'IFY' }, { id: 29, name: 'IFY-PH-Hybrid', type: 'IFY' }] }],
    },
]

const typeBadge: Record<ClassArm['type'], { label: string; className: string }> = {
    IGCSE: { label: 'IGCSE', className: 'bg-blue-100 text-blue-700' },
    WAEC: { label: 'WAEC', className: 'bg-emerald-100 text-emerald-700' },
    IFY: { label: 'IFY', className: 'bg-teal-100 text-teal-700' },
    Standard: { label: 'Standard', className: 'bg-amber-100 text-amber-700' },
}

const pillClass = 'inline-flex items-center rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset'

export default function SectionsYearGroups() {
    const [sections, setSections] = useState<Section[]>(initialSections)
    const [expanded, setExpanded] = useState<number[]>([1, 2])
    const [isSectionModalOpen, setIsSectionModalOpen] = useState(false)
    const [isYearGroupModalOpen, setIsYearGroupModalOpen] = useState(false)
    const [isArmModalOpen, setIsArmModalOpen] = useState(false)
    const [isConfirmOpen, setIsConfirmOpen] = useState(false)
    const [activeSectionId, setActiveSectionId] = useState<number | null>(null)
    const [activeYearGroupId, setActiveYearGroupId] = useState<number | null>(null)
    const [activeArm, setActiveArm] = useState<ClassArm | null>(null)
    const [sectionName, setSectionName] = useState('')
    const [yearGroupName, setYearGroupName] = useState('')
    const [newArmName, setNewArmName] = useState('')
    const [newArmType, setNewArmType] = useState<ClassArm['type']>('IGCSE')
    const [deleteTarget, setDeleteTarget] = useState<{ type: 'section' | 'yearGroup'; sectionId: number; yearGroupId?: number } | null>(null)

    const summaryBySection = useMemo(() => {
        return sections.map((section) => {
            const yearGroupCount = section.yearGroups.length
            const armCount = section.yearGroups.reduce((total, group) => total + group.classArms.length, 0)
            return { id: section.id, yearGroupCount, armCount }
        })
    }, [sections])

    const handleToggleSection = (sectionId: number) => {
        setExpanded((current) =>
            current.includes(sectionId) ? current.filter((id) => id !== sectionId) : [...current, sectionId]
        )
    }

    const openAddSection = () => {
        setSectionName('')
        setIsSectionModalOpen(true)
    }

    const openAddYearGroup = (sectionId: number) => {
        setActiveSectionId(sectionId)
        setYearGroupName('')
        setIsYearGroupModalOpen(true)
    }

    const openAddArm = (sectionId: number, yearGroupId: number) => {
        setActiveSectionId(sectionId)
        setActiveYearGroupId(yearGroupId)
        setNewArmName('')
        setNewArmType('IGCSE')
        setIsArmModalOpen(true)
    }

    const openEditArm = (sectionId: number, yearGroupId: number, arm: ClassArm) => {
        setActiveSectionId(sectionId)
        setActiveYearGroupId(yearGroupId)
        setActiveArm(arm)
        setNewArmName(arm.name)
        setNewArmType(arm.type)
        setIsArmModalOpen(true)
    }

    const handleAddSection = () => {
        if (!sectionName.trim()) return
        setSections((current) => [
            ...current,
            { id: Date.now(), name: sectionName.trim(), slug: sectionName.toLowerCase().replace(/\s+/g, '-'), is_active: true, yearGroups: [] },
        ])
        setIsSectionModalOpen(false)
    }

    const handleAddYearGroup = () => {
        if (!yearGroupName.trim() || activeSectionId === null) return
        setSections((current) =>
            current.map((section) =>
                section.id === activeSectionId
                    ? {
                          ...section,
                          yearGroups: [
                              ...section.yearGroups,
                              { id: Date.now(), name: yearGroupName.trim(), classArms: [] },
                          ],
                      }
                    : section
            )
        )
        setIsYearGroupModalOpen(false)
    }

    const handleSaveArm = () => {
        if (!newArmName.trim() || activeSectionId === null || activeYearGroupId === null) return

        setSections((current) =>
            current.map((section) => {
                if (section.id !== activeSectionId) return section
                return {
                    ...section,
                    yearGroups: section.yearGroups.map((group) => {
                        if (group.id !== activeYearGroupId) return group
                        const newArm: ClassArm = {
                            id: activeArm?.id ?? Date.now(),
                            name: newArmName.trim(),
                            type: newArmType,
                        }
                        const updatedArms = group.classArms.filter((arm) => arm.id !== newArm.id)
                        return {
                            ...group,
                            classArms: [...updatedArms, newArm],
                        }
                    }),
                }
            })
        )
        setActiveArm(null)
        setIsArmModalOpen(false)
    }

    const handleDelete = () => {
        if (!deleteTarget) return

        setSections((current) =>
            current.map((section) => {
                if (section.id !== deleteTarget.sectionId) return section
                if (deleteTarget.type === 'section') {
                    return null
                }
                return {
                    ...section,
                    yearGroups: section.yearGroups.filter((group) => group.id !== deleteTarget.yearGroupId),
                }
            })
                .filter(Boolean) as Section[]
        )
        setDeleteTarget(null)
        setIsConfirmOpen(false)
    }

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <div>
                    <h1 className="text-xl font-semibold text-gray-900">Sections &amp; year groups</h1>
                    <p className="text-sm text-gray-600">Manage school sections, year groups and class arms.</p>
                </div>
                <button
                    type="button"
                    className="inline-flex items-center gap-2 rounded-lg bg-[#185FA5] px-4 py-2 text-sm font-medium text-white hover:bg-[#0f4a82]"
                    onClick={openAddSection}
                >
                    <Plus className="h-4 w-4" />
                    Add section
                </button>
            </div>

            {sections.length === 0 ? (
                <EmptyState
                    icon={<Plus className="h-8 w-8" />}
                    title="No sections yet"
                    description="Add a section to begin organising year groups and class arms."
                    actionLabel="Add section"
                    onAction={openAddSection}
                />
            ) : (
                sections.map((section) => {
                    const summary = summaryBySection.find((item) => item.id === section.id)
                    return (
                        <div key={section.id} className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <button
                                type="button"
                                className="w-full text-left"
                                onClick={() => handleToggleSection(section.id)}
                            >
                                <div className="flex items-center justify-between gap-4">
                                    <div className="flex items-center gap-3">
                                        <span className="h-3 w-3 rounded-full bg-[#185FA5]" />
                                        <div>
                                            <p className="text-lg font-semibold text-gray-900">{section.name}</p>
                                            <p className="text-sm text-gray-500">
                                                {summary?.yearGroupCount ?? 0} year groups · {summary?.armCount ?? 0} class arms
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm text-gray-500">{expanded.includes(section.id) ? 'Collapse' : 'Expand'}</span>
                                        <Pencil className="h-4 w-4 text-gray-400" />
                                    </div>
                                </div>
                            </button>

                            {expanded.includes(section.id) ? (
                                <div className="mt-5 space-y-4">
                                    {section.yearGroups.length === 0 ? (
                                        <div className="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-600">
                                            No year groups yet. Add one to get started.
                                        </div>
                                    ) : (
                                        section.yearGroups.map((group) => (
                                            <div key={group.id} className="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                                    <div>
                                                        <p className="font-medium text-gray-900">{group.name}</p>
                                                    </div>
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        {group.classArms.map((arm) => (
                                                            <button
                                                                key={arm.id}
                                                                type="button"
                                                                className={`${pillClass} ${typeBadge[arm.type].className}`}
                                                                onClick={() => openEditArm(section.id, group.id, arm)}
                                                            >
                                                                {arm.name}
                                                            </button>
                                                        ))}
                                                        <button
                                                            type="button"
                                                            className="inline-flex items-center gap-2 rounded-full border border-dashed border-gray-300 bg-white px-3 py-1 text-xs font-medium text-gray-600 hover:bg-gray-100"
                                                            onClick={() => openAddArm(section.id, group.id)}
                                                        >
                                                            <Plus className="h-3.5 w-3.5" />
                                                            Add arm
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className="rounded-full border border-gray-300 bg-white px-3 py-1 text-xs font-medium text-gray-600 hover:bg-gray-100"
                                                            onClick={() => {
                                                                setDeleteTarget({ type: 'yearGroup', sectionId: section.id, yearGroupId: group.id })
                                                                setIsConfirmOpen(true)
                                                            }}
                                                        >
                                                            <X className="h-3.5 w-3.5" />
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        ))
                                    )}
                                    <button
                                        type="button"
                                        className="inline-flex items-center gap-2 rounded-full border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50"
                                        onClick={() => openAddYearGroup(section.id)}
                                    >
                                        <Plus className="h-4 w-4" />
                                        Add year group
                                    </button>
                                </div>
                            ) : null}
                        </div>
                    )
                })
            )}

            <ConfirmDialog
                isOpen={isConfirmOpen}
                onClose={() => setIsConfirmOpen(false)}
                onConfirm={handleDelete}
                title="Confirm delete"
                message="This action cannot be undone. Are you sure you want to remove this item?"
                confirmLabel="Delete"
                dangerous
            />

            <Modal
                isOpen={isSectionModalOpen}
                onClose={() => setIsSectionModalOpen(false)}
                title="Add section"
                footer={
                    <div className="flex justify-end gap-3">
                        <button
                            type="button"
                            className="border border-gray-300 bg-white text-gray-600 rounded-lg px-4 py-2 text-sm hover:bg-gray-50"
                            onClick={() => setIsSectionModalOpen(false)}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="rounded-lg bg-[#185FA5] px-4 py-2 text-sm font-medium text-white hover:bg-[#0f4a82]"
                            onClick={handleAddSection}
                        >
                            Save section
                        </button>
                    </div>
                }
            >
                <div className="space-y-4">
                    <label className="block text-sm font-medium text-gray-700">Section name</label>
                    <input
                        type="text"
                        value={sectionName}
                        onChange={(event) => setSectionName(event.target.value)}
                        className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#185FA5] focus:border-transparent"
                        placeholder="Secondary School"
                    />
                </div>
            </Modal>

            <Modal
                isOpen={isYearGroupModalOpen}
                onClose={() => setIsYearGroupModalOpen(false)}
                title="Add year group"
                footer={
                    <div className="flex justify-end gap-3">
                        <button
                            type="button"
                            className="border border-gray-300 bg-white text-gray-600 rounded-lg px-4 py-2 text-sm hover:bg-gray-50"
                            onClick={() => setIsYearGroupModalOpen(false)}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="rounded-lg bg-[#185FA5] px-4 py-2 text-sm font-medium text-white hover:bg-[#0f4a82]"
                            onClick={handleAddYearGroup}
                        >
                            Save year group
                        </button>
                    </div>
                }
            >
                <div className="space-y-4">
                    <label className="block text-sm font-medium text-gray-700">Year group name</label>
                    <input
                        type="text"
                        value={yearGroupName}
                        onChange={(event) => setYearGroupName(event.target.value)}
                        className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#185FA5] focus:border-transparent"
                        placeholder="Year 13"
                    />
                </div>
            </Modal>

            <Modal
                isOpen={isArmModalOpen}
                onClose={() => {
                    setIsArmModalOpen(false)
                    setActiveArm(null)
                }}
                title={activeArm ? 'Edit class arm' : 'Add class arm'}
                footer={
                    <div className="flex justify-end gap-3">
                        <button
                            type="button"
                            className="border border-gray-300 bg-white text-gray-600 rounded-lg px-4 py-2 text-sm hover:bg-gray-50"
                            onClick={() => {
                                setIsArmModalOpen(false)
                                setActiveArm(null)
                            }}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="rounded-lg bg-[#185FA5] px-4 py-2 text-sm font-medium text-white hover:bg-[#0f4a82]"
                            onClick={handleSaveArm}
                        >
                            Save arm
                        </button>
                    </div>
                }
            >
                <div className="space-y-4">
                    <label className="block text-sm font-medium text-gray-700">Arm name</label>
                    <input
                        type="text"
                        value={newArmName}
                        onChange={(event) => setNewArmName(event.target.value)}
                        className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#185FA5] focus:border-transparent"
                        placeholder="7C"
                    />
                    <label className="block text-sm font-medium text-gray-700">Curriculum type</label>
                    <select
                        value={newArmType}
                        onChange={(event) => setNewArmType(event.target.value as ClassArm['type'])}
                        className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#185FA5] focus:border-transparent"
                    >
                        <option value="IGCSE">IGCSE</option>
                        <option value="WAEC">WAEC</option>
                        <option value="IFY">IFY</option>
                        <option value="Standard">Standard</option>
                    </select>
                </div>
            </Modal>
        </div>
    )
}
