<?php

namespace App\Controller\Admin;

use App\Entity\Place;
use App\Enum\ContentStatus;
use App\Enum\PlaceDifficulty;
use App\Enum\PriceType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

final class PlaceCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Place::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Lieu à visiter')
            ->setEntityLabelInPlural('Lieux à visiter')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom');
        yield TextField::new('slug', 'Slug');
        yield AssociationField::new('destination', 'Destination');
        yield AssociationField::new('category', 'Catégorie');
        yield ChoiceField::new('status', 'Statut')
            ->setChoices($this->enumChoices(ContentStatus::cases()))
            ->renderAsBadges();
        yield TextareaField::new('shortDescription', 'Description courte')->hideOnIndex();
        yield TextareaField::new('description', 'Description')->hideOnIndex();
        yield TextField::new('address', 'Adresse')->hideOnIndex();
        yield NumberField::new('latitude', 'Latitude')->setNumDecimals(6);
        yield NumberField::new('longitude', 'Longitude')->setNumDecimals(6);
        yield IntegerField::new('visitDurationMinutes', 'Durée de visite');
        yield ChoiceField::new('difficulty', 'Difficulté')
            ->setChoices($this->nullableEnumChoices(PlaceDifficulty::cases()));
        yield ChoiceField::new('priceType', 'Prix')
            ->setChoices($this->enumChoices(PriceType::cases()));
        yield AssociationField::new('featuredImage', 'Image principale');
        yield TextField::new('seoTitle', 'Titre SEO')->hideOnIndex();
        yield TextareaField::new('seoDescription', 'Description SEO')->hideOnIndex();
        yield DateTimeField::new('publishedAt', 'Publication');
        yield DateTimeField::new('createdAt', 'Création')->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Mise à jour')->hideOnForm();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices($this->enumChoices(ContentStatus::cases())))
            ->add(EntityFilter::new('destination', 'Destination'))
            ->add(EntityFilter::new('category', 'Catégorie'))
            ->add(ChoiceFilter::new('priceType', 'Prix')->setChoices($this->enumChoices(PriceType::cases())))
            ->add(ChoiceFilter::new('difficulty', 'Difficulté')->setChoices($this->enumChoices(PlaceDifficulty::cases())));
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

    /**
     * @param list<\BackedEnum> $cases
     *
     * @return array<string, \BackedEnum|null>
     */
    private function nullableEnumChoices(array $cases): array
    {
        return ['Non renseigné' => null] + $this->enumChoices($cases);
    }
}
