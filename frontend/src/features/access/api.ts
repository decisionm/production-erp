import type { User } from '@/features/auth/types';
import { api } from '@/lib/api';
import type { Paginated } from '@/lib/types';
import type { PermissionCatalogEntry, Role } from './types';
import type { UserListFilters } from './userList';

/**
 * The name-ordered first page — what the person pickers (PersonSelect, the
 * production approval and entry pages) hand straight to TanStack as their
 * queryFn, so it takes NO argument: a filters parameter here would receive
 * the query context. The Users page reads listUsersPage.
 */
export async function listUsers(): Promise<Paginated<User>> {
    const { data } = await api.get<Paginated<User>>('/users');
    return data;
}

/** ONE page of users, sorted and paged on the SERVER (ListUsersRequest). */
export async function listUsersPage(filters: UserListFilters = {}): Promise<Paginated<User>> {
    const { data } = await api.get<Paginated<User>>('/users', { params: filters });
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
