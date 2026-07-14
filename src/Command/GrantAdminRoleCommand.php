<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\UserRoleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:user:grant-admin',
    description: 'Ajoute ROLE_ADMIN à un utilisateur existant, en simulation par défaut.',
)]
final class GrantAdminRoleCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserRoleManager $roleManager,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Adresse e-mail de l’utilisateur existant.')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Applique réellement l’ajout de ROLE_ADMIN.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->environment !== 'prod') {
            $io->error(sprintf(
                'Cette commande est réservée à l’environnement prod (environnement actuel : %s).',
                $this->environment,
            ));
            $this->reportNoModification($io);

            return Command::FAILURE;
        }

        $emailArgument = $input->getArgument('email');
        if (!is_string($emailArgument)) {
            $io->error('L’adresse e-mail doit être fournie sous forme de chaîne.');
            $this->reportNoModification($io);

            return Command::INVALID;
        }

        $email = mb_strtolower(trim($emailArgument));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error(sprintf('Adresse e-mail invalide : « %s ».', $emailArgument));
            $this->reportNoModification($io);

            return Command::INVALID;
        }

        $user = $this->userRepository->findOneByEmail($email);
        if (!$user instanceof User) {
            $io->error(sprintf('Aucun utilisateur trouvé pour l’adresse %s.', $email));
            $this->reportNoModification($io);

            return Command::FAILURE;
        }

        if ($user->isSuperAdmin()) {
            $io->error(sprintf(
                'L’utilisateur %s est super-administrateur et dispose déjà des droits administratifs grâce à la hiérarchie des rôles. ROLE_ADMIN ne sera pas ajouté explicitement.',
                $email,
            ));
            $this->reportNoModification($io);

            return Command::FAILURE;
        }

        if (!$user->isVerified()) {
            $io->error(sprintf('L’adresse e-mail de l’utilisateur %s n’est pas vérifiée.', $email));
            $this->reportNoModification($io);

            return Command::FAILURE;
        }

        if ($user->isBanned()) {
            $io->error(sprintf('L’utilisateur %s est banni.', $email));
            $this->reportNoModification($io);

            return Command::FAILURE;
        }

        if (in_array(UserRoleManager::ROLE_ADMIN, $user->getRoles(), true)) {
            $io->success(sprintf('L’utilisateur %s est déjà administrateur. Aucune modification ni nouvelle trace d’audit.', $email));

            return Command::SUCCESS;
        }

        if (!(bool) $input->getOption('apply')) {
            $io->writeln(sprintf('Utilisateur : %s', $email));
            $io->writeln('État : trouvé et vérifié');
            $io->writeln('Action : ROLE_ADMIN serait ajouté');
            $io->writeln('Aucune donnée n’a été modifiée.');

            return Command::SUCCESS;
        }

        try {
            $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($user): void {
                $this->roleManager->grantAdminFromCli($user);
                $entityManager->flush();
            });
        } catch (\Throwable) {
            $io->error('L’ajout transactionnel a échoué. Aucune modification n’a été conservée.');

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'ROLE_ADMIN a été ajouté à l’utilisateur %s. Les rôles existants ont été conservés et une trace d’audit CLI a été créée.',
            $email,
        ));

        return Command::SUCCESS;
    }

    private function reportNoModification(SymfonyStyle $io): void
    {
        $io->note('Aucune donnée n’a été modifiée.');
    }
}
