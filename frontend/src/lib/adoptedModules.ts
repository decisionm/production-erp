/**
 * The modules this factory has actually adopted.
 *
 * WHAT THIS IS NOT. It is not a list of what is built. CRM, Finance and Payroll
 * are real features with real screens behind them — Leads alone is 500 lines —
 * and every one of their endpoints still works for anything that calls it.
 * Nothing here is deleted or disabled.
 *
 * WHAT IT IS. A menu of modules a factory is not using yet reads as a half-built
 * product, and it read that way to the owner's manager (05-Aug): "why CRM, HRMS,
 * payroll pages, where we don't have anything, and finance too." He was looking
 * at working screens with no data in them — the same experience as a stub, and
 * worse than an absence. An absence is a roadmap; an empty screen is a broken
 * promise.
 *
 * DECIDED BY COUNTING ROWS, not by taking the complaint at face value — with one
 * correction worth recording, because it was mine. Leads 0, journal entries 0 and
 * payroll runs 0 are hidden. Assets and GST rates carry real data, so Maintenance
 * and Compliance stay.
 *
 * HRMS was kept for a day on the strength of 7 employee rows, and that was wrong
 * twice over. The count came from the DEV database rather than the live one, and
 * the rows are seed data: EMP-001 to EMP-007, no names on them, and not one
 * referenced as an operator by any production entry. The owner said it plainly —
 * "those are dummy data". A module whose only content is fixtures is precisely
 * what this list exists to hide.
 *
 * The lesson lives here rather than in a commit message nobody re-reads: count on
 * the machine the decision is about.
 *
 * PERMISSIONS COULD NOT ANSWER THIS. Visibility is granted by `<module>.view`,
 * and the people who open this app are Administrators who hold every permission
 * by definition. The only way to hide a module by permission was to strip it
 * from the role that also runs production.
 *
 * ADD A LINE THE DAY A MODULE GOES INTO USE. That is the whole maintenance
 * burden, and it is deliberately in source rather than in a settings screen: a
 * factory adopting a module is a decision made once, not a toggle anyone should
 * flip by accident on a live floor. Two surfaces read this list — the sidebar
 * (AppLayout) and the dashboard's Office band, which already carries cells for
 * HRMS and CRM — so the one added line surfaces both at once.
 */
export const ADOPTED_MODULES = new Set([
    // The production spine — what this deployment exists to run.
    'inventory',
    'production',
    'procurement',
    'quality',
    'tally-sync',
    // The internal carton trace tier — owner-decided (DEC-20260810-001), so
    // adopted the day it shipped. Visibility is still permission-gated on
    // top of this (carton-trace.view: Owner/PM/Accounts, never Supervisor);
    // this line only lets the menu entry exist at all.
    'carton-trace',
    // Master data and access, needed to administer any of the above.
    'users',
    'roles',
    // Sales stays because it is the demand side of the spine the factory is
    // being taken through next — purchase order through to sales received, the
    // manager's own request. Not kept on a row count; see the note above.
    'sales',
    // Neither was named by the manager, so neither is this change's business.
    // Left visible on that ground alone rather than on a count, after the HRMS
    // mistake: absence of a complaint is a fact about the complaint, which is
    // all this list is judging.
    'maintenance',
    'compliance',
    // HRMS and Payroll — adopted by DEC-20260812-001 (superseding
    // DEC-20260809-001, which hid them). Hiding HRMS never protected anything:
    // Employee is what shift_production_entries.operator_id,
    // supervisor_signed_by and ShiftSummary.supervisor point at, so the Start
    // Batch operator dropdown has been offering those people to the floor all
    // along. The seven seeded employees are KEPT, not deleted — the factory
    // edits them into real people, which fixes the dropdown at the same time
    // and preserves every existing link from real production records.
    'hrms',
    'payroll',
    // CRM IS RETIRED (owner instruction, 31-Aug-2026) and its line is gone
    // from this list.
    //
    // It was adopted for DEMONSTRATION on 12-Aug at the owner's request, and
    // was the one entry here never justified by a row count: Leads,
    // Opportunities and Quotations have held nothing since, and the factory
    // has recorded no enquiry (Q37 stands unanswered). Retiring it returns the
    // list to the rule it is built on — a module appears when it is in use —
    // and restores DEC-20260812-001's original clause, which said CRM stays
    // hidden and which that day's later instruction had superseded in
    // practice. It is worth a superseding DEC recording the round trip.
    //
    // RETIRED MEANS HIDDEN, NOT DELETED — the distinction this whole file
    // rests on. Every CRM screen, route, endpoint and test is untouched and
    // still works for anything that calls it; only the menu entry and the
    // dashboard's CRM cell go. The day the factory records a real enquiry,
    // one line brings it all back.
    // FINANCE — adopted because Client Outstanding gives it its first screen
    // with real content in it: what every client owes and how late it is, read
    // from the position the agent mirrors out of Tally, which is where this
    // factory actually raises its sales.
    //
    // It was hidden on a row count (journal entries 0) and on the manager's
    // 05-Aug complaint, which named finance directly. That reasoning has not
    // changed for the BOOKS screens — Chart of Accounts, Journal Entries and
    // Reports are still empty, and adopting the module surfaces them too. That
    // is the known cost of this line, and it is the owner's to weigh: the
    // alternative is to hang Client Outstanding off an adopted group with
    // `permissionModule: 'finance'`, exactly as Supplier Bills and Tally Vendor
    // Review hang off Procurement today, and leave Finance hidden.
    //
    // Adopted here rather than that, on the instruction to make it a Finance
    // page (31-Aug-2026).
    'finance',
]);
