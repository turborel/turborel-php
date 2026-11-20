<?php

declare(strict_types=1);

namespace Turborel\Tools;

/**
 * Every public item documented (RULES.md R-DOC-1): each class, interface,
 * trait and enum of the library, and each of its public and protected
 * constants, properties and methods, has a doc comment.
 */
final class DocCheck
{
    /** The library's directory in a path. */
    private const string LIBRARY = '/src/';

    /** The library's namespace, which `src/` holds by PSR-4. */
    private const string NAMESPACE = 'Turborel\\';

    /** The members R-DOC-1 asks a doc comment of. */
    private const int VISIBLE = \ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED;

    /**
     * What breaks R-DOC-1 in the library's `$files`.
     *
     * @param list<string> $files
     *
     * @return list<string>
     */
    public static function check(array $files): array
    {
        $findings = [];
        foreach ($files as $file) {
            $name = ClassFile::name($file, self::LIBRARY, self::NAMESPACE);
            if ($name === null) {
                $findings[] = "{$file}: declares no class, interface, trait or enum of its path (R-DOC-1)";

                continue;
            }
            $findings = [...$findings, ...self::undocumented($file, new \ReflectionClass($name))];
        }

        return $findings;
    }

    /**
     * The items of `$class` that should have a doc comment and lack one.
     *
     * @param \ReflectionClass<object> $class
     *
     * @return list<string>
     */
    private static function undocumented(string $file, \ReflectionClass $class): array
    {
        $name = $class->getName();
        $items = [$name => $class->getDocComment()];
        // Only what the class declares itself: what it inherits is documented where it is declared.
        foreach ($class->getReflectionConstants(self::VISIBLE) as $constant) {
            if ($constant->getDeclaringClass()->getName() === $name) {
                $items["{$name}::{$constant->getName()}"] = $constant->getDocComment();
            }
        }
        foreach ($class->getProperties(self::VISIBLE) as $property) {
            if ($property->getDeclaringClass()->getName() === $name) {
                $items["{$name}::\${$property->getName()}"] = $property->getDocComment();
            }
        }
        foreach ($class->getMethods(self::VISIBLE) as $method) {
            if ($method->getDeclaringClass()->getName() === $name) {
                $items["{$name}::{$method->getName()}()"] = $method->getDocComment();
            }
        }
        $findings = [];
        foreach ($items as $item => $doc) {
            if ($doc === false) {
                $findings[] = "{$file}: {$item} has no doc comment (R-DOC-1)";
            }
        }

        return $findings;
    }
}
