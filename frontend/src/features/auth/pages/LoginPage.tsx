import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation } from '@tanstack/react-query';
import { Alert, Button, Card, Flex, Form, Input, Typography } from 'antd';
import { Controller, useForm } from 'react-hook-form';
import { useNavigate } from 'react-router-dom';
import { z } from 'zod';
import { login } from '@/features/auth/api';
import { useAuthStore } from '@/features/auth/store';

const loginSchema = z.object({
    email: z.string().email('Enter a valid email address'),
    password: z.string().min(1, 'Password is required'),
});

type LoginFormValues = z.infer<typeof loginSchema>;

export default function LoginPage() {
    const navigate = useNavigate();
    const setUser = useAuthStore((state) => state.setUser);

    const {
        control,
        handleSubmit,
        formState: { errors },
    } = useForm<LoginFormValues>({
        resolver: zodResolver(loginSchema),
        defaultValues: { email: '', password: '' },
    });

    const mutation = useMutation({
        mutationFn: login,
        onSuccess: (user) => {
            setUser(user);
            navigate('/');
        },
    });

    return (
        <Flex justify="center" align="center" style={{ minHeight: '100vh' }}>
            <Card style={{ width: 360 }}>
                <Typography.Title level={3} style={{ marginTop: 0 }}>
                    Sign in
                </Typography.Title>

                {mutation.isError && (
                    <Alert
                        type="error"
                        message="Invalid credentials"
                        style={{ marginBottom: 16 }}
                        showIcon
                    />
                )}

                <Form layout="vertical" onFinish={handleSubmit((values) => mutation.mutate(values))}>
                    <Form.Item
                        label="Email"
                        validateStatus={errors.email ? 'error' : ''}
                        help={errors.email?.message}
                    >
                        <Controller
                            name="email"
                            control={control}
                            render={({ field }) => <Input {...field} autoComplete="username" />}
                        />
                    </Form.Item>

                    <Form.Item
                        label="Password"
                        validateStatus={errors.password ? 'error' : ''}
                        help={errors.password?.message}
                    >
                        <Controller
                            name="password"
                            control={control}
                            render={({ field }) => (
                                <Input.Password {...field} autoComplete="current-password" />
                            )}
                        />
                    </Form.Item>

                    <Button type="primary" htmlType="submit" block loading={mutation.isPending}>
                        Sign in
                    </Button>
                </Form>
            </Card>
        </Flex>
    );
}
