<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            // The secret part of the bill's private PDF link sent to the customer.
            $table->string('share_token', 40)->nullable()->unique()->after('bill_number');
        });

        // Bills made before this change get a link too.
        DB::table('bills')->whereNull('share_token')->orderBy('id')->each(function (object $bill) {
            DB::table('bills')->where('id', $bill->id)->update(['share_token' => Str::random(40)]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropUnique(['share_token']);
            $table->dropColumn('share_token');
        });
    }
};
