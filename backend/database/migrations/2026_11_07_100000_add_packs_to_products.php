<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Products bought in packs and sold loose, such as wire bought in 90 m coils and sold by the
 * metre. Stock stays counted in the loose unit; a bill or delivery line remembers how it was
 * entered (1 coil, or 5 m) and how many loose units that was (its unit_size).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // "1 Coil = 90 m": the pack it comes in, and how many of its loose unit are in one.
            $table->foreignId('pack_unit_id')->nullable()->after('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('pack_size', 12, 3)->nullable()->after('pack_unit_id');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            // What a full pack sells for; empty means the loose price times the pack size.
            $table->decimal('pack_price', 12, 2)->nullable()->after('selling_price');
            // A cost per metre worked out from a coil's price needs more than two decimals to
            // give the coil's price back exactly: ₹1,000 ÷ 90 is ₹11.1111 a metre.
            $table->decimal('purchase_price', 14, 4)->change();
        });

        foreach (['bill_items', 'bill_return_items', 'stock_entry_items'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                // How many loose units one of this line's unit is: 90 for a coil, 1 when loose.
                $table->decimal('unit_size', 12, 3)->default(1)->after('unit');
                // The loose unit stock is counted in, such as "m", when the line is in packs.
                $table->string('base_unit', 20)->nullable()->after('unit_size');
            });
        }

        Schema::table('stock_entry_items', function (Blueprint $table) {
            $table->decimal('cost_before', 14, 4)->nullable()->change();
            $table->decimal('cost_after', 14, 4)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_entry_items', function (Blueprint $table) {
            $table->decimal('cost_before', 12, 2)->nullable()->change();
            $table->decimal('cost_after', 12, 2)->change();
        });

        foreach (['bill_items', 'bill_return_items', 'stock_entry_items'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['unit_size', 'base_unit']);
            });
        }

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('pack_price');
            $table->decimal('purchase_price', 12, 2)->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pack_unit_id');
            $table->dropColumn('pack_size');
        });
    }
};
