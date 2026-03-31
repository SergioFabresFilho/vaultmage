<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant', 'tool']);
            $table->text('content')->nullable();
            $table->string('tool_call_id')->nullable();   // for role=tool result messages
            $table->json('tool_calls')->nullable();        // for role=assistant with pending calls
            $table->json('metadata')->nullable();          // deck proposals, etc.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
