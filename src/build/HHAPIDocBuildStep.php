<?hh // strict
/*
 *  Copyright (c) 2004-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the BSD-style license found in the
 *  LICENSE file in the root directory of this source tree. An additional grant
 *  of patent rights can be found in the PATENTS file in the same directory.
 *
 */

namespace HHVM\UserDocumentation;

use type Facebook\DefinitionFinder\{
  FileParser,
  ScannedDefinition,
  ScannedClass,
  ScannedClassish,
  ScannedFunction,
  ScannedInterface,
  ScannedMethod,
  ScannedTrait,
};
use namespace Facebook\HHAPIDoc;
use namespace Facebook\HHAPIDoc\Documentables;
use type Facebook\HHAPIDoc\{Documentable, Documentables};
use namespace HH\Lib\{C, Dict, Str, Vec};
use namespace Facebook\TypeAssert;

final class HHAPIDocBuildStep extends BuildStep {
  <<__Override>>
  public function buildAll(): void {
    Log::i("\nHHAPIDocBuildStep");

    $exts = ImmSet { 'php', 'hhi', 'hh' };

    Log::i("\nFinding Builtin Sources");
    $runtime_sources = Vec\concat(
      self::findSources(BuildPaths::HHVM_TREE.'/hphp/system/php/', $exts),
      self::findSources(BuildPaths::HHVM_TREE.'/hphp/runtime/ext/', $exts),
    );
    $hhi_sources =
      self::findSources(BuildPaths::HHVM_TREE.'/hphp/hack/hhi/', $exts);
    Log::i("\nParsing builtins");
    $runtime_defs = self::parse($runtime_sources);
    $hhi_defs = self::parse($hhi_sources);
    Log::i("\nDe-duping builtins");
    $builtin_defs = DataMerger::mergeAll(Vec\concat($runtime_defs, $hhi_defs));
    Log::i("\nFiltering out PHP builtins");
    $builtin_defs = Vec\filter(
      $builtin_defs,
      $documentable ==> {
        $parent = $documentable['parent'];
        if ($parent !== null) {
          return ScannedDefinitionFilters::IsHHSpecific($parent);
        }
        return ScannedDefinitionFilters::IsHHSpecific($documentable['definition']);
      },
    );

    Log::i("\nFinding HSL sources");
    $hsl_sources = self::findSources(BuildPaths::HSL_TREE.'/src/', $exts);
    Log::i("\nParsing HSL sources");
    $hsl_defs = self::parse($hsl_sources);

    Log::i("\nGenerating index for builtins");
    $builtin_index = self::createProductIndex(APIProduct::HACK, $builtin_defs);
    Log::i("\nGenerating index for the HSL");
    $hsl_index = self::createProductIndex(APIProduct::HSL, $hsl_defs);
    Log::i("\nWriting index file");
    \file_put_contents(
      BuildPaths::APIDOCS_INDEX_JSON,
      JSON\encode_shape(
        APIIndexShape::class,
        shape(
          APIProduct::HACK => $builtin_index,
          APIProduct::HSL => $hsl_index,
        ),
      ),
    );

    Log::i("\nGenerating Markdown for builtins");
    $builtin_md = self::buildMarkdown(APIProduct::HACK, $builtin_defs);
    Log::i("\nGenerating Markdown for the HSL");
    $hsl_md = self::buildMarkdown(APIProduct::HSL, $hsl_defs);

    \file_put_contents(
      BuildPaths::APIDOCS_MARKDOWN.'/index.json',
      JSON\encode_shape(
        DirectoryIndex::class,
        shape('files' => Vec\concat($builtin_md, $hsl_md)),
      ),
    );
  }

  private static function createProductIndex(
    APIProduct $product,
    vec<Documentable> $documentables,
  ): ProductAPIIndexShape {
    return shape(
      'class' => self::createClassishIndex(
        $product,
        APIDefinitionType::CLASS_DEF,
        $documentables,
      ),
      'interface' => self::createClassishIndex(
        $product,
        APIDefinitionType::INTERFACE_DEF,
        $documentables,
      ),
      'trait' => self::createClassishIndex(
        $product,
        APIDefinitionType::TRAIT_DEF,
        $documentables,
      ),
      'function' => self::createFunctionIndex($product, $documentables),
    );
  }

