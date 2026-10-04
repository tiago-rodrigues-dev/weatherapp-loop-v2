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
        Schema::create('consulta_clima', function (Blueprint $table) {
            $table->id();
            $table->string('cidade');
            $table->string('cidade_slug');
            $table->decimal('temperatura', 5, 2);
            $table->decimal('sensacao_termica', 5, 2);
            $table->unsignedTinyInteger('umidade');
            $table->string('descricao');
            $table->dateTime('consultado_em');
            $table->timestamps();

            $table->index(['cidade_slug', 'consultado_em']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consulta_clima');
    }
};
