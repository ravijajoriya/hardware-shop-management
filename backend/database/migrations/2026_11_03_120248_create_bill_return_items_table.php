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
        Schema::create('bill_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_item_id')->nullable()->constrained()->nullOnDelete();
            // Kept as links while the product exists; the copies below keep the return readable after.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->string('variant_name', 100)->nullable();
            $table->string('unit', 20);
            $table->decimal('quantity', 12, 3);
            // The price charged on the bill, and what the items had cost the shop.
            $table->decimal('price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bill_return_items');
    }
};
