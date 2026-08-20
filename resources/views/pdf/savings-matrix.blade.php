{{-- The SAVINGS sheet as a printable page, laid out the way the group's workbook is. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Savings ledger — {{ $cycle->name }}</title>
    @include('pdf.partials.style')
</head>
<body>
    <h1>Savings ledger</h1>
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
                <th rowspan="2">Total<br>savings</th>
                <th rowspan="2">Total<br>interest</th>
                <th rowspan="2">Net<br>value</th>
            </tr>
            <tr>
                @foreach ($matrix['months'] as $month)
                    <th>Sav</th>
                    <th>Int</th>
                @endforeach
            </tr>
        </thead>

        <tbody>
            @foreach ($matrix['rows'] as $row)
                <tr>
                    <td class="left">{{ $row['member_number'] }}</td>
                    <td class="left">{{ $row['full_name'] }}</td>
                    @foreach ($matrix['months'] as $month)
                        <td>{{ $money($row['cells'][$month['id']]['savings'] ?? 0) }}</td>
                        <td>{{ $money($row['cells'][$month['id']]['interest'] ?? 0) }}</td>
                    @endforeach
                    <td>{{ $money($row['total_savings_ngwee']) }}</td>
                    <td>{{ $money($row['total_interest_ngwee']) }}</td>
                    <td class="{{ $row['net_value_ngwee'] < 0 ? 'negative' : '' }}">
                        {{ $money($row['net_value_ngwee']) }}
                    </td>
                </tr>
            @endforeach
        </tbody>

        <tfoot>
            <tr>
                <th class="left" colspan="2">Total</th>
                @foreach ($matrix['months'] as $month)
                    <td>{{ $money($matrix['totals']['months'][$month['id']]['savings'] ?? 0) }}</td>
                    <td>{{ $money($matrix['totals']['months'][$month['id']]['interest'] ?? 0) }}</td>
                @endforeach
                <td>{{ $money($matrix['totals']['total_savings_ngwee']) }}</td>
                <td>{{ $money($matrix['totals']['total_interest_ngwee']) }}</td>
                <td>{{ $money($matrix['totals']['net_value_ngwee']) }}</td>
            </tr>
        </tfoot>
    </table>

    <p class="footnote">
        Amounts in Kwacha. Interest is the member's share of the group's pooled loan
        interest, split in proportion to cumulative savings. Net value is savings plus
        interest earned, less anything still owed.
    </p>
</body>
</html>
