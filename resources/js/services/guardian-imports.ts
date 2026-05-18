import axios from 'axios';

export interface GuardianImportRecord {
    uuid: string;
    file_name: string;
    status: 'queued' | 'processing' | 'completed' | 'failed';
    total_rows: number;
    processed_rows: number;
    succeeded: number;
    failed: number;
    skipped: number;
    update_existing_links: boolean;
    started_at: string | null;
    completed_at: string | null;
    created_at: string | null;
    has_report: boolean;
    error: string | null;
}

export const guardianImports = {
    async submit(file: File, updateExistingLinks: boolean): Promise<GuardianImportRecord> {
        const form = new FormData();
        form.append('file', file);
        form.append('update_existing_links', updateExistingLinks ? '1' : '0');

        const res = await axios.post('/api/guardians/import', form, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        return res.data.import;
    },

    async status(uuid: string): Promise<GuardianImportRecord> {
        const res = await axios.get(`/api/guardians/import/${uuid}/status`);
        return res.data.import;
    },

    async list(limit = 10): Promise<GuardianImportRecord[]> {
        const res = await axios.get('/api/guardians/imports', { params: { limit } });
        return res.data.data;
    },

    templateUrl(): string {
        return '/api/guardians/import/template';
    },

    reportUrl(uuid: string): string {
        return `/api/guardians/import/${uuid}/report`;
    },
};
