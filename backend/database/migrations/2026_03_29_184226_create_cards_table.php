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
        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->string('scryfall_id')->unique();
            $table->string('name');
            $table->string('set_code', 10);
            $table->string('set_name');
            $table->string('collector_number', 20);
            $table->string('rarity', 20);
            $table->string('mana_cost')->nullable();
            $table->string('type_line');
            $table->string('image_uri')->nullable();
            $table->timestamps();

            $table->index(['name', 'set_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
