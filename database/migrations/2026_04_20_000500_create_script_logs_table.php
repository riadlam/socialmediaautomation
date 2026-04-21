<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('script_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('script_id')->nullable()->constrained('scripts')->nullOnDelete();
            $table->string('stage', 40);
            $table->string('level', 20)->default('info');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['script_id', 'created_at']);
            $table->index(['level', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('script_logs');
    }
};
