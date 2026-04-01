<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sample_decks', function (Blueprint $table) {
            $table->id();
            $table->string('source')->default('edhrec');       // edhrec | archidekt
            $table->string('format')->default('commander');
            $table->string('commander_name');                  // e.g. "Atraxa, Praetors' Voice"
            $table->string('commander_slug')->unique();        // e.g. "atraxa-praetors-voice"
            $table->json('cards');                             // [{name, quantity}]
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->index(['format', 'commander_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_decks');
    }
};
