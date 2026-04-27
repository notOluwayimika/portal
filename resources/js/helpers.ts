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
