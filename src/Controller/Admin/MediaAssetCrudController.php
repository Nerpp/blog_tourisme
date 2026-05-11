<?php

namespace App\Controller\Admin;

use App\Entity\MediaAsset;
use App\Enum\ImageType;
use App\Enum\MediaType;
use App\Enum\VideoType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
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
        if (\in_array($pageName, [Crud::PAGE_NEW, Crud::PAGE_EDIT], true)) {
            yield ChoiceField::new('mediaType', 'Type de média')
                ->setChoices(MediaType::cases());
            yield ChoiceField::new('imageType', 'Type image')
                ->setChoices(ImageType::cases())
                ->setFormTypeOption('required', false);
            yield ChoiceField::new('videoType', 'Type vidéo')
                ->setChoices(VideoType::cases())
                ->setFormTypeOption('required', false);
        } else {
            yield ChoiceField::new('mediaType', 'Type de média')
                ->setChoices($this->enumValueChoices(MediaType::cases()));
            yield ChoiceField::new('imageType', 'Type image')
                ->setChoices($this->enumValueChoices(ImageType::cases()));
            yield ChoiceField::new('videoType', 'Type vidéo')
                ->setChoices($this->enumValueChoices(VideoType::cases()));
        }
        yield TextField::new('filePath', 'Fichier');
        yield TextField::new('thumbnailPath', 'Miniature')->hideOnIndex();
        yield TextField::new('externalUrl', 'URL externe')->hideOnIndex();
        yield TextField::new('altText', 'Texte alternatif')->hideOnIndex();
        yield TextareaField::new('caption', 'Légende')->hideOnIndex();
        yield TextField::new('projection', 'Projection')->hideOnIndex();
        yield CodeEditorField::new('metadata', 'Métadonnées')
            ->onlyOnDetail()
            ->formatValue(static fn (mixed $value): string => self::formatJsonValue($value))
            ->setLanguage('js')
            ->setNumOfRows(12);
        yield IntegerField::new('width', 'Largeur');
        yield IntegerField::new('height', 'Hauteur');
        yield IntegerField::new('durationSeconds', 'Durée');
        yield DateTimeField::new('createdAt', 'Création')->hideOnForm();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('mediaType', 'Type de média')->setChoices($this->enumValueChoices(MediaType::cases())))
            ->add(ChoiceFilter::new('imageType', 'Type image')->setChoices($this->enumValueChoices(ImageType::cases())))
            ->add(ChoiceFilter::new('videoType', 'Type vidéo')->setChoices($this->enumValueChoices(VideoType::cases())));
    }

    /**
     * @param list<\BackedEnum> $cases
     *
     * @return array<string, string|int>
     */
    private function enumValueChoices(array $cases): array
    {
        $choices = [];
        foreach ($cases as $case) {
            $choices[$case->value] = $case->value;
        }

        return $choices;
    }

    private static function formatJsonValue(mixed $value): string
    {
        if (null === $value || [] === $value) {
            return '';
        }

        if (!\is_array($value)) {
            return (string) $value;
        }

        $json = json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return false === $json ? '' : $json;
    }
}
