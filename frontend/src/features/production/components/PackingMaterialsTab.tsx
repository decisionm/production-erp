import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    Form,
    Input,
    InputNumber,
    message,
    Modal,
    Popconfirm,
    Select,
    Space,
    Table,
    Tag,
    Typography,
} from 'antd';
import { useState } from 'react';
import { hasManageAccess } from '@/features/auth/permissions';
import { useAuthStore } from '@/features/auth/store';
import {
    listPackingMaterialMappings,
    listPackingMaterialOptions,
    type PackingMaterialMappingPayload,
    type PackingMaterialMappingRow,
    savePackingMaterialMapping,
    withdrawPackingMaterialMapping,
} from '@/features/production/api';

/**
 * THE PACKING-MATERIAL MASTER'S OWN SCREEN — which Tally item each workbook
 * spec means, editable without a deploy.
 *
 * The backend half of this has existed since the mappings shipped: the
 * endpoints upsert and withdraw, the seed records why every row points where
 * it does, and PackingMaterialMappingTest pins that a correction REPLACES the
 * answer in force. What never shipped was a screen, so the only way to change
 * a wrong mapping was the completion drawer's per-line picker — which applies
 * to ONE batch and saves nothing. The factory hit exactly that: a spec mapped
 * to the wrong Tally item, visible on every voucher, with no control anywhere
 * to correct it ("there is no option to change the tally attached").
 *
 * Corrections made here reach every FUTURE prefill and every voucher not yet
 * posted, because the preview and the voucher build read this table live.
 * Nothing already posted to Tally is rewritten — a posted voucher is a
 * record of what happened, not a view of today's configuration.
 *
 * Pouch and tray are separate kinds with separate rows, so a product packed
 * both ways carries BOTH mappings; which one a batch consumes follows from
 * the packing the supervisor actually recorded, not from anything here.
 */

const KIND_LABEL: Record<PackingMaterialMappingRow['spec_kind'], string> = {
    carton: 'Carton',
    tray: 'Tray',
    pouch_film: 'Pouch / film',
    tape: 'Tape',
};

const KIND_ORDER: PackingMaterialMappingRow['spec_kind'][] = ['carton', 'tray', 'pouch_film', 'tape'];

/** The dose column's meaning per kind — '' means the kind carries no dose. */
const DOSE_LABEL: Record<string, string> = {
    pouch_film: 'Grams per piece',
    tape: 'Metres per box',
};

interface MappingFormValues {
    spec_kind: PackingMaterialMappingRow['spec_kind'];
    spec_value: string;
    item_id?: number;
    grams_per_piece?: number | null;
    metres_per_box?: number | null;
    note?: string | null;
}

/** `null` = closed, `'new'` = the add flow, a row = correcting that row. */
type Editing = PackingMaterialMappingRow | 'new' | null;

