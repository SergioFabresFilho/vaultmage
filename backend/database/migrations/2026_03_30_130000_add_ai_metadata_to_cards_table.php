<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->text('oracle_text')->nullable()->after('mana_cost');
            $table->decimal('cmc', 8, 2)->nullable()->after('oracle_text');
            $table->json('legalities')->nullable()->after('color_identity');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn(['oracle_text', 'cmc', 'legalities']);
        });
    }
};
