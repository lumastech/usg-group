<?php

use App\Enums\NextOfKinRelationship;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves next of kin off the members row onto its own table.
 *
 * A member may nominate more than one person, and the commitment sheets already
 * record relationships as free text ("Sister", "Aunt"), so the bucketed enum is
 * stored alongside the original wording rather than replacing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('next_of_kin', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('relationship')->default(NextOfKinRelationship::Other->value);
            $table->string('relationship_label')->nullable();
            $table->timestamps();

            $table->index('member_id');
        });

        $this->migrateExistingNextOfKin();

        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship']);
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('next_of_kin_name')->nullable()->after('phone');
            $table->string('next_of_kin_phone')->nullable()->after('next_of_kin_name');
            $table->string('next_of_kin_relationship')->nullable()->after('next_of_kin_phone');
        });

        Schema::dropIfExists('next_of_kin');
    }

    /** Carries the inline columns over so no existing member loses their nominee. */
    protected function migrateExistingNextOfKin(): void
    {
        $now = now();

        DB::table('members')
            ->whereNotNull('next_of_kin_name')
            ->orderBy('id')
            ->chunkById(100, function ($members) use ($now) {
                $rows = [];

                foreach ($members as $member) {
                    $rows[] = [
                        'member_id' => $member->id,
                        'name' => $member->next_of_kin_name,
                        'phone' => $member->next_of_kin_phone,
                        'relationship' => NextOfKinRelationship::fromLabel($member->next_of_kin_relationship)->value,
                        'relationship_label' => $member->next_of_kin_relationship,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('next_of_kin')->insert($rows);
            });
    }
};
