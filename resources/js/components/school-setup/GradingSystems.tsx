import { useState } from 'react'
import { Save } from 'lucide-react'
import Toast from '@/components/ui/Toast'

interface GradeBand {
    id: number
    min: number
    max: number
    grade: string
    label: string
    gp: number
}

interface GradingSystem {
    id: number
    name: string
    type: 'igcse' | 'waec' | 'primary' | 'ify'
    applicable_to: string[]
    bands: GradeBand[]
}

const initialSystems: GradingSystem[] = [
    {
        id: 1,
        name: 'Secondary IGCSE',
        type: 'igcse',
        applicable_to: ['Year 7', 'Year 8', 'Year 9', 'Year 10 IGCSE', 'Year 11 IGCSE'],
        bands: [
            { id: 1, min: 91, max: 100, grade: 'A*', label: '', gp: 5.0 },
            { id: 2, min: 80, max: 90.9, grade: 'A', label: '', gp: 5.0 },
            { id: 3, min: 70, max: 79.9, grade: 'B', label: '', gp: 4.0 },
            { id: 4, min: 60, max: 69.9, grade: 'C', label: '', gp: 3.0 },
            { id: 5, min: 50, max: 59.9, grade: 'D', label: '', gp: 2.0 },
            { id: 6, min: 40, max: 49.9, grade: 'E', label: '', gp: 1.0 },
            { id: 7, min: 0, max: 39.9, grade: 'F', label: '', gp: 0.0 },
        ],
    },
    {
        id: 2,
        name: 'Secondary WAEC',
        type: 'waec',
        applicable_to: ['Year 10 WAEC', 'Year 11 WAEC', 'Year 12'],
        bands: [
            { id: 8, min: 75, max: 100, grade: 'A1', label: '', gp: 5.0 },
            { id: 9, min: 70, max: 74.9, grade: 'B2', label: '', gp: 4.5 },
            { id: 10, min: 65, max: 69.9, grade: 'B3', label: '', gp: 4.0 },
            { id: 11, min: 60, max: 64.9, grade: 'C4', label: '', gp: 3.5 },
            { id: 12, min: 55, max: 59.9, grade: 'C5', label: '', gp: 3.0 },
            { id: 13, min: 50, max: 54.9, grade: 'C6', label: '', gp: 2.5 },
            { id: 14, min: 45, max: 49.9, grade: 'D7', label: '', gp: 2.0 },
            { id: 15, min: 40, max: 44.9, grade: 'E8', label: '', gp: 1.0 },
            { id: 16, min: 0, max: 39.9, grade: 'F9', label: '', gp: 0.0 },
        ],
    },
    {
        id: 3,
        name: 'Primary',
        type: 'primary',
        applicable_to: ['Primary 1', 'Primary 2', 'Primary 3', 'Primary 4', 'Primary 5', 'Primary 6'],
        bands: [
            { id: 17, min: 90, max: 100, grade: 'Excellent', label: 'Excellent', gp: 5.0 },
            { id: 18, min: 80, max: 89, grade: 'Very Good', label: 'Very Good', gp: 4.0 },
            { id: 19, min: 70, max: 79, grade: 'Good', label: 'Good', gp: 3.0 },
            { id: 20, min: 60, max: 69, grade: 'Satisfactory', label: 'Satisfactory', gp: 2.0 },
            { id: 21, min: 50, max: 59, grade: 'Developing', label: 'Developing', gp: 1.0 },
            { id: 22, min: 30, max: 49, grade: 'Beginning', label: 'Beginning', gp: 0.5 },
            { id: 23, min: 0, max: 29, grade: 'Needs Support', label: 'Needs Support', gp: 0.0 },
        ],
    },
    {
        id: 4,
        name: 'IFY (NCUK)',
        type: 'ify',
        applicable_to: ['IFY Abuja', 'IFY PH'],
        bands: [
            { id: 24, min: 80, max: 100, grade: 'A*', label: 'A*', gp: 56 },
            { id: 25, min: 70, max: 79, grade: 'A', label: 'A', gp: 48 },
            { id: 26, min: 60, max: 69, grade: 'B', label: 'B', gp: 40 },
            { id: 27, min: 50, max: 59, grade: 'C', label: 'C', gp: 32 },
            { id: 28, min: 40, max: 49, grade: 'D', label: 'D', gp: 24 },
            { id: 29, min: 35, max: 39, grade: 'E', label: 'E', gp: 16 },
            { id: 30, min: 0, max: 34, grade: 'U', label: 'U', gp: 0 },
        ],
    },
]

