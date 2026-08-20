<?php

namespace App\Http\Controllers\App;

use App\Domain\Reporting\PayoutVoucherPdf;
use App\Http\Controllers\Controller;
use App\Models\Payout;
use Symfony\Component\HttpFoundation\Response;

/** The printable voucher for an executed payout. */
class PayoutVoucherController extends Controller
{
    public function __invoke(Payout $payout, PayoutVoucherPdf $pdf): Response
    {
        $this->authorize('view', $payout);

        return $pdf->for($payout);
    }
}
