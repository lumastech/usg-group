<?php

namespace App\Domain\Reporting;

use App\Models\Payout;
use App\Support\Kwacha;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * The voucher a member signs for their share-out.
 *
 * Rendered from the payout's stored breakdown rather than from the ledgers, so a
 * voucher reprinted a year later still shows the figures the two committee members
 * signed for on the day.
 */
class PayoutVoucherPdf
{
    public function for(Payout $payout): Response
    {
        $payout->loadMissing('member', 'cycle', 'executedBy', 'secondApprover');

        $filename = 'payout-voucher-'.$payout->member->member_number
            .'-'.($payout->executed_at ?? Carbon::now())->format('Ymd').'.pdf';

        return Pdf::loadView('pdf.payout-voucher', [
            'payout' => $payout,
            'member' => $payout->member,
            'cycle' => $payout->cycle,
            'lines' => $payout->breakdown['lines'] ?? [],
            'generatedAt' => Carbon::now(),
            'money' => fn (int $ngwee): string => Kwacha::format($ngwee),
        ])->download($filename);
    }
}
