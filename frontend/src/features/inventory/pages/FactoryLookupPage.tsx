import { useQuery } from '@tanstack/react-query';
import { Alert, Card, Input, List, Space, Tag, Typography } from 'antd';
import { useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { factoryLookup, type FactoryLookupKind, type FactoryLookupMatch } from '../api';
import { ListEmpty } from '@/lib/ListEmpty';

/**
 * WHAT IS THIS NUMBER?
 *
 * Six identifier spaces, six screens, and until now no way to ask the
 * question a person actually has: they are holding a bag, a lot slip, or a
 * movement row reading "Store issue SI-000001", and the ERP required them to
 * know which screen owns that KIND of number before they could look it up.
 *
 * IT SENDS YOU THERE WHEN IT CAN. A scanned barcode is one exact unique
 * thing, so the answer is that bag — not a page with one row on it. The
 * server decides when a jump is safe (see FactoryLookupService: exact, AND
 * globally unique, AND the only match) and this page obeys it. Batch and
 * serial numbers are unique only within an item, so they always list even
 * when the term is exact — jumping would pick one item's batch out of
 * several and present it as the answer.
 *
 * THE ROUTES LIVE HERE, NOT IN THE API. The server returns a kind and an id;
 * which URL that is belongs to the app, not to a versioned API other clients
 * may consume.
 */
const KIND_LABEL: Record<FactoryLookupKind, string> = {
    item: 'Material',
    bag: 'Bag',
    lot: 'Supplier lot',
    batch: 'Batch',
    serial: 'Serial number',
    store_issue: 'Store issue',
};

function routeFor(match: FactoryLookupMatch): string {
    switch (match.kind) {
        case 'item':
            return `/inventory/items/${match.id}`;
        case 'bag':
            return '/inventory/barcode-labels';
        case 'lot':
            return '/inventory/material-lots';
        case 'batch':
            return '/inventory/batches';
        case 'serial':
            return '/inventory/serial-numbers';
        case 'store_issue':
            return '/inventory/store-production?tab=issues';
    }
}

/** Minimum the server accepts — stated here so the box explains itself. */
const MIN_TERM = 3;

export default function FactoryLookupPage() {
    const navigate = useNavigate();

    // Addressable, so a scanner wedge or a link can land straight on an
    // answer: /inventory/find?q=FL-BAG-0001.
    const [searchParams, setSearchParams] = useSearchParams();
    const submitted = searchParams.get('q') ?? '';
    const [term, setTerm] = useState(submitted);

    useEffect(() => {
        setTerm(submitted);
    }, [submitted]);

    const lookup = useQuery({
        queryKey: ['inventory', 'lookup', submitted],
        queryFn: () => factoryLookup(submitted),
        enabled: submitted.trim().length >= MIN_TERM,
    });

    const resolved = lookup.data?.resolved ?? null;

    /**
     * The jump. In an effect rather than in the query callback because
     * navigating is a side effect of the ANSWER, not of the fetch — a
     * refetch of the same term must not re-navigate a reader who has since
     * pressed Back.
     */
    useEffect(() => {
        if (resolved) navigate(routeFor(resolved), { replace: true });
    }, [resolved, navigate]);

    return (
        <>
            <Typography.Title level={3} style={{ marginTop: 0 }}>
                Find anything by its number
            </Typography.Title>

            <Card size="small" style={{ marginBottom: 16 }}>
                <Input.Search
                    autoFocus
                    allowClear
                    size="large"
                    placeholder="Scan or type a barcode, SKU, lot, batch, serial or store issue number"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                    onSearch={(value) => {
                        const next = new URLSearchParams(searchParams);
                        if (value.trim()) next.set('q', value.trim());
                        else next.delete('q');
                        setSearchParams(next, { replace: true });
                    }}
                    enterButton="Find"
                />
                <Typography.Paragraph type="secondary" style={{ marginBottom: 0, marginTop: 8 }}>
                    A barcode, SKU or store issue number goes straight to its record. A batch or serial number is shown
                    as a list, because the same number can belong to more than one material.
                </Typography.Paragraph>
            </Card>

            {submitted.trim().length > 0 && submitted.trim().length < MIN_TERM && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message={`Use at least ${MIN_TERM} characters`}
                    description="A shorter term would match too much of the factory to be an answer."
                />
            )}

            {/* Never a silent absence: a kind that was not looked up says so,
                because "no match" would read as "the ERP does not know this
                bag" when the truth is that it was not asked. */}
            {(lookup.data?.omitted ?? []).map((omission) => (
                <Alert
                    key={omission.kind}
                    type="info"
                    showIcon
                    style={{ marginBottom: 8 }}
                    message={`${KIND_LABEL[omission.kind]}s were not searched`}
                    description={omission.reason}
                />
            ))}

            {submitted.trim().length >= MIN_TERM && !resolved && (
                <List<FactoryLookupMatch>
                    bordered
                    loading={lookup.isPending}
                    dataSource={lookup.data?.matches ?? []}
                    locale={{
                        emptyText: (
                            <ListEmpty
                                state={lookup}
                                entity="records"
                                empty={`Nothing in the factory is numbered “${submitted}”.`}
                            />
                        ),
                    }}
                    renderItem={(match) => (
                        <List.Item>
                            <Link
                                to={routeFor(match)}
                                aria-label={`Open ${KIND_LABEL[match.kind]} ${match.identifier ?? match.label}`}
                                style={{ display: 'block', width: '100%', color: 'inherit' }}
                            >
                                <List.Item.Meta
                                    title={
                                        <Space>
                                            <Tag>{KIND_LABEL[match.kind]}</Tag>
                                            <strong>{match.identifier}</strong>
                                            {match.exact && <Tag color="blue">exact</Tag>}
                                            {match.retired && <Tag>Retired</Tag>}
                                        </Space>
                                    }
                                    description={[match.label, match.detail].filter(Boolean).join(' · ')}
                                />
                            </Link>
                        </List.Item>
                    )}
                />
            )}
        </>
    );
}
