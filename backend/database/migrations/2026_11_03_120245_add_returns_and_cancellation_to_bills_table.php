<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            // Due = total − returned − (paid − refunded).
            $table->decimal('returned_amount', 12, 2)->default(0)->after('paid_amount');
            $table->decimal('refunded_amount', 12, 2)->default(0)->after('returned_amount');

            $table->timestamp('edited_at')->nullable()->after('created_by');
            $table->foreignId('edited_by')->nullable()->after('edited_at')->constrained('users')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable()->after('edited_by');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable()->after('cancelled_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('edited_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['returned_amount', 'refunded_amount', 'edited_at', 'cancelled_at', 'cancel_reason']);
        });
    }
};
