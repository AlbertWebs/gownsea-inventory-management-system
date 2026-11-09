<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etims_submissions', function (Blueprint $table): void {
            $table->id();
            $table->string('document_type', 32);
            $table->unsignedBigInteger('document_id');
            $table->foreignId('credit_note_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('status', 32)->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('result_code', 16)->nullable();
            $table->text('result_message')->nullable();
            $table->unsignedBigInteger('remote_invoice_number')->nullable();
            $table->string('remote_receipt_number', 64)->nullable();
            $table->string('internal_data', 128)->nullable();
            $table->string('receipt_signature', 128)->nullable();
            $table->string('sdc_id', 64)->nullable();
            $table->string('mrc_number', 64)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['document_type', 'document_id'], 'etims_document_unique');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etims_submissions');
    }
};
