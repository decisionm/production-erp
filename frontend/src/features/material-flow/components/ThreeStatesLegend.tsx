import { Card, Space, Tag, Typography } from 'antd';
import { ISSUE_IS_NOT_CONSUMPTION, STATE_HELP, STATE_LABEL, STATE_LOCATION, STATE_ORDER, STATE_TONE, LOCATION_LABEL } from '../words';

/**
 * The three states, on screen, with their own words and their own place.
 *
 * This is the whole point of the material-flow screens: a reader must never
 * be able to read "issued to production" as "consumed". So the states are
 * not implied by a status colour somewhere — they are listed, named, and
 * each one says which inventory location holds it (DEC-20260817-001), with
 * consumption saying plainly that it holds none because it is an event.
 */
export default function ThreeStatesLegend() {
    return (
        <Card size="small" style={{ marginBottom: 16 }}>
            <Typography.Paragraph style={{ marginBottom: 12 }}>{ISSUE_IS_NOT_CONSUMPTION}</Typography.Paragraph>
            <Space direction="vertical" size={8} style={{ width: '100%' }}>
                {STATE_ORDER.map((state) => {
                    const location = STATE_LOCATION[state];
                    return (
                        <div key={state}>
                            <Space size={8} wrap>
                                <Tag color={STATE_TONE[state]}>{STATE_LABEL[state]}</Tag>
                                <Typography.Text type="secondary">
                                    {location ? `stands in ${LOCATION_LABEL[location]}` : 'stands in no location — it has left stock'}
                                </Typography.Text>
                            </Space>
                            <div>
                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                    {STATE_HELP[state]}
                                </Typography.Text>
                            </div>
                        </div>
                    );
                })}
            </Space>
        </Card>
    );
}
