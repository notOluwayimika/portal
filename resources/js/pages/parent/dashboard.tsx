import React, { useState, useRef, useEffect } from 'react';
import { Head } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowRight,
    Award,
    Bell,
    Calendar,
    CheckCircle2,
    ChevronRight,
    Clock,
    CreditCard as FinanceIcon,
    Download,
    FileBarChart,
    Layout,
    Lock,
    Mail,
    MessageSquare,
    Phone,
    User,
    X,
} from 'lucide-react';
import { toast } from 'react-toastify';

// =======================================================
// MOCK DATA
// =======================================================

const PARENT_DATA = {
    name: 'Mrs. Nneka Adeyemi',
    email: 'nneka.adeyemi@gmail.com',
    phone: '+234 803 456 7890',
    avatar_initials: 'NA',
    children: [
        {
            id: 1,
            first_name: 'John',
            last_name: 'Adeyemi',
            initials: 'JA',
            avatar_colour: '#185FA5',
            section: 'Secondary',
            year_group: 'Year 10',
            class_arm: '10A (IGCSE)',
            boarding: true,
            boarding_house: 'Phoenix House',
            admission_number: 'BSS/2019/0142',
            has_fee_debt: true,
            outstanding_balance: 185000,
            result_locked: true,
            attendance_percent: 93,
            current_term: 'Second Term',
            current_session: '2025/2026',
            notices: [
                {
                    type: 'finance',
                    title: 'Outstanding balance — result access restricted',
                    description:
                        "John's Second Term results are ready but locked due to an outstanding balance of ₦185,000.",
                    time: 'Today',
                    sender: 'Finance Office',
                    badge_colour: 'red',
                },
                {
                    type: 'event',
                    title: 'Inter-house sports day — Saturday March 15',
                    description:
                        'All students are expected to participate. Parents are welcome to attend from 9:00 AM at the school field.',
                    time: '2 days ago',
                    sender: 'Admin',
                    badge_colour: 'amber',
                },
                {
                    type: 'achievement',
                    title: 'John named Student of the Month — February 2026',
                    description:
                        'John has been awarded Student of the Month by the Year 10 team for outstanding conduct and academics.',
                    time: '5 days ago',
                    sender: 'Head of School',
                    badge_colour: 'green',
                },
                {
                    type: 'general',
                    title: 'Second term resumes January 13, 2026',
                    description:
                        'Boarding students must resume by Sunday January 12 by 6:00 PM. Day students resume Monday January 13.',
                    time: '1 week ago',
                    sender: 'Admin',
                    badge_colour: 'gray',
                },
            ],
            timetable: [
                {
                    time: '8:00–9:00 AM',
                    subject: 'Mathematics',
                    teacher: 'Mr. Adeyemi',
                    room: 'Room 14',
                    status: 'done',
                },
                {
                    time: '9:00–10:00 AM',
                    subject: 'Physics',
                    teacher: 'Mrs. Bello',
                    room: 'Lab 2',
                    status: 'done',
                },
                {
                    time: '10:30–11:30 AM',
                    subject: 'English Language',
                    teacher: 'Mr. James',
                    room: 'Room 6',
                    status: 'current',
                },
                {
                    time: '11:30–12:30 PM',
                    subject: 'Chemistry',
                    teacher: 'Dr. Garba',
                    room: 'Lab 1',
                    status: 'upcoming',
                },
                {
                    time: '2:00–3:00 PM',
                    subject: 'Further Maths',
                    teacher: 'Mr. Adeyemi',
                    room: 'Room 14',
                    status: 'upcoming',
                },
            ],
        },
        {
            id: 2,
            first_name: 'Sarah',
            last_name: 'Adeyemi',
            initials: 'SA',
            avatar_colour: '#1D9E75',
            section: 'Primary',
            year_group: 'Primary 4',
            class_arm: 'P4A',
            boarding: false,
            boarding_house: null,
            admission_number: 'BSP/2021/0089',
            has_fee_debt: false,
            outstanding_balance: 0,
            result_locked: false,
            attendance_percent: 98,
            current_term: 'Second Term',
            current_session: '2025/2026',
            notices: [
                {
                    type: 'event',
                    title: 'Primary School Spelling Bee',
                    description:
                        'Sarah has been selected for the inter-class spelling bee competition on Wednesday.',
                    time: 'Yesterday',
                    sender: 'Class Teacher',
                    badge_colour: 'amber',
                },
                {
                    type: 'general',
                    title: 'Fruit Day Reminder',
                    description:
                        'Every Friday is fruit day. Please ensure Sarah brings her favourite fruit for the morning snack.',
                    time: '3 days ago',
                    sender: 'Primary Admin',
                    badge_colour: 'gray',
                },
            ],
            timetable: [
                {
                    time: '8:00–9:00 AM',
                    subject: 'Literacy',
                    teacher: 'Mrs. Okoro',
                    room: 'P4A',
                    status: 'done',
                },
                {
                    time: '9:00–10:00 AM',
                    subject: 'Numeracy',
                    teacher: 'Mr. Yusuf',
                    room: 'P4A',
                    status: 'done',
                },
                {
                    time: '10:30–11:30 AM',
                    subject: 'Social Studies',
                    teacher: 'Mrs. Okoro',
                    room: 'P4A',
                    status: 'current',
                },
                {
                    time: '11:30–12:30 PM',
                    subject: 'P.E.',
                    teacher: 'Coach Sam',
                    room: 'Field',
                    status: 'upcoming',
                },
            ],
            results: [
                {
                    subject: 'Mathematics',
                    score: 88,
                    grade: 'Very Good',
                    gp: 4.0,
                },
                {
                    subject: 'English Language',
                    score: 92,
                    grade: 'Excellent',
                    gp: 5.0,
                },
                { subject: 'Basic Science', score: 75, grade: 'Good', gp: 3.0 },
                {
                    subject: 'Civic Education',
                    score: 80,
                    grade: 'Very Good',
                    gp: 4.0,
                },
                {
                    subject: 'Verbal Reasoning',
                    score: 69,
                    grade: 'Satisfactory',
                    gp: 2.0,
                },
                {
                    subject: 'Quantitative Reasoning',
                    score: 83,
                    grade: 'Very Good',
                    gp: 4.0,
                },
            ],
        },
    ],
};

