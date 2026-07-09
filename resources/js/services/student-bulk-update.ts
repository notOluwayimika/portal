import axios from 'axios';

export interface StudentBulkUpdateRecord {
    uuid: string;
    file_name: string;
    status: 'queued' | 'processing' | 'completed' | 'failed';
    total_rows: number;
    processed_rows: number;
    succeeded: number;
    failed: number;
    skipped: number;
    started_at: string | null;
    completed_at: string | null;
    created_at: string | null;
    has_report: boolean;
    error: string | null;
}

export const studentBulkUpdate = {
    async submit(file: File): Promise<StudentBulkUpdateRecord> {
        const form = new FormData();
        form.append('file', file);

        const res = await axios.post('/api/students/bulk-update', form, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        return res.data.import;
    },

    async status(uuid: string): Promise<StudentBulkUpdateRecord> {
        const res = await axios.get(`/api/students/bulk-update/${uuid}/status`);
        return res.data.import;
    },

    async list(limit = 10): Promise<StudentBulkUpdateRecord[]> {
        const res = await axios.get('/api/students/bulk-updates', { params: { limit } });
        return res.data.data;
    },

    templateUrl(): string {
        return '/api/students/bulk-update/template';
    },

    reportUrl(uuid: string): string {
        return `/api/students/bulk-update/${uuid}/report`;
    },
};
