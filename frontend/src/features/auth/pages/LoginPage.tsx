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
                    // Brand navy taken from the logo artwork.
                    background: 'linear-gradient(135deg, #0A145B 0%, #16256E 100%)',
                    color: '#fff',
                }}
            >
                {/* Navy-on-white artwork, so it sits on a white plaque rather
                    than directly on the navy panel. */}
                <div
                    style={{
                        alignSelf: 'flex-start',
                        background: '#fff',
                        borderRadius: 12,
                        padding: '14px 18px',
                        marginBottom: 32,
                        lineHeight: 0,
                    }}
                >
                    <img
                        src={`${import.meta.env.BASE_URL}swaashpet-logo.png`}
                        alt="SWAASHPET POLYMERS"
                        style={{ height: 56, width: 'auto', display: 'block' }}
                    />
                </div>
                <Typography.Title level={3} style={{ color: '#fff', maxWidth: 480, marginTop: 0 }}>
                    Swaashpet Polymers Private Limited
                </Typography.Title>
                <Typography.Paragraph style={{ color: 'rgba(255,255,255,0.85)', maxWidth: 440, fontSize: 16, marginBottom: 0 }}>
                    Production, stores and compliance.
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

                    {/* Inside the card, not the brand panel: the panel is hidden
                        below the `lg` breakpoint (see index.css), so this is the
                        only spot a phone actually shows. */}
                    <Typography.Text type="secondary" style={{ display: 'block', marginTop: 24, textAlign: 'center', fontSize: 12 }}>
                        Powered by Balin
                    </Typography.Text>
                </Card>
            </Flex>
        </Flex>
    );
}
