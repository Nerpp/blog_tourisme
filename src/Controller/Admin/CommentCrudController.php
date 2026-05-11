<?php

namespace App\Controller\Admin;

use App\Entity\Comment;
use App\Entity\User;
use App\Enum\CommentStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

final class CommentCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Comment::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Commentaire')
            ->setEntityLabelInPlural('Commentaires')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('author', 'Auteur');
        yield AssociationField::new('article', 'Article');
        yield AssociationField::new('place', 'Lieu');
        yield TextareaField::new('content', 'Contenu');
        yield ChoiceField::new('status', 'Statut')
            ->setChoices($this->enumChoices(CommentStatus::cases()))
            ->renderAsBadges();
        yield IntegerField::new('spamScore', 'Score spam');
        yield TextareaField::new('moderationReason', 'Raison de modération')->hideOnIndex();
        yield IntegerField::new('reportedCount', 'Signalements');
        yield DateTimeField::new('publishedAt', 'Publication');
        yield DateTimeField::new('approvedAt', 'Approbation');
        yield DateTimeField::new('moderatedAt', 'Modération');
        yield AssociationField::new('moderatedBy', 'Modéré par');
        yield DateTimeField::new('editedAt', 'Édition');
        yield DateTimeField::new('createdAt', 'Création')->hideOnForm();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices($this->enumChoices(CommentStatus::cases())))
            ->add(NumericFilter::new('reportedCount', 'Signalements'))
            ->add(EntityFilter::new('author', 'Auteur'))
            ->add(EntityFilter::new('article', 'Article'))
            ->add(EntityFilter::new('place', 'Lieu'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $approve = Action::new('approve', 'Approuver', 'fa fa-check')
            ->linkToCrudAction('approve')
            ->renderAsForm()
            ->asSuccessAction()
            ->displayIf(static fn (Comment $comment): bool => $comment->getStatus() !== CommentStatus::Approved);

        $reject = Action::new('reject', 'Rejeter', 'fa fa-ban')
            ->linkToCrudAction('reject')
            ->renderAsForm()
            ->asWarningAction()
            ->displayIf(static fn (Comment $comment): bool => $comment->getStatus() !== CommentStatus::Rejected);

        $spam = Action::new('spam', 'Spam', 'fa fa-triangle-exclamation')
            ->linkToCrudAction('spam')
            ->renderAsForm()
            ->asDangerAction()
            ->displayIf(static fn (Comment $comment): bool => $comment->getStatus() !== CommentStatus::Spam);

        $deleteLogically = Action::new('deleteLogically', 'Supprimer logiquement', 'fa fa-trash')
            ->linkToCrudAction('deleteLogically')
            ->renderAsForm()
            ->asDangerAction()
            ->askConfirmation('Supprimer logiquement ce commentaire ?');

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $approve)
            ->add(Crud::PAGE_DETAIL, $approve)
            ->add(Crud::PAGE_INDEX, $reject)
            ->add(Crud::PAGE_DETAIL, $reject)
            ->add(Crud::PAGE_INDEX, $spam)
            ->add(Crud::PAGE_DETAIL, $spam)
            ->add(Crud::PAGE_INDEX, $deleteLogically)
            ->add(Crud::PAGE_DETAIL, $deleteLogically);
    }

    #[AdminRoute('/{entityId}/approve', name: 'approve', options: ['methods' => ['POST']])]
    public function approve(AdminContext $context, EntityManagerInterface $entityManager): Response
    {
        $comment = $this->getComment($context);
        $now = new DateTimeImmutable();

        $comment
            ->setStatus(CommentStatus::Approved)
            ->setApprovedAt($now)
            ->setPublishedAt($comment->getPublishedAt() ?? $now)
            ->setModeratedAt($now)
            ->setModeratedBy($this->getAdminUser());

        $entityManager->flush();
        $this->addFlash('success', 'Commentaire approuvé.');

        return $this->redirectAfterModeration($context);
    }

    #[AdminRoute('/{entityId}/reject', name: 'reject', options: ['methods' => ['POST']])]
    public function reject(AdminContext $context, EntityManagerInterface $entityManager): Response
    {
        $this->updateModerationStatus($context, $entityManager, CommentStatus::Rejected, 'Commentaire rejeté.');

        return $this->redirectAfterModeration($context);
    }

    #[AdminRoute('/{entityId}/spam', name: 'spam', options: ['methods' => ['POST']])]
    public function spam(AdminContext $context, EntityManagerInterface $entityManager): Response
    {
        $this->updateModerationStatus($context, $entityManager, CommentStatus::Spam, 'Commentaire marqué comme spam.');

        return $this->redirectAfterModeration($context);
    }

    #[AdminRoute('/{entityId}/delete-logically', name: 'delete_logically', options: ['methods' => ['POST']])]
    public function deleteLogically(AdminContext $context, EntityManagerInterface $entityManager): Response
    {
        $comment = $this->getComment($context);

        $comment
            ->setStatus(CommentStatus::Deleted)
            ->setContent('Commentaire supprimé par modération.')
            ->setModeratedAt(new DateTimeImmutable())
            ->setModeratedBy($this->getAdminUser());

        $entityManager->flush();
        $this->addFlash('success', 'Commentaire supprimé logiquement.');

        return $this->redirectAfterModeration($context);
    }

    /**
     * @param list<\BackedEnum> $cases
     *
     * @return array<string, \BackedEnum>
     */
    private function enumChoices(array $cases): array
    {
        $choices = [];
        foreach ($cases as $case) {
            $choices[$case->value] = $case;
        }

        return $choices;
    }

    private function updateModerationStatus(
        AdminContext $context,
        EntityManagerInterface $entityManager,
        CommentStatus $status,
        string $flashMessage,
    ): void {
        $this->getComment($context)
            ->setStatus($status)
            ->setModeratedAt(new DateTimeImmutable())
            ->setModeratedBy($this->getAdminUser());

        $entityManager->flush();
        $this->addFlash('success', $flashMessage);
    }

    private function getComment(AdminContext $context): Comment
    {
        $comment = $context->getEntity()->getInstance();
        if (!$comment instanceof Comment) {
            throw $this->createNotFoundException('Commentaire introuvable.');
        }

        return $comment;
    }

    private function getAdminUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    private function redirectAfterModeration(AdminContext $context): Response
    {
        $referer = $context->getRequest()->headers->get('referer');

        return $this->redirect($referer ?: $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl());
    }
}
