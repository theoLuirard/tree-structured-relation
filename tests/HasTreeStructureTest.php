<?php

namespace Tests;

use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Eloquent\Model;

/**
 * Minimal test model used to exercise the trait.
 */
class TestModel extends Model
{
    use \theoLuirard\TreeStructuredRelation\Traits\HasTreeStructure;

    protected $table = 'test_models';
    protected $guarded = [];

    // Configure trait properties to match the test table
    public string $parent_column_name = 'parent_id';
    public string $path_column_name = 'path';
    public string $path_separator = '/';
    public string $property_for_explicit_path = 'name';
    public ?string $explicit_path_column_name = null;
}

class HasTreeStructureTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        // Use in-memory SQLite for tests
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('test_models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('path')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_models');
        parent::tearDown();
    }

    public function test_trait_relationships_and_helpers()
    {
        // Create a small tree:
        // root
        //  ├─ child1
        //  │   └─ grandchild
        //  └─ child2
        $root = TestModel::create(['name' => 'root']);
        $child1 = TestModel::create(['name' => 'child1', 'parent_id' => $root->id]);
        $child2 = TestModel::create(['name' => 'child2', 'parent_id' => $root->id]);
        $grandchild = TestModel::create(['name' => 'grandchild', 'parent_id' => $child1->id]);

        // Basic relations
        $this->assertCount(2, $root->direct_children);
        $this->assertTrue($child1->parent->is($root));
        $this->assertTrue($child1->parent()->exists());

        // Helper methods
        $this->assertTrue($root->isRoot());
        $this->assertFalse($child1->isRoot());
        $this->assertFalse($root->isLeaf());
        $this->assertTrue($grandchild->isLeaf());

        // Parent/child/ancestor/descendant/sibling checks
        $this->assertTrue($child1->isParentOf($grandchild));
        $this->assertTrue($grandchild->isChildOf($child1));
        $this->assertTrue($root->isAncestorOf($grandchild));
        $this->assertTrue($grandchild->isDescendantOf($root));
        $this->assertTrue($child1->isSiblingOf($child2));

        // Move child2 under child1 and check updates
        $child2->setAsChildOf($child1);
        $child2 = $child2->fresh();
        $this->assertEquals($child1->id, $child2->parent_id);
        $this->assertTrue($child1->isParentOf($child2));

        // Path computation: refreshPath should set path to names joined by separator
        $root->refreshPath();

        $root = $root->fresh();
        $child1 = $child1->fresh();
        $grandchild = $grandchild->fresh();

        $this->assertEquals('root', $root->path);
        $this->assertEquals('root/child1', $child1->path);
        $this->assertEquals('root/child1/grandchild', $grandchild->path);
    }
}