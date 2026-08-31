import { Alert, Tabs, Typography } from 'antd';
import { useSearchParams } from 'react-router-dom';
import ProductionReturnPage from './ProductionReturnPage';
import StoreIssueQueuePage from './StoreIssueQueuePage';
import StoreProductionHistoryTab from './StoreProductionHistoryTab';
import { ISSUE_IS_NOT_CONSUMPTION } from '../words';

/**
 * STORE ↔ PRODUCTION — one place for material leaving the store for the
 * floor, and for material coming back.
 *
 * The two halves were two screens on two URLs, which meant a storekeeper
 * settling the evening had to know that "Store Issue Queue" and "Return to
 * Store" were the outward and inward halves of the same daily act. They are
 * tabs of one workspace now.
 *
 * NOTHING IN EITHER HALF WAS REWRITTEN. Both tabs render exactly the
 * component their old URL rendered, embedded — the same pattern Production
 * Configuration uses for its six tabs. That is not tidiness, it is the point:
 * between them the two pages carry ten write paths, five server-side filters,
 * an idempotency contract whose asymmetry stops a double handover, and two
 * different return doors with different refusals. A merge that rewrote them
 * into one form would have had to re-derive every one of those, and the one
 * most likely to be lost is the least visible.
 *
 * THE TWO RETURN DOORS ARE BOTH STILL HERE, DELIBERATELY. The Issues tab
 * returns material against ONE open handover (the drawer's Return to store);
 * the Returns tab returns material by MATERIAL, including material no
 * handover ever put on the floor, and it is the only door that can reach a
 * handover already marked complete (StoreIssueStatus::isOpen is Issued and
 * PartiallyReturned only, so the drawer's button is gated off a completed
 * issue while ProductionReturnService::standingByItem deliberately keeps it).
 * Collapsing them onto the drawer's door would strand that material — it
 * would have no way home at all while still blocking its own unattributed
 * return — and every service test would stay green. Which door a storekeeper
 * should be told to use is a factory question, not a screen question; it is
 * recorded in PENDING-OWNER-QUESTIONS.md and answered by the owner.
 *
 * WHY THE BANNER SITS ABOVE THE TABS. ProductionReturnPage's own header
 * (:26-28) records a standing rule that the returns screen carries NO blurb —
 * "the row's own numbers and the disabled state say what is possible" — and
 * this placement puts a sentence over it. The rule is about the returns
 * TABLE explaining its own figures, which it still does. This sentence is
 * about something neither table can say: that issuing is not consuming.
 * It is equally true of both halves, and once they are tabs the shell is the
 * only place it can sit without being repeated twice or attached to one
 * direction of a two-direction screen.
 */
const STORE_PRODUCTION_TABS = [
    /**
     * Direction is in the labels on purpose. "Issues" and "Returns" alone
     * are ambiguous on a screen that is explicitly about two directions —
     * and the Returns tab loses its Card title ("Return to Store", the one
     * place the direction was written) when it is embedded. The label
     * carries that orientation instead.
     */
    { key: 'issues', label: 'Issue to production', render: () => <StoreIssueQueuePage embedded /> },
    { key: 'returns', label: 'Returns from production', render: () => <ProductionReturnPage embedded /> },
    { key: 'history', label: 'Movement history', render: () => <StoreProductionHistoryTab /> },
] as const;

type TabKey = (typeof STORE_PRODUCTION_TABS)[number]['key'];
const TAB_KEYS: readonly TabKey[] = STORE_PRODUCTION_TABS.map((tab) => tab.key);

/**
 * A LITERAL, for the same reason Production Configuration's is: the store
 * opens this screen in the morning to hand material out, so `issues` is the
 * landing tab because that is the act the day starts with — not because it
 * happens to be listed first.
 */
const DEFAULT_TAB: TabKey = 'issues';

export default function StoreProductionPage() {
    // Addressable, so the retired URLs can redirect to the specific half they
    // used to be, and so a link to one tab in someone else's prose lands
    // there rather than on the default.
    const [searchParams, setSearchParams] = useSearchParams();
    const requested = searchParams.get('tab');
    const activeTab: TabKey = TAB_KEYS.includes(requested as TabKey) ? (requested as TabKey) : DEFAULT_TAB;

    return (
        <>
            <Typography.Title level={3} style={{ marginTop: 0 }}>
                Store ↔ Production
            </Typography.Title>

            <Alert type="info" showIcon style={{ marginBottom: 16 }} message={ISSUE_IS_NOT_CONSUMPTION} />

            <Tabs
                activeKey={activeTab}
                onChange={(key) => {
                    const next = new URLSearchParams(searchParams);
                    next.set('tab', key);
                    // Replace, not push: clicking through the tabs should not
                    // cost one Back press each to leave the screen.
                    setSearchParams(next, { replace: true });
                }}
                items={STORE_PRODUCTION_TABS.map((tab) => ({
                    key: tab.key,
                    label: tab.label,
                    children: tab.render(),
                }))}
            />
        </>
    );
}
