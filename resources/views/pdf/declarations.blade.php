{{-- The DECLARATIONS sheet as a printable page, for reading out at the table. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Declarations — {{ $month->label() }}</title>
    @include('pdf.partials.style')
</head>
<body>
    <h1>Declarations</h1>
    <p class="meta muted">
        {{ $cycle->name }} cycle &middot;
        {{ $month->label() }} &middot;
        window {{ $month->declarations_open_at->format('j M, H:i') }} to {{ $month->declarations_close_at->format('j M') }} &middot;
        generated {{ $generatedAt->format('j M Y, H:i') }}
    </p>

    <table>
        <thead>
            <tr>
                <th class="left">#</th>
                <th class="left">Member</th>
                <th>Savings</th>
                <th>Repayment</th>
                <th>Loan requested</th>
                <th>Total expected</th>
                <th>Submitted</th>
                <th>Status</th>
            </tr>
        </thead>

        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td class="left">{{ $row['member_number'] }}</td>
                    <td class="left">{{ $row['full_name'] }}</td>
                    @if ($row['declared'])
                        <td>{{ $money($row['saving_ngwee']) }}</td>
                        <td>{{ $money($row['repayment_ngwee']) }}</td>
                        <td>{{ $money($row['requested_ngwee']) }}</td>
                        {{-- A negative total is the fund paying the member out on the
                             day; it is shown as such rather than clamped to zero. --}}
                        <td class="{{ $row['total_ngwee'] < 0 ? 'negative' : '' }}">
                            {{ $money($row['total_ngwee']) }}
                        </td>
                        <td>{{ $row['submitted_at'] }}</td>
                        <td>{{ $row['status_label'] }}{{ $row['is_late'] ? ' (late)' : '' }}</td>
                    @else
                        <td colspan="5" class="muted">—</td>
                        <td class="muted">Not declared</td>
                    @endif
                </tr>
            @endforeach
        </tbody>

        <tfoot>
            <tr>
                <th class="left" colspan="2">Total</th>
                <td>{{ $money($totals['saving_ngwee']) }}</td>
                <td>{{ $money($totals['repayment_ngwee']) }}</td>
                <td>{{ $money($totals['requested_ngwee']) }}</td>
                <td class="{{ $totals['total_ngwee'] < 0 ? 'negative' : '' }}">{{ $money($totals['total_ngwee']) }}</td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
