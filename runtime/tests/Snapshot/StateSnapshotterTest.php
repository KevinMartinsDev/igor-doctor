<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Snapshot;

use IgorDoctor\Runtime\Snapshot\NamespaceFilter;
use IgorDoctor\Runtime\Snapshot\StateSnapshotter;
use PHPUnit\Framework\TestCase;

final class StateSnapshotterTest extends TestCase
{
    public function testItFlattensPrivateProtectedAndInheritedProperties(): void
    {
        $snapshot = $this->snapshotter()->snapshot(['@child' => [new SnapshotChild(), null]]);

        self::assertNull($snapshot->get('@child'), 'a root has no holder to belong to');
        self::assertSame('child', $snapshot->get('@child->$name')?->value);
        self::assertSame('parent', $snapshot->get('@child->$'.SnapshotParent::class.'::name')?->value);
        self::assertSame('3', $snapshot->get('@child->$level')?->value);
        self::assertSame(2, $snapshot->get('@child->$tags')?->count);
        self::assertSame('b', $snapshot->get('@child->$tags[1]')?->value);
    }

    public function testItVisitsEachObjectOnceAndStopsOnCycles(): void
    {
        $node = new SnapshotNode();
        $node->next = new SnapshotNode();
        $node->next->next = $node;

        $snapshot = $this->snapshotter()->snapshot(['@node' => [$node, null]]);

        self::assertSame(SnapshotNode::class, $snapshot->get('@node->$next')?->type);
        self::assertSame('ref '.SnapshotNode::class, $snapshot->get('@node->$next->$next')?->type);
        self::assertFalse($snapshot->truncated);
    }

    public function testItReadsTheStorageAndThePropertiesOfAnArrayObject(): void
    {
        $collection = new SnapshotCollection(['get' => new SnapshotNode()]);

        $snapshot = $this->snapshotter()->snapshot(['@collection' => [$collection, null]]);

        self::assertSame(SnapshotNode::class, $snapshot->get('@collection[get]')?->type);
        self::assertSame(0, $snapshot->get('@collection->$operationCache')?->count);
    }

    public function testItRecordsOnlyTheStateUnderAKeptOwner(): void
    {
        $holder = new \stdClass();
        $holder->kept = new SnapshotNode();
        $holder->kept->value = 'inside';
        $holder->other = 'outside';

        $snapshot = $this->snapshotter()->snapshot(['@holder' => [$holder, null]]);

        self::assertNull($snapshot->get('@holder'));
        self::assertNull($snapshot->get('@holder->$other'));
        self::assertSame('inside', $snapshot->get('@holder->$kept->$value')?->value);
        self::assertSame(SnapshotNode::class, $snapshot->get('@holder->$kept->$value')?->owner);
    }

    public function testItDoesNotInitializeALazyObject(): void
    {
        if (\PHP_VERSION_ID < 80400) {
            self::markTestSkipped('Native lazy objects need PHP 8.4.');
        }

        $initialized = false;
        $ghost = (new \ReflectionClass(SnapshotNode::class))->newLazyGhost(static function () use (&$initialized): void {
            $initialized = true;
        });

        $holder = new SnapshotNode();
        $holder->next = $ghost;

        $snapshot = $this->snapshotter()->snapshot(['@holder' => [$holder, null]]);

        self::assertFalse($initialized);
        self::assertSame('lazy', $snapshot->get('@holder->$next')?->value);
    }

    public function testItStopsAtTheObjectDepthAndTheNodeBudget(): void
    {
        $node = new SnapshotNode();
        $node->next = new SnapshotNode();
        $node->next->next = new SnapshotNode();

        $shallow = (new StateSnapshotter(NamespaceFilter::everything(), maxObjectDepth: 1))->snapshot(['@node' => [$node, null]]);
        self::assertNotNull($shallow->get('@node->$next'));
        self::assertNull($shallow->get('@node->$next->$next'));

        $small = (new StateSnapshotter(NamespaceFilter::everything(), maxNodes: 3))->snapshot(['@node' => [$node, null]]);
        self::assertTrue($small->truncated);
    }

    public function testItKeepsLongStringsComparable(): void
    {
        $node = new SnapshotNode();
        $node->value = str_repeat('a', 5000).'end';
        $other = new SnapshotNode();
        $other->value = str_repeat('a', 5000).'END';

        $first = $this->snapshotter()->snapshot(['@node' => [$node, null]])->get('@node->$value');
        $second = $this->snapshotter()->snapshot(['@node' => [$other, null]])->get('@node->$value');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertFalse($first->sameAs($second));
    }

    private function snapshotter(): StateSnapshotter
    {
        return new StateSnapshotter(new NamespaceFilter(['IgorDoctor\\Runtime\\Tests\\Snapshot\\']));
    }
}

class SnapshotParent
{
    private string $name = 'parent';
}

final class SnapshotChild extends SnapshotParent
{
    protected int $level = 3;

    /** @var list<string> */
    public array $tags = ['a', 'b'];

    private string $name = 'child';
}

final class SnapshotNode
{
    public ?self $next = null;

    public ?string $value = null;
}

/**
 * @extends \ArrayObject<string, SnapshotNode>
 */
final class SnapshotCollection extends \ArrayObject
{
    /** @var array<string, mixed> */
    private array $operationCache = [];
}
