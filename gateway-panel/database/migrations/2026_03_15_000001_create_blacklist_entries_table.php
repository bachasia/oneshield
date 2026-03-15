<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blacklist_entries', function (Blueprint $table) {
            $table->id();
            $table->string('type');           // 'email' | 'address'
            $table->string('value');          // normalized lowercase
            $table->string('source')->default('pgprints'); // 'pgprints' | 'custom'
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index(['type', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blacklist_entries');
    }
};
