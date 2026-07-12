import type { Role, School } from './models';

export type User = {
    id: number;
    first_name: string;
    last_name: string;
    full_name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    school_id: string;
    school?: School;
    roles?: string[];
    [key: string]: unknown;
};

export type SchoolOption = {
    uuid: string;
    name: string;
    current?: boolean;
};

export type Auth = {
    user: User;
    school: School | null;
    schools: SchoolOption[];
    isSuperAdmin: boolean;
    roles: string[];
    rolesFull: Role[];
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
