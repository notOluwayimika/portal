export interface StudentBulkUpdateColumn {
    name: string;
    group: string;
    required: boolean;
    format: string;
    example: string;
    notes: string;
}

export const STUDENT_BULK_UPDATE_COLUMNS: StudentBulkUpdateColumn[] = [
    {
        name: 'code',
        group: 'Lookup',
        required: true,
        format: 'string',
        example: '20201004',
        notes: 'Student admission number (must exist in this school). Used to find the student.',
    },
    {
        name: 'admission_date',
        group: 'Details',
        required: false,
        format: 'YYYY-MM-DD or DD/MM/YYYY',
        example: '2020-09-05',
        notes: 'Date the student was admitted.',
    },
    {
        name: 'sport_house',
        group: 'Details',
        required: false,
        format: 'string',
        example: 'Emerald',
        notes: 'Sport house name (must exist in this school). Matched case-insensitively.',
    },
    {
        name: 'scholarship',
        group: 'Details',
        required: false,
        format: 'string',
        example: 'BSS',
        notes: 'Scholarship name (must exist in this school). Matched case-insensitively. Leave blank to clear.',
    },
    {
        name: 'nationality',
        group: 'Details',
        required: false,
        format: 'string',
        example: 'Nigerian',
        notes: '',
    },
    {
        name: 'state_of_origin',
        group: 'Details',
        required: false,
        format: 'string',
        example: 'Rivers State',
        notes: '',
    },
    {
        name: 'religion',
        group: 'Details',
        required: false,
        format: 'string',
        example: 'Christianity',
        notes: '',
    },
    {
        name: 'previous_school',
        group: 'Details',
        required: false,
        format: 'string',
        example: 'Hopespring Foundation School',
        notes: '',
    },
    {
        name: 'address',
        group: 'Details',
        required: false,
        format: 'string',
        example: '15 Onne Road, GRA Phase 2, Port Harcourt',
        notes: '',
    },
    {
        name: 'gender',
        group: 'Personal',
        required: false,
        format: 'male|female|other',
        example: 'male',
        notes: 'Accepts m/f/o variations.',
    },
    {
        name: 'date_of_birth',
        group: 'Personal',
        required: false,
        format: 'YYYY-MM-DD or DD/MM/YYYY',
        example: '2008-03-15',
        notes: '',
    },
    {
        name: 'middle_name',
        group: 'Personal',
        required: false,
        format: 'string',
        example: 'Michael',
        notes: '',
    },
    {
        name: 'other_nationality',
        group: 'Personal',
        required: false,
        format: 'string',
        example: 'British',
        notes: 'If the student holds dual nationality.',
    },
];
