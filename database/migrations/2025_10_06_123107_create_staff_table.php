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
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->onDelete('cascade');
            $table->string('address')->nullable();
            $table->string('hpcz_number')->nullable();
            $table->string('nrc_number')->nullable();
            $table->string('nrc_uri')->nullable();
            $table->string('selfie_uri')->nullable();
            $table->string('signature_uri')->nullable();
            $table->integer('is_approved')->default(2);
            $table->boolean('has_accepted_terms_and_conditions')->default(false);
            $table->decimal('last_known_latitude', 10, 7)->nullable();
            $table->decimal('last_known_longitude', 10, 7)->nullable();
            $table->string('fcm_token')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
