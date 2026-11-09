<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('original_name', 180);
            $table->string('disk', 32)->default('local');
            $table->string('path', 500)->unique();
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
            $table->index(['quotation_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_attachments');
    }
};
