<?php

namespace Tests\Unit\Models;

use App\Models\OrganizationalUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationalUnitHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_units_have_no_parent(): void
    {
        $root = OrganizationalUnit::factory()->create();

        $this->assertNull($root->parent);
        $this->assertTrue($root->ancestors()->isEmpty());
    }

    public function test_scope_roots_only_returns_top_level_units(): void
    {
        $root = OrganizationalUnit::factory()->create();
        OrganizationalUnit::factory()->childOf($root)->create();

        $roots = OrganizationalUnit::query()->roots()->get();

        $this->assertCount(1, $roots);
        $this->assertTrue($roots->first()->is($root));
    }

    public function test_child_retrieval_via_children_relationship(): void
    {
        $parent = OrganizationalUnit::factory()->create();
        $childA = OrganizationalUnit::factory()->childOf($parent)->create();
        $childB = OrganizationalUnit::factory()->childOf($parent)->create();

        $this->assertCount(2, $parent->children);
        $this->assertTrue($parent->children->contains($childA));
        $this->assertTrue($parent->children->contains($childB));
    }

    public function test_ancestors_returns_the_chain_up_to_the_root_nearest_first(): void
    {
        $grandparent = OrganizationalUnit::factory()->create(['name' => 'Grandparent']);
        $parent = OrganizationalUnit::factory()->childOf($grandparent)->create(['name' => 'Parent']);
        $child = OrganizationalUnit::factory()->childOf($parent)->create(['name' => 'Child']);

        $ancestors = $child->ancestors();

        $this->assertSame(['Parent', 'Grandparent'], $ancestors->pluck('name')->all());
    }

    public function test_descendants_returns_every_unit_below_regardless_of_depth(): void
    {
        $root = OrganizationalUnit::factory()->create(['name' => 'Root']);
        $child = OrganizationalUnit::factory()->childOf($root)->create(['name' => 'Child']);
        $grandchild = OrganizationalUnit::factory()->childOf($child)->create(['name' => 'Grandchild']);

        $descendants = $root->descendants();

        $this->assertCount(2, $descendants);
        $this->assertTrue($descendants->contains('id', $child->id));
        $this->assertTrue($descendants->contains('id', $grandchild->id));
    }

    public function test_would_create_cycle_is_false_for_a_null_parent(): void
    {
        $unit = OrganizationalUnit::factory()->create();

        $this->assertFalse($unit->wouldCreateCycleIfParentedTo(null));
    }

    public function test_would_create_cycle_is_true_when_parenting_a_unit_to_itself(): void
    {
        $unit = OrganizationalUnit::factory()->create();

        $this->assertTrue($unit->wouldCreateCycleIfParentedTo($unit));
    }

    public function test_would_create_cycle_is_true_when_parenting_a_unit_to_its_own_descendant(): void
    {
        $root = OrganizationalUnit::factory()->create();
        $child = OrganizationalUnit::factory()->childOf($root)->create();
        $grandchild = OrganizationalUnit::factory()->childOf($child)->create();

        $this->assertTrue($root->wouldCreateCycleIfParentedTo($grandchild));
    }

    public function test_would_create_cycle_is_false_for_an_unrelated_unit(): void
    {
        $unitA = OrganizationalUnit::factory()->create();
        $unitB = OrganizationalUnit::factory()->create();

        $this->assertFalse($unitA->wouldCreateCycleIfParentedTo($unitB));
    }

    public function test_slug_is_generated_uniquely_for_duplicate_names(): void
    {
        $first = OrganizationalUnit::generateUniqueSlug('Sample Unit');
        OrganizationalUnit::factory()->create(['slug' => $first]);

        $second = OrganizationalUnit::generateUniqueSlug('Sample Unit');

        $this->assertNotSame($first, $second);
    }
}
