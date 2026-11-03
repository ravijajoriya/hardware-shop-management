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
        Schema::create('stock_entry_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_entry_id')->constrained()->cascadeOnDelete();
            // Kept as links while the product exists; the copies below keep the entry readable after.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->string('variant_name', 100)->nullable();
            $table->string('unit', 20);
            $table->decimal('quantity', 12, 3);
            // What one unit cost from the supplier on this delivery.
            $table->decimal('purchase_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            // The size's average cost before and after this delivery; "before" is put back if the
            // delivery is deleted while it is still the latest one for the size.
            $table->decimal('cost_before', 12, 2)->nullable();
            $table->decimal('cost_after', 12, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_entry_items');
    }
};
