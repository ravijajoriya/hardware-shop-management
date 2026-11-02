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
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->string('bill_number', 20)->unique();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            // A copy of what was printed, so correcting the customer later leaves old bills alone.
            $table->string('customer_name');
            $table->string('customer_mobile', 10);
            $table->decimal('total', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            // Udhaar: what the customer still has to pay on this bill.
            $table->decimal('due_amount', 12, 2)->default(0);
            $table->string('status', 20)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
