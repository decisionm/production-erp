export interface Role {
    id: number;
    name: string;
    permissions: string[];
    users_count?: number;
    created_at: string;
}

export interface PermissionCatalogPermission {
    name: string;
    label: string;
}

export interface PermissionCatalogEntry {
    module: string;
    label: string;
    permissions: PermissionCatalogPermission[];
}
