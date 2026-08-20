<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            $table->string('case', 24);

            /*
             * The itemised statement exactly as it was computed and shown to the two
             * approvers: every line, its formula hint and its amount. It is stored
             * rather than recomputed because the ledgers behind it go on moving — a
             * voucher reprinted next year must still read as it did on the day.
             */
            $table->json('breakdown');

            $table->bigInteger('net_value_ngwee');

            /*
             * The workbook's ROUNDOFF ADJSTMNT column. No rounding is applied today
             * (App\Domain\Payouts\NoRounding), so this is always zero and net payable
             * equals net value. The column exists so turning rounding on later is a
             * binding change, not a schema change.
             */
            $table->bigInteger('round_off_ngwee')->default(0);

            $table->bigInteger('amount_ngwee');

            $table->timestamp('executed_at')->nullable();
            $table->foreignId('executed_by_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('second_approver_member_id')->nullable()->constrained('members')->nullOnDelete();

            /** Why a death was settled before the cycle reached share-out. */
            $table->text('early_settlement_note')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            /* One member is settled once. */
            $table->unique('member_id');
            $table->index(['cycle_id', 'case']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
