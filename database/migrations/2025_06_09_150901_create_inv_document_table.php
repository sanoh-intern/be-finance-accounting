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
        Schema::connection('mysql')->create('inv_document', function (Blueprint $table) {
            $table->id('inv_doc_id');

            // Foreign key to inv_header
            $table->unsignedBigInteger('inv_id')->nullable();
            $table->foreign('inv_id')->references('inv_id')->on('inv_header')->onDelete('cascade');

            $table->string('type', 255)->nullable();
            $table->string('file', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inv_document');
    }
};
