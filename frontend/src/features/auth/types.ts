export interface UserRole {
    id: number;
    name: string;
}

export interface User {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    roles?: UserRole[];
    permissions?: string[];
    created_at?: string;
}
