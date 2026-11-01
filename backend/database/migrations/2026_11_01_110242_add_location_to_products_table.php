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
        Schema::table('products', function (Blueprint $table) {
            // Where the product is kept; existing products start in the shop.
            $table->string('location_area', 10)->default('shop')->after('gst_rate');
            $table->unsignedTinyInteger('location_floor')->nullable()->after('location_area');
            $table->string('location_rack', 4)->nullable()->after('location_floor');
            $table->unsignedTinyInteger('location_shelf')->nullable()->after('location_rack');
            // Built from the parts above, such as SHOP-GF-R3-S2; shared by everything on that shelf.
            $table->string('location_code', 40)->default('SHOP')->after('location_shelf')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['location_code']);
            $table->dropColumn(['location_area', 'location_floor', 'location_rack', 'location_shelf', 'location_code']);
        });
    }
};
