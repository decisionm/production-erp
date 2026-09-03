import { DatePicker, Segmented, Space, Typography } from 'antd';
import dayjs from 'dayjs';
import { RANGE_PRESETS, type DateRange, type RangePreset, presetFor, rangeFor, rangeLabel } from '@/features/hrms/attendanceRange';

const { RangePicker } = DatePicker;

/**
 * THE PERIOD, ONCE, FOR THE WHOLE PAGE.
 *
 * Today / Yesterday / This week / Last week / This month / Last month, and
 * a pair of dates for anything else. One control drives both halves of the
 * page: a reader who picks "last month" means it for the person they are
 * looking at AND for the factory beneath, and two separate pickers would
 * let those two disagree without saying so.
 */
export default function AttendanceRangeBar({ range, onChange }: { range: DateRange; onChange: (range: DateRange) => void }) {
    const active = presetFor(range);

    return (
        <Space wrap align="center" style={{ width: '100%', justifyContent: 'space-between' }}>
            <Space wrap align="center">
                <Segmented
                    value={active ?? ''}
                    onChange={(value) => onChange(rangeFor(value as RangePreset))}
                    options={RANGE_PRESETS.map((preset) => ({ value: preset.value, label: preset.label }))}
                />
                <RangePicker
                    allowClear={false}
                    value={[dayjs(range.from), dayjs(range.to)]}
                    onChange={(_, text) => {
                        const [from, to] = text as [string, string];
                        if (from && to) onChange({ from, to });
                    }}
                />
            </Space>
            <Typography.Text type="secondary">{rangeLabel(range)}</Typography.Text>
        </Space>
    );
}
