<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Enum\ContentStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

final class ArticleCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Article::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Article')
            ->setEntityLabelInPlural('Articles')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titre');
        yield TextField::new('slug', 'Slug');
        yield AssociationField::new('category', 'Catégorie');
        yield AssociationField::new('author', 'Auteur');
        yield ChoiceField::new('status', 'Statut')
            ->setChoices($this->enumChoices(ContentStatus::cases()))
            ->renderAsBadges();
        yield TextareaField::new('excerpt', 'Extrait')->hideOnIndex();
        yield TextareaField::new('content', 'Contenu')->hideOnIndex();
        yield AssociationField::new('featuredImage', 'Image principale');
        yield TextField::new('seoTitle', 'Titre SEO')->hideOnIndex();
        yield TextareaField::new('seoDescription', 'Description SEO')->hideOnIndex();
        yield TextField::new('canonicalUrl', 'URL canonique')->hideOnIndex();
        yield DateTimeField::new('publishedAt', 'Publication');
        yield DateTimeField::new('createdAt', 'Création')->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Mise à jour')->hideOnForm();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices($this->enumChoices(ContentStatus::cases())))
            ->add(EntityFilter::new('category', 'Catégorie'))
            ->add(DateTimeFilter::new('publishedAt', 'Publication'));
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