const CONTACTS = [
    { office: 'Form Office (Yr 10)', phone: '+234 801 234 5678' },
    { office: 'Finance Office', phone: '+234 802 345 6789' },
    { office: 'Medical Centre', phone: '+234 803 456 7890' },
    { office: 'Head of School', phone: '+234 804 567 8901' },
];

const NOTIFICATIONS = [
    {
        id: 1,
        title: 'New result available for Sarah',
        time: '2h ago',
        unread: true,
        color: 'green',
    },
    {
        id: 2,
        title: 'Fee reminder for John',
        time: '5h ago',
        unread: true,
        color: 'red',
    },
    {
        id: 3,
        title: 'School newsletter: March Edition',
        time: '1d ago',
        unread: false,
        color: 'blue',
    },
    {
        id: 4,
        title: 'Holiday announcement',
        time: '3d ago',
        unread: false,
        color: 'gray',
    },
];



const DebtBanner = ({ child, onDismiss, onPay }) => {
    if (!child.has_fee_debt) return null;

    return (
        <div className="flex flex-col items-center gap-4 rounded-2xl border border-red-200 bg-red-50 p-4 transition-all hover:shadow-md sm:flex-row">
            <div className="shrink-0 rounded-xl bg-red-100 p-3">
                <AlertCircle className="h-6 w-6 text-red-600" />
            </div>
            <div className="flex-1 text-center sm:text-left">
                <p className="font-medium text-red-900">
                    Outstanding balance of ₦
                    {child.outstanding_balance.toLocaleString()} on{' '}
                    {child.first_name}'s account.
                </p>
                <p className="text-sm text-red-700">
                    Result access is restricted until payment is made.
                </p>
            </div>
            <div className="flex w-full items-center justify-center gap-3 sm:w-auto">
                <button
                    onClick={onPay}
                    className="rounded-xl bg-red-600 px-6 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-red-700 active:scale-95"
                >
                    Pay Now
                </button>
                <button
                    onClick={onDismiss}
                    className="rounded-xl p-2 text-red-400 transition-colors hover:bg-red-100 hover:text-red-600"
                >
                    <X className="h-5 w-5" />
                </button>
            </div>
        </div>
    );
};

