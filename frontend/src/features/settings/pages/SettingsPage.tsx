import {
    DownloadOutlined,
    QuestionCircleOutlined,
    SafetyOutlined,
    SettingOutlined,
    TeamOutlined,
} from '@ant-design/icons';
import { Card, Col, Row, Space, Typography } from 'antd';
import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useAuthStore } from '@/features/auth/store';
import { settingsSections } from '@/features/settings/settingsSections';

/**
 * Settings — the one destination for what used to sit loose at the bottom of
 * the sidebar: Help, Downloads, and the two Administration screens.
 *
 * The list and its permission gate live in `settingsSections`, so this file
 * only decides how a section LOOKS. The icons are keyed by section key here
 * rather than in that module, which keeps the gate testable without pulling
 * the component library into a pure test.
 */
const SECTION_ICONS: Record<string, ReactNode> = {
    downloads: <DownloadOutlined />,
    help: <QuestionCircleOutlined />,
    users: <TeamOutlined />,
    roles: <SafetyOutlined />,
};

export default function SettingsPage() {
    const user = useAuthStore((state) => state.user);
    const sections = settingsSections(user);

    return (
        <>
            <Typography.Title level={3} style={{ marginTop: 0, marginBottom: 16 }}>Settings</Typography.Title>

            <Row gutter={[16, 16]}>
                {sections.map((section) => (
                    <Col key={section.key} xs={24} sm={12} lg={8} xl={6}>
                        <Link to={section.to} style={{ display: 'block', height: '100%' }}>
                            <Card hoverable styles={{ body: { padding: 20 } }} style={{ height: '100%' }}>
                                <Space align="start" size={12}>
                                    <span
                                        aria-hidden="true"
                                        style={{ fontSize: 22, color: 'var(--brand-navy)', lineHeight: 1 }}
                                    >
                                        {SECTION_ICONS[section.key] ?? <SettingOutlined />}
                                    </span>
                                    <Space direction="vertical" size={2}>
                                        <Typography.Text strong>{section.label}</Typography.Text>
                                        <Typography.Text type="secondary">{section.hint}</Typography.Text>
                                    </Space>
                                </Space>
                            </Card>
                        </Link>
                    </Col>
                ))}
            </Row>
        </>
    );
}
