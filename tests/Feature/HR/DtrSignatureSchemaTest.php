<?php

namespace Tests\Feature\HR;

use App\Models\DtrSignature;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The record of an employee signing a DTR sheet.
 */
class DtrSignatureSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_verification_code_is_unique(): void
    {
        DtrSignature::factory()->create(['code' => 'ABCDEFGH2345']);

        $this->expectException(QueryException::class);
        DtrSignature::factory()->create(['code' => 'ABCDEFGH2345']);
    }

    public function test_the_current_scope_leaves_out_superseded_signings(): void
    {
        $current = DtrSignature::factory()->create();
        DtrSignature::factory()->create(['superseded_at' => now()]);

        $this->assertSame([$current->id], DtrSignature::query()->current()->pluck('id')->all());
    }

    public function test_the_period_dates_are_plain_dates(): void
    {
        $signature = DtrSignature::factory()->create(['period_from' => '2026-10-01', 'period_to' => '2026-10-18']);

        $this->assertSame(['2026-10-01', '2026-10-18'], [$signature->fresh()->period_from->toDateString(), $signature->fresh()->period_to->toDateString()]);
    }
}
