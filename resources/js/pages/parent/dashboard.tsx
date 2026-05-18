import React, { useState, useRef, useEffect } from 'react';
import { Head } from '@inertiajs/react';
import {
  AlertCircle, ArrowRight, Award, Bell, Calendar, CheckCircle2,
  ChevronRight, Clock, CreditCard as FinanceIcon, Download, FileBarChart,
  Layout, Lock, Mail, MessageSquare, Phone, User, Users, X,
} from 'lucide-react';

// =======================================================
// MOCK DATA
// =======================================================

const PARENT_DATA = {
  name: "Mrs. Nneka Adeyemi",
  email: "nneka.adeyemi@gmail.com",
  phone: "+234 803 456 7890",
  avatar_initials: "NA",
  children: [
    {
      id: 1,
      first_name: "John",
      last_name: "Adeyemi",
      initials: "JA",
      avatar_colour: "#2c197a",
      section: "Secondary",
      year_group: "Year 10",
      class_arm: "10A (IGCSE)",
      boarding: true,
      boarding_house: "Phoenix House",
      admission_number: "BSS/2019/0142",
      has_fee_debt: true,
      outstanding_balance: 185000,
      result_locked: true,
      attendance_percent: 93,
      current_term: "Second Term",
      current_session: "2025/2026",
      notices: [
        {
          type: "finance",
          title: "Outstanding balance — result access restricted",
          description: "John's Second Term results are ready but locked due to an outstanding balance of ₦185,000.",
          time: "Today",
          sender: "Finance Office",
          badge_colour: "red"
        },
        {
          type: "event",
          title: "Inter-house sports day — Saturday March 15",
          description: "All students are expected to participate. Parents are welcome to attend from 9:00 AM at the school field.",
          time: "2 days ago",
          sender: "Admin",
          badge_colour: "amber"
        },
        {
          type: "achievement",
          title: "John named Student of the Month — February 2026",
          description: "John has been awarded Student of the Month by the Year 10 team for outstanding conduct and academics.",
          time: "5 days ago",
          sender: "Head of School",
          badge_colour: "green"
        },
        {
          type: "general",
          title: "Second term resumes January 13, 2026",
          description: "Boarding students must resume by Sunday January 12 by 6:00 PM. Day students resume Monday January 13.",
          time: "1 week ago",
          sender: "Admin",
          badge_colour: "gray"
        }
      ],
      timetable: [
        { time:"8:00–9:00 AM",   subject:"Mathematics",    teacher:"Mr. Adeyemi", room:"Room 14",  status:"done"    },
        { time:"9:00–10:00 AM",  subject:"Physics",         teacher:"Mrs. Bello",  room:"Lab 2",   status:"done"    },
        { time:"10:30–11:30 AM", subject:"English Language",teacher:"Mr. James",   room:"Room 6",  status:"current" },
        { time:"11:30–12:30 PM", subject:"Chemistry",       teacher:"Dr. Garba",   room:"Lab 1",   status:"upcoming"},
        { time:"2:00–3:00 PM",   subject:"Further Maths",   teacher:"Mr. Adeyemi", room:"Room 14", status:"upcoming"},
      ]
    },
    {
      id: 2,
      first_name: "Sarah",
      last_name: "Adeyemi",
      initials: "SA",
      avatar_colour: "#1D9E75",
      section: "Primary",
      year_group: "Primary 4",
      class_arm: "P4A",
      boarding: false,
      boarding_house: null,
      admission_number: "BSP/2021/0089",
      has_fee_debt: false,
      outstanding_balance: 0,
      result_locked: false,
      attendance_percent: 98,
      current_term: "Second Term",
      current_session: "2025/2026",
      notices: [
        {
          type: "event",
          title: "Primary School Spelling Bee",
          description: "Sarah has been selected for the inter-class spelling bee competition on Wednesday.",
          time: "Yesterday",
          sender: "Class Teacher",
          badge_colour: "amber"
        },
        {
          type: "general",
          title: "Fruit Day Reminder",
          description: "Every Friday is fruit day. Please ensure Sarah brings her favourite fruit for the morning snack.",
          time: "3 days ago",
          sender: "Primary Admin",
          badge_colour: "gray"
        }
      ],
      timetable: [
        { time:"8:00–9:00 AM",   subject:"Literacy",       teacher:"Mrs. Okoro",  room:"P4A",      status:"done"    },
        { time:"9:00–10:00 AM",  subject:"Numeracy",       teacher:"Mr. Yusuf",   room:"P4A",      status:"done"    },
        { time:"10:30–11:30 AM", subject:"Social Studies", teacher:"Mrs. Okoro",  room:"P4A",      status:"current" },
        { time:"11:30–12:30 PM", subject:"P.E.",           teacher:"Coach Sam",   room:"Field",    status:"upcoming"},
      ],
      results: [
        { subject:"Mathematics",      score:88, grade:"Very Good",   gp:4.0 },
        { subject:"English Language", score:92, grade:"Excellent",   gp:5.0 },
        { subject:"Basic Science",    score:75, grade:"Good",        gp:3.0 },
        { subject:"Civic Education",  score:80, grade:"Very Good",   gp:4.0 },
        { subject:"Verbal Reasoning", score:69, grade:"Satisfactory",gp:2.0 },
        { subject:"Quantitative Reasoning", score:83, grade:"Very Good", gp:4.0 },
      ]
    }
  ]
};