export default function PackingMaterialsTab() {
    const queryClient = useQueryClient();
    const user = useAuthStore((s) => s.user);
    // Reads pass on production.view (the route group's GET rule); the POST and
    // DELETE need production.manage, so the controls only render for it.
    const canManage = hasManageAccess(user, 'production');

    const { data: rows, isFetching } = useQuery({
        queryKey: ['production', 'packing-material-mappings'],
        queryFn: listPackingMaterialMappings,
    });
    // Same catalogue the completion drawer's pickers use — active items only,
    // keyed by the wire's kind words (carton, tray, pouch_film, tape).
    const { data: options } = useQuery({
        queryKey: ['production', 'packing-material-options'],
        queryFn: listPackingMaterialOptions,
    });

    const [editing, setEditing] = useState<Editing>(null);
    const [form] = Form.useForm<MappingFormValues>();
    const kindWatch = Form.useWatch('spec_kind', form);

    const invalidate = () => {
        queryClient.invalidateQueries({ queryKey: ['production', 'packing-material-mappings'] });
        // The floor's prefill and the Start Batch preview read the same rows —
        // a stale cache would keep showing the old material after a correction.
        queryClient.invalidateQueries({ queryKey: ['production', 'batch-preview'] });
    };

    const save = useMutation({
        mutationFn: (payload: PackingMaterialMappingPayload) => savePackingMaterialMapping(payload),
        onSuccess: () => {
            invalidate();
            setEditing(null);
            message.success('Saved — every future batch prefills from this answer.');
        },
        onError: (error: any) => {
            const errors = error?.response?.data?.errors;
            Modal.error({
                title: 'Could not save this mapping',
                // The backend's messages name the fix ("this figure is the
                // weight of ONE film piece…"), so they are shown verbatim.
                content: errors
                    ? Object.values(errors).flat().join(' ')
                    : (error?.response?.data?.message ?? 'Unexpected error.'),
            });
        },
    });

    const withdraw = useMutation({
        mutationFn: (id: number) => withdrawPackingMaterialMapping(id),
        onSuccess: () => {
            invalidate();
            message.success('Withdrawn — the floor is asked to choose the material again.');
        },
        onError: (error: any) =>
            Modal.error({ title: 'Could not withdraw', content: error?.response?.data?.message ?? 'Unexpected error.' }),
    });

    const open = (row: PackingMaterialMappingRow | 'new') => {
        setEditing(row);
        form.resetFields();
        form.setFieldsValue(
            row === 'new'
                ? { spec_kind: 'carton', spec_value: '', note: null }
                : {
                      spec_kind: row.spec_kind,
                      spec_value: row.spec_value,
                      item_id: row.item.id,
                      grams_per_piece: row.grams_per_piece === null ? null : Number(row.grams_per_piece),
                      metres_per_box: row.metres_per_box === null ? null : Number(row.metres_per_box),
                      note: row.note,
                  },
        );
    };

    const submit = (v: MappingFormValues) => {
        if (v.item_id === undefined) return; // required on the Form.Item
        save.mutate({
            spec_kind: v.spec_kind,
            spec_value: v.spec_value.trim(),
            item_id: v.item_id,
            // Only the kind's own dose travels — the backend refuses the wrong
            // pairing at the door, so the payload must not carry a stale one.
            grams_per_piece: v.spec_kind === 'pouch_film' ? (v.grams_per_piece ?? null) : undefined,
            metres_per_box: v.spec_kind === 'tape' ? (v.metres_per_box ?? null) : undefined,
            note: v.note?.trim() || null,
        });
    };

    /**
     * The item choices for the kind being edited — plus, when correcting a row
     * whose mapped item the filtered catalogue does not hold (deactivated
     * since, or a name no kind-word matches), that item as a disabled entry.
     * Same rule as the completion drawer: the row must SAY which material it
     * means, without re-offering an item that was excluded on purpose.
     */
    const optionsForKind = (kind: string | undefined, current?: PackingMaterialMappingRow['item']) => {
        const catalogue = (kind && options?.[kind]) || [];
        const listed = catalogue.map((o) => ({ value: o.id, label: o.name }));
        if (current && !catalogue.some((o) => o.id === current.id)) {
            return [{ value: current.id, label: current.name, disabled: true }, ...listed];
        }
        return listed;
    };

    const sorted = [...(rows ?? [])].sort(
        (a, b) =>
            KIND_ORDER.indexOf(a.spec_kind) - KIND_ORDER.indexOf(b.spec_kind) ||
            a.spec_value.localeCompare(b.spec_value),
    );

    const doseText = (row: PackingMaterialMappingRow) =>
        row.spec_kind === 'pouch_film'
            ? row.grams_per_piece === null
                ? null
                : `${row.grams_per_piece} g / piece`
            : row.spec_kind === 'tape'
              ? row.metres_per_box === null
                  ? null
                  : `${row.metres_per_box} m / box`
              : '—';

    const columns = [
        {
            title: 'Kind',
            width: 110,
            render: (_: unknown, row: PackingMaterialMappingRow) => <Tag>{KIND_LABEL[row.spec_kind]}</Tag>,
        },
        {
            title: 'Workbook spec',
            dataIndex: 'spec_value',
            width: 180,
            render: (value: string) => <Typography.Text code>{value}</Typography.Text>,
        },
        {
            title: 'Tally item it means',
            render: (_: unknown, row: PackingMaterialMappingRow) => row.item.name,
        },
        {
            title: 'Dose',
            width: 140,
            render: (_: unknown, row: PackingMaterialMappingRow) =>
                doseText(row) ?? (
                    // A film with no per-piece weight (or tape with no metres)
                    // offers the item and NO quantity on the floor — worth a
                    // visible nudge here, where the person who can fix it is.
                    <Typography.Text type="warning" style={{ fontSize: 12 }}>
                        not set — no quantity prefills
                    </Typography.Text>
                ),
        },
        {
            title: 'Why / by whom',
            render: (_: unknown, row: PackingMaterialMappingRow) => (
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    {row.note ?? '—'}
                    {row.set_by ? ` (${row.set_by})` : ''}
                </Typography.Text>
            ),
        },
        ...(canManage
            ? [
                  {
                      title: '',
                      width: 150,
                      fixed: 'right' as const,
                      render: (_: unknown, row: PackingMaterialMappingRow) => (
                          <Space size={8}>
                              <Button size="small" onClick={() => open(row)}>
                                  Change
                              </Button>
                              <Popconfirm
                                  title="Withdraw this mapping?"
                                  description="The floor's line goes back to “choose the material”. Nothing already posted changes."
                                  okText="Withdraw"
                                  onConfirm={() => withdraw.mutate(row.id)}
                              >
                                  <Button size="small" danger loading={withdraw.isPending}>
                                      Withdraw
                                  </Button>
                              </Popconfirm>
                          </Space>
                      ),
                  },
              ]
            : []),
    ];

    return (
        <>
            <Alert
                type="info"
                showIcon
                style={{ marginBottom: 16 }}
                message="Which Tally item each workbook packing spec means — nothing here is hardcoded."
                description={
                    <>
                        Every carton, tray, pouch/film and tape line the floor is prefilled with resolves
                        through this table. Correcting a row applies to every future batch and any voucher not
                        yet posted — vouchers already in Tally are history and stay as posted. A product packed
                        more than one way (pouch and tray) carries a row for each; which one a batch consumes
                        follows the packing counts the supervisor records, not this table.
                    </>
                }
                closable
            />

            <Space style={{ marginBottom: 16 }}>
                {canManage && (
                    <Button type="primary" onClick={() => open('new')}>
                        Add mapping
                    </Button>
                )}
                {!canManage && (
                    <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        Mappings are corrected by the office (production manage access).
                    </Typography.Text>
                )}
            </Space>

            <Table
                rowKey="id"
                size="small"
                loading={isFetching}
                dataSource={sorted}
                pagination={false}
                scroll={{ x: 'max-content' }}
                columns={columns as never}
                locale={{
                    emptyText:
                        'No mappings yet — the floor is asked to choose every material by hand until specs are mapped here.',
                }}
            />

            <Modal
                title={editing === 'new' ? 'Add mapping' : `Mapping — ${editing?.spec_value ?? ''}`}
                open={editing !== null}
                onCancel={() => setEditing(null)}
                okText={editing === 'new' ? 'Add mapping' : 'Save correction'}
                confirmLoading={save.isPending}
                onOk={() => form.submit()}
                maskClosable={false}
                destroyOnHidden
                width={560}
            >
                <Form<MappingFormValues> form={form} layout="vertical" onFinish={submit} requiredMark={false}>
                    <Form.Item
                        name="spec_kind"
                        label="Kind"
                        rules={[{ required: true }]}
                        extra={
                            editing !== 'new'
                                ? 'Kind and spec identify the row — to move an answer, withdraw this one and add the other.'
                                : undefined
                        }
                    >
                        <Select
                            disabled={editing !== 'new'}
                            options={KIND_ORDER.map((k) => ({ value: k, label: KIND_LABEL[k] }))}
                        />
                    </Form.Item>
                    <Form.Item
                        name="spec_value"
                        label="Workbook spec"
                        rules={[{ required: true, message: 'The spec string as the workbook spells it.' }, { max: 120 }]}
                        extra="Exactly as the sheet writes it (case and spacing are forgiven on lookup)."
                    >
                        <Input disabled={editing !== 'new'} placeholder="e.g. 500ML ROUND" />
                    </Form.Item>
                    <Form.Item
                        name="item_id"
                        label="Tally item"
                        rules={[{ required: true, message: 'Choose the Tally item this spec means.' }]}
                    >
                        <Select
                            showSearch
                            optionFilterProp="label"
                            placeholder="Choose the material…"
                            options={optionsForKind(
                                kindWatch,
                                editing !== null && editing !== 'new' ? editing.item : undefined,
                            )}
                        />
                    </Form.Item>
                    {kindWatch === 'pouch_film' && (
                        <Form.Item
                            name="grams_per_piece"
                            label={DOSE_LABEL.pouch_film}
                            extra="The weight of ONE film piece in grams — Tally moves film in Kgs, so the floor's kg figure is pieces × this ÷ 1000. Leave blank to offer the item with no quantity."
                        >
                            <InputNumber min={0.0001} max={1000} step={0.1} style={{ width: '100%' }} />
                        </Form.Item>
                    )}
                    {kindWatch === 'tape' && (
                        <Form.Item
                            name="metres_per_box"
                            label={DOSE_LABEL.tape}
                            extra="How much tape seals one box, in metres — the owner's figures run from 2.226 m to 5.160 m."
                        >
                            <InputNumber min={0.0001} max={100} step={0.001} style={{ width: '100%' }} />
                        </Form.Item>
                    )}
                    <Form.Item
                        name="note"
                        label="Why (note)"
                        extra="Recorded on the row — the next person reading this table sees why it points where it does."
                    >
                        <Input.TextArea rows={2} maxLength={1000} />
                    </Form.Item>
                </Form>
            </Modal>
        </>
    );
}
