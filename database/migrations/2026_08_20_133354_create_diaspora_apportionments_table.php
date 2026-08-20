<?php

use App\Enums\ApportionmentItemStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diaspora_apportionments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('total_ngwee');

            /* What the equal split actually pays out; the remainder stays in the fund. */
            $table->unsignedBigInteger('apportioned_ngwee');
            $table->unsignedBigInteger('share_ngwee');
            $table->unsignedBigInteger('remainder_ngwee');

            $table->date('declared_on');
            $table->foreignId('recorded_by_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('second_approver_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['cycle_id', 'declared_on']);
        });

        Schema::create('diaspora_apportionment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('diaspora_apportionment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('amount_ngwee');
            $table->string('status', 16)->default(ApportionmentItemStatus::Pending->value);
            $table->date('paid_on')->nullable();
            $table->foreignId('confirmed_by_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('reference', 120)->nullable();

            $table->timestamps();

            $table->unique(['diaspora_apportionment_id', 'member_id'], 'apportionment_member_unique');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diaspora_apportionment_items');
        Schema::dropIfExists('diaspora_apportionments');
    }
};
