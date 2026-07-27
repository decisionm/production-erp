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

/**
 * An expired session must land the user on the login page, not leave them
 * staring at silently-empty tables. Auth endpoints are exempt: /auth/login's
 * own 401 means "wrong password" (the form shows it), and ProtectedRoute
 * already handles the /auth/me bootstrap probe. Full-page redirect (not
 * client routing) so every stale query cache is dropped with the session.
 */
api.interceptors.response.use(undefined, (error) => {
    const status: number | undefined = error?.response?.status;
    const url: string = error?.config?.url ?? '';
    if (status === 401 && !url.startsWith('/auth/') && !window.location.pathname.startsWith('/login')) {
        window.location.assign('/login');
    }
    return Promise.reject(error);
});
