import { useMemo, useState } from 'react'
import { Edit3, Plus, Trash2 } from 'lucide-react'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import EmptyState from '@/components/ui/EmptyState'
import Modal from '@/components/ui/Modal'

interface BoardingHouse {
    id: number
    name: string
    gender: 'Boys' | 'Girls'
    year_groups: string[]
}

const initialHouses: BoardingHouse[] = [
    { id: 1, name: 'Phoenix', gender: 'Boys', year_groups: ['Year 7', 'Year 8', 'Year 9'] },
    { id: 2, name: 'Iris', gender: 'Girls', year_groups: ['Year 7', 'Year 8', 'Year 9'] },
    { id: 3, name: 'Atlas', gender: 'Boys', year_groups: ['Year 10', 'Year 11'] },
    { id: 4, name: 'Lotus', gender: 'Girls', year_groups: ['Year 10', 'Year 11'] },
    { id: 5, name: 'Zenith', gender: 'Boys', year_groups: ['Year 12'] },
    { id: 6, name: 'Zenith', gender: 'Girls', year_groups: ['Year 12'] },
    { id: 7, name: 'Summit', gender: 'Boys', year_groups: ['IFY Year 1'] },
    { id: 8, name: 'Aurora', gender: 'Girls', year_groups: ['IFY Year 1'] },
]

const yearGroups = [
    'Year 7',
    'Year 8',
    'Year 9',
    'Year 10',
    'Year 11',
    'Year 12',
    'IFY Year 1',
]

