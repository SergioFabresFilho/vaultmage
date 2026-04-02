<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deck_cards', function (Blueprint $table) {
            $table->boolean('is_commander')->default(false)->after('is_sideboard');
        });
    }

    public function down(): void
    {
        Schema::table('deck_cards', function (Blueprint $table) {
            $table->dropColumn('is_commander');
        });
    }
};
