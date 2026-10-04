<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consulta_clima', function (Blueprint $table) {
            $table->decimal('vento_kmh', 5, 1)->nullable()->after('descricao');
        });
    }

    public function down(): void
    {
        Schema::table('consulta_clima', function (Blueprint $table) {
            $table->dropColumn('vento_kmh');
        });
    }
};
