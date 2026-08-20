{{-- The SOCIAL FUND sheet as a printable page: the month summary, then every entry. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Social fund — {{ $cycle->name }}</title>
    @include('pdf.partials.style')
    <style>h2 { font-size: 12px; margin: 14px 0 4px; }</style>
</head>
<body>
    <h1>Social fund</h1>
    <p class="meta muted">
        {{ $cycle->name }} cycle &middot;
        balance {{ $money($overview['balance_ngwee']) }} &middot;
        generated {{ $generatedAt->format('j M Y, H:i') }}
    </p>

    <table>
        <thead>
            <tr>
                <th class="left">Month</th>
                <th>In</th>
                <th>Out</th>
                <th>Closing balance</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($overview['months'] as $month)
                <tr>
                    <td class="left">{{ $month['label'] }}</td>
                    <td>{{ $money($month['in_ngwee']) }}</td>
                    <td>{{ $money($month['out_ngwee']) }}</td>
                    <td>{{ $money($month['closing_ngwee']) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td class="left">TOTAL</td>
                <td>{{ $money($overview['inflow_ngwee']) }}</td>
                <td>{{ $money($overview['outflow_ngwee']) }}</td>
                <td>{{ $money($overview['balance_ngwee']) }}</td>
            </tr>
        </tfoot>
    </table>

    <h2>Ledger</h2>

    <table>
        <thead>
            <tr>
                <th class="left">Date</th>
                <th class="left">Type</th>
                <th class="left">Member</th>
                <th>Amount</th>
                <th>Balance</th>
                <th class="left">Recorded by</th>
                <th class="left">Second approver</th>
            </tr>
        </thead>
        <tbody>
            @php($running = 0)
            @foreach ($entries as $entry)
                @php($running += $entry->getRawOriginal('amount_ngwee'))
                <tr>
                    <td class="left">{{ $entry->occurred_on->format('j M Y') }}</td>
                    <td class="left">{{ $entry->type->label() }}</td>
                    <td class="left">{{ $entry->member?->full_name ?? '—' }}</td>
                    <td>{{ $money($entry->getRawOriginal('amount_ngwee')) }}</td>
                    <td>{{ $money($running) }}</td>
                    <td class="left">{{ $entry->recordedBy?->full_name ?? 'System' }}</td>
                    <td class="left">{{ $entry->secondApprover?->full_name ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
