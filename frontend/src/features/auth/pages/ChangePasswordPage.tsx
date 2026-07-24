import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation } from '@tanstack/react-query';
import { Alert, Button, Card, Form, Input, Typography } from 'antd';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { changePassword } from '@/features/auth/api';

const schema = z
    .object({
        current_password: z.string().min(1, 'Enter your current password'),
        password: z.string().min(8, 'At least 8 characters'),
        password_confirmation: z.string().min(1, 'Confirm your new password'),
    })
    .refine((v) => v.password === v.password_confirmation, {
        message: 'Passwords do not match',
        path: ['password_confirmation'],
    })
    .refine((v) => v.password !== v.current_password, {
        message: 'New password must be different from the current one',
        path: ['password'],
    });

type FormValues = z.infer<typeof schema>;

export default function ChangePasswordPage() {
    const [done, setDone] = useState(false);
    const {
        control,
        handleSubmit,
        reset,
        setError,
        formState: { errors },
    } = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { current_password: '', password: '', password_confirmation: '' },
    });

    const mutation = useMutation({
        mutationFn: changePassword,
        onSuccess: () => {
            setDone(true);
            reset();
        },
        onError: (error: any) => {
            const serverErrors = error?.response?.data?.errors;
            if (serverErrors?.current_password) {
                setError('current_password', { message: serverErrors.current_password[0] });
            } else {
                setError('password', { message: error?.response?.data?.message ?? 'Could not change password' });
            }
        },
    });

    return (
        <Card style={{ maxWidth: 460 }}>
            <Typography.Title level={3} style={{ marginTop: 0 }}>Change Password</Typography.Title>
            <Typography.Paragraph type="secondary">
                Set your own password. If you were given a temporary one, change it here now.
            </Typography.Paragraph>

            {done && (
                <Alert
                    type="success"
                    showIcon
                    message="Password changed"
                    description="Use your new password next time you sign in."
                    style={{ marginBottom: 16 }}
                />
            )}

            <Form layout="vertical" onFinish={handleSubmit((values) => mutation.mutate(values))}>
                <Form.Item
                    label="Current password"
                    validateStatus={errors.current_password ? 'error' : ''}
                    help={errors.current_password?.message}
                >
                    <Controller
                        name="current_password"
                        control={control}
                        render={({ field }) => <Input.Password {...field} size="large" autoComplete="current-password" />}
                    />
                </Form.Item>
                <Form.Item
                    label="New password"
                    validateStatus={errors.password ? 'error' : ''}
                    help={errors.password?.message}
                >
                    <Controller
                        name="password"
                        control={control}
                        render={({ field }) => <Input.Password {...field} size="large" autoComplete="new-password" />}
                    />
                </Form.Item>
                <Form.Item
                    label="Confirm new password"
                    validateStatus={errors.password_confirmation ? 'error' : ''}
                    help={errors.password_confirmation?.message}
                >
                    <Controller
                        name="password_confirmation"
                        control={control}
                        render={({ field }) => <Input.Password {...field} size="large" autoComplete="new-password" />}
                    />
                </Form.Item>
                <Button type="primary" htmlType="submit" size="large" block loading={mutation.isPending}>
                    Change password
                </Button>
            </Form>
        </Card>
    );
}
