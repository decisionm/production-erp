/**
 * GRAB A WIDE TABLE AND SLIDE IT (03-Sep-2026).
 *
 * The owner, of the floor's own screens: "scrolling is very difficult ... they
 * scroll down to the last row, then hold the left scrolling bar and scroll."
 * A wide table hides its columns behind ONE handle — the horizontal bar at the
 * BOTTOM of the table — so reading the right-hand columns means scrolling the
 * whole page down, dragging, and scrolling back. On a tablet by a machine that
 * is not a workflow, it is an obstacle. Trying to drag the table instead just
 * selected the text.
 *
 * So the table pans: press anywhere on the rows and slide. One delegated
 * listener covers EVERY table in the app, present and future, rather than a
 * prop each page must remember.
 *
 * The rules that keep it from stealing anything:
 *  - only the primary button, and never on something you can press or type in;
 *  - only once the pointer has moved horizontally more than {@link PAN_THRESHOLD},
 *    and more sideways than down, so a click stays a click and a vertical drag
 *    still scrolls the page;
 *  - only on a table that actually overflows.
 */

/** Movement, in px, before a press becomes a pan. Below this a click is still a click. */
export const PAN_THRESHOLD = 4;

/** What a person is pressing ON rather than dragging FROM. */
const INTERACTIVE = 'a, button, input, select, textarea, label, [role="button"], [role="checkbox"], [contenteditable="true"], .ant-checkbox, .ant-radio, .ant-switch, .ant-tag, .ant-select, .ant-picker, .ant-slider, .ant-table-column-sorters, .ant-table-filter-trigger';

/** The class the document wears while a pan is in flight. */
export const PANNING_CLASS = 'is-panning-table';

export interface PanTarget {
    scrollLeft: number;
    readonly scrollWidth: number;
    readonly clientWidth: number;
}

/** A table can be panned only when it is actually hiding something. */
export function overflowsHorizontally(el: PanTarget): boolean {
    return el.scrollWidth - el.clientWidth > 1;
}

/**
 * Whether a press on `target` may begin a pan. `closestMatches` is the DOM's
 * own `closest`, passed in so this is testable without a document.
 */
export function pressCanPan(
    button: number,
    closestMatches: (selector: string) => boolean,
    container: PanTarget | null,
): boolean {
    if (button !== 0) return false;
    if (container === null || !overflowsHorizontally(container)) return false;

    return !closestMatches(INTERACTIVE);
}

/**
 * Has the pointer moved enough, and sideways enough, to be a pan rather than
 * a click or a page scroll? Vertical drags are left alone: the page still
 * scrolls the way it always did.
 */
export function movementIsAPan(dx: number, dy: number): boolean {
    return Math.abs(dx) > PAN_THRESHOLD && Math.abs(dx) > Math.abs(dy);
}

/** Where the table should sit after the pointer has travelled `dx` from the press. */
export function pannedScrollLeft(startScrollLeft: number, dx: number, el: PanTarget): number {
    const most = Math.max(0, el.scrollWidth - el.clientWidth);

    return Math.min(most, Math.max(0, startScrollLeft - dx));
}

/**
 * Attach the behaviour to a document. Returns the detach function.
 *
 * Delegated from the document rather than bound per table, because tables
 * mount and unmount constantly here (every drawer, every tab) and a listener
 * per table would be a leak per table.
 */
export function attachDragToPan(doc: Document): () => void {
    let container: (HTMLElement & PanTarget) | null = null;
    let startX = 0;
    let startY = 0;
    let startScrollLeft = 0;
    let panning = false;

    const onPointerDown = (event: PointerEvent) => {
        const target = event.target;
        if (!(target instanceof Element)) return;

        const body = target.closest<HTMLElement>('.ant-table-body, .ant-table-content');
        if (body === null) return;
        if (!pressCanPan(event.button, (selector) => target.closest(selector) !== null, body)) return;

        container = body;
        startX = event.clientX;
        startY = event.clientY;
        startScrollLeft = body.scrollLeft;
        panning = false;
    };

    const onPointerMove = (event: PointerEvent) => {
        if (container === null) return;

        const dx = event.clientX - startX;
        const dy = event.clientY - startY;

        if (!panning) {
            if (!movementIsAPan(dx, dy)) return;
            panning = true;
            doc.body.classList.add(PANNING_CLASS);
        }

        // Stops the browser turning the drag into a text selection, which is
        // what it did before and why dragging felt broken.
        event.preventDefault();
        container.scrollLeft = pannedScrollLeft(startScrollLeft, dx, container);
    };

    const stop = () => {
        container = null;
        panning = false;
        doc.body.classList.remove(PANNING_CLASS);
    };

    doc.addEventListener('pointerdown', onPointerDown, true);
    doc.addEventListener('pointermove', onPointerMove, { passive: false });
    doc.addEventListener('pointerup', stop);
    doc.addEventListener('pointercancel', stop);

    return () => {
        doc.removeEventListener('pointerdown', onPointerDown, true);
        doc.removeEventListener('pointermove', onPointerMove);
        doc.removeEventListener('pointerup', stop);
        doc.removeEventListener('pointercancel', stop);
        stop();
    };
}
