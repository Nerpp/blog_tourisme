<?php

namespace App\Command;

use App\Entity\ProductionAccessBootstrap;
use App\Entity\User;
use App\Repository\ProductionAccessBootstrapRepository;
use App\Repository\UserRepository;
use App\Service\UserRoleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'app:access:bootstrap',
    description: 'Amorce, sans suppression, les accès administrateurs d’utilisateurs existants.',
)]
final class BootstrapProductionAccessCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ProductionAccessBootstrapRepository $bootstrapRepository,
        private readonly UserRoleManager $roleManager,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'file',
                null,
                InputOption::VALUE_REQUIRED,
                'Fichier YAML contenant uniquement les listes super_admins et admins.',
                'config/production/initial-admins.yaml',
            )
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Applique réellement les ajouts de rôles.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Autorise explicitement une réexécution après l’amorçage initial.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $force = (bool) $input->getOption('force');
        $configuredFile = $input->getOption('file');

        if (!is_string($configuredFile) || trim($configuredFile) === '') {
            $io->error('L’option --file doit désigner un fichier YAML.');
            $this->reportNoModification($io);

            return Command::INVALID;
        }

        $completedBootstrap = $this->bootstrapRepository->findCompleted();
        if ($completedBootstrap instanceof ProductionAccessBootstrap && !$force) {
            $io->error(sprintf(
                'L’amorçage a déjà été effectué le %s. Gérez désormais ROLE_ADMIN depuis l’interface super-admin, ou utilisez --force pour une intervention exceptionnelle.',
                $completedBootstrap->getCompletedAt()->format('d/m/Y H:i:s'),
            ));
            $this->reportNoModification($io);

            return Command::FAILURE;
        }

        $file = $this->resolveFile(trim($configuredFile));
        try {
            $configuration = $this->loadAndValidateConfiguration($file);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());
            $this->reportNoModification($io);

            return Command::INVALID;
        }

        if ($configuration['super_admins'] === []) {
            $io->error('L’amorçage de production exige au moins un super-administrateur déclaré dans « super_admins ».');
            $this->reportNoModification($io);

            return Command::FAILURE;
        }

        $requests = [];
        foreach ($configuration['super_admins'] as $email) {
            $requests[] = ['email' => $email, 'role' => UserRoleManager::ROLE_SUPER_ADMIN];
        }
        foreach ($configuration['admins'] as $email) {
            $requests[] = ['email' => $email, 'role' => UserRoleManager::ROLE_ADMIN];
        }

        /** @var list<array{user: User, role: string, already_granted: bool}> $resolved */
        $resolved = [];
        $rows = [];
        $hasBlockingError = false;

        foreach ($requests as $request) {
            $user = $this->userRepository->findOneByEmail($request['email']);
            if (!$user instanceof User) {
                $rows[] = [$request['email'], $request['role'], 'Introuvable', 'Bloquant'];
                $hasBlockingError = true;
                continue;
            }

            if (!$user->isVerified()) {
                $rows[] = [$request['email'], $request['role'], 'Trouvé, e-mail non vérifié', 'Bloquant'];
                $hasBlockingError = true;
                continue;
            }

            $alreadyGranted = in_array($request['role'], $user->getRoles(), true);
            $rows[] = [
                $request['email'],
                $request['role'],
                'Trouvé et vérifié',
                $alreadyGranted ? 'Rôle déjà présent' : ($apply ? 'Rôle à ajouter' : 'Rôle serait ajouté'),
            ];
            $resolved[] = ['user' => $user, 'role' => $request['role'], 'already_granted' => $alreadyGranted];
        }

        $io->table(['Utilisateur', 'Rôle demandé', 'État du compte', 'Résultat'], $rows);

        if ($hasBlockingError) {
            $io->error('Au moins un utilisateur est absent ou non vérifié : l’opération transactionnelle est annulée dans son intégralité.');
            $this->reportNoModification($io);

            return Command::FAILURE;
        }

        if (!$apply) {
            $io->success('Simulation terminée : la configuration est valide.');
            $this->reportNoModification($io);

            return Command::SUCCESS;
        }

        try {
            $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($resolved, $completedBootstrap, $file): void {
                foreach ($resolved as $item) {
                    if (!$item['already_granted']) {
                        $this->roleManager->grantFromBootstrap($item['user'], $item['role']);
                    }
                }

                if (!$completedBootstrap instanceof ProductionAccessBootstrap) {
                    $contents = file_get_contents($file);
                    if (!is_string($contents)) {
                        throw new \RuntimeException('Impossible de relire le fichier pour calculer son empreinte.');
                    }

                    $marker = (new ProductionAccessBootstrap())
                        ->setConfigurationFingerprint(hash('sha256', $contents))
                        ->setExecutedFromFile($this->displayPath($file));
                    $entityManager->persist($marker);
                }

                $entityManager->flush();
            });
        } catch (\Throwable $exception) {
            $io->error('Aucune modification n’a été conservée : '.$exception->getMessage());

            return Command::FAILURE;
        }

        $changedCount = count(array_filter($resolved, static fn (array $item): bool => !$item['already_granted']));
        $io->success(sprintf(
            'Amorçage appliqué de manière transactionnelle : %d rôle(s) ajouté(s), aucun rôle retiré.',
            $changedCount,
        ));

        return Command::SUCCESS;
    }

    private function resolveFile(string $file): string
    {
        if (str_starts_with($file, '/')) {
            return $file;
        }

        return $this->projectDir.'/'.$file;
    }

    /** @return array{super_admins: list<string>, admins: list<string>} */
    private function loadAndValidateConfiguration(string $file): array
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new \InvalidArgumentException(sprintf('Le fichier d’amorçage "%s" est absent ou illisible.', $this->displayPath($file)));
        }

        try {
            $data = Yaml::parseFile($file);
        } catch (ParseException $exception) {
            throw new \InvalidArgumentException('Le fichier YAML est invalide : '.$exception->getMessage(), 0, $exception);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new \InvalidArgumentException('La racine YAML doit contenir exactement les clés super_admins et admins.');
        }

        $keys = array_keys($data);
        sort($keys);
        if ($keys !== ['admins', 'super_admins']) {
            throw new \InvalidArgumentException('Les seules clés autorisées sont super_admins et admins, toutes deux obligatoires.');
        }

        $validated = ['super_admins' => [], 'admins' => []];
        $seen = [];
        foreach (['super_admins', 'admins'] as $key) {
            $emails = $data[$key];
            if (!is_array($emails) || !array_is_list($emails)) {
                throw new \InvalidArgumentException(sprintf('La clé %s doit contenir une liste d’adresses e-mail.', $key));
            }

            foreach ($emails as $email) {
                if (!is_string($email)) {
                    throw new \InvalidArgumentException(sprintf('Chaque valeur de %s doit être une adresse e-mail sous forme de chaîne.', $key));
                }

                $normalizedEmail = mb_strtolower(trim($email));
                if (!filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new \InvalidArgumentException(sprintf('Adresse e-mail invalide dans %s : "%s".', $key, $email));
                }

                if (isset($seen[$normalizedEmail])) {
                    throw new \InvalidArgumentException(sprintf(
                        'L’adresse "%s" apparaît plusieurs fois ou dans les deux listes.',
                        $normalizedEmail,
                    ));
                }

                $seen[$normalizedEmail] = true;
                $validated[$key][] = $normalizedEmail;
            }
        }

        return $validated;
    }

    private function displayPath(string $file): string
    {
        $projectPrefix = rtrim($this->projectDir, '/').'/';

        return str_starts_with($file, $projectPrefix) ? substr($file, strlen($projectPrefix)) : $file;
    }

    private function reportNoModification(SymfonyStyle $io): void
    {
        $io->note('Aucune donnée n’a été modifiée.');
    }
}
