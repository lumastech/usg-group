{{-- One member's savings statement: what they put in, what they earned, where they stand. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Savings statement — {{ $member->full_name }}</title>
    @include('pdf.partials.style')
</head>
<body>
    <h1>Savings statement</h1>
    <p class="meta muted">
        {{ $member->full_name }} &middot; member {{ $member->member_number }} &middot;
        {{ $cycle->name }} cycle &middot;
        generated {{ $generatedAt->format('j M Y, H:i') }}
    </p>

    <table class="cards">
        <tr>
            <td>
                <div class="label">Total savings</div>
                <div class="value">{{ $money($totals['savings_ngwee']) }}</div>
            </td>
            <td>
                <div class="label">Interest earned</div>
                <div class="value">{{ $money($totals['interest_ngwee']) }}</div>
            </td>
            <td>
                <div class="label">Net value</div>
                <div class="value {{ $totals['net_value_ngwee'] < 0 ? 'negative' : '' }}">
                    {{ $money($totals['net_value_ngwee']) }}
                </div>
            </td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th class="left">Month</th>
                <th>Savings</th>
                <th>Interest</th>
                <th>Cumulative savings</th>
                <th>Cumulative interest</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($history as $month)
                <tr>
                    <td class="left">
                        {{ $month['full_label'] }}
                        @if ($month['lockdown'])
                            <span class="muted">(lockdown)</span>
                        @endif
                    </td>
                    <td>{{ $money($month['savings_ngwee']) }}</td>
                    <td>{{ $money($month['interest_ngwee']) }}</td>
                    <td>{{ $money($month['cumulative_savings_ngwee']) }}</td>
                    <td>{{ $money($month['cumulative_interest_ngwee']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="footnote">
        Amounts in Kwacha. Interest is this member's share of the group's pooled loan
        interest, split in proportion to cumulative savings. Corrections appear as their
        own reversing entries — nothing on this statement is ever edited after the fact.
    </p>
</body>
</html>
