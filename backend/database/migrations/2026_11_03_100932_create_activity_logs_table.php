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
        // What admins did, written as it happens and never changed afterwards.
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            // Who did it, with their name copied so the entry reads the same if the account goes.
            // A failed login has no one yet.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('action', 40)->index();
            $table->string('section', 20)->index();
            // The record it is about, such as a bill or a product, with its name at the time.
            $table->string('subject_type', 30)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->string('description', 1000);
            // Fields that changed: [{field, old, new}].
            $table->json('changes')->nullable();
            // Other facts worth keeping: [{label, value}], where value is text or a list of lines.
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
