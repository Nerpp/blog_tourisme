<?php

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Enum\CategoryType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

final class CategoryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Category::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Catégorie')
            ->setEntityLabelInPlural('Catégories')
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom');
        yield TextField::new('slug', 'Slug');
        yield ChoiceField::new('type', 'Type')
            ->setChoices($this->enumChoices(CategoryType::cases()));
        yield TextareaField::new('description', 'Description')->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Création')->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Mise à jour')->hideOnForm();
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