const CONTACTS = [
  { office:"Form Office (Yr 10)", phone:"+234 801 234 5678" },
  { office:"Finance Office",      phone:"+234 802 345 6789" },
  { office:"Medical Centre",      phone:"+234 803 456 7890" },
  { office:"Head of School",      phone:"+234 804 567 8901" },
];

const NOTIFICATIONS = [
  { id: 1, title: "New result available for Sarah", time: "2h ago", unread: true, color: "green" },
  { id: 2, title: "Fee reminder for John", time: "5h ago", unread: true, color: "red" },
  { id: 3, title: "School newsletter: March Edition", time: "1d ago", unread: false, color: "blue" },
  { id: 4, title: "Holiday announcement", time: "3d ago", unread: false, color: "gray" },
];

// =======================================================
// SUB-COMPONENTS
// =======================================================

const Toast = ({ toasts, onDismiss }) => {
  return (
    <div className="fixed bottom-6 right-6 z-[100] flex flex-col gap-3">
      {toasts.map((toast) => (
        <div
          key={toast.id}
          className="flex items-center gap-3 bg-gray-900 text-white px-4 py-3 rounded-2xl shadow-2xl animate-in slide-in-from-right-full duration-300 min-w-[300px]"
        >
          <div className="bg-white/10 p-1.5 rounded-full">
            <CheckCircle2 className="w-4 h-4 text-green-400" />
          </div>
          <span className="text-sm font-medium">{toast.message}</span>
          <button
            onClick={() => onDismiss(toast.id)}
            className="ml-auto p-1 hover:bg-white/10 rounded-lg transition-colors"
          >
            <X className="w-4 h-4 text-white/50" />
          </button>
        </div>
      ))}
    </div>
  );
};

