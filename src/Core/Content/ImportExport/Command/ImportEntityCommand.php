<?php declare(strict_types=1);

namespace Shopware\Core\Content\ImportExport\Command;

use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use Shopware\Core\Content\ImportExport\Aggregate\ImportExportLog\ImportExportLogEntity;
use Shopware\Core\Content\ImportExport\ImportExport;
use Shopware\Core\Content\ImportExport\ImportExportException;
use Shopware\Core\Content\ImportExport\ImportExportFactory;
use Shopware\Core\Content\ImportExport\ImportExportProfileEntity;
use Shopware\Core\Content\ImportExport\Processing\Reader\CsvReader;
use Shopware\Core\Content\ImportExport\Service\ImportExportService;
use Shopware\Core\Content\ImportExport\Struct\Config;
use Shopware\Core\Content\ImportExport\Struct\Progress;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotEqualsFilter;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[AsCommand(
    name: 'import:entity',
    description: 'Import entities from a csv file',
)]
#[Package('fundamentals@after-sales')]
class ImportEntityCommand extends Command
{
    private const DEFAULT_CHUNK_SIZE = 300;

    /**
     * @internal
     *
     * @param EntityRepository<EntityCollection<ImportExportProfileEntity>> $profileRepository
     */
    public function __construct(
        private readonly ImportExportService $initiationService,
        private readonly EntityRepository $profileRepository,
        private readonly ImportExportFactory $importExportFactory,
        private readonly Connection $connection,
        private readonly FilesystemOperator $filesystem
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to import file')
            ->addArgument('expireDate', InputArgument::REQUIRED, 'PHP DateTime compatible string')
            ->addOption(
                'profile-technical-name',
                null,
                InputOption::VALUE_OPTIONAL,
                'Specify the profile to use via its technical name'
            )
            ->addOption('rollbackOnError', 'r', InputOption::VALUE_NONE, 'Rollback database transaction on error')
            ->addOption('printErrors', 'p', InputOption::VALUE_NONE, 'Print errors occurred during import')
            ->addOption('dryRun', 'd', InputOption::VALUE_NONE, 'Do a dry run of import without persisting data')
            ->addOption('useBatchImport', 'b', InputOption::VALUE_NONE, 'Use batch import strategy');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createCLIContext();

        $profile = $this->getProfile($input, $output, $context);

        $filePath = $input->getArgument('file');
        $rollbackOnError = $input->getOption('rollbackOnError');
        $dryRun = $input->getOption('dryRun');
        $printErrors = $input->getOption('printErrors');

        $expireDateString = $input->getArgument('expireDate');

        try {
            $expireDate = new \DateTimeImmutable($expireDateString);
        } catch (\Exception) {
            throw ImportExportException::importCommandFailed(
                \sprintf('"%s" is not a valid date. Please use format Y-m-d', $expireDateString)
            );
        }

        $file = new UploadedFile($filePath, basename((string) $filePath), $profile->getFileType());

        $doRollback = $rollbackOnError && !$dryRun;
        if ($doRollback) {
            $this->connection->beginTransaction();
        }

        $log = $this->initiationService->prepareImport(
            $context,
            $profile->getId(),
            $expireDate,
            $file,
            [],
            $dryRun
        );

        $startTime = time();

        $importExport = $this->importExportFactory->create(
            $log->getId(),
            self::DEFAULT_CHUNK_SIZE,
            self::DEFAULT_CHUNK_SIZE,
            $input->getOption('useBatchImport') ?? false
        );

        $total = filesize($filePath);
        if ($total === false) {
            $total = 0;
        }
        $progressBar = $io->createProgressBar($total);

        $io->title(\sprintf('Starting import of size %d ', $total));

        $progress = new Progress($log->getId(), Progress::STATE_PROGRESS, 0);
        do {
            $progress = $importExport->import($context, $progress->getOffset());
            $progressBar->setProgress($progress->getOffset());
        } while (!$progress->isFinished());

        $elapsed = time() - $startTime;
        $io->newLine(2);

        if ($printErrors) {
            $this->printErrors($importExport, $log, $io, $doRollback && $progress->getState() === Progress::STATE_FAILED);
        }

        $this->printResults($log, $io);

        if ($dryRun) {
            $io->info(\sprintf('Dry run completed in %d seconds', $elapsed));

            return self::SUCCESS;
        }

        if (!$doRollback || $progress->getState() === Progress::STATE_SUCCEEDED) {
            if ($progress->getState() === Progress::STATE_FAILED) {
                $io->warning('Not all records could be imported due to errors');
            }

            $io->success(\sprintf(
                'Successfully imported %d records in %d seconds',
                $progress->getProcessedRecords(),
                $elapsed
            ));

            return self::SUCCESS;
        }

        $this->connection->rollBack();

        $io->error(\sprintf(
            'Errors on import. Rolling back transactions for %d records. Time elapsed: %d seconds',
            $progress->getProcessedRecords(),
            $elapsed
        ));

        return self::FAILURE;
    }

    private function getProfile(InputInterface $input, OutputInterface $output, Context $context): ImportExportProfileEntity
    {
        $technicalName = $input->getOption('profile-technical-name');

        if (!empty($technicalName)) {
            return $this->profileByTechnicalName($technicalName, $context);
        }

        return $this->chooseProfile($context, new SymfonyStyle($input, $output));
    }

    private function chooseProfile(Context $context, SymfonyStyle $io): ImportExportProfileEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new NotEqualsFilter('type', ImportExportProfileEntity::TYPE_EXPORT));

        $result = $this->profileRepository->search($criteria, $context)->getEntities();

        $byName = [];
        foreach ($result as $profile) {
            $byName[$profile->getLabel()] = $profile;
        }

        $answer = $io->choice('Please choose a profile', array_keys($byName));

        return $byName[$answer];
    }

    private function profileByTechnicalName(string $technicalName, Context $context): ImportExportProfileEntity
    {
        $result = $this->profileRepository->search(
            (new Criteria())->addFilter(new EqualsFilter('technicalName', $technicalName)),
            $context
        )->getEntities();

        if ($result->first() === null) {
            throw ImportExportException::profileSearchEmpty();
        }

        return $result->first();
    }

    private function printErrors(ImportExport $importExport, ImportExportLogEntity $log, SymfonyStyle $io, bool $deleteLog): void
    {
        if (!$importExport->getLogEntity()->getInvalidRecordsLog() || !$log->getFile()) {
            return;
        }

        $config = Config::fromLog($importExport->getLogEntity()->getInvalidRecordsLog());
        $reader = new CsvReader();
        $invalidLogFilePath = $log->getFile()->getPath() . '_invalid';
        $resource = $this->filesystem->readStream($invalidLogFilePath);

        $invalidRows = $reader->read($config, $resource, 0);

        foreach ($invalidRows as $invalidRow) {
            $io->note($invalidRow['_error']);
            $io->newLine();
        }

        if ($deleteLog) {
            $this->filesystem->delete($invalidLogFilePath);
            $this->filesystem->delete($log->getFile()->getPath());
        }
    }

    private function printResults(ImportExportLogEntity $log, SymfonyStyle $io): void
    {
        $importExport = $this->importExportFactory->create($log->getId());
        $results = $importExport->getLogEntity()->getResult();

        if (empty($results)) {
            return;
        }

        $rows = [];
        foreach ($results as $entity => $values) {
            ksort($values);
            $rows[] = array_merge(['entity' => $entity], $values);
        }
        $headers = array_keys(reset($rows));

        $io->table($headers, $rows);
    }
}
