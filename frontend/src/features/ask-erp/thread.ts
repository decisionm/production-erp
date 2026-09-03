/**
 * When the thread should follow the newest turn.
 *
 * A chat window scrolls to the bottom as answers arrive — but only while the
 * reader is ALREADY there. Yanking someone back while they are reading an
 * older answer is the thing every chat window gets wrong, and this page's
 * answers are long: a table, a chart and the SQL under them. Someone who has
 * scrolled up is reading, so they keep their place and get a "Newest" button
 * instead.
 */

/** How close to the floor still counts as "at the bottom", in px. */
export const NEAR_BOTTOM = 80;

export interface ScrollState {
    scrollTop: number;
    scrollHeight: number;
    clientHeight: number;
}

export function isNearBottom(el: ScrollState, slack: number = NEAR_BOTTOM): boolean {
    return el.scrollHeight - el.scrollTop - el.clientHeight <= slack;
}