const DebtBanner = ({ child, onDismiss, onPay }) => {
  if (!child.has_fee_debt) return null;

  return (
    <div className="bg-red-50 border border-red-200 rounded-2xl p-4 flex flex-col sm:flex-row items-center gap-4 transition-all hover:shadow-md">
      <div className="bg-red-100 p-3 rounded-xl shrink-0">
        <AlertCircle className="w-6 h-6 text-red-600" />
      </div>
      <div className="flex-1 text-center sm:text-left">
        <p className="text-red-900 font-medium">Outstanding balance of ₦{child.outstanding_balance.toLocaleString()} on {child.first_name}'s account.</p>
        <p className="text-red-700 text-sm">Result access is restricted until payment is made.</p>
      </div>
      <div className="flex items-center gap-3 w-full sm:w-auto justify-center">
        <button
          onClick={onPay}
          className="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-xl font-medium text-sm transition-colors shadow-sm active:scale-95"
        >
          Pay Now
        </button>
        <button
          onClick={onDismiss}
          className="p-2 hover:bg-red-100 rounded-xl transition-colors text-red-400 hover:text-red-600"
        >
          <X className="w-5 h-5" />
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
    <div className="bg-white rounded-3xl p-6 shadow-sm border border-gray-100 overflow-hidden relative dark:bg-slate-900 dark:border-slate-700">
      <div className="absolute top-0 right-0 w-32 h-32 bg-gray-50 rounded-full -mr-16 -mt-16 opacity-50" />

      <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-6 relative z-10">
        <div className="flex items-center gap-5">
          <div
            className="w-16 h-16 rounded-full flex items-center justify-center text-xl font-bold text-white shadow-lg"
            style={{ backgroundColor: child.avatar_colour }}
          >
            {child.initials}
          </div>
          <div>
            <h1 className="text-2xl font-bold text-gray-900 dark:text-white">{child.first_name} {child.last_name}</h1>
            <div className="flex flex-wrap gap-2 mt-2">
              <span className="bg-blue-50 text-blue-700 text-xs font-semibold px-3 py-1 rounded-full uppercase tracking-wider">
                {child.section}
              </span>
              <span className="bg-gray-100 text-gray-600 text-xs font-semibold px-3 py-1 rounded-full uppercase tracking-wider">
                {child.class_arm}
              </span>
              {child.boarding && (
                <span className="bg-teal-50 text-teal-700 text-xs font-semibold px-3 py-1 rounded-full uppercase tracking-wider">
                  Boarding • {child.boarding_house}
                </span>
              )}
              {child.has_fee_debt && (
                <span className="bg-red-50 text-red-700 text-xs font-semibold px-3 py-1 rounded-full uppercase tracking-wider">
                  Fee balance due
                </span>
              )}
            </div>
          </div>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 lg:gap-8">
          <div className="text-center sm:text-left px-4">
            <p className="text-gray-400 text-xs uppercase font-bold tracking-widest mb-1">First Term Result</p>
            <div className={`flex items-center justify-center sm:justify-start gap-1 font-bold text-lg ${child.result_locked ? 'text-red-600' : 'text-green-600'}`}>
              {child.result_locked ? (
                <>Locked <Lock className="w-4 h-4 ml-1" /></>
              ) : (
                <>Available <CheckCircle2 className="w-4 h-4 ml-1" /></>
              )}
            </div>
          </div>
          <div className="text-center sm:text-left px-4 border-y sm:border-y-0 sm:border-x border-gray-100 py-4 sm:py-0 dark:border-slate-700">
            <p className="text-gray-400 text-xs uppercase font-bold tracking-widest mb-1">Attendance</p>
            <div className={`font-bold text-2xl ${getAttendanceColor(child.attendance_percent).split(' ')[0]}`}>
              {child.attendance_percent}%
              <span className="text-xs font-normal text-gray-400 ml-1">this term</span>
            </div>
          </div>
          <div className="text-center sm:text-left px-4">
            <p className="text-gray-400 text-xs uppercase font-bold tracking-widest mb-1">Fee Balance</p>
            <div className={`font-bold text-lg ${child.has_fee_debt ? 'text-red-600' : 'text-green-600'}`}>
              {child.has_fee_debt ? `₦${child.outstanding_balance.toLocaleString()}` : "₦0 · Paid ✓"}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

const NoticesCard = ({ notices, onAction }) => {
  const getBadgeStyles = (color) => {
    const styles = {
      red: 'bg-red-50 text-red-700 border-red-100',
      amber: 'bg-amber-50 text-amber-700 border-amber-100',
      green: 'bg-green-50 text-green-700 border-green-100',
      gray: 'bg-gray-50 text-gray-700 border-gray-100',
      blue: 'bg-blue-50 text-blue-700 border-blue-100',
    };
    return styles[color] || styles.gray;
  };

  const getIcon = (type) => {
    switch (type) {
      case 'finance': return <FinanceIcon className="w-5 h-5" />;
      case 'event': return <Calendar className="w-5 h-5" />;
      case 'achievement': return <Award className="w-5 h-5" />;
      default: return <Bell className="w-5 h-5" />;
    }
  };

  return (
    <div className="bg-white rounded-3xl p-6 shadow-sm border border-gray-100 lg:col-span-2 flex flex-col h-full dark:bg-slate-900 dark:border-slate-700">
      <div className="flex items-center justify-between mb-6">
        <h2 className="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
          <Bell className="w-5 h-5 text-blue-600" />
          Latest Notices
        </h2>
        <button onClick={onAction} className="text-sm font-semibold text-blue-600 hover:text-blue-800 transition-colors flex items-center gap-1 group">
          View all notices <ArrowRight className="w-4 h-4 group-hover:translate-x-1 transition-transform" />
        </button>
      </div>

      <div className="space-y-4 flex-1">
        {notices.map((notice, idx) => (
          <div key={idx} className="group p-4 rounded-2xl hover:bg-gray-50 transition-all border border-transparent hover:border-gray-100 cursor-pointer dark:hover:bg-slate-800 dark:hover:border-slate-700">
            <div className="flex gap-4">
              <div className={`w-12 h-12 rounded-xl flex items-center justify-center shrink-0 ${getBadgeStyles(notice.badge_colour)}`}>
                {getIcon(notice.type)}
              </div>
              <div className="flex-1 min-w-0">
                <div className="flex items-start justify-between gap-2 mb-1">
                  <h3 className="font-bold text-[13px] text-gray-900 dark:text-white line-clamp-1">{notice.title}</h3>
                  <span className={`text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full border ${getBadgeStyles(notice.badge_colour)}`}>
                    {notice.type}
                  </span>
                </div>
                <p className="text-[11px] text-gray-500 line-clamp-2 mb-2 leading-relaxed">
                  {notice.description}
                </p>
                <div className="flex items-center gap-3 text-[10px] text-gray-400 font-medium">
                  <span className="flex items-center gap-1"><Clock className="w-3 h-3" /> {notice.time}</span>
                  <span className="flex items-center gap-1"><Users className="w-3 h-3" /> {notice.sender}</span>
                </div>
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};

const FeeSummaryCard = ({ child, onAction }) => {
  return (
    <div className="bg-white rounded-3xl p-6 shadow-sm border border-gray-100 h-full flex flex-col dark:bg-slate-900 dark:border-slate-700">
      <h2 className="text-lg font-bold text-gray-900 dark:text-white mb-6 flex items-center gap-2">
        <FinanceIcon className="w-5 h-5 text-red-500" />
        Fee Summary
      </h2>

      <div className="mb-6">
        <span className="text-[10px] font-bold text-gray-400 uppercase tracking-widest block mb-4">Current Term</span>
        <h3 className="text-xl font-bold text-gray-800 dark:text-slate-200">{child.current_term} {child.current_session}</h3>
      </div>

      <div className="space-y-4 flex-1">
        <div className="flex justify-between items-center text-sm">
          <span className="text-gray-500 font-medium">Term fees</span>
          <span className="text-gray-900 font-bold dark:text-white">₦850,000</span>
        </div>
        <div className="flex justify-between items-center text-sm">
          <span className="text-gray-500 font-medium">Amount paid</span>
          <span className="text-green-600 font-bold">₦{child.has_fee_debt ? "665,000" : "850,000"}</span>
        </div>

        <div className="h-px bg-gray-100 my-4 dark:bg-slate-700" />

        <div className="flex justify-between items-center">
          <span className="text-gray-900 font-bold dark:text-white">Balance due</span>
          <span className={`text-xl font-black ${child.has_fee_debt ? 'text-red-600' : 'text-green-600'}`}>
            ₦{child.outstanding_balance.toLocaleString()}
          </span>
        </div>
      </div>

      <div className="mt-8 space-y-3">
        {child.has_fee_debt ? (
          <button
            onClick={() => onAction('payment')}
            className="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-4 rounded-2xl shadow-lg shadow-red-100 transition-all active:scale-95"
          >
            Clear Balance
          </button>
        ) : (
          <div className="bg-green-50 border border-green-100 p-4 rounded-2xl flex items-center justify-center gap-2 text-green-700 font-bold text-sm dark:bg-green-950/20 dark:border-green-900/40 dark:text-green-400">
            <CheckCircle2 className="w-5 h-5" />
            All fees paid for this term
          </div>
        )}
        <button
          onClick={() => onAction('statement')}
          className="w-full text-sm font-semibold text-gray-500 hover:text-gray-900 py-2 transition-colors flex items-center justify-center gap-1 dark:text-slate-400 dark:hover:text-white"
        >
          View full statement <ArrowRight className="w-4 h-4" />
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
    ['P', 'P', 'P', 'L', 'P']
  ];

  const getStatusColor = (s) => {
    switch(s) {
      case 'P': return 'bg-green-500';
      case 'A': return 'bg-red-500';
      case 'L': return 'bg-amber-500';
      default: return 'bg-gray-200';
    }
  };

  return (
    <div className="bg-white rounded-3xl p-6 shadow-sm border border-gray-100 h-full flex flex-col dark:bg-slate-900 dark:border-slate-700">
      <h2 className="text-lg font-bold text-gray-900 dark:text-white mb-6 flex items-center gap-2">
        <Layout className="w-5 h-5 text-teal-500" />
        Attendance This Term
      </h2>

      <div className="flex flex-col items-center mb-8">
        <div className="relative w-32 h-32 flex items-center justify-center">
          <svg className="w-full h-full transform -rotate-90">
            <circle cx="64" cy="64" r={radius} fill="transparent" stroke="#f3f4f6" strokeWidth="8" />
            <circle
              cx="64" cy="64" r={radius} fill="transparent" stroke={getColor(pct)} strokeWidth="8"
              strokeDasharray={circumference} strokeDashoffset={offset} strokeLinecap="round"
              className="transition-all duration-1000 ease-out"
            />
          </svg>
          <div className="absolute inset-0 flex flex-col items-center justify-center">
            <span className="text-2xl font-black text-gray-900 dark:text-white">{pct}%</span>
            <span className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Present</span>
          </div>
        </div>

        <div className="flex gap-2 mt-4">
          <span className="text-[10px] bg-gray-50 px-2 py-1 rounded-lg text-gray-600 font-bold dark:bg-slate-800 dark:text-slate-400">56 days present</span>
          <span className="text-[10px] bg-gray-50 px-2 py-1 rounded-lg text-gray-600 font-bold dark:bg-slate-800 dark:text-slate-400">4 absences</span>
          <span className="text-[10px] bg-gray-50 px-2 py-1 rounded-lg text-gray-600 font-bold dark:bg-slate-800 dark:text-slate-400">0 late</span>
        </div>
      </div>

      <div className="space-y-4 flex-1">
        <div className="grid grid-cols-5 gap-2">
          {days.map(d => <span key={d} className="text-[10px] font-bold text-gray-400 text-center">{d}</span>)}
          {grid[0].map((s, i) => (
            <div key={i} className={`h-6 rounded-lg ${getStatusColor(s)} opacity-80 flex items-center justify-center text-[10px] text-white font-bold`}>{s}</div>
          ))}
          {grid[1].map((s, i) => (
            <div key={i} className={`h-6 rounded-lg ${getStatusColor(s)} opacity-80 flex items-center justify-center text-[10px] text-white font-bold`}>{s}</div>
          ))}
        </div>
        <div className="flex items-center justify-between text-[10px] text-gray-400 font-bold px-1">
          <span>AM Session</span>
          <span>PM Session</span>
        </div>
      </div>

      <button onClick={onAction} className="mt-6 text-sm font-semibold text-teal-600 hover:text-teal-800 transition-colors flex items-center justify-center gap-1">
        Full attendance record <ArrowRight className="w-4 h-4" />
      </button>
    </div>
  );
};

const ResultsCard = ({ child, onAction }) => {
  const results = child.results || [];

  if (child.result_locked) {
    return (
      <div className="bg-white rounded-3xl p-6 shadow-sm border border-gray-100 lg:col-span-2 overflow-hidden relative dark:bg-slate-900 dark:border-slate-700">
        <h2 className="text-lg font-bold text-gray-900 mb-6 flex items-center gap-2">
          <FileBarChart className="w-5 h-5 text-primary" />
          Academic Results
        </h2>

        <div className="relative">
          <div className="blur-md select-none opacity-20 space-y-4">
            {[1,2,3,4,5].map(i => (
              <div key={i} className="flex items-center justify-between p-4 bg-gray-100 rounded-2xl">
                <div className="h-4 w-32 bg-gray-300 rounded" />
                <div className="h-4 w-12 bg-gray-300 rounded" />
                <div className="h-4 w-12 bg-gray-300 rounded" />
              </div>
            ))}
          </div>

          <div className="absolute inset-0 flex flex-col items-center justify-center bg-white/40 backdrop-blur-[2px] rounded-3xl dark:bg-slate-900/60">
            <div className="bg-red-50 p-4 rounded-full mb-4 animate-bounce">
              <Lock className="w-10 h-10 text-red-600" />
            </div>
            <h3 className="text-2xl font-black text-gray-900 dark:text-white mb-2">Results Locked</h3>
            <p className="text-gray-600 dark:text-slate-400 text-center max-w-sm mb-6 px-4">
              {child.first_name}'s {child.current_term} results are ready. Clear the outstanding balance of ₦{child.outstanding_balance.toLocaleString()} to unlock.
            </p>
            <button
              onClick={() => onAction('unlock')}
              className="bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-8 rounded-2xl shadow-xl shadow-red-100 transition-all active:scale-95 flex items-center gap-2"
            >
              Pay & Unlock Results <FinanceIcon className="w-5 h-5" />
            </button>
            <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 pointer-events-none opacity-[0.05] whitespace-nowrap rotate-[-20deg]">
              <span className="text-[120px] font-black text-red-600 tracking-tighter">LOCKED</span>
            </div>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="bg-white rounded-3xl p-6 shadow-sm border border-gray-100 lg:col-span-2 dark:bg-slate-900 dark:border-slate-700">
      <div className="flex items-center justify-between mb-6">
        <h2 className="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
          <FileBarChart className="w-5 h-5 text-primary" />
          Academic Results
        </h2>
        <div className="flex gap-2">
          <button onClick={() => onAction('download')} className="p-2 hover:bg-gray-100 rounded-xl text-gray-500 transition-colors" title="Download PDF">
            <Download className="w-5 h-5" />
          </button>
        </div>
      </div>

      <div className="overflow-hidden">
        <table className="w-full">
          <thead>
            <tr className="border-b border-gray-100 dark:border-slate-700">
              <th className="text-left py-3 text-[10px] font-black text-gray-400 uppercase tracking-widest">Subject</th>
              <th className="text-center py-3 text-[10px] font-black text-gray-400 uppercase tracking-widest">Score</th>
              <th className="text-left py-3 text-[10px] font-black text-gray-400 uppercase tracking-widest hidden sm:table-cell">Grade</th>
              <th className="text-right py-3 text-[10px] font-black text-gray-400 uppercase tracking-widest">Status</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-50 dark:divide-slate-700">
            {results.map((r, i) => (
              <tr key={i} className="group hover:bg-gray-50 transition-colors dark:hover:bg-slate-800">
                <td className="py-4">
                  <div className="flex flex-col">
                    <span className="text-sm font-bold text-gray-800 dark:text-slate-200">{r.subject}</span>
                    <span className="text-[10px] text-gray-400 sm:hidden">{r.grade}</span>
                  </div>
                </td>
                <td className="py-4 text-center">
                  <div className="flex flex-col items-center gap-1">
                    <span className="text-sm font-black text-gray-900 dark:text-white">{r.score}</span>
                    <div className="w-12 h-1 bg-gray-100 rounded-full overflow-hidden dark:bg-slate-700">
                      <div
                        className={`h-full ${r.score >= 70 ? 'bg-green-500' : r.score >= 50 ? 'bg-amber-500' : 'bg-red-500'}`}
                        style={{ width: `${r.score}%` }}
                      />
                    </div>
                  </div>
                </td>
                <td className="py-4 text-left hidden sm:table-cell">
                  <span className="text-xs font-semibold text-gray-600 dark:text-slate-400">{r.grade}</span>
                </td>
                <td className="py-4 text-right">
                  <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${r.score >= 50 ? 'text-green-600 bg-green-50' : 'text-red-600 bg-red-50'}`}>
                    {r.score >= 50 ? 'PASSED' : 'RETAKE'}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="mt-6 flex flex-col sm:flex-row items-center justify-between p-4 bg-gray-50 rounded-2xl gap-4 dark:bg-slate-800">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center dark:bg-primary/10">
            <Award className="w-5 h-5 text-primary" />
          </div>
          <div>
            <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest leading-none mb-1">Class Position</p>
            <p className="text-sm font-black text-gray-900 dark:text-white">3rd of 28 students</p>
          </div>
        </div>
        <div className="flex gap-3 w-full sm:w-auto">
          <button
            onClick={() => onAction('download')}
            className="flex-1 sm:flex-none text-xs font-bold text-gray-600 hover:text-gray-900 px-4 py-2 rounded-xl border border-gray-200 bg-white transition-all flex items-center justify-center gap-2 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-300 dark:hover:text-white"
          >
            Download PDF <Download className="w-3 h-3" />
          </button>
          <button
            onClick={() => onAction('analysis')}
            className="flex-1 sm:flex-none text-xs font-bold text-primary hover:text-primary px-4 py-2 transition-all flex items-center justify-center gap-1"
          >
            Full Analysis <ArrowRight className="w-3 h-3" />
          </button>
        </div>
      </div>
    </div>
  );
};

const TimetableCard = ({ timetable, onAction }) => {
  return (
    <div className="bg-white rounded-3xl p-6 shadow-sm border border-gray-100 h-full flex flex-col dark:bg-slate-900 dark:border-slate-700">
      <div className="flex items-center justify-between mb-6">
        <h2 className="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
          <Calendar className="w-5 h-5 text-orange-500" />
          Today's Timetable
        </h2>
        <span className="text-[10px] font-bold text-gray-400 uppercase tracking-widest">
          Wed, 15 Jan
        </span>
      </div>

      <div className="space-y-3 flex-1">
        {timetable.map((lesson, idx) => (
          <div
            key={idx}
            className={`p-3 rounded-2xl border transition-all ${
              lesson.status === 'current'
                ? 'bg-blue-50 border-blue-100 shadow-sm'
                : lesson.status === 'done'
                ? 'bg-gray-50/50 border-transparent opacity-60 dark:bg-slate-800/30'
                : 'bg-white border-gray-100 dark:bg-slate-800 dark:border-slate-700'
            }`}
          >
            <div className="flex justify-between items-start gap-3">
              <div className="shrink-0 pt-1">
                <Clock className={`w-3.5 h-3.5 ${lesson.status === 'current' ? 'text-blue-600' : 'text-gray-400'}`} />
              </div>
              <div className="flex-1 min-w-0">
                <div className="flex justify-between items-center mb-1">
                  <span className="text-[10px] font-bold text-gray-400">{lesson.time}</span>
                  {lesson.status === 'current' && (
                    <span className="text-[9px] font-black bg-blue-600 text-white px-2 py-0.5 rounded-full uppercase tracking-tighter">Now</span>
                  )}
                </div>
                <h4 className={`text-sm font-bold ${lesson.status === 'current' ? 'text-blue-900 dark:text-blue-300' : 'text-gray-800 dark:text-slate-200'}`}>
                  {lesson.subject}
                </h4>
                <div className="flex items-center justify-between mt-1">
                  <span className="text-[11px] text-gray-500 flex items-center gap-1">
                    <User className="w-3 h-3" /> {lesson.teacher}
                  </span>
                  <span className="text-[11px] text-gray-400 font-bold uppercase tracking-widest">{lesson.room}</span>
                </div>
              </div>
            </div>
          </div>
        ))}
      </div>

      <button onClick={onAction} className="mt-6 text-sm font-semibold text-orange-600 hover:text-orange-800 transition-colors flex items-center justify-center gap-1">
        Full timetable <ArrowRight className="w-4 h-4" />
      </button>
    </div>
  );
};

const QuickContactCard = ({ contacts, onAction }) => {
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
    <div className="bg-white rounded-3xl p-6 shadow-sm border border-gray-100 h-full flex flex-col dark:bg-slate-900 dark:border-slate-700">
      <h2 className="text-lg font-bold text-gray-900 dark:text-white mb-6 flex items-center gap-2">
        <MessageSquare className="w-5 h-5 text-purple-500" />
        Quick Contact
      </h2>

      <div className="space-y-3 mb-8">
        {contacts.map((c, i) => (
          <div key={i} className="flex items-center justify-between p-3 rounded-2xl bg-gray-50 hover:bg-gray-100 transition-colors cursor-pointer group dark:bg-slate-800 dark:hover:bg-slate-700">
            <div>
              <p className="text-xs font-bold text-gray-800 dark:text-slate-200">{c.office}</p>
              <p className="text-[10px] text-gray-500">{c.phone}</p>
            </div>
            <div className="flex gap-2">
              <button className="p-2 bg-white rounded-xl shadow-sm text-gray-400 group-hover:text-blue-600 transition-colors dark:bg-slate-700 dark:text-slate-400">
                <Phone className="w-4 h-4" />
              </button>
              <button className="p-2 bg-white rounded-xl shadow-sm text-gray-400 group-hover:text-purple-600 transition-colors dark:bg-slate-700 dark:text-slate-400">
                <Mail className="w-4 h-4" />
              </button>
            </div>
          </div>
        ))}
      </div>

      <div className="bg-purple-50 p-5 rounded-3xl flex-1 dark:bg-purple-950/20">
        <h3 className="text-xs font-bold text-purple-900 dark:text-purple-300 mb-4 uppercase tracking-widest">Message the school</h3>
        <form onSubmit={handleSubmit} className="space-y-4">
          <select
            value={to}
            onChange={(e) => setTo(e.target.value)}
            className="w-full bg-white border border-purple-100 rounded-xl px-4 py-2 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all dark:bg-slate-800 dark:border-slate-600 dark:text-slate-200"
          >
            {contacts.map(c => <option key={c.office}>{c.office}</option>)}
          </select>
          <textarea
            value={msg}
            onChange={(e) => setMsg(e.target.value)}
            placeholder="Write a message to the school..."
            rows={3}
            className="w-full bg-white border border-purple-100 rounded-xl px-4 py-3 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all resize-none dark:bg-slate-800 dark:border-slate-600 dark:text-slate-200 dark:placeholder-slate-500"
          />
          <button
            type="submit"
            className="w-full bg-primary hover:bg-primary/90 text-white font-bold py-3 rounded-xl shadow-lg shadow-blue-100 transition-all active:scale-95 flex items-center justify-center gap-2"
          >
            Send Message <Mail className="w-4 h-4" />
          </button>
        </form>
      </div>
    </div>
  );
};

const NotificationDropdown = ({ isOpen, onClose, notifications }) => {
  const dropdownRef = useRef(null);

  useEffect(() => {
    const handleClickOutside = (e) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target)) {
        onClose();
      }
    };
    if (isOpen) document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  return (
    <div
      ref={dropdownRef}
      className="absolute right-0 mt-3 w-[320px] bg-white rounded-3xl shadow-2xl border border-gray-100 z-50 overflow-hidden animate-in fade-in zoom-in duration-200 origin-top-right dark:bg-slate-900 dark:border-slate-700"
    >
      <div className="p-4 border-b border-gray-50 flex items-center justify-between dark:border-slate-700">
        <h3 className="font-black text-gray-900 dark:text-white">Notifications</h3>
        <button className="text-xs font-bold text-blue-600 hover:text-blue-800">Mark all as read</button>
      </div>
      <div className="max-h-[360px] overflow-y-auto">
        {notifications.map((n) => (
          <div key={n.id} className="p-4 hover:bg-gray-50 transition-colors cursor-pointer flex gap-4 items-start border-b border-gray-50/50 dark:hover:bg-slate-800 dark:border-slate-700/50">
            <div className={`mt-1.5 w-2 h-2 rounded-full shrink-0 ${n.unread ? `bg-${n.color}-500 shadow-[0_0_8px] shadow-${n.color}-500/50` : 'border border-gray-300'}`} />
            <div className="flex-1">
              <p className={`text-sm ${n.unread ? 'font-bold text-gray-900 dark:text-white' : 'text-gray-600 dark:text-slate-400'}`}>{n.title}</p>
              <p className="text-[10px] text-gray-400 mt-1 font-medium">{n.time}</p>
            </div>
          </div>
        ))}
      </div>
      <div className="p-4 text-center">
        <button className="text-xs font-bold text-gray-500 hover:text-gray-900 transition-colors flex items-center justify-center gap-1 mx-auto dark:text-slate-400 dark:hover:text-white">
          View all notifications <ChevronRight className="w-3 h-3" />
        </button>
      </div>
    </div>
  );
};

// =======================================================
// MAIN COMPONENT
// =======================================================

export default function ParentDashboard() {
  const [activeChildId, setActiveChildId] = useState(PARENT_DATA.children[0].id);
  const [isBannerDismissed, setIsBannerDismissed] = useState(false);
  const [isNotifOpen, setIsNotifOpen] = useState(false);
  const [toasts, setToasts] = useState([]);

  const activeChild = PARENT_DATA.children.find(c => c.id === activeChildId);

  const addToast = (message) => {
    const id = Date.now();
    setToasts(prev => [...prev, { id, message }]);
    setTimeout(() => {
      setToasts(prev => prev.filter(t => t.id !== id));
    }, 4000);
  };

  const removeToast = (id) => {
    setToasts(prev => prev.filter(t => t.id !== id));
  };

  const handleAction = (type, data) => {
    switch (type) {
      case 'payment':
      case 'unlock':
        addToast("Redirecting to payment gateway...");
        break;
      case 'statement':
        addToast("Feature coming soon");
        break;
      case 'download':
        addToast("Preparing PDF download...");
        break;
      case 'send_message':
        addToast(`Message sent to ${data.to}`);
        break;
      case 'analysis':
      case 'attendance':
      case 'notices':
      case 'timetable':
        addToast("Feature coming soon");
        break;
      default:
        addToast("Action triggered");
    }
  };

  return (
    <>
      <Head title="Parent Dashboard" />

      <div className="flex flex-1 flex-col gap-6 p-4 lg:p-6">
        {/* Page header: welcome + child switcher + notifications */}
        <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
          <div>
            <h3 className="text-2xl font-black text-gray-900 dark:text-white tracking-tight">
              Welcome back, {PARENT_DATA.name.split(' ')[1]}!
            </h3>
            <p className="text-gray-500 mt-1 font-medium flex items-center gap-2 text-sm">
              Here's what's happening with your children today at Brookstone.
              <span className="w-1.5 h-1.5 rounded-full bg-green-500 inline-block animate-pulse" />
            </p>
          </div>

          <div className="flex items-center gap-3 shrink-0">
            {/* Child switcher */}
            <div className="flex gap-1 bg-gray-100 p-1 rounded-2xl dark:bg-slate-800">
              {PARENT_DATA.children.map(child => (
                <button
                  key={child.id}
                  onClick={() => setActiveChildId(child.id)}
                  className={`px-4 py-1.5 rounded-xl text-xs font-bold transition-all ${
                    activeChildId === child.id
                      ? 'bg-white text-gray-900 shadow-sm dark:bg-slate-700 dark:text-white'
                      : 'text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200'
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
                className={`p-2.5 rounded-2xl transition-all relative ${isNotifOpen ? 'bg-blue-50 text-blue-600 dark:bg-blue-950/30' : 'bg-gray-100 text-gray-500 hover:bg-gray-200 dark:bg-slate-800 dark:text-slate-400 dark:hover:bg-slate-700'}`}
              >
                <Bell className="w-5 h-5" />
                <span className="absolute top-2 right-2 w-2.5 h-2.5 bg-red-500 border-2 border-white rounded-full dark:border-slate-900" />
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
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          <NoticesCard
            notices={activeChild.notices}
            onAction={() => handleAction('notices')}
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
        <footer className="mt-4 pt-8 border-t border-sidebar-border/50 flex flex-col sm:flex-row justify-between items-center gap-4 text-gray-400 text-xs font-bold uppercase tracking-widest">
          <span>Brookstone School Management System</span>
          <div className="flex gap-6">
            <a href="#" className="hover:text-blue-600 transition-colors">Support</a>
            <a href="#" className="hover:text-blue-600 transition-colors">Privacy</a>
            <a href="#" className="hover:text-blue-600 transition-colors">Terms</a>
          </div>
        </footer>
      </div>

      <Toast toasts={toasts} onDismiss={removeToast} />
    </>
  );
}

ParentDashboard.layout = {
  breadcrumbs: [
    { title: 'Parent Dashboard', href: '/parent/dashboard' },
  ],
};
