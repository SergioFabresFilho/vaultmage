<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sample_decks', function (Blueprint $table) {
            $table->string('archetype')->default('generic')->after('budget_tier');

            $table->dropUnique(['commander_slug', 'budget_tier']);
            $table->unique(['commander_slug', 'budget_tier', 'archetype']);
            $table->index(['commander_name', 'budget_tier', 'archetype']);
        });
    }

    public function down(): void
    {
        Schema::table('sample_decks', function (Blueprint $table) {
            $table->dropUnique(['commander_slug', 'budget_tier', 'archetype']);
            $table->dropIndex(['commander_name', 'budget_tier', 'archetype']);
            $table->dropColumn('archetype');
            $table->unique(['commander_slug', 'budget_tier']);
        });
    }
};
