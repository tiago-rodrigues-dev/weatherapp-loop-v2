<?php

namespace Tests\Unit;

use App\Repositories\MunicipioRepository;
use App\Services\MunicipioService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MunicipioServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    #[Test]
    public function normaliza_o_termo_e_aplica_o_limite(): void
    {
        $repository = Mockery::mock(MunicipioRepository::class);
        $repository->shouldReceive('buscarPorNome')
            ->once()
            ->with('sao jo', MunicipioService::LIMITE_RESULTADOS)
            ->andReturn(collect());

        (new MunicipioService($repository))->autocomplete('  São Jo ');

        $this->addToAssertionCount(1);
    }
}