export default function BoardingHouses() {
    const [houses, setHouses] = useState<BoardingHouse[]>(initialHouses)
    const [isModalOpen, setIsModalOpen] = useState(false)
    const [editingHouse, setEditingHouse] = useState<BoardingHouse | null>(null)
    const [houseName, setHouseName] = useState('')
    const [gender, setGender] = useState<BoardingHouse['gender']>('Boys')
    const [selectedYearGroups, setSelectedYearGroups] = useState<string[]>([])
    const [isConfirmOpen, setIsConfirmOpen] = useState(false)
    const [deleteTarget, setDeleteTarget] = useState<BoardingHouse | null>(null)

    const counts = useMemo(() => {
        const boys = houses.filter((house) => house.gender === 'Boys').length
        const girls = houses.filter((house) => house.gender === 'Girls').length
        return { total: houses.length, boys, girls }
    }, [houses])

    const openAddHouse = () => {
        setEditingHouse(null)
        setHouseName('')
        setGender('Boys')
        setSelectedYearGroups([])
        setIsModalOpen(true)
    }

    const openEditHouse = (house: BoardingHouse) => {
        setEditingHouse(house)
        setHouseName(house.name)
        setGender(house.gender)
        setSelectedYearGroups(house.year_groups)
        setIsModalOpen(true)
    }

    const handleSaveHouse = () => {
        if (!houseName.trim() || selectedYearGroups.length === 0) return

        const normalized = { id: editingHouse?.id ?? Date.now(), name: houseName.trim(), gender, year_groups: selectedYearGroups }

        if (editingHouse) {
            setHouses((current) => current.map((house) => (house.id === editingHouse.id ? normalized : house)))
        } else {
            setHouses((current) => [...current, normalized])
        }

        setIsModalOpen(false)
    }

    const handleDeleteHouse = () => {
        if (!deleteTarget) return
        setHouses((current) => current.filter((house) => house.id !== deleteTarget.id))
        setDeleteTarget(null)
        setIsConfirmOpen(false)
    }

    const toggleYearGroup = (group: string) => {
        setSelectedYearGroups((current) =>
            current.includes(group) ? current.filter((item) => item !== group) : [...current, group]
        )
    }

    const grouped = useMemo(
        () => ({
            boys: houses.filter((house) => house.gender === 'Boys'),
            girls: houses.filter((house) => house.gender === 'Girls'),
        }),
        [houses]
    )

    return (
        <div className="space-y-6">
            <div className="flex flex-col gap-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-xl font-semibold text-gray-900">Boarding houses</h1>
                    <p className="text-sm text-gray-600">Manage boarding houses and assign year groups for each house.</p>
                </div>
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <div className="rounded-full border border-gray-200 bg-gray-50 px-4 py-2 text-sm text-gray-700">
                        {counts.total} houses · {counts.boys} boys · {counts.girls} girls
                    </div>
                    <button
                        type="button"
                        onClick={openAddHouse}
                        className="inline-flex items-center gap-2 rounded-lg bg-[#185FA5] px-4 py-2 text-sm font-medium text-white hover:bg-[#0f4a82]"
                    >
                        <Plus className="h-4 w-4" />
                        Add house
                    </button>
                </div>
            </div>

            {houses.length === 0 ? (
                <EmptyState
                    icon={<Plus className="h-8 w-8" />}
                    title="No boarding houses"
                    description="Create boarding houses and assign year groups to each house."
                    actionLabel="Add house"
                    onAction={openAddHouse}
                />
            ) : (
                <div className="grid gap-6 lg:grid-cols-2">
                    <div className="space-y-4">
                        <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <h2 className="text-base font-semibold text-gray-900">Boys' Houses</h2>
                        </div>
                        {grouped.boys.map((house) => (
                            <div key={house.id} className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                                <div className="flex items-start justify-between gap-4">
                                    <div>
                                        <p className="text-lg font-semibold text-gray-900">{house.name}</p>
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            {house.year_groups.map((group) => (
                                                <span key={group} className="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700">
                                                    {group}
                                                </span>
                                            ))}
                                        </div>
                                    </div>
                                    <div className="space-y-2 text-right">
                                        <span className="inline-flex rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">Boys</span>
                                        <div className="flex gap-2">
                                            <button
                                                type="button"
                                                onClick={() => openEditHouse(house)}
                                                className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-600 hover:bg-gray-50"
                                            >
                                                <Edit3 className="h-4 w-4" />
                                                Edit
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setDeleteTarget(house)
                                                    setIsConfirmOpen(true)
                                                }}
                                                className="inline-flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 hover:bg-red-100"
                                            >
                                                <Trash2 className="h-4 w-4" />
                                                Delete
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                    <div className="space-y-4">
                        <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                            <h2 className="text-base font-semibold text-gray-900">Girls' Houses</h2>
                        </div>
                        {grouped.girls.map((house) => (
                            <div key={house.id} className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                                <div className="flex items-start justify-between gap-4">
                                    <div>
                                        <p className="text-lg font-semibold text-gray-900">{house.name}</p>
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            {house.year_groups.map((group) => (
                                                <span key={group} className="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700">
                                                    {group}
                                                </span>
                                            ))}
                                        </div>
                                    </div>
                                    <div className="space-y-2 text-right">
                                        <span className="inline-flex rounded-full bg-pink-50 px-3 py-1 text-xs font-semibold text-pink-700">Girls</span>
                                        <div className="flex gap-2">
                                            <button
                                                type="button"
                                                onClick={() => openEditHouse(house)}
                                                className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-600 hover:bg-gray-50"
                                            >
                                                <Edit3 className="h-4 w-4" />
                                                Edit
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setDeleteTarget(house)
                                                    setIsConfirmOpen(true)
                                                }}
                                                className="inline-flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 hover:bg-red-100"
                                            >
                                                <Trash2 className="h-4 w-4" />
                                                Delete
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            <Modal
                isOpen={isModalOpen}
                onClose={() => setIsModalOpen(false)}
                title={editingHouse ? 'Edit house' : 'Add house'}
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
                            onClick={handleSaveHouse}
                        >
                            Save house
                        </button>
                    </div>
                }
            >
                <div className="space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700">House name</label>
                        <input
                            type="text"
                            value={houseName}
                            onChange={(event) => setHouseName(event.target.value)}
                            className="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#185FA5] focus:border-transparent"
                            placeholder="Phoenix"
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Gender</label>
                        <select
                            value={gender}
                            onChange={(event) => setGender(event.target.value as BoardingHouse['gender'])}
                            className="mt-2 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#185FA5] focus:border-transparent"
                        >
                            <option value="Boys">Boys</option>
                            <option value="Girls">Girls</option>
                        </select>
                    </div>
                    <div>
                        <p className="block text-sm font-medium text-gray-700">Year groups</p>
                        <div className="mt-3 grid gap-2 sm:grid-cols-2">
                            {yearGroups.map((group) => (
                                <label
                                    key={group}
                                    className={`flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm ${selectedYearGroups.includes(group) ? 'border-[#185FA5] bg-[#def1ff] text-[#185FA5]' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'}`}
                                >
                                    <input
                                        type="checkbox"
                                        checked={selectedYearGroups.includes(group)}
                                        onChange={() => toggleYearGroup(group)}
                                        className="h-4 w-4 rounded border-gray-300 text-[#185FA5] focus:ring-[#185FA5]"
                                    />
                                    {group}
                                </label>
                            ))}
                        </div>
                    </div>
                </div>
            </Modal>

            <ConfirmDialog
                isOpen={isConfirmOpen}
                onClose={() => setIsConfirmOpen(false)}
                onConfirm={handleDeleteHouse}
                title="Delete boarding house"
                message="This boarding house will be removed. Are you sure you want to continue?"
                confirmLabel="Delete"
                dangerous
            />
        </div>
    )
}
