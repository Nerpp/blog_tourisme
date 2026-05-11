<?php

namespace App\Controller\Admin;

use App\Entity\Destination;
use App\Enum\DestinationType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

final class DestinationCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Destination::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Destination')
            ->setEntityLabelInPlural('Destinations')
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom');
        yield TextField::new('slug', 'Slug');
        yield ChoiceField::new('type', 'Type')
            ->setChoices($this->enumChoices(DestinationType::cases()));
        yield AssociationField::new('parent', 'Parent');
        yield TextField::new('code', 'Code');
        yield TextareaField::new('description', 'Description')->hideOnIndex();
        yield NumberField::new('latitude', 'Latitude')->setNumDecimals(6);
        yield NumberField::new('longitude', 'Longitude')->setNumDecimals(6);
        yield TextField::new('seoTitle', 'Titre SEO')->hideOnIndex();
        yield TextareaField::new('seoDescription', 'Description SEO')->hideOnIndex();
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
