{{-- The SHARE OUT sheet as a printable page, laid out the way the group's workbook is.
     Read out line by line on the last day, so the totals row is the point of it. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Share-out — {{ $cycle->name }}</title>
    @include('pdf.partials.style')
</head>
<body>
    <h1>Share-out</h1>
    <p class="meta muted">
        {{ $cycle->name }} cycle &middot;
        {{ $sheet['totals']['members'] }} members &middot;
        {{ $sheet['totals']['settled'] }} already settled &middot;
        generated {{ $generatedAt->format('j M Y, H:i') }}
    </p>

    <table class="cards">
        <tr>
            <td>
                <div class="label">Total savings</div>
                <div class="value">{{ $money($sheet['totals']['total_savings_ngwee']) }}</div>
            </td>
            <td>
                <div class="label">Total interest</div>
                <div class="value">{{ $money($sheet['totals']['total_interest_ngwee']) }}</div>
            </td>
            <td>
                <div class="label">Net payable</div>
                <div class="value">{{ $money($sheet['totals']['payable_ngwee']) }}</div>
            </td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th class="left" rowspan="2">#</th>
                <th class="left" rowspan="2">Member</th>
                @foreach ($sheet['months'] as $month)
                    <th colspan="2">{{ $month['label'] }} {{ $month['year'] }}</th>
                @endforeach
                <th rowspan="2">Total<br>savings</th>
                <th rowspan="2">Total<br>interest</th>
                <th rowspan="2">Outstanding<br>loan</th>
                <th rowspan="2">Net<br>value</th>
                <th rowspan="2">Round-off<br>adjustment</th>
                <th rowspan="2">Net<br>payable</th>
            </tr>
            <tr>
                @foreach ($sheet['months'] as $month)
                    <th>Sav</th>
                    <th>Int</th>
                @endforeach
            </tr>
        </thead>

        <tbody>
            @foreach ($sheet['rows'] as $row)
                <tr>
                    <td class="left">{{ $row['member_number'] }}</td>
                    <td class="left">
                        {{ $row['full_name'] }}
                        @if ($row['case'] !== 'active_share_out')
                            <span class="muted">({{ $row['case_label'] }})</span>
                        @endif
                    </td>
                    @foreach ($sheet['months'] as $month)
                        <td>{{ $money($row['cells'][$month['id']]['savings'] ?? 0) }}</td>
                        <td>{{ $money($row['cells'][$month['id']]['interest'] ?? 0) }}</td>
                    @endforeach
                    <td>{{ $money($row['total_savings_ngwee']) }}</td>
                    <td>{{ $money($row['total_interest_ngwee']) }}</td>
                    <td>{{ $money($row['outstanding_loan_ngwee']) }}</td>
                    <td class="{{ $row['net_value_ngwee'] < 0 ? 'negative' : '' }}">
                        {{ $money($row['net_value_ngwee']) }}
                    </td>
                    <td>{{ $money($row['round_off_ngwee']) }}</td>
                    <td class="{{ $row['net_payable_ngwee'] < 0 ? 'negative' : '' }}">
                        {{ $money($row['net_payable_ngwee']) }}
                    </td>
                </tr>
            @endforeach
        </tbody>

        <tfoot>
            <tr>
                <th class="left" colspan="2">Total</th>
                @foreach ($sheet['months'] as $month)
                    <td>{{ $money($sheet['totals']['months'][$month['id']]['savings'] ?? 0) }}</td>
                    <td>{{ $money($sheet['totals']['months'][$month['id']]['interest'] ?? 0) }}</td>
                @endforeach
                <td>{{ $money($sheet['totals']['total_savings_ngwee']) }}</td>
                <td>{{ $money($sheet['totals']['total_interest_ngwee']) }}</td>
                <td>{{ $money($sheet['totals']['outstanding_loan_ngwee']) }}</td>
                <td>{{ $money($sheet['totals']['net_value_ngwee']) }}</td>
                <td>{{ $money($sheet['totals']['round_off_ngwee']) }}</td>
                <td>{{ $money($sheet['totals']['net_payable_ngwee']) }}</td>
            </tr>
        </tfoot>
    </table>

    <p class="footnote">
        Net value is total savings plus interest, less what the member still owes on the loan ledger.
        Members who left early or were expelled forfeit their interest, and an estate's interest stops
        at the date of death — the interest they earned is still shown, but it is not carried into net value.
        A negative net payable is a shortfall the member owes the group; nothing is handed over on that line.
    </p>
</body>
</html>