const statusClasses: Record<GradingSystem['type'], string> = {
    igcse: 'bg-blue-50 text-blue-700',
    waec: 'bg-emerald-50 text-emerald-700',
    primary: 'bg-amber-50 text-amber-700',
    ify: 'bg-teal-50 text-teal-700',
}

export default function GradingSystems() {
    const [systems, setSystems] = useState<GradingSystem[]>(initialSystems)
    const [scoreInput, setScoreInput] = useState<Record<number, string>>({})
    const [toast, setToast] = useState<{ message: string; type: 'success' | 'info' | 'error' } | null>(null)
    const [isSaving, setIsSaving] = useState<Record<number, boolean>>({})

    const handleBandChange = (systemId: number, bandId: number, field: keyof GradeBand, value: string) => {
        setSystems((current) =>
            current.map((system) =>
                system.id !== systemId
                    ? system
                    : {
                          ...system,
                          bands: system.bands.map((band) =>
                              band.id !== bandId
                                  ? band
                                  : {
                                        ...band,
                                        [field]: field === 'grade' || field === 'label' ? value : Number(value),
                                    }
                          ),
                      }
            )
        )
    }

    const handleSave = (systemId: number) => {
        setIsSaving((current) => ({ ...current, [systemId]: true }))
        window.setTimeout(() => {
            setIsSaving((current) => ({ ...current, [systemId]: false }))
            setToast({ message: 'Grading system saved successfully.', type: 'success' })
        }, 600)
    }

    const getPreview = (system: GradingSystem) => {
        const value = Number(scoreInput[system.id])
        if (!value && value !== 0) {
            return null
        }

        return system.bands.find((band) => value >= band.min && value <= band.max) ?? null
    }

    return (
        <div className="space-y-8">
            <div className="rounded-2xl border border-gray-200 bg-white p-8 shadow-sm transition-shadow hover:shadow-md dark:border-slate-700 dark:bg-slate-900">
                <div className="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-xl font-semibold text-gray-900 dark:text-white">Grading systems</h1>
                        <p className="mt-2 text-sm text-gray-600 dark:text-slate-400">Edit band thresholds and preview the resulting grade for each system.</p>
                    </div>
                </div>
            </div>

            {systems.map((system) => {
                const preview = getPreview(system)
                return (
                    <div key={system.id} className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-shadow hover:shadow-md dark:border-slate-700 dark:bg-slate-900">
                        <div className="flex flex-col gap-6 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h2 className="text-lg font-medium text-gray-900 dark:text-white">{system.name}</h2>
                                <p className="mt-2 text-sm text-gray-500 dark:text-slate-400">Applied to: {system.applicable_to.join(', ')}</p>
                            </div>
                            <span className={`inline-flex rounded-full px-4 py-2 text-xs font-semibold ${statusClasses[system.type]}`}>
                                {system.type.toUpperCase()}
                            </span>
                        </div>

                        <div className="mt-8 overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-700">
                                <thead className="bg-gray-50 dark:bg-slate-800">
                                    <tr>
                                        <th className="px-6 py-4 text-left font-semibold text-gray-700 dark:text-slate-300">Min Score</th>
                                        <th className="px-6 py-4 text-left font-semibold text-gray-700 dark:text-slate-300">Max Score</th>
                                        <th className="px-6 py-4 text-left font-semibold text-gray-700 dark:text-slate-300">Grade</th>
                                        <th className="px-6 py-4 text-left font-semibold text-gray-700 dark:text-slate-300">Label</th>
                                        <th className="px-6 py-4 text-left font-semibold text-gray-700 dark:text-slate-300">GP</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white dark:divide-slate-700 dark:bg-slate-900">
                                    {system.bands.map((band) => (
                                        <tr key={band.id} className="hover:bg-gray-50 transition-colors dark:hover:bg-slate-800/50">
                                            <td className="px-6 py-4">
                                                <input
                                                    type="number"
                                                    value={band.min}
                                                    onChange={(event) => handleBandChange(system.id, band.id, 'min', event.target.value)}
                                                    className="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm transition-all focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 dark:border-slate-600 dark:bg-slate-800 dark:text-white"
                                                />
                                            </td>
                                            <td className="px-6 py-4">
                                                <input
                                                    type="number"
                                                    value={band.max}
                                                    onChange={(event) => handleBandChange(system.id, band.id, 'max', event.target.value)}
                                                    className="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm transition-all focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 dark:border-slate-600 dark:bg-slate-800 dark:text-white"
                                                />
                                            </td>
                                            <td className="px-6 py-4">
                                                <input
                                                    type="text"
                                                    value={band.grade}
                                                    onChange={(event) => handleBandChange(system.id, band.id, 'grade', event.target.value)}
                                                    className="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm transition-all focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 dark:border-slate-600 dark:bg-slate-800 dark:text-white"
                                                />
                                            </td>
                                            <td className="px-6 py-4">
                                                <input
                                                    type="text"
                                                    value={band.label}
                                                    onChange={(event) => handleBandChange(system.id, band.id, 'label', event.target.value)}
                                                    className="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm transition-all focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 dark:border-slate-600 dark:bg-slate-800 dark:text-white"
                                                />
                                            </td>
                                            <td className="px-6 py-4">
                                                <input
                                                    type="number"
                                                    step="0.1"
                                                    value={band.gp}
                                                    onChange={(event) => handleBandChange(system.id, band.id, 'gp', event.target.value)}
                                                    className="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm transition-all focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 dark:border-slate-600 dark:bg-slate-800 dark:text-white"
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="mt-8 flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                                <label className="text-sm font-medium text-gray-700 dark:text-slate-300">Test a score:</label>
                                <input
                                    type="number"
                                    value={scoreInput[system.id] ?? ''}
                                    onChange={(event) => setScoreInput((current) => ({ ...current, [system.id]: event.target.value }))}
                                    className="w-full max-w-xs rounded-lg border border-gray-300 px-4 py-2.5 text-sm transition-all focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                                    placeholder="Enter score"
                                />
                                {preview ? (
                                    <div className="rounded-xl bg-emerald-50 px-5 py-3 text-sm text-emerald-700 border border-emerald-200">
                                        Grade: <span className="font-semibold">{preview.grade}</span> · GP: <span className="font-semibold">{preview.gp}</span>
                                    </div>
                                ) : (
                                    <div className="rounded-xl bg-gray-50 px-5 py-3 text-sm text-gray-500 border border-gray-200 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400">
                                        Enter a score to preview grade.
                                    </div>
                                )}
                            </div>
                            <button
                                type="button"
                                onClick={() => handleSave(system.id)}
                                disabled={isSaving[system.id]}
                                className="inline-flex items-center gap-2 rounded-lg bg-primary px-5 py-3 text-sm font-medium text-white shadow-sm transition-all hover:bg-primary/90 hover:shadow-md disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <Save className="h-4 w-4" />
                                {isSaving[system.id] ? 'Saving...' : 'Save changes'}
                            </button>
                        </div>
                    </div>
                )
            })}

            {toast ? <Toast message={toast.message} type={toast.type} onDismiss={() => setToast(null)} /> : null}
        </div>
    )
}
