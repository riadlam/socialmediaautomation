<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('scripts')) {
            return;
        }

        Schema::create('scripts', function (Blueprint $table) {
            $table->id();
            $table->longText('script');
            $table->string('status')->default('pending')->index();
            $table->string('video_id')->nullable()->index();
            $table->text('video_url')->nullable();
            $table->unsignedSmallInteger('poll_attempts')->default(0);
            $table->text('error')->nullable();
            $table->json('publish_response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scripts');
    }
};

