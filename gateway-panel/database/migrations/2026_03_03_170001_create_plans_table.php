<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique()->comment('machine key: trial, start, pro, enterprise');
            $table->string('label', 100)->comment('Display name: Start, Pro, Enterprise');
            $table->unsignedInteger('price_usd')->default(0)->comment('Monthly price in USD (0 = free/contact)');
            $table->unsignedInteger('max_shield_sites')->default(1)->comment('999 = unlimited for enterprise');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
