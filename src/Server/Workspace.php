<?php
declare(strict_types = 1);

namespace LanguageServer\Server;

use LanguageServer\{LanguageClient, Project, PhpDocumentLoader};
use LanguageServer\Index\{ProjectIndex, DependenciesIndex, Index};
use LanguageServer\Protocol\{SymbolInformation, SymbolDescriptor, ReferenceInformation, DependencyReference, Location};
use Sabre\Event\Promise;
use function Sabre\Event\coroutine;

/**
 * Provides method handlers for all workspace/* methods
 */
class Workspace
{
    /**
     * The symbol index for the workspace
     *
     * @var ProjectIndex
     */
    private $index;

    /**
     * @var DependenciesIndex
     */
    private $dependenciesIndex;

    /**
     * @var Index
     */
    private $sourceIndex;

    /**
     * @var \stdClass
     */
    public $composerLock;

    /**
     * @var PhpDocumentLoader
     */
    public $documentLoader;

    /**
     * @param ProjectIndex      $index             Index that is searched on a workspace/symbol request
     * @param DependenciesIndex $dependenciesIndex Index that is used on a workspace/xreferences request
     * @param DependenciesIndex $sourceIndex       Index that is used on a workspace/xreferences request
     * @param \stdClass         $composerLock      The parsed composer.lock of the project, if any
     * @param PhpDocumentLoader $documentLoader    PhpDocumentLoader instance to load documents
     */
    public function __construct(ProjectIndex $index, DependenciesIndex $dependenciesIndex, Index $sourceIndex, \stdClass $composerLock = null, PhpDocumentLoader $documentLoader)
    {
        $this->sourceIndex = $sourceIndex;
        $this->index = $index;
        $this->dependenciesIndex = $dependenciesIndex;
        $this->composerLock = $composerLock;
        $this->documentLoader = $documentLoader;
    }

    /**
     * The workspace symbol request is sent from the client to the server to list project-wide symbols matching the query string.
     *
     * @param string $query
     * @return SymbolInformation[]
     */
    public function symbol(string $query): array
    {
        $symbols = [];
        foreach ($this->index->getDefinitions() as $fqn => $definition) {
            if ($query === '' || stripos($fqn, $query) !== false) {
                $symbols[] = $definition->symbolInformation;
            }
        }
        return $symbols;
    }

    /**
     * The workspace references request is sent from the client to the server to locate project-wide references to a symbol given its description / metadata.
     *
     * @param SymbolDescriptor $query Partial metadata about the symbol that is being searched for.
     * @param string[]         $files An optional list of files to restrict the search to.
     * @return ReferenceInformation[]
     */
    public function xreferences($query, array $files = null): Promise
    {
        return coroutine(function () use ($query, $files) {
            if ($this->composerLock === null) {
                return [];
            }
            /** Map from URI to array of referenced FQNs in dependencies */
            $refs = [];
            // Get all references TO dependencies
            $fqns = isset($query->fqsen) ? [$query->fqsen] : array_values($this->dependenciesIndex->getDefinitions());
            foreach ($fqns as $fqn) {
                foreach ($this->sourceIndex->getReferenceUris($fqn) as $uri) {
                    if (!isset($refs[$uri])) {
                        $refs[$uri] = [];
                    }
                    if (array_search($uri, $refs[$uri]) === false) {
                        $refs[$uri][] = $fqn;
                    }
                }
            }
            $refInfos = [];
            foreach ($refs as $uri => $fqns) {
                foreach ($fqns as $fqn) {
                    $def = $this->dependenciesIndex->getDefinition($fqn);
                    $symbol = new SymbolDescriptor;
                    $symbol->fqsen = $fqn;
                    foreach (get_object_vars($def->symbolInformation) as $prop => $val) {
                        $symbol->$prop = $val;
                    }
                    // Find out package name
                    preg_match('/\/vendor\/([^\/]+\/[^\/]+)\//', $def->symbolInformation->location->uri, $matches);
                    $packageName = $matches[1];
                    foreach ($this->composerLock->packages as $package) {
                        if ($package->name === $packageName) {
                            $symbol->package = $package;
                            break;
                        }
                    }
                    // If there was no FQSEN provided, check if query attributes match
                    if (!isset($query->fqsen)) {
                        $matches = true;
                        foreach (get_object_vars($query) as $prop => $val) {
                            if ($query->$prop != $symbol->$prop) {
                                $matches = false;
                                break;
                            }
                        }
                        if (!$matches) {
                            continue;
                        }
                    }
                    $doc = yield $this->documentLoader->getOrLoad($uri);
                    foreach ($doc->getReferenceNodesByFqn($fqn) as $node) {
                        $refInfo = new ReferenceInformation;
                        $refInfo->reference = Location::fromNode($node);
                        $refInfo->symbol = $symbol;
                        $refInfos[] = $refInfo;
                    }
                }
            }
            return $refInfos;
        });
    }

    /**
     * @return DependencyReference[]
     */
    public function xdependencies(): array
    {
        if ($this->composerLock === null) {
            return [];
        }
        $dependencyReferences = [];
        foreach ($this->composerLock->packages as $package) {
            $dependencyReferences[] = new DependencyReference($package);
        }
        return $dependencyReferences;
    }
}
