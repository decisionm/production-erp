<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Quotation #{{ $quotation->id }}</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1f1f1f; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #666; }
        table.header { width: 100%; margin-bottom: 24px; }
        table.header td { vertical-align: top; }
        table.header td.meta { text-align: right; width: 40%; }
        .section-title { font-weight: bold; text-transform: uppercase; font-size: 11px; color: #666; margin-bottom: 4px; }
        .bill-to { margin-bottom: 24px; }
        table.lines { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.lines th, table.lines td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
        table.lines th { background: #f5f5f5; }
        table.lines td.num, table.lines th.num { text-align: right; }
        .totals { width: 100%; }
        .totals td { padding: 4px 8px; }
        .totals .label { text-align: right; }
        .totals .value { text-align: right; width: 120px; }
        .totals .grand td { font-weight: bold; border-top: 2px solid #1f1f1f; }
        .notes { margin-top: 24px; }
        .status { display: inline-block; padding: 2px 8px; border-radius: 4px; background: #f0f0f0; font-size: 11px; text-transform: uppercase; }
        .footer { margin-top: 40px; font-size: 10px; color: #999; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <h1>{{ config('app.name') }}</h1>
                @if($gstRegistration)
                    <div class="muted">GSTIN: {{ $gstRegistration->gstin }} ({{ $gstRegistration->state_name }})</div>
                @endif
            </td>
            <td class="meta">
                <div><strong>Quotation #{{ $quotation->id }}</strong></div>
                <div class="muted">Date: {{ $quotation->quotation_date?->format('d M Y') }}</div>
                @if($quotation->valid_until)
                    <div class="muted">Valid Until: {{ $quotation->valid_until->format('d M Y') }}</div>
                @endif
                <div style="margin-top: 6px;"><span class="status">{{ $quotation->status->value }}</span></div>
            </td>
        </tr>
    </table>

    <div class="bill-to">
        <div class="section-title">Bill To</div>
        <div><strong>{{ $quotation->customer->name }}</strong></div>
        @if($quotation->customer->address)
            <div>{{ $quotation->customer->address }}</div>
        @endif
        @if($quotation->customer->gstin)
            <div>GSTIN: {{ $quotation->customer->gstin }}</div>
        @endif
        @if($quotation->customer->email)
            <div>{{ $quotation->customer->email }}</div>
        @endif
        @if($quotation->customer->phone)
            <div>{{ $quotation->customer->phone }}</div>
        @endif
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th>Item</th>
                <th>HSN/SAC</th>
                <th class="num">Quantity</th>
                <th class="num">Unit Price</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @php $grandTotal = '0.0000'; @endphp
            @foreach($quotation->lines as $line)
                @php
                    $lineTotal = bcmul((string) $line->quantity, (string) $line->unit_price, 4);
                    $grandTotal = bcadd($grandTotal, $lineTotal, 4);
                @endphp
                <tr>
                    <td>{{ $line->item->sku }} — {{ $line->item->name }}</td>
                    <td>{{ $line->item->hsn_sac_code ?? '—' }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 4, '.', ''), '0'), '.') }} {{ $line->item->uom }}</td>
                    <td class="num">{{ number_format((float) $line->unit_price, 2) }}</td>
                    <td class="num">{{ number_format((float) $lineTotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr class="grand">
            <td class="label">Total</td>
            <td class="value">{{ number_format((float) $grandTotal, 2) }}</td>
        </tr>
    </table>

    @if($quotation->notes)
        <div class="notes">
            <div class="section-title">Notes</div>
            <div>{{ $quotation->notes }}</div>
        </div>
    @endif

    <div class="footer">
        This is a system-generated quotation and is subject to confirmation. Prices exclude applicable taxes unless stated otherwise.
    </div>
</body>
</html>
