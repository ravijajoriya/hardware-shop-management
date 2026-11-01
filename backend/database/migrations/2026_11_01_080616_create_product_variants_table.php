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
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100)->nullable();
            $table->string('sku', 50)->nullable()->unique();
            $table->decimal('purchase_price', 12, 2);
            $table->decimal('selling_price', 12, 2);
            $table->decimal('mrp', 12, 2)->nullable();
            $table->decimal('stock_quantity', 12, 3)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
