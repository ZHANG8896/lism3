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

use League\Tactician\CommandBus;
use phpDocumentor\Descriptor\DocumentationSetDescriptor;
use phpDocumentor\Descriptor\GuideSetDescriptor;
use phpDocumentor\Descriptor\ProjectDescriptor;
use phpDocumentor\Dsn;
use phpDocumentor\Guides\RenderCommand;
use phpDocumentor\Guides\Twig\EnvironmentBuilder;
use phpDocumentor\Parser\FlySystemFactory;
use phpDocumentor\Transformer\Transformation;
use phpDocumentor\Transformer\View\DefaultViewSet;
use phpDocumentor\Transformer\Writer\Twig\EnvironmentFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Stopwatch\Stopwatch;

use function sprintf;

/**
 * @experimental this feature is in alpha stages and can have unresolved issues or missing features.
 */
final class RenderGuide extends WriterAbstract implements ProjectDescriptor\WithCustomSettings
{
    public const FEATURE_FLAG = 'guides.enabled';

    private LoggerInterface $logger;
    private CommandBus $commandBus;
    private FlySystemFactory $flySystemFactory;
    private EnvironmentFactory $environmentFactory;
    private EnvironmentBuilder $environmentBuilder;

    public function __construct(
        LoggerInterface $logger,
        CommandBus $commandBus,
        FlySystemFactory $flySystemFactory,
        EnvironmentFactory $environmentFactory,
        EnvironmentBuilder $environmentBuilder
    ) {
        $this->logger = $logger;
        $this->commandBus = $commandBus;
        $this->flySystemFactory = $flySystemFactory;
        $this->environmentFactory = $environmentFactory;
        $this->environmentBuilder = $environmentBuilder;
    }

    public function getName(): string
    {
        return 'RenderGuide';
    }

    public function transform(
        Transformation $transformation,
        ProjectDescriptor $project,
        DocumentationSetDescriptor $documentationSet
    ): void {
        // Feature flag: Guides are disabled by default since this is an experimental feature
        if (!($project->getSettings()->getCustom()['guides.enabled'] ?? false)) {
            return;
        }

        if ($documentationSet instanceof GuideSetDescriptor === false) {
            return;
        }

        $this->logger->warning(
            'Generating guides is experimental, no BC guarantees are given, use at your own risk'
        );

        //TODO Extract this, as this code is duplicated
        $this->environmentBuilder->setEnvironmentFactory(
            function () use ($transformation, $project, $documentationSet) {
                $twig = $this->environmentFactory->create($project, $documentationSet, $transformation->template());
                $twig->addGlobal('destinationPath', null);

                $viewSet = DefaultViewSet::create($project, $documentationSet, $transformation);
                foreach ($viewSet->getViews() as $name => $view) {
                    $twig->addGlobal($name, $view);
                }

                return $twig;
            }
        );

        $this->renderDocumentationSet($documentationSet, $transformation);
    }

    public function getDefaultSettings(): array
    {
        return ['guides.enabled' => false];
    }

    private function renderDocumentationSet(
        GuideSetDescriptor $documentationSet,
        Transformation $transformation
    ): void {
        $dsn = $documentationSet->getSource()->dsn();
        $stopwatch = $this->startRenderingSetMessage($dsn);

        $filesystem = $this->flySystemFactory->create($dsn);
        $destination = $transformation->getTransformer()->destination();
        $this->commandBus->handle(new RenderCommand($documentationSet, $filesystem, $destination));

        $this->completedRenderingSetMessage($stopwatch, $dsn);
    }

    private function startRenderingSetMessage(Dsn $dsn): Stopwatch
    {
        $stopwatch = new Stopwatch();
        $stopwatch->start('guide');
        $this->logger->info('Rendering guide ' . $dsn);

        return $stopwatch;
    }

    private function completedRenderingSetMessage(Stopwatch $stopwatch, Dsn $dsn): void
    {
        $stopwatchEvent = $stopwatch->stop('guide');
        $this->logger->info(
            sprintf(
                'Completed rendering guide %s in %.2fms using %.2f mb memory',
                (string) $dsn,
                $stopwatchEvent->getDuration(),
                $stopwatchEvent->getMemory() / 1024 / 1024
            )
        );
    }
}
