<?php

namespace App\Controller\Admin;

use App\Entity\CommentReport;
use App\Enum\CommentReportReason;
use App\Enum\CommentReportStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;

final class CommentReportCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return CommentReport::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Signalement')
            ->setEntityLabelInPlural('Signalements')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('comment', 'Commentaire');
        yield AssociationField::new('reporter', 'Auteur du signalement');
        yield ChoiceField::new('reason', 'Raison')
            ->setChoices($this->enumChoices(CommentReportReason::cases()));
        yield TextareaField::new('message', 'Message')->hideOnIndex();
        yield ChoiceField::new('status', 'Statut')
            ->setChoices($this->enumChoices(CommentReportStatus::cases()))
            ->renderAsBadges();
        yield AssociationField::new('reviewedBy', 'Traité par');
        yield DateTimeField::new('reviewedAt', 'Traitement');
        yield DateTimeField::new('createdAt', 'Création')->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Mise à jour')->hideOnForm();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices($this->enumChoices(CommentReportStatus::cases())))
            ->add(ChoiceFilter::new('reason', 'Raison')->setChoices($this->enumChoices(CommentReportReason::cases())));
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
}
