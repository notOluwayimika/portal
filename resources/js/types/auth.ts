import type { School } from './models';

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
    // Effective permission names (C4): what the backend Gate will actually allow
    // this user in the active school — includes the super-admin bypass and
    // ADR 0040's checker exclusion. Gate ACTIONS on these via <Can>; do not gate
    // sidebar persona menus on them (see c4-brief D2).
    permissions: string[];
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
