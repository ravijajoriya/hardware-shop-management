<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            // What one unit cost the shop when it was sold (the average purchase price then),
            // for working out profit.
            $table->decimal('cost_price', 12, 2)->nullable()->after('list_price');
            // True when the cost is today's purchase price standing in for the one at the time.
            $table->boolean('cost_estimated')->default(false)->after('cost_price');
            $table->decimal('returned_quantity', 12, 3)->default(0)->after('quantity');
        });

        // Bills made before costs were kept get the current purchase price as an estimate.
        DB::table('bill_items')
            ->join('product_variants', 'product_variants.id', '=', 'bill_items.product_variant_id')
            ->select('bill_items.id', 'product_variants.purchase_price')
            ->orderBy('bill_items.id')
            ->get()
            ->each(fn (object $row) => DB::table('bill_items')->where('id', $row->id)->update([
                'cost_price' => $row->purchase_price,
                'cost_estimated' => true,
            ]));

        // Lines whose product is gone have no cost to go on.
        DB::table('bill_items')->whereNull('cost_price')->update(['cost_estimated' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropColumn(['cost_price', 'cost_estimated', 'returned_quantity']);
        });
    }
};
