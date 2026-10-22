<?php

declare(strict_types=1);

namespace Tests\Unit;

use Adonyarik\ConsistentApi\Support\PhpClassReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhpClassReaderTest extends TestCase
{
    /**
     * @return array<string, array{string, array{namespace: string, name: string, kind: string}|null}>
     */
    public static function sources(): array
    {
        return [
            'class' => ["<?php\nnamespace App\\Models;\nclass Post {}", ['namespace' => 'App\\Models', 'name' => 'Post', 'kind' => 'class']],
            'docblock and comment with class words' => [
                "<?php\n// namespace Fake; class Wrong\nnamespace App\\Models;\n/** This class represents a post */\nclass Post {}",
                ['namespace' => 'App\\Models', 'name' => 'Post', 'kind' => 'class'],
            ],
            '::class and anonymous classes first' => [
                "<?php\nnamespace App;\n\$a = Foo::class;\n\$b = new class {};\n\$c = new readonly class {};\nclass Real {}",
                ['namespace' => 'App', 'name' => 'Real', 'kind' => 'class'],
            ],
            'modifiers and attributes' => [
                "<?php\nnamespace App;\n#[\\Attribute]\nfinal readonly class Value {}",
                ['namespace' => 'App', 'name' => 'Value', 'kind' => 'class'],
            ],
            'abstract class' => ["<?php\nnamespace App;\nabstract class Base {}", ['namespace' => 'App', 'name' => 'Base', 'kind' => 'class']],
            'interface' => ["<?php\nnamespace App;\ninterface Contract {}", ['namespace' => 'App', 'name' => 'Contract', 'kind' => 'interface']],
            'trait' => ["<?php\nnamespace App\\Concerns;\ntrait HasSlug {}", ['namespace' => 'App\\Concerns', 'name' => 'HasSlug', 'kind' => 'trait']],
            'enum' => ["<?php\nnamespace App;\nenum Status: string { case Draft = 'draft'; }", ['namespace' => 'App', 'name' => 'Status', 'kind' => 'enum']],
            'global namespace' => ["<?php\nclass Loose {}", ['namespace' => '', 'name' => 'Loose', 'kind' => 'class']],
            'braced global namespace' => ["<?php\nnamespace {\nclass Loose {}\n}", ['namespace' => '', 'name' => 'Loose', 'kind' => 'class']],
            'no declaration' => ["<?php\nnamespace App;\nfunction helper(): void {}\n\$x = Foo::class;", null],
        ];
    }

    /**
     * @param  array{namespace: string, name: string, kind: string}|null  $expected
     */
    #[DataProvider('sources')]
    public function test_reads_the_first_declaration(string $source, ?array $expected): void
    {
        $this->assertSame($expected, (new PhpClassReader)->read($source));
    }

    public function test_class_name_counts_classes_only(): void
    {
        $reader = new PhpClassReader;

        $this->assertSame('App\\Models\\Post', $reader->className("<?php\nnamespace App\\Models;\n/** The class */\nclass Post {}"));
        $this->assertSame('Loose', $reader->className("<?php\nclass Loose {}"));
        $this->assertNull($reader->className("<?php\nnamespace App;\nenum Status {}"));
        $this->assertNull($reader->className("<?php\nnamespace App;\ninterface Contract {}"));
        $this->assertNull($reader->className('<?php echo 1;'));
        // The first declaration alone would hide the class after it
        $this->assertNull($reader->className("<?php\nnamespace App;\ninterface HasFoo {}\nclass Post {}"));
        $this->assertNull($reader->className("<?php\nnamespace App;\nclass Post {}\nclass PostStatus {}"));
    }

    public function test_reads_every_declaration_with_its_namespace(): void
    {
        $reader = new PhpClassReader;
        $source = "<?php\nnamespace App;\ninterface HasFoo {}\nclass Post {}\nnamespace Other;\nenum Kind {}\nclass Tag {}";

        $this->assertSame([
            ['namespace' => 'App', 'name' => 'HasFoo', 'kind' => 'interface'],
            ['namespace' => 'App', 'name' => 'Post', 'kind' => 'class'],
            ['namespace' => 'Other', 'name' => 'Kind', 'kind' => 'enum'],
            ['namespace' => 'Other', 'name' => 'Tag', 'kind' => 'class'],
        ], $reader->readAll($source));
        $this->assertSame(['App\\Post', 'Other\\Tag'], $reader->classNames($source));
        $this->assertSame([], $reader->readAll('<?php echo 1;'));
    }
}