  private static function createClassishIndex(
    APIProduct $product,
    APIDefinitionType $type,
    vec<Documentable> $documentables,
  ): dict<string, APIClassIndexEntry> {
    $classes = Vec\filter(
      $documentables,
      $d ==> {
        if ($type === APIDefinitionType::CLASS_DEF) {
          return $d['definition'] instanceof ScannedClass;
        }
        if ($type === APIDefinitionType::INTERFACE_DEF) {
          return $d['definition'] instanceof ScannedInterface;
        }
        if ($type === APIDefinitionType::TRAIT_DEF) {
          return $d['definition'] instanceof ScannedTrait;
        }
        invariant_violation('unhandled type: %s', $type);
      },
    );

    $html_paths = HTMLPaths::get($product);

    return Dict\pull(
      $classes,
      $class ==> {
        $class_name = $class['definition']->getName();
        $methods = Dict\filter(
          $documentables,
          $d ==> $d['parent'] === $class['definition'],
        );

        return shape(
          'type' => $type,
          'name' => $class_name,
          'htmlPath' => $html_paths->getPathForClassish($type, $class_name),
          'urlPath' => \APIClassPageControllerURIBuilder::getPath(shape(
            'Product' => $product,
            'Name' => Str\replace($class_name, "\\", '.'),
            'Type' => $type,
          )),
          'methods' => Dict\pull(
            $methods,
            $method ==> {
              $method_name = $method['definition']->getName();
              return shape(
                'name' => $method_name,
                'className' => $class_name,
                'classType' => $type,
                'htmlPath' => $html_paths->getPathForClassishMethod(
                  $type,
                  $class_name,
                  $method_name,
                ),
                'urlPath' => \APIMethodPageControllerURIBuilder::getPath(shape(
                  'Product' => $product,
                  'Class' => Str\replace($class_name, "\\", '.'),
                  'Method' => $method_name,
                  'Type' => $type,
                )),
              );
            },
            $method ==> Str\replace(
              $method['definition']->getName(),
              "\\",
              '.',
            ),
          ),
        );
      },
      $class ==> Str\replace($class['definition']->getName(), "\\", '.'),
    );
  }

  private static function createFunctionIndex(
    APIProduct $product,
    vec<Documentable> $documentables,
  ): dict<string, APIFunctionIndexEntry> {
    $functions = Dict\filter(
      $documentables,
      $d ==> $d['definition'] instanceof ScannedFunction,
    );
    $html_paths = HTMLPaths::get($product);
    return Dict\pull(
      $functions,
      $function ==> {
        $function_name = $function['definition']->getName();
        return shape(
          'name' => $function_name,
          'htmlPath' => $html_paths->getPathForFunction($function_name),
          'urlPath' => \APIClassPageControllerURIBuilder::getPath(shape(
            'Product' => $product,
            'Name' => Str\replace($function_name, "\\", '.'),
            'Type' => APIDefinitionType::FUNCTION_DEF,
          ),
        ));
      },
      $function ==> Str\replace($function['definition']->getName(), "\\", '.'),
    );
  }

  private static function parse(
    Traversable<string> $sources,
  ): vec<Documentable> {
    return $sources
      |> Vec\map(
        $$,
        $file ==> {
          Log::v('.');
          return FileParser::fromFile($file);
        },
      )
      |> Vec\map($$, $parser ==> Documentables\from_parser($parser))
      |> Vec\flatten($$)
      |> Vec\filter(
        $$,
        $documentable ==> {
          $parent = $documentable['parent'];
          if (
            $parent !== null
            && ScannedDefinitionFilters::ShouldNotDocument($parent)
          ) {
            return false;
          }
          return !ScannedDefinitionFilters::ShouldNotDocument(
            $documentable['definition'],
          );
        },
      );
  }

  private static function buildMarkdown(
    APIProduct $product,
    vec<Documentable> $documentables,
  ): vec<string> {
    $root = BuildPaths::APIDOCS_MARKDOWN.'/'.$product;

    if (!\is_dir($root)) {
      \mkdir($root, /* mode = */ 0755, /* recursive = */ true);
    }
    $md_paths = MarkdownPaths::get($product);
    $ctx = new HHAPIDoc\MarkdownBuilderContext(
      new HHAPIDocExt\PathProvider()
    );
    $builder = new HHAPIDocExt\MarkdownBuilder($ctx);

    return Vec\map($documentables, $documentable ==> {
      Log::v('.');
      $md = $builder->getDocumentation($documentable);
      $what = $documentable['definition'];
      if ($what instanceof ScannedMethod) {
        $parent = TypeAssert\not_null($documentable['parent']);
        $path = $md_paths->getPathForClassishMethod(
          self::getClassishAPIDefinitionType($parent),
          $parent->getName(),
          $what->getName(),
        );
      } else if ($what instanceof ScannedFunction) {
        $path = $md_paths->getPathForFunction($what->getName());
        } else if ($what instanceof ScannedClassish) {
          $path = $md_paths->getPathForClassish(
          self::getClassishAPIDefinitionType($what),
          $what->getName(),
        );
      } else {
        invariant_violation(
          "Can't handle definition of type %s",
          \get_class($what),
        );
      }
      \file_put_contents($path, $md."\n<!-- HHAPIDOC -->\n");
      return $path;
    });
  }

  private static function getClassishAPIDefinitionType(
    ScannedDefinition $definition,
  ): APIDefinitionType {
    if ($definition instanceof ScannedClass) {
      return APIDefinitionType::CLASS_DEF;
    }
    if ($definition instanceof ScannedInterface) {
      return APIDefinitionType::INTERFACE_DEF;
    }
    if ($definition instanceof ScannedTrait) {
      return APIDefinitionType::TRAIT_DEF;
    }
    invariant_violation(
      "Can't handle type %s",
      \get_class($definition),
    );
  }
}
