import { useState } from 'react';
import BoardingHouses from '@/components/school-setup/BoardingHouses';
import GradingSystems from '@/components/school-setup/GradingSystems';
import SectionsYearGroups from '@/components/school-setup/SectionsYearGroups';
import SubjectManager from '@/components/school-setup/SubjectManager';
import TermsAndSessions from '@/components/school-setup/TermsAndSessions';
import YearEndMigration from '@/components/school-setup/YearEndMigration';

const tabs = [
    { id: 'sections', label: 'Sections & Year Groups' },
    { id: 'terms', label: 'Terms & Sessions' },
    { id: 'subjects', label: 'Subject Manager' },
    { id: 'grading', label: 'Grading Systems' },
    { id: 'boarding', label: 'Boarding Houses' },
    { id: 'migration', label: 'Year-End Migration' },
] as const;

type TabId = (typeof tabs)[number]['id'];

export default function SchoolSetup() {
    const [activeTab, setActiveTab] = useState<TabId>('sections');

    return (
        <div className="space-y-6 pb-10">
            <div className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div className="space-y-3">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-900 dark:text-white">
                            School Setup
                        </h1>
                        <p className="mt-2 text-sm text-gray-600 dark:text-slate-400">
                            Configure sections, terms, subjects, grading and
                            more.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {tabs.map((tab) => {
                            const isActive = activeTab === tab.id;

                            return (
                                <button
                                    key={tab.id}
                                    type="button"
                                    onClick={() => setActiveTab(tab.id)}
                                    className={`rounded-full px-4 py-2 text-sm font-medium transition ${isActive ? 'bg-primary text-white' : 'text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200'}`}
                                >
                                    {tab.label}
                                </button>
                            );
                        })}
                    </div>
                </div>
            </div>

            <div>
                {activeTab === 'sections' && <SectionsYearGroups />}
                {activeTab === 'terms' && <TermsAndSessions />}
                {activeTab === 'subjects' && <SubjectManager />}
                {activeTab === 'grading' && <GradingSystems />}
                {activeTab === 'boarding' && <BoardingHouses />}
                {activeTab === 'migration' && <YearEndMigration />}
            </div>
        </div>
    );
}
