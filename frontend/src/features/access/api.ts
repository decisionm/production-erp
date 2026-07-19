import type { User } from '@/features/auth/types';
import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type { PermissionCatalogEntry, Role } from './types';

export async function listUsers(): Promise<Paginated<User>> {
    const { data } = await api.get<Paginated<User>>('/users');
    return data;
}

export interface CreateUserPayload {
    name: string;
    email: string;
    password: string;
    roles?: number[];
}

export async function createUser(payload: CreateUserPayload): Promise<User> {
    const { data } = await api.post<{ data: User }>('/users', payload);
    return data.data;
}

export interface UpdateUserPayload {
    name?: string;
    email?: string;
    is_active?: boolean;
    roles?: number[];
}

export async function updateUser(id: number, payload: UpdateUserPayload): Promise<User> {
    const { data } = await api.put<{ data: User }>(`/users/${id}`, payload);
    return data.data;
}

export async function resetUserPassword(id: number, password: string): Promise<void> {
    await api.post(`/users/${id}/reset-password`, { password });
}

export async function listRoles(): Promise<Role[]> {
    const { data } = await api.get<{ data: Role[] }>('/roles');
    return data.data;
}

export interface RolePayload {
    name: string;
    permissions: string[];
}

export async function createRole(payload: RolePayload): Promise<Role> {
    const { data } = await api.post<{ data: Role }>('/roles', payload);
    return data.data;
}

export async function updateRole(id: number, payload: Partial<RolePayload>): Promise<Role> {
    const { data } = await api.put<{ data: Role }>(`/roles/${id}`, payload);
    return data.data;
}

export async function deleteRole(id: number): Promise<void> {
    await api.delete(`/roles/${id}`);
}

export async function listPermissionCatalog(): Promise<PermissionCatalogEntry[]> {
    const { data } = await api.get<{ data: PermissionCatalogEntry[] }>('/permissions');
    return data.data;
}
