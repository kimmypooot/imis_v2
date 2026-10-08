<?php

namespace Tests\Unit\Models;

use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserHonorificTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: ?string, 2: string}>
     */
    public static function firstNames(): array
    {
        return [
            'title' => ['Atty. Otelia Fe', 'Atty.', 'Otelia Fe'],
            'capitals, no space' => ['ATTY.Juan', 'Atty.', 'Juan'],
            'Dra. is not Dr.' => ['Dra. Ana', 'Dra.', 'Ana'],
            'Ma. is a name' => ['Ma. Lourdes', null, 'Ma. Lourdes'],
            'no period' => ['Hon Ming', null, 'Hon Ming'],
            'title alone' => ['Atty.', null, 'Atty.'],
        ];
    }

    #[DataProvider('firstNames')]
    public function test_a_title_is_split_from_the_first_name(string $typed, ?string $honorific, string $firstName): void
    {
        $this->assertSame([$honorific, $firstName], User::splitHonorific($typed));
    }
}
