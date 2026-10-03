<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MunicipioSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $caminho = base_path('cidades.json');

        if(!file_exists($caminho)) {
            throw new RuntimeException("Arquivo cidades.json não encontrado no caminho: $caminho");
        }

        $dados = json_decode(file_get_contents($caminho), true, flags: JSON_THROW_ON_ERROR);

        $agora = now();
        $registros = [];

        foreach ($dados['estados'] as $estado) {
            foreach ($estado['cidades'] as $cidade) {
                $registros[] = [
                    'nome' => $cidade,
                    'nome_normalizado' => mb_strtolower($cidade),
                    'uf' => $estado['sigla'],
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ];
            }
        }

        DB::table('municipios')->truncate();

        foreach (array_chunk($registros, 500) as $lote) {
            DB::table('municipios')->insert($lote);
        }
    }
}
