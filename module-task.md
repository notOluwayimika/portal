You are building the School Setup module frontend for the Brookstone School Portal.
Stack: React 18 + Tailwind CSS. Laravel API is already built and available.

=======================================================
CONTEXT
=======================================================
This is the School Setup page used exclusively by Super Admins and Administrators.
It has 6 tabs. Build all 6 React components with full UI, interactions, and mock
data. No real API calls yet — use local useState and mock data arrays to simulate
all CRUD operations. API integration will be added later.

Primary brand colour: #185FA5
Use Tailwind CSS only. No custom CSS files.

=======================================================
FILE STRUCTURE TO GENERATE
=======================================================

resources/js/
├── pages/admin/
│   └── SchoolSetup.jsx               ← main layout + tab switcher
├── components/school-setup/
│   ├── SectionsYearGroups.jsx        ← Tab 1
│   ├── TermsAndSessions.jsx          ← Tab 2
│   ├── SubjectManager.jsx            ← Tab 3 (with drag & drop)
│   ├── GradingSystems.jsx            ← Tab 4
│   ├── BoardingHouses.jsx            ← Tab 5
│   └── YearEndMigration.jsx          ← Tab 6
└── components/ui/
    ├── Modal.jsx                     ← reusable modal with backdrop + ESC close
    ├── Toast.jsx                     ← success/error toast notification
    ├── ConfirmDialog.jsx             ← reusable delete/action confirm modal
    ├── Badge.jsx                     ← coloured status/role badge
    └── EmptyState.jsx                ← empty list placeholder UI

=======================================================
SHARED UI RULES (apply to ALL components)
=======================================================
- Tailwind only. No inline style objects except for brand colour #185FA5 where
  Tailwind's built-in blues don't match closely enough
