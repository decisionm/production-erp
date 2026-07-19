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
        <Flex style={{ minHeight: '100vh' }}>
            <Flex
                vertical
                justify="center"
                className="login-brand-panel"
                style={{
                    flex: '1 1 0',
                    minWidth: 0,
                    padding: '48px 64px',
                    background: 'linear-gradient(135deg, #0b2a6b 0%, #1677ff 55%, #52c9ff 100%)',
                    color: '#fff',
                }}
            >
                <div
                    style={{
                        width: 44,
                        height: 44,
                        borderRadius: 12,
                        background: 'rgba(255,255,255,0.15)',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        fontWeight: 700,
                        fontSize: 20,
                        marginBottom: 32,
                    }}
                >
                    M
                </div>
                <Typography.Title level={2} style={{ color: '#fff', maxWidth: 480 }}>
                    Manufacturing ERP
                </Typography.Title>
                <Typography.Paragraph style={{ color: 'rgba(255,255,255,0.85)', maxWidth: 440, fontSize: 16 }}>
                    Inventory, Production, Procurement, Sales, Finance, HRMS, Payroll, Quality and
                    Maintenance — with GST, TDS and Tally compliance built in for the Indian market.
                </Typography.Paragraph>
            </Flex>

            <Flex
                vertical
                justify="center"
                align="center"
                style={{ flex: '0 1 460px', minWidth: 0, width: '100%', padding: 24, boxSizing: 'border-box' }}
            >
                <Card style={{ width: '100%', maxWidth: 360 }} variant="borderless" styles={{ body: { padding: 32 } }}>
                    <Typography.Title level={3} style={{ marginTop: 0, marginBottom: 4 }}>
                        Sign in
                    </Typography.Title>
                    <Typography.Text type="secondary">Enter your credentials to access your workspace.</Typography.Text>

                    {mutation.isError && (
                        <Alert
                            type="error"
                            message="Invalid credentials"
                            style={{ marginTop: 16, marginBottom: 0 }}
                            showIcon
                        />
                    )}

                    <Form
                        layout="vertical"
                        style={{ marginTop: 24 }}
                        onFinish={handleSubmit((values) => mutation.mutate(values))}
                    >
                        <Form.Item
                            label="Email"
                            validateStatus={errors.email ? 'error' : ''}
                            help={errors.email?.message}
                        >
                            <Controller
                                name="email"
                                control={control}
                                render={({ field }) => (
                                    <Input {...field} size="large" autoComplete="username" placeholder="you@company.com" />
                                )}
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
                                    <Input.Password {...field} size="large" autoComplete="current-password" />
                                )}
                            />
                        </Form.Item>

                        <Button type="primary" size="large" htmlType="submit" block loading={mutation.isPending}>
                            Sign in
                        </Button>
                    </Form>
                </Card>
            </Flex>
        </Flex>
    );
}
