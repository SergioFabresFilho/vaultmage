<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('bulk_type')->default('oracle_cards');
            $table->unsignedInteger('chunk_size')->default(500);
            $table->boolean('dry_run')->default(false);
            $table->string('status')->default('queued');
            $table->unsignedBigInteger('total_cards')->default(0);
            $table->unsignedBigInteger('processed_cards')->default(0);
            $table->unsignedBigInteger('skipped_cards')->default(0);
            $table->unsignedInteger('total_chunks')->default(0);
            $table->unsignedInteger('processed_chunks')->default(0);
            $table->unsignedBigInteger('bulk_size_bytes')->nullable();
            $table->timestamp('bulk_updated_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_import_runs');
    }
};
