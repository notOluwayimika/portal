import axios from 'axios';

export function generateSlug(name: string): string {
    return name
        .toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '')
        .trim()
        .replace(/\s+/g, '-');
}

export function checkToken() {
    const token = localStorage.getItem('token');

    return !!token;
}

export async function getUserFromToken() {
    const token = localStorage.getItem('token');

    if (!token) {
        return null;
    }

    try {
        const response = await axios.get('/api/user', {
            headers: {
                Authorization: `Bearer ${token}`,
            },
        });

        return response.data;
    } catch (error) {
        console.error('Failed to fetch user data:', error);

        return null;
    }
}

export function convertToSelectOptions(
    collection: { id: string | number; [key: string]: any }[],
    nameColumn: string = 'name',
) {
    return collection.map((item) => ({
        value: item.id,
        label: item[nameColumn],
    }));
}

export const fmtDate = (d: string): string =>
    d
        ? new Date(d).toLocaleDateString('en-US', {
              month: 'long',
              day: '2-digit',
              year: 'numeric',
          })
        : '—';

export function handleBack() {
    // Implementation for handling back navigation
    window.history.back();
}

export function toShortName(fullName: string): string {
    const parts = fullName.trim().split(' ');

    if (parts.length === 0) {
        return '';
    }

    const firstName = parts[0];
    const lastNameInitial = parts[parts.length - 1]?.[0];

    return lastNameInitial ? `${firstName} ${lastNameInitial}` : firstName;
}

export function convertNameToResultFmt(fullName: string): string {
    const parts = fullName.trim().split(' ');

    if (parts.length < 2) {
        return fullName;
    }

    const lastName = parts[parts.length - 1];
    const firstName = parts[0];

    return `${lastName.toUpperCase()} ${firstName}`;
}
