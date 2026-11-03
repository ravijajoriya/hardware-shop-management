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
        // Money given back to a customer: after a return, an edit that lowered the total, or a
        // cancelled bill.
        Schema::create('bill_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_return_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 20);
            $table->string('method', 20);
            $table->decimal('amount', 12, 2);
            $table->string('reference', 100)->nullable();
            $table->timestamp('refunded_at')->index();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bill_refunds');
    }
};
