<?php

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ArrayFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;

final class UserCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Utilisateur')
            ->setEntityLabelInPlural('Utilisateurs')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield EmailField::new('email', 'Email');
        yield ChoiceField::new('roles', 'Rôles')
            ->setChoices($this->roleChoices())
            ->allowMultipleChoices()
            ->renderAsBadges();
        yield TextField::new('displayName', 'Nom affiché');
        yield BooleanField::new('trustedCommenter', 'Commentateur fiable');
        yield IntegerField::new('approvedCommentsCount', 'Commentaires approuvés');
        yield DateTimeField::new('createdAt', 'Création')->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Mise à jour')->hideOnForm();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ArrayFilter::new('roles', 'Rôles')->setChoices($this->roleChoices()))
            ->add(BooleanFilter::new('trustedCommenter', 'Commentateur fiable'));
    }

    /** @return array<string, string> */
    private function roleChoices(): array
    {
        return [
            'Utilisateur' => 'ROLE_USER',
            'Administrateur' => 'ROLE_ADMIN',
        ];
    }
}
