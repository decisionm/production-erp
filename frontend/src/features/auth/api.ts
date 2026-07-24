import { api, ensureCsrfCookie } from '@/lib/api';
import type { User } from './types';

export interface LoginPayload {
    email: string;
    password: string;
}

export async function login(payload: LoginPayload): Promise<User> {
    await ensureCsrfCookie();
    const { data } = await api.post<{ data: User }>('/auth/login', payload);
    return data.data;
}

export async function logout(): Promise<void> {
    await api.post('/auth/logout');
}

export async function fetchCurrentUser(): Promise<User> {
    const { data } = await api.get<{ data: User }>('/auth/me');
    return data.data;
}

export interface ChangePasswordPayload {
    current_password: string;
    password: string;
    password_confirmation: string;
}

export async function changePassword(payload: ChangePasswordPayload): Promise<void> {
    await api.post('/auth/change-password', payload);
}
