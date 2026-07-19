import {
    AccountBookOutlined,
    BuildOutlined,
    InboxOutlined,
    SafetyCertificateOutlined,
    ShopOutlined,
    ShoppingCartOutlined,
    TeamOutlined,
    ToolOutlined,
    WarningOutlined,
} from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { Card, Col, Row, Skeleton, Statistic, Table, Tag, Typography } from 'antd';
import type { ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '@/features/auth/store';
import { getDashboardSummary } from '@/features/dashboard/api';
import type { RecentSalesOrder, RecentWorkOrder } from '@/features/dashboard/types';

interface StatCardProps {
    title: string;
    value: number | string;
    icon: ReactNode;
    color: string;
    onClick: () => void;
    warn?: boolean;
}

function StatCard({ title, value, icon, color, onClick, warn }: StatCardProps) {
    return (
        <Card hoverable onClick={onClick} styles={{ body: { padding: 20 } }}>
            <Statistic
                title={title}
                value={value}
                prefix={icon}
                styles={{ content: { color: warn ? '#cf1322' : color } }}
            />
        </Card>
    );
}

const statusColor: Record<string, string> = {
    draft: 'default',
    released: 'blue',
    completed: 'green',
    confirmed: 'blue',
    partially_delivered: 'orange',
    cancelled: 'red',
};

export default function DashboardPage() {
    const navigate = useNavigate();
    const user = useAuthStore((state) => state.user);
    const { data, isLoading } = useQuery({ queryKey: ['dashboard', 'summary'], queryFn: getDashboardSummary });

    return (
        <>
            <Typography.Title level={3}>Welcome{user ? `, ${user.name}` : ''}</Typography.Title>
            <Typography.Paragraph type="secondary">
                A snapshot of stock levels and open orders across every module.
            </Typography.Paragraph>

            {isLoading || !data ? (
                <Skeleton active paragraph={{ rows: 6 }} />
            ) : (
                <>
                    <Row gutter={[16, 16]}>
                        <Col xs={24} sm={12} md={8} lg={6}>
                            <StatCard
                                title="Items"
                                value={data.inventory.total_items}
                                icon={<InboxOutlined />}
                                color="#1677ff"
                                onClick={() => navigate('/inventory/items')}
                            />
                        </Col>
                        <Col xs={24} sm={12} md={8} lg={6}>
                            <StatCard
                                title="Low Stock Items"
                                value={data.inventory.low_stock_items}
                                icon={<WarningOutlined />}
                                color="#1677ff"
                                warn={data.inventory.low_stock_items > 0}
                                onClick={() => navigate('/inventory/stock')}
                            />
                        </Col>
                        <Col xs={24} sm={12} md={8} lg={6}>
                            <StatCard
                                title="Warehouses"
                                value={data.inventory.total_warehouses}
                                icon={<InboxOutlined />}
                                color="#1677ff"
                                onClick={() => navigate('/inventory/warehouses')}
                            />
                        </Col>
                        <Col xs={24} sm={12} md={8} lg={6}>
                            <StatCard
                                title="Open Purchase Orders"
                                value={data.procurement.open_purchase_orders}
                                icon={<ShopOutlined />}
                                color="#722ed1"
                                onClick={() => navigate('/procurement/purchase-orders')}
                            />
                        </Col>
                        <Col xs={24} sm={12} md={8} lg={6}>
                            <StatCard
                                title="Requisitions Awaiting Approval"
                                value={data.procurement.pending_requisitions}
                                icon={<ShopOutlined />}
                                color="#722ed1"
                                onClick={() => navigate('/procurement/purchase-requisitions')}
                            />
                        </Col>
                        <Col xs={24} sm={12} md={8} lg={6}>
                            <StatCard
                                title="Open Work Orders"
                                value={data.production.open_work_orders}
                                icon={<ToolOutlined />}
                                color="#fa8c16"
                                onClick={() => navigate('/production/work-orders')}
                            />
                        </Col>
                        <Col xs={24} sm={12} md={8} lg={6}>
                            <StatCard
                                title="Open Sales Orders"
                                value={data.sales.open_sales_orders}
                                icon={<ShoppingCartOutlined />}
                                color="#13c2c2"
                                onClick={() => navigate('/sales/sales-orders')}
                            />
                        </Col>
                        <Col xs={24} sm={12} md={8} lg={6}>
                            <StatCard
                                title="Orders Awaiting Delivery"
                                value={data.sales.orders_awaiting_delivery}
                                icon={<ShoppingCartOutlined />}
                                color="#13c2c2"
                                onClick={() => navigate('/sales/deliveries')}
                            />
                        </Col>
                        <Col xs={24} sm={12} md={8} lg={6}>
                            <StatCard
                                title="Receivables Outstanding"
                                value={Number(data.sales.receivables_outstanding).toLocaleString('en-IN', {
                                    style: 'currency',
                                    currency: 'INR',
                                    maximumFractionDigits: 0,
                                })}
                                icon={<AccountBookOutlined />}
                                color="#13c2c2"
                                onClick={() => navigate('/finance/reports')}
                            />
                        </Col>
                        <Col xs={24} sm={12} md={8} lg={6}>
                            <StatCard
                                title="Open NCRs"
                                value={data.quality.open_ncrs}
                                icon={<SafetyCertificateOutlined />}
                                color="#eb2f96"
                                onClick={() => navigate('/quality/ncrs')}
                            />
                        </Col>
                        <Col xs={24} sm={12} md={8} lg={6}>
                            <StatCard
                                title="Open CAPAs"
                                value={data.quality.open_capas}
                                icon={<SafetyCertificateOutlined />}
                                color="#eb2f96"
                                onClick={() => navigate('/quality/capas')}
                            />
                        </Col>
                        <Col xs={24} sm={12} md={8} lg={6}>
                            <StatCard
                                title="Pending Leave Requests"
                                value={data.hrms.pending_leave_requests}
                                icon={<TeamOutlined />}
                                color="#2f54eb"
                                onClick={() => navigate('/hrms/leave-requests')}
                            />
                        </Col>
                        <Col xs={24} sm={12} md={8} lg={6}>
                            <StatCard
                                title="Open Maintenance Work Orders"
                                value={data.maintenance.open_work_orders}
                                icon={<BuildOutlined />}
                                color="#595959"
                                onClick={() => navigate('/maintenance/work-orders')}
                            />
                        </Col>
                    </Row>

                    <Row gutter={[16, 16]} style={{ marginTop: 24 }}>
                        <Col xs={24} lg={12}>
                            <Card title="Recent Work Orders" styles={{ body: { padding: 0 } }}>
                                <Table<RecentWorkOrder>
                                    scroll={{ x: 'max-content' }}
                                    rowKey="id"
                                    size="small"
                                    dataSource={data.recent_work_orders}
                                    pagination={false}
                                    onRow={() => ({ onClick: () => navigate('/production/work-orders') })}
                                    columns={[
                                        { title: 'Item', dataIndex: 'item' },
                                        { title: 'Planned', dataIndex: 'quantity_planned' },
                                        { title: 'Completed', dataIndex: 'quantity_completed' },
                                        {
                                            title: 'Status',
                                            dataIndex: 'status',
                                            render: (status: string) => (
                                                <Tag color={statusColor[status] ?? 'default'}>{status}</Tag>
                                            ),
                                        },
                                    ]}
                                />
                            </Card>
                        </Col>
                        <Col xs={24} lg={12}>
                            <Card title="Recent Sales Orders" styles={{ body: { padding: 0 } }}>
                                <Table<RecentSalesOrder>
                                    scroll={{ x: 'max-content' }}
                                    rowKey="id"
                                    size="small"
                                    dataSource={data.recent_sales_orders}
                                    pagination={false}
                                    onRow={() => ({ onClick: () => navigate('/sales/sales-orders') })}
                                    columns={[
                                        { title: 'Customer', dataIndex: 'customer' },
                                        { title: 'Order Date', dataIndex: 'order_date' },
                                        {
                                            title: 'Status',
                                            dataIndex: 'status',
                                            render: (status: string) => (
                                                <Tag color={statusColor[status] ?? 'default'}>{status}</Tag>
                                            ),
                                        },
                                    ]}
                                />
                            </Card>
                        </Col>
                    </Row>
                </>
            )}
        </>
    );
}
