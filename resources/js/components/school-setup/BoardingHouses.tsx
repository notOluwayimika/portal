import { useMemo, useState } from 'react'
import { Edit3, Plus, Trash2, Users, UserCheck } from 'lucide-react'
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
    const [activeTab, setActiveTab] = useState<'boys' | 'girls'>('boys')
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
        setGender(activeTab === 'boys' ? 'Boys' : 'Girls')
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

    const currentHouses = activeTab === 'boys' ? grouped.boys : grouped.girls
    const currentGender = activeTab === 'boys' ? 'Boys' : 'Girls'

    return (
        <div className="space-y-8">
            {/* Header */}
            <div className="flex flex-col gap-6 rounded-2xl border border-gray-200 bg-white p-8 shadow-sm transition-shadow hover:shadow-md sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-xl font-semibold text-gray-900">Boarding houses</h1>
                    <p className="mt-2 text-sm text-gray-600">Manage boarding houses and assign year groups for each house.</p>
                </div>
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                    <div className="rounded-full border border-gray-200 bg-gray-50 px-5 py-3 text-sm text-gray-700">
                        {counts.total} houses · {counts.boys} boys · {counts.girls} girls
                    </div>
                    <button
                        type="button"
                        onClick={openAddHouse}
                        className="inline-flex items-center gap-2 rounded-lg bg-[#185FA5] px-5 py-3 text-sm font-medium text-white shadow-sm transition-all hover:bg-[#0f4a82] hover:shadow-md"
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
                <div className="rounded-2xl border border-gray-200 bg-white shadow-sm">
                    {/* Tab Navigation */}
                    <div className="border-b border-gray-200">
                        <div className="flex">
                            <button
                                type="button"
                                onClick={() => setActiveTab('boys')}
                                className={`flex items-center gap-3 px-8 py-5 text-sm font-medium transition-all ${
                                    activeTab === 'boys'
                                        ? 'border-b-2 border-[#185FA5] text-[#185FA5] bg-blue-50/50'
                                        : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50'
                                }`}
                            >
                                <Users className="h-5 w-5" />
                                Boys' Houses ({counts.boys})
                            </button>
                            <button
                                type="button"
                                onClick={() => setActiveTab('girls')}
                                className={`flex items-center gap-3 px-8 py-5 text-sm font-medium transition-all ${
                                    activeTab === 'girls'
                                        ? 'border-b-2 border-[#185FA5] text-[#185FA5] bg-pink-50/50'
                                        : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50'
                                }`}
                            >
                                <UserCheck className="h-5 w-5" />
                                Girls' Houses ({counts.girls})
                            </button>
                        </div>
                    </div>

                    {/* Table Content */}
                    <div className="p-6">
                        {currentHouses.length === 0 ? (
                            <div className="text-center py-12">
                                <div className="mx-auto h-12 w-12 text-gray-400">
                                    <Users className="h-12 w-12" />
                                </div>
                                <h3 className="mt-4 text-sm font-medium text-gray-900">No {currentGender.toLowerCase()} houses</h3>
                                <p className="mt-1 text-sm text-gray-500">Get started by adding a {currentGender.toLowerCase()} house.</p>
                                <div className="mt-6">
                                    <button
                                        type="button"
                                        onClick={openAddHouse}
                                        className="inline-flex items-center gap-2 rounded-lg bg-[#185FA5] px-4 py-2 text-sm font-medium text-white shadow-sm transition-all hover:bg-[#0f4a82] hover:shadow-md"
                                    >
                                        <Plus className="h-4 w-4" />
                                        Add {currentGender.toLowerCase()} house
                                    </button>
                                </div>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                                House Name
                                            </th>
                                            <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                                Year Groups
                                            </th>
                                            <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                                Gender
                                            </th>
                                            <th className="px-6 py-4 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {currentHouses.map((house) => (
                                            <tr key={house.id} className="hover:bg-gray-50 transition-colors">
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <div className="text-sm font-medium text-gray-900">{house.name}</div>
                                                </td>
                                                <td className="px-6 py-4">
                                                    <div className="flex flex-wrap gap-2">
                                                        {house.year_groups.map((group) => (
                                                            <span
                                                                key={group}
                                                                className="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800"
                                                            >
                                                                {group}
                                                            </span>
                                                        ))}
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <span className={`inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold ${
                                                        house.gender === 'Boys'
                                                            ? 'bg-blue-100 text-blue-800'
                                                            : 'bg-pink-100 text-pink-800'
                                                    }`}>
                                                        {house.gender}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                    <div className="flex items-center justify-end gap-3">
                                                        <button
                                                            type="button"
                                                            onClick={() => openEditHouse(house)}
                                                            className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-600 transition-all hover:bg-gray-50 hover:border-gray-400 hover:shadow-sm"
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
                                                            className="inline-flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700 transition-all hover:bg-red-100 hover:border-red-300 hover:shadow-sm"
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                            Delete
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
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
                            className="rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 transition-all hover:bg-gray-50 hover:shadow-sm"
                            onClick={() => setIsModalOpen(false)}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="rounded-lg bg-[#185FA5] px-5 py-2.5 text-sm font-medium text-white shadow-sm transition-all hover:bg-[#0f4a82] hover:shadow-md"
                            onClick={handleSaveHouse}
                        >
                            Save house
                        </button>
                    </div>
                }
            >
                <div className="space-y-6">
                    <div>
                        <label className="block text-sm font-medium text-gray-700">House name</label>
                        <input
                            type="text"
                            value={houseName}
                            onChange={(event) => setHouseName(event.target.value)}
                            className="mt-2 w-full rounded-lg border border-gray-300 px-4 py-3 text-sm transition-all focus:border-[#185FA5] focus:outline-none focus:ring-2 focus:ring-[#185FA5]/20"
                            placeholder="Phoenix"
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">Gender</label>
                        <select
                            value={gender}
                            onChange={(event) => setGender(event.target.value as BoardingHouse['gender'])}
                            className="mt-2 w-full rounded-lg border border-gray-300 bg-white px-4 py-3 text-sm transition-all focus:border-[#185FA5] focus:outline-none focus:ring-2 focus:ring-[#185FA5]/20"
                        >
                            <option value="Boys">Boys</option>
                            <option value="Girls">Girls</option>
                        </select>
                    </div>
                    <div>
                        <p className="block text-sm font-medium text-gray-700">Year groups</p>
                        <div className="mt-4 grid gap-3 sm:grid-cols-2">
                            {yearGroups.map((group) => (
                                <label
                                    key={group}
                                    className={`flex cursor-pointer items-center gap-4 rounded-lg border px-4 py-3 text-sm transition-all ${selectedYearGroups.includes(group) ? 'border-[#185FA5] bg-[#def1ff] text-[#185FA5]' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50 hover:border-gray-400'}`}
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