- All cards: white bg, rounded-xl, border border-gray-200, shadow-sm
- All primary buttons: bg-[#185FA5] text-white rounded-lg hover:bg-[#0f4a82]
- All secondary buttons: border border-gray-300 text-gray-600 rounded-lg hover:bg-gray-50
- Danger buttons: bg-red-50 text-red-700 border border-red-200 hover:bg-red-100
- All modals: fixed inset-0 bg-black/40 flex items-center justify-center z-50
- Modal panels: bg-white rounded-2xl shadow-xl p-6 w-full max-w-md
- Close modal on ESC key and backdrop click
- Toast notifications appear bottom-right, auto-dismiss after 3 seconds
- Confirm before any delete using ConfirmDialog
- Show spinner (animate-spin) while simulating async operations (use setTimeout 600ms)
- Show EmptyState component when a list has zero items
- All form inputs: border border-gray-300 rounded-lg px-3 py-2 text-sm
  focus:outline-none focus:ring-2 focus:ring-[#185FA5] focus:border-transparent
- All section/card headers: flex items-center justify-between mb-4
  with title in font-semibold text-gray-800 and action button on the right

=======================================================
MOCK DATA TO USE
=======================================================

SCHOOL SECTIONS:
[
  {
    id: 1, name: "Secondary School", slug: "secondary", is_active: true,
    yearGroups: [
      { id: 1, name: "Year 7",  classArms: [{id:1,name:"7A",type:"IGCSE"},{id:2,name:"7B",type:"IGCSE"}] },
      { id: 2, name: "Year 8",  classArms: [{id:3,name:"8A",type:"IGCSE"},{id:4,name:"8B",type:"IGCSE"}] },
      { id: 3, name: "Year 9",  classArms: [{id:5,name:"9A",type:"IGCSE"},{id:6,name:"9B",type:"IGCSE"}] },
      { id: 4, name: "Year 10", classArms: [{id:7,name:"10A",type:"IGCSE"},{id:8,name:"10B",type:"IGCSE"},{id:9,name:"10C",type:"WAEC"}] },
      { id: 5, name: "Year 11", classArms: [{id:10,name:"11A",type:"IGCSE"},{id:11,name:"11B",type:"WAEC"}] },
      { id: 6, name: "Year 12", classArms: [{id:12,name:"12A",type:"WAEC"}] },
    ]
  },
  {
    id: 2, name: "Primary School", slug: "primary", is_active: true,
    yearGroups: [
      { id: 7,  name: "Pre-Kinderfun", classArms: [{id:13,name:"PKF A",type:"Standard"}] },
      { id: 8,  name: "Kinderfun",     classArms: [{id:14,name:"KF A",type:"Standard"}] },
      { id: 9,  name: "Nursery",       classArms: [{id:15,name:"Nur A",type:"Standard"},{id:16,name:"Nur B",type:"Standard"}] },
      { id: 10, name: "Reception",     classArms: [{id:17,name:"Rec A",type:"Standard"}] },
      { id: 11, name: "Primary 1",     classArms: [{id:18,name:"P1A",type:"Standard"},{id:19,name:"P1B",type:"Standard"}] },
      { id: 12, name: "Primary 2",     classArms: [{id:20,name:"P2A",type:"Standard"}] },
      { id: 13, name: "Primary 3",     classArms: [{id:21,name:"P3A",type:"Standard"}] },
      { id: 14, name: "Primary 4",     classArms: [{id:22,name:"P4A",type:"Standard"}] },
      { id: 15, name: "Primary 5",     classArms: [{id:23,name:"P5A",type:"Standard"},{id:24,name:"P5B",type:"Standard"}] },
      { id: 16, name: "Primary 6",     classArms: [{id:25,name:"P6A",type:"Standard"}] },
    ]
  },
  {
    id: 3, name: "IFY Abuja", slug: "ify-abuja", is_active: true,
    yearGroups: [
      { id: 17, name: "IFY Year 1", classArms: [{id:26,name:"IFY-ABJ-A",type:"IFY"},{id:27,name:"IFY-ABJ-Hybrid",type:"IFY"}] }
    ]
  },
  {
    id: 4, name: "IFY PH", slug: "ify-ph", is_active: true,
    yearGroups: [
      { id: 18, name: "IFY Year 1", classArms: [{id:28,name:"IFY-PH-A",type:"IFY"},{id:29,name:"IFY-PH-Hybrid",type:"IFY"}] }
    ]
  }
]

ACADEMIC SESSIONS:
[
  {
    id: 1, name: "2025/2026", is_current: true,
    terms: [
      { id:1, name:"First Term",  start_date:"2025-09-09", end_date:"2025-12-13", status:"completed" },
      { id:2, name:"Second Term", start_date:"2026-01-13", end_date:"2026-04-04", status:"active"    },
      { id:3, name:"Third Term",  start_date:"2026-04-27", end_date:"2026-07-18", status:"upcoming"  },
    ]
  },
  {
    id: 2, name: "2024/2025", is_current: false,
    terms: [
      { id:4, name:"First Term",  start_date:"2024-09-10", end_date:"2024-12-14", status:"completed" },
      { id:5, name:"Second Term", start_date:"2025-01-14", end_date:"2025-04-05", status:"completed" },
      { id:6, name:"Third Term",  start_date:"2025-04-28", end_date:"2025-07-19", status:"completed" },
    ]
  }
]

SUBJECTS:
[
  { id:1,  name:"Mathematics",        sections:["Secondary","IFY"], is_optional:false, order:1 },
  { id:2,  name:"English Language",   sections:["Secondary","Primary"], is_optional:false, order:2 },
  { id:3,  name:"Physics",            sections:["Secondary"], is_optional:true,  order:3 },
  { id:4,  name:"Chemistry",          sections:["Secondary"], is_optional:true,  order:4 },
  { id:5,  name:"Biology",            sections:["Secondary"], is_optional:true,  order:5 },
  { id:6,  name:"Further Mathematics",sections:["Secondary"], is_optional:true,  order:6 },
  { id:7,  name:"Economics",          sections:["Secondary","IFY"], is_optional:true, order:7 },
  { id:8,  name:"Geography",          sections:["Secondary"], is_optional:true,  order:8 },
  { id:9,  name:"Civic Education",    sections:["Secondary","Primary"], is_optional:false, order:9 },
  { id:10, name:"Basic Science",      sections:["Primary"], is_optional:false, order:10 },
  { id:11, name:"Quantitative Reasoning", sections:["Primary"], is_optional:false, order:11 },
  { id:12, name:"Verbal Reasoning",   sections:["Primary"], is_optional:false, order:12 },
]

GRADING SYSTEMS:
[
  {
    id:1, name:"Secondary IGCSE", type:"igcse",
    applicable_to: ["Year 7","Year 8","Year 9","Year 10 IGCSE","Year 11 IGCSE"],
    bands: [
      {id:1,min:91,  max:100,  grade:"A*",label:"",    gp:5.0},
      {id:2,min:80,  max:90.9, grade:"A", label:"",    gp:5.0},
      {id:3,min:70,  max:79.9, grade:"B", label:"",    gp:4.0},
      {id:4,min:60,  max:69.9, grade:"C", label:"",    gp:3.0},
      {id:5,min:50,  max:59.9, grade:"D", label:"",    gp:2.0},
      {id:6,min:40,  max:49.9, grade:"E", label:"",    gp:1.0},
      {id:7,min:0,   max:39.9, grade:"F", label:"",    gp:0.0},
    ]
  },
  {
    id:2, name:"Secondary WAEC", type:"waec",
    applicable_to: ["Year 10 WAEC","Year 11 WAEC","Year 12"],
    bands: [
      {id:8, min:75,  max:100,  grade:"A1",label:"",gp:5.0},
      {id:9, min:70,  max:74.9, grade:"B2",label:"",gp:4.5},
      {id:10,min:65,  max:69.9, grade:"B3",label:"",gp:4.0},
      {id:11,min:60,  max:64.9, grade:"C4",label:"",gp:3.5},
      {id:12,min:55,  max:59.9, grade:"C5",label:"",gp:3.0},
      {id:13,min:50,  max:54.9, grade:"C6",label:"",gp:2.5},
      {id:14,min:45,  max:49.9, grade:"D7",label:"",gp:2.0},
      {id:15,min:40,  max:44.9, grade:"E8",label:"",gp:1.0},
      {id:16,min:0,   max:39.9, grade:"F9",label:"",gp:0.0},
    ]
  },
  {
    id:3, name:"Primary", type:"primary",
    applicable_to: ["Primary 1","Primary 2","Primary 3","Primary 4","Primary 5","Primary 6"],
    bands: [
      {id:17,min:90,max:100,grade:"Excellent",     label:"Excellent",     gp:5.0},
      {id:18,min:80,max:89, grade:"Very Good",     label:"Very Good",     gp:4.0},
      {id:19,min:70,max:79, grade:"Good",          label:"Good",          gp:3.0},
      {id:20,min:60,max:69, grade:"Satisfactory",  label:"Satisfactory",  gp:2.0},
      {id:21,min:50,max:59, grade:"Developing",    label:"Developing",    gp:1.0},
      {id:22,min:30,max:49, grade:"Beginning",     label:"Beginning",     gp:0.5},
      {id:23,min:0, max:29, grade:"Needs Support", label:"Needs Support", gp:0.0},
    ]
  },
  {
    id:4, name:"IFY (NCUK)", type:"ify",
    applicable_to: ["IFY Abuja","IFY PH"],
    bands: [
      {id:24,min:80,max:100,grade:"A*",label:"A*",gp:56},
      {id:25,min:70,max:79, grade:"A", label:"A", gp:48},
      {id:26,min:60,max:69, grade:"B", label:"B", gp:40},
      {id:27,min:50,max:59, grade:"C", label:"C", gp:32},
      {id:28,min:40,max:49, grade:"D", label:"D", gp:24},
      {id:29,min:35,max:39, grade:"E", label:"E", gp:16},
      {id:30,min:0, max:34, grade:"U", label:"U", gp:0 },
    ]
  }
]

BOARDING HOUSES:
[
  { id:1, name:"Phoenix", gender:"Boys",  year_groups:["Year 7","Year 8","Year 9"] },
  { id:2, name:"Iris",    gender:"Girls", year_groups:["Year 7","Year 8","Year 9"] },
  { id:3, name:"Atlas",   gender:"Boys",  year_groups:["Year 10","Year 11"] },
  { id:4, name:"Lotus",   gender:"Girls", year_groups:["Year 10","Year 11"] },
  { id:5, name:"Zenith",  gender:"Boys",  year_groups:["Year 12"] },
  { id:6, name:"Zenith",  gender:"Girls", year_groups:["Year 12"] },
  { id:7, name:"Summit",  gender:"Boys",  year_groups:["IFY Year 1"] },
  { id:8, name:"Aurora",  gender:"Girls", year_groups:["IFY Year 1"] },
]

=======================================================
TAB-BY-TAB COMPONENT SPECS
=======================================================

--- TAB 1: SectionsYearGroups.jsx ---
- Render each section as a collapsible accordion card
- Collapsed state shows: coloured dot, section name, summary (e.g. "6 year groups · 14 class arms")
- Expanded state shows a list of year group rows
- Each year group row shows: year group name, class arm pills, "+ Add arm" dashed pill, edit icon
- Class arm pill colours: IGCSE=blue, WAEC=green, IFY=teal, Standard=amber
- Clicking a class arm pill opens an edit popover/modal: rename arm, change curriculum type
- "+ Add arm" opens a small inline form (name input + type select + Save)
- "+ Add section" button top right opens a modal: section name input
- "+ Add year group" button per section opens a modal: year group name
- Delete section/year group: show ConfirmDialog, then remove from state

--- TAB 2: TermsAndSessions.jsx ---
- Show a session selector dropdown at the top (e.g. "2025/2026 (Current)")
- Display the selected session's 3 terms as rows with: term name, date range, status badge, edit icon
- Status badge: completed=grey, active=green pill, upcoming=blue pill
- Only one term can be "active" — if admin sets a term to active, auto-set others to upcoming/completed
- Edit term opens a modal: name, start date, end date, status select
- "+ New session" button top right opens a modal: session name (e.g. 2026/2027), auto-creates 3 blank terms

--- TAB 3: SubjectManager.jsx ---
- Render subjects as a vertically draggable list using @dnd-kit/sortable
- Each row: [⠿ drag handle] [subject name] [section badges] [Optional badge if applicable] [Edit] [Delete]
- Drag handle: 6 dots icon (⠿), cursor-grab, text-gray-400
- Section badges: "Secondary" in blue, "Primary" in amber, "IFY" in teal
- "Optional" badge: grey, shown only when is_optional is true
- On drag end, update the order field for all subjects in state
- Info banner at top: "Subject order here defines the order on all result templates. Drag rows to reorder."
- "+ Add subject" button opens a modal:
    - Subject name input
    - Section checkboxes: Secondary / Primary / IFY Abuja / IFY PH
    - Optional toggle (switch)
    - Save adds to bottom of list
- Edit subject opens same modal pre-filled
- Delete subject: ConfirmDialog → remove from state

--- TAB 4: GradingSystems.jsx ---
- Render each of the 4 grading systems as its own card
- Card header: system name + "Applied to: Year 7, 8, 9..." in small grey text
- Each system has an editable table with columns: Min Score | Max Score | Grade | Label | GP
- All cells are editable inline (use controlled inputs inside the table cells)
- Each system card has a "Save changes" button at the bottom
- On save: simulate async with setTimeout, show success toast
- Show a small live preview: input a score → show resulting grade in real time
  (input at bottom of each card: "Test a score:" [input] → shows "Grade: B · GP: 4.0")

--- TAB 5: BoardingHouses.jsx ---
- Render houses grouped by gender: "Boys' Houses" section, then "Girls' Houses" section
- Each house card: house name, gender badge, assigned year groups as grey pills
- Edit button opens a modal: house name, gender select, year group multi-select checkboxes
- "+ Add house" button top right opens same modal empty
- Delete: ConfirmDialog → remove from state
- Show total count: e.g. "8 houses · 4 boys · 4 girls"

--- TAB 6: YearEndMigration.jsx ---
- Render 5 step cards vertically, each with: step number circle, step title, step description, checkbox
- Steps unlock in sequence — step N checkbox is disabled until step N-1 is checked
- Step 4 checkbox triggers a simulated download (just show a toast: "Backup downloaded")
- All 5 checked → "Run Year-End Migration" button becomes active (bg-red-600)
- Clicking it opens a ConfirmDialog with:
    Title: "Run Year-End Migration?"
    Body:  "This will promote all students to the next year group.
            This cannot be undone. Type CONFIRM to proceed."
    Input: text field — button only enables when user types exactly "CONFIRM"
- On confirm: show spinner for 1.5s, then show success toast:
    "Migration complete. All students have been promoted."
- Reset all checkboxes after completion

Steps content:
  1. "Confirm session end" — "Verify that the 2025/2026 session closes on July 18, 2026"
  2. "Review graduation list" — "Check Year 12 leavers and IFY completion records"
  3. "Mark repeating students" — "Flag any students staying in their current year group"
  4. "Export database backup" — "Download a full backup before migration runs"
  5. "Run migration" — "Promote all eligible students to the next year group"

=======================================================
SchoolSetup.jsx — MAIN PAGE
=======================================================
- Page title: "School Setup" with a subtitle: "Configure sections, terms, subjects, grading and more"
- Tab bar below the title: 6 tabs as pill buttons
  Active tab: bg-[#185FA5] text-white
  Inactive tab: text-gray-500 hover:text-gray-700 hover:bg-gray-100
- Render the active tab's component below
- Import and render all 6 tab components
- Pass no props — each component manages its own local state with mock data

=======================================================
REUSABLE COMPONENTS
=======================================================

Modal.jsx:
- Props: isOpen, onClose, title, children, size ("sm"|"md"|"lg", default "md")
- Sizes: sm=max-w-sm, md=max-w-md, lg=max-w-2xl
- Close on ESC keydown (useEffect + addEventListener)
- Close on backdrop click (check e.target === e.currentTarget)
- Header: title + X close button
- Body: children with overflow-y-auto max-h-[70vh] px-6 py-4
- Footer slot: pass footer as prop or children

Toast.jsx:
- Props: message, type ("success"|"error"|"info"), onDismiss
- Position: fixed bottom-4 right-4 z-50
- success: green left border, error: red left border, info: blue left border
- Auto-dismiss after 3000ms using useEffect
- Animate in with translate-y + opacity transition

ConfirmDialog.jsx:
- Props: isOpen, onClose, onConfirm, title, message, confirmLabel, dangerous
- If dangerous=true: confirm button is red, otherwise blue
- Optional: requiresTyping (bool) + expectedText (string)
  When requiresTyping=true, show a text input; confirm button disabled until input matches expectedText

Badge.jsx:
- Props: label, variant
- Variants: "igcse"=blue, "waec"=green, "ify"=teal, "standard"=amber,
            "optional"=grey, "active"=green, "completed"=grey, "upcoming"=blue,
            "boys"=blue, "girls"=pink, "secondary"=blue, "primary"=amber
- Returns a styled <span> with rounded-full px-2 py-0.5 text-xs font-medium

EmptyState.jsx:
- Props: icon, title, description, actionLabel, onAction
- Centered layout: icon (large, grey), title, description, optional action button
- Use for empty subject list, empty boarding house list, etc.

=======================================================
INSTALL NOTES
=======================================================
Run before building SubjectManager:
  npm install @dnd-kit/core @dnd-kit/sortable @dnd-kit/utilities

=======================================================
GENERATION INSTRUCTIONS
=======================================================
Generate one file at a time in this order:
1. components/ui/Modal.jsx
2. components/ui/Toast.jsx
3. components/ui/ConfirmDialog.jsx
4. components/ui/Badge.jsx
5. components/ui/EmptyState.jsx
6. components/school-setup/SectionsYearGroups.jsx
7. components/school-setup/TermsAndSessions.jsx
8. components/school-setup/SubjectManager.jsx
9. components/school-setup/GradingSystems.jsx
10. components/school-setup/BoardingHouses.jsx
11. components/school-setup/YearEndMigration.jsx
12. pages/admin/SchoolSetup.jsx

For each file:
- Write the complete file with no placeholders or TODOs
- All mock data lives inside each component as a useState initial value
- No API calls — simulate async with setTimeout(fn, 600)
- Export as default
- Use only React, Tailwind CSS, and @dnd-kit (for SubjectManager only)