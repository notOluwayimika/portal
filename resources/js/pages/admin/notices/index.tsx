import axios from 'axios';
import {
    AlertTriangle,
    Bell,
    Calendar,
    ChevronDown,
    ChevronUp,
    Eye,
    Pencil,
    Plus,
    Search,
    StopCircle,
    Trash2,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'react-toastify';
import { Pagination } from '@/components/pagination';
import { RichTextEditor } from '@/components/rich-text-editor';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';

// ---------- Types ----------

interface Category {
    id: string;
    name: string;
    slug: string;
    color: string;
    is_default: boolean;
}

interface NoticeTarget {
    id: string;
    name: string;
}

interface Notice {
    id: string;
    title: string;
    body: string;
    target_gender: string | null;
    starts_at: string;
    ends_at: string | null;
    is_active: boolean;
    created_at: string;
    category: Category;
    creator?: { id: number; name: string };
    class_levels: NoticeTarget[];
    class_level_arms: NoticeTarget[];
    students: NoticeTarget[];
}

interface ClassLevelOption {
    id: string;
    name: string;
}

interface ClassLevelArmOption {
    id: string;
    name: string;
    class_level?: { id: string; name: string };
}

const DEFAULT_PAGINATION = {
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
    prev_page_url: null,
    next_page_url: null,
};

const BADGE_COLORS: Record<string, string> = {
    red: 'bg-red-50 text-red-700 border-red-200',
    amber: 'bg-amber-50 text-amber-700 border-amber-200',
    green: 'bg-green-50 text-green-700 border-green-200',
    gray: 'bg-gray-50 text-gray-700 border-gray-200',
    blue: 'bg-blue-50 text-blue-700 border-blue-200',
    purple: 'bg-purple-50 text-purple-700 border-purple-200',
};

function badgeClass(color: string) {
    return BADGE_COLORS[color] || BADGE_COLORS.gray;
}

function formatDate(iso: string | null) {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function statusBadge(notice: Notice) {
    if (notice.is_active) {
        return (
            <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
                <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                Active
            </span>
        );
    }
    const now = new Date();
    if (new Date(notice.starts_at) > now) {
        return (
            <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700">
                <Calendar className="h-3 w-3" />
                Scheduled
            </span>
        );
    }
    return (
        <span className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-500">
            Ended
        </span>
    );
}

function targetSummary(notice: Notice) {
    const parts: string[] = [];
    if (notice.students.length) {
        parts.push(
            `${notice.students.length} student${notice.students.length > 1 ? 's' : ''}`,
        );
    }
    if (notice.class_level_arms.length) {
        parts.push(notice.class_level_arms.map((a) => a.name).join(', '));
    }
    if (notice.class_levels.length) {
        parts.push(notice.class_levels.map((c) => c.name).join(', '));
    }
    if (!parts.length) {
        parts.push('All students');
    }
    if (notice.target_gender) {
        parts.push(
            notice.target_gender.charAt(0).toUpperCase() +
                notice.target_gender.slice(1) +
                ' only',
        );
    }
    return parts.join(' · ');
}

// ---------- Main Page ----------

export default function NoticesIndex() {
    const [notices, setNotices] = useState<Notice[]>([]);
    const [loading, setLoading] = useState(true);
    const [pagination, setPagination] = useState(DEFAULT_PAGINATION);
    const [page, setPage] = useState(1);
    const [limit, setLimit] = useState(15);

    // Filters
    const [search, setSearch] = useState('');
    const [categoryFilter, setCategoryFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [genderFilter, setGenderFilter] = useState('');

    // Categories
    const [categories, setCategories] = useState<Category[]>([]);
    const [classLevels, setClassLevels] = useState<ClassLevelOption[]>([]);
    const [classLevelArms, setClassLevelArms] = useState<ClassLevelArmOption[]>([]);

    // Modal
    const [showModal, setShowModal] = useState(false);
    const [editingNotice, setEditingNotice] = useState<Notice | null>(null);
    const [viewingNotice, setViewingNotice] = useState<Notice | null>(null);

    // Category modal
    const [showCategoryModal, setShowCategoryModal] = useState(false);
    const [newCategoryName, setNewCategoryName] = useState('');
    const [newCategoryColor, setNewCategoryColor] = useState('gray');

    const searchTimeout = useRef<ReturnType<typeof setTimeout>>();

    useEffect(() => {
        fetchCategories();
        fetchClassStructure();
    }, []);

    useEffect(() => {
        fetchNotices();
    }, [page, limit, categoryFilter, statusFilter, genderFilter]);

    useEffect(() => {
        clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            setPage(1);
            fetchNotices();
        }, 400);
        return () => clearTimeout(searchTimeout.current);
    }, [search]);

    async function fetchNotices() {
        setLoading(true);
        try {
            const res = await axios.get('/api/notices', {
                params: {
                    page,
                    per_page: limit,
                    search: search || undefined,
                    category: categoryFilter || undefined,
                    status: statusFilter || undefined,
                    gender: genderFilter || undefined,
                },
            });
            setNotices(res.data.data ?? []);
            setPagination(res.data.pagination ?? DEFAULT_PAGINATION);
        } catch {
            toast.error('Failed to fetch notices');
        } finally {
            setLoading(false);
        }
    }

    async function fetchCategories() {
        try {
            const res = await axios.get('/api/notices/categories');
            setCategories(res.data.data ?? []);
        } catch {
            // silent
        }
    }

    async function fetchClassStructure() {
        try {
            const res = await axios.get('/api/class-structure');
            setClassLevels(res.data.class_levels ?? []);
            setClassLevelArms(res.data.class_level_arms ?? []);
        } catch {
            // silent
        }
    }

    async function handleDelete(notice: Notice) {
        if (!confirm(`Delete notice "${notice.title}"?`)) return;
        try {
            await axios.delete(`/api/notices/${notice.id}`);
            toast.success('Notice deleted');
            fetchNotices();
        } catch {
            toast.error('Failed to delete notice');
        }
    }

    async function handleEnd(notice: Notice) {
        if (!confirm(`End notice "${notice.title}"? It will no longer appear to guardians.`)) return;
        try {
            await axios.post(`/api/notices/${notice.id}/end`);
            toast.success('Notice ended');
            fetchNotices();
        } catch {
            toast.error('Failed to end notice');
        }
    }

    async function handleAddCategory() {
        if (!newCategoryName.trim()) return;
        try {
            await axios.post('/api/notices/categories', {
                name: newCategoryName.trim(),
                color: newCategoryColor,
            });
            toast.success('Category added');
            setNewCategoryName('');
            setNewCategoryColor('gray');
            setShowCategoryModal(false);
            fetchCategories();
        } catch (err: any) {
            toast.error(
                err.response?.data?.message || 'Failed to add category',
            );
        }
    }

    async function handleDeleteCategory(cat: Category) {
        if (!confirm(`Delete category "${cat.name}"?`)) return;
        try {
            await axios.delete(`/api/notices/categories/${cat.id}`);
            toast.success('Category deleted');
            fetchCategories();
        } catch (err: any) {
            toast.error(
                err.response?.data?.message || 'Failed to delete category',
            );
        }
    }

    return (
        <div className="space-y-6 p-4">
            {/* Header */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-lg font-semibold text-gray-900">
                        Notices
                    </h1>
                    <p className="text-sm text-gray-500">
                        Create and manage notices for guardians and students.
                    </p>
                </div>
                <div className="flex gap-2">
                    <Button
                        variant="outline"
                        onClick={() => setShowCategoryModal(true)}
                    >
                        Categories
                    </Button>
                    <Button
                        onClick={() => {
                            setEditingNotice(null);
                            setShowModal(true);
                        }}
                    >
                        <Plus className="mr-1.5 h-4 w-4" />
                        New Notice
                    </Button>
                </div>
            </div>

            {/* Filters */}
            <div className="flex flex-wrap items-center gap-3">
                <div className="relative flex-1 sm:max-w-xs">
                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-gray-400" />
                    <input
                        type="text"
                        placeholder="Search by title..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="w-full rounded-lg border border-gray-300 py-2 pr-3 pl-9 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                    />
                </div>

                <select
                    value={categoryFilter}
                    onChange={(e) => {
                        setCategoryFilter(e.target.value);
                        setPage(1);
                    }}
                    className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                >
                    <option value="">All categories</option>
                    {categories.map((c) => (
                        <option key={c.id} value={c.id}>
                            {c.name}
                        </option>
                    ))}
                </select>

                <select
                    value={statusFilter}
                    onChange={(e) => {
                        setStatusFilter(e.target.value);
                        setPage(1);
                    }}
                    className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                >
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="scheduled">Scheduled</option>
                    <option value="ended">Ended</option>
                </select>

                <select
                    value={genderFilter}
                    onChange={(e) => {
                        setGenderFilter(e.target.value);
                        setPage(1);
                    }}
                    className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                >
                    <option value="">All genders</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                </select>
            </div>

            {/* Table */}
            {loading ? (
                <div className="space-y-3">
                    {[1, 2, 3, 4, 5].map((i) => (
                        <Skeleton key={i} className="h-16 rounded-lg" />
                    ))}
                </div>
            ) : notices.length === 0 ? (
                <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-gray-300 bg-white py-16">
                    <Bell className="mb-3 h-10 w-10 text-gray-300" />
                    <p className="text-sm font-medium text-gray-700">
                        No notices found
                    </p>
                    <p className="mt-1 text-xs text-gray-400">
                        Create your first notice to get started.
                    </p>
                </div>
            ) : (
                <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-gray-100 bg-gray-50/50">
                                    <th className="px-4 py-3 text-left text-xs font-medium tracking-wide text-gray-500 uppercase">
                                        Title
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-medium tracking-wide text-gray-500 uppercase">
                                        Category
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-medium tracking-wide text-gray-500 uppercase">
                                        Target
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-medium tracking-wide text-gray-500 uppercase">
                                        Status
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-medium tracking-wide text-gray-500 uppercase">
                                        Start
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-medium tracking-wide text-gray-500 uppercase">
                                        End
                                    </th>
                                    <th className="px-4 py-3 text-right text-xs font-medium tracking-wide text-gray-500 uppercase">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {notices.map((notice) => (
                                    <tr
                                        key={notice.id}
                                        className="hover:bg-gray-50/50"
                                    >
                                        <td className="max-w-[250px] truncate px-4 py-3 font-medium text-gray-900">
                                            {notice.title}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${badgeClass(notice.category?.color)}`}
                                            >
                                                {notice.category?.name}
                                            </span>
                                        </td>
                                        <td className="max-w-[200px] truncate px-4 py-3 text-xs text-gray-500">
                                            {targetSummary(notice)}
                                        </td>
                                        <td className="px-4 py-3">
                                            {statusBadge(notice)}
                                        </td>
                                        <td className="px-4 py-3 text-xs text-gray-500">
                                            {formatDate(notice.starts_at)}
                                        </td>
                                        <td className="px-4 py-3 text-xs text-gray-500">
                                            {formatDate(notice.ends_at)}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center justify-end gap-1">
                                                <button
                                                    onClick={() =>
                                                        setViewingNotice(notice)
                                                    }
                                                    className="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                                                    title="View"
                                                >
                                                    <Eye className="h-4 w-4" />
                                                </button>
                                                <button
                                                    onClick={() => {
                                                        setEditingNotice(
                                                            notice,
                                                        );
                                                        setShowModal(true);
                                                    }}
                                                    className="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                                                    title="Edit"
                                                >
                                                    <Pencil className="h-4 w-4" />
                                                </button>
                                                {notice.is_active && (
                                                    <button
                                                        onClick={() =>
                                                            handleEnd(notice)
                                                        }
                                                        className="rounded p-1.5 text-amber-400 hover:bg-amber-50 hover:text-amber-600"
                                                        title="End notice"
                                                    >
                                                        <StopCircle className="h-4 w-4" />
                                                    </button>
                                                )}
                                                <button
                                                    onClick={() =>
                                                        handleDelete(notice)
                                                    }
                                                    className="rounded p-1.5 text-gray-400 hover:bg-red-50 hover:text-red-600"
                                                    title="Delete"
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {pagination.last_page > 1 && (
                        <Pagination
                            meta={pagination}
                            setPage={setPage}
                            setLimit={(l) => {
                                setLimit(l);
                                setPage(1);
                            }}
                        />
                    )}
                </div>
            )}

            {/* View modal */}
            {viewingNotice && (
                <ViewNoticeModal
                    notice={viewingNotice}
                    onClose={() => setViewingNotice(null)}
                />
            )}

            {/* Create/Edit modal */}
            {showModal && (
                <NoticeFormModal
                    notice={editingNotice}
                    categories={categories}
                    classLevels={classLevels}
                    classLevelArms={classLevelArms}
                    onClose={() => {
                        setShowModal(false);
                        setEditingNotice(null);
                    }}
                    onSaved={() => {
                        setShowModal(false);
                        setEditingNotice(null);
                        fetchNotices();
                    }}
                />
            )}

            {/* Category management modal */}
            {showCategoryModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="text-lg font-semibold text-gray-900">
                                Manage Categories
                            </h2>
                            <button
                                onClick={() => setShowCategoryModal(false)}
                                className="rounded p-1 text-gray-400 hover:bg-gray-100"
                            >
                                <X className="h-5 w-5" />
                            </button>
                        </div>

                        <div className="mb-4 space-y-2">
                            {categories.map((cat) => (
                                <div
                                    key={cat.id}
                                    className="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2"
                                >
                                    <div className="flex items-center gap-2">
                                        <span
                                            className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${badgeClass(cat.color)}`}
                                        >
                                            {cat.name}
                                        </span>
                                        {cat.is_default && (
                                            <span className="text-[10px] text-gray-400">
                                                Default
                                            </span>
                                        )}
                                    </div>
                                    {!cat.is_default && (
                                        <button
                                            onClick={() =>
                                                handleDeleteCategory(cat)
                                            }
                                            className="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600"
                                        >
                                            <Trash2 className="h-3.5 w-3.5" />
                                        </button>
                                    )}
                                </div>
                            ))}
                        </div>

                        <div className="border-t border-gray-100 pt-4">
                            <p className="mb-2 text-xs font-medium text-gray-500">
                                Add new category
                            </p>
                            <div className="flex gap-2">
                                <input
                                    type="text"
                                    placeholder="Category name"
                                    value={newCategoryName}
                                    onChange={(e) =>
                                        setNewCategoryName(e.target.value)
                                    }
                                    className="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                                />
                                <select
                                    value={newCategoryColor}
                                    onChange={(e) =>
                                        setNewCategoryColor(e.target.value)
                                    }
                                    className="rounded-lg border border-gray-300 px-2 py-2 text-sm"
                                >
                                    {Object.keys(BADGE_COLORS).map((c) => (
                                        <option key={c} value={c}>
                                            {c}
                                        </option>
                                    ))}
                                </select>
                                <Button onClick={handleAddCategory} size="sm">
                                    Add
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

NoticesIndex.layout = {
    breadcrumbs: [{ title: 'Notices', href: '/notices' }],
};

// ---------- View Modal ----------

function ViewNoticeModal({
    notice,
    onClose,
}: {
    notice: Notice;
    onClose: () => void;
}) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-xl">
                <div className="mb-4 flex items-start justify-between">
                    <div>
                        <h2 className="text-lg font-semibold text-gray-900">
                            {notice.title}
                        </h2>
                        <div className="mt-1 flex items-center gap-2">
                            <span
                                className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${badgeClass(notice.category?.color)}`}
                            >
                                {notice.category?.name}
                            </span>
                            {statusBadge(notice)}
                        </div>
                    </div>
                    <button
                        onClick={onClose}
                        className="rounded p-1 text-gray-400 hover:bg-gray-100"
                    >
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <div className="mb-4 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <span className="text-xs text-gray-400">
                            Start date
                        </span>
                        <p className="font-medium text-gray-700">
                            {formatDate(notice.starts_at)}
                        </p>
                    </div>
                    <div>
                        <span className="text-xs text-gray-400">End date</span>
                        <p className="font-medium text-gray-700">
                            {formatDate(notice.ends_at)}
                        </p>
                    </div>
                    <div>
                        <span className="text-xs text-gray-400">Target</span>
                        <p className="font-medium text-gray-700">
                            {targetSummary(notice)}
                        </p>
                    </div>
                    <div>
                        <span className="text-xs text-gray-400">
                            Created by
                        </span>
                        <p className="font-medium text-gray-700">
                            {notice.creator?.name ?? '—'}
                        </p>
                    </div>
                </div>

                <div className="border-t border-gray-100 pt-4">
                    <div
                        className="prose prose-sm max-w-none text-gray-700"
                        dangerouslySetInnerHTML={{ __html: notice.body }}
                    />
                </div>
            </div>
        </div>
    );
}

// ---------- Form Modal ----------

function NoticeFormModal({
    notice,
    categories,
    classLevels,
    classLevelArms: allClassLevelArms,
    onClose,
    onSaved,
}: {
    notice: Notice | null;
    categories: Category[];
    classLevels: ClassLevelOption[];
    classLevelArms: ClassLevelArmOption[];
    onClose: () => void;
    onSaved: () => void;
}) {
    const isEdit = !!notice;

    const [title, setTitle] = useState(notice?.title ?? '');
    const [body, setBody] = useState(notice?.body ?? '');
    const [categoryId, setCategoryId] = useState(notice?.category?.id ?? '');
    const [targetGender, setTargetGender] = useState(
        notice?.target_gender ?? '',
    );
    const [startsAt, setStartsAt] = useState(
        notice?.starts_at
            ? new Date(notice.starts_at).toISOString().slice(0, 16)
            : new Date().toISOString().slice(0, 16),
    );
    const [endsAt, setEndsAt] = useState(
        notice?.ends_at
            ? new Date(notice.ends_at).toISOString().slice(0, 16)
            : '',
    );
    const [selectedClassLevels, setSelectedClassLevels] = useState<string[]>(
        notice?.class_levels?.map((c) => c.id) ?? [],
    );
    const [selectedArms, setSelectedArms] = useState<string[]>(
        notice?.class_level_arms?.map((a) => a.id) ?? [],
    );
    const [studentSearch, setStudentSearch] = useState('');
    const [studentResults, setStudentResults] = useState<
        { id: string; name: string; admission_number: string }[]
    >([]);
    const [selectedStudents, setSelectedStudents] = useState<
        { id: string; name: string }[]
    >(notice?.students ?? []);

    const [saving, setSaving] = useState(false);
    const [showTargeting, setShowTargeting] = useState(
        !!(
            notice?.class_levels?.length ||
            notice?.class_level_arms?.length ||
            notice?.students?.length ||
            notice?.target_gender
        ),
    );

    const studentSearchTimeout = useRef<ReturnType<typeof setTimeout>>();

    useEffect(() => {
        if (!studentSearch.trim()) {
            setStudentResults([]);
            return;
        }
        clearTimeout(studentSearchTimeout.current);
        studentSearchTimeout.current = setTimeout(async () => {
            try {
                const res = await axios.get('/api/students', {
                    params: { search: studentSearch, per_page: 10 },
                });
                setStudentResults(
                    (res.data.data ?? []).map((s: any) => ({
                        id: s.id,
                        name: `${s.first_name} ${s.last_name}`,
                        admission_number: s.admission_number,
                    })),
                );
            } catch {
                // silent
            }
        }, 400);
        return () => clearTimeout(studentSearchTimeout.current);
    }, [studentSearch]);

    const filteredArms = selectedClassLevels.length
        ? allClassLevelArms.filter(
              (a) =>
                  a.class_level &&
                  selectedClassLevels.includes(a.class_level.id),
          )
        : allClassLevelArms;

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        if (!title.trim() || !body.trim() || !categoryId) {
            toast.error('Please fill in all required fields');
            return;
        }

        setSaving(true);
        try {
            const payload = {
                title: title.trim(),
                body,
                category_id: categoryId,
                target_gender: targetGender || null,
                starts_at: startsAt,
                ends_at: endsAt || null,
                class_level_ids: selectedClassLevels,
                class_level_arm_ids: selectedArms,
                student_ids: selectedStudents.map((s) => s.id),
            };

            if (isEdit) {
                await axios.put(`/api/notices/${notice!.id}`, payload);
                toast.success('Notice updated');
            } else {
                await axios.post('/api/notices', payload);
                toast.success('Notice created');
            }
            onSaved();
        } catch (err: any) {
            const msg =
                err.response?.data?.message || 'Failed to save notice';
            toast.error(msg);
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div className="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-2xl bg-white shadow-xl">
                <form onSubmit={handleSubmit}>
                    <div className="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                        <h2 className="text-lg font-semibold text-gray-900">
                            {isEdit ? 'Edit Notice' : 'New Notice'}
                        </h2>
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded p-1 text-gray-400 hover:bg-gray-100"
                        >
                            <X className="h-5 w-5" />
                        </button>
                    </div>

                    <div className="space-y-4 px-6 py-4">
                        {/* Title */}
                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">
                                Title <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                value={title}
                                onChange={(e) => setTitle(e.target.value)}
                                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                                placeholder="Notice title"
                            />
                        </div>

                        {/* Category */}
                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">
                                Category{' '}
                                <span className="text-red-500">*</span>
                            </label>
                            <select
                                value={categoryId}
                                onChange={(e) => setCategoryId(e.target.value)}
                                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                            >
                                <option value="">Select category</option>
                                {categories.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {/* Body (Rich text) */}
                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">
                                Content{' '}
                                <span className="text-red-500">*</span>
                            </label>
                            <RichTextEditor
                                content={body}
                                onChange={setBody}
                            />
                        </div>

                        {/* Dates */}
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label className="mb-1 block text-sm font-medium text-gray-700">
                                    Start date{' '}
                                    <span className="text-red-500">*</span>
                                </label>
                                <input
                                    type="datetime-local"
                                    value={startsAt}
                                    onChange={(e) => setStartsAt(e.target.value)}
                                    className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                                />
                            </div>
                            <div>
                                <label className="mb-1 block text-sm font-medium text-gray-700">
                                    End date{' '}
                                    <span className="text-xs text-gray-400">
                                        (optional)
                                    </span>
                                </label>
                                <input
                                    type="datetime-local"
                                    value={endsAt}
                                    onChange={(e) => setEndsAt(e.target.value)}
                                    className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                                />
                                <p className="mt-1 text-xs text-gray-400">
                                    Leave empty to show indefinitely
                                </p>
                            </div>
                        </div>

                        {/* Targeting toggle */}
                        <div>
                            <button
                                type="button"
                                onClick={() => setShowTargeting(!showTargeting)}
                                className="flex items-center gap-2 text-sm font-medium text-indigo-600 hover:text-indigo-700"
                            >
                                {showTargeting ? (
                                    <ChevronUp className="h-4 w-4" />
                                ) : (
                                    <ChevronDown className="h-4 w-4" />
                                )}
                                {showTargeting
                                    ? 'Hide targeting options'
                                    : 'Target specific students'}
                            </button>
                            <p className="mt-0.5 text-xs text-gray-400">
                                By default, notices are shown to all students. Use targeting to narrow the audience.
                            </p>
                        </div>

                        {showTargeting && (
                            <div className="space-y-4 rounded-lg border border-gray-200 bg-gray-50/50 p-4">
                                {/* Gender */}
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-gray-700">
                                        Gender
                                    </label>
                                    <select
                                        value={targetGender}
                                        onChange={(e) =>
                                            setTargetGender(e.target.value)
                                        }
                                        className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                                    >
                                        <option value="">All genders</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                    </select>
                                </div>

                                {/* Class levels */}
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-gray-700">
                                        Class levels
                                    </label>
                                    <div className="flex flex-wrap gap-2">
                                        {classLevels.map((cl) => (
                                            <label
                                                key={cl.id}
                                                className={[
                                                    'flex cursor-pointer items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors',
                                                    selectedClassLevels.includes(
                                                        cl.id,
                                                    )
                                                        ? 'border-indigo-300 bg-indigo-50 text-indigo-700'
                                                        : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300',
                                                ].join(' ')}
                                            >
                                                <input
                                                    type="checkbox"
                                                    className="sr-only"
                                                    checked={selectedClassLevels.includes(
                                                        cl.id,
                                                    )}
                                                    onChange={(e) => {
                                                        if (e.target.checked) {
                                                            setSelectedClassLevels(
                                                                [
                                                                    ...selectedClassLevels,
                                                                    cl.id,
                                                                ],
                                                            );
                                                        } else {
                                                            setSelectedClassLevels(
                                                                selectedClassLevels.filter(
                                                                    (id) =>
                                                                        id !==
                                                                        cl.id,
                                                                ),
                                                            );
                                                        }
                                                    }}
                                                />
                                                {cl.name}
                                            </label>
                                        ))}
                                    </div>
                                </div>

                                {/* Class arms */}
                                {filteredArms.length > 0 && (
                                    <div>
                                        <label className="mb-1 block text-sm font-medium text-gray-700">
                                            Class arms
                                        </label>
                                        <div className="flex flex-wrap gap-2">
                                            {filteredArms.map((arm) => (
                                                <label
                                                    key={arm.id}
                                                    className={[
                                                        'flex cursor-pointer items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors',
                                                        selectedArms.includes(
                                                            arm.id,
                                                        )
                                                            ? 'border-indigo-300 bg-indigo-50 text-indigo-700'
                                                            : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300',
                                                    ].join(' ')}
                                                >
                                                    <input
                                                        type="checkbox"
                                                        className="sr-only"
                                                        checked={selectedArms.includes(
                                                            arm.id,
                                                        )}
                                                        onChange={(e) => {
                                                            if (
                                                                e.target.checked
                                                            ) {
                                                                setSelectedArms(
                                                                    [
                                                                        ...selectedArms,
                                                                        arm.id,
                                                                    ],
                                                                );
                                                            } else {
                                                                setSelectedArms(
                                                                    selectedArms.filter(
                                                                        (id) =>
                                                                            id !==
                                                                            arm.id,
                                                                    ),
                                                                );
                                                            }
                                                        }}
                                                    />
                                                    {arm.name}
                                                </label>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {/* Student search */}
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-gray-700">
                                        Specific students
                                    </label>
                                    <div className="relative">
                                        <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-gray-400" />
                                        <input
                                            type="text"
                                            placeholder="Search students by name or admission number..."
                                            value={studentSearch}
                                            onChange={(e) =>
                                                setStudentSearch(e.target.value)
                                            }
                                            className="w-full rounded-lg border border-gray-300 bg-white py-2 pr-3 pl-9 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                                        />
                                    </div>
                                    {studentResults.length > 0 && (
                                        <div className="mt-1 max-h-32 overflow-y-auto rounded-lg border border-gray-200 bg-white">
                                            {studentResults
                                                .filter(
                                                    (s) =>
                                                        !selectedStudents.find(
                                                            (ss) =>
                                                                ss.id === s.id,
                                                        ),
                                                )
                                                .map((s) => (
                                                    <button
                                                        type="button"
                                                        key={s.id}
                                                        onClick={() => {
                                                            setSelectedStudents(
                                                                [
                                                                    ...selectedStudents,
                                                                    {
                                                                        id: s.id,
                                                                        name: s.name,
                                                                    },
                                                                ],
                                                            );
                                                            setStudentSearch(
                                                                '',
                                                            );
                                                            setStudentResults(
                                                                [],
                                                            );
                                                        }}
                                                        className="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-gray-50"
                                                    >
                                                        <span>{s.name}</span>
                                                        <span className="text-xs text-gray-400">
                                                            {
                                                                s.admission_number
                                                            }
                                                        </span>
                                                    </button>
                                                ))}
                                        </div>
                                    )}
                                    {selectedStudents.length > 0 && (
                                        <div className="mt-2 flex flex-wrap gap-1.5">
                                            {selectedStudents.map((s) => (
                                                <span
                                                    key={s.id}
                                                    className="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700"
                                                >
                                                    {s.name}
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            setSelectedStudents(
                                                                selectedStudents.filter(
                                                                    (ss) =>
                                                                        ss.id !==
                                                                        s.id,
                                                                ),
                                                            )
                                                        }
                                                        className="ml-0.5 rounded-full p-0.5 hover:bg-indigo-100"
                                                    >
                                                        <X className="h-3 w-3" />
                                                    </button>
                                                </span>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>

                    <div className="flex items-center justify-end gap-3 border-t border-gray-100 px-6 py-4">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={saving}>
                            {saving
                                ? 'Saving...'
                                : isEdit
                                  ? 'Update Notice'
                                  : 'Create Notice'}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    );
}
