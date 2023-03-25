<?php

declare(strict_types=1);

/**
 * This file is part of phpDocumentor.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @link https://phpdoc.org
 */

namespace phpDocumentor\Transformer\Writer;

use phpDocumentor\Descriptor\ApiSetDescriptor;
use phpDocumentor\Descriptor\Collection as DescriptorCollection;
use phpDocumentor\Descriptor\Descriptor;
use phpDocumentor\Descriptor\DocumentationSetDescriptor;
use phpDocumentor\Descriptor\ProjectDescriptor;
use phpDocumentor\Descriptor\Query\Engine;
use phpDocumentor\Transformer\Template;
use phpDocumentor\Transformer\Transformation;
use phpDocumentor\Transformer\View\DefaultViewSet;
use phpDocumentor\Transformer\View\ViewSet;
use phpDocumentor\Transformer\Writer\Twig\EnvironmentFactory;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Webmozart\Assert\Assert;

use function ltrim;
use function preg_split;
use function strlen;
use function substr;

/**
 * A specialized writer which uses the Twig templating engine to convert
 * templates to HTML output.
 *
 * This writer support the Query attribute of a Transformation to generate
 * multiple templates in one transformation.
 *
 * The Query attribute supports a simplified version of Twig queries and will
 * use each individual result as the 'node' global variable in the Twig template.
 *
 * Example:
 *
 *   Suppose a Query `indexes.classes` is given then this writer will be
 *   invoked as many times as there are classes in the project and the
 *   'node' global variable in twig will be filled with each individual
 *
 *
 * When using the Query attribute in the transformation it is important to
 * use a variable in the Artifact attribute as well (otherwise the same file
 * would be overwritten several times).
 *
 * A simple example transformation line could be:
 *
 * ```
 * <transformation
 *     writer="twig"
 *     source="templates/twig/index.twig"
 *     artifact="index.html"
 * />
 * ```
 *
 * This example transformation would use this writer to transform the
 * index.twig template file in the twig template folder into index.html at
 * the destination location.
 * Since no Query is provided the 'node' global variable will contain
 * the Documentation Set Descriptor of the Object Graph.
 *
 * A complex example transformation line could be:
 *
 * ```
 * <transformation
 *     query="indexes.classes"
 *     writer="twig"
 *     source="templates/twig/class.twig"
 *     artifact="{{name}}.html"/>
 * ```
 *
 * This example transformation would use this writer to transform the
 * class.twig template file in the twig template folder into a file with
 * the 'name' property for an individual class inside the Object Graph.
 * Since a Query *is* provided will the 'node' global variable contain a
 * specific instance of a class applicable to the current iteration.
 *
 * @see self::getDestinationPath() for more information about variables in the
 *     Artifact attribute.
 */
final class Twig extends WriterAbstract implements Initializable, ProjectDescriptor\WithCustomSettings
{
    use IoTrait;

    private EnvironmentFactory $environmentFactory;
    private PathGenerator $pathGenerator;
    private Environment $environment;
    private Engine $queryEngine;

    public function __construct(
        EnvironmentFactory $environmentFactory,
        PathGenerator $pathGenerator,
        Engine $queryEngine
    ) {
        $this->environmentFactory = $environmentFactory;
        $this->pathGenerator = $pathGenerator;
        $this->queryEngine = $queryEngine;
    }

    public function getName(): string
    {
        return 'twig';
    }

    public function initialize(
        ProjectDescriptor $project,
        DocumentationSetDescriptor $documentationSet,
        Template $template
    ): void {
        $this->environment = $this->environmentFactory->create($project, $documentationSet, $template);
    }

    /**
     * This method combines the ProjectDescriptor and the given target template
     * and creates a static html page at the artifact location.
     *
     * @param Transformation $transformation Transformation to execute.
     * @param ProjectDescriptor $project Document containing the structure.
     *
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function transform(
        Transformation $transformation,
        ProjectDescriptor $project,
        DocumentationSetDescriptor $documentationSet
    ): void {
        // TODO: At a later stage we want to support more types of Documentation Sets using the Twig writer
        //       but at the moment this causes headaches in the migration process towards multiple sets of
        //       documentation. As such, this limitation has been added
        if ($documentationSet instanceof ApiSetDescriptor === false) {
            return;
        }

        $templatePath = substr($transformation->getSource(), strlen($this->getTemplatePath($transformation)));

        $nodes = [$documentationSet];
        if ($transformation->getQuery()) {
            $nodes = $this->queryEngine->perform($documentationSet, $transformation->getQuery());
        }

        $viewSet = DefaultViewSet::create($project, $documentationSet, $transformation);

        foreach ($nodes as $node) {
            if ($node instanceof DescriptorCollection) {
                $this->transformNodeCollection($node, $transformation, $viewSet, $templatePath);
            }

            if (!($node instanceof Descriptor)) {
                continue;
            }

            $this->transformNode($node, $transformation, $viewSet, $templatePath);
        }
    }

    public function getDefaultSettings(): array
    {
        return [];
    }

    /**
     * @param DescriptorCollection<Descriptor> $nodes
     */
    private function transformNodeCollection(
        DescriptorCollection $nodes,
        Transformation $transformation,
        ViewSet $viewSet,
        string $templatePath
    ): void {
        foreach ($nodes as $node) {
            if ($node instanceof DescriptorCollection) {
                $this->transformNodeCollection($node, $transformation, $viewSet, $templatePath);
            }

            if (!($node instanceof Descriptor)) {
                continue;
            }

            $this->transformNode($node, $transformation, $viewSet, $templatePath);
        }
    }

    private function transformNode(
        Descriptor $node,
        Transformation $transformation,
        ViewSet $viewSet,
        string $templatePath
    ): void {
        $path = $this->pathGenerator->generate($node, $transformation);
        if ($path === '') {
            return;
        }

        foreach ($viewSet->getViews() as $key => $view) {
            $this->environment->addGlobal($key, $view);
        }

        $this->environment->addGlobal('node', $node);
        $this->environment->addGlobal('destinationPath', $path);

        // pre-set the global variable so that we can update it later
        // TODO: replace env with origin filesystem, as this will help us to copy assets.
        $this->environment->addGlobal('env', null);

        $output = $this->environment->render($templatePath, ['target_path' => ltrim($path, '/\\')]);

        $this->persistTo($transformation, ltrim($path, '/\\'), $output);
    }

    /**
     * Returns the path belonging to the template.
     */
    private function getTemplatePath(Transformation $transformation): string
    {
        $parts = preg_split('~[\\\\|/]~', $transformation->getSource());

        Assert::isArray($parts);

        if ($parts[0] !== 'templates') {
            return '';
        }

        return $parts[0] . '/' . $parts[1];
    }
}