const ChildHeroCard = ({ child }) => {
    const getAttendanceColor = (pct) => {
        if (pct >= 90) return 'text-green-600 bg-green-50';
        if (pct >= 75) return 'text-amber-600 bg-amber-50';
        return 'text-red-600 bg-red-50';
    };

    return (
        <div className="relative overflow-hidden rounded-3xl border border-gray-100 bg-white p-6 shadow-sm">
            <div className="absolute top-0 right-0 -mt-16 -mr-16 h-32 w-32 rounded-full bg-gray-50 opacity-50" />

            <div className="relative z-10 flex flex-col justify-between gap-6 lg:flex-row lg:items-center">
                <div className="flex items-center gap-5">
                    <div
                        className="flex h-16 w-16 items-center justify-center rounded-full text-xl font-bold text-white shadow-lg"
                        style={{ backgroundColor: child.avatar_colour }}
                    >
                        {child.initials}
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">
                            {child.first_name} {child.last_name}
                        </h1>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <span className="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold tracking-wider text-blue-700 uppercase">
                                {child.section}
                            </span>
                            <span className="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold tracking-wider text-gray-600 uppercase">
                                {child.class_arm}
                            </span>
                            {child.boarding && (
                                <span className="rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold tracking-wider text-teal-700 uppercase">
                                    Boarding • {child.boarding_house}
                                </span>
                            )}
                            {child.has_fee_debt && (
                                <span className="rounded-full bg-red-50 px-3 py-1 text-xs font-semibold tracking-wider text-red-700 uppercase">
                                    Fee balance due
                                </span>
                            )}
                        </div>
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3 lg:gap-8">
                    <div className="px-4 text-center sm:text-left">
                        <p className="mb-1 text-xs font-bold tracking-widest text-gray-400 uppercase">
                            First Term Result
                        </p>
                        <div
                            className={`flex items-center justify-center gap-1 text-lg font-bold sm:justify-start ${child.result_locked ? 'text-red-600' : 'text-green-600'}`}
                        >
                            {child.result_locked ? (
                                <>
                                    Locked <Lock className="ml-1 h-4 w-4" />
                                </>
                            ) : (
                                <>
                                    Available{' '}
                                    <CheckCircle2 className="ml-1 h-4 w-4" />
                                </>
                            )}
                        </div>
                    </div>
                    <div className="border-y border-gray-100 px-4 py-4 text-center sm:border-x sm:border-y-0 sm:py-0 sm:text-left">
                        <p className="mb-1 text-xs font-bold tracking-widest text-gray-400 uppercase">
                            Attendance
                        </p>
                        <div
                            className={`text-2xl font-bold ${getAttendanceColor(child.attendance_percent).split(' ')[0]}`}
                        >
                            {child.attendance_percent}%
                            <span className="ml-1 text-xs font-normal text-gray-400">
                                this term
                            </span>
                        </div>
                    </div>
                    <div className="px-4 text-center sm:text-left">
                        <p className="mb-1 text-xs font-bold tracking-widest text-gray-400 uppercase">
                            Fee Balance
                        </p>
                        <div
                            className={`text-lg font-bold ${child.has_fee_debt ? 'text-red-600' : 'text-green-600'}`}
                        >
                            {child.has_fee_debt
                                ? `₦${child.outstanding_balance.toLocaleString()}`
                                : '₦0 · Paid ✓'}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

export const NoticesCard = ({
    notices,
    loading = false,
}: {
    notices: {
        id?: string;
        type: string;
        title: string;
        description?: string;
        body?: string;
        time: string;
        sender?: string;
        category?: string;
        badge_colour: string;
        for_students?: string[];
    }[];
    loading?: boolean;
    onAction?: () => void;
}) => {
    const [collapsed, setCollapsed] = React.useState(false);

    const getBadgeStyles = (color: string) => {
        const styles: Record<string, string> = {
            red: 'bg-red-50 text-red-700 border-red-100',
            amber: 'bg-amber-50 text-amber-700 border-amber-100',
            green: 'bg-green-50 text-green-700 border-green-100',
            gray: 'bg-gray-50 text-gray-700 border-gray-100',
            blue: 'bg-blue-50 text-blue-700 border-blue-100',
        };
        return styles[color] || styles.gray;
    };

    const getIcon = (type: string) => {
        switch (type) {
            case 'finance':
                return <FinanceIcon className="h-5 w-5" />;
            case 'event':
                return <Calendar className="h-5 w-5" />;
            case 'achievement':
                return <Award className="h-5 w-5" />;
            default:
                return <Bell className="h-5 w-5" />;
        }
    };

    const getContent = (notice: (typeof notices)[0]) => {
        if (notice.body) {
            return notice.body;
        }

        if (notice.description) {
            return notice.description.replace(/\n/g, '<br />');
        }

        return '';
    };

    return (
        <div className="flex h-full flex-col rounded-3xl border border-gray-100 bg-white p-6 shadow-sm">
            <div className="mb-4 flex items-center justify-between">
                <h2 className="flex items-center gap-2 text-lg font-bold text-gray-900">
                    <Bell className="h-5 w-5 text-blue-600" />
                    Latest Notices
                    {notices.length > 0 && (
                        <span className="ml-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-700">
                            {notices.length}
                        </span>
                    )}
                </h2>
                <button
                    onClick={() => setCollapsed(!collapsed)}
                    className="rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600"
                >
                    {collapsed ? (
                        <ChevronRight className="h-5 w-5" />
                    ) : (
                        <X className="h-4 w-4" />
                    )}
                </button>
            </div>

            {!collapsed && (
                <div className="flex-1 space-y-4">
                    {loading ? (
                        <div className="space-y-3">
                            {[1, 2, 3].map((i) => (
                                <div
                                    key={i}
                                    className="h-20 animate-pulse rounded-2xl bg-gray-100"
                                />
                            ))}
                        </div>
                    ) : notices.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-8 text-center">
                            <Bell className="mb-2 h-8 w-8 text-gray-300" />
                            <p className="text-sm font-medium text-gray-500">
                                No notices at this time
                            </p>
                        </div>
                    ) : (
                        notices.map((notice, idx) => (
                            <div
                                key={notice.id ?? idx}
                                className="group rounded-2xl border border-transparent p-4 transition-all hover:border-gray-100 hover:bg-gray-50"
                            >
                                <div className="flex gap-4">
                                    <div
                                        className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-xl ${getBadgeStyles(notice.badge_colour)}`}
                                    >
                                        {getIcon(notice.type)}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="mb-1 flex items-start justify-between gap-2">
                                            <h3 className="text-[13px] font-bold text-gray-900">
                                                {notice.title}
                                            </h3>
                                            <span
                                                className={`shrink-0 rounded-full border px-2 py-0.5 text-[10px] font-bold tracking-wider uppercase ${getBadgeStyles(notice.badge_colour)}`}
                                            >
                                                {notice.category ??
                                                    notice.type}
                                            </span>
                                        </div>
                                        <div
                                            dangerouslySetInnerHTML={{
                                                __html: getContent(notice),
                                            }}
                                            className="prose prose-xs mb-2 max-w-none text-[11px] leading-relaxed text-gray-500"
                                        />
                                        <div className="flex flex-wrap items-center gap-3 text-[10px] font-medium text-gray-400">
                                            <span className="flex items-center gap-1">
                                                <Clock className="h-3 w-3" />{' '}
                                                {notice.time}
                                            </span>
                                            {!!notice.for_students?.length && (
                                                <span className="flex items-center gap-1 text-blue-600">
                                                    <User className="h-3 w-3" />
                                                    For:{' '}
                                                    {notice.for_students.join(
                                                        ', ',
                                                    )}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        ))
                    )}
                </div>
            )}
        </div>
    );
};

const FeeSummaryCard = ({ child, onAction }) => {
    return (
        <div className="flex h-full flex-col rounded-3xl border border-gray-100 bg-white p-6 shadow-sm">
            <h2 className="mb-6 flex items-center gap-2 text-lg font-bold text-gray-900">
                <FinanceIcon className="h-5 w-5 text-red-500" />
                Fee Summary
            </h2>

            <div className="mb-6">
                <span className="mb-4 block text-[10px] font-bold tracking-widest text-gray-400 uppercase">
                    Current Term
                </span>
                <h3 className="text-xl font-bold text-gray-800">
                    {child.current_term} {child.current_session}
                </h3>
            </div>

            <div className="flex-1 space-y-4">
                <div className="flex items-center justify-between text-sm">
                    <span className="font-medium text-gray-500">Term fees</span>
                    <span className="font-bold text-gray-900">₦850,000</span>
                </div>
                <div className="flex items-center justify-between text-sm">
                    <span className="font-medium text-gray-500">
                        Amount paid
                    </span>
                    <span className="font-bold text-green-600">
                        ₦{child.has_fee_debt ? '665,000' : '850,000'}
                    </span>
                </div>

                <div className="my-4 h-px bg-gray-100" />

                <div className="flex items-center justify-between">
                    <span className="font-bold text-gray-900">Balance due</span>
                    <span
                        className={`text-xl font-black ${child.has_fee_debt ? 'text-red-600' : 'text-green-600'}`}
                    >
                        ₦{child.outstanding_balance.toLocaleString()}
                    </span>
                </div>
            </div>

            <div className="mt-8 space-y-3">
                {child.has_fee_debt ? (
                    <button
                        onClick={() => onAction('payment')}
                        className="w-full rounded-2xl bg-red-600 px-4 py-3 font-bold text-white shadow-lg shadow-red-100 transition-all hover:bg-red-700 active:scale-95"
                    >
                        Clear Balance
                    </button>
                ) : (
                    <div className="flex items-center justify-center gap-2 rounded-2xl border border-green-100 bg-green-50 p-4 text-sm font-bold text-green-700">
                        <CheckCircle2 className="h-5 w-5" />
                        All fees paid for this term
                    </div>
                )}
                <button
                    onClick={() => onAction('statement')}
                    className="flex w-full items-center justify-center gap-1 py-2 text-sm font-semibold text-gray-500 transition-colors hover:text-gray-900"
                >
                    View full statement <ArrowRight className="h-4 w-4" />
                </button>
            </div>
        </div>
    );
};

const AttendanceCard = ({ child, onAction }) => {
    const pct = child.attendance_percent;
    const radius = 35;
    const circumference = 2 * Math.PI * radius;
    const offset = circumference - (pct / 100) * circumference;

    const getColor = (p) => {
        if (p >= 90) return '#10b981';
        if (p >= 75) return '#f59e0b';
        return '#ef4444';
    };

    const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
    const grid = [
        ['P', 'P', 'P', 'P', 'A'],
        ['P', 'P', 'P', 'L', 'P'],
    ];

    const getStatusColor = (s) => {
        switch (s) {
            case 'P':
                return 'bg-green-500';
            case 'A':
                return 'bg-red-500';
            case 'L':
                return 'bg-amber-500';
            default:
                return 'bg-gray-200';
        }
    };

    return (
        <div className="flex h-full flex-col rounded-3xl border border-gray-100 bg-white p-6 shadow-sm">
            <h2 className="mb-6 flex items-center gap-2 text-lg font-bold text-gray-900">
                <Layout className="h-5 w-5 text-teal-500" />
                Attendance This Term
            </h2>

            <div className="mb-8 flex flex-col items-center">
                <div className="relative flex h-32 w-32 items-center justify-center">
                    <svg className="h-full w-full -rotate-90 transform">
                        <circle
                            cx="64"
                            cy="64"
                            r={radius}
                            fill="transparent"
                            stroke="#f3f4f6"
                            strokeWidth="8"
                        />
                        <circle
                            cx="64"
                            cy="64"
                            r={radius}
                            fill="transparent"
                            stroke={getColor(pct)}
                            strokeWidth="8"
                            strokeDasharray={circumference}
                            strokeDashoffset={offset}
                            strokeLinecap="round"
                            className="transition-all duration-1000 ease-out"
                        />
                    </svg>
                    <div className="absolute inset-0 flex flex-col items-center justify-center">
                        <span className="text-2xl font-black text-gray-900">
                            {pct}%
                        </span>
                        <span className="text-[10px] font-bold tracking-widest text-gray-400 uppercase">
                            Present
                        </span>
                    </div>
                </div>

                <div className="mt-4 flex gap-2">
                    <span className="rounded-lg bg-gray-50 px-2 py-1 text-[10px] font-bold text-gray-600">
                        56 days present
                    </span>
                    <span className="rounded-lg bg-gray-50 px-2 py-1 text-[10px] font-bold text-gray-600">
                        4 absences
                    </span>
                    <span className="rounded-lg bg-gray-50 px-2 py-1 text-[10px] font-bold text-gray-600">
                        0 late
                    </span>
                </div>
            </div>

            <div className="flex-1 space-y-4">
                <div className="grid grid-cols-5 gap-2">
                    {days.map((d) => (
                        <span
                            key={d}
                            className="text-center text-[10px] font-bold text-gray-400"
                        >
                            {d}
                        </span>
                    ))}
                    {grid[0].map((s, i) => (
                        <div
                            key={i}
                            className={`h-6 rounded-lg ${getStatusColor(s)} flex items-center justify-center text-[10px] font-bold text-white opacity-80`}
                        >
                            {s}
                        </div>
                    ))}
                    {grid[1].map((s, i) => (
                        <div
                            key={i}
                            className={`h-6 rounded-lg ${getStatusColor(s)} flex items-center justify-center text-[10px] font-bold text-white opacity-80`}
                        >
                            {s}
                        </div>
                    ))}
                </div>
                <div className="flex items-center justify-between px-1 text-[10px] font-bold text-gray-400">
                    <span>AM Session</span>
                    <span>PM Session</span>
                </div>
            </div>

            <button
                onClick={onAction}
                className="mt-6 flex items-center justify-center gap-1 text-sm font-semibold text-teal-600 transition-colors hover:text-teal-800"
            >
                Full attendance record <ArrowRight className="h-4 w-4" />
            </button>
        </div>
    );
};

const ResultsCard = ({ child, onAction }) => {
    const results = child.results || [];

    if (child.result_locked) {
        return (
            <div className="relative overflow-hidden rounded-3xl border border-gray-100 bg-white p-6 shadow-sm lg:col-span-2">
                <h2 className="mb-6 flex items-center gap-2 text-lg font-bold text-gray-900">
                    <FileBarChart className="h-5 w-5 text-indigo-500" />
                    Academic Results
                </h2>

                <div className="relative">
                    <div className="space-y-4 opacity-20 blur-md select-none">
                        {[1, 2, 3, 4, 5].map((i) => (
                            <div
                                key={i}
                                className="flex items-center justify-between rounded-2xl bg-gray-100 p-4"
                            >
                                <div className="h-4 w-32 rounded bg-gray-300" />
                                <div className="h-4 w-12 rounded bg-gray-300" />
                                <div className="h-4 w-12 rounded bg-gray-300" />
                            </div>
                        ))}
                    </div>

                    <div className="absolute inset-0 flex flex-col items-center justify-center rounded-3xl bg-white/40 backdrop-blur-[2px]">
                        <div className="mb-4 animate-bounce rounded-full bg-red-50 p-4">
                            <Lock className="h-10 w-10 text-red-600" />
                        </div>
                        <h3 className="mb-2 text-2xl font-black text-gray-900">
                            Results Locked
                        </h3>
                        <p className="mb-6 max-w-sm px-4 text-center text-gray-600">
                            {child.first_name}'s {child.current_term} results
                            are ready. Clear the outstanding balance of ₦
                            {child.outstanding_balance.toLocaleString()} to
                            unlock.
                        </p>
                        <button
                            onClick={() => onAction('unlock')}
                            className="flex items-center gap-2 rounded-2xl bg-red-600 px-8 py-3 font-bold text-white shadow-xl shadow-red-100 transition-all hover:bg-red-700 active:scale-95"
                        >
                            Pay & Unlock Results{' '}
                            <FinanceIcon className="h-5 w-5" />
                        </button>
                        <div className="pointer-events-none absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 rotate-[-20deg] whitespace-nowrap opacity-[0.05]">
                            <span className="text-[120px] font-black tracking-tighter text-red-600">
                                LOCKED
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="rounded-3xl border border-gray-100 bg-white p-6 shadow-sm lg:col-span-2">
            <div className="mb-6 flex items-center justify-between">
                <h2 className="flex items-center gap-2 text-lg font-bold text-gray-900">
                    <FileBarChart className="h-5 w-5 text-indigo-500" />
                    Academic Results
                </h2>
                <div className="flex gap-2">
                    <button
                        onClick={() => onAction('download')}
                        className="rounded-xl p-2 text-gray-500 transition-colors hover:bg-gray-100"
                        title="Download PDF"
                    >
                        <Download className="h-5 w-5" />
                    </button>
                </div>
            </div>

            <div className="overflow-hidden">
                <table className="w-full">
                    <thead>
                        <tr className="border-b border-gray-100">
                            <th className="py-3 text-left text-[10px] font-black tracking-widest text-gray-400 uppercase">
                                Subject
                            </th>
                            <th className="py-3 text-center text-[10px] font-black tracking-widest text-gray-400 uppercase">
                                Score
                            </th>
                            <th className="hidden py-3 text-left text-[10px] font-black tracking-widest text-gray-400 uppercase sm:table-cell">
                                Grade
                            </th>
                            <th className="py-3 text-right text-[10px] font-black tracking-widest text-gray-400 uppercase">
                                Status
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50">
                        {results.map((r, i) => (
                            <tr
                                key={i}
                                className="group transition-colors hover:bg-gray-50"
                            >
                                <td className="py-4">
                                    <div className="flex flex-col">
                                        <span className="text-sm font-bold text-gray-800">
                                            {r.subject}
                                        </span>
                                        <span className="text-[10px] text-gray-400 sm:hidden">
                                            {r.grade}
                                        </span>
                                    </div>
                                </td>
                                <td className="py-4 text-center">
                                    <div className="flex flex-col items-center gap-1">
                                        <span className="text-sm font-black text-gray-900">
                                            {r.score}
                                        </span>
                                        <div className="h-1 w-12 overflow-hidden rounded-full bg-gray-100">
                                            <div
                                                className={`h-full ${r.score >= 70 ? 'bg-green-500' : r.score >= 50 ? 'bg-amber-500' : 'bg-red-500'}`}
                                                style={{ width: `${r.score}%` }}
                                            />
                                        </div>
                                    </div>
                                </td>
                                <td className="hidden py-4 text-left sm:table-cell">
                                    <span className="text-xs font-semibold text-gray-600">
                                        {r.grade}
                                    </span>
                                </td>
                                <td className="py-4 text-right">
                                    <span
                                        className={`rounded-full px-2 py-0.5 text-[10px] font-bold ${r.score >= 50 ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-600'}`}
                                    >
                                        {r.score >= 50 ? 'PASSED' : 'RETAKE'}
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="mt-6 flex flex-col items-center justify-between gap-4 rounded-2xl bg-gray-50 p-4 sm:flex-row">
                <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-100">
                        <Award className="h-5 w-5 text-indigo-600" />
                    </div>
                    <div>
                        <p className="mb-1 text-[10px] leading-none font-bold tracking-widest text-gray-400 uppercase">
                            Class Position
                        </p>
                        <p className="text-sm font-black text-gray-900">
                            3rd of 28 students
                        </p>
                    </div>
                </div>
                <div className="flex w-full gap-3 sm:w-auto">
                    <button
                        onClick={() => onAction('download')}
                        className="flex flex-1 items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2 text-xs font-bold text-gray-600 transition-all hover:text-gray-900 sm:flex-none"
                    >
                        Download PDF <Download className="h-3 w-3" />
                    </button>
                    <button
                        onClick={() => onAction('analysis')}
                        className="flex flex-1 items-center justify-center gap-1 px-4 py-2 text-xs font-bold text-indigo-600 transition-all hover:text-indigo-800 sm:flex-none"
                    >
                        Full Analysis <ArrowRight className="h-3 w-3" />
                    </button>
                </div>
            </div>
        </div>
    );
};

const TimetableCard = ({ timetable, onAction }) => {
    return (
        <div className="flex h-full flex-col rounded-3xl border border-gray-100 bg-white p-6 shadow-sm">
            <div className="mb-6 flex items-center justify-between">
                <h2 className="flex items-center gap-2 text-lg font-bold text-gray-900">
                    <Calendar className="h-5 w-5 text-orange-500" />
                    Today's Timetable
                </h2>
                <span className="text-[10px] font-bold tracking-widest text-gray-400 uppercase">
                    Wed, 15 Jan
                </span>
            </div>

            <div className="flex-1 space-y-3">
                {timetable.map((lesson, idx) => (
                    <div
                        key={idx}
                        className={`rounded-2xl border p-3 transition-all ${
                            lesson.status === 'current'
                                ? 'border-blue-100 bg-blue-50 shadow-sm'
                                : lesson.status === 'done'
                                  ? 'border-transparent bg-gray-50/50 opacity-60'
                                  : 'border-gray-100 bg-white'
                        }`}
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div className="shrink-0 pt-1">
                                <Clock
                                    className={`h-3.5 w-3.5 ${lesson.status === 'current' ? 'text-blue-600' : 'text-gray-400'}`}
                                />
                            </div>
                            <div className="min-w-0 flex-1">
                                <div className="mb-1 flex items-center justify-between">
                                    <span className="text-[10px] font-bold text-gray-400">
                                        {lesson.time}
                                    </span>
                                    {lesson.status === 'current' && (
                                        <span className="rounded-full bg-blue-600 px-2 py-0.5 text-[9px] font-black tracking-tighter text-white uppercase">
                                            Now
                                        </span>
                                    )}
                                </div>
                                <h4
                                    className={`text-sm font-bold ${lesson.status === 'current' ? 'text-blue-900' : 'text-gray-800'}`}
                                >
                                    {lesson.subject}
                                </h4>
                                <div className="mt-1 flex items-center justify-between">
                                    <span className="flex items-center gap-1 text-[11px] text-gray-500">
                                        <User className="h-3 w-3" />{' '}
                                        {lesson.teacher}
                                    </span>
                                    <span className="text-[11px] font-bold tracking-widest text-gray-400 uppercase">
                                        {lesson.room}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                ))}
            </div>

            <button
                onClick={onAction}
                className="mt-6 flex items-center justify-center gap-1 text-sm font-semibold text-orange-600 transition-colors hover:text-orange-800"
            >
                Full timetable <ArrowRight className="h-4 w-4" />
            </button>
        </div>
    );
};

export const QuickContactCard = ({ contacts, onAction }) => {
    const [msg, setMsg] = useState('');
    const [to, setTo] = useState('Finance Office');

    const handleSubmit = (e) => {
        e.preventDefault();
        if (msg.trim()) {
            onAction('send_message', { to, msg });
            setMsg('');
        }
    };

    return (
        <div className="flex h-full flex-col rounded-3xl border border-gray-100 bg-white p-6 shadow-sm">
            <h2 className="mb-6 flex items-center gap-2 text-lg font-bold text-gray-900">
                <MessageSquare className="h-5 w-5 text-purple-500" />
                Quick Contact
            </h2>

            <div className="mb-8 space-y-3">
                {contacts.map((c, i) => (
                    <div
                        key={i}
                        className="group flex cursor-pointer items-center justify-between rounded-2xl bg-gray-50 p-3 transition-colors hover:bg-gray-100"
                    >
                        <div>
                            <p className="text-xs font-bold text-gray-800">
                                {c.office}
                            </p>
                            <p className="text-[10px] text-gray-500">
                                {c.phone}
                            </p>
                            <p className="text-[10px] text-gray-500">
                                {c.email}
                            </p>
                        </div>
                        <div className="flex gap-2">
                            <a
                                href={`tel:${c.phone}`}
                                className="rounded-xl bg-white p-2 text-gray-400 shadow-sm transition-colors group-hover:text-blue-600"
                            >
                                <Phone className="h-4 w-4" />
                            </a>

                            <a
                                href={`mailto:${c.email}`}
                                className="rounded-xl bg-white p-2 text-gray-400 shadow-sm transition-colors group-hover:text-purple-600"
                            >
                                <Mail className="h-4 w-4" />
                            </a>
                        </div>
                    </div>
                ))}
            </div>

            {/* <div className="flex-1 rounded-3xl bg-purple-50 p-5">
                <h3 className="mb-4 text-xs font-bold tracking-widest text-purple-900 uppercase">
                    Message the school
                </h3>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <select
                        value={to}
                        onChange={(e) => setTo(e.target.value)}
                        className="w-full rounded-xl border border-purple-100 bg-white px-4 py-2 text-sm text-gray-700 transition-all focus:border-transparent focus:ring-2 focus:ring-purple-500 focus:outline-none"
                    >
                        {contacts.map((c) => (
                            <option key={c.office}>{c.office}</option>
                        ))}
                    </select>
                    <textarea
                        value={msg}
                        onChange={(e) => setMsg(e.target.value)}
                        placeholder="Write a message to the school..."
                        rows={3}
                        className="w-full resize-none rounded-xl border border-purple-100 bg-white px-4 py-3 text-sm text-gray-700 transition-all focus:border-transparent focus:ring-2 focus:ring-purple-500 focus:outline-none"
                    />
                    <button
                        disabled
                        type="submit"
                        className="flex w-full items-center justify-center gap-2 rounded-xl bg-[#185FA5] py-3 font-bold text-white shadow-lg shadow-blue-100 transition-all hover:bg-[#0f4a82] active:scale-95 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Send Message <Mail className="h-4 w-4" />
                    </button>
                </form>
            </div> */}
        </div>
    );
};

const NotificationDropdown = ({ isOpen, onClose, notifications }) => {
    const dropdownRef = useRef(null);

    useEffect(() => {
        const handleClickOutside = (e) => {
            if (
                dropdownRef.current &&
                !dropdownRef.current.contains(e.target)
            ) {
                onClose();
            }
        };
        if (isOpen) document.addEventListener('mousedown', handleClickOutside);
        return () =>
            document.removeEventListener('mousedown', handleClickOutside);
    }, [isOpen, onClose]);

    if (!isOpen) return null;

    return (
        <div
            ref={dropdownRef}
            className="absolute right-0 z-50 mt-3 w-[320px] origin-top-right animate-in overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-2xl duration-200 fade-in zoom-in"
        >
            <div className="flex items-center justify-between border-b border-gray-50 p-4">
                <h3 className="font-black text-gray-900">Notifications</h3>
                <button className="text-xs font-bold text-blue-600 hover:text-blue-800">
                    Mark all as read
                </button>
            </div>
            <div className="max-h-[360px] overflow-y-auto">
                {notifications.map((n) => (
                    <div
                        key={n.id}
                        className="flex cursor-pointer items-start gap-4 border-b border-gray-50/50 p-4 transition-colors hover:bg-gray-50"
                    >
                        <div
                            className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${n.unread ? `bg-${n.color}-500 shadow-[0_0_8px] shadow-${n.color}-500/50` : 'border border-gray-300'}`}
                        />
                        <div className="flex-1">
                            <p
                                className={`text-sm ${n.unread ? 'font-bold text-gray-900' : 'text-gray-600'}`}
                            >
                                {n.title}
                            </p>
                            <p className="mt-1 text-[10px] font-medium text-gray-400">
                                {n.time}
                            </p>
                        </div>
                    </div>
                ))}
            </div>
            <div className="p-4 text-center">
                <button className="mx-auto flex items-center justify-center gap-1 text-xs font-bold text-gray-500 transition-colors hover:text-gray-900">
                    View all notifications <ChevronRight className="h-3 w-3" />
                </button>
            </div>
        </div>
    );
};

// =======================================================
// MAIN COMPONENT
// =======================================================

export default function ParentDashboard() {
    const [activeChildId, setActiveChildId] = useState(
        PARENT_DATA.children[0].id,
    );
    const [isBannerDismissed, setIsBannerDismissed] = useState(false);
    const [isNotifOpen, setIsNotifOpen] = useState(false);

    const activeChild = PARENT_DATA.children.find(
        (c) => c.id === activeChildId,
    );



    const handleAction = (type, data) => {
        switch (type) {
            case 'payment':
            case 'unlock':
                toast.info('Redirecting to payment gateway...');
                break;
            case 'statement':
                toast.info('Feature coming soon');
                break;
            case 'download':
                toast.info('Preparing PDF download...');
                break;
            case 'send_message':
                toast.info(`Message sent to ${data.to}`);
                break;
            case 'analysis':
            case 'attendance':
            case 'notices':
            case 'timetable':
                toast.info('Feature coming soon');
                break;
            default:
                toast.info('Action triggered');
        }
    };

    return (
        <>
            <Head title="Parent Dashboard" />

            <div className="flex flex-1 flex-col gap-6 p-4 lg:p-6">
                {/* Page header: welcome + child switcher + notifications */}
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div>
                        <h3 className="text-2xl font-black tracking-tight text-gray-900">
                            Welcome back, {PARENT_DATA.name.split(' ')[1]}!
                        </h3>
                        <p className="mt-1 flex items-center gap-2 text-sm font-medium text-gray-500">
                            Here's what's happening with your children today at
                            Brookstone.
                            <span className="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-green-500" />
                        </p>
                    </div>

                    <div className="flex shrink-0 items-center gap-3">
                        {/* Child switcher */}
                        <div className="flex gap-1 rounded-2xl bg-gray-100 p-1">
                            {PARENT_DATA.children.map((child) => (
                                <button
                                    key={child.id}
                                    onClick={() => setActiveChildId(child.id)}
                                    className={`rounded-xl px-4 py-1.5 text-xs font-bold transition-all ${
                                        activeChildId === child.id
                                            ? 'bg-white text-gray-900 shadow-sm'
                                            : 'text-gray-500 hover:text-gray-700'
                                    }`}
                                >
                                    {child.first_name}
                                </button>
                            ))}
                        </div>

                        {/* Notification bell */}
                        <div className="relative">
                            <button
                                onClick={() => setIsNotifOpen(!isNotifOpen)}
                                className={`relative rounded-2xl p-2.5 transition-all ${isNotifOpen ? 'bg-blue-50 text-blue-600' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'}`}
                            >
                                <Bell className="h-5 w-5" />
                                <span className="absolute top-2 right-2 h-2.5 w-2.5 rounded-full border-2 border-white bg-red-500" />
                            </button>
                            <NotificationDropdown
                                isOpen={isNotifOpen}
                                onClose={() => setIsNotifOpen(false)}
                                notifications={NOTIFICATIONS}
                            />
                        </div>
                    </div>
                </div>

                {/* Debt Banner */}
                {!isBannerDismissed && (
                    <DebtBanner
                        child={activeChild}
                        onDismiss={() => setIsBannerDismissed(true)}
                        onPay={() => handleAction('payment')}
                    />
                )}

                {/* Hero Card */}
                <ChildHeroCard child={activeChild} />

                {/* Main Grid */}
                <div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
                    <NoticesCard
                        notices={activeChild.notices}
                    />
                    <FeeSummaryCard
                        child={activeChild}
                        onAction={(type) => handleAction(type)}
                    />

                    <AttendanceCard
                        child={activeChild}
                        onAction={() => handleAction('attendance')}
                    />
                    <ResultsCard
                        child={activeChild}
                        onAction={(type) => handleAction(type)}
                    />

                    <TimetableCard
                        timetable={activeChild.timetable}
                        onAction={() => handleAction('timetable')}
                    />
                    <QuickContactCard
                        contacts={CONTACTS}
                        onAction={(type, data) => handleAction(type, data)}
                    />
                </div>

                {/* Footer */}
                <footer className="mt-4 flex flex-col items-center justify-between gap-4 border-t border-sidebar-border/50 pt-8 text-xs font-bold tracking-widest text-gray-400 uppercase sm:flex-row">
                    <span>Brookstone School Management System</span>
                    <div className="flex gap-6">
                        <a
                            href="#"
                            className="transition-colors hover:text-blue-600"
                        >
                            Support
                        </a>
                        <a
                            href="#"
                            className="transition-colors hover:text-blue-600"
                        >
                            Privacy
                        </a>
                        <a
                            href="#"
                            className="transition-colors hover:text-blue-600"
                        >
                            Terms
                        </a>
                    </div>
                </footer>
            </div>
        </>
    );
}

ParentDashboard.layout = {
    breadcrumbs: [{ title: 'Parent Dashboard', href: '/parent/dashboard' }],
};
