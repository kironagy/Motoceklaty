<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Merchants (owner 2026-11-23): a showroom with its own dashboard on {subdomain}.motogate-eg.com.
// Its login lives in its own database (App\Support\Merchants\MerchantTenancy), not here.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('showroom_name');
            $table->string('subdomain')->unique();
            $table->string('logo')->nullable();
            $table->json('pages')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
