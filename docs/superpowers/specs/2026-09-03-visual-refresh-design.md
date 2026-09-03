# Visual refresh — design plan (03-Sep-2026)

**Brief (owner, 03-Sep):** make the whole app a modern, graphic frontend:
fonts, colour, vibrancy, and tables with filters and sorting everywhere.
No business rule changes. No explanatory text in the UI (25-Aug rule).

## Subject

SWAASHPET POLYMERS, Puducherry: a PET bottle and preform factory. The
material is clear PET with a cool blue-green cast at the bottle base; the
process is heat (infra-red lamps glow orange in a blow-moulder) and then
cold stretch. The brand mark is a navy double chevron with copper "PET".
Users: shift-floor operators on tablets, the store, plant manager, quality,
accounts. Primary job of the UI: find a row fast, read a figure without
doubt, press one clear action.

## Tokens

### Colour (brand-derived, not a template)

| Name | Hex | Role |
|---|---|---|
| Preform navy | `#12256B` | primary: buttons, links, active tabs, table header |
| Ink | `#141B33` | body text |
| Furnace orange | `#F07C1A` | the one accent: active nav chevron, active sort, focus ring, selected filter chip |
| PET glass | `#F2F6FB` | page ground (cool, not cream) |
| Bottle teal | `#0E9AA7` | info / neutral-positive states |
| Rule | `#D9E0EC` | borders and dividers |

Semantic: success `#1B8A4C`, warning `#D98E04`, danger `#C8321F`, all used
as **solid** pills with white text so a status reads from across a table.

### Type

One family, two widths: **Archivo** (variable, bundled via
`@fontsource-variable/archivo`, no external font request on the factory
network).

- Page title: Archivo, width 112, weight 800, 28px, tracking −0.01em.
- Section heading: width 100, weight 700, 16px.
- Body and table cells: width 100, weight 400, 14px / 1.45.
- Figures: `font-variant-numeric: tabular-nums`, right-aligned in tables.
- No all-caps eyebrows, no monospace for data labels. The dashboard's
  monospace/caps treatment is replaced by the same scale.

### Layout

```
┌────────────┬──────────────────────────────────────────────────────┐
│ ▣ logo     │ Purchase Orders                  [New purchase order]│
│            │ 4 orders                                             │
│ » Dashboard│ ┌──────────────────────────────────────────────────┐ │
│   Procure… │ │ 🔍 Search…  Status ▾  Vendor ▾  Dates  Sort ▾    │ │
│   » Orders │ ├───────┬──────────┬──────────┬────────┬───────────┤ │
│            │ │ Number│ Status   │ Vendor   │ Date ▲ │ Received  │ │  navy header
│            │ │ PO-4  │ ●Partial │ Sri Man… │ 29 Aug │  200/500  │ │
│            │ │ …                                                │ │
│            │ │ 1–4 of 4                     ‹ 1 ›   50 / page   │ │
└────────────┴──────────────────────────────────────────────────────┘
```

- Sidebar turns **light** (PET glass), ink text, the logo tile as is; the
  active item carries an orange chevron rail (the brand mark, used as
  structure). Content is left-aligned throughout; figures right-aligned.
- Page header: title, a one-line count under it, actions on the right.
- Every list page: one toolbar row (search, filters, sort), a sticky navy
  table header with visible sort arrows, solid status pills, hover row tint,
  and the shared pager. Sorting is column-header driven wherever the server
  supports a sort param; otherwise the existing "Sort ▾" select stays.

### Principles

1. Spend boldness once: the orange chevron and the navy table header.
   Everything else stays quiet.
2. Structure is information: pills mean state, chevrons mean "you are here",
   a right-aligned number means it is a figure.
3. No decoration that does not help the floor find a row.
4. Tablet first: 44px touch targets in toolbars, sticky headers, horizontal
   scroll inside the table only.

## Review against the generic default

A default pass on "modern ERP" gives: navy sidebar + Ant blue + Inter +
grey table headers, which is exactly what exists today. Changes made on
purpose: the sidebar becomes light so the navy moves into the tables where
figures live; the accent is the client's own copper/orange, not a taste
pick; the typeface is Archivo in two widths, not Inter/Plus Jakarta; the
ground is cool PET glass, not cream; no caps eyebrows, no mono labels.

## Scope of the build

1. Theme: `ConfigProvider` tokens + one global stylesheet + font bundle.
2. Shell: light sidebar, chevron rail, page header with count line.
3. Table standard: sticky navy header, sort arrows, solid pills, shared
   toolbar, applied through global component tokens so every existing
   `<Table>` inherits it.
4. Column sorting and filters on the list pages whose API already accepts
   sort/filter params (procurement, inventory, sales, production lists);
   pages whose API has no sort param keep their sort select and are listed
   in the PR as remaining work.
