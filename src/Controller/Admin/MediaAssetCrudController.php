<?php

namespace App\Controller\Admin;

use App\Entity\MediaAsset;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Enum\VideoType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;

final class MediaAssetCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return MediaAsset::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Média')
            ->setEntityLabelInPlural('Médias')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titre');
        yield ChoiceField::new('mediaType', 'Type de média')
            ->setChoices($this->enumChoices(MediaType::cases()));
        yield ChoiceField::new('imageType', 'Type image')
            ->setChoices($this->nullableEnumChoices(ImageType::cases()));
        yield ChoiceField::new('videoType', 'Type vidéo')
            ->setChoices($this->nullableEnumChoices(VideoType::cases()));
        yield TextField::new('filePath', 'Fichier');
        yield TextField::new('thumbnailPath', 'Miniature')->hideOnIndex();
        yield TextField::new('externalUrl', 'URL externe')->hideOnIndex();
        yield TextField::new('altText', 'Texte alternatif')->hideOnIndex();
        yield TextareaField::new('caption', 'Légende')->hideOnIndex();
        yield TextField::new('projection', 'Projection')->hideOnIndex();
        yield ArrayField::new('metadata', 'Métadonnées')->hideOnForm();
        yield IntegerField::new('width', 'Largeur');
        yield IntegerField::new('height', 'Hauteur');
        yield IntegerField::new('durationSeconds', 'Durée');
        yield DateTimeField::new('createdAt', 'Création')->hideOnForm();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('mediaType', 'Type de média')->setChoices($this->enumChoices(MediaType::cases())))
            ->add(ChoiceFilter::new('imageType', 'Type image')->setChoices($this->enumChoices(ImageType::cases())))
            ->add(ChoiceFilter::new('videoType', 'Type vidéo')->setChoices($this->enumChoices(VideoType::cases())));
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
