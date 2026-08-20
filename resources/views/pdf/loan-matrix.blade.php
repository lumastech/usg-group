{{-- The LOANS sheet as a printable page, laid out the way the group's workbook is. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Loans ledger — {{ $cycle->name }}</title>
    @include('pdf.partials.style')
</head>
<body>
    <h1>Loans ledger</h1>
    <p class="meta muted">
        {{ $cycle->name }} cycle &middot;
        {{ count($matrix['rows']) }} members &middot;
        generated {{ $generatedAt->format('j M Y, H:i') }}
    </p>

    <table>
        <thead>
            <tr>
                <th class="left" rowspan="2">#</th>
                <th class="left" rowspan="2">Member</th>
                @foreach ($matrix['months'] as $month)
                    <th colspan="2">{{ $month['label'] }} {{ $month['year'] }}</th>
                @endforeach
                <th rowspan="2">Total<br>borrowed</th>
                <th rowspan="2">Interest<br>paid</th>
                <th rowspan="2">Penalties</th>
                <th rowspan="2">Balance</th>
            </tr>
            <tr>
                @foreach ($matrix['months'] as $month)
                    <th>Out</th>
                    <th>Bal</th>
                @endforeach
            </tr>
        </thead>

        <tbody>
            @foreach ($matrix['rows'] as $row)
                <tr>
                    <td class="left">{{ $row['member_number'] }}</td>
                    <td class="left">{{ $row['full_name'] }}</td>
                    @foreach ($matrix['months'] as $month)
                        <td>{{ $money($row['cells'][$month['id']]['borrowed'] ?? 0) }}</td>
                        <td>{{ $money($row['cells'][$month['id']]['balance'] ?? 0) }}</td>
                    @endforeach
                    <td>{{ $money($row['borrowed_ngwee']) }}</td>
                    <td>{{ $money($row['interest_paid_ngwee']) }}</td>
                    <td>{{ $money($row['penalties_ngwee']) }}</td>
                    <td>{{ $money($row['balance_ngwee']) }}</td>
                </tr>
            @endforeach
        </tbody>

        <tfoot>
            <tr>
                <th class="left" colspan="2">Total</th>
                @foreach ($matrix['months'] as $month)
                    <td>{{ $money($matrix['totals']['months'][$month['id']]['borrowed'] ?? 0) }}</td>
                    <td>{{ $money($matrix['totals']['months'][$month['id']]['balance'] ?? 0) }}</td>
                @endforeach
                <td>{{ $money($matrix['totals']['borrowed_ngwee']) }}</td>
                <td>{{ $money($matrix['totals']['interest_paid_ngwee']) }}</td>
                <td>{{ $money($matrix['totals']['penalties_ngwee']) }}</td>
                <td>{{ $money($matrix['totals']['balance_ngwee']) }}</td>
            </tr>
        </tfoot>
    </table>

    <p class="footnote">
        Amounts in Kwacha. Interest runs at 5% a month on the balance still outstanding,
        and every loan must be repaid in full by {{ $cycle->final_repayment_date->format('j F Y') }}.
    </p>
</body>
</html>
