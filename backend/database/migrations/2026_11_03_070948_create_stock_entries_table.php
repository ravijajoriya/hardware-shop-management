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
        // One delivery of stock coming into the shop.
        Schema::create('stock_entries', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_name', 100)->nullable()->index();
            // The supplier's own bill or challan number for the delivery.
            $table->string('supplier_bill_number', 50)->nullable();
            $table->date('received_on')->index();
            $table->string('note', 255)->nullable();
            $table->decimal('total', 12, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_entries');
    }
};
