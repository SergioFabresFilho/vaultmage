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
        Schema::table('sample_decks', function (Blueprint $table) {
            $table->string('budget_tier')->default('average')->after('commander_slug');

            // Replace the single-column unique on commander_slug with a composite
            // unique so we can store budget / average / expensive per commander.
            $table->dropUnique(['commander_slug']);
            $table->unique(['commander_slug', 'budget_tier']);
        });
    }

    public function down(): void
    {
        Schema::table('sample_decks', function (Blueprint $table) {
            $table->dropUnique(['commander_slug', 'budget_tier']);
            $table->dropColumn('budget_tier');
            $table->unique(['commander_slug']);
        });
    }
};
