import { useQuery } from '@tanstack/react-query';
import { Select, Typography } from 'antd';
import { listUsers } from '@/features/access/api';

/**
 * WHO RECEIVED IT — a person, picked from the ERP's own list of people.
 *
 * The backend records `received_by` as a user, not as a typed-in name, so
 * this picker is what a handover's second signature actually is. A store
 * login may not be allowed to read the user list at all; when that read
 * fails the picker says so in one plain line rather than offering a free-text
 * box that would write a name the ledger cannot resolve to anybody.
 */
export function usePeople() {
    const query = useQuery({ queryKey: ['access', 'users', 'material-flow'], queryFn: listUsers, retry: false });

    return {
        canPick: query.isSuccess,
        isLoading: query.isLoading,
        options: (query.data?.data ?? [])
            .filter((user) => user.is_active)
            .map((user) => ({ value: user.id, label: user.name })),
    };
}

export default function PersonSelect({
    value,
    onChange,
    placeholder,
    style,
}: {
    value: number | null;
    onChange: (value: number | null) => void;
    placeholder: string;
    style?: React.CSSProperties;
}) {
    const { canPick, isLoading, options } = usePeople();

    if (!canPick && !isLoading) {
        return (
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                This login cannot read the list of people, so the ERP cannot name who received the material. Ask an
                administrator for the permission rather than recording a name it cannot resolve.
            </Typography.Text>
        );
    }

    return (
        <Select
            allowClear
            showSearch
            optionFilterProp="label"
            loading={isLoading}
            value={value}
            onChange={(next) => onChange(next ?? null)}
            placeholder={placeholder}
            options={options}
            style={style ?? { width: '100%' }}
        />
    );
}
