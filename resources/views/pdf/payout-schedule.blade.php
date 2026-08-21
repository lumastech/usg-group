{{-- The master payout schedule the signatories take to the bank. One line per payout,
     in member-number order, with a signature column against each. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payout schedule — {{ $cycle->name }}</title>
    @include('pdf.partials.style')
</head>
<body>
    <h1>Master payout schedule</h1>
    <p class="meta muted">
        {{ $cycle->name }} cycle &middot;
        {{ $schedule['count'] }} payouts &middot;
        generated {{ $generatedAt->format('j M Y, H:i') }}
    </p>

    <table class="cards">
        <tr>
            <td>
                <div class="label">Payouts</div>
                <div class="value">{{ $schedule['count'] }}</div>
            </td>
            <td>
                <div class="label">Total to disburse</div>
                <div class="value">{{ $money($schedule['total_ngwee']) }}</div>
            </td>
            <td>
                <div class="label">Prepared</div>
                <div class="value">{{ $generatedAt->format('j M Y') }}</div>
            </td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th class="left">#</th>
                <th class="left">Member</th>
                <th class="left">Case</th>
                <th>Net value</th>
                <th>Round-off</th>
                <th>Amount paid</th>
                <th class="left">Received (signature)</th>
            </tr>
        </thead>

        <tbody>
            @foreach ($schedule['rows'] as $row)
                <tr>
                    <td class="left">{{ $row['member_number'] }}</td>
                    <td class="left">{{ $row['full_name'] }}</td>
                    <td class="left">{{ $row['case_label'] }}</td>
                    <td>{{ $money($row['net_value_ngwee']) }}</td>
                    <td>{{ $money($row['round_off_ngwee']) }}</td>
                    <td>{{ $money($row['amount_ngwee']) }}</td>
                    <td class="left">&nbsp;</td>
                </tr>
            @endforeach
        </tbody>

        <tfoot>
            <tr>
                <th class="left" colspan="5">Total</th>
                <td>{{ $money($schedule['total_ngwee']) }}</td>
                <td class="left">&nbsp;</td>
            </tr>
        </tfoot>
    </table>

    <table class="cards" style="margin-top: 18px;">
        <tr>
            <td>
                <div class="label">Signatory</div>
                <div style="height: 26px;"></div>
                <div class="muted">Name &amp; signature</div>
            </td>
            <td>
                <div class="label">Signatory</div>
                <div style="height: 26px;"></div>
                <div class="muted">Name &amp; signature</div>
            </td>
            <td>
                <div class="label">Date</div>
                <div style="height: 26px;"></div>
                <div class="muted">Day of share-out</div>
            </td>
        </tr>
    </table>

    <p class="footnote">
        Every line on this schedule is backed by an individual voucher carrying the member's own
        statement. A member who came out under water does not appear here — nothing is handed over
        on a shortfall; it is recorded as a debt or an arrangement instead.
    </p>
</body>
</html>
