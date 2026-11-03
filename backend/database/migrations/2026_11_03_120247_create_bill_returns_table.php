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
        // Items a customer brought back from a bill.
        Schema::create('bill_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_number', 20)->unique();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            // What the items were worth on the bill: first taken off the due, the rest refunded.
            $table->decimal('amount', 12, 2);
            $table->decimal('due_reduced', 12, 2)->default(0);
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamp('returned_at')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bill_returns');
    }
};
