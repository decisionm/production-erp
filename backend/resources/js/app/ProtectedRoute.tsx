import { useEffect, useState, type PropsWithChildren } from 'react';
import { Navigate } from 'react-router-dom';
import { Flex, Spin } from 'antd';
import { fetchCurrentUser } from '@/features/auth/api';
import { useAuthStore } from '@/features/auth/store';

export default function ProtectedRoute({ children }: PropsWithChildren) {
    const user = useAuthStore((state) => state.user);
    const setUser = useAuthStore((state) => state.setUser);
    const [checking, setChecking] = useState(user === null);

    useEffect(() => {
        if (user) {
            setChecking(false);
            return;
        }

        fetchCurrentUser()
            .then(setUser)
            .catch(() => setUser(null))
            .finally(() => setChecking(false));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    if (checking) {
        return (
            <Flex justify="center" align="center" style={{ minHeight: '100vh' }}>
                <Spin size="large" />
            </Flex>
        );
    }

    if (!user) {
        return <Navigate to="/login" replace />;
    }

    return <>{children}</>;
}
