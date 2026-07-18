import axios from 'axios';

/**
 * Single axios instance for all API calls. Same-origin, cookie-based
 * (Sanctum SPA auth) since the SPA is served by this Laravel app.
 * A separate client (mobile, third-party) hits the same /api/v1 routes
 * with a Bearer token instead — no server-side change needed for that.
 */
export const api = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
    },
});

export async function ensureCsrfCookie(): Promise<void> {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}
