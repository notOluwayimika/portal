/**
 * Mirrors GuardianImportRowValidator::COLUMNS on the backend.
 * Keep in sync when adding or changing columns.
 */
export interface GuardianImportColumn {
    name: string;
    group: string;
    required: boolean;
    format: string;
    example: string;
    notes: string;
}

export const GUARDIAN_IMPORT_COLUMNS: GuardianImportColumn[] = [
    { name: 'admission_number', group: 'Linking', required: true,  format: 'string',                                                                  example: 'STU2025001',          notes: "Student admission number (must exist in this school)." },
    { name: 'relationship',     group: 'Linking', required: true,  format: 'father|mother|guardian|uncle|aunt|grandparent|step_parent|sibling|other', example: 'father',              notes: 'Relationship of guardian to the student.' },
    { name: 'is_primary',       group: 'Linking', required: true,  format: 'yes/no or true/false',                                                    example: 'yes',                 notes: 'Whether this guardian is the primary contact for the student.' },

    { name: 'first_name',       group: 'Identity', required: true, format: 'string', example: 'John', notes: 'Guardian first name.' },
    { name: 'last_name',        group: 'Identity', required: true, format: 'string', example: 'Doe',  notes: 'Guardian last name.' },
    { name: 'phone',            group: 'Identity', required: true, format: 'string', example: '+2348000000000', notes: 'Phone number. Used for deduplication; formats are normalized.' },

    { name: 'middle_name',      group: 'Personal', required: false, format: 'string',                            example: 'Michael',                     notes: '' },
    { name: 'gender',           group: 'Personal', required: false, format: 'male|female|other',                  example: 'male',                        notes: 'Accepts m/f/o variations.' },
    { name: 'marital_status',   group: 'Personal', required: false, format: 'single|married|divorced|widowed|separated', example: 'married',           notes: '' },
    { name: 'email',            group: 'Personal', required: false, format: 'email',                              example: 'john.doe@example.com',        notes: 'Login identifier. Required if can_login = yes. Used for deduplication.' },

    { name: 'whatsapp_number',  group: 'Contact', required: false, format: 'phone', example: '+2348000000000', notes: '' },
    { name: 'emergency_contact',group: 'Contact', required: false, format: 'phone', example: '+2348111111111', notes: '' },

    { name: 'city',             group: 'Address', required: false, format: 'string', example: 'Lagos',   notes: '' },
    { name: 'state',            group: 'Address', required: false, format: 'string', example: 'Lagos',   notes: '' },
    { name: 'country',          group: 'Address', required: false, format: 'string', example: 'Nigeria', notes: '' },
    { name: 'postal_code',      group: 'Address', required: false, format: 'string', example: '100001',  notes: '' },

    { name: 'occupation',       group: 'Employment', required: false, format: 'string', example: 'Engineer',  notes: '' },
    { name: 'employer_name',    group: 'Employment', required: false, format: 'string', example: 'Acme Inc.', notes: '' },

    { name: 'id_type',          group: 'Identification', required: false, format: 'national_id|passport|drivers_license', example: 'national_id', notes: 'Must be paired with id_number.' },
    { name: 'id_number',        group: 'Identification', required: false, format: 'string',                                example: 'A12345678',   notes: 'Required when id_type is provided.' },
    { name: 'id_expiry_date',   group: 'Identification', required: false, format: 'YYYY-MM-DD',                            example: '2030-12-31',  notes: '' },

    { name: 'status',                    group: 'Status & Access', required: false, format: 'active|inactive|blocked', example: 'active', notes: 'Defaults to active.' },
    { name: 'can_login',                 group: 'Status & Access', required: false, format: 'yes/no or true/false',    example: 'no',     notes: 'Defaults to no. If yes, an invitation is sent.' },
    { name: 'preferred_contact_channel', group: 'Status & Access', required: false, format: 'email|sms|whatsapp',      example: 'email',  notes: 'Channel used for the invitation when can_login = yes.' },
];
