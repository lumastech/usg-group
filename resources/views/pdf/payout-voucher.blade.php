{{-- The voucher a member signs for. Rendered from the stored breakdown, never recomputed. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payout voucher — {{ $member->full_name }}</title>
    @include('pdf.partials.style')
    <style>
        .sign { margin-top: 26px; width: 100%; }
        .sign td { border: 0; text-align: left; padding-top: 26px; width: 33%; }
        .sign .rule { border-top: 0.5px solid #111827; padding-top: 3px; font-size: 8px; color: #6b7280; }
        .formula { font-size: 8px; color: #6b7280; }
        tr.total td { font-weight: bold; border-top: 1px solid #111827; background: #f9fafb; }
        tr.subtotal td { font-weight: bold; background: #f9fafb; }
        tr.note td { color: #6b7280; font-style: italic; }
    </style>
</head>
<body>
    <h1>Payout voucher</h1>
    <p class="meta muted">
        {{ $member->full_name }} &middot; member {{ $member->member_number }} &middot;
        {{ $cycle->name }} cycle &middot;
        {{ $payout->case->label() }} &middot;
        executed {{ $payout->executed_at?->format('j M Y, H:i') }}
    </p>

    <table class="cards">
        <tr>
            <td>
                <div class="label">Net value</div>
                <div class="value">{{ $money(\App\Support\Kwacha::toNgwee($payout->net_value_ngwee)) }}</div>
            </td>
            <td>
                <div class="label">Round-off adjustment</div>
                <div class="value">{{ $money(\App\Support\Kwacha::toNgwee($payout->round_off_ngwee)) }}</div>
            </td>
            <td>
                <div class="label">Net payable</div>
                <div class="value">{{ $money(\App\Support\Kwacha::toNgwee($payout->amount_ngwee)) }}</div>
            </td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th class="left">Line</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr class="{{ $line['kind'] }}">
                    <td class="left">
                        {{ $line['label'] }}<br>
                        <span class="formula">{{ $line['formula'] }}</span>
                    </td>
                    <td class="{{ $line['amount_ngwee'] < 0 ? 'negative' : '' }}">
                        {{ $money($line['amount_ngwee']) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($payout->early_settlement_note)
        <p class="footnote">
            <strong>Settled before share-out:</strong> {{ $payout->early_settlement_note }}
        </p>
    @endif

    @if ($payout->note)
        <p class="footnote">{{ $payout->note }}</p>
    @endif

    <table class="sign">
        <tr>
            <td><div class="rule">Member / next of kin signature</div></td>
            <td><div class="rule">{{ $payout->executedBy?->full_name }} — executed by</div></td>
            <td><div class="rule">{{ $payout->secondApprover?->full_name }} — second approver</div></td>
        </tr>
    </table>

    <p class="footnote">
        Generated {{ $generatedAt->format('j M Y, H:i') }}. This voucher shows the position as it
        stood when the payout was executed; the member's ledgers were closed at that moment.
    </p>
</body>
</html>
